// COMPROBACIÓN REAL DE LA PANTALLA DEL LABORATORIO EN PRODUCCIÓN (lead, 12-11-2026). Solo lectura.
// Abre el CRM con la sesión de la cuenta técnica en el enlace que se le dará al propietario, lee la lista de ejecuciones
// de la versión, abre la primera y lee lo que enseña la ficha y la sección «Veredicto». No pulsa ningún botón que
// escriba: no lanza, no cancela y no registra veredictos (la cuenta técnica es la autora y no podría).
//   NODE_PATH=<Iron-Body_Front>/node_modules node lab-pantalla.cjs <sesion.json> <dir de salida> <uuid de la versión>
// `page.$$eval` y `page.evaluate` ejecutan en la página funciones FIJAS de este fichero para leer el DOM.
'use strict';
const fs = require('fs');
const path = require('path');
const [, , F_SESION, DIR, VERSION] = process.argv;
const CRM = 'https://ironbodyneiva.cloud';
const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const puppeteer = require('puppeteer-core');
const dormir = (ms) => new Promise((r) => setTimeout(r, ms));
const n = (t) => String(t || '').replace(/\s+/g, ' ').trim();

(async () => {
  const { token, user } = JSON.parse(fs.readFileSync(F_SESION, 'utf8'));
  fs.mkdirSync(DIR, { recursive: true });
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox', '--window-size=1440,1400'] });
  try {
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 1400 });
    const errores = [];
    page.on('pageerror', (e) => errores.push(String(e.message || e).slice(0, 200)));
    await page.evaluateOnNewDocument((t, u) => { try { sessionStorage.setItem('auth_token', t); sessionStorage.setItem('auth_user', u); sessionStorage.setItem('auth_session_type', 'session'); } catch { /* sin almacenamiento */ } }, token, JSON.stringify(user));
    await page.goto(`${CRM}/marketing/ultron-studio/laboratorio?version=${VERSION}`, { waitUntil: 'domcontentloaded', timeout: 120000 });
    await page.waitForSelector('[data-lab-run-item]', { timeout: 90000 });
    await dormir(1500);
    const titulo = await page.evaluate(() => (document.querySelector('h1')?.textContent || '').replace(/\s+/g, ' ').trim());
    const pestana = await page.evaluate(() => (document.querySelector('[role="tab"][aria-selected="true"]')?.textContent || '').replace(/\s+/g, ' ').trim());
    const filas = await page.$$eval('[data-lab-run-item]', (xs) => xs.map((x) => ({ uuid: x.getAttribute('data-lab-run-item'), texto: x.innerText.replace(/\s+/g, ' ').trim(), aria: x.querySelector('.lr-resultados')?.getAttribute('aria-label') || null })));
    console.log('TÍTULO:', titulo, '· PESTAÑA:', pestana);
    console.log('EJECUCIONES EN LA LISTA:', filas.length);
    for (const f of filas) console.log('  ·', String(f.uuid).slice(0, 8), '|', f.texto.slice(0, 330), '|', f.aria);
    await page.screenshot({ path: path.join(DIR, 'lab-lista.png'), fullPage: false });

    // Abre la primera ejecución de la lista (un GET) y lee su ficha.
    await page.click('[data-lab-run-item]');
    await page.waitForSelector('[data-lab-veredicto]', { timeout: 90000 });
    await dormir(2000);
    const ficha = await page.evaluate(() => {
      const t = (e) => (e ? e.innerText.replace(/\s+/g, ' ').trim() : null);
      const v = document.querySelector('[data-lab-veredicto]');
      return {
        veredictoEstado: v?.getAttribute('data-lab-veredicto-estado') || null,
        veredictoTexto: t(v)?.slice(0, 1400) || null,
        decisiones: [...document.querySelectorAll('[data-lab-veredicto-decision]')].map((i) => ({ id: i.getAttribute('data-lab-veredicto-decision'), etiqueta: t(i.closest('label')) })),
        motivo: t(document.querySelector('[data-lab-veredicto-motivo]')),
        boton: t(document.querySelector('[data-lab-veredicto-enviar]')),
        encabezados: [...document.querySelectorAll('h2, h3')].map((h) => t(h)).filter(Boolean).slice(0, 30),
        casos: [...document.querySelectorAll('[data-lab-caso], [data-lab-case], .lc-caso, us-lab-case')].slice(0, 12).map((c) => t(c)?.slice(0, 160)),
      };
    });
    console.log('FICHA · encabezados:', JSON.stringify(ficha.encabezados));
    console.log('FICHA · casos (primeros):', JSON.stringify(ficha.casos.slice(0, 4)));
    console.log('VEREDICTO · estado:', ficha.veredictoEstado, '· botón:', ficha.boton);
    console.log('VEREDICTO · decisiones:', JSON.stringify(ficha.decisiones));
    console.log('VEREDICTO · motivo (cuenta técnica = autora):', ficha.motivo);
    console.log('VEREDICTO · texto:', ficha.veredictoTexto);
    await page.evaluate(() => document.querySelector('[data-lab-veredicto]')?.scrollIntoView({ block: 'center' }));
    await dormir(600);
    await page.screenshot({ path: path.join(DIR, 'lab-veredicto.png'), fullPage: false });
    await page.screenshot({ path: path.join(DIR, 'lab-ejecucion.png'), fullPage: true });
    console.log('errores de página:', errores.length, JSON.stringify(errores.slice(0, 2)));
  } finally {
    await browser.close();
  }
})().catch((e) => { console.error('ERROR', String(e.message || e).slice(0, 300)); process.exit(2); });
