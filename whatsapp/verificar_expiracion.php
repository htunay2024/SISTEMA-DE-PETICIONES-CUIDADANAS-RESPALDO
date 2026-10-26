<?php
/**
 * whatsapp/verificar_expiracion.php
 *
 * Barrido periódico que cancela automáticamente las solicitudes que un
 * ciudadano dejó a medias (empezó a dar sus datos pero no confirmó) y que
 * llevan más de 1 hora sin actividad. A cada uno se le envía un mensaje
 * de WhatsApp avisándole, de forma amable, que su solicitud se canceló
 * por inactividad y que puede volver a escribir cuando quiera.
 *
 * CÓMO PROGRAMARLO (Programador de tareas de Windows)
 * -----------------------------------------------------
 *   Programa/script:
 *     C:\...\SISTEMA_PETICIONES_CUIDADANAS_PAQUETE\sistema-web\php\php.exe
 *   Argumentos:
 *     "C:\...\SISTEMA_PETICIONES_CUIDADANAS_PAQUETE\sistema-web\whatsapp\verificar_expiracion.php"
 *   Repetir la tarea cada: 10-15 minutos, indefinidamente.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/config_whatsapp.php';
require_once __DIR__ . '/sesion_utils.php';

/**
 * Envía un mensaje de WhatsApp a través del puente Node (Baileys).
 * Usa la misma lógica que whatsapp/enviar.php.
 */
function enviarMensajeWhatsApp($numero, $mensaje) {
    $to = preg_replace('/\D/', '', $numero);
    if (strlen($to) < 8) {
        registrarLog("Número inválido para enviar aviso: $to", 'error', 'errores.log');
        return false;
    }

    $url = rtrim(WHATSAPP_BRIDGE_URL, '/') . '/enviar';
    $payload = json_encode([
        'secret'  => BRIDGE_SECRET,
        'numero'  => $to,
        'mensaje' => $mensaje,
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    } else {
        registrarLog("Error al enviar aviso a $to: HTTP $httpCode, error: $error", 'error', 'errores.log');
        return false;
    }
}

$numeros = listarNumerosConSesion();
$canceladas = 0;

foreach ($numeros as $numero) {
    $sesion = cargarSesion($numero);
    if (!sesionEstaExpirada($sesion)) continue;

    $mensaje = mensajeExpiracionAleatorio();
    $mensajeTexto = str_replace(SEP_FRAGMENTO, "\n\n", $mensaje);

    $enviado = enviarMensajeWhatsApp($numero, $mensajeTexto);

    borrarSesion($numero);
    borrarConversacion($numero);
    $canceladas++;

    registrarLog(
        "Solicitud de $numero cancelada por inactividad (más de " . (EXPIRACION_SESION_SEGUNDOS / 60) .
        " min sin actividad). Aviso enviado: " . ($enviado ? 'sí' : 'no, revisar errores.log') . ".",
        'info', 'ia.log'
    );
}

echo "Revisión completada. Sesiones canceladas por inactividad: {$canceladas}." . PHP_EOL;