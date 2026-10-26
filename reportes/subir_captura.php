<?php
/**
 * reportes/subir_captura.php
 *
 * Recibe (por AJAX, desde el botón "📎 Adjuntar captura de Chat" en
 * reportes/imprimir.php) una captura de pantalla REAL tomada a mano por el
 * usuario desde WhatsApp Web en su propia computadora, y la guarda
 * asociada al ticket para que aparezca como anexo del oficio.
 *
 * No genera ni simula nada: solo recibe el archivo de imagen que el
 * usuario ya capturó por su cuenta y lo persiste.
 *
 * Entrada: multipart/form-data
 *   - ticket_id       : int
 *   - captura         : archivo de imagen (jpg/jpeg/png/webp)
 *   - reemplazar_ruta : (opcional) ruta relativa de una captura ya
 *                       existente de este mismo ticket. Si se manda, en
 *                       vez de agregar una captura nueva se REEMPLAZA esa
 *                       en el mismo lugar de la lista (mismo tamaño
 *                       guardado), borrando el archivo físico anterior.
 *                       Lo usa el botón "✂️ Recortar de nuevo" del modo
 *                       edición en reportes/imprimir.php.
 *   - tamano          : (opcional) 'pequena' | 'mediana' | 'grande'.
 *                       Solo aplica a capturas NUEVAS (no reemplazos, que
 *                       conservan su tamaño); por defecto 'mediana'.
 *
 * Salida: JSON { success: bool, ruta?: string, error?: string }
 */

require_once dirname(__DIR__) . '/config/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

