<?php
/**
 * whatsapp/convertir_consulta.php
 *
 * Endpoint AJAX (solo administradores) usado desde vistas/admin_tickets.php.
 * Convierte un ticket de CONSULTA INFORMATIVA (categoria = 'informacion_ciudadana'
 * o tipo_informacion = true) en un REPORTE: vuelve a clasificar el texto del
 * ciudadano con clasificarPeticion() (IA 2/clasificar.php) y, si el motor
 * identifica una categoría de reporte real, actualiza categoria, urgencia y
 * departamento_id del ticket y deja constancia en historial_estados.
 *
 * NOTA: el bot guarda las consultas con 'conversacion' => [] (ver
 * recibir_bot.php), así que normalmente se reclasifica 'mensaje_original'.
 * Si el ticket sí trae conversación, se usa todo el texto del ciudadano.
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/IA 2/clasificar.php'; // clasificarPeticion(), registrarLog()

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

function responder($ok, $extra = []) {
    echo json_encode(array_merge(['success' => $ok], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true || !($_SESSION['admin'] ?? false)) {
    http_response_code(403);
    responder(false, ['error' => 'No autorizado']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    responder(false, ['error' => 'Método no permitido']);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$ticketId = isset($input['ticket_id']) ? intval($input['ticket_id']) : 0;
if ($ticketId <= 0) {
    responder(false, ['error' => 'Ticket inválido']);
}

if (!file_exists(TICKETS_JSON)) {
    responder(false, ['error' => 'No existe tickets.json']);
}

$fp = fopen(TICKETS_JSON, 'c+');
if (!$fp || !flock($fp, LOCK_EX)) {
    if ($fp) fclose($fp);
    responder(false, ['error' => 'No se pudo bloquear tickets.json']);
}

$contenido = '';
fseek($fp, 0);
while (!feof($fp)) $contenido .= fread($fp, 8192);
$tickets = json_decode($contenido, true);
if (!is_array($tickets)) $tickets = [];

$indice = null;
$ticket = null;
foreach ($tickets as $i => $t) {
    if (($t['id'] ?? null) == $ticketId) { $indice = $i; $ticket = $t; break; }
}

if ($ticket === null) {
    flock($fp, LOCK_UN); fclose($fp);
    responder(false, ['error' => 'Ticket no encontrado']);
}

if (($ticket['categoria'] ?? '') !== 'informacion_ciudadana' && empty($ticket['tipo_informacion'])) {
    flock($fp, LOCK_UN); fclose($fp);
    responder(false, ['error' => 'Este ticket no es una consulta informativa (ya es un reporte)']);
}

// Texto del ciudadano: toda la conversación si existe; si no, el mensaje original.
$textos = [];
if (!empty($ticket['conversacion']) && is_array($ticket['conversacion'])) {
    foreach ($ticket['conversacion'] as $turno) {
        if (!is_array($turno)) continue;
        $c = trim((string) ($turno['ciudadano'] ?? ''));
        if ($c === '' || $c === '[Fotografía adjunta]' || $c === '__FOTO_RECIBIDA__') continue;
        $textos[] = $c;
    }
}
if (empty($textos)) {
    $textos[] = (string) ($ticket['mensaje_original'] ?? '');
}

$textoCompleto = trim(implode("\n", $textos));
if ($textoCompleto === '') {
    flock($fp, LOCK_UN); fclose($fp);
    responder(false, ['error' => 'No hay texto para reclasificar']);
}

$clasificacion = clasificarPeticion($textoCompleto);
$categoriaNueva = $clasificacion['categoria'] ?? null;

if (empty($clasificacion['tiene_senal_clara'])
    || empty($categoriaNueva)
    || $categoriaNueva === 'administrativo'
    || $categoriaNueva === 'informacion_ciudadana'
    || strpos($categoriaNueva, 'informacion_') === 0) {
    flock($fp, LOCK_UN); fclose($fp);
    responder(false, [
        'error' => 'El clasificador no identificó una categoría de reporte. Es posible que realmente sea solo una consulta informativa.',
        'categoria_detectada' => $categoriaNueva,
        'confianza' => $clasificacion['confianza'] ?? 0,
    ]);
}

$admin = $_SESSION['nombre_completo'] ?? $_SESSION['username'] ?? 'admin';
$categoriaAnterior = $ticket['categoria'] ?? 'informacion_ciudadana';

$tickets[$indice]['categoria']            = $categoriaNueva;
$tickets[$indice]['urgencia']             = $clasificacion['urgencia'] ?? 'baja';
$tickets[$indice]['departamento_id']      = $clasificacion['departamento_id'] ?? 0;
$tickets[$indice]['tipo_informacion']     = false;
$tickets[$indice]['convertido_a_reporte'] = true;
$tickets[$indice]['convertido_en']        = date('Y-m-d H:i:s');
$tickets[$indice]['convertido_por']       = $admin;
$tickets[$indice]['categoria_anterior']   = $categoriaAnterior;
$tickets[$indice]['fecha_actualizacion']  = date('Y-m-d H:i:s');

if (!isset($tickets[$indice]['historial_estados']) || !is_array($tickets[$indice]['historial_estados'])) {
    $tickets[$indice]['historial_estados'] = [];
}
$tickets[$indice]['historial_estados'][] = [
    'estado'     => $tickets[$indice]['estado'] ?? 'pendiente',
    'fecha'      => date('Y-m-d H:i:s'),
    'usuario_id' => $_SESSION['usuario_id'] ?? null,
    'comentario' => 'Convertido de consulta informativa a reporte (categoría: ' . $categoriaNueva . ') por '
                    . $admin . '. Clasificación automática con ' . ($clasificacion['confianza'] ?? 0) . '% de confianza.',
];

ftruncate($fp, 0);
fseek($fp, 0);
fwrite($fp, json_encode($tickets, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

if (function_exists('registrarLog')) {
    registrarLog("Consulta informativa #$ticketId convertida a reporte ($categoriaNueva) por $admin", 'info', 'tickets.log');
}

responder(true, [
    'ticket_id'        => $ticketId,
    'categoria_nueva'  => $categoriaNueva,
    'categoria_nombre' => $clasificacion['categoria_nombre'] ?? $categoriaNueva,
    'urgencia'         => $clasificacion['urgencia'] ?? 'baja',
    'confianza'        => $clasificacion['confianza'] ?? 0,
    'mensaje'          => 'Convertido a reporte: ' . ($clasificacion['categoria_nombre'] ?? $categoriaNueva),
]);
