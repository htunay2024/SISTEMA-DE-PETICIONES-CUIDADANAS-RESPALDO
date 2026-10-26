<?php
/**
 * whatsapp/sesion_utils.php
 *
 * Funciones compartidas para manejar el archivo de sesión (estado de la
 * conversación) y el historial de conversación de cada número de
 * WhatsApp. Se extrajeron de whatsapp/recibir_bot.php a este archivo
 * aparte para poder reutilizarlas también desde
 * whatsapp/verificar_expiracion.php (el barrido que cancela solicitudes
 * abandonadas por más de una hora), sin duplicar código.
 *
 * Incluir siempre con: require_once __DIR__ . '/sesion_utils.php';
 */

if (!function_exists('registrarLog')) {
    function registrarLog($mensaje, $nivel = 'info', $archivo = 'app.log') {
        $dir = __DIR__ . '/logs';
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        $linea = '[' . date('Y-m-d H:i:s') . "] [$nivel] $mensaje" . PHP_EOL;
        @file_put_contents($dir . '/' . $archivo, $linea, FILE_APPEND);
    }
}

// ------------------------------------------------------------
// Separador de fragmentos para respuestas "en varios mensajes" (ver uso
// en recibir_bot.php: armarRespuestaFragmentada()).
// ------------------------------------------------------------
if (!defined('SEP_FRAGMENTO')) {
    define('SEP_FRAGMENTO', "\x01");
}

if (!function_exists('armarRespuestaFragmentada')) {
    function armarRespuestaFragmentada(array $partes) {
        $partes = array_values(array_filter(array_map('trim', $partes), function ($p) {
            return $p !== '';
        }));
        return implode(SEP_FRAGMENTO, $partes);
    }
}

// ------------------------------------------------------------
// Expiración de solicitudes abandonadas
// ------------------------------------------------------------
// Tiempo de inactividad (en segundos) a partir del cual una solicitud que
// quedó a medias (el ciudadano dejó de responder en medio del proceso de
// dar sus datos) se considera abandonada y se cancela automáticamente.
if (!defined('EXPIRACION_SESION_SEGUNDOS')) {
    define('EXPIRACION_SESION_SEGUNDOS', 3600); // 1 hora
}

if (!defined('RUTA_SESIONES_DIR')) {
    define('RUTA_SESIONES_DIR', __DIR__ . '/sesiones');
}

if (!is_dir(RUTA_SESIONES_DIR)) {
    @mkdir(RUTA_SESIONES_DIR, 0777, true);
}

function rutaSesion($numero) {
    return RUTA_SESIONES_DIR . '/' . preg_replace('/[^0-9]/', '', $numero) . '.json';
}

function cargarSesion($numero) {
    $archivo = rutaSesion($numero);
    if (file_exists($archivo)) {
        $datos = json_decode(file_get_contents($archivo), true);
        if (is_array($datos)) return $datos;
    }
    return [
        'fase' => 'inicio',
        'texto_peticion' => null,
        'clasificacion' => null,
        'nombre' => null,
        'dpi' => null,
        'telefono' => null,
        'direccion' => null,
        'fotos' => [],
        'ia_desactivada' => false,
        'intentos_sin_clasificar' => 0,
        // Marca de tiempo (timestamp UNIX) de la última actividad de este
        // número. Se actualiza automáticamente en guardarSesion(). Sirve
        // para detectar solicitudes abandonadas: ver sesionEstaExpirada().
        'ultima_actividad' => time(),
    ];
}

function guardarSesion($numero, $sesion) {
    $sesion['ultima_actividad'] = time();
    file_put_contents(rutaSesion($numero), json_encode($sesion, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function borrarSesion($numero) {
    $archivo = rutaSesion($numero);
    if (file_exists($archivo)) @unlink($archivo);
}

/**
 * True si la sesión quedó "a medias" (se empezó una solicitud pero no se
 * confirmó) y pasó más de EXPIRACION_SESION_SEGUNDOS desde su última
 * actividad.
 *
 * Se excluyen a propósito: la fase 'inicio' (no hay nada en curso que
 * cancelar) y las sesiones en atención humana (ia_desactivada = true),
 * porque si alguien de la Municipalidad ya está atendiendo esa
 * conversación manualmente, el sistema no debe cancelarla solo.
 */
function sesionEstaExpirada($sesion) {
    if (($sesion['fase'] ?? 'inicio') === 'inicio') return false;
    if (!empty($sesion['ia_desactivada'])) return false;
    $ultima = $sesion['ultima_actividad'] ?? time();
    return (time() - $ultima) > EXPIRACION_SESION_SEGUNDOS;
}

/**
 * Mensaje amable (elegido al azar) para avisarle al ciudadano que su
 * solicitud anterior se canceló automáticamente por inactividad.
 */
function mensajeExpiracionAleatorio() {
    $mensajes = [
        armarRespuestaFragmentada([
            "Hola de nuevo 🙂",
            "Como pasó más de una hora sin que pudiéramos terminar de armar tu solicitud anterior, la cancelamos automáticamente para no dejarla a medias.",
            "No hay ningún problema: si todavía necesitás reportarlo, contame de nuevo qué está pasando y lo retomamos.",
        ]),
        armarRespuestaFragmentada([
            "Notamos que pasó bastante tiempo sin respuesta, así que tu solicitud anterior quedó cancelada automáticamente.",
            "Cuando quieras, podés escribirme de nuevo para empezar otra vez, con gusto te ayudo. 🙏",
        ]),
        armarRespuestaFragmentada([
            "Tu solicitud anterior se canceló por inactividad (pasó más de una hora sin novedades).",
            "No te preocupes, no se perdió nada importante. Si aún necesitás ayuda, contame de nuevo el problema y seguimos. 😊",
        ]),
    ];
    return $mensajes[array_rand($mensajes)];
}

// ------------------------------------------------------------
// Historial de conversación (evidencia para el oficio)
// ------------------------------------------------------------
function rutaConversacion($numero) {
    $dir = RUTA_SESIONES_DIR . '/conversaciones';
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    return $dir . '/' . preg_replace('/[^0-9]/', '', $numero) . '.jsonl';
}

function registrarTurnoConversacion($numero, $mensaje, $respuesta) {
    $turno = [
        'hora'      => date('Y-m-d H:i:s'),
        'ciudadano' => $mensaje,
        'bot'       => $respuesta,
    ];
    @file_put_contents(rutaConversacion($numero), json_encode($turno, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function leerConversacion($numero) {
    $archivo = rutaConversacion($numero);
    if (!file_exists($archivo)) return [];
    $lineas = file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lineas === false) return [];
    $turnos = [];
    foreach ($lineas as $linea) {
        $t = json_decode($linea, true);
        if (is_array($t)) $turnos[] = $t;
    }
    return $turnos;
}

function borrarConversacion($numero) {
    $archivo = rutaConversacion($numero);
    if (file_exists($archivo)) @unlink($archivo);
}

/**
 * Lista los números que tienen actualmente un archivo de sesión guardado
 * (conversación en curso). La usa whatsapp/verificar_expiracion.php para
 * recorrerlos y detectar cuáles ya expiraron.
 */
function listarNumerosConSesion() {
    $archivos = glob(RUTA_SESIONES_DIR . '/*.json');
    if ($archivos === false) return [];
    $numeros = [];
    foreach ($archivos as $archivo) {
        $numeros[] = basename($archivo, '.json');
    }
    return $numeros;
}