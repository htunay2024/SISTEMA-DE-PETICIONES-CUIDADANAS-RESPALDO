<?php
/**
 * whatsapp/recibir_bot.php
 *
 * Puente entre el bot de WhatsApp (Node/Baileys, carpeta "WhatsApp Bot")
 * y la IA local del proyecto ("IA 2"), SIN base de datos.
 *
 * El bot de Node le hace POST a este archivo con:
 *   { "secret": "...", "numero": "50212345678", "mensaje": "texto del usuario" }
 *
 * Este archivo:
 *  1) Valida el secreto compartido (BRIDGE_SECRET del .env del bot).
 *  2) Filtra lenguaje inapropiado/vulgar antes de avanzar la conversación
 *     (ver "contieneLenguajeInapropiado" más abajo).
 *  3) Mantiene el "estado de la conversación" de cada número en un archivo
 *     JSON dentro de whatsapp/sesiones/ (imitando la máquina de estados
 *     que en el navegador maneja chat_ia.js: petición → nombre → DPI →
 *     teléfono → dirección → registro inmediato de la solicitud; ya no
 *     hay paso de confirmación SÍ/NO/MODIFICAR).
 *  4) Usa clasificarPeticion() de "IA 2/clasificar.php" y el catálogo de
 *     "IA 2/datos_ia.json" para clasificar la petición y armar el folio.
 *  5) Devuelve { "respuesta": "texto para reenviar por WhatsApp" }.
 *
 * UBICACIÓN REQUERIDA:
 *   C:\xampp\htdocs\SISTEMA_PETICIONES_CUIDADANAS\whatsapp\recibir_bot.php
 *   (la carpeta "IA 2" debe estar un nivel arriba, junto a "whatsapp")
 *
 * IMPORTANTE: este endpoint SIEMPRE debe devolver JSON puro. Si PHP llega a
 * imprimir un warning/notice antes del JSON (por ejemplo, por una función
 * mal llamada), el bot de Node no puede interpretar la respuesta y el
 * ciudadano se queda sin contestación aunque el sistema "sí" haya
 * procesado su mensaje. Por eso capturamos cualquier salida accidental con
 * un buffer y la descartamos antes de imprimir el JSON real (igual que en
 * estado_bot.php y vincular_bot.php).
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

ob_start();

// ------------------------------------------------------------
// 0.-1 Red de seguridad ante errores fatales
// ------------------------------------------------------------
// BUGFIX (conexión "falla" / respuestas genéricas al ciudadano): si en
// cualquier punto de este script ocurre un error fatal de PHP (por
// ejemplo, un fallo real de clasificación, una función mal llamada,
// etc.), con display_errors en '0' el script simplemente termina SIN
// imprimir nada: el body de la respuesta queda vacío pero con código 200
// y Content-Type application/json.
//
// El bot de Node (whatsapp-bot/index.js) no detecta esto como un error de
// red (axios no lanza excepción con un body vacío), así que antes caía en
// su propio texto de repuesto genérico ("Gracias por tu mensaje, lo
// estamos procesando.") sin reintentar ni avisar a nadie — el ciudadano
// recibía una respuesta que no decía nada real, y en el log no quedaba
// ningún rastro del problema.
//
// Este manejador de apagado se asegura de que, incluso ante un error
// fatal, SIEMPRE se devuelva un JSON válido (con "ok": false) para que el
// bot de Node lo reconozca como un fallo real, reintente automáticamente,
// y quede registrado en whatsapp_mensajes.log para poder diagnosticarlo.
register_shutdown_function(function () {
    $error = error_get_last();
    $tiposFatales = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if ($error && in_array($error['type'], $tiposFatales, true)) {
        if (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'ok' => false,
            'error' => 'Error interno del servidor al procesar el mensaje.',
        ], JSON_UNESCAPED_UNICODE);

        if (function_exists('registrarLog')) {
            registrarLog(
                'Error fatal en recibir_bot.php: ' . $error['message'] .
                ' en ' . $error['file'] . ':' . $error['line'],
                'error',
                'whatsapp_mensajes.log'
            );
        } else {
            @file_put_contents(
                __DIR__ . '/../logs/whatsapp_mensajes.log',
                '[' . date('Y-m-d H:i:s') . "] [error] Error fatal en recibir_bot.php: {$error['message']} en {$error['file']}:{$error['line']}" . PHP_EOL,
                FILE_APPEND
            );
        }
    }
});

// ------------------------------------------------------------
// 0. Config: secreto compartido con el bot de Node (.env BRIDGE_SECRET)
// ------------------------------------------------------------
define('BRIDGE_SECRET', 'ESTUDIOSGHIBLI2026.'); // debe ser IDÉNTICO al .env del bot

// ------------------------------------------------------------
// 0.0 Reglas del reporte de INTERÉS PARTICULAR
// ------------------------------------------------------------
// Un reporte particular (una denuncia o conflicto entre vecinos, o un
// asunto propio de la persona) SIEMPRE necesita el nombre de quien lo
// hace, porque el oficio va dirigido a una autoridad (ej. el Juzgado de
// Asuntos Municipales) y hay que poder ubicar a quién citar/atender.
// Los reportes de interés SOCIAL (área pública) nunca piden nombre.
//
//   PARTICULAR_EXIGE_NOMBRE  true  = no se acepta "Anónimo": se explica el
//                                    motivo y se vuelve a pedir el nombre;
//                                    si se niega por segunda vez, se cancela
//                                    la solicitud amablemente.
//                            false = se acepta "Anónimo" como antes.
//   PARTICULAR_PIDE_TELEFONO true  = además del nombre se pregunta el
//                                    teléfono (comportamiento anterior).
//                            false = NO se pregunta: se guarda en silencio
//                                    el número del WhatsApp desde el que
//                                    escribe (el reporte particular pide
//                                    solo Nombre, Reporte y Dirección).
if (!defined('PARTICULAR_EXIGE_NOMBRE'))  define('PARTICULAR_EXIGE_NOMBRE', true);
if (!defined('PARTICULAR_PIDE_TELEFONO')) define('PARTICULAR_PIDE_TELEFONO', false);

// ------------------------------------------------------------
// 0.0.1 Catálogo de LUGARES CONOCIDOS del municipio
// ------------------------------------------------------------
// Lista de los lugares del municipio de San Pablo Jocopilas que el bot
// debe reconocer como ubicación válida, incluso si el ciudadano los
// escribe con minúsculas, sin tildes, con errores de ortografía leves
// ("flor de cafe" por "Flor del Café", "madre mia" por "Madre Mía") o
// sin indicar el tipo de lugar ("en chocola" en vez de "en Aldea
// Chocola").
//
// Se guardan SIEMPRE normalizados (minúsculas, sin tildes, espacios
// simples), para comparar contra el texto ya pasado por
// normalizarParaFiltro(). Si en el futuro se agregan aldeas, colonias o
// caseríos nuevos, basta con agregar sus variantes aquí.
if (!defined('LUGARES_CONOCIDOS_MUNICIPIO')) {
    define('LUGARES_CONOCIDOS_MUNICIPIO', [
        // --- Casco urbano ---
        'casco urbano',
        'zona 1', 'zona 2', 'zona 3', 'zona 4', 'zona 5',

        // --- Cantones del casco urbano ---
        'valle alegre',
        'ixcoj',
        'rumalinda',
        'ixtacapa',
        'brisas del ixtacapa',

        // --- Colonias y lotificaciones del casco urbano ---
        'vista hermosa',
        'linda vista',
        'flor del cafe',
        'flor de cafe',
        'flor cafe',
        'villa isabel',
        'maria luisa',
        'la cumbre',
        'valle dorado',
        'la felicidad',
        'las rosas',
        'esmirna',
        'villa hermosa',
        'san lorenzo',
        'san lorenzo montecristo',
        'san juan',
        'san antonio',
        'san cristobal',
        'san rodolfo',
        'la ceiba',
        'la flor',
        'rosario',
        'el socorro',
        'socorro',
        'madre mia',
        'villa canales',
        'colina flor del cafe',
        'la cumbre de alaska',
        'cumbre de alaska',

        // --- Aldeas y comunidades agrarias ---
        'chocola',
        'lolemi',
        'la ladrillera',
        'ladrillera',
        'pacacoj',
        'san pablito',
        'las piedritas',
        'piedritas',
        'piedrecitas',
        'las piedrecitas',
        'microparcelamiento las piedritas',
        'comunidad agraria chocola',
        'comunidad agraria lolemi',
        'comunidad agraria la ladrillera',
        'comunidad agraria madre mia',

        // --- Sectores de Chocola (subdivisiones) ---
        // Como todos contienen "chocola", la detección base ya los cubre,
        // pero se listan por claridad semántica y para futuras variantes
        // (ej. "sector X de chocola").
        'sector chocola',
        'sectores de chocola',
        'cerro partido',
        'el cerrito',
        'ian casitas',
        'ian tarrales',
        'el bosque',
        'excanalero',
        'el toronja',
        // AGREGADO: variantes de "toronjal" (caso real: chat 11, "Chocola,
        // sector toronjal").
        'el toronjal',
        'toronjal',
        'toronjales',
        'sector el toronjal',
        'sector toronjal',
        'sectores de toronjal',
        'el calvario',
        'las gradas',
        'el mercado',
        'escuela vieja',
        'alta vista',
        'sector el cementerio',
        'sector el campo',
        'sector bodega',
        'la presa',
        'el jardin',
        'nanzal',
        'sector ixcanalero',
        'sector san pablito',
        'sector centro',

        // --- Sectores de Lolemi ---
        'lolemi centro',

        // --- Sectores de Madre Mía ---
        'la union',
        'sector el centro',
        'calvario',
        'san rafael',
        'satelite',
        'principe de paz',
        'borbon',
        'sector el borbon 2',

        // --- Sectores de La Ladrillera ---
        'campo viejo',
        'la canoa',
        'zapotal',
        'el centro',
        'sector belen',
        'lomita',
        'punta de riel',
        'la cuchilla',
        'galera',

        // --- Puntos de referencia / instituciones ---
        // Se agregan porque la gente los usa muchísimo para ubicar
        // problemas ("cerca del INEBO", "frente al INEBO") y, si no
        // están en esta lista, el bot no detecta la ubicación y la
        // vuelve a preguntar, aunque el ciudadano ya la haya dado.
        'inebo',
        'instituto nacional de educacion basica',
        'ineb',
        // BUGFIX: "INSODI" (carro mal estacionado "en frente del INSODI"
        // no se reconocía y el bot volvía a pedir la ubicación). Nombre
        // normalizado (minúsculas, sin tildes) como lo compara normalizarLugar().
        'insodi',
        'escuela el quetzal',
        'escuela oficial urbana mixta',
        'instituto de educacion basica por cooperativa',
        'la portada',
        'iglesia catolica',
        'iglesia colonial',
        'parque central',
        'cementerio',
        'cementerio municipal',
        'mercado municipal',
        'mercado central',
        'centro de salud',
        'puesto de salud',
        'hospital',
        'policia nacional civil',
        'pnc',
        'renap',
        'conalfa',
        'maga',
        'cocode',
        'comred',
        'salon municipal',

        // --- Comercios y servicios reconocidos ---
        'kairos ferreteria',
        'ferreteria kairos',
        'banco industrial',
        'banco promerica',
        'banco reformador',
        'bantrab',
        'banco azteca',
        'elektra',
        'gasolinera canan',
        'gasolinera',
        'almacen elio coc',
        'novedades clarisa',
        'vision pablence',
        'clinica medica',
        'salon de belleza',
        'restaurante',
        'panaderia',
        'supermercado',
        'joyeria',
        'gimnasio',

        // --- Sitios arqueológicos y turísticos ---
        'sitio arqueologico chocola',
        'chocola sitio arqueologico',
        'centro vacacional y ecoturistico el bosque chocola',
        'el bosque chocola',
        'la montanita',
        'el murcielago',
        'agua vieja',
        'palo grande',
        'rio ixtacapa',
        'rio chocola',
        'cerro paculam',
        'cerro panan',
        'cerro paquisis',
        'volcan santo tomas',
        'las delicias',

        // ============================================================
        // AMPLIACIÓN: lugares reconocidos del municipio
        // (agregados desde el archivo "Portada (Iglesia católica en ruinas)")
        // Se guardan normalizados: minúsculas, sin tildes, espacios simples.
        // ============================================================

        // --- Casco urbano e institucional ---
        'portada iglesia catolica en ruinas',
        'portada iglesia catolica',
        'parque central de san pablo jocopilas',
        'parque central san pablo',
        'iglesia catolica principal',
        'iglesia catolica central',
        'estadio municipal',
        'estadio municipal de san pablo',
        'municipalidad de san pablo jocopilas',
        'escuela oficial urbana de varones',
        'escuela oficial urbana de ninas',
        'canchas deportivas polideportivas centrales',
        'canchas polideportivas centrales',
        'salon municipal de usos multiples',
        'parqueo municipal',
        'biblioteca municipal',
        'mercado de artesanias',
        'cancha de basquetbol del parque central',
        'campo de la feria',
        'rastro municipal',
        'tanque municipal',
        'ermita del calvario',
        'capilla de adoracion perpetua',
        'parque infantil municipal',
        'zona comercial central',
        'parque lineal municipal',
        'monumento a los fundadores',
        'subestacion de policia nacional civil',
        'subestacion pnc',
        'policia nacional civil',
        'mirador natural de san pablo jocopilas',
        'mirador natural san pablo',
        'puente san pablo',
        'rio san pablo',
        'comedor popular municipal',
        'mercado de mariscos',
        'cancha sintetica municipal',
        'salon de celebraciones el encuentro',
        'taller de artesanias en madera',
        'area recreativa las acacias',
        'puente colgante peatonal',

        // --- Chocolá y sus sectores ---
        'finca chocola',
        'reloj centenario de chocola',
        'reloj centenario',
        'estadio de futbol de chocola',
        'estadio chocola',
        'cooperativa agricola de chocola',
        'cooperativa agricola chocola',
        'antigua estacion del ferrocarril de chocola',
        'antigua estacion del ferrocarril',
        'instituto mixto de educacion basica por cooperativa de chocola',
        'instituto mixto de educacion basica por cooperativa',
        'instituto mixto de educacion basica chocola',
        'escuela primaria de aldea chocola',
        'cancha polideportiva de chocola',
        'casa comunal de chocola',
        'puesto de salud de chocola',
        'puente de acceso a chocola',
        'antiguo beneficio de cafe de chocola',
        'antiguo beneficio de cafe',
        'antiguo trapiche de la region',
        'antigua casa patronal de finca chocola',
        'cancha de basquetbol de aldea chocola',
        'campo de futbol de chocola',

        // --- Colonia Flor del Café ---
        'campo de futbol de la colonia flor del cafe',
        'campo de futbol flor del cafe',

        // --- Colonias ---
        'colonia el milagro',
        'colonia la union',
        'colonia las delicias',
        'colonia el maestro',

        // --- Cantones ---
        'canton san francisco',
        'canton san jose',
        'canton concepcion',
        'canton la ceiba',

        // --- Aldeas y caseríos ---
        'aldea san antonio abad',
        'caserio los angeles',
        'aldea san antonio',
        'campo de futbol de aldea san antonio abad',
        'escuela primaria de san antonio abad',
        'ermita de san antonio abad',

        // --- Ríos y puentes ---
        'puente sobre el rio sis',
        'puente rio sis',
        'rio sis',
        'rio xab',
        'puente de concreto el naranjo',
        'puente el naranjo',

        // --- Sectores y callejones ---
        'sector el centro',
        'sector la cuchilla',
        'sector el esfuerzo',
        'sector los olivos',
        'sector el paraiso',
        'sector la linea',
        'sector las brisas',
        'sector el porvenir',
        'sector los pinos',
        'sector la palmita',
        'sector el eden',
        'callejon los pinos',
        'callejon el zapote',
        'callejon las flores',
        'riachuelo el tablon',

        // --- Fincas y cooperativas ---
        'finca las margaritas',
        'finca san julian',
        'finca el retiro',
        'cooperativa de ahorro y credito local',
        'cooperativa de ahorro y credito',

        // --- Comercios y servicios ---
        'agencia de banrural',
        'banrural',
        'tienda de insumos agricolas la esperanza',
        'tienda de insumos agricolas',
        'tienda de cafe el cafetalito',
        'el cafetalito',
        'policlinica comunitaria',

        // --- Iglesias, ermitas y capillas ---
        'capilla de san jose',
        'ermita de la virgen de guadalupe',
        'ermita virgen de guadalupe',
    ]);
}

/**
 * Normaliza un texto para compararlo con el catálogo de lugares conocidos:
 * minúsculas, sin tildes, espacios simples, sin puntuación.
 */
if (!function_exists('normalizarLugar')) {
    function normalizarLugar($texto) {
        $t = normalizarParaFiltro((string) $texto); // minúsculas + sin tildes
        $t = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $t); // quita signos
        $t = trim(preg_replace('/\s+/u', ' ', $t));
        return $t;
    }
}

/**
 * Devuelve true si el texto CONTIENE (como palabra/frase completa) alguno
 * de los lugares conocidos del municipio. Se compara ya normalizado, así
 * que "Flor del Café", "flor del cafe" y "FLOR DE CAFE" dan igual.
 */
if (!function_exists('mencionaLugarConocido')) {
    function mencionaLugarConocido($texto) {
        $norm = normalizarLugar($texto);
        if ($norm === '') return false;
        foreach (LUGARES_CONOCIDOS_MUNICIPIO as $lugar) {
            // Límite de palabra con \b adaptado a unicode (para no hacer
            // match de "chocola" dentro de "chocolateria", por ejemplo).
            $patron = '/(?<![\p{L}\p{N}])' . preg_quote($lugar, '/') . '(?![\p{L}\p{N}])/u';
            if (preg_match($patron, $norm)) return true;
        }
        return false;
    }
}

// ------------------------------------------------------------
// 0.1 Unificación de burbujas: respuestas de varias "partes" en UN solo
//     mensaje de WhatsApp
// ------------------------------------------------------------
// Antes, algunas respuestas se enviaban como VARIAS burbujas de WhatsApp
// consecutivas (ej.: "Entendido, lo registré como…", "Ya identifiqué estos
// datos…", "¿Cuál es tu nombre completo?" llegaban como 3-4 mensajes
// separados). Esto generaba demasiadas notificaciones/mensajes para algo
// que se lee como una sola idea, así que ahora TODAS esas partes se
// combinan en una única burbuja, cada una en su propia línea (igual que ya
// hacía el resumen de confirmación con "\n\n").
//
// SEP_FRAGMENTO se conserva solo por compatibilidad (por si algún código
// viejo llegara a usarlo), pero armarRespuestaFragmentada() YA NO lo usa:
// ahora une las partes con saltos de línea normales, así que el bot de
// Node (whatsapp-bot/index.js) las envía siempre como un solo mensaje
// (enviarFragmentado solo divide en varias burbujas si encuentra este
// caracter, y ahora nunca lo va a encontrar).
define('SEP_FRAGMENTO', "\x01");

/**
 * Arma una respuesta a partir de varias partes que antes se enviaban como
 * burbujas separadas de WhatsApp. Ahora se unen con saltos de línea para
 * que todas viajen juntas en UN solo mensaje/burbuja. Se descartan partes
 * vacías por seguridad.
 */
function armarRespuestaFragmentada(array $partes) {
    $partes = array_values(array_filter(array_map('trim', $partes), function ($p) {
        return $p !== '';
    }));
    // AJUSTE: antes se unían con un solo "\n", lo que hacía que ideas
    // distintas (ej. "Entendido, lo registré como…" y "Ya identifiqué
    // estos datos…") se vieran pegadas una debajo de la otra sin ninguna
    // separación visual. Ahora se unen con una línea en blanco ("\n\n")
    // entre cada parte, igual que ya se hacía a mano en varios puntos del
    // archivo (ej. el menú de "¿Qué dato querés modificar?"). Las líneas
    // que sí deben ir pegadas sin espacio (ej. los datos del resumen de
    // confirmación, o "✅ Solicitud registrada" + su expediente) se arman
    // como un solo elemento del arreglo con su propio "\n" interno ANTES
    // de llegar a esta función, así que no se ven afectadas.
    return implode("\n\n", $partes);
}

/**
 * Devuelve el saludo apropiado según la hora del día.
 */
function saludoPorHora() {
    $hora = (int) date('H');
    if ($hora < 12) {
        return 'Buenos días';
    } elseif ($hora < 18) {
        return 'Buenas tardes';
    } else {
        return 'Buenas noches';
    }
}

/**
 * AGREGADO: mensajes de texto editables desde el panel de administración
 * ("Inteligencia Artificial" > "Mensajes del Bot"), guardados en
 * sistema-web/config/mensajes_bot.json. Se leen del disco en cada
 * petición (no se cachean) para que una edición desde el panel se vea de
 * inmediato. Si el archivo no existe, está corrupto, o le falta alguna
 * clave, se usan los mismos textos por defecto que tenía el bot antes de
 * que existiera este archivo, así que nunca se rompe por su ausencia.
 */
function leerMensajesBot() {
    static $mensajes = null;
    if ($mensajes !== null) return $mensajes;

    $porDefecto = [
        'clasificacion_registrada' => "{saludo}, gracias por comunicarse a Municipalidad de San Pablo Jocopilas.\n\nEntendido, lo registré como: {categoria} ({urgencia}).",
        'datos_detectados' => "Ya identifiqué estos datos en tu mensaje: {detectados}.",
        'pedir_datos_intro' => "Para generar tu solicitud formal necesito algunos datos.",
        'pedir_nombre' => "👤 ¿Cuál es tu nombre completo?",
        'pedir_direccion' => "📍 ¿Cuál es la dirección o punto de referencia del problema?",
        // AGREGADO (interés particular vs. interés social): ver detectarTipoInteres().
        'pedir_telefono' => "📞 ¿A qué número de teléfono te podemos contactar? Si es el mismo de este WhatsApp, escribí *este mismo*.",
        'agradecer_reporte_social' => "{saludo}, muchas gracias por el reporte.",
        'pedir_ubicacion_social' => "📍 Solo necesito la ubicación para registrarlo: ¿podría indicarnos el nombre de la colonia o un punto de referencia?",
        'agradecimiento_variantes' => [
            ["¡De nada, para eso estamos!", "Si más adelante necesitás reportar algo, escribime cuando quieras."],
            ["¡Con mucho gusto! 🙏 Estamos para servirle.", "Cuando necesite reportar algo más, aquí voy a estar."],
            ["¡Gracias a usted por su confianza!", "Cuando necesite algo, no dude en escribirnos. 😊"],
            ["¡Un placer poder ayudarle!", "Que tenga un excelente día. 🙌"],
        ],
    ];

    $ruta = __DIR__ . '/../config/mensajes_bot.json';
    $guardado = [];
    if (file_exists($ruta)) {
        $decodificado = json_decode(file_get_contents($ruta), true);
        if (is_array($decodificado)) $guardado = $decodificado;
    }

    $mensajes = array_merge($porDefecto, $guardado);
    // Nunca dejar una clave vacía o de tipo inválido: si algo quedó en
    // blanco en el panel, se cae de vuelta al texto por defecto en esa
    // clave puntual (en vez de mandar un mensaje vacío al ciudadano).
    foreach ($porDefecto as $clave => $valorPorDefecto) {
        if ($clave === 'agradecimiento_variantes') {
            if (!is_array($mensajes[$clave]) || empty($mensajes[$clave])) {
                $mensajes[$clave] = $valorPorDefecto;
            }
        } elseif (!is_string($mensajes[$clave]) || trim($mensajes[$clave]) === '') {
            $mensajes[$clave] = $valorPorDefecto;
        }
    }
    return $mensajes;
}

/**
 * Reemplaza {marcadores} tipo {categoria} en una plantilla de
 * mensajes_bot.json por su valor real.
 */
function aplicarMarcadores($plantilla, array $valores) {
    $buscar = array_map(function ($k) { return '{' . $k . '}'; }, array_keys($valores));
    return str_replace($buscar, array_values($valores), $plantilla);
}

/**
 * Devuelve, al azar, una respuesta cordial para cuando el ciudadano
 * agradece o se despide (ej. "Gracias por el apoyo", "Muy amables"), en
 * vez de contestar siempre exactamente lo mismo. Las variantes se leen de
 * mensajes_bot.json (editables desde el panel), con las 4 de siempre como
 * respaldo si el archivo no las trae.
 */
function respuestaAgradecimientoAleatoria() {
    $variantes = leerMensajesBot()['agradecimiento_variantes'];
    $mensajes = array_map('armarRespuestaFragmentada', $variantes);
    return $mensajes[array_rand($mensajes)];
}

$RUTA_IA = __DIR__ . '/../IA 2';           // ajustá si tu carpeta se llama distinto
$RUTA_SESIONES = __DIR__ . '/sesiones';    // aquí se guarda 1 archivo .json por número

if (!is_dir($RUTA_SESIONES)) {
    @mkdir($RUTA_SESIONES, 0777, true);
}

require_once $RUTA_IA . '/clasificar.php'; // aporta clasificarPeticion(), registrarLog()
require_once __DIR__ . '/../tickets/crear.php'; // aporta crearTicket() — guarda la petición confirmada en tickets.json
require_once __DIR__ . '/../tickets/estado.php'; // aporta cambiarEstadoTicket()
require_once __DIR__ . '/../tickets/correlativo_solicitud.php'; // aporta obtenerYAvanzarNumeroSolicitud() — número correlativo configurable de "Solicitud No."

// ------------------------------------------------------------
// 0.2 Detección de departamento para peticiones informativas
// ------------------------------------------------------------
/**
 * Detecta el departamento responsable según palabras clave en el mensaje.
 * Devuelve un array con id y nombre del departamento.
 * Si no se detecta, devuelve departamento por defecto (Catastro-COMRED, id=1).
 */
function detectarDepartamentoPorPalabras($mensaje) {
    $mensajeMin = mb_strtolower($mensaje, 'UTF-8');

    // Mapeo de palabras clave a departamento ID
    $mapa = [
        // Oficina 2: Juzgado de Asuntos Municipales
        'salón municipal|salon municipal|alquiler de salón|renta de salón|reservar salón|juzgado|asuntos municipales' => 2,
        // Oficina 3: Dirección Municipal de la Mujer
        'mujer|violencia de género|derechos de la mujer|equidad de género|feminicidio|atención a la mujer' => 3,
        // Oficina 4: Cultura y Deportes
        'cultura|deporte|evento cultural|feria|exposición|artístico|deportivo|actividad cultural|taller de arte|música|baile|emprendimiento' => 4,
        // Oficina 5: Niñez, Juventud y Adolescencia
        'niñez|juventud|adolescencia|beca|curso|taller educativo|escuela|colegio|niño|joven|adolescente|capacitación|intecap|kiche' => 5,
        // Oficina 1: Catastro-COMRED (riesgos)
        'catastro|comred|riesgo|desastre|temblor|inundación|deslave|árbol caído|derrumb' => 1,
    ];

    foreach ($mapa as $patron => $id) {
        if (preg_match('/' . $patron . '/u', $mensajeMin)) {
            // Obtener el nombre del departamento desde el archivo
            $departamentos = json_decode(file_get_contents(__DIR__ . '/../data/departamentos.json'), true);
            foreach ($departamentos as $dep) {
                if ($dep['id'] == $id) {
                    return ['id' => $dep['id'], 'nombre' => $dep['nombre']];
                }
            }
            return ['id' => $id, 'nombre' => "Departamento ID $id"];
        }
    }
    // Si no se detecta nada, asignar Catastro-COMRED por defecto (o el que prefieras)
    return ['id' => 1, 'nombre' => 'Catastro-COMRED'];
}

/**
 * Crea un ticket de tipo "Información" para ser incluido en el informe mensual.
 */
function crearTicketInformacion($numero, $mensaje, $departamento_id, $departamento_nombre, $nombre = null, $fotos = []) {
    $sanitizar = function ($valor) {
        return htmlspecialchars(strip_tags(trim((string) $valor)), ENT_QUOTES, 'UTF-8');
    };

    // Si no se proporcionó nombre, intentar extraer del mensaje
    if (empty($nombre)) {
        if (preg_match('/(?:mi\s+nombre\s+(?:completo\s+)?es|me\s+llamo|soy)\s+(\p{L}[\p{L}\'\-]*(?:\s+\p{L}[\p{L}\'\-]*){0,4})/iu', $mensaje, $m)) {
            $nombre = trim($m[1]);
            if (preg_match('/^(an[oó]nimo|anonimo)$/iu', $nombre)) {
                $nombre = 'Anónimo';
            }
        } else {
            $nombre = 'Anónimo';
        }
    }

    if (preg_match('/^(an[oó]nimo|anonimo)$/iu', $nombre)) {
        $nombre = 'Anónimo';
    }

    $datosTicket = [
        'remitente' => $numero,
        'usuario_id' => null,
        'mensaje_original' => $sanitizar($mensaje),
        'categoria' => 'informacion_ciudadana', // categoría especial para informes
        'urgencia' => 'baja', // las consultas informativas suelen ser de baja urgencia
        'departamento_id' => $departamento_id,
        'estado' => 'pendiente',
        'canal' => 'whatsapp',
        'expediente' => null, // no se genera expediente para información
        'nombre_ciudadano' => $nombre,
        'dpi' => '',
        'telefono_contacto' => $numero,
        'direccion' => '',
        'conversacion' => [], // no se guarda conversación completa para no duplicar
        'fotos' => $fotos,
        // Marcar como ticket de información
        'tipo_informacion' => true,
        'departamento_nombre' => $departamento_nombre,
    ];

    $ticketId = crearTicket($datosTicket);
    if ($ticketId) {
        registrarLog("Ticket de información #$ticketId creado para $numero (departamento: $departamento_nombre)", 'info', 'ia.log');
    } else {
        registrarLog("No se pudo crear ticket de información para $numero", 'error', 'errores.log');
    }
    return $ticketId;
}

// ------------------------------------------------------------
// 1. Leer y validar la petición entrante
// ------------------------------------------------------------
$input = json_decode(file_get_contents('php://input'), true);

$secret    = $input['secret']  ?? '';
$numero    = preg_replace('/\D/', '', $input['numero'] ?? '');
$mensaje   = trim($input['mensaje'] ?? '');
// El bot de Node avisa acá cuando el mensaje entrante traía una fotografía
// adjunta (con o sin descripción), y —si logró descargarla— la ruta donde
// la guardó dentro de whatsapp/fotos/ (ver whatsapp-bot/index.js).
$tieneFoto = !empty($input['tieneFoto']);
// El bot de Node avisa acá si este mensaje viene del propio número vinculado
// mientras el "modo prueba" está activado (ver whatsapp-bot/index.js). Sirve
// únicamente para poder reactivar automáticamente la IA justo después de
// avisar el silencio intencional por "atención humana en curso" (más abajo),
// de forma que quien está probando el sistema con su propio teléfono pueda
// seguir enviando mensajes de prueba sin quedar bloqueado esperando a que
// alguien "termine de atender" manualmente. Con un ciudadano real este flag
// nunca llega en true, así que su comportamiento no cambia en nada.
$modoPrueba = !empty($input['modoPrueba']);
// El bot de Node marca este flag cuando el mensaje fue escrito fuera del
// horario de atención (8:00-16:00 L-V, hora Guatemala) o es backlog de
// cuando el bot estaba apagado. En ese caso NO se responde al ciudadano:
// solo se registra el ticket como "petición informativa" (ver bloque 2.4).
$fueraDeHorario = !empty($input['fueraDeHorario']);
// El bot de Node ahora puede enviar un array de fotos (campo 'fotos') o una sola foto (campo 'foto')
$fotosEntrantes = [];
if (isset($input['fotos']) && is_array($input['fotos'])) {
    foreach ($input['fotos'] as $ruta) {
        $ruta = trim((string) $ruta);
        if ($ruta !== '' && preg_match('#^whatsapp/fotos/[0-9]+/[A-Za-z0-9_]+\.(jpg|jpeg|png|webp)$#', $ruta)) {
            $fotosEntrantes[] = $ruta;
        }
    }
} else {
    // Compatibilidad con el envío antiguo (una sola foto)
    $fotoUnica = trim((string) ($input['foto'] ?? ''));
    if ($fotoUnica !== '' && preg_match('#^whatsapp/fotos/[0-9]+/[A-Za-z0-9_]+\.(jpg|jpeg|png|webp)$#', $fotoUnica)) {
        $fotosEntrantes[] = $fotoUnica;
    }
}
// También se puede usar el campo 'foto' como respaldo
if (empty($fotosEntrantes) && !empty($input['foto'])) {
    $ruta = trim((string) $input['foto']);
    if ($ruta !== '' && preg_match('#^whatsapp/fotos/[0-9]+/[A-Za-z0-9_]+\.(jpg|jpeg|png|webp)$#', $ruta)) {
        $fotosEntrantes[] = $ruta;
    }
}
// Se conserva $fotoRutaEntrante (primera foto del arreglo, si existe) por
// compatibilidad con cualquier código más abajo que aún la use.
$fotoRutaEntrante = $fotosEntrantes[0] ?? '';
// Marcador especial que manda el bot cuando la foto llegó SIN descripción:
// no es una petición real todavía, así que se maneja aparte (más abajo)
// en vez de pasar por el clasificador.
$esFotoSinDescripcion = ($mensaje === '__FOTO_RECIBIDA__');

