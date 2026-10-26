<?php
/**
 * reportes/correlativo_oficio.php
 *
 * Permite a un administrador definir desde qué número correlativo debe
 * empezar a numerar el sistema el "Oficio No. X-ACC" que se imprime en
 * cada expediente (ver reportes/oficio_html.php -> obtenerNumeroOficio()).
 *
 * Ese número ya era correlativo/automático (se guarda en
 * reportes/oficios_emitidos.json, campo "ultimo_numero"), pero no había
 * forma de indicarle al sistema en qué número continuar — por ejemplo,
 * si en papel ya se llevan oficios hasta el "31-ACC" y se quiere que el
 * sistema continúe desde el 32 en vez de reiniciar desde el 1.
 *
 * El campo del panel de administración acepta letras y números (por
 * ejemplo, se puede pegar directamente "32-ACC" o solo "32"): se toma
 * automáticamente el último grupo de dígitos que contenga el texto
 * ingresado.
 */

require_once dirname(__DIR__) . '/config/config.php';

// Mismo archivo que usa reportes/oficio_html.php -> obtenerNumeroOficio()
// para asignar y recordar el número de oficio de cada expediente.
if (!defined('OFICIOS_EMITIDOS_JSON')) {
    define('OFICIOS_EMITIDOS_JSON', __DIR__ . '/oficios_emitidos.json');
}

/**
 * Extrae el último grupo de dígitos de un texto (permite que el
 * administrador ingrese letras y números, ej. "32-ACC").
 *
 * @param string $texto
 * @return int|null Número extraído, o null si el texto no contiene dígitos.
 */
if (!function_exists('extraerUltimoNumeroDeTexto')) {
function extraerUltimoNumeroDeTexto($texto) {
    if (preg_match_all('/\d+/', (string) $texto, $coincidencias) && !empty($coincidencias[0])) {
        return intval(end($coincidencias[0]));
    }
    return null;
}
}

/**
 * Obtiene el próximo número de oficio que se asignaría (el que le tocaría
 * al siguiente expediente que se imprima), para mostrarlo de referencia
 * en el panel de administración.
 * @return int
 */
function obtenerProximoNumeroOficio() {
    if (!file_exists(OFICIOS_EMITIDOS_JSON)) {
        return 1;
    }
    $contenido = file_get_contents(OFICIOS_EMITIDOS_JSON);
    $datos = json_decode($contenido, true);
    if (!is_array($datos) || !isset($datos['ultimo_numero'])) {
        return 1;
    }
    return max(0, intval($datos['ultimo_numero'])) + 1;
}

/**
 * Define el próximo número de oficio a usar para el siguiente expediente
 * que se imprima (Oficio No. X-ACC), usando bloqueo exclusivo del archivo.
 * Conserva los números ya asignados a expedientes anteriores
 * (campo "asignados"), solo ajusta desde dónde continúa la numeración.
 *
 * @param string|int $valorTexto Puede ser un número puro ("32") o texto
 *                                con letras y números ("32-ACC"), del cual
 *                                se toma el último grupo de dígitos.
 * @param int|null $usuarioId ID del usuario administrador que lo configura (para el log).
 * @return array{ok: bool, valor?: int, error?: string}
 */
function establecerProximoNumeroOficio($valorTexto, $usuarioId = null) {
    $valor = extraerUltimoNumeroDeTexto($valorTexto);
    if ($valor === null || $valor < 1) {
        return ['ok' => false, 'error' => 'Ingrese un número correlativo válido (puede incluir letras, ej. 32-ACC)'];
    }

    if (!file_exists(OFICIOS_EMITIDOS_JSON)) {
        file_put_contents(OFICIOS_EMITIDOS_JSON, json_encode(['ultimo_numero' => 0, 'asignados' => new stdClass()], JSON_PRETTY_PRINT));
    }

    $fp = fopen(OFICIOS_EMITIDOS_JSON, 'c+');
    if (!$fp) {
        return ['ok' => false, 'error' => 'No se pudo abrir el archivo de correlativo'];
    }

    $guardado = false;
    if (flock($fp, LOCK_EX)) {
        $contenido = '';
        fseek($fp, 0);
        while (!feof($fp)) $contenido .= fread($fp, 8192);
        $datos = json_decode($contenido, true);
        if (!is_array($datos)) $datos = [];
        if (!isset($datos['asignados']) || !is_array($datos['asignados'])) $datos['asignados'] = [];

        // El siguiente oficio a asignar debe ser $valor, así que el
        // "último número" usado (justo antes de ese) es $valor - 1.
        $datos['ultimo_numero'] = $valor - 1;

        ftruncate($fp, 0);
        fseek($fp, 0);
        if (fwrite($fp, json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false) {
            $guardado = true;
            if (function_exists('registrarLog')) {
                registrarLog("Correlativo de número de oficio configurado para continuar en #$valor por usuario administrador ID: $usuarioId", 'info', 'tickets.log');
            }
        }
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);

    if (!$guardado) {
        return ['ok' => false, 'error' => 'No se pudo guardar el correlativo'];
    }
    return ['ok' => true, 'valor' => $valor];
}

// API para peticiones POST (desde el panel de administración)
if (basename($_SERVER['SCRIPT_FILENAME']) == 'correlativo_oficio.php' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    session_start();
    if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true) {
        http_response_code(403);
        echo json_encode(['error' => 'No autorizado']);
        exit;
    }

    // Solo administradores pueden cambiar el correlativo
    $esAdmin = $_SESSION['admin'] ?? false;
    if (!$esAdmin) {
        http_response_code(403);
        echo json_encode(['error' => 'Solo un administrador puede configurar el correlativo']);
        exit;
    }

    $valorTexto = $_POST['proximo_numero'] ?? '';
    $usuarioId = $_SESSION['usuario_id'] ?? null;
    $resultado = establecerProximoNumeroOficio($valorTexto, $usuarioId);

    if ($resultado['ok']) {
        echo json_encode(['success' => true, 'proximo_numero' => $resultado['valor']]);
    } else {
        echo json_encode(['error' => $resultado['error']]);
    }
    exit;
}
?>
