#!/usr/bin/env node
/*
 * COMPROBADOR OFFLINE DEL FLUJO DE n8n «S1» (Enmienda M-1 del H5; plan de motores §B.8).
 *
 * Uso (desde cualquier directorio):
 *   node backend/docs/ultron-studio/ops/motores/verificar-flujo.cjs <export.json>
 *        [--respaldo=<29de5119.json>] [--generado=<dir de ultron:prompts-n8n>]
 *
 * Sin red y sin tocar n8n. Sale con 0 solo si TODO cuadra; con 1 ante cualquier
 * diferencia (y la lista de diferencias); con 2 si no puede leer las entradas.
 *
 * Lo que comprueba:
 *   A. La base del repositorio: sus cinco sha256 y la huella Legacy son los de
 *      UltronPromptBase.php (calculados aquí con el crypto de Node).
 *   B. Nodos: los 24 del respaldo 29de5119 + «Elegir Prompts», ni uno más.
 *   C. Los 24 nodos, iguales al respaldo salvo la posición en el lienzo y los
 *      parámetros de §B.7: `responses.values[0].content` de los cinco nodos de
 *      OpenAI, `jsonBody` de «Pedir Contexto al CRM» y `jsCode` de «Preparar
 *      Commit» (por tanto `values[1]`, `options`, modelo, credenciales y
 *      reintentos, idénticos).
 *   D. Esos parámetros valen exactamente lo esperado; el nodo nuevo es un Code
 *      v2 en JavaScript, una vez para todos los ítems.
 *   E. Conexiones: las del respaldo con `Mensaje superado?`[1] → «Elegir
 *      Prompts» → `Strategist` en lugar de `Mensaje superado?`[1] → `Strategist`.
 *   F. El selector, ejecutado en `vm`: sin oferta da la base byte a byte y la
 *      huella Legacy; con una oferta `studio` válida da los roles ofrecidos
 *      (huella del SHA-256 en JS = la de Node = la de PHP en el fixture); un rol
 *      alterado → hash_mismatch; falta o sobra un rol → shape; uno enorme o
 *      vacío → size; contrato desconocido; oferta legacy → offered_<motivo>; una
 *      excepción → selector_error sin huella. Nunca deja `studio_prompts`.
 *   G. «Preparar Commit», ejecutado en `vm` junto al de 29de5119 con los mismos
 *      datos: sin negociación, la MISMA salida y sin bloque `prompts`; con
 *      negociación, la misma salida más un bloque `prompts` válido para el
 *      contrato del commit; si leer el selector falla, sin bloque.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const crypto = require('crypto');
const util = require('util');

const BACKEND = path.resolve(__dirname, '../../../..');
const REPO = path.resolve(BACKEND, '..');
const BASE_DIR = path.join(BACKEND, 'resources/ultron-prompts/n8n-29de5119');
const BASE_PHP = path.join(BACKEND, 'app/Services/Marketing/Ultron/Prompts/UltronPromptBase.php');

const ROLES = ['strategist', 'composer', 'critic', 'composer_retry', 'critic_retry'];
const NODES = { strategist: 'Strategist', composer: 'Composer', critic: 'Critic Comercial', composer_retry: 'Composer Reintento', critic_retry: 'Critic Comercial 2' };
const CONTRACT = 'ultron-prompts/1';
const FLOW_REV = 'S1';
const SELECTOR = 'Elegir Prompts';
const GATE = 'Mensaje superado?';
const DECIDE = 'Pedir Contexto al CRM';
const COMMIT = 'Preparar Commit';
const DECIDE_BODY = '={{ JSON.stringify({ conversation_id: $json.conversation_id, message_id: $json.message_id, prompt_contract: "ultron-prompts/1" }) }}';
const systemExpression = rol => `={{ $('${SELECTOR}').first().json.ultron_prompts.roles.${rol} }}`;
const MAX_ROLE = 40960;
const TOKEN_RE = /^[A-Za-z0-9_.:-]+$/;

// ── Entradas ─────────────────────────────────────────────────────────────

const args = process.argv.slice(2);
const opcion = (nombre, defecto) => {
  const a = args.find(x => x.startsWith(`--${nombre}=`));
  return a ? path.resolve(a.slice(nombre.length + 3)) : defecto;
};
const exportPath = args.find(x => !x.startsWith('--'));
if (!exportPath) {
  console.error('Uso: node verificar-flujo.cjs <export.json> [--respaldo=<29de5119.json>] [--generado=<dir>]');
  process.exit(2);
}
const respaldoPath = opcion('respaldo', path.join(REPO, 'n8n/respaldos/20261111-ultron-29de5119/ultron-commercial-advisor.json'));
const generadoDir = opcion('generado', path.join(REPO, 'n8n/flujos/ultron-studio-s1'));

function leerJson(p) {
  try { return JSON.parse(fs.readFileSync(p, 'utf8')); } catch (e) {
    console.error(`No se puede leer ${p}: ${e.message}`);
    process.exit(2);
  }
}

/** Un export de n8n puede venir con el flujo arriba o envuelto (API, MCP). */
function flujoDe(json) {
  for (const c of [json, json && json.data, json && json.workflow, json && json.data && json.data.workflow]) {
    if (c && Array.isArray(c.nodes) && c.connections && typeof c.connections === 'object') return c;
  }
  return null;
}

