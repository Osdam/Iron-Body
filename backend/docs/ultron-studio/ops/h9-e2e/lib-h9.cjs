/**
 * Biblioteca del guion del E2E del Hito 9 (prueba #26). La usa `recorridos-h9.cjs`. Adaptada de la del E2E del CRM del
 * H8 (`ops/h8-e2e-crm/lib.cjs`), con lo propio del H9:
 *
 * - El contexto: `context.json` del lanzador (rutas a ficheros 600: los tokens de las dos cuentas y el manifiesto de la
 *   siembra). Ningún valor secreto ni personal se imprime ni se escribe: solo etiquetas.
 * - Una sesión del CRM por cuenta: contexto de navegador propio y el token inyectado en `sessionStorage` ANTES de que
 *   arranque Angular (como el login sin «Recordarme»), con el usuario tal cual lo devuelve `GET admin/auth/me`.
 * - Registro de red por CDP: método, esquema, host, ruta y los NOMBRES de la consulta. Los cuerpos de las respuestas
 *   de la API (y los eventos del canal en vivo) se barren aquí contra los nombres y teléfonos SINTÉTICOS del manifiesto
 *   y contra los tokens; solo se anota la etiqueta.
 * - Lecturas «sin gesto» (CDP `Runtime.evaluate` con `userGesture: false`).
 * - `cambiarModo()`: la fase asistida cambia tres líneas del entorno de la COPIA y reinicia la API (mismo comando y
 *   entorno con la valla que el lanzador; el pid queda donde el lanzador lo para).
 */
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { execFileSync, spawn } = require('node:child_process');

const T = (ms) => Math.round(ms * Number(process.env.E2E_TIMEOUT_SCALE || 1));
const dormir = (ms) => new Promise((r) => setTimeout(r, ms));

function cargarContexto(fichero) {
  const c = JSON.parse(fs.readFileSync(fichero, 'utf8'));
  const rutas = c.tokens;
  c.tokenFiles = rutas;
  c.tokens = {};
  for (const k of Object.keys(rutas)) c.tokens[k] = fs.readFileSync(rutas[k], 'utf8').trim();
  c.manifiesto = () => JSON.parse(fs.readFileSync(c.manifest, 'utf8'));
  c.apiBase = `${c.api}/api`;
  return c;
}

/** Los valores que no pueden aparecer en el DOM, la consola, el almacenamiento ni la red (solo se dicen sus etiquetas). */
function prohibidos(ctx) {
  const out = {};
  for (const [k, t] of Object.entries(ctx.tokens)) out[`sesión ${k}`] = t;
  const m = ctx.manifiesto();
  for (const [id, p] of Object.entries(m.people || {})) {
    out[`nombre de ${id}`] = p.name;
    out[`teléfono de ${id} (nacional)`] = p.phone;
    out[`teléfono de ${id} (E.164)`] = p.e164;
    out[`teléfono de ${id} (sin +)`] = p.e164.slice(1);
  }
  return out;
}

/** Las etiquetas de los valores prohibidos que aparecen en `texto` (nunca el valor). */
function fugas(texto, lista, excepto = []) {
  if (!texto) return [];
  const s = String(texto);
  return Object.entries(lista).filter(([k, v]) => v && !excepto.includes(k) && s.includes(v)).map(([k]) => k);
}

/** Cualquier E.164 completo de Colombia en un texto (patrón genérico, además de los conocidos). */
function e164s(texto) {
  return (String(texto || '').match(/\+57\s?\d{3}\s?\d{3}\s?\d{4}/g) || []).map((x) => '…' + x.replace(/\D/g, '').slice(-4));
}

