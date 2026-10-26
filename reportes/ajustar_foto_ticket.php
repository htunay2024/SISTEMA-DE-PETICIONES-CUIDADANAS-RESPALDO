<?php
/**
 * reportes/ajustar_foto_ticket.php
 *
 * Guarda los ajustes visuales (tamaño, centrado y posición) de una
 * FOTOGRAFÍA que el ciudadano envió por WhatsApp (ver campo "fotos" del
 * ticket en tickets.json y renderAnexoEvidencia() en oficio_html.php).
 *
 * Se guardan en reportes/anexo_fotos_ajustes.json, indexados por ticket_id
 * y luego por ruta de la foto. El archivo tickets.json NO se modifica.
 *
 * Entrada: JSON o x-www-form-urlencoded
 *   - ticket_id : int
 *   - ruta      : string  (whatsapp/fotos/<carpeta>/<archivo>.ext)
 *   - tamano    : 'pequena'|'mediana'|'grande' (opcional)
 *   - centrada  : bool                        (opcional)
 *   - offsetX   : int                         (opcional, píxeles)
 *   - offsetY   : int                         (opcional, píxeles)
 */

require_once dirname(__DIR__) . '/config/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

function responderAjusteFoto($ok, $extra = []) {
    echo json_encode(array_merge(['success' => $ok], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true) {
    http_response_code(403);
    responderAjusteFoto(false, ['error' => 'Debe iniciar sesión.']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    responderAjusteFoto(false, ['error' => 'Método no permitido.']);
}

$entrada = json_decode(file_get_contents('php://input'), true);
if (!is_array($entrada)) $entrada = $_POST;

$ticketId = isset($entrada['ticket_id']) ? intval($entrada['ticket_id']) : 0;
$ruta     = isset($entrada['ruta']) ? trim((string) $entrada['ruta']) : '';

if ($ticketId <= 0 || $ruta === '') {
    responderAjusteFoto(false, ['error' => 'Datos incompletos.']);
}

// Solo rutas dentro de whatsapp/fotos/, sin ".." (nombres de carpeta y
// archivo con letras, números, guion, guion bajo y punto).
if (strpos($ruta, '..') !== false
    || !preg_match('#^whatsapp/fotos/[A-Za-z0-9_\-]+/[A-Za-z0-9_\-.]+\.(jpg|jpeg|png|webp)$#i', $ruta)) {
    responderAjusteFoto(false, ['error' => 'Ruta inválida.']);
}

// Verificar permiso sobre el ticket (mismo criterio que generar.php)
$tickets = [];
if (file_exists(TICKETS_JSON)) {
    $tickets = json_decode(file_get_contents(TICKETS_JSON), true) ?: [];
}
$ticket = null;
foreach ($tickets as $t) {
    if ($t['id'] == $ticketId) { $ticket = $t; break; }
}
if (!$ticket) {
    responderAjusteFoto(false, ['error' => 'Ticket no encontrado.']);
}
$esAdmin = $_SESSION['admin'] ?? false;
$esPropietario = (isset($ticket['usuario_id']) && $ticket['usuario_id'] == $_SESSION['usuario_id']);
if (!$esAdmin && !$esPropietario) {
    http_response_code(403);
    responderAjusteFoto(false, ['error' => 'No tiene permiso sobre este ticket.']);
}

$tamanosValidos = ['pequena', 'mediana', 'grande'];
$tamano = isset($entrada['tamano']) ? trim((string) $entrada['tamano']) : '';
$tieneTamano = in_array($tamano, $tamanosValidos, true);
$centrada = array_key_exists('centrada', $entrada) ? filter_var($entrada['centrada'], FILTER_VALIDATE_BOOLEAN) : null;
$offsetX = array_key_exists('offsetX', $entrada) ? max(-200, min(200, intval($entrada['offsetX']))) : null;
$offsetY = array_key_exists('offsetY', $entrada) ? max(-200, min(200, intval($entrada['offsetY']))) : null;

if (!$tieneTamano && $centrada === null && $offsetX === null && $offsetY === null) {
    responderAjusteFoto(false, ['error' => 'Nada para actualizar.']);
}

$archivo = __DIR__ . '/anexo_fotos_ajustes.json';
if (!file_exists($archivo)) {
    file_put_contents($archivo, json_encode(new stdClass(), JSON_PRETTY_PRINT));
}

$fp = fopen($archivo, 'c+');
if (!$fp) responderAjusteFoto(false, ['error' => 'No se pudo abrir el archivo de ajustes.']);

$guardado = false;
if (flock($fp, LOCK_EX)) {
    $contenido = '';
    fseek($fp, 0);
    while (!feof($fp)) $contenido .= fread($fp, 8192);
    $datos = json_decode($contenido, true);
    if (!is_array($datos)) $datos = [];

    $claveTicket = (string) $ticketId;
    if (!isset($datos[$claveTicket]) || !is_array($datos[$claveTicket])) $datos[$claveTicket] = [];
    if (!isset($datos[$claveTicket][$ruta]) || !is_array($datos[$claveTicket][$ruta])) $datos[$claveTicket][$ruta] = [];

    if ($tieneTamano) $datos[$claveTicket][$ruta]['tamano'] = $tamano;
    if ($centrada !== null) $datos[$claveTicket][$ruta]['centrada'] = $centrada;
    if ($offsetX !== null) $datos[$claveTicket][$ruta]['offsetX'] = $offsetX;
    if ($offsetY !== null) $datos[$claveTicket][$ruta]['offsetY'] = $offsetY;

    ftruncate($fp, 0);
    fseek($fp, 0);
    fwrite($fp, json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    $guardado = true;
}
fclose($fp);

if (!$guardado) {
    responderAjusteFoto(false, ['error' => 'No se pudo guardar el ajuste.']);
}

responderAjusteFoto(true, [
    'tamano'   => $tieneTamano ? $tamano : null,
    'centrada' => $centrada,
    'offsetX'  => $offsetX,
    'offsetY'  => $offsetY,
]);
