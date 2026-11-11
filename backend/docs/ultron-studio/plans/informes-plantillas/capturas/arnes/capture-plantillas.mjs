/**
 * Capturas de la pestaña «Plantillas» con las plantillas de WhatsApp de Meta (L9-96, informe-frontend.md §4) con la
 * API INTERCEPTADA: el CRM compilado (`dist/frontend/browser`) servido en local, una sesión fijada en
 * `sessionStorage` (Super Admin con `ultron.meta_templates_submit`) y cada petición a la API contestada aquí con las
 * formas REALES de `dtos-para-frontend.md` (el doble de Meta). Nada sale de la máquina: el build de producción apunta a
 * `api.ironbodyneiva.cloud` y el navegador manda un preflight OPTIONS por petición, que se contesta con CORS.
 * Datos inventados: ningún socio, ningún teléfono real (el del botón «Llamar» es el publicado del gimnasio).
 *
 *   node capture-plantillas.mjs <CRM> <backend> <salida>
 *
 * Mismo patrón que `Iron-Body_Front/e2e/h9-ui/capture-h9.mjs` (FE9).
 */
import { createRequire } from 'node:module';
import { createServer } from 'node:http';
import { existsSync, mkdirSync, readFileSync, statSync } from 'node:fs';
import { extname, join, resolve } from 'node:path';

const [CRM, BACKEND, OUT] = process.argv.slice(2).map((p) => resolve(p));
if (!CRM || !BACKEND || !OUT) throw new Error('uso: node capture-plantillas.mjs <CRM> <backend> <salida>');
const puppeteer = createRequire(join(CRM, 'package.json'))('puppeteer-core');
const DIST = join(CRM, 'dist/frontend/browser');
const CHROME = process.env.CHROME_PATH ?? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const PUERTO = Number(process.env.PORT ?? 4317);
if (!existsSync(join(DIST, 'index.html'))) throw new Error(`No hay build en ${DIST}: ng build --configuration=production`);
mkdirSync(OUT, { recursive: true });

const fx = (ruta) => JSON.parse(readFileSync(join(BACKEND, ruta), 'utf8'));
const catalogo = fx('tests/fixtures/outreach/catalog-snapshot.json');
const trazas = fx('tests/Unit/Outreach/Core/fixtures/trace-text-cases.json');
const condiciones = fx('tests/fixtures/outreach/conditions-cases.json');

const ok = (data, meta) => (meta ? { ok: true, data, meta } : { ok: true, data });
const pagina = (items) => ({ data: items, current_page: 1, last_page: 1, per_page: 20, total: items.length });
const ana = { name: 'Ana Autora', role: 'Super Admin' };
const beto = { name: 'Beto Revisor', role: 'Administrador' };
const H = (c) => c.repeat(64).slice(0, 64);

// ── Catálogo con `templates.meta` (§5.4), botones de URL y «Llamar» encendidos (Q1 y Q2 de L9-96) ──
const metaCatalogo = {
  headerFormats: ['TEXT'], headerMax: 60, footerMax: 60, buttonTextMax: 25, buttonsMax: 3, urlButtonsMax: 2, urlMax: 200, exampleMax: 40,
  buttonTypes: ['PHONE_NUMBER', 'QUICK_REPLY', 'URL'], urlButtons: true, urlHosts: ['ironbodyneiva.cloud'], phoneButton: true, phoneLast4: '5483',
  optOutLabels: ['Darme de baja', 'No más mensajes', 'Detener promociones'],
  examples: { class_name: 'Spinning', class_time_text: 'mañana a las 6:00 p. m.', days_to_expiry: '5', first_name: 'Laura', gym_name: 'Iron Body', membership_end_date_text: '15 de diciembre', payment_link: 'el enlace de pago', plan_name: 'Plan Trimestral', plan_price: 'el valor de tu plan' },
  wordsPerVariable: 4,
};
const gobierno = { enabled: true, publishing: { enabled: false, code: 'strategies_publishing_disabled', window: { label: '07:00-19:00', open: true } }, simulationGate: 'required', canary: { minHours: 24, minTouches: 3 } };
const can = { edit: true, approve: true, publish: true, rollback: true, simulateReal: true, personBindings: true };
const catalogoDto = {
  catalog: catalogo.catalog, catalogHash: catalogo.catalogHash, dynamicDomains: condiciones.dynamicDomains, contentFormat: 'strategy-content/1',
  limits: { contactsPerDay: [1, 500], cooldownDays: [0, 365], enrollmentDays: [1, 60], maxTouches: [1, 6], newEnrollmentsPerDay: [1, 500] }, limitsOptional: { commercialBlockOverdueDays: [0, 365] },
  templates: { channels: ['assisted', 'voice', 'whatsapp'], languages: ['es', 'es_CO'], categoryByPurpose: { commercial: 'marketing', collections: 'utility', service: 'utility' }, markers: ['class_name', 'class_time_text', 'days_to_expiry', 'first_name', 'gym_name', 'membership_end_date_text', 'payment_link', 'plan_name', 'plan_price'], markersForbiddenByPurpose: { collections: ['plan_price'] }, bodyMax: 1024, scriptMax: 600, meta: metaCatalogo },
  traceText: trazas.dictionary, studioBaseline: { catalogVersion: '2026-10-19.1', catalogHash: H('d') }, governance: gobierno, can, person: { bound: true },
};