/** SQL de solo lectura con el rol lector de la base desechable. */
function sql(ctx, consulta) {
  const envuelta = `select coalesce(json_agg(t), '[]'::json) from (${consulta}) t`;
  const out = execFileSync('psql', ['-X', '-h', ctx.db.host, '-p', String(ctx.db.port), '-U', ctx.db.reader, '-d', ctx.db.database, '-At', '-v', 'ON_ERROR_STOP=1', '-c', envuelta], {
    encoding: 'utf8', env: { ...process.env, PGGSSENCMODE: 'disable' },
  });
  return JSON.parse(out.trim() || '[]');
}
const valor = (ctx, q) => {
  const f = sql(ctx, q)[0];
  return f ? Object.values(f)[0] : null;
};

/** Petición directa a la API con el token de una cuenta (solo verificaciones; nunca imprime el token). */
async function api(ctx, quien, metodo, ruta, cuerpo) {
  const h = { Accept: 'application/json' };
  if (quien) h.Authorization = `Bearer ${ctx.tokens[quien]}`;
  if (cuerpo !== undefined) h['Content-Type'] = 'application/json';
  const r = await fetch(`${ctx.apiBase}/${ruta.replace(/^\//, '')}`, { method: metodo, headers: h, body: cuerpo === undefined ? undefined : JSON.stringify(cuerpo) });
  const texto = await r.text();
  let json = null;
  try { json = JSON.parse(texto); } catch { /* sin JSON */ }
  return { status: r.status, json, texto };
}

function entornoRed(ctx, extra = {}) {
  return { PATH: process.env.PATH, HOME: process.env.HOME, LC_ALL: 'C', PGGSSENCMODE: 'disable', ...ctx.red_env, ...extra };
}

/**
 * Un `artisan` de la copia, con la valla. Devuelve el código, la salida y el error (para la evidencia). `relojObjetivo`
 * (ISO 8601) carga `reloj-h9.php` en ESE proceso: `now()` de Carbon en ese instante (la costura del despacho).
 */
function artisan(ctx, args, extraEnv = {}, relojObjetivo = null) {
  const php = relojObjetivo ? ['-d', `auto_prepend_file=${ctx.helpers.reloj}`] : [];
  const env = entornoRed(ctx, relojObjetivo ? { ...extraEnv, H9_E2E_RELOJ_OBJETIVO: relojObjetivo, H9_E2E_VENDOR: `${ctx.backend}/vendor/autoload.php` } : extraEnv);
  try {
    const r = require('node:child_process').spawnSync(ctx.php, [...php, 'artisan', ...args], { cwd: ctx.backend, env, encoding: 'utf8', timeout: T(180000) });
    return { code: r.status ?? 1, out: String(r.stdout || ''), err: String(r.stderr || '').slice(0, 1200) };
  } catch (e) {
    return { code: 1, out: '', err: String(e.message || e).slice(0, 600) };
  }
}

/** El ayudante PHP del arnés, con la valla. */
function ayudante(ctx, args) {
  const out = execFileSync(ctx.php, [ctx.helpers.ayudante, ctx.backend, ...args], { cwd: ctx.backend, env: entornoRed(ctx), encoding: 'utf8', timeout: T(120000) });
  return JSON.parse(out.trim().split('\n').pop());
}

const uuid = () => crypto.randomUUID();

// ── La API: cambiar de modo y reiniciar (fase asistida) ─────────────────────────────────────────────────────────────
function descendientes(pid) {
  let hijos = [];
  try { hijos = execFileSync('pgrep', ['-P', String(pid)], { encoding: 'utf8' }).split('\n').filter(Boolean); } catch { hijos = []; }
  return hijos.flatMap((h) => [...descendientes(h), h]);
}

/** Los pid que escuchan en el puerto de la API (los workers de `php -S` pueden quedar huérfanos, con PPID 1). */
function escuchando(puerto) {
  try {
    return [...new Set(execFileSync('lsof', ['-nP', `-iTCP:${puerto}`, '-sTCP:LISTEN', '-t'], { encoding: 'utf8' }).split('\n').filter(Boolean))];
  } catch {
    return [];
  }
}

