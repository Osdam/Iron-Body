#!/usr/bin/env node
'use strict';
/**
 * Pruebas propias de los simuladores y la valla del banco del Hito 8 (OPS8-a). SIN RED: todo en 127.0.0.1, con
 * puertos que da el sistema (en macOS, ≥ 49152). SIN CREDENCIALES REALES: piezas aleatorias en un directorio
 * temporal en 700/600 que se borra al final. No toca el repo ni ninguna base de datos.
 *
 * Oráculos independientes de los simuladores (para que «el simulador firma bien» no sea circular):
 *  - ElevenLabs: la clase REAL del H6, `App\Services\Voice\ElevenLabs\ElevenLabsWebhookSignature::verify` (PHP puro,
 *    la que B8 envuelve sin cambiarla, D8-3), sobre las 21 variantes del emisor. Sin `backend/vendor`, OMITIDO.
 *  - Twilio: 3 vectores de la documentación y de los SDK oficiales de Twilio, comprobados recomputando el HMAC (una
 *    coincidencia de HMAC-SHA1 no es casual), y una segunda implementación en Python (diferencial, claves repetidas).
 *    Los 6 vectores oficiales del contrato los fija TEST8-a en tests/fixtures/voice/**: cuando existan, se contrastan.
 * Uso: node sim-pruebas.cjs   (código 0 = todo OK; la última línea es «N OK · M FALLO · K OMITIDO»)
 */
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const http = require('node:http');
const os = require('node:os');
const path = require('node:path');
const { spawn, spawnSync } = require('node:child_process');
const C = require('./sim-comun.cjs');
const EL = require('./sim-elevenlabs.cjs');
const TW = require('./sim-twilio.cjs');

const AQUI = __dirname;
// BANCO_H8_BACKEND: el backend cuyo vendor y cuya clase del H6 hacen de oráculo (por defecto, el de este worktree).
const BACKEND = process.env.BANCO_H8_BACKEND || path.resolve(AQUI, '../../../..');
const hex = (n) => crypto.randomBytes(n).toString('hex');
const TEL_CANARIO = '+573000000011';   // convención sintética de TEST8-a: +57 300 000 00xx y +57 601 000 00xx
const TEL_OTRO = '+573000000012';
const TEL_FIJO = '+576010000091';

// ── Ejecución de casos ──────────────────────────────────────────────────────────────────────────
const casos = [];
const caso = (nombre, fn) => casos.push({ nombre, fn });
class Omitido extends Error {}

// ── Piezas sintéticas y procesos ───────────────────────────────────────────────────────────────
const TMP = fs.mkdtempSync(path.join(os.tmpdir(), 'banco-h8-pruebas-'));
fs.chmodSync(TMP, 0o700);
const PZ = {
  EL_API_KEY: `xi_${hex(24)}`, EL_WEBHOOK_SECRET: `wsec_${hex(24)}`, EL_TOOL_SECRET: `tool_${hex(24)}`, EL_INITIATION_SECRET: `ini_${hex(24)}`,
  EL_VERIFY_AGENT_ID: `agent_verificacion_${hex(4)}`, EL_PHONE_NUMBER_ID: `phnum_${hex(8)}`, EL_INITIATION_HEADER: 'X-Voice-Initiation-Secret',
  VOICE_NUMBER: '+576010000090', WHATSAPP_NUMBER: '+573000000050',
  TW_ACCOUNT_SID: C.sidTwilio('AC'), TW_MASTER_SID: C.sidTwilio('AC'), TW_MASTER_TOKEN: hex(16), TW_SIGNING_TOKEN: hex(16),
  TW_LARAVEL_KEY_SID: C.sidTwilio('SK'), TW_LARAVEL_KEY_SECRET: hex(16), TW_EL_KEY_SID: C.sidTwilio('SK'), TW_EL_KEY_SECRET: hex(16),
  TW_VOICE_NUMBER_SID: C.sidTwilio('PN'), TW_SIGN_BASE: 'https://api.ironbody-banco.invalid',
  SENTINEL_ELEVENLABS: `centinela-el-${hex(6)}`, SENTINEL_TWILIO: `centinela-tw-${hex(6)}`,
};
const SECRETOS = ['EL_API_KEY', 'EL_WEBHOOK_SECRET', 'EL_TOOL_SECRET', 'EL_INITIATION_SECRET', 'TW_MASTER_TOKEN', 'TW_SIGNING_TOKEN', 'TW_LARAVEL_KEY_SECRET', 'TW_EL_KEY_SECRET'].map((k) => PZ[k]);
const escribirPiezas = (nombre, extra) => {
  const f = path.join(TMP, nombre);
  fs.writeFileSync(f, Object.entries({ ...PZ, ...extra }).map(([k, v]) => `${k}=${v}`).join('\n') + '\n', { mode: 0o600 });
  return f;
};

const hijos = [];
function arrancar(script, args) {
  return new Promise((resolve, reject) => {
    const p = spawn(process.execPath, [path.join(AQUI, script), ...args], { stdio: ['ignore', 'pipe', 'pipe'] });
    hijos.push(p);
    let salida = '';
    let err = '';
    const t = setTimeout(() => reject(new Error(`${script} no arrancó en 5 s: ${err.slice(0, 200)}`)), 5000);
    p.stderr.on('data', (d) => { err += d; });
    p.stdout.on('data', (d) => {
      salida += d;
      const linea = salida.split('\n')[0];
      if (salida.includes('\n')) {
        clearTimeout(t);
        try {
          resolve({ ...JSON.parse(linea), proceso: p });
        } catch (e) {
          reject(e);
        }
      }
    });
    p.on('exit', (c) => { clearTimeout(t); if (!salida.includes('\n')) reject(new Error(`${script} salió con ${c}: ${err.slice(0, 200)}`)); });
  });
}

// ── Receptor que hace de Laravel ───────────────────────────────────────────────────────────────
const recibido = [];
const reglas = new Map(); // ruta → (entrada) => {status, body, delayMs}
const receptor = http.createServer((req, res) => {
  const trozos = [];
  req.on('data', (c) => trozos.push(c));
  req.on('end', () => {
    const e = { method: req.method, url: req.url, headers: req.headers, raw: Buffer.concat(trozos).toString('utf8') };
    recibido.push(e);
    const ruta = req.url.split('?')[0];
    const regla = reglas.get(ruta) ?? [...reglas.entries()].find(([k]) => k.endsWith('*') && ruta.startsWith(k.slice(0, -1)))?.[1];
    const r = regla ? regla(e) : { status: 200, body: { ok: true } };
    setTimeout(() => {
      res.writeHead(r.status, { 'Content-Type': 'application/json' });
      res.end(typeof r.body === 'string' ? r.body : JSON.stringify(r.body ?? {}));
    }, r.delayMs ?? 0);
  });
});

// ── Peticiones ─────────────────────────────────────────────────────────────────────────────────
let BASE_EL;
let BASE_TW;
let BASE_VALLA;
let BASE_L;
const pedir = async (base, method, ruta, { headers = {}, body = '', timeoutMs = 5000 } = {}) => {
  const r = await C.emitir({ base, path: ruta, method, cabeceras: headers, cuerpo: body, timeoutMs });
  let json = null;
  try {
    json = JSON.parse(r.body);
  } catch {
    /* no JSON */
  }
  return { ...r, json };
};
const ctl = async (base, ruta) => {
  const r = await pedir(base, 'GET', ruta);
  return r.json;
};
const statsEl = () => ctl(BASE_EL, '/__stats');
const statsTw = () => ctl(BASE_TW, '/__stats');
const el = (method, ruta, { key = PZ.EL_API_KEY, body, headers = {}, timeoutMs } = {}) => {
  const h = { ...headers };
  if (key !== null) h['xi-api-key'] = key;
  if (body !== undefined) h['Content-Type'] ??= 'application/json';
  return pedir(BASE_EL, method, ruta, { headers: h, body: body === undefined ? '' : typeof body === 'string' ? body : JSON.stringify(body), timeoutMs });
};
const basic = (u, p) => `Basic ${Buffer.from(`${u}:${p}`).toString('base64')}`;
const CRED = {
  laravel: basic(PZ.TW_LARAVEL_KEY_SID, PZ.TW_LARAVEL_KEY_SECRET), elevenlabs: basic(PZ.TW_EL_KEY_SID, PZ.TW_EL_KEY_SECRET),
  firma: basic(PZ.TW_ACCOUNT_SID, PZ.TW_SIGNING_TOKEN), maestra: basic(PZ.TW_MASTER_SID, PZ.TW_MASTER_TOKEN),
  mezclada: basic(PZ.TW_LARAVEL_KEY_SID, PZ.TW_EL_KEY_SECRET), desconocida: basic(C.sidTwilio('SK'), hex(16)),
};
const ACC = `/2010-04-01/Accounts/${PZ.TW_ACCOUNT_SID}`;
const tw = (method, ruta, { cred = CRED.laravel, form, headers = {}, timeoutMs } = {}) => {
  const h = { ...headers };
  if (cred !== null) h.Authorization = cred;
  if (form !== undefined) h['Content-Type'] ??= 'application/x-www-form-urlencoded';
  return pedir(BASE_TW, method, ruta, { headers: h, body: form === undefined ? '' : new URLSearchParams(form).toString(), timeoutMs });
};
/** Ejecuta `fn` y devuelve las violaciones nuevas del simulador, por código. */
async function nuevas(stats, fn) {
  const a = (await stats()).violationsByCode;
  const r = await fn();
  const b = (await stats()).violationsByCode;
  const d = {};
  for (const [k, n] of Object.entries(b)) if (n - (a[k] ?? 0) > 0) d[k] = n - (a[k] ?? 0);
  return { r, d };
}
const espera = (ms) => new Promise((r) => setTimeout(r, ms));

