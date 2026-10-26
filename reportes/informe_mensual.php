<?php
/**
 * reportes/informe_mensual.php
 *
 * Genera el "Informe de Peticiones Ciudadanas del WhatsApp Municipal"
 * correspondiente a un mes/año elegido, a partir de los tickets ya
 * guardados en tickets/tickets.json (peticiones formales recibidas y
 * clasificadas por el bot). La tabla se muestra en ORDEN CRONOLÓGICO
 * (de la petición más antigua a la más reciente dentro del mes).
 *
 * Estructura del documento (v2):
 *   - Página 1: Introducción, Reporte (resumen narrativo) y Conclusión,
 *     con las firmas al final — sin la tabla.
 *   - Página 2 en adelante: solo la tabla cronológica de peticiones.
 *
 * La columna "Tipo de consulta" admite dos valores: "Información"
 * o "Reporte". Para los tickets de información (generados cuando el
 * chatbot deriva a atención humana) se fuerza el tipo "Información"
 * y se asigna la oficina según el departamento detectado.
 *
 * Uso: reportes/informe_mensual.php?mes=8&anio=2026
 * Acceso: solo usuarios administradores autenticados (mismo criterio que
 * reportes/generar.php).
 */

require_once dirname(__DIR__) . '/config/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true) {
    header('Location: ../login/iniciar_sesion.php');
    exit;
}

$esAdmin = $_SESSION['admin'] ?? false;
if (!$esAdmin) {
    header('HTTP/1.1 403 Forbidden');
    die('Solo un administrador puede generar el informe mensual.');
}

// ------------------------------------------------------------
// 0.1 "Aprendizaje" de correcciones: cada vez que un administrador edita
//     y guarda el informe (ver reportes/guardar_edicion_informe.php), los
//     textos fijos marcados con data-learn-id (títulos, encabezados de
//     sección, párrafos fijos de introducción/conclusión, firma y
//     encabezados de la tabla) quedan registrados en
//     reportes/correcciones_aprendidas.json. Desde ese momento, TODOS los
//     informes que se generen de ahí en adelante (cualquier mes/año que
//     todavía no tenga su propia edición guardada) usan automáticamente
//     esa versión corregida en vez del texto original de la plantilla.
//
//     Los párrafos que sí varían según los datos reales del mes (los que
//     mencionan cantidades: "$parrafoReporte" y "$parrafoAtencion" más
//     abajo) NO pasan por este mecanismo, porque memorizar ese texto tal
//     cual congelaría cifras de un mes anterior en los meses futuros.
// ------------------------------------------------------------
$correccionesAprendidas = [];
$rutaCorreccionesAprendidas = __DIR__ . '/correcciones_aprendidas.json';
if (file_exists($rutaCorreccionesAprendidas)) {
    $tmpAprendidas = json_decode(file_get_contents($rutaCorreccionesAprendidas), true);
    if (is_array($tmpAprendidas)) $correccionesAprendidas = $tmpAprendidas;
}

function textoAprendido($id, $default) {
    global $correccionesAprendidas;
    if (isset($correccionesAprendidas[$id]) && trim($correccionesAprendidas[$id]) !== '') {
        return $correccionesAprendidas[$id];
    }
    return $default;
}

// ------------------------------------------------------------
// 1. Mes/año a reportar (por defecto: el mes actual)
// ------------------------------------------------------------
$mesesTexto = ['', 'enero','febrero','marzo','abril','mayo','junio','julio',
    'agosto','septiembre','octubre','noviembre','diciembre'];

$mes  = isset($_GET['mes'])  ? max(1, min(12, intval($_GET['mes'])))  : (int) date('n');
$anio = isset($_GET['anio']) ? intval($_GET['anio'])                  : (int) date('Y');
if ($anio < 2020 || $anio > 2100) $anio = (int) date('Y');

$inicioTs = mktime(0, 0, 0, $mes, 1, $anio);
$diasEnMes = (int) date('t', $inicioTs);
$finTs = mktime(23, 59, 59, $mes, $diasEnMes, $anio);

// ------------------------------------------------------------
// 2. Catálogo de categorías (nombre de la petición + oficina/departamento
//    responsable), la misma fuente que usa reportes/generar.php para los
//    oficios individuales.
// ------------------------------------------------------------
$rutaCatalogo = dirname(__DIR__) . '/IA 2/datos_ia.json';
$catalogo = [];
if (file_exists($rutaCatalogo)) {
    $datosIA = json_decode(file_get_contents($rutaCatalogo), true);
    $catalogo = $datosIA['catalogo'] ?? [];
}

function infoCategoriaInforme($categoriaKey, $catalogo) {
    if (isset($catalogo[$categoriaKey])) return $catalogo[$categoriaKey];
    return [
        'nombre' => ucfirst(str_replace('_', ' ', $categoriaKey)),
        'dep'    => 'Secretaría Municipal',
    ];
}

$estadosTexto = [
    'pendiente'  => 'En Proceso',
    'en_proceso' => 'En Proceso',
    'resuelto'   => 'Atendido',
    'cancelado'  => 'Cancelado',
];