function responderCaptura($ok, $extra = []) {
    echo json_encode(array_merge(['success' => $ok], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true) {
    http_response_code(403);
    responderCaptura(false, ['error' => 'Debe iniciar sesión.']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    responderCaptura(false, ['error' => 'Método no permitido.']);
}

$ticketId = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
if ($ticketId <= 0) {
    responderCaptura(false, ['error' => 'ID de ticket inválido.']);
}

// Cargar ticket y verificar permiso (mismo criterio que reportes/generar.php:
// admin o propietario del ticket).
$tickets = [];
if (file_exists(TICKETS_JSON)) {
    $tickets = json_decode(file_get_contents(TICKETS_JSON), true) ?: [];
}
$ticket = null;
foreach ($tickets as $t) {
    if ($t['id'] == $ticketId) { $ticket = $t; break; }
}
if (!$ticket) {
    responderCaptura(false, ['error' => 'Ticket no encontrado.']);
}
$esAdmin = $_SESSION['admin'] ?? false;
$esPropietario = (isset($ticket['usuario_id']) && $ticket['usuario_id'] == $_SESSION['usuario_id']);
if (!$esAdmin && !$esPropietario) {
    http_response_code(403);
    responderCaptura(false, ['error' => 'No tiene permiso sobre este ticket.']);
}

if (!isset($_FILES['captura']) || $_FILES['captura']['error'] !== UPLOAD_ERR_OK) {
    $codigo = $_FILES['captura']['error'] ?? UPLOAD_ERR_NO_FILE;
    $msg = $codigo === UPLOAD_ERR_INI_SIZE || $codigo === UPLOAD_ERR_FORM_SIZE
        ? 'La imagen es demasiado grande.'
        : 'No se recibió ningún archivo.';
    responderCaptura(false, ['error' => $msg]);
}

$archivo = $_FILES['captura'];

// Límite propio de 10 MB (además del límite de PHP en php.ini).
if ($archivo['size'] > 10 * 1024 * 1024) {
    responderCaptura(false, ['error' => 'La imagen supera el límite de 10 MB.']);
}

// Validar que sea realmente una imagen (no solo confiar en el nombre/mime
// que manda el navegador) usando getimagesize().
$info = @getimagesize($archivo['tmp_name']);
if ($info === false) {
    responderCaptura(false, ['error' => 'El archivo no es una imagen válida.']);
}
$extensionesPermitidas = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];
$mime = $info['mime'];
if (!isset($extensionesPermitidas[$mime])) {
    responderCaptura(false, ['error' => 'Formato no permitido. Use JPG, PNG o WEBP.']);
}
$ext = $extensionesPermitidas[$mime];

// ¿Es un reemplazo en el mismo lugar (re-recorte de una captura ya
// existente) o una captura nueva? Si es reemplazo, la ruta indicada debe
// pertenecer estrictamente a este ticket (mismo formato de ruta que se
// genera más abajo: reportes/capturas_chat/{ticket_id}/archivo.ext),
// para no poder pisar ni borrar archivos de otro ticket.
$reemplazarRuta = isset($_POST['reemplazar_ruta']) ? trim((string) $_POST['reemplazar_ruta']) : '';
$prefijoValido = 'reportes/capturas_chat/' . $ticketId . '/';
if ($reemplazarRuta !== '' && strpos($reemplazarRuta, $prefijoValido) !== 0) {
    responderCaptura(false, ['error' => 'Ruta de reemplazo inválida.']);
}

$tamanosValidos = ['pequena', 'mediana', 'grande'];
$tamanoSolicitado = isset($_POST['tamano']) ? trim((string) $_POST['tamano']) : '';
if (!in_array($tamanoSolicitado, $tamanosValidos, true)) $tamanoSolicitado = null;

// Carpeta destino: reportes/capturas_chat/{ticket_id}/
$dirDestino = __DIR__ . '/capturas_chat/' . $ticketId;
if (!is_dir($dirDestino)) {
    mkdir($dirDestino, 0755, true);
}

$nombreArchivo = date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
$rutaAbsoluta = $dirDestino . '/' . $nombreArchivo;

if (!move_uploaded_file($archivo['tmp_name'], $rutaAbsoluta)) {
    responderCaptura(false, ['error' => 'No se pudo guardar la imagen en el servidor.']);
}

// Ruta relativa a la raíz web (sistema-web/), igual que se hace con las
// fotos del ciudadano en whatsapp/fotos/.
$rutaRelativa = 'reportes/capturas_chat/' . $ticketId . '/' . $nombreArchivo;

// Registrar en reportes/capturas_chat.json, por ticket, con bloqueo de
// archivo (mismo patrón que obtenerNumeroOficio() en oficio_html.php).
$rutaRegistro = __DIR__ . '/capturas_chat.json';
if (!file_exists($rutaRegistro)) {
    file_put_contents($rutaRegistro, json_encode(new stdClass(), JSON_PRETTY_PRINT));
}

$fp = fopen($rutaRegistro, 'c+');
if ($fp && flock($fp, LOCK_EX)) {
    $contenido = '';
    fseek($fp, 0);
    while (!feof($fp)) $contenido .= fread($fp, 8192);
    $datos = json_decode($contenido, true);
    if (!is_array($datos)) $datos = [];

    $clave = (string) $ticketId;
    if (!isset($datos[$clave]) || !is_array($datos[$clave])) $datos[$clave] = [];

    $rutaFisicaVieja = null;
    $indiceExistente = null;
    if ($reemplazarRuta !== '') {
        foreach ($datos[$clave] as $i => $entrada) {
            $rutaEntrada = is_array($entrada) ? ($entrada['ruta'] ?? '') : $entrada;
            if ($rutaEntrada === $reemplazarRuta) { $indiceExistente = $i; break; }
        }
    }

    if ($indiceExistente !== null) {
        // Reemplazo en el mismo lugar: conserva el tamaño ya guardado
        // (salvo que se haya pedido explícitamente otro) y actualiza
        // fecha/ruta/subido_por.
        $entradaVieja = $datos[$clave][$indiceExistente];
        $tamanoPrevio = is_array($entradaVieja) ? ($entradaVieja['tamano'] ?? 'mediana') : 'mediana';
        $rutaFisicaVieja = is_array($entradaVieja) ? ($entradaVieja['ruta'] ?? '') : $entradaVieja;

        $datos[$clave][$indiceExistente] = [
            'ruta'       => $rutaRelativa,
            'fecha'      => date('Y-m-d H:i:s'),
            'subido_por' => $_SESSION['nombre_completo'] ?? $_SESSION['username'] ?? 'Sistema',
            'tamano'     => $tamanoSolicitado ?? $tamanoPrevio,
        ];
    } else {
        $datos[$clave][] = [
            'ruta'       => $rutaRelativa,
            'fecha'      => date('Y-m-d H:i:s'),
            'subido_por' => $_SESSION['nombre_completo'] ?? $_SESSION['username'] ?? 'Sistema',
            'tamano'     => $tamanoSolicitado ?? 'mediana',
        ];
    }

    ftruncate($fp, 0);
    fseek($fp, 0);
    fwrite($fp, json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    // Ya con el registro actualizado y a salvo, se borra el archivo físico
    // viejo (solo si el reemplazo se registró correctamente, para no
    // perder la imagen si algo falla antes).
    if ($rutaFisicaVieja) {
        $rutaFisicaVieja = dirname(__DIR__) . '/' . $rutaFisicaVieja;
        if (strpos(realpath(dirname($rutaFisicaVieja)) ?: '', realpath(__DIR__ . '/capturas_chat') ?: "\0") === 0
            && is_file($rutaFisicaVieja)) {
            @unlink($rutaFisicaVieja);
        }
    }
} else {
    if ($fp) fclose($fp);
    // La imagen ya se guardó en disco; si el registro falla, igual
    // avisamos éxito parcial para no perder el archivo subido, pero lo
    // señalamos.
    responderCaptura(true, [
        'ruta' => $rutaRelativa,
        'aviso' => 'La imagen se guardó pero no se pudo actualizar el registro; contacte soporte si no aparece en el oficio.',
    ]);
}

responderCaptura(true, ['ruta' => $rutaRelativa]);