// ── La carga de un agente compilado: la forma de B8-a (ElevenLabsAgentMapper, 03-11-2026) ─────────
// Las webhook se crean antes con POST /v1/convai/tools y van por id en prompt.tool_ids; las de sistema, en
// prompt.built_in_tools; los secretos, por referencia (secret_id) del gestor de ElevenLabs.
const BASE_CANONICA = 'https://api.ironbody-banco.invalid';
const herramientaCompilada = (nombre, extra = {}) => C.mezclar({
  type: 'webhook', name: nombre, description: `Herramienta ${nombre}`,
  api_schema: {
    url: `${BASE_CANONICA}/api/voice/tools/${nombre}`, method: 'POST',
    request_headers: { Authorization: { secret_id: 'sec_banco_herramientas' } },
    request_body_schema: { type: 'object', properties: { conversation_id: { type: 'string', dynamic_variable: 'system__conversation_id' }, call_sid: { type: 'string', dynamic_variable: 'system__call_sid' } } },
  },
}, extra);
async function crearHerramientas(nombres = ['consultar_horarios', 'consultar_planes']) {
  const ids = [];
  for (const n of nombres) ids.push((await el('POST', '/v1/convai/tools', { body: { tool_config: herramientaCompilada(n) } })).json.id);
  return ids;
}
function agenteCompilado(toolIds, extra = {}) {
  return C.mezclar({
    name: 'Iron Body · publicación (banco)', tags: ['ultron-studio', 'publicacion'],
    conversation_config: {
      agent: {
        first_message: EL.AVISO, language: 'es',
        dynamic_variables: { dynamic_variable_placeholders: { caller_authorized: 'no', call_ref: '', version_label: '' } },
        prompt: {
          prompt: 'Plantilla ultron-voice-compiler/1 (banco).', llm: 'banco-llm', temperature: 0, tool_ids: toolIds,
          built_in_tools: {
            end_call: { type: 'system', name: 'end_call', description: '', params: { system_tool_type: 'end_call' } },
            transfer_to_number: { type: 'system', name: 'transfer_to_number', description: '', params: { system_tool_type: 'transfer_to_number', transfers: [{ transfer_destination: { type: 'phone', phone_number: TEL_FIJO }, condition: 'pide una persona', transfer_type: 'conference' }] } },
          },
        },
      },
      conversation: { max_duration_seconds: 300 },
      tts: { voice_id: 'voz-banco' },
    },
    platform_settings: {
      call_limits: { agent_concurrency_limit: -1, daily_limit: 20, bursting_enabled: false },
      privacy: { record_voice: false, retention_days: 30, delete_audio: true, zero_retention_mode: false },
      overrides: { conversation_config_override: { agent: { first_message: false, language: false, prompt: { prompt: false } } }, enable_conversation_initiation_client_data_from_webhook: true },
      workspace_overrides: {
        conversation_initiation_client_data_webhook: { url: `${BASE_CANONICA}/api/webhooks/voice/elevenlabs/initiation`, request_headers: { 'X-Voice-Initiation-Secret': { secret_id: 'sec_banco_iniciacion' } } },
        webhooks: { post_call_webhook_id: 'wh_banco' },
      },
    },
  }, extra);
}
const salienteValida = (agentId, to = TEL_CANARIO, extra = {}) => C.mezclar({
  agent_id: agentId, agent_phone_number_id: PZ.EL_PHONE_NUMBER_ID, to_number: to, call_recording_enabled: false,
  telephony_call_config: { ringing_timeout_secs: 30, twilio_call_recording_enabled: false },
  conversation_initiation_client_data: { dynamic_variables: { caller_authorized: 'si', call_ref: crypto.randomUUID() } },
}, extra);
const respuestaIniciacion = (si = 'si') => ({ status: 200, body: { type: 'conversation_initiation_client_data', dynamic_variables: { caller_authorized: si, call_ref: crypto.randomUUID(), version_label: 'v-banco-1' } } });

// ══ A. ElevenLabs: superficie y autenticación ═════════════════════════════════════════════════
caso('EL · el control (/__stats, /__surface) responde y no cuenta como petición; superficie de 13 rutas', async () => {
  const s = await statsEl();
  const sup = await ctl(BASE_EL, '/__surface');
  assert.equal(s.requests, 0);
  assert.equal(sup.routes.length, 13);
  assert.equal((await statsEl()).requests, 0);
});
caso('EL · ruta no listada → 404 y violación unknown_route', async () => {
  const { r, d } = await nuevas(statsEl, () => el('GET', '/v1/voices'));
  assert.equal(r.status, 404);
  assert.deepEqual(d, { unknown_route: 1 });
});
caso('EL · verbo no listado (DELETE de un agente) → 405 y violación method_not_allowed', async () => {
  const { r, d } = await nuevas(statsEl, () => el('DELETE', `/v1/convai/agents/${PZ.EL_VERIFY_AGENT_ID}`));
  assert.equal(r.status, 405);
  assert.deepEqual(d, { method_not_allowed: 1 });
});
caso('EL · sin xi-api-key → 401 y violación no_api_key', async () => {
  const { r, d } = await nuevas(statsEl, () => el('GET', `/v1/convai/agents/${PZ.EL_VERIFY_AGENT_ID}`, { key: null }));
  assert.equal(r.status, 401);
  assert.deepEqual(d, { no_api_key: 1 });
});
caso('EL · clave equivocada → 401 y authRejected, sin violación', async () => {
  const a = await statsEl();
  const { r, d } = await nuevas(statsEl, () => el('GET', `/v1/convai/agents/${PZ.EL_VERIFY_AGENT_ID}`, { key: `xi_${hex(24)}` }));
  assert.equal(r.status, 401);
  assert.deepEqual(d, {});
  assert.equal((await statsEl()).authRejected, a.authRejected + 1);
});
caso('EL · Authorization junto a la clave → 200 y violación authorization_header_present', async () => {
  const { r, d } = await nuevas(statsEl, () => el('GET', `/v1/convai/agents/${PZ.EL_VERIFY_AGENT_ID}`, { headers: { Authorization: `Bearer ${PZ.EL_API_KEY}` } }));
  assert.equal(r.status, 200);
  assert.deepEqual(d, { authorization_header_present: 1 });
});
caso('EL · clave en la consulta y consulta no listada → api_key_in_query y unexpected_query:foo', async () => {
  const { d } = await nuevas(statsEl, () => el('GET', `/v1/convai/agents/${PZ.EL_VERIFY_AGENT_ID}?xi-api-key=x&foo=1`));
  assert.deepEqual(d, { api_key_in_query: 1, 'unexpected_query:foo': 1 });
});
caso('EL · GET del agente de verificación como el adaptador del H6: 200, ulaw_8000 en tts y asr, 0 violaciones', async () => {
  const { r, d } = await nuevas(statsEl, () => el('GET', `/v1/convai/agents/${PZ.EL_VERIFY_AGENT_ID}`, { headers: { Accept: 'application/json' } }));
  assert.equal(r.status, 200);
  assert.equal(r.json.agent_id, PZ.EL_VERIFY_AGENT_ID);
  assert.equal(r.json.conversation_config.tts.agent_output_audio_format, 'ulaw_8000');
  assert.equal(r.json.conversation_config.asr.user_input_audio_format, 'ulaw_8000');
  assert.deepEqual(d, {});
});

