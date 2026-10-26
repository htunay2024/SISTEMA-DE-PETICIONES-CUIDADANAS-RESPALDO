/**
 * whatsapp-bot-sistema-peticiones/index.js
 *
 * Puente entre WhatsApp (vía Baileys, protocolo no oficial tipo "WhatsApp Web")
 * y el backend PHP del SISTEMA_PETICIONES_CUIDADANAS.
 *
 * FLUJO:
 * 1) Este proceso se conecta a WhatsApp escaneando un QR. Ya NO hace falta
 *    mirar la consola: el código QR también se expone como imagen PNG
 *    (base64) a través del endpoint GET /qr, para que el panel de
 *    administración del sistema web lo pueda mostrar directamente.
 * 2) Cuando llega un mensaje de un ciudadano, se lo reenvía por HTTP
 *    a whatsapp/recibir_bot.php (tu PHP existente, adaptado).
 * 3) PHP procesa (IA, tickets, etc.) y devuelve el texto de respuesta
 *    en el JSON de respuesta HTTP.
 * 4) Este proceso envía esa respuesta al ciudadano por WhatsApp.
 *
 * También expone:
 *   POST /enviar        -> para que PHP pueda mandar mensajes salientes
 *   GET  /estado         -> estado de conexión (conectado, número vinculado,
 *                           modo prueba activo o no)
 *   GET  /qr              -> último código QR pendiente de escanear (imagen PNG en base64)
 *   POST /desvincular     -> cierra sesión de WhatsApp y borra las credenciales
 *                            guardadas, dejando el bot listo para generar un
 *                            QR nuevo (vinculación "a voluntad" desde el panel admin)
 *   POST /vincular        -> si el bot está desvinculado o detenido, fuerza el
 *                            arranque de una nueva conexión (genera QR nuevo)
 *   POST /modo_prueba     -> activa/desactiva el "modo prueba" (ver más abajo)
 *   POST /emojis          -> activa/desactiva el uso de emojis en las
 *                            respuestas salientes (pruebas de compatibilidad)
 *   POST /velocidad_escritura -> cambia la velocidad de "escritura" (caracteres
 *                            por segundo) simulada antes de enviar cada
 *                            respuesta, para hacer pruebas (ver panel admin)
 *   POST /pausa_entre_burbujas -> cambia los segundos de pausa entre una
 *                            burbuja y la siguiente cuando una respuesta se
 *                            manda fragmentada en varios mensajes, para
 *                            hacer pruebas (ver panel admin)
 *   POST /reiniciar_sesion -> borra la sesión de cifrado guardada con UN
 *                            número puntual (mitigación del bug de Baileys
 *                            que deja a algunos iPhone viendo "Esperando el
 *                            mensaje..." sin fin; no afecta a otros números
 *                            ni requiere re-escanear el QR)
 *
 * MODO PRUEBA (solo para pruebas temporales, ver WHATSAPP_VINCULACION.md):
 *   Normalmente, cualquier mensaje que "fromMe" (enviado por el propio número
 *   vinculado, incluido el chat "Tú" donde uno se escribe a sí mismo) se
 *   ignora, porque si no el bot terminaría respondiéndose en bucle a sus
 *   propios mensajes salientes hacia los ciudadanos.
 *
 *   Cuando el administrador activa el "modo prueba" desde el panel
 *   (vistas/whatsapp_admin.php -> botón "Prueba de Bot"), se hace UNA única
 *   excepción muy acotada: los mensajes que el propio número se envía a sí
 *   mismo (el chat "Tú", cuyo remoteJid coincide con el número vinculado)
 *   SÍ se reenvían a recibir_bot.php como si fueran de un ciudadano. Así el
 *   administrador puede probar todo el flujo de la IA desde su propio
 *   teléfono, sin necesitar un segundo número.
 *
 *   Esto NUNCA afecta a los demás chats: cualquier mensaje "fromMe" hacia
 *   otro número (o de otro número hacia el bot) sigue funcionando exactamente
 *   igual que antes, esté o no activado el modo prueba. Es un interruptor
 *   pensado para desactivarse apenas termine la prueba.
 *
 * Todos los endpoints (excepto /estado y /qr, de solo lectura) están
 * protegidos con el secreto compartido BRIDGE_SECRET (ver .env).
 *
 * ESTABILIDAD DEL PROCESAMIENTO DE MENSAJES:
 *   - Se deduplican mensajes por su id (msg.key.id): Baileys a veces entrega
 *     el mismo mensaje en más de un evento, y sin este control el bot
 *     respondía por error "Por favor enviá tu solicitud como texto..." de
 *     forma duplicada en cada conversación.
 *   - Solo se envía ese aviso cuando el mensaje es realmente de un tipo sin
 *     texto reconocido (audio, imagen, video, sticker, documento); cualquier
 *     otro caso sin texto se ignora en silencio.
 *   - Se procesan también mensajes de backlog ("append") recientes, para
 *     recuperar mensajes que hubieran llegado durante una breve
 *     desconexión/reconexión, sin reprocesar historial viejo.
 *   - El procesamiento de mensajes de un mismo número se serializa (una
 *     cola por número), para que dos eventos casi simultáneos no se pisen
 *     la fase de la conversación guardada en whatsapp/sesiones/.
 *   - En "modo prueba" (chat "Tú"), cada mensaje que EL BOT envía se marca
 *     también como "ya procesado" (ver enviarYRegistrar), porque en ese
 *     chat las respuestas del propio bot rebotan como si fueran mensajes
 *     nuevos del "ciudadano" (mismo remoteJid, fromMe=true). Sin esto, el
 *     bot terminaba respondiéndose a sí mismo en bucle infinito, repitiendo
 *     la misma pregunta (ej. de confirmación SI/NO) decenas de veces
 *     seguidas en el mismo instante.
 */

require('dotenv').config();
const fs = require('fs');
const path = require('path');
const express = require('express');
const axios = require('axios');
const qrcode = require('qrcode-terminal');
const QRCode = require('qrcode'); // genera el QR como imagen (PNG/DataURL)
const pino = require('pino');
const makeWASocket = require('@whiskeysockets/baileys').default;
const {
  useMultiFileAuthState,
  DisconnectReason,
  fetchLatestBaileysVersion,
  downloadMediaMessage,
  makeCacheableSignalKeyStore,
  Browsers,
} = require('@whiskeysockets/baileys');
const archivoChats = require('./archivo_chats'); // NUEVO: archivo permanente de chats
// === Sincronización PC1 <-> PC2 ===
const { registrarRutasSync, iniciarCicloSync, estaPausado } = require('./sync');

// ============================================================================
// SILENCIADOR DE ERRORES RUIDOSOS DE DESCIFRADO (Baileys / libsignal)
// ============================================================================
// Baileys tiene un bug conocido (ver github.com/WhiskeySockets/Baileys,
// issues 1744, 1701, 1767, 2115, 2169) por el cual, con algunos destinatarios
// (típicamente iPhone), la sesión de cifrado Signal queda corrupta y luego
// aparecen en consola mensajes como:
//
//   "Failed to decrypt message with any known session..."
//   "Session error:MessageCounterError: Key used already or never filled..."
//   "Session error:Error: Bad MAC Error: Bad MAC..."
//
// Son RUIDO INTERNO de Baileys/libsignal: el mensaje que no se pudo descifrar
// simplemente se ignora (el bot ya filtra los mensajes sin contenido con
// `if (!msg.message) continue;`). No requieren ninguna acción del
// administrador y no impiden que el bot siga funcionando con los demás
// mensajes.
//
// Para evitar que llenen la consola y confundan al operador, este bloque
// intercepta console.error y descarta ÚNICAMENTE los mensajes que coinciden
// con esos patrones conocidos; cualquier otro error sigue apareciendo
// normalmente. Si más adelante necesitás verlos para depurar, comentá el
// `return;` de abajo (o el bloque completo).
const _consoleErrorOriginal = console.error.bind(console);
const _RUIDO_BAILEYS = /(Failed to decrypt message with any known session|Bad MAC|MessageCounterError|Session error:|Closing (stale|open) session|No matching sessions|Decryption failed)/i;
console.error = function (...args) {
  try {
    const texto = args.map((a) => {
      if (a instanceof Error) return a.message + ' ' + a.stack;
      if (typeof a === 'object') { try { return JSON.stringify(a); } catch (_) { return String(a); } }
      return String(a);
    }).join(' ');
    if (_RUIDO_BAILEYS.test(texto)) return;
  } catch (_) {}
  _consoleErrorOriginal(...args);
};

const PORT = process.env.PORT || 3001;
const PHP_RECEIVE_URL = process.env.PHP_RECEIVE_URL;
const BRIDGE_SECRET = process.env.BRIDGE_SECRET || 'change-me';
const AUTH_DIR = path.join(__dirname, 'auth_info');
const MODO_PRUEBA_FILE = path.join(__dirname, 'modo_prueba.json');
// Carpeta donde se guardan las fotografías que los ciudadanos envían por
// WhatsApp, DENTRO de "sistema-web" (la carpeta que sirve el servidor PHP
// embebido, ver sistema-web/iniciar_sistema.*), para que el oficio pueda
// mostrarlas con un <img> normal en http://localhost:8080/whatsapp/fotos/...
const CARPETA_FOTOS_BASE = path.join(__dirname, '..', 'sistema-web', 'whatsapp', 'fotos');

// NUEVO: opciones del archivo de historial (ver whatsapp-bot/archivo_chats.js)
// HISTORIAL_COMPLETO=true        -> pide al teléfono el historial más largo posible (solo al re-vincular)
// HISTORIAL_DESCARGAR_FOTOS=true -> intenta descargar también las fotos del historial
const HISTORIAL_COMPLETO = process.env.HISTORIAL_COMPLETO === 'true';
const HISTORIAL_DESCARGAR_FOTOS = process.env.HISTORIAL_DESCARGAR_FOTOS === 'true';

// ---------- Horario de atención (hora Guatemala, UTC-6) ----------
// Fuera de este rango horario el bot NO responde al ciudadano. Sigue
// registrando la petición (ticket, fotos, oficio) pero evita contestar
// mensajes que probablemente ya fueron atendidos a mano por la encargada
// fuera de la jornada laboral, o que son backlog de cuando el bot estaba
// apagado (se evalúa con la hora en que el ciudadano ESCRIBIÓ el mensaje,
// no con la hora en que el bot lo procesa).
const HORA_APERTURA = parseInt(process.env.HORARIO_APERTURA || '8', 10);
const HORA_CIERRE   = parseInt(process.env.HORARIO_CIERRE   || '16', 10);

/**
 * true si el timestamp (ms) cae dentro del horario de atención, en hora de
 * Guatemala (UTC-6 todo el año, sin DST). Sábado y domingo quedan fuera.
 */
function estaEnHorarioAtencion(timestampMs) {
  const fechaLocal = new Date(timestampMs - 6 * 60 * 60 * 1000);
  const hora = fechaLocal.getUTCHours();
  const dia  = fechaLocal.getUTCDay(); // 0=domingo, 6=sábado
  if (dia === 0 || dia === 6) return false;
  return hora >= HORA_APERTURA && hora < HORA_CIERRE;
}
// Carpeta donde recibir_bot.php guarda el estado ("fase") de la
// conversación de cada número: whatsapp/sesiones/<numero>.json. Se usa
// para saber si un mensaje que llega es el que ABRE una conversación
// nueva (fase "inicio" o sesión inexistente) o si es la RESPUESTA a una
// pregunta puntual que el bot ya hizo (fase distinta de "inicio"), y así
// decidir cuánto esperar antes de procesarlo (ver debounceInicioMs
// y DEBOUNCE_DELAY_RESPUESTA_MS más abajo).
const CARPETA_SESIONES = path.join(__dirname, '..', 'sistema-web', 'whatsapp', 'sesiones');

// Momento en que este proceso de Node arrancó. Se usa para evitar que, en
// "modo prueba", los mensajes de backlog (type === 'append') que Baileys
// entrega al reconectar tras un reinicio se procesen de nuevo: el Map
// MENSAJES_PROCESADOS vive en memoria y queda vacío al reiniciar, así que
// sin este filtro el último mensaje del chat "Tú" se reprocesaba como si
// fuera nuevo del ciudadano cada vez que se abría el sistema (creando
// ticket y oficio duplicados). En chats reales de ciudadanos no hace
// falta: sus mensajes llegan con fromMe=false y las respuestas del bot
// hacia ellos se filtran por fromMe incondicionalmente.
const botStartedAt = Date.now();

// ---------- BUGFIX: "modo prueba" se olvidaba al reiniciar el bot ----------
// Antes, modoPruebaActivo era una variable en memoria que SIEMPRE arrancaba
// en false. Como el chat "Tú" solo se trata como un ciudadano cuando el
// modo prueba está encendido, cada vez que el proceso de Node se
// reiniciaba (ej. al actualizar este mismo archivo, o si el proceso se
// caía y volvía a levantar solo), el modo prueba quedaba apagado sin que
// nadie lo tocara desde el panel — y el bot se quedaba completamente mudo
// en el chat "Tú", SIN ningún mensaje de error, porque simplemente dejaba
// de tratar esos mensajes como entrantes. Para evitar esta confusión, el
// estado del modo prueba ahora se guarda en un archivo (modo_prueba.json)
// y se recupera al arrancar, así sobrevive a reinicios del proceso.
function cargarModoPruebaGuardado() {
  try {
    if (fs.existsSync(MODO_PRUEBA_FILE)) {
      const datos = JSON.parse(fs.readFileSync(MODO_PRUEBA_FILE, 'utf8'));
      return datos?.activo === true;
    }
  } catch (e) {
    console.error('No se pudo leer modo_prueba.json, se asume desactivado:', e.message);
  }
  return false;
}

function guardarModoPruebaEnDisco(valor) {
  try {
    fs.writeFileSync(MODO_PRUEBA_FILE, JSON.stringify({ activo: valor }));
  } catch (e) {
    console.error('No se pudo guardar modo_prueba.json:', e.message);
  }
}

// ---------------------------------------------------------------------------
// NUEVO: interruptor de "uso de emojis", para hacer pruebas de compatibilidad
// (hay teléfonos/clientes de WhatsApp donde los emojis se ven mal o rompen
// el formato). Se guarda en disco igual que el modo prueba, para que
// sobreviva a reinicios del bot, y por defecto queda ACTIVADO (así el
// comportamiento no cambia para nadie que no lo toque desde el panel).
// ---------------------------------------------------------------------------
const EMOJIS_FILE = path.join(__dirname, 'emojis_config.json');

function cargarEmojisGuardado() {
  try {
    if (fs.existsSync(EMOJIS_FILE)) {
      const datos = JSON.parse(fs.readFileSync(EMOJIS_FILE, 'utf8'));
      return datos?.activo !== false; // cualquier cosa que no sea "false" explícito -> activado
    }
  } catch (e) {
    console.error('No se pudo leer emojis_config.json, se asume activado:', e.message);
  }
  return true;
}

function guardarEmojisEnDisco(valor) {
  try {
    fs.writeFileSync(EMOJIS_FILE, JSON.stringify({ activo: valor }));
  } catch (e) {
    console.error('No se pudo guardar emojis_config.json:', e.message);
  }
}

// ---------------------------------------------------------------------------
// NUEVO: números excluidos del bot (contactos internos de la municipalidad,
// ej. concejales, personal administrativo). El bot NUNCA debe responderles
// automáticamente ni tratarlos como una petición ciudadana: se ignoran por
// completo apenas llega el mensaje (ver "esNumeroExcluido" y su uso en
// 'messages.upsert' más abajo, justo después de resolver "numero"). Se
// guardan en disco (igual que modo_prueba.json / emojis_config.json) para
// que la lista sobreviva a reinicios del bot, y se administran desde el
// panel (vistas/whatsapp_admin.php) vía los endpoints
// POST /numeros_excluidos/agregar y POST /numeros_excluidos/quitar.
// ---------------------------------------------------------------------------
const NUMEROS_EXCLUIDOS_FILE = path.join(__dirname, 'numeros_excluidos.json');

function cargarNumerosExcluidosGuardados() {
  try {
    if (fs.existsSync(NUMEROS_EXCLUIDOS_FILE)) {
      const datos = JSON.parse(fs.readFileSync(NUMEROS_EXCLUIDOS_FILE, 'utf8'));
      if (Array.isArray(datos?.numeros)) {
        return datos.numeros.map((n) => String(n).replace(/\D/g, '')).filter(Boolean);
      }
    }
  } catch (e) {
    console.error('No se pudo leer numeros_excluidos.json, se asume lista vacía:', e.message);
  }
  return [];
}

function guardarNumerosExcluidosEnDisco(set) {
  try {
    fs.writeFileSync(NUMEROS_EXCLUIDOS_FILE, JSON.stringify({ numeros: Array.from(set) }));
  } catch (e) {
    console.error('No se pudo guardar numeros_excluidos.json:', e.message);
  }
}

