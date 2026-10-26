<?php
/**
 * reportes/guardar_edicion_informe.php
 *
 * Guarda la edición manual que un administrador hace sobre el informe
 * mensual (reportes/informe_mensual.php) DESPUÉS de generarlo, usando el
 * botón "✏️ Editar" de esa misma pantalla — mismo patrón que ya usa
 * reportes/guardar_edicion.php para los oficios individuales.
 *
 * Recibe (POST, JSON):
 *   - mes  : int → mes (1-12) del informe editado.
 *   - anio : int → año del informe editado.
 *   - html : string → HTML editado del contenido del informe (el mismo
 *                      bloque #informe-contenido que informe_mensual.php
 *                      vuelve editable, incluye ambas páginas).
 *
 * Persiste el HTML editado en reportes/informes_editados.json, indexado
 * por la clave "mes-anio" (mismo patrón de bloqueo de archivo que ya usa
 * obtenerNumeroOficio() en oficio_html.php y guardar_edicion.php). La
 * próxima vez que se abra el informe de ese mismo mes/año,
 * reportes/informe_mensual.php detecta esta edición guardada y la
 * muestra en vez de regenerar el informe desde cero.
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

// El informe mensual solo es accesible para administradores (mismo
// criterio que reportes/informe_mensual.php), así que guardar ediciones
// también se restringe a ellos.
$esAdmin = $_SESSION['admin'] ?? false;
if (!$esAdmin) {
    http_response_code(403);
    responder(false, ['error' => 'Solo un administrador puede editar el informe mensual.']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    responder(false, ['error' => 'Método no permitido.']);
}

$input = json_decode(file_get_contents('php://input'), true);
$mes  = isset($input['mes'])  ? intval($input['mes'])  : 0;
$anio = isset($input['anio']) ? intval($input['anio']) : 0;
$htmlEditado = isset($input['html']) ? (string) $input['html'] : '';

if ($mes < 1 || $mes > 12) {
    responder(false, ['error' => 'Mes inválido.']);
}
if ($anio < 2020 || $anio > 2100) {
    responder(false, ['error' => 'Año inválido.']);
}
if (trim($htmlEditado) === '') {
    responder(false, ['error' => 'El contenido del informe está vacío.']);
}
// Límite razonable de tamaño (~4 MB, el informe puede traer una tabla
// larga) para evitar que un error del cliente llene el disco.
if (strlen($htmlEditado) > 4 * 1024 * 1024) {
    responder(false, ['error' => 'El contenido editado es demasiado grande.']);
}

/**
 * Mismo saneamiento básico que guardar_edicion.php: se permite formato
 * (el usuario edita texto dentro de un contenteditable) pero se eliminan
 * etiquetas y atributos que podrían ejecutar código si otro usuario
 * vuelve a abrir este mismo informe (scripts, iframes, manejadores de
 * eventos "on..." y URLs "javascript:").
 */
function sanearHtmlInforme($html) {
    $html = preg_replace('/<(script|iframe|object|embed|style)\b[^>]*>.*?<\/\1>/is', '', $html);
    $html = preg_replace('/<\/?(script|iframe|object|embed|style)\b[^>]*>/i', '', $html);
    $html = preg_replace('/\son\w+\s*=\s*"[^"]*"/i', '', $html);
    $html = preg_replace('/\son\w+\s*=\s*\'[^\']*\'/i', '', $html);
    $html = preg_replace('/(href|src)\s*=\s*"(?:\s*javascript:)[^"]*"/i', '$1="#"', $html);
    $html = preg_replace('/(href|src)\s*=\s*\'(?:\s*javascript:)[^\']*\'/i', "\$1='#'", $html);
    return $html;
}

$htmlEditado = sanearHtmlInforme($htmlEditado);