const exportado = flujoDe(leerJson(path.resolve(exportPath)));
const respaldo = flujoDe(leerJson(respaldoPath));
if (!exportado || !respaldo) {
  console.error('El export o el respaldo no tienen nodes y connections.');
  process.exit(2);
}

// ── Registro de comprobaciones ───────────────────────────────────────────

const fallos = [];
let total = 0;
function check(nombre, ok, detalle) {
  total++;
  if (ok) { console.log(`  ✓ ${nombre}`); return true; }
  fallos.push(nombre);
  console.log(`  ✗ ${nombre}${detalle ? `\n      ${String(detalle).split('\n').join('\n      ')}` : ''}`);
  return false;
}
const sha256 = s => crypto.createHash('sha256').update(s, 'utf8').digest('hex');
const material = r => [CONTRACT].concat(...ROLES.map(k => [k, r[k]])).join('\n');
const clon = v => JSON.parse(JSON.stringify(v));

/** Las rutas donde dos valores JSON difieren (como mucho 20). */
function diferencias(a, b, ruta = '', out = []) {
  if (out.length >= 20) return out;
  if (a === b) return out;
  const ta = Array.isArray(a) ? 'array' : typeof a;
  const tb = Array.isArray(b) ? 'array' : typeof b;
  if (ta !== tb || a === null || b === null || ta !== 'object' && ta !== 'array') {
    out.push(`${ruta || '/'}: ${util.inspect(a, { depth: 1, maxStringLength: 60 })} ≠ ${util.inspect(b, { depth: 1, maxStringLength: 60 })}`);
    return out;
  }
  const claves = new Set([...Object.keys(a), ...Object.keys(b)]);
  for (const k of claves) diferencias(a[k], b[k], `${ruta}/${k}`, out);
  return out;
}

console.log(`Comprobando ${path.resolve(exportPath)}\n  contra el respaldo ${respaldoPath}\n`);

// ── A. La base y sus huellas ─────────────────────────────────────────────

console.log('A. Base del repositorio');
const php = fs.readFileSync(BASE_PHP, 'utf8');
const legacyPhp = (php.match(/LEGACY_BUNDLE_HASH = '([0-9a-f]{64})'/) || [])[1];
const bloqueSha = (php.match(/const SHA256 = \[([\s\S]*?)\];/) || [])[1] || '';
const shaPhp = {};
for (const m of bloqueSha.matchAll(/'([a-z_]+)' => '([0-9a-f]{64})'/g)) shaPhp[m[1]] = m[2];
const base = {};
for (const rol of ROLES) {
  base[rol] = fs.readFileSync(path.join(BASE_DIR, `${rol}.txt`), 'utf8');
  check(`base ${rol}: sha256 = UltronPromptBase::SHA256`, sha256(base[rol]) === shaPhp[rol], `${sha256(base[rol])} ≠ ${shaPhp[rol]}`);
}
const LEGACY_HASH = sha256(material(base));
check('huella Legacy (Node) = UltronPromptBase::LEGACY_BUNDLE_HASH', LEGACY_HASH === legacyPhp, `${LEGACY_HASH} ≠ ${legacyPhp}`);
const nodosRespaldo = Object.fromEntries(respaldo.nodes.map(n => [n.name, n]));
check('el respaldo es 29de5119 con la base en sus cinco nodos', ROLES.every(r => nodosRespaldo[NODES[r]] && nodosRespaldo[NODES[r]].parameters.responses.values[0].content === base[r]));

// ── B. Nodos ─────────────────────────────────────────────────────────────

