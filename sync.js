// ============================================================================
// sync.js - Sincronización PC1 (Encargada, primario) <-> PC2 (Respaldo)
//
// Reglas:
//   • PC1 activa: Lunes a Viernes, 08:00–15:59
//   • PC2 activa: Todos los días, 06:00–23:59
//   • Fuera de ambos horarios (00:00–05:59): nadie procesa
//   • PC1 tiene prioridad: si está activa y alcanzable, PC2 pausa
//   • Si PC1 está caída o pausada, PC2 activa (dentro de su horario)
//   • Al activarse PC1, pide "handoff" a PC2: PC2 pausa, empuja todos sus
//     datos + fotos a PC1, y luego PC1 queda activa.
// ============================================================================

const fs = require('fs');
const path = require('path');
const axios = require('axios');

// -------- Rutas --------
const SYNC_CONFIG_FILE = path.join(__dirname, 'sync_config.json');
const PAUSADO_FILE     = path.join(__dirname, 'pausado.json');
const SISTEMA_WEB      = path.join(__dirname, '..', 'sistema-web');
const TICKETS_FILE     = path.join(SISTEMA_WEB, 'tickets', 'tickets.json');
const CORRELATIVO_FILE = path.join(SISTEMA_WEB, 'data', 'correlativo.json');
const CORRELATIVO_SOL  = path.join(SISTEMA_WEB, 'data', 'correlativo_solicitud.json');
const CORRELATIVO_OFI  = path.join(SISTEMA_WEB, 'data', 'correlativos_oficinas.json');
const FOTOS_DIR        = path.join(SISTEMA_WEB, 'whatsapp', 'fotos');

// -------- Config por defecto --------
let config = {
  rol: 'primario',
  peer_ip: '',
  peer_port: 3001,
  secreto: '',
  intervalo_ping_seg: 20,
  fallos_para_activar: 4,
};

// -------- Estado en memoria --------
let pausado = true;
let fallosConsecutivos = 0;
let intervaloHandler = null;
let handoffEnCurso = false;

// -------- Helpers JSON --------
function leerJson(archivo, def) {
  try {
    if (!fs.existsSync(archivo)) return def;
    const data = JSON.parse(fs.readFileSync(archivo, 'utf8'));
    return data === null ? def : data;
  } catch (_) { return def; }
}
function escribirJson(archivo, data) {
  const dir = path.dirname(archivo);
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
  fs.writeFileSync(archivo, JSON.stringify(data, null, 2));
}
function cargarConfig() {
  const c = leerJson(SYNC_CONFIG_FILE, null);
  if (!c) {
    console.warn('[Sync] No existe sync_config.json. Se asume primario sin peer.');
    return;
  }
  config = { ...config, ...c };
  console.log(`[Sync] Config: rol=${config.rol}, peer=${config.peer_ip}:${config.peer_port}`);
}

// -------- Horarios --------
function dentroDeHorarioPC1() {
  const d = new Date();
  const dia = d.getDay();          // 0=dom, 6=sáb
  if (dia === 0 || dia === 6) return false;
  const h = d.getHours();          // 0-23
  return h >= 8 && h < 16;         // 08:00–15:59
}
function dentroDeHorarioPC2() {
  const h = new Date().getHours();
  return h >= 6 && h <= 23;        // 06:00–23:59
}
function dentroDeAlgunHorario() {
  return dentroDeHorarioPC1() || dentroDeHorarioPC2();
}

// -------- Pausa --------
function estaPausado() { return pausado; }
function setPausado(v, razon = '') {
  if (pausado === v) return;
  pausado = v;
  escribirJson(PAUSADO_FILE, { pausado: v, razon, ts: Date.now(), rol: config.rol });
  console.log(`[Sync] pausado=${v}${razon ? ' (' + razon + ')' : ''}`);
}

