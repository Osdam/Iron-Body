// ESCRIBE EN PRODUCCIÓN (datos del Studio) con la sesión de la cuenta técnica, por autorización expresa del propietario
// del 23-11-2026 (opción A), y SOLO estas tres operaciones:
//   1. crear la versión 4 desde el borrador de la cuenta técnica (el mismo contenido que la versión 3),
//   2. enviarla a revisión,
//   3. crear cuatro copias del escenario E-001 del laboratorio (para las 20 muestras en 4 ejecuciones de 5 casos).
// No aprueba, no rechaza, no da veredictos, no publica, no revierte, no lanza ejecuciones (no gasta) y no toca permisos.
//
//   node preparar-version-4.cjs <sesion.json> <llaves.json> leer       solo lectura: las condiciones previas
//   node preparar-version-4.cjs <sesion.json> <llaves.json> escribir   las tres operaciones, cada una con su comprobación
//   node preparar-version-4.cjs <sesion.json> <llaves.json> despues    solo lectura: cómo quedó y lo que costará medirla
//
// FALLA CERRADO: se detiene sin seguir si el borrador no existe o no pasa la validación, si ya hay una versión con esa
// etiqueta que no sea la de esta misma orden, o si la versión creada no tiene la MISMA huella de contenido que la 3.
// Las llaves de idempotencia se guardan en <llaves.json> antes de la primera escritura: repetir la orden no duplica nada
// (el servidor responde `replayed`). Nunca imprime la sesión ni nombres de personas.
'use strict';
const fs = require('fs');
const crypto = require('crypto');

const [, , SESION, LLAVES, FASE] = process.argv;
if (!SESION || !LLAVES || !['leer', 'escribir', 'despues'].includes(FASE)) { console.log('uso: node preparar-version-4.cjs <sesion.json> <llaves.json> leer|escribir|despues'); process.exit(2); }
const { token } = JSON.parse(fs.readFileSync(SESION, 'utf8'));
const API = 'https://api.ironbodyneiva.cloud/api/admin/ultron-studio';
const V3 = '39cfae9f-19a1-44bd-8d8e-f9f3327fed81';
const ETIQUETA = 'Prueba de aceptación 4 · profesional 200';
const NOTA = 'Mismo contenido que la versión 3 (huella 24b29173). Creada con la cuenta técnica por autorización expresa del propietario del 23-11-2026 (opción A), para medir en el laboratorio el compilador 5 del turno de planes (PT-17). No publicar hasta superar los umbrales de la medición.';
const ORIGEN = 'E-001';
const COPIAS = [2, 3, 4, 5];

const corto = (v, n = 300) => { const s = typeof v === 'string' ? v : JSON.stringify(v); return s === undefined ? 'undefined' : (s.length > n ? s.slice(0, n) + '…' : s); };
const usd = (micros) => (typeof micros === 'number' ? 'US$ ' + (micros / 1e6).toFixed(4) : '?');
const igual = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const para = (motivo, codigo = 3) => { console.log('DETENIDO: ' + motivo); process.exit(codigo); };

async function llamar(metodo, ruta, cuerpo) {
  const r = await fetch(`${API}/${ruta}`, {
    method: metodo,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
    body: cuerpo === undefined ? undefined : JSON.stringify(cuerpo),
  });
  let j = null;
  try { j = await r.json(); } catch { /* sin cuerpo */ }
  return { status: r.status, json: j, data: j && j.data !== undefined ? j.data : null, replayed: !!(j && j.replayed) };
}
// Lo que dice el servidor cuando no es lo esperado: código y mensaje, nunca el cuerpo entero.
const fallo = (r) => `HTTP ${r.status} · ${corto({ code: r.json?.code ?? r.json?.error ?? null, message: r.json?.message ?? null, errors: r.json?.errors ?? null }, 500)}`;

const turnosDe = (e) => (e.turns || []).map((t) => ({ role: t.role, text: t.text }));
const hechosDe = (e) => ({ paymentState: e.facts?.paymentState, handoffRequested: e.facts?.handoffRequested === true });
const criteriosDe = (e) => (e.criteria || []).map((k) => ({ kind: k.kind, text: k.text, rules: Array.isArray(k.rules) ? k.rules : [] }));