// ---------------------------------------------------------------------------
// NUEVO: mensajes de texto fijos que el bot usa fuera de recibir_bot.php
// (ej. cuando llega un audio/sticker/documento sin texto), editables desde
// el panel de administración ("Inteligencia Artificial" > "Mensajes del
// Bot"). Se leen del disco en cada uso (no se cachean en memoria) para que
// una edición desde el panel se refleje de inmediato, sin reiniciar el bot.
// Si el archivo no existe o falta la clave, se usa el texto por defecto de
// siempre (mismo comportamiento que antes de que existiera este archivo).
// ---------------------------------------------------------------------------
const MENSAJES_BOT_FILE = path.join(__dirname, '..', 'sistema-web', 'config', 'mensajes_bot.json');

function obtenerMensajeBot(clave, porDefecto) {
  try {
    if (fs.existsSync(MENSAJES_BOT_FILE)) {
      const datos = JSON.parse(fs.readFileSync(MENSAJES_BOT_FILE, 'utf8'));
      if (typeof datos?.[clave] === 'string' && datos[clave].trim() !== '') {
        return datos[clave];
      }
    }
  } catch (e) {
    console.error('No se pudo leer mensajes_bot.json, se usa el mensaje por defecto:', e.message);
  }
  return porDefecto;
}

let sock; // instancia activa del socket de WhatsApp
let isConnected = false; // true cuando WhatsApp confirma la conexión ('open')
let isStarting = false; // evita arranques duplicados en paralelo
let numeroVinculado = null; // número de teléfono (con el que quedó vinculado el bot)
// ---------- BUGFIX: el chat "Tú" dejó de usar el número de teléfono ----------
// WhatsApp introdujo un identificador nuevo llamado "LID" (linked ID) y, en
// cuentas donde está activo, el chat "Tú" (uno mismo) llega con un remoteJid
// del tipo "<lid>@lid" en vez de "<numero>@s.whatsapp.net". Como antes solo
// comparábamos contra el número de teléfono, esChatPropio nunca daba
// verdadero para ese chat y el mensaje se descartaba en silencio (sin
// ningún error visible), aunque el "modo prueba" estuviera activado. Baileys
// expone este identificador en sock.user.lid (también visible en
// auth_info/creds.json bajo "me.lid"), así que lo guardamos igual que el
// número de teléfono para poder reconocer el chat propio por cualquiera de
// los dos identificadores.
let lidVinculado = null; // identificador LID (con el que WhatsApp también identifica el chat propio)

// ---------- BUGFIX: LID propio desconocido tras vincular/re-vincular ----------
// lidVinculado se leía UNA sola vez al abrir la conexión. Justo después de
// escanear un QR nuevo (o tras el "515 restartRequired"), Baileys todavía no
// tiene me.lid en ese momento: llega un instante después vía 'creds.update'.
// Resultado: lidVinculado quedaba en null para toda la sesión y los mensajes
// del chat "Tú" que llegan por "<lid>@lid" se descartaban en silencio.
// Estas funciones consultan el LID propio en el momento en que se necesita.
function normalizarLid(valor) {
  return valor ? String(valor).split(':')[0].split('@')[0] : null;
}
function obtenerLidPropio() {
  return (
    lidVinculado ||
    normalizarLid(sock?.user?.lid) ||
    normalizarLid(sock?.authState?.creds?.me?.lid) ||
    null
  );
}
let lastQR = null; // último texto crudo del QR recibido de Baileys
let lastQRDataUrl = null; // el mismo QR, ya convertido a imagen PNG (data URL)
let lastQRAt = null; // timestamp de cuándo se generó el último QR
let ultimoError = null; // último motivo de desconexión, para mostrar en el panel
let modoPruebaActivo = cargarModoPruebaGuardado(); // ver "MODO PRUEBA" en el comentario de arriba
let emojisActivos = cargarEmojisGuardado(); // ver "uso de emojis" en el comentario de arriba
const NUMEROS_EXCLUIDOS = new Set(cargarNumerosExcluidosGuardados()); // números que el bot nunca debe atender (ver comentario de arriba)

console.log(
  modoPruebaActivo
    ? 'Modo prueba recuperado como ACTIVADO (quedó encendido de una sesión anterior).'
    : 'Modo prueba: desactivado.'
);
console.log(`Uso de emojis: ${emojisActivos ? 'activado' : 'desactivado'}.`);

// ---------- Deduplicación y orden de procesamiento de mensajes entrantes ----------
// Baileys en ocasiones entrega el MISMO mensaje en más de un evento
// 'messages.upsert' (por ejemplo, un primer evento mientras todavía está
// terminando de sincronizar/desencriptar el contenido, y luego el evento
// "completo" con el texto real). Si no filtramos esto, el primer evento
// parece "sin texto" y el bot respondía por error "Por favor enviá tu
// solicitud como mensaje de texto...", generando ese aviso duplicado e
// innecesario en cada mensaje que se ve en las conversaciones reales.
const MENSAJES_PROCESADOS = new Map(); // msg.key.id -> timestamp en que se procesó
const VENTANA_DEDUP_MS = 2 * 60 * 1000; // recordamos ids de los últimos 2 minutos

// ---------- FIX: "Esperando mensaje. Esto puede tomar tiempo." en iPhone ----------
// Cuando el cliente de WhatsApp que recibe el mensaje (muy notoriamente en
// iOS) no logra descifrarlo a la primera, le pide a Baileys que se lo
// reenvíe ("retry receipt"). Baileys necesita el CONTENIDO ORIGINAL de ese
// mensaje para poder reenviarlo, y lo pide a través de la función
// `getMessage` que se le pasa a makeWASocket(). Como antes no se
// implementaba esa función (ni se guardaba el contenido de los mensajes
// enviados en ningún lado), Baileys no tenía nada que reenviar y el
// ciudadano se quedaba viendo indefinidamente ese aviso de WhatsApp en vez
// de la respuesta del bot.
//
// Se guarda acá el contenido de cada mensaje que el bot envía (ver
// enviarYRegistrar), indexado por el id que le asigna WhatsApp, para poder
// devolvérselo a Baileys si llega a pedirlo.
const CONTENIDO_ENVIADOS = new Map(); // msg.key.id -> { content, ts }
const VENTANA_RETRY_MS = 10 * 60 * 1000; // se guarda el contenido de los últimos 10 minutos

function limpiarContenidoEnviadosViejos() {
  const ahora = Date.now();
  for (const [id, { ts }] of CONTENIDO_ENVIADOS) {
    if (ahora - ts > VENTANA_RETRY_MS) CONTENIDO_ENVIADOS.delete(id);
  }
}

// ---------- AUTO-RECUPERACIÓN: el propio ciudadano avisa que el mensaje no le llega ----------
// Ver COMPATIBILIDAD_DEL_SISTEMA_Baileys.txt y el bloque grande
// "FIX puntual: sesión de cifrado 'trabada' con un número (iPhone)" más
// abajo (borrarSesionSignalDeNumero). Hasta ahora, cuando a un ciudadano
// (típicamente con iPhone) se le quedaba un mensaje pegado en "Esperando el
// mensaje. Esto puede tomar tiempo.", el administrador tenía que enterarse
// (por ejemplo por captura de pantalla) y reiniciar la sesión a mano desde
// el panel (vistas/whatsapp_admin.php).
//
// Ahora, si el propio ciudadano escribe algo como "no puedo leer el
// mensaje", "no me llega el mensaje" o "sigo esperando el mensaje", el bot
// lo detecta solo y se autocorrige sin intervención del administrador:
//   1) Reinicia la sesión de cifrado guardada con ESE número puntual
//      (idéntico a lo que hace el botón manual del panel).
//   2) Le reenvía el último mensaje que le había mandado (el que
//      probablemente se quedó atascado del lado del ciudadano).
//   3) NO reenvía este aviso a la IA/PHP como si fuera una petición nueva
//      del ciudadano (no lo es), así que la conversación sigue exactamente
//      en la misma fase en la que estaba: en cuanto el ciudadano responda
//      de verdad, el sistema sigue con lo que le estaba pidiendo o
//      contestando, como si el corte nunca hubiera pasado.
//
// Para guardar "el último mensaje enviado" por número, ver
// ULTIMO_ENVIADO_POR_NUMERO más abajo (se actualiza en enviarYRegistrar).
const ULTIMO_ENVIADO_POR_NUMERO = new Map(); // numero -> { texto, ts }
const VENTANA_ULTIMO_ENVIADO_MS = 30 * 60 * 1000; // se recuerda el último mensaje de los últimos 30 minutos

function limpiarUltimoEnviadoViejos() {
  const ahora = Date.now();
  for (const [numero, { ts }] of ULTIMO_ENVIADO_POR_NUMERO) {
    if (ahora - ts > VENTANA_ULTIMO_ENVIADO_MS) ULTIMO_ENVIADO_POR_NUMERO.delete(numero);
  }
}

// ---------- BUGFIX: LID inestable en mensajes de ciudadanos reales ----------
// Cuando un mensaje llega con remoteJid terminado en "@lid" (en vez del
// número de teléfono), WhatsApp normalmente también manda el número real
// en msg.key.senderPn, y con eso se resuelve el número real (ver más abajo,
// bloque "BUGFIX: mensajes de ciudadanos reales llegando con LID"). El
// problema es que ALGUNOS mensajes del mismo ciudadano llegan SIN
// senderPn (se ha visto sobre todo con iPhone) — y sin este campo no hay
// forma de saber a qué número de teléfono corresponde ese LID en ese
// mensaje puntual. Si en ese caso se usara el LID tal cual como "numero",
// cada mensaje podría terminar guardándose bajo una clave distinta en
// whatsapp/sesiones/, y para el sistema cada uno parecería una
// conversación nueva: el ciudadano vuelve a "inicio" sin haberlo pedido,
// tiene que repetir su nombre, el DPI, etc. — exactamente el síntoma de
// "el bot se olvida a mitad de la conversación" que se reportó.
//
// La solución: cachear la relación LID -> número real la primera vez que
// un mensaje trae ambos datos juntos, y usar esa caché como respaldo
// cuando un mensaje posterior con el mismo LID no trae senderPn.
const LID_A_NUMERO_REAL = new Map(); // lid -> { numero, ts }
const VENTANA_LID_A_NUMERO_REAL_MS = 24 * 60 * 60 * 1000; // se recuerda hasta 24h

function limpiarCacheLidViejos() {
  const ahora = Date.now();
  for (const [lid, { ts }] of LID_A_NUMERO_REAL) {
    if (ahora - ts > VENTANA_LID_A_NUMERO_REAL_MS) LID_A_NUMERO_REAL.delete(lid);
  }
}

// ---------- Reinicio preventivo de sesión de cifrado para TODOS los números ----------
// A partir de esta versión, el bot reinicia la sesión de cifrado (Signal) del
// destinatario ANTES de mandarle CADA mensaje automático, sin importar qué
// número sea. Esto es lo que hace falta para que ciudadanos con iPhone (que
// son los que típicamente tenían la sesión "trabada" y veían "Esperando el
// mensaje. Esto puede tomar tiempo." sin recibir nada) reciban SIEMPRE los
// mensajes del bot, sin que nadie tenga que avisar "no me llegó el mensaje"
// ni que un administrador marque el número a mano.
//
// No hay lista de "problemáticos" ni nada que mantener: se aplica a todos
// los números por igual. El costo es que WhatsApp puede mostrar a veces el
// aviso nativo "Reiniciamos la conexión de este chat." del lado del
// ciudadano, pero eso es aceptable a cambio de que los mensajes lleguen
// siempre.
function reiniciarSesionPreventiva(numero) {
  const numeroLimpio = String(numero || '').replace(/\D/g, '');
  if (!numeroLimpio) return;

  borrarSesionSignalDeNumero(numeroLimpio);
  console.log(`[Auto-recuperación] Reinicio preventivo de sesión para ${numeroLimpio} antes de este mensaje.`);
}

// Números que el bot NUNCA debe responder automáticamente (ver
// NUMEROS_EXCLUIDOS y su comentario grande más arriba). Se usa apenas se
// resuelve el número real del remitente, antes de cualquier otro
// procesamiento (auto-recuperación, reenvío a PHP, etc.).
function esNumeroExcluido(numero) {
  const numeroLimpio = String(numero || '').replace(/\D/g, '');
  return !!numeroLimpio && NUMEROS_EXCLUIDOS.has(numeroLimpio);
}

// Frases (en español coloquial guatemalteco) con las que un ciudadano suele
// avisar que el mensaje del bot no le llega o no lo puede leer. Se exige
// que la palabra "mensaje"/"mensajes" esté presente para no confundir esto
// con quejas municipales reales que también usan negaciones parecidas (ej.
// "no llega el agua", "no llega el drenaje"), que no tienen nada que ver
// con este problema técnico y deben seguir su flujo normal hacia la IA.
const PATRONES_QUEJA_MENSAJE_ATASCADO = [
  /no\s+(me\s+)?(llega|llego|llegan|llegaron)/,
  /no\s+puedo\s+(leer|ver|abrir)/,
  /no\s+(lo\s+)?(veo|recibo|recibi)/,
  /no\s+(me\s+)?(carga|abre|aparece|sale)\b/,
  /esperando\s+el?\s*mensaje/,
  /se\s+qued(o|ó)\s+(pegad|trab)/,
  /no\s+(me\s+)?contesta/,
  /sigo\s+esperando/,
];

function esQuejaMensajeAtascado(texto) {
  if (typeof texto !== 'string' || !texto.trim()) return false;
  const t = texto
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, ''); // quita tildes para que "llegó"/"llego" den igual
  if (!/\bmensajes?\b/.test(t)) return false;
  return PATRONES_QUEJA_MENSAJE_ATASCADO.some((re) => re.test(t));
}

// Tras una reconexión (por ejemplo, si el WhatsApp se desvincula/relincula
// mientras el ciudadano está en medio de una conversación), Baileys puede
// entregar como backlog ("append") los mensajes que llegaron mientras el
// bot estaba desconectado. Los procesamos igual que uno nuevo SOLO si son
// recientes, para no reprocesar por error historial viejo de conversaciones.
const VENTANA_RECUPERACION_BACKLOG_MS = 3 * 60 * 1000;

function limpiarDedupViejos() {
  const ahora = Date.now();
  for (const [id, ts] of MENSAJES_PROCESADOS) {
    if (ahora - ts > VENTANA_DEDUP_MS) MENSAJES_PROCESADOS.delete(id);
  }
}