// -------- Fotos --------
function listarFotos() {
  const res = [];
  if (!fs.existsSync(FOTOS_DIR)) return res;
  const walk = (dir, base) => {
    for (const item of fs.readdirSync(dir)) {
      const p = path.join(dir, item);
      const rel = base ? base + '/' + item : item;
      if (fs.statSync(p).isDirectory()) walk(p, rel);
      else res.push(rel);
    }
  };
  walk(FOTOS_DIR, '');
  return res;
}
function rutaFotoAbs(rel) {
  const limpia = String(rel).replace(/\\/g, '/').replace(/\.\./g, '');
  return path.join(FOTOS_DIR, limpia);
}

// -------- Exportar / Importar --------
function exportarDatos() {
  return {
    tickets: leerJson(TICKETS_FILE, []),
    correlativo: (leerJson(CORRELATIVO_FILE, { proximo_id: 1 }).proximo_id) || 1,
    correlativo_solicitud: (leerJson(CORRELATIVO_SOL, { proximo_numero: 1 }).proximo_numero) || 1,
    correlativos_oficinas: leerJson(CORRELATIVO_OFI, []),
    fotos: listarFotos(),
  };
}

function importarDatos(body) {
  const {
    tickets: peerTickets = [],
    correlativo: peerCorr = 1,
    correlativo_solicitud: peerSol = 1,
    correlativos_oficinas: peerOfi = [],
  } = body || {};

  // Tickets: merge por id (gana el más reciente por fecha_actualizacion).
  const local = leerJson(TICKETS_FILE, []);
  const byId = new Map(local.map(t => [String(t.id), t]));
  let importados = 0, actualizados = 0, omitidos = 0;
  for (const t of peerTickets) {
    if (!t || t.id === undefined) continue;
    const k = String(t.id);
    if (!byId.has(k)) {
      byId.set(k, t); importados++;
    } else {
      const ex = byId.get(k);
      const fEx = String(ex.fecha_actualizacion || ex.fecha_creacion || '');
      const fNew = String(t.fecha_actualizacion || t.fecha_creacion || '');
      if (fNew > fEx) { byId.set(k, t); actualizados++; }
      else omitidos++;
    }
  }
  const merged = Array.from(byId.values())
    .sort((a, b) => (parseInt(a.id) || 0) - (parseInt(b.id) || 0));
  escribirJson(TICKETS_FILE, merged);

  // Correlativos: siempre al menos maxId+1.
  const maxId = merged.reduce((m, t) => Math.max(m, parseInt(t.id) || 0), 0);
  const nuevaCorr = Math.max(
    parseInt(peerCorr) || 1,
    (leerJson(CORRELATIVO_FILE, { proximo_id: 1 }).proximo_id) || 1,
    maxId + 1
  );
  escribirJson(CORRELATIVO_FILE, { proximo_id: nuevaCorr });

  const nuevaSol = Math.max(
    parseInt(peerSol) || 1,
    (leerJson(CORRELATIVO_SOL, { proximo_numero: 1 }).proximo_numero) || 1
  );
  escribirJson(CORRELATIVO_SOL, { proximo_numero: nuevaSol });

  // Correlativos por oficina: merge por id, mayor proximo_numero.
  if (Array.isArray(peerOfi) && peerOfi.length) {
    const locales = leerJson(CORRELATIVO_OFI, []);
    const byOfi = new Map(locales.map(o => [String(o.id), o]));
    for (const o of peerOfi) {
      if (!o || o.id === undefined) continue;
      const k = String(o.id);
      if (!byOfi.has(k)) byOfi.set(k, o);
      else {
        const ex = byOfi.get(k);
        ex.proximo_numero = Math.max(
          parseInt(ex.proximo_numero) || 1,
          parseInt(o.proximo_numero) || 1
        );
      }
    }
    escribirJson(CORRELATIVO_OFI, Array.from(byOfi.values()));
  }

  return {
    importados, actualizados, omitidos,
    correlativo: nuevaCorr,
    correlativo_solicitud: nuevaSol,
    fotos_existentes: listarFotos(),
  };
}