async function previas() {
  const b = await llamar('GET', 'draft');
  if (b.status !== 200 || !b.data) para('no se pudo leer el borrador: ' + fallo(b));
  const borrador = b.data;
  console.log(`[borrador de la cuenta técnica] existe: ${borrador.exists} · revisión ${borrador.revision} · guardado ${borrador.savedAt} · catálogo ${borrador.catalogVersion} · validación: ${corto({ publishable: borrador.validation?.publishable, warnings: borrador.validation?.warnings, blockers: borrador.validation?.blockers }, 260)}`);
  if (borrador.exists !== true || !(borrador.revision >= 1)) para('la cuenta técnica no tiene borrador guardado');

  // `GET versions` devuelve una PÁGINA: las filas van dentro (`data.data`), con su total.
  const lista = await llamar('GET', 'versions?per_page=50');
  const pagina = lista.data && !Array.isArray(lista.data) ? lista.data : null;
  const filas = Array.isArray(lista.data) ? lista.data : (Array.isArray(pagina?.data) ? pagina.data : (Array.isArray(pagina?.items) ? pagina.items : null));
  if (lista.status !== 200 || filas === null) para(`no se pudo leer la lista de versiones: HTTP ${lista.status} · claves ${corto(pagina ? Object.keys(pagina) : typeof lista.data, 200)}`);
  const total = pagina ? (pagina.total ?? pagina.meta?.total ?? null) : filas.length;
  console.log(`[versiones] ${filas.length} leídas · total que declara el servidor: ${total}`);
  if (total !== null && total !== filas.length) para(`la lista de versiones no cabe en una página (${filas.length} de ${total}): no se puede asegurar que la etiqueta esté libre`);
  for (const v of filas) console.log(`[versión] ${String(v.uuid).slice(0, 8)} · «${v.label}» · ${v.status} · contenido ${String(v.contentHash).slice(0, 8)}`);
  const yaEsta = filas.filter((v) => v.label === ETIQUETA);

  const v3 = await llamar('GET', `versions/${V3}`);
  if (v3.status !== 200 || !v3.data?.contentHash) para('no se pudo leer la versión 3: ' + fallo(v3));
  console.log(`[versión 3] «${v3.data.label}» · ${v3.data.status} · contenido ${v3.data.contentHash} · íntegra: ${v3.data.integrityOk}`);
  if (v3.data.integrityOk !== true) para('la versión 3 no supera su comprobación de integridad');

  const esc = await llamar('GET', 'lab/scenarios');
  if (esc.status !== 200 || !Array.isArray(esc.data)) para('no se pudo leer el catálogo de escenarios: ' + fallo(esc));
  const origen = esc.data.find((e) => e.code === ORIGEN);
  if (!origen) para(`no existe el escenario ${ORIGEN}`);
  console.log(`[escenarios] ${esc.data.length} en total · del catálogo ${esc.data.filter((e) => e.source === 'catalog').length} · del personal ${esc.data.filter((e) => e.source === 'studio').length}`);
  console.log(`[${ORIGEN}] ${origen.uuid} · rev ${origen.revision} · ${origen.status} · ${origen.category} · «${origen.title}» · huella ${String(origen.contentHash).slice(0, 8)}`);
  console.log(`    descripción: ${corto(origen.description, 200)} · hechos: ${corto(hechosDe(origen))}`);
  for (const t of turnosDe(origen)) console.log(`    turno (${t.role}): ${corto(t.text, 200)}`);
  for (const k of criteriosDe(origen)) console.log(`    criterio (${k.kind}; reglas ${corto(k.rules, 60)}): ${corto(k.text, 220)}`);
  if (origen.status !== 'active') para(`${ORIGEN} no está activo`);
  return { borrador, v3: v3.data, yaEsta, escenarios: esc.data, origen };
}

function llaves() {
  if (fs.existsSync(LLAVES)) return JSON.parse(fs.readFileSync(LLAVES, 'utf8'));
  const k = { version: crypto.randomUUID(), copias: Object.fromEntries(COPIAS.map((n) => [String(n), crypto.randomUUID()])) };
  fs.writeFileSync(LLAVES, JSON.stringify(k, null, 2), { mode: 0o600 });
  return k;
}