// ---------- BUGFIX: bucle infinito en el chat "Tú" (modo prueba) ----------
// En el chat "Tú" (uno mismo como remitente y destinatario), WhatsApp/Baileys
// NO distingue entre "el usuario escribió esto" y "el bot lo envió": ambos
// llegan como fromMe=true con el mismo remoteJid. Como en "modo prueba"
// tratamos los mensajes fromMe del chat propio como si fueran de un
// ciudadano, si el bot responde con sock.sendMessage(), esa MISMA respuesta
// vuelve a llegar como un nuevo evento 'messages.upsert' y el bot la vuelve
// a procesar como si fuera un mensaje nuevo del "ciudadano" -> vuelve a
// responder -> dispara otro evento -> bucle infinito. Esto es lo que
// causaba decenas de repeticiones idénticas del mismo mensaje en el mismo
// segundo (ej. "Por favor respondé SI para confirmar o NO para cancelar.").
//
// Fix: en cuanto el bot envía un mensaje, se registra el id de ESE mensaje
// enviado en el mismo mapa de deduplicación (MENSAJES_PROCESADOS). Así,
// cuando ese mensaje "rebota" como evento entrante (fromMe=true, mismo
// chat), el filtro de deduplicación que ya existía lo descarta de
// inmediato, porque su id ya figura como "ya procesado".
//
// Además: antes de enviar, se marca el chat como "escribiendo..." y se
// espera un instante breve (en vez de contestar de forma instantánea), y
// luego de enviar se guarda el contenido del mensaje en CONTENIDO_ENVIADOS
// para poder resolver los "retry receipt" que pida WhatsApp (ver
// getMessage en iniciarWhatsApp) — juntas, estas dos cosas son las que
// evitan que el mensaje se quede pegado como "Esperando mensaje. Esto
// puede tomar tiempo." del lado del ciudadano, sobre todo en iPhone.
// ---------- Duración del "escribiendo…" a ritmo humano (~5 caract./seg) ----
// Antes se usaba una demora fija (700-1300ms) sin relación con el largo del
// mensaje. Ahora la duración del indicador "escribiendo…" se calcula según
// la cantidad real de caracteres del texto a enviar, a un ritmo de ~5
// caracteres por segundo (200ms por caracter), que es aproximadamente lo
// que tarda una persona en tipear. Esto hace que mensajes cortos se envíen
// casi al toque y mensajes largos "tarden" lo que tardaría alguien
// escribiéndolos, lo cual ayuda a que WhatsApp/iPhone tengan tiempo de
// sincronizar el cifrado antes de que llegue el mensaje (ver comentario
// grande más arriba sobre el bug de "Esperando mensaje...").
//
// IMPORTANTE: antes había un tope (DEMORA_MAXIMA_MS) de solo 8 segundos, que
// cortaba la espera de los mensajes largos (ej. la confirmación de datos de
// una petición, que suele rondar los 400-500 caracteres) mucho antes de que
// terminara de "tipearse" a este ritmo. Ahora el tope se sube a 120
// segundos, que alcanza para mensajes de hasta ~600 caracteres sin
// cortarse (cubre con margen la confirmación de datos completa, de unos
// 426 caracteres); solo actúa como resguardo ante un mensaje verdaderamente
// extremo, para no dejar al ciudadano esperando varios minutos.
//
// Ejemplo (mensaje de confirmación de 426 caracteres):
//   426 ÷ 5 = 85.2 segundos de demora antes de enviarse.
//
// Nota técnica importante: WhatsApp (y Baileys) no ofrecen ninguna forma de
// "ir tipeando" letra por letra dentro del chat del destinatario -- solo
// existe el mensaje completo ya armado y el indicador de presencia
// "composing"/"paused". Por eso lo que se simula acá es la DURACIÓN del
// indicador de "escribiendo…", no el tipeo caracter por caracter en sí.
// ---------------------------------------------------------------------------
// NUEVO: velocidad de escritura configurable desde el panel admin
// (vistas/whatsapp_admin.php), para poder hacer pruebas variando qué tan
// rápido "escribe" el bot antes de enviar cada respuesta. Antes
// RITMO_CARACTERES_POR_SEGUNDO era una constante fija (5); ahora es un valor
// que se guarda en disco (igual que modo_prueba.json / emojis_config.json)
// para que sobreviva a reinicios del bot, y se puede cambiar en caliente vía
// POST /velocidad_escritura sin tener que tocar el código.
const RITMO_CARACTERES_POR_SEGUNDO_DEFECTO = 5;
const VELOCIDAD_ESCRITURA_MINIMA = 0.5;  // caracteres por segundo (muy lento, para pruebas)
const VELOCIDAD_ESCRITURA_MAXIMA = 1000; // caracteres por segundo (prácticamente instantáneo)
const VELOCIDAD_ESCRITURA_FILE = path.join(__dirname, 'velocidad_escritura.json');
const DEMORA_MINIMA_MS = 600;    // para que nunca se sienta instantáneo/robótico
const DEMORA_MAXIMA_MS = 120000; // tope de resguardo (~600 caracteres) para no hacer esperar de más en mensajes extremadamente largos

function cargarVelocidadEscrituraGuardada() {
  try {
    if (fs.existsSync(VELOCIDAD_ESCRITURA_FILE)) {
      const datos = JSON.parse(fs.readFileSync(VELOCIDAD_ESCRITURA_FILE, 'utf8'));
      const valor = Number(datos?.caracteresPorSegundo);
      if (Number.isFinite(valor) && valor >= VELOCIDAD_ESCRITURA_MINIMA && valor <= VELOCIDAD_ESCRITURA_MAXIMA) {
        return valor;
      }
    }
  } catch (e) {
    console.error('No se pudo leer velocidad_escritura.json, se asume el valor por defecto:', e.message);
  }
  return RITMO_CARACTERES_POR_SEGUNDO_DEFECTO;
}

function guardarVelocidadEscrituraEnDisco(valor) {
  try {
    fs.writeFileSync(VELOCIDAD_ESCRITURA_FILE, JSON.stringify({ caracteresPorSegundo: valor }));
  } catch (e) {
    console.error('No se pudo guardar velocidad_escritura.json:', e.message);
  }
}

// Caracteres por segundo actuales (mutable: se actualiza desde el panel admin).
let ritmoCaracteresPorSegundo = cargarVelocidadEscrituraGuardada();
console.log(`Velocidad de escritura: ${ritmoCaracteresPorSegundo} caracteres/segundo.`);

function contarCaracteresVisibles(texto) {
  if (!texto) return 0;
  // Array.from separa correctamente los emojis (y otros caracteres fuera del
  // plano básico) en una sola "unidad", a diferencia de String.length, que
  // los cuenta como 2 y daría una demora incorrecta (el doble de larga).
  return Array.from(String(texto)).length;
}

function calcularDemoraDeTipeo(contenido) {
  const texto = contenido && typeof contenido.text === 'string' ? contenido.text : '';
  const cantidadCaracteres = contarCaracteresVisibles(texto);
  const msBase = (cantidadCaracteres / ritmoCaracteresPorSegundo) * 1000;
  // Un poco de variación aleatoria (±15%) para que no sea siempre el mismo
  // tiempo exacto para mensajes del mismo largo (más natural).
  const variacion = 1 + (Math.random() * 0.3 - 0.15);
  const msConVariacion = msBase * variacion;
  return Math.min(DEMORA_MAXIMA_MS, Math.max(DEMORA_MINIMA_MS, Math.round(msConVariacion)));
}

// ---------------------------------------------------------------------------
// FIX: los mensajes seguían llegando con NEGRITA (asteriscos "*texto*") al
// ciudadano a pesar de que el municipio pidió que ninguna respuesta llevara
// negrita (ej. "*Alumbrado Público*", "*Solicitud registrada*").
//
// Causa: esta limpieza ("quitarNegritaWhatsApp") YA estaba escrita, pero
// solo existía en una copia vieja de este mismo archivo dentro de
// "sistema-web/IA 2/index.js" (que no es la que realmente se ejecuta como
// bot). El bot real que de verdad envía los mensajes por WhatsApp es ESTE
// archivo (whatsapp-bot/index.js), y aquí nunca se había aplicado — por eso
// todo el texto con "*así*" que arma recibir_bot.php (categoría, "SI"/"NO",
// nombre del expediente, etc.) salía tal cual, en negrita, hacia WhatsApp.
//
// La solución se aplica en un único punto (enviarYRegistrar, por el que
// pasan TODOS los mensajes salientes: tanto los que responde el bot solo
// como los que manda vía POST /enviar) para no depender de que cada script
// de PHP se acuerde de no usar asteriscos.
// ---------------------------------------------------------------------------
function quitarNegritaWhatsApp(texto) {
  if (typeof texto !== 'string' || texto.indexOf('*') === -1) return texto;
  // "*algo*" -> "algo". Evita cruzar saltos de línea (una "negrita" nunca
  // debería abarcar párrafos completos) y exige al menos un carácter entre
  // los asteriscos, para no tocar un "*" suelto usado como viñeta de lista.
  return texto.replace(/\*([^*\n]+)\*/g, '$1');
}

// ---------------------------------------------------------------------------
// NUEVO: quitar emojis de los mensajes salientes cuando el interruptor de
// "uso de emojis" del panel admin está en OFF (ver POST /emojis más abajo).
// Pensado para pruebas de compatibilidad en teléfonos/clientes de WhatsApp
// donde los emojis no se ven bien o rompen el formato del mensaje.
// ---------------------------------------------------------------------------
// Cubre emojis "modernos" (\p{Extended_Pictographic}, que incluye la
// inmensa mayoría: 👋📋📍📱✅❌🙏 etc.), variation selectors y el
// modificador de "zero width joiner" que se usa para pegar varios emojis en
// uno (ej. familias, banderas compuestas), además de símbolos sueltos como
// ✅❤️➡️ que a veces quedan fuera de Extended_Pictographic según la versión
// de Node/ICU.
const REGEX_EMOJIS = /[\p{Extended_Pictographic}\u{FE0F}\u{200D}\u{2600}-\u{27BF}]/gu;

function quitarEmojis(texto) {
  if (typeof texto !== 'string' || texto === '') return texto;
  return texto
    .replace(REGEX_EMOJIS, '')
    // Los emojis casi siempre llevan un espacio pegado (ej. "Hola 👋 ¿en qué
    // te ayudo?"); al quitarlos solos quedan dobles espacios o espacios
    // sueltos antes de un salto de línea. Esto deja el texto prolijo en vez
    // de con huecos raros.
    .replace(/[ \t]{2,}/g, ' ')
    .replace(/[ \t]+\n/g, '\n')
    .replace(/^[ \t]+/gm, '')
    .trim();
}

function limpiarContenidoDeSalida(contenido) {
  if (!contenido || typeof contenido !== 'object') return contenido;
  let limpio = { ...contenido };
  if (typeof limpio.text === 'string') limpio.text = quitarNegritaWhatsApp(limpio.text);
  if (typeof limpio.caption === 'string') limpio.caption = quitarNegritaWhatsApp(limpio.caption);
  if (!emojisActivos) {
    if (typeof limpio.text === 'string') limpio.text = quitarEmojis(limpio.text);
    if (typeof limpio.caption === 'string') limpio.caption = quitarEmojis(limpio.caption);
  }
  return limpio;
}

async function enviarYRegistrar(jid, contenido, numeroDestino = null) {
  // ---------- Reinicio preventivo de sesión (para TODOS los números) ----------
  // Ver el bloque grande junto a reiniciarSesionPreventiva más arriba. Esto
  // se hace acá, en el único punto por el que pasan TODOS los mensajes
  // salientes del bot (tanto los de la conversación normal — incluidos los
  // que arma enviarFragmentado, burbuja por burbuja — como los de
  // POST /enviar), para que el reinicio preventivo realmente cubra cada
  // mensaje automático que se manda, sin depender de que cada punto del
  // código se acuerde de pedirlo por su cuenta.
  //
  // El número contra el que se reinicia la sesión es "numeroDestino" si se
  // lo pasan explícitamente y, si no, el que se puede sacar del jid (mismo
  // criterio que ULTIMO_ENVIADO_POR_NUMERO más abajo, para que ambas cosas
  // coincidan siempre con el mismo número).
  const numeroParaPreventivo = numeroDestino || String(jid).split('@')[0];
  reiniciarSesionPreventiva(numeroParaPreventivo);

  contenido = limpiarContenidoDeSalida(contenido);
  try {
    await sock.presenceSubscribe(jid);
    await sock.sendPresenceUpdate('composing', jid);
  } catch (e) {
    // La presencia es solo un gesto "humano" antes de responder; si falla
    // (por ejemplo, el chat todavía no está sincronizado del todo), no debe
    // impedir que el mensaje se envíe igual.
  }

  const demoraMs = calcularDemoraDeTipeo(contenido);
  await new Promise((resolve) => setTimeout(resolve, demoraMs));

  const enviado = await sock.sendMessage(jid, contenido);

  try {
    await sock.sendPresenceUpdate('paused', jid);
  } catch (e) {
    // idem: no crítico.
  }

  const idEnviado = enviado?.key?.id;
  if (idEnviado) {
    MENSAJES_PROCESADOS.set(idEnviado, Date.now());
    limpiarDedupViejos();

    CONTENIDO_ENVIADOS.set(idEnviado, { content: contenido, ts: Date.now() });
    limpiarContenidoEnviadosViejos();
  }

  // Recordamos el último texto enviado a este número (ver AUTO-RECUPERACIÓN
  // más arriba), para poder reenviarlo solo si el ciudadano avisa que se le
  // quedó atascado del lado suyo.
  //
  // OJO: la clave con la que se guarda es "numeroDestino" si se lo pasan
  // explícitamente, y SOLO si no, se deriva del jid. Esto importa sobre
  // todo en "modo prueba" (chat "Tú"), donde a veces WhatsApp entrega y
  // hay que responder por un identificador LID (ej. "12345@lid") en vez
  // del número de teléfono real: si acá derivábamos la clave del jid,
  // quedaba guardada bajo el LID, pero el resto del sistema (sesiones,
  // auto-recuperación) siempre busca por el número de teléfono real, así
  // que nunca la encontraba y el reenvío del último mensaje fallaba en
  // silencio, cayendo siempre al mensaje genérico de "contame de nuevo".
  const textoParaGuardar = typeof contenido?.text === 'string' ? contenido.text.trim() : '';
  if (textoParaGuardar) {
    const clave = numeroDestino || String(jid).split('@')[0];
    if (clave) {
      ULTIMO_ENVIADO_POR_NUMERO.set(clave, { texto: contenido.text, ts: Date.now() });
      limpiarUltimoEnviadoViejos();
    }
  }

  return enviado;
}

// ---------------------------------------------------------------------------
// Fragmentación de respuestas en varias burbujas consecutivas
// ---------------------------------------------------------------------------
// El PHP (sistema-web/whatsapp/recibir_bot.php) arma varias de sus
// respuestas (clasificación de la petición, resumen de confirmación,
// confirmación final del expediente, etc.) como VARIAS partes, unidas con
// un caracter separador especial (SEPARADOR_FRAGMENTO = "\x01", ver
// SEP_FRAGMENTO en ese mismo archivo PHP). La idea es que, en vez de mandar
// todo eso pegado en un solo mensaje larguísimo, cada parte se envíe como
// su propia burbuja de WhatsApp, con su propia simulación de
// "escribiendo…" (a 5 caracteres por segundo, ver calcularDemoraDeTipeo) y
// una pausa de transición de ~1 segundo entre una burbuja y la siguiente,
// para que iOS tenga tiempo de renderizar cada una sin saltos de pantalla
// ni reacomodos raros del scroll.
//
// Si el texto NO contiene el separador (ej. mensajes cortos de una sola
// idea, como "Gracias, {nombre}." usado solo), se manda tal cual como un
// solo mensaje, sin ningún cambio de comportamiento.
//
// Ejemplo real (confirmación final de una petición de agua, expediente
// SPJ-AGU-2026-3172):
//   Burbuja 1: "✅ Solicitud registrada"
//   [pausa ~1s]
//   Burbuja 2: "📋 Expediente: SPJ-AGU-2026-3172"
//   [pausa ~1s]
//   Burbuja 3: "📂 Categoría: Agua y Saneamiento"
//   [pausa ~1s]
//   Burbuja 4: "Guardá tu número de expediente para dar seguimiento."
//   [pausa ~1s]
//   Burbuja 5: "¡Gracias por tu reporte, Roger Eddy Luis Oxlaj Estrada!"
const SEPARADOR_FRAGMENTO = '\u0001'; // debe ser IDÉNTICO a SEP_FRAGMENTO en recibir_bot.php

// ---------------------------------------------------------------------------
// NUEVO: pausa entre burbujas configurable desde el panel admin
// (vistas/whatsapp_admin.php), en la misma tarjeta que la velocidad de
// escritura. Antes esto era un rango fijo (900-1100ms, es decir ~1s ±10%)
// escrito en el código; ahora es un valor en SEGUNDOS que se guarda en
// disco (igual que velocidad_escritura.json) para que sobreviva a
// reinicios del bot, y se puede cambiar en caliente vía
// POST /pausa_entre_burbujas sin tener que tocar el código. Se conserva la
// misma variación aleatoria de ±10% que había antes, ahora calculada sobre
// el valor configurado en vez de sobre el fijo de 1 segundo.
const PAUSA_ENTRE_FRAGMENTOS_SEGUNDOS_DEFECTO = 1; // segundos (equivale al rango 900-1100ms de antes)
const PAUSA_ENTRE_FRAGMENTOS_MINIMA = 0.2;  // segundos (pausa casi imperceptible, para pruebas)
const PAUSA_ENTRE_FRAGMENTOS_MAXIMA = 10;   // segundos (pausa bien marcada entre burbujas)
const PAUSA_ENTRE_FRAGMENTOS_FILE = path.join(__dirname, 'pausa_entre_burbujas.json');

function cargarPausaEntreFragmentosGuardada() {
  try {
    if (fs.existsSync(PAUSA_ENTRE_FRAGMENTOS_FILE)) {
      const datos = JSON.parse(fs.readFileSync(PAUSA_ENTRE_FRAGMENTOS_FILE, 'utf8'));
      const valor = Number(datos?.segundos);
      if (Number.isFinite(valor) && valor >= PAUSA_ENTRE_FRAGMENTOS_MINIMA && valor <= PAUSA_ENTRE_FRAGMENTOS_MAXIMA) {
        return valor;
      }
    }
  } catch (e) {
    console.error('No se pudo leer pausa_entre_burbujas.json, se asume el valor por defecto:', e.message);
  }
  return PAUSA_ENTRE_FRAGMENTOS_SEGUNDOS_DEFECTO;
}

function guardarPausaEntreFragmentosEnDisco(valor) {
  try {
    fs.writeFileSync(PAUSA_ENTRE_FRAGMENTOS_FILE, JSON.stringify({ segundos: valor }));
  } catch (e) {
    console.error('No se pudo guardar pausa_entre_burbujas.json:', e.message);
  }
}

// Segundos de pausa entre burbujas actuales (mutable: se actualiza desde el panel admin).
let pausaEntreFragmentosSegundos = cargarPausaEntreFragmentosGuardada();
console.log(`Pausa entre burbujas: ${pausaEntreFragmentosSegundos} segundos.`);

