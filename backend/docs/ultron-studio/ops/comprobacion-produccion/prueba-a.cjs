// PRUEBA DE ACEPTACIÓN A (Studio → WhatsApp), parte técnica: con la cuenta técnica temporal crea la versión de prueba
// (tono «profesional», longitud 200) y la ENVÍA A REVISIÓN; lanza sobre ella una ejecución del laboratorio de texto.
// NO aprueba, NO da el veredicto y NO publica: eso es de una persona que no sea la autora (cuatro ojos).
// Uso: node prueba-a.cjs <sesion.json> leer | crear | lab <uuid versión> | ejecucion <uuid ejecución> | previa <uuid versión> | descartar
// No imprime el portador, teléfonos ni textos de prompts: solo claves, estados, huellas cortas, recuentos y costes.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const [, , F_SESION, MODO, ARG] = process.argv;
const API = 'https://api.ironbodyneiva.cloud/api/admin/ultron-studio';
const { token } = JSON.parse(fs.readFileSync(F_SESION, 'utf8'));
const DIR = path.dirname(F_SESION);
const TONO = 'profesional';
const LONGITUD = 200;
// La etiqueta y la nota se pueden dar por entorno (una versión revertida no se vuelve a publicar: la repetición necesita otra).
const ETIQUETA = process.env.A_ETIQUETA || 'Prueba de aceptación · profesional 200';
const NOTA = process.env.A_NOTA || 'Prueba de aceptación de la Enmienda M-1 (Studio a WhatsApp, solo conversación canaria). Creada por la cuenta técnica: cambia el tono a profesional y la longitud máxima a 200. La aprueba, la valora en el laboratorio y la publica una persona que no es la autora. Se revierte al terminar la prueba.';

async function api(metodo, ruta, cuerpo) {
  const r = await fetch(`${API}/${ruta}`, {
    method: metodo,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
    body: cuerpo === undefined ? undefined : JSON.stringify(cuerpo),
  });
  let j = null;
  try { j = await r.json(); } catch { /* sin cuerpo JSON */ }
  return { status: r.status, json: j };
}
const corto = (v, n = 300) => { const s = JSON.stringify(v); return s === undefined ? 'undefined' : (s.length > n ? s.slice(0, n) + '…' : s); };
const fallo = (que, r) => { console.log(`PARO · ${que} →`, r.status, corto({ code: r.json?.code, message: r.json?.message, errors: r.json?.errors, data: r.json?.data }, 900)); process.exit(1); };

async function leerBorrador() {
  const d = await api('GET', 'draft');
  if (d.status !== 200) fallo('leer el borrador', d);
  return d.json.data;
}

