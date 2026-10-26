<?php
/**
 * reportes/borrar_foto_ticket.php
 *
 * Elimina una fotografía que el ciudadano envió por WhatsApp, tanto del
 * archivo tickets.json (campo "fotos") como del disco
 * (sistema-web/whatsapp/fotos/<numero>/archivo.jpg).
 *
 * Lo usa el botón "🗑️" de la barra de herramientas de las fotografías
 * dentro del anexo del oficio (reportes/imprimir.php), cuando la celda
 * tiene data-origen="whatsapp".
 *
 * Entrada: JSON o application/x-www-form-urlencoded
 *   - ticket_id : int
 *   - ruta      : string — ruta relativa exacta guardada en el ticket
 *                 (ej. "whatsapp/fotos/50259312332/1790183462802_8y1h1o.jpg")
 *
 * Salida: JSON { success: bool, error?: string }
 */

require_once dirname(__DIR__) . '/config/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

function responderBorradoFoto($ok, $extra = []) {
    echo json_encode(array_merge(['success' => $ok], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true) {
    http_response_code(403);
    responderBorradoFoto(false, ['error' => 'Debe iniciar sesión.']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    responderBorradoFoto(false, ['error' => 'Método no permitido.']);
}

$entrada = json_decode(file_get_contents('php://input'), true);
if (!is_array($entrada)) $entrada = $_POST;

$ticketId = isset($entrada['ticket_id']) ? intval($entrada['ticket_id']) : 0;
$ruta     = isset($entrada['ruta']) ? trim((string) $entrada['ruta']) : '';

if ($ticketId <= 0 || $ruta === '') {
    responderBorradoFoto(false, ['error' => 'Datos incompletos.']);
}

// Validación estricta: la ruta debe estar dentro de whatsapp/fotos/<numero>/
// (mismo patrón con el que se guardan las fotos), para no poder borrar
// nada fuera de esa carpeta.
if (!preg_match('#^whatsapp/fotos/[0-9]+/[A-Za-z0-9_]+\.(jpg|jpeg|png|webp)$#', $ruta)) {
    responderBorradoFoto(false, ['error' => 'Ruta inválida.']);
}

// Verificar permiso sobre el ticket (admin o propietario).
$tickets = [];
if (file_exists(TICKETS_JSON)) {
    $tickets = json_decode(file_get_contents(TICKETS_JSON), true) ?: [];
}
$ticketEncontrado = null;
foreach ($tickets as $t) {
    if ($t['id'] == $ticketId) { $ticketEncontrado = $t; break; }
}
if (!$ticketEncontrado) {
    responderBorradoFoto(false, ['error' => 'Ticket no encontrado.']);
}
$esAdmin = $_SESSION['admin'] ?? false;
$esPropietario = (isset($ticketEncontrado['usuario_id']) && $ticketEncontrado['usuario_id'] == $_SESSION['usuario_id']);
if (!$esAdmin && !$esPropietario) {
    http_response_code(403);
    responderBorradoFoto(false, ['error' => 'No tiene permiso sobre este ticket.']);
}

// Quitar la ruta del array "fotos" del ticket (con bloqueo exclusivo,
// mismo patrón que estado.php y eliminar.php).
if (!file_exists(TICKETS_JSON)) {
    responderBorradoFoto(false, ['error' => 'No existe tickets.json.']);
}

$fp = fopen(TICKETS_JSON, 'c+');
if (!$fp) responderBorradoFoto(false, ['error' => 'No se pudo abrir tickets.json.']);

$encontrado = false;
if (flock($fp, LOCK_EX)) {
    $contenido = '';
    fseek($fp, 0);
    while (!feof($fp)) $contenido .= fread($fp, 8192);
    $tickets = json_decode($contenido, true);
    if (!is_array($tickets)) $tickets = [];

    foreach ($tickets as &$t) {
        if ($t['id'] != $ticketId) continue;
        if (!empty($t['fotos']) && is_array($t['fotos'])) {
            $nuevas = [];
            foreach ($t['fotos'] as $foto) {
                $rutaFoto = is_array($foto) ? ($foto['ruta'] ?? '') : $foto;
                if ($rutaFoto === $ruta) {
                    $encontrado = true;
                    continue;
                }
                $nuevas[] = $foto;
            }
            $t['fotos'] = array_values($nuevas);
        }
        break;
    }
    unset($t);

    if ($encontrado) {
        ftruncate($fp, 0);
        fseek($fp, 0);
        fwrite($fp, json_encode($tickets, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($fp);
        if (function_exists('registrarLog')) {
            registrarLog("Fotografía de WhatsApp eliminada del ticket #$ticketId ($ruta) por usuario ID: " . ($_SESSION['usuario_id'] ?? '?'), 'info', 'tickets.log');
        }
    }
    flock($fp, LOCK_UN);
}
fclose($fp);

if (!$encontrado) {
    responderBorradoFoto(false, ['error' => 'La fotografía ya no estaba registrada en el ticket.']);
}

// Borrar el archivo físico (solo si sigue dentro de whatsapp/fotos/).
$rutaFisica   = dirname(__DIR__) . '/' . $ruta;
$carpetaFotos = realpath(__DIR__ . '/../whatsapp/fotos');
$carpetaReal  = realpath(dirname($rutaFisica));
if ($carpetaFotos && $carpetaReal && strpos($carpetaReal, $carpetaFotos) === 0 && is_file($rutaFisica)) {
    @unlink($rutaFisica);
}

responderBorradoFoto(true);