// -------- Ping --------
async function pingPeer() {
  if (!config.peer_ip) return null;
  try {
    const r = await axios.get(
      `http://${config.peer_ip}:${config.peer_port}/sync/ping`,
      { timeout: 5000 }
    );
    return r.data;
  } catch (_) { return null; }
}

// -------- Push PC2 -> PC1 (import + fotos) --------
async function enviarDatosAlPeer() {
  if (!config.peer_ip) throw new Error('Sin peer_ip configurada');
  const data = exportarDatos();
  const base = `http://${config.peer_ip}:${config.peer_port}`;

  const r = await axios.post(
    `${base}/sync/import`,
    { secreto: config.secreto, ...data },
    { timeout: 300000, maxBodyLength: Infinity, maxContentLength: Infinity }
  );

  const yaExisten = new Set(r.data.fotos_existentes || []);
  let enviadas = 0, fallidas = 0;
  for (const rel of data.fotos) {
    if (yaExisten.has(rel)) continue;
    const abs = rutaFotoAbs(rel);
    if (!fs.existsSync(abs)) continue;
    try {
      const b64 = fs.readFileSync(abs).toString('base64');
      await axios.post(
        `${base}/sync/import-foto`,
        { secreto: config.secreto, ruta: rel, data_b64: b64 },
        { timeout: 90000, maxBodyLength: Infinity, maxContentLength: Infinity }
      );
      enviadas++;
    } catch (e) {
      console.error(`[Sync] Error subiendo foto ${rel}: ${e.message}`);
      fallidas++;
    }
  }
  return { ...r.data, fotos_enviadas: enviadas, fotos_fallidas: fallidas };
}

// -------- Ciclo PC1 (primario) --------
async function cicloPrimario() {
  if (!dentroDeHorarioPC1()) {
    setPausado(true, 'fuera de horario PC1 (L-V 08-16)');
    return;
  }
  if (!pausado) return;              // ya está activa
  if (handoffEnCurso) return;        // evitar doble handoff

  handoffEnCurso = true;
  try {
    console.log('[Sync] Solicitando handoff a PC2 (recibir datos pendientes)…');
    try {
      const r = await axios.post(
        `http://${config.peer_ip}:${config.peer_port}/sync/request-handoff`,
        { secreto: config.secreto },
        { timeout: 300000 }
      );
      console.log(`[Sync] Handoff OK. PC2 empujó: importados=${r.data.importados || 0}, actualizados=${r.data.actualizados || 0}, fotos=${r.data.fotos_enviadas || 0}`);
    } catch (e) {
      console.warn('[Sync] Handoff a PC2 falló:', e.message, '— PC1 se activará igual.');
    }
    setPausado(false, 'PC1 activa (L-V 08-16)');
  } finally {
    handoffEnCurso = false;
  }
}

// -------- Ciclo PC2 (respaldo) --------
async function cicloRespaldo() {
  if (!dentroDeHorarioPC2()) {
    setPausado(true, 'fuera de horario PC2 (00:00-05:59)');
    return;
  }

  const peer = await pingPeer();

  if (peer && peer.pausado === false) {
    fallosConsecutivos = 0;
    if (!pausado) {
      setPausado(true, 'PC1 activa');
      try {
        const r = await enviarDatosAlPeer();
        console.log(`[Sync] Push de respaldo a PC1: importados=${r.importados}, actualizados=${r.actualizados}, fotos=${r.fotos_enviadas}`);
      } catch (e) {
        console.error('[Sync] Push de respaldo a PC1 falló:', e.message);
      }
    }
    return;
  }

  // PC1 no responde
  if (!peer) {
    fallosConsecutivos++;
    console.log(`[Sync] PC1 no responde (${fallosConsecutivos}/${config.fallos_para_activar})`);
    if (fallosConsecutivos >= (config.fallos_para_activar || 4) && pausado) {
      setPausado(false, `PC1 no responde (${fallosConsecutivos} fallos)`);
    }
    return;
  }

  fallosConsecutivos = 0;

  // PC1 responde pero pausada (fuera de su horario) → PC2 toma el relevo
  if (pausado) setPausado(false, 'PC1 pausada (fuera de su horario)');
}

