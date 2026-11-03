'use strict';
/**
 * sim-comun.cjs · lo comparten los simuladores del banco del Hito 8 (`sim-elevenlabs.cjs`, `sim-twilio.cjs`) y la
 * valla de red (`valla.cjs`). Solo módulos `node:`, sin dependencias. No se ejecuta solo.
 *
 * Reglas de los simuladores (contrato H8, protección 3 y fila OPS8: «cuentan como violación toda ruta no listada»):
 *  - escuchan SOLO en 127.0.0.1 y SOLO emiten hacia 127.0.0.1: se niegan a arrancar con otra dirección;
 *  - toda ruta fuera de su lista cerrada es una VIOLACIÓN (404 o 405, como el proveedor);
 *  - el registro (JSONL y `/__requests`) guarda método, plantilla de ruta, id de ruta, clase de credencial, estado,
 *    milisegundos, violaciones y fallo inyectado: NUNCA cabeceras, claves, cuerpos, valores de consulta ni teléfonos;
 *  - las violaciones no se borran nunca: `/__reset?state=1` vacía el estado del dominio, no el registro;
 *  - el control (`/__…`) es solo GET y no cuenta como petición al proveedor; otro verbo es una violación.
 *
 * Las piezas (credenciales SINTÉTICAS del banco) se leen de un fichero `CLAVE=valor` en 600 que pasa `--config`:
 * así no viajan por la línea de órdenes ni por el entorno, donde `ps` las enseñaría.
 */
const fs = require('node:fs');
const http = require('node:http');
const crypto = require('node:crypto');

/** `--config <fichero>` `--log <fichero>` `--port <n>` (0 = el que dé el sistema). Solo 127.0.0.1. */
function argumentos(argv, nombre) {
  const a = { config: null, log: null, port: null };
  for (let i = 2; i < argv.length; i++) {
    const k = argv[i];
    const v = argv[i + 1];
    if (k === '--config' && v) { a.config = v; i++; continue; }
    if (k === '--log' && v) { a.log = v; i++; continue; }
    if (k === '--port' && v) { a.port = Number(v); i++; continue; }
    if (k === '--host' && v) {
      if (v !== '127.0.0.1') fatal(nombre, `solo escucha en 127.0.0.1 (pedido: ${v.slice(0, 40)})`);
      i++;
      continue;
    }
    fatal(nombre, `argumento desconocido: ${String(k).slice(0, 40)}`);
  }
  if (!a.config || !a.log || !Number.isInteger(a.port) || a.port < 0 || a.port > 65535) {
    fatal(nombre, 'uso: node <sim> --config <piezas en 600> --log <registro.jsonl> --port <n|0>');
  }
  return a;
}

function fatal(nombre, msg) {
  console.error(`${nombre}: ${msg}`);
  process.exit(2);
}

/** Lee `CLAVE=valor` (sin comillas ni `export`); se niega si el fichero es legible por el grupo u otros. */
function leerPiezas(ruta, nombre) {
  let st;
  try {
    st = fs.statSync(ruta);
  } catch {
    fatal(nombre, 'no encuentro el fichero de piezas');
  }
  if ((st.mode & 0o077) !== 0) fatal(nombre, `el fichero de piezas es legible por otros (modo ${(st.mode & 0o777).toString(8)}): chmod 600`);
  const out = {};
  for (const linea of fs.readFileSync(ruta, 'utf8').split('\n')) {
    const l = linea.trim();
    if (l === '' || l.startsWith('#')) continue;
    const i = l.indexOf('=');
    if (i <= 0) continue;
    out[l.slice(0, i).trim()] = l.slice(i + 1).trim();
  }
  return out;
}

/** Comparación en tiempo constante (longitudes distintas → false sin comparar). */
function igual(a, b) {
  const x = Buffer.from(String(a));
  const y = Buffer.from(String(b));
  return x.length === y.length && crypto.timingSafeEqual(x, y);
}