// ── Las versiones de plantilla ──
const U = (n) => `7e000000-0000-4000-8000-0000000000${String(n).padStart(2, '0')}`;
const contenido = (cambios = {}) => ({
  metaName: 'renovacion_aviso_v2', language: 'es_CO', category: 'marketing',
  header: { format: 'TEXT', text: 'Tu plan está por vencer' },
  bodyPreview: 'Hola {{1}}, tu *{{2}}* vence el {{3}}.\nSi quieres seguir entrenando con nosotros, respóndenos y te ayudamos a renovarlo.',
  variables: ['first_name', 'plan_name', 'membership_end_date_text'],
  examples: { first_name: 'Laura', plan_name: 'Plan Trimestral', membership_end_date_text: '15 de diciembre' },
  footer: { text: 'Escribe BAJA para no recibir más mensajes' },
  buttons: [{ type: 'QUICK_REPLY', text: 'Quiero renovar' }, { type: 'QUICK_REPLY', text: 'Darme de baja', role: 'opt_out' }],
  optOutLine: true, ...cambios,
});
const vista = (c) => ({ header: c.header?.text ?? null, body: c.bodyPreview, footer: c.footer?.text ?? null, buttons: c.buttons ?? [], variables: c.variables, examples: c.examples ?? {}, metaWarnings: [] });
const envio = (cambios = {}) => ({
  uuid: '696ef374-6b57-41ff-ba93-95fdfe5ebbd5', state: 'SUBMITTED', metaName: 'renovacion_aviso_v2', language: 'es_CO', category: 'marketing', metaTemplateId: '1688186126583244',
  metaStatus: 'PENDING', metaCategory: 'MARKETING', correctCategory: null, rejectedReason: null, quality: null, matchesStudio: null, mismatch: [], attesterUnavailable: false, error: null,
  submittedBy: ana, submittedAt: '2026-11-11T06:18:26+00:00', lastCheckedAt: '2026-11-11T06:18:26+00:00', lastChangedAt: '2026-11-11T06:18:26+00:00', ...cambios,
});
const libro = [
  { at: '2026-11-11T06:18:26+00:00', event: 'meta_template.submitted', summary: 'Se envió a Meta la versión 2 de la plantilla «renovacion-aviso»: PENDING', actor: 'Ana Autora' },
  { at: '2026-11-11T06:10:00+00:00', event: 'message_template.approved', summary: 'Beto Revisor aprobó la versión 2 de la plantilla «renovacion-aviso»', actor: 'Beto Revisor' },
  { at: '2026-11-11T05:40:00+00:00', event: 'message_template.created', summary: 'Ana Autora creó la versión 2 de la plantilla «renovacion-aviso»', actor: 'Ana Autora' },
];
const metaDet = (cambios = {}) => ({ status: 'PENDING', state: 'SUBMITTED', campaignReady: false, campaignReasons: ['template_not_approved_by_meta'], submission: envio(), attempts: 1, latestCheck: null, timeline: libro, actions: { submit: { allowed: false, code: 'already_submitted' }, refresh: { allowed: true } }, ...cambios });

