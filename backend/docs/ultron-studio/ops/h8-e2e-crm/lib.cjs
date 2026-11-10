/**
 * Biblioteca de los guiones del E2E del CRM del H8 (TEST8-b, parte 2). La usa `recorridos.cjs`.
 *
 * - El contexto: `crm-context.json` del lanzador + el `context.json` del arnés del backend (rutas a ficheros 600: los
 *   tokens de las cuentas y las piezas sintéticas). Ningún valor secreto se imprime ni se escribe: solo etiquetas.
 * - Una sesión del CRM por cuenta: contexto de navegador propio, el token del arnés inyectado en `sessionStorage` ANTES
 *   de que arranque Angular (como deja la sesión el formulario de login sin «Recordarme»; las cuentas del arnés no tienen
 *   contraseña), y el usuario tal cual lo devuelve `GET admin/auth/me`.
 * - Registro de red por CDP (todas las peticiones del navegador, WebSocket incluido): método, esquema, host, ruta y los
 *   NOMBRES de la consulta. Los cuerpos de las respuestas de la API se barren aquí (teléfonos completos, piezas, URL
 *   firmada, id del agente en claro) y solo se anota la etiqueta.
 * - Lecturas «sin gesto» (CDP `Runtime.evaluate` con `userGesture: false`): `page.evaluate` de puppeteer cuenta como
 *   gesto del usuario y desbloquearía lo que el CRM solo hace tras un gesto (micrófono, audio).
 */
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { execFileSync } = require('node:child_process');

const T = (ms) => Math.round(ms * Number(process.env.E2E_TIMEOUT_SCALE || 1));
const dormir = (ms) => new Promise((r) => setTimeout(r, ms));

function leerPiezas(ruta) {
  const out = {};
  for (const l of fs.readFileSync(ruta, 'utf8').split('\n')) {
    const s = l.trim();
    if (!s || s.startsWith('#') || !s.includes('=')) continue;
    const i = s.indexOf('=');
    out[s.slice(0, i).trim()] = s.slice(i + 1).trim();
  }
  return out;
}

function cargar(fichero) {
  const c = JSON.parse(fs.readFileSync(fichero, 'utf8'));
  c.h = JSON.parse(fs.readFileSync(c.harnessContext, 'utf8'));
  c.tokens = {};
  for (const k of ['a', 'b', 'c']) c.tokens[k] = fs.readFileSync(c.h.tokens[k], 'utf8').trim();
  c.piezas = leerPiezas(c.h.pieces);
  c.api = `${c.h.api}/api`;
  return c;
}