async function despues(uuidV4, origen) {
  const d = await llamar('GET', `versions/${uuidV4}`);
  if (d.status !== 200 || !d.data) para('no se pudo releer la versión 4: ' + fallo(d), 4);
  const v = d.data;
  console.log(`[versión 4, releída] ${v.uuid} · «${v.label}» · ${v.status} · contenido ${v.contentHash} · íntegra: ${v.integrityOk} · avisos de su validación: ${corto(v.lastValidation?.warnings, 200)}`);
  console.log(`    lo que ESTA cuenta (la que la creó) puede hacer con ella: ${corto(v.actions, 700)}`);
  const p = await llamar('GET', `versions/${uuidV4}/publication-preview`);
  if (p.status === 200 && p.data) {
    console.log(`[vista previa de publicar la versión 4, vista por la cuenta que la creó] publicable: ${p.data.publishable}`);
    for (const [k, r] of Object.entries(p.data.requirements || {})) console.log(`    requisito ${k.padEnd(16)} → ${r?.ok === true ? 'cumple' : 'FALTA '} · ${corto(r?.message ?? r?.reason ?? r, 200)}`);
    console.log(`    prompts que firmaría: ${corto(p.data.prompts, 500)}`);
    console.log(`    puerta del laboratorio: ${corto(p.data.labGate, 300)}`);
  } else {
    console.log('[vista previa de publicar] ' + fallo(p));
  }
  const esc = await llamar('GET', 'lab/scenarios');
  const mias = (esc.data || []).filter((e) => e.source === 'studio');
  for (const e of mias) console.log(`[escenario del personal] ${e.code} · ${e.uuid} · rev ${e.revision} · ${e.status} · «${e.title}» · mismos turnos, hechos y criterios que ${ORIGEN}: ${igual(turnosDe(e), turnosDe(origen)) && igual(hechosDe(e), hechosDe(origen)) && igual(criteriosDe(e), criteriosDe(origen)) ? 'SÍ' : 'NO'}`);
  const planes = mias.filter((e) => e.status === 'active' && igual(turnosDe(e), turnosDe(origen))).map((e) => e.uuid);
  const catalogo = (esc.data || []).filter((e) => e.source === 'catalog' && e.status === 'active').map((e) => e.uuid);
  // POST lab/estimate NO escribe ni gasta (va con las lecturas): es lo que verá el propietario al pulsar «Estimar el coste».
  for (const [nombre, cuerpo] of [
    [`recorrido del motor · ${planes.length} casos de planes`, { target: { kind: 'version', uuid: uuidV4 }, scenarioUuids: planes, fidelity: 'engine' }],
    [`simulación del Studio · ${catalogo.length} escenarios del catálogo`, { target: { kind: 'version', uuid: uuidV4 }, scenarioUuids: catalogo, fidelity: 'simulation' }],
  ]) {
    const e = await llamar('POST', 'lab/estimate', cuerpo);
    if (e.status !== 200 || !e.data) { console.log(`[estimación · ${nombre}] ${fallo(e)}`); continue; }
    const x = e.data;
    console.log(`[estimación · ${nombre}] ${corto(x, 1100)}`);
  }
  const st = await llamar('GET', 'lab/status');
  // Modelo, precios, presupuesto y cupos del laboratorio: no son secretos (lo mismo que imprime lab-estado.cjs).
  console.log(`[laboratorio] HTTP ${st.status} · ${corto(st.data, 1400)}`);
}

