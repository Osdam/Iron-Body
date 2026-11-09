/**
 * Humo del E2E del CRM: cada cuenta entra con su sesión del arnés y abre las 7 pestañas de «Voz y telefonía» a 1 440 px.
 * Sirve para comprobar la fontanería (pila, proxy, CORS, sesión inyectada) antes de los recorridos.
 *   node humo.cjs <crm-context.json>
 */
'use strict';
const L = require('./lib.cjs');

(async () => {
  const ctx = L.cargar(process.argv[2]);
  const inf = new L.Informe(ctx, L.prohibidos(ctx));
  const browser = await L.lanzar(ctx);
  const sesiones = [];
  try {
    inf.en('humo');
    for (const q of ['a', 'b', 'c']) {
      const s = await L.sesion(browser, ctx, inf, q);
      sesiones.push(s);
      for (const p of ['estado', 'perfil', 'publicacion', 'llamadas', 'costes', 'contactos', 'laboratorio']) {
        const ok = await L.pestana(s, ctx, p);
        inf.check(`${q}: la pestaña ${p} se pinta`, ok, await L.leer(s, 'return location.pathname + location.search;'));
      }
      await L.captura(s, ctx, `humo-${q}-laboratorio-1440`);
      inf.check(`${q}: sin errores de JS`, s.errores.length === 0, s.errores.slice(0, 3));
      inf.check(`${q}: sin 5xx de la API`, s.api5xx.length === 0, s.api5xx.slice(0, 3));
    }
  } catch (e) {
    inf.check('el guion terminó sin excepción', false, String(e && e.stack ? e.stack.split('\n').slice(0, 3).join(' | ') : e));
  } finally {
    L.guardarRed(ctx, sesiones);
    await browser.close().catch(() => {});
    process.exitCode = inf.fin() ? 0 : 1;
  }
})();