// ------------------------------------------------------------
// 2.1 Clasificación del "Tipo de consulta": únicamente dos valores
//     posibles, "Información" o "Reporte".
//
//     Las categorías que el bot solo usa para reportar problemas de
//     infraestructura (bacheo, alumbrado, agua, basura, ambiental) son
//     siempre "Reporte". Para el resto (administrativo, catastro, o
//     cualquier categoría nueva que se agregue más adelante) se decide
//     por el contenido del mensaje: si el ciudadano está preguntando /
//     averiguando algo (verbos de consulta), es "Información"; si está
//     pidiendo que se resuelva o se haga algo, es "Reporte".
//
//     Además, si el ticket tiene la categoría 'informacion_ciudadana'
//     (creada por el sistema al derivar a atención humana), se fuerza
//     'Información'.
// ------------------------------------------------------------
function tipoConsultaTicket($categoriaKey, $mensaje) {
    // Si la categoría es 'informacion_ciudadana', es información pura
    if ($categoriaKey === 'informacion_ciudadana') {
        return 'Información';
    }

    $categoriasSiempreReporte = ['bacheo', 'alumbrado_publico', 'fuga_agua', 'recoleccion_basura', 'gestion_ambiental'];
    if (in_array($categoriaKey, $categoriasSiempreReporte, true)) {
        return 'Reporte';
    }

    $texto = mb_strtolower($mensaje, 'UTF-8');
    $indiciosInformacion = [
        'averiguar', 'informacion', 'información', 'saber', 'cual es', 'cuál es',
        'como puedo', 'cómo puedo', 'requisitos', 'tramite', 'trámite', 'que necesito',
        'qué necesito', 'cuando es', 'cuándo es', 'donde queda', 'dónde queda', 'horario',
        'a que hora', 'a qué hora', 'consulta sobre', 'quisiera saber', 'quiero saber',
    ];
    foreach ($indiciosInformacion as $indicio) {
        if (mb_strpos($texto, $indicio) !== false) {
            return 'Información';
        }
    }
    return 'Reporte';
}

// ------------------------------------------------------------
// 2.2 Descripción breve (~7 palabras) de qué se reportó o consultó.
//     NUNCA se usa el mensaje textual del ciudadano tal cual: se arma
//     una frase corta a partir de la categoría (y, cuando se detecta,
//     el sector/colonia mencionado), no una copia del chat.
// ------------------------------------------------------------
function limitarPalabras($texto, $maxPalabras = 9) {
    $palabras = preg_split('/\s+/u', trim($texto));
    if (count($palabras) <= $maxPalabras) return trim($texto);
    return implode(' ', array_slice($palabras, 0, $maxPalabras));
}

/**
 * Intenta extraer un sector/colonia/barrio mencionado en el mensaje
 * (ej. "en la colonia San Andrés", "sector Ixtacapa") para hacer la
 * descripción un poco más específica sin copiar el mensaje completo.
 * Devuelve como máximo 3 palabras, o null si no encuentra nada.
 */
function extraerLugarMencionado($mensaje) {
    // Busca la palabra clave (sector/colonia/etc.) sin importar mayúsculas,
    // pero luego SOLO toma las palabras que sigan y empiecen con mayúscula
    // (nombre propio del lugar: "la Flor", "San Andrés"), para no arrastrar
    // el resto de la frase ("...Rosario y no hay agua").
    $palabrasClave = ['sector', 'colonia', 'caserío', 'caserio', 'barrio', 'aldea', 'cantón', 'canton', 'zona'];
    $mensajeMin = mb_strtolower($mensaje, 'UTF-8');
    foreach ($palabrasClave as $clave) {
        $pos = mb_strpos($mensajeMin, $clave, 0, 'UTF-8');
        if ($pos === false) continue;
        $resto = mb_substr($mensaje, $pos + mb_strlen($clave, 'UTF-8'), null, 'UTF-8');
        if (preg_match('/^\s+((?:(?:la|el|los|las|de)\s+)?(?:[A-ZÁÉÍÓÚÑ][\p{L}]*\s*){1,3})/u', $resto, $m)) {
            $lugar = trim($m[1]);
            if ($lugar !== '') return $lugar;
        }
    }
    return null;
}

function generarDescripcionBreve($categoriaKey, $mensaje, $tipo) {
    $mensajeLimpio = trim(preg_replace('/[^\p{L}\p{N}\s.,¿?]/u', '', $mensaje));

    // Mensajes sin contenido textual real (solo un emoji, un saludo suelto, etc.)
    if (mb_strlen($mensajeLimpio, 'UTF-8') < 3) {
        return 'Mensaje sin contenido claro recibido';
    }

    // Para tickets de información, armar descripción específica según palabras clave
    if ($categoriaKey === 'informacion_ciudadana') {
        $texto = mb_strtolower($mensaje, 'UTF-8');
        if (mb_strpos($texto, 'feria') !== false || mb_strpos($texto, 'emprendimiento') !== false) {
            return 'Consulta Feria de Emprendimiento';
        }
        if (mb_strpos($texto, 'curso') !== false || mb_strpos($texto, 'kiche') !== false || mb_strpos($texto, 'taller') !== false || mb_strpos($texto, 'capacitación') !== false) {
            return 'Consulta Cursos y Talleres';
        }
        if (mb_strpos($texto, 'salon') !== false || mb_strpos($texto, 'salón') !== false) {
            return 'Consulta Uso del Salón Municipal';
        }
        if (mb_strpos($texto, 'mujer') !== false || mb_strpos($texto, 'violencia') !== false) {
            return 'Consulta sobre atención a la mujer';
        }
        if (mb_strpos($texto, 'niñez') !== false || mb_strpos($texto, 'juventud') !== false || mb_strpos($texto, 'beca') !== false) {
            return 'Consulta sobre programas para niñez y juventud';
        }
        if (mb_strpos($texto, 'catastro') !== false || mb_strpos($texto, 'comred') !== false || mb_strpos($texto, 'riesgo') !== false) {
            return 'Consulta sobre riesgos o catastro';
        }
        return 'Consulta administrativa general';
    }

    $etiquetasReporte = [
        'bacheo'             => 'Bache o daño en la vía',
        'alumbrado_publico'  => 'Falla en el alumbrado público',
        'fuga_agua'          => 'Corte o fuga de agua',
        'recoleccion_basura' => 'Basura acumulada sin recolectar',
        'gestion_ambiental'  => 'Árbol caído o daño ambiental',
    ];

    if (isset($etiquetasReporte[$categoriaKey])) {
        $base = $etiquetasReporte[$categoriaKey];
        $lugar = extraerLugarMencionado($mensaje);
        if ($lugar) {
            $base .= ' en ' . $lugar;
        }
        return limitarPalabras($base, 9);
    }

    $texto = mb_strtolower($mensaje, 'UTF-8');

    if (mb_strpos($texto, 'salon') !== false || mb_strpos($texto, 'salón') !== false) {
        return 'Solicitud de uso del salón municipal';
    }
    if (mb_strpos($texto, 'cementerio') !== false || mb_strpos($texto, 'nicho') !== false) {
        return $tipo === 'Información' ? 'Consulta sobre trámite del cementerio' : 'Solicitud de trámite en cementerio';
    }
    if ($categoriaKey === 'catastro') {
        return $tipo === 'Información' ? 'Consulta sobre trámite de catastro' : 'Solicitud de trámite catastral';
    }

    // Categoría "administrativo" u otra sin etiqueta fija: descripción
    // genérica según el tipo detectado.
    return $tipo === 'Información'
        ? 'Consulta administrativa general del ciudadano'
        : 'Solicitud de gestión administrativa municipal';
}

