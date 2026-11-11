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
}, promptsAplicados ? { prompts: promptsAplicados } : {}) }];
