<?php
/**
 * reportes/anexo_titulos.php
 *
 * Guarda los TÍTULOS personalizados de cada imagen del anexo de evidencia
 * (por defecto "Fotografía N" / "Captura N"). El usuario los edita haciendo
 * clic sobre el rótulo en modo "✏️ Editar" (reportes/imprimir.php).
 *
 * Persiste en reportes/anexo_titulos.json, indexado por ticket_id y luego
 * por clave "origen|ruta":
 *   { "22": { "whatsapp|whatsapp/fotos/.../x.jpg": "Bache frente a mi casa",
 *             "chat|reportes/capturas_chat/22/y.png": "Chat con el vecino" } }
 *
 * Entrada POST (JSON):
 *   { ticket_id: int, titulos: { "origen|ruta": "texto nuevo", ... } }
 *   Un título vacío ("") borra el título personalizado de esa imagen
 *   (vuelve al "Fotografía N" / "Captura N" por defecto).
 */

require_once dirname(__DIR__) . '/config/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

function responderTitulo($ok, $extra = []) {
    echo json_encode(array_merge(['success' => $ok], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true) {
    http_response_code(403);
    responderTitulo(false, ['error' => 'Debe iniciar sesión.']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    responderTitulo(false, ['error' => 'Método no permitido.']);
}

$entrada = json_decode(file_get_contents('php://input'), true);
if (!is_array($entrada)) $entrada = $_POST;

$ticketId = isset($entrada['ticket_id']) ? intval($entrada['ticket_id']) : 0;
$titulos  = isset($entrada['titulos']) && is_array($entrada['titulos']) ? $entrada['titulos'] : null;

if ($ticketId <= 0 || $titulos === null) {
    responderTitulo(false, ['error' => 'Datos incompletos.']);
}

// Verificar permiso sobre el ticket
$tickets = [];
if (file_exists(TICKETS_JSON)) {
    $tickets = json_decode(file_get_contents(TICKETS_JSON), true) ?: [];
}
$ticket = null;
foreach ($tickets as $t) {
    if ($t['id'] == $ticketId) { $ticket = $t; break; }
}
if (!$ticket) responderTitulo(false, ['error' => 'Ticket no encontrado.']);
$esAdmin = $_SESSION['admin'] ?? false;
$esPropietario = (isset($ticket['usuario_id']) && $ticket['usuario_id'] == $_SESSION['usuario_id']);
if (!$esAdmin && !$esPropietario) {
    http_response_code(403);
    responderTitulo(false, ['error' => 'No tiene permiso sobre este ticket.']);
}

// Validar claves y textos. Valor null = borrar el título personalizado.
$cambios = [];
foreach ($titulos as $claveImg => $texto) {
    if (!is_string($claveImg) || strpos($claveImg, '|') === false) continue;
    list($origen, $ruta) = explode('|', $claveImg, 2);
    if (!in_array($origen, ['whatsapp', 'chat'], true)) continue;
    if (strpos($ruta, '..') !== false || !preg_match('#^[A-Za-z0-9_./-]+$#', $ruta)) continue;

    $texto = is_string($texto) ? trim(strip_tags($texto)) : '';
    $texto = trim((string) preg_replace('/\s+/u', ' ', $texto));
    $texto = mb_substr($texto, 0, 200);

    $cambios[$claveImg] = ($texto !== '') ? $texto : null;
}

$archivo = __DIR__ . '/anexo_titulos.json';
if (!file_exists($archivo)) {
    file_put_contents($archivo, json_encode(new stdClass(), JSON_PRETTY_PRINT));
}

$fp = fopen($archivo, 'c+');
if (!$fp) responderTitulo(false, ['error' => 'No se pudo abrir el archivo de títulos.']);

$resultado = false;
if (flock($fp, LOCK_EX)) {
    $contenido = '';
    fseek($fp, 0);
    while (!feof($fp)) $contenido .= fread($fp, 8192);
    $datos = json_decode($contenido, true);
    if (!is_array($datos)) $datos = [];

    $claveTicket = (string) $ticketId;
    if (!isset($datos[$claveTicket]) || !is_array($datos[$claveTicket])) $datos[$claveTicket] = [];

    foreach ($cambios as $k => $v) {
        if ($v === null) {
            unset($datos[$claveTicket][$k]);
        } else {
            $datos[$claveTicket][$k] = $v;
        }
    }

    // Si el ticket se quedó sin títulos personalizados, se quita su entrada
    // entera para no dejar basura en el JSON.
    if (empty($datos[$claveTicket])) {
        unset($datos[$claveTicket]);
    }

    ftruncate($fp, 0);
    fseek($fp, 0);
    fwrite($fp, json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    $resultado = true;
}
fclose($fp);

if (!$resultado) responderTitulo(false, ['error' => 'No se pudieron guardar los títulos.']);
responderTitulo(true);