// -------- Ciclo principal --------
async function cicloSync() {
  try {
    if (config.rol === 'primario') await cicloPrimario();
    else await cicloRespaldo();
  } catch (e) {
    console.error('[Sync] Error en cicloSync:', e.message);
  }
}

// -------- Arranque --------
function iniciarCicloSync() {
  cargarConfig();

  const p = leerJson(PAUSADO_FILE, { pausado: true });
  pausado = p.pausado !== false;

  // PC1 siempre arranca pausada: debe pasar por el handoff antes de activarse
  // (evita dos PCs procesando a la vez tras un reinicio/corte de luz).
  if (config.rol === 'primario') pausado = true;

  if (!dentroDeAlgunHorario()) {
    setPausado(true, 'fuera de horario del sistema (00:00–05:59)');
  }

  console.log(`[Sync] Iniciando ciclo. rol=${config.rol}, pausado=${pausado}`);
  cicloSync();
  const ms = Math.max(5000, (config.intervalo_ping_seg || 20) * 1000);
  intervaloHandler = setInterval(cicloSync, ms);
}

// -------- Rutas HTTP (/sync/*) --------
function registrarRutasSync(app) {
  cargarConfig();

  app.get('/sync/ping', (req, res) => {
    res.json({
      ok: true,
      rol: config.rol,
      pausado,
      dentro_horario_pc1: dentroDeHorarioPC1(),
      dentro_horario_pc2: dentroDeHorarioPC2(),
      ts: Date.now(),
    });
  });

  app.get('/sync/export', (req, res) => {
    const secret = req.query.secret || req.headers['x-sync-secret'];
    if (config.secreto && secret !== config.secreto) {
      return res.status(403).json({ ok: false, error: 'Secreto inválido' });
    }
    res.json({ ok: true, ...exportarDatos() });
  });

  app.post('/sync/import', (req, res) => {
    const { secreto } = req.body || {};
    if (config.secreto && secreto !== config.secreto) {
      return res.status(403).json({ ok: false, error: 'Secreto inválido' });
    }
    try {
      const r = importarDatos(req.body);
      console.log(`[Sync] Import OK: nuevos=${r.importados}, actualizados=${r.actualizados}, omitidos=${r.omitidos}`);
      res.json({ ok: true, ...r });
    } catch (e) {
      console.error('[Sync] Error importando:', e.message);
      res.status(500).json({ ok: false, error: e.message });
    }
  });

  app.post('/sync/import-foto', (req, res) => {
    const { secreto, ruta, data_b64 } = req.body || {};
    if (config.secreto && secreto !== config.secreto) {
      return res.status(403).json({ ok: false, error: 'Secreto inválido' });
    }
    if (!ruta || !data_b64) return res.status(400).json({ ok: false, error: 'Faltan datos' });
    try {
      const destino = rutaFotoAbs(ruta);
      const dir = path.dirname(destino);
      if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
      fs.writeFileSync(destino, Buffer.from(data_b64, 'base64'));
      res.json({ ok: true });
    } catch (e) {
      res.status(500).json({ ok: false, error: e.message });
    }
  });

  app.post('/sync/request-handoff', async (req, res) => {
    const { secreto } = req.body || {};
    if (config.secreto && secreto !== config.secreto) {
      return res.status(403).json({ ok: false, error: 'Secreto inválido' });
    }
    try {
      setPausado(true, 'handoff solicitado por PC1');
      const r = await enviarDatosAlPeer();
      console.log(`[Sync] Handoff enviado a PC1: importados=${r.importados}, actualizados=${r.actualizados}, fotos=${r.fotos_enviadas}`);
      res.json({ ok: true, ...r });
    } catch (e) {
      console.error('[Sync] Handoff falló:', e.message);
      res.status(500).json({ ok: false, error: e.message });
    }
  });
}

module.exports = { registrarRutasSync, iniciarCicloSync, estaPausado };
