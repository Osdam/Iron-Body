// DIÁLOGO DE PUBLICAR EN EL CRM DE PRODUCCIÓN, SOLO LECTURA (lead, 11-11-2026).
// Abre ULTRON Studio con una sesión ya abierta (la cuenta técnica temporal), entra en Versiones, abre una versión
// APROBADA y pulsa «Publicar en canario» SOLO para abrir el diálogo: eso pide la vista previa al servidor (GET) y la
// pasa por el lector estricto del CRM. Lee lo que pinta el diálogo, guarda una captura y lo cierra con Escape.
// NUNCA pulsa «enviar»: no publica, no aprueba, no escribe nada.
//   node dialogo-publicar-solo-lectura.cjs <sesion.json> <dir de salida> <uuid de la versión aprobada> [<uuid de otra versión>]
'use strict';
const fs = require('fs');
const path = require('path');
const [, , F_SESION, DIR, UUID_APROBADA, UUID_OTRA] = process.argv;
const CRM = 'https://ironbodyneiva.cloud';
const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const puppeteer = require('/Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/ultron-h8/Iron-Body_Front/node_modules/puppeteer-core');
const dormir = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  const { token, user } = JSON.parse(fs.readFileSync(F_SESION, 'utf8'));
  fs.mkdirSync(DIR, { recursive: true });
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: true, defaultViewport: { width: 1440, height: 900 }, args: ['--no-first-run', '--no-default-browser-check', '--disable-extensions', '--no-pings'] });
  const escrituras = [];
  const errores = [];
  let fallo = 0;
  const paso = (nombre, ok, dato) => { if (!ok) fallo = 1; console.log(`${ok ? 'OK   ' : 'FALLO'} ${nombre}${dato !== undefined ? ' · ' + (typeof dato === 'string' ? dato : JSON.stringify(dato)).slice(0, 420) : ''}`); };
  try {
    const page = await browser.newPage();
    page.on('pageerror', (e) => errores.push(String(e.message || e).slice(0, 200)));
    // Testigo: cualquier petición que no sea de lectura hacia la API del Studio se anota (no debe haber ninguna).
    page.on('request', (r) => { if (/\/api\/admin\/ultron-studio\//.test(r.url()) && !['GET', 'OPTIONS', 'HEAD'].includes(r.method())) escrituras.push(`${r.method()} ${new URL(r.url()).pathname}`); });
    await page.evaluateOnNewDocument((t, u) => { try { sessionStorage.setItem('auth_token', t); sessionStorage.setItem('auth_user', u); sessionStorage.setItem('auth_session_type', 'session'); } catch { /* sin almacenamiento */ } }, token, JSON.stringify(user));
    const esperar = async (fn, arg, ms = 45000) => { const fin = Date.now() + ms; while (Date.now() < fin) { const v = await page.evaluate(fn, arg).catch(() => null); if (v) return v; await dormir(300); } return null; };
    const limpio = (s) => String(s || '').replace(/[-]/g, ' ').replace(/\s+/g, ' ').trim();

    await page.goto(`${CRM}/marketing/ultron-studio`, { waitUntil: 'domcontentloaded', timeout: 120000 });
    paso('Studio pintado', await esperar(() => !!document.querySelector('[data-panel-versiones]')));
    await page.click('[data-panel-versiones]');
    paso('panel de versiones abierto', await esperar(() => !!document.querySelector('[data-versiones] [data-version-item]')));
    const items = await page.evaluate(() => [...document.querySelectorAll('[data-version-item]')].map((e) => ({ uuid: e.getAttribute('data-version-item').slice(0, 8), texto: (e.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 90) })));
    console.log('     versiones en la lista:', JSON.stringify(items));

    await page.click(`[data-version-item="${UUID_APROBADA}"]`);
    paso('detalle de la versión aprobada, con su botón de publicar habilitado', await esperar(() => { const b = document.querySelector('[data-publicar]'); return !!b && !b.disabled; }));
    await page.click('[data-publicar]');
    paso('diálogo de publicar abierto', await esperar(() => !!document.querySelector('[data-publicacion-dialogo]')));
    await esperar(() => { const d = document.querySelector('[data-publicacion-dialogo]'); return !!d && d.getAttribute('aria-busy') !== 'true' && (!!document.querySelector('[data-publicacion-efecto]') || !!document.querySelector('[data-publicacion-carga-error]')); }, null, 60000);
    await dormir(1500);
    const d = await page.evaluate(() => {
      const q = (s) => document.querySelector(s);
      const t = (e) => (e ? (e.innerText || e.textContent || '').replace(/[-]/g, ' ').replace(/\s+/g, ' ').trim() : null);
      const dlg = q('[data-publicacion-dialogo]');
      return {
        estado: dlg?.getAttribute('data-publicacion-estado') ?? null,
        efecto: dlg?.getAttribute('data-publicacion-efecto') ?? t(q('[data-publicacion-efecto]')),
        errorDeCarga: t(q('[data-publicacion-carga-error]')),
        requisitos: [...document.querySelectorAll('[data-publicacion-requisito]')].map((e) => `${e.getAttribute('data-publicacion-requisito')}=${e.getAttribute('data-ok') ?? e.getAttribute('data-estado') ?? '?'}`),
        cambios: [...document.querySelectorAll('[data-publicacion-cambio]')].map((e) => t(e).slice(0, 120)),
        enviarDeshabilitado: q('[data-publicacion-enviar]') ? q('[data-publicacion-enviar]').disabled : null,
        falta: t(q('[data-publicacion-falta]')),
        texto: t(dlg).slice(0, 1400),
      };
    });
    paso('la vista previa del servidor se leyó (sin error de carga)', d.errorDeCarga === null, d.errorDeCarga ?? `estado=${d.estado} · efecto=${d.efecto}`);
    paso('el botón de enviar está deshabilitado (falta el veredicto del laboratorio)', d.enviarDeshabilitado === true, d.falta);
    console.log('     requisitos:', JSON.stringify(d.requisitos));
    console.log('     cambios:', JSON.stringify(d.cambios));
    console.log('     texto del diálogo:', d.texto);
    await page.screenshot({ path: path.join(DIR, 'dialogo-publicar-aprobada.png'), fullPage: false });
    fs.writeFileSync(path.join(DIR, 'dialogo-publicar-aprobada.txt'), `${d.texto}\n`);
    await page.keyboard.press('Escape');
    await dormir(800);

    if (UUID_OTRA) {
      const hay = await page.evaluate((u) => !!document.querySelector(`[data-version-item="${u}"]`), UUID_OTRA);
      if (hay) {
        await page.click(`[data-version-item="${UUID_OTRA}"]`);
        const b = await esperar(() => { const x = document.querySelector('[data-publicar-version]') || document.querySelector('[data-publicar]'); return x ? { deshabilitado: x.disabled, motivo: x.getAttribute('title') } : null; });
        paso('la versión de la prueba (en revisión): publicar deshabilitado, con su motivo', !!b && b.deshabilitado === true, b);
        await page.screenshot({ path: path.join(DIR, 'detalle-version-de-prueba.png'), fullPage: false });
      } else { paso('la versión de la prueba está en la lista', false); }
    }
    paso('ninguna escritura hacia la API del Studio', escrituras.length === 0, escrituras);
    paso('sin errores de página', errores.length === 0, errores);
  } finally {
    await browser.close();
  }
  process.exit(fallo);
})().catch((e) => { console.log('ERROR', String(e.message).slice(0, 300)); process.exit(2); });