// ------------------------------------------------------------
// 3. Cargar tickets, filtrar por el mes/año elegido y ORDENAR
//    CRONOLÓGICAMENTE (fecha_creacion ascendente).
// ------------------------------------------------------------
$tickets = [];
if (file_exists(TICKETS_JSON)) {
    $tickets = json_decode(file_get_contents(TICKETS_JSON), true) ?: [];
}

// Cargar departamentos para obtener nombre de oficina
$departamentos = [];
if (file_exists(DEPARTAMENTOS_JSON)) {
    $departamentos = json_decode(file_get_contents(DEPARTAMENTOS_JSON), true) ?: [];
}
$departamentosPorId = [];
foreach ($departamentos as $dep) {
    $departamentosPorId[$dep['id']] = $dep['nombre'];
}

$delMes = array_filter($tickets, function ($t) use ($inicioTs, $finTs) {
    $ts = strtotime($t['fecha_creacion'] ?? '');
    return $ts !== false && $ts >= $inicioTs && $ts <= $finTs;
});

usort($delMes, function ($a, $b) {
    return strtotime($a['fecha_creacion']) <=> strtotime($b['fecha_creacion']);
});

// ------------------------------------------------------------
// 4. Armar filas de la tabla + estadísticas para los párrafos
// ------------------------------------------------------------
$filas = [];
$oficinasConteo = [];
$estadoConteo = ['Atendido' => 0, 'En Proceso' => 0, 'Cancelado' => 0];
$tipoConteo = ['Información' => 0, 'Reporte' => 0];

foreach ($delMes as $t) {
    $categoriaKey = $t['categoria'] ?? 'administrativo';
    $mensajeOriginal = $t['mensaje_original'] ?? '';

    // Determinar tipo de consulta
    $tipo = tipoConsultaTicket($categoriaKey, $mensajeOriginal);

    // Determinar oficina
    if ($categoriaKey === 'informacion_ciudadana') {
        // Usar el departamento_id del ticket, si existe
        $depId = isset($t['departamento_id']) ? (int) $t['departamento_id'] : 0;
        if ($depId > 0 && isset($departamentosPorId[$depId])) {
            $oficina = $departamentosPorId[$depId];
        } elseif (!empty($t['departamento_nombre'])) {
            $oficina = $t['departamento_nombre'];
        } else {
            $oficina = 'Secretaría Municipal';
        }
    } else {
        $cat = infoCategoriaInforme($categoriaKey, $catalogo);
        $oficina = $cat['dep'] ?? 'Secretaría Municipal';
    }

    $estado = $estadosTexto[$t['estado'] ?? 'pendiente'] ?? 'En Proceso';
    $descripcion = generarDescripcionBreve($categoriaKey, $mensajeOriginal, $tipo);

    $filas[] = [
        'fecha'       => date('d/m/Y', strtotime($t['fecha_creacion'])),
        'tipo'        => $tipo,
        'descripcion' => $descripcion,
        'oficina'     => $oficina,
        'estado'      => $estado,
    ];

    $oficinasConteo[$oficina] = ($oficinasConteo[$oficina] ?? 0) + 1;
    if (isset($estadoConteo[$estado])) $estadoConteo[$estado]++;
    if (isset($tipoConteo[$tipo])) $tipoConteo[$tipo]++;
}

$totalPeticiones = count($filas);
arsort($oficinasConteo);

// ------------------------------------------------------------
// 5. Textos del informe (introducción / reporte / atención / conclusión),
//    generados a partir de las estadísticas reales del mes.
// ------------------------------------------------------------
$rangoFechas = '01 al ' . $diasEnMes . ' de ' . $mesesTexto[$mes] . ' ' . $anio;

$listaOficinasHtml = '';
foreach ($oficinasConteo as $of => $cant) {
    $listaOficinasHtml .= '<li>' . htmlspecialchars($of, ENT_QUOTES, 'UTF-8')
        . ' (' . $cant . ')</li>';
}

if ($totalPeticiones > 0) {
    $parrafoReporte = 'Durante el mes de ' . ucfirst($mesesTexto[$mes]) . ' se recibieron '
        . $totalPeticiones . ' petici' . ($totalPeticiones === 1 ? 'ón' : 'ones')
        . ' a través del WhatsApp municipal (' . $tipoConteo['Reporte'] . ' reporte(s) y '
        . $tipoConteo['Información'] . ' consulta(s) de información), dirigidas a las siguientes oficinas:';
} else {
    $parrafoReporte = 'Durante el mes de ' . ucfirst($mesesTexto[$mes]) . ' no se registraron peticiones a través del WhatsApp municipal.';
}