// ---------------------------------------------------------------------------
// NUEVO: Espera configurable al recibir el PRIMER mensaje de una conversación
// nueva (debounce de la fase 'inicio'). El resto de fases sigue siendo fijo
// en DEBOUNCE_DELAY_RESPUESTA_MS (1.5s), porque ahí el ciudadano está
// respondiendo una pregunta puntual y no tiene sentido esperar.
// ---------------------------------------------------------------------------
const DEBOUNCE_INICIO_FILE = path.join(__dirname, 'debounce_inicio.json');
const DEBOUNCE_INICIO_DEFECTO_MS = 20 * 1000; // 20s
const DEBOUNCE_INICIO_MIN_MS = 3 * 1000;      // 3s
const DEBOUNCE_INICIO_MAX_MS = 120 * 1000;    // 2 minutos

function cargarDebounceInicioGuardado() {
  try {
    if (fs.existsSync(DEBOUNCE_INICIO_FILE)) {
      const datos = JSON.parse(fs.readFileSync(DEBOUNCE_INICIO_FILE, 'utf8'));
      const valor = Number(datos?.ms);
      if (Number.isFinite(valor) && valor >= DEBOUNCE_INICIO_MIN_MS && valor <= DEBOUNCE_INICIO_MAX_MS) {
        return Math.round(valor);
      }
    }
  } catch (e) {
    console.error('No se pudo leer debounce_inicio.json, se usa el valor por defecto:', e.message);
  }
  return DEBOUNCE_INICIO_DEFECTO_MS;
}

function guardarDebounceInicioEnDisco(valor) {
  try {
    fs.writeFileSync(DEBOUNCE_INICIO_FILE, JSON.stringify({ ms: valor }));
  } catch (e) {
    console.error('No se pudo guardar debounce_inicio.json:', e.message);
  }
}

let debounceInicioMs = cargarDebounceInicioGuardado();
console.log(`Espera al iniciar conversación: ${debounceInicioMs} ms (${(debounceInicioMs / 1000).toFixed(1)}s).`);

function esperarPausaEntreFragmentos() {
  const baseMs = pausaEntreFragmentosSegundos * 1000;
  // Mismo ±10% de variación aleatoria que tenía el rango fijo original.
  const variacion = 1 + (Math.random() * 0.2 - 0.1);
  const demora = Math.max(0, Math.round(baseMs * variacion));
  return new Promise((resolve) => setTimeout(resolve, demora));
}

/**
 * Envía un texto de respuesta al ciudadano, partiéndolo en varias burbujas
 * de WhatsApp consecutivas si viene marcado con SEPARADOR_FRAGMENTO (ver
 * comentario de arriba). Cada burbuja pasa por enviarYRegistrar, así que
 * conserva toda la lógica ya existente: presencia "escribiendo…", demora
 * proporcional al largo del texto, registro para deduplicación/retry, y
 * limpieza de negrita/emojis.
 */
async function enviarFragmentado(jid, textoCompleto, numeroDestino = null) {
  if (typeof textoCompleto !== 'string' || textoCompleto.indexOf(SEPARADOR_FRAGMENTO) === -1) {
    // Caso normal: un solo mensaje, sin cambios de comportamiento.
    return enviarYRegistrar(jid, { text: textoCompleto }, numeroDestino);
  }

  const partes = textoCompleto
    .split(SEPARADOR_FRAGMENTO)
    .map((parte) => parte.trim())
    .filter((parte) => parte.length > 0);

  if (partes.length === 0) return null;
  if (partes.length === 1) {
    return enviarYRegistrar(jid, { text: partes[0] }, numeroDestino);
  }

  let ultimoEnviado = null;
  for (let i = 0; i < partes.length; i++) {
    ultimoEnviado = await enviarYRegistrar(jid, { text: partes[i] }, numeroDestino);
    const esUltimaParte = i === partes.length - 1;
    if (!esUltimaParte) {
      await esperarPausaEntreFragmentos();
    }
  }
  return ultimoEnviado;
}

// Cola de procesamiento por número: si llegan dos eventos casi al mismo
// tiempo para el mismo número (ej. por una reconexión o un reintento de
// WhatsApp), esto asegura que se procesen uno después del otro, en orden,
// en vez de en paralelo — evitando que se pisen la sesión/fase de la
// conversación guardada en whatsapp/sesiones/<numero>.json.
const colasPorNumero = new Map(); // numero -> Promise de la última tarea encolada

function encolarProcesamiento(numero, tarea) {
  const anterior = colasPorNumero.get(numero) || Promise.resolve();
  const actual = anterior.then(tarea, tarea);
  // Guardamos una versión que nunca queda "rechazada" para no romper la
  // cadena de futuras tareas del mismo número.
  colasPorNumero.set(numero, actual.catch(() => {}));
  return actual;
}

// ---------- DEBOUNCE: agrupar mensajes sueltos del mismo ciudadano ----------
// Si un ciudadano manda varios mensajes seguidos (ej. "Hola", luego "tengo
// un problema", luego el detalle, luego una foto), antes cada uno se
// reenviaba a PHP por separado, como si fueran peticiones distintas. Ahora
// se acumulan por número y, si no llega nada nuevo dentro de la ventana de
// espera, se concatenan y se procesan como una sola petición.
//
// numero -> { mensajes: [{texto, tieneFoto, fotoRuta}], timeout, jidDestino }
const pendingMessages = new Map();

// ---------------------------------------------------------------------------
// CORRECCIÓN (2 en 1):
//
// 1) Antes se esperaban 60s SIEMPRE, sin importar en qué momento de la
//    conversación llegara el mensaje. Eso tenía sentido para la primera
//    tanda de mensajes de un reporte nuevo (para dar tiempo a que el
//    ciudadano mande el texto en varias burbujas y/o una foto), pero no
//    tiene ningún sentido cuando el bot YA hizo una pregunta puntual
//    ("¿cuál es tu nombre?", "¿podría indicarnos la colonia?", etc.): ahí
//    el ciudadano ya sabe exactamente qué está respondiendo y esperar 60s
//    (o incluso 15s) antes de contestarle es una demora innecesaria.
//
// 2) Por eso ahora la espera depende de la FASE de la conversación,
//    consultando whatsapp/sesiones/<numero>.json (que mantiene PHP):
//      - Fase "inicio" (o sesión inexistente / recién creada): es el
//        arranque de una conversación nueva. Se espera
//        debounceInicioMs (configurable, 20s por defecto) de inactividad, para permitir que
//        el reporte llegue repartido en varias burbujas y/o con fotos.
//      - Cualquier otra fase (esperando_nombre, esperando_telefono,
//        esperando_direccion, confirmar, modificando_*, etc.): el
//        ciudadano está respondiendo algo puntual que el bot ya preguntó,
//        así que se usa DEBOUNCE_DELAY_RESPUESTA_MS (prácticamente
//        instantáneo). Se deja un margen mínimo, en vez de 0ms, únicamente
//        para no partir en dos una respuesta que WhatsApp entregue como
//        texto + foto casi en el mismo instante.
//
// En ambos casos se sigue acumulando todo lo que llegue DENTRO de la
// ventana correspondiente (texto, foto, o ambos) en una sola petición;
// lo único que cambia es cuán larga es esa ventana.
// ---------------------------------------------------------------------------
// La espera al ABRIR una conversación nueva es configurable desde el panel
// admin (variable debounceInicioMs, cargada arriba). Sólo se mantiene fija
// la espera cuando el ciudadano está respondiendo algo puntual.
const DEBOUNCE_DELAY_RESPUESTA_MS = 1500; // 1.5s: respuesta a una pregunta puntual ya en curso

/**
 * Lee la fase actual guardada por PHP para este número en
 * whatsapp/sesiones/<numero>.json. Si el archivo no existe todavía (nunca
 * escribió este número) o no se puede leer/parsear, se asume "inicio" (el
 * caso más seguro: preferir esperar un poco de más a cortar en pedazos el
 * primer reporte de un ciudadano).
 *
 * @returns {string} la fase ('inicio' por defecto)
 */
function obtenerFaseSesion(numero) {
  try {
    const ruta = path.join(CARPETA_SESIONES, `${numero}.json`);
    if (!fs.existsSync(ruta)) return 'inicio';
    const contenido = fs.readFileSync(ruta, 'utf8');
    const sesion = JSON.parse(contenido);
    return sesion?.fase || 'inicio';
  } catch (err) {
    console.error(`No se pudo leer la fase de sesión de ${numero}, se asume "inicio":`, err.message);
    return 'inicio';
  }
}

/**
 * Calcula cuántos milisegundos hay que esperar antes de procesar los
 * mensajes acumulados de este número, según la fase de su conversación
 * (ver comentario grande arriba).
 */
function calcularDebounceMs(numero) {
  const fase = obtenerFaseSesion(numero);
  return fase === 'inicio' ? debounceInicioMs : DEBOUNCE_DELAY_RESPUESTA_MS;
}

/**
 * Toma todos los mensajes acumulados de un número (una vez vencido el
 * debounce), los concatena en una sola petición y sigue exactamente el
 * mismo flujo que antes tenía el procesamiento inmediato: reintentos hacia
 * PHP, manejo de "sin_respuesta_automatica", mensaje de error amable +
 * log en disco, y el envío final fragmentado de la respuesta.
 *
 * Se ejecuta dentro de encolarProcesamiento(numero, ...) para mantener la
 * misma garantía de siempre: nunca dos peticiones del mismo número en
 * paralelo pisándose la sesión guardada en whatsapp/sesiones/<numero>.json.
 */
async function procesarMensajesAcumulados(numero) {
  const pendiente = pendingMessages.get(numero);
  if (!pendiente) return;

  const mensajes = pendiente.mensajes;
  const jidDestino = pendiente.jidDestino;
  // Limpiamos antes de procesar: si llega un mensaje nuevo mientras se
  // está armando/enviando esta petición, arranca su propio ciclo de
  // debounce en vez de mezclarse con el que ya está en curso.
  pendiente.mensajes = [];
  pendiente.timeout = null;

  if (mensajes.length === 0) return;

  // Si se pausó mientras el mensaje esperaba el debounce, no lo procesamos.
  if (estaPausado()) {
    console.log(`[Sync] Bot pausado. Se descarta el lote pendiente de ${numero}.`);
    return;
  }

  await encolarProcesamiento(numero, async () => {
    // Texto concatenado: varios mensajes sueltos del ciudadano dentro de
    // la misma ventana se juntan en uno solo (separados por salto de
    // línea), para que PHP/la IA lo vea como una única petición.
    const textos = mensajes.map((m) => m.texto).filter((t) => t && t !== '__FOTO_RECIBIDA__');
    const huboFotoSinTexto = mensajes.some((m) => m.texto === '__FOTO_RECIBIDA__');
    let texto = textos.join('\n');
    if (!texto) texto = huboFotoSinTexto ? '__FOTO_RECIBIDA__' : '';

    const tieneFoto = mensajes.some((m) => m.tieneFoto);
    const fotos = mensajes.map((m) => m.fotoRuta).filter((f) => f !== null);
    // Por compatibilidad con el contrato actual de recibir_bot.php (que
    // espera un único campo "foto"), se manda la última fotografía
    // recibida en la ventana. También se manda "fotos" (array completo)
    // por si el lado de PHP se actualiza para aceptar varias; si no lo
    // usa, simplemente lo ignora.
    const fotoRuta = fotos.length > 0 ? fotos[fotos.length - 1] : null;

    console.log(
      `[Debounce] Procesando ${mensajes.length} mensaje(s) acumulado(s) de ${numero} tras la ventana de espera sin nuevos mensajes.`
    );

    // Se calcula acá (antes de llamar a PHP) porque, si es una prueba con el
    // propio número vinculado, se lo avisamos a PHP en la misma petición:
    // así, si la IA está desactivada por "atención humana en curso", PHP
    // puede reactivarla automáticamente después de este mensaje (ver
    // comentario detallado en recibir_bot.php) en vez de quedar bloqueada
    // para siempre esperando a que un encargado "termine de atender".
    const esPruebaConMiPropioNumero = modoPruebaActivo && !!numeroVinculado && numero === numeroVinculado;

    // Fuera de horario (8-16, L-V, hora Guatemala) o backlog: PHP registra
    // la petición como informativa y responde con "sin_respuesta_automatica",
    // así que no se le contesta nada al ciudadano. Se usa la hora del primer
    // mensaje de la ventana. En modo prueba (propio número vinculado) no se
    // aplica, para poder probar el bot a cualquier hora.
    const timestampPrimerMensajeMs = mensajes[0].timestampMs || Date.now();
    const fueraDeHorario = !esPruebaConMiPropioNumero && !estaEnHorarioAtencion(timestampPrimerMensajeMs);
    if (fueraDeHorario) {
      console.log(
        `[Horario] Mensaje de ${numero} fuera del horario de atención (${new Date(timestampPrimerMensajeMs).toISOString()}): se registrará como informativa, sin responder.`
      );
    }

    let textoRespuesta = null;
    let sinRespuestaAutomatica = false;
    for (let intento = 1; intento <= 2 && textoRespuesta === null && !sinRespuestaAutomatica; intento++) {
      try {
        const respuestaPHP = await axios.post(
          PHP_RECEIVE_URL,
          {
            secret: BRIDGE_SECRET,
            numero,
            mensaje: texto,
            tieneFoto,
            foto: fotoRuta,
            fotos: fotos.length > 0 ? fotos : undefined,
            modoPrueba: esPruebaConMiPropioNumero,
            fueraDeHorario,
            timestampMensaje: timestampPrimerMensajeMs,
          },
          { timeout: 20000 }
        );

        if (respuestaPHP.data && respuestaPHP.data.sin_respuesta_automatica === true) {
          sinRespuestaAutomatica = true;
          console.log(
            `[Silencio intencional] El sistema (PHP) indicó que no hay que responder automáticamente a ${numero} (atención humana en curso). No se reintenta ni se envía nada.`
          );
          break;
        }

        const posibleRespuesta = respuestaPHP.data && typeof respuestaPHP.data === 'object'
          ? respuestaPHP.data.respuesta
          : null;

        if (typeof posibleRespuesta === 'string' && posibleRespuesta.trim() !== '') {
          textoRespuesta = posibleRespuesta;
        } else {
          console.error(
            `El sistema (PHP) respondió sin un texto de respuesta válido (intento ${intento}/2) para ${numero}. Respuesta cruda:`,
            JSON.stringify(respuestaPHP.data).slice(0, 500)
          );
          if (intento < 2) {
            await new Promise((resolve) => setTimeout(resolve, 1500));
          }
        }
      } catch (err) {
        console.error(`Error consultando al sistema (intento ${intento}/2) para ${numero}:`, err.message);
        if (intento < 2) {
          await new Promise((resolve) => setTimeout(resolve, 1500));
        }
      }
    }

    if (sinRespuestaAutomatica) {
      if (esPruebaConMiPropioNumero) {
        await enviarYRegistrar(jidDestino, {
          text: '🧪 [Modo prueba] El sistema decidió NO responder automáticamente aquí (atención humana en curso / IA desactivada en este chat). Un ciudadano real no recibiría ningún mensaje en este punto.',
        }, numero);
        console.log(`[Modo prueba] Silencio intencional avisado a ${numero} (no se le habría mandado nada a un ciudadano real).`);
      }
      return;
    }

    if (textoRespuesta === null) {
      // CAMBIO: NO se le envía al ciudadano ningún mensaje de "dificultades
      // técnicas" — se decidió no exponer la inestabilidad del sistema
      // para no minar la confianza de quien lo usa (y porque antes ese
      // mensaje aparecía incluso cuando el problema era interno y
      // transitorio, como cuando llegó un mensaje con numero=null y PHP
      // respondió 400).
      //
      // Se registra el fallo en el log para que el administrador lo
      // diagnostique, y se deja el chat en silencio. En la práctica el
      // ciudadano vuelve a escribir por su cuenta y ese nuevo intento
      // suele procesarse bien.
      try {
        const logPath = path.join(__dirname, '..', 'sistema-web', 'logs', 'errores_bot.log');
        const logDir = path.dirname(logPath);
        if (!fs.existsSync(logDir)) {
          fs.mkdirSync(logDir, { recursive: true });
        }
        const logMsg = `[${new Date().toISOString()}] Error al procesar mensaje(s) acumulado(s) de ${numero}: ${texto}\n`;
        fs.appendFileSync(logPath, logMsg);
      } catch (e) {
        console.error('No se pudo escribir el log de error:', e.message);
      }
      return;
    }

    await enviarFragmentado(jidDestino, textoRespuesta, numero);
    console.log(`Respuesta enviada a ${numero} (tras debounce)`);
  });
}

/**
 * Borra las credenciales guardadas en ./auth_info (todo excepto .gitkeep).
 * Se usa tanto al desvincular manualmente como cuando Baileys detecta que
 * la sesión fue cerrada del lado del teléfono (logout / 401), para que la
 * próxima vez que se llame a iniciarWhatsApp() genere un QR totalmente
 * nuevo en vez de reintentar con credenciales que ya no sirven.
 */
