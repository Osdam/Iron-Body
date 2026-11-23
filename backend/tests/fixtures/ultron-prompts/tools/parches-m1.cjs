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
 *
 * Compilador 3 (Enmienda A4-1 del H5, 12-11-2026): el texto de `maxChars` para
 * el redactor es el del apartado «Compilador 3» de §B.3, con `{M}`, `{P}` y
 * `{F}` además de `{N}`. Este guion los calcula POR SU CUENTA con las fórmulas
 * del plan (`margen()`), y comprueba que las fórmulas y la tabla N → M, P, F
 * están escritas tal cual en §B.3: tres fuentes (el plan, este guion y PHP).
 *
 * Compilador 4 (Enmienda A4-2 del H5, 18-11-2026): los dos textos de
 * `tone = profesional` son los del apartado «Compilador 4» de §B.3 (sustituyen
 * a los de la tabla, que quedan como históricos), y se exige que estén escritos
 * tal cual DENTRO de ese apartado, no solo en algún sitio de §B.3.
 *
 * Compilador 4, el parche del MOTOR («regenerar antes de sustituir», 19-11-2026;
 * `plans/informes-motores/plan-arquitecto-a4-regenerar.md` §7.1, y §B.3,
 * apartado «Compilador 4», «El parche del motor: la lista de planes»): no es de
 * un parámetro; va AL FINAL y SOLO si ya hay algún parche (con la línea base,
 * ninguno), a Composer y Composer Reintento tras `FORMA:` y a los dos Critic
 * tras `progressive_disclosure:`. Sus dos textos también tienen que estar
 * escritos tal cual DENTRO del apartado «Compilador 4».
 *
 * Compilador 5 (Enmienda A4-5 del H5, 22-11-2026; PT-17,
 * `plans/informes-motores/diseno-arq-pt17-planes.md`, y §B.3, apartado
 * «Compilador 5»): el parche del motor pasa de cuatro entradas a once. En el
 * Strategist, una inserción tras `QUE PLAN SE COTIZA` y una sustitución de
 * «Presentalo con dos o tres beneficios reales…»; en Composer y Composer
 * Reintento, el texto nuevo tras `FORMA:` (sustituye al del 4) y las
 * sustituciones de «Cualquier otro marcador…» y «el precio solo del que se
 * cotiza.», más la de «Elige el plan…» solo en Composer (su reintento no la
 * tiene); los Critic, igual que en el 4. Los seis textos nuevos tienen que
 * estar escritos tal cual DENTRO del apartado «Compilador 5».
 *
 * Compilador 6 (Enmienda A4-6 del H5, 23-11-2026; PT-17b,
 * `plans/informes-motores/diseno-arq-pt17b-planes-c6.md` con la enmienda del
 * lead, y §B.3, apartado «Compilador 6»): el parche del motor pasa de once
 * entradas a quince. En Composer y Composer Reintento, la sustitución de «Si
 * vas a preguntar, usa suggested_question cuando venga:», las del 5 (la del
 * precio, con texto nuevo) y el texto de la lista tras su ÚLTIMA línea
 * («price_verification true significa…»), en lugar de tras `FORMA:`; en cada
 * Critic, dos inserciones tras su última línea («Si el borrador explica POR
 * QUE la persona vio otro precio…»): la lista y la frase de lo que ya
 * comprueba el backend (quedan esa frase y después LISTA). El Strategist,
 * igual que en el 5. El texto de la lista del redactor es una guía con `{L}` y
 * `{W}`, que este guion calcula POR SU CUENTA (`topeDeLista()`) con las
 * fórmulas de ese apartado, y comprueba que las fórmulas y su tabla están
 * escritas tal cual en él. Los cinco textos que cambian o se suman tienen que
 * estar escritos tal cual DENTRO del apartado «Compilador 6»; los del 5 que
 * siguen, dentro del apartado «Compilador 5».
 *
 * Compilador 7 (Enmienda A4-7 del H5, 23-11-2026; PT-17c,
 * `plans/informes-motores/diseno-pt17c-estratega-c7.md`, y §B.3, apartado
 * «Compilador 7»): solo cambia el texto de la entrada 1, la lista del
 * Strategist tras `QUE PLAN SE COTIZA` (`engine.plansOverview.strategist`),
 * que sustituye al del 5 y el 6 y tiene que estar escrito tal cual DENTRO del
 * apartado «Compilador 7». Las quince entradas, sus anclas, sus operaciones y
 * su orden, y todos los textos de los redactores y los revisores, igual que
 * en el 6.
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
/** Compilador 3: cambia el texto de `maxChars` para el redactor (§B.3, «Compilador 3»); el 4, los dos de `tone = profesional` (§B.3, «Compilador 4»); el 5, el 6 y el 7, el parche del motor (§B.3, «Compilador 5», «Compilador 6» y «Compilador 7»). */
const COMPILER = 7;