$parrafoAtencion = $totalPeticiones > 0
    ? ('De las ' . $totalPeticiones . ' peticiones recibidas, ' . $estadoConteo['Atendido'] . ' fueron atendidas, '
        . $estadoConteo['En Proceso'] . ' se encuentran en proceso de atención'
        . ($estadoConteo['Cancelado'] > 0 ? ' y ' . $estadoConteo['Cancelado'] . ' fueron canceladas' : '')
        . ', orientando en cada caso a los ciudadanos hacia las dependencias correspondientes.')
    : '';

// ------------------------------------------------------------
// 6. Si este informe (mismo mes/año) ya fue editado manualmente antes
//    (botón "✏️ Editar" de esta misma pantalla, ver
//    reportes/guardar_edicion_informe.php), se muestra esa versión
//    editada en vez de regenerar el contenido desde cero a partir de los
//    tickets. Así la edición persiste entre visitas/impresiones,
//    mientras se pida el mismo mes y año.
// ------------------------------------------------------------
$claveInforme = $mes . '-' . $anio;
$edicionGuardada = null;
$rutaEdicionesInforme = __DIR__ . '/informes_editados.json';
if (file_exists($rutaEdicionesInforme)) {
    $edicionesInforme = json_decode(file_get_contents($rutaEdicionesInforme), true);
    if (is_array($edicionesInforme) && isset($edicionesInforme[$claveInforme])) {
        $edicionGuardada = $edicionesInforme[$claveInforme];
    }
}

// ------------------------------------------------------------
// NUEVO: detectar si la edición guardada quedó DESACTUALIZADA.
//
// El botón "💾 Guardar cambios" congela el HTML completo del informe en
// informes_editados.json. Desde ese momento, informe_mensual.php muestra
// siempre esa versión guardada, aunque lleguen peticiones nuevas. Si
// nadie toca el informe durante semanas, se queda "pegado" en la fecha
// en que se guardó (bug real: en septiembre 2026 el informe quedó
// congelado en el día 1 y ya había peticiones hasta el día 23).
//
// Se compara la fecha de la edición guardada contra la fecha del ticket
// MÁS RECIENTE de ese mes. Si hay tickets posteriores a la edición, se
// marca $edicionDesactualizada = true para mostrar un aviso visible con
// un botón para regenerar el informe.
// ------------------------------------------------------------
$edicionDesactualizada = false;
$edicionFechaGuardada = null;
$ticketsPosterioresEdicion = 0;
if ($edicionGuardada && !empty($edicionGuardada['fecha'])) {
    $edicionFechaGuardada = $edicionGuardada['fecha'];
    $tsEdicion = strtotime($edicionGuardada['fecha']);
    if ($tsEdicion !== false) {
        // Contar los tickets del mes cuya fecha_creacion es posterior a
        // la fecha de la edición guardada.
        foreach ($delMes as $t) {
            $tsTicket = strtotime($t['fecha_creacion'] ?? '');
            if ($tsTicket !== false && $tsTicket > $tsEdicion) {
                $ticketsPosterioresEdicion++;
            }
        }
        if ($ticketsPosterioresEdicion > 0) {
            $edicionDesactualizada = true;
        }
    }
}

