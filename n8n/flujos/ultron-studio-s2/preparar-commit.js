const ev = $("Normalizar Evento").first().json;
const ctx = $("Pedir Contexto al CRM").first().json;
const est = $("Validar Estrategia").first().json.strategy;

function sacar(j) {
  const o = j && j.output;
  if (o === undefined || o === null) return j;
  if (Array.isArray(o)) {
    const c = o[0] && o[0].content && o[0].content[0];
    let t = c ? c.text : undefined;
    if (typeof t === "string") { try { t = JSON.parse(t); } catch (e) {} }
    return t;
  }
  if (typeof o === "string") { try { return JSON.parse(o); } catch (e) { return o; } }
  return o;
}

const critic = sacar($input.first().json);
if (!critic || typeof critic !== "object") {
  throw new Error("Veredicto del critico ilegible");
}

let intento = 1;
const prim = sacar($("Composer").first().json);
let draft = prim ? prim.reply_draft : undefined;
try {
  const r = $("Composer Reintento").first();
  const alt = r && r.json ? sacar(r.json) : null;
  if (alt && alt.reply_draft) { intento = 2; draft = alt.reply_draft; }
} catch (e) { intento = 1; }

/*
 * ULTRON STUDIO S2 («regenerar antes de sustituir»): el tercer borrador, en su
 * propio try. Si no corrio o no trae texto, se queda el que habia: nunca vuelve
 * al primero.
 */
try {
  const r3 = $("Composer Reintento 2").first();
  const alt3 = r3 && r3.json ? sacar(r3.json) : null;
  if (alt3 && alt3.reply_draft) { intento = 3; draft = alt3.reply_draft; }
} catch (e) { /* no corrio: se queda el borrador de antes */ }

if (!draft || typeof draft !== "string" || draft.trim() === "") {
  throw new Error("El Composer no produjo borrador");
}

/*
 * Ultima red antes del commit, y la que de verdad importa: se mira el TEXTO.
 * Si el borrador cotiza un plan pero la estrategia no dijo cual, el CRM lo
 * rechazaria y el cliente se quedaria sin respuesta. Se usa el plan por
 * defecto del negocio que manda el propio CRM; nunca un id inventado aqui.
 */
let plan = est.recommended_plan_id;
const cotiza = /\{\{\s*PLAN_(PRICE|NAME|DURATION)\s*\}\}/.test(draft);
if (cotiza && (plan === null || plan === undefined)) {
  const porDefecto = ctx.context && ctx.context.default_plan_id;
  const activos = ((ctx.context && ctx.context.active_plans) || []).map(p => p.id);
  if (porDefecto && activos.includes(porDefecto)) { plan = porDefecto; }
}

/*
 * ULTRON STUDIO S1 (Enmienda M-1): lo que este flujo dice haber aplicado.
 * Solo si decide ofrecio prompts con el contrato que conocemos (un backend
 * sin la oferta no recibe un bloque que no entenderia). Es traza: si algo
 * falla aqui, el commit sale sin bloque, nunca sin respuesta.
 */
let promptsAplicados = null;
try {
  const sp = ctx.studio_prompts;
  if (sp && sp.contract === "ultron-prompts/1") {
    const up = $("Elegir Prompts").first().json.ultron_prompts;
    promptsAplicados = {
      mode: up.mode,
      bundle_hash: up.bundle_hash,
      reason: up.reason,
      flow_rev: up.flow_rev,
      execution_id: String($execution.id)
    };
  }
} catch (e) { promptsAplicados = null; }

/*
 * ULTRON STUDIO S2 («regenerar antes de sustituir», plan §4.3 y §5.2): lo que
 * este flujo dice haber hecho para el borrador. Solo si decide ofrecio el
 * contrato ultron-regen/1 (un backend sin la oferta no recibe un bloque que no
 * entenderia). Es traza: si algo falla aqui, el commit sale sin bloque, nunca
 * sin respuesta. Solo codigos y numeros del vocabulario cerrado del servidor:
 * ningun texto.
 */