/** §B.3 / C3: la línea base («como hoy», M1-4), con la lista de frases vacía. */
const BASELINE = { 'persona.tone': 'cercano', 'persona.emojis': true, 'persona.maxChars': 320, [PHRASES]: [], 'strategy.goal': 'cita' };
/** §B.3: «Varios parches sobre la misma ancla se aplican en el orden de los parámetros». */
const PARAMETERS = ['persona.tone', 'persona.emojis', 'persona.maxChars', 'strategy.goal'];
/** §B.3, «Compilador 4», el parche del motor: no es un parámetro; va el último. */
const ENGINE = 'engine.plansOverview';
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
  // Compilador 5 (§B.3, «Compilador 5»): las del parche del motor en el Strategist y en los redactores.
  cotiza: { text: 'QUE PLAN SE COTIZA', lineStart: false },
  presentalo: { text: 'Presentalo con dos o tres beneficios reales, nunca con adjetivos.', lineStart: false },
  marcador: { text: 'Cualquier otro marcador hace que el mensaje se rechace.', lineStart: false },
  elige: { text: 'Elige el plan que mejor encaje, di su nombre y su precio con los marcadores, y si quieres pregunta despues.', lineStart: false },
  precio: { text: 'el precio solo del que se cotiza.', lineStart: false },
  // Compilador 6 (§B.3, «Compilador 6»): la de suggested_question y las últimas líneas de los redactores y de los revisores.
  sugerida: { text: 'Si vas a preguntar, usa suggested_question cuando venga:', lineStart: false },
  finComposer: { text: 'price_verification true significa', lineStart: false },
  finCritic: { text: 'Si el borrador explica POR QUE la persona vio otro precio', lineStart: false },
};

