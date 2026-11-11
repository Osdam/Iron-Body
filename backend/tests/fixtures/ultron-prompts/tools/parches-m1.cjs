'use strict';
/*
 * PARCHES DE LA ENMIENDA M-1, DERIVADOS DEL CONTRATO (tester, 11-11-2026).
 *
 * Fuente ÚNICA: `docs/ultron-studio/plans/STUDIO_MOTORES_PLAN.md` §B.3 (tabla
 * «Qué entra de cada capa en cada rol», anclas, operaciones, orden, límites y
 * fórmula de la huella), congelado por la Enmienda M-1 del contrato H5
 * (M1-3: «Aprobados los borradores de B.3 tal cual»).
 *
 * NO se ha leído `app/Services/Marketing/Ultron/Prompts/UltronPromptCompiler.php`
 * para escribir esto: es la segunda implementación, independiente, con la que se
 * contrasta la primera. Los textos están transcritos a mano del plan y
 * `comprobarTextosContraElPlan()` exige que cada uno aparezca literalmente en él.
 *
 * Puro: sin red, sin reloj, sin aleatoriedad. Solo lee la base y el plan.
 */
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const BACKEND = path.resolve(__dirname, '..', '..', '..', '..');
const REPO = path.resolve(BACKEND, '..');
const BASE_DIR = path.join(BACKEND, 'resources', 'ultron-prompts', 'n8n-29de5119');
const PLAN = path.join(BACKEND, 'docs', 'ultron-studio', 'plans', 'STUDIO_MOTORES_PLAN.md');

/** §B.3: orden canónico de roles ↔ nodos de n8n. */
const ROLES = ['strategist', 'composer', 'critic', 'composer_retry', 'critic_retry'];
const NODES = { strategist: 'Strategist', composer: 'Composer', critic: 'Critic Comercial', composer_retry: 'Composer Reintento', critic_retry: 'Critic Comercial 2' };
const CONTRACT = 'ultron-prompts/1';

/**
 * Compilador 2 (reglas S-NNN en WhatsApp, auditoría de cierre §3.2): lo
 * gobernado lleva una quinta clave, la lista de frases prohibidas. Su parche NO
 * está en §B.3 (no es texto congelado del plan): este guion solo deriva la
 * lista VACÍA, que no aplica ningún parche, así que los 306 casos del contrato
 * tienen que dar los mismos bytes con el compilador 2 que con el 1. Las frases
 * no vacías se prueban en PHP (`UltronPromptCompilerPhrasesTest`).
 */
const PHRASES = 'rules.forbiddenPhrases';
const COMPILER = 2;

/** §B.3 / C3: la línea base («como hoy», M1-4), con la lista de frases vacía. */
const BASELINE = { 'persona.tone': 'cercano', 'persona.emojis': true, 'persona.maxChars': 320, [PHRASES]: [], 'strategy.goal': 'cita' };
/** §B.3: «Varios parches sobre la misma ancla se aplican en el orden de los parámetros». */
const PARAMETERS = ['persona.tone', 'persona.emojis', 'persona.maxChars', 'strategy.goal'];
const TONES = ['cercano', 'profesional', 'entusiasta'];
const GOALS = ['cita', 'venta', 'informar'];
/** §B.3: «maxChars = N (160–480, paso 20, ≠ 320)». */
const MAX_CHARS = Array.from({ length: (480 - 160) / 20 + 1 }, (_, i) => 160 + 20 * i);

/** §B.3, «Límites». */
const LIMITS = { patchBytes: 1200, roleBytes: 40960, bundleBytes: 163840 };

/**
 * Las anclas de §B.3. `lineStart`: el plan dice de `naturalness:` y
 * `progressive_disclosure:` que están «a principio de línea» (y del segundo,
 * «la línea que empieza por»). Se buscan de las DOS maneras (subcadena y
 * principio de línea) y las dos tienen que dar exactamente una línea, la
 * misma: si un día no coinciden, la generación falla en vez de elegir.
 */
