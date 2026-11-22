#!/usr/bin/env node
'use strict';
/*
 * T9 DEL APARTADO 5: UN SIMULADOR DE LA RESPONSES API DE OPENAI PARA EL E2E DEL
 * LABORATORIO CON EL WORKER REAL (`UltronStudioLabEngineE2ETest`). El de H4
 * (`mock-openai.cjs`) vive en el repositorio del CRM; este es solo del backend.
 *
 * Uso: node mock-openai-lab-motor.cjs <puerto> <fichero.jsonl>
 *
 * `POST /v1/responses` contesta por el NOMBRE DEL ESQUEMA (`text.format.name`):
 *  - los nodos del motor (`ultron_strategy`, `ultron_reply`, `ultron_critic`):
 *    una estrategia de saludo, un borrador de usted que cabe en 200 y un revisor
 *    que aprueba;
 *  - el laboratorio (`ultron_lab_actor_v1`, `ultron_lab_judge_v1`): una
 *    respuesta de usted y `pass`/`not_applicable` a lo que pregunte el juez.
 * Cada petición (cabeceras de autorización enmascaradas, cuerpo entero) va a una
 * línea del fichero. Sin red hacia fuera: solo escucha en 127.0.0.1.
 */
const http = require('http');
const fs = require('fs');

const puerto = Number(process.argv[2] || 18093);
const registro = process.argv[3];

const ESTRATEGIA = {
  strategy_goal: 'recibir', next_state: 'NEW_LEAD', intent: 'greeting', recommended_plan_id: null, main_barrier: null,
  next_best_action: 'answer', confidence: 0.9, tools_requested: [], staff_review_reason: null, reason: 'Simulador.',
  human_handoff_requested: false, human_handoff_reason: null, human_handoff_evidence: null, conversation_goal: 'understand',
  information_needed: [], question_needed: false, response_goal: 'recibir', buying_signal: 'none', closing_opportunity: false,
  payment_opportunity: false, app_support_opportunity: false, recommendation_goal: 'none',
};

function salida(cuerpo) {
  const nombre = cuerpo && cuerpo.text && cuerpo.text.format && cuerpo.text.format.name;
  if (nombre === 'ultron_strategy') return ESTRATEGIA;
  if (nombre === 'ultron_reply') return { reply_draft: 'Buenas tardes, ¿en qué le puedo ayudar?', uses_price_placeholder: false, questions_asked: 1, plans_mentioned: 0 };
  if (nombre === 'ultron_critic') return { verdict: 'pass', score: 0.9, issues: [], notes: '', hard_fail: null };
  if (nombre === 'ultron_lab_actor_v1') return { reply: 'Buenas tardes, ¿en qué le puedo ayudar?' };
  if (nombre === 'ultron_lab_judge_v1') {
    let datos = {};
    try { datos = JSON.parse(cuerpo.input[0].content); } catch (e) { datos = {}; }
    const criterios = ((datos.escenario || {}).criterios || []).map(c => ({ id: c.id, verdict: 'pass', evidence: '', reason: 'Simulador.' }));
    const reglas = (datos.reglas || []).map(r => ({ code: r.codigo, verdict: 'not_applicable', reason: '' }));
    return { criteria: criterios, rules: reglas, risks: [], summary: 'Simulador del proveedor.' };
  }
  return null;
}

http.createServer((req, res) => {
  let trozos = '';
  req.on('data', c => { trozos += c; });
  req.on('end', () => {
    let cuerpo = null;
    try { cuerpo = JSON.parse(trozos); } catch (e) { cuerpo = null; }
    const auth = String(req.headers.authorization || '');
    if (registro) fs.appendFileSync(registro, JSON.stringify({ method: req.method, url: req.url, authorization: auth.replace(/^(Bearer .{6}).*$/, '$1…'), body: cuerpo }) + '\n');
    const s = req.method === 'POST' && req.url === '/v1/responses' ? salida(cuerpo) : null;
    if (s === null) { res.writeHead(400, { 'content-type': 'application/json' }); res.end(JSON.stringify({ error: { message: 'no previsto' } })); return; }
    res.writeHead(200, { 'content-type': 'application/json' });
    res.end(JSON.stringify({
      id: 'resp_e2e', object: 'response', status: 'completed',
      output: [{ type: 'message', id: 'msg_1', role: 'assistant', content: [{ type: 'output_text', text: JSON.stringify(s) }] }],
      usage: { input_tokens: 1500, output_tokens: 90, total_tokens: 1590 },
    }));
  });
}).listen(puerto, '127.0.0.1', () => { process.stdout.write(`listo ${puerto}\n`); });
