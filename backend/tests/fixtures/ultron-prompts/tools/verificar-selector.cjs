#!/usr/bin/env node
'use strict';
/*
 * E3 (tester): el selector «Elegir Prompts» que se pega en n8n, contra lo que
 * sale del CONTRATO (no contra el compilador de PHP).
 *
 *   node backend/tests/fixtures/ultron-prompts/tools/verificar-selector.cjs [selector]
 *
 * `selector` es el `.js` generado (por defecto `n8n/flujos/ultron-studio-s1/elegir-prompts.js`)
 * o un export de n8n (`.json`), del que se toma el `jsCode` del nodo «Elegir Prompts».
 * Se ejecuta como lo ejecuta el nodo Code de n8n: el cuerpo de una función, con
 * `$input.first().json` = la respuesta de `decide`, en un `vm` sin `require`.
 *
 * Lo que se exige (plan §B.7 y M1-5: «todo falla hacia Legacy y nunca deja un turno mudo»):
 *  1. Sin oferta → roles = la base byte a byte, huella observada = Legacy, modo legacy.
 *  2. Para CADA uno de los 306 casos del fixture, una oferta `studio` con los roles
 *     compilados por el guion del tester y su huella → modo studio, los mismos roles
 *     y la misma huella (la SHA-256 del selector = la de Node = la del fixture).
 *  3. Un rol alterado, uno de menos o de más, uno vacío o enorme, otro contrato, una
 *     oferta legacy → Legacy con su motivo, nunca los roles ofrecidos.
 *  4. Entradas hostiles (getters que lanzan, tipos raros, `$input` que lanza) → el
 *     selector NO lanza: Legacy con `selector_error` (o el motivo de forma).
 *  5. El motivo que sale siempre cabe en la regla del commit (`reason` ≤ 64,
 *     `^[A-Za-z0-9_.:-]+$`) o es null: un motivo que no cupiera daría un 422 y una rendición.
 *  6. La respuesta de `decide` sale intacta salvo `studio_prompts` (quitada) y `ultron_prompts` (añadida).
 * Sale 0 si todo cuadra; 1 con la lista de fallos.
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const M = require('./parches-m1.cjs');

const arg = process.argv[2] || path.join(M.REPO, 'n8n', 'flujos', 'ultron-studio-s1', 'elegir-prompts.js');
let codigo;
if (arg.endsWith('.json')) {
  const flujo = JSON.parse(fs.readFileSync(arg, 'utf8'));
  const nodo = flujo.nodes.filter((n) => n.name === 'Elegir Prompts');
  if (nodo.length !== 1) {
    console.error(`${arg}: no hay un único nodo «Elegir Prompts»`);
    process.exit(1);
  }
  codigo = nodo[0].parameters.jsCode;
} else {
  codigo = fs.readFileSync(arg, 'utf8');
}

const fixture = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'compile-cases.json'), 'utf8'));
const base = M.leerBase();
const LEGACY = fixture.legacyBundleHash;
const REASON_COMMIT = /^[A-Za-z0-9_.:-]{1,64}$/;

const fallos = [];
let comprobaciones = 0;
const exige = (cond, que) => {
  comprobaciones++;
  if (!cond) fallos.push(que);
};

/** Ejecuta el selector como el nodo Code de n8n. Devuelve {json} o {lanza}. */
function ejecuta(ctx, { inputLanza = false } = {}) {
  const $input = { first: () => { if (inputLanza) throw new Error('input roto'); return { json: ctx }; } };
  try {
    const out = vm.runInContext(`(function () {\n${codigo}\n})()`, vm.createContext({ $input }), { timeout: 20000 });
    return { json: out[0].json, items: out.length };
  } catch (e) {
    return { lanza: String(e && e.message) };
  }
}

const mismosRoles = (a, b) => a && b && M.ROLES.every((r) => a[r] === b[r]) && Object.keys(a).length === M.ROLES.length;
const esLegacy = (r, motivo, que) => {
  exige(!r.lanza, `${que}: el selector lanza (${r.lanza})`);
  if (r.lanza) return;
  const up = r.json.ultron_prompts || {};
  exige(up.mode === 'legacy', `${que}: modo ${up.mode}, se esperaba legacy`);
  exige(mismosRoles(up.roles, base), `${que}: los roles no son la base byte a byte`);
  if (motivo !== undefined) exige(up.reason === motivo, `${que}: motivo ${JSON.stringify(up.reason)}, se esperaba ${JSON.stringify(motivo)}`);
  exige(up.reason === null || (typeof up.reason === 'string' && REASON_COMMIT.test(up.reason)), `${que}: el motivo ${JSON.stringify(up.reason)} no cabe en la regla del commit`);
  exige(up.flow_rev === 'S1', `${que}: flow_rev ${up.flow_rev}`);
  exige(!('studio_prompts' in r.json), `${que}: studio_prompts sigue en la salida`);
};