const fila = (n, key, number, channel, purpose, status, meta, cambios = {}) => ({
  uuid: U(n), key, number, channel, purpose, status, contentHash: H(String(n % 10)), reference: { uuid: U(n), hash: H(String(n % 10)) },
  createdBy: ana, createdAt: '2026-11-11T05:40:00+00:00', statusChangedAt: '2026-11-11T06:18:26+00:00', meta, ...cambios,
});
const detalle = (f, content, meta, cambios = {}) => ({
  ...f, content, preview: content.bodyPreview ? content.bodyPreview.replace(/\{\{(\d)\}\}/g, (_, n) => `[${content.variables[n - 1]}]`) : null, restrictions: [], usesPaymentLink: false, integrity: true,
  review: f.status === 'DRAFT' ? null : { note: null, reviewer: beto, at: '2026-11-11T06:10:00+00:00' },
  actions: f.status === 'DRAFT' ? { approve: { allowed: false, code: 'four_eyes_required' }, reject: { allowed: false, code: 'four_eyes_required' } } : { approve: { allowed: false, code: 'invalid_transition' }, reject: { allowed: false, code: 'invalid_transition' } },
  waPreview: f.channel === 'whatsapp' ? vista(content) : null, meta, ...cambios,
});

const C_LISTA = contenido({ metaName: 'renovacion_aviso_v1', header: undefined, buttons: [{ type: 'QUICK_REPLY', text: 'Quiero renovar' }, { type: 'URL', text: 'Ver mi plan', url: 'https://ironbodyneiva.cloud/planes' }, { type: 'PHONE_NUMBER', text: 'Llamar' }] });
delete C_LISTA.header;
const filas = [
  fila(1, 'renovacion-aviso', 2, 'whatsapp', 'commercial', 'APPROVED', { status: 'PENDING', state: 'SUBMITTED', campaignReady: false }),
  fila(2, 'renovacion-aviso', 1, 'whatsapp', 'commercial', 'APPROVED', { status: 'APPROVED', state: 'SUBMITTED', campaignReady: true }),
  fila(3, 'cobro-recordatorio', 1, 'whatsapp', 'collections', 'APPROVED', { status: 'REJECTED', state: 'SUBMITTED', campaignReady: false }),
  fila(4, 'clase-recordatorio', 3, 'whatsapp', 'service', 'APPROVED', { status: 'PAUSED', state: 'SUBMITTED', campaignReady: false }),
  fila(5, 'bienvenida', 1, 'whatsapp', 'commercial', 'APPROVED', { status: null, state: null, campaignReady: false }),
  fila(6, 'promo-referidos', 1, 'whatsapp', 'commercial', 'DRAFT', { status: null, state: null, campaignReady: false }),
  fila(7, 'cartera-15d', 1, 'whatsapp', 'collections', 'APPROVED', { status: null, state: 'FAILED', campaignReady: false }),
  fila(8, 'renovacion-guion', 1, 'assisted', 'commercial', 'APPROVED', null),
];
const C_COBRO = contenido({ metaName: 'cobro_recordatorio_v1', category: 'utility', header: undefined, bodyPreview: 'Hola {{1}}, te recordamos que tu {{2}} venció el {{3}}. Si ya pagaste, ignora este mensaje.', buttons: [{ type: 'QUICK_REPLY', text: 'Ya pagué' }, { type: 'QUICK_REPLY', text: 'No más mensajes', role: 'opt_out' }] });
delete C_COBRO.header;
const detalles = {
  [U(1)]: detalle(filas[0], contenido(), metaDet()),
  [U(2)]: detalle(filas[1], C_LISTA, metaDet({ status: 'APPROVED', campaignReady: true, campaignReasons: [], submission: envio({ metaName: 'renovacion_aviso_v1', metaStatus: 'APPROVED', quality: 'GREEN', matchesStudio: true }), latestCheck: { status: 'APPROVED', source: 'meta_api', checkedAt: '2026-11-11T07:05:00Z', recordedBy: 'Ana Autora' }, timeline: [{ at: '2026-11-11T07:05:00+00:00', event: 'meta_template.meta_check_recorded', summary: 'Quedó registrado lo que dice Meta de la versión 1 de la plantilla «renovacion_aviso_v1»: APPROVED', actor: 'Ana Autora' }, ...libro.slice(0, 2)] })),
  [U(3)]: detalle(filas[2], C_COBRO, metaDet({ status: 'REJECTED', campaignReasons: ['meta_status_not_approved'], submission: envio({ metaName: 'cobro_recordatorio_v1', category: 'utility', metaCategory: 'UTILITY', metaStatus: 'REJECTED', rejectedReason: 'INCORRECT_CATEGORY' }) })),
  [U(5)]: detalle(filas[4], contenido({ metaName: 'bienvenida_v1' }), metaDet({ status: null, state: null, campaignReasons: ['template_not_approved_by_meta'], submission: null, attempts: 0, timeline: libro.slice(1), actions: { submit: { allowed: true }, refresh: { allowed: false, code: 'meta_not_tracked' } } })),
};

