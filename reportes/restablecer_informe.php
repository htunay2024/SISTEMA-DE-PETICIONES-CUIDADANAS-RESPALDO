<?php
/**
 * reportes/restablecer_informe.php
 *
 * Elimina la edición manual guardada (ver reportes/guardar_edicion_informe.php)
 * de un mes/año concreto del informe mensual, para que
 * reportes/informe_mensual.php vuelva a generarlo desde cero con la
 * plantilla vigente en vez de seguir mostrando el HTML que quedó
 * congelado la última vez que alguien lo editó y guardó.
 *
 * Esto hace falta, por ejemplo, cuando se corrige algo en la plantilla
 * (informe_mensual.php / informe_mensual.css) después de que ya se había
 * guardado una edición manual para ese mes: mientras esa edición exista,
 * el informe seguirá mostrando la versión vieja, sin la corrección.
 *
 * Recibe (POST, JSON):
 *   - mes  : int → mes (1-12) del informe a restablecer.
 *   - anio : int → año del informe a restablecer.
 *
 * Acceso: solo administradores autenticados (mismo criterio que
 * reportes/guardar_edicion_informe.php).
 */

require_once dirname(__DIR__) . '/config/config.php';
session_start();

header('Content-Type: application/json; charset=utf-8');

function responder($ok, $extra = []) {
    echo json_encode(array_merge(['success' => $ok], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true) {
    http_response_code(401);
    responder(false, ['error' => 'No ha iniciado sesión.']);
}

$esAdmin = $_SESSION['admin'] ?? false;
if (!$esAdmin) {
    http_response_code(403);
    responder(false, ['error' => 'Solo un administrador puede restablecer el informe mensual.']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    responder(false, ['error' => 'Método no permitido.']);
}

$input = json_decode(file_get_contents('php://input'), true);
$mes  = isset($input['mes'])  ? intval($input['mes'])  : 0;
$anio = isset($input['anio']) ? intval($input['anio']) : 0;

if ($mes < 1 || $mes > 12) {
    responder(false, ['error' => 'Mes inválido.']);
}
if ($anio < 2020 || $anio > 2100) {
    responder(false, ['error' => 'Año inválido.']);
}

$clave = $mes . '-' . $anio;
$archivo = __DIR__ . '/informes_editados.json';

if (!file_exists($archivo)) {
    // No hay ninguna edición guardada de ningún mes: no hay nada que
    // restablecer, pero no es un error.
    responder(true, ['existia' => false]);
}

$fp = fopen($archivo, 'c+');
if (!$fp) {
    responder(false, ['error' => 'No se pudo abrir el archivo de ediciones.']);
}

$existia = false;
$listo = false;
if (flock($fp, LOCK_EX)) {
    $contenido = '';
    fseek($fp, 0);
    while (!feof($fp)) $contenido .= fread($fp, 8192);
    $datos = json_decode($contenido, true);
    if (!is_array($datos)) $datos = [];

    if (isset($datos[$clave])) {
        $existia = true;
        unset($datos[$clave]);
    }

    ftruncate($fp, 0);
    fseek($fp, 0);
    fwrite($fp, json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    $listo = true;
}
fclose($fp);

if (!$listo) {
    responder(false, ['error' => 'No se pudo restablecer el informe (archivo en uso, intente de nuevo).']);
}

if ($existia && function_exists('registrarLog')) {
    registrarLog("Informe mensual $mes-$anio restablecido (se descartó su edición manual guardada) por " . ($_SESSION['nombre_completo'] ?? $_SESSION['username'] ?? 'Usuario del sistema'), 'info', 'app.log');
}

responder(true, ['existia' => $existia]);