const decideBase = { ok: true, conversation_id: 1, message_id: 2, decide_token: 'x', superseded: false, lead: { stage: 'interest' } };
const oferta = (roles, hash, extra = {}) => ({ contract: 'ultron-prompts/1', mode: 'studio', reason: null, base: 'n8n-29de5119', compiler: 1, bundle_hash: hash, version: 'b0000000-0000-4000-8000-00000000000b', content_hash: 'c'.repeat(64), entry: 'e0000000-0000-4000-8000-00000000000e', seq: 7, roles, ...extra });

// 1. Sin oferta.
{
  const r = ejecuta({ ...decideBase });
  esLegacy(r, 'absent', 'sin oferta');
  if (!r.lanza) {
    exige(r.json.ultron_prompts.bundle_hash === LEGACY, `sin oferta: huella observada ${r.json.ultron_prompts.bundle_hash} ≠ Legacy`);
    const { ultron_prompts: _u, ...resto } = r.json;
    exige(JSON.stringify(resto) === JSON.stringify(decideBase), 'sin oferta: la respuesta de decide no sale intacta');
  }
}

// 2. Los 306 casos del contrato, cada uno como oferta studio.
for (const caso of fixture.cases) {
  const c = M.compilar(base, caso.governed);
  exige(c.bundleHash === caso.expect.bundleHash, `${caso.id}: el guion ya no da la huella del fixture`);
  const r = ejecuta({ ...decideBase, studio_prompts: oferta(c.roles, caso.expect.bundleHash) });
  if (r.lanza) {
    exige(false, `${caso.id}: el selector lanza (${r.lanza})`);
    continue;
  }
  const up = r.json.ultron_prompts;
  exige(up.mode === 'studio' && up.reason === null, `${caso.id} (${caso.name}): modo ${up.mode}/${up.reason}, se esperaba studio`);
  exige(up.bundle_hash === caso.expect.bundleHash, `${caso.id}: huella observada ${up.bundle_hash} ≠ ${caso.expect.bundleHash}`);
  exige(mismosRoles(up.roles, c.roles), `${caso.id}: los roles que salen no son los ofrecidos`);
  exige(!('studio_prompts' in r.json) && r.json.lead && r.json.lead.stage === 'interest', `${caso.id}: la respuesta de decide no sale intacta`);
}

// 3. Ofertas que no se pueden usar.
const v2 = fixture.cases.find((c) => c.name === 'tone=profesional emojis=true maxChars=200 goal=cita');
const rolesV2 = M.compilar(base, v2.governed).roles;
const conRoles = (cambio) => { const r = { ...rolesV2 }; cambio(r); return r; };
const casosMalos = [
  ['un carácter alterado en composer', oferta(conRoles((r) => { r.composer = r.composer.replace('REGISTRO PUBLICADO', 'REGISTRO PUBLICADo'); }), v2.expect.bundleHash), 'hash_mismatch'],
  ['la huella de otra versión', oferta(rolesV2, LEGACY), 'hash_mismatch'],
  ['huella en mayúsculas', oferta(rolesV2, v2.expect.bundleHash.toUpperCase()), 'hash_mismatch'],
  ['sin huella', oferta(rolesV2, null), 'hash_mismatch'],
  ['falta critic_retry', oferta(conRoles((r) => { delete r.critic_retry; }), v2.expect.bundleHash), 'shape'],
  ['un rol de más', oferta(conRoles((r) => { r.extra = 'x'; }), v2.expect.bundleHash), 'shape'],
  ['roles como lista', oferta(M.ROLES.map((k) => rolesV2[k]), v2.expect.bundleHash), 'shape'],
  ['roles nulos', oferta(null, v2.expect.bundleHash), 'shape'],
  ['un rol vacío', oferta(conRoles((r) => { r.critic = ''; }), v2.expect.bundleHash), 'size'],
  ['un rol no cadena', oferta(conRoles((r) => { r.critic = 42; }), v2.expect.bundleHash), 'size'],
  ['un rol enorme (40 961)', oferta(conRoles((r) => { r.strategist = 'a'.repeat(40961); }), v2.expect.bundleHash), 'size'],
];
for (const [que, sp, motivo] of casosMalos) {
  const r = ejecuta({ ...decideBase, studio_prompts: sp });
  esLegacy(r, motivo, que);
  if (!r.lanza) exige(r.json.ultron_prompts.bundle_hash === LEGACY, `${que}: huella observada ≠ Legacy`);
}
for (const [que, sp, motivo] of [
  ['otro contrato', { ...oferta(rolesV2, v2.expect.bundleHash), contract: 'ultron-prompts/2' }, 'contract_unknown'],
  ['oferta legacy prompts_off', { contract: 'ultron-prompts/1', mode: 'legacy', reason: 'prompts_off', roles: null, bundle_hash: null }, 'offered_prompts_off'],
  ['oferta legacy sin motivo', { contract: 'ultron-prompts/1', mode: 'legacy', reason: null, roles: null, bundle_hash: null }, 'offered_legacy'],
  ['oferta legacy publication_hash_mismatch', { contract: 'ultron-prompts/1', mode: 'legacy', reason: 'publication_hash_mismatch', roles: null, bundle_hash: null }, 'offered_publication_hash_mismatch'],
  ['oferta con modo desconocido', { ...oferta(rolesV2, v2.expect.bundleHash), mode: 'all', reason: 'x' }, undefined],
]) {
  esLegacy(ejecuta({ ...decideBase, studio_prompts: sp }), motivo, que);
}

