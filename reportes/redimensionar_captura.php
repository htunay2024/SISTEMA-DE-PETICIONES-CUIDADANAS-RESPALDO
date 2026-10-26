<?php
/**
 * reportes/redimensionar_captura.php
 *
 * Cambia el tamaño de despliegue y/o el flag "centrada" (NO recorta ni
 * toca el archivo de imagen) de una captura de chat ya anexada: guarda el nuevo tamaño en
 * reportes/capturas_chat.json para que reportes/oficio_html.php
 * (renderAnexoCapturasChat) le aplique la clase CSS correspondiente la
 * próxima vez que se muestre el oficio. Usado por los botones "➖"
 * (achicar) y "➕" (agrandar) del modo "✏️ Editar" en
 * reportes/imprimir.php.
 *
 * Entrada: JSON o application/x-www-form-urlencoded
 *   - ticket_id : int
 *   - ruta      : string — ruta relativa exacta guardada en el registro
 *   - tamano    : 'pequena' | 'mediana' | 'grande'   (opcional si se manda centrada)
 *   - centrada  : bool                                 (opcional si se manda tamano)
 *
 * Salida: JSON { success: bool, tamano?: string|null, centrada?: bool|null, error?: string }
 */

require_once dirname(__DIR__) . '/config/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

function responderTamano($ok, $extra = []) {
    echo json_encode(array_merge(['success' => $ok], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true) {
    http_response_code(403);
    responderTamano(false, ['error' => 'Debe iniciar sesión.']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    responderTamano(false, ['error' => 'Método no permitido.']);
}

$entrada = json_decode(file_get_contents('php://input'), true);
if (!is_array($entrada)) $entrada = $_POST;

$ticketId = isset($entrada['ticket_id']) ? intval($entrada['ticket_id']) : 0;
$ruta     = isset($entrada['ruta']) ? trim((string) $entrada['ruta']) : '';
$tamano   = isset($entrada['tamano']) ? trim((string) $entrada['tamano']) : '';
// "centrada" es opcional: null = no tocar. filter_var evita que el texto
// "false" (formulario) se interprete como true.
$centrada = array_key_exists('centrada', $entrada)
    ? filter_var($entrada['centrada'], FILTER_VALIDATE_BOOLEAN)
    : null;

$tamanosValidos = ['pequena', 'mediana', 'grande'];
$tieneTamano = in_array($tamano, $tamanosValidos, true);

if ($ticketId <= 0 || $ruta === '' || (!$tieneTamano && $centrada === null)) {
    responderTamano(false, ['error' => 'Datos incompletos o parámetros inválidos.']);
}

$prefijoValido = 'reportes/capturas_chat/' . $ticketId . '/';
if (strpos($ruta, $prefijoValido) !== 0) {
    responderTamano(false, ['error' => 'Ruta inválida.']);
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
    responderTamano(false, ['error' => 'Ticket no encontrado.']);
}
$esAdmin = $_SESSION['admin'] ?? false;
$esPropietario = (isset($ticket['usuario_id']) && $ticket['usuario_id'] == $_SESSION['usuario_id']);
if (!$esAdmin && !$esPropietario) {
    http_response_code(403);
    responderTamano(false, ['error' => 'No tiene permiso sobre este ticket.']);
}

$rutaRegistro = __DIR__ . '/capturas_chat.json';
if (!file_exists($rutaRegistro)) {
    responderTamano(false, ['error' => 'No hay capturas registradas para este ticket.']);
}

$fp = fopen($rutaRegistro, 'c+');
if (!$fp || !flock($fp, LOCK_EX)) {
    if ($fp) fclose($fp);
    responderTamano(false, ['error' => 'No se pudo actualizar el registro (bloqueado).']);
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
            if (!is_array($datos[$clave][$i])) {
                $datos[$clave][$i] = ['ruta' => $rutaEntrada];
            }
            if ($tieneTamano) {
                $datos[$clave][$i]['tamano'] = $tamano;
            }
            if ($centrada !== null) {
                $datos[$clave][$i]['centrada'] = $centrada;
            }
            $encontrada = true;
            break;
        }
    }
}

if (!$encontrada) {
    flock($fp, LOCK_UN);
    fclose($fp);
    responderTamano(false, ['error' => 'La captura ya no existe en el registro.']);
}

ftruncate($fp, 0);
fseek($fp, 0);
fwrite($fp, json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

responderTamano(true, [
    'tamano'   => $tieneTamano ? $tamano : null,
    'centrada' => $centrada,
]);