async function pararApi(ctx) {
  const arbol = fs.existsSync(ctx.apiPid) ? (() => { const pid = fs.readFileSync(ctx.apiPid, 'utf8').trim(); return [...descendientes(pid), pid]; })() : [];
  const todos = [...new Set([...arbol, ...escuchando(ctx.ports.api)])];
  for (const p of todos) { try { process.kill(Number(p), 'SIGTERM'); } catch { /* ya no está */ } }
  for (let i = 0; i < 60; i++) {
    if (todos.every((p) => { try { process.kill(Number(p), 0); return false; } catch { return true; } })) break;
    await dormir(100);
  }
  for (const p of [...todos, ...escuchando(ctx.ports.api)]) { try { process.kill(Number(p), 'SIGKILL'); } catch { /* ya no está */ } }
  for (let i = 0; i < 100 && escuchando(ctx.ports.api).length; i++) await dormir(100);
  if (escuchando(ctx.ports.api).length) throw new Error(`el puerto ${ctx.ports.api} sigue ocupado tras parar la API`);
}

async function arrancarApi(ctx) {
  const salida = fs.openSync(ctx.apiLog, 'a');
  const hijo = spawn(ctx.apiCmd[0], ctx.apiCmd.slice(1), { cwd: ctx.backend, env: entornoRed(ctx, { PHP_CLI_SERVER_WORKERS: '16' }), detached: true, stdio: ['ignore', salida, salida] });
  hijo.unref();
  fs.writeFileSync(ctx.apiPid, String(hijo.pid));
  for (let i = 0; i < 120; i++) {
    try {
      const r = await fetch(`${ctx.apiBase}/admin/ultron-studio/strategies/catalog`, { headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(2000) });
      // Contesta, y quien escucha en el puerto desciende del proceso nuevo (no un worker huérfano de la API anterior).
      if (r.status === 401) {
        const nuestros = new Set(descendientes(hijo.pid));
        const oyentes = escuchando(ctx.ports.api);
        return oyentes.length > 0 && oyentes.every((p) => nuestros.has(p));
      }
    } catch { /* aún no */ }
    await dormir(500);
  }
  return false;
}

/** Los procesos de la API llevan la valla en su entorno (todos). */
function apiConValla(ctx) {
  const pid = fs.readFileSync(ctx.apiPid, 'utf8').trim();
  const hijos = descendientes(pid);
  let con = 0;
  for (const h of hijos) {
    try { if (execFileSync('ps', ['eww', '-o', 'command=', '-p', h], { encoding: 'utf8' }).includes(`https_proxy=${ctx.red_env.https_proxy}`)) con++; } catch { /* terminó */ }
  }
  return { procesos: hijos.length, conValla: con };
}

/** Cambia en el entorno de la COPIA el modo y el asistido (solo esas líneas) y reinicia la API. */
async function cambiarModo(ctx, { modo, asistido, cupo }) {
  const f = path.join(ctx.backend, '.env');
  let texto = fs.readFileSync(f, 'utf8');
  const poner = (clave, v) => {
    const re = new RegExp(`^${clave}=.*$`, 'm');
    if (!re.test(texto)) throw new Error(`falta ${clave} en el entorno de la copia`);
    texto = texto.replace(re, `${clave}=${v}`);
  };
  poner('COMMERCIAL_AUTOMATION_MODE', modo);
  poner('COMMERCIAL_AUTOMATION_ASSISTED_ENABLED', asistido ? 'true' : 'false');
  poner('COMMERCIAL_AUTOMATION_ASSISTED_DAILY_CAP', String(cupo));
  fs.writeFileSync(f, texto, { mode: 0o600 });
  const desde = new Date().toISOString();
  await pararApi(ctx);
  const ok = await arrancarApi(ctx);
  // La ventana del reinicio deliberado: el cierre no cuenta como 5xx los 502 del proxy dentro de ella.
  fs.appendFileSync(path.join(ctx.run, 'reinicios-api.jsonl'), JSON.stringify({ desde, hasta: new Date().toISOString(), modo }) + '\n');
  return ok;
}