const A = {
  comoSuenas: { text: 'COMO SUENAS:', lineStart: false },
  forma: { text: 'FORMA:', lineStart: false },
  emoji: { text: 'Emoji opcional y raro, nunca en temas sensibles.', lineStart: false },
  mas900: { text: 'mas de ~900 caracteres', lineStart: false },
  naturalness: { text: 'naturalness:', lineStart: true },
  progressive: { text: 'progressive_disclosure:', lineStart: true },
  principio: { text: 'PRINCIPIO:', lineStart: false },
};

/** Los textos de §B.3 tal cual («…» del plan). `{N}` = el número de `maxChars`. */
const T = {
  'tone.profesional.composer': 'REGISTRO PUBLICADO: profesional. Esto manda sobre los ejemplos de arriba: trata a la persona de usted, frases completas y sobrias, sin muletillas coloquiales ("De una", "Listo", "Tranquilo") ni exclamaciones seguidas. Sigue siendo calido; profesional no es frio.',
  'tone.profesional.critic': 'REGISTRO PUBLICADO: profesional. Tratar de usted NO es defecto de naturalness; tutear o usar muletillas coloquiales SI lo es.',
  'tone.entusiasta.composer': 'REGISTRO PUBLICADO: entusiasta. Mas energia: celebra lo que la persona quiere lograr y transmite ganas de acompanarla. Sin exagerar, sin prometer resultados y sin urgencia ni presion.',
  'tone.entusiasta.critic': 'REGISTRO PUBLICADO: entusiasta. La energia NO es defecto de naturalness; la urgencia o la presion siguen siendo PRESSURE.',
  'emojis.false.composer': 'Sin emojis: ninguno, en ningun mensaje.',
  'emojis.false.critic': 'EMOJIS: la version publicada no usa emojis; si el borrador lleva alguno, verdict fail con naturalness.',
  'maxChars.composer': 'TOPE DE LONGITUD PUBLICADO: como maximo unos {N} caracteres por mensaje, contando cada marcador como el texto que lo sustituye; tambien la informacion general respeta el tope: si no cabe, da lo esencial y ofrece ampliar.',
  'maxChars.critic.replace': 'mas de ~{N} caracteres',
  'maxChars.critic.insert': 'TOPE PUBLICADO: un borrador de mas de ~{N} caracteres falla por progressive_disclosure aunque se haya pedido detalle.',
  'goal.venta.strategist': 'OBJETIVO COMERCIAL PUBLICADO: cerrar membresia. Con senales claras de compra (buying_signal strong, hot_lead_fast_path o READY) prioriza recommend y close sobre ofrecer la visita; el dia de cortesia queda para quien desconfia o quiere conocer antes. Nada de esto autoriza presion, descuentos ni salirse de allowed_transitions.',
  'goal.informar.strategist': 'OBJETIVO COMERCIAL PUBLICADO: informar y cualificar. Prioriza understand y recommend; no propongas close ni collect_payment salvo que la persona pida comprar o pagar con palabras.',
};

/** Cómo aparece cada texto en el plan (para la comprobación de transcripción). */
const EN_EL_PLAN = (clave, texto) => (clave === 'maxChars.critic.replace' ? '`' + texto.replace('{N}', 'N') + '`' : '«' + texto.replace('{N}', 'N') + '»');

function comprobarTextosContraElPlan() {
  const plan = fs.readFileSync(PLAN, 'utf8');
  const ini = plan.indexOf('### B.3 El compilador');
  const fin = plan.indexOf('### B.4');
  if (ini < 0 || fin < 0) throw new Error('No encuentro §B.3 en el plan');
  const b3 = plan.slice(ini, fin);
  for (const [clave, texto] of Object.entries(T)) {
    if (!b3.includes(EN_EL_PLAN(clave, texto))) throw new Error(`El texto ${clave} no aparece literalmente en §B.3 del plan: transcripción equivocada`);
  }
  for (const a of Object.values(A)) {
    if (!b3.includes('`' + a.text + '`')) throw new Error(`El ancla «${a.text}» no aparece en §B.3`);
  }
  if (!b3.includes('`bundleHash = sha256_hex(implode("\\n", ["ultron-prompts/1", "strategist", S, "composer", C, "critic", K, "composer_retry", CR, "critic_retry", KR]))`')) {
    throw new Error('La fórmula de la huella de §B.3 no es la que este guion implementa');
  }
}

