// SOLO LECTURA: la vista previa de publicar una versión del Studio, tal como la calcula el servidor de producción.
//   node vista-previa.cjs <sesion.json> <uuid de la versión>
'use strict';
const fs = require('fs');
const { token } = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const API = 'https://api.ironbodyneiva.cloud/api/admin/ultron-studio';
const get = async (r) => { const x = await fetch(`${API}/${r}`, { headers: { Accept: 'application/json', Authorization: `Bearer ${token}` } }); let j = null; try { j = await x.json(); } catch { /* sin cuerpo */ } return { status: x.status, json: j }; };
const corto = (v, n = 500) => { const s = typeof v === 'string' ? v : JSON.stringify(v); return s === undefined ? 'undefined' : (s.length > n ? s.slice(0, n) + '…' : s); };
(async () => {
  const p = await get(`versions/${process.argv[3]}/publication-preview`);
  const d = p.json?.data || {};
  console.log('estado HTTP:', p.status, '· claves:', Object.keys(d).join(','));
  console.log('publicable:', d.publishable, '· efecto:', corto(d.effect, 200), '· sin efecto:', corto(d.noEffect, 200));
  for (const [k, r] of Object.entries(d.requirements || {})) console.log('  requisito', k.padEnd(18), '→', r?.ok === true ? 'CUMPLE' : 'FALTA ', '·', corto(r?.message ?? r?.reason ?? r, 260));
  for (const k of ['prompts', 'promptsHash', 'bundle', 'declared', 'baseline', 'compiler', 'window', 'labGate', 'scope', 'canary', 'engine', 'target']) if (d[k] !== undefined) console.log('  ', k, ':', corto(d[k], 420));
  const st = await get('status');
  const s = st.json?.data || {};
  console.log('status del Studio:', corto({ can: s.can, engine: s.engine, publication: s.publication, publishing: s.publishing }, 900));
  const pubs = await get('publications?per_page=5');
  console.log('publicaciones:', pubs.status, corto(pubs.json?.data, 400));
})().catch((e) => { console.log('ERROR', String(e.message || e).slice(0, 300)); process.exit(1); });
