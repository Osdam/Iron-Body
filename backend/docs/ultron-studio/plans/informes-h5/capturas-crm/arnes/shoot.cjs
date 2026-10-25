// Prueba en Chrome del paso 4 (H5, CRM) con la API simulada: el CRM servido con
// `ng serve` y puppeteer respondiendo a 127.0.0.1:8080 desde mock.cjs. No es el
// E2E con la pila real (ese es del tester). Uso:
//   node shoot.cjs <url base del CRM> <ruta de puppeteer-core> <carpeta de capturas>
'use strict';

const path = require('node:path');
const fs = require('node:fs');
const { crear, responder } = require('./mock.cjs');

const BASE = process.argv[2];
const puppeteer = require(process.argv[3]);
const SHOTS = process.argv[4];
fs.mkdirSync(SHOTS, { recursive: true });

const PERMISOS = ['ultron.view', 'ultron.edit_draft', 'ultron.manage_rules', 'ultron.approve', 'ultron.publish', 'ultron.rollback', 'marketing.view'];
const resultados = [];
const errores = [];
function check(nombre, ok, detalle) {
  resultados.push({ nombre, ok: !!ok, detalle });
  console.log(`${ok ? 'OK  ' : 'FALLA'} ${nombre}${!ok && detalle !== undefined ? ' · ' + JSON.stringify(detalle) : ''}`);
}

const CORS = {
  'Access-Control-Allow-Origin': '*',
  'Access-Control-Allow-Headers': '*',
  'Access-Control-Allow-Methods': 'GET,POST,PUT,PATCH,DELETE,OPTIONS',
};

async function abrir(browser, escenario, ancho) {
  const page = await browser.newPage();
  await page.setViewport({ width: ancho, height: ancho < 600 ? 844 : 900, deviceScaleFactor: 1 });
  const estado = crear(escenario);
  page.on('pageerror', (e) => errores.push(`${escenario}@${ancho}: ${e.message}`));
  page.on('console', (m) => {
    if (m.type() === 'error' && !/Failed to load resource|net::ERR|EventSource|stream/i.test(m.text())) errores.push(`${escenario}@${ancho} console: ${m.text()}`);
  });
  await page.evaluateOnNewDocument((permisos) => {
    sessionStorage.setItem('auth_token', 'token-de-prueba');
    sessionStorage.setItem('auth_user', JSON.stringify({ id: '7', email: 'publicadora@local.test', name: 'Publicadora E2E', role: 'super_admin', permissions: permisos }));
  }, PERMISOS);
  await page.setRequestInterception(true);
  page.on('request', (req) => {
    const url = req.url();
    if (!url.startsWith('http://127.0.0.1:8080')) return req.continue();
    if (req.method() === 'OPTIONS') return req.respond({ status: 204, headers: CORS, body: '' });
    let cuerpo = null;
    try {
      cuerpo = req.postData() ? JSON.parse(req.postData()) : null;
    } catch {
      cuerpo = null;
    }
    const r = responder(estado, req.method(), url, cuerpo);
    if (r) return req.respond({ status: r[0], headers: { ...CORS, 'Content-Type': 'application/json' }, body: JSON.stringify(r[1]) });
    if (url.includes('/admin/auth/me')) return req.respond({ status: 404, headers: CORS, body: '{}' });
    if (url.includes('stream')) return req.respond({ status: 204, headers: CORS, body: '' });
    return req.respond({ status: 200, headers: { ...CORS, 'Content-Type': 'application/json' }, body: JSON.stringify({ ok: true, data: [] }) });
  });
  await page.goto(`${BASE}/marketing/ultron-studio`, { waitUntil: 'networkidle2', timeout: 90000 });
  await page.waitForSelector('[data-ultron-studio][data-fase="ready"]', { timeout: 30000 });
  await esperar(400);
  return { page, estado };
}

const esperar = (ms) => new Promise((r) => setTimeout(r, ms));

/** Espera a que el selector ya no esté en el DOM. «hidden» no sirve: el host de ib-dialog mide 0×0 (su contenido es fixed). */
const fuera = (page, sel) => page.waitForFunction((s) => !document.querySelector(s), { timeout: 10000 }, sel);