function limpiarCredenciales() {
  if (fs.existsSync(AUTH_DIR)) {
    for (const archivo of fs.readdirSync(AUTH_DIR)) {
      if (archivo === '.gitkeep') continue;
      fs.rmSync(path.join(AUTH_DIR, archivo), { force: true });
    }
  }
}

// NUEVO: descarga una foto del historial. El nombre empieza con la hora real del
// mensaje (ms) para que el importador la asocie a la conversación correcta.
async function descargarFotoHistorial(msg, numero, tsSeg) {
  const c = msg.message?.ephemeralMessage?.message || msg.message;
  if (!c?.imageMessage || msg.key.fromMe || numero.startsWith('lid-')) return;
  const buffer = await downloadMediaMessage(
    msg, 'buffer', {},
    { logger: pino({ level: 'silent' }), reuploadRequest: sock.updateMediaMessage }
  );
  const carpeta = path.join(CARPETA_FOTOS_BASE, numero);
  fs.mkdirSync(carpeta, { recursive: true });
  const nombre = `${tsSeg * 1000}_${Math.random().toString(36).slice(2, 8)}.jpg`;
  fs.writeFileSync(path.join(carpeta, nombre), buffer);
}

/**
 * Arranca (o reinicia) la conexión con WhatsApp. Si ya existe una sesión
 * guardada en ./auth_info, Baileys se reconecta sin pedir QR. Si no,
 * dispara el evento 'qr' que capturamos abajo para exponerlo por HTTP.
 */
