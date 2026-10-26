<?php
/**
 * reportes/oficio_html.php
 *
 * Genera el HTML del "oficio" (carta formal imprimible que la Oficina de
 * Atención y Comunicación Ciudadana envía al director del departamento
 * responsable, entregándole una petición ciudadana recibida por el
 * sistema/bot).
 *
 * El formato replica el de los oficios físicos reales de la Municipalidad
 * de San Pablo Jocopilas (membrete con escudo municipal y escudo de
 * Guatemala, lugar y fecha, número de oficio correlativo "-ACC",
 * destinatario, saludo fijo, dos párrafos de cuerpo, cierre y firma, y
 * pie con el lema institucional), en vez del reporte extenso de
 * secciones I-VIII que se usaba antes.
 *
 * Requiere que $sol tenga la forma que arma reportes/generar.php a partir
 * del ticket + el catálogo de "IA 2/datos_ia.json".
 */

// Funciones para consumir/avanzar el correlativo propio de cada oficina
// (ej. "SP0009"), ver tickets/correlativo_oficinas.php y la sección
// "Correlativo por Oficina" en vistas/config_sistema.php.
require_once dirname(__DIR__) . '/tickets/correlativo_oficinas.php';
require_once __DIR__ . '/oficio_reglas.php';

if (!function_exists('fechaFormateadaOficio')) {
    // Formato de los oficios reales: "07 de septiembre del 2026"
    function fechaFormateadaOficio($timestamp = null) {
        $meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
        $timestamp = $timestamp ?? time();
        return date('d', $timestamp) . ' de ' . $meses[date('n', $timestamp) - 1] . ' del ' . date('Y', $timestamp);
    }
}

if (!function_exists('fechaLimiteOficio')) {
    function fechaLimiteOficio($timestampBase, $dias) {
        $fecha = new DateTime('@' . $timestampBase);
        $fecha->modify("+{$dias} days");
        $meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
        return $fecha->format('j') . ' de ' . $meses[$fecha->format('n') - 1] . ' de ' . $fecha->format('Y');
    }
}

/**
 * Asigna (o recupera, si ya se había generado antes) el número correlativo
 * de "Oficio No. X-ACC" de un ticket, guardándolo en
 * reportes/oficios_emitidos.json.
 *
 * Se persiste por ticket (no se recalcula cada vez que se abre/reimprime
 * el mismo oficio) para que reimprimir el mismo expediente no vaya
 * "gastando" números correlativos nuevos, igual que pasaría con oficios
 * físicos: cada expediente tiene siempre el mismo número de oficio.
 *
 * @param int|string $ticketId
 * @return int
 */