const estadoMeta = {
  enabled: true, submitEnabled: true, syncEnabled: true, metaEnabled: true, driver: 'graph', graphVersion: 'v25.0', configured: true, configurationProblem: null, credentialSource: 'database',
  lastProbe: { uuid: '01207b59-c7e2-4bd6-a093-d0f3ba6a7e7b', at: '2026-11-11T06:18:26Z', outcome: 'ok', scopes: { whatsapp_business_management: true }, templatesReadable: true, templatesError: null, driver: 'graph', graphVersion: 'v25.0', by: ana, writeVerified: false },
  lastSyncAt: '2026-11-11T07:05:00Z', lastSync: { at: '2026-11-11T07:05:00Z', outcome: 'ok', watched: 5, requests: 2, error: null, changed: 1, rowsWritten: 1 },
  counts: { PENDING: 1, APPROVED: 1, REJECTED: 1, PAUSED: 1 }, submissions: { SENDING: 0, SUBMITTED: 4, FAILED: 1, UNKNOWN: 0 }, can: { submit: true, refresh: true, probe: true },
};

const sesion = {
  id: '1', name: 'Cuenta de capturas', email: 'capturas@local.invalid', role: 'Super Admin',
  permissions: ['ultron.view', 'ultron.approve', 'ultron.strategies_edit', 'ultron.strategies_publish', 'ultron.strategies_rollback', 'ultron.person_bindings_manage', 'ultron.meta_templates_submit', 'ultron.integrations_manage', 'commercial_automation.view', 'commercial_automation.simulate_real', 'commercial_automation.timeline_view', 'commercial_automation.assisted_work', 'commercial_automation.stop', 'commercial_automation.release', 'marketing.view'],
};

function responder(url, metodo) {
  const p = new URL(url).pathname;
  const S = '/api/admin/ultron-studio/strategies';
  if (p === '/api/health') return { message: 'Laravel API is healthy', status: 'healthy', timestamp: new Date().toISOString() };
  if (p === '/api/admin/auth/me') return { ok: true, token: '', user: sesion };
  if (p === '/api/admin/notifications') return { data: [], current_page: 1, last_page: 1, per_page: 8, total: 0, unread: 0 };
  if (p === `${S}/catalog`) return ok(catalogoDto);
  if (p === `${S}/templates/meta/status`) return ok(estadoMeta);
  if (p === `${S}/templates` && metodo === 'GET') return ok(pagina(filas));
  const m = p.match(/\/templates\/([0-9a-f-]{36})$/);
  if (m && detalles[m[1]]) return ok(detalles[m[1]]);
  return null;
}