console.log('\nB. Nodos');
const nombresExport = exportado.nodes.map(n => n.name);
const nodosExport = Object.fromEntries(exportado.nodes.map(n => [n.name, n]));
const esperados = [...respaldo.nodes.map(n => n.name), SELECTOR].sort();
check(`son los ${respaldo.nodes.length} del respaldo + «${SELECTOR}»`, JSON.stringify([...nombresExport].sort()) === JSON.stringify(esperados),
  `sobran: ${nombresExport.filter(n => !esperados.includes(n)).join(', ') || '—'}; faltan: ${esperados.filter(n => !nombresExport.includes(n)).join(', ') || '—'}`);
check('sin nombres repetidos', new Set(nombresExport).size === nombresExport.length);

// ── C. Los 24 nodos, iguales salvo lo de §B.7 ────────────────────────────

console.log('\nC. Los nodos del respaldo, sin cambios fuera de §B.7');
const PERMITIDO = '«cambio permitido»';
function sinLoPermitido(nodo) {
  const n = clon(nodo);
  delete n.position;
  const rol = Object.keys(NODES).find(r => NODES[r] === n.name);
  if (rol && n.parameters && n.parameters.responses && Array.isArray(n.parameters.responses.values) && n.parameters.responses.values[0]) n.parameters.responses.values[0].content = PERMITIDO;
  if (n.name === DECIDE && n.parameters) n.parameters.jsonBody = PERMITIDO;
  if (n.name === COMMIT && n.parameters) n.parameters.jsCode = PERMITIDO;
  return n;
}
for (const original of respaldo.nodes) {
  const actual = nodosExport[original.name];
  if (!actual) { check(`«${original.name}» existe`, false); continue; }
  const d = diferencias(sinLoPermitido(original), sinLoPermitido(actual));
  check(`«${original.name}» igual al respaldo`, d.length === 0, d.join('\n'));
}

// ── D. Los parámetros de §B.7 y el nodo nuevo ────────────────────────────

console.log('\nD. Parámetros de §B.7');
for (const rol of ROLES) {
  const n = nodosExport[NODES[rol]];
  const v = n && n.parameters && n.parameters.responses && n.parameters.responses.values;
  check(`${NODES[rol]}: sistema = ${systemExpression(rol)}`, Array.isArray(v) && v.length === 2 && v[0].content === systemExpression(rol), v && v[0] ? util.inspect(v[0].content, { maxStringLength: 120 }) : 'sin values');
  const u = nodosRespaldo[NODES[rol]].parameters.responses.values[1].content;
  check(`${NODES[rol]}: values[1] intacto (sha256 ${sha256(u).slice(0, 12)}…)`, Array.isArray(v) && v[1] && sha256(v[1].content) === sha256(u));
}
check(`${DECIDE}: jsonBody negocia el contrato`, nodosExport[DECIDE] && nodosExport[DECIDE].parameters.jsonBody === DECIDE_BODY, nodosExport[DECIDE] && nodosExport[DECIDE].parameters.jsonBody);
const sel = nodosExport[SELECTOR];
check(`«${SELECTOR}» es un Code v2`, sel && sel.type === 'n8n-nodes-base.code' && sel.typeVersion === 2);
const pSel = (sel && sel.parameters) || {};
check(`«${SELECTOR}» en JavaScript, una vez para todos los ítems, sin otros parámetros`,
  typeof pSel.jsCode === 'string' && Object.keys(pSel).every(k => ['jsCode', 'mode', 'language'].includes(k))
  && (pSel.mode === undefined || pSel.mode === 'runOnceForAllItems') && (pSel.language === undefined || pSel.language === 'javaScript'),
  JSON.stringify(Object.keys(pSel)));
check(`«${SELECTOR}» sin credenciales, activo y sin manejo de error propio`, sel && !sel.credentials && !sel.disabled && !sel.onError);
const commitExport = nodosExport[COMMIT] && nodosExport[COMMIT].parameters.jsCode;
check(`${COMMIT}: el código de 29de5119 + el bloque prompts y nada más`, typeof commitExport === 'string'
  && commitExport.replace(/\/\*\n \* ULTRON STUDIO S1[\s\S]*?\} catch \(e\) \{ promptsAplicados = null; \}\n\n/, '')
    .replace('return [{ json: Object.assign({\n', 'return [{ json: {\n')
    .replace('\n}, promptsAplicados ? { prompts: promptsAplicados } : {}) }];', '\n} }];') === nodosRespaldo[COMMIT].parameters.jsCode);

