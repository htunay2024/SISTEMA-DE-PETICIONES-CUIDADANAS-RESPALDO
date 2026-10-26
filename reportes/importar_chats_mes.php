<?php
/**
 * reportes/importar_chats_mes.php
 *
 * Importa exports .txt de chats de WhatsApp y crea "peticiones informativas"
 * para los mensajes de un mes/año (p. ej. octubre 1-5, antes de que el bot
 * arrancara), para que los oficios y el informe mensual queden completos.
 *
 * CLI:      php reportes/importar_chats_mes.php <mes> <anio> [archivo.txt] [nombres_propios]
 * Navegador (sesión de administrador iniciada):
 *           /reportes/importar_chats_mes.php?mes=10&anio=2026[&archivo=chat.txt][&propio=Nombre1,Nombre2]
 *
 * Sin archivo, procesa todos los .txt de whatsapp/historial_importar/.
 * "propio" = nombre(s) con que aparece la cuenta de la municipalidad en el
 * export (se descartan sus mensajes; solo se importa lo que escribe el ciudadano).
 *
 * Formatos: Android "12/10/2026, 14:30 - Nombre: texto" (también "2:30 p. m.")
 *           iOS     "[12/10/2026, 14:30:00] Nombre: texto"
 * Se asume fecha día/mes/año.
 */

