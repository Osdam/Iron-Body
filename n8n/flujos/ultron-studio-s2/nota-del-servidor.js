/*
 * NOTA DEL SERVIDOR — ULTRON STUDIO S2 («regenerar antes de sustituir», plan
 * §5.2). GENERADO por `php artisan ultron:prompts-n8n --rev=S2`. NO SE EDITA A
 * MANO.
 *
 * ai/check pidio reescribir: se emite con la FORMA DEL REVISOR, para que el
 * redactor de la reescritura lea critic_issues y critic_notes de
 * $json.output[0].content[0].text sin tocar su values[1]. by y codes son
 * traza: el redactor no los recibe.
 */
let c = {};
try {
  const j = ($input.first() && $input.first().json) || {};
  c = (j.ultron_check && typeof j.ultron_check === "object") ? j.ultron_check : {};
} catch (e) { c = {}; }
return [{ json: { output: [{ content: [{ text: {
  verdict: "fail",
  score: null,
  issues: Array.isArray(c.issues) ? c.issues : [],
  notes: typeof c.notes === "string" ? c.notes : "",
  hard_fail: null,
  by: "server",
  codes: Array.isArray(c.codes) ? c.codes : []
} }] }] } }];