// ══ B. Herramientas y agentes ══════════════════════════════════════════════════════════════════
const ESTADO = {};
caso('EL · crear las herramientas compiladas (POST /v1/convai/tools) y el agente con sus tool_ids → ids con forma, 0 violaciones', async () => {
  const { r, d } = await nuevas(statsEl, async () => {
    ESTADO.herramientas = await crearHerramientas();
    return el('POST', '/v1/convai/agents/create', { body: agenteCompilado(ESTADO.herramientas) });
  });
  assert.ok(ESTADO.herramientas.every((id) => /^tool_[0-9a-z]{26}$/.test(id)));
  assert.equal(r.status, 200);
  assert.match(r.json.agent_id, /^agent_[0-9a-z]{26}$/);
  assert.deepEqual(d, {});
  ESTADO.agente = r.json.agent_id;
});
caso('EL · leer el agente y sus herramientas (lo que hace describeAgent): lo compilado intacto y valores por defecto fuera de la proyección', async () => {
  const r = await el('GET', `/v1/convai/agents/${ESTADO.agente}`);
  const c = agenteCompilado(ESTADO.herramientas);
  assert.deepEqual(r.json.conversation_config.agent, c.conversation_config.agent);
  assert.deepEqual(r.json.platform_settings.call_limits, c.platform_settings.call_limits);
  assert.deepEqual(r.json.platform_settings.workspace_overrides, c.platform_settings.workspace_overrides);
  assert.equal(r.json.conversation_config.turn.turn_timeout, 7);
  const t = await el('GET', `/v1/convai/tools/${ESTADO.herramientas[0]}`);
  assert.equal(t.status, 200);
  assert.deepEqual(t.json.tool_config, herramientaCompilada('consultar_horarios'));
  assert.equal((await el('GET', '/v1/convai/tools/tool_noexiste')).status, 404);
});
caso('EL · herramientas que el compilador nunca debería producir → 6 violaciones, una por herramienta', async () => {
  const malas = [
    herramientaCompilada('cobrar', {}),
    herramientaCompilada('consultar_planes', { api_schema: { request_headers: { Authorization: `Bearer ${hex(8)}` } } }),
    (() => { const t = herramientaCompilada('consultar_planes'); t.api_schema.request_body_schema.properties.conversation_id = { type: 'string', description: 'el id' }; return t; })(),
    herramientaCompilada('consultar_planes', { api_schema: { method: 'GET' } }),
    { type: 'client', name: 'abrir_pagina', description: 'x' },
  ];
  const sinAuth = herramientaCompilada('consultar_planes');
  delete sinAuth.api_schema.request_headers;
  malas.push(sinAuth);
  const { d } = await nuevas(statsEl, async () => { for (const t of malas) await el('POST', '/v1/convai/tools', { body: { tool_config: t } }); });
  assert.deepEqual(d, { tool_outside_catalog: 1, tool_secret_inline: 1, tool_call_ids_llm_filled: 1, tool_method_not_post: 1, tool_type_not_allowed: 1, tool_auth_missing: 1 });
});
caso('EL · agente con ráfaga, sin end_call, con una de sistema fuera del catálogo y un tool_id inventado → 4 violaciones', async () => {
  const b = agenteCompilado([...ESTADO.herramientas, 'tool_inventada'], { platform_settings: { call_limits: { bursting_enabled: true } } });
  delete b.conversation_config.agent.prompt.built_in_tools.end_call;
  b.conversation_config.agent.prompt.built_in_tools.language_detection = { type: 'system', name: 'language_detection' };
  const { r, d } = await nuevas(statsEl, () => el('POST', '/v1/convai/agents/create', { body: b }));
  assert.equal(r.status, 200);
  assert.deepEqual(d, { bursting_enabled: 1, system_tool_outside_catalog: 1, tool_id_unknown: 1, end_call_missing: 1 });
});
caso('EL · transferencia ciega con 4 destinos y caller_authorized = «si» por defecto → 3 violaciones', async () => {
  const b = agenteCompilado(ESTADO.herramientas);
  const destino = (n) => ({ transfer_destination: { type: 'phone', phone_number: `+57601000010${n}` }, transfer_type: 'blind' });
  b.conversation_config.agent.prompt.built_in_tools.transfer_to_number.params.transfers = [destino('1'), destino('2'), destino('3'), destino('4')];
  b.conversation_config.agent.dynamic_variables.dynamic_variable_placeholders.caller_authorized = 'si';
  const { d } = await nuevas(statsEl, () => el('POST', '/v1/convai/agents/create', { body: b }));
  assert.deepEqual(d, { transfer_not_conference: 1, too_many_transfer_destinations: 1, caller_authorized_default: 1 });
});
caso('EL · webhook de iniciación del agente en otra ruta y con el secreto en claro → 2 violaciones; audio guardado y retención ilimitada → 2', async () => {
  const b = agenteCompilado(ESTADO.herramientas, { platform_settings: { workspace_overrides: { conversation_initiation_client_data_webhook: { url: `${BASE_CANONICA}/api/otra`, request_headers: { 'X-Voice-Initiation-Secret': hex(12) } } } } });
  const a = await nuevas(statsEl, () => el('POST', '/v1/convai/agents/create', { body: b }));
  assert.deepEqual(a.d, { initiation_webhook_url: 1, initiation_secret_inline: 1 });
  const p = agenteCompilado(ESTADO.herramientas, { platform_settings: { privacy: { record_voice: true, retention_days: -1 } } });
  const b2 = await nuevas(statsEl, () => el('POST', '/v1/convai/agents/create', { body: p }));
  assert.deepEqual(b2.d, { audio_retention_enabled: 1, retention_over_limit: 1 });
});
caso('EL · max_duration_seconds fuera de 60–7200 → 422 como ElevenLabs, sin violación', async () => {
  const { r, d } = await nuevas(statsEl, () => el('POST', '/v1/convai/agents/create', { body: agenteCompilado(ESTADO.herramientas, { conversation_config: { conversation: { max_duration_seconds: 30 } } }) }));
  assert.equal(r.status, 422);
  assert.deepEqual(d, {});
});
caso('EL · listar agentes: forma {agents, has_more, next_cursor}, paginación por cursor; page_size=0 → 422', async () => {
  const p1 = await el('GET', '/v1/convai/agents?page_size=1');
  assert.equal(p1.json.agents.length, 1);
  assert.equal(p1.json.has_more, true);
  const p2 = await el('GET', `/v1/convai/agents?page_size=1&cursor=${p1.json.next_cursor}`);
  assert.equal(p2.status, 200);
  assert.notEqual(p2.json.agents[0].agent_id, p1.json.agents[0].agent_id);
  assert.equal((await el('GET', '/v1/convai/agents?page_size=0')).status, 422);
});
caso('EL · deriva «en el panel»: /__drift cambia el first_message del agente y la URL de una herramienta, y los GET lo enseñan', async () => {
  const a = await statsEl();
  assert.equal((await ctl(BASE_EL, `/__drift?agent_id=${ESTADO.agente}&path=conversation_config.agent.first_message&value=${encodeURIComponent('"Hola, soy otro"')}`)).ok, true);
  assert.equal((await el('GET', `/v1/convai/agents/${ESTADO.agente}`)).json.conversation_config.agent.first_message, 'Hola, soy otro');
  assert.equal((await ctl(BASE_EL, `/__drift?tool_id=${ESTADO.herramientas[1]}&path=api_schema.url&value=${encodeURIComponent('"https://otro.invalid/x"')}`)).ok, true);
  assert.equal((await el('GET', `/v1/convai/tools/${ESTADO.herramientas[1]}`)).json.tool_config.api_schema.url, 'https://otro.invalid/x');
  assert.equal((await statsEl()).drifts, a.drifts + 2);
  await ctl(BASE_EL, `/__drift?agent_id=${ESTADO.agente}&path=conversation_config.agent.first_message&value=${encodeURIComponent(JSON.stringify(EL.AVISO))}`);
  await ctl(BASE_EL, `/__drift?tool_id=${ESTADO.herramientas[1]}&path=api_schema.url&value=${encodeURIComponent(JSON.stringify(`${BASE_CANONICA}/api/voice/tools/consultar_planes`))}`);
});
caso('EL · PATCH o DELETE de un agente → 405 y method_not_allowed (D8-7: un agente no se modifica nunca)', async () => {
  const { d } = await nuevas(statsEl, async () => {
    assert.equal((await el('PATCH', `/v1/convai/agents/${ESTADO.agente}`, { body: { name: 'cambiado' } })).status, 405);
    assert.equal((await el('DELETE', `/v1/convai/agents/${ESTADO.agente}`)).status, 405);
  });
  assert.deepEqual(d, { method_not_allowed: 2 });
});

// ══ C. Números ══════════════════════════════════════════════════════════════════════════════════
caso('EL · números: listar (el importado), leer, desconocido → 404; importar (POST) → 405 y violación', async () => {
  const l = await el('GET', '/v1/convai/phone-numbers');
  assert.equal(l.json.length, 1);
  assert.equal(l.json[0].phone_number_id, PZ.EL_PHONE_NUMBER_ID);
  assert.equal((await el('GET', `/v1/convai/phone-numbers/${PZ.EL_PHONE_NUMBER_ID}`)).status, 200);
  assert.equal((await el('GET', '/v1/convai/phone-numbers/phnum_noexiste')).status, 404);
  const { r, d } = await nuevas(statsEl, () => el('POST', '/v1/convai/phone-numbers', { body: { phone_number: '+576010000092', provider: 'twilio' } }));
  assert.equal(r.status, 405);
  assert.deepEqual(d, { method_not_allowed: 1 });
});
caso('EL · asignar el agente al número ({agent_id}); con algo más que agent_id → number_patch_beyond_agent', async () => {
  const a = await el('PATCH', `/v1/convai/phone-numbers/${PZ.EL_PHONE_NUMBER_ID}`, { body: { agent_id: ESTADO.agente } });
  assert.equal(a.json.assigned_agent.agent_id, ESTADO.agente);
  const { d } = await nuevas(statsEl, () => el('PATCH', `/v1/convai/phone-numbers/${PZ.EL_PHONE_NUMBER_ID}`, { body: { agent_id: ESTADO.agente, label: 'otro' } }));
  assert.deepEqual(d, { number_patch_beyond_agent: 1 });
});