?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Informe Mensual — <?= htmlspecialchars(ucfirst($mesesTexto[$mes]) . ' ' . $anio, ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="informe_mensual.css">
</head>
<body>

<div class="barra-informe no-print">
    <form method="get" style="display:flex; gap:14px; align-items:end; flex-wrap:wrap;">
        <div class="campo">
            <label for="mes">Mes</label>
            <select name="mes" id="mes">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $m === $mes ? 'selected' : '' ?>><?= ucfirst($mesesTexto[$m]) ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="campo">
            <label for="anio">Año</label>
            <select name="anio" id="anio">
                <?php for ($a = (int) date('Y'); $a >= (int) date('Y') - 4; $a--): ?>
                    <option value="<?= $a ?>" <?= $a === $anio ? 'selected' : '' ?>><?= $a ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <button type="submit" class="btn-generar"><i class="fas fa-file-alt"></i> Generar informe</button>
    </form>
    <button type="button" id="btn-editar-informe" style="padding:10px 18px; background:#495057; color:#fff; border:none; border-radius:8px; cursor:pointer; font-weight:600;">✏️ Editar</button>
    <button type="button" id="btn-guardar-informe" style="display:none; padding:10px 18px; background:#2e7d32; color:#fff; border:none; border-radius:8px; cursor:pointer; font-weight:600;">💾 Guardar cambios</button>
    <span id="estado-guardado-informe" style="font-size:12px; color:#2e7d32; display:none;"></span>
    <button type="button" class="btn-imprimir" onclick="window.print()">🖨️ Imprimir / Guardar PDF</button>
    <a href="../vistas/dashboard.php" class="btn btn-volver" style="background:#495057;color:#fff;">← Volver</a>
</div>

<div class="informe-shell">

<?php if ($edicionGuardada && $edicionDesactualizada): ?>
    <div class="no-print" style="max-width:21.59cm; margin:0 auto 10px auto; background:#fdecea; border:1px solid #e53935; border-radius:8px; padding:14px 18px; font-size:13px; color:#7f1d1d; display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
        <div style="flex:1; min-width:240px; line-height:1.5;">
            <strong>⚠️ El informe guardado está desactualizado.</strong><br>
            La última edición manual fue por
            <strong><?= htmlspecialchars($edicionGuardada['editado_por'], ENT_QUOTES, 'UTF-8') ?></strong>
            el <strong><?= htmlspecialchars($edicionGuardada['fecha'], ENT_QUOTES, 'UTF-8') ?></strong>,
            pero se han registrado <strong><?= (int) $ticketsPosterioresEdicion ?></strong>
            petici<?= $ticketsPosterioresEdicion === 1 ? 'ón' : 'ones' ?> más desde entonces.
            Como el informe guardado no incluye esas peticiones, aquí abajo se está mostrando
            la versión <em>antigua</em>.
        </div>
        <button type="button" id="btn-regenerar-informe"
                style="padding:10px 20px; background:#e53935; color:#fff; border:none; border-radius:8px; cursor:pointer; font-weight:600; white-space:nowrap;">
            🔄 Regenerar informe
        </button>
        <span id="estado-regenerar-informe" style="font-size:12px; color:#7f1d1d; display:none;"></span>
    </div>
<?php elseif ($edicionGuardada): ?>
    <div class="no-print" style="max-width:21.59cm; margin:0 auto 10px auto; background:#fff8e1; border:1px solid #e6c200; border-radius:6px; padding:8px 14px; font-size:12px; color:#5a4a00;">
        ✏️ Este informe tiene ediciones manuales guardadas por <?= htmlspecialchars($edicionGuardada['editado_por'], ENT_QUOTES, 'UTF-8') ?> el <?= htmlspecialchars($edicionGuardada['fecha'], ENT_QUOTES, 'UTF-8') ?>.
        <a href="#" id="btn-regenerar-informe-forzado" style="color:#5a4a00; text-decoration:underline; margin-left:8px;">Regenerar desde cero</a>
    </div>
<?php endif; ?>

<div id="informe-contenido" data-mes="<?= (int) $mes ?>" data-anio="<?= (int) $anio ?>">
<?php if ($edicionGuardada): ?>
    <?= $edicionGuardada['html'] ?>
<?php else: ?>
    <!-- ==================== PÁGINA 1: Introducción / Reporte / Conclusión ==================== -->
    <div class="carta-pagina" id="informe-pagina-1">
    <div class="carta-contenido">
        <!-- Encabezado con logo -->
        <div style="display:flex; align-items:center; justify-content:flex-start; gap:15px; margin-bottom:10px; text-align:left;">
            <img src="../assets/img/Logo San Pablo.png" alt="Logo Municipalidad" style="height:135px; object-fit:contain; flex-shrink:0;">
            <div>
                <div class="informe-titulo" data-learn-id="titulo-informe" style="margin-bottom:0; text-align:left;"><?= htmlspecialchars(textoAprendido('titulo-informe', 'Informe de Peticiones Ciudadanas del WhatsApp Municipal'), ENT_QUOTES, 'UTF-8') ?></div>
                <div class="informe-subtitulo" data-learn-id="subtitulo-informe" style="margin-top:0; text-align:left;"><?= htmlspecialchars(textoAprendido('subtitulo-informe', 'Oficina Municipal de Atención y Comunicación Ciudadana'), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>

        <div class="informe-meta"><strong><?= htmlspecialchars($rangoFechas, ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div class="informe-meta">Medio de atención: WhatsApp Municipal</div>

        <div class="informe-seccion-titulo" data-learn-id="titulo-introduccion"><?= htmlspecialchars(textoAprendido('titulo-introduccion', 'Introducción'), ENT_QUOTES, 'UTF-8') ?></div>
        <p class="informe-parrafo" data-learn-id="texto-introduccion"><?= htmlspecialchars(textoAprendido('texto-introduccion',
            'La Oficina Municipal de Atención y Comunicación Ciudadana tiene el objetivo de mantener '
            . 'habilitado el servicio de atención por medio de WhatsApp para poder brindar información, '
            . 'resolver dudas y recibir peticiones de la población de manera rápida y accesible. Este '
            . 'canal permite fortalecer la comunicación directa entre la municipalidad y los vecinos del '
            . 'municipio.'), ENT_QUOTES, 'UTF-8') ?>
        </p>

        <div class="informe-seccion-titulo" data-learn-id="titulo-reporte"><?= htmlspecialchars(textoAprendido('titulo-reporte', 'Reporte'), ENT_QUOTES, 'UTF-8') ?></div>
        <p class="informe-parrafo informe-parrafo-reporte"><?= htmlspecialchars($parrafoReporte, ENT_QUOTES, 'UTF-8') ?></p>
        <?php if ($totalPeticiones > 0): ?>
        <ul class="informe-lista-oficinas"><?= $listaOficinasHtml ?></ul>
        <?php endif; ?>

        <?php if ($parrafoAtencion !== ''): ?>
        <p class="informe-parrafo informe-parrafo-atencion"><?= htmlspecialchars($parrafoAtencion, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <div class="informe-seccion-titulo" data-learn-id="titulo-conclusion"><?= htmlspecialchars(textoAprendido('titulo-conclusion', 'Conclusión'), ENT_QUOTES, 'UTF-8') ?></div>
        <p class="informe-parrafo" data-learn-id="texto-conclusion"><?= htmlspecialchars(textoAprendido('texto-conclusion',
            'El canal de WhatsApp continúa siendo una herramienta importante para fortalecer la '
            . 'comunicación con la población, permitiendo atender peticiones, recibir sugerencias y '
            . 'contribuir a mejorar la atención ciudadana. El detalle cronológico de cada petición '
            . 'recibida durante el mes se adjunta en la página siguiente.'), ENT_QUOTES, 'UTF-8') ?>
        </p>

        <div class="informe-firmas informe-firmas-una">
            <div class="informe-firma informe-firma-unica">
                <div class="linea" data-learn-id="firma-nombre"><?= htmlspecialchars(textoAprendido('firma-nombre', 'Lcda. María Fernanda Zaldaña Martínez'), ENT_QUOTES, 'UTF-8') ?></div>
                <div class="cargo" data-learn-id="firma-cargo"><?= htmlspecialchars(textoAprendido('firma-cargo', 'Oficina Municipal de Atención y Comunicación Ciudadana'), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>
    </div>

        <div class="informe-pie-pagina">1/2</div>
    </div>

    <!-- ==================== PÁGINA 2 EN ADELANTE: solo la tabla ====================
         Esta hoja es una PLANTILLA, no la versión final: contiene TODAS las
         filas del mes en una sola tabla larga. El script
         "repaginarInformeMensual()" (al final del documento) la reparte en
         tantas hojas tamaño carta como realmente se necesiten según la
         cantidad de peticiones, repitiendo el encabezado azul en cada una y
         renumerando el pie de página (1/N, 2/N, 3/N...). Si JavaScript
         estuviera deshabilitado, esta plantilla se sigue viendo/imprimiendo
         igual que antes (con el corte de fila evitado por informe_mensual.css). -->
    <div class="carta-pagina pagina-nueva" id="informe-pagina-tabla-plantilla">
    <div class="carta-contenido">
        <div class="informe-tabla-titulo"><span data-learn-id="titulo-tabla"><?= htmlspecialchars(textoAprendido('titulo-tabla', 'Detalle Cronológico de Peticiones'), ENT_QUOTES, 'UTF-8') ?></span> — <?= htmlspecialchars(ucfirst($mesesTexto[$mes]) . ' ' . $anio, ENT_QUOTES, 'UTF-8') ?></div>

        <?php if ($totalPeticiones > 0): ?>
        <table class="informe-tabla">
            <thead>
                <tr>
                    <th class="col-fecha" data-learn-id="col-fecha"><?= htmlspecialchars(textoAprendido('col-fecha', 'Fecha'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th class="col-tipo" data-learn-id="col-tipo"><?= htmlspecialchars(textoAprendido('col-tipo', 'Tipo de consulta'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th data-learn-id="col-descripcion"><?= htmlspecialchars(textoAprendido('col-descripcion', 'Descripción de la consulta'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th class="col-oficina" data-learn-id="col-oficina"><?= htmlspecialchars(textoAprendido('col-oficina', 'Oficina'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th class="col-estado" data-learn-id="col-estado"><?= htmlspecialchars(textoAprendido('col-estado', 'Estado'), ENT_QUOTES, 'UTF-8') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($filas as $f): ?>
                <tr>
                    <td class="col-fecha"><?= htmlspecialchars($f['fecha'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="col-tipo"><?= htmlspecialchars($f['tipo'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($f['descripcion'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="col-oficina"><?= htmlspecialchars($f['oficina'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="col-estado"><?= htmlspecialchars($f['estado'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="informe-vacio">No se registraron peticiones ciudadanas en este período.</div>
        <?php endif; ?>
    </div>

        <div class="informe-pie-pagina">2/2</div>
    </div>
<?php endif; ?>
</div>

    <div class="no-print" style="text-align:center; font-size:11px; color:#777; margin-top:6px;">
        Informe generado el <?= date('d/m/Y H:i') ?> a partir de <?= $totalPeticiones ?> petición(es) registradas en el sistema.
        Usa el botón "✏️ Editar" para modificar el texto; "💾 Guardar cambios" guarda y cierra la edición.
    </div>
</div>

<script>
/**
 * Reparte TODAS las filas de la tabla de peticiones en tantas hojas
 * tamaño carta como hagan falta según la cantidad real de datos, en vez
 * de forzar siempre "2 hojas" fijas. Repite el encabezado azul en cada
 * hoja nueva y renumera el pie de página de TODAS las hojas
 * (1/N, 2/N, 3/N...).
 *
 * @version 2 — BUGFIX: la versión anterior solo funcionaba la primera
 * vez que se generaba el informe, porque buscaba un id fijo
 * ("#informe-pagina-tabla-plantilla") y si no lo encontraba —por
 * ejemplo, al abrir un informe con una edición ya guardada (ver
 * guardar_edicion_informe.php)— se salía sin hacer nada
 * ("if (!plantilla) return;"). El problema es que el HTML guardado por
 * una edición NO trae ese id (guarda tal cual el DOM ya renderizado en
 * pantalla en ese momento), así que cualquier informe con una edición
 * guardada dejaba de repaginarse: todas las filas de la tabla quedaban
 * amontonadas en una sola hoja gigante, tanto al imprimir como en la
 * propia pantalla de edición (era exactamente lo que se veía en el
 * informe de agosto 2026, ya editado).
 *
 * Esta versión ya NO depende de ningún id fijo: junta las filas de
 * TODAS las hojas que traigan una tabla ".informe-tabla" —sea una sola
 * hoja larga sin repartir (recién generada o una edición vieja sin
 * repaginar) o varias hojas ya repartidas de una edición anterior— y
 * las vuelve a repartir de cero cada vez. Como se MUEVEN los mismos
 * nodos <tr> (no se regeneran a partir de datos), cualquier corrección
 * de texto que un administrador haya escrito a mano dentro de una fila
 * se conserva igual. También repara sobre la marcha la estructura de
 * cada hoja (".carta-contenido" + ".informe-pie-pagina") por si viene de
 * una edición guardada con una versión más antigua de la plantilla que
 * no tenía esos elementos.
 */
function repaginarInformeMensual() {
    var contenedor = document.getElementById('informe-contenido');
    var pagina1 = document.getElementById('informe-pagina-1');
    if (!contenedor || !pagina1) return;

    function pxDesdeCm(cm) {
        var sonda = document.createElement('div');
        sonda.style.position = 'absolute';
        sonda.style.visibility = 'hidden';
        sonda.style.height = cm + 'cm';
        document.body.appendChild(sonda);
        var px = sonda.offsetHeight;
        document.body.removeChild(sonda);
        return px;
    }

    // Completa la estructura ".carta-contenido" / ".informe-pie-pagina"
    // de una hoja si le falta (edición guardada con una plantilla vieja),
    // sin tocar el contenido ya editado. Devuelve el pie de página (lo
    // crea si no existía).
    function asegurarEstructuraHoja(hoja) {
        var contenido = null, pie = null;
        for (var i = 0; i < hoja.children.length; i++) {
            if (hoja.children[i].classList.contains('carta-contenido')) contenido = hoja.children[i];
            if (hoja.children[i].classList.contains('informe-pie-pagina')) pie = hoja.children[i];
        }
        if (!contenido) {
            contenido = document.createElement('div');
            contenido.className = 'carta-contenido';
            Array.prototype.slice.call(hoja.childNodes).forEach(function (nodo) {
                if (nodo === pie) return;
                contenido.appendChild(nodo);
            });
            hoja.insertBefore(contenido, hoja.firstChild);
        }
        if (!pie) {
            pie = document.createElement('div');
            pie.className = 'informe-pie-pagina';
            hoja.appendChild(pie);
        }
        return pie;
    }

    var pie1 = asegurarEstructuraHoja(pagina1);

    // Todas las hojas que no sean la página 1: la plantilla recién
    // generada, o las hojas de una edición guardada (repartida
    // correctamente o no).
    var otrasHojas = Array.prototype.slice.call(contenedor.children).filter(function (el) {
        return el !== pagina1;
    });
    otrasHojas.forEach(asegurarEstructuraHoja);

    var hojasConTabla = otrasHojas.filter(function (el) {
        return el.querySelector('table.informe-tabla');
    });

    // Caso sin peticiones registradas: no hay ninguna tabla que repartir,
    // solo se corrige la numeración de las hojas que ya existan.
    if (hojasConTabla.length === 0) {
        var totalSinTabla = 1 + otrasHojas.length;
        if (pie1) pie1.textContent = '1/' + totalSinTabla;
        otrasHojas.forEach(function (hoja, i) {
            var pieHoja = hoja.querySelector('.informe-pie-pagina');
            if (pieHoja) pieHoja.textContent = (i + 2) + '/' + totalSinTabla;
        });
        return;
    }

    // Junta el título de la tabla, el encabezado (thead) y TODAS las
    // filas de TODAS las hojas con tabla que haya en este momento.
    var tituloHtml = '';
    var theadHtml = '';
    var filas = [];
    hojasConTabla.forEach(function (hoja) {
        if (!tituloHtml) {
            var tituloEl = hoja.querySelector('.informe-tabla-titulo');
            if (tituloEl) tituloHtml = tituloEl.outerHTML;
        }
        var tablaHoja = hoja.querySelector('table.informe-tabla');
        if (!theadHtml) {
            var theadHoja = tablaHoja.querySelector('thead');
            if (theadHoja) theadHtml = theadHoja.outerHTML;
        }
        var tbodyHoja = tablaHoja.querySelector('tbody');
        if (tbodyHoja) {
            filas = filas.concat(Array.prototype.slice.call(tbodyHoja.querySelectorAll('tr')));
        }
    });

    // Hoja temporal con TODAS las filas juntas (se inserta en el lugar de
    // la primera hoja con tabla) para poder medir el alto real ya
    // renderizado de cada fila, con el ancho y la fuente final de
    // impresión, antes de repartirlas.
    var plantilla = document.createElement('div');
    plantilla.className = 'carta-pagina pagina-nueva';
    plantilla.innerHTML = '<div class="carta-contenido">' + tituloHtml
        + '<table class="informe-tabla">' + theadHtml + '</table></div>'
        + '<div class="informe-pie-pagina"></div>';
    var tbodyTemporal = document.createElement('tbody');
    filas.forEach(function (fila) { tbodyTemporal.appendChild(fila); });
    plantilla.querySelector('table.informe-tabla').appendChild(tbodyTemporal);

    hojasConTabla[0].parentNode.insertBefore(plantilla, hojasConTabla[0]);
    hojasConTabla.forEach(function (hoja) { hoja.parentNode.removeChild(hoja); });

    var tituloEl = plantilla.querySelector('.informe-tabla-titulo');
    var thead = plantilla.querySelector('thead');

    // Alto útil real de una hoja carta (27.94 cm) menos el padding vertical
    // que ya trae .carta-pagina (informe_mensual.css), y una reserva para
    // el pie de página (numeración), que no debe chocar con la última
    // fila de la tabla.
    var estiloHoja = getComputedStyle(plantilla);
    var alturaHojaTotal = pxDesdeCm(27.94);
    var paddingSup = parseFloat(estiloHoja.paddingTop) || 0;
    var paddingInf = parseFloat(estiloHoja.paddingBottom) || 0;
    var alturaContenido = alturaHojaTotal - paddingSup - paddingInf;
    var reservaPie = pxDesdeCm(0.9) + 20;

    var alturaTitulo = tituloEl ? tituloEl.offsetHeight : 0;
    var alturaThead = thead ? thead.offsetHeight : 0;

    var alturaDisponiblePrimeraHoja = alturaContenido - alturaTitulo - alturaThead - reservaPie;
    var alturaDisponibleSiguientes = alturaContenido - alturaThead - reservaPie;

    // Agrupa las filas (ya renderizadas, con su alto real según cuánto
    // ocupe cada descripción) en bloques que quepan en una hoja física.
    var bloques = [];
    var bloqueActual = [];
    var alturaAcumulada = 0;
    var esPrimerBloque = true;

    filas.forEach(function (fila) {
        var alturaFila = fila.getBoundingClientRect().height;
        var limite = esPrimerBloque ? alturaDisponiblePrimeraHoja : alturaDisponibleSiguientes;

        if (bloqueActual.length > 0 && (alturaAcumulada + alturaFila) > limite) {
            bloques.push(bloqueActual);
            bloqueActual = [];
            alturaAcumulada = 0;
            esPrimerBloque = false;
        }
        bloqueActual.push(fila);
        alturaAcumulada += alturaFila;
    });
    if (bloqueActual.length > 0) bloques.push(bloqueActual);
    if (bloques.length === 0) bloques.push([]);

    var totalPaginas = 1 + bloques.length;

    var fragmento = document.createDocumentFragment();
    bloques.forEach(function (filasDelBloque, indice) {
        var hoja = document.createElement('div');
        hoja.className = 'carta-pagina pagina-nueva';
        hoja.innerHTML = '<div class="carta-contenido">'
            + (indice === 0 ? tituloHtml : '')
            + '<table class="informe-tabla">' + theadHtml + '</table>'
            + '</div>'
            + '<div class="informe-pie-pagina">' + (indice + 2) + '/' + totalPaginas + '</div>';

        var cuerpoNuevo = document.createElement('tbody');
        filasDelBloque.forEach(function (fila) { cuerpoNuevo.appendChild(fila); });
        hoja.querySelector('table.informe-tabla').appendChild(cuerpoNuevo);

        fragmento.appendChild(hoja);
    });

    plantilla.parentNode.replaceChild(fragmento, plantilla);

    if (pie1) pie1.textContent = '1/' + totalPaginas;
}

document.addEventListener('DOMContentLoaded', repaginarInformeMensual);
// Por si el navegador reflowea con fuentes/tamaños distintos justo antes
// de imprimir, se vuelve a intentar (la función es idempotente: si las
// hojas ya están bien repartidas, las vuelve a juntar y a repartir igual).
window.addEventListener('beforeprint', repaginarInformeMensual);
</script>

<script>
(function () {
    var contenedor = document.getElementById('informe-contenido');
    if (!contenedor) return;

    var mes = contenedor.getAttribute('data-mes');
    var anio = contenedor.getAttribute('data-anio');

    // ------------------------------------------------------------
    // NUEVO: botón "🔄 Regenerar informe" (aparece solo si la edición
    // guardada quedó desactualizada porque llegaron tickets nuevos
    // después de la última edición manual — ver bloque PHP arriba).
    //
    // Llama al endpoint existente restablecer_informe.php, que borra
    // la edición guardada para este mes/año en informes_editados.json.
    // Al recargar, informe_mensual.php vuelve a generar el informe
    // desde cero a partir de tickets.json, mostrando TODAS las
    // peticiones del mes hasta el día de hoy.
    // ------------------------------------------------------------
    function conectarBotonRegenerar(boton, esForzado) {
        if (!boton) return;
        boton.addEventListener('click', function (e) {
            e.preventDefault();

            var mensajeConfirmar = esForzado
                ? '¿Regenerar el informe desde cero? Se perderán los cambios manuales guardados.'
                : '¿Regenerar el informe con las peticiones más recientes? Se perderán los cambios manuales que hayas hecho (título, párrafos, etc.) y el informe se armará de nuevo con TODAS las peticiones registradas hasta hoy.';
            if (!confirm(mensajeConfirmar)) return;

            var estado = document.getElementById('estado-regenerar-informe');
            boton.disabled = true;
            var textoOriginal = boton.textContent;
            boton.textContent = '⏳ Regenerando...';
            if (estado) {
                estado.textContent = 'Regenerando informe...';
                estado.style.display = 'inline';
            }

            fetch('restablecer_informe.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ mes: mes, anio: anio })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.success) {
                    if (estado) estado.textContent = 'Listo, recargando...';
                    window.location.reload();
                } else {
                    boton.disabled = false;
                    boton.textContent = textoOriginal;
                    if (estado) {
                        estado.textContent = 'No se pudo regenerar: ' + (data && data.error ? data.error : 'error desconocido');
                    } else {
                        alert('No se pudo regenerar el informe: ' + (data && data.error ? data.error : 'error desconocido'));
                    }
                }
            })
            .catch(function () {
                boton.disabled = false;
                boton.textContent = textoOriginal;
                if (estado) estado.textContent = 'No se pudo regenerar (sin conexión).';
                else alert('No se pudo regenerar (sin conexión).');
            });
        });
    }

    conectarBotonRegenerar(document.getElementById('btn-regenerar-informe'), false);
    conectarBotonRegenerar(document.getElementById('btn-regenerar-informe-forzado'), true);

    var btnEditar = document.getElementById('btn-editar-informe');
    var btnGuardar = document.getElementById('btn-guardar-informe');
    var estadoEl = document.getElementById('estado-guardado-informe');
    var editando = false;
    var huboCambios = false;

    function mostrarEstado(texto, esError) {
        estadoEl.textContent = texto;
        estadoEl.style.color = esError ? '#b00020' : '#2e7d32';
        estadoEl.style.display = 'inline-block';
        setTimeout(function () { estadoEl.style.display = 'none'; }, 4000);
    }

    function marcarCambio() {
        huboCambios = true;
    }

    function activarEdicion() {
        editando = true;
        contenedor.setAttribute('contenteditable', 'true');
        contenedor.style.outline = '2px dashed #1a3d7c';
        contenedor.style.outlineOffset = '6px';
        contenedor.addEventListener('input', marcarCambio);
        btnEditar.style.display = 'none';
        btnGuardar.style.display = 'inline-block';
    }

    function desactivarEdicion() {
        editando = false;
        contenedor.setAttribute('contenteditable', 'false');
        contenedor.style.outline = 'none';
        btnGuardar.style.display = 'none';
        btnEditar.style.display = 'inline-block';
    }

    btnEditar.addEventListener('click', function () {
        if (!editando) activarEdicion();
    });

    function guardarEdicion(callback) {
        fetch('guardar_edicion_informe.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ mes: mes, anio: anio, html: contenedor.innerHTML })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data && data.success) {
                huboCambios = false;
                mostrarEstado('Cambios guardados ✔', false);
                desactivarEdicion();
            } else {
                mostrarEstado('No se pudo guardar: ' + (data && data.error ? data.error : 'error desconocido'), true);
            }
            if (callback) callback();
        })
        .catch(function () {
            mostrarEstado('No se pudo guardar (sin conexión).', true);
            if (callback) callback();
        });
    }

    btnGuardar.addEventListener('click', function () { guardarEdicion(); });

    // Guardado automático después de imprimir, solo si hubo cambios sin
    // guardar (mismo comportamiento que en los oficios individuales).
    window.onafterprint = function () {
        if (huboCambios) {
            guardarEdicion();
        }
    };
})();
</script>

</body>
</html>