/** Una ruta desconocida, sin nada que pueda ser un id largo, un secreto o un teléfono. */
const sanearRuta = (p) => String(p).replace(/[A-Za-z0-9]{12,}/g, '{…}').replace(/\d{6,}/g, '{…}').replace(/[^A-Za-z0-9/._{}…-]/g, '_').slice(0, 120);
/** El nombre de un parámetro de consulta, solo si tiene forma de nombre. */
const sanearClave = (k) => (/^[A-Za-z0-9<>=_.-]{1,40}$/.test(k) ? k : '(clave no válida)');

const esObjeto = (x) => x !== null && typeof x === 'object' && !Array.isArray(x);
const clonar = (x) => (x === undefined ? undefined : JSON.parse(JSON.stringify(x)));
const ahoraS = () => Math.floor(Date.now() / 1000);

/** Mezcla profunda: los objetos se mezclan, el resto (listas incluidas) lo sustituye `b`. */
function mezclar(a, b) {
  if (!esObjeto(a) || !esObjeto(b)) return clonar(b);
  const out = clonar(a);
  for (const [k, v] of Object.entries(b)) {
    if (k === '__proto__' || k === 'constructor' || k === 'prototype') continue;
    out[k] = esObjeto(v) && esObjeto(out[k]) ? mezclar(out[k], v) : clonar(v);
  }
  return out;
}

/** Ids con la forma de cada proveedor. Twilio: prefijo + 32 hex. ElevenLabs: `prefijo_` + 26 de [0-9a-z]. */
const sidTwilio = (prefijo) => prefijo + crypto.randomBytes(16).toString('hex');
function idEleven(prefijo) {
  const abc = '0123456789abcdefghjkmnpqrstvwxyz';
  let s = '';
  for (const b of crypto.randomBytes(26)) s += abc[b % 32];
  return `${prefijo}_${s}`;
}
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i; // el patrón de B8-a (VoiceIds::UUID_PATTERN), sin exigir versión
const E164 = /^\+[1-9]\d{6,14}$/;