// ══ D. Salientes ═══════════════════════════════════════════════════════════════════════════════
caso('EL · saliente conforme → 200 {success, conversation_id, callSid}; la llamada existe en Twilio, creada con la credencial de ElevenLabs', async () => {
  const { r, d } = await nuevas(statsEl, () => el('POST', '/v1/convai/twilio/outbound-call', { body: salienteValida(ESTADO.agente) }));
  assert.equal(r.status, 200);
  assert.equal(r.json.success, true);
  assert.match(r.json.conversation_id, /^conv_[0-9a-z]{26}$/);
  assert.match(r.json.callSid, /^CA[0-9a-f]{32}$/);
  assert.deepEqual(d, {});
  const g = await tw('GET', `${ACC}/Calls/${r.json.callSid}.json`);
  assert.equal(g.status, 200);
  assert.equal(g.json.status, 'queued');
  assert.equal((await ctl(BASE_TW, `/__state?sid=${r.json.callSid}`)).origin, 'elevenlabs_key');
  ESTADO.conv = r.json.conversation_id;
  ESTADO.callSid = r.json.callSid;
});
caso('EL · saliente con grabación, grabación de Twilio, override y variables de más con un teléfono → 5 violaciones', async () => {
  const b = salienteValida(ESTADO.agente, TEL_OTRO, { call_recording_enabled: true, telephony_call_config: { twilio_call_recording_enabled: true }, conversation_initiation_client_data: { conversation_config_override: { agent: { first_message: 'x' } }, dynamic_variables: { nombre: `Llamar al ${TEL_OTRO}` } } });
  const { r, d } = await nuevas(statsEl, () => el('POST', '/v1/convai/twilio/outbound-call', { body: b }));
  assert.equal(r.status, 200);
  assert.deepEqual(d, { recording_enabled: 1, twilio_recording_enabled: 1, config_override: 1, dynamic_variables_beyond_contract: 1, dynamic_variables_personal_data: 1 });
});
caso('EL · saliente mal formada (to_number sin E.164) → 422 y outbound_malformed; agente desconocido → 404 sin violación', async () => {
  const a = await nuevas(statsEl, () => el('POST', '/v1/convai/twilio/outbound-call', { body: salienteValida(ESTADO.agente, '3000000011') }));
  assert.equal(a.r.status, 422);
  assert.deepEqual(a.d, { outbound_malformed: 1 });
  const b = await nuevas(statsEl, () => el('POST', '/v1/convai/twilio/outbound-call', { body: salienteValida('agent_noexiste') }));
  assert.equal(b.r.status, 404);
  assert.deepEqual(b.d, {});
});
caso('EL · escenario success_false: 200 con success=false y sin ids (Laravel tiene que leerlo como rechazo)', async () => {
  await ctl(BASE_EL, `/__scenario?to=${encodeURIComponent('+573000000013')}&outcome=success_false`);
  const r = await el('POST', '/v1/convai/twilio/outbound-call', { body: salienteValida(ESTADO.agente, '+573000000013') });
  assert.equal(r.status, 200);
  assert.equal(r.json.success, false);
  assert.equal(r.json.conversation_id, null);
  await ctl(BASE_EL, '/__scenario?clear=1');
});
caso('EL · fallo inyectado 500 una vez en outbound_call → 500 y la siguiente vuelve a 200 (faultsServed +1)', async () => {
  const a = await statsEl();
  await ctl(BASE_EL, '/__fault?route=outbound_call&status=500&times=1');
  assert.equal((await el('POST', '/v1/convai/twilio/outbound-call', { body: salienteValida(ESTADO.agente) })).status, 500);
  assert.equal((await el('POST', '/v1/convai/twilio/outbound-call', { body: salienteValida(ESTADO.agente) })).status, 200);
  assert.equal((await statsEl()).faultsServed, a.faultsServed + 1);
});
caso('EL · lentitud (delay 1500 ms) con un cliente de 300 ms: el cliente agota el tiempo y NO hay llamada (sin efecto)', async () => {
  const antes = (await statsEl()).state.conversations;
  await ctl(BASE_EL, '/__fault?route=outbound_call&kind=delay&delayMs=1500&times=1');
  const r = await el('POST', '/v1/convai/twilio/outbound-call', { body: salienteValida(ESTADO.agente), timeoutMs: 300 });
  assert.equal(r.status, 'timeout');
  await espera(1700);
  assert.equal((await statsEl()).state.conversations, antes);
  const ult = (await ctl(BASE_EL, '/__requests?since=0')).entries.filter((x) => x.kind === 'request').at(-1);
  assert.equal(ult.status, 'client_gone');
});
caso('EL · hang_after_effect: el cliente agota el tiempo pero la llamada SÍ existe en ElevenLabs y en Twilio (el ambiguo)', async () => {
  const antes = (await statsEl()).state.conversations;
  const antesTw = (await statsTw()).state.calls;
  await ctl(BASE_EL, '/__fault?route=outbound_call&kind=hang_after_effect&times=1');
  const r = await el('POST', '/v1/convai/twilio/outbound-call', { body: salienteValida(ESTADO.agente), timeoutMs: 300 });
  assert.equal(r.status, 'timeout');
  await espera(100);
  assert.equal((await statsEl()).state.conversations, antes + 1);
  assert.equal((await statsTw()).state.calls, antesTw + 1);
});
caso('EL · agent_concurrency_limit = 1: con una activa, la segunda saliente → 429', async () => {
  const id = (await el('POST', '/v1/convai/agents/create', { body: agenteCompilado(ESTADO.herramientas, { platform_settings: { call_limits: { agent_concurrency_limit: 1 } } }) })).json.agent_id;
  assert.equal((await el('POST', '/v1/convai/twilio/outbound-call', { body: salienteValida(id) })).status, 200);
  const r = await el('POST', '/v1/convai/twilio/outbound-call', { body: salienteValida(id) });
  assert.equal(r.status, 429);
  assert.equal(r.json.detail.status, 'concurrency_limit_exceeded');
});

// ══ E. Conversaciones, estado de la llamada y URL firmada ═══════════════════════════════════════
caso('EL · leer la conversación: metadata.phone_call.call_sid = callSid; sin transcripción mientras no termina', async () => {
  const r = await el('GET', `/v1/convai/conversations/${ESTADO.conv}`);
  assert.equal(r.status, 200);
  assert.equal(r.json.metadata.phone_call.call_sid, ESTADO.callSid);
  assert.deepEqual(r.json.transcript, []);
});
caso('EL+TW · contestada y completada (65 s): conversación done y llamada completed con precio −2 min × (0,0377 + 0,0044)', async () => {
  await ctl(BASE_EL, `/__call?conversation_id=${ESTADO.conv}&event=answered`);
  const c = await ctl(BASE_EL, `/__call?conversation_id=${ESTADO.conv}&event=completed&duration=65`);
  assert.equal(c.status, 'done');
  const r = await el('GET', `/v1/convai/conversations/${ESTADO.conv}`);
  assert.equal(r.json.metadata.call_duration_secs, 65);
  const g = await tw('GET', `${ACC}/Calls/${ESTADO.callSid}.json`);
  assert.equal(g.json.status, 'completed');
  assert.equal(g.json.duration, '65');
  assert.equal(g.json.price, '-0.08420');
});
caso('EL+TW · transferencia en conferencia: el tramo al destino es una llamada HIJA (parent_call_sid) con su precio (DF-W4)', async () => {
  const s = await el('POST', '/v1/convai/twilio/outbound-call', { body: salienteValida(ESTADO.agente) });
  await ctl(BASE_EL, `/__call?conversation_id=${s.json.conversation_id}&event=answered`);
  const r = await ctl(BASE_EL, `/__call?conversation_id=${s.json.conversation_id}&event=completed&duration=95&transfer_to=${encodeURIComponent(TEL_FIJO)}&transfer_duration=30`);
  assert.match(r.transferLeg, /^CA[0-9a-f]{32}$/);
  const hija = await tw('GET', `${ACC}/Calls/${r.transferLeg}.json`);
  assert.equal(hija.json.parent_call_sid, s.json.callSid);
  assert.equal(hija.json.to, TEL_FIJO);
  assert.equal(hija.json.status, 'completed');
  assert.equal(hija.json.price, '-0.07440');
});
caso('EL · listar conversaciones por agente; un filtro no listado → unexpected_query:foo', async () => {
  const r = await el('GET', `/v1/convai/conversations?agent_id=${ESTADO.agente}&page_size=100`);
  assert.ok(r.json.conversations.some((x) => x.conversation_id === ESTADO.conv));
  const { d } = await nuevas(statsEl, () => el('GET', '/v1/convai/conversations?foo=1'));
  assert.deepEqual(d, { 'unexpected_query:foo': 1 });
});
caso('EL · DELETE de una conversación: apagado → 403 y violación; encendido para el ensayo 1581 → 200', async () => {
  const a = await nuevas(statsEl, () => el('DELETE', `/v1/convai/conversations/${ESTADO.conv}`));
  assert.equal(a.r.status, 403);
  assert.deepEqual(a.d, { conversation_delete_disabled: 1 });
  const extra = (await el('POST', '/v1/convai/twilio/outbound-call', { body: salienteValida(ESTADO.agente) })).json.conversation_id;
  await ctl(BASE_EL, '/__config?allow_conversation_delete=1');
  assert.equal((await el('DELETE', `/v1/convai/conversations/${extra}`)).status, 200);
  await ctl(BASE_EL, '/__config?allow_conversation_delete=0');
});
caso('EL · URL firmada del laboratorio: ws://127.0.0.1 con conversation_signature; sin agent_id → 422 y agent_id_pattern', async () => {
  const r = await el('GET', `/v1/convai/conversation/get-signed-url?agent_id=${ESTADO.agente}`);
  assert.equal(r.status, 200);
  assert.match(r.json.signed_url, /^ws:\/\/127\.0\.0\.1:\d+\/v1\/convai\/conversation\?agent_id=.+&conversation_signature=[A-Za-z0-9_-]{32}$/);
  const { r: r2, d } = await nuevas(statsEl, () => el('GET', '/v1/convai/conversation/get-signed-url'));
  assert.equal(r2.status, 422);
  assert.deepEqual(d, { agent_id_pattern: 1 });
});