/** Los textos de §B.3 tal cual («…» del plan). `{N}` = el número de `maxChars`. */
const T = {
  // Compilador 4 (§B.3, «Compilador 4»): sustituyen a los del compilador 1–3, que siguen en la tabla de §B.3 como históricos.
  'tone.profesional.composer': 'REGISTRO PUBLICADO: profesional. Esto manda sobre TODO este prompt, tambien sobre los ejemplos de abajo, knowledge_base.tone, suggested_question y approved_brand_copy: trata a la persona de usted en cada frase, sin mezclar. Los ejemplos se dicen de usted: "Te entiendo" es "Le entiendo", "tu objetivo" es "su objetivo", "te paso" es "le paso", "cuentame" es "cuenteme", "quieres" es "quiere". Nunca "te", "tu", "tus", "ti" ni "contigo". Frases completas y sobrias, sin muletillas coloquiales ("De una", "Listo", "Tranquilo") ni exclamaciones seguidas. Sigue siendo calido; profesional no es frio. El backend comprueba el registro antes de enviar y descarta el mensaje que tutee.',
  'tone.profesional.critic': 'REGISTRO PUBLICADO: profesional. Tratar de usted NO es defecto. Si el borrador tutea en cualquier frase o mezcla tu y usted ("te", "tu", "tus", "ti", "contigo", "quieres", "puedes", "cuentame", "dime"), verdict fail con naturalness y notes "pasar todo el mensaje a usted". Lo que va entre comillas citando a la persona no cuenta.',
  'tone.entusiasta.composer': 'REGISTRO PUBLICADO: entusiasta. Mas energia: celebra lo que la persona quiere lograr y transmite ganas de acompanarla. Sin exagerar, sin prometer resultados y sin urgencia ni presion.',
  'tone.entusiasta.critic': 'REGISTRO PUBLICADO: entusiasta. La energia NO es defecto de naturalness; la urgencia o la presion siguen siendo PRESSURE.',
  'emojis.false.composer': 'Sin emojis: ninguno, en ningun mensaje.',
  'emojis.false.critic': 'EMOJIS: la version publicada no usa emojis; si el borrador lleva alguno, verdict fail con naturalness.',
  // Compilador 3 (§B.3, «Compilador 3»): sustituye al del compilador 1–2, que sigue en la tabla de §B.3 como histórico.
  'maxChars.composer': 'TOPE DE LONGITUD PUBLICADO: el limite es {N} caracteres por mensaje y el backend lo mide antes de enviar: al mensaje que se pase se le quita la pregunta final y, si aun asi no cabe, se descarta entero. Escribe unos {M} caracteres, que son unas {P} palabras: como mucho {F} frases cortas, con una sola pregunta. Cuenta cada marcador como el texto que lo sustituye. Tambien la informacion general respeta el tope: da primero lo esencial de lo que se pregunto y, si hace falta, ofrece ampliar.',
  'maxChars.critic.replace': 'mas de ~{N} caracteres',
  'maxChars.critic.insert': 'TOPE PUBLICADO: un borrador de mas de ~{N} caracteres falla por progressive_disclosure aunque se haya pedido detalle.',
  'goal.venta.strategist': 'OBJETIVO COMERCIAL PUBLICADO: cerrar membresia. Con senales claras de compra (buying_signal strong, hot_lead_fast_path o READY) prioriza recommend y close sobre ofrecer la visita; el dia de cortesia queda para quien desconfia o quiere conocer antes. Nada de esto autoriza presion, descuentos ni salirse de allowed_transitions.',
  'goal.informar.strategist': 'OBJETIVO COMERCIAL PUBLICADO: informar y cualificar. Prioriza understand y recommend; no propongas close ni collect_payment salvo que la persona pida comprar o pagar con palabras.',
  // Compilador 5, el parche del motor (§B.3, «Compilador 5»): el del redactor sustituye al del 4, que sigue en «Compilador 4» como histórico.
  // Compilador 7 (§B.3, «Compilador 7»): la lista del Strategist sustituye a la del 5 y el 6, que sigue en «Compilador 5» como histórica.
  'engine.plansOverview.strategist': 'LISTA DE PLANES DEL BACKEND: si commercial_turn_policy.plans_overview trae planes, la persona pidio los planes o los precios en general y el backend decidio responder con esa lista. En ese turno esto manda sobre las reglas de este apartado, la lista de next_best_action, resolved_reference y strategy_hints (suggested_question, should_recommend_now): el turno RESPONDE con la lista entera; no descubre; una recomendacion no sustituye la lista: solo si se pidio, va tras ella. PLAN_k es la posicion k de plans_overview.plans, no el plan de id k. Entonces next_best_action answer (de allowed_next_actions), conversation_goal recommend, recommendation_goal compare_plans, information_needed [] y recommended_plan_id el que digan las reglas de abajo (nunca null), solo como referencia del contrato: se destaca solo si se pidio. response_goal con tus palabras, sin cifras y en menos de 160 caracteres: se presentan los planes de plans_overview, cada uno con su marcador y un beneficio tal como esta en sus benefits, sin cambiarle el sentido; se contesta tambien lo demas que pregunto en ese mensaje, nombrandolo; y cabe una pregunta breve para elegir. Si plans_overview no viene, nada de esto aplica.',
  'engine.plansOverview.strategist.present': 'Presentalo con dos o tres beneficios reales, nunca con adjetivos; si commercial_turn_policy.plans_overview trae planes, no: manda LISTA DE PLANES DEL BACKEND.',
  // Compilador 6 (§B.3, «Compilador 6»): el del redactor (al final del prompt; una guía sin frase fija, con `{L}` y `{W}` del tope de la lista) y el del precio sustituyen a los del 5, que siguen en «Compilador 5» como históricos; se suma el de suggested_question.
  'engine.plansOverview.composer.ask': 'Si vas a preguntar, usa suggested_question cuando venga, nunca si commercial_turn_policy.plans_overview trae planes (LISTA DE PLANES DEL BACKEND):',
  'engine.plansOverview.composer': 'LISTA DE PLANES DEL BACKEND: si commercial_turn_policy.plans_overview trae planes, la persona pidio los planes o los precios y el backend ya eligio cuales mostrar. Presentalos con tus palabras, en una o dos frases naturales: cada plan con su marcador PLAN_1, PLAN_2 o PLAN_3 entre dobles llaves (el de su posicion en plans_overview.plans, una sola vez) y un beneficio breve tomado de sus benefits. El backend cambia cada marcador por el nombre y el precio oficiales: no escribas nombres de plan, ni cifras, ni PLAN_NAME ni PLAN_PRICE. Si la persona pregunto ademas otra cosa, respondela tambien en este mensaje. Puedes abrir con una frase corta y cerrar con UNA pregunta breve que ayude a elegir o a avanzar; suggested_question no es obligatoria aqui. LONGITUD DE ESTE TURNO: el mensaje completo, ya con nombres y precios, no pasa de {L} caracteres; cada marcador ocupara unos 25, asi que escribe como mucho unas {W} palabras tuyas. En este turno esto manda sobre lo anterior que choque (orden del mensaje, recomendar un solo plan, preguntar antes de informar y el tope general). Si plans_overview no viene, nada de esto aplica.',
  'engine.plansOverview.composer.marker': 'Cualquier otro marcador hace que el mensaje se rechace, salvo PLAN_1 a PLAN_3 cuando commercial_turn_policy.plans_overview trae planes (LISTA DE PLANES DEL BACKEND).',
  'engine.plansOverview.composer.choose': 'Elige el plan que mejor encaje, di su nombre y su precio con los marcadores, y si quieres pregunta despues; si commercial_turn_policy.plans_overview trae planes, en vez de eso das esa lista (LISTA DE PLANES DEL BACKEND).',
  'engine.plansOverview.composer.price': 'el precio solo del que se cotiza o, si commercial_turn_policy.plans_overview trae planes, ni nombres ni precios a mano: los pone el marcador de cada plan (LISTA DE PLANES DEL BACKEND).',
  // Compilador 6 (§B.3, «Compilador 6»): el del revisor sustituye al del 4 y el 5 (que sigue en «Compilador 4» como histórico) y se suma la frase de lo que ya comprueba el backend.
  'engine.plansOverview.critic': 'LISTA DE PLANES DEL BACKEND (va al final porque manda sobre todo lo anterior): si reply_draft lleva PLAN_1, PLAN_2 o PLAN_3 entre dobles llaves, la persona pidio los planes o los precios y el backend eligio esos planes; cada marcador pone el nombre y el precio reales. En ese borrador NO son defecto: dar dos o tres planes en una frase con un beneficio corto de cada uno, que es la forma pedida y no plantilla ni folleto (naturalness, progressive_disclosure); darlos sin preguntar antes objetivo, experiencia u horario, aunque la fase sea NEW_LEAD o DISCOVERY o la estrategia traiga recommended_plan_id (sales_judgment, phase_alignment, TECNICA, ni customer_fit por la fase); cerrar con una pregunta corta para elegir, con una sola pregunta aunque lleve una o, o sin pregunta (next_step, question_discipline); su longitud, que el backend mide con los marcadores ya sustituidos. No es invented_plan ni invented_price ni esquiva el precio. Todo lo demas falla como siempre: un nombre o una cifra escritos a mano, un beneficio que no esta en active_plans, mas de una pregunta, y cada regla de FALLA TAMBIEN y de HARD_FAIL.',
  'engine.plansOverview.critic.checked': 'REGISTRO Y LONGITUD EN LA LISTA DE PLANES: si reply_draft lleva PLAN_1, PLAN_2 o PLAN_3 entre dobles llaves, el registro (tu/usted) y la longitud ya los comprueba el backend de forma determinista: no falles, no bajes naturalness ni los menciones en notes por ellos.',
};

