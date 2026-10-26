<?php
/**
 * whatsapp/estado_bot.php
 *
 * Endpoint AJAX (solo administradores) usado por vistas/whatsapp_admin.php
 * para consultar en tiempo real:
 *   - Si el bot de WhatsApp está conectado y con qué número.
 *   - El código QR pendiente de escanear (si aún no está vinculado).
 *   - Si el "modo prueba" (autoenvío al chat "Tú") está activado o no.
 *   - La velocidad de escritura (caracteres por segundo) configurada.
 *   - La pausa entre burbujas (segundos) configurada.
 *   - La espera (debounce) al recibir el primer mensaje de una conversación nueva.
 *   - Los números "excluidos" (contactos de la municipalidad a los que el
 *     bot nunca les responde automáticamente).
 *
 * Es de solo lectura: no requiere el BRIDGE_SECRET porque solo reenvía
 * datos del bot (que corre en localhost) hacia el navegador del admin.
 * El acceso está protegido por sesión de administrador.
 *
 * IMPORTANTE: este endpoint SIEMPRE debe devolver JSON puro. Si PHP llega
 * a imprimir un warning/notice antes del JSON (por ejemplo por algún error
 * de configuración), el navegador no puede interpretar la respuesta y el
 * panel muestra "Error de conexión" aunque el bot esté funcionando bien.
 * Por eso capturamos cualquier salida accidental con un buffer y la
 * descartamos antes de imprimir el JSON real.
 */

// No mostrar errores en pantalla (romperían el JSON); si algo falla, que
// quede registrado en el log de errores de PHP, no en la respuesta.
ini_set('display_errors', '0');
error_reporting(E_ALL);

session_start();
header('Content-Type: application/json; charset=utf-8');

// Buffer de salida: cualquier cosa que PHP imprima por error (warnings,
// notices, HTML, etc.) queda atrapada acá y se descarta antes del JSON.
ob_start();

if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true || !($_SESSION['admin'] ?? false)) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}

require_once __DIR__ . '/config_whatsapp.php';

// Timeout corto (a propósito): este endpoint se pollea cada pocos segundos
// desde el panel admin, y el servidor embebido de PHP (`php -S`) solo puede
// atender una petición a la vez. Si esto se quedara esperando 8 segundos
// completos (el timeout por defecto), ese tiempo se lo estaría "robando" a
// cualquier otra petición real que llegue mientras tanto — incluyendo los
// mensajes de los ciudadanos que llegan por recibir_bot.php.
$estado = solicitarAlBotWhatsApp('/estado', 'GET', false, [], 4);
$qr = solicitarAlBotWhatsApp('/qr', 'GET', false, [], 4);

ob_end_clean(); // descarta cualquier salida accidental generada hasta acá

if (!$estado['ok'] && !$qr['ok']) {
    echo json_encode([
        'ok' => false,
        'bot_activo' => false,
        'error' => $estado['error'] ?? 'El bot de WhatsApp no está corriendo. Iniciá whatsapp-bot con "npm start".',
    ]);
    exit;
}

echo json_encode([
    'ok' => true,
    'bot_activo' => true,
    'conectado' => $estado['data']['conectado'] ?? false,
    'numero' => $estado['data']['numero'] ?? null,
    'ultimo_error' => $estado['data']['ultimoError'] ?? null,
    'qr' => $qr['data']['qr'] ?? null,
    'modo_prueba' => $estado['data']['modoPrueba'] ?? false,
    'velocidad_escritura' => $estado['data']['velocidadEscritura'] ?? null,
    'velocidad_escritura_min' => $estado['data']['velocidadEscrituraMinima'] ?? null,
    'velocidad_escritura_max' => $estado['data']['velocidadEscrituraMaxima'] ?? null,
    'pausa_entre_burbujas' => $estado['data']['pausaEntreBurbujas'] ?? null,
    'pausa_entre_burbujas_min' => $estado['data']['pausaEntreBurbujasMinima'] ?? null,
    'pausa_entre_burbujas_max' => $estado['data']['pausaEntreBurbujasMaxima'] ?? null,
    'debounce_inicio_ms' => $estado['data']['debounceInicioMs'] ?? null,
    'debounce_inicio_min' => $estado['data']['debounceInicioMin'] ?? null,
    'debounce_inicio_max' => $estado['data']['debounceInicioMax'] ?? null,
    'numeros_excluidos' => $estado['data']['numerosExcluidos'] ?? [],
]);