// ══ F. Emisores hacia Laravel ═══════════════════════════════════════════════════════════════════
caso('EL → L · post-llamada válida: cabecera ElevenLabs-Signature verificable sobre el cuerpo CRUDO, centinela y teléfono dentro', async () => {
  reglas.delete(EL.LARAVEL.postCall);
  const n = recibido.length;
  const r = await ctl(BASE_EL, `/__emit/post-call?conversation_id=${ESTADO.conv}&type=post_call_transcription&variant=valid`);
  assert.equal(r.deliveries[0].status, 200);
  const e = recibido[n];
  assert.equal(e.url, EL.LARAVEL.postCall);
  assert.match(e.headers['content-type'], /^application\/json/);
  const m = /^t=(\d+),v0=([0-9a-f]{64})$/.exec(e.headers['elevenlabs-signature']);
  assert.ok(m);
  assert.equal(C.hmacHex(PZ.EL_WEBHOOK_SECRET, `${m[1]}.${e.raw}`), m[2]);
  const b = JSON.parse(e.raw);
  assert.equal(b.data.conversation_id, ESTADO.conv);
  assert.equal(b.data.metadata.phone_call.call_sid, ESTADO.callSid);
  assert.ok(e.raw.includes(PZ.SENTINEL_ELEVENLABS));
  assert.equal(b.data.version_id, null);
});
caso('EL → L · Laravel que acepta (2xx) una firma caducada → violación post_call_bad_signature_accepted', async () => {
  const { d } = await nuevas(statsEl, () => ctl(BASE_EL, `/__emit/post-call?conversation_id=${ESTADO.conv}&variant=expired`));
  assert.deepEqual(d, { post_call_bad_signature_accepted: 1 });
});
caso('EL → L · desactivación automática: 10 entregas buenas con 500 seguidas → laravel_webhook_disabled; la 11.ª no sale', async () => {
  reglas.set(EL.LARAVEL.postCall, () => ({ status: 500, body: { error: 'caído' } }));
  await ctl(BASE_EL, '/__webhook?reset=1');
  const { r, d } = await nuevas(statsEl, () => ctl(BASE_EL, `/__emit/post-call?conversation_id=${ESTADO.conv}&repeat=11`));
  assert.equal(r.deliveries.filter((x) => x.status === 500).length, 10);
  assert.equal(r.deliveries[10].status, 'webhook_disabled');
  assert.deepEqual(d, { laravel_webhook_disabled: 1 });
  reglas.delete(EL.LARAVEL.postCall);
  await ctl(BASE_EL, '/__webhook?reset=1');
});
caso('EL → L · con una entrega buena de hace 2 días, 10 fallos NO desactivan (la regla de los 7 días)', async () => {
  reglas.set(EL.LARAVEL.postCall, () => ({ status: 503, body: {} }));
  await ctl(BASE_EL, '/__webhook?reset=1');
  await ctl(BASE_EL, '/__webhook?last_success_days_ago=2');
  const r = await ctl(BASE_EL, `/__emit/post-call?conversation_id=${ESTADO.conv}&repeat=10`);
  assert.equal(r.webhook.desactivado, false);
  reglas.delete(EL.LARAVEL.postCall);
  await ctl(BASE_EL, '/__webhook?reset=1');
});
caso('EL → L · iniciación conforme: secreto en su cabecera, los 5 campos oficiales; caller_authorized=si → conversación viva', async () => {
  reglas.set(EL.LARAVEL.initiation, () => respuestaIniciacion('si'));
  const n = recibido.length;
  const { r, d } = await nuevas(statsEl, () => ctl(BASE_EL, `/__inbound?from=${encodeURIComponent(TEL_CANARIO)}`));
  assert.deepEqual(d, {});
  assert.equal(r.laravel.status, 200);
  assert.equal(r.caller_authorized, 'si');
  assert.equal(r.conversation_status, 'in-progress');
  const e = recibido[n];
  assert.equal(e.headers['x-voice-initiation-secret'], PZ.EL_INITIATION_SECRET);
  assert.deepEqual(Object.keys(JSON.parse(e.raw)).sort(), ['agent_id', 'call_sid', 'called_number', 'caller_id', 'conversation_id']);
  assert.equal((await ctl(BASE_TW, `/__state?sid=${r.call_sid}`)).direction, 'inbound');
  ESTADO.entrante = r.conversation_id;
});
caso('EL → L · respuesta de iniciación con override, clave de más y el teléfono → 3 violaciones', async () => {
  reglas.set(EL.LARAVEL.initiation, () => ({ status: 200, body: { type: 'conversation_initiation_client_data', dynamic_variables: { caller_authorized: 'si', call_ref: crypto.randomUUID(), version_label: `v ${TEL_OTRO}` }, conversation_config_override: { agent: {} } } }));
  const { d } = await nuevas(statsEl, () => ctl(BASE_EL, `/__inbound?from=${encodeURIComponent(TEL_OTRO)}`));
  assert.deepEqual(d, { initiation_response_extra_keys: 1, initiation_response_override: 1, initiation_response_personal_data: 1 });
});
caso('EL → L · Laravel caído en la iniciación: fail_mode=defaults usa el «no» del agente (cuelga corto); abort → no arranca', async () => {
  reglas.set(EL.LARAVEL.initiation, () => ({ status: 500, body: {} }));
  const a = await ctl(BASE_EL, `/__inbound?from=${encodeURIComponent(TEL_OTRO)}&fail_mode=defaults`);
  assert.equal(a.initiation, 'fallback_defaults');
  assert.equal(a.caller_authorized, 'no');
  assert.equal(a.conversation_status, 'done');
  const b = await ctl(BASE_EL, `/__inbound?from=${encodeURIComponent(TEL_OTRO)}&fail_mode=abort`);
  assert.equal(b.initiation, 'aborted');
  assert.equal(b.conversation_status, 'failed');
});
caso('EL → L · Laravel que acepta (200) una iniciación con el secreto malo → initiation_bad_secret_accepted', async () => {
  reglas.set(EL.LARAVEL.initiation, () => respuestaIniciacion('no'));
  const { d } = await nuevas(statsEl, () => ctl(BASE_EL, `/__inbound?from=${encodeURIComponent(TEL_OTRO)}&variant=bad_secret`));
  assert.deepEqual(d, { initiation_bad_secret_accepted: 1 });
  reglas.delete(EL.LARAVEL.initiation);
});
caso('EL → L · herramienta: Bearer del secreto; conversation_id y call_sid rellenados desde el esquema compilado (C-A3)', async () => {
  reglas.set(`${EL.LARAVEL.tools}*`, () => ({ status: 200, body: { horario: 'L-V 05:00-22:00', sede: 'Neiva' } }));
  const n = recibido.length;
  const { r, d } = await nuevas(statsEl, () => ctl(BASE_EL, `/__tool?conversation_id=${ESTADO.entrante}&tool=consultar_horarios&params=${encodeURIComponent('{"tema":"sábados","inventado":"x"}')}`));
  assert.deepEqual(d, {});
  assert.equal(r.bodyFrom, 'schema');
  const e = recibido[n];
  assert.equal(e.url, `${EL.LARAVEL.tools}consultar_horarios`);
  assert.equal(e.headers.authorization, `Bearer ${PZ.EL_TOOL_SECRET}`);
  const b = JSON.parse(e.raw);
  assert.equal(b.conversation_id, ESTADO.entrante);
  assert.match(b.call_sid, /^CA[0-9a-f]{32}$/);
  assert.deepEqual(Object.keys(b).sort(), ['call_sid', 'conversation_id']);   // esquema estricto (DF-E8): lo que no está, no viaja
});
caso('EL → L · herramienta con un teléfono en la respuesta, y una sin Bearer aceptada → 2 violaciones', async () => {
  reglas.set(`${EL.LARAVEL.tools}*`, () => ({ status: 200, body: { destino: TEL_FIJO } }));
  const { d } = await nuevas(statsEl, async () => {
    await ctl(BASE_EL, `/__tool?conversation_id=${ESTADO.entrante}&tool=solicitar_humano`);
    return ctl(BASE_EL, `/__tool?conversation_id=${ESTADO.entrante}&tool=solicitar_humano&variant=no_auth`);
  });
  assert.deepEqual(d, { tool_response_personal_data: 2, tool_bad_auth_accepted: 1 });
  reglas.delete(`${EL.LARAVEL.tools}*`);
});

