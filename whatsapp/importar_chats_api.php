<?php
/**
 * whatsapp/importar_chats_api.php
 *
 * Endpoint del panel admin para importar las conversaciones guardadas de un mes.
 * Petición:  POST application/json  { "mes": 10, "anio": 2026 }
 * Solo administradores.
 */

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

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$mes   = (int) ($input['mes']  ?? 0);
$anio  = (int) ($input['anio'] ?? 0);
if ($mes < 1 || $mes > 12 || $anio < 2000) {
    echo json_encode(['ok' => false, 'error' => 'Mes o año inválidos.']);
    exit;
}

require_once __DIR__ . '/importar_chats_core.php';

$log = [];
$r = importarConversacionesMes($mes, $anio, $log);

if (isset($r['error'])) {
    echo json_encode(['ok' => false, 'error' => $r['error'], 'log' => $log], JSON_UNESCAPED_UNICODE);
    exit;
}
echo json_encode(['ok' => true, 'mes' => $mes, 'anio' => $anio] + $r + ['log' => $log], JSON_UNESCAPED_UNICODE);