if (!function_exists('obtenerNumeroOficio')) {
function obtenerNumeroOficio($ticketId) {
    $archivo = __DIR__ . '/oficios_emitidos.json';
    $clave = (string) $ticketId;

    if (!file_exists($archivo)) {
        file_put_contents($archivo, json_encode(['ultimo_numero' => 0, 'asignados' => new stdClass()], JSON_PRETTY_PRINT));
    }

    $fp = fopen($archivo, 'c+');
    if (!$fp) {
        return (int) $ticketId;
    }

    $numero = null;
    if (flock($fp, LOCK_EX)) {
        $contenido = '';
        fseek($fp, 0);
        while (!feof($fp)) $contenido .= fread($fp, 8192);
        $datos = json_decode($contenido, true);
        if (!is_array($datos)) $datos = [];
        if (!isset($datos['ultimo_numero'])) $datos['ultimo_numero'] = 0;
        if (!isset($datos['asignados']) || !is_array($datos['asignados'])) $datos['asignados'] = [];

        if (!isset($datos['asignados'][$clave])) {
            $datos['ultimo_numero'] = (int) $datos['ultimo_numero'] + 1;
            $datos['asignados'][$clave] = $datos['ultimo_numero'];
            ftruncate($fp, 0);
            fseek($fp, 0);
            fwrite($fp, json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            fflush($fp);
        }
        $numero = (int) $datos['asignados'][$clave];
        flock($fp, LOCK_UN);
    }
    fclose($fp);

    return $numero ?? (int) $ticketId;
}
}

/**
 * Asigna (o recupera, si ya se había generado antes) el CÓDIGO DE
 * CORRELATIVO PROPIO DE LA OFICINA (ej. "SP0009", "JAMYT0006") a un
 * ticket, según a qué oficina esté vinculada su categoría (ver la
 * columna "Oficina (correlativo)" en vistas/config_sistema.php, sección
 * "Departamentos y Encargados (para los Oficios)").
 *
 * Igual que obtenerNumeroOficio(), se persiste por ticket en
 * reportes/codigos_oficina_emitidos.json para que reimprimir el mismo
 * expediente NUNCA vuelva a consumir/avanzar el correlativo de la
 * oficina — cada expediente conserva siempre el mismo código, y el
 * contador de la oficina solo avanza la primera vez que se genera su
 * oficio (esto es lo que permite que, después de generar varios
 * oficios, el sistema siga en el siguiente correlativo en vez de
 * repetir números).
 *
 * @param int|string $ticketId
 * @param int|null $oficinaId ID de la oficina (tickets/correlativo_oficinas.php).
 *                            Si es null (la categoría no tiene oficina asignada),
 *                            no se genera ningún código y se devuelve null.
 * @return string|null Código de oficina (ej. "SP0009"), o null si no aplica.
 */
if (!function_exists('obtenerCodigoOficinaOficio')) {
function obtenerCodigoOficinaOficio($ticketId, $oficinaId) {
    if (empty($oficinaId)) {
        return null;
    }

    $archivo = __DIR__ . '/codigos_oficina_emitidos.json';
    $clave = (string) $ticketId;

    if (!file_exists($archivo)) {
        file_put_contents($archivo, json_encode(['asignados' => new stdClass()], JSON_PRETTY_PRINT));
    }

    $fp = fopen($archivo, 'c+');
    if (!$fp) {
        return null;
    }

    $codigo = null;
    if (flock($fp, LOCK_EX)) {
        $contenido = '';
        fseek($fp, 0);
        while (!feof($fp)) $contenido .= fread($fp, 8192);
        $datos = json_decode($contenido, true);
        if (!is_array($datos)) $datos = [];
        if (!isset($datos['asignados']) || !is_array($datos['asignados'])) $datos['asignados'] = [];

        if (isset($datos['asignados'][$clave])) {
            // Este expediente ya tenía un código de oficina asignado
            // (se está reimprimiendo el mismo oficio): se reutiliza el
            // mismo, sin volver a avanzar el correlativo de la oficina.
            $codigo = $datos['asignados'][$clave];
        } else {
            // Primera vez que se genera el oficio de este expediente:
            // se consume (y avanza) el siguiente correlativo de la
            // oficina indicada.
            $nuevoCodigo = obtenerYAvanzarCodigoOficina($oficinaId);
            if ($nuevoCodigo !== null) {
                $datos['asignados'][$clave] = $nuevoCodigo;
                ftruncate($fp, 0);
                fseek($fp, 0);
                fwrite($fp, json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                fflush($fp);
                $codigo = $nuevoCodigo;
            }
        }
        flock($fp, LOCK_UN);
    }
    fclose($fp);

    return $codigo;
}
}

/* ============================================================================
 * REDACCIÓN AUTOMÁTICA DEL CUERPO DEL OFICIO
 *
 * El cuerpo de la carta se redacta en el mismo estilo de los oficios reales
 * de la Oficina de Comunicación y Atención Ciudadana:
 *
 *   Párrafo 1: fórmula de cortesía + número de solicitud + descripción
 *              concreta de la problemática (qué es y dónde ocurre).
 *   Párrafo 2: fotografías adjuntas / verificación en sitio, persona de
 *              referencia y solicitud de acción a la brevedad posible.
 *   Cierre:    "Sin otro particular, me suscribo de usted de la manera más
 *              atenta."
 *
 * La descripción se arma con lo que el ciudadano realmente escribió (se
 * limpian saludos, presentaciones, DPI/teléfonos, ruegos y muletillas, y se
 * pasa a tercera persona), y NO con una frase genérica de la categoría. La
 * frase genérica (resumenProblemaOficio) queda solo como respaldo cuando el
 * mensaje no trae información útil (p. ej. solo un emoji).
 *
 * Las funciones de texto de abajo NO dependen de la extensión mbstring
 * (el PHP portable del proyecto puede no tenerla activada).
 * ========================================================================== */

if (!function_exists('oficioMin')) {
    function oficioMin($s) {
        $s = (string) $s;
        if (function_exists('mb_strtolower')) return mb_strtolower($s, 'UTF-8');
        return strtr(strtolower($s), ['Á'=>'á','É'=>'é','Í'=>'í','Ó'=>'ó','Ú'=>'ú','Ñ'=>'ñ','Ü'=>'ü']);
    }
}
if (!function_exists('oficioMay')) {
    function oficioMay($s) {
        $s = (string) $s;
        if (function_exists('mb_strtoupper')) return mb_strtoupper($s, 'UTF-8');
        return strtr(strtoupper($s), ['á'=>'Á','é'=>'É','í'=>'Í','ó'=>'Ó','ú'=>'Ú','ñ'=>'Ñ','ü'=>'Ü']);
    }
}
if (!function_exists('oficioLargo')) {
    function oficioLargo($s) {
        return (int) preg_match_all('/./us', (string) $s);
    }
}
if (!function_exists('oficioPalabras')) {
    function oficioPalabras($s) {
        return (int) preg_match_all('/\p{L}+/u', (string) $s);
    }
}
if (!function_exists('oficioPrimeraMin')) {
    function oficioPrimeraMin($s) {
        if (preg_match('/^(.)(.*)$/us', (string) $s, $m)) return oficioMin($m[1]) . $m[2];
        return (string) $s;
    }
}
if (!function_exists('oficioPrimeraMay')) {
    function oficioPrimeraMay($s) {
        if (preg_match('/^(.)(.*)$/us', (string) $s, $m)) return oficioMay($m[1]) . $m[2];
        return (string) $s;
    }
}
if (!function_exists('oficioSinAcentos')) {
    function oficioSinAcentos($s) {
        return strtr(oficioMin($s), ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
    }
}

/**
 * Tipos de lugar reconocidos (sin acentos) => artículo con el que se citan.
 * Los datos viven en oficio_reglas.php (OFICIO_TIPOS_LUGAR).
 */
if (!function_exists('oficioTiposLugar')) {
    function oficioTiposLugar() {
        return OFICIO_TIPOS_LUGAR;
    }
}

/**
 * Pasa a "Título" un nombre de lugar: mayúscula inicial en los nombres
 * propios ("colonia Flor del Café", "camino La Ceiba", "aldea Madre Mía"),
 * y minúscula en tipos de lugar, preposiciones y adjetivos genéricos.
 */
if (!function_exists('oficioTitulo')) {
    function oficioTitulo($s) {
        $tipos = oficioTiposLugar();
        $chicas = ['de', 'del', 'y', 'e', 'en', 'a', 'al', 'con', 'sin', 'por', 'para', 'hacia', 'o'];
        $articulos = ['el', 'la', 'los', 'las'];
        $genericas = ['principal', 'real', 'central', 'urbano', 'nueva', 'nuevo', 'vieja', 'viejo', 'norte', 'sur',
                      'oriente', 'poniente', 'primera', 'segunda', 'tercera', 'cuarta', 'quinta', 'sexta',
                      'septima', 'octava', 'novena', 'decima'];

        $partes = preg_split('/(\s+)/u', oficioMin(trim((string) $s)), -1, PREG_SPLIT_DELIM_CAPTURE);
        $tras = true; // inicio del nombre o justo después de un tipo de lugar: el artículo es parte del nombre
        foreach ($partes as $i => $p) {
            if ($p === '' || preg_match('/^\s+$/u', $p)) continue;
            $limpio = preg_replace('/[^\p{L}\p{N}]/u', '', $p);
            if ($limpio === '') continue;
            if (preg_match('/\d/u', $p)) { $tras = false; continue; }
            $k = oficioSinAcentos($limpio);
            if (isset($tipos[$k])) { $tras = true; continue; }
            if (in_array($k, $genericas, true)) { $tras = false; continue; }
            if (in_array($k, $articulos, true)) {
                if ($tras) $partes[$i] = oficioPrimeraMay($p);
                $tras = false;
                continue;
            }
            if (in_array($k, $chicas, true)) { $tras = false; continue; }
            $partes[$i] = oficioPrimeraMay($p);
            $tras = false;
        }
        return implode('', $partes);
    }
}

/**
 * Género probable (f / m / '') según el primer nombre. Se usa solo para
 * escribir "doña / don" y "directora / director"; si no hay certeza
 * devuelve '' y el texto usa una forma neutra.
 */
if (!function_exists('oficioGenero')) {
    function oficioGenero($nombre) {
        $n = trim(preg_replace('/^(?:lcda?\.?|licda?\.?|lic\.?|ing\.?|inga\.?|dra?\.?|do[ñn]a|don|sra?\.?|se[ñn]ora?|prof\.?|profa\.?|arq\.?)\s+/iu', '', trim((string) $nombre)));
        if (!preg_match('/^(\p{L}+)/u', $n, $m)) return '';
        $p = oficioSinAcentos($m[1]);
        if ($p === '') return '';

        $femeninos = ['carmen','mercedes','rosario','lourdes','beatriz','isabel','raquel','ruth','esther','ester','miriam',
                      'noemi','dolores','consuelo','ines','luz','gladys','ingrid','astrid','yaneth','janeth','elizabeth',
                      'judith','karen','helen','jennifer','nancy','lucy','lizeth','lisbeth','maribel','marisol','maricruz',
                      'jazmin','abigail','edith','mabel','belen','soledad','pilar','guadalupe','concepcion','rocio',
                      'heidy','heidi','wendy','sury','yuri','liz','flor','paz','sara','ivon','yvonne','shirley'];
        $masculinosEnA = ['joshua','jonatha','luca','nikita','sasha','elia','moises'];

        if (in_array($p, $femeninos, true)) return 'f';
        if (in_array($p, $masculinosEnA, true)) return 'm';
        if (substr($p, -1) === 'a') return 'f';
        return 'm';
    }
}

/**
 * Deja el nombre del vecino listo para el oficio: sin restos de frases mal
 * separadas ("Yolanda Salazar y aquí en"), sin números y en formato Título.
 * Devuelve '' si el ciudadano es anónimo o no dio su nombre.
 */
if (!function_exists('oficioNombreVecino')) {
    function oficioNombreVecino($nombre) {
        $n = html_entity_decode(trim((string) $nombre), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $n = preg_replace('/\s+/u', ' ', $n);
        $n = preg_replace('/\s+y\s+(?:mi|mis|aqu[ií]|ac[aá]|vivo|en|tengo|soy|me|el|la|un|una|nos|somos|estoy)\b.*$/iu', '', $n);
        $n = preg_replace('/\d+/u', '', $n);
        $n = trim($n, " \t\n\r\0\x0B.,;:-");
        if ($n === '' || $n === '(No proporcionado)' || $n === '—') return '';
        if (preg_match('/^an[oó]nim[oa]$/iu', $n)) return '';
        if (oficioPalabras($n) > 6) return '';
        if ($n === oficioMay($n)) $n = oficioMin($n);
        $partes = preg_split('/(\s+)/u', $n, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($partes as $i => $p) {
            if (in_array($p, ['de', 'del', 'la', 'las', 'los', 'y'], true)) continue;
            $partes[$i] = oficioPrimeraMay($p);
        }
        return implode('', $partes);
    }
}

/** Teléfono legible: "50259312332" => "5931 2332". Devuelve '' si no hay. */
if (!function_exists('oficioTelefono')) {
    function oficioTelefono($tel) {
        $d = preg_replace('/\D+/', '', (string) $tel);
        if ($d === '') return '';
        if (strlen($d) === 11 && substr($d, 0, 3) === '502') $d = substr($d, 3);
        if (strlen($d) === 8) return substr($d, 0, 4) . ' ' . substr($d, 4);
        return $d;
    }
}

/**
 * Da forma formal a la ubicación que dio el ciudadano.
 *
 * @return array|null ['frase' => 'en la colonia Flor del Café',
 *                     'nucleo' => 'colonia Flor del Café',
 *                     'crudo' => 'colonia flor del café']  o null si no sirve.
 */
if (!function_exists('oficioUbicacion')) {
    function oficioUbicacion($dirRaw) {
        $d = html_entity_decode(trim((string) $dirRaw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $d = preg_replace('/\s+/u', ' ', $d);
        $d = trim($d, " \t\n\r\0\x0B.,;:-");
        if ($d === '' || $d === '(No proporcionada)' || $d === '—') return null;
        if (!preg_match('/\p{L}/u', $d)) return null;
        // Respuestas que no son una dirección ("Hola buenas noches", "No", "Gracias")
        if (oficioLargo($d) < 40 && preg_match('/^(?:hola|buen[oa]s?|gracias|ok|s[ií]|no)\b/iu', $d)) return null;

        if ($d === oficioMay($d)) $d = oficioMin($d);
        $d = preg_replace('/^(?:aqu[ií]|ac[aá])\s+/iu', '', $d);
        $d = preg_replace('/\bcafe\b/iu', 'café', $d);
        $d = preg_replace('/^en\s+frente\b/iu', 'enfrente', $d);
        $d = preg_replace('/^en\s+/iu', '', $d);

        $tipos = oficioTiposLugar();
        // "la colonia X" => "colonia X" (el artículo se pone después)
        if (preg_match('/^(?:el|la|los|las)\s+(\p{L}+)\s+(.+)$/iu', $d, $m) && isset($tipos[oficioSinAcentos($m[1])])) {
            $d = $m[1] . ' ' . $m[2];
        }

        // DEFENSIVO: si la dirección guardada trae pegada la narración del
        // problema ("...se reventó una tubería", "...está metiendo a las
        // casas"), se corta para que el oficio solo cite la ubicación real.
        $d = preg_replace('/\s+se\s+\p{L}{3,}\b.*$/iu', '', $d);
        $d = preg_replace('/\s+est[aá]n?\s+\p{L}+(?:ando|iendo|yendo)\b.*$/iu', '', $d);
        $d = trim($d);
        if ($d === '') return null;

        $sa = oficioSinAcentos($d);
        $primera = '';
        if (preg_match('/^(\p{L}+)/u', $d, $m)) $primera = oficioSinAcentos($m[1]);

        $relacional = '/^(?:enfrente|frente|cerca|junto|detras|atras|adyacente|a la par|al lado|al costado|a un costado|contiguo|sobre|entre|a la altura|a orillas|hacia|por|dentro|fuera|a unos)\b/u';
        $callePorNumero = '/^(?:\d+\s*(?:ra|da|ta|a|va|ma|na|o)?\.?|primera|segunda|tercera|cuarta|quinta|sexta|septima|octava|novena|decima)\s+(?:calle|avenida|av\.?)\b/u';

        if (preg_match($relacional, $sa)) {
            $nucleo = oficioPrimeraMin($d);
            $frase = $nucleo;
        } elseif ($primera !== '' && isset($tipos[$primera])) {
            $nucleo = oficioTitulo($d);
            $frase = 'en ' . $tipos[$primera] . ' ' . $nucleo;
        } elseif (preg_match($callePorNumero, $sa)) {
            $nucleo = oficioTitulo($d);
            $frase = 'en la ' . $nucleo;
        } elseif (preg_match('/^\d/u', $d)) {
            $nucleo = $d;
            $frase = 'en la dirección ' . $d;
        } else {
            // Si todo viene en minúsculas y no empieza con un tipo de lugar conocido,
            // no se capitaliza (podría ser un sustantivo común: "las casas").
            $tieneMayusculasOriginales = ($d !== oficioMin($d));
            $nucleo = $tieneMayusculasOriginales ? oficioTitulo($d) : $d;
            $frase = 'en ' . $nucleo;
        }

        return ['frase' => $frase, 'nucleo' => $nucleo, 'crudo' => $d];
    }
}

/**
 * Agrega el contexto de aldea a topónimos conocidos cuando el ciudadano los
 * mencionó sin él. Caso real: oficio 03, "el corte de grama del campo de
 * Lolemi" → "...del campo de la aldea Lolemi". También convierte "sector
 * Toronjal Chocolá" en "sector Toronjal de la aldea Chocolá" y capitaliza
 * "Iglesia Católica" cuando va seguida de un lugar.
 *
 * Se aplica DESPUÉS de oficioCorregirOrtografia(), por eso los patrones
 * toleran la forma con tilde ("Chocolá", "Madre Mía"). Solo actúa cuando el
 * topónimo va pegado a la preposición ("de Lolemi", "en Chocolá"), de modo
 * que "de la aldea Lolemi" no se duplica.
 */
if (!function_exists('agregarContextoAldea')) {
    function agregarContextoAldea($texto) {
        if (!is_string($texto) || $texto === '') return $texto;

        // patrón del topónimo (tolerante a tilde) => forma final con "aldea"
        static $ALDEAS = [
            'lolemi'          => 'aldea Lolemi',
            'chocol[aá]'      => 'aldea Chocolá',
            'madre\s+m[ií]a'  => 'aldea Madre Mía',
            'pacacoj'         => 'aldea Pacacoj',
            'piedritas'       => 'aldea Piedritas',
        ];

        // Primero el caso de dos topónimos seguidos, para que el bucle no lo toque.
        $texto = preg_replace(
            '/\b(sector)\s+toronjal\s+chocol[aá]\b/iu',
            '$1 Toronjal de la aldea Chocolá',
            $texto
        );

        foreach ($ALDEAS as $patronTopo => $conAldea) {
            $texto = preg_replace_callback(
                '/\b(de|en|hasta|hacia|por)\s+' . $patronTopo . '\b/iu',
                function ($m) use ($conAldea) {
                    return $m[1] . ' la ' . $conAldea;
                },
                $texto
            );
        }

        $texto = preg_replace(
            '/\b(iglesia)\s+(cat[oó]lica)(?=\s+de\s+(?:la\s+)?(?:aldea|colonia|cant[oó]n|caser[ií]o|barrio|sector)\b)/iu',
            'Iglesia Católica',
            $texto
        );

        return $texto;
    }
}

/**
 * ¿La ubicación ya está mencionada dentro del relato del ciudadano?
 * (para no repetirla al final de la frase)
 */
if (!function_exists('oficioUbicacionYaEnTexto')) {
    function oficioUbicacionYaEnTexto($ubic, $texto) {
        if (!$ubic) return false;
        // El relato ya pasó por las reglas de redacción (ej. "Colina de Chocola"
        // → "Colonia Chocola"); se prueba también la ubicación corregida.
        $corr = oficioCorregirOrtografia($ubic['crudo']);
        if ($corr !== '' && $corr !== $ubic['crudo']) {
            $u2 = $ubic;
            $u2['crudo'] = $corr;
            if (oficioUbicacionYaEnTexto($u2, $texto)) return true;
        }
        $t = oficioSinAcentos($texto);
        $d = oficioSinAcentos($ubic['crudo']);
        if ($d !== '' && strpos($t, $d) !== false) return true;

        $tipos = oficioTiposLugar();
        $ignorar = ['de', 'del', 'la', 'las', 'los', 'el', 'en', 'y', 'a', 'al', 'principal', 'real', 'central', 'urbano', 'casco'];
        $significativas = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $d, -1, PREG_SPLIT_NO_EMPTY) as $w) {
            if (strlen($w) < 3 || in_array($w, $ignorar, true) || isset($tipos[$w])) continue;
            $significativas[] = $w;
        }
        if (empty($significativas)) return false;
        foreach ($significativas as $w) {
            if (!preg_match('/(?<![\p{L}\p{N}])' . preg_quote($w, '/') . '(?![\p{L}\p{N}])/u', $t)) return false;
        }
        return true;
    }
}

/**
 * Corrige ortografía frecuente de WhatsApp y FORMALIZA modismos coloquiales.
 * Las reglas viven en oficio_reglas.php (OFICIO_REGLAS_ORTOGRAFIA,
 * OFICIO_REGLAS_FORMALIZACION y OFICIO_PROHIBIDAS). Se aplica al relato y a
 * la dirección.
 */
if (!function_exists('oficioCorregirOrtografia')) {
    function oficioCorregirOrtografia($t) {
        // 1) Ortografía básica
        foreach (OFICIO_REGLAS_ORTOGRAFIA as $patron => $reemplazo) {
            $t = preg_replace($patron, $reemplazo, $t);
        }
        // 2) Formalización (modismos coloquiales → lenguaje institucional)
        foreach (OFICIO_REGLAS_FORMALIZACION as $patron => $reemplazo) {
            $t = preg_replace($patron, $reemplazo, $t);
        }
        // 3) Muletillas y opiniones personales
        foreach (OFICIO_REGLAS_MULETILLAS as $patron => $reemplazo) {
            $t = preg_replace($patron, $reemplazo, $t);
        }
        // 4) Citas textuales (se conservan literales, introducidas en impersonal)
        foreach (OFICIO_REGLAS_CITAS as $patron => $reemplazo) {
            $t = preg_replace($patron, $reemplazo, $t);
        }
        // 5) Persona/tiempo verbal del peticionario
        foreach (OFICIO_REGLAS_TIEMPO_VERBAL as $patron => $reemplazo) {
            $t = preg_replace($patron, $reemplazo, $t);
        }
        // 6) Concordancia de género y número
        foreach (OFICIO_REGLAS_CONCORDANCIA as $patron => $reemplazo) {
            $t = preg_replace($patron, $reemplazo, $t);
        }
        // 7) Filtro de palabras que nunca deben salir en un oficio
        foreach (OFICIO_PROHIBIDAS as $palabra) {
            $patron = '/(?<![\p{L}\p{N}])' . preg_quote($palabra, '/') . '(?![\p{L}\p{N}])/iu';
            $t = preg_replace($patron, '', $t);
        }
        // 8) Limpieza de puntuación y espacios
        $t = oficioNormalizarPuntuacion($t);
        // 9) Puntuación fina
        foreach (OFICIO_REGLAS_PUNTUACION_FINAL as $patron => $reemplazo) {
            $t = preg_replace($patron, $reemplazo, $t);
        }
        // 10) Vocabulario formal + números en letras + redundancias + causa→efecto
        return oficioPulirRedaccionFinal($t);
    }
}

/**
 * Normaliza espacios, signos repetidos y puntuación sobrante, sin alterar
 * el contenido.
 */
if (!function_exists('oficioNormalizarPuntuacion')) {
    function oficioNormalizarPuntuacion($t) {
        if (!is_string($t)) return $t;
        $t = preg_replace('/[ \t]+/u', ' ', $t);
        $t = preg_replace('/\s+([,.;:!?])/u', '$1', $t);
        $t = preg_replace('/([.,;:!?])\1+/u', '$1', $t);
        $t = preg_replace('/^[ \t]+/m', '', $t);
        $t = preg_replace('/[ \t]+$/m', '', $t);
        $t = preg_replace('/ *\n */u', "\n", $t);
        $t = str_replace('…', '.', $t);
        // Comas dobles, coma antes de punto, coma al inicio/final de línea
        $t = preg_replace('/,\s*,+/u', ',', $t);
        $t = preg_replace('/,\s*\./u', '.', $t);
        $t = preg_replace('/^\s*,\s*/m', '', $t);
        $t = preg_replace('/[ \t]*,[ \t]*$/m', '', $t);
        return trim($t);
    }
}

/**
 * Aplica al relato el vocabulario formal (boquete→bache, aguas negras→aguas
 * residuales, motos→motocicletas...) y limpia redundancias inmediatas.
 */
if (!function_exists('oficioAplicarVocabularioFormal')) {
    function oficioAplicarVocabularioFormal($t) {
        if (!is_string($t) || $t === '') return $t;
        foreach (OFICIO_REGLAS_VOCABULARIO_FORMAL as $patron => $reemplazo) {
            $t = preg_replace($patron, $reemplazo, $t);
        }
        foreach (OFICIO_REGLAS_REDUNDANCIA as $patron => $reemplazo) {
            $t = preg_replace($patron, $reemplazo, $t);
        }
        return $t;
    }
}

/**
 * Convierte "<hecho> y (ya) <cuantificador?> <sujeto> (ya) se <verbo de daño>"
 * en "<hecho>, el cual ha provocado <la caída de> <cuantificador> <sujeto>".
 * Solo reestructura cuando el patrón es inequívoco; si no, devuelve el texto
 * intacto. Conserva el punto final solo si el texto original lo traía.
 */
if (!function_exists('oficioReestructurarCausaEfecto')) {
    function oficioReestructurarCausaEfecto($t) {
        if (!is_string($t) || trim($t) === '') return $t;

        // ── Caso especial: emisión (humo, chispas, llamas) sobre el tendido ──
        // "Una rama de árbol cayó sobre el tendido eléctrico y está emitiendo
        //  humo en Colonia X" → "... cayó sobre el tendido eléctrico,
        //  provocando la emisión de humo en Colonia X". Va antes del patrón
        // general porque la consecuencia no es un sujeto que se daña.
        foreach (OFICIO_REGLAS_CAUSA_EFECTO['patrones_emision_electrica'] ?? [] as $patronEmision) {
            $t = preg_replace_callback($patronEmision, function ($m) {
                $sujeto   = trim($m[1]);
                $receptor = trim($m[2]);
                $verbo    = oficioSinAcentos($m[3]);
                $emision  = oficioSinAcentos($m[4]);
                // "mucho/bastante/muchas humo/chispas" → "abundante(s)"
                $emision  = preg_replace('/^(?:mucho|bastante|abundante)\s+/u', 'abundante ', $emision);
                $emision  = preg_replace('/^(?:muchas|abundantes)\s+/u', 'abundantes ', $emision);
                $cola     = trim($m[5] ?? '');

                $mapaEmision = [
                    'emitiendo'     => 'la emisión de',
                    'desprendiendo' => 'el desprendimiento de',
                    'produciendo'   => 'la producción de',
                    'generando'     => 'la generación de',
                    'provocando'    => 'la emisión de',
                    'causando'      => 'la emisión de',
                ];
                $fraseNominal = $mapaEmision[$verbo] ?? 'la emisión de';

                // "una rama de un árbol" → "una rama de árbol"
                $sujeto = preg_replace('/\bde\s+un\s+(arbol|árbol|rama|poste|cable|objeto)\b/iu', 'de $1', $sujeto);

                $res = $sujeto . ' cayó sobre ' . $receptor
                     . ', provocando ' . $fraseNominal . ' ' . $emision;
                if ($cola !== '') {
                    // La cola puede ser "." o " en Colonia X."; se pega sin doble espacio.
                    $res .= (preg_match('/^[.,;:]/u', $cola) ? '' : ' ') . $cola;
                }
                return $res;
            }, $t);
        }

        // ── Caso: obstrucción de la vía ("X y no pueden pasar") ──
        // "Un árbol caído encima de la calle y no pueden pasar"
        //   → "Un árbol caído encima de la calle, el cual impide el paso"
        //
        // La ubicación (por ejemplo "en la colonia Gerardo Reyero") se añade
        // DESPUÉS por oficioFraseProblema(), así que la frase final lee:
        //   "... el cual impide el paso en la colonia Gerardo Reyero."
        //
        // Si después de "pasar/transitar/..." viene un sujeto explícito
        // ("los carros", "las motos", "las personas"), se incorpora como
        // "el paso de <sujeto>" en vez de dejarlo pegado como cola.
        foreach (OFICIO_REGLAS_CAUSA_EFECTO['patrones_obstruccion_via'] ?? [] as $patronObstruccion) {
            $t = preg_replace_callback($patronObstruccion, function ($m) {
                $hecho = rtrim(trim($m[1]), " ,;");
                $cola  = trim($m[2] ?? '');

                // ¿La cola empieza con un sujeto ("los carros", "las personas"...)?
                if ($cola !== '' && preg_match(
                    '/^(los?|las?|unos?|unas?)\s+([\p{L}áéíóúñ]+)\b\s*(.*)$/iu',
                    $cola, $mSubj
                )) {
                    $sujeto = $mSubj[2];
                    $resto  = trim($mSubj[3] ?? '');
                    $res = $hecho . ', el cual impide el paso de ' . $sujeto;
                    if ($resto !== '') {
                        $res .= (preg_match('/^[.,;:]/u', $resto) ? '' : ' ') . $resto;
                    }
                    return $res;
                }

                $res = $hecho . ', el cual impide el paso';
                if ($cola !== '') {
                    $res .= (preg_match('/^[.,;:]/u', $cola) ? '' : ' ') . $cola;
                }
                return $res;
            }, $t);
        }

        // ── Caso: anegamiento (fuga / drenaje tapado + agua acumulada) ──
        foreach (OFICIO_REGLAS_CAUSA_EFECTO['patrones_anegamiento'] ?? [] as $patronAneg) {
            $t = preg_replace_callback($patronAneg, function ($m) {
                $hecho    = rtrim(trim($m['hecho']), " ,;");
                $receptor = trim(preg_replace('/\s+/u', ' ', oficioSinAcentos($m['receptor'] ?? '')));
                $cola     = trim($m['cola'] ?? '');
                $esLluvia = !empty($m['lluvia']);   // solo existe en el 2º patrón

                if ($esLluvia) {
                    // Complemento de lugar: "provocando acumulación de agua <lugar> cuando llueve"
                    $mapaLugar = [
                        'frente a las casas'     => 'frente a las viviendas',
                        'frente a la casa'       => 'frente a la vivienda',
                        'frente a las viviendas' => 'frente a las viviendas',
                        'frente a la vivienda'   => 'frente a la vivienda',
                        'frente a mi casa'       => 'frente a su vivienda',
                        'frente a su vivienda'   => 'frente a su vivienda',
                        'en la calle'            => 'en la vía pública',
                        'en la via'              => 'en la vía pública',
                        'en la via publica'      => 'en la vía pública',
                        'en el sector'           => 'en el sector',
                    ];
                    $res = $hecho . ', provocando acumulación de agua '
                         . ($mapaLugar[$receptor] ?? $receptor) . ' cuando llueve';
                } else {
                    // Objeto directo: "…, la cual ha comenzado a anegar <objeto>"
                    $mapaObjeto = [
                        'la calle'               => 'la vía pública',
                        'el camino'              => 'el camino',
                        'la via'                 => 'la vía pública',
                        'la via publica'         => 'la vía pública',
                        'el sector'              => 'el sector',
                        'la colonia'             => 'la colonia',
                        'el barrio'              => 'el barrio',
                        'frente a las casas'     => 'el frente de las viviendas',
                        'frente a la casa'       => 'el frente de la vivienda',
                        'frente a las viviendas' => 'el frente de las viviendas',
                        'frente a la vivienda'   => 'el frente de la vivienda',
                        'frente a mi casa'       => 'el frente de su vivienda',
                        'frente a su vivienda'   => 'el frente de su vivienda',
                        'las casas'              => 'las viviendas',
                        'la casa'                => 'la vivienda',
                        'las viviendas'          => 'las viviendas',
                        'la vivienda'            => 'la vivienda',
                    ];
                    // "la cual" solo si el antecedente es la fuga (femenino); si no, "lo cual".
                    $rel = preg_match('/\bfuga\b/iu', $hecho) ? 'la cual' : 'lo cual';
                    $res = $hecho . ', ' . $rel . ' ha comenzado a anegar '
                         . ($mapaObjeto[$receptor] ?? $receptor);
                }

                if ($cola !== '') {
                    $res .= (preg_match('/^[.,;:]/u', $cola) ? '' : ' ') . $cola;
                }
                return $res;
            }, $t);
        }

        // ── Caso: oscuridad nocturna por falta de alumbrado ──
        foreach (OFICIO_REGLAS_CAUSA_EFECTO['patrones_oscuridad'] ?? [] as $patronOsc) {
            $t = preg_replace_callback($patronOsc, function ($m) {
                $hecho = rtrim(trim($m['hecho']), " ,;");
                $cola  = trim($m['cola'] ?? '');

                $res = $hecho . ', lo cual mantiene el sector a oscuras durante la noche';
                if ($cola !== '') {
                    $res .= (preg_match('/^[.,;:]/u', $cola) ? '' : ' ') . $cola;
                }
                return $res;
            }, $t);
        }

        $verbos  = OFICIO_REGLAS_CAUSA_EFECTO['verbos_dano'];
        $sujetos = OFICIO_REGLAS_CAUSA_EFECTO['sujetos'];
        $cuant   = OFICIO_REGLAS_CAUSA_EFECTO['cuantificadores'];
        $mapa    = OFICIO_REGLAS_CAUSA_EFECTO['mapa_verbos'];

        // 1 = hecho, 2 = cuantificador, 3 = sujeto, 4 = verbo, 5 = punto final
        $patron = '/^(.+?)[,.]?\s+y\s+(?:ya\s+)?(?:(' . $cuant . ')\s+)?(' . $sujetos . ')\s+(?:ya\s+)?se\s+(' . $verbos . ')'
                . '(?:\s+por\s+(?:ese|este|el|dicho|aquel)\s+(?:agujero|hoyo|bache|hueco|desperfecto|problema|sitio|lugar))?'
                . '\s*(\.?)\s*$/iu';

        return preg_replace_callback($patron, function ($m) use ($mapa) {
            $hecho   = rtrim(trim($m[1]), ',');
            $cuantif = trim($m[2]);
            $sujeto  = oficioSinAcentos($m[3]);
            $verbo   = oficioSinAcentos($m[4]);
            $punto   = $m[5];

            $equivalencias = [
                'moto' => 'motocicletas', 'motos' => 'motocicletas',
                'motocicleta' => 'motocicletas', 'motocicletas' => 'motocicletas',
                'motociclista' => 'motociclistas', 'motociclistas' => 'motociclistas',
                'motorista' => 'motociclistas', 'motoristas' => 'motociclistas',
                'carro' => 'vehículos', 'carros' => 'vehículos',
                'vehiculo' => 'vehículos', 'vehiculos' => 'vehículos',
                'automovil' => 'vehículos', 'automoviles' => 'vehículos',
                'camion' => 'camiones', 'camiones' => 'camiones',
                'bus' => 'buses', 'buses' => 'buses',
                'bicicleta' => 'bicicletas', 'bicicletas' => 'bicicletas',
                'ciclista' => 'ciclistas', 'ciclistas' => 'ciclistas',
                'peaton' => 'peatones', 'peatones' => 'peatones',
                'persona' => 'personas', 'personas' => 'personas',
                'nino' => 'menores', 'ninos' => 'menores',
                'anciano' => 'personas de la tercera edad', 'ancianos' => 'personas de la tercera edad',
                'vecino' => 'vecinos', 'vecinos' => 'vecinos',
                'conductor' => 'conductores', 'conductores' => 'conductores',
            ];
            $sujetoFormal = $equivalencias[$sujeto] ?? (substr($sujeto, -1) === 's' ? $sujeto : $sujeto . 's');

            $fraseNominal = null;
            foreach ($mapa as $clave => $valor) {
                if (preg_match('/^(?:' . $clave . ')$/u', $verbo)) { $fraseNominal = $valor; break; }
            }
            if ($fraseNominal === null) return $m[0]; // verbo no mapeado: texto intacto

            // Con cuantificador en singular ("una moto") no se pluraliza el sentido:
            // se mantiene tal cual lo dijo el ciudadano.
            $cuantifTxt = $cuantif !== '' ? oficioMin($cuantif) . ' ' : '';
            return $hecho . ', el cual ha provocado ' . $fraseNominal . ' ' . $cuantifTxt . $sujetoFormal . $punto;
        }, $t);
    }
}

/**
 * Pasada final de pulido: vocabulario + redundancias + causa→efecto.
 */
if (!function_exists('oficioPulirRedaccionFinal')) {
    function oficioPulirRedaccionFinal($t) {
        $t = oficioAplicarVocabularioFormal($t);
        // Números en letras y ordinales de vía (después del vocabulario, para
        // que "5 motos" → "5 motocicletas" → "cinco motocicletas" en una pasada).
        foreach (OFICIO_REGLAS_NUMEROS as $patron => $reemplazo) {
            $t = preg_replace($patron, $reemplazo, $t);
        }
        $t = oficioReestructurarCausaEfecto($t);
        return $t;
    }
}

/**
 * NUEVO — Depura el CHAT completo antes de redactar.
 * Quita: respuestas automáticas del bot, enlaces, "Sin DPI", nombres sueltos,
 * "No", "Ok", agradecimientos y líneas repetidas. Si el chat trae varias
 * peticiones (cada una abre con un nuevo saludo), se queda con la primera.
 * Devuelve el texto listo para oficioLimpiarRelato() ('' si no queda nada).
 */
if (!function_exists('oficioDepurarChat')) {
    function oficioDepurarChat($mensaje) {
        $t = html_entity_decode((string) $mensaje, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace('/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{FE00}-\x{FE0F}\x{200D}]/u', ' ', $t);
        $lineas = preg_split('/\R+/u', $t, -1, PREG_SPLIT_NO_EMPTY);

        // Texto que escribe el BOT (nunca es relato del ciudadano)
        $rxBot = '/(?:gracias\s+por\s+comunicarse|lo\s+registr[eé]\s+como|hemos\s+recibido\s+tu|ya\s+ha\s+sido\s+registrad|ser[aá]\s+revisado\s+y\s+atendido|[aá]rea\s+correspondiente|solicitud\s+registrada|ser[aá]\s+atendida\s+lo\s+antes|necesito\s+un\s+poco\s+m[aá]s\s+de\s+informaci[oó]n|importado\s+de\s+whatsapp|vecino\(a\)|urgencia\s+(?:alta|media|baja|inmediata))/iu';
        // Saludos (con errores típicos: "buenas no noche", "buen día")
        $rxSaludo = '/^[\s,;:.!¡¿?-]*(?:hola+|holi+|buen[oa]?s?(?:(?:\s+no)?\s+(?:d[ií]as?|tardes?|noches?))?|muy\s+buen[oa]?s?(?:\s+(?:d[ií]as?|tardes?|noches?))?|saludos|disculpe(?:n)?|perd[oó]n|oiga|ok(?:ey)?|pues|bueno|estimad[oa]s?|se[ñn]ores|f[ií]jese(?:\s+que)?|una\s+consulta|una\s+pregunta|(?:muchas\s+)?gracias(?:\s+por\s+(?:el|la|su|sus|las|los|todo)\s+\p{L}+)?(?:\s+y\b)?|vale)\b[\s,;:.!¡¿?-]*/iu';
        // Líneas que no aportan nada
        $rxRuido = '/^(?:(?:sin|no\s+tengo|no\s+tiene|no\s+cuento\s+con)\s+(?:dpi|cui)|\p{L}{1,2}|(?:no|s[ií]|ok(?:ey)?|vale|gracias|listo|ya|xd|jaja\w*|jeje\w*)(?:\s+(?:tengo|tiene|gracias))?)[\s.!?,]*$/iu';

        $bloque = [];
        $vistas = [];
        $hayContenido = false;
        foreach ($lineas as $l) {
            $l = trim(preg_replace('/\s+/u', ' ', $l));
            if ($l === '' || preg_match($rxBot, $l) || preg_match('~(?:https?://|www\.)~i', $l)) continue;

            $traeSaludo = (bool) preg_match($rxSaludo, $l) && preg_replace($rxSaludo, '', $l, 1) !== $l;
            // Un nuevo saludo después de haber dado el reporte = otra conversación/petición.
            if ($traeSaludo && $hayContenido) break;

            for ($i = 0; $i < 5; $i++) {
                $n = preg_replace($rxSaludo, '', $l, 1);
                if ($n === $l) break;
                $l = trim($n);
            }
            if ($l === '' || preg_match($rxRuido, $l)) continue;
            // Nombre suelto de persona ("Alejandro Estrada"): no es parte del relato.
            if (preg_match('/^\p{Lu}\p{Ll}+(?:\s+\p{Lu}\p{Ll}+){1,2}$/u', $l)) continue;

            $clave = oficioSinAcentos(oficioMin($l));
            if (isset($vistas[$clave])) continue;
            $vistas[$clave] = true;

            $bloque[] = $l;
            if (oficioPalabras($l) >= 3) $hayContenido = true;
        }
        return trim(implode('. ', array_map(function ($x) { return rtrim($x, " \t.,;"); }, $bloque)));
    }
}

/**
 * NUEVO — Lugar mencionado dentro del propio relato ("en sector Vista Hermosa
 * del casco urbano"). Devuelve la frase formal ("en el sector ...") o ''.
 */
if (!function_exists('oficioLugarEnTexto')) {
    function oficioLugarEnTexto($t) {
        if (!preg_match('/\b((?:sector|colonia|aldea|cant[oó]n|barrio|zona|caser[ií]o|lotificaci[oó]n|residenciales?|parcelamiento|finca)\s+[\p{L}\p{N}\'.-]+(?:\s+[\p{L}\p{N}\'.-]+){0,6})/iu', (string) $t, $m)) return '';
        $alto = ['no','hay','se','y','e','desde','con','sin','que','esta','está','están','estan','tiene','tienen','hace','en','por','para','ya','ha','han','lleva','llevan','falta','fue','cuentan','contamos','tenemos','hoy','ayer','mucho','muy','donde','cuando','y'];
        $out = [];
        foreach (preg_split('/\s+/u', trim($m[1])) as $i => $w) {
            if ($i >= 2 && in_array(oficioMin($w), $alto, true)) break;
            $out[] = $w;
        }
        $u = oficioUbicacion(implode(' ', $out));
        return $u ? $u['frase'] : '';
    }
}

/**
 * NUEVO — Duración / momento del problema ("desde el día anterior al reporte",
 * "desde hace dos días", "desde las 8:45am"). '' si no se menciona.
 */
if (!function_exists('oficioDuracionEnTexto')) {
    function oficioDuracionEnTexto($t) {
        $n = oficioSinAcentos(oficioMin((string) $t));
        if (preg_match('/dia\s+anterior\s+al\s+reporte/u', $n)) return 'desde el día anterior al reporte';
        if (preg_match('/dia\s+del\s+reporte/u', $n))           return 'desde el día del reporte';
        if (preg_match('/hace\s+((?:[a-z0-9]+\s+){1,3}?(?:dias?|semanas?|horas?|meses|mes))\b/u', $n, $m)) {
            return 'desde hace ' . str_replace(['dias', 'dia'], ['días', 'día'], $m[1]);
        }
        if (preg_match('/desde\s+las?\s+(\d{1,2}(?:[:.]\d{2})?\s*(?:am|pm|a\.m\.|p\.m\.)?)/u', $n, $m)) return 'desde las ' . trim($m[1]);
        return '';
    }
}

/**
 * NUEVO — INTERPRETACIÓN del relato. En lugar de copiar lo que escribió el
 * ciudadano, reconoce los casos típicos y redacta la situación en lenguaje
 * institucional. Devuelve la frase (SIN ubicación) o null si no reconoce
 * el caso (entonces se usa la redacción genérica de siempre).
 * Para agregar un caso nuevo, añadir otro bloque "if" siguiendo el patrón.
 */
if (!function_exists('oficioInterpretarRelato')) {
    function oficioInterpretarRelato($t) {
        $n = oficioSinAcentos(oficioMin((string) $t));
        if ($n === '') return null;
        $dur = oficioDuracionEnTexto($t);

        // 1) Vehículo estacionado donde está prohibido / bloqueando
        $hayVehiculo = preg_match('/\b(?:carro|carros|vehiculo|vehiculos|camion|camioneta|pick\s?up|troca|moto|motos|motocicleta)\b/u', $n);
        $hayEstac    = preg_match('/\b(?:estacion\w*|parque\w*|bloque\w+|obstru\w+|impid\w+|hay\s+(?:un|una)\s+(?:carro|vehiculo|camion|camioneta|moto))\b/u', $n);
        $hayZona     = preg_match('/\b(?:rojo|roja|pintad\w+|prohib\w+|banqueta|banquetas|acera|aceras|cochera|porton|entrada|bloque\w+|obstru\w+)\b/u', $n);
        if ($hayVehiculo && $hayEstac && $hayZona) {
            $lugar = preg_match('/\b(?:enfrente|frente)\s+de\s+su\s+(?:vivienda|casa|domicilio|residencia)\b/u', $n) ? ' frente a su vivienda' : '';
            if (preg_match('/\b(?:rojo|roja|pintad\w+|prohib\w+)\b/u', $n)) {
                return 'la presencia de un vehículo estacionado' . $lugar . ' en un tramo de acera señalizado con pintura roja, donde el estacionamiento se encuentra prohibido';
            }
            return 'la presencia de un vehículo estacionado' . $lugar . ' que obstruye el paso o el acceso';
        }

        // 2) Calidad del agua
        if (preg_match('/calidad\s+del\s+agua|agua\s+(?:sucia|turbia|contaminad\w+|amarilla|con\s+(?:lodo|mal\s+olor|sarro))/u', $n)) {
            return preg_match('/\bmejor\w*\b/u', $n)
                ? 'la necesidad de mejorar la calidad del agua que se suministra a la población'
                : 'deficiencias en la calidad del agua que se suministra a la población';
        }

        // 3) Falta / interrupción del agua
        if (preg_match('/\b(?:no\s+(?:hay|tienen|cuentan|llega|ha\s+llegado|sale|baja)|sin|falta\s+de)\b(?:\s+\w+){0,3}\s+agua\b/u', $n)
            || preg_match('/\bagua\b(?:\s+\w+){0,4}\s+(?:se\s+(?:fue|cort\w+)|no\s+(?:ha\s+regresado|regresa|llega|ha\s+llegado|hay|sale|baja))\b/u', $n)) {
            return 'la falta del servicio de agua potable' . ($dur !== '' ? ' ' . $dur : '');
        }

        // 4) Transformador descompuesto / falta de energía
        $sinLuz = preg_match('/\bno\s+(?:hay|tienen|cuentan|llega|tenemos)\s+(?:luz|energia)\b|\bsin\s+(?:luz|energia)\b|\bse\s+(?:fue|cort\w+)\s+la\s+(?:luz|energia)\b/u', $n);
        if (preg_match('/\btransformador(?:es)?\b/u', $n) && preg_match('/\b(?:descompuest\w+|da[nñ]ad\w+|quemad\w+|averiad\w+|explot\w+|fall\w+|no\s+funciona)\b/u', $n)) {
            return 'la avería de un transformador eléctrico' . ($sinLuz ? ', que ha dejado a los vecinos sin suministro de energía eléctrica' : '') . ($dur !== '' ? ' ' . $dur : '');
        }
        if ($sinLuz) {
            return 'la falta de suministro de energía eléctrica' . ($dur !== '' ? ' ' . $dur : '');
        }

        return null;
    }
}

/**
 * NUEVO — ¿Esta petición es solo una consulta/información (o no trae nada
 * utilizable)? Si es así NO debe generar oficio. Lo usa reportes/generar.php.
 */
if (!function_exists('oficioEsConsultaSinOficio')) {
    function oficioEsConsultaSinOficio($textoOriginal, $categoriaKey, $direccion = '') {
        $cat = (string) $categoriaKey;
        if (strpos($cat, 'informacion') === 0) return true;
        $r = oficioFraseProblema($textoOriginal, $direccion);
        if ($r === null) return in_array($cat, ['', 'administrativo'], true);
        return in_array($r['tipo'], ['consulta', 'info'], true);
    }
}

/**
 * Limpia y pasa a tercera persona lo que escribió el ciudadano.
 *
 * @return array ['tipo' => 'problema'|'consulta'|'info'|'solicitud', 'texto' => '...']
 *               (texto vacío si no quedó nada útil)
 */
if (!function_exists('oficioLimpiarRelato')) {
    function oficioLimpiarRelato($mensaje, $ubic = null, $particular = false) {
        $vacio = ['tipo' => 'problema', 'texto' => ''];

        // CAMBIO: antes de interpretar se depura el chat (bot, "Sin DPI", nombres
        // sueltos, repetidos, otras peticiones). Ver oficioDepurarChat().
        $t = oficioDepurarChat($mensaje);
        $t = trim(preg_replace('/\s+/u', ' ', $t));
        if ($t === '') return $vacio;

        // --- Datos personales que NUNCA deben ir en el oficio: DPI y teléfonos ---
        $t = preg_replace('/[,;.]?\s*\b(?:y\s+)?(?:mi\s+)?(?:n[uú]mero\s+de\s+)?(?:dpi|cui)\b.*$/iu', '', $t);
        $t = preg_replace('/\b\d[\d\s-]{6,}\d\b/u', ' ', $t);
        $t = preg_replace('/[,;]?\s*(?:mi\s+)?(?:n[uú]mero|tel[eé]fono|celular|cel)\b(?:\s+de\s+(?:contacto|tel[eé]fono|celular))?\s*(?:es|:)?\s*[,.]?\s*$/iu', '', $t);

        // --- Presentaciones: "soy Mario Bros y", "me llamo José Pérez", "mi nombre es Karla" ---
        $t = preg_replace('/[,;]?\s*\b(?i:soy|me llamo|mi nombre es|mi nombre completo es)\s+\p{Lu}[\p{L}\'.-]*(?:\s+(?:(?i:de|del|de la)\s+)?\p{Lu}[\p{L}\'.-]*){0,3}(?:\s*(?:,|(?i:y)\b))?/u', ' ', $t);
        $t = preg_replace('/\b(?:vivo|resido|vivimos|residimos)\s+en\s+/iu', 'en ', $t);
        $t = trim(preg_replace('/\s+/u', ' ', $t));

        // --- Saludos, muletillas y ruegos al inicio ---
        $saludo = '/^[\s,;:.!¡¿?-]*(?:hola|holi|buen[oa]s?(?:\s+(?:d[ií]as?|tardes?|noches?))?|muy\s+buen[oa]s?(?:\s+(?:d[ií]as?|tardes?|noches?))?|saludos|disculpe(?:n)?|perd[oó]n|oiga|ok|okey|pues|bueno|estimad[oa]s?|se[ñn]ores|f[ií]jese(?:\s+que)?|fijese(?:\s+que)?|una\s+consulta|una\s+pregunta|por\s+favor|porfa(?:vor)?|favor)\b[\s,;:.!¡¿?-]*/iu';
        for ($i = 0; $i < 6; $i++) {
            $n = preg_replace($saludo, '', $t, 1);
            if ($n === $t) break;
            $t = $n;
        }

        // --- Ruegos y cortesías al final ---
        $t = preg_replace('/[,;]?\s*\b(?:por\s*favor|porfa(?:vor)?|favor\s+de)\b.*$/isu', '', $t);
        $t = preg_replace('/[,;]?\s*\b(?:muchas\s+)?gracias\b[\s\p{P}]*$/iu', '', $t);

        // AGREGADO: quitar frases de ubicación que ya fueron capturadas como
        // dirección, para no duplicar el lugar en el relato. Caso real:
        // "...hay un agujero en la calle ayer no esta, es por aqui en Villa Canales"
        // → la dirección detectada es "Villa Canales", así que "es por aqui
        // en Villa Canales" debe desaparecer del relato (ya se cita al final).
        $lugarCorto = '(?:[\p{L}\p{N}\'.-]+(?:\s+(?:de|del|la|las|los|y)\s+[\p{L}\p{N}\'.-]+|\s+[\p{L}\p{N}\'.-]+){0,3})';
        // CORRECCIÓN: solo se quita "aquí en <lugar>" si el lugar es corto (hasta 4
        // palabras) y cierra la cláusula; si sigue la descripción, no se toca.
        $t = preg_replace('/[.,;]?\s*\bes\s+por\s+aqu[ií]\s+en\s+' . $lugarCorto . '(?=\s*[.,;]|\s*$)/iu', '', $t);
        $t = preg_replace('/[.,;]?\s*\bes\s+por\s+ac[aá]\s+en\s+' . $lugarCorto . '(?=\s*[.,;]|\s*$)/iu', '', $t);
        $t = preg_replace('/[.,;]?\s*\bes\s+aqu[ií]\s+en\s+' . $lugarCorto . '(?=\s*[.,;]|\s*$)/iu', '', $t);
        $t = preg_replace('/[.,;]?\s*\baqu[ií]\s+en\s+' . $lugarCorto . '(?=\s*[.,;]|\s*$)/iu', '', $t);
        // Y si igual queda un "es por aqui" suelto (sin "en" después), también
        // se limpia porque no aporta información al oficio.
        $t = preg_replace('/[.,;]?\s*\bes\s+por\s+aqu[ií]\b/iu', '', $t);
        $t = preg_replace('/[.,;]?\s*\bes\s+por\s+ac[aá]\b/iu', '', $t);

        // Referencias a la foto / video / captura adjunta: no describen el
        // problema, así que se quitan para que no aparezcan en el oficio.
        $t = preg_replace('/[.,;]?\s*(?:esta|ahi|aqu[ií]|esa)\s+(?:es|va|est[áa])\s+(?:la|una|el|un)\s+(?:foto|imagen|captura|v[ií]deo|pantallazo)\b[^.,;\n]*/iu', '', $t);
        $t = preg_replace('/[.,;]?\s*(?:mando|adjunto|comparto|env[ií]o|les?\s+mando|te\s+mando)\s+(?:la|una|el|un)?\s*(?:foto|imagen|captura|v[ií]deo|pantallazo)\b[^.,;\n]*/iu', '', $t);
        $t = preg_replace('/[.,;]?\s*para\s+que\s+(?:vean?|veas?|ver)\s+(?:la|el)?\s*(?:magnitud|gravedad|situaci[oó]n|problema|asunto)\b[^.,;\n]*/iu', '', $t);
        // Ruego imperativo pegado al final sin coma: "...corto circuito vengan a verlo"
        $t = preg_replace('/\s+(?:vengan|venga|v[eé]nganse)\s+(?:a\s+)?(?:ver|revisar|arreglar|atender|checar|verificar)\w*\b[^,;.]*$/iu', '', $t);
        // "...y molesta mucho podrían venir a verlo" / "...pueden pasar a revisar":
        // ruego sin coma pegado al final del relato. Solo se quita la frase del
        // ruego (verbo + hasta el final de esa cláusula), no lo que siga después.
        $t = preg_replace('/(?<!\bse)(?<!\bno)[,;]?\s+(?:y\s+)?(?:por\s+favor\s+)?(?:podr[ií]a(?:n|s)?|pueden|puede|quisiera\s+que|quisi[eé]ramos\s+que|ser[ií]a\s+bueno\s+que|les?\s+pido\s+que|necesito\s+que|necesitamos\s+que)\s+(?:que\s+)?(?:(?:venir|vengan|pasar|pasen)\s+(?:a|por|para)|revisar|revisen|ver(?:lo|la|los|las)?|verificar|verifiquen|atender|atiendan|ayudarnos|ayudarme|ayudar|hacer\s+algo|hagan\s+algo|resolver|resuelvan|arreglar|arreglen|intervenir|intervengan)\b[^,;.]{0,40}/iu', '', $t);
        $t = preg_replace('/\s+y\s+no\s+(?:tenemos|tienen|sabemos|saben)\s+(?:ni\s+)?idea\b.*$/iu', '', $t);

        // --- Cláusulas que son solo una petición (ya la hace el propio oficio) ---
        $peticion = '/^(?:y\s+)?(?:por\s+(?:lo\s+cual|eso|ello|lo\s+tanto)\s+)?(?:espero|pero\s+bueno|feliz|que\s+tenga|bendiciones|saludos|quedo\s+atent[oa]|de\s+antemano|necesito|necesitamos|queremos|quiero|quisi[eé]ramos|solicito|solicitamos|pedimos|les\s+pido|le\s+pido|ojal[aá]|vengan|venga|arreglen|arregle|resuelvan|resuelva|ayuden|ayuda|apoyen|podr[ií]a(?:n|s)?|pueden|puede|agradezco|gracias|urge|me\s+urge|es\s+urgente|hagan|haga|env[ií]en|env[ií]e|manden|mande|quiten|quite|reparen|repare|solucionen|solucione|que\s+(?:vengan|arreglen|resuelvan|reparen|quiten))\b/iu';
        // Se separa por coma, punto y coma, punto (sin cortar decimales tipo
        // "12.5", ordinales "4a." ni abreviaturas "Av.", "Col.", "Sra.") y saltos
        // de línea, para detectar peticiones que vengan como oración aparte.
        $clausulas = preg_split('/(?<!\d)(?<!\d[aoªº])(?<!\bav)(?<!\bcol)(?<!\bsra)(?<!\bsr)(?<!\bing)(?<!\blic)(?<!\bdr)(?<!\bdra)[.,;](?!\d)|\n+/iu', $t, -1, PREG_SPLIT_NO_EMPTY);
        $mantener = [];
        foreach ($clausulas as $i => $c) {
            $c = trim($c);
            if ($c === '') continue;
            if ($i > 0 && preg_match($peticion, $c)) continue;
            $mantener[] = $c;
        }
        // Primera cláusula que solo dice "en <lugar>" y ya viene en la dirección: se quita (se cita al final)
        if (count($mantener) > 1 && $ubic && preg_match('/^en\s+/iu', $mantener[0]) && oficioUbicacionYaEnTexto($ubic, $mantener[0])
            && oficioPalabras($mantener[0]) <= 7) {
            array_shift($mantener);
        }
        // "Tengo un problema, hay un problema con el agua..." => se quita la primera cláusula vacía
        if (count($mantener) > 1 && preg_match('/^(?:tengo\s+|tenemos\s+)?(?:un|el)\s+problema$/iu', $mantener[0])) {
            array_shift($mantener);
        }
        $t = trim(implode(', ', $mantener), " \t\n\r\0\x0B.,;:-");
        if ($t === '') return $vacio;

        // Interés particular: "quiero denunciar a mi vecino porque quema llantas"
        // debe quedar como "denuncia a su vecino porque quema llantas" (si se
        // quitara el verbo, quedaría "a su vecino porque...", sin sentido).
        if ($particular && preg_match('/^(?:(?:quiero|quisiera|quer[ií]a|deseo|desear[ií]a|necesito|vengo\s+a|me\s+gustar[ií]a)\s+)?(?:denunciar|denuncio)\s+(a\s+.+)$/iu', $t, $mDen)) {
            $t = 'denuncia ' . $mDen[1];
        }

        // --- Fórmulas de entrada: "quiero reportar ...", "reporto ...", "necesito ..." ---
        $tipo = 'problema';
        // CAMBIO: acepta "solo para reportar ..." y verbos como "indicar/manifestar/comentar".
        $t = preg_replace('/^(?:(?:solo|s[oó]lo|solamente|[uú]nicamente)\s+)?(?:(?:para|por\s+este\s+medio)\s+)?(?:(?:quiero|quisiera|quer[ií]a|deseo|desear[ií]a|necesito|vengo\s+a|me\s+gustar[ií]a|queremos|quer[ií]amos|deseamos|quisi[eé]ramos)\s+)?(?:reportar|informar|denunciar|avisar|comunicar|notificar|indicar|manifestar|comentar|exponer|decir)(?:le|les)?\s+(?:que\s+)?/iu', '', $t);
        // NUEVO: "espero mejoren la calidad del agua" => "la necesidad de mejorar la calidad del agua"
        $t = preg_replace('/^espero\s+(?:que\s+)?(?:mejoren|mejore)\s+/iu', 'la necesidad de mejorar ', $t);
        $t = preg_replace('/^(?:reporto|informo|denuncio|aviso|comunico)\s+(?:que\s+)?/iu', '', $t);
        $t = preg_replace('/^(?:quiero|quisiera|quer[ií]a|deseo|necesito|queremos)\s+(?:hacer\s+)?(?:un\s+|una\s+)?(?:reporte|reclamo|aviso|denuncia)\s+(?:de|sobre)\s+/iu', '', $t);

        if (preg_match('/^(?:quer[ií]a|quisiera|quiero|deseo|necesito|me\s+gustar[ií]a|queremos|quer[ií]amos)\s+(?:saber|averiguar|consultar|preguntar|conocer)\s+(.+)$/iu', $t, $m)) {
            $tipo = 'consulta';
            $t = $m[1];
        } elseif (preg_match('/^(?:quer[ií]a|quisiera|quiero|deseo|necesito|me\s+gustar[ií]a|queremos|quer[ií]amos)\s+(?:pedir\s+|solicitar\s+|obtener\s+|recibir\s+)?(?:m[aá]s\s+)?informaci[oó]n\s+(?:sobre|acerca\s+de|de)\s+(.+)$/iu', $t, $m)) {
            $tipo = 'info';
            $t = $m[1];
        } elseif (preg_match('/^(?:necesito|necesitamos|quiero|queremos|quisiera|quisi[eé]ramos|solicito|solicitamos|deseo|deseamos)\s+(?:que\s+)?(.+)$/iu', $t, $m)) {
            $tipo = 'solicitud';
            $t = $m[1];
        } elseif (preg_match('/^(?:a\s+qu[eé]\s+horas?|cu[aá]ndo|qu[eé]\s+d[ií]a|c[oó]mo|d[oó]nde|cu[aá]nto|hasta\s+cu[aá]ndo|para\s+cu[aá]ndo|qu[eé]\s+(?:necesito|requisitos|documentos|horario))\b/iu', $t)) {
            // NUEVO: pregunta directa ("a qué horas regresa el agua", "qué día dan las clases")
            $tipo = 'consulta';
        } elseif (preg_match('/^(?:(?:est(?:oy|amos)|quedo)\s+)?interesad[oa]s?\b/iu', $t)) {
            // NUEVO: "estoy interesada en los cursos" es información, no una petición
            $tipo = 'info';
        }
        $t = preg_replace('/^que\s+/iu', '', $t);

        $t = oficioCorregirOrtografia($t);

        // --- Lenguaje coloquial que no debe ir en un oficio ---
        $t = preg_replace('/\b(?:un|el)\s+bolo\b/iu', 'una persona en estado de ebriedad', $t);
        $t = preg_replace('/\bbolos\b/iu', 'personas en estado de ebriedad', $t);
        $t = preg_replace('/(persona en estado de ebriedad\s+(?:tirad|dormid|acostad|tumbad|ca[ií]d))([oó])\b/iu', '$1a', $t);

        // --- Tiempo relativo: "ayer" se cuenta desde el día del reporte, no desde hoy ---
        // Patrones COMPUESTOS primero (tienen prioridad). Resuelven frases
        // reales como "ayer no esta" → "no se encontraba el día anterior al
        // reporte", que antes quedaban cojas porque "ayer" se reemplazaba
        // suelto y el verbo "no esta" quedaba descolgado.
        $t = preg_replace('/\bayer\s+no\s+esta(ba)?\b/iu', 'no se encontraba el día anterior al reporte', $t);
        $t = preg_replace('/\bhoy\s+no\s+esta(ba)?\b/iu', 'no se encontraba el día del reporte', $t);
        $t = preg_replace('/\bno\s+esta(ba)?\s+(desde\s+)?ayer\b/iu', 'no se encontraba el día anterior al reporte', $t);
        $t = preg_replace('/\bno\s+esta(ba)?\s+(desde\s+)?hoy\b/iu', 'no se encontraba el día del reporte', $t);
        // Reemplazos simples (solo cuando "ayer"/"hoy" aparecen solos).
        $t = preg_replace('/\bdesde\s+ayer\b/iu', 'desde el día anterior al reporte', $t);
        $t = preg_replace('/\bdesde\s+anteayer\b/iu', 'desde dos días antes del reporte', $t);
        $t = preg_replace('/\bayer\b/iu', 'el día anterior al reporte', $t);
        $t = preg_replace('/\bhoy\b/iu', 'el día del reporte', $t);

        // --- Primera persona => tercera persona ---
        $t = preg_replace('/\b(?:aqu[ií]|ac[aá])\b\s*/iu', '', $t);
        $t = preg_replace('/^(?:tengo|tenemos)\s+(?=(?:un|una|unos|unas)\b)/iu', '', $t);
        // --- Verbos de percepción personal: "veo que X", "oí que Y", "me dijeron
        //     que Z". Se eliminan porque dejan el relato en primera persona y no
        //     aportan información al oficio. Con "me" obligatorio en los de
        //     "dijeron/dicen/comentan/contaron": sin él se borraría también
        //     "los vecinos dicen que…", que no es primera persona.
        $t = preg_replace('/\b(?:yo\s+)?veo\s+que\s+/iu', '', $t);
        $t = preg_replace('/\b(?:yo\s+)?o[ií]\s+que\s+/iu', '', $t);
        $t = preg_replace('/\b(?:yo\s+)?not[ée]\s+que\s+/iu', '', $t);
        $t = preg_replace('/\bme\s+(?:dijeron|dicen|comentan|contaron)\s+que\s+/iu', '', $t);
        $t = preg_replace('/\b(?:yo\s+)?puedo\s+ver\s+que\s+/iu', '', $t);
        $t = preg_replace('/\bse\s+puede\s+ver\s+que\s+/iu', '', $t);

        // --- Redundancias de "ya": "ya varias motos ya se cayeron". Se quita el
        //     primer "ya" cuando va seguido de un cuantificador, y si quedan dos
        //     "ya" en una cláusula corta se conserva solo el segundo.
        $t = preg_replace('/\bya\s+(var[ií]as?|much[oa]s?|algun[oa]s?|varios|muchos|algunos)\s+/iu', '$1 ', $t);
        $t = preg_replace('/\bya\s+([^.,;\n]{1,40}?)\s+ya\s+/iu', '$1 ya ', $t);

        $persona = [
            '/\bse\s+nos\b/iu'  => 'se les',
            '/\bnos\b/iu'       => 'les',
            '/\btenemos\b/iu'   => 'tienen',
            '/\bcontamos\b/iu'  => 'cuentan',
            '/\bestamos\b/iu'   => 'están',
            '/\bnecesitamos\b/iu' => 'necesitan',
            '/\bqueremos\b/iu'  => 'quieren',
            '/\bllevamos\b/iu'  => 'llevan',
            '/\bpodemos\b/iu'   => 'pueden',
            '/\bvivimos\b/iu'   => 'viven',
            '/\bsomos\b/iu'     => 'son',
            '/\btengo\b/iu'     => 'tiene',
            '/\bestoy\b/iu'     => 'está',
            '/\bvivo\b/iu'      => 'vive',
            '/\bnecesito\b/iu'  => 'necesita',
            '/\bnuestr([oa])s?\b/iu' => 'su',
            '/\bmis\b/iu'       => 'sus',
            '/\bmi\b/iu'        => 'su',
        ];
        foreach ($persona as $p => $r) $t = preg_replace($p, $r, $t);

        $t = trim(preg_replace('/\s+/u', ' ', $t), " \t\n\r\0\x0B.,;:-");
        $t = preg_replace('/\s+,/u', ',', $t);
        $t = preg_replace('/^en\s+su\s+casa\b/iu', 'en su vivienda', $t);
        $t = preg_replace('/\ben\s+su\s+casa\b/iu', 'en su vivienda', $t);
        $t = preg_replace('/\b(de|del|a)\s+su\s+casa\b/iu', '$1 su vivienda', $t);

        // NUEVO: normalización final de posesivos en referencias de ubicación.
        // El reemplazo general de persona (que ya se aplicó más arriba) no
        // siempre alcanza cuando "mi casa" aparece a mitad de una frase que
        // ya traía una mención previa al mismo lugar.
        $t = preg_replace(
            '/\b(mi|mis|nuestra?|nuestros?|nuestras?)\s+(vivienda|casa|domicilio|residencia|terreno|negocio|propiedad|local)\b/iu',
            'su $2',
            $t
        );

        // NUEVO: deduplicación de la referencia de ubicación. Caso real:
        // "hay un carro enfrente de su vivienda enfrente de mi casa" — el
        // ciudadano (o la mezcla de mensajes) repite la misma idea de
        // lugar dos veces seguidas, separadas por espacio, coma o "y". Se
        // deja una sola mención formal para que no aparezcan frases del
        // tipo "enfrente de su vivienda enfrente de su vivienda".
        $t = preg_replace(
            '/(?:enfrente|frente|al\s+lado|a\s+la\s+par|junto|detr[aá]s|atr[aá]s|cerca)\s+de\s+su\s+(?:vivienda|casa|domicilio|residencia)\s*,?\s+(?:y\s+)?(?:enfrente|frente|al\s+lado|a\s+la\s+par|junto|detr[aá]s|atr[aá]s|cerca)\s+de\s+su\s+(?:vivienda|casa|domicilio|residencia)/iu',
            'enfrente de su vivienda',
            $t
        );

        if ($tipo === 'problema') {
            // "vivo en <lugar> y no hay agua"  =>  "no hay agua" (el lugar se cita al final)
            if ($ubic && preg_match('/^(en\s+[^,]+?)\s+y\s+(.+)$/iu', $t, $m) && oficioPalabras($m[1]) <= oficioPalabras($ubic['crudo']) + 3 && oficioUbicacionYaEnTexto($ubic, $m[1])) {
                $t = $m[2];
            }
            // "en <lugar>, hay/no hay <cosa>"  =>  "<cosa> en <lugar>"
            if (preg_match('/^(en\s+.+?)[,\s]+(no\s+hay|hay)\s+(.+)$/iu', $t, $m) && oficioPalabras($m[1]) <= 14) {
                $t = (strtolower($m[2]) === 'hay') ? ($m[3] . ' ' . $m[1]) : ($m[2] . ' ' . $m[3] . ' ' . $m[1]);
            }
            // "hace dos días no hay luz"  =>  "no hay luz desde hace dos días"
            if (preg_match('/^(hace\s+(?:\p{L}+\s+){1,3}?(?:d[ií]as?|semanas?|horas?|meses|mes))\s+(no\s+hay\s+.+)$/iu', $t, $m)) {
                $t = $m[2] . ' desde ' . $m[1];
            }
            $t = preg_replace('/^hay\s+(?!que\b)/iu', '', $t);
        }

        // Relatos muy largos: se corta en el último límite de cláusula antes de ~420 caracteres
        if (oficioLargo($t) > 420) {
            $corte = preg_match('/^(.{200,420}[,;.])\s/us', $t . ' ', $m) ? $m[1] : (preg_match('/^(.{200,420})\s/us', $t . ' ', $m) ? $m[1] : $t);
            $t = $corte;
        }

        $t = oficioPrimeraMin(trim($t, " \t\n\r\0\x0B.,;:-"));
        return ['tipo' => $tipo, 'texto' => $t];
    }
}

/**
 * Detecta si el relato corresponde a un CONDUCTOR PELIGROSO (exceso de
 * velocidad, ebriedad, manejo temerario), para redactar el oficio en lenguaje
 * formal en vez de repetir la frase coloquial del chat. Exige vehículo +
 * conducta peligrosa, con las MISMAS listas que IA 2/clasificar.php (se
 * omiten a propósito "tomado", "pedo", "contento", "a mil", "a full", que
 * darían falsos positivos).
 */
if (!function_exists('oficioEsConductorPeligroso')) {
function oficioEsConductorPeligroso($texto) {
    $norm = oficioSinAcentos($texto);
    $vehiculo = '(?:vehiculos?|carros?|autos?|automoviles?|motos?|motocicletas?|pasolas?|scooters?|'
        . 'cuatrimotos?|pickup|picop|camionetas?|camion|camiones|trailers?|rastras?|furgon|furgones|'
        . 'cabezal|cabezales|bus|buses|microbus|tuctuc|mototaxis?|taxis?|uber|colectivos?|busetas?|'
        . 'paneles|panel|trocas?|volquetas?|gruas?)';
    $conducta = '(?:a\s+toda\s+velocidad|a\s+exceso\s+de\s+velocidad|exceso\s+de\s+velocidad'
        . '|a\s+gran\s+velocidad|a\s+alta\s+velocidad|a\s+mil\s+por\s+hora|volando|demasiado\s+rapid[oa]'
        . '|muy\s+rapid[oa]|aumentando\s+velocidad'
        . '|bol[oa]s?|borrach[oa]s?|ebri[oa]s?|alcoholizad[oa]s?|en\s+estado\s+de\s+ebriedad'
        . '|bajo\s+los\s+efectos(?:\s+del\s+alcohol)?|con\s+alcohol\s+en\s+la\s+sangre'
        . '|zigzagueando|haciendo\s+piruetas|pisteando|derrapando|quemando\s+llantas?'
        . '|haciendo\s+arrancones|arrancones|maneja\s+mal|conduciendo\s+peligrosamente'
        . '|manejo\s+peligroso|conduccion\s+peligrosa|conductor\s+temerario|manejar\s+temerario)';
    return preg_match('/(?<![a-z0-9])' . $vehiculo . '(?![a-z0-9])/u', $norm) === 1
        && preg_match('/(?<![a-z0-9])' . $conducta . '(?![a-z0-9])/u', $norm) === 1;
}
}

/**
 * Frase formal (tercera persona, lenguaje institucional) para el cuerpo del
 * oficio de un CONDUCTOR PELIGROSO. No usa el texto del chat; solo conserva
 * el tipo de vehículo y la conducta detectada (velocidad y/o ebriedad).
 */
if (!function_exists('oficioFraseFormalConductorPeligroso')) {
function oficioFraseFormalConductorPeligroso($texto) {
    $norm = oficioSinAcentos($texto);
    $tipos = [
        'taxis?' => 'un vehículo tipo taxi',
        'mototaxis?' => 'un mototaxi',
        'motos?|motocicletas?|pasolas?' => 'una motocicleta',
        'cuatrimotos?' => 'un vehículo tipo cuatrimoto',
        'picop|pickup' => 'un vehículo tipo picop',
        'camionetas?' => 'una camioneta',
        'camion|camiones' => 'un camión',
        'trailers?' => 'un vehículo tipo tráiler',
        'buses|bus' => 'un bus',
        'microbus' => 'un microbús',
        'tuctuc' => 'un vehículo tipo tuctuc',
        'colectivos?' => 'un vehículo de transporte colectivo',
        'volquetas?' => 'una volqueta',
        'gruas?' => 'una grúa',
    ];
    $sujeto = 'un vehículo automotor';
    $mejor = null;
    foreach ($tipos as $rx => $frase) {
        if (preg_match('/(?<![a-z0-9])(?:' . $rx . ')(?![a-z0-9])/u', $norm, $m, PREG_OFFSET_CAPTURE)) {
            if ($mejor === null || $m[0][1] < $mejor) { $mejor = $m[0][1]; $sujeto = $frase; }
        }
    }

    $ini = '(?<![a-z0-9])';
    $fin = '(?![a-z0-9])';
    $hayVelocidad = preg_match('/' . $ini . '(?:a\s+toda\s+velocidad|a\s+exceso\s+de\s+velocidad|exceso\s+de\s+velocidad|a\s+alta\s+velocidad|a\s+gran\s+velocidad|a\s+mil\s+por\s+hora|volando|muy\s+rapid[oa]|demasiado\s+rapid[oa]|aumentando\s+velocidad)' . $fin . '/u', $norm) === 1;
    $hayEbriedad = preg_match('/' . $ini . '(?:bol[oa]s?|borrach[oa]s?|ebri[oa]s?|alcoholizad[oa]s?|en\s+estado\s+de\s+ebriedad|bajo\s+los\s+efectos(?:\s+del\s+alcohol)?|con\s+alcohol\s+en\s+la\s+sangre)' . $fin . '/u', $norm) === 1;

    $conductas = [];
    if ($hayVelocidad) $conductas[] = 'circula a exceso de velocidad';
    if ($hayEbriedad) $conductas[] = 'es conducido por una persona que aparentemente se encuentra en estado de ebriedad';
    if (empty($conductas)) $conductas[] = 'es conducido de manera temeraria e imprudente';

    return $sujeto . ' que ' . implode(' y ', $conductas)
        . ', situación que representa un riesgo inminente para la integridad física '
        . 'de los peatones y conductores que transitan por la vía pública';
}
}

/**
 * Convierte el relato limpio en la frase que completa "la cual consiste en ...".
 * Devuelve ['frase' => ..., 'tipo' => 'problema'|'consulta'|'info'|'solicitud'],
 * o null si el mensaje no aporta nada utilizable.
 */
if (!function_exists('oficioFraseProblema')) {
    function oficioFraseProblema($mensaje, $direccion, $particular = false) {
        $ubic = oficioUbicacion($direccion);
        $rel = oficioLimpiarRelato($mensaje, $ubic, $particular);
        $t = $rel['texto'];
        // CAMBIO: mínimo 3 palabras ("al comando" ya no genera oficio).
        if ($t === '' || oficioPalabras($t) < 3 || oficioLargo($t) < 6) return null;

        // NUEVO: interpretación. Si se reconoce el caso (parqueo indebido, falta de
        // agua, calidad del agua, transformador, sin luz) se redacta en lenguaje
        // institucional en vez de copiar el chat, aunque el ciudadano lo haya
        // escrito como pregunta ("quiero saber por qué ...").
        $interp = $particular ? null : oficioInterpretarRelato($t);
        if ($interp !== null) {
            $lugar = $ubic ? $ubic['frase'] : oficioLugarEnTexto($t);
            return ['frase' => trim($interp . ($lugar !== '' ? ' ' . $lugar : '')), 'tipo' => 'problema'];
        }

        $tipo = $rel['tipo'];
        $ubicacionIncluida = false;

        // Nombre del lugar bien escrito dentro del propio relato
        // ("en colonia flor del café" => "en la colonia Flor del Café")
        if ($ubic && oficioUbicacionYaEnTexto($ubic, $t)) {
            $ubicacionIncluida = true;
            $relacional = preg_match('/^(?:enfrente|frente|cerca|junto|detr[aá]s|atr[aá]s|sobre|entre|hacia|por|a\s)/iu', $ubic['crudo']);
            if (!$relacional) {
                $ini = '(?<![\p{L}\p{N}])';
                $fin = '(?![\p{L}\p{N}])';
                $crudoRx = preg_quote($ubic['crudo'], '/');
                $frasePropia = $ubic['frase'];
                $nucleoPropio = $ubic['nucleo'];
                $sustantivoComun = oficioPalabras($ubic['crudo']) === 1 && $ubic['crudo'] === oficioMin($ubic['crudo'])
                    && preg_match('/' . $ini . '(?:el|la|los|las|un|una)\s+' . $crudoRx . $fin . '/iu', $t);
                if (!$sustantivoComun) {
                    $n = 0;
                    $t2 = preg_replace_callback('/' . $ini . 'en\s+(?:(?:el|la|los|las)\s+)?' . $crudoRx . $fin . '/iu',
                        function () use ($frasePropia) { return $frasePropia; }, $t, -1, $n);
                    if ($n === 0) {
                        $t2 = preg_replace_callback('/' . $ini . $crudoRx . $fin . '/iu',
                            function () use ($nucleoPropio) { return $nucleoPropio; }, $t);
                    }
                    $t = $t2;
                }
            }
        }

        if ($tipo === 'consulta') {
            // Reformula el arranque para no encadenar "consulta por que …"
            // ni "consulta que …". Si el relato empieza con una partícula
            // interrogativa de causa ("por que"/"porque"/"por qué"), se
            // reescribe como "el motivo por el cual …". En cualquier otro
            // caso, se introduce con "lo siguiente: ".
            if (preg_match('/^por\s+qu[eé]\s+(.+)$/iu', $t, $m) && trim($m[1]) !== '') {
                $frase = 'que el vecino consulta el motivo por el cual ' . trim($m[1]);
            } else {
                $frase = 'que el vecino consulta lo siguiente: ' . $t;
            }
        } elseif ($tipo === 'info') {
            $frase = 'una solicitud de información sobre ' . $t;
        } elseif ($tipo === 'solicitud') {
            $frase = 'que el vecino solicita ' . $t;
        } else {
            if (preg_match('/^(?:no\s+hay|no\s+tienen|no\s+cuentan\s+con)\s+(.+)$/iu', $t, $m)) {
                $frase = 'la falta de ' . $m[1];
            } else {
                $clausula = preg_match('/^(?:un|una|unos|unas|el|la|los|las)\s+\p{L}+(?:\s+de(?:l)?(?:\s+(?:la|las|los|el))?\s+\p{L}+)?\s+(?:se|no|ya|ha|han|est[aá]n?|qued[oó]|\p{L}{3,}ó|\p{L}{3,}aron|\p{L}{3,}ieron)\b/iu', $t);
                $nominal = !$clausula && preg_match('/^(?:un|una|unos|unas|el|la|los|las|problemas?|falta|fuga|fugas|cables?|ramas?|hoyos?|baches?|bacheo|cunetas?|drenajes?|alcantarillas?|basura|ca[ií]da|derrumbe|deslave|deslizamiento|suministro|acumulaci[oó]n|da[ñn]os?|desperfectos?|obstrucci[oó]n|humo|ruido|olor(?:es)?|mal\s+olor|animales?|perros?|vecinos?|transformador(?:es)?|postes?|focos?|l[aá]mparas?|[aá]rbol(?:es)?|palmeras?)\b/iu', $t);
                $frase = $nominal ? $t : ('que ' . $t);
            }
        }

        if ($ubic && !$ubicacionIncluida) {
            // NUEVO: si el relato ya cerraba con un lugar que la ubicación contiene
            // ("..., en el casco urbano"), se quita para no repetirlo.
            if (preg_match('/^(.*?)[,\s]+en\s+(?:el|la|los|las)\s+([^,]+)$/iu', $frase, $mm)
                && mb_stripos($ubic['frase'], trim($mm[2])) !== false) {
                $frase = $mm[1];
            }
            $frase .= ' ' . $ubic['frase'];
        }

        return ['frase' => $frase, 'tipo' => $tipo];
    }
}

/**
 * Frase de respaldo según la categoría (solo se usa cuando el mensaje del
 * ciudadano no trae información utilizable, p. ej. un emoji).
 */
if (!function_exists('resumenProblemaOficio')) {
function resumenProblemaOficio($categoriaKey, $mensajeOriginal) {
    $texto = oficioMin((string) $mensajeOriginal);

    $patrones = [
        // --- Árboles caídos / riesgos naturales (categoría catastro) ---
        '/\b(arbol|árbol|rama|ramas)\b.{0,30}\b(cay\w*|ca[ií]d[oa]s?|tumbad[oa]s?|derrib\w*|atravesad[oa]s?|obstruyendo|bloqueando)\b/u' => 'un árbol caído que representa un riesgo',
        '/\b(cay\w*|ca[ií]d[oa]s?|tumbad[oa]s?|derrib\w*)\b.{0,30}\b(arbol|árbol|rama|ramas)\b/u' => 'un árbol caído que representa un riesgo',
        '/\bca[ií]da\s+de\s+[aá]rbol\b/u' => 'un árbol caído que representa un riesgo',
        '/\b[aá]rbol\s+se\s+(cay[oó]|call[oó])\b/u' => 'un árbol caído que representa un riesgo',

        // --- Alumbrado público / tendido eléctrico ---
        '/\btransformador/u' => 'una falla en el transformador eléctrico que afecta el suministro de energía',
        '/\bposte.{0,20}(ca[ií]d|inclinad|da[ñn]ad)|cables?\s+(tirad|suelt|ca[ií]d|colgando)/u' => 'un riesgo por poste o cableado eléctrico en mal estado',
        '/\bno hay (luz|energ[ií]a)|se (fue|cort[oó]) la (luz|energ[ií]a)|corte de energ[ií]a|apag[oó]n|sin energ[ií]a el[eé]ctrica/u'
            => 'la falta de suministro de energía eléctrica',
        '/\bl[aá]mpara|foco|poste sin luz|alumbrado p[uú]blico/u'
            => 'una falla en el alumbrado público',
        '/\bno hay agua|se cort[oó] el agua|sin agua potable|falta de agua/u'
            => 'la falta de suministro de agua potable',
        '/\bfuga de agua|tuber[ií]a (rota|reventada)|fuga en la (tuber[ií]a|red)/u'
            => 'una fuga de agua',
        '/\bno (recogen|pasan) la basura|acumulaci[oó]n de basura|basura acumulada|tren de aseo/u'
            => 'acumulación de basura por falta de recolección',
        '/\bbache|hueco en la calle|agujero|calle da[ñn]ada|pavimento da[ñn]ado/u'
            => 'daños en la vía pública (bacheo)',
        '/\bfosa s[eé]ptica|malos olores|contaminaci[oó]n|derrame|quema de basura/u'
            => 'una afectación ambiental',
        '/\brobo|asalto|bolo|esc[aá]ndalo|pelea|disturbio|inseguridad/u'
            => 'un incidente que afecta la seguridad ciudadana',
        '/\btemblor|terremoto|sismo|inundacion|deslave|derrumb\w*/u'
            => 'un evento de origen natural que requiere atención inmediata',
    ];

    foreach ($patrones as $patron => $frase) {
        if (preg_match($patron, $texto)) {
            return $frase;
        }
    }

    $fallbackPorCategoria = [
        'bacheo'               => 'daños en la vía pública que requieren mantenimiento',
        'alumbrado_publico'    => 'una falla en el alumbrado público',
        'fuga_agua'            => 'problemas en el suministro de agua potable',
        'recoleccion_basura'   => 'acumulación de basura y deficiencias en la recolección de desechos',
        'incidentes_seguridad' => 'un incidente que afecta la seguridad ciudadana',
        'gestion_ambiental'    => 'una afectación al medio ambiente',
        'administrativo'       => 'una gestión de tipo administrativo',
        'catastro'             => 'una emergencia o riesgo natural',
        'conflictos_particulares' => 'un conflicto entre vecinos que afecta bienes privados',
        // Categorías informativas (antes caían en el default genérico).
        'informacion_cursos'       => 'una solicitud de información sobre los cursos, talleres y capacitaciones municipales',
        'informacion_actividades'  => 'una solicitud de información sobre las actividades culturales, deportivas y de emprendimiento municipal',
        'informacion_general'      => 'una solicitud de información general sobre los servicios municipales',
        'informacion_ciudadana'    => 'una solicitud de información sobre los servicios municipales',
    ];

    return $fallbackPorCategoria[$categoriaKey] ?? 'una problemática reportada por el ciudadano';
}
}

/**
 * Arma los párrafos del cuerpo de la carta y la línea de cierre.
 *
 * @return array ['p1' => ..., 'p2' => ..., 'cierre' => ...]  (HTML ya escapado)
 */
if (!function_exists('oficioElegirVariante')) {
/**
 * Elige una variante de una lista de forma DETERMINISTA a partir de una semilla
 * (el número de solicitud) y una sal (para que cada elemento del oficio varíe
 * de forma independiente). Así el mismo expediente produce siempre el mismo
 * oficio: nada de textos distintos cada vez que se reimprime.
 */
function oficioElegirVariante(array $opciones, $semilla, $sal = '') {
    if (!$opciones) return '';
    $opciones = array_values($opciones);
    return $opciones[crc32($semilla . '|' . $sal) % count($opciones)];
}
}

if (!function_exists('cuerpoCartaOficio')) {
function cuerpoCartaOficio($sol, $categoriaKey = '', $codigoOficina = null) {
    $e = function ($str) { return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8'); };

    $direccion = trim((string) ($sol['ciudadano']['direccion'] ?? ''));

    // Reporte de INTERÉS PARTICULAR (denuncia o conflicto entre vecinos, o
    // asunto propio de la persona): el oficio lleva el nombre de quien
    // reporta, el reporte y la dirección ("como datos se tiene que es en
    // ..."), y NO lleva teléfono. Ver reportes/generar.php ('interes').
    $esParticular = (($sol['interes'] ?? '') === 'particular');

    // CASO ESPECIAL: conductor peligroso (exceso de velocidad, ebriedad, manejo
    // temerario). El oficio NO repite la frase coloquial del chat: se redacta en
    // lenguaje formal, en tercera persona (ver oficioFraseFormalConductorPeligroso()).
    // Solo aplica a vía pública (o sin categoría) para no afectar otras categorías.
    if (($categoriaKey === '' || $categoriaKey === 'via_publica')
        && oficioEsConductorPeligroso($sol['texto_original'] ?? '')) {
        $tipo = 'problema';
        $descripcion = oficioFraseFormalConductorPeligroso($sol['texto_original'] ?? '');
        $ubic = oficioUbicacion($direccion);
        if ($ubic && !$esParticular) {
            $descripcion .= ' ' . $ubic['frase'];
        }
    } else {
        // Descripción concreta a partir de lo que escribió el ciudadano; si no
        // hay nada utilizable, se cae a la frase genérica de la categoría (y la
        // ubicación se agrega igual, para que el oficio siempre diga dónde es).
        // En interés particular la dirección NO se mezcla con el relato: va
        // aparte, en el segundo párrafo.
        $resultado = oficioFraseProblema($sol['texto_original'] ?? '', $esParticular ? '' : $direccion, $esParticular);
        // NOTA: oficioFraseProblema() YA maneja el caso de que la ubicación venga
        // dentro del relato del ciudadano: solo la anexa al final cuando NO está
        // incluida (ver "$ubicacionIncluida"). No se debe volver a recortar aquí,
        // porque eso dejaba el oficio sin ningún lugar ("un árbol caído.").

        if ($resultado === null) {
            $tipo = 'problema';
            $descripcion = resumenProblemaOficio($categoriaKey, $sol['texto_original'] ?? '');
            $ubic = oficioUbicacion($direccion);
            if ($ubic && !$esParticular) {
                $descripcion .= ' ' . $ubic['frase'];
            }
        } else {
            $tipo = $resultado['tipo'];
            $descripcion = $resultado['frase'];
        }
    }
    $esProblema = ($tipo === 'problema');

    // El número que se cita en el cuerpo de la carta ("solicitud No. ...")
    // es el correlativo PROPIO de la oficina destinataria (ej. "SP0008"),
    // ver tickets/correlativo_oficinas.php. Si la categoría de la petición
    // no tiene ninguna oficina asignada (columna "Oficina (correlativo)"
    // en vistas/config_sistema.php), se usa el número de expediente de
    // siempre como respaldo, para que la carta nunca quede sin número.
    $numeroSolicitudCarta = $codigoOficina ?: ($sol['numero_expediente'] ?? '');

    // Interés particular + problema: "...la cual consiste en que la vecina
    // Dalia López reporta que al lado de su vivienda están quemando basura".
    if ($esParticular && $esProblema) {
        $nombreQuien = oficioNombreVecino($sol['ciudadano']['nombre_completo'] ?? '');
        $generoQuien = $nombreQuien !== '' ? oficioGenero($nombreQuien) : '';
        if ($nombreQuien === '') {
            $quienReporta = 'un vecino';
        } elseif ($generoQuien === 'f') {
            $quienReporta = 'la vecina ' . $nombreQuien;
        } elseif ($generoQuien === 'm') {
            $quienReporta = 'el vecino ' . $nombreQuien;
        } else {
            $quienReporta = $nombreQuien;
        }
        $sinQue = preg_replace('/^que\s+/iu', '', $descripcion);
        if ($resultado !== null && preg_match('/^denuncia\s+a\s/iu', $sinQue)) {
            // "la vecina X denuncia a su vecino porque quema llantas"
            $descripcion = $quienReporta . ' ' . $sinQue;
        } else {
            // Relato en forma de oración ("la vecina Mercedes tira basura..."):
            // se antepone "que" para que lea "reporta que ...". Los relatos que
            // ya son un sustantivo ("la falta de agua", "ruido excesivo") y la
            // frase de respaldo de la categoría quedan igual.
            if ($resultado !== null && $sinQue === $descripcion
                && preg_match('/^(?:el|la|los|las|un|una|unos|unas)\s/iu', $descripcion)
                && !preg_match('/^(?:el|la|los|las|un|una|unos|unas)\s+(?:falta|ruido|olor|humo|problema|quema|acumulaci|contaminaci)/iu', $descripcion)) {
                $descripcion = 'que ' . $descripcion;
            }
            $verbosSeguros = array_slice(OFICIO_REGLAS_ENRIQUECIMIENTO['verbos_reporte'], 0, 5);
            $verboReporte = oficioElegirVariante($verbosSeguros, $numeroSolicitudCarta, 'verbo');
            $descripcion = $quienReporta . ' ' . $verboReporte . ' ' . $descripcion;
        }
    }

    // --- Redacción del párrafo 1: presentación + petición, sin "la cual consiste en" ---
    $descripcionLimpia = oficioCorregirOrtografia(trim($descripcion));
    // NUEVO: contexto de aldea para topónimos conocidos (ver agregarContextoAldea()).
    $descripcionLimpia = agregarContextoAldea($descripcionLimpia);
    if ($esProblema) {
        $descripcionLimpia = preg_replace('/^que\s+/iu', '', $descripcionLimpia);
    }
    // La descripción sigue a "...problemática:" (ver OFICIO_FRASE_CIERRE_MARCO),
    // así que se deja tal cual viene (sin forzar mayúscula): si empieza con un
    // nombre propio conserva su mayúscula y si no, fluye en minúscula.
    $descripcionLimpia = trim($descripcionLimpia, " \t\n\r\0\x0B.,;:-");

    // Frase de cierre según el marco de la categoría (oficio_reglas.php).
    $marcoTipo = OFICIO_MARCO_POR_CATEGORIA[$categoriaKey]
        ?? OFICIO_MARCO_POR_CATEGORIA['_default'];
    $marcoFrase = OFICIO_FRASE_CIERRE_MARCO[$marcoTipo]
        ?? OFICIO_FRASE_CIERRE_MARCO['_default'];

    // Cierre de contexto comunitario: solo en reportes de interés general. En
    // un conflicto entre particulares no aplica ("vulnera los derechos de la
    // comunidad" sería incorrecto). Elección determinista por expediente.
    $cierreContexto = '';
    if ($esProblema && !$esParticular && in_array($marcoTipo, ['problema', 'emergencia', 'requerimiento'], true)) {
        $cierreContexto = '. ' . ucfirst(oficioElegirVariante(
            OFICIO_REGLAS_ENRIQUECIMIENTO['cierres_contexto'], $numeroSolicitudCarta, 'contexto'
        ));
    }

    $parrafo1 = 'De la manera más atenta me dirijo a usted deseándole éxitos en la realización de sus labores diarias. '
        . 'El motivo de la presente es hacerle entrega de la solicitud No. ' . $e($numeroSolicitudCarta) . ', '
        . 'recibida a través de nuestro sistema virtual de la Oficina Municipal de Comunicación y Atención Ciudadana, '
        . $marcoFrase . ' ' . $e($descripcionLimpia) . $cierreContexto . '.';

    // --- Párrafo 2: evidencia, persona de referencia y solicitud de acción ---
    $nombre = oficioNombreVecino($sol['ciudadano']['nombre_completo'] ?? '');
    $genero = $nombre !== '' ? oficioGenero($nombre) : '';
    $telefono = oficioTelefono($sol['ciudadano']['telefono'] ?? '');

    $numFotos = is_array($sol['fotos'] ?? null) ? count(array_filter($sol['fotos'])) : 0;
    if ($esParticular && $esProblema) {
        // Párrafo 2 de un reporte particular: fotografías (o verificación en
        // sitio) + "como datos se tiene que es en <dirección>" + solicitud
        // de acción. Sin teléfono ni persona de referencia: el nombre de
        // quien reporta ya va en el párrafo 1.
        if ($numFotos === 1) {
            $parrafo2 = 'Para lo cual se adjunta una fotografía del lugar, proporcionada por el vecino, en donde se aprecia la situación descrita';
        } elseif ($numFotos > 1) {
            $parrafo2 = 'Para lo cual se adjuntan fotografías del lugar, proporcionadas por el vecino, en donde se aprecia la situación descrita';
        } else {
            $parrafo2 = 'Para lo cual se solicita efectuar una verificación en el sitio a fin de atender la situación descrita';
        }
        $ubicParticular = oficioUbicacion($direccion);
        if ($ubicParticular) {
            $parrafo2 .= ', y se tiene como referencia la siguiente ubicación: ' . $e($ubicParticular['frase']);
        }
        $nombre = '';
        $telefono = '';
    } elseif (!$esProblema) {
        $parrafo2 = 'Para lo cual se solicita brindar la atención que corresponda al vecino peticionario';
    } elseif ($numFotos === 1) {
        $parrafo2 = 'Para lo cual se adjunta una fotografía del lugar, proporcionada por el vecino, en donde se aprecia la situación descrita';
    } elseif ($numFotos > 1) {
        $parrafo2 = 'Para lo cual se adjuntan fotografías del lugar, proporcionadas por el vecino, en donde se aprecia la situación descrita';
    } else {
        $parrafo2 = 'Para lo cual se solicita efectuar una inspección en el sitio a fin de atender la situación descrita';
    }

    if ($esParticular && $esProblema) {
        // (sin persona de referencia ni teléfono en interés particular)
    } elseif ($nombre !== '') {
        $tratamiento = $genero === 'f' ? 'doña ' : ($genero === 'm' ? 'don ' : '');
        $accion = $esProblema ? 'el reporte' : 'la consulta';
        $quien = $genero === 'f' ? 'la vecina que realizó ' . $accion : ($genero === 'm' ? 'el vecino que realizó ' . $accion : 'quien realizó ' . $accion);
        // CAMBIO: el oficio NO debe llevar números de teléfono.
        $parrafo2 .= '. Como persona de referencia puede consultarse con ' . $e($tratamiento . $nombre) . ', ' . $quien;
    }
    // (eliminado: "Como contacto de referencia se proporciona el teléfono ...")

    $urgencia = $sol['urgencia'] ?? 'baja';
    // Conector de transición (determinista) + refuerzo institucional.
    $conector = ucfirst(oficioElegirVariante(
        OFICIO_REGLAS_ENRIQUECIMIENTO['conectores_transicion'], $numeroSolicitudCarta, 'conector'
    ));
    $parrafo2 = rtrim($parrafo2, '. ');
    $parrafo2 .= ($urgencia === 'alta')
        ? '. ' . $conector . ', se sugiere atender el presente requerimiento con carácter de urgencia, '
            . 'dado que la situación reportada puede agravarse con el paso del tiempo y representa un riesgo '
            . 'para la integridad de los vecinos.'
        : '. ' . $conector . ', se sugiere atender el presente requerimiento a la brevedad posible, '
            . 'a fin de garantizar el restablecimiento de las condiciones adecuadas para la comunidad.';

    $cierre = oficioElegirVariante(OFICIO_REGLAS_ENRIQUECIMIENTO['cierres_formales'], $numeroSolicitudCarta, 'cierre');

    return ['p1' => $parrafo1, 'p2' => $parrafo2, 'cierre' => $cierre];
}
}

/**
 * Página de anexo con las fotografías que el ciudadano envió por WhatsApp
 * (whatsapp/recibir_bot.php las descarga a whatsapp/fotos/ y
 * tickets/crear.php copia las rutas dentro del ticket). Se imprime en una
 * hoja aparte después de la carta, como constancia fotográfica del
 * expediente.
 */
if (!function_exists('renderAnexoFotos')) {
function renderAnexoFotos($fotos, $numeroExpediente) {
    $e = function ($str) { return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8'); };
    $fotos = is_array($fotos) ? array_values(array_filter($fotos)) : [];
    if (empty($fotos)) return '';

    // 4 fotografías por hoja (2x2). Si hay más, se abren hojas adicionales.
    $porPagina = 4;
    $paginas = array_chunk($fotos, $porPagina);
    $totalPaginas = count($paginas);
    $totalFotos = count($fotos);

    ob_start();
    foreach ($paginas as $idxPagina => $grupo) {
        ?>
        <div class="carta-pagina anexo-pagina">
            <div class="anexo-titulo">
                Anexo — Fotografías del problema reportado
                <?php if ($totalPaginas > 1): ?>
                    <span style="font-size:11px;font-weight:normal;color:var(--texto-suave);">
                        (página <?= ($idxPagina + 1) . ' de ' . $totalPaginas ?> · <?= $totalFotos ?> fotos en total)
                    </span>
                <?php endif; ?>
            </div>
            <div class="anexo-sub">Expediente <?= $e($numeroExpediente) ?> · Fotografías recibidas junto con la petición ciudadana</div>
            <div class="anexo-grid">
                <?php foreach ($grupo as $i => $ruta): ?>
                    <?php $numGlobal = ($idxPagina * $porPagina) + $i + 1; ?>
                    <div class="anexo-foto-caja" data-tipo="whatsapp" data-ruta="<?= $e($ruta) ?>">
                        <div class="anexo-foto-toolbar no-print" contenteditable="false">
                            <button type="button" class="anexo-btn-herramienta anexo-btn-centrar"  title="Centrar imagen">⤢</button>
                            <button type="button" class="anexo-btn-herramienta anexo-btn-achicar"  title="Achicar">➖</button>
                            <button type="button" class="anexo-btn-herramienta anexo-btn-agrandar" title="Agrandar">➕</button>
                            <button type="button" class="anexo-btn-herramienta anexo-btn-borrar"   title="Quitar de esta hoja">🗑️</button>
                        </div>
                        <img src="/<?= $e(ltrim($ruta, '/')) ?>" alt="Fotografía adjunta a la solicitud">
                        <div class="anexo-foto-num">Fotografía <?= $numGlobal ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
    return ob_get_clean();
}
}

/**
 * Página de anexo con la(s) captura(s) reales de la conversación de
 * WhatsApp, subidas a mano por el usuario desde reportes/imprimir.php
 * (botón "📎 Adjuntar captura de Chat" → reportes/subir_captura.php →
 * reportes/capturas_chat.json). Son capturas reales de la interfaz de
 * WhatsApp Web tomadas por la persona que atiende, no una simulación.
 *
 * Se llama por separado desde reportes/generar.php (NO dentro de
 * generarHTMLOficio()) para que las capturas subidas después de una
 * edición manual del oficio (ver reportes/guardar_edicion.php) sigan
 * apareciendo siempre actualizadas, sin quedar "congeladas" dentro del
 * HTML editado.
 *
 * Cada captura trae, además de 'ruta', un tamaño ('pequena' | 'mediana' |
 * 'grande') y un flag opcional 'centrada' (ver
 * reportes/redimensionar_captura.php) que controlan las clases CSS de la
 * caja. Se pagina de a 4 por hoja. Se agrega también una barra de
 * herramientas por imagen (centrar / recortar de nuevo / achicar / agrandar / borrar) que solo se
 * muestra cuando reportes/imprimir.php activa el modo "✏️ Editar" (clase
 * "anexo-modo-edicion" en el contenedor #anexo-capturas-chat) y que nunca
 * se imprime (clase "no-print").
 */
if (!function_exists('renderAnexoCapturasChat')) {
function renderAnexoCapturasChat($capturas, $numeroExpediente) {
    $e = function ($str) { return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8'); };
    $capturas = is_array($capturas) ? array_values(array_filter($capturas)) : [];
    if (empty($capturas)) return '';

    $tamanosValidos = ['pequena', 'mediana', 'grande'];
    // 4 capturas por hoja (2x2). Si hay más, se abren hojas adicionales.
    $porPagina = 4;
    $paginas = array_chunk($capturas, $porPagina);
    $totalPaginas = count($paginas);
    $totalCapturas = count($capturas);

    ob_start();
    foreach ($paginas as $idxPagina => $grupo) {
        ?>
        <div class="carta-pagina anexo-pagina">
            <div class="anexo-titulo">
                Anexo — Captura(s) de la conversación de WhatsApp y Evidencias
                <?php if ($totalPaginas > 1): ?>
                    <span style="font-size:11px;font-weight:normal;color:var(--texto-suave);">
                        (página <?= ($idxPagina + 1) . ' de ' . $totalPaginas ?> · <?= $totalCapturas ?> capturas en total)
                    </span>
                <?php endif; ?>
            </div>
            <div class="anexo-grid">
                <?php foreach ($grupo as $i => $captura): ?>
                    <?php
                    $ruta = is_array($captura) ? ($captura['ruta'] ?? '') : $captura;
                    if ($ruta === '') continue;
                    $tamano = is_array($captura) ? ($captura['tamano'] ?? 'mediana') : 'mediana';
                    if (!in_array($tamano, $tamanosValidos, true)) $tamano = 'mediana';
                    $centrada = is_array($captura) ? !empty($captura['centrada']) : false;
                    $numGlobal = ($idxPagina * $porPagina) + $i + 1;
                    $clases = 'anexo-foto-caja anexo-tam-' . $tamano;
                    if ($centrada) $clases .= ' anexo-foto-centrada';
                    ?>
                    <div class="<?= $e($clases) ?>" data-ruta="<?= $e($ruta) ?>" data-tamano="<?= $e($tamano) ?>" data-centrada="<?= $centrada ? '1' : '0' ?>">
                        <div class="anexo-foto-toolbar no-print">
                            <button type="button" class="anexo-btn-herramienta anexo-btn-centrar"  title="Centrar imagen">⤢</button>
                            <button type="button" class="anexo-btn-herramienta anexo-btn-recortar" title="Recortar de nuevo">✂️</button>
                            <button type="button" class="anexo-btn-herramienta anexo-btn-achicar"  title="Achicar">➖</button>
                            <button type="button" class="anexo-btn-herramienta anexo-btn-agrandar" title="Agrandar">➕</button>
                            <button type="button" class="anexo-btn-herramienta anexo-btn-borrar"   title="Eliminar">🗑️</button>
                        </div>
                        <img src="/<?= $e(ltrim($ruta, '/')) ?>" alt="Captura de la conversación de WhatsApp">
                        <div class="anexo-foto-num">Captura <?= $numGlobal ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
    return ob_get_clean();
}
}

/**
 * Anexo único de evidencia del expediente: mezcla las fotografías que el
 * ciudadano envió por WhatsApp (rutas dentro de whatsapp/fotos/) con las
 * capturas de la conversación subidas a mano (reportes/capturas_chat.json)
 * en un mismo grid 2x2 por hoja, paginado de a 4 imágenes.
 *
 * Encabezado (siempre el mismo, sin subtítulo):
 *   "Anexo — Captura(s) de la conversación de WhatsApp y Evidencias"
 *
 * Cada celda lleva data-origen="whatsapp"|"chat" para que la barra de
 * herramientas (reportes/imprimir.php) sepa a qué endpoint llamar:
 *   · whatsapp -> borrar_foto_ticket.php (centrar y tamaño son solo
 *                 efecto visual de esa vista, no persisten)
 *   · chat     -> redimensionar_captura.php / borrar_captura.php /
 *                 subir_captura.php (todo persistente, incluido el recorte)
 */
if (!function_exists('renderAnexoEvidencia')) {
function renderAnexoEvidencia($sol, $numeroExpediente, $tituloPersonalizado = null) {
    $e = function ($str) { return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8'); };

    // Ajustes visuales guardados para las FOTOGRAFÍAS de WhatsApp de este
    // ticket (tamaño, centrado y posición), escritos por
    // reportes/ajustar_foto_ticket.php. Si el archivo aún no existe, todas
    // las fotos usan los valores por defecto (mediana, sin centrar, sin
    // desplazamiento).
    $ajustesFotosWhatsApp = [];
    $rutaAjustes = __DIR__ . '/anexo_fotos_ajustes.json';
    if (file_exists($rutaAjustes)) {
        $datosAjustes = json_decode(file_get_contents($rutaAjustes), true);
        if (is_array($datosAjustes) && isset($datosAjustes[(string) ($sol['ticket_id'] ?? '')])) {
            $ajustesFotosWhatsApp = $datosAjustes[(string) $sol['ticket_id']];
        }
    }

    // Títulos personalizados de cada imagen ("Fotografía N"/"Captura N"
    // editados por el usuario en modo "✏️ Editar"), escritos por
    // reportes/anexo_titulos.php.
    $titulosCustom = [];
    $rutaTitulos = __DIR__ . '/anexo_titulos.json';
    if (file_exists($rutaTitulos)) {
        $datosTitulos = json_decode(file_get_contents($rutaTitulos), true);
        $claveTitulos = (string) ($sol['ticket_id'] ?? '');
        if (is_array($datosTitulos) && isset($datosTitulos[$claveTitulos]) && is_array($datosTitulos[$claveTitulos])) {
            $titulosCustom = $datosTitulos[$claveTitulos];
        }
    }

    // 1. Combinar fotos de WhatsApp y capturas del chat en un solo listado
    $items = [];
    $tamanosValidos = ['pequena', 'mediana', 'grande'];

    $fotosWhatsApp = is_array($sol['fotos'] ?? null) ? array_values(array_filter($sol['fotos'])) : [];
    foreach ($fotosWhatsApp as $foto) {
        $ruta = is_array($foto) ? ($foto['ruta'] ?? '') : $foto;
        if ($ruta === '') continue;
        $ajuste = is_array($ajustesFotosWhatsApp[$ruta] ?? null) ? $ajustesFotosWhatsApp[$ruta] : [];
        $tamano = $ajuste['tamano'] ?? 'mediana';
        if (!in_array($tamano, $tamanosValidos, true)) $tamano = 'mediana';
        $items[] = [
            'origen'   => 'whatsapp',
            'ruta'     => $ruta,
            'tamano'   => $tamano,
            'centrada' => !empty($ajuste['centrada']),
            'offsetX'  => intval($ajuste['offsetX'] ?? 0),
            'offsetY'  => intval($ajuste['offsetY'] ?? 0),
        ];
    }

    $capturasChat = is_array($sol['capturas_chat'] ?? null) ? array_values(array_filter($sol['capturas_chat'])) : [];
    foreach ($capturasChat as $captura) {
        $ruta = is_array($captura) ? ($captura['ruta'] ?? '') : $captura;
        if ($ruta === '') continue;
        $tamano = is_array($captura) ? ($captura['tamano'] ?? 'mediana') : 'mediana';
        if (!in_array($tamano, $tamanosValidos, true)) $tamano = 'mediana';
        $items[] = [
            'origen'   => 'chat',
            'ruta'     => $ruta,
            'tamano'   => $tamano,
            'centrada' => is_array($captura) ? !empty($captura['centrada']) : false,
            'offsetX'  => is_array($captura) ? intval($captura['offsetX'] ?? 0) : 0,
            'offsetY'  => is_array($captura) ? intval($captura['offsetY'] ?? 0) : 0,
        ];
    }

    if (empty($items)) return '';

    // 1.b Aplicar el ORDEN guardado por el usuario (arrastrar-para-intercambiar
    //     de reportes/imprimir.php, ver reportes/anexo_orden.php). Sin orden
    //     guardado se respeta el orden por defecto (fotos de WhatsApp primero,
    //     luego capturas del chat).
    $rutaOrden = __DIR__ . '/anexo_orden.json';
    if (file_exists($rutaOrden)) {
        $datosOrden = json_decode(file_get_contents($rutaOrden), true);
        $claveOrden = (string) ($sol['ticket_id'] ?? '');
        if (is_array($datosOrden) && !empty($datosOrden[$claveOrden]) && is_array($datosOrden[$claveOrden])) {
            $itemsPorClave = [];
            foreach ($items as $it) {
                $itemsPorClave[$it['origen'] . '|' . $it['ruta']] = $it;
            }
            $itemsReordenados = [];
            foreach ($datosOrden[$claveOrden] as $ord) {
                $k = ($ord['origen'] ?? '') . '|' . ($ord['ruta'] ?? '');
                if (isset($itemsPorClave[$k])) {
                    $itemsReordenados[] = $itemsPorClave[$k];
                    unset($itemsPorClave[$k]);
                }
            }
            // Los que no estaban en el orden guardado (p. ej. una foto nueva)
            // van al final, conservando su orden relativo original.
            foreach ($itemsPorClave as $it) {
                $itemsReordenados[] = $it;
            }
            $items = $itemsReordenados;
        }
    }

    // 2. Encabezado único para toda la evidencia del expediente (editable
    //    desde "✏️ Editar"; el título guardado llega en $tituloPersonalizado)
    $titulo = ($tituloPersonalizado !== null && trim((string) $tituloPersonalizado) !== '')
        ? trim((string) $tituloPersonalizado)
        : 'Anexo — Captura(s) de la conversación de WhatsApp y Evidencias';

    // 3. Numeración por tipo (Fotografía N / Captura N), respetando los
    //    títulos personalizados que el usuario haya guardado.
    $nWhatsapp = 0;
    $nChat = 0;
    foreach ($items as &$item) {
        if ($item['origen'] === 'whatsapp') {
            $nWhatsapp++;
            $porDefecto = 'Fotografía ' . $nWhatsapp;
        } else {
            $nChat++;
            $porDefecto = 'Captura ' . $nChat;
        }
        $claveItem = $item['origen'] . '|' . $item['ruta'];
        if (isset($titulosCustom[$claveItem]) && trim((string) $titulosCustom[$claveItem]) !== '') {
            $item['etiqueta'] = trim((string) $titulosCustom[$claveItem]);
            $item['titulo_custom'] = true;
        } else {
            $item['etiqueta'] = $porDefecto;
            $item['titulo_custom'] = false;
        }
    }
    unset($item);

    // 4. Paginación respetando el TAMAÑO elegido. El grid es de 2 columnas:
    //    pequeña/mediana ocupan media fila; grande ocupa la fila completa.
    //    Se simula el acomodo por filas y se pasa a otra hoja cuando el alto
    //    acumulado supera el espacio útil de la hoja carta (o ya hay 4
    //    imágenes). Resultado: hasta 4 medianas/pequeñas por hoja, o hasta
    //    2 grandes por hoja; la impresión nunca se desborda.
    //    IMPORTANTE: estas alturas (cm: imagen + borde + pie de foto) y el
    //    presupuesto deben coincidir con oficio.css y con reacomodarAnexo()
    //    en imprimir.php.
    // Valores en cm: altura real de cada celda (imagen + padding + rótulo) =
    // alto de la imagen en oficio.css + 0.8. Ver también ALTO_CELDA en imprimir.php.
    $altoCelda   = ['pequena' => 5.3, 'mediana' => 10.3, 'grande' => 10.5];
    $presupuesto = 21.3;   // cm útiles bajo membrete + título (27.94 - 3.0 de padding - ~3.6 de cabecera)
    $gapFilas    = 0.22;   // 8 px
    $maxPorHoja  = 4;

    $altoTotal = function ($filas) use ($gapFilas) {
        $t = 0;
        foreach ($filas as $f) $t += $f['h'];
        return $t + $gapFilas * max(0, count($filas) - 1);
    };
    $colocar = function ($filas, $item) use ($altoCelda) {
        $h = $altoCelda[$item['tamano']] ?? $altoCelda['mediana'];
        $grande = ($item['tamano'] === 'grande');
        $n = count($filas);
        if (!$grande && $n > 0 && !$filas[$n - 1]['llena']) {
            $filas[$n - 1]['llena'] = true;
            $filas[$n - 1]['h'] = max($filas[$n - 1]['h'], $h);
        } else {
            $filas[] = ['h' => $h, 'llena' => $grande];
        }
        return $filas;
    };

    $paginas = [];
    $paginaActual = [];
    $filas = [];

    foreach ($items as $item) {
        $probables = $colocar($filas, $item);
        $excede = !empty($paginaActual)
            && ($altoTotal($probables) > $presupuesto || count($paginaActual) + 1 > $maxPorHoja);
        if ($excede) {
            $paginas[] = $paginaActual;
            $paginaActual = [];
            $probables = $colocar([], $item);
        }
        $paginaActual[] = $item;
        $filas = $probables;
    }
    if (!empty($paginaActual)) $paginas[] = $paginaActual;

    $totalPaginas = count($paginas);
    $totalItems   = count($items);

    ob_start();
    foreach ($paginas as $idxPagina => $grupo) {
        $claseContinuacion = ($idxPagina === 0) ? '' : ' anexo-pagina-continuacion';
        ?>
        <div class="carta-pagina anexo-pagina<?= $claseContinuacion ?>" data-pagina-anexo="<?= $idxPagina + 1 ?>">
            <!-- Encabezado institucional, repetido en cada hoja del anexo.
                 Logos a los lados; a partir de la segunda hoja el CSS los
                 reduce al 50% (.anexo-pagina-continuacion en oficio.css),
                 pero el texto conserva el mismo tamaño. -->
            <div class="anexo-membrete">
                <img class="anexo-membrete-logo" src="../assets/img/Logo%20San%20Pablo.PNG" alt="Logo Municipalidad">
                <div class="anexo-membrete-centro">
                    <div class="anexo-membrete-linea1">Municipalidad de San Pablo Jocopilas</div>
                    <div class="anexo-membrete-linea2">Departamento de Suchitepéquez, Guatemala, C.A.</div>
                    <div class="anexo-membrete-linea3">Oficina de Atención y Comunicación Ciudadana</div>
                </div>
                <img class="anexo-membrete-logo" src="../assets/img/escudo.jpg" alt="Escudo de Guatemala">
            </div>
            <div class="anexo-membrete-regla"></div>

            <div class="anexo-titulo">
                <span class="anexo-titulo-texto"><?= $e($titulo) ?></span>
            </div>
            <div class="anexo-grid">
                <?php foreach ($grupo as $item): ?>
                    <?php
                    $clases = 'anexo-foto-caja anexo-tam-' . $item['tamano'];
                    if ($item['centrada']) $clases .= ' anexo-foto-centrada';
                    ?>
                    <div class="<?= $e($clases) ?>"
                         data-origen="<?= $e($item['origen']) ?>"
                         data-ruta="<?= $e($item['ruta']) ?>"
                         data-tamano="<?= $e($item['tamano']) ?>"
                         data-centrada="<?= $item['centrada'] ? '1' : '0' ?>">
                        <div class="anexo-foto-toolbar no-print">
                            <button type="button" class="anexo-btn-herramienta anexo-btn-centrar"  title="Centrar imagen">⤢</button>
                            <?php if ($item['origen'] === 'chat'): ?>
                                <button type="button" class="anexo-btn-herramienta anexo-btn-recortar" title="Recortar de nuevo">✂️</button>
                            <?php endif; ?>
                            <button type="button" class="anexo-btn-herramienta anexo-btn-achicar"  title="Achicar">➖</button>
                            <button type="button" class="anexo-btn-herramienta anexo-btn-agrandar" title="Agrandar">➕</button>
                            <button type="button" class="anexo-btn-herramienta anexo-btn-borrar"   title="Eliminar">🗑️</button>
                        </div>
                        <img src="/<?= $e(ltrim($item['ruta'], '/')) ?>" alt="<?= $e($item['etiqueta']) ?>">
                        <div class="anexo-foto-num"<?= !empty($item['titulo_custom']) ? ' data-titulo-custom="1"' : '' ?>><?= $e($item['etiqueta']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
    return ob_get_clean();
}
}

/**
 * Quita de un HTML de oficio YA GUARDADO (reportes/oficios_editados.json)
 * las hojas de anexo que se guardaron embebidas antes de que el anexo
 * pasara a #anexo-evidencia. Sin esto, un oficio editado antes de este
 * cambio mostraría las fotografías dos veces (la copia congelada dentro
 * del HTML guardado + el anexo nuevo). Trabaja por conteo de <div> para
 * no alterar ni un byte del resto del HTML; si no hay anexos, lo devuelve igual.
 */
if (!function_exists('oficioQuitarAnexosEmbebidos')) {
function oficioQuitarAnexosEmbebidos($html) {
    if (!is_string($html) || strpos($html, 'anexo-pagina') === false) return $html;

    $patronInicio = '/<div\b[^>]*\bclass\s*=\s*"[^"]*\banexo-pagina\b[^"]*"[^>]*>/i';
    for ($guardia = 0; $guardia < 50; $guardia++) {
        if (!preg_match($patronInicio, $html, $m, PREG_OFFSET_CAPTURE)) break;
        $ini = $m[0][1];
        $pos = $ini + strlen($m[0][0]);
        $profundidad = 1;
        while ($profundidad > 0 && preg_match('/<(\/?)div\b[^>]*>/i', $html, $t, PREG_OFFSET_CAPTURE, $pos)) {
            $profundidad += ($t[1][0] === '/') ? -1 : 1;
            $pos = $t[0][1] + strlen($t[0][0]);
        }
        if ($profundidad > 0) break; // HTML desbalanceado: no se toca
        $html = substr($html, 0, $ini) . substr($html, $pos);
    }
    return $html;
}
}

/**
 * Cargo del destinatario tal como aparece en los oficios reales
 * ("DIRECTORA DE SERVICIOS PÚBLICOS"): corrige acentos de nombres de
 * departamento comunes y antepone "Director(a) de" cuando el departamento
 * es solo el nombre de un área (y no ya un cargo o una unidad).
 */
if (!function_exists('oficioCargoDestinatario')) {
function oficioCargoDestinatario($dep, $nombreDirector) {
    $dep = trim(preg_replace('/\s+/u', ' ', (string) $dep));

    $acentos = [
        'publico' => 'público', 'publicos' => 'públicos', 'publica' => 'pública', 'publicas' => 'públicas',
        'gestion' => 'gestión', 'direccion' => 'dirección', 'secretaria' => 'secretaría', 'transito' => 'tránsito',
        'informacion' => 'información', 'comunicacion' => 'comunicación', 'atencion' => 'atención',
        'administracion' => 'administración', 'educacion' => 'educación', 'alcaldia' => 'alcaldía',
        'tesoreria' => 'tesorería', 'coordinacion' => 'coordinación', 'policia' => 'policía',
        'saneamiento' => 'saneamiento', 'basicos' => 'básicos', 'ambito' => 'ámbito',
    ];
    $dep = preg_replace_callback('/\p{L}+/u', function ($m) use ($acentos) {
        $k = oficioSinAcentos($m[0]);
        if (!isset($acentos[$k])) return $m[0];
        return ($m[0] === oficioPrimeraMay($m[0]) && $m[0] !== oficioMin($m[0])) ? oficioPrimeraMay($acentos[$k]) : $acentos[$k];
    }, $dep);

    $yaEsCargoOUnidad = '/^(?:direccion|unidad|oficina|departamento|juzgado|juez|jueza|secretari|catastro|alcald|gerencia|gerente|tesorer|coordinaci|coordinador|jefatura|jefe|jefa|policia|encargad|municipalidad|concejo|registro|comision|area|director|directora|regidor|sindico|procurador|delegad)/u';
    $sinTitulo = trim(preg_replace('/^(?:lcda?\.?|licda?\.?|lic\.?|ing\.?|inga\.?|dra?\.?|arq\.?)\s+/iu', '', (string) $nombreDirector));
    $hayNombre = $sinTitulo !== '' && oficioSinAcentos($sinTitulo) !== 'encargado de departamento';

    if ($hayNombre && !preg_match($yaEsCargoOUnidad, oficioSinAcentos($dep))) {
        $g = oficioGenero($nombreDirector);
        $dep = ($g === 'f' ? 'Directora' : ($g === 'm' ? 'Director' : 'Director(a)')) . ' de ' . $dep;
    }
    return $dep;
}
}

if (!function_exists('generarHTMLOficio')) {
function generarHTMLOficio($sol, $cla) {
    $e = function ($str) { return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8'); };

    $numeroOficio = obtenerNumeroOficio($sol['ticket_id'] ?? $sol['numero_expediente']);
    $codigoOficina = obtenerCodigoOficinaOficio($sol['ticket_id'] ?? $sol['numero_expediente'], $sol['oficina_id'] ?? null);
    $categoriaKey = $cla['categoria'] ?? '';
    $parrafos = cuerpoCartaOficio($sol, $categoriaKey, $codigoOficina);

    $nombreDirector = trim((string) ($sol['departamento']['director'] ?? '')) ?: 'Encargado de Departamento';
    $depDirector    = trim((string) ($sol['departamento']['nombre'] ?? '')) ?: 'Municipalidad de San Pablo Jocopilas';
    $cargoDirector  = oficioCargoDestinatario($depDirector, $nombreDirector);

    ob_start();
    ?>
    <div class="carta-pagina">
        <!-- Membrete institucional con logo -->
        <div class="carta-membrete" style="position:relative; min-height:135px; display:flex; align-items:center; justify-content:center;">
            <img src="../assets/img/Logo San Pablo.png" alt="Logo Municipalidad" style="position:absolute; left:0; top:50%; transform:translateY(-50%); height:135px; object-fit:contain;">
            <div class="membrete-institucion" style="text-align:center;">
                <div class="membrete-linea1">Municipalidad de San Pablo Jocopilas</div>
                <div class="membrete-linea2">Departamento de Suchitepéquez, Guatemala, C.A.</div>
                <div class="membrete-linea3">Oficina de Atención y Comunicación Ciudadana</div>
            </div>
        </div>
        <div class="carta-regla"></div>

        <!-- Lugar, fecha y número de oficio -->
        <div class="carta-fecha-bloque">
            <div>San Pablo Jocopilas, Suchitepéquez, <?= $e($sol['fecha_formato']) ?></div>
            <div class="carta-num-oficio">Oficio No. <?= (int) $numeroOficio ?>-ACC</div>
        </div>

        <!-- Destinatario -->
        <div class="carta-destinatario">
            <div class="carta-dest-nombre"><?= $e($nombreDirector) ?></div>
            <div class="carta-dest-cargo"><?= $e(oficioMay($cargoDirector)) ?></div>
        </div>

        <div class="carta-saludo">Estimado (a) director (a):</div>

        <!-- Cuerpo -->
        <div class="carta-cuerpo">
            <p><?= $parrafos['p1'] ?></p>
            <p><?= $parrafos['p2'] ?></p>
            <p><?= $parrafos['cierre'] ?></p>
        </div>

        <div class="carta-cierre">Atentamente,</div>

        <div class="carta-firma">
            <div class="carta-firma-linea"></div>
            <div class="carta-firma-nombre">Lcda. María Fernanda Zaldaña Martínez</div>
            <div class="carta-firma-cargo">Atención y Comunicación Ciudadana</div>
        </div>

        <div class="carta-footer">
            <div>¡EN DIOS CONFIAMOS!</div>
            <div>ADMINISTRACIÓN MUNICIPAL, 2024-2028</div>
        </div>
    </div>

    <?php
    // El anexo de evidencia (fotografías de WhatsApp + capturas del chat)
    // ya NO se incluye aquí. Vive aparte, en #anexo-evidencia, y lo arma
    // renderAnexoEvidencia() fuera del HTML editable del oficio (ver
    // reportes/generar.php), de modo que siempre se recalcula fresco desde
    // tickets.json + capturas_chat.json y fotos y capturas comparten un
    // mismo grid 2x2 por hoja.
    return ob_get_clean();
}
}
