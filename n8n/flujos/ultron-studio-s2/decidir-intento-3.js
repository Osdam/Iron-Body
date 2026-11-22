/*
 * DECIDIR INTENTO 3 — ULTRON STUDIO S2 («regenerar antes de sustituir», plan
 * §5.2). GENERADO por `php artisan ultron:prompts-n8n --rev=S2`. NO SE EDITA A
 * MANO.
 *
 * Entra el veredicto de «Critic Comercial 2» y sale TAL CUAL (Preparar Commit
 * y el redactor del tercer borrador lo leen de $json.output), mas
 * ultron_intento3: un tercer borrador solo si ese revisor lo rechazo y la
 * comprobacion 2 dijo, sin error, que todavia queda presupuesto. Sin la oferta
 * (Legacy) la comprobacion 2 no corrio y es false: el camino de hoy. Nunca
 * lanza: si algo falla aqui, false.
 */
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

let entrada = {};
try { entrada = ($input.first() && $input.first().json) || {}; } catch (e) { entrada = {}; }

let intento3 = false;
try {
  const critic = sacar(entrada);
  const falla = !!critic && typeof critic === "object"
    && (critic.verdict === "fail" || (typeof critic.hard_fail === "string" && critic.hard_fail !== ""));
  if (falla) {
    const l = $("Leer Comprobación 2").first();
    const c = l && l.json ? l.json.ultron_check : null;
    intento3 = !!c && c.may_rewrite === true && c.error === false;
  }
} catch (e) { intento3 = false; }

return [{ json: Object.assign({}, entrada, { ultron_intento3: intento3 }) }];
