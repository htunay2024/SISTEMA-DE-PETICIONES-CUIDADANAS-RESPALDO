<?php
/**
 * whatsapp/importar_chats_core.php
 *
 * Importación automática de conversaciones de WhatsApp por mes, a partir
 * del historial que el propio bot guarda en
 *   whatsapp/sesiones/conversaciones/<numero>.jsonl
 * (un turno por línea: {hora, ciudadano, bot}; lo escribe recibir_bot.php).
 *
 * IMPORTANTE: recibir_bot.php BORRA ese archivo cuando una solicitud se
 * registra (ya queda copiada dentro del ticket) o se cancela/reinicia. Por
 * eso aquí solo quedan conversaciones que NO terminaron en ticket (el
 * ciudadano abandonó, o escribió fuera de horario sin ser una petición
 * válida, etc.). Este importador rescata justamente esas.
 *
 * Es idempotente: si el número ya tiene un ticket ese mes (normal,
 * informativo o importado), se omite.
 *
 * No se incluye recibir_bot.php (ejecutaría el webhook completo).
 */

// El clasificador define variables globales ($saludos, etc.), por eso debe
// cargarse aquí, a nivel global, y NO dentro de la función.
if (is_file(__DIR__ . '/../IA 2/clasificar.php')) {
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../IA 2/clasificar.php'; // clasificarPeticion()
}

