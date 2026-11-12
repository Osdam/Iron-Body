// COMPROBACIÓN REAL DEL CENTRO DE SUPERVISIÓN EN PRODUCCIÓN (lead, 12-11-2026). Solo lectura.
// Abre el CRM de producción con la sesión de la cuenta técnica, lee la cabecera, las tarjetas y la línea de tiempo, y
// llama a las dos rutas de la API. NUNCA escribe la sesión, ni motivos de texto libre (lo que escribió una persona),
// ni nombres ni teléfonos: solo los textos del sistema y recuentos.
//   NODE_PATH=<Iron-Body_Front>/node_modules node supervision-pantalla.cjs <sesion.json> <dir de salida>
// La sesión es la de la cuenta técnica (`/root/lab-tecnica-cuenta.sh abrir` en el servidor; copia local con umask 077, que se
// borra al terminar, y `cerrar` después).
// `page.$eval`, `page.$$eval` y `page.evaluate` son la API de puppeteer: ejecutan en la página funciones FIJAS de este
// fichero para leer el DOM. No se evalúa ningún dato ni ninguna cadena venida de fuera.
'use strict';
const fs = require('fs');
const path = require('path');
const [, , F_SESION, DIR] = process.argv;
const CRM = 'https://ironbodyneiva.cloud';
const API = 'https://api.ironbodyneiva.cloud/api';
const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const puppeteer = require('puppeteer-core');                // el del CRM: NODE_PATH=<Iron-Body_Front>/node_modules
const dormir = (ms) => new Promise((r) => setTimeout(r, ms));
const CRUDO = /^[a-z0-9]+(_[a-z0-9]+)*$/;                    // una clave interna: minúsculas con guiones bajos
const cuenta = (lista) => Object.entries(lista.reduce((a, x) => ({ ...a, [x]: (a[x] || 0) + 1 }), {})).sort((a, b) => b[1] - a[1]);
const inf = { pasos: [], errores: [] };
const paso = (nombre, ok, dato) => { inf.pasos.push({ nombre, ok: !!ok, dato: dato ?? null }); console.log(`${ok ? 'OK   ' : 'FALLO'} ${nombre}${dato !== undefined ? ' · ' + JSON.stringify(dato) : ''}`); };

