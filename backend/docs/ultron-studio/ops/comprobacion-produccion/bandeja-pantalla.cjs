// COMPROBACIÓN REAL DE LA BANDEJA ASISTIDA EN PRODUCCIÓN (lead, 12-11-2026). Solo lectura.
// Abre el CRM de producción con la sesión de la cuenta técnica, entra en «Automatizaciones comerciales → Bandeja
// asistida», lee los avisos y el texto de vacío, cuenta las lecturas de la bandeja que hace la página y comprueba la
// relectura al volver a la pestaña (una, y no otra antes de 15 s). NUNCA escribe la sesión ni datos de personas: solo
// textos del sistema, códigos de estado y recuentos. No pulsa «Reclamar» ni ningún botón que escriba.
//   NODE_PATH=<Iron-Body_Front>/node_modules node bandeja-pantalla.cjs <sesion.json> <dir de salida> [commit esperado del CRM]
// La sesión es la de la cuenta técnica (`/root/lab-tecnica-cuenta.sh abrir` en el servidor; copia local con umask 077, que se
// borra al terminar, y `cerrar` después).
// `page.$eval`, `page.$$eval` y `page.evaluate` ejecutan en la página funciones FIJAS de este fichero para leer el DOM.
'use strict';
const fs = require('fs');
const path = require('path');
const [, , F_SESION, DIR, COMMIT] = process.argv;
const CRM = 'https://ironbodyneiva.cloud';
const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const puppeteer = require('puppeteer-core');                // el del CRM: NODE_PATH=<Iron-Body_Front>/node_modules
const dormir = (ms) => new Promise((r) => setTimeout(r, ms));
const inf = { pasos: [], errores: [] };
const paso = (nombre, ok, dato) => { inf.pasos.push({ nombre, ok: !!ok, dato: dato ?? null }); console.log(`${ok ? 'OK   ' : 'FALLO'} ${nombre}${dato !== undefined ? ' · ' + JSON.stringify(dato) : ''}`); };

