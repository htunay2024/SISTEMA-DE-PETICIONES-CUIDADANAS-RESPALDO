<?php
/**
 * reportes/borrar_captura.php
 *
 * Elimina (por AJAX, desde el botón "🗑️" del modo "✏️ Editar" en
 * reportes/imprimir.php) una captura de chat previamente subida con
 * reportes/subir_captura.php: borra el archivo físico y quita la entrada
 * de reportes/capturas_chat.json.
 *
 * Entrada: JSON o application/x-www-form-urlencoded
 *   - ticket_id : int
 *   - ruta      : string — ruta relativa exacta guardada en el registro
 *                 (ej. "reportes/capturas_chat/21/20260812_204822_x.png")
 *
 * Salida: JSON { success: bool, error?: string }
 */

require_once dirname(__DIR__) . '/config/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

function responderBorrado($ok, $extra = []) {
    echo json_encode(array_merge(['success' => $ok], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true) {
    http_response_code(403);
    responderBorrado(false, ['error' => 'Debe iniciar sesión.']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    responderBorrado(false, ['error' => 'Método no permitido.']);
}

// Acepta tanto JSON (fetch con Content-Type: application/json) como
// formulario clásico, igual criterio que reportes/guardar_edicion.php.
$entrada = json_decode(file_get_contents('php://input'), true);
if (!is_array($entrada)) $entrada = $_POST;

$ticketId = isset($entrada['ticket_id']) ? intval($entrada['ticket_id']) : 0;
$ruta = isset($entrada['ruta']) ? trim((string) $entrada['ruta']) : '';

if ($ticketId <= 0 || $ruta === '') {
    responderBorrado(false, ['error' => 'Datos incompletos.']);
}

// La ruta debe pertenecer estrictamente a la carpeta de este ticket, para
// que nunca se pueda borrar un archivo fuera de ahí.
$prefijoValido = 'reportes/capturas_chat/' . $ticketId . '/';
if (strpos($ruta, $prefijoValido) !== 0) {
    responderBorrado(false, ['error' => 'Ruta inválida.']);
}

// Verificar permiso sobre el ticket (mismo criterio que
// reportes/subir_captura.php: admin o propietario).
$tickets = [];
if (file_exists(TICKETS_JSON)) {
    $tickets = json_decode(file_get_contents(TICKETS_JSON), true) ?: [];
}
$ticket = null;
foreach ($tickets as $t) {
    if ($t['id'] == $ticketId) { $ticket = $t; break; }
}
if (!$ticket) {
    responderBorrado(false, ['error' => 'Ticket no encontrado.']);
}
$esAdmin = $_SESSION['admin'] ?? false;
$esPropietario = (isset($ticket['usuario_id']) && $ticket['usuario_id'] == $_SESSION['usuario_id']);
if (!$esAdmin && !$esPropietario) {
    http_response_code(403);
    responderBorrado(false, ['error' => 'No tiene permiso sobre este ticket.']);
}

$rutaRegistro = __DIR__ . '/capturas_chat.json';
if (!file_exists($rutaRegistro)) {
    responderBorrado(false, ['error' => 'No hay capturas registradas para este ticket.']);
}

$fp = fopen($rutaRegistro, 'c+');
if (!$fp || !flock($fp, LOCK_EX)) {
    if ($fp) fclose($fp);
    responderBorrado(false, ['error' => 'No se pudo actualizar el registro (bloqueado).']);
}

$contenido = '';
fseek($fp, 0);
while (!feof($fp)) $contenido .= fread($fp, 8192);
$datos = json_decode($contenido, true);
if (!is_array($datos)) $datos = [];

$clave = (string) $ticketId;
$encontrada = false;
if (isset($datos[$clave]) && is_array($datos[$clave])) {
    foreach ($datos[$clave] as $i => $entrada2) {
        $rutaEntrada = is_array($entrada2) ? ($entrada2['ruta'] ?? '') : $entrada2;
        if ($rutaEntrada === $ruta) {
            array_splice($datos[$clave], $i, 1);
            $encontrada = true;
            break;
        }
    }
}

if (!$encontrada) {
    flock($fp, LOCK_UN);
    fclose($fp);
    responderBorrado(false, ['error' => 'La captura ya no existe en el registro.']);
}

ftruncate($fp, 0);
fseek($fp, 0);
fwrite($fp, json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

// Borrar el archivo físico solo después de haber quitado la entrada del
// registro correctamente, y solo si queda dentro de la carpeta de
// capturas (defensa adicional más allá de la validación de prefijo).
$rutaFisica = dirname(__DIR__) . '/' . $ruta;
$carpetaCapturas = realpath(__DIR__ . '/capturas_chat');
$carpetaReal = realpath(dirname($rutaFisica));
if ($carpetaCapturas && $carpetaReal && strpos($carpetaReal, $carpetaCapturas) === 0 && is_file($rutaFisica)) {
    @unlink($rutaFisica);
}

responderBorrado(true);