/** ¿Cae un instante dentro de una ventana de reinicio deliberado de la API (con 5 s de margen)? */
function enReinicio(ctx, iso) {
  const f = path.join(ctx.run, 'reinicios-api.jsonl');
  if (!fs.existsSync(f)) return false;
  const t = Date.parse(iso);
  return fs.readFileSync(f, 'utf8').split('\n').filter(Boolean).map((l) => JSON.parse(l)).some((v) => t >= Date.parse(v.desde) - 5000 && t <= Date.parse(v.hasta) + 5000);
}

// ── Comprobaciones ───────────────────────────────────────────────────────────────────────────────────────────────
class Informe {
  constructor(ctx, prohib) {
    this.ctx = ctx;
    this.prohib = prohib;
    this.paso = 'inicio';
    this.n = 0;
    this.fallos = 0;
    this.t0 = Date.now();
  }
  en(paso) { this.paso = paso; console.log(`── ${paso}`); }
  check(nombre, ok, detalle) {
    this.n++;
    if (!ok) this.fallos++;
    let d = detalle === undefined ? null : detalle;
    const s = d === null ? '' : typeof d === 'string' ? d : JSON.stringify(d);
    const f = fugas(s, this.prohib);
    if (f.length) d = `[detalle retirado: llevaba ${f.join(', ')}]`;
    const linea = { phase: 'e2e', step: this.paso, check: nombre, ok: !!ok, detail: d, ts: new Date().toISOString() };
    fs.appendFileSync(this.ctx.checksFile, JSON.stringify(linea) + '\n');
    const ds = d === null ? '' : ` — ${typeof d === 'string' ? d : JSON.stringify(d)}`.slice(0, 700);
    console.log(`  ${ok ? 'OK   ' : 'FALLO'} ${nombre}${ok ? (ds && ds.length < 200 ? ds : '') : ds}`);
    return !!ok;
  }
  must(nombre, ok, detalle) {
    if (!this.check(nombre, ok, detalle)) throw new Requisito(nombre);
  }
  fin() {
    console.log(`  → ${this.n - this.fallos}/${this.n} OK en ${Math.round((Date.now() - this.t0) / 1000)} s`);
    return this.fallos === 0;
  }
}
class Requisito extends Error {}

// ── Navegador ────────────────────────────────────────────────────────────────────────────────────────────────────
async function lanzar(ctx) {
  const puppeteer = require(path.join(ctx.crmRepo, 'node_modules', 'puppeteer-core'));
  return puppeteer.launch({
    executablePath: ctx.chrome,
    headless: true,
    defaultViewport: { width: 1440, height: 900 },
    args: [
      '--no-first-run', '--no-default-browser-check', '--disable-extensions', '--disable-background-networking', '--disable-component-update',
      '--disable-sync', '--disable-default-apps', '--no-pings', '--metrics-recording-only', '--disable-domain-reliability',
      '--disable-features=OptimizationHints,MediaRouter,Translate,AutofillServerCommunication,CertificateTransparencyComponentUpdater',
      // Ninguna petición del navegador sale de la máquina: todo nombre que no sea 127.0.0.1/localhost no resuelve.
      '--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1, EXCLUDE localhost',
      '--window-size=1440,900',
    ],
  });
}

function rutaSinValores(u) {
  try {
    const x = new URL(u);
    const claves = [...new Set([...x.searchParams.keys()])].sort();
    return { scheme: x.protocol.replace(':', ''), host: x.host, path: x.pathname + (claves.length ? '?' + claves.map((k) => `${k}=…`).join('&') : '') };
  } catch {
    return { scheme: '?', host: '?', path: String(u).slice(0, 40) };
  }
}

const ES_LOCAL = (host) => /^(127\.0\.0\.1|localhost)(:\d+)?$/.test(host);

