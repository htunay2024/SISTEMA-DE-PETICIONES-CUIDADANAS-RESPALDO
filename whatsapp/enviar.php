<?php
/**
 * whatsapp/enviar.php
 * Envía mensajes de vuelta al ciudadano por WhatsApp
 * Soporte:
 * - Modo simulación (solo logs)
 * - Envío real a través del puente Node (Baileys) - SIN Meta/Facebook
 * - Manejo de errores y reintentos
 * - Validación de longitud de mensajes
 * @version 2.0 (sin Meta Business API)
 */

require_once __DIR__ . '/config_whatsapp.php';
require_once dirname(__DIR__) . '/config/config.php';

/**
 * Envía un mensaje de texto a un número de WhatsApp
 * @param string $to      Número de teléfono del destinatario (sin '+', solo dígitos)
 * @param string $message Texto del mensaje
 * @param int $retryCount Número de reintentos en caso de fallo
 * @return bool           True si se envió (o se simuló) correctamente
 */
function enviarMensajeWhatsApp($to, $message, $retryCount = 0) {
    // Limpiar número: eliminar cualquier caracter no numérico
    $to = preg_replace('/\D/', '', $to);

    // Validar que el número tenga longitud válida (al menos 8 dígitos)
    if (strlen($to) < 8) {
        registrarLog("Número de teléfono inválido (muy corto): $to", 'error', 'errores.log');
        whatsapp_log("Número inválido: $to", 'error');
        return false;
    }

    // Truncar mensaje si excede el límite
    if (strlen($message) > WHATSAPP_MAX_MESSAGE_LENGTH) {
        $message = substr($message, 0, WHATSAPP_MAX_MESSAGE_LENGTH - 100) . "\n\n[Mensaje truncado por exceder el límite...]";
        registrarLog("Mensaje truncado para $to (excedía " . WHATSAPP_MAX_MESSAGE_LENGTH . " caracteres)", 'warning');
    }

    whatsapp_log("Enviando a $to: " . substr($message, 0, 200) . (strlen($message) > 200 ? '...' : ''), 'info');
    registrarLog("Intento de envío WhatsApp a $to (retry $retryCount)", 'info', 'whatsapp_mensajes.log');

    // Modo simulación: solo registrar y retornar éxito
    if (WHATSAPP_MODE_SIMULATION) {
        registrarLog("SIMULACIÓN: Mensaje enviado a $to", 'info', 'whatsapp_mensajes.log');
        return true;
    }

    // ---- Envío real a través del puente Node (Baileys) ----
    $url = rtrim(WHATSAPP_BRIDGE_URL, '/') . '/enviar';

    $payload = json_encode([
        'secret'  => BRIDGE_SECRET,
        'numero'  => $to,
        'mensaje' => $message,
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, WHATSAPP_RESPONSE_TIMEOUT);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        registrarLog("Mensaje enviado correctamente a $to vía bot local. HTTP $httpCode", 'info', 'whatsapp_mensajes.log');
        whatsapp_log("Envío exitoso a $to", 'info');
        return true;
    }

    $errorMsg = "Error al enviar mensaje a $to vía bot local. HTTP $httpCode - Error: $error - Response: $response";
    registrarLog($errorMsg, 'error', 'errores.log');
    whatsapp_log($errorMsg, 'error');

    // Reintentar hasta 2 veces si el bot no respondió o hubo error de red/servidor
    if ($retryCount < 2 && ($httpCode === 0 || $httpCode >= 500 || strpos($error, 'timeout') !== false)) {
        sleep(2);
        return enviarMensajeWhatsApp($to, $message, $retryCount + 1);
    }

    return false;
}

/**
 * Envía un mensaje con opciones tipo lista (se simula como texto numerado,
 * ya que Baileys/WhatsApp Web no soporta templates de botones de Meta Business)
 * @param string $to      Número del destinatario
 * @param string $body    Texto del mensaje
 * @param array $buttons  Array de opciones
 * @return bool
 */
function enviarMensajeInteractivoWhatsApp($to, $body, $buttons) {
    $message = $body . "\n\n";
    foreach ($buttons as $index => $button) {
        $message .= ($index + 1) . ". " . $button . "\n";
    }
    return enviarMensajeWhatsApp($to, $message);
}
