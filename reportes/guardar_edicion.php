<?php
/**
 * reportes/guardar_edicion.php
 *
 * Guarda la edición manual que un usuario hace sobre el oficio DESPUÉS de
 * darle clic a "Ver reporte" (reportes/imprimir.php), es decir, sobre el
 * documento ya formateado — no es el formulario de edición del ticket que
 * existe en el dashboard.
 *
 * Recibe (POST, JSON):
 *   - id   : int  → id del ticket al que pertenece el oficio.
 *   - html : string → HTML editado del contenido del oficio
 *                     (el mismo bloque que imprimir.php vuelve editable).
 *   - titulo_anexo : string (opcional) → título editado del anexo de
 *                     evidencia (texto plano; vive fuera de #oficio-contenido).
 *
 * Persiste el HTML editado en reportes/oficios_editados.json, indexado por
 * ticket id (mismo patrón de bloqueo de archivo que ya usa
 * obtenerNumeroOficio() en oficio_html.php y cambiarEstadoTicket() en
 * tickets/estado.php). La próxima vez que se abra "Ver reporte" para ese
 * mismo ticket, reportes/generar.php detecta esta edición guardada y la
 * muestra en vez de regenerar el oficio desde cero.
 */

require_once dirname(__DIR__) . '/config/config.php';
session_start();

header('Content-Type: application/json; charset=utf-8');

function responder($ok, $extra = []) {
    echo json_encode(array_merge(['success' => $ok], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true) {
    http_response_code(401);
    responder(false, ['error' => 'No ha iniciado sesión.']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    responder(false, ['error' => 'Método no permitido.']);
}

$input = json_decode(file_get_contents('php://input'), true);
$ticketId = isset($input['id']) ? intval($input['id']) : 0;
$htmlEditado = isset($input['html']) ? (string) $input['html'] : '';

if ($ticketId <= 0) {
    responder(false, ['error' => 'ID de ticket inválido.']);
}
if (trim($htmlEditado) === '') {
    responder(false, ['error' => 'El contenido del oficio está vacío.']);
}
// Límite razonable de tamaño (~2 MB) para evitar que un error del cliente
// (por ejemplo, pegar una imagen base64 gigante) llene el disco.
if (strlen($htmlEditado) > 2 * 1024 * 1024) {
    responder(false, ['error' => 'El contenido editado es demasiado grande.']);
}

// Cargar ticket y verificar permisos (mismo criterio que reportes/generar.php:
// admin, o el propio ciudadano dueño del ticket).
$tickets = [];
if (file_exists(TICKETS_JSON)) {
    $tickets = json_decode(file_get_contents(TICKETS_JSON), true) ?: [];
}
$ticket = null;
foreach ($tickets as $t) {
    if ($t['id'] == $ticketId) { $ticket = $t; break; }
}
if (!$ticket) {
    responder(false, ['error' => 'Ticket no encontrado.']);
}
$esAdmin = $_SESSION['admin'] ?? false;
$esPropietario = (isset($ticket['usuario_id']) && $ticket['usuario_id'] == $_SESSION['usuario_id']);
if (!$esAdmin && !$esPropietario) {
    http_response_code(403);
    responder(false, ['error' => 'No tiene permiso para editar este oficio.']);
}

/**
 * Saneamiento básico del HTML editado: se permite formato (el usuario edita
 * texto dentro de un contenteditable, con negritas/párrafos, etc.) pero se
 * eliminan etiquetas y atributos que podrían ejecutar código si otro
 * usuario vuelve a abrir este mismo oficio (scripts, iframes, manejadores
 * de eventos "on..." y URLs "javascript:").
 */
function sanearHtmlOficio($html) {
    // Quita <script>, <iframe>, <object>, <embed>, <style> completos.
    $html = preg_replace('/<(script|iframe|object|embed|style)\b[^>]*>.*?<\/\1>/is', '', $html);
    // Quita etiquetas sueltas de esos mismos tipos (auto-cerradas o mal formadas).
    $html = preg_replace('/<\/?(script|iframe|object|embed|style)\b[^>]*>/i', '', $html);
    // Quita atributos de evento on*="..." / on*='...'
    $html = preg_replace('/\son\w+\s*=\s*"[^"]*"/i', '', $html);
    $html = preg_replace('/\son\w+\s*=\s*\'[^\']*\'/i', '', $html);
    // Neutraliza URLs "javascript:" en href/src
    $html = preg_replace('/(href|src)\s*=\s*"(?:\s*javascript:)[^"]*"/i', '$1="#"', $html);
    $html = preg_replace('/(href|src)\s*=\s*\'(?:\s*javascript:)[^\']*\'/i', "\$1='#'", $html);
    return $html;
}

$htmlEditado = sanearHtmlOficio($htmlEditado);

$archivo = __DIR__ . '/oficios_editados.json';
if (!file_exists($archivo)) {
    file_put_contents($archivo, json_encode(new stdClass(), JSON_PRETTY_PRINT));
}

$fp = fopen($archivo, 'c+');
if (!$fp) {
    responder(false, ['error' => 'No se pudo abrir el archivo de ediciones.']);
}

$guardado = false;
if (flock($fp, LOCK_EX)) {
    $contenido = '';
    fseek($fp, 0);
    while (!feof($fp)) $contenido .= fread($fp, 8192);
    $datos = json_decode($contenido, true);
    if (!is_array($datos)) $datos = [];

    // Título personalizado del anexo (opcional). Se guarda como TEXTO PLANO
    // (sin etiquetas); el escape HTML se hace al mostrarlo, en
    // renderAnexoEvidencia(). Vacío = se usa el título por defecto.
    $tituloAnexo = isset($input['titulo_anexo']) ? trim(strip_tags((string) $input['titulo_anexo'])) : '';
    $tituloAnexo = trim((string) preg_replace('/\s+/u', ' ', $tituloAnexo));
    if (mb_strlen($tituloAnexo) > 300) {
        $tituloAnexo = mb_substr($tituloAnexo, 0, 300);
    }

    $editor = $_SESSION['nombre_completo'] ?? $_SESSION['username'] ?? 'Usuario del sistema';
    $datos[(string) $ticketId] = [
        'html'         => $htmlEditado,
        'titulo_anexo' => $tituloAnexo,
        'editado_por'  => $editor,
        'fecha'        => date('Y-m-d H:i:s'),
    ];

    ftruncate($fp, 0);
    fseek($fp, 0);
    fwrite($fp, json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    $guardado = true;
}
fclose($fp);

if (!$guardado) {
    responder(false, ['error' => 'No se pudo guardar la edición (archivo en uso, intente de nuevo).']);
}

registrarLog("Oficio del ticket #$ticketId editado manualmente por " . ($_SESSION['nombre_completo'] ?? $_SESSION['username'] ?? 'Usuario del sistema'), 'info', 'app.log');

responder(true, ['fecha' => date('Y-m-d H:i:s')]);
