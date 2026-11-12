// LANZA UNA EJECUCIÓN DEL LABORATORIO DE TEXTO EN PRODUCCIÓN con la cuenta técnica y espera a que termine.
// Es la parte del AUTOR: no aprueba, no emite veredicto y no publica. Escenarios sintéticos del catálogo: sin datos de
// personas. Coste: el que estima el servidor, con el tope que se le acepta (y el diario del laboratorio).
//   node lab-run.cjs <sesion.json> <version:UUID | baseline> <all | C-04,C-06,…> [tope en micros, por defecto 500000]
// La sesión es la de la cuenta técnica (`/root/lab-tecnica-cuenta.sh abrir` en el servidor; copia local con umask 077, que se
// borra al terminar, y `cerrar` después). CUESTA DINERO (céntimos de dólar por ejecución, dentro del tope del laboratorio).
// Nunca imprime la sesión.
'use strict';
const fs = require('fs');
const crypto = require('crypto');
const [, , F_SESION, OBJETIVO, CODIGOS, TOPE] = process.argv;
const API = 'https://api.ironbodyneiva.cloud/api/admin/ultron-studio';
const dormir = (ms) => new Promise((r) => setTimeout(r, ms));
const corto = (v, n = 400) => { const s = typeof v === 'string' ? v : JSON.stringify(v); return s === undefined ? 'undefined' : (s.length > n ? s.slice(0, n) + '…' : s); };

(async () => {
  const { token } = JSON.parse(fs.readFileSync(F_SESION, 'utf8'));
  const api = async (m, r, b) => {
    const x = await fetch(`${API}/${r}`, { method: m, headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${token}` }, body: b === undefined ? undefined : JSON.stringify(b) });
    let j = null; try { j = await x.json(); } catch { /* sin cuerpo */ }
    return { status: x.status, json: j };
  };
  const objetivo = OBJETIVO === 'baseline' ? { kind: 'baseline' } : { kind: 'version', uuid: OBJETIVO.replace(/^version:/, '') };
  const tope = Number.isInteger(Number(TOPE)) && Number(TOPE) > 0 ? Number(TOPE) : 500000;

  const st = await api('GET', 'lab/status');
  const s = st.json?.data || {};
  console.log('laboratorio:', corto({ status: st.status, enabled: s.enabled, mode: s.mode, model: s.model, promptVersion: s.promptVersion, catalog: s.catalog, budget: s.budget }, 600));

  const esc = await api('GET', 'lab/scenarios?per_page=50');
  const lista = esc.json?.data?.data || esc.json?.data?.items || esc.json?.data || [];
  const activos = lista.filter((e) => (e.status ?? 'active') === 'active');
  const quiero = CODIGOS === 'all' ? null : new Set(String(CODIGOS).split(',').map((c) => c.trim()));
  const elegidos = activos.filter((e) => quiero === null || quiero.has(e.code));
  if (elegidos.length === 0 || (quiero !== null && elegidos.length !== quiero.size)) { console.log('PARO: escenarios', elegidos.map((e) => e.code), 'de', activos.map((e) => e.code)); process.exit(1); }
  const uuids = elegidos.map((e) => e.uuid);
  console.log('escenarios:', elegidos.map((e) => e.code).join(' '));

  const est = await api('POST', 'lab/estimate', { target: objetivo, scenarioUuids: uuids });
  if (est.status !== 200 || est.json?.data?.allowed !== true) { console.log('PARO estimar →', est.status, corto(est.json, 700)); process.exit(1); }
  const maximo = est.json.data.estimate.maxMicros;
  console.log('estimación:', corto({ promptVersion: est.json.data.promptVersion, esperado: est.json.data.estimate.expectedMicros, maximo, presupuesto: est.json.data.budget }, 500));
  if (!Number.isInteger(maximo) || maximo > tope) { console.log('PARO: el coste máximo', maximo, 'supera el tope aceptado', tope); process.exit(1); }

  const run = await api('POST', 'lab/runs', { target: objetivo, scenarioUuids: uuids, acceptedMaxCostMicros: maximo, clientRequestId: crypto.randomUUID() });
  if (![200, 201, 202].includes(run.status)) { console.log('PARO lanzar →', run.status, corto(run.json, 700)); process.exit(1); }
  const r = run.json.data?.run || run.json.data;
  console.log('ejecución lanzada:', r.uuid, r.status);
  fs.writeFileSync(`${F_SESION}.ultima-ejecucion`, String(r.uuid));

  const inicio = Date.now();
  let d = null;
  while (Date.now() - inicio < 15 * 60 * 1000) {
    await dormir(10000);
    const x = await api('GET', `lab/runs/${r.uuid}`);
    d = x.json?.data?.run || x.json?.data || null;
    const estado = d?.status;
    process.stdout.write(`  ${Math.round((Date.now() - inicio) / 1000)} s · ${estado} · ${corto(d?.summary?.progress ?? d?.progress ?? null, 120)}\n`);
    if (['completed', 'stopped', 'cancelled', 'failed'].includes(estado)) break;
  }
  console.log('FINAL:', corto({ uuid: d?.uuid, status: d?.status, stopReason: d?.stopReason, promptVersion: d?.promptVersion, summary: d?.summary, costMicros: d?.costMicros ?? d?.cost?.micros, aiRecommendation: d?.aiRecommendation ?? d?.proposal, resultsHash: String(d?.resultsHash || '').slice(0, 8) }, 900));
  process.exit(d?.status === 'completed' ? 0 : 3);
})().catch((e) => { console.log('ERROR', String(e.message || e).slice(0, 300)); process.exit(2); });