// ══ G. Twilio ═══════════════════════════════════════════════════════════════════════════════════
caso('TW · rutas no listadas (caller ID, crear un aviso de gasto) → 404 y unknown_route; DELETE → 405', async () => {
  const { d } = await nuevas(statsTw, async () => {
    assert.equal((await tw('GET', `${ACC}/OutgoingCallerIds.json`)).status, 404);
    assert.equal((await tw('POST', `${ACC}/Usage/Triggers.json`, { form: { TriggerValue: '1' } })).status, 404);
    return tw('DELETE', `${ACC}/Calls/${ESTADO.callSid}.json`);
  });
  assert.deepEqual(d, { unknown_route: 2, method_not_allowed: 1 });
});
caso('TW · la cuenta madre en la ruta → account_sid_mismatch', async () => {
  const { r, d } = await nuevas(statsTw, () => tw('GET', `/2010-04-01/Accounts/${PZ.TW_MASTER_SID}/Calls.json`));
  assert.equal(r.status, 404);
  assert.deepEqual(d, { account_sid_mismatch: 1 });
});
caso('TW · credencial maestra y token de firma contra la API → se atienden y son violaciones; la ficha solo lleva auth_token con el token', async () => {
  const { d } = await nuevas(statsTw, async () => {
    assert.equal((await tw('GET', `${ACC}/Calls.json`, { cred: CRED.maestra })).status, 200);
    const f = await tw('GET', `${ACC}.json`, { cred: CRED.firma });
    assert.equal(f.json.auth_token, PZ.TW_SIGNING_TOKEN);
  });
  assert.deepEqual(d, { master_credential_used: 1, signing_token_used_for_api: 1 });
  const l = await tw('GET', `${ACC}.json`);
  assert.equal(l.status, 200);
  assert.equal(l.json.auth_token, undefined);
});
caso('TW · sin Basic → 401 y no_basic_auth; mezclada → credential_mixed; desconocida → 401 sin violación', async () => {
  const { d } = await nuevas(statsTw, async () => {
    assert.equal((await tw('GET', `${ACC}/Calls.json`, { cred: null })).status, 401);
    assert.equal((await tw('GET', `${ACC}/Calls.json`, { cred: CRED.mezclada })).status, 401);
    return tw('GET', `${ACC}/Calls.json`, { cred: CRED.desconocida });
  });
  assert.equal(d.no_basic_auth, 1);
  assert.equal(d.credential_mixed, 1);
  assert.equal(Object.keys(d).length, 2);
});
caso('TW · Laravel creando una llamada → 401 y call_create_by_laravel (opción A: solo la crea ElevenLabs)', async () => {
  const { r, d } = await nuevas(statsTw, () => tw('POST', `${ACC}/Calls.json`, { form: { To: TEL_CANARIO, From: PZ.VOICE_NUMBER, Twiml: '<Response/>' } }));
  assert.equal(r.status, 401);
  assert.deepEqual(d, { call_create_by_laravel: 1 });
});
caso('TW · colgar (Status=completed): en curso → completed; otra vez → 200 idempotente; sonando → canceled', async () => {
  const s = await el('POST', '/v1/convai/twilio/outbound-call', { body: salienteValida(ESTADO.agente) });
  const conv = s.json.conversation_id;
  await ctl(BASE_EL, `/__call?conversation_id=${conv}&event=answered`);
  const { r, d } = await nuevas(statsTw, () => tw('POST', `${ACC}/Calls/${s.json.callSid}.json`, { form: { Status: 'completed' } }));
  assert.deepEqual(d, {});
  assert.equal(r.status, 200);
  assert.equal(r.json.status, 'completed');
  const otra = await tw('POST', `${ACC}/Calls/${s.json.callSid}.json`, { form: { Status: 'completed' } });
  assert.equal(otra.status, 200);
  assert.equal(otra.json.status, 'completed');
  const s2 = await el('POST', '/v1/convai/twilio/outbound-call', { body: salienteValida(ESTADO.agente) });
  await ctl(BASE_EL, `/__call?conversation_id=${s2.json.conversation_id}&event=ringing`);
  assert.equal((await tw('POST', `${ACC}/Calls/${s2.json.callSid}.json`, { form: { Status: 'completed' } })).json.status, 'canceled');
});
caso('TW · Laravel actualizando una llamada con Url (redirigirla) → call_update_beyond_hangup', async () => {
  const { d } = await nuevas(statsTw, () => tw('POST', `${ACC}/Calls/${ESTADO.callSid}.json`, { form: { Url: 'https://evil.example.invalid/twiml' } }));
  assert.deepEqual(d, { call_update_beyond_hangup: 1 });
});
caso('TW · listar llamadas: PageSize=1 da next_page_uri con PageToken; seguirlo → 200; token falso → 400 y page_token_forged; To= → phone_in_query:To', async () => {
  const hoy = new Date().toISOString().slice(0, 10);
  const p1 = await tw('GET', `${ACC}/Calls.json?PageSize=1&${encodeURIComponent('StartTime>')}=${hoy}`);
  assert.equal(p1.status, 200);
  assert.equal(p1.json.calls.length, 1);
  const sig = new URL(p1.json.next_page_uri, 'http://x');
  assert.equal(sig.searchParams.get('Page'), '1');
  const p2 = await tw('GET', `${ACC}/Calls.json?PageSize=1&Page=1&PageToken=${sig.searchParams.get('PageToken')}&${encodeURIComponent('StartTime>')}=${hoy}`);
  assert.equal(p2.status, 200);
  assert.notEqual(p2.json.calls[0].sid, p1.json.calls[0].sid);
  const { r, d } = await nuevas(statsTw, () => tw('GET', `${ACC}/Calls.json?PageSize=1&Page=1&PageToken=PA${hex(16)}`));
  assert.equal(r.status, 400);
  assert.deepEqual(d, { page_token_forged: 1 });
  const t = await nuevas(statsTw, () => tw('GET', `${ACC}/Calls.json?To=${encodeURIComponent(TEL_CANARIO)}`));
  assert.deepEqual(t.d, { 'phone_in_query:To': 1 });
});
caso('TW · números: listar y leer con la key de Laravel → 200; cambiarlo → 401 y number_update_by_laravel', async () => {
  const l = await tw('GET', `${ACC}/IncomingPhoneNumbers.json`);
  assert.equal(l.json.incoming_phone_numbers[0].sid, PZ.TW_VOICE_NUMBER_SID);
  assert.equal(l.json.incoming_phone_numbers[0].capabilities.voice, true);
  assert.equal((await tw('GET', `${ACC}/IncomingPhoneNumbers/${PZ.TW_VOICE_NUMBER_SID}.json`)).status, 200);
  const { r, d } = await nuevas(statsTw, () => tw('POST', `${ACC}/IncomingPhoneNumbers/${PZ.TW_VOICE_NUMBER_SID}.json`, { form: { VoiceUrl: 'https://evil.example.invalid/' } }));
  assert.equal(r.status, 401);
  assert.deepEqual(d, { number_update_by_laravel: 1 });
});
caso('TW · uso de hoy: totalprice = la suma de los precios de las llamadas completadas (y count de calls)', async () => {
  const lista = (await tw('GET', `${ACC}/Calls.json?PageSize=1000&Status=completed`)).json.calls;
  const suma = lista.reduce((s, c) => s + Math.abs(Number(c.price)), 0);
  const u = await tw('GET', `${ACC}/Usage/Records/Today.json?Category=totalprice`);
  assert.equal(u.status, 200);
  assert.equal(u.json.usage_records.length, 1);
  assert.equal(u.json.usage_records[0].price, suma.toFixed(5));
  const k = await tw('GET', `${ACC}/Usage/Records/Today.json?Category=calls`);
  assert.equal(Number(k.json.usage_records[0].count), lista.length);
});
caso('TW · fallo 503 inyectado en calls_list → 503 y luego 200; llamada sembrada (nadie la pidió) aparece en el listado', async () => {
  await ctl(BASE_TW, '/__fault?route=calls_list&status=503&times=1');
  assert.equal((await tw('GET', `${ACC}/Calls.json`)).status, 503);
  const s = await ctl(BASE_TW, `/__seed-call?direction=outbound-api&to=${encodeURIComponent('+573000000014')}&status=completed&duration=30&created_by=panel`);
  const l = await tw('GET', `${ACC}/Calls.json?PageSize=1000`);
  assert.equal(l.status, 200);
  assert.ok(l.json.calls.some((c) => c.sid === s.sid));
});