const sha256 = (s) => crypto.createHash('sha256').update(Buffer.from(s, 'utf8')).digest('hex');
const bytes = (s) => Buffer.byteLength(s, 'utf8');

/** §B.3: la huella del paquete. */
function bundleHash(roles) {
  const partes = [CONTRACT];
  for (const r of ROLES) {
    if (typeof roles[r] !== 'string') throw new Error(`falta el rol ${r}`);
    partes.push(r, roles[r]);
  }
  return sha256(partes.join('\n'));
}

/** La base, tal cual (bytes → UTF-8, ida y vuelta exacta). */
function leerBase() {
  const out = {};
  for (const r of ROLES) {
    const b = fs.readFileSync(path.join(BASE_DIR, r + '.txt'));
    const s = b.toString('utf8');
    if (!Buffer.from(s, 'utf8').equals(b)) throw new Error(`${r}.txt no es UTF-8 válido`);
    out[r] = s;
  }
  return out;
}

/**
 * La base = el flujo vivo 29de5119 (respaldo exportado) = nuevo4.json → .system,
 * byte a byte. Se comprueba aquí, de forma independiente de UltronPromptBase.
 */
function comprobarBaseContraFlujo(base) {
  const respaldo = JSON.parse(fs.readFileSync(path.join(REPO, 'n8n', 'respaldos', '20261111-ultron-29de5119', 'ultron-commercial-advisor.json'), 'utf8'));
  if (respaldo.versionId !== '29de5119-2e5a-4bc8-a479-60f4fe37c5b3') throw new Error('el respaldo no es la versión 29de5119');
  const nuevo4 = JSON.parse(fs.readFileSync(path.join(BACKEND, 'docs', 'marketing', 'prompts', '2026-09-22-voz', 'nuevo4.json'), 'utf8'));
  for (const r of ROLES) {
    const nodo = respaldo.nodes.filter((n) => n.name === NODES[r]);
    if (nodo.length !== 1) throw new Error(`el respaldo no tiene un único nodo ${NODES[r]}`);
    if (nodo[0].parameters.responses.values[0].content !== base[r]) throw new Error(`${r}.txt ≠ ${NODES[r]}.responses.values[0].content del flujo 29de5119`);
    if (nuevo4[NODES[r]].system !== base[r]) throw new Error(`${r}.txt ≠ nuevo4.json → ${NODES[r]}.system`);
  }
}

/** Las posiciones (índices de inicio) de las líneas que contienen / empiezan por el ancla. */
function lineasDelAncla(texto, ancla) {
  const porSubcadena = [];
  for (let i = texto.indexOf(ancla.text); i >= 0; i = texto.indexOf(ancla.text, i + 1)) porSubcadena.push(i);
  if (porSubcadena.length !== 1) return { error: `ancla «${ancla.text}» aparece ${porSubcadena.length} veces` };
  const inicioLinea = texto.lastIndexOf('\n', porSubcadena[0]) + 1;
  if (ancla.lineStart && inicioLinea !== porSubcadena[0]) return { error: `ancla «${ancla.text}» no está a principio de línea` };
  // La otra lectura (principio de línea) tiene que señalar la MISMA línea, una sola vez.
  if (ancla.lineStart) {
    const lineas = texto.split('\n');
    const empiezan = lineas.filter((l) => l.startsWith(ancla.text)).length;
    if (empiezan !== 1) return { error: `ancla «${ancla.text}»: ${empiezan} líneas empiezan por ella` };
  }
  return { at: porSubcadena[0] };
}

/**
 * §B.3, operaciones:
 *  - insert_after_line: «inserta una línea nueva tras la línea que contiene el ancla»;
 *  - replace: «sustituye la subcadena».
 * Ancla ausente o repetida → error.
 */
function aplicar(texto, op, ancla, nuevo) {
  const r = lineasDelAncla(texto, ancla);
  if (r.error) throw new Error(r.error);
  if (op === 'replace') return texto.slice(0, r.at) + nuevo + texto.slice(r.at + ancla.text.length);
  if (op === 'insert_after_line') {
    const finDeLinea = texto.indexOf('\n', r.at);
    if (finDeLinea < 0) return texto + '\n' + nuevo; // el ancla está en la última línea
    return texto.slice(0, finDeLinea) + '\n' + nuevo + texto.slice(finDeLinea);
  }
  throw new Error('operación desconocida ' + op);
}

