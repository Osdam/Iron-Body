// Prueba en Chrome del paso 3 del Hito 6 (integraciones en el CRM) con la API
// simulada: el CRM servido con `ng serve` y puppeteer respondiendo a
// 127.0.0.1:8080 desde mock.cjs. No es el E2E con la pila real (ese es del
// tester). Uso:
//   CRM_DIR=<CRM> node shoot.cjs <url base del CRM> <ruta de puppeteer-core> <carpeta de capturas>
// `page.$eval` y `page.evaluate` son de puppeteer: ejecutan en la página las
// funciones de este fichero (nunca texto que llegue de fuera); no es `eval`.
'use strict';

const path = require('node:path');
const fs = require('node:fs');
const { crear, responder } = require('./mock.cjs');

const BASE = process.argv[2];
const puppeteer = require(process.argv[3]);
const SHOTS = process.argv[4];
fs.mkdirSync(SHOTS, { recursive: true });

const RUTA = '/marketing/ultron-studio/integraciones';
const PERMISOS = ['ultron.view', 'ultron.edit_draft', 'ultron.approve', 'marketing.view', 'ultron.campaigns_manage', 'ultron.canary_manage', 'ultron.canary_authorize', 'ultron.integrations_manage'];
const resultados = [];
const errores = [];
function check(nombre, ok, detalle) {
  resultados.push({ nombre, ok: !!ok, detalle });
  console.log(`${ok ? 'OK  ' : 'FALLA'} ${nombre}${!ok && detalle !== undefined ? ' · ' + JSON.stringify(detalle) : ''}`);
}

const CORS = { 'Access-Control-Allow-Origin': '*', 'Access-Control-Allow-Headers': '*', 'Access-Control-Allow-Methods': 'GET,POST,PUT,PATCH,DELETE,OPTIONS' };
const esperar = (ms) => new Promise((r) => setTimeout(r, ms));
/** «hidden» no sirve: el host de ib-dialog mide 0×0 (su contenido es fixed). */
const fuera = (page, sel) => page.waitForFunction((s) => !document.querySelector(s), { timeout: 10000 }, sel);

async function abrir(browser, escenario, ancho, url = RUTA) {
  const page = await browser.newPage();
  await page.setViewport({ width: ancho, height: ancho < 600 ? 844 : 900, deviceScaleFactor: 1 });
  const estado = crear(escenario);
  page.on('pageerror', (e) => errores.push(`${escenario}@${ancho}: ${e.message}`));
  page.on('console', (m) => {
    if (m.type() === 'error' && !/Failed to load resource|net::ERR|EventSource|stream/i.test(m.text())) errores.push(`${escenario}@${ancho} console: ${m.text()}`);
  });
  await page.evaluateOnNewDocument((permisos) => {
    sessionStorage.setItem('auth_token', 'token-de-prueba');
    sessionStorage.setItem('auth_user', JSON.stringify({ id: '1', email: 'root@local.test', name: 'Root', role: 'super_admin', permissions: permisos }));
  }, PERMISOS);
  await page.setRequestInterception(true);
  page.on('request', (req) => {
    const u = req.url();
    if (!u.startsWith('http://127.0.0.1:8080')) return req.continue();
    if (req.method() === 'OPTIONS') return req.respond({ status: 204, headers: CORS, body: '' });
    let cuerpo = null;
    try {
      cuerpo = req.postData() ? JSON.parse(req.postData()) : null;
    } catch {
      cuerpo = null;
    }
    const r = responder(estado, req.method(), u, cuerpo);
    if (r) return req.respond({ status: r[0], headers: { ...CORS, 'Content-Type': 'application/json' }, body: JSON.stringify(r[1]) });
    if (u.includes('/admin/auth/me')) return req.respond({ status: 404, headers: CORS, body: '{}' });
    if (u.includes('stream')) return req.respond({ status: 204, headers: CORS, body: '' });
    return req.respond({ status: 200, headers: { ...CORS, 'Content-Type': 'application/json' }, body: JSON.stringify({ ok: true, data: [] }) });
  });
  await page.goto(`${BASE}${url}`, { waitUntil: 'networkidle2', timeout: 90000 });
  return { page, estado };
}