/** Una sesión del CRM de una cuenta: página, registro de red, cuerpos barridos, eventos SSE barridos y consola. */
async function sesion(browser, ctx, inf, quien) {
  const me = await api(ctx, quien, 'GET', 'admin/auth/me');
  if (me.status !== 200 || !me.json?.user) throw new Requisito(`GET admin/auth/me de ${quien}: ${me.status}`);
  const u = me.json.user;
  const usuario = { id: u.id, name: u.name, email: u.email, role: u.role, permissions: u.permissions ?? [] };
  const bctx = await browser.createBrowserContext();
  const page = await bctx.newPage();
  const s = { quien, page, bctx, usuario, red: [], consola: [], errores: [], api5xx: [], api4xx: [], fugasRed: [], cuerpos: 0, sse: { abiertos: 0, eventos: 0, temas: {} }, simulaciones: [] };
  const cdp = await page.createCDPSession();
  s.cdp = cdp;
  await cdp.send('Network.enable', { maxPostDataSize: 65536 });
  const peticiones = new Map();
  const hostApi = new URL(ctx.proxyApi).host;
  cdp.on('Network.requestWillBeSent', (e) => {
    const r = rutaSinValores(e.request.url);
    if (r.scheme === 'data' || r.scheme === 'blob') return;
    const linea = { ts: new Date().toISOString(), quien, kind: 'http', method: e.request.method, scheme: r.scheme, host: r.host, path: r.path, type: e.type };
    peticiones.set(e.requestId, { method: e.request.method, ...r, linea });
    s.red.push(linea);
    const f = [...fugas(e.request.url, inf.prohib, ['sesión ' + quien]), ...fugas(e.request.postData || '', inf.prohib)];
    if (f.length) s.fugasRed.push({ dir: 'envío', method: e.request.method, path: r.path, labels: [...new Set(f)] });
    // La simulación sintética que manda el navegador (H9-E2E-2): solo la FORMA de `events` y `contacts` de cada persona
    // de prueba (lista con su longitud, null o ausente) y su referencia sintética; nunca el resto del cuerpo.
    if (e.request.method === 'POST' && /^\/api\/admin\/ultron-studio\/strategies\/(versions\/)?[0-9a-f-]{36}\/simulations$/.test(r.path)) {
      const forma = (v) => (Array.isArray(v) ? `lista(${v.length})` : v === undefined ? 'ausente' : v === null ? 'null' : typeof v);
      const anotar = (texto, origen) => {
        let cuerpo = null;
        try { cuerpo = JSON.parse(texto || 'null'); } catch { cuerpo = null; }
        const gente = Array.isArray(cuerpo?.people) ? cuerpo.people : null;
        s.simulaciones.push({ ts: linea.ts, path: r.path, kind: cuerpo?.kind ?? null, origen,
          formas: gente ? gente.map((p) => ({ ref: String(p?.ref ?? '').slice(0, 40), events: forma(p?.events), contacts: forma(p?.contacts) })) : null });
      };
      const entradas = (e.request.postDataEntries || []).map((x) => Buffer.from(x.bytes || '', 'base64').toString('utf8')).join('');
      if (e.request.postData) anotar(e.request.postData, 'postData');
      else if (entradas) anotar(entradas, 'postDataEntries');
      else cdp.send('Network.getRequestPostData', { requestId: e.requestId }).then((b) => anotar(b.postData, 'getRequestPostData')).catch(() => anotar(null, 'sin cuerpo legible'));
    }
  });
  cdp.on('Network.webSocketCreated', (e) => {
    const r = rutaSinValores(e.url);
    s.red.push({ ts: new Date().toISOString(), quien, kind: 'websocket', method: 'GET', scheme: r.scheme, host: r.host, path: r.path });
  });
  cdp.on('Network.responseReceived', (e) => {
    const p = peticiones.get(e.requestId);
    if (!p) return;
    p.status = e.response.status;
    p.mime = e.response.mimeType;
    p.linea.status = e.response.status;
    if (p.host === hostApi && p.status >= 500) s.api5xx.push({ ts: new Date().toISOString(), txt: `${p.method} ${p.path} ${p.status}`, status: p.status });
    if (p.host === hostApi && p.status >= 400 && p.status < 500) s.api4xx.push(`${p.method} ${p.path} ${p.status}`);
    if (p.mime === 'text/event-stream') s.sse.abiertos++;
  });
  // Las peticiones que no llegan a ninguna parte (el resolver de Chrome mata los nombres que no son de esta máquina).
  cdp.on('Network.loadingFailed', (e) => {
    const p = peticiones.get(e.requestId);
    if (p) p.linea.error = String(e.errorText || 'failed').slice(0, 60);
  });
  // El canal en vivo: cada evento SSE se cuenta por tema y se barre (nunca se guarda su contenido).
  cdp.on('Network.eventSourceMessageReceived', (e) => {
    s.sse.eventos++;
    const tema = String(e.eventName || 'message');
    s.sse.temas[tema] = (s.sse.temas[tema] || 0) + 1;
    const f = [...fugas(e.data, inf.prohib), ...e164s(e.data).map((x) => 'E.164 ' + x)];
    if (f.length) s.fugasRed.push({ dir: 'evento SSE', method: 'GET', path: tema, labels: [...new Set(f)] });
  });
  cdp.on('Network.loadingFinished', async (e) => {
    const p = peticiones.get(e.requestId);
    if (!p || p.host !== hostApi || !p.path.startsWith('/api/') || p.mime === 'text/event-stream') return;
    try {
      const b = await cdp.send('Network.getResponseBody', { requestId: e.requestId });
      const cuerpo = b.base64Encoded ? Buffer.from(b.body, 'base64').toString('utf8') : b.body;
      s.cuerpos++;
      const f = fugas(cuerpo, inf.prohib);
      const g = e164s(cuerpo);
      if (f.length || g.length) s.fugasRed.push({ dir: 'respuesta', method: p.method, path: p.path, status: p.status, labels: [...new Set([...f, ...g.map((x) => 'E.164 ' + x)])] });
    } catch { /* cuerpo ya liberado */ }
  });
  page.on('console', (m) => s.consola.push({ type: m.type(), text: m.text().slice(0, 500) }));
  s.log = [];
  await cdp.send('Log.enable');
  cdp.on('Log.entryAdded', (e) => s.log.push({ source: e.entry.source, level: e.entry.level, text: String(e.entry.text || '').slice(0, 500), url: e.entry.url || '' }));
  page.on('pageerror', (e) => s.errores.push(String(e.message || e).slice(0, 300)));
  page.setDefaultTimeout(T(30000));
  await page.evaluateOnNewDocument((token, user) => {
    try {
      if (!sessionStorage.getItem('auth_token') && !sessionStorage.getItem('auth_tab_signed_out')) {
        sessionStorage.setItem('auth_token', token);
        sessionStorage.setItem('auth_user', user);
        sessionStorage.setItem('auth_session_type', 'session');
      }
    } catch { /* sin almacenamiento */ }
  }, ctx.tokens[quien], JSON.stringify(usuario));
  return s;
}