if ($secret !== BRIDGE_SECRET) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Secreto inválido']);
    exit;
}
if ($numero === '' || $mensaje === '') {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Faltan campos numero/mensaje']);
    exit;
}

// ------------------------------------------------------------
// 2. Cargar / crear la sesión de este número
// ------------------------------------------------------------
function rutaSesion($numero) {
    global $RUTA_SESIONES;
    return $RUTA_SESIONES . '/' . preg_replace('/[^0-9]/', '', $numero) . '.json';
}

function cargarSesion($numero) {
    $archivo = rutaSesion($numero);
    if (file_exists($archivo)) {
        $datos = json_decode(file_get_contents($archivo), true);
        if (is_array($datos)) return $datos;
    }
    return [
        'fase' => 'inicio',
        'texto_peticion' => null,
        'clasificacion' => null,
        'nombre' => null,
        'dpi' => null,
        'telefono' => null,
        'direccion' => null,
        // 'social' (área pública / beneficio comunitario: NO se piden nombre
        // ni teléfono) o 'particular' (afecta a la persona: se piden nombre
        // y teléfono). Se decide en la fase 'inicio' con detectarTipoInteres().
        'tipo_interes' => null,
        // Rutas (dentro de whatsapp/fotos/) de las fotografías que el
        // ciudadano fue enviando durante esta conversación, en el orden en
        // que llegaron. Se guarda a nivel de sesión para no perderlas
        // aunque lleguen antes que el resto de los datos, y se copian al
        // ticket final para que el oficio las muestre automáticamente.
        'fotos' => [],
        // Ver bloque "2.3 Atención humana" más abajo: cuando la IA no logra
        // identificar ningún trámite reconocido (ej. "nichos del
        // cementerio", "COCODES", algo que no está en el catálogo de
        // datos_ia.json), se pone este número en atención humana: el bot
        // deja de responder automáticamente en este chat hasta que se
        // detecte una nueva petición que sí se pueda clasificar.
        'ia_desactivada' => false,
        // Cuenta cuántas veces SEGUIDAS, en la fase 'inicio', el
        // ciudadano escribió algo que la IA no pudo clasificar (ver
        // bloque "2.3 Atención humana"). Se reinicia a 0 apenas se
        // identifica un trámite reconocido.
        'intentos_sin_clasificar' => 0,
    ];
}

