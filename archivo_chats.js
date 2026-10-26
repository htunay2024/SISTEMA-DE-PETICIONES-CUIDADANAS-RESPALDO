/**
 * whatsapp-bot/archivo_chats.js   (ARCHIVO NUEVO)
 *
 * Archivo PERMANENTE de los mensajes de los chats 1 a 1 de WhatsApp.
 * Guarda una línea JSON por mensaje en:
 *   sistema-web/whatsapp/historial_chats/<numero>.jsonl
 * A diferencia de whatsapp/sesiones/conversaciones/*.jsonl, estos archivos
 * NUNCA se borran cuando una solicitud se convierte en ticket.
 *
 * Línea: { id, ts (segundos UNIX), me (1 = lo envió la municipalidad),
 *          jid, tipo, texto, nombre, origen }
 */
const fs = require('fs');
const path = require('path');

const CARPETA_HISTORIAL = path.join(__dirname, '..', 'sistema-web', 'whatsapp', 'historial_chats');
const AUTH_DIR = path.join(__dirname, 'auth_info');
fs.mkdirSync(CARPETA_HISTORIAL, { recursive: true });

let cfg = { numeroPorLid: () => null, esChatPropio: () => false };
function configurar(opciones) { cfg = { ...cfg, ...opciones }; }

const idsPorArchivo = new Map(); // nombre de archivo -> Set de ids ya guardados

function aNumero(v) {
  if (v === null || v === undefined) return 0;
  if (typeof v === 'number') return v;
  if (typeof v.toNumber === 'function') return v.toNumber(); // Long de protobuf
  return Number(v) || 0;
}
function soloDigitos(jid) {
  return String(jid || '').split(/[:@]/)[0].replace(/\D/g, '');
}

// Respaldo: Baileys guarda en auth_info/lid-mapping-<lid>_reverse.json el
// teléfono real de cada LID que ha visto.
function numeroDesdeArchivoLid(lid) {
  try {
    const ruta = path.join(AUTH_DIR, `lid-mapping-${lid}_reverse.json`);
    if (fs.existsSync(ruta)) {
      const d = soloDigitos(JSON.parse(fs.readFileSync(ruta, 'utf8')));
      if (d) return d;
    }
  } catch (_) {}
  return null;
}

/** Devuelve el teléfono real, o "lid-<lid>" si todavía no se puede resolver. */
function resolverNumero(key) {
  const jid = key.remoteJid || '';
  if (jid.endsWith('@s.whatsapp.net')) return soloDigitos(jid);
  if (!jid.endsWith('@lid')) return null;
  const lid = soloDigitos(jid);
  const alt = key.senderPn || key.remoteJidAlt;
  if (alt && !String(alt).endsWith('@lid')) {
    const d = soloDigitos(alt);
    if (d) return d;
  }
  return cfg.numeroPorLid(lid) || numeroDesdeArchivoLid(lid) || `lid-${lid}`;
}

function desenvolver(m) {
  let c = m;
  for (let i = 0; i < 4 && c; i++) {
    const interno = c.ephemeralMessage?.message || c.viewOnceMessage?.message ||
      c.viewOnceMessageV2?.message || c.documentWithCaptionMessage?.message || c.editedMessage?.message;
    if (!interno) break;
    c = interno;
  }
  return c;
}

function extraerContenido(msg) {
  const c = desenvolver(msg.message);
  if (!c) return null;
  if (c.protocolMessage || c.reactionMessage || c.pollUpdateMessage) return null;
  if (c.conversation) return { tipo: 'texto', texto: c.conversation };
  if (c.extendedTextMessage?.text) return { tipo: 'texto', texto: c.extendedTextMessage.text };
  if (c.imageMessage) return { tipo: 'imagen', texto: c.imageMessage.caption || '' };
  if (c.videoMessage) return { tipo: 'video', texto: c.videoMessage.caption || '' };
  if (c.audioMessage) return { tipo: 'audio', texto: '' };
  if (c.documentMessage) return { tipo: 'documento', texto: c.documentMessage.caption || c.documentMessage.fileName || '' };
  if (c.locationMessage) {
    const l = c.locationMessage;
    return { tipo: 'ubicacion', texto: `Ubicación: ${l.degreesLatitude}, ${l.degreesLongitude}` };
  }
  return null;
}

/**
 * Guarda un mensaje (sin duplicar). Devuelve { numero, linea } si era nuevo,
 * o null si se ignoró (grupo, estado, sin contenido, chat propio, repetido).
 */
function archivar(msg, origen = 'vivo') {
  try {
    const key = msg?.key;
    if (!key?.remoteJid || !key.id) return null;
    const jid = key.remoteJid;
    if (!jid.endsWith('@s.whatsapp.net') && !jid.endsWith('@lid')) return null; // solo chats 1 a 1
    const contenido = extraerContenido(msg);
    if (!contenido) return null;
    const numero = resolverNumero(key);
    if (!numero || cfg.esChatPropio(jid, numero)) return null;

    const nombreArchivo = `${numero}.jsonl`;
    const ruta = path.join(CARPETA_HISTORIAL, nombreArchivo);
    let ids = idsPorArchivo.get(nombreArchivo);
    if (!ids) {
      ids = new Set();
      if (fs.existsSync(ruta)) {
        for (const l of fs.readFileSync(ruta, 'utf8').split('\n')) {
          if (!l) continue;
          try { ids.add(JSON.parse(l).id); } catch (_) {}
        }
      }
      idsPorArchivo.set(nombreArchivo, ids);
    }
    if (ids.has(key.id)) return null;
    ids.add(key.id);

    const linea = {
      id: key.id,
      ts: aNumero(msg.messageTimestamp) || Math.floor(Date.now() / 1000),
      me: key.fromMe ? 1 : 0,
      jid,
      tipo: contenido.tipo,
      texto: contenido.texto,
      nombre: key.fromMe ? '' : (msg.pushName || ''),
      origen,
    };
    fs.appendFileSync(ruta, JSON.stringify(linea) + '\n');
    return { numero, linea };
  } catch (e) {
    console.error('[Historial] No se pudo archivar un mensaje:', e.message);
    return null;
  }
}

/** Mensaje más antiguo conocido de cada chat (ancla para pedir historial anterior). */
function masAntiguoPorChat() {
  const salida = [];
  for (const f of fs.readdirSync(CARPETA_HISTORIAL)) {
    if (!f.endsWith('.jsonl')) continue;
    let min = null;
    for (const l of fs.readFileSync(path.join(CARPETA_HISTORIAL, f), 'utf8').split('\n')) {
      if (!l) continue;
      try {
        const o = JSON.parse(l);
        if (o.id && o.jid && (!min || o.ts < min.ts)) min = o;
      } catch (_) {}
    }
    if (min) salida.push({ archivo: f, key: { remoteJid: min.jid, id: min.id, fromMe: !!min.me }, ts: min.ts });
  }
  return salida;
}

module.exports = { configurar, archivar, masAntiguoPorChat, CARPETA_HISTORIAL };