let regeneracion = null;
try {
  const rg = ctx.regeneration;
  if (rg && rg.contract === "ultron-regen/1") {
    const CODES = ["register_tuteo","length_over_cap","plans_overview","benefit_misattributed","policy_greeting_sells","policy_active_goal_lost","policy_wrong_first_plan","policy_answer_without_fact","policy_general_info_only_discovery","policy_memory_turn_sells"];
    const DIMENSIONS = ["relevance","empathy","naturalness","context_use","sales_judgment","pressure_control","next_step","question_discipline","repetition","phase_alignment","reference_resolution","novelty","customer_fit","progressive_disclosure","factuality","tool_consistency","memory_consistency","payment_safety","url_safety","app_factuality","handoff_leak","membership_factuality"];
    const MAX_CODES = 8, MAX_ISSUES = 8, MAX_TOKENS = 2000000;
    const corrio = n => { try { const x = $(n).first(); return x && x.json ? x.json : null; } catch (e) { return null; } };
    const unicos = (lista, vocabulario, tope) => {
      const out = [];
      for (const x of Array.isArray(lista) ? lista : []) { if (typeof x === "string" && vocabulario.includes(x) && !out.includes(x)) out.push(x); }
      return out.slice(0, tope);
    };
    const cuantos = nombres => nombres.filter(n => corrio(n) !== null).length;
    // La reescritura k la pidio el servidor si «Leer Comprobacion k» dijo rewrite; si no, el revisor anterior.
    const quien = (lectura, revisor) => {
      const l = corrio(lectura);
      const c = l ? l.ultron_check : null;
      if (c && c.rewrite === true) return { by: "server", codes: unicos(c.codes, CODES, MAX_CODES), issues: unicos(c.issues, DIMENSIONS, MAX_ISSUES) };
      const r = corrio(revisor);
      const v = r ? sacar(r) : null;
      return { by: "critic", codes: [], issues: unicos(v && v.issues, DIMENSIONS, MAX_ISSUES) };
    };
    const lecturas = ["Leer Comprobación 1","Leer Comprobación 2"].map(corrio).filter(j => j !== null).map(j => j.ultron_check);
    const reescrituras = [];
    if (corrio("Composer Reintento") !== null) reescrituras.push(quien("Leer Comprobación 1", "Critic Comercial"));
    if (corrio("Composer Reintento 2") !== null) reescrituras.push(quien("Leer Comprobación 2", "Critic Comercial 2"));
    // Los tokens, solo si TODOS los nodos de OpenAI que corrieron los entregan: una suma a medias diria menos de lo que costo.
    const modelos = ["Strategist","Composer","Critic Comercial","Composer Reintento","Critic Comercial 2","Composer Reintento 2","Critic Comercial 3"].map(corrio).filter(j => j !== null);
    const entero = v => Number.isInteger(v) && v >= 0;
    let tokens = null;
    if (modelos.length > 0 && modelos.every(j => j.usage && entero(j.usage.input_tokens) && entero(j.usage.output_tokens))) {
      const enviados = modelos.reduce((s, j) => s + j.usage.input_tokens, 0);
      const recibidos = modelos.reduce((s, j) => s + j.usage.output_tokens, 0);
      if (enviados <= MAX_TOKENS && recibidos <= MAX_TOKENS) tokens = { input: enviados, output: recibidos };
    }
    const bloque = {
      contract: "ultron-regen/1",
      drafts: cuantos(["Composer","Composer Reintento","Composer Reintento 2"]),
      critics: cuantos(["Critic Comercial","Critic Comercial 2","Critic Comercial 3"]),
      checks: lecturas.length,
      check_errors: lecturas.filter(c => !c || c.error !== false).length,
      rewrites: reescrituras,
      tokens: tokens
    };
    // Fuera de su rango no se manda: el commit lo rechazaria con un 422, y esto es solo traza.
    if (bloque.drafts >= 1 && bloque.drafts <= 3 && bloque.critics <= 3 && bloque.checks <= 2
      && bloque.rewrites.length === bloque.drafts - 1) {
      regeneracion = bloque;
    }
  }
} catch (e) { regeneracion = null; }

return [{ json: Object.assign({
  source_type: ctx.source.source_type,
  source_event_id: ctx.source.source_event_id,
  idempotency_key: ctx.source.idempotency_key || ev.idempotency_key,
  conversation_id: ev.conversation_id,
  decide_token: ctx.decide_token,
  proposal: {
    strategy_goal: est.strategy_goal,
    next_state: est.next_state,
    intent: est.intent,
    reply_draft: draft,
    recommended_plan_id: plan === undefined ? null : plan,
    main_barrier: est.main_barrier,
    next_best_action: est.next_best_action,
    confidence: est.confidence,
    tools_requested: est.tools_requested,
    // POR QUE se pide staff_review. Ya normalizado arriba: o es el unico motivo
    // proponible por el modelo, o viaja null y el CRM descarta la herramienta.
    staff_review_reason: est.staff_review_reason || null,
    /*
     * Derivacion: PROPUESTA, ya normalizada por Validar Estrategia. El CRM la
     * vuelve a verificar contra el mensaje real antes de cualquier efecto.
     */
    conversation_goal: est.conversation_goal,
    information_needed: est.information_needed,
    question_needed: est.question_needed === true,
    response_goal: est.response_goal || null,
    buying_signal: est.buying_signal,
    closing_opportunity: est.closing_opportunity === true,
    payment_opportunity: est.payment_opportunity === true,
    app_support_opportunity: est.app_support_opportunity === true,
    recommendation_goal: est.recommendation_goal,
    human_handoff_requested: est.human_handoff_requested === true,
    human_handoff_reason: est.human_handoff_requested === true ? est.human_handoff_reason : null,
    human_handoff_evidence: est.human_handoff_requested === true ? est.human_handoff_evidence : null
  },
  critic: {
    verdict: critic.verdict,
    attempt: intento,
    score: critic.score,
    notes: String(critic.notes || "").slice(0, 500),
    issues: Array.isArray(critic.issues) ? critic.issues.filter(x => typeof x === "string").slice(0, 8) : [],
    hard_fail: typeof critic.hard_fail === "string" && critic.hard_fail !== "" ? critic.hard_fail : null
  }
}, promptsAplicados ? { prompts: promptsAplicados } : {}, regeneracion ? { regeneration: regeneracion } : {}) }];