async function lista(page) {
  await page.waitForSelector('[data-integraciones-pagina][data-integraciones-fase="ready"]', { timeout: 30000 });
  await esperar(300);
}

async function pestana(page, id, listo) {
  await page.click(`[data-integraciones-pestana="${id}"]`);
  await page.waitForSelector(listo, { timeout: 10000 });
  await esperar(350);
}

async function sinScrollHorizontal(page, etiqueta) {
  const m = await page.evaluate(() => {
    const raiz = document.querySelector('.ig-root');
    return { sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth, bw: document.body.scrollWidth, rw: raiz ? raiz.scrollWidth : 0, rc: raiz ? raiz.clientWidth : 0 };
  });
  check(`${etiqueta}: sin desplazamiento horizontal (${m.sw} ≤ ${m.cw}; raíz ${m.rw} ≤ ${m.rc})`, m.sw <= m.cw && m.bw <= m.cw && m.rw <= m.rc, m);
}

const captura = (page, nombre) => page.screenshot({ path: path.join(SHOTS, `${nombre}.png`), fullPage: false });
async function capturaDialogo(page, nombre) {
  const d = await page.$('[role="dialog"]');
  if (d) await d.screenshot({ path: path.join(SHOTS, `${nombre}.png`) });
}

async function clic(page, sel) {
  await page.waitForSelector(sel, { visible: true, timeout: 10000 });
  await page.$eval(sel, (e) => e.scrollIntoView({ block: 'nearest' }));
  await page.click(sel);
}

async function texto(page, sel) {
  return page.$eval(sel, (e) => e.textContent.replace(/\s+/g, ' ').trim()).catch(() => null);
}

async function focoAtrapado(page, etiqueta) {
  const dentro = [];
  for (let i = 0; i < 14; i++) {
    await page.keyboard.press('Tab');
    dentro.push(await page.evaluate(() => !!document.activeElement?.closest('[role="dialog"]')));
  }
  for (let i = 0; i < 4; i++) {
    await page.keyboard.down('Shift');
    await page.keyboard.press('Tab');
    await page.keyboard.up('Shift');
    dentro.push(await page.evaluate(() => !!document.activeElement?.closest('[role="dialog"]')));
  }
  check(`${etiqueta}: el foco no sale del diálogo con Tab y Mayús+Tab`, dentro.every(Boolean), dentro);
}

/** El botón principal del pie no queda bajo el asistente flotante del shell (esquina inferior derecha). */
async function pieLibre(page, sel, etiqueta) {
  const r = await page.evaluate((s) => {
    const b = document.querySelector(s);
    const w = document.querySelector('app-iron-assistant-widget button, app-iron-assistant-widget [role="button"], app-iron-assistant-widget .fab, app-iron-assistant-widget');
    if (!b || !w) return null;
    const x = b.getBoundingClientRect();
    const y = (w.querySelector('button') ?? w).getBoundingClientRect();
    const solapa = !(x.right <= y.left || y.right <= x.left || x.bottom <= y.top || y.bottom <= x.top) && y.width > 0 && y.height > 0;
    return { solapa, boton: [x.left, x.top, x.right, x.bottom], asistente: [y.left, y.top, y.right, y.bottom] };
  }, sel);
  if (r === null) return check(`${etiqueta}: (sin asistente flotante en esta sesión: no aplica)`, true);
  check(`${etiqueta}: el asistente flotante no tapa el botón`, !r.solapa, r);
}

