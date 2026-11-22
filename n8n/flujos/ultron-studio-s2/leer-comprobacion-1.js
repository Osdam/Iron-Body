/*
 * LEER COMPROBACION 1 — ULTRON STUDIO S2 («regenerar antes de sustituir»,
 * plan §5.2). GENERADO por `php artisan ultron:prompts-n8n --rev=S2`. NO SE
 * EDITA A MANO.
 *
 * Lee lo que contesto ai/check sobre el borrador 1 y devuelve EL MISMO
 * objeto del redactor (el revisor sigue leyendo el borrador en $json.output)
 * mas ultron_check = {rewrite, may_rewrite, error, codes, issues, notes}.
 * Solo un 200 bien formado del contrato puede pedir una reescritura; cualquier
 * otra cosa (404, 422, 5xx, red, tiempo agotado, otra forma) es error y NO
 * reescribir: el camino de hoy. Lo que no es del vocabulario del servidor no
 * pasa. Leer la comprobacion nunca lanza.
 */
const K = 1, REDACTOR = "Composer", CONTRACT = "ultron-regen/1";
const CODES = ["register_tuteo","length_over_cap","plans_overview","benefit_misattributed","policy_greeting_sells","policy_active_goal_lost","policy_wrong_first_plan","policy_answer_without_fact","policy_general_info_only_discovery","policy_memory_turn_sells"];
const DIMENSIONS = ["relevance","empathy","naturalness","context_use","sales_judgment","pressure_control","next_step","question_discipline","repetition","phase_alignment","reference_resolution","novelty","customer_fit","progressive_disclosure","factuality","tool_consistency","memory_consistency","payment_safety","url_safety","app_factuality","handoff_leak","membership_factuality"];
const MAX_CODES = 8, MAX_ISSUES = 8, MAX_NOTES = 500;

function noReescribir() {
  return { rewrite: false, may_rewrite: false, error: true, codes: [], issues: [], notes: "" };
}

function unicos(lista, vocabulario, tope) {
  const out = [];
  for (const x of Array.isArray(lista) ? lista : []) {
    if (typeof x === "string" && vocabulario.includes(x) && !out.includes(x)) out.push(x);
  }
  return out.slice(0, tope);
}

function leer(r) {
  if (!r || typeof r !== "object" || r.statusCode !== 200) return noReescribir();
  let b = r.body;
  if (typeof b === "string") { try { b = JSON.parse(b); } catch (e) { return noReescribir(); } }
  const forma = !!b && typeof b === "object" && !Array.isArray(b)
    && b.ok === true && b.contract === CONTRACT && (b.mode === "studio" || b.mode === "legacy") && b.attempt === K
    && typeof b.pass === "boolean" && Array.isArray(b.defects) && Array.isArray(b.issues)
    && typeof b.notes === "string" && typeof b.may_rewrite === "boolean" && typeof b.rewrite === "boolean";
  if (!forma) return noReescribir();
  const codes = unicos(b.defects.map(d => d && d.code), CODES, MAX_CODES);
  const issues = unicos(b.issues, DIMENSIONS, MAX_ISSUES);
  const notes = b.notes.trim().slice(0, MAX_NOTES);
  const puede = b.mode === "studio" && b.may_rewrite === true;
  if (b.rewrite !== true) return { rewrite: false, may_rewrite: puede, error: false, codes, issues, notes };
  // Reescribir sin presupuesto, sin defectos o sin nota no es del contrato: no se reescribe.
  if (!puede || b.defects.length === 0 || notes === "") return noReescribir();
  return { rewrite: true, may_rewrite: true, error: false, codes, issues, notes };
}

// El redactor siempre corrio: este nodo va detras de el.
const redactor = $(REDACTOR).first().json;
let check = noReescribir();
try { check = leer($input.first().json); } catch (e) { check = noReescribir(); }
return [{ json: Object.assign({}, redactor, { ultron_check: check }) }];