/** Lectura sin gesto del usuario (CDP, `userGesture: false`). `expr` es el cuerpo de una función; devuelve su valor. */
async function leer(s, expr) {
  const r = await s.cdp.send('Runtime.evaluate', { expression: `(() => { ${expr} })()`, returnByValue: true, awaitPromise: true, userGesture: false });
  if (r.exceptionDetails) throw new Error(`leer: ${r.exceptionDetails.exception?.description?.slice(0, 200) || r.exceptionDetails.text}`);
  return r.result.value;
}

async function esperar(s, expr, ms = T(20000), paso = 200) {
  const fin = Date.now() + ms;
  let v;
  do {
    try { v = await leer(s, expr); } catch { v = undefined; }
    if (v) return v;
    await dormir(paso);
  } while (Date.now() < fin);
  return v;
}

const NORMALIZA = "const n = (t) => String(t || '').replace(/[\\uE500\\u00a0]/g, ' ').replace(/\\s+/g, ' ').trim();";

async function texto(s, selector) {
  return leer(s, `${NORMALIZA} const e = document.querySelector(${JSON.stringify(selector)}); return e ? n(e.innerText || e.textContent) : null;`);
}

/** Abre la página de automatizaciones en una pestaña y espera a que esté lista. */
async function pagina(s, ctx, query) {
  await s.page.goto(`${ctx.crmUrl}/marketing/automatizaciones?pestana=${query}`, { waitUntil: 'domcontentloaded', timeout: T(120000) });
  return esperar(s, `const p = document.querySelector('[data-ca-pagina]'); return !!p && p.getAttribute('data-ca-fase') === 'ready';`, T(60000));
}