if (!function_exists('importarConversacionesMes')) {

function importarConversacionesMes($mes, $anio, &$log = null)
{
    if ($log === null) $log = [];
    $out = ['creados' => 0, 'omitidos' => 0, 'descartados' => 0, 'en_curso' => 0,
            'mensajes' => 0, 'revisados' => 0, 'detalles' => []];

    require_once __DIR__ . '/../tickets/crear.php';
    require_once __DIR__ . '/../tickets/correlativo_solicitud.php'; // obtenerYAvanzarNumeroSolicitud()

    if ($mes < 1 || $mes > 12 || $anio < 2000) {
        $out['error'] = 'Mes/año inválidos.';
        return $out;
    }

    $carpetaConv = __DIR__ . '/sesiones/conversaciones';
    $archivos = is_dir($carpetaConv) ? (glob($carpetaConv . '/*.jsonl') ?: []) : [];
    if (empty($archivos)) {
        $out['error']   = 'No hay conversaciones pendientes de importar (el bot borra el historial de cada chat cuando su solicitud queda registrada como ticket).';
        $out['carpeta'] = $carpetaConv;
        return $out;
    }

    // Números que YA tienen ticket creado este mes/año.
    $tickets = file_exists(TICKETS_JSON) ? (json_decode(file_get_contents(TICKETS_JSON), true) ?: []) : [];
    $yaTienenTicket = [];
    foreach ($tickets as $t) {
        $ts = !empty($t['fecha_creacion']) ? strtotime($t['fecha_creacion']) : null;
        if (!$ts || (int) date('n', $ts) !== $mes || (int) date('Y', $ts) !== $anio) continue;
        $rem = preg_replace('/\D/', '', (string) ($t['remitente'] ?? ''));
        if ($rem !== '') $yaTienenTicket[$rem] = true;
    }

    $log[] = 'Revisando ' . count($archivos) . " conversación(es) del mes {$mes}/{$anio}…";

    foreach ($archivos as $archivo) {
        $numero = preg_replace('/\D/', '', basename($archivo, '.jsonl'));
        if ($numero === '' || $numero === '0') continue;
        $out['revisados']++;

        $lineas = file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lineas === false) continue;

        $turnosDelMes = [];
        $ultimaHora = 0;
        foreach ($lineas as $linea) {
            $turno = json_decode($linea, true);
            if (!is_array($turno)) continue;
            $ts = !empty($turno['hora']) ? strtotime($turno['hora']) : false;
            if (!$ts) continue;
            $ultimaHora = max($ultimaHora, $ts);
            if ((int) date('n', $ts) !== $mes || (int) date('Y', $ts) !== $anio) continue;
            $ciudadano = trim((string) ($turno['ciudadano'] ?? ''));
            $bot       = trim((string) ($turno['bot'] ?? ''));
            if ($ciudadano === '' && $bot === '') continue;
            $turnosDelMes[] = ['hora' => $turno['hora'], 'ciudadano' => $ciudadano, 'bot' => $bot];
        }
        if (empty($turnosDelMes)) continue;

        if (isset($yaTienenTicket[$numero])) {
            $out['omitidos']++;
            $log[] = "  · Omitido (ya tiene ticket este mes): {$numero}";
            continue;
        }

        // Conversación que aún puede estar en curso: si el ciudadano la
        // termina, el bot crearía su ticket y habría duplicado.
        if (time() - $ultimaHora < 3 * 3600) {
            $out['en_curso']++;
            $log[] = "  · Omitido (conversación activa hace menos de 3 h): {$numero}";
            continue;
        }

        $textos = [];
        foreach ($turnosDelMes as $t) {
            $c = $t['ciudadano'];
            if ($c === '' || $c === '[Fotografía adjunta]' || $c === '__FOTO_RECIBIDA__') continue;
            $textos[] = $c;
        }

        // Fotos de este número guardadas en el mes (ruta relativa validada por crearTicket).
        $fotos = [];
        $dirFotos = __DIR__ . '/fotos/' . $numero;
        if (is_dir($dirFotos)) {
            foreach (glob($dirFotos . '/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [] as $f) {
                $m = filemtime($f);
                if ($m && (int) date('n', $m) === $mes && (int) date('Y', $m) === $anio) {
                    $fotos[] = 'whatsapp/fotos/' . $numero . '/' . basename($f);
                }
            }
        }

        if (empty($textos) && empty($fotos)) { $out['descartados']++; continue; }

        // Clasificación. Un saludo suelto ("hola") no es una petición: se descarta.
        $categoria = 'administrativo'; $urgencia = 'baja'; $departamentoId = 0;
        if (!empty($textos)) {
            if (function_exists('clasificarPeticion')) {
                $cla = clasificarPeticion(implode("\n", $textos));
                if (!empty($cla['es_saludo']) || empty($cla['categoria'])) {
                    $out['descartados']++;
                    $log[] = "  · Descartado (solo saludo / sin petición): {$numero}";
                    continue;
                }
                $categoria = $cla['categoria'];
                $urgencia = $cla['urgencia'] ?? 'baja';
                $departamentoId = $cla['departamento_id'] ?? 0;
            }
            $textoConsolidado = implode("\n", $textos);
        } else {
            $textoConsolidado = '[Conversación con fotografías, sin descripción textual]';
        }

        $expediente = 'SPJ-IMP-' . $anio . '-' . str_pad((string) obtenerYAvanzarNumeroSolicitud(), 4, '0', STR_PAD_LEFT);

        $ticketId = crearTicket([
            'remitente'         => $numero,
            'mensaje_original'  => $textoConsolidado,
            'categoria'         => $categoria,
            'urgencia'          => $urgencia,
            'departamento_id'   => $departamentoId,
            'estado'            => 'pendiente',
            'canal'             => 'whatsapp',
            'expediente'        => $expediente,
            'nombre_ciudadano'  => '',   // lo completa la encargada; no se adivina
            'telefono_contacto' => $numero,
            'direccion'         => '',
            'conversacion'      => $turnosDelMes,
            'fotos'             => $fotos,
            'es_informativa'    => true,
            'importado'         => true,
            'fecha_creacion'    => $turnosDelMes[0]['hora'],
        ]);

        if ($ticketId) {
            $out['creados']++;
            $out['mensajes'] += count($textos);
            $yaTienenTicket[$numero] = true;
            $out['detalles'][] = ['numero' => $numero, 'ticket' => $ticketId, 'mensajes' => count($textos)];
            $log[] = "  + Ticket #{$ticketId} creado para {$numero} (" . count($textos) . ' mensajes)';
        } else {
            $log[] = "  ! ERROR creando ticket para {$numero}";
        }
    }

    return $out;
}

}

