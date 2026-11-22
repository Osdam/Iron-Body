#!/usr/bin/env node
/*
 * COMPROBADOR OFFLINE DEL FLUJO DE n8n «S2» («regenerar antes de sustituir», ola 5; plan
 * `plan-arquitecto-a4-regenerar.md` §5.4). Es el modo S2 de `verificar-flujo.cjs`.
 *
 * Uso (desde cualquier directorio):
 *   node backend/docs/ultron-studio/ops/motores/verificar-flujo.cjs --rev=S2 <export.json>
 *        [--s1=<flujo-s1.json o export de la copia S1>] [--generado=<dir de ultron:prompts-n8n --rev=S2>]
 *   (o este fichero directamente, con los mismos argumentos sin --rev)
 *
 * Sin red y sin tocar n8n. Sale con 0 solo si TODO cuadra; con 1 ante cualquier diferencia (y la
 * lista de diferencias); con 2 si no puede leer las entradas. Pone `process.exitCode` y no corta la
 * salida con `process.exit()` (en macOS una tubería es asíncrona y se perderían líneas).
 *
 * Lo que comprueba (el plan, §5.4):
 *   A. La base: sus cinco sha256 y la huella Legacy (lo de hoy); el S1 de referencia es el
 *      generado (25 nodos y las huellas de S1); el vocabulario del servidor, leído del PHP.
 *   B. Nodos: los 25 de S1 + los 14 de §5.2, ni uno más (39, 12 con credencial).
 *   C. Los 25 de S1, idénticos a S1 salvo el `jsCode` de Preparar Commit y el `FLOW_REV` del
 *      selector (la posición en el lienzo no cuenta, como en S1).
 *   D. Los parámetros de los nuevos, exactos: los IF con la forma del de S1 y su expresión; la
 *      comprobación con la URL y la credencial de `decide` (por referencia), 3 s, sin reintentos,
 *      `fullResponse` + `neverError` y `continueRegularOutput`; los Code = los generados; las dos
 *      copias de OpenAI iguales a sus originales salvo nombre, id, posición y `rejected_draft`;
 *      Preparar Commit = el de S1 con sus dos anclas; el vocabulario de los Code = el del PHP.
 *   E. Las conexiones de §5.1: las de S1 con sus tres cambios y las de los 14.
 *   F. El selector, ejecutado en `vm`: el de S1 con `flowRev S2`.
 *   G. Preparar Commit, ejecutado en `vm` junto al de S1 con los mismos datos: sin oferta, la
 *      MISMA salida y sin bloque; con oferta, la misma más un bloque válido para §4.3; una lectura
 *      que lanza dentro del bloque → sin bloque; el tercer borrador; los tokens.
 *   H. Los Code nuevos, ejecutados en `vm` con los fixtures de `ai/check` (pasa, reescribe,
 *      legacy, 404, 422, red, mal formada y variantes): solo un 200 bien formado reescribe.
 *   I. El grafo: sin bucles fuera del ciclo de la rendición; en todo camino de Composer a
 *      Preparar Commit, ≤ 3 redactores, ≤ 3 revisores y ≤ 2 comprobaciones; el último borrador lo
 *      juzga un revisor; el tercero no se comprueba; la salida «sí» de ¿Comprobar k? pasa por la
 *      comprobación. Y los RECORRIDOS: el flujo se ejecuta nodo a nodo (los IF y los Code reales;
 *      los de OpenAI y la comprobación con salidas fijadas) en los seis casos del plan y en los
 *      del servidor caído, con lo que no corrió lanzando y devolviendo `undefined`; sin oferta,
 *      las mismas llamadas, los mismos datos y la misma salida de Preparar Commit que S1.
 *   J. El cuerpo de la comprobación: solo las claves de §4.1, de los nodos que tocan.
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
const ULTRON_PHP = path.join(BACKEND, 'app/Services/Marketing/Ultron');

const ROLES = ['strategist', 'composer', 'critic', 'composer_retry', 'critic_retry'];
const CONTRATO_PROMPTS = 'ultron-prompts/1';
const SELECTOR = 'Elegir Prompts';
const DECIDE = 'Pedir Contexto al CRM';
const COMMIT = 'Preparar Commit';
const EVENTO = 'Normalizar Evento';
const ESTRATEGIA = 'Validar Estrategia';
const RENDICION = 'Preparar Rendicion';
const STRATEGIST = 'Strategist';
const COMPOSER = 'Composer';
const CRITIC = 'Critic Comercial';
const RETRY = 'Composer Reintento';
const CRITIC2 = 'Critic Comercial 2';
const RETRY2 = 'Composer Reintento 2';
const CRITIC3 = 'Critic Comercial 3';
const IF_C = { 1: '¿Comprobar 1?', 2: '¿Comprobar 2?' };
const CHK = { 1: 'Comprobar Borrador 1', 2: 'Comprobar Borrador 2' };
const LEER = { 1: 'Leer Comprobación 1', 2: 'Leer Comprobación 2' };
const IF_R = { 1: '¿Reescribir 1?', 2: '¿Reescribir 2?' };
const NOTA = { 1: 'Nota del Servidor 1', 2: 'Nota del Servidor 2' };
const DECIDIR = 'Decidir Intento 3';
const IF_3 = '¿Intento 3?';
const NUEVOS = [IF_C[1], CHK[1], LEER[1], IF_R[1], NOTA[1], IF_C[2], CHK[2], LEER[2], IF_R[2], NOTA[2], DECIDIR, IF_3, RETRY2, CRITIC3];
const REDACTORES = [COMPOSER, RETRY, RETRY2];
const REVISORES = [CRITIC, CRITIC2, CRITIC3];
const COMPROBACIONES = [CHK[1], CHK[2]];
const MODELOS = [STRATEGIST, COMPOSER, CRITIC, RETRY, CRITIC2, RETRY2, CRITIC3];
const FICHEROS_CODE = { [LEER[1]]: 'leer-comprobacion-1.js', [LEER[2]]: 'leer-comprobacion-2.js', [NOTA[1]]: 'nota-del-servidor.js', [NOTA[2]]: 'nota-del-servidor.js', [DECIDIR]: 'decidir-intento-3.js' };
const OPENAI = '@n8n/n8n-nodes-langchain.openAi';
const IF = 'n8n-nodes-base.if';
const HTTP = 'n8n-nodes-base.httpRequest';
const CODE = 'n8n-nodes-base.code';

const CONTRATO = 'ultron-regen/1';
const DECIDE_URL = 'https://api.ironbodyneiva.cloud/api/internal/marketing/ai/decide';
const CHECK_URL = 'https://api.ironbodyneiva.cloud/api/internal/marketing/ai/check';
const PLANTILLA_IF = 'Rendirse?';
const IF_OFERTA = "={{ $('Pedir Contexto al CRM').first().json.regeneration ? $('Pedir Contexto al CRM').first().json.regeneration.contract === 'ultron-regen/1' : false }}";
const IF_REESCRIBIR = '={{ $json.ultron_check ? $json.ultron_check.rewrite === true : false }}';
const IF_TERCERO = '={{ $json.ultron_intento3 === true }}';
const EXPRESION_IF = { [IF_C[1]]: IF_OFERTA, [IF_C[2]]: IF_OFERTA, [IF_R[1]]: IF_REESCRIBIR, [IF_R[2]]: IF_REESCRIBIR, [IF_3]: IF_TERCERO };
const cuerpoEsperado = k => {
  const e = '$("Normalizar Evento").first().json';
  const s = '$("Validar Estrategia").first().json.strategy';
  return `={{ JSON.stringify({ conversation_id: ${e}.conversation_id, message_id: ${e}.message_id, decide_token: $("Pedir Contexto al CRM").first().json.decide_token, attempt: ${k}, `
    + `draft: { reply_draft: $("${k === 1 ? COMPOSER : RETRY}").first().json.output[0].content[0].text.reply_draft, intent: ${s}.intent, `
    + `recommended_plan_id: ${s}.recommended_plan_id === undefined ? null : ${s}.recommended_plan_id, tools_requested: ${s}.tools_requested } }) }}`;
};
const RECHAZADO_1 = 'rejected_draft: $("Composer").first().json.output[0].content[0].text.reply_draft';
const RECHAZADO_2 = 'rejected_draft: $("Composer Reintento").first().json.output[0].content[0].text.reply_draft';
const CLAVES_CUERPO = ['conversation_id', 'message_id', 'decide_token', 'attempt', 'draft'];
const CLAVES_BORRADOR = ['reply_draft', 'intent', 'recommended_plan_id', 'tools_requested'];
const CLAVES_BLOQUE = ['contract', 'drafts', 'critics', 'checks', 'check_errors', 'rewrites', 'tokens'];
const CLAVES_CHECK = ['rewrite', 'may_rewrite', 'error', 'codes', 'issues', 'notes'];
const MAX_TOKENS = 2000000;
const FIN_S1 = '\n}, promptsAplicados ? { prompts: promptsAplicados } : {}) }];';
const FIN_S2 = '\n}, promptsAplicados ? { prompts: promptsAplicados } : {}, regeneracion ? { regeneration: regeneracion } : {}) }];';
const TERCERO_RE = /\n\/\*\n \* ULTRON STUDIO S2 \(«regenerar antes de sustituir»\): el tercer borrador[\s\S]*?\} catch \(e\) \{ \/\* no corrio: se queda el borrador de antes \*\/ \}\n/;
const BLOQUE_RE = /\/\*\n \* ULTRON STUDIO S2 \(«regenerar antes de sustituir», plan §4\.3 y §5\.2\)[\s\S]*?\} catch \(e\) \{ regeneracion = null; \}\n\n/;

class EntradaIlegible extends Error {}

// ── Utilidades ───────────────────────────────────────────────────────────

const sha256 = s => crypto.createHash('sha256').update(s, 'utf8').digest('hex');
const material = r => [CONTRATO_PROMPTS].concat(...ROLES.map(k => [k, r[k]])).join('\n');
const clon = v => (v === undefined ? undefined : JSON.parse(JSON.stringify(v)));
const iguales = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const insp = v => util.inspect(v, { depth: 4, maxStringLength: 80, breakLength: 160 });

function leerJson(p) {
  try { return JSON.parse(fs.readFileSync(p, 'utf8')); } catch (e) { throw new EntradaIlegible(`No se puede leer ${p}: ${e.message}`); }
}

/** Un export de n8n puede venir con el flujo arriba o envuelto (API, MCP). */
function flujoDe(json) {
  for (const c of [json, json && json.data, json && json.workflow, json && json.data && json.data.workflow]) {
    if (c && Array.isArray(c.nodes) && c.connections && typeof c.connections === 'object') return c;
  }
  return null;
}