const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml', '.woff2': 'font/woff2', '.woff': 'font/woff', '.ico': 'image/x-icon', '.txt': 'text/plain' };
const servidor = createServer((req, res) => {
  let f = join(DIST, decodeURIComponent(new URL(req.url, 'http://x').pathname));
  if (!existsSync(f) || statSync(f).isDirectory()) f = join(DIST, 'index.html');
  res.writeHead(200, { 'content-type': MIME[extname(f)] ?? 'application/octet-stream' });
  res.end(readFileSync(f));
});
await new Promise((r) => servidor.listen(PUERTO, '127.0.0.1', r));

const CORS = { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*', 'access-control-allow-methods': 'GET,POST,PUT,DELETE,OPTIONS', 'access-control-expose-headers': '*', 'access-control-max-age': '600' };
const registro = [];
const fuera = [];
const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox', '--disable-gpu', '--hide-scrollbars'] });
/** Las fuentes del CRM (Inter, Lexend y los iconos) vienen de Google Fonts: sin ellas los iconos salen como texto. Solo esas pasan. */
const FUENTES = new Set(['fonts.googleapis.com', 'fonts.gstatic.com']);
const fuentes = new Set();
const page = await browser.newPage();
await page.evaluateOnNewDocument((s) => {
  sessionStorage.setItem('auth_token', 'capturas-sin-servidor');
  sessionStorage.setItem('auth_user', JSON.stringify(s));
}, sesion);
await page.setRequestInterception(true);
page.on('request', (req) => {
  const url = req.url();
  if (url.startsWith(`http://127.0.0.1:${PUERTO}`) || url.startsWith('data:')) return req.continue();
  if (FUENTES.has(new URL(url).host)) {
    fuentes.add(new URL(url).host);
    return req.continue();
  }
  if (!/\/api\//.test(url)) {
    fuera.push(new URL(url).host);
    return req.abort();
  }
  if (req.method() === 'OPTIONS') return req.respond({ status: 204, headers: CORS, body: '' });
  const cuerpo = responder(url, req.method());
  registro.push({ metodo: req.method(), ruta: new URL(url).pathname, contestada: cuerpo !== null });
  if (cuerpo === null) return req.respond({ status: 404, headers: { ...CORS, 'content-type': 'application/json' }, body: JSON.stringify({ message: 'Not Found (capturas)' }) });
  return req.respond({ status: 200, headers: { ...CORS, 'content-type': 'application/json' }, body: JSON.stringify(cuerpo) });
});
const errores = [];
page.on('pageerror', (e) => errores.push(String(e).slice(0, 300)));

const BASE = `http://127.0.0.1:${PUERTO}`;
const esperar = (ms) => new Promise((r) => setTimeout(r, ms));
/** Pulsa con `element.click()`: el asistente flotante del CRM tapa la esquina inferior derecha y un clic por coordenadas cae en él. */
const clic = async (sel) => {
  await page.waitForSelector(sel, { timeout: 20000 });
  await page.$eval(sel, (b) => b.click());
};
const medidas = [];
async function medir(nombre, ancho) {
  const m = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }));
  medidas.push({ nombre, ancho, desplazamientoHorizontal: m.sw > m.cw, scrollWidth: m.sw, clientWidth: m.cw });
}
async function foto(nombre, ancho, completa = true) {
  await esperar(350);
  await medir(nombre, ancho);
  await page.screenshot({ path: join(OUT, `${nombre}-${ancho}.png`), fullPage: completa });
  console.log(`captura ${nombre}-${ancho}.png`);
}
async function abrirPestana() {
  await page.goto(`${BASE}/marketing/automatizaciones?pestana=plantillas`, { waitUntil: 'networkidle0', timeout: 60000 });
  await page.waitForSelector('[data-ca-plantillas-tabla]', { timeout: 20000 });
  await page.waitForSelector('[data-ca-meta-conexion]', { timeout: 20000 });
}
/** El CRM desplaza un contenedor interno (no la página): se lleva el elemento arriba de la vista. */
async function desplazarA(sel) {
  await page.$eval(sel, (e) => e.scrollIntoView({ block: 'start' }));
  await esperar(250);
}
async function tema(oscuro) {
  await page.$$eval(oscuro ? '[data-wa-tema-oscuro]' : '[data-wa-tema-claro]', (bs) => bs.forEach((b) => b.click()));
  await esperar(200);
}

