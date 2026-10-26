<?php
/**
 * reportes/generar.php
 * Genera el reporte imprimible de una petición ("oficio" formal), a partir
 * de un ticket guardado en tickets.json.
 * Uso: incluir/visitar este script con ?id=123
 * Acceso: solo usuarios autenticados (admin o propietario del ticket)
 *
 * @version 2.0 — el reporte ahora usa el mismo formato de "oficio" que ya
 *                se veía en el chat web de IA 2 (membrete institucional,
 *                banda de urgencia, secciones I-VII, firmas, distribución
 *                de copias — ver IA 2/clasificar_y_generar.php y
 *                IA 2/generar_solicitud.js, de donde se adaptó). El HTML
 *                del oficio se arma con oficio_html.php y se estiliza con
 *                oficio.css (ambos en esta misma carpeta). La plantilla
 *                simple anterior (formato_reporte.html) queda sin usar.
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once __DIR__ . '/oficio_html.php';

// Puede llegar aquí por acceso directo (reportes/generar.php?id=X) o
// incluido desde imprimir.php (que ya inició sesión antes de incluir este
// archivo). session_start() con una sesión ya activa dispara un warning
// que ensuciaría el HTML del reporte, así que solo la iniciamos si hace
// falta.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Verificar autenticación
if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Acceso denegado. Debe iniciar sesión.';
    exit;
}

$ticketId = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($ticketId <= 0) {
    die('ID de ticket inválido.');
}

// Cargar ticket
$tickets = [];
if (file_exists(TICKETS_JSON)) {
    $contenido = file_get_contents(TICKETS_JSON);
    $tickets = json_decode($contenido, true) ?: [];
}

$ticket = null;
foreach ($tickets as $t) {
    if ($t['id'] == $ticketId) {
        $ticket = $t;
        break;
    }
}

if (!$ticket) {
    die('Ticket no encontrado.');
}

// Verificar permisos: admin o propietario del ticket
$esAdmin = $_SESSION['admin'] ?? false;
$esPropietario = (isset($ticket['usuario_id']) && $ticket['usuario_id'] == $_SESSION['usuario_id']);
if (!$esAdmin && !$esPropietario) {
    header('HTTP/1.1 403 Forbidden');
    die('No tiene permiso para generar este reporte.');
}

// ------------------------------------------------------------
// Las consultas informativas NO generan oficio.
//
// Cuando el ciudadano solo hace una pregunta (sobre un evento, un
// trámite, un horario, una feria, un curso, etc.) y no reporta un
// problema de servicios públicos, recibir_bot.php crea el ticket con
// categoría 'informacion_ciudadana' y el flag 'tipo_informacion'.
// Ese ticket SÍ debe quedar registrado (para admin_tickets.php y el
// informe mensual), pero no tiene sentido emitir un "Oficio interno"
// como si fuera una petición de intervención municipal. Acá se corta
// el flujo con un aviso claro en vez de generar el oficio.
// ------------------------------------------------------------
if (!empty($ticket['tipo_informacion'])
    || oficioEsConsultaSinOficio($ticket['mensaje_original'] ?? '', $ticket['categoria'] ?? '', $ticket['direccion'] ?? '')) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
        . '<title>Consulta informativa — sin oficio</title>'
        . '<style>body{font-family:"Segoe UI",Tahoma,sans-serif;background:#f0f2f5;padding:40px 20px;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}'
        . '.card{background:#fff;max-width:540px;padding:32px;border-radius:14px;box-shadow:0 4px 18px rgba(0,0,0,.1);text-align:center}'
        . '.icon{font-size:46px;margin-bottom:14px}'
        . 'h2{margin:0 0 10px;color:#2d3748;font-size:20px}'
        . 'p{color:#4a5568;line-height:1.6;margin:0 0 20px;font-size:14px}'
        . 'a{display:inline-block;padding:10px 22px;background:#667eea;color:#fff;text-decoration:none;border-radius:8px;font-weight:600}'
        . 'a:hover{background:#556cd6}'
        . '</style></head><body>'
        . '<div class="card">'
        . '<div class="icon">ℹ️</div>'
        . '<h2>Esta petición es una consulta informativa</h2>'
        . '<p>El ciudadano hizo una pregunta (sobre un evento, un trámite, un horario, una feria, un curso, etc.) '
        . 'y no reportó un problema de servicios públicos. Las consultas informativas se registran en el sistema '
        . 'para el informe mensual, pero no generan oficio.</p>'
        . '<a href="../vistas/admin_tickets.php">← Volver a Gestión de Peticiones</a>'
        . '</div></body></html>';
    exit;
}

// ------------------------------------------------------------
// Cargar el catálogo de categorías de "IA 2/datos_ia.json" (mismo catálogo
// que usa el chat web para generar el oficio: nombre, departamento,
// unidad, director, teléfono, descripción formal, unidad de seguimiento y
// distribución de copias por categoría).
//
// NOTA: la constante DATOS_IA_JSON de config.php apunta a "ia/" (carpeta
// vacía, sin espacio) y no a la carpeta real "IA 2/" donde vive el
// catálogo — por eso acá se arma la ruta directamente, igual que ya hace
// whatsapp/recibir_bot.php ($RUTA_IA = __DIR__ . '/../IA 2').
// ------------------------------------------------------------
$rutaCatalogo = dirname(__DIR__) . '/IA 2/datos_ia.json';
$catalogo = [];
if (file_exists($rutaCatalogo)) {
    $datosIA = json_decode(file_get_contents($rutaCatalogo), true);
    $catalogo = $datosIA['catalogo'] ?? [];
}

$categoriaKey = $ticket['categoria'] ?? 'administrativo';
$infoCat = $catalogo[$categoriaKey] ?? $catalogo['administrativo'] ?? [
    'nombre' => ucfirst(str_replace('_', ' ', $categoriaKey)),
    'dep' => 'Secretaría Municipal',
    'unidad' => 'No asignada',
    'dir' => 'Encargado de Departamento',
    'tel' => 'N/D',
    'desc' => 'El ciudadano peticionario reporta una problemática que requiere la intervención de la Municipalidad.',
    'seguimiento' => 'Secretaría Municipal',
    'copias' => ['Alcaldía Municipal', 'Secretaría Municipal', 'Ciudadano Peticionario'],
    // Oficina del correlativo por oficina (ver tickets/correlativo_oficinas.php)
    // que le corresponde por defecto a una categoría sin catálogo propio.
    'oficina_id' => null,
];

// ------------------------------------------------------------
// Armar $sol / $cla con la misma forma que usa generarHTMLOficio() (ver
// IA 2/clasificar_y_generar.php), pero a partir de los datos YA
// GUARDADOS del ticket en vez de una clasificación en caliente.
// ------------------------------------------------------------
$urgencia = $ticket['urgencia'] ?? 'media';
$plazos = ['inmediata' => 1, 'alta' => 3, 'media' => 7, 'baja' => 15];
$plazo = $plazos[$urgencia] ?? 7;
$urgEtiquetas = [
    'inmediata' => '🔴 URGENCIA INMEDIATA',
    'alta'      => '🟠 URGENCIA ALTA',
    'media'     => '🟡 URGENCIA MEDIA',
    'baja'      => '🟢 URGENCIA BAJA',
];

$estadosTexto = [
    'pendiente'  => 'PENDIENTE DE ATENCIÓN',
    'en_proceso' => 'EN PROCESO',
    'resuelto'   => 'RESUELTO',
    'cancelado'  => 'CANCELADO',
];

$canal = $ticket['canal'] ?? 'dashboard';
$canalTexto = $canal === 'whatsapp'
    ? 'WhatsApp (Bot de Atención Ciudadana)'
    : 'Dashboard administrativo / Presencial';

$tsCreacion = strtotime($ticket['fecha_creacion']) ?: time();

// El expediente ya se generó al crear el ticket (whatsapp/recibir_bot.php
// o tickets/crear.php); si por algún motivo faltara (tickets muy
// antiguos, previos a esa columna), se genera uno de respaldo para que el
// oficio nunca quede sin folio.
$expediente = !empty($ticket['expediente']) ? $ticket['expediente'] : ('SPJ-GEN-' . date('Y', $tsCreacion) . '-' . str_pad($ticket['id'], 4, '0', STR_PAD_LEFT));

$sol = [
    'ticket_id'           => $ticket['id'],
    'numero_expediente'   => $expediente,
    'fecha_formato'       => fechaFormateadaOficio($tsCreacion),
    'fecha_registro'      => date('d/m/Y H:i:s', $tsCreacion),
    'canal_recepcion'     => $canalTexto,
    // 'social' (área pública), 'particular' (nombre + reporte + dirección,
    // sin teléfono en el oficio) o '' (tickets antiguos). Ver
    // cuerpoCartaOficio() en oficio_html.php.
    'interes'             => $ticket['interes'] ?? '',
    'categoria_nombre'    => $infoCat['nombre'],
    'urgencia'            => $urgencia,
    'urgencia_etiqueta'   => $urgEtiquetas[$urgencia] ?? $urgEtiquetas['media'],
    'plazo_atencion_dias' => $plazo,
    'fecha_limite'        => fechaLimiteOficio($tsCreacion, $plazo),
    'estado'              => $estadosTexto[$ticket['estado'] ?? 'pendiente'] ?? 'PENDIENTE DE ATENCIÓN',
    'descripcion_formal'  => $infoCat['desc'] ?? '',
    'texto_original'      => $ticket['mensaje_original'] ?? '',
    'unidad_seguimiento'  => $infoCat['seguimiento'] ?? $infoCat['dep'] ?? '',
    'copias_a'            => $infoCat['copias'] ?? [],
    // ID de la oficina del correlativo por oficina (ej. "SP") que le
    // corresponde a esta categoría, ver tickets/correlativo_oficinas.php
    // y vistas/config_sistema.php (columna "Oficina (correlativo)").
    // Null = esta categoría no tiene oficina asignada, el oficio no
    // lleva código de oficina (solo el "Oficio No. X-ACC" de siempre).
    'oficina_id'          => $infoCat['oficina_id'] ?? null,
    'departamento' => [
        'nombre'   => $infoCat['dep'] ?? 'Secretaría Municipal',
        'unidad'   => $infoCat['unidad'] ?? '',
        'director' => $infoCat['dir'] ?? 'Encargado de Departamento',
        'telefono' => $infoCat['tel'] ?? 'N/D',
    ],
    'ciudadano' => [
        'nombre_completo' => !empty($ticket['nombre_ciudadano']) ? $ticket['nombre_ciudadano'] : '(No proporcionado)',
        // Interés SOCIAL (área pública): el oficio NO lleva teléfono de
        // contacto, ni siquiera el del WhatsApp del remitente. En los demás
        // casos se mantiene el comportamiento de siempre.
        'telefono'        => (($ticket['interes'] ?? '') === 'social')
            ? ''
            : (!empty($ticket['telefono_contacto']) ? $ticket['telefono_contacto'] : (!empty($ticket['remitente']) ? $ticket['remitente'] : '—')),
        'direccion'       => !empty($ticket['direccion']) ? $ticket['direccion'] : '(No proporcionada)',
    ],
    // Historial de estados propio del panel administrativo (ver sección
    // VIII agregada en oficio_html.php).
    'historial' => $ticket['historial_estados'] ?? [],
    // Evidencia del reporte: conversación real de WhatsApp de donde salió
    // la petición (ver whatsapp/recibir_bot.php y tickets/crear.php). Los
    // tickets creados desde el dashboard, o tickets de WhatsApp muy
    // antiguos previos a esta función, no la traen: queda como arreglo
    // vacío y oficio_html.php muestra el recuadro para adjuntar una
    // captura manual en esos casos.
    'conversacion' => $ticket['conversacion'] ?? [],
    // Fotografías que el ciudadano envió por WhatsApp (rutas dentro de
    // whatsapp/fotos/, ver whatsapp/recibir_bot.php y tickets/crear.php).
    // Se usan en la sección "IV. Evidencia del Reporte" del oficio para
    // mostrarlas automáticamente en vez del recuadro genérico de siempre.
    'fotos' => $ticket['fotos'] ?? [],
    // Captura(s) reales de la conversación de WhatsApp, subidas a mano
    // desde reportes/imprimir.php (ver reportes/subir_captura.php y
    // reportes/capturas_chat.json). Se cargan más abajo, después de armar
    // $sol, y se renderizan por separado del HTML editable del oficio.
    'capturas_chat' => [],
];

// ------------------------------------------------------------
// Cargar capturas de chat subidas manualmente para este ticket (ver
// reportes/subir_captura.php). No están ligadas al ticket.json sino a su
// propio registro, porque se pueden ir agregando en cualquier momento
// desde la vista del oficio.
// ------------------------------------------------------------
$rutaCapturasChat = __DIR__ . '/capturas_chat.json';
if (file_exists($rutaCapturasChat)) {
    $registroCapturas = json_decode(file_get_contents($rutaCapturasChat), true);
    if (is_array($registroCapturas) && isset($registroCapturas[(string) $ticket['id']])) {
        $sol['capturas_chat'] = $registroCapturas[(string) $ticket['id']];
    }
}

$cla = [
    'categoria'        => $categoriaKey,
    'confianza'        => $ticket['confianza'] ?? null,
    'version_modelo'   => $canal === 'whatsapp' ? 'whatsapp-bot' : 'dashboard',
];

$htmlOficio = generarHTMLOficio($sol, $cla);

// ------------------------------------------------------------
// Si este oficio ya fue editado manualmente antes (desde la vista de
// "Ver reporte" / imprimir.php, ver reportes/guardar_edicion.php), se
// muestra esa versión editada en vez de regenerar el oficio desde cero a
// partir del ticket. Así la edición persiste entre visitas/impresiones.
// ------------------------------------------------------------
$edicionGuardada = null;
$rutaEdiciones = __DIR__ . '/oficios_editados.json';
if (file_exists($rutaEdiciones)) {
    $ediciones = json_decode(file_get_contents($rutaEdiciones), true);
    if (is_array($ediciones) && isset($ediciones[(string) $ticket['id']])) {
        $edicionGuardada = $ediciones[(string) $ticket['id']];
    }
}
if ($edicionGuardada) {
    $htmlOficio = $edicionGuardada['html'];
}

// Anexo único de evidencia: combina las fotografías que el ciudadano
// envió por WhatsApp (whatsapp/fotos/) con las capturas del chat subidas
// a mano (reportes/capturas_chat.json) en un mismo grid 2x2 por hoja, con
// paginación que respeta el tamaño elegido (4 medianas/pequeñas por hoja;
// 2 grandes por hoja) y con el título editable desde el modo "✏️ Editar".
// SIEMPRE se recalcula fresco desde $sol (nunca desde el HTML congelado),
// para que una captura/foto nueva siga apareciendo.
$tituloAnexoCustom = ($edicionGuardada && !empty($edicionGuardada['titulo_anexo']))
    ? $edicionGuardada['titulo_anexo']
    : null;
$htmlAnexoEvidencia = renderAnexoEvidencia($sol, $sol['numero_expediente'], $tituloAnexoCustom);

$generadoPor = htmlspecialchars($_SESSION['nombre_completo'] ?? $_SESSION['username'] ?? 'Sistema', ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Oficio — Expediente <?= htmlspecialchars($sol['numero_expediente'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="oficio.css">
<style media="print">
    body { padding: 0; }
    .no-print { display: none !important; }
</style>
</head>
<body>
<div class="reporte-shell">
    <?php if ($edicionGuardada): ?>
    <div class="no-print" style="max-width:21.59cm; margin:0 auto 10px auto; background:#fff8e1; border:1px solid #e6c200; border-radius:6px; padding:8px 14px; font-size:12px; color:#5a4a00;">
        ✏️ Este oficio tiene ediciones manuales guardadas por <?= htmlspecialchars($edicionGuardada['editado_por'], ENT_QUOTES, 'UTF-8') ?> el <?= htmlspecialchars($edicionGuardada['fecha'], ENT_QUOTES, 'UTF-8') ?>.
    </div>
    <?php endif; ?>
    <div id="oficio-contenido" data-ticket-id="<?= (int) $ticket['id'] ?>"><?= $htmlOficio ?></div>
    <div id="anexo-evidencia"><?= $htmlAnexoEvidencia ?></div>
    <div class="no-print" style="margin-top:16px; text-align:center; font-size:11px; color:var(--text3);">
        Reporte generado por <?= $generadoPor ?> el <?= date('d/m/Y H:i') ?> — Petición #<?= (int) $ticket['id'] ?>
    </div>
</div>
</body>
</html>