(async () => {
  const browser = await puppeteer.launch({
    executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    headless: true,
    args: ['--no-first-run', '--no-default-browser-check', '--disable-extensions'],
  });
  try {
    for (const ancho of [1440, 390]) {
      const s = ancho === 1440 ? 'escritorio' : 'movil';

      // ── 1. Fase 1 de producción: escrituras y sonda apagadas ─────────────────────
      {
        const { page, estado } = await abrir(browser, 'apagado', ancho);
        await lista(page);
        check(`${s}: la cabecera dice que es registro`, /Registro del Studio: no cambia lo que ULTRON dice o hace/.test((await texto(page, '[data-integraciones-registro]')) ?? ''));
        check(`${s}: estado con la nota de las banderas`, (await texto(page, '[data-integraciones-nota-banderas]'))?.endsWith('Las banderas son las del proceso web, con su caché de configuración.'));
        const clases = await page.$$eval('[data-proveedor]', (xs) => xs.map((x) => `${x.getAttribute('data-proveedor')}:${x.getAttribute('data-clasificacion')}`));
        check(`${s}: las cinco áreas con su clasificación`, clases.join(',') === 'meta_ads:connected_read,twilio:connected_otp_only,twilio_voice:not_configured,canaries:registry,elevenlabs:not_configured', clases);
        await sinScrollHorizontal(page, `${s} estado`);
        await captura(page, `${s}-01-estado`);
        await pestana(page, 'meta', '[data-campana]');
        check(`${s}: campañas sin botones y con el motivo (escrituras apagadas)`, !(await page.$('[data-campana-asociar]')) && /apagadas en el servidor/.test((await texto(page, '[data-motivo-accion="campaigns"]')) ?? ''));
        check(`${s}: los dos carriles separados`, /^campaignEn Meta: Activa/.test((await texto(page, '[data-campana-meta]')) ?? '') && /En ULTRON Studio: asociación activa/.test((await texto(page, '[data-campana-ultron]')) ?? ''));
        await sinScrollHorizontal(page, `${s} campañas`);
        await captura(page, `${s}-02-campanas`);
        await pestana(page, 'canarios', '[data-coherencia]');
        check(`${s}: canarios con el texto obligatorio`, /Este registro no cambia a quién responde ULTRON/.test((await texto(page, '[data-texto-canarios]')) ?? ''));
        check(`${s}: canarios sin registrar y con la bandera dicha una vez`, !(await page.$('[data-canario-registrar]')) && (await page.$$('[data-motivo-accion]')).length === 1);
        await sinScrollHorizontal(page, `${s} canarios`);
        await captura(page, `${s}-03-canarios-solo-lectura`);
        await pestana(page, 'twilio', '[data-sonda]');
        check(`${s}: sin botón de sonda y con su motivo`, !(await page.$('[data-sonda-lanzar]')) && !!(await page.$('[data-motivo-accion="probe"]')));
        await sinScrollHorizontal(page, `${s} twilio`);
        await captura(page, `${s}-04-twilio-solo-lectura`);
        await pestana(page, 'voz', '[data-voz-estado]');
        check(`${s}: voz ELEVENLABS_NOT_CONFIGURED y sin llamadas`, (await page.$eval('[data-voz-estado]', (e) => e.getAttribute('data-estado'))) === 'ELEVENLABS_NOT_CONFIGURED' && /En este hito no se hace ninguna llamada\./.test((await texto(page, '[data-texto-voz]')) ?? ''));
        check(`${s}: voz sin ningún botón`, (await page.$$('[data-voz] button')).length === 0);
        await sinScrollHorizontal(page, `${s} voz`);
        await captura(page, `${s}-05-voz`);
        check(`${s}: en solo lectura no se escribió nada`, estado.escrituras.length === 0, estado.escrituras);
        await page.close();
      }

      // ── 2. Super Admin con escrituras: campañas ─────────────────────────────────
      {
        const { page, estado } = await abrir(browser, 'superadmin', ancho, `${RUTA}?pestana=meta`);
        await lista(page);
        await page.waitForSelector('[data-campana]', { timeout: 10000 });
        await esperar(300);
        await clic(page, '[data-campana-asociar]');
        await page.waitForSelector('[data-asociar-dialogo]', { timeout: 10000 });
        await page.click('[data-asociar-tipo="version"]');
        await page.waitForSelector('[data-asociar-version]', { timeout: 10000 });
        await page.select('[data-asociar-version]', '33333333-3333-4333-8333-333333333333');
        await esperar(250);
        await sinScrollHorizontal(page, `${s} diálogo de asociar`);
        await capturaDialogo(page, `${s}-06-asociar-version`);
        await focoAtrapado(page, `${s} diálogo de asociar`);
        await pieLibre(page, '[data-asociar-enviar]', `${s} diálogo de asociar`);
        await page.click('[data-asociar-enviar]');
        await fuera(page, '[data-asociar-dialogo]');
        await esperar(350);
        check(`${s}: una sola petición de asociar`, estado.escrituras.filter((x) => x === '/meta/links').length === 1, estado.escrituras);
        check(`${s}: las dos campañas quedan asociadas`, (await page.$$('[data-campana-ultron][data-asociada="si"]')).length === 2);
        await clic(page, '[data-campana-desactivar]');
        await page.waitForSelector('ib-reason-dialog textarea', { timeout: 10000 });
        await page.type('ib-reason-dialog textarea', 'La campaña terminó');
        check(`${s}: desactivar lleva el aviso de datos personales`, /No escribas datos personales/.test((await texto(page, '#mc-desactivar-datos')) ?? ''));
        await capturaDialogo(page, `${s}-07-desactivar-motivo`);
        await page.click('ib-reason-dialog button[type="submit"]');
        await fuera(page, 'ib-reason-dialog');
        await esperar(350);
        check(`${s}: el historial tiene la desactivada con su motivo`, /La campaña terminó/.test((await texto(page, '[data-asociaciones]')) ?? ''));
        await sinScrollHorizontal(page, `${s} campañas tras escribir`);
        await captura(page, `${s}-08-campanas-tras-escribir`);
        await page.close();
      }

      // ── 3. Super Admin: números canarios ─────────────────────────────────────────
      {
        const { page, estado } = await abrir(browser, 'superadmin', ancho, `${RUTA}?pestana=canarios`);
        await lista(page);
        await page.waitForSelector('[data-coherencia]', { timeout: 10000 });
        await esperar(300);
        await clic(page, '[data-canario-registrar]');
        await page.waitForSelector('[data-registrar-dialogo]', { timeout: 10000 });
        await esperar(250);
        await page.type('[data-registrar-telefono]', '+57 601 234 5678');
        await page.type('[data-registrar-alias]', 'Ana 3001234567');
        await page.click('[data-registrar-enviar]');
        await page.waitForSelector('[data-registrar-error="phone"]', { timeout: 10000 });
        check(`${s}: registrar avisa junto a cada campo sin pedir nada`, /WhatsApp exige un móvil/.test((await texto(page, '[data-registrar-error="phone"]')) ?? '') && estado.escrituras.length === 0);
        // La hoja global del CRM pinta todo p y small con !important: el error tiene que verse en su rojo.
        const colorError = await page.$eval('[data-registrar-error="phone"]', (x) => getComputedStyle(x).color);
        check(`${s}: el error junto al campo se ve en rojo (#fda4af)`, colorError === 'rgb(253, 164, 175)', colorError);
        await page.hover('[data-registrar-enviar]');
        check(`${s}: «Registrar» conserva su degradado con el ratón encima`, /gradient/.test(await page.$eval('[data-registrar-enviar]', (x) => getComputedStyle(x).backgroundImage)));
        await page.mouse.move(2, 2);
        await sinScrollHorizontal(page, `${s} diálogo de registrar`);
        await capturaDialogo(page, `${s}-09-registrar-errores`);
        await focoAtrapado(page, `${s} diálogo de registrar`);
        await pieLibre(page, '[data-registrar-enviar]', `${s} diálogo de registrar`);
        await page.$eval('[data-registrar-telefono]', (e) => (e.value = ''));
        await page.type('[data-registrar-telefono]', '+57 300 765 0042');
        await page.$eval('[data-registrar-alias]', (e) => (e.value = ''));
        await page.type('[data-registrar-alias]', 'Móvil de pruebas 2');
        await page.click('[data-registrar-consentimiento]');
        await esperar(150);
        check(`${s}: vista previa enmascarada`, (await texto(page, '[data-registrar-previa]')) === 'Se verá así: +57 ••• ••• 0042');
        await page.click('[data-registrar-enviar]');
        await fuera(page, '[data-registrar-dialogo]');
        await esperar(400);
        check(`${s}: el número nuevo está pendiente y enmascarado`, (await page.$$eval('[data-canario-numero]', (xs) => xs.map((x) => x.textContent.trim()))).includes('+57 ••• ••• 0042'));
        check(`${s}: el teléfono completo no está en la pantalla`, !(await page.evaluate(() => document.body.innerText.includes('765 0042') || document.body.innerText.includes('3007650042'))));
        const propio = await page.$$eval('[data-canario][data-estado="pending"]', (xs) => xs.map((x) => ({ boton: !!x.querySelector('[data-canario-accion="authorize"]'), motivo: x.querySelector('[data-motivo-accion="authorize"]')?.textContent ?? '' })));
        check(`${s}: cuatro ojos: el número propio no tiene «Autorizar» y dice por qué`, propio.some((x) => !x.boton && /Nadie autoriza un número que registró/.test(x.motivo)), propio);
        // El de voz lo registró Ana: Root lo autoriza y lo activa.
        await clic(page, '[data-canario][data-canal="voice"] [data-canario-accion="authorize"]');
        await page.waitForSelector('[data-canario][data-canal="voice"][data-estado="authorized"]', { timeout: 10000 });
        await clic(page, '[data-canario][data-canal="voice"] [data-canario-accion="activate"]');
        await page.waitForSelector('[data-canario][data-canal="voice"][data-estado="active"]', { timeout: 10000 });
        await esperar(300);
        await sinScrollHorizontal(page, `${s} canarios tras escribir`);
        await captura(page, `${s}-10-canarios-super-admin`);
        await clic(page, '[data-canario][data-canal="voice"] [data-canario-accion="revoke"]');
        await page.waitForSelector('ib-reason-dialog textarea', { timeout: 10000 });
        check(`${s}: revocar voz dice que se borra el cifrado`, /cifrado se borra/.test((await texto(page, 'ib-reason-dialog .rd-warn')) ?? ''));
        await capturaDialogo(page, `${s}-11-revocar-motivo`);
        await page.keyboard.press('Escape');
        await fuera(page, 'ib-reason-dialog');
        check(`${s}: Escape cierra y devuelve el foco a «Revocar»`, await page.evaluate(() => document.activeElement?.getAttribute('data-canario-accion') === 'revoke'));
        await page.close();
      }

      // ── 4. Administrador: sin autorizar ──────────────────────────────────────────
      {
        const { page } = await abrir(browser, 'admin', ancho, `${RUTA}?pestana=canarios`);
        await lista(page);
        await page.waitForSelector('[data-canario]', { timeout: 10000 });
        await esperar(300);
        check(`${s}: el Administrador no ve «Autorizar» y lee la llave que le falta`, !(await page.$('[data-canario-accion="authorize"]')) && /Autorizar números canarios de otra persona/.test((await texto(page, '[data-motivo-accion="authorize"]')) ?? ''));
        await captura(page, `${s}-12-administrador`);
        await page.close();
      }

      // ── 5. Twilio: sonda, enfriamiento y configuración ───────────────────────────
      {
        const { page, estado } = await abrir(browser, 'superadmin', ancho, `${RUTA}?pestana=twilio`);
        await lista(page);
        await page.waitForSelector('[data-sonda-lanzar]', { timeout: 10000 });
        check(`${s}: el texto de la sonda junto al botón`, /Solo lee: no envía mensajes ni hace llamadas/.test((await texto(page, '[data-texto-sonda]')) ?? ''));
        await clic(page, '[data-sonda-lanzar]');
        await page.waitForSelector('[data-sonda-aviso]', { timeout: 10000 });
        await clic(page, '[data-sonda-lanzar]');
        await page.waitForFunction(() => /\(en \d+ s\)/.test(document.querySelector('[data-sonda-lanzar]')?.textContent ?? ''), { timeout: 10000 });
        check(`${s}: tras el 429, el botón espera con cuenta atrás`, await page.$eval('[data-sonda-lanzar]', (b) => b.disabled));
        const colorAlerta = await page.$eval('[data-sonda-error]', (x) => getComputedStyle(x).color);
        check(`${s}: el aviso del 429 se lee en su color de alerta (#fecdd3)`, colorAlerta === 'rgb(254, 205, 211)', colorAlerta);
        check(`${s}: dos clics, dos peticiones (la segunda, 429)`, estado.escrituras.filter((x) => x === '/twilio/probe').length === 2, estado.escrituras);
        await page.$eval('[data-sonda]', (e) => e.scrollIntoView({ block: 'nearest' }));
        await esperar(200);
        await sinScrollHorizontal(page, `${s} twilio`);
        await captura(page, `${s}-13-sonda-429`);
        await clic(page, '[data-config="twilio"] [data-config-reconocer]');
        await page.waitForSelector('[data-config-dialogo]', { timeout: 10000 });
        await page.click('[data-config-reconocer-enviar]');
        await page.waitForSelector('[data-config-cambio]', { timeout: 10000 });
        await esperar(200);
        await capturaDialogo(page, `${s}-14-reconocer-cambio`);
        await page.click('[data-config-reconocer-enviar]');
        await fuera(page, '[data-config-dialogo]');
        await esperar(300);
        check(`${s}: tras confirmar con la huella nueva, autorizada`, (await page.$eval('[data-config="twilio"]', (e) => e.getAttribute('data-config-estado'))) === 'authorized');
        await page.close();
      }

      // ── 6. Backend del Hito 5 ────────────────────────────────────────────────────
      {
        const { page } = await abrir(browser, 'h5', ancho);
        await page.waitForSelector('[data-integraciones-sin-h6]', { timeout: 30000 });
        check(`${s}: backend H5: el texto de I10`, (await texto(page, '[data-integraciones-sin-h6] h2')) === 'El servidor todavía no tiene integraciones (Hito 6)');
        await captura(page, `${s}-15-backend-h5`);
        await page.close();
        const { page: tablero } = await abrir(browser, 'h5', ancho, '/marketing/ultron-studio');
        await tablero.waitForSelector('[data-ultron-studio][data-fase="ready"]', { timeout: 30000 });
        await esperar(400);
        const enlace = await tablero.$eval('a[data-integraciones-enlace]', (a) => a.getAttribute('href')).catch(() => null);
        check(`${s}: el tablero sigue igual y enlaza «Integraciones»`, enlace === '/marketing/ultron-studio/integraciones');
        await captura(tablero, `${s}-16-tablero-enlace`);
        await tablero.close();
      }
    }
  } finally {
    await browser.close();
  }
  check('sin errores de JavaScript', errores.length === 0, errores);
  fs.writeFileSync(path.join(SHOTS, 'chrome-resultados.json'), JSON.stringify({ resultados, errores }, null, 2));
  const fallos = resultados.filter((r) => !r.ok).length;
  console.log(`\n${resultados.length - fallos}/${resultados.length} comprobaciones en verde`);
  process.exit(fallos ? 1 : 0);
})();