(async () => {
  console.log(`── ${FASE.toUpperCase()} · ${new Date().toISOString()} ──`);
  const pre = await previas();
  const k = fs.existsSync(LLAVES) ? JSON.parse(fs.readFileSync(LLAVES, 'utf8')) : null;

  if (FASE === 'leer') {
    if (pre.yaEsta.length) para(`ya existe una versión con la etiqueta «${ETIQUETA}» (${pre.yaEsta.map((v) => String(v.uuid).slice(0, 8) + ' ' + v.status).join(', ')}): no se crea otra a ciegas`);
    const repetidas = pre.escenarios.filter((e) => /\(copia [2-5]\)$/.test(e.title || ''));
    if (repetidas.length) para(`ya hay escenarios con título de copia: ${repetidas.map((e) => e.code).join(', ')}`);
    console.log(`CONDICIONES PREVIAS: OK · se crearía «${ETIQUETA}» desde la revisión ${pre.borrador.revision} del borrador y ${COPIAS.length} copias de ${ORIGEN}`);
    return;
  }

  if (FASE === 'despues') {
    if (pre.yaEsta.length !== 1) para(`se esperaba una sola versión «${ETIQUETA}» y hay ${pre.yaEsta.length}`, 4);
    await despues(pre.yaEsta[0].uuid, pre.origen);
    return;
  }

  // ── ESCRIBIR ──
  if (pre.yaEsta.length && !k) para(`ya existe una versión con la etiqueta «${ETIQUETA}» y no hay llaves de esta orden: no se sigue`);
  const llave = llaves();

  // 1. Crear la versión 4 desde el borrador de la cuenta técnica.
  const c = await llamar('POST', 'versions', { label: ETIQUETA, note: NOTA, draftRevision: pre.borrador.revision, clientRequestId: llave.version });
  if (![200, 201].includes(c.status) || !c.data?.uuid) para('[1] crear la versión: ' + fallo(c));
  const v4 = c.data;
  console.log(`[1] CREADA${c.replayed ? ' (repetición: ya estaba)' : ''} → HTTP ${c.status} · ${v4.uuid} · «${v4.label}» · ${v4.status} · contenido ${v4.contentHash} · íntegra: ${v4.integrityOk}`);
  if (v4.contentHash !== pre.v3.contentHash) para(`[1] la versión creada NO tiene el contenido de la versión 3 (${v4.contentHash} frente a ${pre.v3.contentHash}): queda en borrador, sin enviar y sin copias`);
  console.log(`    MISMO CONTENIDO QUE LA VERSIÓN 3: sí (${v4.contentHash})`);

  // 2. Enviarla a revisión (solo puede quien la creó).
  if (v4.status === 'DRAFT') {
    const s = await llamar('POST', `versions/${v4.uuid}/submit`, {});
    if (s.status !== 200 || !s.data?.status) para('[2] enviar a revisión: ' + fallo(s));
    console.log(`[2] ENVIADA A REVISIÓN → HTTP ${s.status} · estado ${s.data.status} · avisos: ${corto(s.data.lastValidation?.warnings, 200)}`);
  } else {
    console.log(`[2] ya no está en borrador (estado ${v4.status}): no se vuelve a enviar`);
  }

  // 3. Cuatro copias del escenario E-001 (mismos turnos, hechos y criterios; el título lleva «(copia N)»).
  for (const n of COPIAS) {
    const cuerpo = {
      category: pre.origen.category,
      title: `${pre.origen.title} (copia ${n})`,
      description: pre.origen.description ?? null,
      turns: turnosDe(pre.origen),
      facts: hechosDe(pre.origen),
      criteria: criteriosDe(pre.origen),
      clientRequestId: llave.copias[String(n)],
    };
    const e = await llamar('POST', 'lab/scenarios', cuerpo);
    if (![200, 201].includes(e.status) || !e.data?.uuid) para(`[3] copia ${n}: ` + fallo(e));
    const mismo = igual(turnosDe(e.data), turnosDe(pre.origen)) && igual(hechosDe(e.data), hechosDe(pre.origen)) && igual(criteriosDe(e.data), criteriosDe(pre.origen));
    console.log(`[3] COPIA ${n}${e.replayed ? ' (repetición: ya estaba)' : ''} → HTTP ${e.status} · ${e.data.code} · ${e.data.uuid} · rev ${e.data.revision} · ${e.data.status} · «${e.data.title}» · mismos turnos, hechos y criterios que ${ORIGEN}: ${mismo ? 'SÍ' : 'NO'}`);
    if (!mismo) para(`[3] la copia ${n} no quedó igual que ${ORIGEN}`);
  }

  console.log('── LAS TRES OPERACIONES, HECHAS · relectura ──');
  await despues(v4.uuid, pre.origen);
})().catch((e) => { console.log('ERROR ' + String(e && e.message ? e.message : e).slice(0, 300)); process.exit(1); });