for (const [ancho, alto] of [[1440, 900], [390, 844]]) {
  await page.setViewport({ width: ancho, height: alto, deviceScaleFactor: ancho === 390 ? 2 : 1 });
  // 1. La lista con la conexión con Meta.
  await abrirPestana();
  await foto('plantillas-lista', ancho);
  if (ancho === 390) {
    await desplazarA('[data-ca-plantillas-tabla]');
    await foto('plantillas-lista-tabla', ancho, false);
    await desplazarA('[data-ca-plantillas]');
  }
  // 2. El detalle: en revisión (el carril a medias), lista para campañas y rechazada.
  for (const [n, nombre] of [[1, 'detalle-en-revision'], [2, 'detalle-lista'], [3, 'detalle-rechazada']]) {
    await clic(`[data-ca-plantilla-abrir="${U(n)}"]`);
    await page.waitForSelector('[data-ca-plantilla-carril]', { timeout: 20000 });
    await esperar(300);
    await foto(`plantillas-${nombre}`, ancho, false);
    if (n === 2) {
      // El cajón entero desplazado hasta el historial (el cajón desplaza dentro de sí).
      await page.$eval('.ibd-body', (b) => b.scrollTo(0, b.scrollHeight));
      await foto('plantillas-detalle-lista-final', ancho, false);
    }
    await page.keyboard.press('Escape');
    await esperar(300);
  }
  // 3. «Enviar a Meta»: el diálogo con lo que sale exactamente.
  await clic(`[data-ca-plantilla-abrir="${U(5)}"]`);
  await page.waitForSelector('[data-ca-plantilla-enviar-meta]', { timeout: 20000 });
  await clic('[data-ca-plantilla-enviar-meta]');
  await page.waitForSelector('[data-ca-enviar-meta-form]', { timeout: 20000 });
  await clic('[data-ca-enviar-meta-entiendo]');
  await foto('plantillas-enviar-a-meta', ancho, false);
  await page.keyboard.press('Escape');
  await esperar(300);
  await page.keyboard.press('Escape');
  await esperar(300);
  // 4. El editor (versión corregida de la rechazada), con la vista previa en claro y en oscuro.
  await clic(`[data-ca-plantilla-abrir="${U(3)}"]`);
  await page.waitForSelector('[data-ca-plantilla-corregir]', { timeout: 20000 });
  await clic('[data-ca-plantilla-corregir]');
  await page.waitForSelector('[data-ca-plantilla-form]', { timeout: 20000 });
  await desplazarA('[data-ca-plantilla-editor]');
  await foto('plantillas-editor-arriba', ancho, false);
  await clic('[data-ca-plantilla-con-encabezado]');
  await page.type('[data-ca-plantilla-encabezado]', 'Tu pago está pendiente');
  await page.focus('[data-ca-plantilla-pie]').catch(() => {});
  if (ancho === 1440) {
    await tema(false);
    await foto('plantillas-editor-pie-claro', ancho);
    await tema(true);
    await foto('plantillas-editor-pie-oscuro', ancho);
    await tema(false);
  } else {
    await foto('plantillas-editor-pie', ancho);
    await clic('[data-ca-plantilla-ver-como-llega]');
    await page.waitForSelector('[role="dialog"] [data-wa-telefono]', { timeout: 20000 });
    await tema(false);
    await foto('plantillas-vista-claro', ancho, false);
    await tema(true);
    await foto('plantillas-vista-oscuro', ancho, false);
    await page.keyboard.press('Escape');
  }
}

console.log('MEDIDAS=' + JSON.stringify(medidas));
console.log('PETICIONES_SIN_RESPUESTA=' + JSON.stringify([...new Set(registro.filter((r) => !r.contestada).map((r) => `${r.metodo} ${r.ruta}`))]));
console.log('HOSTS_BLOQUEADOS=' + JSON.stringify([...new Set(fuera)]));
console.log('HOSTS_DE_FUENTES=' + JSON.stringify([...fuentes]));
console.log('ERRORES_DE_PAGINA=' + JSON.stringify(errores));
await browser.close();
servidor.close();
