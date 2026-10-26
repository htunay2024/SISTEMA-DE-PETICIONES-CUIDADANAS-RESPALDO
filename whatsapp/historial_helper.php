<?php
/**
 * whatsapp/historial_helper.php
 *
 * Historial PERMANENTE de todos los mensajes de WhatsApp, uno por número.
 * A diferencia de whatsapp/sesiones/conversaciones/<numero>.jsonl (que se
 * borra al crear el ticket), este archivo NUNCA se borra: es la fuente de
 * verdad para "releer chat" y reprocesar peticiones.
 *
 * Formato: una línea JSON por turno, igual que el conversation log:
 *   {"hora":"2026-10-15 06:58:00","ciudadano":"...","bot":"..."}
 */

if (!defined('HISTORIAL_DIR')) {
    define('HISTORIAL_DIR', __DIR__ . '/sesiones/historial');
}
if (!is_dir(HISTORIAL_DIR)) {
    @mkdir(HISTORIAL_DIR, 0777, true);
}

if (!function_exists('rutaHistorial')) {
    function rutaHistorial($numero) {
        $n = preg_replace('/\D/', '', (string) $numero);
        return HISTORIAL_DIR . '/' . $n . '.jsonl';
    }
}

/**
 * Agrega un turno al historial permanente. Idempotente por (hora, ciudadano):
 * si el mismo turno ya está guardado, no lo duplica.
 */
if (!function_exists('registrarTurnoEnHistorial')) {
    function registrarTurnoEnHistorial($numero, $mensaje, $respuesta, $hora = null) {
        $n = preg_replace('/\D/', '', (string) $numero);
        if ($n === '') return false;

        $turno = [
            'hora'      => $hora ?: date('Y-m-d H:i:s'),
            'ciudadano' => (string) $mensaje,
            'bot'       => (string) $respuesta,
        ];
        $archivo = rutaHistorial($n);

        // Dedupe: si el último turno tiene la misma hora + ciudadano, no repetir
        if (file_exists($archivo)) {
            $ultima = trim((string) @shell_exec('tail -n 1 ' . escapeshellarg($archivo)));
            if ($ultima !== '') {
                $ultimo = json_decode($ultima, true);
                if (is_array($ultimo)
                    && ($ultimo['hora'] ?? '') === $turno['hora']
                    && ($ultimo['ciudadano'] ?? '') === $turno['ciudadano']) {
                    return false;
                }
            }
        }

        @file_put_contents(
            $archivo,
            json_encode($turno, JSON_UNESCAPED_UNICODE) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
        return true;
    }
}

/**
 * Lee todos los turnos del historial de un número (array cronológico).
 */
if (!function_exists('leerHistorial')) {
    function leerHistorial($numero) {
        $n = preg_replace('/\D/', '', (string) $numero);
        if ($n === '') return [];
        $archivo = rutaHistorial($n);
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
}