/**
 * Compilador 3, §B.3 «Compilador 3»: el margen del redactor para un tope N, en
 * enteros. M = el 80 % de N redondeado a la decena por abajo; P = M / 30 por
 * abajo, por 5; F = (M + 30) / 60 por abajo, y nunca menos de 2.
 */
const FORMULAS = ['`M = intdiv(N * 8, 100) * 10`', '`P = intdiv(M, 30) * 5`', '`F = max(2, intdiv(M + 30, 60))`'];
function margen(n) {
  const m = Math.floor((n * 8) / 100) * 10;
  return { M: m, P: Math.floor(m / 30) * 5, F: Math.max(2, Math.floor((m + 30) / 60)) };
}

/**
 * Compilador 6, §B.3 «Compilador 6»: el tope de un mensaje con la lista de
 * planes para un tope N, en enteros. L = N, y nunca menos de 320; W = (L − 110)
 * / 30 por abajo, por 5.
 */
const FORMULAS_LISTA = ['`L = max(N, 320)`', '`W = intdiv(L - 110, 30) * 5`'];
function topeDeLista(n) {
  const l = Math.max(n, 320);
  return { L: l, W: Math.floor((l - 110) / 30) * 5 };
}

/** Un texto con sus marcadores sustituidos: `{N}` y, si los lleva, `{M}`, `{P}` y `{F}` del margen de N y `{L}` y `{W}` de su tope de lista. */
function sustituir(texto, n) {
  const g = margen(n);
  const t = topeDeLista(n);
  return texto.split('{N}').join(String(n)).split('{M}').join(String(g.M)).split('{P}').join(String(g.P)).split('{F}').join(String(g.F)).split('{L}').join(String(t.L)).split('{W}').join(String(t.W));
}