// ── E. Conexiones ────────────────────────────────────────────────────────

console.log('\nE. Conexiones');
const conEsperadas = clon(respaldo.connections);
conEsperadas[GATE].main[1] = [{ node: SELECTOR, type: 'main', index: 0 }];
conEsperadas[SELECTOR] = { main: [[{ node: NODES.strategist, type: 'main', index: 0 }]] };
const dc = diferencias(conEsperadas, exportado.connections);
check(`las del respaldo con ${GATE}[1] → «${SELECTOR}» → Strategist`, dc.length === 0, dc.join('\n'));

// ── F. El selector, ejecutado ────────────────────────────────────────────

console.log('\nF. El selector, ejecutado en vm');
function selector(ctx) {
  const codigo = pSel.jsCode;
  const contexto = vm.createContext({ $input: { first: () => ({ json: ctx }) } });
  return vm.runInContext(`(function () {\n${codigo}\n})()`, contexto, { timeout: 10000 })[0].json;
}
const decideBase = { ok: true, commercial_phase: 'NEW_LEAD', allowed_transitions: ['NEW_LEAD'], decide_token: 'x.y', context: { lead: { name: null } }, superseded: false };
const conOferta = sp => Object.assign(clon(decideBase), { studio_prompts: sp });
const ofertaStudio = (roles, bundle) => ({ contract: CONTRACT, mode: 'studio', reason: null, base: 'n8n-29de5119', compiler: 1, bundle_hash: bundle, version: 'v', content_hash: 'c', entry: 'e', seq: 1, roles });
const iguales = (a, b) => JSON.stringify(a) === JSON.stringify(b);

function esLegacy(nombre, entrada, motivo, conHuella = true) {
  const out = selector(entrada);
  const resto = clon(entrada);
  delete resto.studio_prompts;
  const up = out && out.ultron_prompts;
  check(`${nombre}: legacy con motivo ${motivo}`, up && up.mode === 'legacy' && up.reason === motivo && up.flow_rev === FLOW_REV, up && JSON.stringify({ mode: up.mode, reason: up.reason }));
  check(`${nombre}: roles = la base byte a byte`, up && ROLES.every(r => up.roles[r] === base[r]) && Object.keys(up.roles).length === 5);
  check(`${nombre}: huella observada = ${conHuella ? 'Legacy' : 'null'}`, up && up.bundle_hash === (conHuella ? LEGACY_HASH : null), up && up.bundle_hash);
  check(`${nombre}: sin studio_prompts y el resto de decide intacto`, out && !('studio_prompts' in out) && iguales(Object.assign({}, out, { ultron_prompts: undefined }), resto));
}

