<?php
/**
 * whatsapp/emojis_config.php
 *
 * Controla si el bot de WhatsApp envía emojis en sus respuestas a los
 * ciudadanos (🔴 urgencia, 📋 folio, ✓/❌, etc.) o si los envía en texto
 * plano, sin cambiar nada más de la lógica de conversación.
 *
 * El estado (activado/desactivado) se guarda en un archivo JSON simple,
 * igual que el resto de configuraciones del sistema (sin base de datos).
 * Lo activa/desactiva el admin desde vistas/whatsapp_admin.php a través
 * del endpoint whatsapp/emojis.php.
 *
 * Por defecto los emojis están ACTIVADOS (así se comportaba el sistema
 * antes de este archivo), para no cambiar el comportamiento existente si
 * el archivo de configuración todavía no existe.
 */

if (!defined('EMOJIS_CONFIG_JSON')) {
    define('EMOJIS_CONFIG_JSON', __DIR__ . '/../config/emojis.json');
}

/**
 * Devuelve true si el bot debe enviar emojis en sus respuestas, false si
 * deben quitarse antes de enviarlas por WhatsApp.
 */
if (!function_exists('emojisActivados')) {
function emojisActivados() {
    if (!file_exists(EMOJIS_CONFIG_JSON)) {
        return true; // valor por defecto: comportamiento original (con emojis)
    }
    $datos = json_decode(@file_get_contents(EMOJIS_CONFIG_JSON), true);
    if (!is_array($datos) || !array_key_exists('activado', $datos)) {
        return true;
    }
    return (bool) $datos['activado'];
}
}

/**
 * Guarda el estado activado/desactivado en el archivo de configuración.
 * @return bool true si se pudo guardar
 */
if (!function_exists('establecerEmojisActivados')) {
function establecerEmojisActivados($activado) {
    $carpeta = dirname(EMOJIS_CONFIG_JSON);
    if (!is_dir($carpeta)) {
        @mkdir($carpeta, 0777, true);
    }
    $resultado = @file_put_contents(
        EMOJIS_CONFIG_JSON,
        json_encode(['activado' => (bool) $activado], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
    return $resultado !== false;
}
}

/**
 * Quita los emojis de un texto (rangos Unicode donde caen los emojis que
 * usa el sistema: 🔴🟠🟡🟢 👋 📋📅📂📌🔄 ✓❌⚠ 🏛️, banderas, etc.), y
 * limpia los espacios/dobles saltos de línea que puedan quedar sueltos
 * donde antes había un emoji seguido de un espacio.
 */
if (!function_exists('quitarEmojisDeTexto')) {
function quitarEmojisDeTexto($texto) {
    if ($texto === null || $texto === '') {
        return $texto;
    }
    $patronEmojis = '/[' .
        '\x{1F1E6}-\x{1F1FF}' . // banderas (indicadores regionales)
        '\x{1F300}-\x{1FAFF}' . // emoticones, símbolos, transporte, etc.
        '\x{2600}-\x{27BF}'   . // símbolos varios y dingbats (✓ ❌ ⚠ ☀ etc.)
        '\x{2B00}-\x{2BFF}'   . // símbolos y flechas varias (⭐ etc.)
        '\x{FE0F}'             . // selector de variación (presentación emoji)
        '\x{200D}'              // zero-width joiner (emojis combinados)
    . ']/u';

    $limpio = preg_replace($patronEmojis, '', $texto);
    if ($limpio === null) {
        // preg_replace puede fallar si el texto no es UTF-8 válido; en ese
        // caso preferimos devolver el texto original (con emojis) antes
        // que romper la respuesta al ciudadano.
        return $texto;
    }

    // Espacios dobles que quedan donde había "emoji + espacio"
    $limpio = preg_replace('/[ \t]{2,}/', ' ', $limpio);
    // Espacio suelto justo antes de un salto de línea
    $limpio = preg_replace('/[ \t]+\n/', "\n", $limpio);
    // Espacio suelto al inicio de cada línea (ej. "🔴 URGENCIA" -> " URGENCIA" -> "URGENCIA")
    $limpio = preg_replace('/^[ \t]+/m', '', $limpio);

    return $limpio;
}
}

/**
 * Punto único que usa recibir_bot.php antes de devolver cada respuesta al
 * bot de Node: si los emojis están desactivados, los quita; si están
 * activados, devuelve el texto tal cual (comportamiento original).
 */
if (!function_exists('prepararRespuestaWhatsApp')) {
function prepararRespuestaWhatsApp($texto) {
    if (emojisActivados()) {
        return $texto;
    }
    return quitarEmojisDeTexto($texto);
}
}