/**
 * Los parches de §B.3 para unos valores gobernados, en orden de aplicación:
 * por parámetro (tone, emojis, maxChars, goal) y, dentro de un parámetro y un
 * rol, en el orden en que los enumera la celda («reemplazar …; e insertar …»).
 * El orden ENTRE roles no lo fija el contrato (no interactúan): no se afirma.
 */
function parches(g) {
  const out = [];
  const pon = (parameter, value, roles, op, ancla, clave, n) => {
    for (const role of roles) out.push({ parameter, value, role, operation: op, anchor: A[ancla].text, textKey: n === undefined ? clave : `${clave}@${n}`, text: T[clave].replace('{N}', String(n)) });
  };
  const COMPOSERS = ['composer', 'composer_retry'];
  const CRITICS = ['critic', 'critic_retry'];
  const tone = g['persona.tone'];
  if (tone === 'profesional' || tone === 'entusiasta') {
    pon('persona.tone', tone, COMPOSERS, 'insert_after_line', 'comoSuenas', `tone.${tone}.composer`);
    pon('persona.tone', tone, CRITICS, 'insert_after_line', 'naturalness', `tone.${tone}.critic`);
  }
  if (g['persona.emojis'] === false) {
    pon('persona.emojis', false, COMPOSERS, 'replace', 'emoji', 'emojis.false.composer');
    pon('persona.emojis', false, CRITICS, 'insert_after_line', 'naturalness', 'emojis.false.critic');
  }
  const n = g['persona.maxChars'];
  if (n !== 320) {
    pon('persona.maxChars', n, COMPOSERS, 'insert_after_line', 'forma', 'maxChars.composer', n);
    for (const role of CRITICS) {
      pon('persona.maxChars', n, [role], 'replace', 'mas900', 'maxChars.critic.replace', n);
      pon('persona.maxChars', n, [role], 'insert_after_line', 'progressive', 'maxChars.critic.insert', n);
    }
  }
  const goal = g['strategy.goal'];
  if (goal === 'venta' || goal === 'informar') pon('strategy.goal', goal, ['strategist'], 'insert_after_line', 'principio', `goal.${goal}.strategist`);
  return out;
}

/** ¿Valores válidos según §B.3? (exactamente las cuatro claves y la de las frases, VACÍA en este guion; tipos y dominios). */
function valido(g) {
  if (g === null || typeof g !== 'object' || Array.isArray(g)) return false;
  const claves = Object.keys(g).sort();
  if (claves.join() !== [...PARAMETERS, PHRASES].sort().join()) return false;
  if (!Array.isArray(g[PHRASES]) || g[PHRASES].length !== 0) return false;
  if (!TONES.includes(g['persona.tone']) || !GOALS.includes(g['strategy.goal'])) return false;
  if (typeof g['persona.emojis'] !== 'boolean') return false;
  return Number.isInteger(g['persona.maxChars']) && MAX_CHARS.includes(g['persona.maxChars']);
}

function compilar(base, g) {
  if (!valido(g)) throw new Error('valores no válidos');
  const roles = { ...base };
  const lista = parches(g);
  for (const p of lista) {
    if (bytes(p.text) > LIMITS.patchBytes) throw new Error('parche demasiado grande');
    const ancla = Object.values(A).find((a) => a.text === p.anchor);
    roles[p.role] = aplicar(roles[p.role], p.operation, ancla, p.text);
  }
  let total = 0;
  for (const r of ROLES) {
    if (bytes(roles[r]) > LIMITS.roleBytes) throw new Error('rol demasiado grande');
    total += bytes(roles[r]);
  }
  if (total > LIMITS.bundleBytes) throw new Error('paquete demasiado grande');
  return { roles, patches: lista, bundleHash: bundleHash(roles), bytes: total };
}

module.exports = { BACKEND, REPO, ROLES, NODES, CONTRACT, PHRASES, COMPILER, BASELINE, PARAMETERS, TONES, GOALS, MAX_CHARS, LIMITS, A, T, sha256, bytes, bundleHash, leerBase, comprobarBaseContraFlujo, comprobarTextosContraElPlan, parches, compilar, valido };
