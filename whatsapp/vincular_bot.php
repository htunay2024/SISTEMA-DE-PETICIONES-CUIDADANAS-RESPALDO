<?php
/**
 * whatsapp/vincular_bot.php
 *
 * Endpoint AJAX (solo administradores) usado por vistas/whatsapp_admin.php
 * para controlar la vinculación de WhatsApp "a voluntad" y el modo prueba:
 *
 *   POST vincular_bot.php   { "accion": "vincular" }           -> pide un QR nuevo
 *   POST vincular_bot.php   { "accion": "desvincular" }         -> cierra sesión y
 *                                                                  borra credenciales
 *   POST vincular_bot.php   { "accion": "reiniciar_bot" }       -> reinicia la
 *                                                                  conexión de
 *                                                                  WhatsApp (útil
 *                                                                  cuando la
 *                                                                  conexión falla o
 *                                                                  se queda
 *                                                                  "trabada"), SIN
 *                                                                  desvincular el
 *                                                                  número ni borrar
 *                                                                  credenciales
 *   POST vincular_bot.php   { "accion": "activar_prueba" }      -> activa el "modo
 *                                                                  prueba" (autoenvío
 *                                                                  al chat "Tú")
 *   POST vincular_bot.php   { "accion": "desactivar_prueba" }   -> desactiva el
 *                                                                  "modo prueba"
 *   POST vincular_bot.php   { "accion": "set_velocidad_escritura",
 *                             "caracteresPorSegundo": 5 }        -> cambia la
 *                                                                  velocidad de
 *                                                                  "escritura" del
 *                                                                  bot (pruebas)
 *   POST vincular_bot.php   { "accion": "set_pausa_entre_burbujas",
 *                             "segundos": 1 }                    -> cambia los
 *                                                                  segundos de
 *                                                                  pausa entre
 *                                                                  una burbuja y
 *                                                                  la siguiente
 *                                                                  cuando una
 *                                                                  respuesta se
 *                                                                  manda en
 *                                                                  varios
 *                                                                  mensajes
 *                                                                  (pruebas)
 *   POST vincular_bot.php   { "accion": "set_debounce_inicio",
 *                             "segundos": 20 }                   -> cambia los
 *                                                                  segundos de
 *                                                                  espera al
 *                                                                  recibir el
 *                                                                  PRIMER mensaje
 *                                                                  de una
 *                                                                  conversación
 *                                                                  nueva (3 a 120)
 *   POST vincular_bot.php   { "accion": "reiniciar_sesion",
 *                             "numero": "50259312332" }          -> borra la sesión de
 *                                                                  cifrado guardada con
 *                                                                  ESE número puntual
 *                                                                  (mitigación del bug
 *                                                                  de Baileys que deja
 *                                                                  a algunos iPhone
 *                                                                  viendo "Esperando el
 *                                                                  mensaje..." sin fin)
 *   POST vincular_bot.php   { "accion": "agregar_numero_excluido",
 *                             "numero": "50259312332" }          -> agrega ESE número
 *                                                                  a la lista de
 *                                                                  "excluidos": el
 *                                                                  bot deja de
 *                                                                  responderle
 *                                                                  automáticamente
 *                                                                  (para contactos
 *                                                                  internos de la
 *                                                                  municipalidad)
 *   POST vincular_bot.php   { "accion": "quitar_numero_excluido",
 *                             "numero": "50259312332" }          -> saca a ESE número
 *                                                                  de la lista de
 *                                                                  "excluidos": el
 *                                                                  bot vuelve a
 *                                                                  responderle como
 *                                                                  a cualquier
 *                                                                  ciudadano
 *
 * El BRIDGE_SECRET se agrega aquí, del lado del servidor PHP, para que
 * nunca quede expuesto en el navegador del administrador.
 *
 * Igual que estado_bot.php, este endpoint SIEMPRE debe devolver JSON puro,
 * por eso se descarta cualquier salida accidental con un buffer.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

session_start();
header('Content-Type: application/json; charset=utf-8');

ob_start();

if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true || !($_SESSION['admin'] ?? false)) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';
require_once __DIR__ . '/config_whatsapp.php';

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$accion = $input['accion'] ?? '';

// Mapa: acción del panel -> [ruta del bot, si lleva BRIDGE_SECRET, datos extra]
$mapaAcciones = [
    'vincular'          => ['/vincular',      true, []],
    'desvincular'       => ['/desvincular',   true, []],
    // Reinicia solo la conexión/socket de WhatsApp dentro del mismo
    // proceso del bot (cierra el socket actual y vuelve a conectar con
    // las credenciales ya guardadas). NO borra la sesión vinculada ni
    // pide escanear el QR de nuevo: es lo indicado cuando la conexión
    // "se cae" o deja de responder sin motivo aparente. Si el proceso
    // de Node en sí dejó de correr (la ventana de la consola se cerró,
    // se colgó la PC, etc.), este botón no puede levantarlo solo: en
    // ese caso hay que volver a ejecutar iniciar_bot.bat / .sh a mano.
    'reiniciar_bot'     => ['/reiniciar_bot', true, []],
    'activar_prueba'    => ['/modo_prueba',   true, ['accion' => 'activar']],
    'desactivar_prueba' => ['/modo_prueba',   true, ['accion' => 'desactivar']],
];

// "set_velocidad_escritura" no puede vivir en el mapa fijo de arriba porque
// su valor (caracteresPorSegundo) viene del propio request, no es un dato
// fijo por acción.
if ($accion === 'set_velocidad_escritura') {
    $caracteresPorSegundo = $input['caracteresPorSegundo'] ?? null;
    if (!is_numeric($caracteresPorSegundo)) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Falta o es inválido el campo "caracteresPorSegundo".']);
        exit;
    }
    $ruta = '/velocidad_escritura';
    $incluirSecreto = true;
    $datosExtra = ['caracteresPorSegundo' => (float) $caracteresPorSegundo];
} elseif ($accion === 'set_pausa_entre_burbujas') {
    // Igual que "set_velocidad_escritura": el valor (segundos) viene del
    // propio request, no es un dato fijo por acción.
    $segundos = $input['segundos'] ?? null;
    if (!is_numeric($segundos)) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Falta o es inválido el campo "segundos".']);
        exit;
    }
    $ruta = '/pausa_entre_burbujas';
    $incluirSecreto = true;
    $datosExtra = ['segundos' => (float) $segundos];
} elseif ($accion === 'set_debounce_inicio') {
    // Espera al recibir el PRIMER mensaje de una conversación nueva.
    $segundos = $input['segundos'] ?? null;
    if (!is_numeric($segundos)) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Falta o es inválido el campo "segundos".']);
        exit;
    }
    $ruta = '/debounce_inicio';
    $incluirSecreto = true;
    $datosExtra = ['segundos' => (float) $segundos];
} elseif ($accion === 'reiniciar_sesion') {
    // Igual que arriba: el número lo manda el admin desde el panel (el
    // ciudadano cuyo iPhone quedó con la sesión de cifrado trabada), no es
    // un dato fijo por acción.
    $numero = preg_replace('/\D/', '', (string) ($input['numero'] ?? ''));
    if ($numero === '') {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Falta el número de teléfono del ciudadano.']);
        exit;
    }
    $ruta = '/reiniciar_sesion';
    $incluirSecreto = true;
    $datosExtra = ['numero' => $numero];
} elseif ($accion === 'agregar_numero_excluido') {
    // El admin agrega un número (ej. un contacto de la municipalidad) a la
    // lista de "excluidos" del bot (ver NUMEROS_EXCLUIDOS en
    // whatsapp-bot/index.js): a partir de ahora el bot no le responde nada
    // automáticamente a ese número.
    $numero = preg_replace('/\D/', '', (string) ($input['numero'] ?? ''));
    if ($numero === '') {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Falta el número de teléfono.']);
        exit;
    }
    $ruta = '/numeros_excluidos/agregar';
    $incluirSecreto = true;
    $datosExtra = ['numero' => $numero];
} elseif ($accion === 'quitar_numero_excluido') {
    // El admin saca a un número de la lista de "excluidos": el bot vuelve
    // a responderle automáticamente como a cualquier ciudadano.
    $numero = preg_replace('/\D/', '', (string) ($input['numero'] ?? ''));
    if ($numero === '') {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Falta el número de teléfono.']);
        exit;
    }
    $ruta = '/numeros_excluidos/quitar';
    $incluirSecreto = true;
    $datosExtra = ['numero' => $numero];
} elseif (array_key_exists($accion, $mapaAcciones)) {
    [$ruta, $incluirSecreto, $datosExtra] = $mapaAcciones[$accion];
} else {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Acción inválida. Use "vincular", "desvincular", "reiniciar_bot", "activar_prueba", "desactivar_prueba", "set_velocidad_escritura", "set_pausa_entre_burbujas", "set_debounce_inicio", "reiniciar_sesion", "agregar_numero_excluido" o "quitar_numero_excluido".']);
    exit;
}

$resultado = solicitarAlBotWhatsApp($ruta, 'POST', $incluirSecreto, $datosExtra);

if (!$resultado['ok']) {
    registrarLog("Fallo al ejecutar '$accion' de WhatsApp desde el panel admin: " . ($resultado['error'] ?? 'error desconocido'), 'error', 'whatsapp_mensajes.log');
    ob_end_clean();
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => $resultado['error'] ?? 'No se pudo contactar al bot de WhatsApp.']);
    exit;
}

registrarLog("Admin '{$_SESSION['username']}' solicitó '$accion' de WhatsApp desde el panel.", 'info', 'whatsapp_mensajes.log');

ob_end_clean();

echo json_encode([
    'ok' => true,
    'mensaje' => $resultado['data']['mensaje'] ?? ucfirst($accion) . ' completado.',
    'modo_prueba' => $resultado['data']['modoPrueba'] ?? null,
    'velocidad_escritura' => $resultado['data']['velocidadEscritura'] ?? null,
    'pausa_entre_burbujas' => $resultado['data']['pausaEntreBurbujas'] ?? null,
    'debounce_inicio_ms' => $resultado['data']['debounceInicioMs'] ?? null,
    'borrados' => $resultado['data']['borrados'] ?? null,
    'numeros_excluidos' => $resultado['data']['numerosExcluidos'] ?? null,
]);