/** Compilador 4: los textos que tienen que estar escritos DENTRO del apartado «Compilador 4» de §B.3 (el del revisor del motor ya no: lo sustituye el del 6). */
const DEL_COMPILADOR_4 = ['tone.profesional.composer', 'tone.profesional.critic'];
/** Compilador 5: los textos del parche del motor del 5 que siguen en el 6, escritos DENTRO del apartado «Compilador 5» de §B.3. */
const DEL_COMPILADOR_5 = ['engine.plansOverview.strategist.present', 'engine.plansOverview.composer.marker', 'engine.plansOverview.composer.choose'];
/** Compilador 6: los cinco textos que cambian o se suman, escritos DENTRO del apartado «Compilador 6» de §B.3. */
const DEL_COMPILADOR_6 = ['engine.plansOverview.composer.ask', 'engine.plansOverview.composer.price', 'engine.plansOverview.composer', 'engine.plansOverview.critic', 'engine.plansOverview.critic.checked'];
/** Compilador 7: el único texto que cambia (la lista del Strategist), escrito DENTRO del apartado «Compilador 7» de §B.3. */
const DEL_COMPILADOR_7 = ['engine.plansOverview.strategist'];

/** Cómo aparece cada texto en el plan (para la comprobación de transcripción): cada marcador, con su letra. */
const CON_LETRAS = (texto) => texto.split('{N}').join('N').split('{M}').join('M').split('{P}').join('P').split('{F}').join('F').split('{L}').join('L').split('{W}').join('W');
const EN_EL_PLAN = (clave, texto) => (clave === 'maxChars.critic.replace' ? '`' + CON_LETRAS(texto) + '`' : '«' + CON_LETRAS(texto) + '»');

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
  // Compilador 3: las fórmulas del margen y su tabla, tal cual en §B.3 («Compilador 3»).
  if (!b3.includes('#### Compilador 3') || !b3.includes('`UltronPromptCompiler::VERSION = 3`')) throw new Error('§B.3 no tiene el apartado del compilador 3');
  // Compilador 4: su apartado, y sus textos que siguen vigentes dentro de él.
  const c4 = b3.indexOf('#### Compilador 4');
  if (c4 < 0 || !b3.includes('`UltronPromptCompiler::VERSION = 4`')) throw new Error('§B.3 no tiene el apartado del compilador 4');
  for (const clave of DEL_COMPILADOR_4) {
    if (!b3.slice(c4).includes(EN_EL_PLAN(clave, T[clave]))) throw new Error(`El texto ${clave} no aparece literalmente en el apartado «Compilador 4» de §B.3: transcripción equivocada`);
  }
  // Compilador 5: su apartado y sus textos que siguen vigentes dentro de él.
  const c5 = b3.indexOf('#### Compilador 5');
  if (c5 < 0 || !b3.slice(c5).includes('`UltronPromptCompiler::VERSION = 5`')) throw new Error('§B.3 no tiene el apartado del compilador 5');
  for (const clave of DEL_COMPILADOR_5) {
    if (!b3.slice(c5).includes(EN_EL_PLAN(clave, T[clave]))) throw new Error(`El texto ${clave} no aparece literalmente en el apartado «Compilador 5» de §B.3: transcripción equivocada`);
  }
  // Compilador 6: su apartado, la versión vigente y sus cinco textos dentro de él.
  const c6 = b3.indexOf('#### Compilador 6');
  if (c6 < 0 || !b3.slice(c6).includes('`UltronPromptCompiler::VERSION = 6`')) throw new Error('§B.3 no tiene el apartado del compilador 6');
  for (const clave of DEL_COMPILADOR_6) {
    if (!b3.slice(c6).includes(EN_EL_PLAN(clave, T[clave]))) throw new Error(`El texto ${clave} no aparece literalmente en el apartado «Compilador 6» de §B.3: transcripción equivocada`);
  }
  // Compilador 7: su apartado, la versión vigente y su texto dentro de él.
  const c7 = b3.indexOf('#### Compilador 7');
  if (c7 < 0 || !b3.slice(c7).includes('`UltronPromptCompiler::VERSION = ' + COMPILER + '`')) throw new Error('§B.3 no tiene el apartado del compilador ' + COMPILER);
  for (const clave of DEL_COMPILADOR_7) {
    if (!b3.slice(c7).includes(EN_EL_PLAN(clave, T[clave]))) throw new Error(`El texto ${clave} no aparece literalmente en el apartado «Compilador 7» de §B.3: transcripción equivocada`);
  }
  for (const f of FORMULAS)if (!b3.includes(f)) throw new Error(`La fórmula ${f} no aparece en §B.3: no es la que este guion implementa`);
  // Compilador 6: las fórmulas del tope de la lista y su tabla, dentro de su apartado.
  for (const f of FORMULAS_LISTA) if (!b3.slice(c6).includes(f)) throw new Error(`La fórmula ${f} no aparece en el apartado «Compilador 6» de §B.3`);
  for (const n of MAX_CHARS) {
    const t = topeDeLista(n);
    const fila = `| ${n} | ${t.L} | ${t.W} |`;
    if (!b3.slice(c6).includes(fila)) throw new Error(`La tabla del tope de la lista no tiene la fila «${fila}» que sale de las fórmulas`);
  }
  for (const n of MAX_CHARS.filter((x) => x !== 320)) {
    const g = margen(n);
    const fila = `| ${n} | ${g.M} | ${g.P} | ${g.F} |`;
    if (!b3.includes(fila)) throw new Error(`La tabla de §B.3 no tiene la fila «${fila}» que sale de las fórmulas`);
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
    for (const role of roles) out.push({ parameter, value, role, operation: op, anchor: A[ancla].text, textKey: n === undefined ? clave : `${clave}@${n}`, text: n === undefined ? T[clave] : sustituir(T[clave], n) });
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
  // El del motor: al final y solo si ya hay alguno (la línea base sigue sin ninguno). Compilador 6: quince
  // entradas; dentro de cada rol, en el orden de §B.3 («Compilador 6»).
  if (out.length > 0) {
    pon(ENGINE, true, ['strategist'], 'insert_after_line', 'cotiza', 'engine.plansOverview.strategist');
    pon(ENGINE, true, ['strategist'], 'replace', 'presentalo', 'engine.plansOverview.strategist.present');
    for (const role of COMPOSERS) {
      pon(ENGINE, true, [role], 'replace', 'sugerida', 'engine.plansOverview.composer.ask');
      pon(ENGINE, true, [role], 'replace', 'marcador', 'engine.plansOverview.composer.marker');
      // «Elige el plan…» solo está en la base de Composer.
      if (role === 'composer') pon(ENGINE, true, [role], 'replace', 'elige', 'engine.plansOverview.composer.choose');
      pon(ENGINE, true, [role], 'replace', 'precio', 'engine.plansOverview.composer.price');
      // Tras la última línea: el texto de la lista queda el último del prompt, con L y W del tope N.
      pon(ENGINE, true, [role], 'insert_after_line', 'finComposer', 'engine.plansOverview.composer', n);
    }
    // En cada revisor, dos inserciones tras su última línea: quedan la frase de lo que comprueba el backend y después LISTA, la última.
    for (const role of CRITICS) {
      pon(ENGINE, true, [role], 'insert_after_line', 'finCritic', 'engine.plansOverview.critic');
      pon(ENGINE, true, [role], 'insert_after_line', 'finCritic', 'engine.plansOverview.critic.checked');
    }
  }
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

module.exports = { BACKEND, REPO, ROLES, NODES, CONTRACT, PHRASES, COMPILER, BASELINE, PARAMETERS, ENGINE, TONES, GOALS, MAX_CHARS, LIMITS, A, T, margen, topeDeLista, sha256, bytes, bundleHash, leerBase, comprobarBaseContraFlujo, comprobarTextosContraElPlan, parches, compilar, valido };