function guardarSesion($numero, $sesion) {
    file_put_contents(rutaSesion($numero), json_encode($sesion, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function borrarSesion($numero) {
    $archivo = rutaSesion($numero);
    if (file_exists($archivo)) @unlink($archivo);
}

// ------------------------------------------------------------
// 1.2 Ventana de "post-cierre": durante los 30 minutos siguientes a
//     que un número registró una solicitud, si el ciudadano manda un
//     mensaje de aclaración o contexto sobre el mismo caso (ej.
//     "ayer le cayó un rayo y se descompuso"), el bot NO debe tratarlo
//     como una petición nueva ni volver a preguntar "contame más
//     detalle" (que era el comportamiento anterior, cuando la sesión
//     ya estaba borrada). En su lugar, responde brevemente confirmando
//     que anotó la aclaración y sigue esperando, sin reiniciar nada.
//
//     El marcador se guarda aparte de la sesión (porque la sesión se
//     borra al cerrar cada solicitud) y se limpia solo cuando llega una
//     petición nueva con señal clara, o cuando se cumplen los 30 min.
// ------------------------------------------------------------
function rutaPostCierre() {
    global $RUTA_SESIONES;
    return $RUTA_SESIONES . '/post_cierre.json';
}

function cargarPostCierre() {
    $archivo = rutaPostCierre();
    if (file_exists($archivo)) {
        $datos = json_decode(file_get_contents($archivo), true);
        if (is_array($datos)) return $datos;
    }
    return [];
}

function marcarPostCierre($numero) {
    $numeroLimpio = preg_replace('/[^0-9]/', '', (string) $numero);
    if ($numeroLimpio === '') return;
    $datos = cargarPostCierre();
    $datos[$numeroLimpio] = time();
    @file_put_contents(rutaPostCierre(), json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function tienePostCierreReciente($numero, $segundos = 1800) {
    $numeroLimpio = preg_replace('/[^0-9]/', '', (string) $numero);
    if ($numeroLimpio === '') return false;
    $datos = cargarPostCierre();
    if (!isset($datos[$numeroLimpio])) return false;
    return (time() - intval($datos[$numeroLimpio])) <= $segundos;
}

function limpiarPostCierre($numero) {
    $numeroLimpio = preg_replace('/[^0-9]/', '', (string) $numero);
    if ($numeroLimpio === '') return;
    $datos = cargarPostCierre();
    if (isset($datos[$numeroLimpio])) {
        unset($datos[$numeroLimpio]);
        @file_put_contents(rutaPostCierre(), json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}

// ------------------------------------------------------------
// 2.1 Historial completo de la conversación (para la evidencia del
//     reporte, sección "IV. Evidencia del Reporte" del oficio).
//
// A diferencia de la sesión (que solo guarda el ESTADO actual: fase,
// nombre, dpi, etc. y se borra al confirmar), acá se guarda cada
// intercambio de mensajes (lo que escribió el ciudadano + lo que
// respondió el bot) en un archivo aparte por número, para poder mostrar
// la conversación real como evidencia cuando se genera el oficio de la
// petición. Antes el reporte solo tenía un recuadro vacío que decía
// "[Espacio para adjuntar la captura real]", obligando a alguien a
// tomar pantallazos manualmente; ahora el propio sistema ya tiene el
// texto exacto de la conversación y lo puede mostrar automáticamente.
// ------------------------------------------------------------
function rutaConversacion($numero) {
    global $RUTA_SESIONES;
    $dir = $RUTA_SESIONES . '/conversaciones';
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    return $dir . '/' . preg_replace('/[^0-9]/', '', $numero) . '.jsonl';
}

/**
 * Agrega un turno (mensaje del ciudadano + respuesta del bot) al
 * historial. Se llama justo antes de cada respuesta que se le envía al
 * ciudadano, para que el archivo siempre refleje la conversación real en
 * el orden en que ocurrió.
 */
function registrarTurnoConversacion($numero, $mensaje, $respuesta) {
    $turno = [
        'hora'      => date('Y-m-d H:i:s'),
        'ciudadano' => $mensaje,
        'bot'       => $respuesta,
    ];
    @file_put_contents(rutaConversacion($numero), json_encode($turno, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * Lee todos los turnos guardados de un número, en orden cronológico.
 * Se usa al confirmar la solicitud, para copiar la conversación completa
 * dentro del ticket antes de que la sesión se reinicie.
 */
function leerConversacion($numero) {
    $archivo = rutaConversacion($numero);
    if (!file_exists($archivo)) return [];
    $lineas = file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lineas === false) return [];
    $turnos = [];
    foreach ($lineas as $linea) {
        $t = json_decode($linea, true);
        if (is_array($t)) $turnos[] = $t;
    }
    return $turnos;
}

/**
 * Borra el historial de conversación de un número. Se llama junto con
 * borrarSesion() al confirmar una solicitud (la conversación ya quedó
 * copiada dentro del ticket, así que no hace falta seguir arrastrándola)
 * y también al reiniciar/cancelar, para que la próxima petición del
 * mismo número no mezcle mensajes de una solicitud anterior ya cerrada.
 */
function borrarConversacion($numero) {
    $archivo = rutaConversacion($numero);
    if (file_exists($archivo)) @unlink($archivo);
}

/**
 * Busca el ticket MÁS RECIENTE creado por este número dentro de la
 * ventana indicada (en segundos). Devuelve el ID del ticket o null.
 *
 * Se usa para el caso en que el ciudadano manda la foto DESPUÉS de que
 * el bot ya registró la solicitud (típico cuando el texto y la foto
 * llegan en mensajes separados, y el debounce ya cerró el ticket). Sin
 * esto, la foto llegaba con la sesión borrada y el bot la trataba como
 * solicitud nueva, perdiendo la foto.
 */
function buscarTicketRecienteDelNumero($numero, $ventanaSegundos = 3600) {
    if (!file_exists(TICKETS_JSON)) return null;
    $tickets = json_decode(file_get_contents(TICKETS_JSON), true);
    if (!is_array($tickets)) return null;

    $numeroLimpio = preg_replace('/\D/', '', (string) $numero);
    if ($numeroLimpio === '') return null;

    $ahora = time();
    $mejor = null;
    $mejorTs = 0;
    foreach ($tickets as $t) {
        if (preg_replace('/\D/', '', (string) ($t['remitente'] ?? '')) !== $numeroLimpio) continue;
        $ts = strtotime($t['fecha_creacion'] ?? '');
        if ($ts === false) continue;
        if (($ahora - $ts) > $ventanaSegundos) continue;
        if ($ts > $mejorTs) {
            $mejorTs = $ts;
            $mejor = $t;
        }
    }
    return $mejor ? $mejor['id'] : null;
}

/**
 * Agrega una foto (ruta relativa dentro de whatsapp/fotos/) al arreglo
 * 'fotos' de un ticket existente. Usa el mismo bloqueo de archivo que
 * cambiarEstadoTicket() y crearTicket() para no corromper tickets.json.
 * Devuelve true si la foto quedó agregada.
 */
function adjuntarFotoATicket($ticketId, $rutaFoto) {
    if (!file_exists(TICKETS_JSON)) return false;
    if (!preg_match('#^whatsapp/fotos/[0-9]+/[A-Za-z0-9_]+\.(jpg|jpeg|png|webp)$#', $rutaFoto)) {
        return false;
    }

    $fp = fopen(TICKETS_JSON, 'c+');
    if (!$fp) return false;

    $ok = false;
    if (flock($fp, LOCK_EX)) {
        $contenido = '';
        fseek($fp, 0);
        while (!feof($fp)) $contenido .= fread($fp, 8192);
        $tickets = json_decode($contenido, true);
        if (!is_array($tickets)) $tickets = [];

        foreach ($tickets as &$t) {
            if ($t['id'] != $ticketId) continue;
            if (!isset($t['fotos']) || !is_array($t['fotos'])) $t['fotos'] = [];
            if (!in_array($rutaFoto, $t['fotos'], true)) {
                $t['fotos'][] = $rutaFoto;
                $ok = true;
            }
            $t['fecha_actualizacion'] = date('Y-m-d H:i:s');
            break;
        }
        unset($t);

        if ($ok) {
            ftruncate($fp, 0);
            fseek($fp, 0);
            fwrite($fp, json_encode($tickets, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            fflush($fp);
            if (function_exists('registrarLog')) {
                registrarLog("Fotografía adjuntada al ticket #$ticketId ($rutaFoto) tras haber sido creado.", 'info', 'ia.log');
            }
        }
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    return $ok;
}

// ------------------------------------------------------------
// 3. Validaciones (versión servidor, equivalentes a utils_ia.js)
// ------------------------------------------------------------
function esReinicio($t) {
    $t = mb_strtolower(trim($t));
    return in_array($t, ['reiniciar', 'cancelar', 'empezar de nuevo', 'nueva solicitud', 'menu', 'menú']);
}

/**
 * BUGFIX: "cancela la petición" no cancelaba nada.
 *
 * esReinicio() de arriba solo reconoce el mensaje EXACTO "cancelar" (sin
 * nada más alrededor). Si el ciudadano escribe una frase natural como
 * "Cancela la petición", "ya no quiero hacer la petición" o "mejor
 * cancelalo", esReinicio() no hace match (ni la palabra exacta "cancelar"
 * ni el mensaje completo coinciden) y el mensaje sigue cayendo en la fase
 * en curso (ej. "esperando_dpi"), que lo valida como si fuera un DPI y lo
 * rechaza — dejando al ciudadano atrapado repitiendo el mismo error sin
 * forma de salir.
 *
 * Esta función reconoce la INTENCIÓN de cancelar dentro de una frase más
 * larga (usando \b para no disparar con palabras parecidas). Solo se usa
 * cuando ya hay una conversación en curso (fase != 'inicio' y !=
 * 'modificando_peticion'): en esas dos fases el ciudadano está describiendo
 * su problema en texto libre, y ahí SÍ podría mencionar la palabra
 * "cancelar" siendo parte real de la petición (ej. "quiero cancelar mi
 * servicio de agua"), así que ahí no aplicamos este atajo.
 */
function esIntencionCancelar($t) {
    $norm = normalizarParaFiltro($t);
    $patrones = [
        // Original: solo cubría "cancela/cancelar/cancelalo/cancelame".
        // AMPLIADO a \bcancel\w*\b para que también reconozca otras
        // conjugaciones comunes ("cancelen", "cancélame", "cancelación",
        // "cancelo esto", etc.) sin tener que listarlas todas a mano.
        '/\bcancel\w*\b/u',
        '/\bya no quiero\b/u',
        '/\bmejor ya no\b/u',
        '/\bmejor no\b/u',
        '/\bolvidal[oa]\b/u',
        // BUGFIX: "No quiero reportar nada." (y similares) tardaban en
        // reconocerse como cancelación porque solo se cubría "no quiero
        // continuar/seguir": si el ciudadano decía directamente que ya no
        // quiere reportar/hacer nada, el bot lo seguía tratando como si
        // no hubiera entendido y repetía la pregunta pendiente (ej. "el
        // nombre completo"), en vez de cancelar de una vez. Se amplía
        // para reconocer también "reportar"/"hacer"/"nada" como verbo u
        // objeto de "no quiero".
        '/\bno quiero (continuar|seguir|reportar|hacer|nada)\b/u',
        '/\bno quiero\b[^.\n]{0,25}\bnada\b/u',
        '/\bno quiero hacer (esto|esta solicitud|esta peticion)\b/u',
        // BUGFIX: variante con un typo real y común en WhatsApp ("ya lo
        // quiero reportar nada", queriendo decir "ya NO quiero reportar
        // nada" — se comió el "no"). En vez de intentar adivinar cada
        // typo posible, se reconoce la frase "reportar nada" tal cual:
        // nadie dice eso queriendo SÍ reportar algo, así que por sí sola
        // ya es una señal clara de que quiere cancelar.
        '/\breportar\s+nada\b/u',
        '/\bdejalo\s+as[ií]\b/u',
        '/\bdejemoslo\s+as[ií]\b/u',
        // AGREGADO: más formas naturales/coloquiales de pedir que se
        // detenga o elimine todo el trámite en curso, además de las
        // originales de arriba.
        '/\banula(r|lo|la|me)?\b/u',
        '/\bborra\s+(todo|esto|la\s+solicitud|la\s+petici[oó]n)\b/u',
        '/\bquita\s+(todo|esto)\b/u',
        '/\bno\s+(sigas|sigamos|continues|continuemos)\b/u',
        '/\bretir(o|a|ar)\b[^.\n]{0,20}\b(solicitud|peticion|petici[oó]n|tramite|tr[aá]mite)\b/u',
    ];
    foreach ($patrones as $p) {
        if (preg_match($p, $norm)) return true;
    }
    return false;
}

/**
 * BUGFIX: el ciudadano quedaba atrapado en un bucle pidiendo el DPI (o
 * teléfono/dirección) una y otra vez aunque SÍ estaba contestando que no lo
 * tenía, simplemente no con las palabras exactas que la función esperaba.
 *
 * Antes esOmitir() solo reconocía el texto literal "sin dpi" o "no tengo"
 * pegado al inicio del mensaje. Un ciudadano real no escribe así de
 * mecánico: escribe "No lo tengo a la mano" (con "lo" en medio, rompe el
 * match de "no tengo"), "No dpi" (no es "sin dpi"), "no sé", "ahorita no",
 * "no cuento con eso", etc. Todas esas variantes antes se rechazaban como
 * si fueran un intento fallido de escribir el número, y el bot repetía el
 * mismo mensaje de error sin salida posible más que adivinar la frase
 * exacta "sin dpi".
 *
 * Esta versión cubre las formas más comunes en que alguien dice "no lo
 * tengo" en español guatemalteco/coloquial, sin dejar de aceptar también
 * las frases originales ("sin dpi", "omitir", "ninguno", etc.).
 */
function esOmitir($t) {
    $norm = normalizarParaFiltro(trim($t));
    // Quita puntuación final (¡"No dpi."¡ / "¿no sé?" etc.) para no fallar
    // el match solo por un punto o signo de exclamación pegado al final.
    $norm = trim(preg_replace('/[!¡?¿.,]+$/u', '', $norm));

    $patrones = [
        // "sin dpi", "sin teléfono", "sin dirección", "sin eso", "sin nada"
        '/^sin\s+(dpi|telefono|direccion|eso|nada|ninguno)\b/u',
        // "no tengo", "no lo tengo", "no la tengo", "no tengo a la mano",
        // "no cuento con eso", "no manejo dpi", "no recuerdo", "no me acuerdo"
        '/^no\s+(lo\s+|la\s+)?(tengo|tenemos|cuento\s+con|manejo|recuerdo|me\s+acuerdo)(\s+\p{L}+)*$/u',
        // "no", "no dpi", "no telefono", "no direccion" (respuesta corta y
        // directa, muy común cuando el ciudadano solo quiere decir "no lo
        // tengo" sin escribir la frase completa)
        '/^no(\s+(dpi|telefono|direccion|tengo))?$/u',
        '/^omitir$/u',
        '/^ninguno$/u',
        '/^no\s+aplica$/u',
        '/^n\/?a$/u',
        '/^no\s+s[eé]$/u',
        // "ahorita no", "por ahora no", "todavía no", "de momento no"
        // (con o sin "tengo" al final)
        '/^(ahorita|por\s+ahora|todav[ií]a|de\s+momento)\s+no(\s+tengo)?$/u',
    ];
    foreach ($patrones as $p) {
        if (preg_match($p, $norm)) return true;
    }
    return false;
}

/**
 * Detecta mensajes de agradecimiento, cumplido o despedida ("Gracias por
 * la atención", "Muy amables", "Hasta luego"), para NO confundirlos con
 * una nueva petición. Se compara el mensaje COMPLETO (no una palabra
 * suelta dentro de una frase larga) contra frases típicas de cierre, para
 * no descartar por error una petición real que mencione "gracias" de
 * pasada dentro de un texto más largo.
 */
function esAgradecimientoODespedida($t) {
    $original = trim(normalizarParaFiltro($t));
    if ($original === '') return false;

    // Soporte para mensajes compuestos por comas del tipo
    // "gracias por el apoyo, que tenga un buen dia". Antes esto NO se
    // reconocía (cada patrón exige que el mensaje TERMINE justo después de
    // la frase reconocida), así que el bot lo trataba como una aclaración
    // sobre el ticket anterior (bloque "post-cierre") en vez de contestar
    // con cortesía.
    //
    // Estrategia: si el mensaje tiene comas, se divide en partes y se
    // verifica que TODAS sean a su vez un agradecimiento o despedida. Si
    // es así, el mensaje completo se considera agradecimiento/despedida.
    // Se trabaja sobre el texto normalizado ANTES de quitar puntuación
    // para poder usar las comas como separador real.
    $sinPuntoFinal = trim(preg_replace('/[!¡.]+$/u', '', $original));
    $sinMuletillaInicial = trim(preg_replace('/^(ok|vale|va|listo|bueno)\s*[,]?\s*/u', '', $sinPuntoFinal));

    // Segmentos cortos de saludo/despedida que valen DENTRO de una frase
    // compuesta (aunque solos serían ambiguos como saludo). Ej. "gracias,
    // buenas noches" -> ambas partes válidas, pero "buenas noches" sola
    // debe seguir cayendo en el saludo de bienvenida, no aquí.
    $regexSegmentoDespedidaCorta = '/^(buenas?\s+(noches?|tardes?|d[ií]as?)|buenos?\s+d[ií]as?|excelente\s+d[ií]a|buen\s+d[ií]a)$/u';

    if (strpos($sinMuletillaInicial, ',') !== false) {
        $partes = array_map('trim', explode(',', $sinMuletillaInicial));
        $partes = array_values(array_filter($partes, function ($p) { return $p !== ''; }));
        if (count($partes) > 1) {
            $todasOk = true;
            foreach ($partes as $parte) {
                if (preg_match($regexSegmentoDespedidaCorta, $parte)) {
                    continue; // segmento corto tipo "buenas noches" cuenta
                }
                if (!esAgradecimientoODespedida($parte)) {
                    $todasOk = false;
                    break;
                }
            }
            if ($todasOk) return true;
            // Si alguna parte no lo es, seguimos con el análisis normal
            // del mensaje completo (por si igual calza con algún patrón).
        }
    }

    // --- Análisis normal del mensaje completo (comportamiento original) ---
    $norm = $sinPuntoFinal;
    // Se quitan también los emojis y signos sueltos ("Gracias 🙏",
    // "Gracias, igualmente!"). Cualquier símbolo dentro del mensaje hacía
    // que ya no calzara con ningún patrón.
    $norm = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $norm);
    $norm = trim(preg_replace('/\s+/u', ' ', $norm));

    // Muletilla de confirmación al inicio ("Ok, gracias por el apoyo",
    // "Vale, gracias") — se quita para que el resto calce con los patrones.
    $norm = preg_replace('/^(ok|vale|va|listo|bueno)\s*[,]?\s*/u', '', $norm);
    $norm = trim($norm);
    if ($norm === '') return false;

    // Vocativo/muletilla cariñosa opcional que el ciudadano suele agregar
    // al final de un agradecimiento (ej. "Gracias por la atención amor",
    // "Gracias profe"), para no rechazar el agradecimiento solo por eso.
    $vocativo = '(\s+(mi\s+)?(amor|corazon|corazoncito|reina|reyna|rey|jefe|jefa|profe|profa|maestro|maestra|amigo|amiga|hermano|hermana|colega|joven|senor|senora|senorita|don|dona|caballero))?';

    $patrones = [
        // "gracias", "gracias por el apoyo", "gracias por todo", etc.
        '/^(muchas |mil |un monton de |bastantes |muchisimas |)gracias(\s+por\s+(la\s+atencion|todo|su\s+ayuda|la\s+ayuda|el\s+apoyo|su\s+apoyo|el\s+respaldo|su\s+respaldo|su\s+tiempo|la\s+informacion))?' . $vocativo . '$/u',
        // "gracias a ustedes", "gracias a usted por todo", "gracias, igualmente"
        '/^(muchas |mil |un monton de |bastantes |muchisimas |)gracias(\s+a\s+(ustedes|usted|vos|ti|todos|todas|dios))?(\s+por\s+(la\s+atencion|todo|su\s+ayuda|la\s+ayuda|el\s+apoyo|su\s+apoyo|el\s+respaldo|su\s+respaldo|su\s+tiempo|la\s+informacion))?(\s+(igualmente|igual|tambien|de\s+igual\s+(manera|forma)|de\s+antemano))?' . $vocativo . '$/u',
        '/^(igualmente|igual|de\s+igual\s+(manera|forma)|a\s+ustedes|a\s+usted|para\s+usted\s+tambien|para\s+ustedes\s+tambien)(\s+gracias)?' . $vocativo . '$/u',
        '/^(que\s+)?(dios|diosito)\s+(los|le|les|te)\s+(bendiga|bendiga\s+mucho|pague|guarde|acompane)(\s+\w+)?$/u',
        '/^(bendiciones|muchas\s+bendiciones)' . $vocativo . '$/u',
        '/^te\s+lo\s+agradezco(\s+mucho)?' . $vocativo . '$/u',
        '/^(estoy|estamos)\s+(muy\s+)?agradecid[oa]s?' . $vocativo . '$/u',
        '/^de\s+nada$/u',
        '/^(excelente|muy buen[oa]|buen[oa])\s+(atencion|servicio|trabajo)$/u',
        '/^(son|estan)\s+muy\s+amables?$/u',
        // Variantes sueltas muy comunes de cortesía.
        '/^muy\s+amables?$/u',
        '/^muy\s+amable\s+de\s+su\s+parte$/u',
        '/^muchas\s+gracias\s+de\s+verdad$/u',
        '/^gracias\s+(por\s+todo(\s+su\s+apoyo)?|mil)$/u',
        '/^felicidades$/u',
        '/^(adios|hasta\s+luego|hasta\s+pronto|hasta\s+la\s+vista|hasta\s+manana|nos\s+vemos|chau|bye)$/u',
        // "que tenga un buen día", "que tengas linda tarde", "que pase buena noche"
        '/^que\s+(tengas?|pase[ns]?|tenga)\s+(una?\s+)?(buena?|buen|linda|bonita|excelente|feliz)\s+(noche|d[ií]a|tarde|semana|fin\s+de\s+semana)$/u',
        // "feliz noche", "buena tarde", "excelente día"
        // (sin "buen" suelto: "buen día" solo es un saludo de bienvenida)
        '/^(feliz|linda|bonita|excelente|buena)\s+(noche|d[ií]a|tarde|semana|fin\s+de\s+semana)s?$/u',
        '/^(cuidate|cuidense|nos\s+estamos\s+hablando|hablamos\s+luego|hablamos\s+despues)$/u',
        // "que descanses", "que duermas bien", "que descansen"
        '/^que\s+(descanses?|duermas?\s+bien|descansen)$/u',
        // "saludes a la familia", "saludos a todos"
        '/^saludes?\s+(a\s+(la\s+familia|todos|todas|su\s+familia))?$/u',
        // Se conserva del código anterior
        '/^(esta bien|está bien)\s+(feliz|buena)?\s*(noche|tarde|dia|día)$/u',
    ];
    foreach ($patrones as $p) {
        if (preg_match($p, $norm)) return true;
    }

    // NUEVO: mensaje que EMPIEZA con un agradecimiento seguido de un
    // comentario o despedida. Ejemplo real que fallaba: "Ok, gracias
    // por el apoyo y espero mejoren la calidad del agua." — el bot lo
    // reclasificaba como petición nueva (veía la palabra "agua" y
    // arrancaba otra vez el flujo) en vez de responder con una
    // despedida cordial. Los patrones estrictos de arriba exigen que
    // el mensaje TERMINE justo después del agradecimiento, así que
    // este caso se evalúa aparte: si lo que sigue al "gracias..." NO
    // contiene una nueva petición clara, se considera despedida.
    $prefijosGracias = [
        'muchas gracias por la atencion', 'muchas gracias por su atencion',
        'muchas gracias por todo', 'muchas gracias por su ayuda',
        'muchas gracias por la ayuda', 'muchas gracias por el apoyo',
        'muchas gracias por su apoyo', 'muchas gracias por su tiempo',
        'muchas gracias por la informacion', 'muchas gracias',
        'gracias por la atencion', 'gracias por su atencion',
        'gracias por todo', 'gracias por su ayuda', 'gracias por la ayuda',
        'gracias por el apoyo', 'gracias por su apoyo',
        'gracias por su tiempo', 'gracias por la informacion',
        'gracias a ustedes', 'gracias a usted', 'gracias a dios',
        'gracias',
    ];
    foreach ($prefijosGracias as $pref) {
        // El prefijo tiene que estar AL INICIO del mensaje ya normalizado
        // (minúsculas, sin tildes, sin puntuación, sin muletilla "ok/vale").
        if (strpos($norm, $pref) !== 0) continue;

        $resto = trim(substr($norm, strlen($pref)));
        $resto = trim($resto, " ,.:;!¡¿?\n\t");

        // Si el mensaje termina justo después del agradecimiento, los
        // patrones estrictos de arriba ya lo cubrieron; no hay nada que
        // hacer acá y salimos del bucle.
        if ($resto === '') break;

        // Señales de NUEVA petición en el "resto": si aparecen, NO es
        // despedida (podría ser "gracias, pero hay un bache..." y en ese
        // caso hay que dejar que el clasificador general lo procese).
        $patronNuevaPeticion = '/\b('
            . 'no\s+hay|no\s+tengo|no\s+tenemos|no\s+funciona|no\s+sirve|no\s+pasa|'
            . 'hay\s+un|hay\s+una|hay\s+unos|hay\s+unas|'
            . 'tengo\s+un|tengo\s+una|tenemos\s+un|tenemos\s+una|'
            . 'se\s+revent|se\s+cay[oó]|se\s+call[oó]|se\s+quem|se\s+rompi|se\s+descomp|'
            . 'est[aá]\s+rot[oa]|est[aá]n?\s+rot[oa]s?|est[aá]\s+da[ñn]ad|'
            . 'necesito\s+report|quiero\s+report|quiero\s+denunci|'
            . 'necesito\s+que|quiero\s+que|quisiera\s+que|'
            . 'reportar\s+un|reportar\s+una'
            . ')\b/u';
        if (!preg_match($patronNuevaPeticion, $resto)) {
            return true;
        }
        break;
    }

    return false;
}

/**
 * Detecta peticiones sobre temas que el sistema de tickets NO puede
 * procesar: inscripciones a ferias, cursos, talleres, capacitaciones,
 * becas, convocatorias de empleo, preguntas sobre fecha/hora/lugar de
 * actividades o eventos municipales, etc. No son un daño/reporte de
 * infraestructura (bacheo, agua, alumbrado...) ni un trámite documental
 * de los que maneja el catálogo de la IA, así que jamás van a encontrar
 * una categoría por más detalle que el ciudadano agregue — no tiene
 * sentido hacerlo pasar por 2 intentos fallidos de "contame más". Lo
 * correcto es avisarle y pasarlo directo con la encargada desde el
 * primer mensaje (ver uso en fase 'inicio' más abajo).
 *
 * Casos reales que motivaron esto: "quisiera saber como me puedo
 * inscribir a la feria que se realizará en madre mía", "Aún puedo
 * inscribirme en los cursos de kiche?", "Estoy averiguando cuando va
 * hacer la actividad del maratón del ICEBOC".
 */
function esPeticionFueraDeAlcance($t) {
    $norm = normalizarTexto($t);

    // 1) Inscripción/participación + feria/curso/taller/evento/etc.
    if (preg_match('/\binscri\w*\b.{0,40}\b(feria|ferias|curso|cursos|taller|talleres|capacitacion|capacitaciones|charla|charlas|diplomado|evento|eventos|actividad|actividades|competencia|competencias|competicion|competiciones|concurso|concursos|campeonato|campeonatos|torneo|torneos)\b/u', $norm)) {
        return true;
    }
    if (preg_match('/\b(feria|ferias|curso|cursos|taller|talleres|capacitacion|capacitaciones|charla|charlas|diplomado|evento|eventos|competencia|competencias|competicion|competiciones|concurso|concursos|campeonato|campeonatos|torneo|torneos)\b.{0,40}\b(inscri\w*|apunt\w*|matricul\w*|cupo|cupos)\b/u', $norm)) {
        return true;
    }
    // 2) Cualquier mención de curso / taller / capacitación / charla /
    //    diplomado / CLASE, con o sin "de <tema>" (ej. "vi el anuncio del
    //    curso", "información sobre este curso", "¿qué día dan las clases?").
    //    Siempre es consulta informativa.
    // AMPLIADO: se agrega "clase|clases" para capturar mensajes de seguimiento
    // que llegan SIN la palabra "curso" pero que siguen siendo sobre el mismo
    // curso (caso real: chat 08, "Que dia dan las clases es de lunes a
    // viernes o fin de semana").
    if (preg_match('/\b(curso|cursos|taller|talleres|capacitacion|capacitaciones|charla|charlas|diplomado|diplomados|clase|clases)\b/u', $norm)) {
        return true;
    }
    // AMPLIADO: consultas sobre cursos/clases/inscripción que no traen la
    // palabra exacta "curso" ni "clase" (ej. "Aaa y requisitos para
    // inscribirme", "cómo me inscribo", "fecha de inscripción").
    if (preg_match('/\b('
        . 'anuncio\s+(del?|de\s+la)\s+(curso|taller|capacitacion|charla|evento|actividad)|'
        . 'requisitos?\s+(para|del?)\s+(el\s+|la\s+)?(inscripcion|inscribir\w*)|'
        . 'fechas?\s+(de|para|del?)\s+(la\s+)?(inscripcion|inscribir\w*)|'
        . 'como\s+(me\s+)?(inscribo|inscribirme|apunto|apuntarme)|'
        . 'donde\s+(me\s+)?(inscribo|inscribirme|apunto|apuntarme)'
        . ')\b/u', $norm)) {
        return true;
    }
    // 3) Ferias y emprendimiento (eventos comunitarios/culturales).
    if (preg_match('/\bferia(s)?\b|\bemprendimiento(s)?\b/u', $norm)) {
        return true;
    }
    // 4) Becas, empleo, convocatorias.
    if (preg_match('/\bbeca(s)?\b|\bconvocatoria(s)?\b|\bvacante(s)?\b|\bbolsa\s+de\s+empleo\b/u', $norm)) {
        return true;
    }
    // 5) Preguntas informativas sobre actividades/eventos municipales
    //    (fecha, hora o lugar), sin pedir reportar ningún problema. No
    //    importa el orden de las palabras: "cuando va a ser la actividad
    //    del maratón", "a qué hora es el evento", "el torneo cuándo es",
    //    "estoy averiguando cuando va hacer la actividad del maratón del
    //    ICEBOC" (caso real que motivó esto). Se compara ya sin acentos
    //    ni signos de interrogación (ver normalizarTexto()).
    //
    // AGREGADO: "competencia/competición de ajedrez", "concurso",
    // "campeonato" — antes solo se reconocían actividad/evento/maratón/
    // torneo/desfile/etc., y una consulta real como "¿dónde podría
    // averiguar sobre la competición de ajedrez?" no calzaba con ninguna
    // palabra de la lista, así que el bot la trataba como un reporte de
    // problema mal descrito en vez de derivarla a la encargada.
    $palabraInterrogativa = '(cuando|que\s+dia|a\s+que\s+hora|donde|averigu\w*'
        // AGREGADO: formas naturales en que la gente pide información sobre
        // un evento, sin usar las palabras interrogativas clásicas. Caso
        // real que motivó esto: "quiero preguntar sobre el evento de fin de
        // año que se desarrollará en frente del parque" — menciona "evento"
        // pero ninguna de las preguntas clásicas, así que no calzaba con
        // ningún patrón y el clasificador general lo tomaba como Gestión
        // Ambiental por la palabra "parque".
        . '|pregunt\w*|consult\w*|saber|conocer|informaci\w*|pregunta\w*)';
    $palabraEvento = '(actividad(es)?|evento(s)?|maraton(es)?|torneo(s)?|desfile(s)?|caminata(s)?|carrera(s)?|celebracion(es)?|conmemoracion(es)?|festival(es)?|festejo(s)?|competencia(s)?|competicion(es)?|concurso(s)?|campeonato(s)?|actividades|presentacion(es)?|exposicion(es)?|show(s)?|concierto(s)?|procesion(es)?|inaguracion(es)?|inauguracion(es)?)';
    if (
        preg_match('/\b' . $palabraInterrogativa . '\b.{0,60}\b' . $palabraEvento . '\b/u', $norm)
        || preg_match('/\b' . $palabraEvento . '\b.{0,60}\b' . $palabraInterrogativa . '\b/u', $norm)
    ) {
        return true;
    }

    // 6) AGREGADO: el ciudadano manda/comparte contenido multimedia
    //    informativo (video, foto, audio, grabación) — típicamente el
    //    caption de una foto/video que solo avisa qué es lo que está
    //    adjuntando (ej. "Mando el vídeo del concurso de Canto"), NO un
    //    reporte de un daño o falta de servicio. Antes esto pasaba por
    //    clasificarPeticion() sin encontrar ninguna categoría real, y el
    //    ciudadano quedaba atrapado pidiéndole "más detalle" sobre un
    //    problema que nunca existió, en vez de derivarlo directo con la
    //    encargada (como sí se hace con ferias/cursos/becas más arriba).
    //    Caso real que motivó esto: "Mando el vídeo del concurso de
    //    Canto" → el bot respondía "Necesito un poco más de información
    //    para poder ayudarle: ¿qué está pasando y en dónde?", un loop sin
    //    salida porque nunca hay un "problema" que describir.
    $verboEnvio = '(mando|manda|te\s+mando|le\s+mando|les\s+mando|aqui\s+(te\s+|le\s+|les\s+)?mando'
        . '|aqui\s+esta|aqui\s+est[aá]|comparto|te\s+comparto|le\s+comparto|les\s+comparto'
        . '|adjunto|te\s+adjunto|le\s+adjunto|env[ií]o|te\s+env[ií]o|le\s+env[ií]o|les\s+env[ií]o)';
    $contenidoMultimedia = '(video(s)?|foto(s)?|fotografia(s)?|imagen(es)?|audio(s)?|grabacion(es)?|nota(s)?\s+de\s+voz)';
    if (preg_match('/\b' . $verboEnvio . '\b.{0,40}\b' . $contenidoMultimedia . '\b/u', $norm)) {
        return true;
    }
    // También cuando describe contenido multimedia DE un evento/actividad
    // (ej. "el video del concurso de canto", "fotos de la feria"), aunque
    // no traiga un verbo explícito de "mando"/"comparto": sigue siendo
    // informativo, no un reporte accionable.
    if (preg_match('/\b' . $contenidoMultimedia . '\b.{0,20}\bde(l)?\b.{0,20}\b' . $palabraEvento . '\b/u', $norm)) {
        return true;
    }
    return false;
}

/**
 * Detecta peticiones sobre infraestructura ferroviaria (vías/rieles del
 * tren, línea férrea, ferrocarril): NO son responsabilidad municipal (son
 * competencia de la entidad ferroviaria nacional, cuando existe) y, además,
 * en San Pablo Jocopilas y en el resto del país esas vías ya no están en
 * operación y no se reparan, así que cualquier "petición" de este tipo es
 * casi siempre un chiste/troleo o, en el mejor de los casos, un pedido que
 * el sistema de tickets jamás va a poder clasificar ni gestionar.
 *
 * Caso real que motivó esto: un ciudadano escribió "Realice las vías del
 * tren Xd" (la "Xd" ya delata que es una broma). El bot intentó tratarlo
 * como un reporte real y le preguntó "¿qué está pasando y en dónde?",
 * generando una conversación confusa y sin salida. Como esPeticionFueraDeAlcance()
 * ya no puede seguir creciendo con casos cada vez más específicos, este es
 * un detector aparte: en vez de que el bot intente interpretarlo, se marca
 * como falso positivo y se deriva DIRECTO con la encargada desde el primer
 * mensaje (igual que ferias/cursos/becas y trámites administrativos, ver
 * mensajeAtencionHumanaUnificado()), para que sea una persona quien decida
 * qué hacer con el caso.
 */
function esPeticionInfraestructuraFerroviaria($t) {
    $norm = normalizarTexto($t);

    // "vía(s)/riel(es) del tren", "línea férrea", "vía férrea", "ferrocarril".
    if (preg_match('/\bv[ií]a(s)?\s+(del\s+|de\s+)?tren\b/u', $norm)) {
        return true;
    }
    if (preg_match('/\briel(es)?\s+(del\s+|de\s+)?tren\b/u', $norm)) {
        return true;
    }
    if (preg_match('/\bv[ií]a(s)?\s+f[eé]rrea(s)?\b|\bl[ií]nea(s)?\s+f[eé]rrea(s)?\b/u', $norm)) {
        return true;
    }
    if (preg_match('/\bferrocarril(es)?\b/u', $norm)) {
        return true;
    }
    // "tren" junto con un verbo de reparación/construcción (ej. "arreglen
    // el tren", "hagan las vías del tren", "reparar el tren"), por si el
    // ciudadano no usa exactamente la palabra "vía"/"riel".
    if (preg_match('/\b(reali[cz]\w*|arregl\w*|repar\w*|constru\w*|hagan|haga)\b.{0,25}\btren(es)?\b/u', $norm)) {
        return true;
    }

    // AGREGADO: referencias a "unicornio(s)" (y otros animales mitológicos
    // usados típicamente en bromas). Nadie reporta en serio un unicornio en
    // la vía pública: es señal inequívoca de que el mensaje no es una
    // petición real, sino un chiste.
    if (preg_match('/\bunicornio(s)?\b|\bdragon(es)?\b|\bsirena(s)?\b|\bduende(s)?\b|\bhombre\s+lobo\b|\bzombi(e)?s?\b/u', $norm)) {
        return true;
    }

    return false;
}

/**
 * Devuelve el mensaje de derivación a atención humana con saludo según la hora.
 * Ahora es una sola burbuja (sin fragmentar).
 */
function mensajeAtencionHumanaUnificado() {
    $saludo = saludoPorHora();
    return $saludo . '. En un momento la encargada se unirá a este chat para atenderle. Por favor, espere un momento.';
}

/**
 * NUEVO: mensajes específicos para SOLICITUDES DE INFORMACIÓN.
 * Distintos a los de atención humana genérica (que se usan cuando
 * la IA no entendió la petición). Aquí sabemos que la persona
 * quiere información, no reportar un problema.
 */
function mensajeInformacionAleatorio() {
    $mensajes = [
        "Gracias por su consulta. En un momento nuestra encargada le brindará la información que necesita.",
        "Permítame un momento, nuestra encargada le atenderá para darle la información solicitada.",
        "Su consulta será atendida por nuestra encargada en breve. Por favor espere un momento.",
        "Gracias por escribirnos. En un momento le atenderá nuestra encargada con la información que busca.",
        "Un momento por favor, en breve nuestra encargada le comparte la información que necesita.",
    ];
    return $mensajes[array_rand($mensajes)];
}

/**
 * Detecta trámites administrativos (Uso del Salón Municipal, Boleto de
 * Ornato, constancias, certificaciones, etc.) que el catálogo de
 * "IA 2/datos_ia.json" NO tiene mapeados como categoría propia con
 * palabras clave (a día de hoy solo existen: bacheo, alumbrado_publico,
 * fuga_agua, recoleccion_basura, gestion_ambiental y catastro — no hay
 * ninguna categoría "administrativo" con palabras_clave reales, así que
 * clasificarPeticion() SIEMPRE cae en el valor por defecto sin poder
 * identificarlo de verdad, ver clasificar.php).
 *
 * Igual que esPeticionFueraDeAlcance(), esto evita hacer pasar al
 * ciudadano por los 2 intentos de "contame más detalle" para algo que el
 * sistema nunca va a poder clasificar por sí solo: se avisa y se pasa
 * directo con la encargada desde el primer mensaje (ver uso en fase
 * 'inicio' más abajo).
 *
 * Si en el futuro se agrega una categoría "administrativo" real con sus
 * propias palabras_clave en datos_ia.json, esta función debería
 * revisarse/eliminarse para dejar que el clasificador la maneje.
 */
function esTramiteAdministrativoSinCategoria($t) {
    $norm = normalizarTexto($t);

    // 1) Uso del Salón Municipal (alquiler, renta, reserva, solicitud de uso).
    if (preg_match('/\bsalon(es)?\b.{0,30}\bmunicipal(es)?\b|\bmunicipal(es)?\b.{0,30}\bsalon(es)?\b/u', $norm)) {
        return true;
    }
    if (preg_match('/\b(alquil\w*|rent\w*|reserv\w*|us\w*|prest\w*)\b.{0,20}\bsalon(es)?\b/u', $norm)) {
        return true;
    }

    // 2) Boleto de Ornato.
    if (preg_match('/\bboleto(s)?\s+(de\s+)?ornato\b|\bornato\b/u', $norm)) {
        return true;
    }

    // 3) Trámites administrativos genéricos (constancias, certificaciones,
    //    solvencia municipal, paz y salvo, permisos/licencias municipales,
    //    cambio de nombre, IUSI, etc.), sin importar cómo los mencione el
    //    ciudadano.
    if (preg_match(
        '/\btramite(s)?\s+administrativo(s)?\b|\bconstancia(s)?\b|\bcertificacion(es)?\b'
        . '|\bcertificado(s)?\s+municipal(es)?\b|\bsolvencia\s+municipal\b|\bpaz\s+y\s+salvo\b'
        . '|\bpermiso(s)?\s+municipal(es)?\b|\blicencia(s)?\s+municipal(es)?\b|\bcambio\s+de\s+nombre\b|\biusi\b/u',
        $norm
    )) {
        return true;
    }

    return false;
}

/**
 * Detecta consultas INFORMATIVAS sobre servicios municipales: el ciudadano
 * NO está reportando un problema (aunque mencione el servicio), sino
 * PREGUNTANDO algo: horarios del agua, cuándo pasa el tren de aseo, cómo
 * hacer un pago, etc.
 *
 * Caso real que motivó esto: "Hola buenas tardes, fíjese que desde ayer no
 * hay agua, por lo cual le pido amablemente que me indique cuáles son los
 * horarios del agua." — el clasificador detecta "no hay agua" y lo registra
 * como reporte de Agua y Saneamiento (urgencia alta), pero en realidad el
 * ciudadano está pidiendo INFORMACIÓN (los horarios), no reportando la
 * falta. Antes esto se quedaba como ticket de reporte y se le pedía la
 * ubicación al ciudadano; lo correcto es derivarlo directo con la
 * encargada (como ya se hace con ferias, cursos, becas, etc.).
 *
 * La regla exige DOS cosas a la vez:
 *   1) un INDICIO claro de que el ciudadano pregunta / pide información
 *      ("me indique", "horarios", "quisiera saber", "cuándo pasa"…), y
 *   2) que mencione algún SERVICIO municipal (agua, basura, alumbrado…).
 * Si falta cualquiera de las dos, se sigue el flujo normal (reporte).
 */
function esConsultaInformativaDeServicio($t) {
    $norm = normalizarTexto($t);

    // 1) ¿Parece una pregunta / petición de información?
    $indiciosConsulta = [
        'horario', 'horarios',
        'a que hora', 'a que horas',
        'cuando pasa', 'cuando pasan',
        'cuando viene', 'cuando vienen',
        'cuando es el', 'cuando sera',
        'cuando va a ser', 'cuando van a',
        'me indique', 'me indica',
        'me puede indicar', 'me podria indicar',
        'me puede decir', 'me podria decir',
        'quisiera saber', 'queria saber', 'quiero saber',
        'me gustaria saber', 'necesito saber',
        'informacion sobre', 'informacion de',
        'como puedo saber', 'como se hace',
        'donde puedo averiguar', 'donde averiguo',
        'averiguar sobre', 'averiguar de',
        'necesito informacion',
        'saber cual es', 'saber cuales son',
        'saber cuando', 'saber a que hora',
        // NUEVAS variantes frecuentes en preguntas sobre horarios/servicios.
        'a que hrs',
        'que hrs',
        'que horas',
        'que dias',
        'que dia',
        'cuando reponen',
        'cuando lo reponen',
        'cuando restablecen',
        'cuando van a restablecer',
        'cuando regresa',
        'cuando regresara',
        'cuando vuelve',
        'cuando van a poner',
        'cuando van a mandar',
        'cuando reparten',
        'si hoy habra',
        'si hoy va a haber',
        'si va a haber',
        'si ya esta',
        'si ya lo restablecieron',
        'por que no ha llegado',
        'por que no esta llegando',
        'por que no llega',
        'por que todavia no',
        'me pueden decir',
        'me puede informar',
        'me pueden informar',
        'me gustaria preguntar',
        'me gustaria consultar',
        'quisiera preguntar',
        'queria preguntar',
        'quiero preguntar',
        'quisiera consultar',
        'queria consultar',
        'quiero consultar',
        'saber sobre',
        'informacion del servicio',
        'informacion del agua',
    ];
    $tieneIndicio = false;
    foreach ($indiciosConsulta as $indicio) {
        if (strpos($norm, $indicio) !== false) { $tieneIndicio = true; break; }
    }
    if (!$tieneIndicio) return false;

    // 2) ¿Menciona algún servicio municipal?
    $servicios = [
        'agua', 'basura', 'tren de aseo', 'recoleccion',
        'alumbrado', 'tasa', 'servicio', 'pago del agua',
    ];
    foreach ($servicios as $s) {
        if (strpos($norm, $s) !== false) return true;
    }

    return false;
}

/**
 * BUGFIX: un saludo pegado al detalle real se rechazaba por error.
 *
 * Antes, esPeticionValida() usaba una regex de "inicio de mensaje"
 * (/^(jeje|jaja|ok|bueno|hola|buenas|hi|hello)\b/i) para descartar
 * mensajes "vagos". El problema es que esa regex solo mira las primeras
 * letras: un mensaje real como "Hola, quiero reportar un bache en la 5ta
 * calle" empieza con "Hola" y por lo tanto SE RECHAZABA COMPLETO, aunque
 * traía toda la información necesaria. Como el ciudadano es impredecible y
 * es muy común que salude y explique el problema en el mismo mensaje, esto
 * generaba fricción innecesaria ("contame con más detalle...") frente a
 * mensajes que ya tenían el detalle.
 *
 * Esta función reemplaza esa regex: solo considera "relleno/vago" un
 * mensaje que es ÚNICAMENTE el saludo o muletilla (comparación de la frase
 * COMPLETA, no un prefijo), igual que ya se hacía con
 * esAgradecimientoODespedida().
 */
function esSoloRelleno($t) {
    $norm = trim(normalizarParaFiltro($t));
    $norm = preg_replace('/[!¡.,]+$/u', '', $norm);
    $norm = trim($norm);
    return preg_match('/^(jeje+|jaja+|ok|okay|okey|bueno|buenas|hola+|hi|hello|buen[oa]s?\s*(dias|tardes|noches)?|que\s*tal|q\s*tal)$/u', $norm) === 1;
}

/**
 * BUGFIX: el bot registraba como "petición" cualquier mensaje de 8+
 * caracteres que no fuera un saludo/despedida puro, sin verificar si
 * realmente describía un problema o trámite. Esto hacía que mensajes como
 * "Estoy cansado" o "No estoy solicitando nada" se registraran como
 * peticiones reales.
 *
 * contieneNegacionExplicita() detecta cuando el ciudadano está NEGANDO
 * explícitamente que quiera pedir/reportar algo (ej. "No estoy solicitando
 * nada", "No estoy pidiendo una petición", "no es una solicitud"). Se
 * comparan patrones específicos de negación de INTENCIÓN, no cualquier "no"
 * (para no rechazar reportes legítimos que usan "no" como parte del
 * problema, ej. "No hay agua" o "El alumbrado no funciona").
 */
function contieneNegacionExplicita($t) {
    $norm = normalizarParaFiltro($t);
    $patrones = [
        '/\bno\s+(estoy|ando|quiero|deseo)\s+(solicitando|pidiendo|reportando|solicitar|pedir|reportar)\b/u',
        '/\bno\s+es(toy)?\s+(una?\s+)?(petici[oó]n|solicitud|reporte|queja|tr[aá]mite)\b/u',
        '/\bno\s+quiero\s+(nada|hacer\s+(ninguna\s+)?(petici[oó]n|solicitud))\b/u',
        '/\bno\s+necesito\s+nada\b/u',
        '/\bno\s+tengo\s+(ninguna|nada\s+que)\s+(petici[oó]n|solicitar|pedir|reportar)\b/u',
    ];
    foreach ($patrones as $p) {
        if (preg_match($p, $norm)) return true;
    }
    return false;
}

/**
 * Verbos/expresiones típicas de alguien que SÍ está pidiendo o reportando
 * algo. Se usa como señal alternativa a la clasificación por palabras clave
 * (ver más abajo), para no rechazar peticiones legítimas cuyo problema no
 * quedó registrado en el catálogo de categorías pero que igual expresan
 * claramente una intención de pedir ayuda.
 */
function tieneVerboDeIntencion($t) {
    $norm = normalizarParaFiltro($t);
    return preg_match('/\b(reportar|reporto|reporte|solicitar|solicito|pedir|pido|necesito|necesitamos|quiero|quisiera|deseo|favor|ayuda|ayudenme|ayúdenme|urgente|problema|dañ|danio|roto|averiad|hay\s+un|hay\s+una|tengo\s+un|tengo\s+una)\b/u', $norm) === 1;
}

/**
 * Mensajes vagos, de relleno o cortesía (hasta 3 palabras) que llegan mientras
 * la encargada atiende el chat (ia_desactivada = true). El bot debe guardar
 * silencio: "A vaya", "Ok", "Va", "Bueno", "Ya", etc. pueden coincidir con
 * palabras sueltas de alguna categoría y reactivar la IA sin motivo.
 */
function esMensajeVagoDuranteAtencionHumana($t) {
    $norm = trim(normalizarParaFiltro($t));
    $norm = trim(preg_replace('/[!¡?¿.,…]+$/u', '', $norm));
    $norm = preg_replace('/\s+/u', ' ', $norm);
    if ($norm === '') return true;

    static $PALABRAS_RELLENO = [
        'a', 'vaya', 'va', 'vale', 'ok', 'oki', 'okay', 'okey', 'bueno', 'buena',
        'ah', 'aha', 'aja', 'mm', 'mmm', 'hmm', 'si', 'no', 'nel',
        'ya', 'listo', 'perfecto', 'bien', 'de', 'acuerdo', 'entendido', 'claro',
        'dale', 'gracias', 'muchas', 'mil', 'chao', 'adios', 'bye',
        'buenas', 'tardes', 'noches', 'dias', 'hola',
    ];

    $palabras = preg_split('/\s+/u', $norm);
    if (count($palabras) > 3) return false;
    foreach ($palabras as $p) {
        if (!in_array($p, $PALABRAS_RELLENO, true)) return false;
    }
    return true;
}

/**
 * $clasificacion (resultado de clasificarPeticion(), ver IA 2/clasificar.php)
 * es opcional para no romper otros usos existentes de esta función, pero
 * SIEMPRE debe pasarse desde los dos lugares donde se decide si arrancar/
 * modificar una petición (fases 'inicio' y 'modificando_peticion'), para
 * poder exigir una señal real (palabra clave del catálogo O verbo de
 * intención) y no solo longitud del mensaje.
 */
function esPeticionValida($t, $clasificacion = null) {
    $t = trim($t);
    if (mb_strlen($t) < 8) return false;
    if (esSoloRelleno($t)) return false;
    if (esAgradecimientoODespedida($t)) return false;
    if (contieneNegacionExplicita($t)) return false;

    if ($clasificacion !== null) {
        // BUGFIX (segunda capa de defensa): si clasificarPeticion() marcó
        // el mensaje como "solo saludo" (es_saludo=true), categoria queda
        // en null. Antes esta función SALTABA la validación en ese caso
        // (por error), así que el mensaje igual se aceptaba como petición
        // válida y el flujo avanzaba pidiendo el nombre con una categoría
        // vacía, mostrando "tu solicitud" en vez del problema real (ej.
        // "Hola tengo un problema"). Ahora se rechaza explícitamente: un
        // saludo puro nunca debe registrarse como petición.
        if (!empty($clasificacion['es_saludo'])) {
            return false;
        }
        $tieneSenalCategoria = !empty($clasificacion['tiene_senal_clara']);
        if (!$tieneSenalCategoria && !tieneVerboDeIntencion($t)) {
            return false;
        }
    }
    return true;
}

/**
 * MEJORA: reconocer el nombre aunque venga dentro de una frase natural.
 *
 * Antes, esNombreValido() exigía que el mensaje COMPLETO fuera nombre +
 * apellido y nada más. Si el ciudadano (de forma impredecible, como
 * cualquier persona real) escribía "me llamo Juan Pérez", "mi nombre es
 * Ana López" o "soy Carlos Ramírez", el mensaje se rechazaba porque
 * "me llamo Juan" tiene una palabra ("llamo") que no es parte del nombre.
 *
 * extraerNombre() primero quita esas muletillas típicas de presentación y
 * deja solo la parte que parece ser el nombre real, para validarla y
 * guardarla limpia (sin "me llamo" pegado en el expediente).
 */
function extraerNombre($t) {
    $t = trim($t);
    $prefijos = '/^(me\s+llamo|mi\s+nombre\s+(completo\s+)?es|soy|mi\s+nombre|nombre\s*:)\s+/iu';
    $t = preg_replace($prefijos, '', $t);
    $nombre = trim($t);
    // Permitir "Anónimo"
    if (preg_match('/^(an[oó]nimo|anonimo)$/iu', $nombre)) {
        return 'Anónimo';
    }
    return $nombre;
}

/**
 * AGREGADO: detecta cuando el ciudadano expresa, con sus propias palabras,
 * que NO quiere dar su nombre (a diferencia de solo escribir "Anónimo",
 * que ya se manejaba). Cubre frases naturales como "no quiero dar mi
 * nombre", "prefiero no decir mi nombre" o "no quiero identificarme".
 */
function esIntencionAnonimo($t) {
    $norm = normalizarParaFiltro($t);
    $patrones = [
        '/no quiero dar (mi )?nombre/',
        '/no (voy a )?dar (mi )?nombre/',
        '/prefiero no (decir|dar) (mi )?nombre/',
        '/anonimo/',
        '/sin nombre/',
        '/nombre anonimo/',
        '/no (quiero|deseo) (decir|dar|proporcionar) (mi )?nombre/',
        '/no (quiero|deseo) (identificarme|ser identificado)/',
    ];
    foreach ($patrones as $p) {
        if (preg_match($p, $norm)) return true;
    }
    return false;
}

/**
 * NUEVO: detecta si un texto contiene algún componente típico de los
 * nombres mayas / k'iche' del occidente de Guatemala (Ajpop, Ixchel,
 * B'alam, K'iche', Tzul, Xicay, Chivalán, Ajpacajá, etc.). Los nombres
 * que contengan al menos uno de estos componentes se consideran nombres
 * de persona válidos automáticamente, sin pasar por el filtro de
 * "palabras comunes del español" (que fue diseñado para rechazar frases
 * como "no quiero nada" escritas en lugar del nombre, no para juzgar
 * nombres mayas que por casualidad se parezcan a palabras comunes).
 *
 * La comparación se hace sin tildes ni signos, así que "Tecún" y "Tecun"
 * cuentan igual, y "K'iche'" se compara como "kiche".
 */
function tieneComponenteNombreMaya($texto) {
    static $COMPONENTES_MAYA = [
        // Nombres / títulos mayas
        'ajpop', 'ajpub', 'ajpu', 'ajxol', 'ajmac', 'ajtumax', 'ajpacaja',
        'ajchumil', 'ajbalam', 'ajkabel', 'ajchicach', 'ajtun',
        // Nombres personales / deidades
        'ixchel', 'ixquic', 'ixquiej', 'ixchumil', 'ixqan', 'ixpok',
        'ixkabel', 'ixbalam',
        // Nombres de días / nawales
        'balam', 'batz', 'kat', 'kiche', 'oxlajuj', 'tecun', 'uman',
        'kabel', 'nim', 'yool', 'chocoy', 'miculax', 'tun', 'chocoj',
        'chuy', 'xiloj', 'xotol', 'tzum', 'coroxon', 'cumatz', 'chivalan',
        'xiquin', 'pachec', 'choxom', 'tzul', 'paxan', 'cumez', 'achi',
        'popol', 'vuh', 'akabal', 'chanchavac', 'xikay', 'xiloj',
        'ajxol', 'ixc', 'ixcay', 'choc', 'tuma', 'tumax', 'chaj',
        // Raíces lingüísticas frecuentes en apellidos del altiplano
        'kakchiquel', 'kackchiquel', 'cakchiquel', 'quiche', 'mam',
        'sac', 'sak', 'canil', 'coyoy', 'cox', 'toj', 'tzoc', 'toc',
        'us', 'uz', 'batzin', 'batzibal', 'catalan', 'chajchalac',
    ];
    $norm = normalizarParaFiltro((string) $texto);
    // Se normaliza quitando signos para que "K'iche'" quede como "kiche".
    $norm = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $norm);
    $norm = ' ' . preg_replace('/\s+/u', ' ', $norm) . ' ';
    foreach ($COMPONENTES_MAYA as $comp) {
        if (preg_match('/(?<![a-z])' . preg_quote($comp, '/') . '(?![a-z])/u', $norm)) {
            return true;
        }
    }
    return false;
}

/**
 * Palabras que NO deberían aparecer en un nombre real de persona. Sirve
 * para distinguir un nombre ("Mario Solís") de una frase/oración que por
 * casualidad tiene 2+ palabras y menos de 60 caracteres, como
 * "No estoy pidiendo nada" o "Gracias por la atención" (que antes se
 * guardaban por error como si fueran el nombre del ciudadano).
 */
function esNombreValido($t) {
    // Permitir "Anónimo" como nombre válido
    if (preg_match('/^(an[oó]nimo|anonimo)$/iu', $t)) return true;

    // AGREGADO: rechazar frases de anonimato ("no quiero dar mi nombre",
    // etc.) para que no se cuelen como si fueran el nombre real.
    if (esIntencionAnonimo($t)) return false;

    $t = extraerNombre($t);
    if ($t === '') return false;
    if (esAgradecimientoODespedida($t)) return false;

    $palabras = preg_split('/\s+/', $t);
    // AMPLIADO: de 6 a 12 palabras para nombres tradicionales largos
    // ("Teodulo Macario Anacleto de la Roca y Mendoza") y mayas compuestos.
    if (count($palabras) < 2 || count($palabras) > 12) return false;
    // AMPLIADO: de 60 a 120 caracteres por la misma razón.
    if (mb_strlen($t) > 120) return false;

    // NUEVO: si el nombre contiene algún componente maya/k'iche' conocido,
    // se acepta directamente (sin pasar por el filtro de palabras comunes,
    // que está pensado para detectar frases en español, no nombres mayas).
    if (tieneComponenteNombreMaya($t)) return true;

    static $PALABRAS_NO_NOMBRE = [
        'no', 'si', 'sí', 'estoy', 'esta', 'está', 'soy', 'eres', 'es',
        'pidiendo', 'pido', 'nada', 'quiero', 'quisiera', 'querer',
        'necesito', 'necesita', 'ayuda', 'ayudame', 'ayúdame', 'gracias',
        'favor', 'porfavor', 'por', 'para', 'que', 'qué', 'como', 'cómo',
        'cuando', 'cuándo', 'donde', 'dónde', 'porque', 'porqué', 'tengo',
        'tiene', 'hay', 'son', 'fue', 'sera', 'será', 'puedo', 'puede',
        'ok', 'vale', 'bueno', 'buenas', 'hola', 'adios', 'adiós', 'chau',
        'problema', 'reporte', 'reportar', 'solicitud', 'peticion',
        'petición', 'gustaria', 'gustaría', 'mande', 'manda', 'nose',
        'sabe', 'se', 'me', 'te', 'lo', 'ni', 'pero', 'cancelar',
        'modificar', 'confirmar',
    ];

    // MEJORA: en Suchitepéquez (y en general en el altiplano/costa sur de
    // Guatemala) hay apellidos y nombres de origen k'iche'/mam realmente
    // cortos que, por pura coincidencia, se escriben igual que una palabra
    // común del español (ej. el apellido "Es", muy real en la zona, se
    // escribe igual que el verbo "es"). Antes, CUALQUIER palabra del
    // nombre que apareciera en $PALABRAS_NO_NOMBRE tumbaba todo el nombre,
    // así que "Es Fuentes" o similar se rechazaba como si fuera una
    // oración común, obligando al ciudadano a reintentar sin motivo.
    //
    // Ahora solo se rechaza el nombre cuando la MAYORÍA de sus palabras
    // están en la lista de palabras comunes (síntoma real de que es una
    // frase/oración y no un nombre, ej. "no se que hacer" tiene 3 de 4
    // palabras en la lista). Una sola coincidencia aislada en un nombre de
    // 2 o más palabras ya no lo invalida.
    $totalPalabras = count($palabras);
    $palabrasComunes = 0;
    foreach ($palabras as $palabra) {
        // Cada palabra de un nombre debe ser solo letras (con acentos/ñ/x/k,
        // que cubre bien los nombres de origen maya de la región), guiones
        // o apóstrofes; nada de números ni signos.
        if (!preg_match('/^[\p{L}\'\-]+$/u', $palabra)) return false;
        $palabraNorm = normalizarParaFiltro($palabra);
        if (in_array($palabraNorm, $PALABRAS_NO_NOMBRE, true)) $palabrasComunes++;
    }
    if ($palabrasComunes > $totalPalabras / 2) return false;
    return true;
}

/**
 * MEJORA: extraer números (DPI/teléfono) aunque vengan dentro de una frase,
 * en vez de exigir que el mensaje sea SOLO el número.
 *
 * Antes, esDpiValido()/esTelefonoValido() hacían preg_replace('/\D/','',$t)
 * sobre el mensaje COMPLETO. Esto tiene un problema serio con frases
 * naturales: si el ciudadano escribía "tengo 3 hijos y mi dpi es
 * 1234567890123", el reemplazo pegaba TODOS los dígitos del mensaje
 * ("31234567890123"), dando un número inválido o incorrecto en vez de
 * fallar de forma clara.
 *
 * extraerDigitos() en cambio busca tramos de dígitos consecutivos (ej. el
 * "1234567890123" suelto) y devuelve el primer tramo cuyo largo esté
 * dentro del rango esperado, ignorando otros números sueltos de la frase
 * (como el "3" de "3 hijos").
 */
function extraerDigitos($t, $min, $max) {
    if (!preg_match_all('/\d+/', $t, $coincidencias)) return null;
    foreach ($coincidencias[0] as $tramo) {
        $largo = strlen($tramo);
        if ($largo >= $min && $largo <= $max) return $tramo;
    }
    return null;
}

function esDpiValido($t) {
    if (esOmitir($t)) return true;
    return extraerDigitos($t, 8, 13) !== null;
}

function esTelefonoValido($t) {
    if (esOmitir($t)) return true;
    return extraerTelefono($t) !== null;
}

/**
 * AGREGADO: extrae un teléfono de un mensaje aunque venga escrito como la
 * gente realmente lo escribe: "4276 5953", "4276-5953", "+502 4276 5953",
 * "50242765953". extraerDigitos() solo entendía dígitos pegados, así que
 * "4276 5953" (lo más común en Guatemala) se rechazaba como inválido.
 * Devuelve los 8 dígitos locales (sin 502) o null si no hay un teléfono.
 */
function extraerTelefono($t) {
    if (preg_match('/(?<!\d)(?:\+?\s*502[\s\-.]*)?(\d{4})[\s\-.]?(\d{4})(?!\d)/', $t, $m)) {
        return $m[1] . $m[2];
    }
    // Respaldo: el criterio anterior (7 a 10 dígitos seguidos).
    return extraerDigitos($t, 7, 10);
}

/**
 * AGREGADO: el ciudadano contesta "este mismo", "el mismo", "sí"... cuando
 * se le pregunta a qué número contactarlo, queriendo decir "el de este
 * WhatsApp" (que el bot ya tiene guardado).
 */
function esMismoNumeroWhatsapp($t) {
    $norm = trim(normalizarParaFiltro($t));
    $norm = trim(preg_replace('/[!¡?¿.,]+/u', ' ', $norm));
    $norm = preg_replace('/\s+/u', ' ', $norm);
    if (preg_match('/^(si|ok|okay|dale|claro|este|ese|mismo|el mismo|este mismo|ese mismo|el mismo numero|este numero|ese numero|mismo numero|este mismo numero|este whatsapp|el de este whatsapp|el de whatsapp|el de aqui|este es|ese es)$/u', $norm)) {
        return true;
    }
    return preg_match('/\b(este|ese|mismo)\s+(mismo\s+)?(numero|whatsapp|telefono|celular)\b/u', $norm) === 1
        || preg_match('/\bel\s+mismo\b/u', $norm) === 1;
}

/**
 * BUGFIX: la dirección se guardaba con basura pegada al final.
 *
 * Ej: "vivo en Caserío Rosario y no hay agua desde ayer" se guardaba
 * completo como dirección ("Caserío Rosario y no hay agua desde ayer") en
 * vez de solo "Caserío Rosario". El ciudadano, de forma totalmente normal,
 * no separa "dónde vive" de "cuál es el problema" con una coma: lo escribe
 * todo junto en una sola frase corrida.
 *
 * Esta función corta el texto en el punto donde empieza otra idea
 * (conjunción, queja, motivo) que ya NO es parte de la dirección, usando
 * las palabras de enlace más comunes en este tipo de mensajes. Solo corta
 * si esas palabras aparecen DESPUÉS de al menos algo de texto (no al
 * inicio), para no destrozar una dirección legítima que por casualidad
 * empiece con una de esas palabras.
 */
/**
 * BUGFIX: el bot decía haber "identificado" una dirección cuando en
 * realidad solo capturó una palabra genérica sin ningún dato útil.
 *
 * Ej: "Hola hay un agujero en la calle" → la extracción automática
 * reconoce el patrón "en la ___" y captura "calle", que técnicamente
 * cumple el mínimo de 5 caracteres pero NO es una dirección real: es
 * el ciudadano usando "la calle" como parte de la frase que describe el
 * problema ("hay un agujero en la calle"), no dando una ubicación.
 *
 * Esta función detecta cuando el texto capturado es (una vez quitados
 * artículos/posesivos como "la", "el", "una", "mi") solo una de estas
 * palabras genéricas sin ningún nombre propio, número o referencia
 * adicional. Se usa para descartar el "falso acierto" y, en ese caso,
 * seguir preguntando la dirección normalmente en vez de darla por buena.
 */
function esDireccionSoloGenerica($t) {
    $norm = normalizarParaFiltro(trim($t));
    $norm = trim(preg_replace('/[!¡?¿.,]+$/u', '', $norm));
    $norm = preg_replace('/^(la|el|una|un|mi|esa|ese|esta|este)\s+/u', '', $norm);

    // AGREGADO: si el texto menciona un LUGAR CONOCIDO del municipio
    // (Chocola, Madre Mía, Flor del Café, Casco Urbano, etc. — ver
    // LUGARES_CONOCIDOS_MUNICIPIO), NUNCA es "solo genérico": es una
    // ubicación real y válida, aunque el ciudadano la haya escrito sin
    // el tipo de lugar ("en chocola" en vez de "en Aldea Chocola") y con
    // minúsculas o sin tildes ("flor del cafe" por "Flor del Café").
    if (mencionaLugarConocido($norm)) {
        return false;
    }

    // Palabras genéricas de TIPO DE LUGAR: por sí solas no son una
    // dirección real ("la calle", "mi casa"), pero SÍ sirven si vienen
    // acompañadas de un nombre propio ("colonia San José", "calle 5").
    // Por eso solo se rechazan cuando el texto es EXACTAMENTE una de ellas.
    static $GENERICOS_SOLOS = [
        'calle', 'calles', 'camino', 'casa', 'colonia', 'barrio', 'zona',
        'lugar', 'cuadra', 'carretera', 'avenida', 'sector', 'vecindario',
        'aqui', 'aca', 'ahi', 'alli', 'comunidad', 'aldea', 'caserio',
    ];

    // AGREGADO: referencias a la IMAGEN / FOTO / ADJUNTO que el ciudadano
    // envió. Caso real que motivó esto: un ciudadano mandó una foto de una
    // pasola abandonada y escribió "aquí en la imagen". El patrón "en la
    // ___" de extraerDireccionDeTexto() capturó "imagen" y el bot la
    // guardó como si fuera una dirección real, sin pedir nunca la
    // ubicación verdadera — cuando la IA NO puede ver la imagen, así que
    // ese dato nunca es aprovechable como ubicación.
    //
    // A diferencia de $GENERICOS_SOLOS, estas NUNCA son una ubicación,
    // ni solas ni seguidas de nada ("imagen", "imagen que le mando",
    // "foto adjunta", "captura de pantalla"...). Siempre se rechazan
    // (salvo que traigan un número, ver más abajo).
    static $REFERENCIAS_MEDIA = [
        'imagen', 'imagenes', 'imágenes',
        'foto', 'fotos', 'fotografia', 'fotografias', 'fotografía', 'fotografías',
        'video', 'videos',
        'captura', 'capturas', 'pantalla', 'pantallazo', 'screenshot',
        'adjunto', 'adjuntos', 'adjunta', 'adjuntas',
        'archivo', 'archivos', 'documento', 'documentos',
        'referencia',
    ];

    // 1) Coincidencia exacta: rechazar "la calle", "colonia", "imagen", etc.
    if (in_array($norm, $GENERICOS_SOLOS, true)) return true;
    if (in_array($norm, $REFERENCIAS_MEDIA, true)) return true;

    // 2) AGREGADO: si la frase EMPIEZA con una referencia a imagen/foto y NO
    //    contiene ningún dígito, tampoco es una ubicación real: solo está
    //    mencionando el adjunto con palabras de relleno ("imagen que le
    //    mando", "foto adjunta", "video que envié", "imagen de arriba"...).
    //    Se exige que NO haya dígitos para no rechazar direcciones reales
    //    como "colonia 5" o "calle 12" (que sí traen números útiles).
    if (!preg_match('/\d/', $norm)
        && preg_match('/^(\p{L}+)\b/u', $norm, $m)
        && in_array($m[1], $REFERENCIAS_MEDIA, true)) {
        return true;
    }

    return false;
}

function recortarEnPalabraCorte($t) {
    $cortes = '/\s+('
        // Conjunciones y arranques de queja ya existentes
        . 'y\s+no|y\s+ya|y\s+tambien|y\s+ademas|y\s+llevamos'
        // AGREGADO: "y está/esta ... estorbando", "y estorba", "y obstruye",
        // "y bloquea", "y impide", "y tapa", "y no deja", "y se está ..."
        // (justo el patrón que falló con "…en la calle y está estorbando").
        . '|y\s+est[áa](n)?\b'
        . '|y\s+estorba(n)?\b'
        . '|y\s+estorban?\b'
        . '|y\s+obstruye(n)?\b'
        . '|y\s+bloquea(n)?\b'
        . '|y\s+impide(n)?\b'
        . '|y\s+tapa(n)?\b'
        . '|y\s+se\s+est[áa](n)?\b'
        . '|y\s+no\s+(deja|dejan|permite|permiten)\b'
        . '|y\s+afecta(n)?\b'
        . '|y\s+molesta(n)?\b'
        . '|y\s+hay\b'
        . '|y\s+tiene(n)?\b'
        . '|y\s+presenta(n)?\b'
        // AGREGADO: verbos de INCIDENTE que anuncian el problema y que
        // pueden venir pegados directamente al nombre del lugar, SIN "y"
        // en medio (a diferencia de los casos de arriba). Sin este corte,
        // un mensaje como "en camino la ladrillera acaba de caer un árbol"
        // hacía que extraerDireccionDeTexto() (ver más abajo, rama de
        // LUGARES_CONOCIDOS_MUNICIPIO) capturara como "dirección" el texto
        // completo "camino la ladrillera acaba de caer un árbol" en vez de
        // detenerse en "camino la ladrillera". Esa dirección contaminada
        // después rompía el oficio (oficio_html.php compara la dirección
        // contra el relato para no repetirla, y al coincidir con parte del
        // relato terminaba borrando la ubicación Y la descripción del
        // problema del cuerpo de la carta).
        . '|acaba(n)?\s+de\b'
        . '|acabo\s+de\b'
        . '|se\s+cay[oó](n)?\b'
        . '|cay[oó]\b'
        . '|se\s+ha(n)?\s+ca[ií]do\b'
        . '|est[áa]n?\s+obstaculizando\b'
        . '|est[áa]n?\s+obstruyendo\b'
        . '|est[áa]n?\s+bloqueando\b'
        . '|est[áa]n?\s+tapando\b'
        . '|est[áa]n?\s+afectando\b'
        . '|est[áa]n?\s+da[ñn]ando\b'
        . '|est[áa]n?\s+causando\b'
        . '|est[áa]n?\s+provocando\b'
        . '|se\s+rompi[oó]\b'
        . '|se\s+da[ñn][oó]\b'
        . '|colaps[oó]\b'
        . '|se\s+incendi[oó]\b'
        . '|explot[oó]\b'
        // AGREGADO: verbos de incidente (tuberías, desbordes, averías)
        . '|se\s+revent[oó]\b'
        . '|se\s+revient[aá]\b'
        . '|revent[oó]\b'
        . '|se\s+meti[oó]\b'
        . '|se\s+tap[oó]\b'
        . '|se\s+quebr[oó]\b'
        . '|se\s+parti[oó]\b'
        . '|se\s+volc[oó]\b'
        . '|se\s+averi[oó]\b'
        . '|se\s+destruy[oó]\b'
        . '|se\s+descompus[oó]\b'
        . '|se\s+desbord[oó]\b'
        . '|est[aá]n?\s+corriendo\b'
        . '|est[aá]n?\s+metiendo\b'
        . '|est[aá]n?\s+saliendo\b'
        . '|est[aá]n?\s+entrando\b'
        . '|est[aá]n?\s+llegando\b'
        . '|est[aá]n?\s+rebalsando\b'
        . '|est[aá]n?\s+inundando\b'
        . '|est[aá]n?\s+anegando\b'
        // Resto de cortes originales (sin tocar)
        . '|pero\s|desde\s+(ayer|hace|el|la)|porque\s|ya\s+que\s|aunque\s|sin\s+embargo'
        . '|no\s+hay|no\s+tengo|no\s+tenemos|no\s+contamos|no\s+podemos|no\s+se\s+puede'
        . '|hay\s+un|hay\s+una|hay\s+unos|hay\s+unas'
        . '|tengo\s+un|tengo\s+una|tenemos\s+un|tenemos\s+una'
        . '|necesito\b|necesitamos\b|quiero\b|queremos\b|quisiera\b|quisi[ée]ramos\b'
        . '|estamos\s+sin|estoy\s+sin|est[áa]\s+sin'
        // NUEVO: imperativos de "llamar a la autoridad" pegados a la ubicación
        // relacional ("venga a ver", "manden a alguien", "vengan pronto").
        . '|venga(n)?\s+a\b|vaya(n)?\s+a\b|mande(n)?\s+a\b'
        . '|vengan?\s+pronto\b|mande(n)?\s+a\s+alguien\b|avise(n)?\b'
        . '|por\s+favor|porfa(vor)?\b|urgente\b|urge\b'
        . ').*/iu';
    return trim(preg_replace($cortes, '', $t));
}

function extraerDireccion($t) {
    $t = trim($t);
    $estructurada = detectarDireccionEstructurada($t);
    if ($estructurada !== null) return $estructurada;
    $prefijos = '/^(vivo\s+en|queda\s+en|es\s+en|la\s+direccion\s+es|mi\s+direccion\s+es|direccion\s*:)\s+/iu';
    $t = preg_replace($prefijos, '', $t);
    $t = recortarEnPalabraCorte(trim($t));
    return $t;
}

function esDireccionValida($t) {
    if (esOmitir($t)) return true;
    $dir = extraerDireccion($t);
    if (mb_strlen($dir) < 5) return false;
    // Si, aun cuando se le preguntó DIRECTAMENTE por la dirección, el
    // ciudadano solo responde con una palabra genérica ("la calle", "mi
    // casa"), tampoco es un dato útil: se le pide que sea más específico
    // en vez de guardarlo como si fuera una ubicación real.
    if (esDireccionSoloGenerica($dir)) return false;
    return true;
}

/**
 * ─────────────────────────────────────────────────────────────────────
 * NUEVO: extracción automática de datos cuando el ciudadano ya los
 * incluyó en su primer mensaje.
 *
 * Antes, el bot SIEMPRE preguntaba nombre → DPI → teléfono → dirección
 * paso a paso, sin importar que el ciudadano ya hubiera escrito esa
 * información en el mensaje inicial (ej. "Mi nombre es Monica Solis...
 * en la calle que va hacia la cumbre de Alaska..."). Estas dos funciones
 * buscan nombre y dirección DENTRO del mensaje original (a diferencia de
 * extraerNombre()/extraerDireccion() de arriba, que solo quitan un
 * prefijo si el mensaje EMPIEZA con él). Se usan únicamente en la fase
 * 'inicio', para no volver a preguntar por un dato que el ciudadano ya
 * dio de forma espontánea.
 * ─────────────────────────────────────────────────────────────────────
 */
/**
 * MEJORA: cuando el ciudadano escribe nombre y dirección juntos en una
 * sola frase SIN puntuación entre ambos datos (ej. "Soy Santiago Moto y
 * vivo en Colonia Flor de Café"), la captura de extraerNombreDeTexto()
 * (ver abajo) es "golosa" y por defecto se sigue de largo tomando también
 * las palabras de la dirección como si fueran parte del nombre ("Santiago
 * Moto y vivo en"). Esta función recorta el nombre capturado justo antes
 * del primer conector que ya no es parte de un nombre de persona (un
 * verbo de "vivir en", otro trámite mencionado de corrido, etc.), similar
 * a como recortarEnPalabraCorte() ya hace lo mismo para direcciones.
 */
function recortarNombreEnPalabraCorte($t) {
    $cortes = '/\s+(y\s+vivo|y\s+vive|y\s+viv[oe]|vivo\s+en|vive\s+en|viviendo\s+en'
        . '|y\s+mi\s+direccion|y\s+mi\s+direcci[oó]n|mi\s+direccion\s+es|mi\s+direcci[oó]n\s+es'
        . '|direccion\s*:|direcci[oó]n\s*:|queda\s+en|ubicad[oa]\s+en|frente\s+a|cerca\s+de'
        . '|en\s+la\s|en\s+el\s|para\s+reportar|reportando|reporto\b|tengo\s+un\b|tengo\s+una\b'
        . '|necesito\b|quiero\b|mi\s+dpi\b|el\s+dpi\b|dpi\s+es\b|y\s+quiero|y\s+necesito|por\s+favor).*/iu';
    return trim(preg_replace($cortes, '', $t));
}

function extraerNombreDeTexto($t) {
    // MEJORA: la ventana de captura se amplió de hasta 5 a hasta 7
    // palabras para dar margen a recortarNombreEnPalabraCorte() de
    // "ver" el conector (ej. "y vivo en") y cortar ahí, en vez de
    // quedarse corta antes de alcanzarlo.
    // AMPLIADO: de {0,6} a {0,11} para aceptar nombres tradicionales
    // guatemaltecos muy largos (ej. "Rigoberto Efraín Maximiliano de la
    // Rosa Cifuentes", "Anastasio Candelario Hermógenes de los Remedios
    // Escobar Gatica") y nombres mayas compuestos de varias palabras.
    if (preg_match('/(?:mi\s+nombre\s+(?:completo\s+)?es|me\s+llamo|soy)\s+(\p{L}[\p{L}\'\-]*(?:\s+\p{L}[\p{L}\'\-]*){0,11})/iu', $t, $m)) {
        $nombre = recortarNombreEnPalabraCorte(trim($m[1]));
        if ($nombre === '') return null;
        // Permitir "Anónimo"
        if (preg_match('/^(an[oó]nimo|anonimo)$/iu', $nombre)) {
            return 'Anónimo';
        }
        return $nombre;
    }
    return null;
}

// ─────────────────────────────────────────────────────────────────────
// MEJORA: identificación de direcciones ESTRUCTURADAS al estilo
// guatemalteco (calle, avenida, número de casa y zona), por ejemplo:
//   "3ra Calle, 5ta Avenida 1-34 Zona 2"
//   "5a avenida 2-10 zona 1"
//   "tercera calle quinta avenida uno guion treinta y cuatro zona dos"
//
// Antes, estos datos (los más útiles para ubicar al ciudadano) solo se
// reconocían por casualidad si coincidían con los patrones genéricos de
// "en la/el ___" o con un LUGAR_CONOCIDO. Esta función los detecta
// explícitamente, sin importar mayúsculas/minúsculas, con o sin punto
// después del ordinal (3a. / 3a), con abreviaturas (Av., C.) y con los
// números escritos completamente en palabras.
// ─────────────────────────────────────────────────────────────────────

// Ordinales en palabras (1° a 20°, y compuestos) → dígito.
// Se comparan ya normalizados sin tildes (ver normalizarParaFiltro()).
function mapaOrdinalesPalabra() {
    return [
        'primer' => '1', 'primero' => '1', 'primera' => '1',
        'segundo' => '2', 'segunda' => '2',
        'tercer' => '3', 'tercero' => '3', 'tercera' => '3',
        'cuarto' => '4', 'cuarta' => '4',
        'quinto' => '5', 'quinta' => '5',
        'sexto' => '6', 'sexta' => '6',
        'septimo' => '7', 'septima' => '7',
        'octavo' => '8', 'octava' => '8',
        'noveno' => '9', 'novena' => '9',
        'decimo' => '10', 'decima' => '10',
        'onceavo' => '11', 'onceava' => '11', 'undecimo' => '11', 'undecima' => '11',
        'doceavo' => '12', 'doceava' => '12', 'duodecimo' => '12', 'duodecima' => '12',
        'treceavo' => '13', 'treceava' => '13',
        'decimotercero' => '13', 'decimotercera' => '13',
        'catorceavo' => '14', 'catorceava' => '14',
        'decimocuarto' => '14', 'decimocuarta' => '14',
        'quinceavo' => '15', 'quinceava' => '15',
        'decimoquinto' => '15', 'decimoquinta' => '15',
        'decimosexto' => '16', 'decimosexta' => '16',
        'decimoseptimo' => '17', 'decimoseptima' => '17',
        'decimoctavo' => '18', 'decimoctava' => '18',
        'decimonoveno' => '19', 'decimonovena' => '19',
        'veinteavo' => '20', 'vigesimo' => '20', 'vigesima' => '20',
        'vigésimo' => '20', 'vigésima' => '20',
    ];
}

// Números en palabras (0-100) para casas y zonas escritas en texto.
function mapaNumerosPalabra() {
    return [
        'cero'=>0,'uno'=>1,'una'=>1,'un'=>1,'dos'=>2,'tres'=>3,'cuatro'=>4,'cinco'=>5,
        'seis'=>6,'siete'=>7,'ocho'=>8,'nueve'=>9,'diez'=>10,'once'=>11,'doce'=>12,
        'trece'=>13,'catorce'=>14,'quince'=>15,
        'dieciseis'=>16,'dieciséis'=>16,
        'diecisiete'=>17,'dieciocho'=>18,'diecinueve'=>19,
        'veinte'=>20,'veintiuno'=>21,'veintiun'=>21,'veintiún'=>21,'veintiuna'=>21,
        'veintidos'=>22,'veintidós'=>22,
        'veintitres'=>23,'veintitrés'=>23,
        'veinticuatro'=>24,'veinticinco'=>25,
        'veintiseis'=>26,'veintiséis'=>26,
        'veintisiete'=>27,'veintiocho'=>28,'veintinueve'=>29,
        'treinta'=>30,'treinta y uno'=>31,'treinta y una'=>31,
        'treinta y dos'=>32,'treinta y tres'=>33,'treinta y cuatro'=>34,
        'treinta y cinco'=>35,'treinta y seis'=>36,'treinta y siete'=>37,
        'treinta y ocho'=>38,'treinta y nueve'=>39,
        'cuarenta'=>40,'cuarenta y uno'=>41,'cuarenta y dos'=>42,
        'cincuenta'=>50,'sesenta'=>60,'setenta'=>70,'ochenta'=>80,'noventa'=>90,
        'cien'=>100,'ciento'=>100,
    ];
}

// Fragmento regex reutilizable: un "ordinal" de calle/avenida, ya sea
// numérico con cualquier sufijo típico del español guatemalteco (3a, 3a.,
// 3ra, 3era, 3ro, 3°, 3º, 3ª, 5to, 5ta, 5to., 7mo, 7ma, 8vo, 8va, 9no,
// 9na, 10mo, 10ma...), sin sufijo (3, 5, 12) o escrito completamente en
// palabras ("primera", "quinta", "vigésima").
function patronOrdinalDireccion() {
    static $palabras = null;
    if ($palabras === null) {
        $palabras = implode('|', array_keys(mapaOrdinalesPalabra()));
    }
    // Sufijos: ro, do, mo, vo, no, to, er, ra, da, ma, na, va, ta, era,
    // y también los signos °, º, ª.  Se acepta punto después.
    $sufijo = '(?:ro|do|mo|vo|no|to|er|ra|da|ma|na|va|ta|era|a|o|°|º|ª)?';
    return '(?:\d{1,3}\s*' . $sufijo . '\.?|' . $palabras . ')';
}

// Fragmento regex reutilizable: un número de casa o de zona, en dígitos
// ("34", "2", "100"), en palabra simple ("cinco") o compuesto con "y"
// ("treinta y cuatro", "cuarenta y dos").
function patronNumeroDireccion() {
    static $palabras = null;
    if ($palabras === null) {
        $palabras = implode('|', array_keys(mapaNumerosPalabra()));
    }
    return '(?:\d{1,4}|(?:' . $palabras . ')(?:\s+y\s+(?:' . $palabras . '))?)';
}

/**
 * Reconoce un fragmento de dirección ESTRUCTURADA (calle, avenida,
 * calzada, callejón, número de casa, zona, manzana, lote y colonia/
 * barrio/sector) dentro del texto, en cualquier orden y con cualquier
 * combinación de mayúsculas/minúsculas, tildes o números en palabras.
 *
 * Casos cubiertos (todos equivalentes al mismo resultado):
 *   "3ra Calle, 5ta Avenida 1-34 Zona 2"
 *   "3a. calle, 5a. avenida, casa 1-34, zona 2"
 *   "3ra calle 5ta avenida 1 34 zona 2"
 *   "3ra calle 5ta avenida 1 al 34 zona 2"
 *   "3ra calle 5ta avenida 1 a la 34 zona 2"
 *   "3ra calle 5ta avenida 1 guion 34 zona 2"
 *   "tercera calle quinta avenida uno guion treinta y cuatro zona dos"
 *   "3 Calle 5 Avenida Casa No. 1-34 Zona 2"
 *   "5ta Avenida 2-10 Zona 1"
 *   "Calzada Roosevelt 15-30 Zona 11"
 *   "Calzada Aguilar Batres 30-35 Zona 12"
 *   "Callejón El Manchén 5-67 Zona 3"
 *   "Mza. 5 Lote 12 Colonia El Milagro"
 *   "Manzana 4, Lote 12, Colonia X, Zona 6"
 *   "12 Calle A 5-67 Zona 2"
 *   "12 Calle 'A' 5-67 Zona 2"
 *   "No. 12 Calle 5-67 Zona 2"
 *   "Calle 5-67 Zona 2 Colonia Las Flores"
 *   "Avenida Las Américas 5-67 Zona 13"
 *   "Colonia Las Flores, 5ta Avenida, Casa 1-34, Zona 2"
 *   "Zona 2, 3ra Calle, 5ta Avenida, 1-34"
 *
 * Devuelve el fragmento tal como lo escribió el ciudadano (conservando
 * mayúsculas/tildes) o null si no encuentra nada estructurado.
 */
function detectarDireccionEstructurada($t) {
    $ordinal = patronOrdinalDireccion();
    $numero  = patronNumeroDireccion();

    // --- Vías: calle, avenida, calzada, callejón, pasaje, diagonal, transversal ---
    // Se aceptan las dos formas: "5ta Calle" (ordinal primero) y "Calle 5ta"
    // o "Calle 5" (vía primero). También "No. 5" / "N° 5" / "#5" delante del
    // número del vía.
    $numVia = '(?:no\.?\s*|n[°º]\.?\s*|numero\s+|n[uú]mero\s+|#\s*)?' . $ordinal;
    $viaCalle      = '(?:(?:calles?|call\.?|cll\.?|cl\.?)\s*' . $numVia . '|' . $ordinal . '\.?\s*(?:calles?|call\.?|cll\.?|cl\.?))';
    $viaAvenida    = '(?:(?:avenidas?|ave\.?|av\.?|avda\.?|avn\.?)\s*' . $numVia . '|' . $ordinal . '\.?\s*(?:avenidas?|ave\.?|av\.?|avda\.?|avn\.?))';
    $viaCalzada    = '(?:(?:calzada|calz\.?|czda\.?)\s*' . $numVia . '|' . $ordinal . '\.?\s*(?:calzada|calz\.?|czda\.?))';
    $viaCallejon   = '(?:(?:callej[oó]n|cllj\.?|pasaje|pas\.?|diagonal|diag\.?|transversal|transv\.?|bulevar|blvd\.?|bulevar)\s*' . $numVia . '|' . $ordinal . '\.?\s*(?:callej[oó]n|cllj\.?|pasaje|pas\.?|diagonal|diag\.?|transversal|transv\.?|bulevar|blvd\.?))';

    // --- Número de casa: "1-34", "1 34", "1 al 34", "1 a la 34", "1 guion 34" ---
    // También "Casa 1-34", "No. 1-34", "Casa No. 1-34", "Lote 1-34",
    // "Casa 5-67 A" (con letra final de casa), "5-67 A".
    $sepCasa = '(?:-|–|—|−|\bguion\b|\bgui[oó]n\b|\bal\b|\ba\s+la\b|\ba\s+el\b)';
    $numCasaBase = '(?:(?:casa|residencia|vivienda|lote|apartamento|apto\.?|apt\.?|local|edificio|edif\.?|bodega|galera)\s*)?'
        . '(?:no\.?\s*|n[°º]\.?\s*|numero\s+|n[uú]mero\s+|#\s*)?'
        . $numero . '\s*' . $sepCasa . '\s*' . $numero
        . '(?:\s+["\'“”]?[A-Za-z]?["\'“”]?)?';
    // "Casa 5", "Casa A", "Casa 5A" (sin guion)
    $casaSimple = '(?:casa\s*(?:no\.?\s*|n[°º]\.?\s*|numero\s+|n[uú]mero\s+|#\s*)?'
        . '(?:' . $numero . '\s*[A-Za-z]?|[A-Za-z]))';

    // --- Zona: "Zona 2", "Z. 2", "Zn. 2", "Zona No. 2", "Zona 2 de Mixco" ---
    $zona = '(?:'
        . '(?:zonas?|zn\.?|z\.?)\s*(?:no\.?\s*|n[°º]\.?\s*|numero\s+|n[uú]mero\s+|#\s*)?' . $numero
        . '|(?:zonas?\s+[A-ZÁÉÍÓÚÑ][\wáéíóúñ]+)'      // "Zona Centro"
        . ')';

    // --- Manzana: "Mza. 5", "Manzana 5", "Mz. 5" ---
    $manzana = '(?:'
        . '(?:manzanas?|mza\.?|mz\.?)\s*(?:no\.?\s*|n[°º]\.?\s*|numero\s+|n[uú]mero\s+|#\s*)?' . $numero
        . '|(?:manzanas?\s+["\'“”]?[A-Za-z]["\'“”]?)'
        . ')';

    // --- Lote: "Lote 12", "Lt. 12", "L. 12" ---
    $lote = '(?:'
        . '(?:lotes?|lt\.?|l\.?)\s*(?:no\.?\s*|n[°º]\.?\s*|numero\s+|n[uú]mero\s+|#\s*)?' . $numero
        . '|(?:lotes?\s+["\'“”]?[A-Za-z]["\'“”]?)'
        . ')';

    // --- Colonia / Barrio / Sector / Aldea / Caserío / Cantón / Comunidad / etc.
    //     con nombre propio (o al menos una palabra) después. ---
    $comunidad = '(?:(?:colonia|col\.?|barrio|bo\.?|sector|sect\.?|'
        . 'aldea|ald\.?|caser[ií]o|cas\.?|cant[oó]n|cant\.?|'
        . 'comunidad|com\.?|residencial|res\.?|lotificaci[oó]n|loteo|'
        . 'condominio|cond\.?|parcelamiento|parc\.?|finca|'
        . 'asentamiento|asent\.?|urbanizaci[oó]n|urb\.?|'
        . 'granja|cooperativa|coop\.?|microparcelamiento|microparc\.?|'
        . 'paraje|poblado|casco\s+urbano|centro\s+hist[oó]rico|'
        . 'ciudad\s+perdida)\s+'
        . '[A-ZÁÉÍÓÚÑ][\wáéíóúñ]+'
        . '(?:\s+(?:de|del|de\s+la|de\s+los|de\s+las|y|la|el|los|las)\s+[A-ZÁÉÍÓÚÑ]?[\wáéíóúñ]+)*'
        . ')';

    // Un "componente" es cualquiera de las piezas anteriores.
    $componentes = '(?:'
        . $viaCalle . '|' . $viaAvenida . '|' . $viaCalzada . '|' . $viaCallejon
        . '|' . $numCasaBase . '|' . $casaSimple
        . '|' . $zona . '|' . $manzana . '|' . $lote . '|' . $comunidad
        . ')';

    // Separadores entre componentes: coma opcional, "y", "con", espacios.
    $separador = '(?:\s*[,;]\s*|\s+y\s+|\s+con\s+|\s+){1,2}';

    $patronCompleto = '/' . $componentes . '(?:' . $separador . $componentes . '){0,5}/iu';

    if (!preg_match($patronCompleto, $t, $m)) return null;
    $fragmento = $m[0];

    // Exigir que el fragmento contenga al menos un indicador claramente
    // direccional (vía, zona, manzana, lote o colonia/barrio/etc.), para
    // no confundir un número de teléfono suelto con una dirección real.
    if (!preg_match(
        '/\b(calles?|call|cll|cl\.|avenidas?|ave|av|avda|avn|calzada|calz|czda|'
        . 'callej[oó]n|cllj|pasaje|pas|diagonal|diag|transversal|transv|bulevar|blvd|'
        . 'zona|zn|manzana|mza|mz|lote|lt|'
        . 'colonia|col|barrio|bo|sector|sect|aldea|ald|caser[ií]o|cas|cant[oó]n|cant|'
        . 'comunidad|com|residencial|res|lotificaci[oó]n|loteo|condominio|cond|'
        . 'parcelamiento|parc|finca|asentamiento|asent|urbanizaci[oó]n|urb)\b/iu',
        $fragmento
    )) {
        return null;
    }

    return trim($fragmento);
}

/**
 * NUEVO: detecta frases de ubicación por REFERENCIA RELACIONAL.
 *
 * La gente en Guatemala ubica los lugares mencionando un punto de
 * referencia conocido (negocio, iglesia, escuela, rótulo) precedido de
 * una PREPOSICIÓN RELACIONAL, sin decir el tipo de lugar (colonia,
 * aldea, zona). Ejemplos reales:
 *
 *   "hay un accidente enfrente de Sarita, abajo de la Iglesia"
 *   "el bache está frente a la tienda Doña María"
 *   "la fuga es cerca del parque central"
 *   "el poste caído está junto a la escuela"
 *   "queda detrás del mercado"
 *   "es al lado de la ferretería"
 *
 * Hasta ahora extraerDireccionDeTexto() NO reconocía estas frases cuando
 * el "objetivo" de la preposición no era un lugar de
 * LUGARES_CONOCIDOS_MUNICIPIO, así que el bot volvía a preguntar la
 * ubicación aunque el ciudadano ya la hubiera dado en el mismo mensaje.
 *
 * Esta función:
 *   1) Busca la primera PREPOSICIÓN RELACIONAL en el texto.
 *   2) Captura desde ahí hasta el final de la oración (punto).
 *   3) Divide por comas y acumula segmentos que sigan empezando con una
 *      preposición relacional ("enfrente de Sarita, abajo de la Iglesia").
 *   4) Aplica recortarEnPalabraCorte() para descartar verbos de acción y
 *      ruegos ("venga a ver", "por favor", "hay heridos", etc.).
 *   5) Normaliza el typo común "del a" → "de la".
 *
 * @return string|null La dirección detectada, o null si no la encontró.
 */
function detectarDireccionRelacional($t) {
    // El "al?" permite tanto "a" como "al".
    static $PREPOSICIONES = [
        'en\s+frente\s+(?:de|del|al?)',
        'enfrente\s+(?:de|del|al?)',
        'frente\s+(?:a|al|de|del)',
        'abajo\s+(?:de|del|al?)',
        'arriba\s+(?:de|del|al?)',
        'cerca\s+(?:de|del|al?)',
        'detr[áa]s\s+(?:de|del|al?)',
        'atr[áa]s\s+(?:de|del|al?)',
        'al\s+lado\s+(?:de|del|al?)',
        'a\s+la\s+par\s+(?:de|del|al?)',
        'junto\s+(?:a|al)',
        'al\s+costado\s+(?:de|del|al?)',
        'a\s+un\s+costado\s+(?:de|del|al?)',
        'contiguo\s+(?:a|al)',
        'adyacente\s+(?:a|al)',
        'a\s+la\s+altura\s+(?:de|del|al?)',
        'a\s+orillas\s+(?:de|del|al?)',
        'a\s+la\s+vuelta\s+(?:de|del|al?)',
        'a\s+espaldas\s+(?:de|del|al?)',
        'diagonal\s+(?:a|al)',
    ];
    $rxPreposicion = '(?:' . implode('|', $PREPOSICIONES) . ')\s+(?:a\s+)?(?:(?:la|el|los|las)\s+)?';

    // Primer match (posición inicial).
    $patron = '/\b(' . $rxPreposicion . ')([\p{L}][^.;\n]{1,80})/iu';
    if (!preg_match($patron, $t, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }

    $inicio = $m[0][1];
    $resto  = substr($t, $inicio);

    // Hasta el fin de la ORACIÓN (punto). Las comas NO cortan: las
    // ubicaciones relacionales suelen encadenarse con comas.
    if (!preg_match('/^([^.;\n]{5,200})/u', $resto, $mCorte)) {
        return null;
    }

    // Se conservan SOLO los segmentos que son referencias de lugar: el
    // primero siempre (ya matcheó la preposición); los siguientes solo si
    // empiezan con OTRA preposición relacional. Si no, se corta.
    $segmentos = array_map('trim', preg_split('/\s*[,;]\s*/u', $mCorte[1]));
    $referencias = [];
    foreach ($segmentos as $i => $seg) {
        if ($seg === '') continue;

        $esRelacional = ($i === 0) || preg_match('/^(?:' . $rxPreposicion . ')/iu', $seg);
        if (!$esRelacional) break;

        // Quitar verbos de acción / ruegos pegados.
        $seg = recortarEnPalabraCorte($seg);
        if ($seg !== '') $referencias[] = $seg;
    }

    if (empty($referencias)) return null;

    $direccion = trim(implode(', ', $referencias));

    // Typo común "del a" -> "de la" ("abajo del a iglesia").
    $direccion = preg_replace('/\bdel\s+a\s+/iu', 'de la ', $direccion);

    if ($direccion === '' || esDireccionSoloGenerica($direccion)) {
        return null;
    }

    return $direccion;
}

function extraerDireccionDeTexto($t) {
    // PRIORIDAD MÁS ALTA: dirección estructurada tipo "3ra Calle, 5ta
    // Avenida 1-34 Zona 2". Si el mensaje ya trae estos datos tan
    // específicos, se usan directamente sin pasar por los patrones más
    // genéricos de abajo (que podrían recortarla o no reconocerla).
    $estructurada = detectarDireccionEstructurada($t);
    if ($estructurada !== null) return $estructurada;

    // NUEVO: dirección por REFERENCIA RELACIONAL ("enfrente de Sarita, abajo
    // de la Iglesia", "frente a la tienda Doña María", "cerca del parque").
    // Va justo después de la dirección estructurada (que es más específica y
    // no debe perderse: "3ra calle 5-34 zona 2, frente a la iglesia") y antes
    // de los patrones genéricos, que descartaban estas frases y hacían que el
    // bot volviera a preguntar la ubicación ya dada en el mismo mensaje.
    $relacional = detectarDireccionRelacional($t);
    if ($relacional !== null) return $relacional;

    // AGREGADO: reconocer frases de ubicación natural como
    // "es por aquí en Villa Canales" o "es por acá en X" ANTES que el
    // patrón general "en la/el ___". Sin esto, un mensaje como
    // "hay un agujero en la calle ayer no esta, es por aqui en Villa Canales"
    // hacía match primero con "en la calle ayer no esta" (por el "en la"),
    // y la ubicación real ("Villa Canales") nunca se detectaba.
    if (preg_match(
        '/\b(?:es\s+por\s+aqu[ií]|es\s+por\s+ac[aá]|es\s+aqu[ií]|aqu[ií]\s+en|ac[aá]\s+en|por\s+aqu[ií]|por\s+ac[aá]|ubicad[oa]\s+aqu[ií])\s+(?:en\s+)?([^.,;\n]{3,100})/iu',
        $t, $m
    )) {
        $direccion = recortarEnPalabraCorte(trim($m[1]));
        if ($direccion !== '' && !esDireccionSoloGenerica($direccion)) {
            return $direccion;
        }
    }

    // ------------------------------------------------------------
    // AGREGADO: primero se busca si el mensaje menciona algún lugar
    // CONOCIDO del municipio (Chocola, Madre Mía, Piedritas, La
    // Ladrillera, Lolemi, Pacacoj, San Pablito, El Socorro, Flor del
    // Café, Ian Casitas, Casco Urbano — ver LUGARES_CONOCIDOS_MUNICIPIO).
    // Se intenta reconocer aunque el ciudadano NO diga el tipo de lugar
    // ("en chocola" en vez de "en Aldea Chocola") y aunque lo escriba con
    // minúsculas, MAYÚSCULAS o sin tildes ("en flor del cafe", "EN IAN
    // CASITAS").
    //
    // BUGFIX: antes se recorría LUGARES_CONOCIDOS_MUNICIPIO en el orden
    // en que está escrita la lista y se paraba en el PRIMER lugar que
    // encontraba ("break"). Eso podía hacer que un lugar corto/genérico
    // (ej. "el centro") ganara sobre uno más largo y específico que
    // también aparecía en el mismo mensaje (ej. "ian casitas"), solo por
    // estar antes en el array. Ahora se recorren TODOS los lugares y se
    // usa el MÁS LARGO que aparezca, que es siempre el más específico.
    // ------------------------------------------------------------
    $textoNorm = normalizarLugar($t);
    $mejorLugar = '';
    if ($textoNorm !== '') {
        foreach (LUGARES_CONOCIDOS_MUNICIPIO as $lugar) {
            if (strlen($lugar) <= strlen($mejorLugar)) continue; // ya hay uno igual o mejor
            $patronLugar = '/(?<![\p{L}\p{N}])' . preg_quote($lugar, '/') . '(?![\p{L}\p{N}])/u';
            if (preg_match($patronLugar, $textoNorm)) {
                $mejorLugar = $lugar;
            }
        }
    }

    if ($mejorLugar !== '') {
        // Se reconstruye el fragmento correspondiente en el TEXTO
        // ORIGINAL (para conservar tildes/mayúsculas tal como las
        // escribió el ciudadano), con un patrón insensible a
        // mayúsculas/minúsculas y tolerante a tildes.
        $lugarFlexible = '';
        foreach (preg_split('//u', $mejorLugar, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $lugarFlexible .= ($ch === ' ')
                ? '\s+'
                : preg_quote($ch, '/') . '[áéíóúü]?'; // tolera la tilde
        }
        $patronFlex = '/(?<![\p{L}\p{N}])' . $lugarFlexible . '(?![\p{L}\p{N}])/iu';

        if (preg_match($patronFlex, $t, $mFlex, PREG_OFFSET_CAPTURE)) {
            $inicio = $mFlex[0][1];

            // AGREGADO: extender la captura hacia ATRÁS si justo antes del
            // lugar conocido viene una palabra de "tipo de lugar" como
            // "camino", "calle", "sector", "cantón", etc. Sin esto, "camino
            // Ian Casitas" o "camino la Ladrillera" perdían el prefijo y la
            // dirección guardada quedaba incompleta ("Ian Casitas" en vez
            // de "camino Ian Casitas").
            //
            // BUGFIX 2026: se agregaron FRASES RELACIONALES (multi-palabra)
            // como "en frente del", "cerca del", "junto a", "al lado de",
            // etc., para conservar el contexto ("en frente del INSODI").
            // El orden importa: las frases MÁS LARGAS van PRIMERO, para que
            // "en frente del" no sea recortado por "frente del".
            static $PREFIJOS_LUGAR = [
                // --- Frases relacionales (multi-palabra) ---
                'en frente de los', 'en frente de las', 'en frente de la',
                'en frente del', 'en frente al',
                'en frente a los', 'en frente a las', 'en frente a la', 'en frente a',
                'en frente',
                'enfrente de los', 'enfrente de las', 'enfrente de la',
                'enfrente del', 'enfrente al',
                'enfrente a los', 'enfrente a las', 'enfrente a la', 'enfrente a',
                'enfrente',
                'frente a los', 'frente a las', 'frente a la', 'frente al',
                'frente de los', 'frente de las', 'frente de la', 'frente del', 'frente de',
                'cerca de los', 'cerca de las', 'cerca de la', 'cerca del', 'cerca de',
                'junto a los', 'junto a las', 'junto a la', 'junto al', 'junto a',
                'al lado de los', 'al lado de las', 'al lado de la', 'al lado del', 'al lado de',
                'a la par de los', 'a la par de las', 'a la par de la', 'a la par del', 'a la par de', 'a la par',
                'detras de los', 'detras de las', 'detras de la', 'detras del', 'detras de',
                'atras de los', 'atras de las', 'atras de la', 'atras del', 'atras de',
                'al costado de los', 'al costado de las', 'al costado de la', 'al costado del', 'al costado de',
                'a un costado de los', 'a un costado de las', 'a un costado de la', 'a un costado del', 'a un costado de',
                'a orillas de los', 'a orillas de las', 'a orillas de la', 'a orillas del', 'a orillas de',
                'a la altura de los', 'a la altura de las', 'a la altura de la', 'a la altura del', 'a la altura de',
                'a la vuelta de los', 'a la vuelta de las', 'a la vuelta de la', 'a la vuelta del', 'a la vuelta de',
                'a espaldas de los', 'a espaldas de las', 'a espaldas de la', 'a espaldas del', 'a espaldas de',
                'contiguo a los', 'contiguo a las', 'contiguo a la', 'contiguo al', 'contiguo a',
                'adyacente a los', 'adyacente a las', 'adyacente a la', 'adyacente al', 'adyacente a',

                // --- Tipos de lugar (originales) ---
                'camino', 'calle', 'avenida', 'av', 'calzada', 'colonia', 'col',
                'canton', 'aldea', 'caserio', 'barrio', 'sector', 'zona',
                'comunidad', 'finca', 'lotificacion', 'residencial',
                'kilometro', 'km', 'parcelamiento', 'microparcelamiento',
                'predio', 'terreno', 'carretera',
            ];
            // BUGFIX: hay que recortar los espacios en blanco ANTES de tomar
            // los últimos N caracteres del texto previo, o la palabra
            // ("Caserío ", "Aldea ") nunca calza con el prefijo normalizado
            // ("caserio", "aldea"): el espacio de separación se comía parte
            // de la ventana de comparación.
            $antesRecortado = rtrim(substr($t, 0, $inicio));
            foreach ($PREFIJOS_LUGAR as $pref) {
                $largoPref = mb_strlen($pref, 'UTF-8');
                $prefijoCandidato = mb_substr($antesRecortado, -($largoPref), $largoPref, 'UTF-8');
                if ($prefijoCandidato === '') continue;
                if (normalizarLugar($prefijoCandidato) !== $pref) continue;

                $posPrefijo = strlen($antesRecortado) - strlen($prefijoCandidato);
                if ($posPrefijo < 0) continue;
                $charAntes = $posPrefijo > 0 ? substr($t, $posPrefijo - 1, 1) : ' ';
                if (preg_match('/[\p{L}\p{N}]/u', $charAntes)) continue; // pegado a otra palabra

                $inicio = $posPrefijo;
                break;
            }

            // Se captura desde el inicio (lugar, o prefijo+lugar) hasta el
            // final del mensaje o hasta una coma/punto, para conservar
            // referencias adicionales ("Aldea Chocola, frente a la
            // escuela").
            $resto = substr($t, $inicio);
            if (preg_match('/^([^.,;\n]{2,150})/u', $resto, $mCorte)) {
                $direccion = recortarEnPalabraCorte(trim($mCorte[1]));
                if ($direccion !== '' && !esDireccionSoloGenerica($direccion)) {
                    return $direccion;
                }
            }
        }
    }

    // --- Resto de la lógica original (sin cambios) ---
    if (preg_match('/(?:en\s+la|en\s+el|direcci[oó]n\s*:?|vivo\s+en|ubicado\s+en|queda\s+en|frente\s+a|cerca\s+de|por\s+la|por\s+el)\s+([^.,;\n]{5,150})/iu', $t, $m)) {
        // BUGFIX: antes se devolvía $m[1] tal cual, que podía incluir todo
        // lo que sigue hasta la próxima coma/punto (ver comentario de
        // recortarEnPalabraCorte() arriba). Ej: "vivo en Caserío Rosario y
        // no hay agua desde ayer" se detectaba como dirección completa en
        // vez de solo "Caserío Rosario".
        $direccion = recortarEnPalabraCorte(trim($m[1]));
        if ($direccion === '') return null;
        // BUGFIX: "Hola hay un agujero en la calle" hacía match con el
        // patrón "en la ___" y capturaba solo "calle", que el bot
        // presentaba como si hubiera "identificado" la dirección. No es un
        // dato real (el ciudadano solo estaba describiendo dónde está el
        // agujero en términos genéricos, no dando ubicación). En ese caso
        // se descarta la detección automática y más adelante se le
        // pregunta la dirección normalmente, en vez de darla por buena.
        if (esDireccionSoloGenerica($direccion)) return null;
        return $direccion;
    }

    // BUGFIX: no se identificaba una dirección como "Aldea Chocola" cuando
    // el ciudadano escribía "en Aldea Chocola" (o "acá en Aldea Chocola")
    // SIN el artículo "la"/"el" en medio ("en la Aldea Chocola"), que es
    // como habla la gente normalmente de un lugar con nombre propio. El
    // patrón de arriba exige "en la"/"en el" exactos, así que un simple
    // "en Aldea Chocola" no hacía match con nada y el dato se perdía.
    //
    // Estas dos comprobaciones adicionales reconocen "en <lugar>" cuando lo
    // que sigue tiene pinta real de nombre de lugar: o empieza con una
    // palabra típica de dirección rural/urbana en Guatemala (aldea,
    // colonia, caserío, cantón, barrio, sector, zona, comunidad, finca,
    // lotificación, residencial, kilómetro/km), o empieza con Mayúscula
    // (nombre propio, ej. "en Chocola", "en Xela"). Se dejan como último
    // recurso (después del patrón de arriba) para no interferir con los
    // casos ya cubiertos.
    $tipoLugar = 'aldea|colonia|caser[ií]o|cant[oó]n|barrio|sector|zona|comunidad|finca|lotificaci[oó]n|residencial|kil[oó]metro|km|calle|avenida|camino|carretera|calzada';
    if (preg_match('/\ben\s+((?:' . $tipoLugar . ')\s+[^.,;\n]{2,150})/iu', $t, $m)) {
        $direccion = recortarEnPalabraCorte(trim($m[1]));
        if ($direccion !== '' && !esDireccionSoloGenerica($direccion)) return $direccion;
    }
    if (preg_match('/\ben\s+([A-ZÁÉÍÓÚÑ][^.,;\n]{4,150})/u', $t, $m)) {
        $direccion = recortarEnPalabraCorte(trim($m[1]));
        if ($direccion !== '' && !esDireccionSoloGenerica($direccion)) return $direccion;
    }

    // AGREGADO: reconocer "<tipo de lugar> <Nombre Propio>" aunque NO venga
    // precedido por "en" y aunque venga con artículo ("la colonia Miraflores",
    // "el barrio El Centro", "colonia Miraflores"). Los patrones anteriores
    // exigían literalmente "en <tipoLugar>" y fallaban con frases naturales
    // donde el tipo de lugar aparece como objeto directo del verbo.
    //
    // Caso real que motivó esto: "…un pájaro topó los cables y dejó sin luz
    // la colonia Miraflores". El ciudadano SÍ estaba dando la ubicación
    // (colonia Miraflores), pero sin escribir "en" antes. El bot no la
    // detectaba y volvía a pedirla, aunque ya la tenía.
    //
    // Se exige que después del tipo de lugar venga un NOMBRE PROPIO
    // (palabra(s) en Mayúscula, con conectores en minúscula como
    // "Flor del Café"). Así se evita capturar frases genéricas como
    // "la colonia está fea" o "el barrio es grande", donde la palabra
    // siguiente no es un nombre propio.
    $nombrePropio = '[A-ZÁÉÍÓÚÑ][\p{L}\']*(?:\s+(?:(?:de|del|la|las|los|y)\s+[A-ZÁÉÍÓÚÑ][\p{L}\']*|[A-ZÁÉÍÓÚÑ][\p{L}\']*))*';
    if (preg_match('/\b(?:la|el|los|las|al|del)?\s*(' . $tipoLugar . '\s+' . $nombrePropio . ')/u', $t, $m)) {
        $direccion = recortarEnPalabraCorte(trim($m[1]));
        if ($direccion !== '' && !esDireccionSoloGenerica($direccion)) return $direccion;
    }

    // AGREGADO: última red de contención para "camino X" / "calle X" /
    // "avenida X" etc. cuando lo que sigue no es exactamente un Nombre
    // Propio en mayúscula (ej. "camino la ladrillera", con minúsculas).
    if (preg_match('/\b((?:camino|calle|avenida|calzada|carretera)\s+[^.,;\n]{2,120})/iu', $t, $m)) {
        $direccion = recortarEnPalabraCorte(trim($m[1]));
        if ($direccion !== '' && !esDireccionSoloGenerica($direccion)) return $direccion;
    }

    return null;
}

/**
 * MEJORA: detectar cuando el ciudadano está confundido o pide ayuda, para
 * responderle de forma amigable y explicándole qué se le está pidiendo (en
 * vez de simplemente repetirle el mismo error de validación una y otra
 * vez, que es justo lo que generaba el bucle del caso anterior con el
 * DPI). Cubre tanto pedidos explícitos de ayuda ("ayuda", "no entiendo")
 * como preguntas típicas de alguien perdido en el flujo ("¿qué necesitas?",
 * "¿cómo funciona esto?", "¿para qué es esto?").
 */
function esConfundidoOPideAyuda($t) {
    $norm = normalizarParaFiltro(trim($t));
    $norm = trim(preg_replace('/[!¡?¿.,]+$/u', '', $norm));
    $patrones = [
        '/^ayuda$/u',
        '/^help$/u',
        '/no entiendo/u',
        '/no comprendo/u',
        '/estoy perdid[oa]/u',
        '/que (es esto|necesitas|necesitan|quieres|me pides|me piden|debo poner|pongo aqui|escribo aqui)/u',
        '/como funciona/u',
        '/para que es esto/u',
        '/no se que (hacer|poner|escribir|responder)/u',
        '/^\?+$/u',
    ];
    foreach ($patrones as $p) {
        if (preg_match($p, $norm)) return true;
    }
    return false;
}

/**
 * Explicación amigable de POR QUÉ se pide cada dato, para cuando el
 * ciudadano está confundido. A diferencia de preguntaPendienteSegunFase()
 * (que solo repite la pregunta), esta da un poquito de contexto para que
 * la persona entienda el propósito y se sienta acompañada, no interrogada.
 */
function ayudaAmigableSegunFase($sesion) {
    switch ($sesion['fase'] ?? 'inicio') {
        case 'esperando_nombre':
            return "Tranqui, te explico. Estoy armando tu solicitud formal ante la Municipalidad, y para eso necesito unos pocos datos tuyos.\n\nPara empezar: ¿cuál es tu *nombre completo*? (nombre y apellido)";
        case 'esperando_telefono':
            return "Te cuento: el *teléfono* es por si la Municipalidad necesita contactarte sobre tu solicitud. Si es el mismo número de este WhatsApp, solo escribí *este mismo*.\n\n¿A qué número te podemos contactar?";
        case 'esperando_direccion':
            if (($sesion['tipo_interes'] ?? 'particular') === 'social') {
                return "Para este tipo de reporte no necesito tus datos personales, solo saber *dónde está el problema* para que la cuadrilla pueda ubicarlo. Puede ser el nombre de la colonia o un punto de referencia conocido (ej. \"frente a la escuela X\").\n\n¿En qué colonia o lugar se encuentra?";
            }
            return "Es para que puedan ubicar el lugar exacto del problema que reportaste. Podés darme una dirección o simplemente un punto de referencia conocido (ej. \"frente a la escuela X\"). Si no lo sabés, escribí *\"sin direccion\"*.\n\n¿Cuál es la dirección o punto de referencia?";
        case 'confirmar':
            return "Es el último paso. Te muestro un resumen de todo lo que anotamos, y vos me confirmás si está bien.\n\nRespondé *SI* para confirmar y generar tu expediente, *NO* si querés cancelar, o *MODIFICAR* si algo está mal escrito.";
        case 'eligiendo_campo_modificar':
            return "Decime cuál de estos datos querés corregir, así solo cambiamos eso (no hace falta repetir todo):\n\n" . textoMenuModificar($sesion);
        default:
            return "¡No te preocupes, vamos paso a paso! Soy el asistente virtual de la Municipalidad de San Pablo Jocopilas: te ayudo a reportar un problema (como un bache, una fuga de agua, falta de alumbrado, etc.) o a pedir un trámite.\n\nContame con tus palabras qué necesitás, por ejemplo: \"Hay un bache grande en la 5ta calle 🕳️\" o \"Necesito información de algún evento 📅\".";
    }
}

/**
 * Detecta si, en la fase de confirmación, el ciudadano en realidad está
 * pidiendo corregir un dato (en vez de responder SI o NO). Cubre tanto la
 * palabra explícita ("modificar", "corregir") como frases naturales típicas
 * ("mi nombre está mal escrito", "el teléfono está equivocado").
 */
function esIntencionModificar($t) {
    $t = normalizarParaFiltro($t);
    // MEJORA: antes solo reconocía el infinitivo exacto ("modificar",
    // "corregir", etc.). Un ciudadano normalmente escribe conjugado
    // ("corrige mi dpi", "cambia el nombre", "actualiza la dirección"), y
    // esas formas no hacían match con nada. Se usan raíces (\w*) para
    // cubrir las conjugaciones más comunes de cada verbo, incluyendo las
    // que cambian de raíz por ortografía (corrijo/corrige/corrigiendo,
    // modifique/modifiquen, actualice/actualicen).
    $palabrasClave = '/\b(modific\w*|modifiqu\w*|correg\w*|corrig\w*|corrij\w*|edit\w*|cambi\w*|arregl\w*|actualiz\w*|actualic\w*)\b/u';
    if (preg_match($palabrasClave, $t)) return true;
    $frasesClave = '/(mal escrit|esta mal|está mal|equivocad|error en|me equivoqu)/u';
    if (preg_match($frasesClave, $t)) return true;
    return false;
}

/**
 * BUGFIX: cuando el ciudadano pedía modificar un dato Y ya escribía el
 * valor nuevo en el MISMO mensaje (ej. "Modificar nombre José Utulan"),
 * el bot solo detectaba la INTENCIÓN de modificar (esIntencionModificar())
 * y volvía a preguntar "¿qué dato querés modificar?" — perdiendo el valor
 * que el ciudadano ya había dado, y obligándolo a escribirlo de nuevo en
 * un segundo mensaje.
 *
 * Esta función identifica, dentro de un solo mensaje, tanto el CAMPO que
 * se quiere corregir ('nombre'|'dpi'|'direccion'|'peticion') como el VALOR
 * nuevo, si viene incluido. Se usa tanto para resolver la modificación de
 * una sola vez desde la fase 'confirmar', como desde el mini-menú de
 * 'eligiendo_campo_modificar' (que antes tenía esta misma lógica
 * duplicada con una expresión regular ligeramente distinta).
 *
 * Devuelve ['campo' => string|null, 'valor' => string] — $valor viene
 * vacío ('') si no se pudo separar un valor útil del inicio del mensaje
 * (ej. el ciudadano solo dijo "quiero corregir mi nombre", sin el nombre).
 */
function detectarCampoYValorModificacion($mensaje, $tipoInteres = 'particular') {
    $t = normalizarParaFiltro($mensaje);
    $social = ($tipoInteres === 'social');

    // Primero se buscan las PALABRAS que nombran el campo y solo después los
    // números del menú (1-4). Antes se evaluaba todo junto, así que un
    // mensaje como "cambiar dirección zona 1" caía en "nombre" por el "1".
    //
    // Menú según el tipo de interés (ver textoMenuModificar()):
    //   particular: 1 nombre · 2 teléfono · 3 dirección · 4 petición
    //   social:     1 dirección · 2 petición (no se piden datos personales)
    $campo = null;
    if ($social) {
        if (preg_match('/\b(direccion|ubicacion|colonia|lugar)\b/u', $t)) {
            $campo = 'direccion';
        } elseif (preg_match('/\b(peticion|problema|reporte)\b/u', $t)) {
            $campo = 'peticion';
        } elseif (preg_match('/\b(nombre|dpi|telefono|numero|celular|whatsapp)\b/u', $t)) {
            // En un reporte de interés social no se guardan datos personales.
            $campo = 'dato_personal';
        } elseif (preg_match('/\b([1-2])\b/u', $t, $mNum)) {
            $campo = ($mNum[1] === '1') ? 'direccion' : 'peticion';
        }
    } else {
        if (preg_match('/\b(nombre)\b/u', $t)) {
            $campo = 'nombre';
        } elseif (preg_match('/\b(telefono|numero|celular|whatsapp)\b/u', $t)) {
            $campo = 'telefono';
        } elseif (preg_match('/\b(dpi)\b/u', $t)) {
            $campo = 'dpi'; // heredado: el DPI ya no se pide, pero se sigue aceptando
        } elseif (preg_match('/\b(direccion)\b/u', $t)) {
            $campo = 'direccion';
        } elseif (preg_match('/\b(peticion|problema)\b/u', $t)) {
            $campo = 'peticion';
        } elseif (preg_match('/\b([1-4])\b/u', $t, $mNum)) {
            $campo = ['1' => 'nombre', '2' => 'telefono', '3' => 'direccion', '4' => 'peticion'][$mNum[1]];
        }
    }

    // Quita, desde el INICIO del mensaje: un verbo de acción opcional
    // ("modificar", "corregir", etc.), un artículo/posesivo opcional
    // ("el", "la", "mi"), la palabra o número que identifica el campo, y
    // un conector opcional ("es", ":", "-"). Lo que queda es el valor
    // nuevo, si el ciudadano lo incluyó en el mismo mensaje.
    $valor = trim(preg_replace(
        '/^\s*(?:(?:modific\w*|modifiqu\w*|correg\w*|corrig\w*|corrij\w*|edit\w*|cambi\w*|arregl\w*|actualiz\w*|actualic\w*)\s+)?(?:el\s+|la\s+|mi\s+)?(?:nombre|dpi|direccion|direcci[oó]n|ubicaci[oó]n|peticion|petici[oó]n|problema|tel[eé]fono|n[uú]mero|celular|[1-4])\s*(?:es\s+|a\s+|:|\-)?\s*/iu',
        '',
        $mensaje
    ));

    // Si la expresión de arriba no encontró nada que quitar (porque el
    // campo/verbo no estaba al inicio del mensaje, ej. "mi nombre está
    // mal escrito, es Juan Pérez"), no hay un valor inline confiable que
    // extraer de una forma simple: se deja vacío para que el flujo
    // pregunte el dato directamente en el siguiente mensaje.
    if ($valor === trim($mensaje)) $valor = '';

    return ['campo' => $campo, 'valor' => $valor];
}

/**
 * Valida y aplica un valor nuevo a uno de los 4 campos modificables de la
 * sesión, con la MISMA validación que ya se usaba por separado en cada
 * fase 'modificando_X'. Devuelve la nota de confirmación ("Nombre
 * actualizado.", etc.) si el valor era válido, o null si no pasó la
 * validación (en ese caso $sesion no se toca).
 */
function aplicarValorCampoModificado($campo, $valor, &$sesion, $numeroWhatsapp = null) {
    if ($valor === '') return null;
    switch ($campo) {
        case 'nombre':
            if (!esNombreValido($valor)) return null;
            $sesion['nombre'] = extraerNombre($valor);
            return 'Nombre actualizado.';
        case 'telefono':
            if (esMismoNumeroWhatsapp($valor) && $numeroWhatsapp !== null) {
                $sesion['telefono'] = $numeroWhatsapp;
                return 'Teléfono actualizado (usaremos el de este WhatsApp).';
            }
            $tel = extraerTelefono($valor);
            if ($tel === null) return null;
            $sesion['telefono'] = $tel;
            return 'Teléfono actualizado.';
        case 'dpi':
            if (!esDpiValido($valor)) return null;
            $sesion['dpi'] = esOmitir($valor) ? '' : extraerDigitos($valor, 8, 13);
            return 'DPI actualizado.';
        case 'direccion':
            if (!esDireccionValida($valor)) return null;
            $sesion['direccion'] = esOmitir($valor) ? '(No proporcionada)' : extraerDireccion($valor);
            return 'Dirección actualizada.';
        case 'peticion':
            $clasificacionInline = clasificarPeticion($valor);
            if (!esPeticionValida($valor, $clasificacionInline) || empty($clasificacionInline['tiene_senal_clara'])) return null;
            $sesion['texto_peticion'] = $valor;
            $sesion['clasificacion'] = $clasificacionInline;
            return 'Petición actualizada.';
    }
    return null;
}

/**
 * Fase a la que pasar y pregunta a mostrar cuando se identificó QUÉ campo
 * se quiere corregir pero todavía no se tiene un valor válido para
 * aplicarlo (mismos textos que ya usaba 'eligiendo_campo_modificar').
 */
function pedirValorCampo($campo) {
    switch ($campo) {
        case 'nombre':    return ['fase' => 'modificando_nombre',    'pregunta' => "Escribime tu *nombre completo* correcto:"];
        case 'telefono':  return ['fase' => 'modificando_telefono',  'pregunta' => "Escribime tu *número de teléfono* correcto (o *este mismo* si es el de este WhatsApp):"];
        case 'dpi':       return ['fase' => 'modificando_dpi',       'pregunta' => "Escribime tu *número de DPI* correcto (o \"sin dpi\"):"];
        case 'direccion': return ['fase' => 'modificando_direccion', 'pregunta' => "Escribime la *dirección o punto de referencia* correcta:"];
        case 'peticion':  return ['fase' => 'modificando_peticion',  'pregunta' => "Contame de nuevo cuál es el *problema o la petición*:"];
    }
    return null;
}

// ------------------------------------------------------------
// 3.0 INTERÉS PARTICULAR vs. INTERÉS SOCIAL
//
//   • Interés PARTICULAR (afecta a la persona, a su vivienda/negocio o es un
//     trámite propio): el bot pide NOMBRE y TELÉFONO, porque la
//     Municipalidad necesita ubicar a quién atender.
//   • Interés SOCIAL / ÁREA PÚBLICA (calles, baches, alumbrado, basura en
//     vía pública, un vehículo que estorba, etc.): el bot NO pide nombre ni
//     teléfono. Solo agradece el reporte y pide la UBICACIÓN (colonia o
//     punto de referencia), como haría una persona de la oficina.
//
// Las consultas informativas (fechas de un evento, cursos, becas...) y los
// trámites administrativos no pasan por aquí: se derivan directo a la
// encargada, sin pedir datos (ver fase 'inicio').
// ------------------------------------------------------------

/**
 * Decide si la petición es de interés 'social' (área pública) o
 * 'particular', puntuando señales en el texto. Solo se declara 'particular'
 * cuando las señales personales SUPERAN a las públicas; ante la duda o
 * empate se devuelve 'social' (es lo que menos molesta al ciudadano: no se
 * le piden datos personales que no hacen falta).
 *
 * Ejemplos:
 *   "Hay un bache enorme en la 5ta calle"                → social
 *   "Quiero denunciar este vehículo que solo estorba"     → social
 *   "No hay agua en mi colonia desde hace 3 días"         → social
 *   "Fuga de agua frente a mi casa"                       → social (es la vía pública)
 *   "Se me reventó la tubería en mi casa"                 → particular
 *   "Se cayó la pared de mi casa por el temblor"          → particular
 */
function detectarTipoInteres($mensaje, $clasificacion = null) {
    $norm = normalizarTexto($mensaje); // minúsculas, sin acentos ni signos

    $particular = 0;
    $social = 1;

    // Categorías que son infraestructura/servicio público por naturaleza.
    $categoria = is_array($clasificacion) ? ($clasificacion['categoria'] ?? '') : '';
    // Denuncias y conflictos entre particulares (ver IA 2/clasificar.php):
    // por definición solo afectan bienes privados, así que SIEMPRE son de
    // interés particular y siempre se pide el nombre, sin importar si el
    // mensaje menciona una calle, un camino, etc.
    if ($categoria === 'conflictos_particulares') {
        return 'particular';
    }
    if (in_array($categoria, ['bacheo', 'alumbrado_publico', 'recoleccion_basura', 'via_publica'], true)) {
        $social += 1;
    }

    // ---------------- Señales de interés PARTICULAR ----------------
    $lugarPrivado = 'casa|hogar|vivienda|domicilio|residencia|negocio|tienda|local|terreno|lote|propiedad|finca|parcela|patio|cocina|bano|cuarto|pared|techo|muro|portal';

    // CORRECCIÓN: cuando el ciudadano menciona su casa SOLO como punto de
    // referencia de ubicación ("en frente de mi casa", "cerca de mi casa",
    // "al lado de mi casa", "frente a mi casa", etc.), NO se cuenta como
    // interés particular: el problema está en la VÍA PÚBLICA (frente a la
    // casa), no dentro de la propiedad.
    //
    // Caso real que motivó esto: "un taxi se dio vuelta en frente de mi
    // casa". El ciudadano solo está diciendo DÓNDE ocurrió la obstrucción
    // vehicular (en la calle, frente a su casa). La solución óptima es
    // mover el vehículo (interés colectivo, no requiere datos personales),
    // no un trámite legal/administrativo propio.
    $esReferenciaDeUbicacion = preg_match(
        '/\b(?:en\s+frente|frente|enfrente|cerca|al\s+lado|a\s+la\s+par|junto|detr[áa]s|atr[áa]s|al\s+costado|a\s+un\s+costado|a\s+orillas)\s+(?:de|a|del)?\s*(?:mi|nuestra|nuestro)\s+(?:' . $lugarPrivado . ')\b/u',
        $norm
    ) === 1;

    // "en mi casa", "dentro de mi negocio" (el problema está ADENTRO de lo suyo).
    // Se omite si el mensaje usa la casa solo como punto de referencia.
    if (!$esReferenciaDeUbicacion
        && preg_match('/\b(en|dentro de|adentro de|al interior de)\s+(mi|mis|nuestra|nuestro)\s+(' . $lugarPrivado . ')\b/u', $norm)) {
        $particular += 3;
    }
    // "la tubería de mi casa". Pesa igual que "en mi casa" pero NO supera a una
    // mención clara de área pública ("el bache de la calle de mi casa" sigue
    // siendo social: bache/calle suman más).
    // Se omite también cuando es solo referencia de ubicación (ver arriba).
    if (!$esReferenciaDeUbicacion
        && preg_match('/\b(de|del)\s+(mi|nuestra|nuestro)\s+(' . $lugarPrivado . ')\b/u', $norm)) {
        $particular += 3;
    }
    // Servicios, cuentas y trámites propios.
    if (preg_match('/\bmi\s+(contador|medidor|conexion|acometida|cuenta|expediente|tramite|solicitud|escritura|dpi|recibo|servicio|licencia|permiso|boleto)\b/u', $norm)) $particular += 3;
    // "no tengo agua/luz" (en singular: habla de su propio servicio).
    if (preg_match('/\bno\s+tengo\s+(agua|luz|energia|drenaje|servicio)\b/u', $norm)) $particular += 2;
    // "se me reventó", "se me dañó".
    if (preg_match('/\bse\s+me\s+\w+/u', $norm)) $particular += 2;
    // Algo que le hicieron o le afecta directamente a la persona.
    if (preg_match('/\bme\s+(cobra\w*|cobr\w*|cort\w*|robaron|asalt\w*|amenaz\w*|agred\w*|golpe\w*|multa\w*|neg\w*|quit\w*|dano\w*|afect\w*|perjudic\w*|molest\w*|tumb\w*)\b/u', $norm)) $particular += 2;
    // Personas y bienes propios.
    if (preg_match('/\bmi\s+(vecino|vecina|hijo|hija|esposo|esposa|familia|papa|mama|hermano|hermana|carro|moto|vehiculo|pickup|camion|mascota|perro|gato)\b/u', $norm)) $particular += 2;
    // Lo dice expresamente.
    if (preg_match('/\b(asunto|tema|caso|problema|interes)\s+(personal|particular|privado)\b|\bes\s+(personal|particular)\b|\bpara\s+mi\s+(casa|familia|negocio)\b|\ba\s+mi\s+nombre\b|\bmi\s+propiedad\b/u', $norm)) $particular += 3;

    // ---------------- Señales de interés SOCIAL ----------------
    // Bienes/áreas públicas mencionadas como el problema en sí.
    if (preg_match('/\b(bache|baches|bacheo|hueco|huecos|hoyo|hoyos|alcantarilla|alcantarillas|poste|postes|luminaria|luminarias|alumbrado|banqueta|banquetas|acera|aceras|puente|cancha|parque|mercado|semaforo|topes?|basural|vertedero|botadero|tiradero|via\s+publica|vias\s+publicas|espacio\s+publico|area\s+publica|areas\s+publicas)\b/u', $norm)) $social += 3;
    // Lo que afecta a muchos.
    if (preg_match('/\b(vecinos|vecindario|comunidad|pobladores|habitantes|la\s+gente|poblacion|peatones|transeuntes|toda\s+la\s+(colonia|comunidad|aldea|calle|cuadra|zona|gente|poblacion)|todo\s+el\s+(sector|barrio|canton|caserio|vecindario|pueblo)|todos\s+los\s+vecinos|nuestra\s+(colonia|comunidad|aldea|calle|zona)|nuestro\s+(barrio|sector|canton|caserio))\b/u', $norm)) $social += 3;
    // Obstrucción o molestia en el espacio de todos (vehículos que estorban, etc.).
    if (preg_match('/\b(estorb\w*|obstru\w*|bloquea\w*|bloqueand\w*|bloqueo|impid\w*\s+(el\s+)?paso|no\s+dej\w*\s+pasar|mal\s+estacionad\w*|estacionad\w*|parquead\w*|abandonad\w*|invad\w*)\b/u', $norm)) $social += 3;
    // Referencias de lugar (débiles: también se usan para dar la dirección).
    if (preg_match('/\b(calle|calles|avenida|callejon|camino|caminos|carretera|esquina|colonia|barrio|sector|aldea|canton|caserio|zona)\b/u', $norm)) $social += 1;

    return ($particular > $social) ? 'particular' : 'social';
}

/**
 * Qué dato falta preguntar todavía, según el tipo de interés:
 *   particular → nombre, teléfono, dirección
 *   social     → solo dirección/ubicación (nunca nombre ni teléfono)
 * Devuelve el nombre de la fase: 'esperando_nombre', 'esperando_telefono',
 * 'esperando_direccion' o 'confirmar' (si ya no falta nada).
 */
function faseFaltante($sesion) {
    $social = (($sesion['tipo_interes'] ?? 'particular') === 'social');
    if (!$social) {
        if (empty($sesion['nombre'])) return 'esperando_nombre';
        // Con PARTICULAR_EXIGE_NOMBRE, "Anónimo" no cuenta como nombre.
        if (PARTICULAR_EXIGE_NOMBRE && $sesion['nombre'] === 'Anónimo') return 'esperando_nombre';
        // El teléfono solo se pregunta si PARTICULAR_PIDE_TELEFONO está activo.
        if (PARTICULAR_PIDE_TELEFONO && $sesion['nombre'] !== 'Anónimo' && empty($sesion['telefono'])) return 'esperando_telefono';
    }
    if (empty($sesion['direccion'])) return 'esperando_direccion';
    return 'confirmar';
}

/**
 * Texto que explica por qué, en un reporte particular (ej. una queja entre
 * vecinos), el nombre es obligatorio. Se usa cuando la persona dice que
 * prefiere no darlo (ver PARTICULAR_EXIGE_NOMBRE).
 */
function textoExplicarNombreObligatorio() {
    return "Entiendo 🙏 pero en este tipo de reporte (un asunto entre particulares) necesitamos tu nombre para poder dirigir la solicitud a la autoridad correspondiente y darle seguimiento. Tus datos solo se usan para este trámite.";
}

/**
 * Menú de datos que se pueden corregir antes de confirmar. En un reporte de
 * interés social no se ofrece nombre ni teléfono porque no se guardan.
 * La numeración debe coincidir con detectarCampoYValorModificacion().
 */
function textoMenuModificar($sesion) {
    if (($sesion['tipo_interes'] ?? 'particular') === 'social') {
        return "1️⃣ Dirección o colonia\n2️⃣ La petición / problema";
    }
    return "1️⃣ Nombre\n2️⃣ Teléfono\n3️⃣ Dirección\n4️⃣ La petición / problema";
}

/**
 * Palabras que se le sugieren al ciudadano en el mini-menú de corrección.
 */
function textoOpcionesModificar($sesion) {
    if (($sesion['tipo_interes'] ?? 'particular') === 'social') {
        return "*dirección* o *petición* (o el número correspondiente)";
    }
    return "*nombre*, *teléfono*, *dirección* o *petición* (o el número correspondiente)";
}

/**
 * Decide qué se le pregunta al ciudadano a continuación, deja la sesión en
 * la fase correcta, la guarda y devuelve el texto a enviar.
 *
 * @param array $extras     Fragmentos previos (ej. "Gracias, Juan.").
 * @param bool  $primeraVez true justo después de clasificar la petición
 *                          (fase 'inicio'): en interés particular antepone la
 *                          frase de "necesito algunos datos", y en ambos casos
 *                          el aviso de "si no te llega un mensaje..." (solo la
 *                          primera vez que este número pasa por acá).
 */
function avanzarAlSiguientePaso(&$sesion, $numero, array $extras = [], $primeraVez = false) {
    $msjBot = leerMensajesBot();
    $fase = faseFaltante($sesion);
    $sesion['fase'] = $fase;

    // CAMBIO: ya no hay paso de confirmación. Si no falta ningún dato
    // ('confirmar' = todo completo), la solicitud se registra de una vez
    // y se responde con la despedida (ver registrarSolicitud()). Los
    // fragmentos previos ($extras: "Gracias, Juan.", "Entendido, lo
    // registré como...", etc.) se conservan al inicio de la respuesta.
    if ($fase === 'confirmar') {
        return registrarSolicitud($sesion, $numero, $extras);
    }

    guardarSesion($numero, $sesion);

    $social = (($sesion['tipo_interes'] ?? 'particular') === 'social');
    $partes = $extras;

    if ($primeraVez && !$social) {
        $partes[] = $msjBot['pedir_datos_intro'];
    }

    switch ($fase) {
        case 'esperando_nombre':
            $partes[] = $msjBot['pedir_nombre'];
            break;
        case 'esperando_telefono':
            $partes[] = $msjBot['pedir_telefono'];
            break;
        default: // esperando_direccion
            $partes[] = $social ? $msjBot['pedir_ubicacion_social'] : $msjBot['pedir_direccion'];
            break;
    }

    return armarRespuestaFragmentada($partes);
}

// ------------------------------------------------------------
// 3.1 Filtro de lenguaje inapropiado (groserías, contenido sexual,
//     insultos). Este es un canal oficial de atención ciudadana, así
//     que si el mensaje contiene este tipo de lenguaje, NO se procesa
//     como una petición/nombre/dirección/etc.: se responde pidiendo un
//     tono respetuoso y se repite la pregunta que estaba pendiente, sin
//     alterar los datos ya guardados en la sesión.
// ------------------------------------------------------------
function normalizarParaFiltro($t) {
    $t = mb_strtolower($t, 'UTF-8');
    $mapa = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n'];
    return strtr($t, $mapa);
}

function contieneLenguajeInapropiado($mensaje) {
    static $PALABRAS_INAPROPIADAS = [
        // Contenido sexual / pornografía
        'porno', 'pornografia', 'xxx', 'nudes', 'desnudo', 'desnuda',
        'verga', 'pene', 'vagina', 'coger', 'cogiendo', 'follar', 'follando',
        // Groserías / insultos comunes
        'puta', 'putas', 'puto', 'putos', 'mierda', 'pendejo', 'pendeja',
        'cabron', 'joder', 'chingar', 'chingada', 'gonorrea',
        'hijueputa', 'hijo de puta', 'hdp', 'marica', 'maricon',
        'culero', 'culera', 'perra', 'zorra', 'malparido', 'malparida',
        'imbecil', 'estupido', 'idiota', 'verga',
    ];

    $t = ' ' . normalizarParaFiltro($mensaje) . ' ';
    foreach ($PALABRAS_INAPROPIADAS as $palabra) {
        $palabraNorm = normalizarParaFiltro($palabra);
        // Límite de palabra manual (en vez de \b) para que también funcione
        // bien con frases de varias palabras como "hijo de puta".
        if (preg_match('/(?<![a-z0-9])' . preg_quote($palabraNorm, '/') . '(?![a-z0-9])/u', $t)) {
            return true;
        }
    }
    return false;
}

/**
 * Devuelve el texto de la pregunta que sigue pendiente según la fase
 * actual de la conversación. Se usa para "recordarle" al ciudadano qué
 * se le estaba preguntando después de un aviso (por ejemplo, tras
 * detectar lenguaje inapropiado), sin perder el progreso ya guardado.
 */
function preguntaPendienteSegunFase($sesion) {
    switch ($sesion['fase'] ?? 'inicio') {
        case 'esperando_nombre':
            return "¿Cuál es tu *nombre completo*?";
        case 'esperando_telefono':
            return "¿A qué *número de teléfono* te podemos contactar? (si es el de este WhatsApp, escribí *este mismo*)";
        case 'esperando_direccion':
            if (($sesion['tipo_interes'] ?? 'particular') === 'social') {
                return "¿Podría indicarnos el nombre de la *colonia* o un *punto de referencia* del lugar?";
            }
            return "¿Cuál es la *dirección o punto de referencia* del problema?";
        case 'confirmar':
            return "Respondé *SI* para confirmar, *NO* para cancelar, o *MODIFICAR* si querés corregir algún dato.";
        case 'eligiendo_campo_modificar':
            return armarRespuestaFragmentada([
                "¿Qué dato querés modificar? 📝",
                "Respondé: " . textoOpcionesModificar($sesion) . ".",
            ]);
        case 'modificando_nombre':
            return "Escribime tu *nombre completo* correcto:";
        case 'modificando_telefono':
            return "Escribime tu *número de teléfono* correcto (o *este mismo* si es el de este WhatsApp):";
        case 'modificando_direccion':
            return "Escribime la *dirección o punto de referencia* correcta:";
        case 'modificando_peticion':
            return "Contame de nuevo cuál es el *problema o la petición*:";
        default:
            return "Contame qué problema querés reportar (ej. \"Bache en la 5ta calle\", \"Fuga de agua en mi colonia\").";
    }
}

/**
 * Arma el texto de resumen + la pregunta de confirmación. Se usa tanto al
 * llegar por primera vez a la fase 'confirmar' (tras pedir la dirección)
 * como al volver a ella después de que el ciudadano corrigió un dato, para
 * que siempre vea el resumen actualizado antes de confirmar.
 */
/**
 * Arma el resumen de confirmación como VARIAS burbujas fragmentadas (una
 * por cada dato), en vez de un solo bloque de texto con "\n\n". El
 * parámetro $extras permite anteponer fragmentos adicionales (ej. "Nombre
 * actualizado.") como su propia burbuja, en vez de pegarlos al resumen sin
 * separación.
 *
 * Nota: a diferencia de la versión anterior, este resumen ya NO incluye el
 * teléfono (el ciudadano ya lo ve implícito: es el mismo WhatsApp desde el
 * que está escribiendo).
 */
/**
 * Genera un TÍTULO CORTO de la petición para mostrarlo en el resumen de
 * confirmación (y quedar guardado como referencia rápida del caso), en vez
 * del mensaje textual completo que escribió el ciudadano (que suele traer
 * saludos, relleno de cortesía o redacción muy larga).
 *
 * IMPORTANTE: esto NO reemplaza el texto original en ningún lado del
 * expediente — mensaje_original en tickets.json (ver
 * whatsapp/recibir_bot.php, bloque "generarTicket") sigue guardando la
 * petición completa tal cual la escribió el ciudadano, para el
 * oficio/reporte formal. Esta función solo decide qué se le muestra como
 * "📝 Petición:" en el chat.
 *
 * Ejemplo real que motivó esto:
 *   Texto completo: "Hola fijese que hay un poste caido en la colonia
 *     flor del cafe."
 *   Antes se mostraba tal cual (larguísimo, con saludo incluido).
 *   Ahora: "Poste eléctrico caído o dañado".
 */
function resumenPeticion($texto) {
    $texto = trim((string) $texto);
    if ($texto === '') return '(sin descripción)';

    $norm = normalizarTexto($texto);

    // 1) Frases específicas con un título canónico claro. Se revisan
    //    primero porque son mucho más precisas que cualquier limpieza
    //    genérica del texto. El orden importa: los patrones más
    //    específicos (ej. "poste caído") van antes que los genéricos
    //    (ej. "tendido eléctrico") para dar el título más útil posible.
    $patronesTitulo = [
        // --- Alumbrado público / tendido eléctrico ---
        '/\bpost\w*\b.{0,25}\b(cay\w*|ca[ií]d[oa]s?|tumbad[oa]s?|inclinad[oa]s?|quebrad[oa]s?)\b/u' => 'Poste eléctrico caído o dañado',
        '/\bcable(s)?\b.{0,25}\b(suelto|pelado|expuesto|colgando|cortado)\b/u' => 'Cable eléctrico suelto o dañado',
        '/\btransformador(es)?\b/u' => 'Transformador dañado',
        '/\btendido\s+electrico\b|\bcableado\b|\balta\s+tension\b|\blinea(s)?\s+electrica(s)?\b/u' => 'Problema de tendido eléctrico',
        '/\bsin\s+luz\b|\bno\s+hay\s+luz\b|\bfoco(s)?\s+fundid\w*\b|\balumbrado\b.{0,20}\b(danad\w*|no\s+funciona|apagad\w*)\b|\bfarol(es)?\s+(apagad\w*|danad\w*)\b/u' => 'Falta de alumbrado público',

        // --- Bacheo / vía pública ---
        '/\bbache(s)?\b/u' => 'Bache en la vía',
        '/\bcalle\b.{0,20}\b(danad\w*|destruid\w*|agrietad\w*)\b|\basfalto\b.{0,20}\bdanad\w*\b/u' => 'Daño en la vía pública',

        // --- Agua y saneamiento ---
        '/\bfuga(s)?\s+de\s+agua\b|\btuberia\b.{0,20}\b(rota|reventad\w*|danad\w*)\b|\btubo\b.{0,20}\b(roto|reventad\w*)\b/u' => 'Fuga de agua',
        '/\bsin\s+agua\b|\bno\s+hay\s+agua\b|\bfalta\s+de\s+agua\b/u' => 'Falta de agua potable',
        '/\bagua\b.{0,20}\b(contaminad\w*|sucia|turbia)\b/u' => 'Agua contaminada',
        '/\bdrenaje\b|\balcantarilla(s)?\b.{0,20}\b(tapad\w*|obstruid\w*)\b/u' => 'Drenaje o alcantarilla obstruida',

        // --- Recolección de basura ---
        '/\bbasura\b.{0,25}\b(acumulad\w*|amontonad\w*|tirad\w*|no\s+recog\w*)\b|\bno\s+(recogen|pasan?)\s+(la\s+)?basura\b/u' => 'Acumulación de basura',
        '/\bmal\s+olor\b.{0,20}\bbasura\b|\bbasura\b.{0,20}\bmal\s+olor\b/u' => 'Basura y mal olor',

        // --- Ambiental ---
        '/\barbol(es)?\b.{0,25}\b(cay\w*|ca[ií]d[oa]s?|tumbad[oa]s?)\b/u' => 'Árbol caído',
        '/\bquema\s+de\s+basura\b|\bhumo\b.{0,20}\b(toxico|contaminante)\b/u' => 'Quema de basura / humo',
        '/\bcontaminacion\b/u' => 'Contaminación ambiental',

        // --- Seguridad / animales ---
        '/\bperro(s)?\b.{0,25}\b(agresiv\w*|peligros\w*|rabios\w*|suelt\w*)\b/u' => 'Perro agresivo o suelto',
        '/\banimal(es)?\s+suelt\w*\b|\bvaca\b|\btoro\b|\bcaballo\b|\bganado\b/u' => 'Animal suelto en la vía',
        '/\brobo\b|\basalto\b/u' => 'Incidente de seguridad',
        '/\bpelea\b|\brina\b|\bdisturbio\b/u' => 'Riña o disturbio',

        // --- Trámites administrativos ---
        '/\bsalon(es)?\b.{0,30}\bmunicipal\b|\bmunicipal\b.{0,30}\bsalon(es)?\b/u' => 'Solicitud de uso del Salón Municipal',
        '/\bboleto(s)?\s+(de\s+)?ornato\b|\bornato\b/u' => 'Solicitud de Boleto de Ornato',
        '/\bconstancia(s)?\b/u' => 'Solicitud de constancia',
        '/\bcertificacion(es)?\b|\bcertificado(s)?\s+municipal(es)?\b/u' => 'Solicitud de certificación municipal',
        '/\bsolvencia\s+municipal\b|\bpaz\s+y\s+salvo\b/u' => 'Solicitud de solvencia municipal',
        '/\biusi\b/u' => 'Trámite de IUSI',
    ];

    foreach ($patronesTitulo as $patron => $titulo) {
        if (preg_match($patron, $norm)) {
            return $titulo;
        }
    }

    // 2) Sin coincidencia específica: se limpia el texto (saludos y
    //    muletillas de arranque) y se trunca, en vez de devolver el
    //    mensaje completo del ciudadano tal cual.
    return limpiarTextoPeticionCorto($texto);
}

/**
 * Quita saludos y muletillas típicas de arranque ("Hola, fíjese que...",
 * "Buenas, disculpe la molestia pero...", "Quisiera reportarle que...")
 * del inicio del mensaje y lo trunca a un largo razonable de "título",
 * para usarse como resumen corto cuando resumenPeticion() no encontró
 * ningún patrón específico más preciso.
 */
function limpiarTextoPeticionCorto($texto) {
    $t = trim($texto);

    // Pasada 1: saludo inicial. Los saludos compuestos ("buenas tardes",
    // "buenas noches", "buenos días") van ANTES que "buenas" a secas,
    // porque en una alternancia regex se toma la primera opción que
    // matchea empezando por la izquierda, no la más larga — si "buenas"
    // fuera primero, "buenas tardes" quedaría cortado a la mitad
    // ("tardes, ..." suelto).
    $t = preg_replace(
        '/^(buenos\s+d[ií]as|buenas\s+tardes|buenas\s+noches|hola|buenas|saludos)[\s,.:!]+/iu',
        '',
        $t
    );

    // Pasada 2: muletillas de cortesía típicas al arrancar el mensaje
    // ("fíjese que", "disculpe la molestia", "por favor", "quisiera
    // reportarle que", etc.), independiente de si hubo saludo antes o no.
    // Se aplica repetidas veces por si vienen varias encadenadas (ej.
    // "disculpe la molestia pero se cayó...").
    $patronRelleno = '/^(fijese\s+que|f[ií]jese\s+que|disculpe(\s+la\s+molestia)?|perd[oó]n(\s+la\s+molestia)?|'
        . 'por\s+favor|pero|'
        . 'quisiera\s+reportar(le)?\s+que|quiero\s+reportar(le)?\s+que|(le\s+)?quer[ií]a\s+reportar(le)?\s+que|'
        . 'necesito\s+reportar(le)?\s+que|quisiera\s+informar(le)?\s+que|quiero\s+informar(le)?\s+que|'
        . 'le\s+escribo\s+(porque|para)|le\s+cuento\s+que|resulta\s+que)[\s,.:!]*/iu';
    for ($i = 0; $i < 4; $i++) {
        $nuevo = preg_replace($patronRelleno, '', $t, 1);
        if ($nuevo === $t) break;
        $t = $nuevo;
    }

    $t = trim($t, " ,.:;!¡¿?\n\t");

    // Si tras quitar el relleno no quedó nada (mensaje que era PURO
    // saludo/relleno, caso raro porque ya pasó por esPeticionValida), se
    // usa el texto original sin tocar en vez de devolver un string vacío.
    if ($t === '') {
        $t = trim($texto);
    }

    $LARGO_MAXIMO = 80;
    if (mb_strlen($t) > $LARGO_MAXIMO) {
        $t = rtrim(mb_substr($t, 0, $LARGO_MAXIMO)) . '…';
    }

    if ($t !== '') {
        $t = mb_strtoupper(mb_substr($t, 0, 1)) . mb_substr($t, 1);
    }

    return $t;
}

// NOTA: armarResumenConfirmacion() YA NO SE USA en el flujo normal: el paso de
// confirmación ("📋 Revisá los datos antes de confirmar") fue eliminado y la
// solicitud se registra de inmediato con registrarSolicitud(). Se conserva
// (junto con esIntencionModificar() y las demás funciones de corrección de
// datos) por si en el futuro se quisiera volver a ese paso; se puede borrar.
function armarResumenConfirmacion($sesion, array $extras = []) {
    $cla = $sesion['clasificacion'] ?? [];
    $partes = $extras;
    $partes[] = "📋 Revisá los datos antes de confirmar:";
    // AJUSTE: estas 5 líneas van juntas, como un solo bloque de datos (un
    // solo elemento del arreglo, unido con "\n" simple), para que la
    // línea en blanco que ahora agrega armarRespuestaFragmentada() entre
    // partes no las separe una de otra.
    // Interés SOCIAL (área pública): el resumen no lleva nombre ni teléfono,
    // porque no se piden. Interés PARTICULAR: lleva nombre y, si el
    // ciudadano no es anónimo, también el teléfono de contacto.
    $esSocial = (($sesion['tipo_interes'] ?? 'particular') === 'social');
    $lineas = [
        "📝 Petición: " . resumenPeticion($sesion['texto_peticion'] ?? ''),
        "📂 Categoría: " . ($cla['categoria_nombre'] ?? '-'),
    ];
    if (!$esSocial) {
        $lineas[] = "👤 Nombre: " . ($sesion['nombre'] ?? '');
        if (($sesion['nombre'] ?? '') !== 'Anónimo' && !empty($sesion['telefono'])) {
            $lineas[] = "📞 Teléfono: {$sesion['telefono']}";
        }
    }
    $lineas[] = "📍 Dirección: {$sesion['direccion']}";
    $partes[] = implode("\n", $lineas);
    // Nueva línea de confirmación con emojis
    $partes[] = "✅ SÍ (para confirmar)\n❌ NO (para cancelar)\n✏️ MODIFICAR (si algo está mal)";
    return armarRespuestaFragmentada($partes);
}

// ------------------------------------------------------------
// 4. Generación de expediente y texto final (equivalente reducido
//    de clasificar_y_generar.php pero en texto plano para WhatsApp)
// ------------------------------------------------------------
function generarExpediente($categoria) {
    $prefijos = [
        'bacheo' => 'BCH', 'alumbrado_publico' => 'ALM', 'fuga_agua' => 'AGU',
        'recoleccion_basura' => 'BAS', 'incidentes_seguridad' => 'SEG',
        'gestion_ambiental' => 'AMB', 'administrativo' => 'ADM',
        'via_publica' => 'VIA', 'conflictos_particulares' => 'PAR'
    ];
    $pref = $prefijos[$categoria] ?? 'GEN';
    // AJUSTE: antes el número final se generaba al azar (rand(1,9999)),
    // lo que no era correlativo y podía repetirse. Ahora se toma de un
    // contador correlativo configurable (ver tickets/correlativo_solicitud.php
    // y el panel "Correlativo Inicial" en vistas/admin_tickets.php), que un
    // administrador puede definir en qué número debe continuar.
    $numero = obtenerYAvanzarNumeroSolicitud();
    return "SPJ-{$pref}-" . date('Y') . "-" . str_pad($numero, 4, '0', STR_PAD_LEFT);
}

function plazoTexto($urgencia) {
    return ($urgencia === 'alta') ? '24 horas' : '7 días';
}

function etiquetaUrgencia($urgencia) {
    // Sistema binario: solo hay dos niveles, ambos con su color propio.
    //   ALTA  → 🔴 rojo
    //   BAJA  → 🟡 amarillo
    return ($urgencia === 'alta') ? '🔴 urgencia alta' : '🟡 urgencia baja';
}

/**
 * Devuelve un saludo de bienvenida elegido al azar entre varias variantes,
 * para que el bot no repita siempre el mismo texto cuando el ciudadano
 * escribe "Hola". Todas dicen lo mismo en esencia (quiénes somos y para
 * qué sirve el canal) pero con distinto tono.
 *
 * MEJORA: cada variante ahora se arma con armarRespuestaFragmentada(),
 * así que se envía como VARIAS burbujas de WhatsApp separadas (saludo,
 * explicación del canal, cierre) en vez de un solo mensaje largo con
 * saltos de línea — se siente más natural, como una conversación real.
 * También se agregaron más variantes (antes había 5) para que el saludo
 * no se sienta repetitivo.
 */
function saludoBienvenidaAleatorio() {
    $saludos = [
        // Opción 1 — Formal y clara
        armarRespuestaFragmentada([
            "👋 ¡Hola! Bienvenido(a) al asistente virtual de la Municipalidad de San Pablo Jocopilas. 🏛️",
            "Estoy aquí para ayudarle a reportar emergencias, problemas o falta de servicios en su sector.",
            "Su reporte será recibido y canalizado al área correspondiente.",
        ]),
        // Opción 2 — Cercana y ciudadana
        armarRespuestaFragmentada([
            "👋 ¡Hola! Soy el asistente virtual de la Municipalidad de San Pablo Jocopilas. 🤝",
            "Queremos escucharle y ayudarle.",
            "Puede utilizar este medio para reportar emergencias, inconvenientes o servicios que hagan falta en su comunidad.",
        ]),
        // Opción 3 — Breve y directa
        armarRespuestaFragmentada([
            "👋 ¡Hola! Soy el asistente virtual de la Municipalidad de San Pablo Jocopilas.",
            "📢 Puede utilizar este servicio para reportar emergencias, problemas o falta de servicios en su sector.",
            "Estamos aquí para atender su reporte. 🙌",
        ]),
        // Opción 4 — Enfocada en la atención ciudadana
        armarRespuestaFragmentada([
            "👋 ¡Hola! Le damos la bienvenida al asistente virtual de la Municipalidad de San Pablo Jocopilas. 🏛️",
            "💬 Este canal ha sido creado para que pueda comunicarnos cualquier emergencia, problema o necesidad relacionada con los servicios de su comunidad.",
            "📍 ¡Su reporte es importante para nosotros!",
        ]),
        // Opción 5 — Más moderna para WhatsApp
        armarRespuestaFragmentada([
            "👋 ¡Hola! Soy el asistente virtual de la Municipalidad de San Pablo Jocopilas. 🤖🏛️",
            "Estoy aquí para ayudarle a reportar emergencias, fallas o falta de servicios en su sector.",
            "📲 Cuénteme qué está sucediendo y le ayudaré a dirigir su reporte al área correspondiente.",
        ]),
        // Opción 6 — Cálida, en tuteo
        armarRespuestaFragmentada([
            "¡Hola! 👋 Bienvenido(a) al asistente virtual de la Municipalidad de San Pablo Jocopilas.",
            "🙌 Estamos para ayudarte con cualquier problema, emergencia o servicio que haga falta en tu comunidad.",
            "📍 Contame qué está pasando y lo canalizamos al área correspondiente.",
        ]),
        // Opción 7 — Institucional, directo al grano
        armarRespuestaFragmentada([
            "🏛️ Municipalidad de San Pablo Jocopilas — Asistente Virtual",
            "💬 Este canal está para recibir sus reportes de emergencias, daños o falta de servicios municipales.",
            "📍 ¡Su reporte es importante para nosotros!",
        ]),
        // Opción 8 — Corta, orientada a la acción
        armarRespuestaFragmentada([
            "¡Hola! 👋 Soy el asistente virtual de la Municipalidad de San Pablo Jocopilas.",
            "Contame en pocas palabras qué está pasando (por ejemplo: \"Bache en la 5ta calle\") y yo me encargo del resto. 🙏",
        ]),
    ];
    return $saludos[array_rand($saludos)];
}

/**
 * Devuelve, al azar, uno de los mensajes con los que se le pide al
 * ciudadano un poco más de detalle sobre su problema, cuando lo que
 * escribió es demasiado corto o vago como para identificar una petición
 * real (ej. no dice qué pasa ni dónde). Se alternan variantes en tuteo
 * ("Contame...") y en usted ("Cuénteme..."), y algunas con emoji, para
 * que el bot no repita siempre exactamente el mismo texto.
 */
function mensajePideMasDetalleAleatorio() {
    $mensajes = [
        "Contame con un poco más de detalle qué está pasando (qué problema es y dónde), así puedo ayudarte mejor 🙏",
        "Cuénteme con un poco más de detalle qué está pasando (qué problema es y dónde), así puedo ayudarle mejor 🙏",
        "Contame un poco más: ¿cuál es el problema y en qué lugar está pasando? 📍 Así te puedo ayudar mejor 🙏",
        "Necesito un poco más de información para poder ayudarle: ¿qué está pasando y en dónde? 📍🙏",
    ];
    return $mensajes[array_rand($mensajes)];
}

/**
 * Variantes del mensaje de CIERRE que se envía justo después de confirmar
 * y registrar la solicitud (después de "✅ Solicitud registrada",
 * "📋 Expediente: ..." y "📂 Categoría: ..."). Se elige una al azar cada
 * vez, con distintos tonos (formal, amable, institucional, cordial, para
 * sistema municipal, estilo IA, etc.), para que el cierre no se sienta
 * repetitivo y el ciudadano vea variedad igual que en los saludos.
 *
 * NOTA: la variante "Con número de ticket" que se pidió agregar tenía el
 * mismo texto que "Formal" (solo con un ✅ delante), y ese ✅ ya se muestra
 * en el encabezado fijo "✅ Solicitud registrada" de la respuesta, así que
 * no se duplicó como entrada aparte para no repetir el emoji dos veces.
 */
function mensajeCierreSolicitudAleatorio($nombre) {
    $nombre = trim((string) $nombre) !== '' ? $nombre : 'vecino(a)';
    $variantes = [
        // Formal
        armarRespuestaFragmentada([
            "✅ Tu solicitud ha sido registrada correctamente.",
            "Será atendida a la brevedad posible. Gracias por tu reporte, {$nombre}. 🙏",
        ]),
        // Amable
        armarRespuestaFragmentada([
            "📝 Hemos recibido y registrado tu solicitud.",
            "Nuestro equipo la atenderá lo antes posible. ¡Gracias por tu reporte, {$nombre}! 🙏",
        ]),
        // Corta
        armarRespuestaFragmentada([
            "✅ Solicitud registrada correctamente.",
            "Será atendida lo antes posible. Gracias por reportarlo, {$nombre}. 🙏",
        ]),
        // Institucional
        armarRespuestaFragmentada([
            "🏛️ Su solicitud ha sido registrada exitosamente y será gestionada a la mayor brevedad posible.",
            "Agradecemos su reporte, {$nombre}. 🙏",
        ]),
        // Cordial
        armarRespuestaFragmentada([
            "¡Gracias por comunicarte con nosotros, {$nombre}! 😊",
            "Tu solicitud ha sido registrada y será atendida lo antes posible. 🙏",
        ]),
        // Para WhatsApp
        armarRespuestaFragmentada([
            "📱 Hemos recibido tu reporte correctamente.",
            "Será atendido a la brevedad posible. ¡Gracias por comunicarte con nosotros, {$nombre}! 🙏",
        ]),
        // Más natural
        armarRespuestaFragmentada([
            "📋 Hemos recibido tu reporte y ya ha sido registrado.",
            "En breve será revisado y atendido por el área correspondiente. ¡Gracias, {$nombre}! 🙌",
        ]),
        // Para sistema municipal
        armarRespuestaFragmentada([
            "🏛️ Hemos registrado correctamente tu solicitud.",
            "¡Muchas gracias por comunicarse con la Municipalidad, {$nombre}! 🙏",
        ]),
        // Automática / IA (separada en dos burbujas)
        armarRespuestaFragmentada([
            "🤖 Gracias por tu reporte, {$nombre}.",
            "Te informaremos sobre cualquier actualización. 📲",
        ]),
    ];
    return $variantes[array_rand($variantes)];
}

// ------------------------------------------------------------
// 4.1 Registro INMEDIATO de la solicitud (sin paso de confirmación)
// ------------------------------------------------------------
/**
 * Crea el ticket real en tickets.json y arma la respuesta final para el
 * ciudadano: "✅ Solicitud registrada" + categoría + mensaje de despedida.
 *
 * CAMBIO: antes, cuando ya se tenían todos los datos, el bot mostraba un
 * resumen ("📋 Revisá los datos antes de confirmar: ...") y esperaba que el
 * ciudadano respondiera SÍ / NO / MODIFICAR. En la práctica el ciudadano no
 * revisa ese resumen, así que ese paso se eliminó: en cuanto se completa el
 * último dato requerido, la solicitud se registra de una vez y se responde
 * con la despedida. La lógica de este bloque es la misma que antes vivía
 * dentro de la fase 'confirmar' (rama "SÍ"), solo que ahora se ejecuta sin
 * esperar la confirmación.
 *
 * @param array  $sesion  Sesión de la conversación (se borra al terminar).
 * @param string $numero  Número de WhatsApp del ciudadano.
 * @param array  $extras  Fragmentos que se muestran ANTES del "✅ Solicitud
 *                        registrada" (ej. "Buenos días, muchas gracias por
 *                        el reporte.", "Entendido, lo registré como: ...").
 * @return string Texto a enviar por WhatsApp.
 */
function registrarSolicitud(&$sesion, $numero, array $extras = []) {
    // Variables del script principal: $mensaje es el último mensaje del
    // ciudadano (se usa para completar la evidencia) y
    // $limpiarConversacionAlFinal le indica al bloque final del script que
    // borre el archivo de conversación una vez creado el ticket.
    global $mensaje, $limpiarConversacionAlFinal;

    $cla = $sesion['clasificacion'] ?? [];
    $categoria = $cla['categoria'] ?? 'administrativo';
    $esSocialTicket = (($sesion['tipo_interes'] ?? 'particular') === 'social');
    $expediente = generarExpediente($categoria);
    $urgencia = $cla['urgencia'] ?? 'baja';
    $departamentoId = $cla['departamento_id'] ?? 0;

    // Respuesta final: lo que ya se le iba a decir a este ciudadano en este
    // turno ($extras) + encabezado del registro + despedida aleatoria.
    // El encabezado va unido en un solo bloque (con "\n" simple) para que
    // "✅ Solicitud registrada" y la categoría queden pegados, y la línea en
    // blanco que agrega armarRespuestaFragmentada() quede solo antes del
    // mensaje de cierre.
    $partes = $extras;
    $partes[] = mensajeCierreSolicitudAleatorio($sesion['nombre'] ?? '');
    $respuesta = armarRespuestaFragmentada($partes);

    // ------------------------------------------------------------
    // Evidencia del reporte: copiar la conversación completa de este
    // número dentro del propio ticket, para que el oficio pueda mostrarla
    // en la sección "IV. Evidencia del Reporte".
    //
    // Se toma todo lo ya guardado por registrarTurnoConversacion() desde
    // el primer mensaje, y se le agrega A MANO el turno actual (mensaje del
    // ciudadano → respuesta de registro de arriba), porque ese turno
    // todavía no se ha escrito al archivo en este punto del código (eso
    // ocurre al final del script). Sin este agregado, la evidencia se
    // cortaría justo antes del último mensaje del ciudadano.
    // ------------------------------------------------------------
    $conversacionParaTicket   = leerConversacion($numero);
    $conversacionParaTicket[] = [
        'hora'      => date('Y-m-d H:i:s'),
        'ciudadano' => $mensaje,
        'bot'       => $respuesta,
    ];

    $ticketId = crearTicket([
        'remitente' => $numero,
        'mensaje_original' => $sesion['texto_peticion'] ?? $mensaje,
        'categoria' => $categoria,
        'urgencia' => $urgencia,
        'departamento_id' => $departamentoId,
        'estado' => 'pendiente',
        'canal' => 'whatsapp',
        'expediente' => $expediente,
        // Interés SOCIAL (área pública): el ticket no lleva nombre ni
        // teléfono de contacto, y el oficio tampoco (ver
        // reportes/generar.php). 'remitente' (el WhatsApp desde el que
        // escribió) sí se conserva porque el sistema lo necesita para ligar
        // la conversación y que la encargada pueda responder por el mismo
        // chat.
        'interes' => $esSocialTicket ? 'social' : 'particular',
        'nombre_ciudadano' => $esSocialTicket ? '' : ($sesion['nombre'] ?? ''),
        'dpi' => $esSocialTicket ? '' : ($sesion['dpi'] ?? ''),
        'telefono_contacto' => $esSocialTicket ? '' : (!empty($sesion['telefono']) ? $sesion['telefono'] : $numero),
        'direccion' => $sesion['direccion'] ?? '',
        'conversacion' => $conversacionParaTicket,
        'fotos' => $sesion['fotos'] ?? [],
    ]);

    if ($ticketId) {
        registrarLog("Ticket #$ticketId (expediente $expediente) creado por WhatsApp para $numero (categoria: $categoria)", 'info', 'ia.log');
        // Ticket creado (evidencia ya copiada adentro): el archivo de
        // conversación de este número se puede limpiar al final del
        // request (ver bloque final del script).
        $limpiarConversacionAlFinal = true;
        // Marcar ventana de "post-cierre" (ver bloque 1.2 arriba):
        // durante los próximos 30 minutos, si el ciudadano manda una
        // aclaración corta sobre el mismo caso (sin señal clara de
        // petición nueva), el bot responderá brevemente confirmando
        // que anotó la aclaración, en vez de reiniciar el flujo.
        marcarPostCierre($numero);
    } else {
        // Si por alguna razón no se pudo guardar el ticket (ej. error de
        // disco/permisos), se deja registrado en el log de errores y NO se
        // limpia el archivo de conversación, para no perder el historial
        // de una solicitud que quedó sin guardar.
        registrarLog("No se pudo crear el ticket en tickets.json para el expediente $expediente ($numero)", 'error', 'errores.log');
    }

    borrarSesion($numero);
    return $respuesta;
}

// ------------------------------------------------------------
// 5. Máquina de estados de la conversación
// ------------------------------------------------------------
$sesion = cargarSesion($numero);
// Compatibilidad hacia atrás: sesiones guardadas antes de este cambio
// (o con un 'tiene_foto' booleano de una versión previa del parche) no
// tienen todavía la clave 'fotos' como arreglo.
if (!isset($sesion['fotos']) || !is_array($sesion['fotos'])) {
    $sesion['fotos'] = [];
}
// Compatibilidad hacia atrás: sesiones guardadas antes de este cambio no
// tienen todavía la clave 'ia_desactivada'.
if (!isset($sesion['ia_desactivada'])) {
    $sesion['ia_desactivada'] = false;
}
if (!isset($sesion['intentos_sin_clasificar'])) {
    $sesion['intentos_sin_clasificar'] = 0;
}

// Si este mensaje trajo una o varias fotografías y se pudieron descargar
// (ver whatsapp-bot/index.js), las agregamos a la lista de fotos de esta
// conversación, sin importar en qué momento hayan llegado.
foreach ($fotosEntrantes as $ruta) {
    if (!in_array($ruta, $sesion['fotos'], true)) {
        $sesion['fotos'][] = $ruta;
    }
}

$respuesta = '';

// ------------------------------------------------------------
// 2.4 Mensajes fuera de horario / backlog.
//
// Si el ciudadano escribió fuera del horario de atención (o el mensaje
// quedó pendiente mientras el bot estaba apagado), ya no tiene sentido
// llevarlo por el flujo interactivo: probablemente la encargada ya lo
// atendió a mano. Se crea directamente un ticket "informativo" con lo que
// se pueda extraer del propio mensaje (texto y fotos como evidencia, para
// que se genere el oficio y entre al informe mensual) y NO se envía nada
// por WhatsApp: se devuelve "sin_respuesta_automatica", que el bot de
// Node ya entiende como silencio intencional (sin reintentos ni errores).
// ------------------------------------------------------------
if ($fueraDeHorario) {
    $conversacionParaTicket = leerConversacion($numero);
    $notaSilencio = '[Sin respuesta automática: mensaje fuera del horario de atención]';
    $ticketInfId = false;

    if (!$esFotoSinDescripcion) {
        $clasificacionFH = clasificarPeticion($mensaje);
        // Solo se crea ticket si el texto es una petición real (no un
        // saludo, un "gracias" ni relleno): mismo criterio que el flujo normal.
        if (esPeticionValida($mensaje, $clasificacionFH)) {
            $categoriaFH = $clasificacionFH['categoria'] ?? 'administrativo';
            $conversacionParaTicket[] = [
                'hora'      => date('Y-m-d H:i:s'),
                'ciudadano' => $mensaje,
                'bot'       => $notaSilencio,
            ];
            $ticketInfId = crearTicket([
                'remitente'         => $numero,
                'mensaje_original'  => $mensaje,
                'categoria'         => $categoriaFH,
                'urgencia'          => $clasificacionFH['urgencia'] ?? 'baja',
                'departamento_id'   => $clasificacionFH['departamento_id'] ?? 0,
                'estado'            => 'pendiente',
                'canal'             => 'whatsapp',
                'expediente'        => generarExpediente($categoriaFH),
                'nombre_ciudadano'  => extraerNombreDeTexto($mensaje) ?? '',
                'telefono_contacto' => $numero,
                'direccion'         => extraerDireccionDeTexto($mensaje) ?? '',
                'conversacion'      => $conversacionParaTicket,
                'fotos'             => $sesion['fotos'] ?? [],
                'es_informativa'    => true,
                'fuera_de_horario'  => true,
            ]);
        }
    } elseif (!empty($sesion['fotos'])) {
        // Foto sin descripción: se registra igual para no perder la evidencia.
        $conversacionParaTicket[] = [
            'hora'      => date('Y-m-d H:i:s'),
            'ciudadano' => '[Fotografía adjunta, sin descripción]',
            'bot'       => $notaSilencio,
        ];
        $ticketInfId = crearTicket([
            'remitente'         => $numero,
            'mensaje_original'  => '[Fotografía sin descripción]',
            'categoria'         => 'administrativo',
            'urgencia'          => 'baja',
            'departamento_id'   => 0,
            'estado'            => 'pendiente',
            'canal'             => 'whatsapp',
            'expediente'        => generarExpediente('administrativo'),
            'nombre_ciudadano'  => '',
            'telefono_contacto' => $numero,
            'direccion'         => '',
            'conversacion'      => $conversacionParaTicket,
            'fotos'             => $sesion['fotos'],
            'es_informativa'    => true,
            'fuera_de_horario'  => true,
        ]);
    }

    if ($ticketInfId) {
        registrarLog("Ticket #{$ticketInfId} (INFORMATIVA, fuera de horario) creado para {$numero}", 'info', 'ia.log');
    } else {
        registrarLog("Mensaje fuera de horario de {$numero} sin ticket (no era una petición válida o hubo un error al guardar)", 'info', 'ia.log');
    }

    // No se arrastra estado: si el ciudadano escribe en horario, la
    // conversación arranca desde cero.
    borrarSesion($numero);
    borrarConversacion($numero);

    ob_end_clean();
    echo json_encode(['respuesta' => '', 'sin_respuesta_automatica' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ------------------------------------------------------------
// 2.3 Atención humana: la IA no logró identificar ningún trámite
//     reconocido en un mensaje anterior de este número (ver más abajo,
//     fase 'inicio', "tiene_senal_clara"=false: cosas como "nichos del
//     cementerio", "COCODES", u otros trámites que el catálogo de
//     "IA 2/datos_ia.json" no tiene mapeados).
//
// Mientras $sesion['ia_desactivada'] esté en true, el bot NO contesta
// nada automáticamente en este chat (para no interferir mientras la
// encargada atiende manualmente), pero sigue "escuchando" en silencio:
// cada mensaje nuevo se vuelve a pasar por el clasificador, solo para
// detectar si esta vez SÍ describe un trámite reconocido. En cuanto eso
// pasa, se reactiva la IA (ia_desactivada = false) y ese mismo mensaje
// sigue el flujo normal más abajo, como si fuera una petición nueva.
//
// Un mensaje de foto sin descripción todavía ("__FOTO_RECIBIDA__") no se
// pasa por el clasificador (no hay texto que clasificar): mientras la IA
// esté desactivada, esas fotos también se reciben en silencio.
// ------------------------------------------------------------
if ($sesion['ia_desactivada']) {
    $clasificacionMientrasDesactivada = $esFotoSinDescripcion ? null : clasificarPeticion($mensaje);

    // FIX: mensajes ultra-cortos o de relleno ("A vaya", "ok", "va", "bueno",
    // "sí", "ya"...) mientras la encargada atiende: silencio total, sin
    // clasificar ni reactivar la IA. Se maneja aparte de la despedida para
    // NO marcar el ticket informativo como resuelto con un simple "ok".
    if (!$esFotoSinDescripcion && esMensajeVagoDuranteAtencionHumana($mensaje) && !esAgradecimientoODespedida($mensaje)) {
        if ($modoPrueba) {
            $sesion['ia_desactivada'] = false;
        }
        guardarSesion($numero, $sesion);
        ob_end_clean();
        registrarTurnoConversacion($numero, $mensaje, '[Sin respuesta automática: IA desactivada, mensaje vago durante atención humana]');
        echo json_encode(['respuesta' => '', 'sin_respuesta_automatica' => true]);
        exit;
    }

    // NUEVO: Si el mensaje es una despedida, marcar el ticket informativo como atendido.
    if (esAgradecimientoODespedida($mensaje)) {
        // Buscar el ticket informativo pendiente de este número
        $tickets = [];
        if (file_exists(TICKETS_JSON)) {
            $tickets = json_decode(file_get_contents(TICKETS_JSON), true) ?: [];
        }
        // Ordenar por id descendente para obtener el más reciente
        $tickets = array_reverse($tickets);
        foreach ($tickets as $t) {
            if ($t['remitente'] == $numero && ($t['categoria'] ?? '') === 'informacion_ciudadana' && ($t['estado'] ?? '') === 'pendiente') {
                cambiarEstadoTicket($t['id'], 'resuelto', null, 'Atendido automáticamente al finalizar la conversación informativa.');
                registrarLog("Ticket informativo #{$t['id']} marcado como resuelto por despedida del ciudadano $numero", 'info', 'ia.log');
                break;
            }
        }
        // No enviamos respuesta (la encargada ya está atendiendo, el bot no debe interferir).
        //
        // BUGFIX: antes esto se devolvía como {"respuesta": ""} a secas, y
        // el bot de Node (whatsapp-bot/index.js) no tiene forma de
        // distinguir un "" a propósito (silencio intencional, humano
        // atendiendo) de un "" por error real (por ejemplo un fallo
        // fatal de PHP). Como Node reintenta y, si sigue vacío, le manda
        // al ciudadano su mensaje de repuesto ("tenemos dificultades
        // técnicas"), el resultado era que un ciudadano que en realidad
        // ya estaba siendo atendido por una persona recibía un mensaje de
        // error que no aplicaba. Con "sin_respuesta_automatica": true,
        // Node puede reconocer este caso como silencio válido y no
        // reintentar ni mandar ningún mensaje de repuesto.
        //
        // MODO PRUEBA: si este mensaje viene del propio número vinculado
        // mientras se está probando el sistema (ver $modoPrueba más
        // arriba), reactivamos la IA para este chat justo después de
        // avisar este silencio intencional. Así, quien prueba recibe UNA
        // vez el aviso de "esto es lo que le pasaría a un ciudadano real"
        // y puede seguir enviando mensajes de prueba con normalidad, sin
        // tener que esperar a que alguien "termine de atender" el chat
        // manualmente. Con un ciudadano real, $modoPrueba nunca es true,
        // así que este bloque no cambia su comportamiento: la IA sigue
        // desactivada hasta que la encargada termine o hasta que el
        // clasificador detecte una nueva petición reconocida.
        if ($modoPrueba) {
            $sesion['ia_desactivada'] = false;
        }
        guardarSesion($numero, $sesion);
        ob_end_clean();
        registrarTurnoConversacion($numero, $mensaje, '[Sin respuesta automática: IA desactivada, atención humana en curso]');
        echo json_encode(['respuesta' => '', 'sin_respuesta_automatica' => true]);
        exit;
    }

    // FIX: NO reactivar la IA con cualquier mensaje que tenga "señal clara".
    // Mensajes vagos mientras la encargada atiende ("A vaya", "ok", "ahorita")
    // pueden coincidir con palabras débiles o caer en el fallback
    // 'administrativo' y generar oficios absurdos. Se exigen las CUATRO:
    //   1) señal clara del clasificador;
    //   2) categoría que NO sea fallback 'administrativo' ni 'informacion_*';
    //   3) confianza >= 50;
    //   4) esPeticionValida() (largo mínimo, no relleno, no negación).
    $categoriaDetectada = $clasificacionMientrasDesactivada['categoria'] ?? null;
    $confianzaDetectada = (int) ($clasificacionMientrasDesactivada['confianza'] ?? 0);
    $categoriaEsReporteReal = !empty($categoriaDetectada)
        && !in_array($categoriaDetectada, ['administrativo', 'informacion_ciudadana'], true)
        && strpos((string) $categoriaDetectada, 'informacion_') !== 0;
    $esPeticionRealParaReactivar = $clasificacionMientrasDesactivada !== null
        && !empty($clasificacionMientrasDesactivada['tiene_senal_clara'])
        && $categoriaEsReporteReal
        && $confianzaDetectada >= 50
        && esPeticionValida($mensaje, $clasificacionMientrasDesactivada);

    if ($esPeticionRealParaReactivar) {
        // Nueva petición real -> se reactiva la IA. Sin "exit": el mensaje
        // sigue el flujo normal más abajo (fase 'inicio').
        $sesion['ia_desactivada'] = false;
        $sesion['fase'] = 'inicio';
        registrarLog("IA reactivada para $numero: nueva petición real ('{$categoriaDetectada}', confianza {$confianzaDetectada}%) tras estar en atención humana.", 'info', 'ia.log');
    } else {
        // Mismo BUGFIX que arriba: se marca explícitamente como silencio
        // intencional (ver comentario detallado más arriba) para que Node
        // no lo confunda con una falla real y no le mande al ciudadano
        // ningún mensaje de "dificultades técnicas".
        //
        // MODO PRUEBA: mismo criterio explicado arriba, reactivamos la IA
        // para este chat después de avisar el silencio intencional, para
        // no dejar bloqueadas las pruebas con el propio número vinculado.
        if ($modoPrueba) {
            $sesion['ia_desactivada'] = false;
        }
        guardarSesion($numero, $sesion);
        ob_end_clean();
        registrarTurnoConversacion($numero, $esFotoSinDescripcion ? '[Fotografía adjunta]' : $mensaje, '[Sin respuesta automática: IA desactivada, atención humana en curso]');
        echo json_encode(['respuesta' => '', 'sin_respuesta_automatica' => true]);
        exit;
    }
}

// ------------------------------------------------------------
// 2.2 Foto recibida SIN descripción todavía: en vez de perderla (como
//     pasaba antes) o de tratarla como si fuera el texto de la petición,
//     avisamos que la recibimos y pedimos la descripción directamente en
//     el chat — respetando en qué fase de la conversación esté el
//     ciudadano, para no perder el progreso ya guardado.
// ------------------------------------------------------------
if ($esFotoSinDescripcion) {
    // NUEVO: antes de tratarlo como conversación nueva, verificar si hay
    // un ticket reciente de este mismo número al que se le pueda adjuntar
    // la foto. Caso típico: el ciudadano manda el texto, el bot registra
    // la solicitud, y unos segundos/minutos después manda la foto por
    // separado. Sin esto, la foto llegaba cuando la sesión ya se había
    // borrado, y el bot la trataba como solicitud nueva pidiendo de nuevo
    // la descripción — la foto nunca quedaba adjunta al expediente real.
    // BUGFIX: antes solo se adjuntaba la primera foto ($fotoRutaEntrante).
    // Ahora se adjuntan TODAS las fotos recibidas en este turno (el bot de
    // Node las agrupa en el campo "fotos") al mismo expediente.
    if (!empty($fotosEntrantes)) {
        $ticketRecienteId = buscarTicketRecienteDelNumero($numero, 3600); // 1 hora
        if ($ticketRecienteId !== null) {
            $adjuntadas = 0;
            foreach ($fotosEntrantes as $rutaFoto) {
                if (adjuntarFotoATicket($ticketRecienteId, $rutaFoto)) {
                    $adjuntadas++;
                }
            }
            if ($adjuntadas > 0) {
                $mensajeAdjuntadas = ($adjuntadas === 1)
                    ? "Gracias, ya adjuntamos tu fotografía al expediente #{$ticketRecienteId}. 🙏"
                    : "Gracias, ya adjuntamos tus {$adjuntadas} fotografías al expediente #{$ticketRecienteId}. 🙏";
                $respuesta = armarRespuestaFragmentada([
                    $mensajeAdjuntadas,
                    "Si necesitás reportar algo más, escribime cuando quieras.",
                ]);
                ob_end_clean();
                registrarTurnoConversacion($numero, '[Fotografía adjunta]', $respuesta);
                echo json_encode(['respuesta' => $respuesta], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }

    guardarSesion($numero, $sesion);

    if (($sesion['fase'] ?? 'inicio') === 'inicio') {
        $respuesta = armarRespuestaFragmentada([
            "Recibimos tu fotografía, gracias.",
            "Para poder registrar tu solicitud, contame en un mensaje de texto qué problema es y en dónde (ej. \"Bache en la 5ta calle\").",
        ]);
    } else {
        // La foto se adjunta automáticamente al ticket; NO se le avisa al
        // ciudadano para no confundirlo: se le repite únicamente la
        // pregunta que tenía pendiente.
        $respuesta = preguntaPendienteSegunFase($sesion);
    }

    ob_end_clean();
    registrarTurnoConversacion($numero, '[Fotografía adjunta]', $respuesta);
    echo json_encode(['respuesta' => $respuesta], JSON_UNESCAPED_UNICODE);
    exit;
}
// Se activa en la fase 'confirmar' cuando el ticket ya quedó creado y su
// conversación completa ya se copió dentro del ticket: en ese punto el
// archivo de conversación de este número ya cumplió su función y se
// borra al final de este request (ver bloque final del archivo), para
// que la próxima petición del mismo número no arrastre mensajes de una
// solicitud ya cerrada.
$limpiarConversacionAlFinal = false;

// El filtro de lenguaje inapropiado corre ANTES que cualquier otra cosa
// (incluso antes de "reiniciar"), y NO avanza ni modifica la sesión: solo
// pide un tono respetuoso y repite la pregunta que seguía pendiente.
if (contieneLenguajeInapropiado($mensaje)) {
    registrarLog("Lenguaje inapropiado detectado de $numero: $mensaje", 'warning', 'whatsapp_mensajes.log');
    $respuesta = armarRespuestaFragmentada([
        "Este es un canal oficial de atención ciudadana de la Municipalidad de San Pablo Jocopilas.",
        "Te pido que mantengamos un tono respetuoso para poder ayudarte.",
        preguntaPendienteSegunFase($sesion),
    ]);
    ob_end_clean();
    registrarTurnoConversacion($numero, $mensaje, $respuesta);
    echo json_encode(['respuesta' => $respuesta], JSON_UNESCAPED_UNICODE);
    exit;
}

if (esReinicio($mensaje)) {
    borrarSesion($numero);
    $respuesta = "Listo, empezamos de nuevo.\n\n¡Hola! Contame qué problema querés reportar (ej. \"Bache en la 5ta calle\", \"Fuga de agua en mi colonia\").";
    ob_end_clean();
    registrarTurnoConversacion($numero, $mensaje, $respuesta);
    echo json_encode(['respuesta' => $respuesta], JSON_UNESCAPED_UNICODE);
    exit;
}

// Ver comentario de esIntencionCancelar() más arriba: reconoce "cancelar"
// dentro de una frase natural (no solo el mensaje exacto), pero solo
// cuando ya hay una conversación en curso y el ciudadano NO está en medio
// de describir su petición en texto libre (fases 'inicio' y
// 'modificando_peticion' quedan afuera a propósito).
$faseActual = $sesion['fase'] ?? 'inicio';
if ($faseActual !== 'inicio' && $faseActual !== 'modificando_peticion' && esIntencionCancelar($mensaje)) {
    borrarSesion($numero);
    $respuesta = armarRespuestaFragmentada([
        "Solicitud cancelada.",
        "Si querés reportar algo, escribime cuando quieras.",
    ]);
    ob_end_clean();
    registrarTurnoConversacion($numero, $mensaje, $respuesta);
    echo json_encode(['respuesta' => $respuesta], JSON_UNESCAPED_UNICODE);
    exit;
}

// MEJORA: si el ciudadano parece confundido o está pidiendo ayuda ("no
// entiendo", "¿qué necesitas?", "ayuda", etc.), le respondemos con una
// explicación amigable de qué se le está pidiendo y por qué, SIN perder
// el progreso ya guardado en la sesión (no se borra ni se avanza de fase:
// simplemente se repite el pedido, pero mejor explicado).
if (esConfundidoOPideAyuda($mensaje)) {
    $respuesta = ayudaAmigableSegunFase($sesion);
    ob_end_clean();
    registrarTurnoConversacion($numero, $mensaje, $respuesta);
    echo json_encode(['respuesta' => $respuesta], JSON_UNESCAPED_UNICODE);
    exit;
}

// Si no hay ninguna conversación en curso (fase 'inicio') y el mensaje es
// un agradecimiento/cumplido/despedida ("Gracias por la atención", "Hasta
// luego"), respondemos cordialmente SIN iniciar una petición nueva. Antes,
// como esPeticionValida() solo miraba longitud y unas pocas palabras
// "vagas", este tipo de mensajes terminaba registrándose por error como si
// fuera un nuevo problema a reportar.
if (($sesion['fase'] ?? 'inicio') === 'inicio' && esAgradecimientoODespedida($mensaje)) {
    $respuesta = respuestaAgradecimientoAleatoria();
    ob_end_clean();
    registrarTurnoConversacion($numero, $mensaje, $respuesta);
    echo json_encode(['respuesta' => $respuesta], JSON_UNESCAPED_UNICODE);
    exit;
}

switch ($sesion['fase']) {

    case 'inicio':
        // NUEVO: si el mensaje es una petición que requiere atención
        // humana (ferias / eventos / cursos / becas / consultas
        // informativas / trámites administrativos sin categoría), hay
        // que limpiar el marcador de post-cierre ANTES de llegar al
        // chequeo de "aclaración", para que NO se confunda con un
        // comentario sobre el ticket anterior.
        //
        // Caso real que motivó esto: el ciudadano preguntó
        // "Quiero preguntar sobre el evento de caridad que se va ha
        // realizar en el salon." poco después de haber registrado otra
        // solicitud. Como el post-cierre seguía activo, el bot tomó el
        // mensaje como una aclaración ("Perfecto, gracias por la
        // aclaración. Ya quedó anotada junto con tu solicitud.") en vez
        // de derivarlo a la encargada, que es lo que corresponde cuando
        // alguien pregunta por un evento.
        if (tienePostCierreReciente($numero)
            && (esPeticionFueraDeAlcance($mensaje)
                || esTramiteAdministrativoSinCategoria($mensaje)
                || esConsultaInformativaDeServicio($mensaje)
                || esPeticionInfraestructuraFerroviaria($mensaje))) {
            limpiarPostCierre($numero);
        }

        // NUEVO: chequeo de "post-cierre" (ver bloque 1.2 más arriba).
        // Si este número registró una solicitud hace menos de 30 min y
        // el mensaje actual NO es un saludo y NO parece una petición
        // nueva con señal clara, se asume que es una aclaración sobre
        // el mismo caso ya cerrado (ej. "ayer le cayó un rayo y se
        // descompuso"): se responde brevemente confirmando que se anotó,
        // SIN reiniciar el flujo ni volver a pedir ubicación/detalle.
        if (tienePostCierreReciente($numero) && !esSaludo($mensaje)) {
            $clasificacionPost = clasificarPeticion($mensaje);
            // CORRECCIÓN: antes se exigía tiene_senal_clara=true del clasificador
            // además de esPeticionValida(). Si el clasificador no conocía la
            // categoría (ej. "accidente con heridos"), una petición nueva
            // quedaba atrapada como aclaración ("Perfecto, gracias por la
            // aclaración"). esPeticionValida() ya acepta señal clara O verbo de
            // intención ("hay un...", "tengo un...", "necesito", "reportar"...),
            // así que se deja que decida ella sola.
            $esNuevaPeticionClara = esPeticionValida($mensaje, $clasificacionPost);

            if ($esNuevaPeticionClara) {
                // Sí es una petición nueva real: se limpia el marcador
                // y sigue el flujo normal (cae al clasificador de abajo).
                limpiarPostCierre($numero);
            } else {
                // Aclaración / contexto sobre el caso ya cerrado.
                registrarLog("Post-cierre para $numero: aclaración recibida, no se reinicia el flujo: '$mensaje'.", 'info', 'ia.log');
                $respuesta = armarRespuestaFragmentada([
                    "Perfecto, gracias por la aclaración. 🙏",
                    "Ya quedó anotada junto con tu solicitud.",
                    "Si necesitás reportar algo más, escribime cuando quieras.",
                ]);
                break;
            }
        }

        if (esSaludo($mensaje) && mb_strlen($mensaje) < 15) {
            $respuesta = saludoBienvenidaAleatorio();
            break;
        }

        // CORRECCIÓN: peticiones sobre infraestructura ferroviaria (vías/
        // rieles del tren, línea férrea, ferrocarril) o con referencias
        // absurdas (unicornios, dragones, etc.) se tratan como un mensaje
        // NO REAL: son bromas o mensajes sin sentido, no una petición
        // ciudadana legítima. NO se crea ticket, NO se desactiva la IA y
        // NO se redirige a la encargada (antes sí se hacía, y el ciudadano
        // terminaba viendo "En un momento la encargada se unirá a este
        // chat...", lo cual no corresponde). Solo se responde un aviso
        // corto y se deja la sesión en 'inicio' para que el ciudadano
        // pueda, si quiere, mandar una petición real.
        if (esPeticionInfraestructuraFerroviaria($mensaje)) {
            registrarLog("Mensaje no real / broma detectado de $numero (infraestructura ferroviaria o referencia absurda): '$mensaje'. No se crea ticket ni se redirige a atención humana.", 'info', 'ia.log');
            $respuesta = 'No pudimos procesar tu mensaje porque no corresponde a un servicio o problema municipal. Si tenés una petición real (bache, agua, alumbrado, basura, etc.), contámela por favor. ¡Con gusto te ayudamos!';
            break;
        }

        // MEJORA: peticiones sobre temas que el sistema de tickets no
        // puede procesar (inscripciones a ferias, cursos, talleres,
        // becas, empleo, etc. — ver esPeticionFueraDeAlcance()) se
        // atienden directo con la encargada desde este primer mensaje,
        // en vez de pasar por el clasificador de trámites/reportes (que
        // nunca va a encontrar una categoría, porque genuinamente no hay
        // ninguna para esto en el catálogo) ni hacer esperar al
        // ciudadano los 2 intentos de "contame más detalle" que
        // normalmente preceden al traspaso a atención humana.
        // FIX: primero se clasifica, y el heurístico de "trámite administrativo"
        // solo se aplica si el clasificador NO encontró una categoría de
        // reporte con señal clara (evita que un reporte real, ej. poste de
        // alumbrado con foco quemado, termine como "administrativo").
        $clasificacion = clasificarPeticion($mensaje);
        $categoriaClara = !empty($clasificacion['tiene_senal_clara'])
            && !empty($clasificacion['categoria'])
            && !in_array($clasificacion['categoria'], ['administrativo', 'informacion_ciudadana'], true)
            && strpos((string) $clasificacion['categoria'], 'informacion_') !== 0;

        if (esPeticionFueraDeAlcance($mensaje)) {
            // 🔥 CREAR TICKET DE INFORMACIÓN antes de activar atención humana
            $dep = detectarDepartamentoPorPalabras($mensaje);
            $nombre = $sesion['nombre'] ?? null;
            crearTicketInformacion($numero, $mensaje, $dep['id'], $dep['nombre'], $nombre, $sesion['fotos'] ?? []);

            $sesion['ia_desactivada'] = true;
            $sesion['intentos_sin_clasificar'] = 0;
            guardarSesion($numero, $sesion);
            registrarLog("IA desactivada para $numero: petición fuera del alcance del sistema de tickets (inscripción/feria/curso/beca/empleo, etc.), mensaje: '$mensaje'.", 'info', 'ia.log');
            $respuesta = mensajeAtencionHumanaUnificado();
            break;
        }

        // MEJORA: trámites administrativos que el sistema no logra
        // identificar con una categoría propia (Uso del Salón Municipal,
        // Boleto de Ornato, constancias, certificaciones, etc. — ver
        // esTramiteAdministrativoSinCategoria()) también se pasan directo
        // con la encargada desde el primer mensaje, en vez de dejar que el
        // clasificador adivine una categoría genérica o hacer esperar al
        // ciudadano los 2 intentos de "contame más detalle".
        if (!$categoriaClara && esTramiteAdministrativoSinCategoria($mensaje)) {
            // 🔥 CREAR TICKET DE INFORMACIÓN antes de activar atención humana
            $dep = detectarDepartamentoPorPalabras($mensaje);
            $nombre = $sesion['nombre'] ?? null;
            crearTicketInformacion($numero, $mensaje, $dep['id'], $dep['nombre'], $nombre, $sesion['fotos'] ?? []);

            $sesion['ia_desactivada'] = true;
            $sesion['intentos_sin_clasificar'] = 0;
            guardarSesion($numero, $sesion);
            registrarLog("IA desactivada para $numero: trámite administrativo sin categoría automatizada (Salón Municipal/Boleto de Ornato/trámite administrativo genérico), mensaje: '$mensaje'.", 'info', 'ia.log');
            $respuesta = mensajeAtencionHumanaUnificado();
            break;
        }

        // MEJORA: consultas INFORMATIVAS sobre servicios municipales
        // (horarios del agua, cuándo pasa el tren de aseo, cómo hacer un
        // pago del agua, etc. — ver esConsultaInformativaDeServicio()).
        // Aunque el mensaje mencione el servicio ("no hay agua"), si el
        // ciudadano está PREGUNTANDO algo (y no reportando una falta), no
        // debe tratarse como reporte: se deriva directo con la encargada
        // desde el primer mensaje, igual que ferias/cursos/becas.
        if (esConsultaInformativaDeServicio($mensaje)) {
            // 🔥 CREAR TICKET DE INFORMACIÓN antes de activar atención humana
            $dep = detectarDepartamentoPorPalabras($mensaje);
            $nombre = $sesion['nombre'] ?? null;
            crearTicketInformacion($numero, $mensaje, $dep['id'], $dep['nombre'], $nombre, $sesion['fotos'] ?? []);

            $sesion['ia_desactivada'] = true;
            $sesion['intentos_sin_clasificar'] = 0;
            guardarSesion($numero, $sesion);
            registrarLog("IA desactivada para $numero: consulta informativa sobre un servicio municipal (horarios / información), mensaje: '$mensaje'.", 'info', 'ia.log');
            $respuesta = mensajeAtencionHumanaUnificado();
            break;
        }

        if (!esPeticionValida($mensaje, $clasificacion)) {
            $respuesta = mensajePideMasDetalleAleatorio();
            break;
        }

        // NUEVO: si el clasificador devolvió una categoría de INFORMACIÓN
        // (informacion_cursos, informacion_actividades, informacion_general),
        // es una consulta informativa: se deriva a la encargada y se registra
        // el ticket de información. Red de seguridad de esPeticionFueraDeAlcance().
        if (!empty($clasificacion['es_informacion']) && !empty($clasificacion['tiene_senal_clara'])) {
            $dep = detectarDepartamentoPorPalabras($mensaje);
            $nombre = $sesion['nombre'] ?? null;
            crearTicketInformacion($numero, $mensaje, $dep['id'], $dep['nombre'], $nombre, $sesion['fotos'] ?? []);

            $sesion['ia_desactivada'] = true;
            $sesion['intentos_sin_clasificar'] = 0;
            guardarSesion($numero, $sesion);
            registrarLog("IA desactivada para $numero: solicitud de información ('{$clasificacion['categoria']}') → atención humana, mensaje: '$mensaje'.", 'info', 'ia.log');
            $respuesta = mensajeInformacionAleatorio();
            break;
        }

        // BUGFIX: mensajes como "Hola tengo un problema" pasaban la
        // validación (tienen un verbo de intención: "tengo un problema"),
        // pero el clasificador NO encontró ninguna palabra clave real de
        // ninguna categoría (tiene_senal_clara=false). Antes, en ese caso
        // igual se registraba la solicitud adivinando una categoría por
        // defecto ("Trámites Administrativos"), lo cual es incorrecto:
        // el ciudadano nunca dijo cuál es su problema. En vez de adivinar,
        // le pedimos que sea más específico ANTES de crear la solicitud.
        //
        // MEJORA (traspaso a atención humana): si TRAS pedirle que
        // aclare, el ciudadano vuelve a escribir algo que la IA sigue sin
        // poder identificar, ya no tiene sentido seguir preguntando: lo
        // más probable es que sea un trámite que el catálogo de
        // "IA 2/datos_ia.json" simplemente no tiene mapeado (ej. "nichos
        // del cementerio", "COCODES", etc., no una simple falta de
        // detalle). En ese caso se traspasa la conversación a la
        // encargada y se desactiva la IA en este chat (ver bloque "2.3
        // Atención humana" más arriba). La primera vez que pasa, en
        // cambio, se le sigue dando al ciudadano la oportunidad de
        // aclarar por su cuenta, como ya hacía el bot.
        if (empty($clasificacion['tiene_senal_clara'])) {
            $INTENTOS_ANTES_DE_ATENCION_HUMANA = 2;
            $sesion['intentos_sin_clasificar'] = ($sesion['intentos_sin_clasificar'] ?? 0) + 1;

            if ($sesion['intentos_sin_clasificar'] >= $INTENTOS_ANTES_DE_ATENCION_HUMANA) {
                // 🔥 CREAR TICKET DE INFORMACIÓN antes de activar atención humana
                $dep = detectarDepartamentoPorPalabras($mensaje);
                $nombre = $sesion['nombre'] ?? null;
                crearTicketInformacion($numero, $mensaje, $dep['id'], $dep['nombre'], $nombre, $sesion['fotos'] ?? []);

                $sesion['ia_desactivada'] = true;
                $sesion['intentos_sin_clasificar'] = 0;
                guardarSesion($numero, $sesion);
                registrarLog("IA desactivada para $numero: no se pudo identificar ningún trámite reconocido tras {$INTENTOS_ANTES_DE_ATENCION_HUMANA} intentos (último mensaje: '$mensaje').", 'info', 'ia.log');
                $respuesta = mensajeAtencionHumanaUnificado();
                break;
            }

            guardarSesion($numero, $sesion);
            $respuesta = armarRespuestaFragmentada([
                "Contame un poco más. 🙏",
                "¿Cuál es el problema exactamente y dónde está pasando? 📍",
                "Por ejemplo: \"Hay un bache en la 5ta calle 🕳️\", \"Se fue la luz en mi colonia 💡\" o \"Necesito información de algún evento 📅\".",
            ]);
            break;
        }

        // Si llegamos hasta acá es porque SÍ se identificó un trámite
        // reconocido: reiniciamos el contador de intentos fallidos para
        // que no quede arrastrado a la siguiente petición de este número.
        $sesion['intentos_sin_clasificar'] = 0;

        $sesion['texto_peticion'] = $mensaje;
        $sesion['clasificacion'] = $clasificacion;

        // ------------------------------------------------------------
        // INTERÉS PARTICULAR vs. INTERÉS SOCIAL (ver detectarTipoInteres()).
        //
        //   social     → NO se piden nombre ni teléfono: se agradece el
        //                reporte y solo se pregunta la ubicación (colonia o
        //                punto de referencia).
        //   particular → se piden nombre y teléfono (y luego la dirección).
        // ------------------------------------------------------------
        $tipoInteres = detectarTipoInteres($mensaje, $clasificacion);
        $sesion['tipo_interes'] = $tipoInteres;

        // AGREGADO: los textos de este bloque salen de mensajes_bot.json
        // (editable desde el panel "Inteligencia Artificial" > "Mensajes
        // del Bot"), con los mismos textos de siempre como respaldo.
        $msjBot = leerMensajesBot();

        $catNombre = $clasificacion['categoria_nombre'] ?? 'tu solicitud';
        $fragmentos = [];

        if ($tipoInteres === 'social') {
            $sesion['nombre'] = '';
            $sesion['dpi'] = '';
            $sesion['telefono'] = '';

            // CASO ESPECIAL: conductor peligroso (exceso de velocidad,
            // ebriedad, manejo temerario). El vehículo se está MOVIENDO y el
            // riesgo es para toda la ciudadanía, así que no hay una ubicación
            // fija que pedir: se registra por defecto "San Pablo Jocopilas"
            // y se salta directo al registro del ticket.
            if (!empty($clasificacion['conductor_peligroso'])) {
                $sesion['direccion'] = 'San Pablo Jocopilas';

                $fragmentos[] = aplicarMarcadores($msjBot['clasificacion_registrada'], [
                    'saludo' => saludoPorHora(),
                    'categoria' => $catNombre,
                    'urgencia' => etiquetaUrgencia($clasificacion['urgencia'] ?? 'baja'),
                ]);
                $respuesta = avanzarAlSiguientePaso($sesion, $numero, $fragmentos, true);
                break;
            }

            // Resto de casos de interés social: solo interesa la ubicación; si
            // ya venía en el mensaje (ej. "en la colonia Flor de Café"), no se
            // vuelve a preguntar.
            $direccionAuto = extraerDireccionDeTexto($mensaje);
            if ($direccionAuto !== null && esDireccionValida($direccionAuto)) {
                $sesion['direccion'] = extraerDireccion($direccionAuto);
            }

            $fragmentos[] = aplicarMarcadores($msjBot['clasificacion_registrada'], [
                'saludo' => saludoPorHora(),
                'categoria' => $catNombre,
                'urgencia' => etiquetaUrgencia($clasificacion['urgencia'] ?? 'baja'),
            ]);
            $respuesta = avanzarAlSiguientePaso($sesion, $numero, $fragmentos, true);
            break;
        }

        // ---- Interés PARTICULAR ----
        // Se piden NOMBRE, REPORTE (ya lo tenemos) y DIRECCIÓN. El teléfono
        // solo se pregunta si PARTICULAR_PIDE_TELEFONO está activo; si no,
        // se guarda en silencio el número del WhatsApp desde el que escribe
        // (el ciudadano puede contestar "este mismo" si sí se pregunta).
        $sesion['telefono'] = PARTICULAR_PIDE_TELEFONO ? '' : $numero;

        // Intentar reconocer nombre y dirección si el ciudadano ya los
        // escribió dentro de este mismo mensaje (ej. "Mi nombre es Monica
        // Solis... en la calle que va hacia la cumbre de Alaska..."), para
        // no volver a preguntar por algo que ya contestó.
        $nombreAuto = extraerNombreDeTexto($mensaje);
        if ($nombreAuto !== null && esNombreValido($nombreAuto)) {
            $sesion['nombre'] = extraerNombre($nombreAuto);
        }
        $direccionAuto = extraerDireccionDeTexto($mensaje);
        if ($direccionAuto !== null && esDireccionValida($direccionAuto)) {
            $sesion['direccion'] = extraerDireccion($direccionAuto);
        }

        // Ejemplo real (fuga de agua dentro de la casa, urgencia alta):
        //   "Entendido, lo registré como: Agua y Saneamiento (🔴 urgencia alta)."
        //   "Ya identifiqué estos datos en tu mensaje: Dirección: sector Ixcoj."  (si aplica)
        //   "Para generar tu solicitud formal necesito algunos datos."
        //   "👤 ¿Cuál es tu nombre completo?"   → luego teléfono → dirección
        $fragmentos[] = aplicarMarcadores($msjBot['clasificacion_registrada'], [
            'saludo' => saludoPorHora(),
            'categoria' => $catNombre,
            'urgencia' => etiquetaUrgencia($clasificacion['urgencia'] ?? 'baja'),
        ]);

        $detectados = [];
        if (!empty($sesion['nombre'])) $detectados[] = "Nombre: {$sesion['nombre']}";
        if (!empty($sesion['direccion'])) $detectados[] = "Dirección: {$sesion['direccion']}";
        if (!empty($detectados)) {
            $fragmentos[] = aplicarMarcadores($msjBot['datos_detectados'], [
                'detectados' => implode(', ', $detectados),
            ]);
        }

        if ($sesion['nombre'] === 'Anónimo') {
            $sesion['dpi'] = '';
            $sesion['telefono'] = $numero;
            if (PARTICULAR_EXIGE_NOMBRE) {
                // Ya en el primer mensaje dijo que es anónimo, pero un
                // reporte particular necesita el nombre: se explica y se
                // vuelve a pedir (cuenta como la primera negativa).
                $sesion['nombre'] = '';
                $sesion['intentos_anonimo'] = 1;
                $fragmentos[] = textoExplicarNombreObligatorio();
            }
            // Si PARTICULAR_EXIGE_NOMBRE es false: se conserva como
            // anónimo (no se pide teléfono ni DPI), igual que antes.
        }
        $respuesta = avanzarAlSiguientePaso($sesion, $numero, $fragmentos, true);
        break;

    case 'esperando_nombre':
        // Reporte particular: el nombre es obligatorio (ver
        // PARTICULAR_EXIGE_NOMBRE arriba). La primera vez que dice que no
        // quiere darlo (o escribe "Anónimo") se le explica para qué se
        // necesita y se vuelve a pedir; la segunda vez se cancela la
        // solicitud con amabilidad, sin registrar nada.
        if (PARTICULAR_EXIGE_NOMBRE
            && ($sesion['tipo_interes'] ?? 'particular') !== 'social'
            && esIntencionAnonimo($mensaje)) {
            $sesion['intentos_anonimo'] = ($sesion['intentos_anonimo'] ?? 0) + 1;
            if ($sesion['intentos_anonimo'] >= 2) {
                borrarSesion($numero);
                $respuesta = armarRespuestaFragmentada([
                    "Entiendo, gracias por tu tiempo. 🙏",
                    "Sin tu nombre no podemos registrar este tipo de reporte, así que lo dejamos cancelado por ahora.",
                    "Si más adelante querés retomarlo, escribime cuando quieras y con gusto lo registramos.",
                ]);
                break;
            }
            guardarSesion($numero, $sesion);
            $respuesta = armarRespuestaFragmentada([
                textoExplicarNombreObligatorio(),
                leerMensajesBot()['pedir_nombre'],
            ]);
            break;
        }

        // AGREGADO: detección de anonimato con frases naturales (no solo
        // la palabra "Anónimo" suelta). Si el ciudadano dice algo como
        // "no quiero dar mi nombre", se registra directamente como
        // Anónimo y se omite el DPI, igual que cuando escribe "Anónimo".
        if (esIntencionAnonimo($mensaje)) {
            $sesion['nombre'] = 'Anónimo';
            $sesion['dpi'] = '';
            $sesion['telefono'] = $numero; // referencia interna; no se le pide teléfono
            if (!empty($sesion['direccion'])) {
                $respuesta = registrarSolicitud($sesion, $numero, ["Entendido, tu solicitud se registrará como anónima."]);
            } else {
                $sesion['fase'] = 'esperando_direccion';
                guardarSesion($numero, $sesion);
                $respuesta = armarRespuestaFragmentada([
                    "Entendido, tu solicitud se registrará como anónima.",
                    "📍 Por último, ¿cuál es la dirección o punto de referencia del problema?",
                ]);
            }
            break;
        }

        // BUGFIX: si el ciudadano contestaba con nombre Y dirección juntos
        // en el mismo mensaje (ej. "Soy Santiago Moto y vivo en Colonia
        // Flor de Café"), esNombreValido() lo rechazaba COMPLETO por tener
        // más de 6 palabras, y el bot volvía a pedir "el nombre completo"
        // aunque el ciudadano ya lo hubiera dado. Ahora primero se intenta
        // sacar el nombre (y, de paso, la dirección) del mensaje con la
        // misma lógica que ya se usa en la fase 'inicio'; si no aplica
        // (mensaje es solo el nombre, sin más), se valida como antes.
        //
        // AGREGADO: lo mismo con el TELÉFONO ("Juan Pérez 4276 5953"): si el
        // ciudadano manda nombre y número juntos, se separan y ya no se
        // vuelve a preguntar el teléfono.
        $patronTelefono = '/(?<!\d)(?:\+?\s*502[\s\-.]*)?\d{4}[\s\-.]?\d{4}(?!\d)/';
        $textoNombre = $mensaje;
        $telefonoJunto = null;
        if (preg_match($patronTelefono, $mensaje)) {
            $sinTelefono = trim(preg_replace($patronTelefono, '', $mensaje), " \t,;:-");
            if ($sinTelefono !== '' && esNombreValido($sinTelefono)) {
                $textoNombre = $sinTelefono;
                $telefonoJunto = extraerTelefono($mensaje);
            }
        }

        $nombreDetectado = extraerNombreDeTexto($textoNombre);
        if ($nombreDetectado !== null && esNombreValido($nombreDetectado)) {
            $nombreFinal = extraerNombre($nombreDetectado);
        } elseif (esNombreValido($textoNombre)) {
            $nombreFinal = extraerNombre($textoNombre);
        } else {
            $respuesta = "Necesito tu nombre completo (nombre y apellido), por favor.";
            break;
        }
        $sesion['nombre'] = $nombreFinal;
        if ($telefonoJunto !== null && $nombreFinal !== 'Anónimo') {
            $sesion['telefono'] = $telefonoJunto;
        }

        // Si en el mismo mensaje también dio la dirección, se aprovecha
        // para no volver a preguntarla más adelante.
        $direccionDetectada = extraerDireccionDeTexto($mensaje);
        if ($direccionDetectada !== null && esDireccionValida($direccionDetectada)) {
            $sesion['direccion'] = extraerDireccion($direccionDetectada);
        }

        // AGREGADO: si el ciudadano dio su nombre como "Anónimo", no tiene
        // sentido pedirle el DPI a continuación — es justo el dato que
        // serviría para identificarlo, y ya dejó claro que no quiere darlo.
        // Se omite directamente (dpi = '') y se pasa a lo que falte:
        // dirección (si aún no se tiene) o, si ya se tiene todo, directo al
        // resumen de confirmación.
        if ($sesion['nombre'] === 'Anónimo') {
            $sesion['dpi'] = '';
            $sesion['telefono'] = $numero; // referencia interna; no se le pide teléfono
            if (!empty($sesion['direccion'])) {
                $respuesta = registrarSolicitud($sesion, $numero);
            } else {
                $sesion['fase'] = 'esperando_direccion';
                guardarSesion($numero, $sesion);
                $respuesta = armarRespuestaFragmentada([
                    "Gracias.",
                    "📍 Por último, ¿cuál es la dirección o punto de referencia del problema?",
                ]);
            }
            break;
        }

        // Interés particular: tras el nombre sigue el TELÉFONO (si aún no se
        // tiene) y luego la dirección. Ver avanzarAlSiguientePaso().
        $respuesta = avanzarAlSiguientePaso($sesion, $numero, ["Gracias, {$sesion['nombre']}."]);
        break;

    case 'esperando_dpi':
        // Fase heredada: ya no se pide el DPI en el flujo inicial (ver
        // fase 'inicio' y 'esperando_nombre' más arriba), pero se deja
        // este caso por si quedó una sesión vieja guardada en este estado
        // antes de la actualización, para no dejar al ciudadano sin
        // respuesta ni obligarlo a reiniciar todo el trámite. Simplemente
        // se avanza al siguiente paso sin volver a preguntar el DPI.
        if (!empty($sesion['direccion'])) {
            $respuesta = registrarSolicitud($sesion, $numero);
        } else {
            $sesion['fase'] = 'esperando_direccion';
            guardarSesion($numero, $sesion);
            $respuesta = "📍 Por último, ¿cuál es la dirección o punto de referencia del problema?";
        }
        break;

    case 'esperando_telefono':
        // Interés PARTICULAR: se pide un número de contacto. El ciudadano
        // puede escribirlo ("4276 5953", "+502 4276-5953"), contestar
        // "este mismo" (usa el de este WhatsApp) o decir que no quiere
        // darlo, en cuyo caso también se usa el de este WhatsApp (que el
        // sistema ya tiene de todas formas).
        $telefonoEscrito = extraerTelefono($mensaje);
        if ($telefonoEscrito !== null) {
            $sesion['telefono'] = $telefonoEscrito;
            $nota = "Gracias, anotamos el número {$telefonoEscrito}.";
        } elseif (esMismoNumeroWhatsapp($mensaje) || esOmitir($mensaje)) {
            $sesion['telefono'] = $numero;
            $nota = "Perfecto, usaremos este mismo número de WhatsApp.";
        } else {
            $respuesta = armarRespuestaFragmentada([
                "No pude leer un número de teléfono válido.",
                "Escribilo con sus 8 dígitos (ej. 4276 5953) o escribí *este mismo* si es el de este WhatsApp.",
            ]);
            break;
        }
        $respuesta = avanzarAlSiguientePaso($sesion, $numero, [$nota]);
        break;

    case 'esperando_direccion':
        if (!esDireccionValida($mensaje)) {
            $respuesta = "Contame la dirección o un punto de referencia (mínimo unas palabras), o escribí \"sin direccion\" para omitirla.";
            break;
        }
        $sesion['direccion'] = esOmitir($mensaje) ? '(No proporcionada)' : extraerDireccion($mensaje);
        // CAMBIO: la dirección es el último dato que se pide. Ya no se
        // muestra un resumen para confirmar: se registra de una vez.
        $respuesta = registrarSolicitud($sesion, $numero);
        break;

    // ------------------------------------------------------------
    // Fases HEREDADAS del antiguo paso de confirmación.
    //
    // CAMBIO: 'confirmar' (resumen + SÍ / NO / MODIFICAR) y todo el
    // sub-flujo de corrección ('eligiendo_campo_modificar' y
    // 'modificando_*') ya NO se generan: cuando se completan los datos, la
    // solicitud se registra de inmediato (ver registrarSolicitud()).
    //
    // Este manejador queda SOLO por compatibilidad: si al actualizar el
    // sistema había un ciudadano con la sesión guardada en alguna de estas
    // fases (o sea, ya le habían mostrado el resumen viejo), su siguiente
    // mensaje no debe dejarlo sin respuesta:
    //   - "no" / "cancelar"  -> se cancela la solicitud.
    //   - cualquier otra cosa -> se registra con los datos que ya tenía
    //                            completos.
    // ------------------------------------------------------------
    case 'confirmar':
    case 'eligiendo_campo_modificar':
    case 'modificando_nombre':
    case 'modificando_dpi':
    case 'modificando_telefono':
    case 'modificando_direccion':
    case 'modificando_peticion':
        $t = mb_strtolower(trim($mensaje));
        if (in_array($t, ['no', 'cancelar', 'cancelo'])) {
            borrarSesion($numero);
            $respuesta = armarRespuestaFragmentada([
                "Solicitud cancelada.",
                "Si querés reportar algo, escribime cuando quieras.",
            ]);
        } else {
            $respuesta = registrarSolicitud($sesion, $numero);
        }
        break;

    default:
        borrarSesion($numero);
        $respuesta = "¡Hola! Contame qué problema querés reportar y con gusto lo registro.";
        break;
}

// ------------------------------------------------------------
// 6. Responder al bot de Node con el JSON esperado.
//
// BUGFIX: el archivo terminaba abruptamente en medio del switch (faltaba
// esta llave de cierre y todo este bloque final). Sin el cierre, PHP
// tiraba un "Parse error: Unclosed '{'" en CADA mensaje, así que el
// endpoint devolvía 500 y el bot de WhatsApp se quedaba sin poder
// contestar absolutamente nada, sin importar qué escribiera el
// ciudadano.
// ------------------------------------------------------------
ob_end_clean();
registrarTurnoConversacion($numero, $mensaje, $respuesta);
if ($limpiarConversacionAlFinal) {
    borrarConversacion($numero);
}
echo json_encode(['respuesta' => $respuesta], JSON_UNESCAPED_UNICODE);