(async () => {
  if (MODO === 'leer') {
    const st = await api('GET', 'status');
    if (st.status !== 200) fallo('status', st);
    const s = st.json.data;
    console.log('status · claves:', Object.keys(s).join(','));
    console.log('  engine.prompts:', corto({ key: s.engine?.prompts?.key, current: s.engine?.prompts?.current }));
    console.log('  engine.publication:', corto(s.engine?.publication, 260));
    const b = await leerBorrador();
    console.log('borrador · claves:', Object.keys(b).join(','));
    console.log('  revision:', b.revision, '· exists:', b.exists ?? b.persisted ?? '(sin campo)', '· contentHash:', String(b.contentHash || '').slice(0, 8));
    const c = b.content || {};
    console.log('  content · claves:', Object.keys(c).join(','), '· configs:', Object.keys(c.configs || {}).join(','));
    console.log('  persona:', corto(c.configs?.persona), '· strategy.goal:', c.configs?.strategy?.goal, '· intent:', corto(c.configs?.intent));
    const cat = await api('GET', 'catalog');
    if (cat.status !== 200) fallo('catalog', cat);
    const k = cat.json.data;
    console.log('catálogo · claves:', Object.keys(k).join(','), '· version:', k.version ?? k.catalogVersion);
    const texto = JSON.stringify(k);
    for (const clave of ['tone', 'maxChars']) {
      const i = texto.indexOf(`"key":"${clave}"`);
      console.log(`  parámetro ${clave}:`, i < 0 ? '(no encontrado por "key")' : texto.slice(Math.max(0, i - 40), i + 420));
    }
    const lab = await api('GET', 'lab/status');
    console.log('laboratorio →', lab.status, corto(lab.json?.data, 700));
    const esc = await api('GET', 'lab/scenarios?per_page=50');
    const lista = esc.json?.data?.data || esc.json?.data?.items || esc.json?.data || [];
    console.log('escenarios →', esc.status, '· n:', Array.isArray(lista) ? lista.length : corto(Object.keys(esc.json?.data || {})));
    if (Array.isArray(lista)) for (const e of lista) console.log('   ', e.uuid, '·', e.status, '·', corto(e.label ?? e.name, 80), '·', corto(e.category ?? e.kind ?? null, 30));
    const v = await api('GET', 'versions?per_page=20');
    const vs = v.json?.data?.data || v.json?.data?.items || v.json?.data || [];
    console.log('versiones →', v.status, Array.isArray(vs) ? vs.map((x) => `${String(x.uuid).slice(0, 8)} ${x.status} «${x.label}»`).join(' | ') : corto(v.json?.data, 300));
    return;
  }

  if (MODO === 'crear') {
    const b = await leerBorrador();
    if (b.isBaseline !== true) { console.log('PARO: el borrador de la cuenta técnica no es la línea base.'); process.exit(1); }
    const c = JSON.parse(JSON.stringify(b.content));
    if (!c?.configs?.persona) fallo('el borrador no trae configs.persona', { status: 200, json: { data: Object.keys(c || {}) } });
    console.log('antes · revision:', b.revision, '· persona:', corto(c.configs.persona), '· goal:', c.configs.strategy?.goal);
    c.configs.persona.tone = TONO;
    c.configs.persona.maxChars = LONGITUD;
    const g = await api('PUT', 'draft', { baseRevision: b.revision ?? 0, content: c });
    if (g.status !== 200) fallo('guardar el borrador', g);
    const nb = g.json.data;
    console.log('borrador guardado · revision:', nb.revision, '· persona:', corto(nb.content?.configs?.persona), '· hallazgos:', corto(nb.findings ?? nb.validation ?? null, 300));
    const cr = await api('POST', 'versions', { label: ETIQUETA, note: NOTA, draftRevision: nb.revision, clientRequestId: crypto.randomUUID() });
    if (![200, 201].includes(cr.status)) fallo('crear la versión', cr);
    const v = cr.json.data?.version || cr.json.data;
    console.log('versión creada:', corto({ uuid: v.uuid, label: v.label, status: v.status, contentHash: String(v.contentHash || '').slice(0, 8), autor: v.createdBy?.name ?? v.createdByName }));
    fs.writeFileSync(path.join(DIR, 'prueba-a.version'), String(v.uuid));
    const val = await api('POST', `versions/${v.uuid}/validate`, {});
    console.log('validar →', val.status, corto(val.json?.data, 700));
    console.log('(sin enviar todavía: revisa la validación y usa el modo enviar)');
    return;
  }

  if (MODO === 'enviar') {
    const en = await api('POST', `versions/${ARG}/submit`, { clientRequestId: crypto.randomUUID() });
    if (en.status !== 200) fallo('enviar a revisión', en);
    const v = en.json.data?.version || en.json.data;
    console.log('enviada a revisión →', corto({ uuid: v.uuid, status: v.status, label: v.label }));
    return;
  }

  if (MODO === 'previa') {
    const p = await api('GET', `versions/${ARG}/publication-preview`);
    if (p.status !== 200) fallo('vista previa', p);
    const d = p.json.data;
    const req = Object.fromEntries(Object.entries(d.requirements || {}).map(([k, r]) => [k, r?.ok]));
    console.log('vista previa:', corto({ publishable: d.publishable, effect: d.effect, noEffect: d.noEffect, requirements: req }, 600));
    const pr = d.prompts || {};
    console.log('  prompts:', corto({ key: pr.key, base: pr.base, compiler: pr.compiler, shortHash: pr.shortHash, baseline: pr.baseline, bytes: pr.bytes, error: pr.error }, 400));
    console.log('  cambios:', corto((pr.changes || []).map((x) => ({ param: x.param ?? x.key ?? x.path, from: x.from ?? x.before, to: x.to ?? x.after, sites: x.sites ?? x.prompts })), 900));
    console.log('  params:', corto((d.params || []).map((x) => ({ k: x.key ?? x.path, clase: x.class ?? x.kind, v: x.value ?? x.to })), 1200));
    return;
  }

  if (MODO === 'lab') {
    const esc = await api('GET', 'lab/scenarios?per_page=50');
    const lista = esc.json?.data?.data || esc.json?.data?.items || esc.json?.data || [];
    const activos = lista.filter((e) => (e.status ?? 'active') === 'active').map((e) => e.uuid);
    if (activos.length === 0) fallo('no hay escenarios activos', esc);
    const objetivo = { kind: 'version', uuid: ARG };
    const est = await api('POST', 'lab/estimate', { target: objetivo, scenarioUuids: activos });
    if (est.status !== 200) fallo('estimar', est);
    console.log('estimación:', corto(est.json.data, 700));
    const maximo = est.json.data?.estimate?.maxMicros;
    if (!Number.isInteger(maximo)) { console.log('PARO: no encuentro el coste máximo en la estimación (no lanzo a ciegas).'); process.exit(1); }
    if (maximo > 500000) { console.log('PARO: el coste máximo estimado supera US$ 0,50:', maximo); process.exit(1); }
    const run = await api('POST', 'lab/runs', { target: objetivo, scenarioUuids: activos, acceptedMaxCostMicros: maximo, clientRequestId: crypto.randomUUID() });
    if (![200, 201, 202].includes(run.status)) fallo('lanzar la ejecución', run);
    const r = run.json.data?.run || run.json.data;
    console.log('ejecución lanzada:', corto({ uuid: r.uuid, status: r.status, mode: r.mode, casos: r.casesTotal ?? r.cases?.length, target: r.target }, 500));
    fs.writeFileSync(path.join(DIR, 'prueba-a.ejecucion'), String(r.uuid));
    return;
  }

  if (MODO === 'ejecucion') {
    const r = await api('GET', `lab/runs/${ARG}`);
    if (r.status !== 200) fallo('leer la ejecución', r);
    const d = r.json.data?.run || r.json.data;
    console.log('ejecución:', corto({ uuid: d.uuid, status: d.status, mode: d.mode, model: d.model, costMicros: d.costMicros ?? d.cost?.micros, resultsHash: String(d.resultsHash || '').slice(0, 8), reviewable: d.reviewable, notReviewable: d.notReviewableReason, target: d.target, verdict: d.verdict }, 900));
    const casos = d.cases || r.json.data?.cases || [];
    const cuenta = {};
    for (const c of casos) cuenta[c.outcome ?? c.status ?? c.result] = (cuenta[c.outcome ?? c.status ?? c.result] || 0) + 1;
    console.log('  casos:', casos.length, corto(cuenta));
    for (const c of casos) console.log('   ', c.position ?? '', '·', c.outcome ?? c.status ?? c.result, '·', corto(c.scenario?.label ?? c.label ?? c.scenarioLabel, 70), '·', corto((c.checks || c.findings || []).filter((x) => x.ok === false || x.passed === false || x.severity).map((x) => x.code ?? x.key ?? x.id).slice(0, 6), 160));
    return;
  }

  if (MODO === 'descartar') {
    const b = await leerBorrador();
    const d = await api('POST', 'draft/discard', { baseRevision: b.revision });
    console.log('descartar el borrador de la cuenta técnica →', d.status, corto({ revision: d.json?.data?.revision, persona: d.json?.data?.content?.configs?.persona }, 300));
    return;
  }
  console.log('modo desconocido');
  process.exit(2);
})().catch((e) => { console.log('ERROR', e.message); process.exit(1); });