/** Los valores que no pueden aparecer en el DOM, la consola, el almacenamiento ni la red (solo se dicen sus etiquetas). */
function prohibidos(ctx, extra = {}) {
  const p = ctx.piezas;
  const out = {};
  for (const k of ['EL_API_KEY', 'EL_WEBHOOK_SECRET', 'EL_TOOL_SECRET', 'EL_INITIATION_SECRET', 'TW_LARAVEL_KEY_SECRET', 'TW_SIGNING_TOKEN', 'TW_EL_KEY_SECRET',
    'TW_MASTER_TOKEN', 'SENTINEL_ELEVENLABS', 'SENTINEL_TWILIO', 'TW_ACCOUNT_SID', 'TW_MASTER_SID', 'TW_LARAVEL_KEY_SID', 'TW_EL_KEY_SID', 'TW_VOICE_NUMBER_SID', 'EL_PHONE_NUMBER_ID']) {
    if (p[k]) out[k] = p[k];
  }
  for (const [k, t] of Object.entries(ctx.tokens)) out[`sesión ${k}`] = t;
  for (const e164 of ['+573000000011', '+573000000012', '+573000000019', '+573000000071', '+573000000077', '+573000000099']) {
    out[`teléfono …${e164.slice(-4)}`] = e164;
    out[`teléfono …${e164.slice(-4)} (sin +)`] = e164.slice(1);
    out[`teléfono …${e164.slice(-4)} (nacional)`] = e164.slice(3);
  }
  out['URL firmada (su firma)'] = 'conversation_signature';
  Object.assign(out, extra);
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

function sql(ctx, consulta, params = []) {
  let q = consulta;
  params.forEach((v, i) => {
    q = q.replace(new RegExp(`\\$${i + 1}(?!\\d)`, 'g'), `'${String(v).replace(/'/g, "''")}'`);
  });
  const envuelta = `select coalesce(json_agg(t), '[]'::json) from (${q}) t`;
  const out = execFileSync('psql', ['-X', '-h', ctx.h.db.host, '-p', String(ctx.h.db.port), '-U', ctx.h.db.reader, '-d', ctx.h.db.database, '-At', '-v', 'ON_ERROR_STOP=1', '-c', envuelta], {
    encoding: 'utf8', env: { ...process.env, PGGSSENCMODE: 'disable' },
  });
  return JSON.parse(out.trim() || '[]');
}
const valor = (ctx, q, p = []) => {
  const f = sql(ctx, q, p)[0];
  return f ? Object.values(f)[0] : null;
};

/** Petición directa a la API del arnés con el token de una cuenta (precondiciones y verificaciones; nunca imprime el token). */
async function api(ctx, quien, metodo, ruta, cuerpo) {
  const h = { Accept: 'application/json' };
  if (quien) h.Authorization = `Bearer ${ctx.tokens[quien]}`;
  if (cuerpo !== undefined) h['Content-Type'] = 'application/json';
  const r = await fetch(`${ctx.api}/${ruta.replace(/^\//, '')}`, { method: metodo, headers: h, body: cuerpo === undefined ? undefined : JSON.stringify(cuerpo) });
  const texto = await r.text();
  let json = null;
  try { json = JSON.parse(texto); } catch { /* sin JSON */ }
  return { status: r.status, json, texto, headers: Object.fromEntries(r.headers.entries()) };
}

/** Control GET de un simulador (`/__<control>`), como `H8StackE2ETestCase::control`. */
async function sim(ctx, cual, control, q = {}) {
  const puerto = ctx.h.ports[cual];
  const u = new URL(`http://127.0.0.1:${puerto}/__${control.replace(/^[_/]+/, '')}`);
  for (const [k, v] of Object.entries(q)) u.searchParams.set(k, String(v));
  const r = await fetch(u, { signal: AbortSignal.timeout(30000) });
  const t = await r.text();
  try { return { _http: r.status, ...JSON.parse(t) }; } catch { return { _http: r.status, _raw: t.slice(0, 300) }; }
}

/** El ayudante del arnés (costuras documentadas en su README), con el entorno limpio de la pila y la valla. */
function ayudante(ctx, args, extraEnv = {}, prepend = null) {
  const env = { PATH: process.env.PATH, HOME: process.env.HOME, LC_ALL: 'C', PGGSSENCMODE: 'disable', ...ctx.h.red, ...extraEnv };
  const php = prepend ? ['-d', `auto_prepend_file=${prepend}`] : [];
  const out = execFileSync(ctx.h.php, [...php, ctx.h.helpers.ayudante, ctx.h.backend, ...args], { cwd: ctx.h.backend, env, encoding: 'utf8', timeout: 120000 });
  return JSON.parse(out.trim().split('\n').pop());
}

/**
 * B8-g (`number_unverified`): la observación del número importado (la parte de números de la conciliación) con el reloj
 * real o con el desplazado a `instante` (el de un worker de un trabajo con `reloj.php`), como `observarNumero()` del E2E
 * del backend. Devuelve su resumen (sin el número).
 */
function observarNumero(ctx, instante = null) {
  const env = instante ? { H8_E2E_RELOJ_OBJETIVO: instante, H8_E2E_VENDOR: `${ctx.h.backend}/vendor/autoload.php` } : {};
  return ayudante(ctx, ['observar-numero'], env, instante ? ctx.h.helpers.reloj : null);
}

/** Un `artisan` de la copia del arnés (p. ej. el worker de UN trabajo con el reloj desplazado). */
function artisan(ctx, args, extraEnv = {}, prepend = null) {
  const env = { PATH: process.env.PATH, HOME: process.env.HOME, LC_ALL: 'C', PGGSSENCMODE: 'disable', ...ctx.h.red, ...extraEnv };
  const php = prepend ? ['-d', `auto_prepend_file=${prepend}`] : [];
  try {
    const out = execFileSync(ctx.h.php, [...php, 'artisan', ...args], { cwd: ctx.h.backend, env, encoding: 'utf8', timeout: 120000, stdio: ['ignore', 'pipe', 'pipe'] });
    return { code: 0, out };
  } catch (e) {
    return { code: e.status ?? 1, out: String(e.stdout || ''), err: String(e.stderr || '').slice(0, 400) };
  }
}

const uuid = () => crypto.randomUUID();

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
    // El detalle va a la evidencia: se barre antes de escribir (si llevara algo prohibido, se sustituye por su etiqueta).
    const s = d === null ? '' : typeof d === 'string' ? d : JSON.stringify(d);
    const f = fugas(s, this.prohib);
    if (f.length) d = `[detalle retirado: llevaba ${f.join(', ')}]`;
    const linea = { phase: this.ctx.phase, step: this.paso, check: nombre, ok: !!ok, detail: d, ts: new Date().toISOString() };
    fs.appendFileSync(this.ctx.checksFile, JSON.stringify(linea) + '\n');
    const ds = d === null ? '' : ` — ${typeof d === 'string' ? d : JSON.stringify(d)}`.slice(0, 600);
    console.log(`  ${ok ? 'OK   ' : 'FALLO'} ${nombre}${ok ? '' : ds}`);
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
async function lanzar(ctx, extraArgs = []) {
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
      // Micrófono falso de Chrome (sin diálogo de permiso): el laboratorio pide el permiso antes de gastar.
      '--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', '--autoplay-policy=no-user-gesture-required',
      '--window-size=1440,900',
      ...extraArgs,
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

/**
 * Una sesión del CRM de una cuenta. Devuelve `s` con la página, el registro de red y lo que se vio en la consola.
 * `excepcionesCuerpo(metodo, ruta)` → etiquetas permitidas en el cuerpo de ESA respuesta (p. ej. la URL firmada en la
 * apertura de la sesión del laboratorio, que es donde viaja por diseño).
 */
async function sesion(browser, ctx, inf, quien, opciones = {}) {
  const me = await api(ctx, quien, 'GET', 'admin/auth/me');
  if (me.status !== 200 || !me.json?.user) throw new Requisito(`GET admin/auth/me de ${quien}: ${me.status}`);
  const u = me.json.user;
  const usuario = { id: u.id, name: u.name, email: u.email, role: u.role, permissions: u.permissions ?? [] };
  const bctx = await browser.createBrowserContext();
  if (opciones.microfono !== false) await bctx.overridePermissions(ctx.crmUrl, ['microphone']);
  const page = await bctx.newPage();
  const s = { quien, page, bctx, usuario, red: [], consola: [], errores: [], api5xx: [], fugasRed: [], innerHtml: [], excepcionesCuerpo: opciones.excepcionesCuerpo || (() => []) };
  const cdp = await page.createCDPSession();
  s.cdp = cdp;
  await cdp.send('Network.enable', { maxPostDataSize: 65536 });
  const peticiones = new Map();
  cdp.on('Network.requestWillBeSent', (e) => {
    const r = rutaSinValores(e.request.url);
    if (r.scheme === 'data' || r.scheme === 'blob') return;
    const linea = { ts: new Date().toISOString(), quien, kind: 'http', method: e.request.method, scheme: r.scheme, host: r.host, path: r.path, type: e.type };
    // El estado HTTP y Retry-After se anotan en la MISMA línea al llegar la respuesta (registro de red de la evidencia).
    peticiones.set(e.requestId, { method: e.request.method, ...r, url: e.request.url, post: e.request.postData || '', linea });
    s.red.push(linea);
    // Lo que el navegador ENVÍA: ni secretos, ni la URL firmada, ni teléfonos (salvo en el formulario que los pide).
    const permitidos = opciones.excepcionesEnvio ? opciones.excepcionesEnvio(e.request.method, r.path) : [];
    const f = [...fugas(e.request.url, s.prohib ?? inf.prohib, ['sesión ' + quien]), ...fugas(e.request.postData || '', inf.prohib, permitidos)];
    if (f.length) s.fugasRed.push({ dir: 'envío', method: e.request.method, path: r.path, labels: [...new Set(f)] });
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
    const ra = Object.entries(e.response.headers || {}).find(([k]) => k.toLowerCase() === 'retry-after');
    if (ra) p.linea.retryAfter = String(ra[1]).slice(0, 8);
    if (p.host === new URL(ctx.proxyApi).host && p.status >= 500) s.api5xx.push(`${p.method} ${p.path} ${p.status}`);
  });
  cdp.on('Network.loadingFinished', async (e) => {
    const p = peticiones.get(e.requestId);
    if (!p || p.host !== new URL(ctx.proxyApi).host || !p.path.startsWith('/api/') || p.mime === 'text/event-stream') return;
    try {
      const b = await cdp.send('Network.getResponseBody', { requestId: e.requestId });
      const cuerpo = b.base64Encoded ? Buffer.from(b.body, 'base64').toString('utf8') : b.body;
      const f = fugas(cuerpo, inf.prohib, s.excepcionesCuerpo(p.method, p.path));
      const g = e164s(cuerpo);
      if (f.length || g.length) s.fugasRed.push({ dir: 'respuesta', method: p.method, path: p.path, status: p.status, labels: [...new Set([...f, ...g.map((x) => 'E.164 ' + x)])] });
    } catch { /* cuerpo ya liberado */ }
  });
  page.on('console', (m) => s.consola.push({ type: m.type(), text: m.text().slice(0, 500) }));
  // El registro del NAVEGADOR (CDP Log): `source` dice si lo escribió Chrome (network, security…) o la página (javascript).
  s.log = [];
  await cdp.send('Log.enable');
  cdp.on('Log.entryAdded', (e) => s.log.push({ source: e.entry.source, level: e.entry.level, text: String(e.entry.text || '').slice(0, 500), url: e.entry.url || '' }));
  page.on('pageerror', (e) => s.errores.push(String(e.message || e).slice(0, 300)));
  page.setDefaultTimeout(T(30000));
  // La sesión del CRM, como la deja el login sin «Recordarme»: en la pestaña. Solo si la pestaña no tiene ya una ni la cerró.
  await page.evaluateOnNewDocument((token, user) => {
    try {
      if (!sessionStorage.getItem('auth_token') && !sessionStorage.getItem('auth_tab_signed_out')) {
        sessionStorage.setItem('auth_token', token);
        sessionStorage.setItem('auth_user', user);
        sessionStorage.setItem('auth_session_type', 'session');
      }
    } catch { /* sin almacenamiento */ }
  }, ctx.tokens[quien], JSON.stringify(usuario));
  // Nada de innerHTML/outerHTML/insertAdjacentHTML/document.write: se cuentan las escrituras con un trozo de la pila.
  await page.evaluateOnNewDocument(() => {
    const registro = (window.__ibHtml = []);
    const anotar = (que) => registro.push({ que, pila: String(new Error().stack || '').split('\n').slice(2, 5).map((x) => x.trim().replace(/\?[^:)]*/, '')).join(' | ') });
    for (const [proto, prop] of [[Element.prototype, 'innerHTML'], [Element.prototype, 'outerHTML'], [ShadowRoot.prototype, 'innerHTML']]) {
      const d = Object.getOwnPropertyDescriptor(proto, prop);
      if (d && d.set) Object.defineProperty(proto, prop, { ...d, set(v) { anotar(prop); return d.set.call(this, v); } });
    }
    const iah = Element.prototype.insertAdjacentHTML;
    Element.prototype.insertAdjacentHTML = function (...a) { anotar('insertAdjacentHTML'); return iah.apply(this, a); };
    const dw = Document.prototype.write;
    Document.prototype.write = function (...a) { anotar('document.write'); return dw.apply(this, a); };
  });
  return s;
}

/** Lectura sin gesto del usuario (CDP, `userGesture: false`). `expr` es una expresión JS; devuelve su valor. */
async function leer(s, expr) {
  const r = await s.cdp.send('Runtime.evaluate', { expression: `(() => { ${expr} })()`, returnByValue: true, awaitPromise: true, userGesture: false });
  if (r.exceptionDetails) throw new Error(`leer: ${r.exceptionDetails.exception?.description?.slice(0, 200) || r.exceptionDetails.text}`);
  return r.result.value;
}

/** Espera a que una expresión (sin gesto) sea verdadera. */
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

/** Texto visible normalizado (el `&ngsp;` de Angular es U+E500). */
async function texto(s, selector) {
  return leer(s, `${NORMALIZA} const e = document.querySelector(${JSON.stringify(selector)}); return e ? n(e.innerText || e.textContent) : null;`);
}

async function ir(s, ctx, ruta) {
  await s.page.goto(`${ctx.crmUrl}${ruta}`, { waitUntil: 'domcontentloaded', timeout: T(120000) });
}

/** Abre la página de voz en una pestaña y espera a que su panel esté pintado. */
async function pestana(s, ctx, id, listo) {
  await ir(s, ctx, `/marketing/ultron-studio/voz?pestana=${id}`);
  const ok = await esperar(s, `return !!document.querySelector('#vt-panel-${id}:not([hidden])') && document.querySelector('[data-voz-pestana="${id}"]')?.getAttribute('aria-selected') === 'true';`, T(90000));
  if (!ok) return false;
  // Listo: lo pedido, y además que el panel no siga «Leyendo…» (estados de carga con role=status).
  const cargado = `return ![...document.querySelectorAll('#vt-panel-${id} [role=status], #vt-panel-${id} .lab-empty')].some((e) => /^Leyendo/.test((e.textContent || '').trim()));`;
  if (!(await esperar(s, cargado, T(45000)))) return false;
  if (listo) return !!(await esperar(s, listo, T(30000)));
  await dormir(300);
  return true;
}

/** Clic real (con gesto) en un selector visible; devuelve false si no aparece. */
async function clic(s, selector, ms = T(15000)) {
  try {
    const h = await s.page.waitForSelector(selector, { visible: true, timeout: ms });
    await h.click();
    return true;
  } catch {
    return false;
  }
}

/** Escribe en un control (vacía antes). */
async function escribir(s, selector, valorTexto) {
  const h = await s.page.waitForSelector(selector, { visible: true });
  await h.click({ clickCount: 3 });
  await s.page.keyboard.press('Backspace');
  await h.type(String(valorTexto));
}

/**
 * Elige una opción de un `<select>`. Espera a que ESA opción exista (las listas del CRM llegan después de pintar el
 * control: `page.select` sobre una opción que aún no está no hace nada y no falla) y comprueba que quedó elegida.
 */
async function elegir(s, selector, valorOpcion, ms = T(30000)) {
  await s.page.waitForSelector(selector, { visible: true });
  if (valorOpcion) {
    const op = `${selector} option[value=${JSON.stringify(String(valorOpcion))}]`;
    await s.page.waitForSelector(op, { timeout: ms });
  }
  await s.page.select(selector, valorOpcion);
  const elegido = await leer(s, `const e = document.querySelector(${JSON.stringify(selector)}); return e ? e.value : null;`);
  if (elegido !== valorOpcion) throw new Requisito(`${selector}: la opción ${String(valorOpcion).slice(0, 8)}… no quedó elegida (${String(elegido).slice(0, 8)})`);
  return elegido;
}

/** Captura de pantalla de la página entera en la evidencia. */
async function captura(s, ctx, nombre) {
  const f = path.join(ctx.shots, `${nombre}.png`);
  try { await s.page.screenshot({ path: f, fullPage: true }); } catch { /* página cerrada */ }
  return f;
}

/** Desplazamiento horizontal: el documento y los elementos que se salen sin una caja con desplazamiento propio. */
async function desborde(s) {
  return leer(s, `
    const w = document.documentElement.clientWidth;
    const doc = document.documentElement.scrollWidth;
    const fuera = [];
    const caja = (e) => { for (let p = e.parentElement; p; p = p.parentElement) { const o = getComputedStyle(p).overflowX; if (o === 'auto' || o === 'scroll' || o === 'hidden' || o === 'clip') return true; } return false; };
    for (const e of document.querySelectorAll('main *, [data-voz-pagina] *')) {
      const r = e.getBoundingClientRect();
      if (r.width === 0 || r.height === 0) continue;
      if (r.right > w + 1 && !caja(e)) fuera.push((e.tagName + '.' + [...e.classList].slice(0, 2).join('.')).slice(0, 60));
      if (fuera.length > 8) break;
    }
    return { ancho: w, scrollWidth: doc, fuera };
  `);
}

/** Foco atrapado: N Tab y M Mayús+Tab sin salir del diálogo abierto (el panel `[role=dialog]` de `ib-dialog`, no el host 0×0). */
async function focoAtrapado(s, tabs = 30, atras = 10) {
  const dentro = `const d = [...document.querySelectorAll('[role=dialog][aria-modal=true]')].pop(); const a = document.activeElement; return !!(d && a && d.contains(a));`;
  if (!(await leer(s, dentro))) return { ok: false, motivo: 'el foco no está en el diálogo al abrir' };
  let fugasFoco = 0;
  for (let i = 0; i < tabs; i++) { await s.page.keyboard.press('Tab'); if (!(await leer(s, dentro))) fugasFoco++; }
  await s.page.keyboard.down('Shift');
  for (let i = 0; i < atras; i++) { await s.page.keyboard.press('Tab'); if (!(await leer(s, dentro))) fugasFoco++; }
  await s.page.keyboard.up('Shift');
  return { ok: fugasFoco === 0, fugas: fugasFoco };
}

/** DOM (HTML con atributos y texto), almacenamiento y consola de la sesión: las etiquetas de lo prohibido que aparecen. */
async function barrerPagina(s, inf, excepto = []) {
  const v = await leer(s, `
    const alm = (st) => { const o = {}; for (let i = 0; i < st.length; i++) { const k = st.key(i); o[k] = st.getItem(k); } return o; };
    return { html: document.documentElement.outerHTML, texto: document.body ? document.body.innerText : '', local: alm(localStorage), sesion: alm(sessionStorage), url: location.href };
  `);
  const out = {};
  out.dom = [...new Set([...fugas(v.html, inf.prohib, ['sesión ' + s.quien, ...excepto]), ...e164s(v.texto).map((x) => 'E.164 ' + x)])];
  // El token de la sesión vive en sessionStorage por diseño del CRM (AuthService): es lo único que se excluye ahí.
  const { auth_token: _t, ...resto } = v.sesion;
  out.almacenamiento = [...new Set([...fugas(JSON.stringify(v.local), inf.prohib, excepto), ...fugas(JSON.stringify(resto), inf.prohib, excepto)])];
  out.url = fugas(v.url, inf.prohib, ['sesión ' + s.quien]);
  // La consola de la PÁGINA: sin las líneas que escribe el propio Chrome por la red (CDP Log, fuente `network`, p. ej.
  // «WebSocket connection to 'wss://…' failed», la limitación del navegador que anota FE8-3 §3.3); esas van aparte.
  const deChrome = new Set((s.log || []).filter((e) => e.source === 'network').map((e) => e.text));
  const etiquetas = (lista) => [...new Set(lista.flatMap((m) => [...fugas(m.text, inf.prohib, ['sesión ' + s.quien]), ...e164s(m.text).map((x) => 'E.164 ' + x)]))];
  out.consola = etiquetas(s.consola.filter((m) => !deChrome.has(m.text)));
  out.consolaDeChrome = etiquetas(s.consola.filter((m) => deChrome.has(m.text)));
  // Lo mismo, separado por quién lo escribió: el propio Chrome (p. ej. «WebSocket connection to … failed», source=network)
  // o el código de la página (source=javascript / console-api).
  out.consolaPorFuente = {};
  for (const e of s.log || []) {
    const f = [...fugas(e.text + ' ' + e.url, inf.prohib, ['sesión ' + s.quien]), ...e164s(e.text).map((x) => 'E.164 ' + x)];
    if (f.length) out.consolaPorFuente[e.source] = [...new Set([...(out.consolaPorFuente[e.source] || []), ...f])];
  }
  out.tokenEnDom = v.html.includes(inf.ctx.tokens[s.quien]);
  return out;
}

async function innerHtml(s) {
  return leer(s, 'return (window.__ibHtml || []).slice(0, 20);');
}

function guardarRed(ctx, sesiones) {
  const f = path.join(ctx.red, 'navegador.jsonl');
  for (const s of sesiones) for (const l of s.red) fs.appendFileSync(f, JSON.stringify(l) + '\n');
  return f;
}

module.exports = {
  T, dormir, cargar, prohibidos, fugas, e164s, sql, valor, api, sim, ayudante, observarNumero, artisan, uuid, Informe, Requisito, lanzar, sesion, leer, esperar,
  texto, ir, pestana, clic, escribir, elegir, captura, desborde, focoAtrapado, barrerPagina, innerHtml, guardarRed, ES_LOCAL, NORMALIZA,
};
