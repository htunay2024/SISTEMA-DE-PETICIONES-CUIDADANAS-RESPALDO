<?php
/**
 * whatsapp/emojis.php
 *
 * Endpoint AJAX (solo administradores) usado por vistas/whatsapp_admin.php
 * para consultar y cambiar si el bot de WhatsApp envía emojis en sus
 * respuestas a los ciudadanos.
 *
 * GET  -> { "ok": true, "activado": true|false }
 * POST { "activado": true|false } -> cambia el estado y devuelve lo mismo
 *
 * No depende del bot de Node ni de BRIDGE_SECRET: el filtro de emojis se
 * aplica del lado de PHP (whatsapp/recibir_bot.php), así que solo hace
 * falta la sesión de administrador, igual que estado_bot.php.
 *
 * IMPORTANTE: igual que el resto de endpoints de este panel, SIEMPRE debe
 * devolver JSON puro (se atrapa cualquier salida accidental con ob_start).
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

session_start();
header('Content-Type: application/json; charset=utf-8');

ob_start();

if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true || !($_SESSION['admin'] ?? false)) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}

require_once __DIR__ . '/emojis_config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);

    if (!is_array($body) || !array_key_exists('activado', $body)) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Falta el campo "activado"']);
        exit;
    }

    $guardado = establecerEmojisActivados((bool) $body['activado']);

    ob_end_clean();

    if (!$guardado) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'No se pudo guardar la configuración de emojis']);
        exit;
    }

    echo json_encode(['ok' => true, 'activado' => emojisActivados()]);
    exit;
}

// GET: solo consultar el estado actual
ob_end_clean();
echo json_encode(['ok' => true, 'activado' => emojisActivados()]);
