// Ensayo dentro de n8n de la copia S2 (lead, 22-11-2026): datos fijados de cada recorrido del LEEME (paso 4).
// Sin red: lee el flujo y los fixtures del repositorio y escribe un JSON por recorrido (cada uno se pasa tal cual a test_workflow del MCP de n8n).
// Uso, desde la raíz del repositorio: node backend/docs/ultron-studio/ops/motores/n8n-copia-s2/pins-s2.cjs . <dir de salida>
const fs = require('fs'), vm = require('vm'), crypto = require('crypto'), path = require('path');
const [R, OUT] = process.argv.slice(2);
const dir = path.join(R, 'n8n/flujos/ultron-studio-s2');
const flujo = JSON.parse(fs.readFileSync(path.join(dir, 'flujo-s2.json'), 'utf8'));
const fx = n => JSON.parse(fs.readFileSync(path.join(dir, 'fixtures', n), 'utf8'));
const clon = x => JSON.parse(JSON.stringify(x));

// decide: el Legacy del repositorio, tal cual; el Studio, el del repositorio con los cinco prompts sustituidos por textos
// cortos y su huella recalculada como la calcula el selector (los nodos de OpenAI van fijados y no leen el prompt).
const decideLegacy = fx('decide-legacy.json');
const entero = fx('decide-studio.json');
const ROLES = ['strategist', 'composer', 'critic', 'composer_retry', 'critic_retry'];
const roles = Object.fromEntries(ROLES.map(k => [k, `ENSAYO S2: prompt compacto del rol ${k}; los nodos de OpenAI van con datos fijados y no lo leen.`]));
const decideStudio = clon(entero);
decideStudio.studio_prompts.roles = roles;
decideStudio.studio_prompts.bundle_hash = crypto.createHash('sha256').update(['ultron-prompts/1'].concat(...ROLES.map(k => [k, roles[k]])).join('\n'), 'utf8').digest('hex');

// Comprobación previa, con el código REAL del selector: el compacto tiene que salir «studio», igual que el entero.
const selector = flujo.nodes.find(x => x.name === 'Elegir Prompts').parameters.jsCode;
const elegir = ctx => vm.runInContext(`(function () {\n${selector}\n})()`, vm.createContext({ $input: { first: () => ({ json: clon(ctx) }) } }))[0].json.ultron_prompts;
const [c, e, l] = [elegir(decideStudio), elegir(entero), elegir(decideLegacy)];
console.log(`selector · compacto: ${c.mode}/${c.reason} · entero: ${e.mode}/${e.reason} · legacy: ${l.mode}/${l.reason}`);
if (c.mode !== 'studio' || e.mode !== 'studio' || l.mode !== 'legacy') { console.log('EL FIXTURE COMPACTO NO VALE'); process.exit(1); }
console.log('claves de studio_prompts:', Object.keys(decideStudio.studio_prompts).join(', '), '· regeneration:', JSON.stringify(decideStudio.regeneration));

