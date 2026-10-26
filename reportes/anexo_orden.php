<?php
/**
 * reportes/anexo_orden.php
 *
 * Guarda el ORDEN en que se muestran las imágenes del anexo de evidencia
 * de un ticket, tanto las fotos de WhatsApp como las capturas del chat.
 * Se usa para la función "arrastrar una imagen sobre otra para
 * intercambiar posiciones" del modo "✏️ Editar" (reportes/imprimir.php).
 *
 * Persiste en reportes/anexo_orden.json, indexado por ticket_id:
 *   { "22": [ {"origen":"whatsapp","ruta":"..."}, {"origen":"chat","ruta":"..."} ] }
 *
 * Endpoints:
 *   POST { ticket_id, a:{origen,ruta}, b:{origen,ruta} }  -> intercambia a y b
 *   POST { ticket_id, accion:'restablecer' }              -> borra el orden guardado
 */

require_once dirname(__DIR__) . '/config/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

function responderOrden($ok, $extra = []) {
    echo json_encode(array_merge(['success' => $ok], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true) {
    http_response_code(403);
    responderOrden(false, ['error' => 'Debe iniciar sesión.']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    responderOrden(false, ['error' => 'Método no permitido.']);
}

$entrada = json_decode(file_get_contents('php://input'), true);
if (!is_array($entrada)) $entrada = $_POST;

$ticketId = isset($entrada['ticket_id']) ? intval($entrada['ticket_id']) : 0;
if ($ticketId <= 0) responderOrden(false, ['error' => 'ID de ticket inválido.']);

// Verificar permiso sobre el ticket
$tickets = [];
if (file_exists(TICKETS_JSON)) {
    $tickets = json_decode(file_get_contents(TICKETS_JSON), true) ?: [];
}
$ticket = null;
foreach ($tickets as $t) {
    if ($t['id'] == $ticketId) { $ticket = $t; break; }
}
if (!$ticket) responderOrden(false, ['error' => 'Ticket no encontrado.']);
$esAdmin = $_SESSION['admin'] ?? false;
$esPropietario = (isset($ticket['usuario_id']) && $ticket['usuario_id'] == $_SESSION['usuario_id']);
if (!$esAdmin && !$esPropietario) {
    http_response_code(403);
    responderOrden(false, ['error' => 'No tiene permiso sobre este ticket.']);
}

// ------------------------------------------------------------
// Orden por defecto: el mismo que usa renderAnexoEvidencia() cuando no
// hay orden guardado (primero fotos de WhatsApp, luego capturas del chat).
// Se usa como base cuando el usuario empieza a mover por primera vez.
// ------------------------------------------------------------
function construirOrdenPorDefecto($ticketId, $ticket) {
    $items = [];
    if (!empty($ticket['fotos']) && is_array($ticket['fotos'])) {
        foreach (array_values(array_filter($ticket['fotos'])) as $f) {
            $ruta = is_array($f) ? ($f['ruta'] ?? '') : $f;
            if ($ruta !== '') $items[] = ['origen' => 'whatsapp', 'ruta' => $ruta];
        }
    }
    $rutaCapturas = __DIR__ . '/capturas_chat.json';
    if (file_exists($rutaCapturas)) {
        $reg = json_decode(file_get_contents($rutaCapturas), true);
        if (is_array($reg) && !empty($reg[(string) $ticketId])) {
            foreach (array_values(array_filter($reg[(string) $ticketId])) as $cap) {
                $ruta = is_array($cap) ? ($cap['ruta'] ?? '') : $cap;
                if ($ruta !== '') $items[] = ['origen' => 'chat', 'ruta' => $ruta];
            }
        }
    }
    return $items;
}

$archivo = __DIR__ . '/anexo_orden.json';
if (!file_exists($archivo)) {
    file_put_contents($archivo, json_encode(new stdClass(), JSON_PRETTY_PRINT));
}

$fp = fopen($archivo, 'c+');
if (!$fp) responderOrden(false, ['error' => 'No se pudo abrir el archivo de orden.']);

$resultado = null;
if (flock($fp, LOCK_EX)) {
    $contenido = '';
    fseek($fp, 0);
    while (!feof($fp)) $contenido .= fread($fp, 8192);
    $datos = json_decode($contenido, true);
    if (!is_array($datos)) $datos = [];

    $clave = (string) $ticketId;

    if (($entrada['accion'] ?? '') === 'restablecer') {
        unset($datos[$clave]);
        $resultado = ['accion' => 'restablecer'];
    } else {
        $a = $entrada['a'] ?? null;
        $b = $entrada['b'] ?? null;
        if (!is_array($a) || !is_array($b)
            || empty($a['ruta']) || empty($b['ruta'])
            || empty($a['origen']) || empty($b['origen'])) {
            flock($fp, LOCK_UN); fclose($fp);
            responderOrden(false, ['error' => 'Faltan los datos de las dos imágenes a intercambiar.']);
        }

        $orden = (isset($datos[$clave]) && is_array($datos[$clave]) && !empty($datos[$clave]))
            ? $datos[$clave]
            : construirOrdenPorDefecto($ticketId, $ticket);

        // Si hay imágenes nuevas que aún no están en el orden guardado
        // (o se borraron algunas), se reconcilia con la lista real para que
        // los índices de a y b coincidan con lo que se ve en pantalla.
        $reales = construirOrdenPorDefecto($ticketId, $ticket);
        $clavesReales = [];
        foreach ($reales as $r) $clavesReales[$r['origen'] . '|' . $r['ruta']] = $r;
        $ordenLimpio = [];
        foreach ($orden as $it) {
            $k = ($it['origen'] ?? '') . '|' . ($it['ruta'] ?? '');
            if (isset($clavesReales[$k])) {
                $ordenLimpio[] = $clavesReales[$k];
                unset($clavesReales[$k]);
            }
        }
        foreach ($clavesReales as $r) $ordenLimpio[] = $r;
        $orden = $ordenLimpio;

        // Ubicar los índices de a y b en el orden
        $idxA = -1; $idxB = -1;
        foreach ($orden as $i => $it) {
            if ($it['origen'] === $a['origen'] && $it['ruta'] === $a['ruta']) $idxA = $i;
            if ($it['origen'] === $b['origen'] && $it['ruta'] === $b['ruta']) $idxB = $i;
        }
        if ($idxA < 0 || $idxB < 0 || $idxA === $idxB) {
            flock($fp, LOCK_UN); fclose($fp);
            responderOrden(false, ['error' => 'No se encontraron las dos imágenes en el anexo.']);
        }

        // Intercambio
        $tmp = $orden[$idxA];
        $orden[$idxA] = $orden[$idxB];
        $orden[$idxB] = $tmp;

        $datos[$clave] = array_values($orden);
        $resultado = ['accion' => 'intercambiar', 'orden' => $datos[$clave]];
    }

    ftruncate($fp, 0);
    fseek($fp, 0);
    fwrite($fp, json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
}
fclose($fp);

if ($resultado === null) {
    responderOrden(false, ['error' => 'No se pudo actualizar el orden.']);
}
responderOrden(true, $resultado);
