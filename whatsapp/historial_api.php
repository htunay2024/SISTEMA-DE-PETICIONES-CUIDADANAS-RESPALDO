<?php
/**
 * whatsapp/historial_api.php   (ARCHIVO NUEVO)
 *
 * Endpoint del panel admin para el historial completo de WhatsApp. Solo administradores.
 *   POST { "accion": "estado" }                       -> resumen del archivo por mes
 *   POST { "accion": "importar", "mes": 3, "anio": 2026 }  -> crea las peticiones de ese mes
 *   POST { "accion": "profundizar" }                  -> pide al teléfono mensajes más antiguos
 */

ini_set('display_errors', '0');
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true || !($_SESSION['admin'] ?? false)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';
require_once __DIR__ . '/config_whatsapp.php';
require_once __DIR__ . '/importar_chats_core.php';   // a nivel global (carga el clasificador)

$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$accion = $input['accion'] ?? '';

if ($accion === 'estado') {
    echo json_encode(['ok' => true] + historialResumen(), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($accion === 'importar') {
    $mes  = (int) ($input['mes'] ?? 0);
    $anio = (int) ($input['anio'] ?? 0);
    if ($mes < 1 || $mes > 12 || $anio < 2000) {
        echo json_encode(['ok' => false, 'error' => 'Mes o año inválidos.']);
        exit;
    }
    $log = [];
    $r = importarHistorialMes($mes, $anio, $log);
    if (isset($r['error'])) {
        echo json_encode(['ok' => false, 'error' => $r['error'], 'log' => $log], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => true, 'mes' => $mes, 'anio' => $anio] + $r + ['log' => $log], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($accion === 'profundizar') {
    // El bot responde enseguida y pide el historial en segundo plano.
    $res = solicitarAlBotWhatsApp('/historial/profundizar', 'POST', true, ['cantidad' => 50], 10);
    if (!$res['ok']) {
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => $res['error'] ?? 'No se pudo contactar al bot de WhatsApp.']);
        exit;
    }
    echo json_encode(['ok' => true, 'mensaje' => $res['data']['mensaje'] ?? 'Solicitud enviada.',
                      'solicitados' => $res['data']['solicitados'] ?? null], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Acción inválida. Use "estado", "importar" o "profundizar".']);