// ============================================================================
// HISTORIAL COMPLETO DE WHATSAPP (archivo permanente que escribe el bot en
// whatsapp/historial_chats/<numero>.jsonl, ver whatsapp-bot/archivo_chats.js).
// A diferencia de importarConversacionesMes(), esto lee TODOS los mensajes
// (del ciudadano y de la municipalidad) y NO depende de que el chat haya
// quedado sin ticket.
// ============================================================================
if (!function_exists('historialCargarChats')) {

/** Lee todos los chats archivados: [ numero => [mensajes ordenados por ts] ] */
function historialCargarChats(&$sinNumero = 0)
{
    $carpeta = __DIR__ . '/historial_chats';
    $dirAuth = __DIR__ . '/../../whatsapp-bot/auth_info';
    $chats = [];
    $sinNumero = 0;

    foreach (glob($carpeta . '/*.jsonl') ?: [] as $archivo) {
        $base = basename($archivo, '.jsonl');
        if (strpos($base, 'lid-') === 0) {
            // Chat que llegó con un identificador LID que el bot no pudo traducir
            // en ese momento: se intenta de nuevo con el mapa que guarda Baileys.
            $lid = preg_replace('/\D/', '', substr($base, 4));
            $numero = '';
            $rutaMapa = $dirAuth . '/lid-mapping-' . $lid . '_reverse.json';
            if ($lid !== '' && is_file($rutaMapa)) {
                $numero = preg_replace('/\D/', '', (string) json_decode((string) file_get_contents($rutaMapa), true));
            }
            if ($numero === '') { $sinNumero++; continue; }
        } else {
            $numero = preg_replace('/\D/', '', $base);
        }
        if ($numero === '' || $numero === '0') continue;

        foreach (file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
            $m = json_decode($linea, true);
            if (!is_array($m) || empty($m['id']) || empty($m['ts'])) continue;
            // La clave = id evita duplicados (mismo chat visto como LID y como teléfono).
            $chats[$numero][$m['id']] = $m;
        }
    }

    foreach ($chats as $n => $porId) {
        $lista = array_values($porId);
        usort($lista, function ($a, $b) { return ((int) $a['ts']) <=> ((int) $b['ts']); });
        $chats[$n] = $lista;
    }
    return $chats;
}

function historialTextoVisible($m)
{
    $texto = trim((string) ($m['texto'] ?? ''));
    if ($texto !== '') return $texto;
    switch ($m['tipo'] ?? '') {
        case 'imagen':    return '[Fotografía adjunta]';
        case 'video':     return '[Video adjunto]';
        case 'audio':     return '[Nota de voz]';
        case 'documento': return '[Documento adjunto]';
    }
    return '';
}

function historialEsMarcador($texto)
{
    return preg_match('/^\[(Fotografía adjunta|Video adjunto|Nota de voz|Documento adjunto)\]$/u', $texto) === 1;
}

/** Resumen del archivo: totales y mensajes por mes (para el panel). */
function historialResumen()
{
    $sinNumero = 0;
    $chats = historialCargarChats($sinNumero);
    $meses = []; $total = 0; $min = 0; $max = 0;

    foreach ($chats as $numero => $lista) {
        foreach ($lista as $m) {
            $ts = (int) $m['ts'];
            $total++;
            if ($min === 0 || $ts < $min) $min = $ts;
            if ($ts > $max) $max = $ts;
            $k = date('Y-m', $ts);
            if (!isset($meses[$k])) $meses[$k] = ['mensajes' => 0, 'ciudadanos' => []];
            $meses[$k]['mensajes']++;
            if (empty($m['me'])) $meses[$k]['ciudadanos'][$numero] = true;
        }
    }
    ksort($meses);

    $detalle = [];
    foreach ($meses as $k => $v) {
        $partes = explode('-', $k);
        $detalle[] = ['anio' => (int) $partes[0], 'mes' => (int) $partes[1],
                      'mensajes' => $v['mensajes'], 'chats' => count($v['ciudadanos'])];
    }
    return [
        'chats'      => count($chats),
        'mensajes'   => $total,
        'sin_numero' => $sinNumero,
        'desde'      => $min ? date('Y-m-d H:i', $min) : null,
        'hasta'      => $max ? date('Y-m-d H:i', $max) : null,
        'meses'      => $detalle,
    ];
}

/**
 * Crea "peticiones informativas" a partir del historial completo de un mes.
 * Cada chat se parte en conversaciones (silencio mayor a $brechaHoras = otra
 * petición). Se omite toda conversación que ya tenga ticket de ese número en
 * esas mismas horas, así que se puede repetir sin duplicar.
 */
function importarHistorialMes($mes, $anio, &$log = null, $brechaHoras = 12)
{
    if ($log === null) $log = [];
    $out = ['revisados' => 0, 'creados' => 0, 'omitidos' => 0, 'descartados' => 0,
            'en_curso' => 0, 'excluidos' => 0, 'sin_numero' => 0, 'mensajes' => 0, 'detalles' => []];

    @set_time_limit(0);
    ignore_user_abort(true);

    require_once __DIR__ . '/../tickets/crear.php';
    require_once __DIR__ . '/../tickets/correlativo_solicitud.php'; // obtenerYAvanzarNumeroSolicitud()

    if ($mes < 1 || $mes > 12 || $anio < 2000) {
        $out['error'] = 'Mes/año inválidos.';
        return $out;
    }

    $chats = historialCargarChats($out['sin_numero']);
    if (empty($chats)) {
        $out['error'] = 'Todavía no hay historial archivado. El bot empieza a archivar apenas arranca con la versión nueva; para traer mensajes viejos usá "Pedir mensajes anteriores" o re-vinculá el teléfono (ver instrucciones).';
        return $out;
    }

    $inicioMes = mktime(0, 0, 0, $mes, 1, $anio);
    $finMes    = mktime(0, 0, 0, $mes + 1, 1, $anio);
    $brecha    = max(1, (int) $brechaHoras) * 3600;
    $margen    = 3 * 3600;

    // Números de la municipalidad que el admin excluyó del bot: no son ciudadanos.
    $excluidos = [];
    $rutaExc = __DIR__ . '/../../whatsapp-bot/numeros_excluidos.json';
    if (is_file($rutaExc)) {
        $d = json_decode((string) file_get_contents($rutaExc), true);
        foreach (($d['numeros'] ?? []) as $n) {
            $n = preg_replace('/\D/', '', (string) $n);
            if ($n !== '') $excluidos[$n] = true;
        }
    }

    // Momentos en que ya existe un ticket de cada número (bot, dashboard o importado).
    $tickets = file_exists(TICKETS_JSON) ? (json_decode(file_get_contents(TICKETS_JSON), true) ?: []) : [];
    $ticketsPorNumero = [];
    foreach ($tickets as $t) {
        $ts = !empty($t['fecha_creacion']) ? strtotime($t['fecha_creacion']) : false;
        if (!$ts) continue;
        foreach ([$t['remitente'] ?? '', $t['telefono_contacto'] ?? ''] as $campo) {
            $n = preg_replace('/\D/', '', (string) $campo);
            if ($n !== '') $ticketsPorNumero[$n][] = $ts;
        }
    }

    $log[] = 'Revisando ' . count($chats) . " chat(s) archivado(s) para {$mes}/{$anio}…";

    foreach ($chats as $numero => $mensajes) {
        if (isset($excluidos[$numero])) { $out['excluidos']++; continue; }

        // Partir el chat en conversaciones por silencios largos.
        $segmentos = []; $actual = []; $ultimo = 0;
        foreach ($mensajes as $m) {
            $ts = (int) $m['ts'];
            if (!empty($actual) && ($ts - $ultimo) > $brecha) { $segmentos[] = $actual; $actual = []; }
            $actual[] = $m;
            $ultimo = $ts;
        }
        if (!empty($actual)) $segmentos[] = $actual;

        foreach ($segmentos as $seg) {
            $ini = (int) $seg[0]['ts'];
            $fin = (int) $seg[count($seg) - 1]['ts'];
            if ($ini < $inicioMes || $ini >= $finMes) continue; // pertenece a otro mes
            $out['revisados']++;

            if (time() - $fin < 3 * 3600) {
                $out['en_curso']++;
                $log[] = "  · Omitida (conversación activa hace menos de 3 h): {$numero}";
                continue;
            }

            $yaTiene = false;
            foreach ($ticketsPorNumero[$numero] ?? [] as $tsT) {
                if ($tsT >= $ini - $margen && $tsT <= $fin + $margen) { $yaTiene = true; break; }
            }
            if ($yaTiene) {
                $out['omitidos']++;
                $log[] = "  · Omitida (ya tiene ticket en esas horas): {$numero} " . date('d/m H:i', $ini);
                continue;
            }

            // Lo que escribió el ciudadano (sin marcadores de multimedia).
            $textos = []; $hayFoto = false;
            foreach ($seg as $m) {
                if (!empty($m['me'])) continue;
                if (($m['tipo'] ?? '') === 'imagen') $hayFoto = true;
                $t = historialTextoVisible($m);
                if ($t === '' || historialEsMarcador($t)) continue;
                $textos[] = $t;
            }

            // Fotos descargadas de este número cuyo nombre (marca de tiempo en ms) cae en la conversación.
            $fotos = [];
            $dirFotos = __DIR__ . '/fotos/' . $numero;
            if (is_dir($dirFotos)) {
                foreach (glob($dirFotos . '/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [] as $f) {
                    if (!preg_match('/^(\d{10,13})_/', basename($f), $mm)) continue;
                    $tsF = strlen($mm[1]) >= 13 ? intdiv((int) $mm[1], 1000) : (int) $mm[1];
                    if ($tsF >= $ini - 120 && $tsF <= $fin + 120) {
                        $fotos[] = 'whatsapp/fotos/' . $numero . '/' . basename($f);
                    }
                }
            }

            if (empty($textos) && empty($fotos) && !$hayFoto) { $out['descartados']++; continue; }

            // Clasificación (misma IA del sistema). Un saludo suelto no es una petición.
            $categoria = 'administrativo'; $urgencia = 'baja'; $departamentoId = 0;
            if (!empty($textos)) {
                if (function_exists('clasificarPeticion')) {
                    $cla = clasificarPeticion(implode("\n", $textos));
                    if (!empty($cla['es_saludo']) || empty($cla['categoria'])) {
                        $out['descartados']++;
                        $log[] = "  · Descartada (solo saludo / sin petición): {$numero} " . date('d/m H:i', $ini);
                        continue;
                    }
                    $categoria = $cla['categoria'];
                    $urgencia = $cla['urgencia'] ?? 'baja';
                    $departamentoId = $cla['departamento_id'] ?? 0;
                }
                $textoConsolidado = implode("\n", $textos);
            } else {
                $textoConsolidado = '[Conversación con fotografías, sin descripción textual]';
            }

            // Conversación completa: lo del ciudadano en "ciudadano", lo de la
            // municipalidad (enviado desde el teléfono) en "bot" del turno anterior.
            $conversacion = [];
            foreach ($seg as $m) {
                $txt = historialTextoVisible($m);
                if ($txt === '') continue;
                $hora = date('Y-m-d H:i:s', (int) $m['ts']);
                if (empty($m['me'])) {
                    $conversacion[] = ['hora' => $hora, 'ciudadano' => $txt, 'bot' => ''];
                } elseif (empty($conversacion)) {
                    $conversacion[] = ['hora' => $hora, 'ciudadano' => '', 'bot' => $txt];
                } else {
                    $i = count($conversacion) - 1;
                    $conversacion[$i]['bot'] = trim($conversacion[$i]['bot'] . "\n" . $txt);
                }
            }

            $expediente = 'SPJ-IMP-' . $anio . '-' . str_pad((string) obtenerYAvanzarNumeroSolicitud(), 4, '0', STR_PAD_LEFT);

            $ticketId = crearTicket([
                'remitente'         => $numero,
                'mensaje_original'  => $textoConsolidado,
                'categoria'         => $categoria,
                'urgencia'          => $urgencia,
                'departamento_id'   => $departamentoId,
                'estado'            => 'pendiente',
                'canal'             => 'whatsapp',
                'expediente'        => $expediente,
                'nombre_ciudadano'  => '',   // lo completa la encargada; no se adivina
                'telefono_contacto' => $numero,
                'direccion'         => '',
                'conversacion'      => $conversacion,
                'fotos'             => $fotos,
                'es_informativa'    => true,
                'importado'         => true,
                'fecha_creacion'    => date('Y-m-d H:i:s', $ini),
            ]);

            if ($ticketId) {
                $out['creados']++;
                $out['mensajes'] += count($textos);
                $ticketsPorNumero[$numero][] = $ini;
                $out['detalles'][] = ['numero' => $numero, 'ticket' => $ticketId, 'mensajes' => count($textos)];
                $log[] = "  + Ticket #{$ticketId} creado para {$numero} (" . count($textos) . ' mensajes, ' . date('d/m H:i', $ini) . ')';
            } else {
                $log[] = "  ! ERROR creando ticket para {$numero}";
            }
        }
    }

    return $out;
}

}