// ══ H. Firma de Twilio: vectores oficiales, diferencial y emisor ═══════════════════════════════
const VECTORES_TWILIO = [
  { de: 'documentación «Webhooks security»', token: '12345', url: 'https://mycompany.com/myapp.php?foo=1&bar=2', params: [['CallSid', 'CA1234567890ABCDE'], ['Caller', '+12349013030'], ['Digits', '1234'], ['From', '+12349013030'], ['To', '+18005551212']], firma: '0/KCTR6DLpKmkAf8muzZqo1nDgQ=' },
  { de: 'pruebas de los SDK (RequestValidator)', token: '12345', url: 'https://mycompany.com/myapp.php?foo=1&bar=2', params: [['CallSid', 'CA1234567890ABCDE'], ['Caller', '+14158675309'], ['Digits', '1234'], ['From', '+14158675309'], ['To', '+18005551212']], firma: 'RSOYDt4T1cUTdK1PDd93/VVr8B8=' },
  { de: 'pruebas de los SDK (bodySHA256)', token: '12345', url: 'https://mycompany.com/myapp.php?foo=1&bar=2&bodySHA256=0a1ff7634d9ab3b95db5c9a2dfe9416e41502b283a80c7cf19632632f96e6620', params: [], firma: 'a9nBmqA0ju/hNViExpshrM61xv4=' },
];
for (const v of VECTORES_TWILIO) {
  caso(`TW · firmaTwilio reproduce el vector oficial (${v.de})`, async () => {
    assert.equal(C.firmaTwilio(v.token, v.url, v.params), v.firma);
  });
}
caso('TW · bodySHA256 del vector oficial = sha256 del cuerpo {"property": "value", "boolean": true}', async () => {
  assert.equal(crypto.createHash('sha256').update('{"property": "value", "boolean": true}').digest('hex'), '0a1ff7634d9ab3b95db5c9a2dfe9416e41502b283a80c7cf19632632f96e6620');
});
caso('TW · diferencial contra una implementación en Python: 8 casos (claves repetidas, duplicados, UTF-8, sin parámetros)', async () => {
  const py = spawnSync('python3', ['--version']);
  if (py.status !== 0) throw new Omitido('sin python3');
  const lote = [
    { token: hex(16), url: 'https://api.ironbody-banco.invalid/api/webhooks/voice/twilio/usage-trigger', params: TW.paramsAviso({ sid: PZ.TW_ACCOUNT_SID, utSid: C.sidTwilio('UT') }) },
    { token: hex(16), url: 'https://a.invalid/x?b=2&a=1', params: [['Tag', 'b'], ['Tag', 'a'], ['Tag', 'b']] },
    { token: hex(16), url: 'https://a.invalid/x', params: [['Z', '1'], ['a', '2'], ['A', '3'], ['_', '4']] },
    { token: hex(16), url: 'https://a.invalid/x', params: [['Nombre', 'Álvaro Peña'], ['Ciudad', 'Bogotá D.C.']] },
    { token: hex(16), url: 'https://a.invalid/x?bodySHA256=abc', params: [] },
    { token: hex(16), url: 'http://127.0.0.1:8401/api/webhooks/voice/twilio/usage-trigger', params: [['IdempotencyToken', 'x'], ['CurrentValue', '4.12']] },
    { token: hex(16), url: 'https://a.invalid/x', params: [['K', ''], ['K', 'v']] },
    { token: hex(16), url: 'https://a.invalid/x', params: [['AccountSid', 'AC1'], ['AccountSidX', 'AC2']] },
  ];
  const f = path.join(TMP, 'twilio-diferencial.json');
  fs.writeFileSync(f, JSON.stringify(lote), { mode: 0o600 });
  const codigo = [
    'import sys, json, hmac, hashlib, base64',
    "for c in json.load(open(sys.argv[1], encoding='utf-8')):",
    "    s = c['url']; claves = {}",
    "    for k, v in c['params']: claves.setdefault(k, set()).add(v)",
    "    for k in sorted(claves, key=lambda x: x.encode('utf-8')):",
    "        for v in sorted(claves[k], key=lambda x: x.encode('utf-8')): s += k + v",
    "    print(base64.b64encode(hmac.new(c['token'].encode('utf-8'), s.encode('utf-8'), hashlib.sha1).digest()).decode())",
  ].join('\n');
  const r = spawnSync('python3', ['-I', '-c', codigo, f], { encoding: 'utf8' });
  assert.equal(r.status, 0, r.stderr);
  assert.deepEqual(r.stdout.trim().split('\n'), lote.map((c) => C.firmaTwilio(c.token, c.url, c.params)));
});
caso('TW → L · aviso de gasto válido: firma sobre la BASE CANÓNICA https (no la URL real) con el token de la subcuenta', async () => {
  reglas.set(TW.LARAVEL_AVISOS, () => ({ status: 200, body: { ok: true } }));
  const n = recibido.length;
  const r = await ctl(BASE_TW, '/__emit/usage-trigger?variant=valid');
  assert.equal(r.deliveries[0].status, 200);
  const e = recibido[n];
  const pares = [...new URLSearchParams(e.raw).entries()];
  assert.equal(e.headers['x-twilio-signature'], C.firmaTwilio(PZ.TW_SIGNING_TOKEN, `${PZ.TW_SIGN_BASE}${e.url}`, pares));
  assert.notEqual(e.headers['x-twilio-signature'], C.firmaTwilio(PZ.TW_SIGNING_TOKEN, `${BASE_L}${e.url}`, pares));
  assert.deepEqual(pares.map(([k]) => k), ['AccountSid', 'UsageTriggerSid', 'DateFired', 'Recurring', 'UsageCategory', 'TriggerBy', 'TriggerValue', 'CurrentValue', 'UsageRecordUri', 'IdempotencyToken']);
});
caso('TW → L · replay: dos entregas idénticas con el mismo IdempotencyToken; query_order llega con la consulta intacta (b=2&a=1)', async () => {
  const n = recibido.length;
  await ctl(BASE_TW, '/__emit/usage-trigger?variant=replay');
  assert.equal(recibido[n].raw, recibido[n + 1].raw);
  assert.equal(recibido[n].headers['x-twilio-signature'], recibido[n + 1].headers['x-twilio-signature']);
  await ctl(BASE_TW, '/__emit/usage-trigger?variant=query_order');
  const e = recibido[n + 2];
  assert.equal(e.url, `${TW.LARAVEL_AVISOS}?b=2&a=1`);
  assert.equal(e.headers['x-twilio-signature'], C.firmaTwilio(PZ.TW_SIGNING_TOKEN, `${PZ.TW_SIGN_BASE}${e.url}`, [...new URLSearchParams(e.raw).entries()]));
});
caso('TW → L · un Laravel que lo acepta todo: las 8 variantes «rejected» dan 8 violaciones usage_trigger_bad_signature_accepted', async () => {
  const malas = Object.entries(TW.VARIANTES_AVISO).filter(([, v]) => v.expected === 'rejected').map(([k]) => k);
  assert.equal(malas.length, 8);
  const { d } = await nuevas(statsTw, async () => { for (const m of malas) await ctl(BASE_TW, `/__emit/usage-trigger?variant=${m}`); });
  assert.deepEqual(d, { usage_trigger_bad_signature_accepted: 8 });
  reglas.delete(TW.LARAVEL_AVISOS);
});

// ══ I. Oráculo PHP del H6 para la firma de ElevenLabs (las 21 variantes) ═══════════════════════
caso('EL · las 21 variantes del emisor, verificadas por la clase REAL del H6 (ElevenLabsWebhookSignature::verify)', async () => {
  const auto = path.join(BACKEND, 'vendor', 'autoload.php');
  if (!fs.existsSync(auto)) throw new Omitido('sin backend/vendor');
  const now = C.ahoraS();
  const lote = [];
  for (const variant of Object.keys(EL.VARIANTS)) {
    const w = await ctl(BASE_EL, `/__post-call?variant=${variant}&now=${now}`);
    lote.push({ variant, rawBody: w.rawBody, header: w.header, now, expected: w.expected });
  }
  const f = path.join(TMP, 'el-variantes.json');
  fs.writeFileSync(f, JSON.stringify(lote.map((x) => ({ ...x, secret: PZ.EL_WEBHOOK_SECRET }))), { mode: 0o600 });
  const codigo = 'require $argv[1]; foreach (json_decode(file_get_contents($argv[2]), true) as $c) { echo App\\Services\\Voice\\ElevenLabs\\ElevenLabsWebhookSignature::verify($c["rawBody"], $c["header"], $c["secret"], $c["now"]), "\\n"; }';
  const r = spawnSync('php', ['-d', 'display_errors=stderr', '-r', codigo, auto, f], { encoding: 'utf8' });
  assert.equal(r.status, 0, r.stderr.slice(0, 300));
  const dado = r.stdout.trim().split('\n');
  const fallan = lote.filter((x, i) => dado[i] !== x.expected).map((x, i) => `${x.variant}: ${dado[i]} ≠ ${x.expected}`);
  assert.equal(lote.length, 21);
  assert.deepEqual(fallan, []);
});

// ══ T. Contraste con los fixtures de TEST8-a (tests/fixtures/voice/**), si existen ══════════════
const FIX = path.join(BACKEND, 'tests', 'fixtures', 'voice');
const fixture = (nombre) => {
  const f = path.join(FIX, nombre);
  if (!fs.existsSync(f)) throw new Omitido(`sin ${nombre} (TEST8-a)`);
  return JSON.parse(fs.readFileSync(f, 'utf8'));
};
const paresDe = (p) => (Array.isArray(p) ? p.map((x) => (Array.isArray(x) ? x : [x.key ?? x.name, x.value])) : Object.entries(p ?? {}).flatMap(([k, v]) => (Array.isArray(v) ? v.map((x) => [k, x]) : [[k, v]])));
caso('TEST8-a · twilio-signature-vectors.json: firmaTwilio reproduce TODOS los vectores de cálculo (los 6 oficiales incluidos)', async () => {
  const d = fixture('twilio-signature-vectors.json');
  assert.ok(d.compute.length >= 6);
  const distintos = d.compute.filter((c) => C.firmaTwilio(c.token, c.url, paresDe(c.params)) !== (typeof c.expected === 'string' ? c.expected : c.expected.signature)).map((c) => c.name);
  assert.deepEqual(distintos, []);
});
caso('TEST8-a · elevenlabs-webhook-vectors.json: el firmador del simulador da la MISMA cabecera en todos los válidos canónicos', async () => {
  const d = fixture('elevenlabs-webhook-vectors.json');
  const validos = d.verify.filter((c) => c.expected?.signatureResult === 'ok' && /^t=\d{1,12},v0=[0-9a-f]{64}$/.test(c.header ?? ''));
  assert.ok(validos.length >= 1);
  const distintos = validos.filter((c) => C.firmaElevenLabs(c.rawBody, c.secret, Number(/^t=(\d+)/.exec(c.header)[1])) !== c.header).map((c) => c.name);
  assert.deepEqual(distintos, []);
});
caso('TEST8-a · formas: post-llamada (data y metadata), iniciación (cabecera y campos), herramienta y aviso de gasto, clave por clave', async () => {
  const b = fixture('elevenlabs-webhook-bodies.json');
  const t = fixture('twilio-webhook-bodies.json');
  const suyo = JSON.parse(b.postCall.find((x) => JSON.parse(x.rawBody).type === 'post_call_transcription').rawBody);
  const mio = JSON.parse(EL.postCall({ secret: 'x'.repeat(16) }).rawBody);
  assert.deepEqual(Object.keys(mio.data).sort(), Object.keys(suyo.data).sort());
  assert.deepEqual(Object.keys(mio.data.metadata).sort(), Object.keys(suyo.data.metadata).sort());
  const ini = b.initiation[0];
  assert.deepEqual(Object.keys(ini.headers).map((h) => h.toLowerCase()), [PZ.EL_INITIATION_HEADER.toLowerCase()]);
  assert.deepEqual(Object.keys(JSON.parse(ini.rawBody)).sort(), ['agent_id', 'call_sid', 'called_number', 'caller_id', 'conversation_id']);
  const herr = b.tools.find((x) => x.tool === 'consultar_horarios');
  assert.deepEqual(Object.keys(JSON.parse(herr.rawBody)).sort(), ['call_sid', 'conversation_id']);
  assert.deepEqual(paresDe(t.usageTrigger[0].params).map(([k]) => k), TW.paramsAviso({ sid: PZ.TW_ACCOUNT_SID, utSid: C.sidTwilio('UT') }).map(([k]) => k));
  assert.deepEqual(Object.keys(t.usageTrigger[0].headers), ['X-Twilio-Signature']);
});