(async () => {
  const { token, user } = JSON.parse(fs.readFileSync(F_SESION, 'utf8'));
  fs.mkdirSync(DIR, { recursive: true });
  const api = async (ruta) => {
    const r = await fetch(`${API}/${ruta}`, { headers: { Accept: 'application/json', Authorization: `Bearer ${token}` } });
    let j = null; try { j = await r.json(); } catch { /* sin cuerpo */ }
    return { status: r.status, json: j };
  };

  // ── La API ──
  const st = await api('admin/marketing/supervision/state');
  const a = st.json?.data?.agent || {}; const c = st.json?.data?.conversations || {};
  paso('API state', st.status === 200, { status: a.status, enabled: a.enabled, label: a.label, short_label: a.short_label, legacy_enabled: a.legacy_enabled, open: c.open, attended_by_agent: c.attended_by_agent, handled_by_ai: c.handled_by_ai, handled_by_human: c.handled_by_human });
  const ac = await api('admin/marketing/supervision/activity?hours=24&limit=200');
  const filas = ac.json?.data || [];
  const conocido = (t) => typeof t === 'string' && /^[A-ZÁÉÍÓÚÑ¿¡]/.test(t);
  paso('API activity', ac.status === 200, {
    filas: filas.length,
    porTipo: cuenta(filas.map((f) => f.kind)),
    accionesEnCrudo: cuenta(filas.filter((f) => CRUDO.test(String(f.action || ''))).map((f) => f.action)),
    resultadosEnCrudo: cuenta(filas.filter((f) => f.result && CRUDO.test(String(f.result))).map((f) => f.result)),
    motivosEnCrudo: cuenta(filas.filter((f) => f.reason && CRUDO.test(String(f.reason))).map((f) => f.reason)),
    acciones: cuenta(filas.map((f) => `${f.actor} · ${f.action} · ${f.result ?? '—'}`)).slice(0, 12),
    // Solo los motivos que son frases del sistema y se repiten (los de texto libre de una persona no se imprimen).
    motivosDelSistema: cuenta(filas.filter((f) => conocido(f.reason)).map((f) => f.reason)).filter(([, n]) => n >= 2).slice(0, 8),
  });

  // ── La pantalla ──
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox', '--window-size=1440,1100'] });
  try {
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 1100 });
    page.on('pageerror', (e) => inf.errores.push(String(e.message || e).slice(0, 300)));
    await page.evaluateOnNewDocument((t, u) => { try { sessionStorage.setItem('auth_token', t); sessionStorage.setItem('auth_user', u); sessionStorage.setItem('auth_session_type', 'session'); } catch { /* sin almacenamiento */ } }, token, JSON.stringify(user));
    await page.goto(`${CRM}/marketing/supervision`, { waitUntil: 'domcontentloaded', timeout: 120000 });
    const n = (t) => String(t || '').replace(/\s+/g, ' ').trim();
    await page.waitForSelector('header.head .pills .pill', { timeout: 60000 });
    await page.waitForSelector('.grid article.card strong.v', { timeout: 60000 });
    const cabecera = await page.$$eval('header.head .pills .pill', (xs) => xs.map((x) => ({ texto: x.textContent.replace(/\s+/g, ' ').trim(), clases: x.className })));
    const tarjetas = await page.$$eval('.grid article.card', (xs) => xs.map((x) => [...x.querySelectorAll('.k, .v, .note')].map((e) => e.textContent.replace(/\s+/g, ' ').trim())));
    const ficha = await page.$$eval('.two-col section.block', (xs) => xs.slice(0, 1).flatMap((x) => [...x.querySelectorAll('dl.facts > div')].map((d) => d.textContent.replace(/\s+/g, ' ').trim())));
    paso('pantalla: cabecera', cabecera.length > 0, cabecera);
    paso('pantalla: tarjetas', tarjetas.length >= 4, tarjetas);
    paso('pantalla: ficha «Agente»', ficha.length > 0, ficha);
    const cuerpo = n(await page.$eval('body', (b) => b.innerText));
    const falsas = ['Agente apagado', 'Las lleva el agente', 'Ejecuciones 24 h', 'Meta sin activar'].filter((f) => cuerpo.includes(f));
    paso('pantalla: ninguna de las frases que eran falsas', falsas.length === 0, falsas);
    await page.screenshot({ path: path.join(DIR, 'supervision-resumen.png'), fullPage: true });

    // La pestaña de la actividad.
    const pulsada = await page.evaluate(() => { const b = [...document.querySelectorAll('nav.tabs button.tab')].find((x) => /Actividad/i.test(x.textContent)); if (b) { b.click(); return true; } return false; });
    paso('pantalla: pestaña «Actividad»', pulsada);
    await page.waitForSelector('ol.timeline li.event, .empty', { timeout: 60000 });
    await dormir(1500);
    const eventos = await page.$$eval('ol.timeline li.event', (xs) => xs.map((x) => ({
      accion: (x.querySelector('.event-head strong')?.textContent || '').replace(/\s+/g, ' ').trim(),
      quien: (x.querySelector('.event-head .faint')?.textContent || '').replace(/\s+/g, ' ').trim().split(' · ')[0],
      resultado: ([...x.querySelectorAll('.event-meta span')].map((s) => s.textContent.replace(/\s+/g, ' ').trim()).find((t) => t.startsWith('Resultado:')) || '').replace('Resultado: ', ''),
      motivo: (x.querySelector('.event-reason')?.textContent || '').replace(/\s+/g, ' ').trim(),
    })));
    paso('pantalla: línea de tiempo', eventos.length > 0, {
      eventos: eventos.length,
      accionesEnCrudo: cuenta(eventos.filter((e) => CRUDO.test(e.accion)).map((e) => e.accion)),
      resultadosEnCrudo: cuenta(eventos.filter((e) => e.resultado && CRUDO.test(e.resultado)).map((e) => e.resultado)),
      motivosEnCrudo: cuenta(eventos.filter((e) => e.motivo && CRUDO.test(e.motivo)).map((e) => e.motivo)),
      lineas: cuenta(eventos.map((e) => `${e.quien} · ${e.accion} · ${e.resultado || '—'}`)).slice(0, 10),
    });
    await page.screenshot({ path: path.join(DIR, 'supervision-actividad.png'), fullPage: false });
    paso('pantalla: sin errores de página', inf.errores.length === 0, inf.errores.slice(0, 3));
  } finally {
    await browser.close();
  }
  fs.writeFileSync(path.join(DIR, 'supervision-pantalla.json'), JSON.stringify(inf, null, 1));
  const fallos = inf.pasos.filter((p) => !p.ok).length;
  console.log(`RESULTADO: ${inf.pasos.length - fallos} OK · ${fallos} FALLO`);
  process.exit(fallos ? 1 : 0);
})().catch((e) => { console.error('ERROR', String(e.message || e).slice(0, 300)); process.exit(2); });