async function sinScrollHorizontal(page, etiqueta) {
  const m = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth, bw: document.body.scrollWidth }));
  check(`${etiqueta}: sin desplazamiento horizontal (${m.sw} ≤ ${m.cw})`, m.sw <= m.cw && m.bw <= m.cw, m);
}

async function captura(page, nombre) {
  await page.screenshot({ path: path.join(SHOTS, `${nombre}.png`) });
}

/** Captura del diálogo abierto entero (su panel) y del pie. */
async function capturaDialogo(page, nombre) {
  const d = await page.$('[role="dialog"]');
  if (d) await d.screenshot({ path: path.join(SHOTS, `${nombre}.png`) });
}

async function clic(page, sel) {
  await page.waitForSelector(sel, { visible: true, timeout: 10000 });
  await page.click(sel);
}

async function abrirVersion(page) {
  await clic(page, '[data-panel-versiones]');
  await clic(page, '[data-version-item="6f1c2a9e-3b4d-4c5e-8f60-7a8b9c0d1e2f"]');
  await page.waitForSelector('.vp-title', { timeout: 10000 });
  await esperar(250);
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

async function texto(page, sel) {
  return page.$eval(sel, (e) => e.textContent.replace(/\s+/g, ' ').trim()).catch(() => null);
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

      // ── 1. Aprobada: barra en Legacy, detalle, diálogo de publicar, envío ─────────
      {
        const { page, estado } = await abrir(browser, 'aprobada', ancho);
        check(`${s}: la barra de la publicación está y dice Legacy`, /Legacy: el Studio no declara ninguna versión/.test((await texto(page, '[data-estado-publicacion]')) ?? ''));
        check(`${s}: el aviso del motor dice lo de H5`, /solo anota la versión publicada/.test((await texto(page, '[data-aviso-motor]')) ?? ''));
        check(`${s}: sin «Publicar» suelto en la cabecera`, !(await page.$('.us-top [data-publicar]')));
        await sinScrollHorizontal(page, `${s} tablero`);
        await captura(page, `${s}-01-tablero-barra-legacy`);
        await abrirVersion(page);
        check(`${s}: «Publicar en canario» en el detalle`, !!(await page.$('[data-publicar]')));
        check(`${s}: texto obligatorio junto al botón`, /no cambia lo que ULTRON dice ni hace/.test((await texto(page, '[data-ayuda-publicar]')) ?? ''));
        await captura(page, `${s}-02-detalle-aprobada`);
        await clic(page, '[data-publicar]');
        await page.waitForSelector('[data-publicacion-dialogo][data-publicacion-estado="publicable"]', { timeout: 10000 });
        await esperar(300);
        check(`${s}: el diálogo enseña 10 requisitos y 4 must_match`, (await page.$$('[data-publicacion-requisito]')).length === 10 && (await page.$$('[data-publicacion-clase="must_match"]')).length === 4);
        check(`${s}: enviar deshabilitado hasta reconocer`, await page.$eval('[data-publicacion-enviar]', (b) => b.disabled));
        await sinScrollHorizontal(page, `${s} diálogo de publicar`);
        await capturaDialogo(page, `${s}-03-publicar-vista-previa`);
        await focoAtrapado(page, `${s} diálogo de publicar`);
        for (const id of ['persona.tone', 'rule:S-003']) await page.click(`[data-publicacion-sin-efecto="${id}"]`);
        await page.$eval('.ibd-body', (b) => (b.scrollTop = b.scrollHeight));
        await esperar(150);
        check(`${s}: con todo reconocido se puede enviar`, !(await page.$eval('[data-publicacion-enviar]', (b) => b.disabled)));
        await capturaDialogo(page, `${s}-04-publicar-reconocido`);
        await page.click('[data-publicacion-enviar]');
        await fuera(page, '[data-publicacion-dialogo]');
        await page.waitForFunction(() => /Publicada en canario/.test(document.querySelector('[data-resultado]')?.textContent ?? ''), { timeout: 10000 });
        await esperar(300);
        check(`${s}: tras publicar, la barra dice el canario`, /En el canario: «Cobro con enlaces \(propuesta\)»/.test((await texto(page, '[data-estado-publicacion-vigente]')) ?? ''));
        check(`${s}: el detalle pasa a Canario con su entrada de la cadena`, (await page.$eval('.vp-meta .vp-status', (e) => e.getAttribute('data-status'))) === 'CANARY' && (await page.$$('[data-versiones] [data-publicacion-entrada]')).length === 1);
        check(`${s}: una sola petición de publicar`, estado.escrituras.filter((x) => x.endsWith('/publish')).length === 1, estado.escrituras);
        await captura(page, `${s}-05-publicada-en-canario`);
        // Comparar con la línea base: el lado de la versión con la huella alterada.
        await clic(page, '[data-comparar]');
        await page.waitForSelector('[data-integridad-lado="to"]', { timeout: 10000 });
        await page.$eval('[data-comparacion]', (e) => e.scrollIntoView({ block: 'center' }));
        await esperar(200);
        check(`${s}: insignia «Huella alterada» en el lado de la versión`, /Huella alterada/.test((await texto(page, '[data-integridad-lado="to"]')) ?? ''));
        await sinScrollHorizontal(page, `${s} comparar`);
        await captura(page, `${s}-06-comparar-huella-alterada`);
        await page.close();
      }

      // ── 2. Bloqueada: la puerta del laboratorio ──────────────────────────────────
      {
        const { page } = await abrir(browser, 'bloqueada', ancho);
        await abrirVersion(page);
        await clic(page, '[data-publicar]');
        await page.waitForSelector('[data-publicacion-dialogo][data-publicacion-estado="bloqueada"]', { timeout: 10000 });
        await esperar(250);
        check(`${s}: bloqueada, el requisito del laboratorio dice por qué`, (await page.$eval('[data-publicacion-requisito="labGate"]', (e) => e.getAttribute('data-ok'))) === 'false');
        check(`${s}: bloqueada, el motivo de no enviar es visible`, /Aún no se puede publicar/.test((await texto(page, '[data-publicacion-falta]')) ?? ''));
        await capturaDialogo(page, `${s}-07-publicar-bloqueada`);
        await page.keyboard.press('Escape');
        await fuera(page, '[data-publicacion-dialogo]');
        check(`${s}: Escape cierra el diálogo y devuelve el foco al botón`, await page.evaluate(() => document.activeElement?.hasAttribute('data-publicar') ?? false));
        await page.close();
      }

      // ── 3. Canario con llave en canario: promover no, revertir sí; historial; Legacy
      {
        const { page } = await abrir(browser, 'canario', ancho);
        check(`${s}: la barra dice canario con sello correcto`, /Correcto: firma verificada y sin deriva/.test((await texto(page, '[data-estado-publicacion]')) ?? ''));
        await captura(page, `${s}-08-tablero-barra-canario`);
        await abrirVersion(page);
        check(`${s}: en canario, «Revertir» y el motivo de no promover`, !!(await page.$('[data-revertir]')) && /todas las conversaciones/.test((await texto(page, '[data-motivo-accion="promote"]')) ?? ''));
        await captura(page, `${s}-09-detalle-canario`);
        await clic(page, '[data-revertir]');
        await page.waitForSelector('[data-revertir-dialogo] [role="dialog"]', { timeout: 5000 });
        await esperar(300); // la entrada del diálogo anima la opacidad: la captura, ya entero
        check(`${s}: revertir pide motivo con el aviso de datos personales`, !!(await page.$('#vp-revertir-datos[data-aviso-datos-personales]')));
        await capturaDialogo(page, `${s}-10-revertir-motivo`);
        await page.type('[data-revertir-dialogo] textarea', 'El canario contestó con un enlace viejo');
        await page.click('[data-revertir-dialogo] button[type="submit"]');
        await fuera(page, '[data-revertir-dialogo]');
        await page.waitForFunction(() => document.querySelector('.vp-meta .vp-status')?.getAttribute('data-status') === 'ROLLED_BACK', { timeout: 10000 });
        check(`${s}: revertida, la barra vuelve a Legacy`, /Legacy: el Studio no declara/.test((await texto(page, '[data-estado-publicacion]')) ?? ''));
        await clic(page, '[data-publicaciones-abrir]');
        await page.waitForSelector('[data-publicaciones-dialogo] [data-publicacion-entrada]', { timeout: 10000 });
        await esperar(200);
        check(`${s}: el historial enseña las dos entradas`, (await page.$$('[data-publicaciones-dialogo] [data-publicacion-entrada]')).length === 2);
        await sinScrollHorizontal(page, `${s} historial`);
        await capturaDialogo(page, `${s}-11-historial`);
        await page.keyboard.press('Escape');
        await page.close();
      }

      // ── 4. Deriva y Legacy desde la barra ────────────────────────────────────────
      {
        const { page } = await abrir(browser, 'deriva', ancho);
        check(`${s}: deriva a la vista, parámetro a parámetro`, (await page.$eval('[data-estado-publicacion-deriva]', (e) => e.getAttribute('data-deriva'))) === '1');
        await sinScrollHorizontal(page, `${s} deriva`);
        await captura(page, `${s}-12-barra-deriva`);
        await clic(page, '[data-legacy]');
        await page.waitForSelector('[data-legacy-dialogo] [role="dialog"]', { timeout: 5000 });
        await esperar(300);
        await capturaDialogo(page, `${s}-13-legacy-motivo`);
        await focoAtrapado(page, `${s} diálogo de Legacy`);
        await page.type('[data-legacy-dialogo] textarea', 'Paramos de declarar hasta revisar la deriva');
        await page.click('[data-legacy-dialogo] button[type="submit"]');
        await fuera(page, '[data-legacy-dialogo]');
        await page.waitForSelector('[data-estado-publicacion-aviso]', { timeout: 10000 });
        check(`${s}: tras Legacy, la barra lo dice y ofrece el historial`, /Legacy: el Studio dejó de declarar/.test((await texto(page, '[data-estado-publicacion-aviso]')) ?? ''));
        await page.close();
      }

      // ── 5. Llave en todas: evidencia con 429 y cuenta atrás; promover ───────────
      {
        const { page, estado } = await abrir(browser, 'todas', ancho);
        estado.retry = false;
        await abrirVersion(page);
        await clic(page, '[data-promover]');
        await page.waitForSelector('[data-evidencia][data-evidencia-estado="pasa"]', { timeout: 10000 });
        await esperar(250);
        check(`${s}: evidencia, 9 medidas y pasa`, (await page.$$('[data-evidencia-medida]')).length === 9);
        await sinScrollHorizontal(page, `${s} evidencia`);
        await capturaDialogo(page, `${s}-14-evidencia-pasa`);
        estado.retry = true;
        await page.click('[data-evidencia-actualizar]');
        await page.waitForSelector('[data-evidencia-espera]', { timeout: 10000 });
        await esperar(1200);
        const etiqueta = await texto(page, '[data-evidencia-actualizar]');
        check(`${s}: tras el 429, «Actualizar» espera con cuenta atrás`, /Actualizar \(en (4[01]|39) s\)/.test(etiqueta ?? '') && (await page.$eval('[data-evidencia-actualizar]', (b) => b.disabled)), etiqueta);
        await capturaDialogo(page, `${s}-15-evidencia-429`);
        await page.click('[data-evidencia-enviar]');
        await fuera(page, '[data-evidencia]');
        await page.waitForFunction(() => /Promovida a todas/.test(document.querySelector('[data-resultado]')?.textContent ?? ''), { timeout: 10000 });
        check(`${s}: promovida, la barra dice «En todas»`, /En todas: «Cobro con enlaces/.test((await texto(page, '[data-estado-publicacion-vigente]')) ?? ''));
        await captura(page, `${s}-16-promovida`);
        await page.close();
      }

      // ── 6. Administrador sin llaves ──────────────────────────────────────────────
      {
        const { page } = await abrir(browser, 'admin', ancho);
        await abrirVersion(page);
        check(`${s}: Administrador sin botones y con el motivo`, !(await page.$('[data-publicar]')) && /Hace falta el permiso/.test((await texto(page, '[data-motivo-accion="publish"]')) ?? ''));
        check(`${s}: Administrador sin «Volver a Legacy»`, !(await page.$('[data-legacy]')));
        await captura(page, `${s}-17-administrador`);
        await page.close();
      }
    }
  } finally {
    await browser.close();
  }
  check('sin errores de JavaScript en ninguna página', errores.length === 0, errores);
  const fallos = resultados.filter((r) => !r.ok);
  console.log(`\n${resultados.length - fallos.length}/${resultados.length} comprobaciones`);
  fs.writeFileSync(path.join(SHOTS, 'chrome-resultados.json'), JSON.stringify({ resultados, errores }, null, 2));
  process.exit(fallos.length ? 1 : 0);
})().catch((e) => {
  console.error(e);
  process.exit(2);
});