// 4 y 5. Entradas hostiles: nunca lanza y el motivo siempre cabe en el commit.
const lanzaAlLeer = (clave) => { const o = oferta(rolesV2, v2.expect.bundleHash); Object.defineProperty(o, clave, { enumerable: true, get() { throw new Error('getter hostil'); } }); return o; };
const rolesQueLanzan = () => { const r = { ...rolesV2 }; Object.defineProperty(r, 'composer', { enumerable: true, get() { throw new Error('getter hostil'); } }); return oferta(r, v2.expect.bundleHash); };
for (const [que, ctx, motivo, opciones] of [
  ['getter de contract que lanza', { ...decideBase, studio_prompts: lanzaAlLeer('contract') }, 'selector_error'],
  ['getter de roles que lanza', { ...decideBase, studio_prompts: lanzaAlLeer('roles') }, 'selector_error'],
  ['un rol cuyo getter lanza', { ...decideBase, studio_prompts: rolesQueLanzan() }, 'selector_error'],
  // El contrato no fija el motivo aquí (su esqueleto lee `$input` fuera del try): se exige no lanzar y Legacy.
  // El selector S1 lo trata como «sin oferta» (`absent`), no como `selector_error` (informe del tester, M-1).
  ['$input.first() lanza', { ...decideBase }, undefined, { inputLanza: true }],
  ['studio_prompts cadena', { ...decideBase, studio_prompts: 'studio' }, undefined],
  ['studio_prompts número', { ...decideBase, studio_prompts: 42 }, undefined],
  ['studio_prompts lista', { ...decideBase, studio_prompts: [] }, undefined],
  ['studio_prompts nulo', { ...decideBase, studio_prompts: null }, 'absent'],
  ['motivo legacy con espacios y acentos', { ...decideBase, studio_prompts: { contract: 'ultron-prompts/1', mode: 'legacy', reason: 'motivo con espacios y ñ', roles: null } }, undefined],
  ['motivo legacy de 300 caracteres', { ...decideBase, studio_prompts: { contract: 'ultron-prompts/1', mode: 'legacy', reason: 'a'.repeat(300), roles: null } }, undefined],
  ['motivo legacy objeto', { ...decideBase, studio_prompts: { contract: 'ultron-prompts/1', mode: 'legacy', reason: { x: 1 }, roles: null } }, undefined],
  ['motivo legacy solo símbolos', { ...decideBase, studio_prompts: { contract: 'ultron-prompts/1', mode: 'legacy', reason: '   ', roles: null } }, undefined],
]) {
  esLegacy(ejecuta(ctx, opciones), motivo, que);
}

if (fallos.length) {
  console.error(`FALLO: ${fallos.length} de ${comprobaciones} comprobaciones (${path.relative(process.cwd(), arg)})`);
  for (const f of fallos.slice(0, 40)) console.error('  - ' + f);
  process.exit(1);
}
console.log(`OK: ${comprobaciones} comprobaciones del selector (${fixture.cases.length} casos del contrato) sobre ${path.relative(process.cwd(), arg)}`);
