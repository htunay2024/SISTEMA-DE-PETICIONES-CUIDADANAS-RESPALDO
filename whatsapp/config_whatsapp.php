<?php
/**
 * whatsapp/config_whatsapp.php
 * Configuración para integración con WhatsApp
 * SIN Meta Business API: usa un puente local (Node + Baileys)
 * que se conecta como WhatsApp Web mediante código QR.
 * @version 2.2 (agrega soporte de datos extra en solicitarAlBotWhatsApp,
 *               usado por el nuevo "modo prueba" del panel admin)
 */

// Modo de operación
// true  = solo registra logs, no envía realmente (útil para pruebas)
// false = envía mensajes reales a través del puente Node
define('WHATSAPP_MODE_SIMULATION', false);

// URL donde corre tu servicio Node (whatsapp-bot/index.js)
// Se usa 127.0.0.1 en vez de "localhost" a propósito: en Windows, curl desde
// PHP a veces intenta resolver "localhost" primero por IPv6 (::1) y falla o
// se cuelga si Node no quedó escuchando ahí de forma confiable, aunque el
// navegador sí conecte bien (los navegadores hacen fallback automático a
// IPv4 y curl de PHP no siempre lo hace igual de rápido).
define('WHATSAPP_BRIDGE_URL', 'http://127.0.0.1:3001');

// Secreto compartido con el bot Node (debe ser IDÉNTICO al BRIDGE_SECRET
// que pusiste en whatsapp-bot/.env)
define('BRIDGE_SECRET', 'ESTUDIOSGHIBLI2026.');

// Tiempo máximo de espera para respuestas del bot (segundos)
define('WHATSAPP_RESPONSE_TIMEOUT', 15);

// Número máximo de caracteres por mensaje
define('WHATSAPP_MAX_MESSAGE_LENGTH', 1600);

// Archivo para registro de logs específico
define('WHATSAPP_LOG_FILE', dirname(__DIR__) . '/logs/whatsapp_mensajes.log');

/**
 * Registra evento de WhatsApp en el archivo de logs
 * @param string $mensaje Texto a registrar
 * @param string $tipo Tipo de log: 'info', 'error', 'warning', 'debug'
 */
function whatsapp_log($mensaje, $tipo = 'info') {
    $timestamp = date('Y-m-d H:i:s');
    $linea = "[$timestamp] [$tipo] $mensaje" . PHP_EOL;
    file_put_contents(WHATSAPP_LOG_FILE, $linea, FILE_APPEND | LOCK_EX);
}

/**
 * Valida que la configuración sea correcta antes de enviar mensajes reales
 * @return array Array con 'valid' (bool) y 'errors' (array)
 */
function validarConfiguracionWhatsApp() {
    $errors = [];

    if (!WHATSAPP_MODE_SIMULATION) {
        if (BRIDGE_SECRET === 'CAMBIA_ESTO_POR_ALGO_LARGO_Y_UNICO') {
            $errors[] = 'BRIDGE_SECRET no configurado (usa el valor por defecto)';
        }
        if (empty(WHATSAPP_BRIDGE_URL)) {
            $errors[] = 'WHATSAPP_BRIDGE_URL no configurado';
        }
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors,
    ];
}

/**
 * Realiza una petición HTTP al bot de Node (el "puente" de WhatsApp).
 * Se usa tanto para consultar estado/QR (GET, sin secreto) como para
 * pedir acciones administrativas como vincular/desvincular/modo_prueba
 * (POST, con secreto).
 *
 * @param string $ruta   Ruta del endpoint del bot, ej: '/estado', '/qr', '/vincular', '/desvincular', '/modo_prueba'
 * @param string $metodo 'GET' o 'POST'
 * @param bool   $incluirSecreto Si true, envía el BRIDGE_SECRET en el body (para POST protegidos)
 * @param array  $datosExtra Datos adicionales a incluir en el body del POST
 *                           (ej: ['accion' => 'activar'] para /modo_prueba)
 * @param int    $timeoutSegundos Tiempo máximo de espera de esta petición puntual.
 *                           IMPORTANTE: el sistema web corre con el servidor
 *                           embebido de PHP (`php -S`), que atiende UNA sola
 *                           petición a la vez. Si el panel admin quedara
 *                           esperando aquí varios segundos (por ejemplo
 *                           mientras hace polling de /estado cada pocos
 *                           segundos), ninguna otra petición podría
 *                           atenderse mientras tanto — incluyendo los
 *                           mensajes reales que llegan de recibir_bot.php.
 *                           Por eso los endpoints de solo lectura que se
 *                           pollean seguido (estado_bot.php) usan un
 *                           timeout corto.
 * @return array ['ok' => bool, 'http_code' => int, 'data' => array|null, 'error' => string|null]
 */
function solicitarAlBotWhatsApp($ruta, $metodo = 'GET', $incluirSecreto = false, $datosExtra = [], $timeoutSegundos = 8) {
    $url = rtrim(WHATSAPP_BRIDGE_URL, '/') . $ruta;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSegundos);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    if ($metodo === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        $payload = $incluirSecreto ? array_merge(['secret' => BRIDGE_SECRET], $datosExtra) : $datosExtra;
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'http_code' => 0, 'data' => null, 'error' => $error ?: 'No se pudo contactar al bot de WhatsApp (¿está corriendo?)'];
    }

    $data = json_decode($response, true);

    return [
        'ok' => $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'data' => $data,
        'error' => ($httpCode >= 200 && $httpCode < 300) ? null : ($data['error'] ?? "HTTP $httpCode"),
    ];
}
