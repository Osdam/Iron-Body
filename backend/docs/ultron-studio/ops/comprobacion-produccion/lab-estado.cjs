// SOLO LECTURA (lead, 23-11-2026 · unidad 2 del cierre operativo): lo que el laboratorio del Studio dice de sí mismo en
// producción, con la sesión de la cuenta técnica. Solo peticiones GET; no lanza, no estima y no escribe nada.
//   node lab-estado.cjs <sesion.json> [<uuid de la versión>]
// Imprime el estado del laboratorio (modelo, precios, presupuesto y cupos: no son secretos), el catálogo de escenarios
// activos (categoría y título) y, de las últimas ejecuciones, su estado y su tipo. Nunca imprime la sesión.
'use strict';
const fs = require('fs');
const { token } = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const VERSION = process.argv[3] || null;
const API = 'https://api.ironbodyneiva.cloud/api/admin/ultron-studio';
const get = async (r) => {
  const x = await fetch(`${API}/${r}`, { headers: { Accept: 'application/json', Authorization: `Bearer ${token}` } });
  let j = null;
  try { j = await x.json(); } catch { /* sin cuerpo */ }
  return { status: x.status, json: j };
};
const corto = (v, n = 500) => { const s = typeof v === 'string' ? v : JSON.stringify(v); return s === undefined ? 'undefined' : (s.length > n ? s.slice(0, n) + '…' : s); };
(async () => {
  const st = await get('lab/status');
  console.log('GET lab/status →', st.status, '· claves:', Object.keys(st.json?.data || {}).join(','));
  console.log('  ', corto(st.json?.data, 2600));
  const sc = await get('lab/scenarios?status=active');
  const lista = Array.isArray(sc.json?.data) ? sc.json.data : (sc.json?.data?.items || []);
  console.log('GET lab/scenarios?status=active →', sc.status, '· escenarios activos:', lista.length);
  for (const e of lista) console.log('   ·', String(e.code ?? e.key ?? '').padEnd(6), '|', String(e.category ?? '').padEnd(14), '|', corto(e.title, 90), '| turnos:', Array.isArray(e.turns) ? e.turns.length : '?', '| origen:', e.origin ?? e.source ?? e.kind ?? '?');
  if (lista[0]) console.log('   forma de un escenario (claves):', Object.keys(lista[0]).join(','));
  const q = VERSION ? `lab/runs?versionUuid=${VERSION}&per_page=5` : 'lab/runs?per_page=5';
  const rn = await get(q);
  const runs = Array.isArray(rn.json?.data) ? rn.json.data : (rn.json?.data?.items || []);
  console.log(`GET ${q} →`, rn.status, '· en esta página:', runs.length, '· meta:', corto(rn.json?.meta, 200));
  for (const r of runs) console.log('   ·', String(r.uuid).slice(0, 8), '|', r.status, '|', r.fidelity ?? '(sin tipo)', '| casos:', r.totals?.cases ?? r.casesCount ?? '?', '|', corto(r.createdAt ?? r.created_at, 25));
  if (runs[0]) console.log('   forma de una ejecución (claves):', Object.keys(runs[0]).join(','));
})().catch((e) => { console.log('ERROR', String(e.message || e).slice(0, 300)); process.exit(1); });