// ══ J. Valla de red ═════════════════════════════════════════════════════════════════════════════
caso('VALLA · proxy http a un host de fuera → 403 y su host anotado; CONNECT (https) → 403 anotado; nada pasa', async () => {
  const u = new URL(BASE_VALLA);
  const proxyGet = () => new Promise((resolve) => {
    const q = http.request({ host: u.hostname, port: u.port, method: 'GET', path: 'http://api.elevenlabs.io/v1/convai/agents', headers: { Host: 'api.elevenlabs.io' } }, (res) => { res.resume(); res.on('end', () => resolve(res.statusCode)); });
    q.on('error', () => resolve('error'));
    q.end();
  });
  const connect = () => new Promise((resolve) => {
    const q = http.request({ host: u.hostname, port: u.port, method: 'CONNECT', path: 'api.twilio.com:443' });
    q.on('connect', (res, socket) => { socket.destroy(); resolve(res.statusCode); });
    q.on('error', () => resolve('error'));
    q.end();
  });
  assert.equal(await proxyGet(), 403);
  assert.equal(await connect(), 403);
  const s = await ctl(BASE_VALLA, '/__stats');
  assert.equal(s.byHost['api.elevenlabs.io'], 1);
  assert.equal(s.byHost['api.twilio.com:443'], 1);
  assert.equal(s.localThroughFence, 0);
});
caso('VALLA · tráfico local que entra POR la valla se marca local_through_fence (no_proxy no respetado)', async () => {
  const u = new URL(BASE_VALLA);
  await new Promise((resolve) => {
    const q = http.request({ host: u.hostname, port: u.port, method: 'GET', path: `${BASE_EL}/__stats` }, (res) => { res.resume(); res.on('end', resolve); });
    q.on('error', resolve);
    q.end();
  });
  assert.equal((await ctl(BASE_VALLA, '/__stats')).localThroughFence, 1);
});

// ══ K. Registro, control y arranque ════════════════════════════════════════════════════════════
caso('CTL · un POST al control → 405 y violación control_method (en los dos simuladores)', async () => {
  const a = await nuevas(statsEl, () => pedir(BASE_EL, 'POST', '/__stats', { body: '{}' }));
  const b = await nuevas(statsTw, () => pedir(BASE_TW, 'POST', '/__reset?state=1'));
  assert.equal(a.r.status, 405);
  assert.deepEqual(a.d, { control_method: 1 });
  assert.deepEqual(b.d, { control_method: 1 });
});
caso('CTL · /__reset?state=1 vacía el estado del dominio y NO las violaciones', async () => {
  const a = await statsEl();
  const b = await statsTw();
  await ctl(BASE_EL, '/__reset?state=1');
  await ctl(BASE_TW, '/__reset?state=1');
  const a2 = await statsEl();
  const b2 = await statsTw();
  assert.equal(a2.state.conversations, 0);
  assert.equal(a2.state.agents, 1);
  assert.equal(b2.state.calls, 0);
  assert.equal(a2.violations, a.violations);
  assert.equal(b2.violations, b.violations);
});
caso('ARRANQUE · se niegan: piezas en 644, LARAVEL_BASE fuera de 127.0.0.1, --host 0.0.0.0 (código 2)', async () => {
  const p644 = escribirPiezas('piezas-644.conf', { LARAVEL_BASE: BASE_L });
  fs.chmodSync(p644, 0o644);
  const fuera = escribirPiezas('piezas-fuera.conf', { LARAVEL_BASE: 'https://api.ironbodyneiva.cloud' });
  const bien = escribirPiezas('piezas-bien.conf', { LARAVEL_BASE: BASE_L });
  const corre = (script, cfg, extra = []) => spawnSync(process.execPath, [path.join(AQUI, script), '--config', cfg, '--log', path.join(TMP, 'x.jsonl'), '--port', '0', ...extra], { encoding: 'utf8', timeout: 5000 });
  const r1 = corre('sim-elevenlabs.cjs', p644);
  const r2 = corre('sim-twilio.cjs', fuera);
  const r3 = corre('sim-elevenlabs.cjs', bien, ['--host', '0.0.0.0']);
  assert.equal(r1.status, 2);
  assert.match(r1.stderr, /legible por otros/);
  assert.equal(r2.status, 2);
  assert.match(r2.stderr, /127\.0\.0\.1/);
  assert.equal(r3.status, 2);
  assert.match(r3.stderr, /solo escucha en 127\.0\.0\.1/);
});
caso('HIGIENE · los registros JSONL (dos simuladores y valla) no llevan secretos, teléfonos, centinelas ni cuerpos; ficheros en 600', async () => {
  await espera(200);
  const ficheros = ['sim-elevenlabs.jsonl', 'sim-twilio.jsonl', 'valla.jsonl'].map((f) => path.join(TMP, f));
  const texto = ficheros.map((f) => fs.readFileSync(f, 'utf8')).join('\n');
  assert.ok(texto.length > 1000);
  for (const f of ficheros) assert.equal(fs.statSync(f).mode & 0o777, 0o600);
  const prohibidos = [...SECRETOS, PZ.SENTINEL_ELEVENLABS, PZ.SENTINEL_TWILIO, PZ.VOICE_NUMBER, PZ.WHATSAPP_NUMBER, TEL_CANARIO, TEL_OTRO, TEL_FIJO, '3000000011', '3000000012', '3000000019', '6010000090', '6010000091', '3000000050', EL.AVISO.slice(0, 40)];
  const encontrados = prohibidos.filter((x) => texto.includes(x)).map((x) => `${x.slice(0, 6)}…`);
  assert.deepEqual(encontrados, []);
});

// ── Ejecución ──────────────────────────────────────────────────────────────────────────────────
(async () => {
  let ok = 0;
  let ko = 0;
  let om = 0;
  try {
    await new Promise((r) => receptor.listen(0, '127.0.0.1', r));
    BASE_L = `http://127.0.0.1:${receptor.address().port}`;
    const valla = await arrancar('valla.cjs', ['--log', path.join(TMP, 'valla.jsonl'), '--port', '0']);
    BASE_VALLA = `http://127.0.0.1:${valla.port}`;
    const t = await arrancar('sim-twilio.cjs', ['--config', escribirPiezas('piezas-tw.conf', { LARAVEL_BASE: BASE_L }), '--log', path.join(TMP, 'sim-twilio.jsonl'), '--port', '0']);
    BASE_TW = `http://127.0.0.1:${t.port}`;
    const e = await arrancar('sim-elevenlabs.cjs', ['--config', escribirPiezas('piezas-el.conf', { LARAVEL_BASE: BASE_L, EL_TWILIO_BASE: BASE_TW }), '--log', path.join(TMP, 'sim-elevenlabs.jsonl'), '--port', '0']);
    BASE_EL = `http://127.0.0.1:${e.port}`;
    console.log(`# simuladores: ElevenLabs :${e.port} · Twilio :${t.port} · valla :${valla.port} · receptor (Laravel) :${receptor.address().port}`);
    for (const c of casos) {
      try {
        await c.fn();
        ok++;
        console.log(`OK       ${c.nombre}`);
      } catch (err) {
        if (err instanceof Omitido) {
          om++;
          console.log(`OMITIDO  ${c.nombre}  [${err.message}]`);
        } else {
          ko++;
          console.log(`FALLO    ${c.nombre}  [${String(err.message).split('\n').slice(0, 6).join(' | ').slice(0, 400)}]`);
        }
      }
    }
    const se = await statsEl();
    const st = await statsTw();
    console.log(`# violaciones provocadas a propósito: ElevenLabs ${se.violations} · Twilio ${st.violations} (las pruebas las cuentan una a una)`);
  } catch (err) {
    ko++;
    console.log(`FALLO    arranque  [${err.message}]`);
  } finally {
    for (const p of hijos) p.kill('SIGTERM');
    receptor.close();
    await espera(300);
    fs.rmSync(TMP, { recursive: true, force: true });
  }
  console.log(`${ok} OK · ${ko} FALLO · ${om} OMITIDO`);
  process.exit(ko === 0 ? 0 : 1);
})();