$esCli = (php_sapi_name() === 'cli');
if (!$esCli) {
    session_start();
    if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "No autorizado. Inicia sesion como administrador.\n";
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/../tickets/crear.php';
// Clasificador de la IA (aporta clasificarPeticion()); si no carga, se usa "administrativo".
$rutaClasificar = __DIR__ . '/../IA 2/clasificar.php';
if (is_file($rutaClasificar)) {
    require_once $rutaClasificar;
}

if ($esCli) {
    $mes = (int) ($argv[1] ?? 0);
    $anio = (int) ($argv[2] ?? 0);
    $rutaManual = $argv[3] ?? null;
    $propiosRaw = $argv[4] ?? '';
} else {
    $mes = (int) ($_GET['mes'] ?? 0);
    $anio = (int) ($_GET['anio'] ?? 0);
    $rutaManual = $_GET['archivo'] ?? null;
    $propiosRaw = (string) ($_GET['propio'] ?? '');
}

if ($mes < 1 || $mes > 12 || $anio < 2000) {
    echo "Uso: importar_chats_mes.php <mes (1-12)> <anio> [archivo.txt] [nombres_propios]\n";
    exit(1);
}

$carpeta = __DIR__ . '/../whatsapp/historial_importar';
if (!is_dir($carpeta)) {
    @mkdir($carpeta, 0777, true);
}

// Nombres a descartar (mensajes de la municipalidad / del propio bot).
$propios = ['tú', 'tu', 'you', 'yo', 'municipalidad bot'];
foreach (explode(',', $propiosRaw) as $n) {
    $n = mb_strtolower(trim($n));
    if ($n !== '') $propios[] = $n;
}

// Lista de archivos.
$archivos = [];
if ($rutaManual) {
    // Desde el navegador solo se permiten archivos dentro de la carpeta de importación.
    $ruta = $esCli ? $rutaManual : $carpeta . '/' . basename($rutaManual);
    if (!is_file($ruta)) {
        echo "No existe el archivo: {$ruta}\n";
        exit(1);
    }
    $archivos[] = $ruta;
} else {
    foreach (glob($carpeta . '/*.txt') as $f) $archivos[] = $f;
}
if (empty($archivos)) {
    echo "No hay archivos .txt en: {$carpeta}\n";
    echo "Coloca alli los exports de WhatsApp y vuelve a ejecutar.\n";
    exit(1);
}

echo "=== IMPORTACION DE CHATS DE WHATSAPP ===\n";
echo "Mes: {$mes}/{$anio}\nArchivos a procesar: " . count($archivos) . "\n";
echo "---------------------------------------\n";

// Anti-duplicados: tickets ya importados (mismo remitente importado y mismo mes).
$tickets = file_exists(TICKETS_JSON) ? (json_decode(file_get_contents(TICKETS_JSON), true) ?: []) : [];
$yaImportado = [];
foreach ($tickets as $t) {
    if (empty($t['importado']) || empty($t['fecha_creacion'])) continue;
    $ts = strtotime($t['fecha_creacion']);
    if ($ts && (int) date('n', $ts) === $mes && (int) date('Y', $ts) === $anio) {
        $yaImportado[$t['remitente'] ?? ''] = true;
    }
}

$creados = 0; $omitidos = 0; $totalMensajes = 0;

foreach ($archivos as $archivo) {
    echo "\nProcesando: " . basename($archivo) . "\n";
    $lineas = file($archivo, FILE_IGNORE_NEW_LINES);
    if ($lineas === false) continue;

    $mensajesTodos = []; // cada: nombre, fecha (Y-m-d H:i:s), texto

    foreach ($lineas as $linea) {
        // Quitar BOM y marcas invisibles de direccionalidad que WhatsApp inserta.
        $linea = preg_replace('/^\x{FEFF}|[\x{200E}\x{200F}\x{202A}-\x{202E}]/u', '', $linea);
        $m = null;
        if (preg_match('/^\[(\d{1,2})\/(\d{1,2})\/(\d{2,4}),?\s+(\d{1,2}):(\d{2})(?::\d{2})?\s*([ap])?\.?\s*m?\.?\]\s+([^:]+):\s?(.*)$/iu', $linea, $m)
            || preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4}),?\s+(\d{1,2}):(\d{2})(?::\d{2})?\s*([ap])?\.?\s*m?\.?\s+-\s+([^:]+):\s?(.*)$/iu', $linea, $m)) {
            $d = (int) $m[1]; $mo = (int) $m[2]; $y = (int) $m[3];
            if ($y < 100) $y += 2000;
            $h = (int) $m[4]; $mi = (int) $m[5];
            $ampm = strtolower($m[6] ?? '');
            if ($ampm === 'p' && $h < 12) $h += 12;
            if ($ampm === 'a' && $h === 12) $h = 0;
            $mensajesTodos[] = [
                'nombre' => trim($m[7]),
                'fecha'  => sprintf('%04d-%02d-%02d %02d:%02d:00', $y, $mo, $d, $h, $mi),
                'texto'  => $m[8],
                'mes'    => $mo, 'anio' => $y,
            ];
        } elseif (!empty($mensajesTodos) && trim($linea) !== '') {
            // Continuación de un mensaje de varias líneas.
            $mensajesTodos[count($mensajesTodos) - 1]['texto'] .= "\n" . $linea;
        }
    }

    // Filtrar por mes/año y descartar propios, multimedia omitida y avisos del sistema.
    $porRemitente = [];
    foreach ($mensajesTodos as $msg) {
        if ($msg['mes'] !== $mes || $msg['anio'] !== $anio) continue;
        if (in_array(mb_strtolower($msg['nombre']), $propios, true)) continue;
        $txt = trim($msg['texto']);
        if ($txt === '' || preg_match('/^<?(multimedia omitido|media omitted|imagen omitida|image omitted)>?$/iu', $txt)) continue;
        $porRemitente[$msg['nombre']][] = ['fecha' => $msg['fecha'], 'texto' => $txt];
    }

    if (empty($porRemitente)) {
        echo "  (sin mensajes del mes {$mes}/{$anio} en este archivo)\n";
        continue;
    }

    foreach ($porRemitente as $nombre => $mensajes) {
        $ident = 'IMPORT-' . substr(md5($nombre), 0, 10);
        if (isset($yaImportado[$ident])) {
            $omitidos++;
            echo "  . Omitido (ya importado este mes): {$nombre}\n";
            continue;
        }

        $textoCompleto = implode("\n", array_column($mensajes, 'texto'));
        $conversacion = [];
        foreach ($mensajes as $msg) {
            $conversacion[] = [
                'hora'      => $msg['fecha'],
                'ciudadano' => $msg['texto'],
                'bot'       => '[Importado de WhatsApp, sin respuesta automática]',
            ];
        }

        // Clasificación automática (misma IA del sistema); si no hay señal, queda administrativo.
        $cat = 'administrativo'; $urg = 'baja'; $dep = 0;
        if (function_exists('clasificarPeticion')) {
            $cla = clasificarPeticion($textoCompleto);
            if (!empty($cla['categoria'])) {
                $cat = $cla['categoria']; $urg = $cla['urgencia'] ?? 'baja'; $dep = $cla['departamento_id'] ?? 0;
            }
        }

        $ticketId = crearTicket([
            'remitente'         => $ident,
            'mensaje_original'  => $textoCompleto,
            'categoria'         => $cat,
            'urgencia'          => $urg,
            'departamento_id'   => $dep,
            'estado'            => 'pendiente',
            'canal'             => 'whatsapp',
            'expediente'        => 'SPJ-IMP-' . $anio . '-' . strtoupper(substr(md5($nombre . $mes), 0, 5)),
            'nombre_ciudadano'  => $nombre,
            'telefono_contacto' => '(importado)',
            'direccion'         => '',
            'conversacion'      => $conversacion,
            'fotos'             => [],
            'es_informativa'    => true,
            'importado'         => true,
            'fecha_creacion'    => $mensajes[0]['fecha'],
        ]);

        if ($ticketId) {
            $creados++; $totalMensajes += count($mensajes); $yaImportado[$ident] = true;
            echo "  + Ticket #{$ticketId} creado para: {$nombre} (" . count($mensajes) . " mensajes)\n";
        } else {
            echo "  ! ERROR creando ticket para: {$nombre}\n";
        }
    }
}

echo "\n---------------------------------------\n";
echo "Tickets creados:     {$creados}\nTickets omitidos:    {$omitidos}\nMensajes importados: {$totalMensajes}\n";
echo "=======================================\n";