async function clic(s, selector, ms = T(15000)) {
  try {
    const h = await s.page.waitForSelector(selector, { visible: true, timeout: ms });
    await h.click();
    return true;
  } catch {
    return false;
  }
}

async function escribir(s, selector, valorTexto) {
  const h = await s.page.waitForSelector(selector, { visible: true });
  await h.click({ clickCount: 3 });
  await s.page.keyboard.press('Backspace');
  await h.type(String(valorTexto));
}

/** Elige una opción de un `<select>`: espera a ESA opción y comprueba que quedó elegida. */
async function elegir(s, selector, valorOpcion, ms = T(30000)) {
  await s.page.waitForSelector(selector, { visible: true });
  if (valorOpcion !== '') await s.page.waitForSelector(`${selector} option[value=${JSON.stringify(String(valorOpcion))}]`, { timeout: ms });
  await s.page.select(selector, valorOpcion);
  const elegido = await leer(s, `const e = document.querySelector(${JSON.stringify(selector)}); return e ? e.value : null;`);
  if (elegido !== valorOpcion) throw new Requisito(`${selector}: la opción ${String(valorOpcion).slice(0, 24)} no quedó elegida (${String(elegido).slice(0, 24)})`);
  return elegido;
}

async function captura(s, ctx, nombre) {
  const f = path.join(ctx.shots, `${nombre}.png`);
  try { await s.page.screenshot({ path: f, fullPage: true }); } catch { /* página cerrada */ }
  return f;
}

/** DOM, almacenamiento y consola de la sesión: las etiquetas de lo prohibido que aparecen. */
async function barrerPagina(s, inf) {
  const v = await leer(s, `
    const alm = (st) => { const o = {}; for (let i = 0; i < st.length; i++) { const k = st.key(i); o[k] = st.getItem(k); } return o; };
    return { html: document.documentElement.outerHTML, texto: document.body ? document.body.innerText : '', local: alm(localStorage), sesion: alm(sessionStorage), url: location.href };
  `);
  const out = {};
  out.dom = [...new Set([...fugas(v.html, inf.prohib, ['sesión ' + s.quien]), ...e164s(v.texto).map((x) => 'E.164 ' + x)])];
  const { auth_token: _t, ...resto } = v.sesion;
  out.almacenamiento = [...new Set([...fugas(JSON.stringify(v.local), inf.prohib), ...fugas(JSON.stringify(resto), inf.prohib)])];
  out.url = fugas(v.url, inf.prohib, ['sesión ' + s.quien]);
  out.consola = [...new Set(s.consola.flatMap((m) => [...fugas(m.text, inf.prohib, ['sesión ' + s.quien]), ...e164s(m.text).map((x) => 'E.164 ' + x)]))];
  out.tokenEnDom = v.html.includes(inf.ctx.tokens[s.quien]);
  return out;
}

function guardarRed(ctx, sesiones) {
  const f = path.join(ctx.red, 'navegador.jsonl');
  for (const s of sesiones) for (const l of s.red) fs.appendFileSync(f, JSON.stringify(l) + '\n');
  return f;
}

module.exports = {
  T, dormir, cargarContexto, prohibidos, fugas, e164s, sql, valor, api, artisan, ayudante, uuid, Informe, Requisito, lanzar, sesion, leer, esperar,
  texto, pagina, clic, escribir, elegir, captura, barrerPagina, guardarRed, ES_LOCAL, NORMALIZA, cambiarModo, apiConValla, pararApi, arrancarApi, enReinicio,
};