async function iniciarWhatsApp() {
  if (isStarting) return;
  isStarting = true;

  try {
    const { state, saveCreds } = await useMultiFileAuthState(AUTH_DIR);
    const { version } = await fetchLatestBaileysVersion();
    const loggerBaileys = pino({ level: 'silent' });

    sock = makeWASocket({
      version,
      auth: {
        creds: state.creds,
        // Envuelve las claves de sesión (Signal) en una caché en memoria.
        // Esto evita que lecturas/escrituras simultáneas al disco
        // (auth_info/session-*.json) se pisen entre sí cuando llegan varios
        // mensajes casi al mismo tiempo, que es lo que produce "Bad MAC".
        keys: makeCacheableSignalKeyStore(state.keys, loggerBaileys),
      },
      // Ponelo en 'silent' para no llenar la consola de logs internos
      logger: loggerBaileys,
      printQRInTerminal: false, // lo manejamos nosotros abajo para más control

      // ============================================================
      // FIX iPhone como primario (CIPHERTEXT / stub=2)
      // ------------------------------------------------------------
      // Cuando el dispositivo primario es un iPhone, WhatsApp lo trata
      // distinto que a un Android: no comparte automáticamente las
      // sesiones Signal iniciales con los secundarios hasta que "reconoce"
      // al secundario como un cliente legítimo de WhatsApp Web.
      //
      // Si no se especifica `browser`, Baileys usa uno por defecto que el
      // servidor puede no aceptar como cliente Desktop válido, y entonces
      // TODOS los mensajes entrantes de ciudadanos llegan como
      // CIPHERTEXT (stub=2) — exactamente lo que muestra tu log:
      //   [MSG-RAW] ... dir=in SIN-contenido stub=2 senderPn=...
      //
      // Al identificarse como WhatsApp Web (Ubuntu + Chrome) el servidor
      // sí enruta los mensajes entrantes con la sesión Signal correcta,
      // y el bot puede descifrarlos normalmente.
      // ============================================================
      browser: HISTORIAL_COMPLETO ? Browsers.macOS('Desktop') : Browsers.ubuntu('Chrome'),

      // ============================================================
      // FIX iPhone primario (parte 2): cache de reintentos
      // ------------------------------------------------------------
      // Cuando un mensaje entrante llega cifrado y Baileys no puede
      // descifrarlo, WhatsApp espera que el receptor pida "retry". Sin
      // este cache, Baileys no puede seguir la cuenta de reintentos ni
      // gestionar correctamente las respuestas que llegan desde el
      // primario tras el retry, y los mensajes se quedan pegados como
      // CIPHERTEXT para siempre. Con el cache, el primario (iPhone) sí
      // responde al retry con el mensaje re-cifrado en una sesión válida.
      //
      // Se usa un Map nativo de JavaScript en vez de @cacheable/node-cache
      // (que además da problemas de import en esta versión del proyecto,
      // porque su export viene dentro de un `.default`). Baileys solo
      // necesita un objeto con .get() y .set(), que un Map cumple.
      // ============================================================
      msgRetryCounterCache: new Map(),

      // ---------- FIX iPhone como primario (sync) ----------
      // Estas tres opciones fuerzan la sincronización completa y las
      // consultas iniciales, que es lo que necesita Baileys para que el
      // primario le empiece a entregar mensajes de otros contactos:
      //   · syncFullHistory      → pide todo el historial, no solo un delta
      //   · fireInitQueries      → dispara las consultas iniciales de sync
      //   · markOnlineOnConnect  → el bot aparece "en línea" al conectar
      syncFullHistory: true,
      fireInitQueries: true,
      markOnlineOnConnect: true,

      // Permite que Baileys reenvíe el contenido real de un mensaje ya
      // enviado cuando el teléfono receptor (típicamente iPhone) pide un
      // "retry receipt" porque no pudo descifrarlo a la primera. Sin esto,
      // Baileys no tenía cómo resolver ese pedido y el mensaje se quedaba
      // pegado del lado del ciudadano como "Esperando mensaje. Esto puede
      // tomar tiempo." (ver CONTENIDO_ENVIADOS / enviarYRegistrar arriba).
      getMessage: async (key) => {
        const guardado = key?.id ? CONTENIDO_ENVIADOS.get(key.id) : null;
        return guardado ? guardado.content : undefined;
      },
    });

    sock.ev.on('creds.update', async () => {
      try {
        await saveCreds();
      } catch (e) {
        console.error('No se pudieron guardar las credenciales:', e.message);
      }
      // Refrescamos el LID propio apenas Baileys lo conozca (ver BUGFIX arriba).
      const lidNuevo = normalizarLid(sock?.user?.lid) || normalizarLid(sock?.authState?.creds?.me?.lid);
      if (lidNuevo && lidNuevo !== lidVinculado) {
        lidVinculado = lidNuevo;
        console.log(`[LID] LID propio actualizado: ${lidVinculado}`);
      }
    });

    sock.ev.on('connection.update', async (update) => {
      const { connection, lastDisconnect, qr } = update;
      // DIAGNÓSTICO: cada evento de conexión, para detectar caídas silenciosas.
      const motivoDiag = lastDisconnect?.error?.output?.statusCode;
      const detalleDiag = lastDisconnect?.error?.message || '';
      console.log(`[DIAG-CONN] connection=${connection || '-'} qr=${qr ? 'sí' : 'no'} motivo=${motivoDiag || '-'} ${detalleDiag ? '(' + detalleDiag + ')' : ''}`);

      if (qr) {
        lastQR = qr;
        lastQRAt = Date.now();
        console.log('\nEscaneá este código QR con WhatsApp (Dispositivos vinculados):\n');
        qrcode.generate(qr, { small: true });
        try {
          lastQRDataUrl = await QRCode.toDataURL(qr, { margin: 1, scale: 6 });
        } catch (e) {
          console.error('No se pudo generar la imagen del QR:', e.message);
          lastQRDataUrl = null;
        }
      }

      if (connection === 'close') {
        isConnected = false;
        numeroVinculado = null;
        lidVinculado = null;
        const motivo = lastDisconnect?.error?.output?.statusCode;
        const debeReconectar = motivo !== DisconnectReason.loggedOut;
        ultimoError = `Conexión cerrada (código ${motivo ?? 'desconocido'})`;
        console.log('Conexión cerrada. Motivo:', motivo, '¿Reconectar?', debeReconectar);
        isStarting = false;
        if (debeReconectar) {
          iniciarWhatsApp();
        } else {
          // Sesión cerrada por logout explícito (o credenciales inválidas/
          // vencidas, típicamente código 401): no reintentamos solos, pero
          // SÍ borramos las credenciales rotas de auth_info. Si no lo
          // hiciéramos, el próximo "Vincular WhatsApp" del panel volvería a
          // fallar con el mismo error en vez de mostrar un QR nuevo.
          limpiarCredenciales();
          sock = null;
          lastQR = null;
          lastQRDataUrl = null;
          console.log('Sesión cerrada (logout). Credenciales inválidas eliminadas. Esperando que el admin vincule de nuevo desde el panel.');
        }
      } else if (connection === 'open') {
        isConnected = true;
        isStarting = false;
        ultimoError = null;
        lastQR = null;
        lastQRDataUrl = null;
        numeroVinculado = sock.user?.id ? sock.user.id.split(':')[0].split('@')[0] : null;
        lidVinculado = normalizarLid(sock.user?.lid) || normalizarLid(sock.authState?.creds?.me?.lid);
        console.log(
          'Conectado a WhatsApp correctamente.',
          numeroVinculado ? `(${numeroVinculado})` : '',
          lidVinculado ? `[LID: ${lidVinculado}]` : ''
        );
      }
    });

    // NUEVO: historial que el teléfono entrega al vincular (o al pedirlo con
    // fetchMessageHistory). Antes este evento se ignoraba y esos mensajes se perdían.
    sock.ev.on('messaging-history.set', async ({ messages, isLatest, progress, syncType }) => {
      let nuevos = 0;
      for (const m of messages || []) {
        const r = archivoChats.archivar(m, 'historial');
        if (!r) continue;
        nuevos++;
        if (HISTORIAL_DESCARGAR_FOTOS) {
          await descargarFotoHistorial(m, r.numero, r.linea.ts).catch(() => {});
        }
      }
      console.log(`[Historial] syncType=${syncType ?? '-'} recibidos=${(messages || []).length} archivados_nuevos=${nuevos} progreso=${progress ?? '-'} isLatest=${isLatest ?? '-'}`);
    });

    // Mensajes entrantes
    sock.ev.on('messages.upsert', async ({ messages, type }) => {
      // 'notify' = mensaje nuevo en tiempo real.
      // 'append'  = mensajes que Baileys entrega como backlog (por ejemplo,
      //             los que llegaron mientras el bot estaba desconectado).
      //             Los aceptamos también, pero más abajo filtramos que
      //             sean recientes, para no reprocesar historial viejo.
      console.log(`[DIAG-RAW] messages.upsert type=${type} cantidad=${messages.length}`);

      // DIAGNÓSTICO: log de TODOS los mensajes que llegan a Baileys,
      // ANTES de cualquier filtro. Sirve para ver si los mensajes de
      // ciudadanos reales están llegando al bot o si el problema es de
      // vinculación/sincronización con el iPhone primario.
      try {
        for (const m of messages) {
          const rj = m.key?.remoteJid || '(sin-jid)';
          const fm = m.key?.fromMe ? 'fromMe' : 'in';
          const tieneMsg = m.message ? 'CON-contenido' : 'SIN-contenido';
          const stubD = m.messageStubType ? ` stub=${m.messageStubType}` : '';
          const protoD = m.protocolMessage ? ' protocol=1' : '';
          const spn = m.key?.senderPn ? ` senderPn=${String(m.key.senderPn).split(/[:@]/)[0]}` : '';
          console.log(`[MSG-RAW] type=${type} jid=${rj} dir=${fm} ${tieneMsg}${stubD}${protoD}${spn} id=${m.key?.id}`);
        }
      } catch (e) {
        console.error('Error en log diagnóstico de messages.upsert:', e.message);
      }

      if (type !== 'notify' && type !== 'append') return;

      // NUEVO: log de diagnóstico — permite ver si los mensajes de
      // ciudadanos están llegando a Baileys (aunque luego los filtros los
      // descarten). Si ves aquí el mensaje pero no "[Debounce] ... puesto
      // en cola", el problema está en un filtro posterior. Si NO lo ves,
      // es un problema de vinculación/entrega de WhatsApp.
      for (const m of messages) {
        const rj = m.key?.remoteJid || '(sin-jid)';
        const fm = m.key?.fromMe ? 'fromMe' : 'in';
        const tieneMsg = m.message ? 'con-contenido' : 'SIN-contenido';
        const stubD = m.messageStubType ? ` stub=${m.messageStubType}` : '';
        const protoD = m.protocolMessage ? ' protocol=1' : '';
        console.log(`[Diagnóstico] upsert type=${type} jid=${rj} dir=${fm} ${tieneMsg}${stubD}${protoD} id=${m.key?.id}`);
      }

      for (const msg of messages) {
        try {
          // NUEVO: se archiva TODO mensaje 1 a 1 (entrante o saliente) antes de
          // cualquier filtro, para que el historial quede completo.
          archivoChats.archivar(msg, type === 'notify' ? 'vivo' : 'backlog');

          if (!msg.message) {
            // NUEVO: detección de fallos de desencriptación (típico en iPhone).
            // OJO: Baileys marca los mensajes que no pudo desencriptar con
            // messageStubType = CIPHERTEXT, así que ese stub NO debe contarse
            // como "mensaje de protocolo"; es justo la señal del fallo.
            const jidFallido = msg.key?.remoteJid || '';
            const esGrupoOEstado =
              jidFallido.endsWith('@g.us') ||
              jidFallido.endsWith('@broadcast') ||
              jidFallido.endsWith('@newsletter');
            const stub = msg.messageStubType;
            const esCiphertext = stub === 2 || stub === 'CIPHERTEXT';
            const esOtroStubOProtocolo = (!!stub && !esCiphertext) || !!msg.protocolMessage;
            if (!msg.key?.fromMe && !esGrupoOEstado && !esOtroStubOProtocolo && jidFallido) {
              // ============================================================
              // FIX iPhone primario: los mensajes llegan como CIPHERTEXT
              // (stub=2) porque el iPhone no le comparte al bot las
              // "pre-keys" Signal con las que el ciudadano cifró su
              // mensaje. Un retry simple NO alcanza, porque el iPhone
              // tampoco tiene la sesión armada con el bot.
              //
              // Solución: ANTES del retry, forzar la negociación de la
              // sesión Signal enviando un "presence" (typing) al
              // ciudadano. Eso hace que:
              //   1) Baileys pida al servidor de WhatsApp el pre-key
              //      bundle del contacto.
              //   2) Se establezca la sesión Signal entre el bot y el
              //      contacto por el canal servidor (no necesita que el
              //      iPhone primario coopere).
              //   3) A partir de ahí, el retry receipt SÍ funciona, y
              //      los mensajes siguientes del mismo ciudadano se
              //      descifran normalmente sin repetir todo esto.
              //
              // El ciudadano VE un "escribiendo…" breve la primera vez
              // que le escribe al bot, pero no recibe ningún mensaje
              // visible: es solo el gesto que fuerza la negociación.
              // ============================================================
              const idFallido = msg.key?.id;

              // Se prefiere el número real (senderPn / remoteJidAlt) para
              // enviar presence y retry; el LID solo se usa si no hay
              // ninguno de los otros, porque algunos flujos de retry aún
              // no aceptan jids "@lid" en todos los servidores.
              let jidParaWarmup = jidFallido;
              const numeroRealParaWarmup =
                (msg.key?.senderPn && String(msg.key.senderPn).split(/[:@]/)[0].replace(/\D/g, '')) ||
                (msg.key?.remoteJidAlt && !String(msg.key.remoteJidAlt).endsWith('@lid') &&
                  String(msg.key.remoteJidAlt).split(/[:@]/)[0].replace(/\D/g, '')) ||
                null;
              if (numeroRealParaWarmup) {
                jidParaWarmup = `${numeroRealParaWarmup}@s.whatsapp.net`;
              }

              if (idFallido) {
                global.__warmupEnviadoTs = global.__warmupEnviadoTs || new Map();
                global.__retryEnviadoTs = global.__retryEnviadoTs || new Map();
                const ahoraMs = Date.now();
                const ultimoWarmup = global.__warmupEnviadoTs.get(jidParaWarmup) || 0;
                const ultimoRetry = global.__retryEnviadoTs.get(idFallido) || 0;

                // Warm-up con cooldown por contacto (no por mensaje): solo
                // hace falta una vez cada 5 minutos por contacto nuevo.
                if (ahoraMs - ultimoWarmup > 5 * 60 * 1000) {
                  global.__warmupEnviadoTs.set(jidParaWarmup, ahoraMs);
                  try {
                    await sock.presenceSubscribe(jidParaWarmup);
                    await sock.sendPresenceUpdate('composing', jidParaWarmup);
                    await new Promise((r) => setTimeout(r, 400));
                    await sock.sendPresenceUpdate('paused', jidParaWarmup);
                    console.log(`[CIPHERTEXT-FIX] Warm-up Signal enviado a ${jidParaWarmup} (composing→paused) para forzar pre-key exchange.`);
                  } catch (e) {
                    console.error(`[CIPHERTEXT-FIX] No se pudo enviar warm-up a ${jidParaWarmup}: ${e.message}`);
                  }
                }

                // Retry receipt con cooldown por mensaje.
                if (ahoraMs - ultimoRetry > 30000 && typeof sock?.sendReceipt === 'function') {
                  global.__retryEnviadoTs.set(idFallido, ahoraMs);
                  try {
                    await sock.sendReceipt(jidParaWarmup, undefined, [idFallido], 'retry');
                    console.log(`[CIPHERTEXT-FIX] Retry receipt enviado para id=${idFallido} (jid=${jidParaWarmup}).`);
                  } catch (e) {
                    console.error(`[CIPHERTEXT-FIX] No se pudo enviar retry receipt: ${e.message}`);
                  }
                }
              }
            }
            continue;
          }
          // Ignorar grupos, canales/newsletters y ESTADOS ("status@broadcast").
          // Los estados no son mensajes dirigidos al bot; procesarlos generaba
          // intentos de clasificación absurdos y errores HTTP 400 en el PHP.
          const jidEntrante = msg.key.remoteJid || '';
          if (jidEntrante.endsWith('@g.us')) continue;         // grupos
          if (jidEntrante === 'status@broadcast') continue;    // estados
          if (jidEntrante.endsWith('@broadcast')) continue;    // otros broadcasts
          if (jidEntrante.endsWith('@newsletter')) continue;   // canales

          const idMensaje = msg.key.id;
          const timestampMsgMs = msg.messageTimestamp ? Number(msg.messageTimestamp) * 1000 : Date.now();
          const antiguedadMs = Date.now() - timestampMsgMs;

          if (type === 'append' && antiguedadMs > VENTANA_RECUPERACION_BACKLOG_MS) {
            // Backlog viejo (por ejemplo, historial sincronizado al escanear
            // un QR nuevo): no es un mensaje "perdido" reciente, se ignora.
            continue;
          }

          if (idMensaje && MENSAJES_PROCESADOS.has(idMensaje)) {
            // Ya procesamos este mismo mensaje (Baileys lo entregó más de
            // una vez). Lo ignoramos en silencio: esto es lo que antes
            // generaba el aviso duplicado de "Por favor enviá tu solicitud
            // como mensaje de texto..." en cada conversación.
            continue;
          }

          const remitente = msg.key.remoteJid; // ej: "50212345678@s.whatsapp.net" o "67787686989873@lid"
          let numero = remitente.split('@')[0];

          // ---------- BUGFIX: mensajes de ciudadanos reales llegando con LID en vez de su número ----------
          // WhatsApp está usando el identificador "LID" no solo para el chat "Tú",
          // sino también para chats normales con otras personas: en esos casos
          // remoteJid llega como "<lid>@lid" y el "numero" que se extrae de ahí
          // NO es el teléfono del ciudadano, sino un identificador interno
          // larguísimo (15 dígitos) que además puede variar entre mensajes. Esto
          // es lo que generaba expedientes con números como "150134960722112":
          // no era ningún error, era el LID del ciudadano, no su teléfono real.
          //
          // Por suerte, cuando esto pasa, WhatsApp SÍ adjunta el número de
          // teléfono real en msg.key.senderPn (disponible en la versión de
          // Baileys que ya tenés instalada, @whiskeysockets/baileys 6.7.x). Si
          // está presente, lo usamos en vez del LID: tanto para guardar el
          // expediente/la sesión bajo el número real, como para responderle al
          // ciudadano por su chat de siempre. Y lo cacheamos en
          // LID_A_NUMERO_REAL (ver arriba) para los mensajes que lleguen SIN
          // senderPn más adelante.
          let jidDestino = remitente; // a dónde se le responde (por defecto, el mismo chat de origen)
          if (remitente.endsWith('@lid')) {
            const lid = remitente.split('@')[0];
            const lidPropio = obtenerLidPropio();
            if (lidPropio && lid === lidPropio && numeroVinculado) {
              // Es el chat "Tú" (mi propio LID): el número real es el vinculado.
              // Se sigue respondiendo por el mismo chat en el que llegó.
              numero = numeroVinculado;
              jidDestino = remitente;
            } else if (msg.key.senderPn) {
              // FIX: split(/[:@]/) porque senderPn puede traer ":deviceId".
              const numeroReal = String(msg.key.senderPn).split(/[:@]/)[0].replace(/\D/g, '');
              if (numeroReal) {
                numero = numeroReal;
                // FIX: NO cambiamos jidDestino a numeroReal@s.whatsapp.net.
                // El chat real (el que la encargada ve en su WhatsApp) está
                // identificado internamente por el LID, no por el número. Si
                // respondemos al JID del teléfono, el ciudadano SÍ recibe la
                // respuesta, pero en el WhatsApp de la municipalidad (teléfono y
                // WhatsApp Web) el mensaje saliente NO aparece en la conversación
                // que se está viendo, así que no se puede capturar pantalla.
                // Dejamos jidDestino = remitente (el @lid original) y seguimos
                // guardando el ticket/sesión bajo el número real (variable numero).
                LID_A_NUMERO_REAL.set(lid, { numero: numeroReal, ts: Date.now() });
                limpiarCacheLidViejos();
              }
            } else if (msg.key.remoteJidAlt && !String(msg.key.remoteJidAlt).endsWith('@lid')) {
              // NUEVO: sin senderPn, el número real puede venir en remoteJidAlt.
              const numeroReal = String(msg.key.remoteJidAlt).split(/[:@]/)[0].replace(/\D/g, '');
              if (numeroReal) {
                numero = numeroReal;
                jidDestino = remitente;
                LID_A_NUMERO_REAL.set(lid, { numero: numeroReal, ts: Date.now() });
                limpiarCacheLidViejos();
                console.log(`[LID] Número real resuelto por remoteJidAlt: ${lid} → ${numeroReal}`);
              }
            } else if (LID_A_NUMERO_REAL.has(lid)) {
              // Este mensaje puntual no trae senderPn, pero ya conocemos a
              // qué número real corresponde este LID por un mensaje
              // anterior: lo reutilizamos para no cortar el hilo de la
              // conversación ni resetear al ciudadano a "inicio".
              const cacheado = LID_A_NUMERO_REAL.get(lid).numero;
              numero = cacheado;
              // FIX: idem — no cambiamos jidDestino, se responde al @lid original.
              console.log(`[LID] Mensaje sin senderPn para LID ${lid}; se usó el número real cacheado (${cacheado}) para ticket/sesión, pero se responde al @lid original para que la conversación coincida con la del WhatsApp de la encargada.`);
            } else {
              // ---------- BUGFIX: mensaje del propio chat "Tú" antes de que
              // la conexión termine de abrir ----------
              // Cuando llega un mensaje del propio número justo después de
              // que Baileys expone el LID (pero ANTES de que 'connection:
              // open' se dispare), numeroVinculado todavía es null. Si
              // seguimos, el mensaje se procesa con numero=null: la sesión
              // se guarda como "null.json", PHP recibe "numero": null y
              // responde 400, y el bot termina mandando un mensaje de error
              // técnico al ciudadano (o, peor, respondiendo cualquier cosa
              // porque no encuentra la sesión).
              //
              // Como WhatsApp reenvía el mismo mensaje una vez que la
              // conexión está lista, lo más seguro es ignorar esta entrega
              // temprana y dejar que la siguiente (ya con numeroVinculado
              // resuelto) sea la que se procese.
              if (lidPropio && lid === lidPropio) {
                console.log(`[LID] Mensaje del propio chat "Tú" ignorado por ahora: el número vinculado aún no está disponible (la conexión todavía se está abriendo). Se procesará cuando llegue la próxima entrega con la conexión lista.`);
                continue;
              }
              console.log(`[LID] ADVERTENCIA: mensaje con LID ${lid} sin senderPn y sin número real cacheado todavía. Se usará el LID como identificador temporal; si este ciudadano ya venía conversando, es posible que esta respuesta no encuentre su sesión anterior.`);
            }
          }

          if (msg.key.fromMe) {
            // Los mensajes que el propio número vinculado envía se ignoran
            // normalmente (si no, el bot respondería en bucle a sus propios
            // mensajes salientes hacia los ciudadanos).
            //
            // ÚNICA EXCEPCIÓN, y solo mientras "modoPruebaActivo" esté encendido
            // desde el panel admin: el chat "Tú" (uno mismo), que sirve para
            // probar el flujo completo de la IA desde el mismo teléfono, sin
            // un segundo número de WhatsApp. Cualquier otro chat (real, con
            // otro número) NUNCA se ve afectado por este interruptor.
            //
            // El chat "Tú" puede llegar identificado de dos formas distintas
            // (ver bloque "BUGFIX: el chat 'Tú' dejó de usar el número de
            // teléfono" más arriba): con el número de teléfono normal
            // (remoteJid terminado en "@s.whatsapp.net") o con el LID
            // (remoteJid terminado en "@lid"). Reconocemos cualquiera de
            // las dos.
            const esViaNumero = !!numeroVinculado && numero === numeroVinculado;

            // ---------- BUGFIX: el LID cacheado al conectar puede quedar desactualizado ----------
            // Antes solo comparábamos contra "lidVinculado", el valor que se
            // guarda UNA ÚNICA VEZ al momento de conectar. Si WhatsApp
            // reasigna el LID durante la sesión (esto puede pasar), esa
            // comparación deja de coincidir para siempre y el bot se queda
            // mudo en el chat "Tú" sin ningún error visible — exactamente el
            // síntoma de "a veces funciona, a veces no contesta nada".
            //
            // Ahora, además de esa comparación (que dejamos como respaldo por
            // si el mensaje no trae senderPn), usamos msg.key.senderPn: ese
            // dato lo entrega WhatsApp en CADA mensaje con el número de
            // teléfono real de quien lo envió. Como ya lo normalizamos arriba
            // en la variable "numero" cuando el mensaje llega vía @lid, basta
            // con comparar "numero" (ya resuelto) contra numeroVinculado.
            const lidPropioActual = obtenerLidPropio();
            const esViaLidCacheado =
              !!lidPropioActual && remitente.endsWith('@lid') && remitente.split('@')[0] === lidPropioActual;
            const esChatPropio = esViaNumero || esViaLidCacheado;
            if (!modoPruebaActivo || !esChatPropio) {
              // Antes este descarte era totalmente silencioso. Avisamos solo
              // en el caso que confunde: modo prueba activo, mensaje propio por
              // @lid y todavía no conocemos nuestro propio LID.
              if (modoPruebaActivo && remitente.endsWith('@lid') && !lidPropioActual) {
                console.log(`[Modo prueba] Mensaje propio por LID ${remitente.split('@')[0]} ignorado: el bot aún no conoce su LID propio. Reiniciá el bot.`);
              }
              continue;
            }

            if (!esViaNumero) {
              // Normalizamos "numero" al número de teléfono vinculado (en vez
              // del LID) para que el ticket/la sesión de esta prueba se
              // guarden bajo el mismo identificador de siempre
              // (whatsapp/sesiones/<numero>.json), sin importar cuál de los
              // dos identificadores haya usado WhatsApp esta vez.
              numero = numeroVinculado;
              jidDestino = remitente; // seguimos respondiendo por el mismo chat "Tú" en el que llegó
            }

            // ---------- FIX: modo prueba reprocesaba mensajes al reiniciar ----------
            // En el chat "Tú", tanto los mensajes que escribe el admin como
            // las respuestas que envía el propio bot llegan con fromMe=true
            // desde el mismo remoteJid. El bot los distingue con
            // MENSAJES_PROCESADOS (un Map en memoria). Pero ese Map queda
            // VACÍO cada vez que el proceso de Node se reinicia, así que al
            // reconectar Baileys entrega como backlog (type === 'append')
            // los últimos mensajes del chat, y el bot los trata como si
            // fueran mensajes nuevos del "ciudadano" → responde de nuevo y
            // vuelve a generar el ticket/oficio en cada arranque.
            //
            // En chats reales de ciudadanos esto no pasa porque sus mensajes
            // llegan con fromMe=false y las respuestas del bot hacia ellos
            // se filtran arriba por fromMe incondicionalmente.
            //
            // Solución: en modo prueba, ignorar
            //   (a) cualquier mensaje de tipo 'append' (backlog de
            //       reconexión, que son ecos de mensajes ya manejados), y
            //   (b) cualquier mensaje con marca de tiempo anterior al
            //       arranque del bot (por si el cliente los reenvía como
            //       'notify' tras un reconecte muy rápido).
            // Es seguro porque el modo prueba es solo para pruebas: si se
            // pierde algún mensaje, el admin simplemente lo reenvía.
            const MARGEN_MS = 60000; // 60s de tolerancia por desfase de reloj
            if (type === 'append') {
              console.log(`[Modo prueba] Mensaje de backlog del chat "Tú" ignorado (id=${idMensaje}); ya fue manejado antes del reinicio del bot.`);
              continue;
            }
            if (timestampMsgMs < (botStartedAt - MARGEN_MS)) {
              const haceSeg = Math.round((Date.now() - timestampMsgMs) / 1000);
              console.log(`[Modo prueba] Mensaje antiguo del chat "Tú" ignorado (id=${idMensaje}, hace ${haceSeg}s); evita reprocesar al reiniciar.`);
              continue;
            }

            console.log(`[Modo prueba] Tratando mensaje propio (chat "Tú", ${numero}) como si fuera de un ciudadano.`);
          }

          // ---------- Números excluidos del bot (contactos de la municipalidad) ----------
          // Se revisa DESPUÉS de resolver el número real (por si el mensaje
          // llegó identificado con LID en vez de con el teléfono, ver bloque
          // "BUGFIX" más arriba) y después del caso especial del "modo
          // prueba", para que un número quede excluido sin importar por
          // cuál de los dos identificadores llegue el mensaje. Si el número
          // está en la lista (ver panel admin > "Números sin respuesta
          // automática"), el mensaje se ignora COMPLETO: no se le contesta
          // nada, no se reenvía a recibir_bot.php y no se guarda ninguna
          // sesión ni ticket para él. Así los contactos internos de la
          // municipalidad (concejales, personal administrativo, etc.)
          // pueden escribir libremente por ese número sin que el bot les
          // responda ni los procese como si fueran una petición ciudadana.
          if (esNumeroExcluido(numero)) {
            console.log(`[Números excluidos] Mensaje de ${numero} ignorado: está en la lista de números sin respuesta automática del panel admin.`);
            continue;
          }

          // === Si el bot está pausado (por failover o por horario), no
          // procesamos el mensaje: no respondemos, no creamos ticket, no
          // llamamos a PHP. La otra PC (activa) se encargará. El archivado
          // de historial_chats (archivo_chats.js) sigue funcionando aparte.
          if (estaPausado()) {
            console.log(`[Sync] Bot pausado. Ignorando procesamiento de ${numero}.`);
            continue;
          }

          // ---------- DEBOUNCE ----------
          // La extracción del mensaje (texto/foto) se hace de inmediato,
          // aquí abajo, para no retrasar la descarga de fotografías. Lo
          // único que se pospone es el envío a PHP y la respuesta al
          // ciudadano (ver procesarMensajesAcumulados), por la ventana que
          // calcule calcularDebounceMs (15s solo al abrir la conversación,
          // prácticamente instantáneo en medio de ella), para poder juntar
          // varios mensajes sueltos en una sola petición sin demoras
          // innecesarias.
          {
            if (idMensaje) {
              MENSAJES_PROCESADOS.set(idMensaje, Date.now());
              limpiarDedupViejos();
            }

            // Contenido de texto, incluyendo el caso de chats con "mensajes
            // temporales" (ephemeralMessage), donde el texto real viene
            // envuelto un nivel más adentro.
            const contenido = msg.message.ephemeralMessage?.message || msg.message;

            // ¿Trae una fotografía? (con o sin texto/caption). Antes, si el
            // ciudadano mandaba una foto sin escribir nada en el mismo
            // mensaje, el bot solo respondía "enviá tu solicitud como
            // texto" y LA FOTO SE PERDÍA POR COMPLETO: nunca se le avisaba
            // al sistema (PHP) que había llegado una fotografía, así que el
            // expediente jamás reflejaba que el ciudadano sí adjuntó
            // evidencia gráfica. Ahora avisamos siempre que llega una foto
            // (tieneFoto), la registramos aunque no traiga texto, y dejamos
            // que sea el propio chat (PHP) quien le pida la descripción del
            // problema si hace falta — en vez de simplemente descartarla.
            const tieneFoto = !!contenido.imageMessage;

            // Si trae una fotografía, la descargamos y la guardamos en disco
            // AHORA, para poder incrustarla automáticamente en el oficio del
            // reporte (ver sistema-web/reportes/oficio_html.php). Antes esto
            // no se hacía: solo se leía el caption (si lo había) y la imagen
            // en sí nunca se guardaba en ningún lado, así que el reporte
            // jamás podía mostrarla de verdad, solo un aviso genérico.
            let fotoRuta = null;
            if (tieneFoto) {
              try {
                const buffer = await downloadMediaMessage(
                  msg,
                  'buffer',
                  {},
                  { logger: pino({ level: 'silent' }), reuploadRequest: sock.updateMediaMessage }
                );
                const mime = contenido.imageMessage?.mimetype || 'image/jpeg';
                const extension = mime.includes('png') ? 'png' : mime.includes('webp') ? 'webp' : 'jpg';
                const carpetaDelNumero = path.join(CARPETA_FOTOS_BASE, numero);
                fs.mkdirSync(carpetaDelNumero, { recursive: true });
                const nombreArchivo = `${Date.now()}_${Math.random().toString(36).slice(2, 8)}.${extension}`;
                fs.writeFileSync(path.join(carpetaDelNumero, nombreArchivo), buffer);
                // Ruta relativa a la raíz de "sistema-web" (así la sirve el
                // servidor PHP embebido): whatsapp/fotos/<numero>/<archivo>
                fotoRuta = `whatsapp/fotos/${numero}/${nombreArchivo}`;
                console.log(`Fotografía de ${numero} guardada en ${fotoRuta}`);
              } catch (err) {
                // Si la descarga falla (ej. el medio ya expiró del lado de
                // WhatsApp), no se interrumpe la conversación: seguimos sin
                // el archivo, pero igual avisamos al sistema que SÍ llegó
                // una foto (tieneFoto), para que el oficio al menos lo
                // mencione en vez de quedar sin ningún rastro.
                console.error(`No se pudo descargar la fotografía de ${numero}:`, err.message);
              }
            }

            let texto =
              contenido.conversation ||
              contenido.extendedTextMessage?.text ||
              contenido.imageMessage?.caption ||
              contenido.videoMessage?.caption ||
              null;

            if (!texto) {
              if (tieneFoto) {
                // Foto sin descripción: no la descartamos. Le avisamos al
                // sistema con un texto "marcador" especial para que registre
                // que llegó una fotografía y sea el propio chat el que pida
                // la descripción directamente (ver recibir_bot.php).
                texto = '__FOTO_RECIBIDA__';
              } else {
                // Solo respondemos "enviá tu solicitud como texto" para el
                // resto de medios sin texto reconocidos (audio, video,
                // sticker, documento). Cualquier otro caso sin texto (por
                // ejemplo, un evento incompleto de sincronización, una
                // reacción, o un mensaje de protocolo) se ignora en
                // silencio en vez de responder algo confuso al ciudadano.
                const esMediaReconocidaSinTexto = !!(
                  contenido.audioMessage ||
                  contenido.videoMessage ||
                  contenido.stickerMessage ||
                  contenido.documentMessage
                );

                if (esMediaReconocidaSinTexto) {
                  // CORRECCIÓN: ya no se reinicia la sesión de cifrado acá
                  // aparte (ver comentario junto a reiniciarSesionPreventiva
                  // más arriba) — enviarYRegistrar ya lo hace, para
                  // cualquier número, antes de cada mensaje.
                  await enviarYRegistrar(jidDestino, {
                    text: obtenerMensajeBot(
                      'media_sin_texto',
                      'Por favor enviá tu solicitud como mensaje de texto para poder ayudarte.'
                    ),
                  }, numero);
                }
                continue;
              }
            }

            console.log(`Mensaje de ${numero}: ${texto}${tieneFoto ? ' (con fotografía adjunta)' : ''}`);

            // ---------- AUTO-RECUPERACIÓN: el ciudadano avisa que el mensaje no le llega ----------
            // Ver el bloque grande "AUTO-RECUPERACIÓN" al inicio del archivo. Si
            // el texto matchea (ej. "no puedo leer el mensaje", "no me llega el
            // mensaje", "sigo esperando el mensaje"), NO se trata como una
            // petición nueva: se reinicia la sesión de cifrado con este número,
            // se avisa brevemente "Disculpá las molestias, aquí tienes de nuevo tu mensaje:" y
            // luego se le reenvía el último mensaje que se le había mandado
            // (o, si no hay ninguno guardado, se le pide que cuente de nuevo
            // qué necesita). Se corta acá (no se le avisa a PHP), para no
            // alterar en nada la fase de la conversación en la que ya estaba.
            if (!tieneFoto && esQuejaMensajeAtascado(texto)) {
              console.log(`[Auto-recuperación] ${numero} avisó que el mensaje no le llega / no lo puede leer.`);
              // (El reinicio de sesión ya no se marca acá: enviarYRegistrar
              // lo hace SIEMPRE, para cualquier número, antes de cada mensaje.)

              // Guardamos el último texto ANTES de mandar la disculpa de
              // "Disculpá las molestias...", porque ese aviso también
              // queda registrado en ULTIMO_ENVIADO_POR_NUMERO (lo hace
              // cualquier mensaje que se manda con enviarYRegistrar) y
              // pisaría el dato que necesitamos reenviar.
              const ultimoTexto = ULTIMO_ENVIADO_POR_NUMERO.get(numero);

              await enviarYRegistrar(jidDestino, {
                text: 'Disculpa las molestias, aquí tienes de nuevo tu mensaje:',
              }, numero);

              if (ultimoTexto?.texto) {
                await enviarFragmentado(jidDestino, ultimoTexto.texto, numero);
                console.log(`[Auto-recuperación] Se reenvió a ${numero} el último mensaje que se le había mandado.`);
              } else {
                await enviarYRegistrar(jidDestino, {
                  text: 'Contame de nuevo qué necesitás.',
                }, numero);
                console.log(`[Auto-recuperación] No había ningún mensaje previo guardado para ${numero}; se le pidió reenviar su solicitud.`);
              }
              continue;
            }

            // ------------------ ACUMULAR PARA EL DEBOUNCE ------------------
            // En vez de reenviar esto a PHP ahora mismo, lo guardamos en la
            // cola pendiente de este número y (re)programamos el
            // temporizador. Si llega otro mensaje de este mismo número antes
            // de que se cumpla ese plazo, el temporizador anterior se
            // cancela y se vuelve a contar desde cero: solo se procesa todo
            // junto cuando pasa la ventana de espera sin mensajes nuevos.
            //
            // Esa ventana de espera YA NO es fija: se recalcula en cada
            // mensaje según la fase de la conversación (ver
            // calcularDebounceMs / debounceInicioMs /
            // DEBOUNCE_DELAY_RESPUESTA_MS más arriba) — 15s solo al abrir
            // una conversación nueva, prácticamente instantáneo cuando el
            // ciudadano está respondiendo algo puntual que el bot ya
            // preguntó (nombre, dirección/ubicación, etc.).
            const debounceMs = calcularDebounceMs(numero);

            if (!pendingMessages.has(numero)) {
              pendingMessages.set(numero, { mensajes: [], timeout: null, jidDestino });
            }
            const pendiente = pendingMessages.get(numero);
            // Actualizamos el jidDestino por si este mensaje puntual resolvió
            // un LID a número real (o viceversa) distinto al de mensajes
            // anteriores todavía en cola: siempre respondemos por el chat
            // más reciente conocido para este número.
            pendiente.jidDestino = jidDestino;
            pendiente.mensajes.push({ texto, tieneFoto, fotoRuta, timestampMs: timestampMsgMs });

            // Cualquier mensaje nuevo de este número (texto y/o foto)
            // reinicia el temporizador con la ventana que corresponda a su
            // fase actual, para que todo lo enviado dentro de esa ventana
            // termine junto en una sola petición.
            if (pendiente.timeout) {
              clearTimeout(pendiente.timeout);
            }
            pendiente.timeout = setTimeout(() => {
              procesarMensajesAcumulados(numero).catch((err) => {
                console.error(`Error procesando mensajes acumulados de ${numero}:`, err.message);
              });
            }, debounceMs);

            console.log(
              `[Debounce] Mensaje de ${numero} puesto en cola (${pendiente.mensajes.length} pendiente(s)); se procesará en ${debounceMs / 1000}s si no llega nada más.`
            );
          }
        } catch (err) {
          console.error('Error procesando mensaje entrante:', err.message);
        }
      }
    });
  } catch (err) {
    console.error('Error iniciando WhatsApp:', err.message);
    ultimoError = err.message;
    isStarting = false;
  }
}