// Las mismas formas que usa el comprobador (verificar-flujo-s2.cjs).
const B1 = 'Claro, le cuento. ¿Qué le gustaría lograr?', B2 = 'Segundo borrador, de usted y más corto.', B3 = 'Tercer borrador.';
const borrador = t => ({ output: [{ content: [{ text: { reply_draft: t, uses_price_placeholder: false, questions_asked: 1, plans_mentioned: 0 } }] }] });
const revisor = (verdict, issues = [], notes = '') => ({ output: [{ content: [{ text: { verdict, score: verdict === 'pass' ? 0.9 : 0.3, issues, notes, hard_fail: null } }] }] });
const PASA = revisor('pass'), FALLA = revisor('fail', ['naturalness', 'relevance'], 'NOTA DEL REVISOR 1: tutea y no responde.'), FALLA2 = revisor('fail', ['next_step'], 'NOTA DEL REVISOR 2: sin paso siguiente.');
const ESTRATEGIA = { output: [{ content: [{ text: {
  strategy_goal: 'collect_data', next_state: 'GOAL_DISCOVERY', intent: 'general_info', recommended_plan_id: null, main_barrier: null, next_best_action: 'ask_discovery', confidence: 0.8,
  tools_requested: [], staff_review_reason: null, conversation_goal: 'understand', information_needed: ['goal'], question_needed: true, response_goal: 'entender', buying_signal: 'none',
  closing_opportunity: false, payment_opportunity: false, app_support_opportunity: false, recommendation_goal: 'none', human_handoff_requested: false, human_handoff_reason: null, human_handoff_evidence: null,
} }] }] };
const ENTRADA = { headers: { 'content-type': 'application/json' }, params: {}, query: {}, body: { conversation_id: 11, message_id: 22, meta_message_id: 'wamid.X', text: 'hola', message_type: 'text', channel: 'whatsapp', event_id: '' } };
const COMMIT200 = { statusCode: 200, statusMessage: 'OK', headers: { 'content-type': 'application/json' }, body: { ok: true, outcome: 'executed', ai_action_id: 1, code: null, fallback_mode: null } };
const con = (f, k) => { const x = clon(fx(f)); if (x.body && typeof x.body === 'object' && 'attempt' in x.body) x.body.attempt = k; return x; };
const base = (decide, chk1, chk2) => ({ 'Entrada ULTRON': ENTRADA, 'Pedir Contexto al CRM': decide, 'Ejecutar en el CRM': COMMIT200, 'Comprobar Borrador 1': chk1, 'Comprobar Borrador 2': chk2, Strategist: ESTRATEGIA, Composer: borrador(B1) });

const RECORRIDOS = {
  '0-trampa': ['Studio, pasa, con «Composer Reintento» fijado DE MÁS (¿la trampa de los datos fijados existe?)', Object.assign(base(decideStudio, con('check-pasa.json', 1), con('check-pasa.json', 2)), { 'Critic Comercial': PASA, 'Composer Reintento': borrador(B2) })],
  '1-legacy-pasa': ['Legacy, pasa', Object.assign(base(decideLegacy, con('check-pasa.json', 1), con('check-pasa.json', 2)), { 'Critic Comercial': PASA })],
  '2-legacy-revisor-falla': ['Legacy, el revisor falla', Object.assign(base(decideLegacy, con('check-pasa.json', 1), con('check-pasa.json', 2)), { 'Critic Comercial': FALLA, 'Composer Reintento': borrador(B2), 'Critic Comercial 2': PASA })],
  '3-studio-pasa': ['Studio, pasa', Object.assign(base(decideStudio, con('check-pasa.json', 1), con('check-pasa.json', 2)), { 'Critic Comercial': PASA })],
  '4-studio-servidor-pide-1': ['Studio, el servidor pide 1', Object.assign(base(decideStudio, con('check-reescribe.json', 1), con('check-pasa.json', 2)), { 'Composer Reintento': borrador(B2), 'Critic Comercial 2': PASA })],
  '5-studio-revisor-pide-1': ['Studio, el revisor pide 1', Object.assign(base(decideStudio, con('check-pasa.json', 1), con('check-pasa.json', 2)), { 'Critic Comercial': FALLA, 'Composer Reintento': borrador(B2), 'Critic Comercial 2': PASA })],
  '6-studio-peor-caso': ['Studio, dos reescrituras (peor caso)', Object.assign(base(decideStudio, con('check-pasa.json', 1), con('check-pasa.json', 2)), { 'Critic Comercial': FALLA, 'Composer Reintento': borrador(B2), 'Critic Comercial 2': FALLA2, 'Composer Reintento 2': borrador(B3), 'Critic Comercial 3': PASA })],
  '7-studio-servidor-caido': ['Studio, el servidor caído', Object.assign(base(decideStudio, con('check-404.json', 1), con('check-error-de-red.json', 2)), { 'Critic Comercial': FALLA, 'Composer Reintento': borrador(B2), 'Critic Comercial 2': FALLA2 })],
};
fs.mkdirSync(OUT, { recursive: true });
for (const [k, [nombre, nodos]] of Object.entries(RECORRIDOS)) {
  const pin = Object.fromEntries(Object.entries(nodos).map(([n, json]) => [n, [{ json }]]));
  const s = JSON.stringify(pin);
  fs.writeFileSync(path.join(OUT, `pin-${k}.json`), s);
  console.log(`${k.padEnd(28)} ${String(s.length).padStart(6)} bytes · ${Object.keys(pin).length} nodos fijados · ${nombre}`);
}