if (typeof pSel.jsCode === 'string') {
  try {
    esLegacy('sin oferta', clon(decideBase), 'absent');

    const rolesNode = Object.assign({}, base, { composer: base.composer + '\nREGISTRO PUBLICADO: prueba del comprobador. ñ — «»' });
    const hNode = sha256(material(rolesNode));
    const s = selector(conOferta(ofertaStudio(rolesNode, hNode)));
    check('studio sintético (crypto de Node): usa los roles ofrecidos', s.ultron_prompts.mode === 'studio' && s.ultron_prompts.reason === null && iguales(s.ultron_prompts.roles, rolesNode));
    check('studio sintético: huella JS = huella de Node', s.ultron_prompts.bundle_hash === hNode, `${s.ultron_prompts.bundle_hash} ≠ ${hNode}`);
    check('studio sintético: sin studio_prompts y el resto intacto', !('studio_prompts' in s) && iguales(Object.assign({}, s, { ultron_prompts: undefined }), Object.assign(clon(decideBase), { ultron_prompts: undefined })));

    // Todas las colas del relleno de SHA-256 (64 longitudes consecutivas del material), con UTF-8 de 1, 2 y 3 bytes.
    const malas = [];
    for (let k = 0; k < 64; k++) {
      const r = Object.assign({}, base, { critic_retry: base.critic_retry + 'ñ'.repeat(k % 3) + 'x'.repeat(k) + '—'.repeat(k % 2) });
      const h = sha256(material(r));
      const o = selector(conOferta(ofertaStudio(r, h)));
      if (o.ultron_prompts.mode !== 'studio' || o.ultron_prompts.bundle_hash !== h) malas.push(k);
    }
    check('SHA-256 del selector = el de Node en 64 longitudes consecutivas', malas.length === 0, `fallan: ${malas.join(', ')}`);

    const fixtureStudio = path.join(generadoDir, 'fixtures/decide-studio.json');
    if (check(`fixture PHP presente (${path.relative(REPO, fixtureStudio)})`, fs.existsSync(fixtureStudio))) {
      const fx = leerJson(fixtureStudio);
      const o = selector(clon(fx));
      check('fixture PHP (profesional + 200): studio con la huella calculada en PHP (E3)', o.ultron_prompts.mode === 'studio' && o.ultron_prompts.bundle_hash === fx.studio_prompts.bundle_hash && iguales(o.ultron_prompts.roles, fx.studio_prompts.roles), o.ultron_prompts.reason);
      check('fixture PHP: la huella PHP = la de Node', sha256(material(fx.studio_prompts.roles)) === fx.studio_prompts.bundle_hash);

      const alterado = clon(fx);
      alterado.studio_prompts.roles.composer += ' ';
      esLegacy('un rol alterado en tránsito', alterado, 'hash_mismatch');
      const sinUno = clon(fx); delete sinUno.studio_prompts.roles.critic_retry;
      esLegacy('falta un rol', sinUno, 'shape');
      const sobra = clon(fx); sobra.studio_prompts.roles.judge = 'x';
      esLegacy('sobra un rol', sobra, 'shape');
      const lista = clon(fx); lista.studio_prompts.roles = ROLES.map(r => fx.studio_prompts.roles[r]);
      esLegacy('roles como lista', lista, 'shape');
      const enorme = clon(fx); enorme.studio_prompts.roles.strategist = 'x'.repeat(MAX_ROLE + 1);
      esLegacy('un rol enorme', enorme, 'size');
      const vacio = clon(fx); vacio.studio_prompts.roles.critic = '';
      esLegacy('un rol vacío', vacio, 'size');
      const numero = clon(fx); numero.studio_prompts.roles.critic = 7;
      esLegacy('un rol que no es texto', numero, 'size');
    }

    esLegacy('contrato desconocido', conOferta({ contract: 'ultron-prompts/2', mode: 'studio' }), 'contract_unknown');
    esLegacy('oferta legacy', conOferta({ contract: CONTRACT, mode: 'legacy', reason: 'prompts_off', roles: null }), 'offered_prompts_off');
    esLegacy('oferta legacy con un motivo raro', conOferta({ contract: CONTRACT, mode: 'legacy', reason: 'a b/c' }), 'offered_a_b_c');

    const explota = clon(decideBase);
    Object.defineProperty(explota, 'studio_prompts', { enumerable: false, get() { throw new Error('boom'); } });
    const e = selector(explota);
    check('una excepción leyendo la oferta: legacy, selector_error, sin huella', e.ultron_prompts.mode === 'legacy' && e.ultron_prompts.reason === 'selector_error' && e.ultron_prompts.bundle_hash === null && ROLES.every(r => e.ultron_prompts.roles[r] === base[r]));
  } catch (err) {
    check('el selector se ejecuta sin lanzar', false, err && err.stack);
  }
} else {
  check('el selector tiene código', false);
}

// ── G. Preparar Commit, ejecutado junto al de 29de5119 ───────────────────

console.log('\nG. Preparar Commit, ejecutado en vm');
function prepararCommit(codigo, { ctx, conReintento, ultronPrompts, elegirFalla }) {
  const nodos = {
    'Normalizar Evento': { json: { conversation_id: 11, message_id: 22, idempotency_key: 'wamid.X', text: 'hola' } },
    'Pedir Contexto al CRM': { json: ctx },
    'Validar Estrategia': { json: { strategy: {
      strategy_goal: 'collect_data', next_state: 'GOAL_DISCOVERY', intent: 'general_info', recommended_plan_id: null, main_barrier: null,
      next_best_action: 'ask_discovery', confidence: 0.8, tools_requested: [], staff_review_reason: null, conversation_goal: 'understand',
      information_needed: ['goal'], question_needed: true, response_goal: 'entender', buying_signal: 'none', closing_opportunity: false,
      payment_opportunity: false, app_support_opportunity: false, recommendation_goal: 'none', human_handoff_requested: false,
      human_handoff_reason: null, human_handoff_evidence: null } } },
    'Composer': { json: { output: [{ content: [{ text: JSON.stringify({ reply_draft: 'Claro, te cuento. ¿Qué te gustaría lograr?' }) }] }] } },
  };
  if (conReintento) nodos['Composer Reintento'] = { json: { output: [{ content: [{ text: JSON.stringify({ reply_draft: 'Segundo borrador.' }) }] }] } };
  if (ultronPrompts) nodos[SELECTOR] = { json: Object.assign({}, ctx, { ultron_prompts: ultronPrompts }) };
  const $ = nombre => ({
    first: () => {
      if (nombre === SELECTOR && elegirFalla) throw new Error('no ejecutado');
      if (!nodos[nombre]) throw new Error(`El nodo «${nombre}» no se ejecutó`);
      return nodos[nombre];
    },
  });
  const critic = { output: [{ content: [{ text: JSON.stringify({ verdict: 'pass', score: 0.9, notes: 'ok', issues: [], hard_fail: null }) }] }] };
  const contexto = vm.createContext({ $, $input: { first: () => ({ json: critic }) }, $execution: { id: '4242' } });
  return vm.runInContext(`(function () {\n${codigo}\n})()`, contexto, { timeout: 10000 })[0].json;
}