/**
 * "Aprendizaje" de correcciones: recorre el HTML editado buscando los
 * elementos marcados con data-learn-id (títulos, encabezados de sección,
 * párrafos fijos, firma y encabezados de la tabla — ver
 * reportes/informe_mensual.php) y devuelve un mapa [data-learn-id => texto
 * actual]. Ese mapa se guarda en reportes/correcciones_aprendidas.json y,
 * de ahí en adelante, informe_mensual.php usa esos textos corregidos en
 * vez de los de la plantilla original en TODOS los informes futuros
 * (cualquier mes/año sin su propia edición guardada todavía).
 */
function extraerCorreccionesAprendidas($html) {
    $mapa = [];
    if (trim($html) === '') return $mapa;

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML(
        '<?xml encoding="utf-8" ?><div>' . $html . '</div>',
        LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NOBLANKS
    );
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $nodos = $xpath->query('//*[@data-learn-id]');
    if ($nodos === false) return $mapa;

    foreach ($nodos as $nodo) {
        $id = trim($nodo->getAttribute('data-learn-id'));
        if ($id === '') continue;
        $texto = trim($nodo->textContent);
        if ($texto === '') continue;
        $mapa[$id] = $texto;
    }
    return $mapa;
}

$correccionesDetectadas = extraerCorreccionesAprendidas($htmlEditado);

$clave = $mes . '-' . $anio;

$archivo = __DIR__ . '/informes_editados.json';
if (!file_exists($archivo)) {
    file_put_contents($archivo, json_encode(new stdClass(), JSON_PRETTY_PRINT));
}

$fp = fopen($archivo, 'c+');
if (!$fp) {
    responder(false, ['error' => 'No se pudo abrir el archivo de ediciones.']);
}

$guardado = false;
if (flock($fp, LOCK_EX)) {
    $contenido = '';
    fseek($fp, 0);
    while (!feof($fp)) $contenido .= fread($fp, 8192);
    $datos = json_decode($contenido, true);
    if (!is_array($datos)) $datos = [];

    $editor = $_SESSION['nombre_completo'] ?? $_SESSION['username'] ?? 'Usuario del sistema';
    $datos[$clave] = [
        'html'        => $htmlEditado,
        'editado_por' => $editor,
        'fecha'       => date('Y-m-d H:i:s'),
    ];

    ftruncate($fp, 0);
    fseek($fp, 0);
    fwrite($fp, json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    $guardado = true;
}
fclose($fp);

if (!$guardado) {
    responder(false, ['error' => 'No se pudo guardar la edición (archivo en uso, intente de nuevo).']);
}

// ------------------------------------------------------------
// Persistir las correcciones "aprendidas" (títulos, encabezados y
// párrafos fijos) para que se apliquen automáticamente en los próximos
// informes, además de en este mes/año en particular.
// ------------------------------------------------------------
if (!empty($correccionesDetectadas)) {
    $archivoAprendido = __DIR__ . '/correcciones_aprendidas.json';
    if (!file_exists($archivoAprendido)) {
        file_put_contents($archivoAprendido, json_encode(new stdClass(), JSON_PRETTY_PRINT));
    }

    $fpAprendido = fopen($archivoAprendido, 'c+');
    if ($fpAprendido && flock($fpAprendido, LOCK_EX)) {
        $contenidoAprendido = '';
        fseek($fpAprendido, 0);
        while (!feof($fpAprendido)) $contenidoAprendido .= fread($fpAprendido, 8192);
        $aprendidas = json_decode($contenidoAprendido, true);
        if (!is_array($aprendidas)) $aprendidas = [];

        foreach ($correccionesDetectadas as $id => $texto) {
            $aprendidas[$id] = $texto;
        }

        ftruncate($fpAprendido, 0);
        fseek($fpAprendido, 0);
        fwrite($fpAprendido, json_encode($aprendidas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($fpAprendido);
        flock($fpAprendido, LOCK_UN);
    }
    if ($fpAprendido) fclose($fpAprendido);
}

if (function_exists('registrarLog')) {
    registrarLog("Informe mensual $mes-$anio editado manualmente por " . ($_SESSION['nombre_completo'] ?? $_SESSION['username'] ?? 'Usuario del sistema'), 'info', 'app.log');
}

responder(true, ['fecha' => date('Y-m-d H:i:s')]);
