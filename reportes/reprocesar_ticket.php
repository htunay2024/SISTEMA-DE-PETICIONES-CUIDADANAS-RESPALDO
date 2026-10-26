<?php
/**
 * reportes/reprocesar_ticket.php  (v2)
 *
 * Relee TODA la conversación del ciudadano que corresponde a este ticket,
 * aplicando estas reglas:
 *   • Fuente principal: whatsapp/sesiones/historial/<numero>.jsonl (permanente).
 *   • Ventana: sólo los mensajes de los últimos 8 días a partir de HOY.
 *   • Omisión: se descartan los mensajes que ya fueron consumidos por OTRO
 *     ticket del mismo número (todo mensaje con hora <= fecha_creacion del
 *     ticket ajeno más reciente dentro de la ventana).
 *   • Fallback: si no hay historial, se usa el campo `conversacion` del ticket
 *     (comportamiento anterior).
 *   • Extra opcional: si llega `texto_extra` en el POST, se añade al texto
 *     antes de clasificar (útil para pegar manualmente la conversación de
 *     WhatsApp cuando el historial no existe).
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
session_start();
header('Content-Type: application/json; charset=utf-8');
ob_start();

if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true || !($_SESSION['admin'] ?? false)) {
    ob_end_clean(); http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No autorizado']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean(); http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']); exit;
}

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/IA 2/clasificar.php';
require_once dirname(__DIR__) . '/tickets/correlativo_solicitud.php';
require_once dirname(__DIR__) . '/whatsapp/historial_helper.php';

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$ticketId    = isset($input['ticket_id']) ? intval($input['ticket_id']) : 0;
$textoExtra  = isset($input['texto_extra']) ? trim((string) $input['texto_extra']) : '';
if ($ticketId <= 0) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'ID de ticket inválido'], JSON_UNESCAPED_UNICODE); exit;
}
if (!file_exists(TICKETS_JSON)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'No existe tickets.json']); exit;
}

$fp = fopen(TICKETS_JSON, 'c+');
if (!$fp || !flock($fp, LOCK_EX)) {
    if ($fp) fclose($fp); ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'No se pudo bloquear tickets.json']); exit;
}

$contenido = '';
fseek($fp, 0);
while (!feof($fp)) $contenido .= fread($fp, 8192);
$tickets = json_decode($contenido, true);
if (!is_array($tickets)) $tickets = [];

$indice = null; $ticket = null;
foreach ($tickets as $i => $t) {
    if (($t['id'] ?? null) == $ticketId) { $indice = $i; $ticket = $t; break; }
}
if ($ticket === null) {
    flock($fp, LOCK_UN); fclose($fp); ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Ticket no encontrado']); exit;
}

$numero = preg_replace('/\D/', '', (string) ($ticket['remitente'] ?? ''));
if ($numero === '') {
    flock($fp, LOCK_UN); fclose($fp); ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Este ticket no tiene un número de WhatsApp asociado.']); exit;
}

// ────────────────────────────────────────────────────────────────────────
// 1) Ventana de 8 días y frontera contra OTROS tickets del mismo número
// ────────────────────────────────────────────────────────────────────────
$ahora     = time();
$hace8dias = $ahora - 8 * 24 * 3600;
$finTicket = strtotime($ticket['fecha_creacion'] ?? 'now') ?: $ahora;

$fronteraOtrosTickets = 0;      // mensajes anteriores a esto son de otro ticket
$otrosTicketsAplicados = [];
foreach ($tickets as $t) {
    if (($t['id'] ?? null) == $ticketId) continue;
    if (preg_replace('/\D/', '', (string) ($t['remitente'] ?? '')) !== $numero) continue;
    $ts = strtotime($t['fecha_creacion'] ?? '');
    if (!$ts) continue;
    if ($ts < $hace8dias) continue; // fuera de la ventana, no lo consideramos
    // Sólo cuentan los tickets ANTERIORES al actual (no futuros)
    if ($ts <= $finTicket && $ts > $fronteraOtrosTickets) {
        $fronteraOtrosTickets = $ts;
        $otrosTicketsAplicados[] = ['id' => $t['id'], 'fecha' => $t['fecha_creacion']];
    }
}

// ────────────────────────────────────────────────────────────────────────
// 2) Construir el texto a clasificar
// ────────────────────────────────────────────────────────────────────────
$historial = leerHistorial($numero);
$fuente = 'historial';
$mensajesUsados = [];

if (!empty($historial)) {
    foreach ($historial as $msg) {
        $ts = strtotime($msg['hora'] ?? '');
        if (!$ts) continue;
        if ($ts < $hace8dias) continue;                       // más de 8 días
        if ($ts > $finTicket + 60) continue;                 // posterior al ticket actual (buffer 60s)
        if ($fronteraOtrosTickets && $ts <= $fronteraOtrosTickets) continue; // consumido por otro ticket

        $txt = trim((string) ($msg['ciudadano'] ?? ''));
        if ($txt === '' || $txt === '[Fotografía adjunta]' || $txt === '__FOTO_RECIBIDA__') continue;

        $mensajesUsados[] = [
            'hora' => $msg['hora'],
            'texto' => $txt,
        ];
    }
}

// Fallback: si no hay historial (o quedó vacío), usar la conversación del ticket
if (empty($mensajesUsados)) {
    $fuente = 'ticket';
    if (!empty($ticket['conversacion']) && is_array($ticket['conversacion'])) {
        foreach ($ticket['conversacion'] as $turno) {
            if (!is_array($turno)) continue;
            $txt = trim((string) ($turno['ciudadano'] ?? ''));
            if ($txt === '' || $txt === '[Fotografía adjunta]' || $txt === '__FOTO_RECIBIDA__') continue;
            $mensajesUsados[] = ['hora' => $turno['hora'] ?? '', 'texto' => $txt];
        }
    }
    if (!empty($ticket['mensaje_original'])) {
        $mensajesUsados[] = ['hora' => $ticket['fecha_creacion'] ?? '', 'texto' => (string) $ticket['mensaje_original']];
    }
}

$texto = trim(implode("\n", array_map(function ($m) { return $m['texto']; }, $mensajesUsados)));
if ($textoExtra !== '') {
    $texto = trim($texto . "\n" . $textoExtra);
}

if ($texto === '') {
    flock($fp, LOCK_UN); fclose($fp); ob_end_clean();
    echo json_encode([
        'success' => false,
        'error'   => 'No hay mensajes dentro de la ventana de 8 días para este ticket (ni en historial ni en la conversación guardada). Si tenés el chat completo de WhatsApp, pegalo en el campo de texto adicional del modal y volvé a intentar.',
        'diagnostico' => [
            'numero'                => $numero,
            'historial_existe'      => file_exists(rutaHistorial($numero)),
            'historial_lineas'      => count($historial),
            'ventana_dias'          => 8,
            'fecha_min'             => date('Y-m-d H:i:s', $hace8dias),
            'fecha_max'             => date('Y-m-d H:i:s', $finTicket),
            'frontera_otros'        => $fronteraOtrosTickets ? date('Y-m-d H:i:s', $fronteraOtrosTickets) : null,
            'otros_tickets_aplicados' => $otrosTicketsAplicados,
        ],
    ], JSON_UNESCAPED_UNICODE); exit;
}

// ────────────────────────────────────────────────────────────────────────
// 3) Clasificar
// ────────────────────────────────────────────────────────────────────────
$clasificacion  = clasificarPeticion($texto);
$categoriaNueva = $clasificacion['categoria'] ?? null;

if (empty($categoriaNueva) || empty($clasificacion['tiene_senal_clara'])
    || $categoriaNueva === 'administrativo'
    || $categoriaNueva === 'informacion_ciudadana'
    || strpos((string) $categoriaNueva, 'informacion_') === 0) {

    flock($fp, LOCK_UN); fclose($fp); ob_end_clean();
    echo json_encode([
        'success'             => false,
        'error'               => 'El clasificador no identificó una categoría de reporte en el texto reunido. Ajustá las palabras clave de datos_ia.json o pegá manualmente el chat en "texto_extra".',
        'fuente'              => $fuente,
        'texto_analizado'     => mb_substr($texto, 0, 800),
        'categoria_detectada' => $categoriaNueva,
        'confianza'           => $clasificacion['confianza'] ?? 0,
        'diagnostico'         => [
            'numero'            => $numero,
            'historial_lineas'  => count($historial),
            'mensajes_usados'   => count($mensajesUsados),
            'frontera_otros'    => $fronteraOtrosTickets ? date('Y-m-d H:i:s', $fronteraOtrosTickets) : null,
        ],
    ], JSON_UNESCAPED_UNICODE); exit;
}

// ────────────────────────────────────────────────────────────────────────
// 4) Actualizar ticket + generar expediente si falta
// ────────────────────────────────────────────────────────────────────────
$categoriaAnterior = $ticket['categoria'] ?? '';
$expediente = $ticket['expediente'] ?? '';
$expedienteGenerado = false;
if ($expediente === '' || $expediente === null) {
    $prefijos = [
        'bacheo' => 'BCH', 'alumbrado_publico' => 'ALM', 'fuga_agua' => 'AGU',
        'recoleccion_basura' => 'BAS', 'incidentes_seguridad' => 'SEG',
        'gestion_ambiental' => 'AMB', 'administrativo' => 'ADM',
        'via_publica' => 'VIA', 'conflictos_particulares' => 'PAR',
    ];
    $pref = $prefijos[$categoriaNueva] ?? 'GEN';
    $nuevoNumero = obtenerYAvanzarNumeroSolicitud();
    $expediente = "SPJ-{$pref}-" . date('Y') . "-" . str_pad($nuevoNumero, 4, '0', STR_PAD_LEFT);
    $expedienteGenerado = true;
}

$tickets[$indice]['categoria']            = $categoriaNueva;
$tickets[$indice]['urgencia']             = $clasificacion['urgencia'] ?? 'baja';
$tickets[$indice]['departamento_id']      = $clasificacion['departamento_id'] ?? 0;
$tickets[$indice]['expediente']           = $expediente;
$tickets[$indice]['tipo_informacion']     = false;
$tickets[$indice]['convertido_a_reporte'] = true;
$tickets[$indice]['convertido_en']        = date('Y-m-d H:i:s');
$tickets[$indice]['convertido_por']       = 'Reprocesamiento manual (admin v2)';
$tickets[$indice]['categoria_anterior']   = $categoriaAnterior;
$tickets[$indice]['fecha_actualizacion']  = date('Y-m-d H:i:s');
if (!isset($tickets[$indice]['historial_estados']) || !is_array($tickets[$indice]['historial_estados'])) {
    $tickets[$indice]['historial_estados'] = [];
}
$tickets[$indice]['historial_estados'][] = [
    'estado'     => $tickets[$indice]['estado'] ?? 'pendiente',
    'fecha'      => date('Y-m-d H:i:s'),
    'usuario_id' => $_SESSION['usuario_id'] ?? null,
    'comentario' => 'Reprocesado (v2, fuente ' . $fuente . '). Categoría anterior: '
        . ($categoriaAnterior ?: 'ninguna') . '. Nueva: ' . $categoriaNueva
        . ' (' . ($clasificacion['categoria_nombre'] ?? '') . '). Confianza: '
        . ($clasificacion['confianza'] ?? 0) . '%. Mensajes usados: '
        . count($mensajesUsados) . '.',
];

ftruncate($fp, 0); fseek($fp, 0);
fwrite($fp, json_encode($tickets, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
fflush($fp); flock($fp, LOCK_UN); fclose($fp);

// ────────────────────────────────────────────────────────────────────────
// 5) Borrar edición guardada del oficio para que se regenere
// ────────────────────────────────────────────────────────────────────────
$rutaEdiciones = __DIR__ . '/oficios_editados.json';
if (file_exists($rutaEdiciones)) {
    $fpEd = fopen($rutaEdiciones, 'c+');
    if ($fpEd && flock($fpEd, LOCK_EX)) {
        $cont = ''; fseek($fpEd, 0);
        while (!feof($fpEd)) $cont .= fread($fpEd, 8192);
        $ediciones = json_decode($cont, true) ?: [];
        if (isset($ediciones[(string) $ticketId])) {
            unset($ediciones[(string) $ticketId]);
            ftruncate($fpEd, 0); fseek($fpEd, 0);
            fwrite($fpEd, json_encode($ediciones, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            fflush($fpEd);
        }
        flock($fpEd, LOCK_UN);
    }
    if ($fpEd) fclose($fpEd);
}

if (function_exists('registrarLog')) {
    registrarLog("Ticket #$ticketId reprocesado (v2, fuente: $fuente, mensajes: " . count($mensajesUsados) . "). Categoría: $categoriaAnterior → $categoriaNueva", 'info', 'tickets.log');
}

ob_end_clean();
echo json_encode([
    'success'             => true,
    'ticket_id'           => $ticketId,
    'fuente'              => $fuente,
    'mensajes_usados'     => count($mensajesUsados),
    'categoria_anterior'  => $categoriaAnterior,
    'categoria_nueva'     => $categoriaNueva,
    'categoria_nombre'    => $clasificacion['categoria_nombre'] ?? $categoriaNueva,
    'urgencia'            => $clasificacion['urgencia'] ?? 'baja',
    'departamento_id'     => $clasificacion['departamento_id'] ?? 0,
    'confianza'           => $clasificacion['confianza'] ?? 0,
    'expediente'          => $expediente,
    'expediente_generado' => $expedienteGenerado,
    'texto_analizado'     => mb_substr($texto, 0, 800),
    'mensaje'             => 'Petición reprocesada. Abrí "Ver Reporte" para ver el oficio regenerado.',
], JSON_UNESCAPED_UNICODE);
