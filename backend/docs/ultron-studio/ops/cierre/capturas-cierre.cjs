// CAPTURAS DEL CRM DE PRODUCCIÓN TRAS UN DESPLIEGUE DE TEXTOS (lead, 11-11-2026).
// Abre, con una sesión ya abierta (la cuenta técnica temporal), las pantallas de ULTRON Studio y de la automatización
// comercial en un Chrome sin cabeza; guarda una captura y el texto visible de cada una; y comprueba que las frases
// que la auditoría de cierre demostró falsas ya no se pintan. SOLO LEE: no pulsa ningún botón que escriba.
//   node capturas-cierre.cjs <sesion.json> <dir de salida>
// Nunca escribe en la salida el portador de la sesión.
'use strict';
const fs = require('fs');
const path = require('path');
const [, , F_SESION, DIR] = process.argv;
const CRM = 'https://ironbodyneiva.cloud';
const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const puppeteer = require('/Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/ultron-h8/Iron-Body_Front/node_modules/puppeteer-core');
const dormir = (ms) => new Promise((r) => setTimeout(r, ms));

/** Pantallas: nombre de fichero, ruta y un selector que tiene que existir cuando ya pintó. */
const PANTALLAS = [
  ['01-studio', '/marketing/ultron-studio', 'main, [data-studio], h1'],
  ['02-laboratorio', '/marketing/ultron-studio/laboratorio', 'main, h1'],
  ['03-integraciones-estado', '/marketing/ultron-studio/integraciones?pestana=estado', '#ig-panel-estado'],
  ['04-integraciones-canarios', '/marketing/ultron-studio/integraciones?pestana=canarios', '#ig-panel-canarios'],
  ['05-voz-estado', '/marketing/ultron-studio/voz?pestana=estado', '#vt-panel-estado'],
  ['06-voz-publicacion', '/marketing/ultron-studio/voz?pestana=publicacion', '#vt-panel-publicacion'],
  ['07-voz-laboratorio', '/marketing/ultron-studio/voz?pestana=laboratorio', '#vt-panel-laboratorio'],
  ['08-automatizaciones-panel', '/marketing/automatizaciones?pestana=panel', 'main, h1'],
  ['09-automatizaciones-estrategias', '/marketing/automatizaciones?pestana=estrategias', 'main, h1'],
  ['10-automatizaciones-plantillas', '/marketing/automatizaciones?pestana=plantillas', 'main, h1'],
];

/** Frases que NO pueden salir en ninguna pantalla (auditorías A, B y C). */
const PROHIBIDAS = [
  'el motor de ULTRON no lee versiones',
  'el motor no lee el Studio',
  'plan de pago de ElevenLabs',
  'Falta el plan de pago',
  'minutos de agente',
  'sin efecto en el motor',
  'La voz no tiene motor en este hito',
  'Subcuenta de Twilio: Sin configurar',
  'la automatización nace apagada',
];

(async () => {
  const { token, user } = JSON.parse(fs.readFileSync(F_SESION, 'utf8'));
  fs.mkdirSync(DIR, { recursive: true });
  const browser = await puppeteer.launch({
    executablePath: CHROME, headless: true, defaultViewport: { width: 1440, height: 900 },
    args: ['--no-first-run', '--no-default-browser-check', '--disable-extensions', '--disable-sync', '--no-pings', '--window-size=1440,900'],
  });
  const resumen = { cuando: new Date().toISOString(), version: null, pantallas: [], prohibidasVistas: [], errores: [] };
  try {
    const page = await browser.newPage();
    page.on('pageerror', (e) => resumen.errores.push(String(e.message || e).slice(0, 200)));
    await page.evaluateOnNewDocument((t, u) => { try { sessionStorage.setItem('auth_token', t); sessionStorage.setItem('auth_user', u); sessionStorage.setItem('auth_session_type', 'session'); } catch { /* sin almacenamiento */ } }, token, JSON.stringify(user));
    try {
      const v = await (await fetch(`${CRM}/version.json?_=${Date.now()}`)).json();
      resumen.version = { commit: String(v.commit || v.sha || v.version || '').slice(0, 12), claves: Object.keys(v) };
    } catch (e) { resumen.version = { error: String(e.message).slice(0, 80) }; }
    for (const [nombre, ruta, selector] of PANTALLAS) {
      const fila = { nombre, ruta, pinto: false, caracteres: 0, prohibidas: [] };
      try {
        await page.goto(`${CRM}${ruta}`, { waitUntil: 'domcontentloaded', timeout: 120000 });
        const fin = Date.now() + 45000;
        while (Date.now() < fin) {
          const listo = await page.evaluate((s) => !!document.querySelector(s) && !document.querySelector('[aria-busy="true"]') && (document.body.innerText || '').length > 400, selector).catch(() => false);
          if (listo) { fila.pinto = true; break; }
          await dormir(500);
        }
        await dormir(2500); // las lecturas secundarias (estado, vínculos, Meta) llegan después del primer pintado
        const texto = await page.evaluate(() => (document.body.innerText || '').replace(/[-]/g, ' ').replace(/[ \t]+/g, ' '));
        const titulos = await page.evaluate(() => [...document.querySelectorAll('[title]')].map((e) => e.getAttribute('title')).filter(Boolean).join('\n'));
        if (texto.includes(token)) throw new Error('la pantalla pinta la sesión: no se guarda');
        fila.caracteres = texto.length;
        fila.prohibidas = PROHIBIDAS.filter((f) => texto.includes(f) || titulos.includes(f));
        for (const f of fila.prohibidas) resumen.prohibidasVistas.push(`${nombre}: «${f}»`);
        fs.writeFileSync(path.join(DIR, `${nombre}.txt`), `${texto}\n\n--- title ---\n${titulos}\n`);
        await page.screenshot({ path: path.join(DIR, `${nombre}.png`), fullPage: true });
      } catch (e) { fila.error = String(e.message).slice(0, 160); }
      resumen.pantallas.push(fila);
      console.log(`${fila.pinto && !fila.error ? 'OK   ' : 'FALLO'} ${nombre} · ${fila.caracteres} caracteres${fila.prohibidas.length ? ' · PROHIBIDAS: ' + fila.prohibidas.join(' | ') : ''}${fila.error ? ' · ' + fila.error : ''}`);
    }
  } finally {
    await browser.close();
  }
  fs.writeFileSync(path.join(DIR, 'resumen.json'), JSON.stringify(resumen, null, 1));
  console.log(`versión servida: ${JSON.stringify(resumen.version)} · errores de página: ${resumen.errores.length} · frases prohibidas vistas: ${resumen.prohibidasVistas.length}`);
  process.exit(resumen.prohibidasVistas.length === 0 && resumen.pantallas.every((p) => p.pinto && !p.error) ? 0 : 1);
})().catch((e) => { console.log('ERROR', String(e.message).slice(0, 200)); process.exit(2); });