/**
 * Reinicia la CONEXIÓN de WhatsApp sin desvincular el número: cierra el
 * socket actual (si existe) y vuelve a conectar usando las credenciales ya
 * guardadas en ./auth_info (NO borra nada, así que no hace falta escanear
 * el QR de nuevo).
 *
 * Para qué sirve: a veces la conexión con WhatsApp queda "trabada" (deja
 * de responder, o Baileys se queda pegado a medio conectar) sin que el
 * proceso de Node se haya caído del todo. Antes la única forma de
 * solucionarlo era cerrar la ventana de la consola y volver a correr
 * "npm start" / iniciar_bot.bat a mano. Este botón, expuesto desde el
 * panel admin (vistas/whatsapp_admin.php -> vincular_bot.php ->
 * POST /reiniciar_bot), hace lo mismo pero sin tocar el servidor:
 * desconecta y reconecta el socket dentro del mismo proceso.
 *
 * IMPORTANTE: esto NO puede levantar el proceso de Node si ya se cerró
 * por completo (ventana cerrada, PC apagada, proceso "matado", etc.) — en
 * ese caso el bot ni siquiera puede recibir este pedido, porque no hay
 * proceso corriendo que lo atienda. Para eso sigue haciendo falta volver
 * a ejecutar iniciar_bot.bat / iniciar_bot.sh manualmente.
 */
async function reiniciarConexionWhatsApp() {
  console.log('Reiniciando la conexión de WhatsApp por pedido del panel admin...');

  const socketAnterior = sock;

  // Se limpia el estado ANTES de cerrar el socket viejo para que, aunque
  // el cierre dispare su propio evento 'connection.update' (close), no
  // termine pisando el reinicio que ya estamos haciendo acá a propósito
  // (por eso también se le quitan los listeners al socket anterior).
  sock = null;
  isStarting = false;
  isConnected = false;
  lastQR = null;
  lastQRDataUrl = null;

  if (socketAnterior) {
    try {
      socketAnterior.ev.removeAllListeners();
    } catch (e) {
      // Si el socket ya estaba en un estado raro, no pasa nada por no
      // poder quitarle los listeners: igual lo vamos a descartar.
    }
    try {
      socketAnterior.end(new Error('Reinicio manual solicitado desde el panel admin'));
    } catch (e) {
      console.error('Error cerrando el socket anterior al reiniciar:', e.message);
    }
  }

  await iniciarWhatsApp();
}

/**
 * Cierra la sesión activa de WhatsApp y borra las credenciales guardadas
 * en ./auth_info, para que la próxima vez que se llame a iniciarWhatsApp()
 * se genere un QR totalmente nuevo (vinculación desde cero).
 */
async function desvincularWhatsApp() {
  try {
    if (sock) {
      try {
        await sock.logout();
      } catch (e) {
        // Si ya estaba desconectado, logout() puede fallar; lo ignoramos.
      }
    }
  } finally {
    isConnected = false;
    numeroVinculado = null;
    lidVinculado = null;
    lastQR = null;
    lastQRDataUrl = null;
    sock = null;
    isStarting = false;
    // Por seguridad, al desvincular se apaga también el modo prueba: no
    // tendría sentido dejarlo activo para una sesión que ya no existe.
    modoPruebaActivo = false;
    guardarModoPruebaEnDisco(false);

    limpiarCredenciales();
  }
}

// ---------- FIX puntual: sesión de cifrado "trabada" con un número (iPhone) ----------
// Baileys tiene un bug conocido y todavía sin resolver por sus mantenedores
// (ver https://github.com/WhiskeySockets/Baileys/issues/1744, 1701, 1767,
// 2115, 2169, entre otros duplicados) por el cual, con algunos destinatarios
// —predominantemente iPhone—, la sesión de cifrado Signal entre el bot y ESE
// número puntual queda corrupta ("Closing stale open session for new
// outgoing prekey bundle"). A partir de ahí, TODOS los mensajes que se le
// envíen a ese número (y solo a ese número) quedan pegados en "Esperando el
// mensaje. Esto puede tomar tiempo." del lado del ciudadano, sin importar
// cuántas veces se reintenten: el problema no es de contenido ni de la
// función getMessage (eso soluciona reintentos por cortes breves de red,
// no una sesión ya corrupta).
//
// La única mitigación práctica hoy (no hay fix definitivo del lado de
// Baileys) es borrar SOLO los archivos de sesión guardados para ese número
// dentro de auth_info/, para que la próxima vez que haya intercambio de
// mensajes con él, Baileys negocie una sesión de cifrado completamente
// nueva. Esto:
//   - NO afecta a ningún otro número/ciudadano.
//   - NO requiere volver a escanear el QR ni desvincular el número de la
//     municipalidad (eso es mucho más drástico y no hace falta para este
//     problema puntual).
//   - Se expone acá como endpoint para que el panel admin
//     (vistas/whatsapp_admin.php) pueda dispararlo con solo escribir el
//     número del ciudadano afectado, sin tener que tocar el servidor a mano.
function borrarSesionSignalDeNumero(numero) {
  const numeroLimpio = String(numero || '').replace(/\D/g, '');
  if (!numeroLimpio) return { borrados: 0, archivos: [] };
  if (!fs.existsSync(AUTH_DIR)) return { borrados: 0, archivos: [] };

  const archivos = fs.readdirSync(AUTH_DIR).filter((archivo) => {
    // Los archivos de sesión de cifrado por-contacto que genera Baileys
    // (useMultiFileAuthState) empiezan con "session-" y contienen el
    // número/JID del contacto en el nombre. A propósito NUNCA tocamos
    // "creds.json" (nuestra propia identidad vinculada) ni los archivos
    // "app-state-sync-*", "sender-key-*", "pre-key-*" (son propios del
    // bot, no de un contacto puntual, y borrarlos de más podría romper
    // la sincronización con OTROS chats).
    return archivo.startsWith('session-') && archivo.includes(numeroLimpio);
  });

  for (const archivo of archivos) {
    try {
      fs.rmSync(path.join(AUTH_DIR, archivo), { force: true });
    } catch (e) {
      console.error(`No se pudo borrar ${archivo}:`, e.message);
    }
  }

  return { borrados: archivos.length, archivos };
}

// ---------- Servidor HTTP para que PHP pueda pedirle envíos/estado/QR a este bot ----------
const app = express();
// Límite subido (el default de 100kb rompería /sync/import y /sync/import-foto).
app.use(express.json({ limit: '50mb' }));

// === Rutas de sincronización (/sync/ping, /sync/import, /sync/import-foto, /sync/export, /sync/request-handoff) ===
registrarRutasSync(app);

function validarSecreto(req, res) {
  const { secret } = req.body || {};
  if (secret !== BRIDGE_SECRET) {
    res.status(403).json({ ok: false, error: 'Secreto inválido' });
    return false;
  }
  return true;
}

app.post('/enviar', async (req, res) => {
  try {
    if (!validarSecreto(req, res)) return;
    const { numero, mensaje } = req.body;

    if (!isConnected) {
      return res.status(503).json({ ok: false, error: 'Bot de WhatsApp no está conectado todavía' });
    }
    if (!numero || !mensaje) {
      return res.status(400).json({ ok: false, error: 'Faltan campos numero/mensaje' });
    }

    const numeroLimpio = String(numero).replace(/\D/g, '');
    const jid = `${numeroLimpio}@s.whatsapp.net`;

    // El reinicio preventivo de sesión (si este número está en la lista de
    // problemáticos) ya lo hace enviarYRegistrar internamente; no hace
    // falta pedirlo acá también.
    await enviarYRegistrar(jid, { text: mensaje }, numeroLimpio);

    return res.json({ ok: true });
  } catch (err) {
    console.error('Error en /enviar:', err.message);
    return res.status(500).json({ ok: false, error: err.message });
  }
});

// Estado de conexión: de solo lectura, no requiere secreto para poder
// pollearlo cómodamente desde el panel admin vía el proxy PHP.
app.get('/estado', (req, res) => {
  res.json({
    conectado: isConnected,
    numero: numeroVinculado,
    lid: lidVinculado, // identificador alterno que WhatsApp usa a veces para el chat "Tú" (ver comentario "BUGFIX" en la parte de arriba del archivo)
    tieneQrPendiente: !!lastQR,
    ultimoError,
    modoPrueba: modoPruebaActivo,
    emojisActivos: emojisActivos,
    velocidadEscritura: ritmoCaracteresPorSegundo,
    velocidadEscrituraMinima: VELOCIDAD_ESCRITURA_MINIMA,
    velocidadEscrituraMaxima: VELOCIDAD_ESCRITURA_MAXIMA,
    pausaEntreBurbujas: pausaEntreFragmentosSegundos,
    pausaEntreBurbujasMinima: PAUSA_ENTRE_FRAGMENTOS_MINIMA,
    pausaEntreBurbujasMaxima: PAUSA_ENTRE_FRAGMENTOS_MAXIMA,
    debounceInicioMs: debounceInicioMs,
    debounceInicioMin: DEBOUNCE_INICIO_MIN_MS,
    debounceInicioMax: DEBOUNCE_INICIO_MAX_MS,
    numerosExcluidos: Array.from(NUMEROS_EXCLUIDOS).sort(),
  });
});