/** Las rutas donde dos valores JSON difieren (como mucho 20). */
function diferencias(a, b, ruta = '', out = []) {
  if (out.length >= 20) return out;
  if (a === b) return out;
  const ta = Array.isArray(a) ? 'array' : typeof a;
  const tb = Array.isArray(b) ? 'array' : typeof b;
  if (ta !== tb || a === null || b === null || (ta !== 'object' && ta !== 'array')) {
    out.push(`${ruta || '/'}: ${util.inspect(a, { depth: 1, maxStringLength: 60 })} ≠ ${util.inspect(b, { depth: 1, maxStringLength: 60 })}`);
    return out;
  }
  for (const k of new Set([...Object.keys(a), ...Object.keys(b)])) diferencias(a[k], b[k], `${ruta}/${k}`, out);
  return out;
}

/**
 * Las constantes de un fichero PHP, resueltas (cadenas, enteros, listas, `self::X`, `Clase::X` y
 * `...self::X`): así el vocabulario del comprobador es el del servidor, sin copiarlo.
 */
function lectorPhp(ficheros) {
  const tablas = {};
  for (const [clase, fichero] of Object.entries(ficheros)) {
    const php = fs.readFileSync(fichero, 'utf8');
    tablas[clase] = {};
    for (const m of php.matchAll(/\bconst\s+([A-Z][A-Z0-9_]*)\s*=\s*(\[[^\]]*\]|'[^']*'|[A-Za-z]+::[A-Z][A-Z0-9_]*|\d+)\s*;/g)) tablas[clase][m[1]] = m[2].trim();
  }
  const valor = (clase, nombre, pila = 0) => {
    const raw = tablas[clase] && tablas[clase][nombre];
    if (raw === undefined || pila > 10) throw new Error(`${clase}::${nombre} no se puede leer del PHP`);
    return expr(clase, raw, pila + 1);
  };
  const expr = (clase, raw, pila) => {
    let m;
    if ((m = raw.match(/^'([^']*)'$/))) return m[1];
    if ((m = raw.match(/^(\d+)$/))) return Number(m[1]);
    if ((m = raw.match(/^(self|[A-Za-z]+)::([A-Z][A-Z0-9_]*)$/))) return valor(m[1] === 'self' ? clase : m[1], m[2], pila);
    if (raw.startsWith('[') && raw.endsWith(']')) {
      const out = [];
      for (const parte of raw.slice(1, -1).split(',').map(x => x.trim()).filter(Boolean)) {
        if (parte.startsWith('...')) out.push(...expr(clase, parte.slice(3), pila)); else out.push(expr(clase, parte, pila));
      }
      return out;
    }
    throw new Error(`no sé leer ${raw}`);
  };
  return valor;
}

// ── La ejecución de n8n, la justa para este flujo ─────────────────────────

/** Una expresión de n8n (`={{ … }}`) evaluada en `vm` con `$json` y `$`. */
function evaluar(expresion, json, $) {
  if (typeof expresion !== 'string' || !expresion.startsWith('={{') || !expresion.endsWith('}}')) throw new Error(`no es una expresión de n8n: ${insp(expresion)}`);
  return vm.runInContext(`(${expresion.slice(3, -2)})`, vm.createContext({ $json: json, $ }), { timeout: 5000 });
}

/** Un nodo Code v2 («una vez para todos los ítems»): devuelve el json del primer ítem. */
function ejecutarCodigo(codigo, entrada, $, entradaRara) {
  const $input = entradaRara || { first: () => ({ json: entrada }), all: () => [{ json: entrada }] };
  const r = vm.runInContext(`(function () {\n${codigo}\n})()`, vm.createContext({ $, $input, $execution: { id: '4242' } }), { timeout: 10000 });
  if (!Array.isArray(r) || !r[0] || !r[0].json || typeof r[0].json !== 'object') throw new Error('el Code no devuelve [{ json }]');
  return r[0].json;
}

/** Un IF 2.3 de una condición: booleano «es verdadero» o texto «igual a» (con la validación estricta, el tipo tiene que cuadrar). */
function evaluarIf(n, json, $) {
  const c = n.parameters && n.parameters.conditions;
  if (!c || c.combinator !== 'and' || !Array.isArray(c.conditions) || c.conditions.length !== 1) throw new Error(`IF no previsto: «${n.name}»`);
  const k = c.conditions[0];
  const v = evaluar(k.leftValue, json, $);
  if (k.operator.type === 'boolean' && k.operator.operation === 'true') {
    if (typeof v !== 'boolean') throw new Error(`«${n.name}» no recibe un booleano: ${insp(v)}`);
    return v === true;
  }
  if (k.operator.type === 'string' && k.operator.operation === 'equals') {
    if (typeof v !== 'string' && c.options && c.options.typeValidation === 'strict') throw new Error(`«${n.name}» (estricto) no recibe un texto: ${insp(v)}`);
    return v === k.rightValue;
  }
  throw new Error(`operador no previsto en «${n.name}»: ${insp(k.operator)}`);
}

// ── Datos sintéticos de los recorridos ───────────────────────────────────

const B1 = 'Claro, le cuento. ¿Qué le gustaría lograr?';
const B2 = 'Segundo borrador, de usted y más corto.';
const B3 = 'Tercer borrador.';
const uso = (i, o) => ({ input_tokens: i, output_tokens: o, total_tokens: i + o });
const borrador = (texto, u = uso(9000, 120)) => Object.assign({ output: [{ content: [{ text: { reply_draft: texto, uses_price_placeholder: false, questions_asked: 1, plans_mentioned: 0 } }] }] }, u ? { usage: u } : {});
const revisor = (verdict, issues = [], notes = '', hardFail = null, u = uso(8000, 60)) => Object.assign({ output: [{ content: [{ text: { verdict, score: verdict === 'pass' ? 0.9 : 0.3, issues, notes, hard_fail: hardFail } }] }] }, u ? { usage: u } : {});
const PASA = revisor('pass');
const FALLA = revisor('fail', ['naturalness', 'relevance'], 'NOTA DEL REVISOR 1: tutea y no responde.');
const FALLA2 = revisor('fail', ['next_step'], 'NOTA DEL REVISOR 2: sin paso siguiente.');
const ESTRATEGIA_FIJA = {
  strategy_goal: 'collect_data', next_state: 'GOAL_DISCOVERY', intent: 'general_info', recommended_plan_id: null, main_barrier: null,
  next_best_action: 'ask_discovery', confidence: 0.8, tools_requested: [], staff_review_reason: null, conversation_goal: 'understand',
  information_needed: ['goal'], question_needed: true, response_goal: 'entender', buying_signal: 'none', closing_opportunity: false,
  payment_opportunity: false, app_support_opportunity: false, recommendation_goal: 'none', human_handoff_requested: false,
  human_handoff_reason: null, human_handoff_evidence: null,
};
const EVENTO_FIJO = { conversation_id: 11, message_id: 22, idempotency_key: 'wamid.X', text: 'hola', message_type: 'text', channel: 'whatsapp', event_id: '' };

// ── El comprobador ───────────────────────────────────────────────────────

function main(argv) {
  try {
    return comprobar(argv);
  } catch (e) {
    if (!(e instanceof EntradaIlegible)) throw e;
    console.error(e.message);
    process.exitCode = 2;
    return 2;
  }
}

function comprobar(argv) {
  const opcion = (nombre, defecto) => {
    const a = argv.find(x => x.startsWith(`--${nombre}=`));
    return a ? path.resolve(a.slice(nombre.length + 3)) : defecto;
  };
  const exportPath = argv.find(x => !x.startsWith('--'));
  if (!exportPath) throw new EntradaIlegible('Uso: node verificar-flujo.cjs --rev=S2 <export.json> [--s1=<flujo-s1.json>] [--generado=<dir>]');
  const s1Path = opcion('s1', path.join(REPO, 'n8n/flujos/ultron-studio-s1/flujo-s1.json'));
  const generadoDir = opcion('generado', path.join(REPO, 'n8n/flujos/ultron-studio-s2'));
  const huellasS1Path = fs.existsSync(path.join(path.dirname(s1Path), 'huellas.json')) ? path.join(path.dirname(s1Path), 'huellas.json') : path.join(REPO, 'n8n/flujos/ultron-studio-s1/huellas.json');

  const s2 = flujoDe(leerJson(path.resolve(exportPath)));
  const s1 = flujoDe(leerJson(s1Path));
  if (!s2 || !s1) throw new EntradaIlegible('El export o el S1 de referencia no tienen nodes y connections.');
  const huellasS1 = leerJson(huellasS1Path);
  const fx = nombre => {
    const p = path.join(generadoDir, 'fixtures', nombre);
    return fs.existsSync(p) ? leerJson(p) : null;
  };

  const fallos = [];
  let total = 0;
  const check = (nombre, ok, detalle) => {
    total++;
    if (ok) { console.log(`  ✓ ${nombre}`); return true; }
    fallos.push(nombre);
    console.log(`  ✗ ${nombre}${detalle ? `\n      ${String(detalle).split('\n').join('\n      ')}` : ''}`);
    return false;
  };
  /** Una comprobación que ejecuta código: si lanza, falla con la traza. */
  const prueba = (nombre, fn) => {
    let r;
    try { r = fn(); } catch (e) { return check(nombre, false, e && e.stack ? e.stack.split('\n').slice(0, 4).join('\n') : String(e)); }
    return check(nombre, r === true || (r && r.ok === true), r && r.detalle);
  };

  const nodosS1 = Object.fromEntries(s1.nodes.map(n => [n.name, n]));
  const nodosS2 = Object.fromEntries(s2.nodes.map(n => [n.name, n]));
  console.log(`Comprobando (S2) ${path.resolve(exportPath)}\n  contra S1 ${s1Path}\n  y lo generado en ${generadoDir}\n`);

  // ── A. La base, S1 y el vocabulario ─────────────────────────────────────

  console.log('A. Base del repositorio, S1 de referencia y vocabulario del servidor');
  const php = fs.readFileSync(path.join(ULTRON_PHP, 'Prompts/UltronPromptBase.php'), 'utf8');
  const legacyPhp = (php.match(/LEGACY_BUNDLE_HASH = '([0-9a-f]{64})'/) || [])[1];
  const shaPhp = {};
  for (const m of ((php.match(/const SHA256 = \[([\s\S]*?)\];/) || [])[1] || '').matchAll(/'([a-z_]+)' => '([0-9a-f]{64})'/g)) shaPhp[m[1]] = m[2];
  const base = {};
  for (const rol of ROLES) {
    base[rol] = fs.readFileSync(path.join(BASE_DIR, `${rol}.txt`), 'utf8');
    check(`base ${rol}: sha256 = UltronPromptBase::SHA256`, sha256(base[rol]) === shaPhp[rol]);
  }
  const LEGACY_HASH = sha256(material(base));
  check('huella Legacy (Node) = UltronPromptBase::LEGACY_BUNDLE_HASH', LEGACY_HASH === legacyPhp, `${LEGACY_HASH} ≠ ${legacyPhp}`);
  const selS1 = nodosS1[SELECTOR] && nodosS1[SELECTOR].parameters.jsCode;
  const commitS1 = nodosS1[COMMIT] && nodosS1[COMMIT].parameters.jsCode;
  check('S1 de referencia: sus 25 nodos', s1.nodes.length === 25 && Object.keys(nodosS1).length === 25);
  check('S1 de referencia: el selector y Preparar Commit son los de las huellas de S1', typeof selS1 === 'string' && typeof commitS1 === 'string'
    && sha256(selS1) === huellasS1.selector.jsCodeSha256 && sha256(commitS1) === huellasS1.preparaCommitJsCodeSha256 && selS1.includes('FLOW_REV = "S1";'));
  let VOC = null;
  prueba('vocabulario del servidor leído del PHP (códigos, dimensiones y topes)', () => {
    const lee = lectorPhp({
      UltronRegenerationNotes: path.join(ULTRON_PHP, 'UltronRegenerationNotes.php'),
      CommercialTurnPolicy: path.join(ULTRON_PHP, 'CommercialTurnPolicy.php'),
      CriticContract: path.join(ULTRON_PHP, 'CriticContract.php'),
      UltronDraftDefects: path.join(ULTRON_PHP, 'UltronDraftDefects.php'),
    });
    VOC = { codes: lee('UltronRegenerationNotes', 'CODES'), dimensions: lee('CriticContract', 'DIMENSIONS'), maxCodes: lee('UltronDraftDefects', 'MAX_DEFECTS'), maxIssues: lee('CriticContract', 'MAX_ISSUES'), maxNotes: lee('CriticContract', 'MAX_NOTES'), maxAttempts: lee('CriticContract', 'MAX_ATTEMPTS') };
    return { ok: VOC.codes.length >= 4 && VOC.codes.every(c => /^[a-z_]+$/.test(c)) && VOC.dimensions.length > 10 && VOC.maxAttempts === 3, detalle: insp(VOC) };
  });
  if (!VOC) VOC = { codes: [], dimensions: [], maxCodes: 8, maxIssues: 8, maxNotes: 500, maxAttempts: 3 };

  // ── B. Nodos ────────────────────────────────────────────────────────────

  console.log('\nB. Nodos');
  const nombres = s2.nodes.map(n => n.name);
  const esperados = [...s1.nodes.map(n => n.name), ...NUEVOS].sort();
  check('son los 25 de S1 + los 14 nuevos', JSON.stringify([...nombres].sort()) === JSON.stringify(esperados),
    `sobran: ${nombres.filter(n => !esperados.includes(n)).join(', ') || '—'}; faltan: ${esperados.filter(n => !nombres.includes(n)).join(', ') || '—'}`);
  check('sin nombres repetidos', new Set(nombres).size === nombres.length);
  check('39 nodos, 12 con credencial (8 de S1, 2 comprobaciones y 2 de OpenAI)', s2.nodes.length === 39 && s2.nodes.filter(n => n.credentials).length === 12,
    `${s2.nodes.length} nodos, ${s2.nodes.filter(n => n.credentials).length} con credencial`);
  check('ningún nodo desactivado', s2.nodes.every(n => !n.disabled));

  // ── C. Los 25 de S1 ─────────────────────────────────────────────────────

  console.log('\nC. Los 25 nodos de S1, sin cambios (salvo el jsCode de Preparar Commit y el FLOW_REV del selector)');
  const PERMITIDO = '«cambio permitido»';
  const comoS1 = (nodo, deS2) => {
    const n = clon(nodo);
    delete n.position;
    if (n.name === COMMIT && n.parameters) n.parameters.jsCode = PERMITIDO;
    if (deS2 && n.name === SELECTOR && n.parameters && typeof n.parameters.jsCode === 'string') {
      const c = n.parameters.jsCode;
      if (c.split('FLOW_REV = "S2";').length === 2) n.parameters.jsCode = c.replace('FLOW_REV = "S2";', 'FLOW_REV = "S1";');
    }
    return n;
  };
  for (const original of s1.nodes) {
    const actual = nodosS2[original.name];
    if (!actual) { check(`«${original.name}» igual a S1`, false, 'no está'); continue; }
    const d = diferencias(comoS1(original, false), comoS1(actual, true));
    check(`«${original.name}» igual a S1`, d.length === 0, d.join('\n'));
  }
  check(`«${SELECTOR}»: una sola constante FLOW_REV, y es "S2"`, nodosS2[SELECTOR] && nodosS2[SELECTOR].parameters.jsCode.split('FLOW_REV = ').length === 2 && nodosS2[SELECTOR].parameters.jsCode.includes('FLOW_REV = "S2";'));

  // ── D. Los parámetros ───────────────────────────────────────────────────

  console.log('\nD. Los parámetros de los nuevos y de Preparar Commit');
  const plantilla = nodosS1[PLANTILLA_IF];
  const generado = f => { const p = path.join(generadoDir, f); return fs.existsSync(p) ? fs.readFileSync(p, 'utf8') : null; };
  for (const nombre of [IF_C[1], IF_R[1], IF_C[2], IF_R[2], IF_3]) {
    const n = nodosS2[nombre];
    if (!check(`«${nombre}» es un IF 2.3`, n && n.type === IF && n.typeVersion === 2.3)) continue;
    const p = clon(plantilla.parameters);
    p.conditions.conditions[0].leftValue = EXPRESION_IF[nombre];
    const d = diferencias(p, n.parameters);
    check(`«${nombre}»: la forma del IF de S1 con su expresión (${EXPRESION_IF[nombre].slice(4, 60)}…)`, d.length === 0, d.join('\n'));
    check(`«${nombre}»: sin credenciales, sin reintentos y sin manejo de error propio`, !n.credentials && !n.onError && !n.retryOnFail && !n.maxTries);
  }
  const decide = nodosS1[DECIDE];
  for (const k of [1, 2]) {
    const nombre = CHK[k];
    const n = nodosS2[nombre];
    if (!check(`«${nombre}» es un HTTP Request 4.4`, n && n.type === HTTP && n.typeVersion === 4.4)) continue;
    const p = n.parameters || {};
    check(`${nombre}: POST a ${CHECK_URL}`, p.method === 'POST' && p.url === CHECK_URL && decide.parameters.url === DECIDE_URL, p.url);
    check(`${nombre}: la credencial de «${DECIDE}», por referencia (sin secreto)`, iguales(n.credentials, decide.credentials) && p.authentication === decide.parameters.authentication && p.genericAuthType === decide.parameters.genericAuthType
      && Object.values(n.credentials || {}).every(r => iguales(Object.keys(r), ['id', 'name'])));
    check(`${nombre}: sin cabeceras, consulta ni parámetros propios`, iguales(Object.keys(p).sort(), Object.keys(decide.parameters).sort()) && p.sendBody === true && p.specifyBody === 'json');
    check(`${nombre}: sin reintentos`, n.retryOnFail === undefined && n.maxTries === undefined && n.waitBetweenTries === undefined, insp({ retryOnFail: n.retryOnFail, maxTries: n.maxTries }));
    check(`${nombre}: tiempo máximo de 3 s, respuesta entera y sin convertir los 4xx/5xx en error`, iguales(p.options, { response: { response: { fullResponse: true, neverError: true } }, timeout: 3000 }), insp(p.options));
    check(`${nombre}: un fallo de red no corta el flujo (continueRegularOutput)`, n.onError === 'continueRegularOutput');
    check(`${nombre}: el cuerpo es el de §4.1`, p.jsonBody === cuerpoEsperado(k), insp(p.jsonBody));
  }
  for (const nombre of [LEER[1], LEER[2], NOTA[1], NOTA[2], DECIDIR]) {
    const n = nodosS2[nombre];
    if (!check(`«${nombre}» es un Code v2 en JavaScript, sin otros parámetros`, n && n.type === CODE && n.typeVersion === 2 && typeof n.parameters.jsCode === 'string'
      && Object.keys(n.parameters).every(k => ['jsCode', 'mode', 'language'].includes(k)) && (n.parameters.mode === undefined || n.parameters.mode === 'runOnceForAllItems'))) continue;
    check(`«${nombre}» sin credenciales, activo y sin manejo de error propio`, !n.credentials && !n.disabled && !n.onError);
    const f = generado(FICHEROS_CODE[nombre]);
    check(`«${nombre}» = ${FICHEROS_CODE[nombre]} generado`, f !== null && n.parameters.jsCode + '\n' === f);
  }
  for (const [copia, origen, cambia] of [[RETRY2, RETRY, true], [CRITIC3, CRITIC2, false]]) {
    const a = clon(nodosS2[copia]);
    const b = clon(nodosS2[origen]);
    if (!check(`«${copia}» existe y es un nodo de OpenAI 2.3`, a && a.type === OPENAI && a.typeVersion === 2.3)) continue;
    for (const k of ['name', 'id', 'position']) { delete a[k]; delete b[k]; }
    if (cambia) {
      const u = a.parameters.responses.values[1].content;
      check(`«${copia}»: rejected_draft lee de «${RETRY}» (una vez)`, u.split(RECHAZADO_2).length === 2 && !u.includes(RECHAZADO_1));
      a.parameters.responses.values[1].content = u.replace(RECHAZADO_2, RECHAZADO_1);
    }
    const d = diferencias(b, a);
    check(`«${copia}» = «${origen}» salvo nombre, id, posición${cambia ? ' y rejected_draft' : ''} (modelo, opciones, reintentos, credencial y rol)`, d.length === 0, d.join('\n'));
    check(`«${copia}»: id propio`, nodosS2[copia].id !== nodosS2[origen].id);
  }
  const commitS2 = nodosS2[COMMIT] && nodosS2[COMMIT].parameters.jsCode;
  check(`${COMMIT}: el de S1 + el tercer borrador + el bloque regeneration, y nada más`, typeof commitS2 === 'string' && typeof commitS1 === 'string'
    && commitS2.replace(TERCERO_RE, '').replace(BLOQUE_RE, '').replace(FIN_S2, FIN_S1) === commitS1);
  check(`${COMMIT} = preparar-commit.js generado`, generado('preparar-commit.js') === commitS2 + '\n');
  const vocabDe = codigo => {
    const lista = nombre => { const m = String(codigo).match(new RegExp(`const ${nombre} = (\\[[^\\]]*\\]);`)); return m ? JSON.parse(m[1]) : null; };
    return { codes: lista('CODES'), dimensions: lista('DIMENSIONS') };
  };
  for (const nombre of [LEER[1], LEER[2], COMMIT]) {
    const v = vocabDe(nodosS2[nombre] && nodosS2[nombre].parameters.jsCode);
    check(`«${nombre}»: el vocabulario es el del servidor (${VOC.codes.length} códigos, ${VOC.dimensions.length} dimensiones)`, iguales(v.codes, VOC.codes) && iguales(v.dimensions, VOC.dimensions));
  }
  const sinSecretos = JSON.stringify(s2.nodes.filter(n => NUEVOS.includes(n.name) || n.name === COMMIT));
  check('ningún secreto ni variable de entorno en los nodos nuevos', !/Bearer |Authorization|X-Internal|\$env|process\.env|require\(|password|apiKey/.test(sinSecretos));

  // ── E. Conexiones ───────────────────────────────────────────────────────

  console.log('\nE. Conexiones');
  const a = (...n) => n.map(x => ({ node: x, type: 'main', index: 0 }));
  const conEsperadas = clon(s1.connections);
  conEsperadas[COMPOSER] = { main: [a(IF_C[1])] };
  conEsperadas[RETRY] = { main: [a(IF_C[2])] };
  conEsperadas[CRITIC2] = { main: [a(DECIDIR)] };
  Object.assign(conEsperadas, {
    [IF_C[1]]: { main: [a(CHK[1]), a(CRITIC)] }, [CHK[1]]: { main: [a(LEER[1])] }, [LEER[1]]: { main: [a(IF_R[1])] },
    [IF_R[1]]: { main: [a(NOTA[1]), a(CRITIC)] }, [NOTA[1]]: { main: [a(RETRY)] },
    [IF_C[2]]: { main: [a(CHK[2]), a(CRITIC2)] }, [CHK[2]]: { main: [a(LEER[2])] }, [LEER[2]]: { main: [a(IF_R[2])] },
    [IF_R[2]]: { main: [a(NOTA[2]), a(CRITIC2)] }, [NOTA[2]]: { main: [a(RETRY2)] },
    [DECIDIR]: { main: [a(IF_3)] }, [IF_3]: { main: [a(RETRY2), a(COMMIT)] }, [RETRY2]: { main: [a(CRITIC3)] }, [CRITIC3]: { main: [a(COMMIT)] },
  });
  const dc = diferencias(conEsperadas, s2.connections);
  check('las de S1 con los cambios de §5.1 (Composer, Composer Reintento y Critic Comercial 2) y las de los 14', dc.length === 0, dc.join('\n'));

  // ── F. El selector ──────────────────────────────────────────────────────

  console.log('\nF. El selector, ejecutado en vm');
  const selS2 = nodosS2[SELECTOR] && nodosS2[SELECTOR].parameters.jsCode;
  const cacheSelector = new Map();
  const selector = (codigo, ctx) => {
    const clave = codigo.length + ':' + sha256(codigo) + ':' + JSON.stringify(ctx).length + ':' + sha256(JSON.stringify(ctx));
    if (!cacheSelector.has(clave)) cacheSelector.set(clave, ejecutarCodigo(codigo, clon(ctx), () => { throw new Error('el selector no lee otros nodos'); }));
    return clon(cacheSelector.get(clave));
  };
  const decideStudio = fx('decide-studio.json');
  const decideLegacy = fx('decide-legacy.json');
  check('fixtures de decide presentes (studio con regeneration, legacy sin él)', decideStudio !== null && decideLegacy !== null
    && iguales(decideStudio.regeneration, { contract: CONTRATO, max_drafts: 3 }) && !('regeneration' in decideLegacy));
  if (typeof selS2 === 'string' && decideStudio && decideLegacy) {
    prueba('sin oferta de prompts: legacy, la base byte a byte, la huella Legacy y flowRev S2', () => {
      const up = selector(selS2, { ok: true, decide_token: 'x.y', superseded: false }).ultron_prompts;
      return up.mode === 'legacy' && up.reason === 'absent' && up.flow_rev === 'S2' && up.bundle_hash === LEGACY_HASH && ROLES.every(r => up.roles[r] === base[r]);
    });
    prueba('decide-studio.json: studio con la huella del fixture y flowRev S2', () => {
      const up = selector(selS2, decideStudio).ultron_prompts;
      return up.mode === 'studio' && up.bundle_hash === decideStudio.studio_prompts.bundle_hash && up.flow_rev === 'S2' && iguales(up.roles, decideStudio.studio_prompts.roles);
    });
    prueba('decide-legacy.json: legacy ofrecido (offered_prompts_off) y flowRev S2', () => {
      const up = selector(selS2, decideLegacy).ultron_prompts;
      return up.mode === 'legacy' && up.reason === 'offered_prompts_off' && up.flow_rev === 'S2';
    });
    prueba('el selector de S2 da lo mismo que el de S1 salvo flow_rev', () => {
      const x = selector(selS2, decideStudio);
      const y = selector(selS1, decideStudio);
      x.ultron_prompts.flow_rev = 'S1';
      return iguales(x, y);
    });
  }

  // ── G. Preparar Commit junto al de S1 ───────────────────────────────────

  console.log('\nG. Preparar Commit, ejecutado en vm junto al de S1 con los mismos datos');
  const sinOferta = decideStudio ? Object.assign(clon(decideStudio), { regeneration: undefined }) : null;
  const otroContrato = decideStudio ? Object.assign(clon(decideStudio), { regeneration: { contract: 'ultron-regen/2', max_drafts: 3 } }) : null;
  const upFijo = { mode: 'studio', reason: null, bundle_hash: 'a'.repeat(64), flow_rev: 'S2', roles: {} };
  const preparar = (codigo, ctx, extra = {}, entrada = PASA, modo = 'lanza') => {
    const nodos = Object.assign({
      [EVENTO]: EVENTO_FIJO, [DECIDE]: ctx, [ESTRATEGIA]: { strategy: ESTRATEGIA_FIJA, context: ctx }, [STRATEGIST]: borrador('x'),
      [SELECTOR]: Object.assign({}, ctx, { ultron_prompts: upFijo }), [COMPOSER]: borrador(B1), [CRITIC]: PASA,
    }, extra);
    const $ = nombre => ({ first: () => {
      if (nodos[nombre] !== undefined && nodos[nombre] !== null) return { json: nodos[nombre] };
      if (modo === 'lanza') throw new Error(`El nodo «${nombre}» no se ejecutó`);
      return undefined;
    } });
    return ejecutarCodigo(codigo, clon(entrada), $);
  };
  const bloqueValido = b => {
    const errores = [];
    if (!b || typeof b !== 'object') return ['no hay bloque'];
    if (!iguales(Object.keys(b), CLAVES_BLOQUE)) errores.push(`claves ${Object.keys(b)}`);
    if (b.contract !== CONTRATO) errores.push('contract');
    const ent = (v, min, max) => Number.isInteger(v) && v >= min && v <= max;
    if (!ent(b.drafts, 1, 3)) errores.push('drafts');
    if (!ent(b.critics, 0, VOC.maxAttempts)) errores.push('critics');
    if (!ent(b.checks, 0, 2)) errores.push('checks');
    if (!ent(b.check_errors, 0, b.checks)) errores.push('check_errors');
    if (!Array.isArray(b.rewrites) || b.rewrites.length > 2 || b.rewrites.length !== b.drafts - 1) errores.push('rewrites');
    for (const r of Array.isArray(b.rewrites) ? b.rewrites : []) {
      if (!iguales(Object.keys(r), ['by', 'codes', 'issues']) || !['server', 'critic'].includes(r.by)) errores.push(`reescritura ${insp(r)}`);
      const lista = (l, voc, max) => Array.isArray(l) && l.length <= max && new Set(l).size === l.length && l.every(x => voc.includes(x));
      if (!lista(r.codes, VOC.codes, VOC.maxCodes) || !lista(r.issues, VOC.dimensions, VOC.maxIssues)) errores.push(`vocabulario ${insp(r)}`);
      if (r.by === 'critic' && r.codes.length !== 0) errores.push('el revisor no tiene códigos del servidor');
    }
    if (b.tokens !== null && !(b.tokens && iguales(Object.keys(b.tokens), ['input', 'output']) && ent(b.tokens.input, 0, MAX_TOKENS) && ent(b.tokens.output, 0, MAX_TOKENS))) errores.push('tokens');
    return errores;
  };
  if (typeof commitS2 === 'string' && typeof commitS1 === 'string' && decideStudio && decideLegacy) {
    const ctxs = [['decide-legacy.json', decideLegacy], ['Studio de un servidor anterior (sin regeneration)', sinOferta], ['regeneration con otro contrato', otroContrato]];
    for (const [etiqueta, ctx] of ctxs) {
      for (const [conReintento, entrada] of [[false, PASA], [true, revisor('pass', [], 'ok', null)], [true, FALLA2]]) {
        for (const modo of ['lanza', 'undefined']) {
          const extra = conReintento ? { [CRITIC]: FALLA, [RETRY]: borrador(B2), [CRITIC2]: entrada } : {};
          prueba(`sin oferta (${etiqueta}${conReintento ? ', con reintento' : ''}${entrada === FALLA2 ? ' que vuelve a fallar' : ''}${modo === 'undefined' ? ', lo que no corrió es undefined' : ''}): la MISMA salida que S1 y sin bloque`, () => {
            const x = preparar(commitS1, ctx, extra, entrada, modo);
            const y = preparar(commitS2, ctx, extra, entrada, modo);
            return { ok: iguales(x, y) && !('regeneration' in y), detalle: diferencias(x, y).join('\n') };
          });
        }
      }
    }
    prueba('con oferta, primer borrador aprobado: la misma salida que S1 + un bloque válido (1 borrador, 1 revisor, 1 comprobación)', () => {
      const extra = { [LEER[1]]: Object.assign(borrador(B1), { ultron_check: { rewrite: false, may_rewrite: true, error: false, codes: [], issues: [], notes: '' } }) };
      const x = preparar(commitS1, decideStudio, extra);
      const y = preparar(commitS2, decideStudio, extra);
      const b = y.regeneration;
      const resto = Object.assign({}, y); delete resto.regeneration;
      return { ok: iguales(resto, x) && bloqueValido(b).length === 0 && iguales(b, { contract: CONTRATO, drafts: 1, critics: 1, checks: 1, check_errors: 0, rewrites: [], tokens: { input: 9000 + 9000 + 8000, output: 120 + 120 + 60 } }), detalle: insp({ b, errores: bloqueValido(b), dif: diferencias(resto, x) }) };
    });
    prueba('una lectura que lanza dentro del bloque: sin bloque y la misma salida', () => {
      const raro = borrador(B1);
      Object.defineProperty(raro, 'ultron_check', { enumerable: true, get() { throw new Error('boom'); } });
      const x = preparar(commitS1, decideStudio, {});
      const y = preparar(commitS2, decideStudio, { [LEER[1]]: raro });
      return { ok: iguales(x, y) && !('regeneration' in y), detalle: diferencias(x, y).join('\n') };
    });
    prueba('el tercer borrador: intento 3 con su texto', () => {
      const y = preparar(commitS2, decideStudio, { [CRITIC]: FALLA, [RETRY]: borrador(B2), [CRITIC2]: FALLA2, [RETRY2]: borrador(B3), [CRITIC3]: PASA });
      return { ok: y.critic.attempt === 3 && y.proposal.reply_draft === B3, detalle: insp({ attempt: y.critic.attempt, draft: y.proposal.reply_draft }) };
    });
    prueba('el tercer borrador sin texto: se queda el segundo (no vuelve al primero)', () => {
      const y = preparar(commitS2, decideStudio, { [CRITIC]: FALLA, [RETRY]: borrador(B2), [CRITIC2]: FALLA2, [RETRY2]: borrador(''), [CRITIC3]: PASA });
      return { ok: y.critic.attempt === 2 && y.proposal.reply_draft === B2 && y.regeneration.drafts === 3, detalle: insp({ attempt: y.critic.attempt, draft: y.proposal.reply_draft, r: y.regeneration }) };
    });
    prueba('los tokens: sin el usage de un nodo que corrió, null; fuera de rango, null', () => {
      const sinUno = preparar(commitS2, decideStudio, { [CRITIC]: revisor('pass', [], '', null, null) }, revisor('pass', [], '', null, null));
      const enorme = preparar(commitS2, decideStudio, { [COMPOSER]: borrador(B1, uso(MAX_TOKENS, 1)) });
      const negativo = preparar(commitS2, decideStudio, { [COMPOSER]: borrador(B1, uso(-1, 1)) });
      return { ok: sinUno.regeneration.tokens === null && enorme.regeneration.tokens === null && negativo.regeneration.tokens === null, detalle: insp([sinUno.regeneration, enorme.regeneration, negativo.regeneration]) };
    });
  }

  // ── H. Los Code nuevos con los fixtures ─────────────────────────────────

  console.log('\nH. Los Code nuevos, ejecutados en vm con los fixtures de ai/check');
  const fixtures = {};
  for (const f of ['check-pasa.json', 'check-reescribe.json', 'check-legacy.json', 'check-404.json', 'check-422.json', 'check-error-de-red.json', 'check-mal-formada.json']) fixtures[f] = fx(f);
  check('los siete fixtures de ai/check presentes', Object.values(fixtures).every(x => x !== null));
  const conIntento = (f, k) => { const c = clon(f); if (c && c.body && typeof c.body === 'object' && 'attempt' in c.body) c.body.attempt = k; return c; };
  const sinPresupuesto = k => { const c = conIntento(fixtures['check-reescribe.json'], k); c.body.may_rewrite = false; c.body.rewrite = false; c.body.budget = { max_drafts: 3, elapsed_s: 26, reason: 'time_budget' }; return c; };
  const NO = { rewrite: false, may_rewrite: false, error: true, codes: [], issues: [], notes: '' };
  const codigoDe = nombre => nodosS2[nombre] && nodosS2[nombre].parameters && nodosS2[nombre].parameters.jsCode;
  const leer = (k, salidaHttp, entradaRara) => {
    const redactor = k === 1 ? borrador(B1) : borrador(B2);
    const $ = nombre => ({ first: () => {
      if (nombre === (k === 1 ? COMPOSER : RETRY)) return { json: clon(redactor) };
      throw new Error(`El nodo «${nombre}» no se ejecutó`);
    } });
    return { out: ejecutarCodigo(codigoDe(LEER[k]), clon(salidaHttp), $, entradaRara), redactor };
  };
  if (Object.values(fixtures).every(x => x !== null) && [LEER[1], LEER[2], NOTA[1], DECIDIR].every(n => typeof codigoDe(n) === 'string')) {
    const reescribe = fixtures['check-reescribe.json'].body;
    for (const k of [1, 2]) {
      const casos = [
        ['check-pasa.json', conIntento(fixtures['check-pasa.json'], k), { rewrite: false, may_rewrite: true, error: false, codes: [], issues: [], notes: '' }, 'pasa, sin reescribir'],
        ['check-reescribe.json', conIntento(fixtures['check-reescribe.json'], k), { rewrite: true, may_rewrite: true, error: false, codes: reescribe.defects.map(d => d.code), issues: reescribe.issues, notes: reescribe.notes }, 'reescribir con los códigos, los issues y la nota del servidor'],
        ['check-legacy.json', conIntento(fixtures['check-legacy.json'], k), { rewrite: false, may_rewrite: false, error: false, codes: [], issues: [], notes: '' }, 'legacy: ni reescribir ni error'],
        ['check-404.json', fixtures['check-404.json'], NO, 'no reescribir (error)'],
        ['check-422.json', fixtures['check-422.json'], NO, 'no reescribir (error)'],
        ['check-error-de-red.json', fixtures['check-error-de-red.json'], NO, 'no reescribir (error)'],
        ['check-mal-formada.json', conIntento(fixtures['check-mal-formada.json'], k), NO, 'no reescribir (error)'],
        ['sin presupuesto (time_budget)', sinPresupuesto(k), { rewrite: false, may_rewrite: false, error: false, codes: reescribe.defects.map(d => d.code), issues: reescribe.issues, notes: reescribe.notes }, 'los defectos, sin reescribir ni error'],
        ['el intento que no es el suyo', conIntento(fixtures['check-reescribe.json'], 3 - k), NO, 'no reescribir (error)'],
        ['el cuerpo como texto JSON', Object.assign(conIntento(fixtures['check-reescribe.json'], k), { body: JSON.stringify(conIntento(fixtures['check-reescribe.json'], k).body) }), { rewrite: true, may_rewrite: true, error: false, codes: reescribe.defects.map(d => d.code), issues: reescribe.issues, notes: reescribe.notes }, 'lo lee igual'],
        ['un 200 en HTML', { statusCode: 200, headers: {}, body: '<html>502</html>' }, NO, 'no reescribir (error)'],
        ['el código como texto "200"', Object.assign(conIntento(fixtures['check-pasa.json'], k), { statusCode: '200' }), NO, 'no reescribir (error)'],
        ['un 500', { statusCode: 500, headers: {}, body: { message: 'Server Error' } }, NO, 'no reescribir (error)'],
        ['rewrite sin defectos', Object.assign(conIntento(fixtures['check-reescribe.json'], k), { body: Object.assign(conIntento(fixtures['check-reescribe.json'], k).body, { defects: [] }) }), NO, 'no reescribir (error)'],
        ['rewrite sin presupuesto', Object.assign(conIntento(fixtures['check-reescribe.json'], k), { body: Object.assign(conIntento(fixtures['check-reescribe.json'], k).body, { may_rewrite: false }) }), NO, 'no reescribir (error)'],
        ['rewrite sin nota', Object.assign(conIntento(fixtures['check-reescribe.json'], k), { body: Object.assign(conIntento(fixtures['check-reescribe.json'], k).body, { notes: '  ' }) }), NO, 'no reescribir (error)'],
        ['rewrite en legacy', Object.assign(conIntento(fixtures['check-reescribe.json'], k), { body: Object.assign(conIntento(fixtures['check-reescribe.json'], k).body, { mode: 'legacy' }) }), NO, 'no reescribir (error)'],
        ['otro contrato', Object.assign(conIntento(fixtures['check-reescribe.json'], k), { body: Object.assign(conIntento(fixtures['check-reescribe.json'], k).body, { contract: 'ultron-regen/2' }) }), NO, 'no reescribir (error)'],
        ['códigos e issues fuera del vocabulario, repetidos, y una nota enorme', Object.assign(conIntento(fixtures['check-reescribe.json'], k), { body: Object.assign(conIntento(fixtures['check-reescribe.json'], k).body, {
          defects: [...reescribe.defects, { code: 'inventado', detail: null, issue: 'x' }, reescribe.defects[0]], issues: [...reescribe.issues, 'inventado', reescribe.issues[0]], notes: 'N'.repeat(900) }) }),
        { rewrite: true, may_rewrite: true, error: false, codes: reescribe.defects.map(d => d.code), issues: reescribe.issues, notes: 'N'.repeat(VOC.maxNotes) }, 'solo el vocabulario, sin repetir, y la nota a 500'],
      ];
      for (const [etiqueta, http, esperado, descripcion] of casos) {
        prueba(`${LEER[k]} con ${etiqueta}: ${descripcion}`, () => {
          const { out, redactor } = leer(k, http);
          const resto = Object.assign({}, out); delete resto.ultron_check;
          return { ok: iguales(out.ultron_check, esperado) && iguales(Object.keys(out.ultron_check), CLAVES_CHECK) && iguales(resto, redactor), detalle: insp(out.ultron_check) };
        });
      }
      prueba(`${LEER[k]}: si $input no tiene ítem, no reescribir (error) y no lanza`, () => iguales(leer(k, null, { first: () => undefined, all: () => [] }).out.ultron_check, NO));
    }
    // Nota del Servidor: la forma del revisor, y el redactor de la reescritura la lee donde lee la del revisor.
    const leido = leer(1, conIntento(fixtures['check-reescribe.json'], 1)).out;
    for (const k of [1, 2]) {
      prueba(`${NOTA[k]}: la forma del revisor con la nota y los issues del servidor (by: server)`, () => {
        const out = ejecutarCodigo(codigoDe(NOTA[k]), clon(leido), () => { throw new Error('no lee otros nodos'); });
        return iguales(out, { output: [{ content: [{ text: { verdict: 'fail', score: null, issues: leido.ultron_check.issues, notes: leido.ultron_check.notes, hard_fail: null, by: 'server', codes: leido.ultron_check.codes } }] }] });
      });
      prueba(`${NOTA[k]} con una entrada sin ultron_check: la forma, vacía, y no lanza`, () => {
        const out = ejecutarCodigo(codigoDe(NOTA[k]), { raro: 1 }, () => { throw new Error('no'); });
        return iguales(out.output[0].content[0].text, { verdict: 'fail', score: null, issues: [], notes: '', hard_fail: null, by: 'server', codes: [] });
      });
    }
    // Decidir Intento 3.
    const decidir = (critic2, leer2, modo = 'lanza') => ejecutarCodigo(codigoDe(DECIDIR), clon(critic2), nombre => ({ first: () => {
      if (nombre === LEER[2] && leer2 !== undefined) return { json: Object.assign(borrador(B2), { ultron_check: leer2 }) };
      if (modo === 'lanza') throw new Error(`El nodo «${nombre}» no se ejecutó`);
      return undefined;
    } }));
    const puede = { rewrite: false, may_rewrite: true, error: false, codes: [], issues: [], notes: '' };
    const casos3 = [
      ['el revisor 2 rechaza y queda presupuesto', FALLA2, puede, true],
      ['el revisor 2 aprueba', PASA, puede, false],
      ['el revisor 2 aprueba con un fallo duro', revisor('pass', [], '', 'invented_price'), puede, true],
      ['el revisor 2 rechaza sin la comprobación 2 (Legacy)', FALLA2, undefined, false],
      ['el revisor 2 rechaza y la comprobación 2 dio error', FALLA2, NO, false],
      ['el revisor 2 rechaza sin presupuesto', FALLA2, Object.assign({}, puede, { may_rewrite: false }), false],
      ['un veredicto ilegible', { output: [{ content: [{ text: 'no es json' }] }] }, puede, false],
      ['un veredicto sin verdict', revisor(undefined), puede, false],
    ];
    for (const [etiqueta, critic2, leer2, esperado] of casos3) {
      for (const modo of ['lanza', 'undefined']) {
        prueba(`${DECIDIR}, ${etiqueta}${modo === 'undefined' ? ' (lo que no corrió es undefined)' : ''}: ${esperado ? 'tercer borrador' : 'no'} y la entrada tal cual`, () => {
          const out = decidir(critic2, leer2, modo);
          const resto = Object.assign({}, out); delete resto.ultron_intento3;
          return { ok: out.ultron_intento3 === esperado && iguales(resto, critic2), detalle: insp(out) };
        });
      }
    }
    if (typeof commitS1 === 'string' && decideLegacy) {
      prueba(`${COMMIT} con la salida de ${DECIDIR} da lo mismo que con la del revisor 2 (S1)`, () => {
        const extra = { [CRITIC]: FALLA, [RETRY]: borrador(B2), [CRITIC2]: FALLA2 };
        return iguales(preparar(commitS1, decideLegacy, extra, FALLA2), preparar(commitS2, decideLegacy, extra, decidir(FALLA2, undefined)));
      });
    }
  }

  // ── I. El grafo y los recorridos ────────────────────────────────────────

  console.log('\nI. El grafo y los recorridos');
  const aristas = {};
  for (const [origen, c] of Object.entries(s2.connections || {})) aristas[origen] = (c.main || []).map(destinos => (destinos || []).map(d => d.node));
  // El único ciclo permitido es el de la rendición de S1 (Preparar Rendicion → Ejecutar en el CRM): se quita esa arista y lo demás tiene que ser acíclico.
  const ciclo = (() => {
    const estado = {};
    const visita = (n, pila) => {
      if (estado[n] === 1) return [...pila, n];
      if (estado[n] === 2) return null;
      estado[n] = 1;
      if (n !== RENDICION) for (const salida of aristas[n] || []) for (const d of salida) { const c = visita(d, [...pila, n]); if (c) return c; }
      estado[n] = 2;
      return null;
    };
    for (const n of Object.keys(aristas)) { const c = visita(n, []); if (c) return c; }
    return null;
  })();
  check('sin bucles fuera del ciclo de la rendición', ciclo === null, ciclo && ciclo.join(' → '));
  check('el ciclo de la rendición es el de S1', iguales(s2.connections[RENDICION], s1.connections[RENDICION]));
  const caminos = [];
  if (ciclo === null) {
    const anda = (n, camino) => {
      if (caminos.length > 2000) return;
      if (n === COMMIT) { caminos.push(camino); return; }
      (aristas[n] || []).forEach((destinos, salida) => destinos.forEach(d => anda(d, [...camino, { nodo: n, salida }])));
    };
    anda(COMPOSER, []);
  }
  const nodosDe = c => c.map(p => p.nodo);
  check(`hay caminos de Composer a ${COMMIT} (${caminos.length})`, caminos.length > 0);
  const malos = (pred) => caminos.filter(c => !pred(c)).map(c => nodosDe(c).join(' → '));
  let m = malos(c => nodosDe(c).filter(n => REDACTORES.includes(n) || /composer/i.test(n)).length <= 3 && nodosDe(c).filter(n => REVISORES.includes(n) || /critic/i.test(n) && nodosS2[n] && nodosS2[n].type === OPENAI).length <= 3
    && nodosDe(c).filter(n => nodosS2[n] && nodosS2[n].type === OPENAI).length <= 6);
  check('ningún camino de Composer a Preparar Commit pasa por más de 3 redactores ni de 3 revisores', ciclo === null && caminos.length > 0 && m.length === 0, m.slice(0, 3).join('\n'));
  m = malos(c => nodosDe(c).filter(n => nodosS2[n] && nodosS2[n].type === HTTP).length <= 2);
  check('ningún camino llama a ai/check más de 2 veces', ciclo === null && caminos.length > 0 && m.length === 0, m.slice(0, 3).join('\n'));
  m = malos(c => { const ns = nodosDe(c); const ultimo = Math.max(...ns.map((n, i) => (nodosS2[n] && nodosS2[n].type === OPENAI && !REVISORES.includes(n) ? i : -1))); return ns.slice(ultimo + 1).some(n => REVISORES.includes(n)); });
  check('todo borrador lo juzga un revisor antes de Preparar Commit', ciclo === null && caminos.length > 0 && m.length === 0, m.slice(0, 3).join('\n'));
  m = malos(c => { const ns = nodosDe(c); const i = ns.indexOf(RETRY2); return i < 0 || !ns.slice(i).some(n => nodosS2[n] && nodosS2[n].type === HTTP); });
  check('el tercer borrador no se comprueba', ciclo === null && caminos.length > 0 && m.length === 0, m.slice(0, 3).join('\n'));
  for (const k of [1, 2]) {
    m = caminos.filter(c => { const i = c.findIndex(p => p.nodo === IF_C[k] && p.salida === 0); return i >= 0 && !nodosDe(c).slice(i + 1).includes(CHK[k]); }).map(c => nodosDe(c).join(' → '));
    const usa = caminos.some(c => c.some(p => p.nodo === IF_C[k] && p.salida === 0));
    check(`con la oferta, la salida «sí» de ${IF_C[k]} pasa siempre por ${CHK[k]}`, usa && m.length === 0, usa ? m.slice(0, 3).join('\n') : 'ningún camino usa la salida «sí»');
    check(`sin la oferta, la salida «no» de ${IF_C[k]} va directa al revisor de S1`, iguales(aristas[IF_C[k]] && aristas[IF_C[k]][1], [k === 1 ? CRITIC : CRITIC2]));
  }
  const alcanzables = new Set(caminos.flatMap(nodosDe));
  check('los 14 nuevos están en algún camino de Composer a Preparar Commit', NUEVOS.every(n => alcanzables.has(n)), NUEVOS.filter(n => !alcanzables.has(n)).join(', '));

  // Los recorridos: el flujo, ejecutado nodo a nodo.
  const previos = (flujo, ctx) => {
    const n = Object.fromEntries(flujo.nodes.map(x => [x.name, x]));
    return {
      [EVENTO]: clon(EVENTO_FIJO), [DECIDE]: clon(ctx), [SELECTOR]: selector(n[SELECTOR].parameters.jsCode, ctx),
      [STRATEGIST]: borrador('estrategia', uso(7000, 300)), [ESTRATEGIA]: { strategy: clon(ESTRATEGIA_FIJA), corrections: [], context: clon(ctx), human_handoff_allowed: false },
    };
  };
  const recorrer = (flujo, ctx, pins, modo) => {
    const porNombre = Object.fromEntries(flujo.nodes.map(x => [x.name, x]));
    const corridos = previos(flujo, ctx);
    const orden = [];
    const enviados = {};
    const cuerpos = {};
    let commit = null;
    const $ = nombre => ({ first: () => {
      if (Object.prototype.hasOwnProperty.call(corridos, nombre)) return { json: corridos[nombre] };
      if (modo === 'lanza') throw new Error(`El nodo «${nombre}» no se ejecutó`);
      return undefined;
    } });
    const cola = [{ nodo: COMPOSER, item: corridos[ESTRATEGIA] }];
    while (cola.length) {
      const { nodo, item } = cola.shift();
      if (orden.includes(nodo)) throw new Error(`«${nodo}» se ejecutaría dos veces`);
      if (orden.length > 60) throw new Error('el recorrido no termina');
      orden.push(nodo);
      const n = porNombre[nodo];
      if (!n) throw new Error(`una conexión lleva a «${nodo}», que no existe`);
      if (nodo === COMMIT) { commit = ejecutarCodigo(n.parameters.jsCode, clon(item), $); corridos[nodo] = commit; continue; }
      let salidas;
      if (n.type === OPENAI) {
        const v = n.parameters.responses.values;
        enviados[nodo] = { system: evaluar(v[0].content, item, $), user: JSON.parse(evaluar(v[1].content, item, $)) };
        if (!(nodo in pins)) throw new Error(`llamada al modelo no prevista: «${nodo}»`);
        salidas = [clon(pins[nodo])];
      } else if (n.type === HTTP) {
        cuerpos[nodo] = JSON.parse(evaluar(n.parameters.jsonBody, item, $));
        if (!(nodo in pins)) throw new Error(`llamada a ai/check no prevista: «${nodo}»`);
        salidas = [clon(pins[nodo])];
      } else if (n.type === IF) {
        salidas = evaluarIf(n, item, $) ? [item, null] : [null, item];
      } else if (n.type === CODE) {
        salidas = [ejecutarCodigo(n.parameters.jsCode, clon(item), $)];
      } else throw new Error(`tipo no previsto en el recorrido: ${n.type} («${nodo}»)`);
      corridos[nodo] = salidas.find(x => x !== null);
      const con = (flujo.connections[nodo] && flujo.connections[nodo].main) || [];
      salidas.forEach((json, i) => { if (json !== null) for (const c of con[i] || []) cola.push({ nodo: c.node, item: json }); });
    }
    if (commit === null) throw new Error(`el recorrido no llega a ${COMMIT}`);
    return { orden, enviados, cuerpos, commit, modelos: orden.filter(x => porNombre[x].type === OPENAI), comprobaciones: orden.filter(x => porNombre[x].type === HTTP) };
  };
  const tokensDe = (pins, modelos) => {
    const usos = [uso(7000, 300), ...modelos.map(x => pins[x].usage)];
    if (usos.some(u => !u)) return null;
    return { input: usos.reduce((s, u) => s + u.input_tokens, 0), output: usos.reduce((s, u) => s + u.output_tokens, 0) };
  };
  const servidor = f => ({ by: 'server', codes: f.body.defects.map(d => d.code), issues: f.body.issues });
  const delRevisor = r => ({ by: 'critic', codes: [], issues: r.output[0].content[0].text.issues });
  const fR = k => conIntento(fixtures['check-reescribe.json'], k);
  const fP = k => conIntento(fixtures['check-pasa.json'], k);
  const fL = k => conIntento(fixtures['check-legacy.json'], k);
  const recorridos = (decideStudio && decideLegacy && Object.values(fixtures).every(x => x !== null)) ? [
    // [nombre, ctx, pins, { modelos, checks, attempt, draft, bloque (sin tokens), comoS1, datos }]
    ['Legacy, pasa', decideLegacy, { [COMPOSER]: borrador(B1), [CRITIC]: PASA }, { modelos: [COMPOSER, CRITIC], attempt: 1, draft: B1, bloque: null, comoS1: true }],
    ['Legacy, el revisor falla', decideLegacy, { [COMPOSER]: borrador(B1), [CRITIC]: FALLA, [RETRY]: borrador(B2), [CRITIC2]: PASA }, { modelos: [COMPOSER, CRITIC, RETRY, CRITIC2], attempt: 2, draft: B2, bloque: null, comoS1: true }],
    ['Legacy, el revisor falla dos veces (sin tercer borrador)', decideLegacy, { [COMPOSER]: borrador(B1), [CRITIC]: FALLA, [RETRY]: borrador(B2), [CRITIC2]: FALLA2 }, { modelos: [COMPOSER, CRITIC, RETRY, CRITIC2], attempt: 2, draft: B2, bloque: null, comoS1: true }],
    ['servidor anterior (Studio sin regeneration), el revisor falla dos veces', sinOferta, { [COMPOSER]: borrador(B1), [CRITIC]: FALLA, [RETRY]: borrador(B2), [CRITIC2]: FALLA2 }, { modelos: [COMPOSER, CRITIC, RETRY, CRITIC2], attempt: 2, draft: B2, bloque: null, comoS1: true }],
    ['regeneration con otro contrato, el revisor falla', otroContrato, { [COMPOSER]: borrador(B1), [CRITIC]: FALLA, [RETRY]: borrador(B2), [CRITIC2]: PASA }, { modelos: [COMPOSER, CRITIC, RETRY, CRITIC2], attempt: 2, draft: B2, bloque: null, comoS1: true }],
    ['Studio, pasa', decideStudio, { [COMPOSER]: borrador(B1), [CHK[1]]: fP(1), [CRITIC]: PASA }, { modelos: [COMPOSER, CRITIC], attempt: 1, draft: B1, bloque: { drafts: 1, critics: 1, checks: 1, check_errors: 0, rewrites: [] } }],
    ['Studio, el servidor pide 1', decideStudio, { [COMPOSER]: borrador(B1), [CHK[1]]: fR(1), [RETRY]: borrador(B2), [CHK[2]]: fP(2), [CRITIC2]: PASA },
      { modelos: [COMPOSER, RETRY, CRITIC2], attempt: 2, draft: B2, bloque: { drafts: 2, critics: 1, checks: 2, check_errors: 0, rewrites: [servidor(fR(1))] }, datos: { [RETRY]: [B1, fR(1)] } }],
    ['Studio, el revisor pide 1', decideStudio, { [COMPOSER]: borrador(B1), [CHK[1]]: fP(1), [CRITIC]: FALLA, [RETRY]: borrador(B2), [CHK[2]]: fP(2), [CRITIC2]: PASA },
      { modelos: [COMPOSER, CRITIC, RETRY, CRITIC2], attempt: 2, draft: B2, bloque: { drafts: 2, critics: 2, checks: 2, check_errors: 0, rewrites: [delRevisor(FALLA)] }, datos: { [RETRY]: [B1, FALLA] } }],
    ['Studio, dos reescrituras del servidor', decideStudio, { [COMPOSER]: borrador(B1), [CHK[1]]: fR(1), [RETRY]: borrador(B2), [CHK[2]]: fR(2), [RETRY2]: borrador(B3), [CRITIC3]: PASA },
      { modelos: [COMPOSER, RETRY, RETRY2, CRITIC3], attempt: 3, draft: B3, bloque: { drafts: 3, critics: 1, checks: 2, check_errors: 0, rewrites: [servidor(fR(1)), servidor(fR(2))] }, datos: { [RETRY]: [B1, fR(1)], [RETRY2]: [B2, fR(2)] } }],
    ['Studio, el revisor y después el servidor', decideStudio, { [COMPOSER]: borrador(B1), [CHK[1]]: fP(1), [CRITIC]: FALLA, [RETRY]: borrador(B2), [CHK[2]]: fR(2), [RETRY2]: borrador(B3), [CRITIC3]: PASA },
      { modelos: [COMPOSER, CRITIC, RETRY, RETRY2, CRITIC3], attempt: 3, draft: B3, bloque: { drafts: 3, critics: 2, checks: 2, check_errors: 0, rewrites: [delRevisor(FALLA), servidor(fR(2))] }, datos: { [RETRY]: [B1, FALLA], [RETRY2]: [B2, fR(2)] } }],
    ['Studio, el servidor y después el revisor', decideStudio, { [COMPOSER]: borrador(B1), [CHK[1]]: fR(1), [RETRY]: borrador(B2), [CHK[2]]: fP(2), [CRITIC2]: FALLA2, [RETRY2]: borrador(B3), [CRITIC3]: PASA },
      { modelos: [COMPOSER, RETRY, CRITIC2, RETRY2, CRITIC3], attempt: 3, draft: B3, bloque: { drafts: 3, critics: 2, checks: 2, check_errors: 0, rewrites: [servidor(fR(1)), delRevisor(FALLA2)] }, datos: { [RETRY]: [B1, fR(1)], [RETRY2]: [B2, FALLA2] } }],
    ['Studio, dos reescrituras del revisor (peor caso)', decideStudio, { [COMPOSER]: borrador(B1), [CHK[1]]: fP(1), [CRITIC]: FALLA, [RETRY]: borrador(B2), [CHK[2]]: fP(2), [CRITIC2]: FALLA2, [RETRY2]: borrador(B3), [CRITIC3]: PASA },
      { modelos: [COMPOSER, CRITIC, RETRY, CRITIC2, RETRY2, CRITIC3], attempt: 3, draft: B3, bloque: { drafts: 3, critics: 3, checks: 2, check_errors: 0, rewrites: [delRevisor(FALLA), delRevisor(FALLA2)] }, datos: { [RETRY]: [B1, FALLA], [RETRY2]: [B2, FALLA2] } }],
    ['Studio, el tercer borrador también lo rechaza el revisor (no hay cuarto)', decideStudio, { [COMPOSER]: borrador(B1), [CHK[1]]: fP(1), [CRITIC]: FALLA, [RETRY]: borrador(B2), [CHK[2]]: fP(2), [CRITIC2]: FALLA2, [RETRY2]: borrador(B3), [CRITIC3]: FALLA },
      { modelos: [COMPOSER, CRITIC, RETRY, CRITIC2, RETRY2, CRITIC3], attempt: 3, draft: B3, verdict: 'fail', bloque: { drafts: 3, critics: 3, checks: 2, check_errors: 0, rewrites: [delRevisor(FALLA), delRevisor(FALLA2)] } }],
    ...['check-404.json', 'check-422.json', 'check-error-de-red.json', 'check-mal-formada.json'].map(f => [`Studio, ${f} en la comprobación 1`, decideStudio, { [COMPOSER]: borrador(B1), [CHK[1]]: fixtures[f], [CRITIC]: PASA },
      { modelos: [COMPOSER, CRITIC], attempt: 1, draft: B1, bloque: { drafts: 1, critics: 1, checks: 1, check_errors: 1, rewrites: [] } }]),
    ['Studio, el servidor caído en las dos comprobaciones (camino de hoy, sin tercer borrador)', decideStudio, { [COMPOSER]: borrador(B1), [CHK[1]]: fixtures['check-404.json'], [CRITIC]: FALLA, [RETRY]: borrador(B2), [CHK[2]]: fixtures['check-error-de-red.json'], [CRITIC2]: FALLA2 },
      { modelos: [COMPOSER, CRITIC, RETRY, CRITIC2], attempt: 2, draft: B2, bloque: { drafts: 2, critics: 2, checks: 2, check_errors: 2, rewrites: [delRevisor(FALLA)] } }],
    ['Studio, la comprobación contesta legacy (sin tercer borrador)', decideStudio, { [COMPOSER]: borrador(B1), [CHK[1]]: fL(1), [CRITIC]: FALLA, [RETRY]: borrador(B2), [CHK[2]]: fL(2), [CRITIC2]: FALLA2 },
      { modelos: [COMPOSER, CRITIC, RETRY, CRITIC2], attempt: 2, draft: B2, bloque: { drafts: 2, critics: 2, checks: 2, check_errors: 0, rewrites: [delRevisor(FALLA)] } }],
    ['Studio, sin presupuesto en la comprobación 2 (sin tercer borrador)', decideStudio, { [COMPOSER]: borrador(B1), [CHK[1]]: fP(1), [CRITIC]: FALLA, [RETRY]: borrador(B2), [CHK[2]]: sinPresupuesto(2), [CRITIC2]: FALLA2 },
      { modelos: [COMPOSER, CRITIC, RETRY, CRITIC2], attempt: 2, draft: B2, bloque: { drafts: 2, critics: 2, checks: 2, check_errors: 0, rewrites: [delRevisor(FALLA)] } }],
    ['Studio, el servidor pide 1 y la comprobación 2 cae (sin tercer borrador)', decideStudio, { [COMPOSER]: borrador(B1), [CHK[1]]: fR(1), [RETRY]: borrador(B2), [CHK[2]]: fixtures['check-error-de-red.json'], [CRITIC2]: FALLA2 },
      { modelos: [COMPOSER, RETRY, CRITIC2], attempt: 2, draft: B2, bloque: { drafts: 2, critics: 1, checks: 2, check_errors: 1, rewrites: [servidor(fR(1))] } }],
    ['Studio, un nodo de OpenAI sin usage (tokens null)', decideStudio, { [COMPOSER]: borrador(B1), [CHK[1]]: fP(1), [CRITIC]: revisor('pass', [], '', null, null) },
      { modelos: [COMPOSER, CRITIC], attempt: 1, draft: B1, bloque: { drafts: 1, critics: 1, checks: 1, check_errors: 0, rewrites: [] } }],
  ] : [];
  check('los recorridos se pueden preparar (fixtures presentes)', recorridos.length > 0);
  for (const [nombre, ctx, pins, e] of recorridos) {
    prueba(`recorrido «${nombre}»`, () => {
      const errores = [];
      for (const modo of ['lanza', 'undefined']) {
        const r = recorrer(s2, ctx, pins, modo);
        const dice = (cond, que) => { if (!cond) errores.push(`[${modo}] ${que}`); };
        dice(iguales(r.modelos, e.modelos), `modelos ${r.modelos.join(', ')} ≠ ${e.modelos.join(', ')}`);
        dice(r.modelos.filter(x => REDACTORES.includes(x)).length <= 3 && r.modelos.filter(x => REVISORES.includes(x)).length <= 3, 'más de 3 redactores o revisores');
        dice(r.comprobaciones.length === (e.bloque ? e.bloque.checks : 0), `comprobaciones ${r.comprobaciones.length}`);
        dice(r.commit.critic.attempt === e.attempt && r.commit.proposal.reply_draft === e.draft, `attempt ${r.commit.critic.attempt}, borrador ${insp(r.commit.proposal.reply_draft)}`);
        if (e.verdict) dice(r.commit.critic.verdict === e.verdict, `veredicto ${r.commit.critic.verdict}`);
        for (const [k, cuerpo] of Object.entries(r.cuerpos)) {
          const kk = k === CHK[1] ? 1 : 2;
          dice(iguales(Object.keys(cuerpo), CLAVES_CUERPO) && iguales(Object.keys(cuerpo.draft), CLAVES_BORRADOR) && cuerpo.attempt === kk
            && cuerpo.draft.reply_draft === (kk === 1 ? B1 : B2) && cuerpo.conversation_id === 11 && cuerpo.message_id === 22 && cuerpo.decide_token === ctx.decide_token, `cuerpo de ${k} ${insp(cuerpo)}`);
        }
        if (e.bloque === null) {
          dice(!('regeneration' in r.commit), 'sin oferta no hay bloque');
        } else {
          const esperado = Object.assign({ contract: CONTRATO }, e.bloque, { tokens: tokensDe(pins, e.modelos) });
          dice(bloqueValido(r.commit.regeneration).length === 0, `bloque inválido: ${bloqueValido(r.commit.regeneration).join(', ')}`);
          dice(iguales(r.commit.regeneration, esperado), `bloque ${insp(r.commit.regeneration)} ≠ ${insp(esperado)}`);
          dice(r.commit.regeneration && r.commit.regeneration.drafts === r.commit.critic.attempt, 'drafts ≠ critic.attempt');
        }
        // Lo que recibe el redactor de cada reescritura: el borrador anterior y la nota de quien la pidió.
        for (const [redactor, [anterior, quien]] of Object.entries(e.datos || {})) {
          const u = r.enviados[redactor] && r.enviados[redactor].user;
          const issues = quien.body ? quien.body.issues : quien.output[0].content[0].text.issues;
          const notes = quien.body ? quien.body.notes : quien.output[0].content[0].text.notes;
          dice(u && u.rejected_draft === anterior && iguales(u.critic_issues, issues) && u.critic_notes === notes, `${redactor} recibe ${insp(u && { rejected_draft: u.rejected_draft, critic_issues: u.critic_issues, critic_notes: u.critic_notes })}`);
        }
        if (e.comoS1) {
          const h = recorrer(s1, ctx, pins, modo);
          dice(iguales(h.modelos, r.modelos), `S1 llama a ${h.modelos.join(', ')}`);
          dice(r.comprobaciones.length === 0, 'sin oferta, ninguna llamada a ai/check');
          dice(iguales(h.enviados, r.enviados), `los datos enviados al modelo difieren de S1: ${diferencias(h.enviados, r.enviados).slice(0, 3).join('; ')}`);
          const sello = x => { const c = clon(x); if (c.prompts) c.prompts.flow_rev = '«rev»'; return c; };
          dice(iguales(sello(h.commit), sello(r.commit)) && (!h.commit.prompts || (h.commit.prompts.flow_rev === 'S1' && r.commit.prompts.flow_rev === 'S2')),
            `Preparar Commit difiere de S1: ${diferencias(sello(h.commit), sello(r.commit)).slice(0, 3).join('; ')}`);
        }
      }
      return { ok: errores.length === 0, detalle: errores.slice(0, 6).join('\n') };
    });
  }

  // ── J. El cuerpo de la comprobación ─────────────────────────────────────

  console.log('\nJ. El cuerpo de la comprobación');
  for (const k of [1, 2]) {
    const n = nodosS2[CHK[k]];
    prueba(`Comprobar Borrador ${k}: el cuerpo lleva solo las claves del contrato`, () => {
      if (!n) return { ok: false, detalle: 'no está' };
      const casos = [[{ recommended_plan_id: undefined, tools_requested: [] }, null, []], [{ recommended_plan_id: 3, tools_requested: ['app_links_send'] }, 3, ['app_links_send']]];
      const errores = [];
      for (const [estrategia, plan, tools] of casos) {
        const nodos = {
          [EVENTO]: EVENTO_FIJO, [DECIDE]: { decide_token: 'tok.en' },
          [ESTRATEGIA]: { strategy: Object.assign({}, ESTRATEGIA_FIJA, { intent: 'pricing_question' }, estrategia) },
          [COMPOSER]: borrador(B1), [RETRY]: borrador(B2),
        };
        const cuerpo = JSON.parse(evaluar(n.parameters.jsonBody, {}, nombre => ({ first: () => { if (nodos[nombre]) return { json: nodos[nombre] }; throw new Error(`«${nombre}» no corrió`); } })));
        if (!iguales(Object.keys(cuerpo), CLAVES_CUERPO) || !iguales(Object.keys(cuerpo.draft || {}), CLAVES_BORRADOR)) errores.push(`claves ${insp(cuerpo)}`);
        if (!iguales(cuerpo, { conversation_id: 11, message_id: 22, decide_token: 'tok.en', attempt: k, draft: { reply_draft: k === 1 ? B1 : B2, intent: 'pricing_question', recommended_plan_id: plan, tools_requested: tools } })) errores.push(`valores ${insp(cuerpo)}`);
      }
      return { ok: errores.length === 0, detalle: errores.join('\n') };
    });
  }

  // ── Resultado ───────────────────────────────────────────────────────────

  console.log('');
  if (fallos.length === 0) {
    console.log(`OK (${total} comprobaciones)`);
    process.exitCode = 0;
    return 0;
  }
  console.log(`FALLO: ${fallos.length} de ${total} comprobaciones`);
  process.exitCode = 1;
  return 1;
}

module.exports = { main };

if (require.main === module) main(process.argv.slice(2));