if (typeof commitExport === 'string') {
  try {
    const original = nodosRespaldo[COMMIT].parameters.jsCode;
    const ctxHoy = Object.assign(clon(decideBase), { source: { source_type: 'inbound_message', source_event_id: 22, idempotency_key: 'wamid.X' }, context: { default_plan_id: 3, active_plans: [{ id: 3 }] } });
    const upStudio = { mode: 'studio', reason: null, bundle_hash: 'a'.repeat(64), flow_rev: FLOW_REV, roles: {} };

    for (const reintento of [false, true]) {
      const etiqueta = reintento ? ' (con reintento)' : '';
      const a = prepararCommit(original, { ctx: ctxHoy, conReintento: reintento });
      const b = prepararCommit(commitExport, { ctx: ctxHoy, conReintento: reintento, ultronPrompts: upStudio });
      check(`sin negociación${etiqueta}: la misma salida que 29de5119 y sin bloque`, iguales(a, b) && !('prompts' in b), diferencias(a, b).join('\n'));

      const ctxNeg = Object.assign(clon(ctxHoy), { studio_prompts: { contract: CONTRACT, mode: 'studio' } });
      const c = prepararCommit(original, { ctx: ctxNeg, conReintento: reintento });
      for (const up of [upStudio, { mode: 'legacy', reason: 'hash_mismatch', bundle_hash: LEGACY_HASH, flow_rev: FLOW_REV, roles: {} }, { mode: 'legacy', reason: 'selector_error', bundle_hash: null, flow_rev: FLOW_REV, roles: {} }]) {
        const d = prepararCommit(commitExport, { ctx: ctxNeg, conReintento: reintento, ultronPrompts: up });
        const p = d.prompts || {};
        const valido = JSON.stringify(Object.keys(p)) === JSON.stringify(['mode', 'bundle_hash', 'reason', 'flow_rev', 'execution_id'])
          && ['studio', 'legacy'].includes(p.mode)
          && (p.bundle_hash === null || /^[0-9a-f]{64}$/.test(p.bundle_hash))
          && (p.reason === null || (p.reason.length <= 64 && TOKEN_RE.test(p.reason)))
          && p.flow_rev.length <= 32 && TOKEN_RE.test(p.flow_rev)
          && p.execution_id.length <= 64 && TOKEN_RE.test(p.execution_id);
        check(`negociado${etiqueta}, ${up.mode}/${up.reason}: la misma salida + un bloque prompts válido`, valido && iguales(Object.assign({}, d, { prompts: undefined }), c)
          && p.mode === up.mode && p.bundle_hash === up.bundle_hash && p.reason === up.reason && p.flow_rev === FLOW_REV && p.execution_id === '4242', JSON.stringify(p));
      }
      const f = prepararCommit(commitExport, { ctx: ctxNeg, conReintento: reintento, elegirFalla: true });
      check(`negociado${etiqueta} pero sin poder leer el selector: sin bloque, misma salida`, !('prompts' in f) && iguales(f, c));
    }
  } catch (err) {
    check('Preparar Commit se ejecuta sin lanzar', false, err && err.stack);
  }
}

// ── Resultado ────────────────────────────────────────────────────────────

console.log('');
if (fallos.length === 0) {
  console.log(`OK (${total} comprobaciones)`);
  process.exit(0);
}
console.log(`FALLO: ${fallos.length} de ${total} comprobaciones`);
process.exit(1);
