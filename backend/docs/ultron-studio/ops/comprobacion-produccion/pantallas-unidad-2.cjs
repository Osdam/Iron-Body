// COMPROBACIÓN REAL DE LAS PANTALLAS NUEVAS DEL CRM EN PRODUCCIÓN (lead, 23-11-2026 · unidad 2 del cierre operativo).
// SOLO LECTURA: abre el CRM de producción con la sesión de la cuenta técnica y lee lo que enseña cada pantalla. Lo único
// que pulsa son pestañas, opciones del formulario del lanzador y «Estimar el coste» (POST lab/estimate: no escribe nada).
// NO pulsa «Ejecutar», no guarda nada, no crea escenarios, no publica. No abre fichas de personas.
//   NODE_PATH=<Iron-Body_Front>/node_modules node pantallas-unidad-2.cjs <sesion.json> <dir de salida> <uuid de la versión>
// La sesión, con sesion-tecnica.sh (cuenta técnica 32; se abre y se cierra en cada uso). Las capturas van al directorio de
// salida, FUERA del repositorio. `page.evaluate` y `page.$$eval` ejecutan en la página funciones FIJAS de este fichero
// para leer el DOM: no se evalúa ningún texto que venga de fuera.
'use strict';
const fs = require('fs');
const path = require('path');
const [, , F_SESION, DIR, VERSION] = process.argv;
const CRM = 'https://ironbodyneiva.cloud';
const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const puppeteer = require('puppeteer-core');
const dormir = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  const { token, user } = JSON.parse(fs.readFileSync(F_SESION, 'utf8'));
  fs.mkdirSync(DIR, { recursive: true });
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox', '--window-size=1440,1500'] });
  const errores = [];
  const respuestas = [];
  try {
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 1500 });
    page.on('pageerror', (e) => errores.push(String(e.message || e).slice(0, 200)));
    page.on('response', (r) => {
      const u = r.url();
      if (!u.includes('/api/')) return;
      const s = r.status();
      if (s >= 400) respuestas.push(`${r.request().method()} ${u.replace(/^https:\/\/[^/]+/, '').replace(/\?.*/, '').replace(/[0-9a-f]{8}-[0-9a-f-]{27}/g, '<uuid>')} → ${s}`);
    });
    await page.evaluateOnNewDocument((t, u) => { try { sessionStorage.setItem('auth_token', t); sessionStorage.setItem('auth_user', u); sessionStorage.setItem('auth_session_type', 'session'); } catch { /* sin almacenamiento */ } }, token, JSON.stringify(user));
    const T = (sel) => page.evaluate((s) => { const e = document.querySelector(s); return e ? e.innerText.replace(/\s+/g, ' ').trim() : null; }, sel);
    const seccion = async (nombre, fn) => { console.log(`\n── ${nombre}`); try { await fn(); } catch (e) { console.log('   ✗ NO SE PUDO COMPROBAR:', String(e.message || e).slice(0, 220)); } };

    await seccion('A. LABORATORIO · ' + CRM + '/marketing/ultron-studio/laboratorio?version=…', async () => {
      await page.goto(`${CRM}/marketing/ultron-studio/laboratorio?version=${VERSION}`, { waitUntil: 'domcontentloaded', timeout: 120000 });
      await page.waitForSelector('[data-lab-pagina][data-lab-fase="ready"]', { timeout: 90000 });
      await dormir(1500);
      console.log('   versión del CRM que carga la página:', await page.evaluate(async () => { try { const r = await fetch('/version.json', { cache: 'no-store' }); return JSON.stringify(await r.json()); } catch (e) { return 'no se pudo leer'; } }));
      console.log('   título:', await T('h1'), '· modo y modelo:', await T('[data-lab-modo]'));
      console.log('   presupuesto (cabecera):', await T('us-lab-budget'));
      console.log('   pestañas:', JSON.stringify(await page.$$eval('[data-lab-pestana]', (xs) => xs.map((x) => `${x.innerText.replace(/\s+/g, ' ').trim()}${x.getAttribute('aria-selected') === 'true' ? ' (abierta)' : ''}`))));
      if (await page.$('[data-lab-pestana="ejecuciones"][aria-selected="false"]')) { await page.click('[data-lab-pestana="ejecuciones"]'); await dormir(800); }
      await page.waitForSelector('[data-lab-lanzador]', { timeout: 60000 });
      await dormir(2500);
      console.log('   «Qué se prueba»:', await page.evaluate(() => { const s = document.querySelector('[data-lab-objetivo-version]'); return s ? (s.selectedOptions[0]?.textContent || '').replace(/\s+/g, ' ').trim() : 'sin selector de versión'; }));
      console.log('   «Cómo se prueba» (lo nuevo):');
      for (const o of await page.$$eval('[data-lab-fidelidad] label', (xs) => xs.map((x) => ({ t: x.innerText.replace(/\s+/g, ' ').trim(), c: x.querySelector('input')?.checked })))) console.log(`      ${o.c ? '(•)' : '( )'} ${o.t}`);
      const escenarios = await page.$$eval('[data-lab-elegir]', (xs) => xs.map((x) => x.getAttribute('data-lab-elegir')));
      console.log('   escenarios en el lanzador:', escenarios.length, '·', escenarios.join(' '), '· contador:', await T('[data-lab-elegidos]'));
      // El recorrido del motor con UN escenario del catálogo, solo para ESTIMAR (no se lanza).
      await page.click('[data-lab-fidelidad-opcion="engine"]');
      await page.click('[data-lab-elegir="C-02"]');
      await dormir(500);
      console.log('   elegido «Recorrido del motor» y el escenario C-02 · contador:', await T('[data-lab-elegidos]'), '· aviso junto a «Estimar»:', await page.evaluate(() => { const b = document.querySelector('[data-lab-estimar]'); return b ? (b.disabled ? 'deshabilitado: ' + (b.parentElement?.querySelector('.lab-help')?.innerText || '') : 'habilitado') : 'no hay botón'; }));
      await page.click('[data-lab-estimar]');
      await page.waitForSelector('[data-lab-estimacion]', { timeout: 90000 });
      await dormir(1200);
      const est = await page.evaluate(() => {
        const t = (s) => { const e = document.querySelector(s); return e ? e.innerText.replace(/\s+/g, ' ').trim() : null; };
        const b = document.querySelector('[data-lab-ejecutar]');
        return {
          estado: document.querySelector('[data-lab-estimacion]')?.getAttribute('data-lab-estimacion-estado'),
          motor: t('[data-lab-estimacion-motor]'), max: t('[data-lab-estimacion-max]'), esperado: t('[data-lab-estimacion-esperado]'), llamadas: t('[data-lab-estimacion-llamadas]'),
          desglose: [...document.querySelectorAll('[data-lab-estimacion] dl > div')].map((d) => d.innerText.replace(/\s+/g, ' ').trim()),
          negativa: t('[data-lab-negativa]'), noSoportado: t('[data-lab-motor-no-soportado]'), reglasFuera: t('[data-lab-reglas-fuera]'),
          boton: b ? b.innerText.replace(/\s+/g, ' ').trim() : null, botonDeshabilitado: b ? b.disabled : null, motivo: t('[data-lab-ejecutar-motivo]'),
        };
      });
      console.log('   ESTIMACIÓN del recorrido del motor · estado:', est.estado);
      console.log('      ', est.motor);
      console.log('      como mucho:', est.max, '·', est.esperado);
      console.log('      ', est.llamadas);
      for (const d of est.desglose) console.log('      ·', d);
      console.log('      negativa:', est.negativa, '· «servidor que no conoce el recorrido»:', est.noSoportado);
      console.log('      botón de lanzar (NO se pulsa):', est.boton, '· deshabilitado:', est.botonDeshabilitado, '·', est.motivo);
      await page.evaluate(() => document.querySelector('[data-lab-lanzador]')?.scrollIntoView({ block: 'start' }));
      await dormir(500);
      await page.screenshot({ path: path.join(DIR, 'u2-lab-lanzador-motor.png'), fullPage: true });
      console.log('   lista de ejecuciones de la versión:', (await page.$$('[data-lab-run-item]')).length, '· filtros de la lista:', await page.evaluate(() => [...document.querySelectorAll('us-lab-runs select, us-lab-runs [role="radiogroup"], us-lab-runs fieldset')].map((x) => x.innerText.replace(/\s+/g, ' ').trim().slice(0, 160)).join(' | ') || '(sin filtros a la vista)'));
      await page.click('[data-lab-pestana="escenarios"]');
      await dormir(2000);
      console.log('   pestaña «Escenarios» · botones:', JSON.stringify(await page.$$eval('#lab-panel-escenarios button', (xs) => [...new Set(xs.map((x) => x.innerText.replace(/\s+/g, ' ').trim()).filter(Boolean))].slice(0, 14))));
      console.log('   pestaña «Escenarios» · encabezados:', JSON.stringify(await page.$$eval('#lab-panel-escenarios h2, #lab-panel-escenarios h3', (xs) => xs.map((x) => x.innerText.replace(/\s+/g, ' ').trim()).slice(0, 10))));
      await page.screenshot({ path: path.join(DIR, 'u2-lab-escenarios.png'), fullPage: true });
    });

    await seccion('B. AUTORIZACIONES DE CONTACTO (su parte del servidor llega con la unidad 4) · /marketing/automatizaciones', async () => {
      await page.goto(`${CRM}/marketing/automatizaciones`, { waitUntil: 'domcontentloaded', timeout: 120000 });
      await page.waitForSelector('[role="tab"]', { timeout: 90000 });
      await dormir(2000);
      const tabs = await page.$$eval('[role="tab"]', (xs) => xs.map((x) => x.innerText.replace(/\s+/g, ' ').trim()));
      console.log('   pestañas:', JSON.stringify(tabs));
      const ok = await page.evaluate(() => { const b = [...document.querySelectorAll('[role="tab"]')].find((x) => /Autorizaciones de contacto/.test(x.innerText)); if (!b) return false; b.click(); return true; });
      console.log('   pestaña «Autorizaciones de contacto»:', ok ? 'existe y se abre' : 'NO APARECE');
      await dormir(4000);
      console.log('   lo que enseña:', await page.evaluate(() => { const p = [...document.querySelectorAll('[role="tabpanel"]')].find((x) => !x.hidden && x.offsetParent !== null) || document.querySelector('main'); return p ? p.innerText.replace(/\s+/g, ' ').trim().slice(0, 520) : null; }));
      await page.screenshot({ path: path.join(DIR, 'u2-autorizaciones.png'), fullPage: false });
    });

    await seccion('C. VOZ (su parte del servidor llega con la unidad 6) · /marketing/ultron-studio/voz', async () => {
      await page.goto(`${CRM}/marketing/ultron-studio/voz`, { waitUntil: 'domcontentloaded', timeout: 120000 });
      await page.waitForSelector('h1', { timeout: 90000 });
      await dormir(5000);
      console.log('   título:', await T('h1'), '· pestañas:', JSON.stringify(await page.$$eval('[role="tab"]', (xs) => xs.map((x) => x.innerText.replace(/\s+/g, ' ').trim()))));
      console.log('   avisos a la vista:', JSON.stringify(await page.$$eval('[role="alert"], .lab-alert, .ig-alert', (xs) => xs.filter((x) => x.offsetParent !== null).map((x) => x.innerText.replace(/\s+/g, ' ').trim().slice(0, 200)).slice(0, 4))));
    });

    await seccion('D. PLANES (el permiso nuevo llega con la unidad 6) · /plans', async () => {
      await page.goto(`${CRM}/plans`, { waitUntil: 'domcontentloaded', timeout: 120000 });
      await dormir(6000);
      console.log('   título:', await T('h1'), '· avisos de error a la vista:', JSON.stringify(await page.$$eval('[role="alert"]', (xs) => xs.filter((x) => x.offsetParent !== null).map((x) => x.innerText.replace(/\s+/g, ' ').trim().slice(0, 160)).slice(0, 3))));
      console.log('   botones de la pantalla (los distintos):', JSON.stringify(await page.$$eval('main button, .page button', (xs) => [...new Set(xs.map((x) => (x.getAttribute('aria-label') || x.title || x.innerText || '').replace(/\s+/g, ' ').trim()).filter(Boolean))].slice(0, 16))));
    });

    console.log('\n── RESUMEN');
    console.log('   errores de página (JavaScript):', errores.length, JSON.stringify(errores.slice(0, 3)));
    const porRuta = {};
    for (const r of respuestas) porRuta[r] = (porRuta[r] || 0) + 1;
    console.log('   respuestas de la API con 4xx o 5xx durante el recorrido:', respuestas.length);
    for (const [k, n] of Object.entries(porRuta)) console.log('      ·', k, '×' + n);
  } finally {
    await browser.close();
  }
})().catch((e) => { console.error('ERROR', String(e.message || e).slice(0, 300)); process.exit(2); });