/** ¿Lleva el texto un teléfono (E.164, móvil o fijo de Colombia) o un correo? Para respuestas que no deben llevarlos. */
function pareceDatoPersonal(texto, extra = []) {
  const s = String(texto);
  if (/\+\d{8,15}\b/.test(s)) return true;
  if (/(^|[^\d])(3\d{9}|60\d{8})([^\d]|$)/.test(s.replace(/[\s.-]/g, ''))) return true;
  if (/[^\s@"']+@[^\s@"']+\.[a-z]{2,}/i.test(s)) return true;
  return extra.some((x) => x && x.length >= 7 && s.replace(/[\s().-]/g, '').includes(x));
}

function json(res, status, body, extra = {}) {
  if (res.headersSent || res.destroyed) return;
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', ...extra });
  res.end(JSON.stringify(body));
}

/** Lee el cuerpo con tope; por encima del tope deja de acumular (y lo dice). */
function leerCuerpo(req, max) {
  return new Promise((resolve) => {
    const trozos = [];
    let n = 0;
    let excedido = false;
    req.on('data', (c) => {
      n += c.length;
      if (n > max) excedido = true;
      else trozos.push(c);
    });
    req.on('end', () => resolve({ buf: Buffer.concat(trozos), excedido, bytes: n }));
    req.on('error', () => resolve({ buf: Buffer.alloc(0), excedido, bytes: n, error: true }));
  });
}

const percentil = (lista, p) => {
  if (lista.length === 0) return null;
  const o = [...lista].sort((a, b) => a - b);
  return o[Math.min(o.length - 1, Math.max(0, Math.ceil((p / 100) * o.length) - 1))];
};

/** Registro de peticiones y violaciones: memoria (`/__requests`) y JSONL en 600. Las violaciones no se borran. */
class Registro {
  constructor(ruta, sim) {
    this.sim = sim;
    this.entradas = [];
    this.log = fs.createWriteStream(ruta, { flags: 'a', mode: 0o600 });
    this.stats = { sim, requests: 0, violations: 0, violationsByCode: {}, authRejected: 0, byRoute: {}, byStatus: {}, faultsServed: 0, controlCalls: 0, emitted: {}, emitStatus: {}, emitMs: {} };
  }

  /** Una petición al proveedor (no de control). */
  peticion(e) {
    const s = this.stats;
    s.requests++;
    this.contarViolaciones(e.violations);
    s.byRoute[e.route] = (s.byRoute[e.route] ?? 0) + 1;
    s.byStatus[e.status] = (s.byStatus[e.status] ?? 0) + 1;
    if (e.fault) s.faultsServed++;
    this.anotar({ kind: 'request', ...e });
  }

  /** Una emisión del simulador hacia Laravel (webhook o herramienta). */
  emision(e) {
    const s = this.stats;
    s.emitted[e.target] = (s.emitted[e.target] ?? 0) + 1;
    s.emitStatus[e.target] ??= {};
    s.emitStatus[e.target][e.status] = (s.emitStatus[e.target][e.status] ?? 0) + 1;
    s.emitMs[e.target] ??= [];
    if (typeof e.ms === 'number') s.emitMs[e.target].push(e.ms);
    this.contarViolaciones(e.violations);
    this.anotar({ kind: 'emit', ...e });
  }

  /** Una llamada al control con un verbo que no es GET, u otra violación del control. */
  control(metodo, ruta, violaciones) {
    this.stats.controlCalls++;
    if (violaciones.length) {
      this.contarViolaciones(violaciones);
      this.anotar({ kind: 'control', method: metodo, route: sanearRuta(ruta), status: 405, violations: violaciones });
    }
  }

  contarViolaciones(v) {
    for (const c of v ?? []) {
      this.stats.violations++;
      this.stats.violationsByCode[c] = (this.stats.violationsByCode[c] ?? 0) + 1;
    }
  }

  anotar(e) {
    const entrada = { n: this.entradas.length, ts: new Date().toISOString(), sim: this.sim, ...e };
    this.entradas.push(entrada);
    this.log.write(JSON.stringify(entrada) + '\n');
    return entrada;
  }

  resumen(extra = {}) {
    const lat = {};
    for (const [t, l] of Object.entries(this.stats.emitMs)) lat[t] = { n: l.length, p50: percentil(l, 50), p95: percentil(l, 95), max: l.length ? Math.max(...l) : null };
    const { emitMs, ...resto } = this.stats;
    return { ...resto, emitLatencyMs: lat, ...extra };
  }

  desde(n) {
    return { total: this.entradas.length, entries: this.entradas.slice(Math.max(0, n)) };
  }

  cerrar() {
    return new Promise((r) => this.log.end(r));
  }
}

/**
 * Fallos inyectados por ruta (`GET /__fault?route=<id|all>&kind=…&status=…&times=…&delayMs=…`):
 *   status            responde `status` (400–599) antes de cualquier efecto;
 *   garbage           200 con HTML (no JSON), antes de cualquier efecto;
 *   reset             corta la conexión sin responder, antes de cualquier efecto;
 *   timeout           no responde (hasta que el cliente se rinda; 120 s como mucho) y no hay efecto;
 *   delay             espera `delayMs` y atiende normal (si el cliente ya se fue, sin efecto);
 *   hang_after_effect HACE el efecto (crea la llamada, el agente…) y no responde: el «ambiguo» de verdad.
 * `times` = cuántas peticiones lo reciben (0 = hasta `clear=1`). No son violaciones: se anotan como `fault`.
 */
const TIPOS_FALLO = ['status', 'garbage', 'reset', 'timeout', 'delay', 'hang_after_effect'];
class Fallos {
  constructor(rutasValidas) {
    this.rutas = new Set([...rutasValidas, 'all']);
    this.lista = [];
  }

  poner(q) {
    if (q.get('clear') === '1') {
      const n = this.lista.length;
      this.lista = [];
      return { ok: true, cleared: n };
    }
    const route = q.get('route') ?? 'all';
    const status = q.has('status') ? Number(q.get('status')) : null;
    const kind = q.get('kind') ?? (status !== null ? 'status' : null);
    const times = q.has('times') ? Number(q.get('times')) : 1;
    const delayMs = q.has('delayMs') ? Number(q.get('delayMs')) : 0;
    if (!this.rutas.has(route)) return { ok: false, error: 'ruta desconocida', routes: [...this.rutas] };
    if (!TIPOS_FALLO.includes(kind)) return { ok: false, error: 'kind no válido', kinds: TIPOS_FALLO };
    if (kind === 'status' && !(Number.isInteger(status) && status >= 400 && status <= 599)) return { ok: false, error: 'status 400–599' };
    if (!(Number.isInteger(times) && times >= 0 && times <= 1000)) return { ok: false, error: 'times 0–1000' };
    if (!(Number.isInteger(delayMs) && delayMs >= 0 && delayMs <= 60000)) return { ok: false, error: 'delayMs 0–60000' };
    const f = { route, kind, status, times, delayMs, served: 0 };
    this.lista.push(f);
    return { ok: true, fault: f };
  }

  /** El primer fallo vivo para la ruta (o `all`); descuenta uno. */
  tomar(routeId) {
    const f = this.lista.find((x) => (x.route === routeId || x.route === 'all') && (x.times === 0 || x.served < x.times));
    if (!f) return null;
    f.served++;
    return { ...f };
  }
}

/** ¿Es una URL http de esta máquina (127.0.0.1 o localhost)? Los simuladores solo emiten hacia ahí. */
function esLocal(base) {
  try {
    const u = new URL(base);
    return u.protocol === 'http:' && (u.hostname === '127.0.0.1' || u.hostname === 'localhost');
  } catch {
    return false;
  }
}

/**
 * Una petición hacia Laravel (o hacia el otro simulador). Solo http a 127.0.0.1. Con `cabeceras`, un valor `null`
 * significa «no la mandes» (para las variantes sin firma). Nunca lanza: devuelve el estado, el cuerpo (≤ 64 KB) y ms.
 */
function emitir({ base, path, method = 'POST', cabeceras = {}, cuerpo = '', timeoutMs = 10000 }) {
  return new Promise((resolve) => {
    if (!esLocal(base)) {
      resolve({ status: 'refused_non_local', ms: 0, body: '' });
      return;
    }
    const u = new URL(base);
    const t0 = process.hrtime.bigint();
    const buf = Buffer.isBuffer(cuerpo) ? cuerpo : Buffer.from(String(cuerpo), 'utf8');
    const h = { 'Content-Length': String(buf.length) };
    for (const [k, v] of Object.entries(cabeceras)) if (v !== null && v !== undefined) h[k] = v;
    const req = http.request({ host: u.hostname, port: u.port || 80, method, path, headers: h }, (res) => {
      const trozos = [];
      let n = 0;
      res.on('data', (c) => {
        n += c.length;
        if (n <= 65536) trozos.push(c);
      });
      res.on('end', () => resolve({ status: res.statusCode, ms: Number(process.hrtime.bigint() - t0) / 1e6, headers: res.headers, body: Buffer.concat(trozos).toString('utf8') }));
    });
    req.setTimeout(timeoutMs, () => req.destroy(Object.assign(new Error('timeout'), { code: 'ETIMEDOUT_SIM' })));
    req.on('error', (e) => resolve({ status: e.code === 'ETIMEDOUT_SIM' ? 'timeout' : 'connection_error', ms: Number(process.hrtime.bigint() - t0) / 1e6, body: '' }));
    req.end(buf);
  });
}

// ── Firmas ──────────────────────────────────────────────────────────────────────────────────────
const hmacHex = (secreto, datos) => crypto.createHmac('sha256', secreto).update(datos).digest('hex');
/** `ElevenLabs-Signature: t=<t>,v0=<HMAC-SHA256(secreto, "<t>.<cuerpo crudo>")>` (auditoría §d.1; H6 §I6.4). */
const firmaElevenLabs = (cuerpoCrudo, secreto, t) => `t=${t},v0=${hmacHex(secreto, `${t}.${cuerpoCrudo}`)}`;

/**
 * `X-Twilio-Signature` (auditoría §d.2): base64(HMAC-SHA1(token, URL exacta + claves ordenadas, cada una seguida de
 * sus valores)). Con claves repetidas, como el SDK oficial de PHP: valores sin duplicados y ordenados. El orden es el
 * de los BYTES (strcmp), para claves y valores. AVISO: con valores NUMÉRICOS repetidos el SDK de PHP ordena con
 * SORT_REGULAR (9 < 10) y el de Python por código (10 < 9): ese vector lo fija TEST8-a.
 * Comprobado contra 3 vectores de la documentación y los SDK de Twilio (sim-pruebas.cjs).
 * @param {Array<[string,string]>} pares los parámetros POST tal como van en el formulario
 */
function firmaTwilio(token, url, pares) {
  const porClave = new Map();
  for (const [k, v] of pares) {
    if (!porClave.has(k)) porClave.set(k, new Set());
    porClave.get(k).add(String(v));
  }
  const bytes = (a, b) => Buffer.compare(Buffer.from(a, 'utf8'), Buffer.from(b, 'utf8'));
  let s = url;
  for (const k of [...porClave.keys()].sort(bytes)) for (const v of [...porClave.get(k)].sort(bytes)) s += k + v;
  return crypto.createHmac('sha1', token).update(Buffer.from(s, 'utf8')).digest('base64');
}

/** Fecha con el formato de la API 2010-04-01 de Twilio (RFC 2822 en UTC). */
const fechaTwilio = (ms) => new Date(ms).toUTCString().replace('GMT', '+0000');

/** Responde a las rutas de control comunes; devuelve true si la atendió. */
function controlComun(u, res, registro, fallos, extraStats) {
  if (u.pathname === '/__stats') {
    json(res, 200, registro.resumen(extraStats()));
    return true;
  }
  if (u.pathname === '/__requests') {
    json(res, 200, registro.desde(Number.parseInt(u.searchParams.get('since') ?? '0', 10) || 0));
    return true;
  }
  if (u.pathname === '/__fault') {
    const r = fallos.poner(u.searchParams);
    json(res, r.ok ? 200 : 400, { ...r, active: fallos.lista });
    return true;
  }
  return false;
}

/** Arranca el servidor en 127.0.0.1 y escribe una línea JSON con el puerto real (la leen los ensayos y las pruebas). */
function escuchar(servidor, puerto, nombre, registro) {
  servidor.listen(puerto, '127.0.0.1', () => {
    console.log(JSON.stringify({ sim: nombre, port: servidor.address().port, pid: process.pid }));
  });
  const parar = () => servidor.close(() => registro.cerrar().then(() => process.exit(0)));
  process.on('SIGTERM', parar);
  process.on('SIGINT', parar);
}

module.exports = {
  argumentos, fatal, leerPiezas, igual, sanearRuta, sanearClave, esObjeto, clonar, ahoraS, mezclar, sidTwilio, idEleven,
  UUID, E164, pareceDatoPersonal, json, leerCuerpo, percentil, Registro, Fallos, TIPOS_FALLO, esLocal, emitir,
  hmacHex, firmaElevenLabs, firmaTwilio, fechaTwilio, controlComun, escuchar,
};