// Devuelve el QR pendiente como imagen (data URL) para mostrarlo en el navegador.
app.get('/qr', (req, res) => {
  res.json({
    conectado: isConnected,
    numero: numeroVinculado,
    qr: isConnected ? null : lastQRDataUrl,
    generadoHace: lastQRAt ? Date.now() - lastQRAt : null,
  });
});

// El admin pide vincular (arrancar sesión / generar QR nuevo)
app.post('/vincular', async (req, res) => {
  if (!validarSecreto(req, res)) return;
  if (isConnected) {
    return res.json({ ok: true, mensaje: 'Ya hay una sesión de WhatsApp conectada.' });
  }
  iniciarWhatsApp();
  return res.json({ ok: true, mensaje: 'Iniciando vinculación, esperá el código QR.' });
});

// El admin pide desvincular (logout + borrar credenciales)
app.post('/desvincular', async (req, res) => {
  if (!validarSecreto(req, res)) return;
  await desvincularWhatsApp();
  return res.json({ ok: true, mensaje: 'WhatsApp desvinculado correctamente.' });
});

// El admin pide reiniciar la CONEXIÓN de WhatsApp (sin desvincular el
// número) porque quedó "trabada" o dejó de responder. Ver comentario
// grande de reiniciarConexionWhatsApp() más arriba.
app.post('/reiniciar_bot', async (req, res) => {
  if (!validarSecreto(req, res)) return;
  try {
    await reiniciarConexionWhatsApp();
    return res.json({ ok: true, mensaje: 'Reiniciando la conexión de WhatsApp, esperá unos segundos...' });
  } catch (err) {
    console.error('Error en /reiniciar_bot:', err.message);
    return res.status(500).json({ ok: false, error: err.message });
  }
});

// El admin activa/desactiva el "modo prueba" (ver comentario grande al
// inicio del archivo). Body esperado: { secret, accion: 'activar'|'desactivar' }
app.post('/modo_prueba', (req, res) => {
  if (!validarSecreto(req, res)) return;
  const { accion } = req.body || {};

  if (accion !== 'activar' && accion !== 'desactivar') {
    return res.status(400).json({ ok: false, error: 'Acción inválida. Use "activar" o "desactivar".' });
  }

  modoPruebaActivo = accion === 'activar';
  guardarModoPruebaEnDisco(modoPruebaActivo);
  console.log(
    modoPruebaActivo
      ? 'Modo prueba ACTIVADO: el chat propio ("Tú") será tratado como un ciudadano más.'
      : 'Modo prueba DESACTIVADO.'
  );

  return res.json({ ok: true, modoPrueba: modoPruebaActivo });
});

// El admin activa/desactiva el uso de emojis en las respuestas (pruebas de
// compatibilidad). Body esperado: { secret, accion: 'activar'|'desactivar' }
app.post('/emojis', (req, res) => {
  if (!validarSecreto(req, res)) return;
  const { accion } = req.body || {};

  if (accion !== 'activar' && accion !== 'desactivar') {
    return res.status(400).json({ ok: false, error: 'Acción inválida. Use "activar" o "desactivar".' });
  }

  emojisActivos = accion === 'activar';
  guardarEmojisEnDisco(emojisActivos);
  console.log(`Uso de emojis ${emojisActivos ? 'ACTIVADO' : 'DESACTIVADO'} desde el panel admin.`);

  return res.json({ ok: true, emojisActivos });
});

// El admin ajusta la velocidad de escritura (caracteres por segundo que se
// simulan antes de enviar cada respuesta), para poder hacer pruebas.
// Body esperado: { secret, caracteresPorSegundo: number }
app.post('/velocidad_escritura', (req, res) => {
  if (!validarSecreto(req, res)) return;
  const { caracteresPorSegundo } = req.body || {};
  const valor = Number(caracteresPorSegundo);

  if (
    !Number.isFinite(valor) ||
    valor < VELOCIDAD_ESCRITURA_MINIMA ||
    valor > VELOCIDAD_ESCRITURA_MAXIMA
  ) {
    return res.status(400).json({
      ok: false,
      error: `caracteresPorSegundo debe ser un número entre ${VELOCIDAD_ESCRITURA_MINIMA} y ${VELOCIDAD_ESCRITURA_MAXIMA}.`,
    });
  }

  ritmoCaracteresPorSegundo = valor;
  guardarVelocidadEscrituraEnDisco(ritmoCaracteresPorSegundo);
  console.log(`Velocidad de escritura cambiada a ${ritmoCaracteresPorSegundo} caracteres/segundo desde el panel admin.`);

  return res.json({ ok: true, velocidadEscritura: ritmoCaracteresPorSegundo });
});

// El admin ajusta la pausa entre burbujas (segundos que se esperan entre
// una parte y la siguiente cuando una respuesta se manda fragmentada en
// varios mensajes, ver "Fragmentación de respuestas en varias burbujas
// consecutivas" más arriba), para poder hacer pruebas.
// Body esperado: { secret, segundos: number }
app.post('/pausa_entre_burbujas', (req, res) => {
  if (!validarSecreto(req, res)) return;
  const { segundos } = req.body || {};
  const valor = Number(segundos);

  if (
    !Number.isFinite(valor) ||
    valor < PAUSA_ENTRE_FRAGMENTOS_MINIMA ||
    valor > PAUSA_ENTRE_FRAGMENTOS_MAXIMA
  ) {
    return res.status(400).json({
      ok: false,
      error: `segundos debe ser un número entre ${PAUSA_ENTRE_FRAGMENTOS_MINIMA} y ${PAUSA_ENTRE_FRAGMENTOS_MAXIMA}.`,
    });
  }

  pausaEntreFragmentosSegundos = valor;
  guardarPausaEntreFragmentosEnDisco(pausaEntreFragmentosSegundos);
  console.log(`Pausa entre burbujas cambiada a ${pausaEntreFragmentosSegundos} segundos desde el panel admin.`);

  return res.json({ ok: true, pausaEntreBurbujas: pausaEntreFragmentosSegundos });
});

// El admin ajusta la espera al recibir el PRIMER mensaje de una conversación
// nueva (debounce de la fase 'inicio'). Body esperado: { secret, segundos }.
app.post('/debounce_inicio', (req, res) => {
  if (!validarSecreto(req, res)) return;
  const { segundos } = req.body || {};
  const valorSegundos = Number(segundos);

  if (!Number.isFinite(valorSegundos)) {
    return res.status(400).json({ ok: false, error: 'Falta el campo segundos (número).' });
  }
  const valorMs = Math.round(valorSegundos * 1000);
  if (valorMs < DEBOUNCE_INICIO_MIN_MS || valorMs > DEBOUNCE_INICIO_MAX_MS) {
    return res.status(400).json({
      ok: false,
      error: `segundos debe estar entre ${DEBOUNCE_INICIO_MIN_MS / 1000} y ${DEBOUNCE_INICIO_MAX_MS / 1000}.`
    });
  }

  debounceInicioMs = valorMs;
  guardarDebounceInicioEnDisco(debounceInicioMs);
  console.log(`Espera al iniciar conversación cambiada a ${debounceInicioMs} ms desde el panel admin.`);

  return res.json({ ok: true, debounceInicioMs });
});

// El admin pide reiniciar la sesión de cifrado con UN número puntual (ver
// comentario grande de borrarSesionSignalDeNumero más arriba). Body
// esperado: { secret, numero }.
app.post('/reiniciar_sesion', (req, res) => {
  if (!validarSecreto(req, res)) return;
  const { numero } = req.body || {};

  if (!numero || !String(numero).replace(/\D/g, '')) {
    return res.status(400).json({ ok: false, error: 'Falta el campo numero (o no tiene dígitos válidos).' });
  }

  const resultado = borrarSesionSignalDeNumero(numero);

  if (resultado.borrados === 0) {
    return res.json({
      ok: true,
      borrados: 0,
      mensaje: 'No había ningún archivo de sesión guardado para ese número (puede que nunca se haya llegado a establecer, o ya esté limpia). De todos modos, pedile al ciudadano que envíe un mensaje nuevo para intentar de cero.',
    });
  }

  console.log(`Sesión de cifrado reiniciada a pedido del admin para el número ${numero} (${resultado.borrados} archivo(s) borrados).`);

  return res.json({
    ok: true,
    borrados: resultado.borrados,
    mensaje: `Se borró la sesión cifrada guardada con ese número (${resultado.borrados} archivo(s)). La próxima vez que el bot le escriba, WhatsApp va a negociar una sesión nueva desde cero.`,
  });
});

// El admin agrega un número a la lista de "excluidos" (ver NUMEROS_EXCLUIDOS
// más arriba): a partir de este momento el bot ignora por completo
// cualquier mensaje que llegue de ese número, sin responderle nada. Body
// esperado: { secret, numero }.
app.post('/numeros_excluidos/agregar', (req, res) => {
  if (!validarSecreto(req, res)) return;
  const numeroLimpio = String(req.body?.numero || '').replace(/\D/g, '');

  if (!numeroLimpio) {
    return res.status(400).json({ ok: false, error: 'Falta el campo numero (o no tiene dígitos válidos).' });
  }

  const yaEstaba = NUMEROS_EXCLUIDOS.has(numeroLimpio);
  NUMEROS_EXCLUIDOS.add(numeroLimpio);
  guardarNumerosExcluidosEnDisco(NUMEROS_EXCLUIDOS);
  console.log(
    yaEstaba
      ? `El admin volvió a agregar a ${numeroLimpio} a la lista de números excluidos (ya estaba).`
      : `${numeroLimpio} se agregó a la lista de números excluidos: el bot ya no le responderá automáticamente.`
  );

  return res.json({
    ok: true,
    agregado: !yaEstaba,
    numerosExcluidos: Array.from(NUMEROS_EXCLUIDOS).sort(),
  });
});

// El admin quita un número de la lista de "excluidos": vuelve a
// comportarse como un ciudadano cualquiera y el bot le responde de nuevo.
// Body esperado: { secret, numero }.
app.post('/numeros_excluidos/quitar', (req, res) => {
  if (!validarSecreto(req, res)) return;
  const numeroLimpio = String(req.body?.numero || '').replace(/\D/g, '');

  if (!numeroLimpio) {
    return res.status(400).json({ ok: false, error: 'Falta el campo numero (o no tiene dígitos válidos).' });
  }

  const existia = NUMEROS_EXCLUIDOS.delete(numeroLimpio);
  guardarNumerosExcluidosEnDisco(NUMEROS_EXCLUIDOS);
  console.log(
    existia
      ? `${numeroLimpio} se quitó de la lista de números excluidos a pedido del admin: el bot vuelve a responderle.`
      : `El admin pidió quitar a ${numeroLimpio} de la lista de números excluidos, pero no estaba en ella.`
  );

  return res.json({
    ok: true,
    quitado: existia,
    numerosExcluidos: Array.from(NUMEROS_EXCLUIDOS).sort(),
  });
});

// NUEVO: pide al teléfono mensajes más antiguos de cada chat ya archivado.
// Los mensajes llegan por el evento 'messaging-history.set' y se archivan solos.
// Body esperado: { secret, cantidad (1-50, opcional) }
let profundizandoHistorial = false;
app.post('/historial/profundizar', async (req, res) => {
  if (!validarSecreto(req, res)) return;
  if (!isConnected) {
    return res.status(503).json({ ok: false, error: 'Bot de WhatsApp no está conectado todavía' });
  }
  if (profundizandoHistorial) {
    return res.json({ ok: true, solicitados: 0, mensaje: 'Ya hay una búsqueda en curso; esperá a que termine.' });
  }
  const cantidad = Math.min(50, Math.max(1, parseInt(req.body?.cantidad, 10) || 50));
  const chats = archivoChats.masAntiguoPorChat();
  if (chats.length === 0) {
    return res.json({ ok: true, solicitados: 0, mensaje: 'Aún no hay chats archivados desde donde pedir mensajes anteriores.' });
  }
  profundizandoHistorial = true;
  res.json({
    ok: true,
    solicitados: chats.length,
    mensaje: `Pidiendo mensajes anteriores de ${chats.length} chat(s) en segundo plano. Mantené el teléfono con internet y revisá el resumen en 1-2 minutos.`,
  });
  (async () => {
    for (const c of chats) {
      try {
        await sock.fetchMessageHistory(cantidad, c.key, c.ts);
      } catch (e) {
        console.error(`[Historial] fetchMessageHistory falló para ${c.archivo}: ${e.message}`);
      }
      await new Promise((r) => setTimeout(r, 2500));
    }
    profundizandoHistorial = false;
    console.log('[Historial] Solicitudes de historial enviadas a todos los chats archivados.');
  })();
});

// NUEVO: exportación completa del historial. Igual que /historial/profundizar
// pero con más mensajes por chat (hasta 200) y con un endpoint de progreso
// (/historial/exportar_estado) que consulta sistema-web/exportar_todos_chats.php --fetch.
// Los mensajes llegan por 'messaging-history.set' y se archivan solos.
// Body esperado: { secret, porChat (10-200, opcional, def. 50) }
let exportandoHistorial = false;
let exportandoEstado = { activo: false, total: 0, enviadas: 0, inicio: null, fin: null };

app.post('/historial/exportar_completo', async (req, res) => {
  if (!validarSecreto(req, res)) return;
  if (!isConnected) {
    return res.status(503).json({ ok: false, error: 'Bot de WhatsApp no está conectado todavía.' });
  }
  if (exportandoHistorial || profundizandoHistorial) {
    return res.status(429).json({ ok: false, error: 'Ya hay una búsqueda de historial en curso. Espere a que termine.' });
  }
  const porChat = Math.min(200, Math.max(10, parseInt(req.body?.porChat, 10) || 50));
  const chats = archivoChats.masAntiguoPorChat();
  if (chats.length === 0) {
    return res.json({ ok: true, total: 0, mensaje: 'Todavía no hay contactos con mensajes archivados.' });
  }
  // Se comparte el candado con /historial/profundizar para que no corran a la vez.
  exportandoHistorial = true;
  profundizandoHistorial = true;
  exportandoEstado = { activo: true, total: chats.length, enviadas: 0, inicio: Date.now(), fin: null };
  res.json({
    ok: true,
    total: chats.length,
    mensaje: `Solicitando historial de ${chats.length} contacto(s) a WhatsApp.`,
  });
  (async () => {
    try {
      for (const c of chats) {
        try {
          await sock.fetchMessageHistory(porChat, c.key, c.ts);
          exportandoEstado.enviadas++;
        } catch (e) {
          console.error(`[Exportar] fetchMessageHistory falló para ${c.archivo}: ${e.message}`);
        }
        await new Promise((r) => setTimeout(r, 700));
      }
      // Margen: WhatsApp puede tardar en entregar los últimos lotes.
      await new Promise((r) => setTimeout(r, 60000));
    } finally {
      exportandoHistorial = false;
      profundizandoHistorial = false;
      exportandoEstado.activo = false;
      exportandoEstado.fin = Date.now();
      console.log(`[Exportar] Completado. ${exportandoEstado.enviadas}/${chats.length} chat(s) solicitados.`);
    }
  })();
});

// GET sin cuerpo: validarSecreto() lee el secreto del body, así que (igual que
// /estado) este endpoint de solo lectura no lo exige. Solo expone contadores.
app.get('/historial/exportar_estado', (req, res) => {
  res.json({
    activo: exportandoHistorial,
    total: exportandoEstado.total,
    enviadas: exportandoEstado.enviadas,
    inicio: exportandoEstado.inicio,
    fin: exportandoEstado.fin,
    transcurrido_ms: exportandoEstado.inicio ? (Date.now() - exportandoEstado.inicio) : 0,
  });
});

app.listen(PORT, () => {
  console.log(`API del puente escuchando en http://localhost:${PORT}`);
});

// === Arranca el ciclo de failover / sincronización ===
iniciarCicloSync();

// NUEVO: datos que necesita el archivo de chats para resolver LID -> teléfono y omitir el chat propio.
archivoChats.configurar({
  numeroPorLid: (lid) => LID_A_NUMERO_REAL.get(lid)?.numero || null,
  esChatPropio: (jid, numero) => {
    const lid = jid.endsWith('@lid') ? jid.split('@')[0] : null;
    return (!!numeroVinculado && numero === numeroVinculado) || (!!lid && lid === obtenerLidPropio());
  },
});

iniciarWhatsApp();