(async () => {
  const { token, user } = JSON.parse(fs.readFileSync(F_SESION, 'utf8'));
  fs.mkdirSync(DIR, { recursive: true });

  // ── Qué versión sirve el CRM ──
  const v = await fetch(`${CRM}/version.json?_=${Date.now()}`, { headers: { 'Cache-Control': 'no-cache' } });
  let ver = null; try { ver = await v.json(); } catch { /* sin cuerpo */ }
  paso('CRM publicado: version.json', v.status === 200 && (!COMMIT || ver?.commit === COMMIT) && ver?.dirty === false, { status: v.status, commit: ver?.commit, dirty: ver?.dirty, builtAt: ver?.builtAt });

  const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox', '--window-size=1440,1100'] });
  try {
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 1100 });
    page.on('pageerror', (e) => inf.errores.push(String(e.message || e).slice(0, 300)));
    // Las lecturas de la bandeja que hace la PÁGINA (método, filtro y estado; nunca el cuerpo ni la cabecera).
    const lecturas = [];
    page.on('response', (r) => {
      const u = r.url();
      if (u.includes('/outreach/assisted/queue') && r.request().method() === 'GET') {
        lecturas.push({ filtro: new URL(u).searchParams.get('filter'), status: r.status(), t: Date.now() });
      }
    });
    await page.evaluateOnNewDocument((t, u) => { try { sessionStorage.setItem('auth_token', t); sessionStorage.setItem('auth_user', u); sessionStorage.setItem('auth_session_type', 'session'); } catch { /* sin almacenamiento */ } }, token, JSON.stringify(user));
    await page.goto(`${CRM}/marketing/automatizaciones?pestana=bandeja`, { waitUntil: 'domcontentloaded', timeout: 120000 });
    const n = (t) => String(t || '').replace(/\s+/g, ' ').trim();
    await page.waitForSelector('[data-ca-bandeja]', { timeout: 90000 });
    await page.waitForSelector('[data-ca-bandeja-vacia], [data-ca-bandeja-lista], [data-ca-bandeja] .lab-alert[role="alert"], [data-ca-bandeja-no-disponible]', { timeout: 90000 });
    await dormir(1500);
    const lee = async () => page.evaluate(() => {
      const t = (s) => { const e = document.querySelector(s); return e ? e.textContent.replace(/\s+/g, ' ').trim() : null; };
      return {
        titulo: t('[data-ca-bandeja] h2'),
        vacia: t('[data-ca-bandeja-vacia]'),
        filas: document.querySelectorAll('[data-ca-bandeja-lista] > li').length,
        retenidos: t('[data-ca-bandeja-retenidos]'),
        tope: t('[data-ca-bandeja-tope]'),
        sinEventos: t('[data-ca-bandeja-sin-eventos]'),
        noDisponible: t('[data-ca-bandeja-no-disponible]'),
        error: t('[data-ca-bandeja] .lab-alert[role="alert"]'),
        sinLlave: t('[data-motivo-accion="assisted"]'),
        filtros: [...document.querySelectorAll('[data-ca-bandeja-filtro] option')].map((o) => o.textContent.replace(/\s+/g, ' ').trim()),
        releer: !!document.querySelector('[data-ca-bandeja-releer]'),
      };
    });
    const p1 = await lee();
    paso('pantalla: la bandeja carga', p1.titulo === 'Bandeja asistida' && p1.error === null && p1.noDisponible === null, p1);
    paso('pantalla: la página leyó la bandeja con 200', lecturas.length >= 1 && lecturas.every((l) => l.status === 200), lecturas.map((l) => ({ filtro: l.filtro, status: l.status })));
    // Hoy no hay asistidos en vivo (sombra): se espera el texto de vacío y ningún aviso de retenidos ni de tope.
    paso('pantalla: vacía, sin aviso de retenidos ni de tope (sombra: 0 trabajos)', p1.filas === 0 && !!p1.vacia && p1.retenidos === null && p1.tope === null, { vacia: p1.vacia });
    await page.screenshot({ path: path.join(DIR, 'bandeja-asistida.png'), fullPage: true });

    // ── La relectura al volver a la pestaña ──
    // El oyente de la página mira `document.visibilityState`: en Chrome sin cabeza la pestaña está visible, así que basta
    // con despachar el evento, como hace el navegador al volver. No se toca el código de la página.
    const antes = lecturas.length;
    await page.evaluate(() => { document.dispatchEvent(new Event('visibilitychange')); });
    await dormir(4000);
    const trasVolver = lecturas.length - antes;
    paso('pantalla: al volver a la pestaña relee UNA vez', trasVolver === 1, { lecturasNuevas: trasVolver, visibilityState: await page.evaluate(() => document.visibilityState) });
    await page.evaluate(() => { document.dispatchEvent(new Event('visibilitychange')); document.dispatchEvent(new Event('visibilitychange')); });
    await dormir(4000);
    const repetidas = lecturas.length - antes - trasVolver;
    paso('pantalla: no vuelve a releer antes de 15 s', repetidas === 0, { lecturasNuevas: repetidas });
    const p2 = await lee();
    paso('pantalla: tras releer sigue igual', p2.error === null && p2.filas === p1.filas && p2.vacia === p1.vacia, { vacia: p2.vacia });

    // ── Los otros dos filtros (solo lectura) ──
    for (const f of ['mine', 'unclaimed']) {
      const previas = lecturas.length;
      await page.select('[data-ca-bandeja-filtro]', f);
      await dormir(3500);
      const nuevas = lecturas.slice(previas);
      const p = await lee();
      paso(`pantalla: filtro ${f}`, nuevas.length >= 1 && nuevas.every((l) => l.status === 200 && l.filtro === f) && p.error === null, { lecturas: nuevas.map((l) => ({ filtro: l.filtro, status: l.status })), vacia: p.vacia, retenidos: p.retenidos, tope: p.tope });
    }
    const cuerpo = n(await page.$eval('body', (b) => b.innerText));
    const crudos = ['strategy_stopped', 'opted_out', 'policy_unknown', 'complaint_recent', 'complaint_unknown', 'scanCapped', 'contactWithheld'].filter((c) => cuerpo.includes(c));
    paso('pantalla: ninguna clave interna a la vista', crudos.length === 0, crudos);
    paso('pantalla: sin errores de página', inf.errores.length === 0, inf.errores.slice(0, 3));
  } finally {
    await browser.close();
  }
  fs.writeFileSync(path.join(DIR, 'bandeja-pantalla.json'), JSON.stringify(inf, null, 1));
  const fallos = inf.pasos.filter((p) => !p.ok).length;
  console.log(`RESULTADO: ${inf.pasos.length - fallos} OK · ${fallos} FALLO`);
  process.exit(fallos ? 1 : 0);
})().catch((e) => { console.error('ERROR', String(e.message || e).slice(0, 300)); process.exit(2); });
