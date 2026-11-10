#!/usr/bin/env node
'use strict';
/**
 * Simulador local de ElevenLabs (ElevenAgents) para el banco y el E2E del Hito 8 (OPS8-a; contrato H8 D8-1, D8-3,
 * D8-6, D8-7, D8-10 a D8-16 y D8-23; auditoría §d.1). Hereda la forma del simulador del H6 (CRM,
 * `e2e/ultron-studio/mock-elevenlabs.cjs`): mismo emisor de firmas con sus 21 variantes, registro JSONL y control GET.
 *
 * LADO PROVEEDOR (Laravel → simulador, cabecera `xi-api-key`). Lista CERRADA; cualquier otra ruta o verbo es una
 * VIOLACIÓN. `[v]` = verificada (auditoría §d.1, el adaptador del H6 o B8-a en la referencia oficial el 03-11-2026);
 * `[pc]` = por confirmar (D8-3). Si B8 encuentra otra ruta, se cambia AQUÍ, en `RUTAS`, y en ningún otro sitio.
 *   POST   /v1/convai/tools                                  [v]  crear una herramienta webhook ({tool_config} → {id})
 *   GET    /v1/convai/tools/{tool_id}                        [v]  leerla (la proyección de describeAgent la incluye)
 *   POST   /v1/convai/agents/create                          [v]  crear agente (D8-7: uno nuevo por publicación)
 *   GET    /v1/convai/agents                                 [pc] listar (page_size, cursor, search)
 *   GET    /v1/convai/agents/{agent_id}                      [v]  leer (proyección; añade valores por defecto)
 *   GET    /v1/convai/phone-numbers                          [pc] listar números importados
 *   GET    /v1/convai/phone-numbers/{phone_number_id}        [pc] leer el número
 *   PATCH  /v1/convai/phone-numbers/{phone_number_id}        [v]  asignar o quitar el agente ({agent_id}, nada más)
 *   POST   /v1/convai/twilio/outbound-call                   [v]  saliente (D8-12)
 *   GET    /v1/convai/conversations                          [pc] listar (conciliación de las `ambiguous`)
 *   GET    /v1/convai/conversations/{conversation_id}        [pc] leer
 *   DELETE /v1/convai/conversations/{conversation_id}        [pc] borrar (supresión 1581, D8-17): APAGADA por defecto
 *   GET    /v1/convai/conversation/get-signed-url?agent_id=  [v]  URL firmada del laboratorio hablado (D8-23, ≤ 15 min)
 *   GET    /v1/convai/secrets                                [v]  listar secretos del workspace (OPS8-c, h8-secrets-register.sh)
 *   POST   /v1/convai/secrets                                [v]  crear un secreto ({type: new, name, value})
 *   PATCH  /v1/convai/secrets/{secret_id}                    [v]  cambiar su valor ({type: update, name, value})
 *   GET    /v1/workspace/webhooks                            [v]  listar los webhooks del workspace
 *   POST   /v1/workspace/webhooks                            [v]  crear el webhook HMAC (devuelve el secreto que GENERA ElevenLabs)
 *   ([v] OPS8-c = verificadas por OPS8-c en la referencia oficial el 09-11-2026: secrets/list, create, update; webhooks/list, create.)
 * NO están (violaciones): PATCH o DELETE de un agente (D8-7: un agente no se modifica nunca; B8-a no tiene ninguno),
 * importar números (POST phone-numbers: es del propietario, C-A1), listar o borrar herramientas, BORRAR secretos o
 * webhooks (el registro nunca borra), y cualquier otra.
 * Los EMISORES usan el secreto que cita el agente (`secret_id` de la iniciación y de cada herramienta; el webhook de
 * `workspace_overrides.webhooks.post_call_webhook_id`) si está registrado aquí, como ElevenLabs; si no, el de las piezas.
 *
 * LADO EMISOR (simulador → Laravel, solo a 127.0.0.1), por el control:
 *   /__inbound      una entrante: crea la llamada en el simulador de Twilio y hace el webhook de iniciación (D8-11)
 *                   con el secreto en su cabecera; revisa la respuesta (solo caller_authorized, call_direction =
 *                   entrante, call_ref y version_label, E1-5;
 *                   sin conversation_config_override ni datos personales) y, si Laravel falla, sigue con los valores
 *                   por defecto del agente (`fail_mode=defaults`) o no arranca (`abort`): las dos lecturas de C-A2.
 *   /__tool         una server tool (D8-10) con `Authorization: Bearer`; rellena las variables de sistema según el
 *                   esquema compilado del agente (C-A3) y revisa que la respuesta no lleve teléfonos.
 *   /__emit/post-call  webhook post-llamada firmado (`ElevenLabs-Signature`), tipos y variantes de firma; modela la
 *                   desactivación automática tras 10 fallos seguidos (auditoría §d.1). Con `web=1&agent_id=` es la de
 *                   una conversación WEB del laboratorio hablado (D8-23): sin `metadata.phone_call` ni `call_sid`
 *                   (la URL firmada registra esa conversación; si no la hay, se crea al emitir).
 *   /__post-call    generador PURO (no envía): como el del H6.
 * Y el estado de las llamadas: /__call (sonando, contestada, buzón, completada, comunicando, sin respuesta, fallo),
 * /__scenario (lo que responde la saliente a un destino), /__panel-call (una llamada que Laravel no pidió: R-2),
 * /__drift (un cambio «en el panel»: deriva), /__webhook, /__config, /__state, /__surface, /__reset, /__fault,
 * /__stats y /__requests.
 *
 * Uso: node sim-elevenlabs.cjs --config <piezas en 600> --log <registro.jsonl> --port <n|0>
 * Como módulo (`require`), exporta el firmador, las variantes, los guiones y la superficie sin arrancar nada.
 */
const crypto = require('node:crypto');
const http = require('node:http');
const C = require('./sim-comun.cjs');

const NOMBRE = 'sim-elevenlabs';
const MAX_CUERPO = 1024 * 1024;
const TOLERANCE_SECONDS = 1800;
const FUTURE_SKEW_SECONDS = 300;
const AGENT_ID = /^[A-Za-z0-9_-]{1,64}$/;
const HERRAMIENTAS = ['consultar_planes', 'consultar_horarios', 'solicitar_humano', 'no_volver_a_llamar']; // D8-10
const HERRAMIENTAS_SISTEMA = ['end_call', 'transfer_to_number']; // D8-6
const TYPES = ['post_call_transcription', 'post_call_audio', 'call_initiation_failure'];
const TYPES_NULL = ['unknown_type', 'no_type', 'invalid_json'];

/** Rutas de Laravel que el contrato fija (D8-16). */
const LARAVEL = {
  postCall: '/api/webhooks/voice/elevenlabs/post-call',
  initiation: '/api/webhooks/voice/elevenlabs/initiation',
  tools: '/api/voice/tools/',
};

/** El aviso provisional de P-9 (congelación del lead, 03-11-2026): primer turno del agente en los guiones. */
const AVISO = 'Hola, te habla el asistente virtual de Iron Body Workout, un sistema de inteligencia artificial. Te atiendo para informarte sobre nuestros planes y servicios, y tus datos se tratan según nuestra política de privacidad. Si no quieres recibir más llamadas o prefieres hablar con una persona, dímelo en cualquier momento.';

// ── Superficie ───────────────────────────────────────────────────────────────────────────────────
// `doc`: «B8-a» = comprobada por B8-a en la referencia oficial el 03-11-2026 (docblocks de ElevenLabsAgentDeployer y
// ElevenLabsLabSessionIssuer); el contraste se rehace cuando B8 cierre el paso 2.
const RUTAS = [
  { id: 'tools_create', method: 'POST', re: /^\/v1\/convai\/tools$/, plantilla: '/v1/convai/tools', doc: 'verificada (B8-a)' },
  { id: 'tools_get', method: 'GET', re: /^\/v1\/convai\/tools\/([^/]+)$/, plantilla: '/v1/convai/tools/{tool_id}', doc: 'verificada (B8-a)' },
  { id: 'agents_create', method: 'POST', re: /^\/v1\/convai\/agents\/create$/, plantilla: '/v1/convai/agents/create', doc: 'verificada (B8-a)' },
  { id: 'agents_list', method: 'GET', re: /^\/v1\/convai\/agents$/, plantilla: '/v1/convai/agents', doc: 'por confirmar', query: ['page_size', 'cursor', 'search'] },
  { id: 'agents_get', method: 'GET', re: /^\/v1\/convai\/agents\/([^/]+)$/, plantilla: '/v1/convai/agents/{agent_id}', doc: 'verificada (H6 y B8-a)' },
  { id: 'numbers_list', method: 'GET', re: /^\/v1\/convai\/phone-numbers$/, plantilla: '/v1/convai/phone-numbers', doc: 'por confirmar' },
  { id: 'numbers_get', method: 'GET', re: /^\/v1\/convai\/phone-numbers\/([^/]+)$/, plantilla: '/v1/convai/phone-numbers/{phone_number_id}', doc: 'por confirmar' },
  { id: 'numbers_patch', method: 'PATCH', re: /^\/v1\/convai\/phone-numbers\/([^/]+)$/, plantilla: '/v1/convai/phone-numbers/{phone_number_id}', doc: 'verificada (B8-a)' },
  { id: 'outbound_call', method: 'POST', re: /^\/v1\/convai\/twilio\/outbound-call$/, plantilla: '/v1/convai/twilio/outbound-call', doc: 'verificada (auditoría §d.1 y B8-a)' },
  { id: 'conversations_list', method: 'GET', re: /^\/v1\/convai\/conversations$/, plantilla: '/v1/convai/conversations', doc: 'por confirmar', query: ['agent_id', 'page_size', 'cursor', 'call_start_after_unix', 'call_start_before_unix', 'call_successful'] },
  { id: 'conversations_get', method: 'GET', re: /^\/v1\/convai\/conversations\/([^/]+)$/, plantilla: '/v1/convai/conversations/{conversation_id}', doc: 'por confirmar' },
  { id: 'conversations_delete', method: 'DELETE', re: /^\/v1\/convai\/conversations\/([^/]+)$/, plantilla: '/v1/convai/conversations/{conversation_id}', doc: 'por confirmar (D8-17); apagada por defecto' },
  { id: 'signed_url', method: 'GET', re: /^\/v1\/convai\/conversation\/get-signed-url$/, plantilla: '/v1/convai/conversation/get-signed-url', doc: 'verificada (B8-a)', query: ['agent_id'] },
  { id: 'secrets_list', method: 'GET', re: /^\/v1\/convai\/secrets$/, plantilla: '/v1/convai/secrets', doc: 'verificada (OPS8-c, 09-11-2026)', query: ['page_size', 'cursor', 'search', 'dependency_limit'] },
  { id: 'secrets_create', method: 'POST', re: /^\/v1\/convai\/secrets$/, plantilla: '/v1/convai/secrets', doc: 'verificada (OPS8-c, 09-11-2026)' },
  { id: 'secrets_update', method: 'PATCH', re: /^\/v1\/convai\/secrets\/([^/]+)$/, plantilla: '/v1/convai/secrets/{secret_id}', doc: 'verificada (OPS8-c, 09-11-2026)' },
  { id: 'webhooks_list', method: 'GET', re: /^\/v1\/workspace\/webhooks$/, plantilla: '/v1/workspace/webhooks', doc: 'verificada (OPS8-c, 09-11-2026)', query: ['include_usages'] },
  { id: 'webhooks_create', method: 'POST', re: /^\/v1\/workspace\/webhooks$/, plantilla: '/v1/workspace/webhooks', doc: 'verificada (OPS8-c, 09-11-2026)' },
];

/** Los nombres que usa el registro de OPS8-c (h8-secrets-register.sh); otro nombre es una violación. */
const SECRETOS_REGISTRO = { herramientas: 'ironbody-voz-herramientas', iniciacion: 'ironbody-voz-iniciacion' };
const WEBHOOK_REGISTRO = 'ironbody-voz-post-llamada';

/** Conjunto cerrado de violaciones, con la cláusula que las funda. El cierre de un ensayo exige 0. */
const VIOLACIONES = {
  unknown_route: 'ruta no listada (fila OPS8)',
  method_not_allowed: 'verbo no listado en una ruta conocida (fila OPS8)',
  bad_path_encoding: 'parámetro de ruta mal codificado',
  no_api_key: 'sin xi-api-key',
  authorization_header_present: 'cabecera Authorization hacia ElevenLabs (la clave va SOLO en xi-api-key)',
  api_key_in_query: 'la clave en la consulta',
  'unexpected_query:*': 'parámetro de consulta fuera de la lista de la ruta',
  body_on_get: 'GET con cuerpo',
  body_on_delete: 'DELETE con cuerpo',
  content_type: 'POST o PATCH sin Content-Type application/json',
  agent_id_pattern: 'agent_id que no casa con ^[A-Za-z0-9_-]{1,64}$ (H6)',
  tool_id_pattern: 'tool_id que no casa con ^[A-Za-z0-9_-]{1,64}$',
  bursting_enabled: 'ráfaga encendida (D8-7, D8-15)',
  audio_retention_enabled: 'audio guardado encendido (D8-17, P-8)',
  retention_over_limit: 'retención por encima del techo del banco o ilimitada (P-8: 30 días)',
  overrides_enabled: 'sobrescrituras de conversation_config activadas (D8-11)',
  initiation_webhook_url: 'webhook de iniciación del agente fuera de /api/webhooks/voice/elevenlabs/initiation (D8-11)',
  initiation_secret_inline: 'cabecera del webhook de iniciación con el VALOR en claro y no una referencia secret_id (D8-5)',
  tool_id_unknown: 'tool_ids con un id que el simulador no emitió (las herramientas se crean antes con POST /v1/convai/tools)',
  tool_type_not_allowed: 'herramienta que no es webhook ni de sistema (client, mcp…): fuera del catálogo (D8-6, D8-10)',
  tool_outside_catalog: 'herramienta webhook fuera de /api/voice/tools/{catálogo} (D8-10)',
  tool_method_not_post: 'herramienta webhook con un método distinto de POST (D8-16)',
  tool_auth_missing: 'herramienta webhook sin cabecera Authorization (D8-16: Bearer)',
  tool_secret_inline: 'Authorization de una herramienta con el VALOR en claro y no una referencia secret_id (D8-5)',
  tool_call_ids_llm_filled: 'conversation_id o call_sid que rellena el LLM, no system__conversation_id / system__call_sid (C-A3; DF-E8 de TEST8-a)',
  system_tool_outside_catalog: 'herramienta de sistema distinta de end_call y transfer_to_number (D8-6)',
  end_call_missing: 'falta end_call (D8-6)',
  transfer_not_conference: 'transferencia que no es en conferencia (D8-14)',
  too_many_transfer_destinations: 'más de 3 destinos de transferencia (D8-14)',
  caller_authorized_default: 'el valor por defecto de caller_authorized no es «no» (D8-11, C-A2)',
  number_patch_beyond_agent: 'PATCH de un número con algo más que agent_id (D8-3: assignNumber)',
  outbound_malformed: 'saliente sin agent_id, agent_phone_number_id o to_number válidos',
  recording_enabled: 'call_recording_enabled = true (D8-12, P-7)',
  twilio_recording_enabled: 'telephony_call_config.twilio_call_recording_enabled = true (D8-12, P-7)',
  config_override: 'conversation_config_override en la saliente (D8-11)',
  outbound_dynamic_variables: 'variables dinámicas de la saliente sin caller_authorized = «si» y call_ref uuid (D8-12)',
  outbound_call_direction: 'call_direction de la saliente distinto de «saliente» (E1-5)',
  outbound_version_label: 'version_label de la saliente vacío, de más de 40 caracteres o con controles o llaves (E1-5)',
  dynamic_variables_beyond_contract: 'variables dinámicas de la saliente fuera de {call_direction, call_ref, caller_authorized, version_label} (D8-12, E1-5)',
  dynamic_variables_personal_data: 'un teléfono o un correo en las variables dinámicas (D8-17)',
  outbound_user_id: 'user_id distinto de call_ref (D8-12)',
  conversation_delete_disabled: 'DELETE de una conversación con el borrado apagado (solo el ensayo de la supresión lo enciende)',
  initiation_response_not_json: 'respuesta de la iniciación que no es JSON',
  initiation_response_type: 'type ≠ conversation_initiation_client_data',
  initiation_response_extra_keys: 'claves de primer nivel fuera de {type, dynamic_variables} (D8-11)',
  initiation_response_override: 'conversation_config_override en la respuesta (D8-11)',
  initiation_response_variables: 'dynamic_variables ≠ exactamente {call_direction, call_ref, caller_authorized, version_label} (D8-11, E1-5)',
  initiation_response_call_direction: 'call_direction de la iniciación distinto de «entrante» (E1-5)',
  initiation_response_version_label: 'version_label de la iniciación vacío, de más de 40 caracteres o con controles o llaves (E1-5)',
  initiation_response_caller_authorized: 'caller_authorized fuera de {si, no}',
  initiation_response_call_ref: 'call_ref que no es un uuid opaco',
  initiation_response_personal_data: 'un teléfono o un correo en la respuesta de la iniciación (D8-11)',
  initiation_response_size: 'respuesta de la iniciación de más de 8 KB',
  initiation_bad_secret_accepted: 'Laravel aceptó (200) una iniciación sin el secreto o con otro (D8-16)',
  initiation_oversize_accepted: 'Laravel aceptó (200) una iniciación de más de 8 KB (D8-16)',
  tool_response_personal_data: 'un teléfono o un correo en la respuesta de una herramienta (D8-10)',
  tool_response_size: 'respuesta de una herramienta de más de 8 KB',
  tool_bad_auth_accepted: 'Laravel aceptó (2xx) una herramienta sin Bearer, con otro o con otro esquema (D8-16)',
  tool_outside_catalog_accepted: 'Laravel aceptó (2xx) una herramienta fuera del catálogo cerrado (D8-10)',
  tool_oversize_accepted: 'Laravel aceptó (2xx) una herramienta de más de 8 KB (D8-16)',
  post_call_bad_signature_accepted: 'Laravel aceptó (2xx) un webhook post-llamada con la firma mala, caducada o del futuro (D8-16)',
  laravel_webhook_disabled: 'el webhook post-llamada quedó DESACTIVADO por 10 fallos seguidos (auditoría §d.1, R-9)',
  control_method: 'verbo distinto de GET en el control',
  secret_request_shape: 'secreto sin {type: new|update, name, value} de texto (referencia oficial, 09-11-2026)',
  secret_name_not_ours: 'secreto con un nombre fuera de los del registro (ironbody-voz-herramientas, ironbody-voz-iniciacion)',
  secret_duplicate_name: 'crear un secreto con un nombre que ya existe (el registro mira antes con GET; nunca duplica)',
  secret_tool_not_bearer: 'el secreto de las herramientas no vale «Bearer …» (la cabecera Authorization entera, D8-16)',
  secret_update_name_changed: 'PATCH de un secreto con otro nombre (el registro solo cambia el valor)',
  webhook_request_shape: 'webhook sin {settings: {auth_type, name, webhook_url}}',
  webhook_auth_not_hmac: 'webhook post-llamada que no es HMAC (D8-16: ElevenLabs-Signature)',
  webhook_url_not_post_call: 'webhook cuya URL no es la base canónica + /api/webhooks/voice/elevenlabs/post-call (D8-16)',
  webhook_name_not_ours: 'webhook con un nombre distinto de ironbody-voz-post-llamada',
  webhook_duplicate_name: 'crear un webhook con un nombre que ya existe (el registro mira antes con GET)',
  webhook_custom_headers: 'cabeceras propias en el webhook (el registro no manda ninguna: solo HMAC)',
};

// ── Firma del webhook post-llamada (forma del H6; algoritmo de §I6.4) ───────────────────────────
const VARIANTS = {
  valid: { expected: 'ok' },
  edge_past_ok: { dt: -TOLERANCE_SECONDS, expected: 'ok' },
  expired: { dt: -(TOLERANCE_SECONDS + 1), expected: 'expired' },
  edge_future_ok: { dt: FUTURE_SKEW_SECONDS, expected: 'ok' },
  from_future: { dt: FUTURE_SKEW_SECONDS + 1, expected: 'from_future' },
  mismatch_secret: { otherSecret: true, expected: 'mismatch' },
  mismatch_body: { alterBody: true, expected: 'mismatch' },
  null_header: { header: () => null, expected: 'missing_signature' },
  missing: { header: () => '', expected: 'missing_signature' },
  blank: { header: () => '   ', expected: 'missing_signature' },
  no_t: { header: (t, v0) => `v0=${v0}`, expected: 'malformed_signature' },
  two_t: { header: (t, v0) => `t=${t},t=${t},v0=${v0}`, expected: 'malformed_signature' },
  bad_t: { header: (t, v0) => `t=12ab,v0=${v0}`, expected: 'malformed_signature' },
  t_13_digits: { header: (t, v0) => `t=1${String(t).padStart(12, '0')},v0=${v0}`, expected: 'malformed_signature' },
  no_v0: { header: (t) => `t=${t}`, expected: 'malformed_signature' },
  short_v0: { header: (t, v0) => `t=${t},v0=${v0.slice(1)}`, expected: 'malformed_signature' },
  part_without_eq: { header: (t, v0) => `t=${t},v0=${v0},garbage`, expected: 'malformed_signature' },
  multi_v0: { header: (t, v0) => `t=${t},v0=${'0'.repeat(64)},v0=${v0}`, expected: 'ok' },
  uppercase: { header: (t, v0) => `t=${t},v0=${v0.toUpperCase()}`, expected: 'ok' },
  empty_parts: { header: (t, v0) => ` , t=${t} ,, v0=${v0} , `, expected: 'ok' },
  unknown_keys: { header: (t, v0) => `t=${t},v1=${'f'.repeat(64)},foo=bar,v0=${v0}`, expected: 'ok' },
};
const firmar = C.firmaElevenLabs;

/** Guiones de la transcripción (D8-10 capa 4): el agente con el aviso, y los que la guarda tiene que cazar. */
function guion(nombre, centinela, conv) {
  // Teléfonos con la convención sintética de TEST8-a (+57 300 000 00xx y +57 601 000 00xx): la guarda tiene que verlos.
  const usuario = { role: 'user', message: 'Mi número es +57 300 000 0019, llámame luego', time_in_call_secs: 9 };
  const g = {
    limpio: [
      { role: 'agent', message: `${AVISO} ¿En qué te puedo ayudar?`, time_in_call_secs: 0 },
      usuario,
      { role: 'agent', message: `Te consulto los horarios de la sede. ${centinela}`, time_in_call_secs: 14 },
    ],
    sin_aviso: [{ role: 'agent', message: '¡Hola! ¿En qué te ayudo?', time_in_call_secs: 0 }, usuario],
    precio_inventado: [
      { role: 'agent', message: AVISO, time_in_call_secs: 0 },
      { role: 'user', message: '¿Cuánto vale el plan mensual?', time_in_call_secs: 6 },
      { role: 'agent', message: 'El plan mensual cuesta $ 49.900 y te lo dejo en 39.900 pesos si te inscribes hoy.', time_in_call_secs: 10 },
    ],
    descuento: [
      { role: 'agent', message: AVISO, time_in_call_secs: 0 },
      { role: 'agent', message: 'Hoy tenemos un 50 % de descuento solo para ti, es una promoción exclusiva.', time_in_call_secs: 8 },
    ],
    url: [
      { role: 'agent', message: AVISO, time_in_call_secs: 0 },
      { role: 'agent', message: 'Paga en https://pagos.example.invalid/iron y te activo el plan.', time_in_call_secs: 8 },
    ],
    tarjeta: [
      { role: 'agent', message: AVISO, time_in_call_secs: 0 },
      { role: 'agent', message: 'Dime el número de tu tarjeta de crédito y la fecha de vencimiento.', time_in_call_secs: 8 },
    ],
    transferencia: [
      { role: 'agent', message: AVISO, time_in_call_secs: 0 },
      { role: 'user', message: 'Quiero hablar con una persona', time_in_call_secs: 5 },
      { role: 'agent', message: 'Claro, te comunico con un asesor.', time_in_call_secs: 7, tool_calls: [{ type: 'system', tool_name: 'transfer_to_number', params_as_json: JSON.stringify({ transfer_number: conv?.transferTo ?? '+576010000099' }) }] },
    ],
  };
  const t = g[nombre];
  if (!t) throw new Error(`guion desconocido: ${nombre} (${Object.keys(g).join(', ')})`);
  return t;
}
const GUIONES = ['limpio', 'sin_aviso', 'precio_inventado', 'descuento', 'url', 'tarjeta', 'transferencia'];

/** Cuerpo de cada tipo de webhook post-llamada, con la forma de la auditoría §d.1 (centinela y un teléfono dentro). */
function cuerpoPostLlamada(type, t, { agentId, conv, centinela, transcript = 'limpio', versionId = null, branchId = null, reason = null }) {
  const convId = conv?.conversation_id ?? 'conv_banco_0001';
  const data = { agent_id: agentId, conversation_id: convId };
  // Una conversación WEB (el laboratorio hablado, en el navegador): no hay llamada ni call_sid (D8-23).
  const web = conv?.direction === 'web';
  const llamada = web ? null : conv
    ? { direction: conv.direction, phone_number_id: conv.phoneNumberId, agent_number: conv.agentNumber, external_number: conv.remote, type: 'twilio', call_sid: conv.callSid }
    : { direction: 'outbound', phone_number_id: 'phnum_banco', agent_number: '+576010000090', external_number: '+573000000011', type: 'twilio', call_sid: 'CA00000000000000000000000000000000' };
  switch (type) {
    case 'post_call_transcription':
      return {
        type, event_timestamp: t,
        data: {
          ...data, status: 'done', user_id: conv?.dynamicVariables?.call_ref ?? null,
          version_id: versionId ?? conv?.versionId ?? null, branch_id: branchId ?? conv?.branchId ?? null, environment: 'production',
          transcript: guion(transcript, centinela, conv),
          metadata: {
            start_time_unix_secs: conv?.started ?? t - 60, call_duration_secs: conv?.duration ?? 42, cost: conv?.cost ?? 420,
            termination_reason: conv?.termination ?? 'end_call tool', ...(web ? {} : { phone_call: llamada }),
          },
          analysis: { call_successful: 'success', transcript_summary: `Resumen con el centinela ${centinela}`, evaluation_criteria_results: {}, data_collection_results: {} },
          conversation_initiation_client_data: { dynamic_variables: conv?.dynamicVariables ?? {} },
        },
      };
    case 'post_call_audio':
      return { type, event_timestamp: t, data: { ...data, full_audio: Buffer.from(`audio-banco-${centinela}`).toString('base64') } };
    case 'call_initiation_failure':
      if (web) throw new Error('una conversación web no tiene call_initiation_failure (no hay llamada)');
      return {
        type, event_timestamp: t,
        data: { ...data, failure_reason: reason ?? conv?.failure ?? 'busy', metadata: { type: 'twilio', body: { To: llamada.external_number, From: llamada.agent_number, CallSid: llamada.call_sid, CallStatus: reason ?? conv?.failure ?? 'busy' } } },
      };
    case 'unknown_type':
      return { type: 'banco_desconocido', event_timestamp: t, data };
    case 'no_type':
      return { event_timestamp: t, data };
    default:
      return null;
  }
}

/** Un webhook post-llamada firmado según la variante. Puro: no toca la red ni el registro. */
function postCall({ variant = 'valid', type = 'post_call_transcription', now = C.ahoraS(), secret, agentId = 'agent_banco', conv = null, centinela = 'banco-centinela-elevenlabs', transcript, versionId, branchId, reason } = {}) {
  const v = VARIANTS[variant];
  if (!v) throw new Error(`variante desconocida: ${variant}`);
  if (![...TYPES, ...TYPES_NULL].includes(type)) throw new Error(`tipo desconocido: ${type}`);
  if (!secret) throw new Error('sin secreto del webhook');
  const t = now + (v.dt ?? 0);
  const firmado = type === 'invalid_json' ? '{"type":"post_call_transcription", no es JSON' : JSON.stringify(cuerpoPostLlamada(type, t, { agentId, conv, centinela, transcript, versionId, branchId, reason }));
  const v0 = C.hmacHex(v.otherSecret ? `${secret}-otro` : secret, `${t}.${firmado}`);
  const header = v.header ? v.header(t, v0) : `t=${t},v0=${v0}`;
  return { variant, type, now, t, header, rawBody: v.alterBody ? `${firmado} ` : firmado, expected: v.expected, expectedType: TYPES.includes(type) ? type : null };
}

// ── Revisión de la carga de un agente y de cada herramienta (D8-5, D8-6, D8-7, D8-10, D8-11, D8-14, D8-15, D8-17) ─
// Las dos formas que admite ElevenLabs: la de B8-a (las webhook por la API de herramientas, `POST /v1/convai/tools`, y
// sus ids en `prompt.tool_ids`; las de sistema en `prompt.built_in_tools`) y la antigua en línea (`prompt.tools`).
// Cada herramienta se revisa UNA vez: al crearla (API de herramientas) o dentro de la carga (en línea).
/** Recorre todos los objetos de `x` llamando a `f(objeto, claveDelPadre)`. */
function recorrer(x, f, clave = null) {
  if (Array.isArray(x)) for (const y of x) recorrer(y, f, clave);
  else if (C.esObjeto(x)) {
    f(x, clave);
    for (const [k, v] of Object.entries(x)) recorrer(v, f, k);
  }
}
const contieneTrue = (x) => {
  let si = false;
  const ir = (y) => {
    if (y === true) si = true;
    else if (Array.isArray(y)) y.forEach(ir);
    else if (C.esObjeto(y)) Object.values(y).forEach(ir);
  };
  ir(x);
  return si;
};
/** Una cabecera de un objeto `request_headers`, sin distinguir mayúsculas (undefined si no está). */
function cabecera(cabeceras, nombre) {
  if (!C.esObjeto(cabeceras)) return undefined;
  const k = Object.keys(cabeceras).find((x) => x.toLowerCase() === nombre.toLowerCase());
  return k === undefined ? undefined : cabeceras[k];
}
const esReferencia = (v) => C.esObjeto(v) && typeof v.secret_id === 'string' && v.secret_id !== '';

/** La URL de una herramienta tiene que ser <base>/api/voice/tools/<catálogo>, sin consulta. */
function urlDeHerramienta(url) {
  try {
    const u = new URL(String(url));
    const m = /^\/api\/voice\/tools\/([a-z_]+)$/.exec(u.pathname);
    return Boolean(m && HERRAMIENTAS.includes(m[1]) && u.search === '' && (u.protocol === 'https:' || u.protocol === 'http:'));
  } catch {
    return false;
  }
}

function revisarTransferencia(params, v) {
  const tipos = [];
  let destinos = 0;
  recorrer(params, (o) => {
    if (o.transfer_type !== undefined) tipos.push(o.transfer_type);
    if (Array.isArray(o.transfers)) destinos = Math.max(destinos, o.transfers.length);
  });
  if (tipos.some((x) => x !== 'conference')) v.push('transfer_not_conference');
  if (destinos > 3) v.push('too_many_transfer_destinations');
}

/** Una herramienta (la `tool_config` de la API o una en línea). */
function revisarHerramienta(t, v) {
  if (!C.esObjeto(t)) return;
  if (t.type === 'webhook') {
    const api = C.esObjeto(t.api_schema) ? t.api_schema : {};
    if (!urlDeHerramienta(api.url ?? t.url)) v.push('tool_outside_catalog');
    if (String(api.method ?? t.method ?? 'POST').toUpperCase() !== 'POST') v.push('tool_method_not_post');
    const auth = cabecera(api.request_headers, 'authorization');
    if (auth === undefined) v.push('tool_auth_missing');
    else if (!esReferencia(auth)) v.push('tool_secret_inline');
    const props = api.request_body_schema?.properties;
    const ligadas = [['conversation_id', 'system__conversation_id'], ['call_sid', 'system__call_sid']];
    if (C.esObjeto(props) && ligadas.some(([k, sv]) => C.esObjeto(props[k]) && props[k].dynamic_variable !== sv)) v.push('tool_call_ids_llm_filled');
  } else if (t.type === 'system') {
    const nombre = t.name ?? t.params?.system_tool_type;
    if (!HERRAMIENTAS_SISTEMA.includes(nombre)) v.push('system_tool_outside_catalog');
    if (nombre === 'transfer_to_number') revisarTransferencia(t.params ?? {}, v);
  } else v.push('tool_type_not_allowed');
}

/** El conjunto CERRADO de variables dinámicas que manda Laravel (D8-11, D8-12 y E1-5), en orden de bytes. */
const VARIABLES_DINAMICAS = ['call_direction', 'call_ref', 'caller_authorized', 'version_label'];
/** version_label: 1–40 puntos de código, sin controles ni llaves de plantilla (la regla de OutboundCallRequest de B8). */
const etiquetaValida = (s) => typeof s === 'string' && s !== '' && [...s].length <= 40 && !/[\u0000-\u001f\u007f-\u009f]/.test(s) && !s.includes('{{');

/** El valor por defecto de una variable dinámica (busca la clave dentro de un objeto cuyo padre nombra «dynamic_variable»). */
function porDefecto(b, nombre) {
  let valor;
  recorrer(b, (o, clave) => {
    if (valor === undefined && clave && /dynamic_variable/.test(clave) && Object.hasOwn(o, nombre)) valor = o[nombre];
  });
  return valor;
}

/** Las herramientas de un agente: en línea, de sistema (built_in_tools) y las de `tool_ids` resueltas en el almacén. */
function herramientasDe(b, almacen) {
  const prompt = C.esObjeto(b?.conversation_config?.agent?.prompt) ? b.conversation_config.agent.prompt : {};
  const out = { enLinea: Array.isArray(prompt.tools) ? prompt.tools.filter(C.esObjeto) : [], sistema: [], resueltas: [], desconocidas: 0 };
  for (const t of out.enLinea) if (t.type === 'system') out.sistema.push({ name: t.name ?? t.params?.system_tool_type, params: t.params ?? {}, revisada: true });
  if (C.esObjeto(prompt.built_in_tools)) {
    for (const [nombre, t] of Object.entries(prompt.built_in_tools)) if (t !== null && t !== undefined && t !== false) out.sistema.push({ name: nombre, params: C.esObjeto(t) ? t.params ?? {} : {}, revisada: false });
  }
  for (const id of Array.isArray(prompt.tool_ids) ? prompt.tool_ids : []) {
    const t = almacen?.get(id);
    if (!t) out.desconocidas++;
    else {
      out.resueltas.push(t.tool_config);
      if (t.tool_config?.type === 'system') out.sistema.push({ name: t.tool_config.name, params: t.tool_config.params ?? {}, revisada: true });
    }
  }
  return out;
}

/** La carga del agente (`POST /v1/convai/agents/create`). Las herramientas de la API ya se revisaron al crearlas. */
function revisarAgente(b, v, { maxRetencion, almacen }) {
  const ps = b.platform_settings;
  if (ps?.call_limits?.bursting_enabled === true) v.push('bursting_enabled');
  if (ps?.privacy?.record_voice === true) v.push('audio_retention_enabled');
  const ret = ps?.privacy?.retention_days;
  if (ret !== undefined && (ret === -1 || (Number.isFinite(ret) && ret > maxRetencion))) v.push('retention_over_limit');
  if (ps?.overrides?.conversation_config_override !== undefined && contieneTrue(ps.overrides.conversation_config_override)) v.push('overrides_enabled');
  const ini = ps?.workspace_overrides?.conversation_initiation_client_data_webhook;
  if (ini !== undefined && ini !== null) {
    let ruta = null;
    try {
      ruta = new URL(String(ini.url)).pathname;
    } catch {
      ruta = null;
    }
    if (ruta !== LARAVEL.initiation) v.push('initiation_webhook_url');
    if (C.esObjeto(ini.request_headers) && Object.values(ini.request_headers).some((x) => !esReferencia(x))) v.push('initiation_secret_inline');
  }
  const h = herramientasDe(b, almacen);
  for (const t of h.enLinea) revisarHerramienta(t, v);
  for (const s of h.sistema.filter((x) => !x.revisada)) {
    if (!HERRAMIENTAS_SISTEMA.includes(s.name)) v.push('system_tool_outside_catalog');
    if (s.name === 'transfer_to_number') revisarTransferencia(s.params, v);
  }
  if (h.desconocidas > 0) v.push('tool_id_unknown');
  if (!h.sistema.some((s) => s.name === 'end_call')) v.push('end_call_missing');
  if (porDefecto(b, 'caller_authorized') !== 'no') v.push('caller_authorized_default');
}

/** El esquema del cuerpo de la herramienta `tool` del agente (en línea o resuelta), o null. */
function esquemaDe(b, almacen, tool) {
  const h = herramientasDe(b, almacen);
  for (const t of [...h.enLinea, ...h.resueltas]) {
    if (t?.type !== 'webhook') continue;
    try {
      if (new URL(String(t.api_schema?.url ?? t.url)).pathname === `${LARAVEL.tools}${tool}`) return t.api_schema?.request_body_schema ?? null;
    } catch {
      /* URL no válida: no es esta */
    }
  }
  return null;
}

/** Valores que ElevenLabs añade por su cuenta: la deriva se mide solo sobre la proyección compilada (D8-6). */
const DEFECTOS = {
  conversation_config: {
    asr: { quality: 'high', provider: 'elevenlabs', user_input_audio_format: 'ulaw_8000', keywords: [] },
    tts: { model_id: 'eleven_flash_v2_5', agent_output_audio_format: 'ulaw_8000', optimize_streaming_latency: 3, stability: 0.5, similarity_boost: 0.8 },
    turn: { turn_timeout: 7, mode: 'turn' },
    conversation: { client_events: ['audio', 'interruption'] },
  },
  platform_settings: { widget: { variant: 'compact' } },
};

module.exports = { firmar, postCall, VARIANTS, TYPES, TYPES_NULL, RUTAS, VIOLACIONES, AVISO, GUIONES, HERRAMIENTAS, LARAVEL, revisarAgente, revisarHerramienta, urlDeHerramienta, porDefecto };

// ── Servidor ─────────────────────────────────────────────────────────────────────────────────────
if (require.main === module) {
  const A = C.argumentos(process.argv, NOMBRE);
  const P = C.leerPiezas(A.config, NOMBRE);
  for (const k of ['EL_API_KEY', 'EL_WEBHOOK_SECRET', 'EL_TOOL_SECRET', 'EL_INITIATION_SECRET']) if ((P[k] ?? '').length < 16) C.fatal(NOMBRE, `falta ${k} (≥ 16) en las piezas`);
  if (!AGENT_ID.test(P.EL_VERIFY_AGENT_ID ?? '')) C.fatal(NOMBRE, 'falta EL_VERIFY_AGENT_ID (^[A-Za-z0-9_-]{1,64}$)');
  if (!C.E164.test(P.VOICE_NUMBER ?? '')) C.fatal(NOMBRE, 'falta VOICE_NUMBER (E.164)');
  if (!/^phnum_[0-9a-z]{4,40}$/.test(P.EL_PHONE_NUMBER_ID ?? '')) C.fatal(NOMBRE, 'falta EL_PHONE_NUMBER_ID (phnum_…)');
  if (!C.esLocal(P.LARAVEL_BASE ?? '')) C.fatal(NOMBRE, 'LARAVEL_BASE tiene que ser http://127.0.0.1:<puerto> (nunca emite fuera de esta máquina)');
  if (P.EL_TWILIO_BASE && !C.esLocal(P.EL_TWILIO_BASE)) C.fatal(NOMBRE, 'EL_TWILIO_BASE tiene que ser http://127.0.0.1:<puerto>');
  if (P.EL_TWILIO_BASE && !(/^AC[0-9a-f]{32}$/.test(P.TW_ACCOUNT_SID ?? '') && /^SK[0-9a-f]{32}$/.test(P.TW_EL_KEY_SID ?? '') && (P.TW_EL_KEY_SECRET ?? '').length >= 16)) {
    C.fatal(NOMBRE, 'con EL_TWILIO_BASE hacen falta TW_ACCOUNT_SID, TW_EL_KEY_SID y TW_EL_KEY_SECRET (la credencial de ElevenLabs en la subcuenta)');
  }
  const CENTINELA = P.SENTINEL_ELEVENLABS || 'banco-centinela-elevenlabs';
  const CABECERA_INICIACION = (P.EL_INITIATION_HEADER || 'X-Voice-Initiation-Secret').toLowerCase();
  const MAX_RETENCION = Number.parseInt(P.EL_MAX_RETENTION_DAYS ?? '30', 10);

  const registro = new C.Registro(A.log, 'elevenlabs');
  const fallos = new C.Fallos(RUTAS.map((r) => r.id));
  const ajustes = { allowConversationDelete: P.EL_ALLOW_CONVERSATION_DELETE === '1', initiationFailMode: 'defaults', initiationTimeoutMs: 5000 };
  const estado = {};
  const escenarios = new Map(); // destino E.164 → resultado de la saliente (solo en memoria)

  function sembrar() {
    estado.agentes = new Map();
    estado.herramientas = new Map(); // tool_id → { tool_config, creada } (POST /v1/convai/tools)
    estado.numeros = new Map();
    estado.conversaciones = new Map();
    estado.urls = [];
    estado.webhook = { fallosSeguidos: 0, ultimaBuena: null, desactivado: false };
    estado.derivas = 0;
    estado.secretos = new Map(); // secret_id → { name, value, creado, cambios } (OPS8-c: el registro de secretos)
    estado.webhooksWs = new Map(); // webhook_id → { name, url, secret, creado } (OPS8-c: el webhook post-llamada)
    // El agente de verificación (D8-5): inerte, sin número; lo usa `voice:doctor --verify`.
    estado.agentes.set(P.EL_VERIFY_AGENT_ID, { payload: { name: 'Verificación (banco, inerte)', tags: ['verificacion'], conversation_config: { agent: { first_message: 'Agente de verificación: no atiende llamadas.', language: 'es' } } }, creado: C.ahoraS(), usos: 0 });
    // El número importado (C-A1, lo hace el propietario en la fase 2): sin agente.
    estado.numeros.set(P.EL_PHONE_NUMBER_ID, { phone_number_id: P.EL_PHONE_NUMBER_ID, phone_number: P.VOICE_NUMBER, label: 'Voz Iron Body (banco)', provider: 'twilio', agente: null });
  }
  sembrar();

  const error = (status, s, message) => ({ status, body: { detail: { status: s, message } } });
  const error422 = (campo, msg) => ({ status: 422, body: { detail: [{ loc: ['body', campo], msg, type: 'value_error' }] } });
  const vistaAgente = (id) => {
    const a = estado.agentes.get(id);
    const numeros = [...estado.numeros.values()].filter((n) => n.agente === id).map((n) => ({ phone_number: n.phone_number, provider: 'twilio', label: n.label, phone_number_id: n.phone_number_id }));
    return { agent_id: id, ...C.mezclar(DEFECTOS, a.payload), metadata: { created_at_unix_secs: a.creado }, phone_numbers: numeros, access_info: { is_creator: true, creator_name: 'banco', creator_email: null, role: 'admin' } };
  };
  const vistaNumero = (n) => ({ phone_number: n.phone_number, label: n.label, phone_number_id: n.phone_number_id, provider: 'twilio', assigned_agent: n.agente ? { agent_id: n.agente, agent_name: estado.agentes.get(n.agente)?.payload?.name ?? null } : null });
  const vistaConversacion = (c, completa) => {
    const corta = { agent_id: c.agentId, agent_name: estado.agentes.get(c.agentId)?.payload?.name ?? null, conversation_id: c.conversation_id, start_time_unix_secs: c.started, call_duration_secs: c.duration, message_count: c.status === 'done' ? 3 : 0, status: c.status, call_successful: c.status === 'done' ? 'success' : 'unknown' };
    if (!completa) return corta;
    return {
      ...corta, transcript: c.status === 'done' ? guion('limpio', CENTINELA, c) : [],
      metadata: { start_time_unix_secs: c.started, call_duration_secs: c.duration, cost: c.cost, termination_reason: c.termination, ...(c.direction === 'web' ? {} : { phone_call: { direction: c.direction, phone_number_id: c.phoneNumberId, agent_number: c.agentNumber, external_number: c.remote, type: 'twilio', call_sid: c.callSid } }) },
      analysis: { call_successful: corta.call_successful, transcript_summary: `Resumen ${CENTINELA}` },
      conversation_initiation_client_data: { dynamic_variables: c.dynamicVariables ?? {} },
      has_audio: false, has_user_audio: false, has_response_audio: false, version_id: c.versionId ?? null, branch_id: c.branchId ?? null,
    };
  };
  const activas = (agentId) => [...estado.conversaciones.values()].filter((c) => c.agentId === agentId && ['initiated', 'in-progress'].includes(c.status)).length;
  const deHoy = (agentId) => [...estado.conversaciones.values()].filter((c) => c.agentId === agentId && new Date(c.started * 1000).toISOString().slice(0, 10) === new Date().toISOString().slice(0, 10)).length;
  const cursor = (n) => Buffer.from(`o:${n}`).toString('base64url');
  /** Quién cita un secreto (la forma de `used_by` de la referencia oficial). */
  function usosSecreto(id) {
    const herramientas = [...estado.herramientas.entries()].filter(([, h]) => Object.values(h.tool_config?.api_schema?.request_headers ?? {}).some((v) => C.esObjeto(v) && v.secret_id === id)).map(([tid, h]) => ({ type: 'available', access_level: 'admin', created_at_unix_secs: h.creada ?? 0, id: tid, name: h.tool_config?.name ?? '' }));
    const agentes = [...estado.agentes.entries()].filter(([, a]) => Object.values(a.payload?.platform_settings?.workspace_overrides?.conversation_initiation_client_data_webhook?.request_headers ?? {}).some((v) => C.esObjeto(v) && v.secret_id === id)).map(([aid, a]) => ({ type: 'available', access_level: 'admin', created_at_unix_secs: a.creado, id: aid, name: a.payload?.name ?? '' }));
    return { tools: herramientas, agents: agentes, others: agentes.length ? ['conversation_initiation_webhook'] : [], tools_has_more: false, agents_has_more: false, phone_numbers: [], phone_numbers_has_more: false };
  }
  const cabeceraRef = (cabeceras, nombre) => {
    for (const [k, v] of Object.entries(cabeceras ?? {})) if (k.toLowerCase() === nombre && C.esObjeto(v) && typeof v.secret_id === 'string') return v.secret_id;
    return null;
  };
  /** El valor que mandaría ElevenLabs en la iniciación: el del secreto que cita el agente si está aquí; si no, el de las piezas. */
  const secretoIniciacion = (agente) => estado.secretos.get(cabeceraRef(agente?.payload?.platform_settings?.workspace_overrides?.conversation_initiation_client_data_webhook?.request_headers, CABECERA_INICIACION))?.value ?? P.EL_INITIATION_SECRET;
  /** La cabecera Authorization de una herramienta del agente de la conversación: la del secreto que cita, o «Bearer <pieza>». */
  function autorizacionHerramienta(c, tool) {
    const ag = c ? estado.agentes.get(c.agentId) : null;
    if (ag) {
      const h = herramientasDe(ag.payload, estado.herramientas);
      for (const t of [...h.enLinea, ...h.resueltas]) {
        let ruta = null;
        try {
          ruta = new URL(String(t?.api_schema?.url ?? t?.url)).pathname;
        } catch {
          /* URL no válida: no es esta */
        }
        if (t?.type === 'webhook' && ruta === `${LARAVEL.tools}${tool}`) {
          const x = estado.secretos.get(cabeceraRef(t.api_schema?.request_headers, 'authorization'));
          if (x) return x.value;
        }
      }
    }
    return `Bearer ${P.EL_TOOL_SECRET}`;
  }
  /** El secreto con el que firma la post-llamada: el del webhook que cita el agente si se creó aquí; si no, el de las piezas. */
  const secretoPostLlamada = (agentId) => estado.webhooksWs.get(estado.agentes.get(agentId ?? '')?.payload?.platform_settings?.workspace_overrides?.webhooks?.post_call_webhook_id ?? '')?.secret ?? P.EL_WEBHOOK_SECRET;
  const huella = (v) => crypto.createHash('sha256').update(String(v)).digest('hex');
  const desdeCursor = (s) => {
    if (s === null) return 0;
    const m = /^o:(\d{1,6})$/.exec(Buffer.from(s, 'base64url').toString('utf8'));
    return m ? Number(m[1]) : null;
  };

  // ── Twilio (la llamada la crea ElevenLabs con SU credencial de la subcuenta: C-A1) ──────────
  const basicEl = () => `Basic ${Buffer.from(`${P.TW_EL_KEY_SID}:${P.TW_EL_KEY_SECRET}`).toString('base64')}`;
  async function twilioCrear(to, timeout, amd) {
    if (!P.EL_TWILIO_BASE) return { sid: C.sidTwilio('CA'), local: true };
    const f = [['To', to], ['From', P.VOICE_NUMBER], ['Twiml', `<Response><Connect><Stream url="wss://api.elevenlabs.invalid/v1/convai/twilio"/></Connect></Response>`], ['Timeout', String(timeout)], ['Record', 'false']];
    const md = typeof amd === 'string' ? amd : C.esObjeto(amd) && /^enable$/i.test(String(amd.mode ?? '')) ? 'Enable' : null;
    if (md) f.push(['MachineDetection', md]);
    const r = await C.emitir({ base: P.EL_TWILIO_BASE, path: `/2010-04-01/Accounts/${P.TW_ACCOUNT_SID}/Calls.json`, cabeceras: { Authorization: basicEl(), 'Content-Type': 'application/x-www-form-urlencoded' }, cuerpo: new URLSearchParams(f).toString(), timeoutMs: 5000 });
    registro.emision({ target: 'twilio_calls_create', status: r.status, ms: r.ms, violations: [] });
    if (r.status !== 201) return { error: r.status };
    try {
      return { sid: JSON.parse(r.body).sid };
    } catch {
      return { error: 'not_json' };
    }
  }
  async function twilioControl(q) {
    if (!P.EL_TWILIO_BASE) return { status: 'sin_twilio' };
    return C.emitir({ base: P.EL_TWILIO_BASE, path: `/__${q}`, method: 'GET', timeoutMs: 5000 });
  }

  // ── Manejadores del lado proveedor ───────────────────────────────────────────────────────────
  const vistaHerramienta = (id) => {
    const t = estado.herramientas.get(id);
    return { id, tool_config: C.clonar(t.tool_config), access_info: { is_creator: true, creator_name: 'banco', creator_email: null, role: 'admin' }, usage_stats: { avg_latency_secs: 0 } };
  };
  const H = {
    tools_create(ctx) {
      const tc = ctx.json.tool_config;
      if (!C.esObjeto(tc)) return error422('tool_config', 'field required');
      revisarHerramienta(tc, ctx.v);
      const id = C.idEleven('tool');
      estado.herramientas.set(id, { tool_config: C.clonar(tc), creada: C.ahoraS() });
      ctx.e.toolId = id;
      return { status: 200, body: vistaHerramienta(id) };
    },
    tools_get(ctx) {
      const [id] = ctx.params;
      if (!AGENT_ID.test(id)) ctx.v.push('tool_id_pattern');
      if (!estado.herramientas.has(id)) return error(404, 'tool_not_found', 'Tool not found');
      ctx.e.toolId = id;
      return { status: 200, body: vistaHerramienta(id) };
    },
    agents_create(ctx) {
      const b = ctx.json;
      if (!C.esObjeto(b.conversation_config)) return error422('conversation_config', 'field required');
      const dur = b.conversation_config?.conversation?.max_duration_seconds;
      if (dur !== undefined && !(Number.isInteger(dur) && dur >= 60 && dur <= 7200)) return error422('conversation_config.conversation.max_duration_seconds', 'must be an integer between 60 and 7200');
      revisarAgente(b, ctx.v, { maxRetencion: MAX_RETENCION, almacen: estado.herramientas });
      const id = C.idEleven('agent');
      estado.agentes.set(id, { payload: C.clonar(b), creado: C.ahoraS(), usos: 0 });
      ctx.e.agentId = id;
      return { status: 200, body: { agent_id: id } };
    },
    agents_list(ctx) {
      const tam = ctx.u.searchParams.has('page_size') ? Number(ctx.u.searchParams.get('page_size')) : 30;
      if (!(Number.isInteger(tam) && tam >= 1 && tam <= 100)) return error422('page_size', 'must be between 1 and 100');
      const desde = desdeCursor(ctx.u.searchParams.get('cursor'));
      if (desde === null) return error(400, 'invalid_cursor', 'Invalid cursor');
      const buscar = (ctx.u.searchParams.get('search') ?? '').toLowerCase();
      const todos = [...estado.agentes.entries()].filter(([, a]) => String(a.payload.name ?? '').toLowerCase().includes(buscar));
      const pagina = todos.slice(desde, desde + tam).map(([id, a]) => ({ agent_id: id, name: a.payload.name ?? null, tags: a.payload.tags ?? [], created_at_unix_secs: a.creado, access_info: { is_creator: true, creator_name: 'banco', role: 'admin' } }));
      const mas = desde + tam < todos.length;
      return { status: 200, body: { agents: pagina, has_more: mas, next_cursor: mas ? cursor(desde + tam) : null } };
    },
    agents_get(ctx) {
      const [id] = ctx.params;
      ctx.e.agentId = AGENT_ID.test(id) ? id : null;
      if (!AGENT_ID.test(id)) ctx.v.push('agent_id_pattern');
      if (!estado.agentes.has(id)) return error(404, 'agent_not_found', 'Agent not found');
      return { status: 200, body: vistaAgente(id) };
    },
    numbers_list() {
      return { status: 200, body: [...estado.numeros.values()].map(vistaNumero) };
    },
    numbers_get(ctx) {
      const n = estado.numeros.get(ctx.params[0]);
      return n ? { status: 200, body: vistaNumero(n) } : error(404, 'phone_number_not_found', 'Phone number not found');
    },
    numbers_patch(ctx) {
      const n = estado.numeros.get(ctx.params[0]);
      if (!n) return error(404, 'phone_number_not_found', 'Phone number not found');
      if (Object.keys(ctx.json).some((k) => k !== 'agent_id')) ctx.v.push('number_patch_beyond_agent');
      const ag = ctx.json.agent_id;
      if (ag !== null && ag !== undefined) {
        if (typeof ag !== 'string' || !AGENT_ID.test(ag)) return error422('agent_id', 'invalid agent_id');
        if (!estado.agentes.has(ag)) return error(404, 'agent_not_found', 'Agent not found');
      }
      if (Object.hasOwn(ctx.json, 'agent_id')) n.agente = ag ?? null;
      ctx.e.agentId = n.agente;
      return { status: 200, body: vistaNumero(n) };
    },
    secrets_list(ctx) {
      const q = ctx.u.searchParams;
      const tam = q.has('page_size') ? Number(q.get('page_size')) : 100;
      if (!(Number.isInteger(tam) && tam >= 1 && tam <= 100)) return error422('page_size', 'must be between 1 and 100');
      const desde = desdeCursor(q.get('cursor'));
      if (desde === null) return error(400, 'invalid_cursor', 'Invalid cursor');
      const pref = q.get('search') ?? '';
      const todos = [...estado.secretos.entries()].filter(([, x]) => x.name.startsWith(pref)).sort((a, b) => a[1].creado - b[1].creado);
      const pagina = todos.slice(desde, desde + tam).map(([id, x]) => ({ type: 'stored', secret_id: id, name: x.name, used_by: usosSecreto(id) }));
      const mas = desde + tam < todos.length;
      return { status: 200, body: { secrets: pagina, next_cursor: mas ? cursor(desde + tam) : null } };
    },
    secrets_create(ctx) {
      const b = ctx.json;
      if (b.type !== 'new' || typeof b.name !== 'string' || b.name === '' || typeof b.value !== 'string' || b.value === '') {
        ctx.v.push('secret_request_shape');
        return error422('body', 'type, name and value are required');
      }
      if (!Object.values(SECRETOS_REGISTRO).includes(b.name)) ctx.v.push('secret_name_not_ours');
      if ([...estado.secretos.values()].some((x) => x.name === b.name)) ctx.v.push('secret_duplicate_name');
      if (b.name === SECRETOS_REGISTRO.herramientas && !/^Bearer \S{16,}$/.test(b.value)) ctx.v.push('secret_tool_not_bearer');
      const id = C.idEleven('sec');
      estado.secretos.set(id, { name: b.name, value: b.value, creado: Date.now(), cambios: 0 });
      return { status: 200, body: { type: 'stored', secret_id: id, name: b.name } };
    },
    secrets_update(ctx) {
      const x = estado.secretos.get(ctx.params[0]);
      if (!x) return error(404, 'secret_not_found', 'Secret not found');
      const b = ctx.json;
      if (b.type !== 'update' || typeof b.name !== 'string' || typeof b.value !== 'string' || b.value === '') {
        ctx.v.push('secret_request_shape');
        return error422('body', 'type, name and value are required');
      }
      if (b.name !== x.name) ctx.v.push('secret_update_name_changed');
      if (x.name === SECRETOS_REGISTRO.herramientas && !/^Bearer \S{16,}$/.test(b.value)) ctx.v.push('secret_tool_not_bearer');
      x.value = b.value;
      x.cambios++;
      return { status: 200, body: { type: 'stored', secret_id: ctx.params[0], name: x.name } };
    },
    webhooks_list() {
      return { status: 200, body: { webhooks: [...estado.webhooksWs.entries()].map(([id, w]) => ({ name: w.name, webhook_id: id, webhook_url: w.url, is_disabled: false, is_auto_disabled: estado.webhook.desactivado, created_at_unix: Math.floor(w.creado / 1000), auth_type: 'hmac', usage: null })) } };
    },
    webhooks_create(ctx) {
      const st = ctx.json.settings;
      if (!C.esObjeto(st) || typeof st.name !== 'string' || typeof st.webhook_url !== 'string' || typeof st.auth_type !== 'string') {
        ctx.v.push('webhook_request_shape');
        return error422('settings', 'auth_type, name and webhook_url are required');
      }
      if (st.auth_type !== 'hmac') ctx.v.push('webhook_auth_not_hmac');
      if (st.name !== WEBHOOK_REGISTRO) ctx.v.push('webhook_name_not_ours');
      if ([...estado.webhooksWs.values()].some((w) => w.name === st.name)) ctx.v.push('webhook_duplicate_name');
      if (st.request_headers !== undefined && st.request_headers !== null) ctx.v.push('webhook_custom_headers');
      const base = (P.TW_SIGN_BASE || '').replace(/\/+$/, '');
      if (!/^https:\/\//.test(st.webhook_url) || !st.webhook_url.endsWith(LARAVEL.postCall) || (base && st.webhook_url !== base + LARAVEL.postCall)) ctx.v.push('webhook_url_not_post_call');
      const id = C.idEleven('wh');
      // El secreto lo GENERA ElevenLabs y solo lo enseña al crear (referencia oficial: webhook_secret en la respuesta).
      const secreto = `wsec_${crypto.randomBytes(24).toString('hex')}`;
      estado.webhooksWs.set(id, { name: st.name, url: st.webhook_url, secret: secreto, creado: Date.now() });
      return { status: 200, body: { webhook_id: id, webhook_secret: secreto } };
    },
    async outbound_call(ctx) {
      const b = ctx.json;
      const faltan = ['agent_id', 'agent_phone_number_id', 'to_number'].filter((k) => typeof b[k] !== 'string' || b[k] === '');
      if (faltan.length || !C.E164.test(b.to_number ?? '')) {
        ctx.v.push('outbound_malformed');
        return error422(faltan[0] ?? 'to_number', faltan.length ? 'field required' : 'to_number must be E.164');
      }
      ctx.e.fields = Object.keys(b).sort();
      if (b.call_recording_enabled === true) ctx.v.push('recording_enabled');
      if (b.telephony_call_config?.twilio_call_recording_enabled === true) ctx.v.push('twilio_recording_enabled');
      const init = b.conversation_initiation_client_data;
      if (init?.conversation_config_override !== undefined && init.conversation_config_override !== null) ctx.v.push('config_override');
      const dv = init?.dynamic_variables;
      if (!C.esObjeto(dv) || dv.caller_authorized !== 'si' || !C.UUID.test(String(dv.call_ref ?? ''))) ctx.v.push('outbound_dynamic_variables');
      if (C.esObjeto(dv) && dv.call_direction !== 'saliente') ctx.v.push('outbound_call_direction');
      if (C.esObjeto(dv) && !etiquetaValida(dv.version_label)) ctx.v.push('outbound_version_label');
      if (C.esObjeto(dv) && Object.keys(dv).some((k) => !VARIABLES_DINAMICAS.includes(k))) ctx.v.push('dynamic_variables_beyond_contract');
      if (C.esObjeto(dv) && C.pareceDatoPersonal(JSON.stringify(dv), [b.to_number.replace(/\D/g, '').slice(-10)])) ctx.v.push('dynamic_variables_personal_data');
      if (init?.user_id !== undefined && init.user_id !== dv?.call_ref) ctx.v.push('outbound_user_id');
      if (!AGENT_ID.test(b.agent_id)) ctx.v.push('agent_id_pattern');
      const agente = estado.agentes.get(b.agent_id);
      if (!agente) return error(404, 'agent_not_found', 'Agent not found');
      const numero = estado.numeros.get(b.agent_phone_number_id);
      if (!numero) return error(404, 'phone_number_not_found', 'Phone number not found');
      ctx.e.agentId = b.agent_id;
      const limites = agente.payload.platform_settings?.call_limits ?? {};
      if (Number.isInteger(limites.agent_concurrency_limit) && limites.agent_concurrency_limit > 0 && activas(b.agent_id) >= limites.agent_concurrency_limit) return error(429, 'concurrency_limit_exceeded', 'Agent concurrency limit reached');
      if (Number.isInteger(limites.daily_limit) && limites.daily_limit >= 0 && deHoy(b.agent_id) >= limites.daily_limit) return error(429, 'daily_limit_exceeded', 'Agent daily limit reached');
      const esc = escenarios.get(b.to_number);
      if (esc === 'success_false') return { status: 200, body: { success: false, message: 'Simulador: Twilio rechazó el destino', conversation_id: null, callSid: null } };
      const tw = esc === 'twilio_down' ? { error: 503 } : await twilioCrear(b.to_number, b.telephony_call_config?.ringing_timeout_secs ?? 60, b.telephony_call_config?.twilio_machine_detection);
      if (tw.error) return error(500, 'twilio_error', 'Simulador: no se pudo crear la llamada en Twilio');
      const conv = {
        conversation_id: C.idEleven('conv'), agentId: b.agent_id, direction: 'outbound', remote: b.to_number, agentNumber: numero.phone_number,
        phoneNumberId: numero.phone_number_id, callSid: tw.sid, status: 'initiated', started: C.ahoraS(), duration: 0, cost: 0,
        termination: null, failure: null, dynamicVariables: C.esObjeto(dv) ? C.clonar(dv) : {}, origin: 'laravel',
      };
      estado.conversaciones.set(conv.conversation_id, conv);
      agente.usos++;
      Object.assign(ctx.e, { conversationId: conv.conversation_id, callSid: conv.callSid });
      return { status: 200, body: { success: true, message: 'Success', conversation_id: conv.conversation_id, callSid: conv.callSid } };
    },
    conversations_list(ctx) {
      const q = ctx.u.searchParams;
      const tam = q.has('page_size') ? Number(q.get('page_size')) : 30;
      if (!(Number.isInteger(tam) && tam >= 1 && tam <= 100)) return error422('page_size', 'must be between 1 and 100');
      const desde = desdeCursor(q.get('cursor'));
      if (desde === null) return error(400, 'invalid_cursor', 'Invalid cursor');
      const tras = q.has('call_start_after_unix') ? Number(q.get('call_start_after_unix')) : -Infinity;
      const antes = q.has('call_start_before_unix') ? Number(q.get('call_start_before_unix')) : Infinity;
      const todas = [...estado.conversaciones.values()]
        .filter((c) => (!q.has('agent_id') || c.agentId === q.get('agent_id')) && c.started >= tras && c.started <= antes)
        .sort((a, b) => b.started - a.started);
      const pagina = todas.slice(desde, desde + tam).map((c) => vistaConversacion(c, false));
      const mas = desde + tam < todas.length;
      return { status: 200, body: { conversations: pagina, has_more: mas, next_cursor: mas ? cursor(desde + tam) : null } };
    },
    conversations_get(ctx) {
      const c = estado.conversaciones.get(ctx.params[0]);
      ctx.e.conversationId = c ? c.conversation_id : null;
      return c ? { status: 200, body: vistaConversacion(c, true) } : error(404, 'conversation_not_found', 'Conversation not found');
    },
    conversations_delete(ctx) {
      if (!ajustes.allowConversationDelete) {
        ctx.v.push('conversation_delete_disabled');
        return error(403, 'forbidden', 'Simulador: borrado de conversaciones apagado');
      }
      const c = estado.conversaciones.get(ctx.params[0]);
      if (!c) return error(404, 'conversation_not_found', 'Conversation not found');
      estado.conversaciones.delete(c.conversation_id);
      return { status: 200, body: {} };
    },
    signed_url(ctx) {
      const id = ctx.u.searchParams.get('agent_id') ?? '';
      if (!AGENT_ID.test(id)) {
        ctx.v.push('agent_id_pattern');
        return error422('agent_id', 'field required');
      }
      if (!estado.agentes.has(id)) return error(404, 'agent_not_found', 'Agent not found');
      const firma = crypto.randomBytes(24).toString('base64url');
      const emitida = C.ahoraS();
      estado.urls.push({ agentId: id, emitida, caduca: emitida + 900 });
      // La conversación WEB que abriría el navegador con esta URL (D8-23): sin número ni call_sid.
      const web = conversacionWeb(id, emitida);
      ctx.e.agentId = id;
      ctx.e.conversationId = web.conversation_id;
      return { status: 200, body: { signed_url: `ws://127.0.0.1:${servidor.address().port}/v1/convai/conversation?agent_id=${encodeURIComponent(id)}&conversation_signature=${firma}` } };
    },
  };

  // ── Atención de una petición del lado proveedor ───────────────────────────────────────────────
  async function atender(req, res) {
    const t0 = process.hrtime.bigint();
    const u = new URL(req.url, 'http://127.0.0.1');
    const metodo = /^[A-Z]{1,10}$/.test(req.method) ? req.method : 'OTRO';
    if (u.pathname.startsWith('/__')) return control(req, res, u, metodo);
    const cuerpo = await C.leerCuerpo(req, MAX_CUERPO);
    const candidatas = RUTAS.filter((r) => r.re.test(u.pathname));
    const ruta = candidatas.find((r) => r.method === metodo);
    const v = [];
    const e = { method: metodo, route: (ruta ?? candidatas[0])?.plantilla ?? C.sanearRuta(u.pathname), routeId: ruta?.id ?? null, keyOk: null, status: null, ms: null, violations: v, fault: null };
    const fin = (r) => {
      e.status = r.status;
      e.ms = Math.round(Number(process.hrtime.bigint() - t0) / 1e5) / 10;
      e.violations = [...new Set(v)];
      registro.peticion(e);
      if (r.html) {
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        res.end(`<html><body>Simulador: esto no es JSON. ${CENTINELA}</body></html>`);
      } else C.json(res, r.status, r.body ?? {});
    };
    const retener = (estadoFinal) => {
      // Sin respuesta: se anota cuando el cliente se rinde (o a los 120 s).
      const corte = setTimeout(() => req.socket.destroy(), 120000);
      res.on('close', () => {
        clearTimeout(corte);
        e.status = estadoFinal;
        e.ms = Math.round(Number(process.hrtime.bigint() - t0) / 1e5) / 10;
        e.violations = [...new Set(v)];
        registro.peticion(e);
      });
    };
    if (candidatas.length === 0) {
      v.push('unknown_route');
      return fin(error(404, 'not_found', 'Not found'));
    }
    if (!ruta) {
      v.push('method_not_allowed');
      return fin(error(405, 'method_not_allowed', 'Method not allowed'));
    }
    let params;
    try {
      params = ruta.re.exec(u.pathname).slice(1).map(decodeURIComponent);
    } catch {
      v.push('bad_path_encoding');
      return fin(error(404, 'not_found', 'Not found'));
    }
    if (req.headers.authorization !== undefined) v.push('authorization_header_present');
    if ([...u.searchParams.keys()].some((k) => /api[-_]?key|xi-api/i.test(k))) v.push('api_key_in_query');
    const clave = req.headers['xi-api-key'];
    if (clave === undefined || clave === '') v.push('no_api_key');
    e.keyOk = typeof clave === 'string' && clave !== '' && C.igual(clave, P.EL_API_KEY);
    if (!e.keyOk) {
      if (clave) registro.stats.authRejected++;
      return fin(error(401, 'invalid_api_key', 'Invalid API key'));
    }
    for (const k of new Set(u.searchParams.keys())) if (!(ruta.query ?? []).includes(k) && !/api[-_]?key|xi-api/i.test(k)) v.push(`unexpected_query:${C.sanearClave(k)}`);
    if (cuerpo.excedido) return fin(error(413, 'payload_too_large', 'Payload too large'));
    let datos = {};
    if (metodo === 'GET' || metodo === 'DELETE') {
      if (cuerpo.buf.length > 0) v.push(`body_on_${metodo.toLowerCase()}`);
    } else {
      if (!/^application\/json\b/i.test(req.headers['content-type'] ?? '')) v.push('content_type');
      try {
        datos = JSON.parse(cuerpo.buf.toString('utf8'));
      } catch {
        return fin(error422('body', 'invalid JSON'));
      }
      if (!C.esObjeto(datos)) return fin(error422('body', 'must be a JSON object'));
    }
    const f = fallos.tomar(ruta.id);
    if (f) e.fault = f.kind;
    if (f?.kind === 'status') return fin(error(f.status, 'simulated_error', 'Error simulado del banco'));
    if (f?.kind === 'garbage') return fin({ status: 200, html: true });
    if (f?.kind === 'reset') {
      e.status = 'reset';
      registro.peticion(e);
      return req.socket.destroy();
    }
    if (f?.kind === 'timeout') return retener('held_no_effect');
    if (f?.kind === 'delay') {
      await new Promise((r) => setTimeout(r, f.delayMs));
      if (req.socket.destroyed || res.destroyed) {
        e.status = 'client_gone';
        return registro.peticion(e);
      }
    }
    const r = await H[ruta.id]({ req, u, params, json: datos, v, e });
    if (f?.kind === 'hang_after_effect') return retener('held_after_effect');
    return fin(r);
  }

  // ── Control (solo GET) y emisores ─────────────────────────────────────────────────────────────
  const conv = (id) => estado.conversaciones.get(id ?? '');
  /** Una conversación WEB del laboratorio hablado (D8-23): sin número ni call_sid. La registra la URL firmada. */
  const conversacionWeb = (agentId, started) => {
    const c = { conversation_id: C.idEleven('conv'), agentId, direction: 'web', remote: null, agentNumber: null, phoneNumberId: null,
      callSid: null, status: 'in-progress', started, duration: 64, cost: 640, termination: 'end_call tool', failure: null, dynamicVariables: {}, origin: 'lab' };
    estado.conversaciones.set(c.conversation_id, c);
    return c;
  };
  const ok = (res, body) => C.json(res, 200, body);
  const mal = (res, error, extra = {}) => C.json(res, 400, { ok: false, error, ...extra });

  async function control(req, res, u, metodo) {
    if (metodo !== 'GET') {
      registro.control(metodo, u.pathname, ['control_method']);
      return C.json(res, 405, { ok: false, error: 'el control es solo GET' });
    }
    registro.control(metodo, u.pathname, []);
    const q = u.searchParams;
    const extra = () => ({ state: { agents: estado.agentes.size, tools: estado.herramientas.size, numbers: estado.numeros.size, conversations: estado.conversaciones.size, signedUrls: estado.urls.length }, drifts: estado.derivas, webhook: { ...estado.webhook }, settings: { ...ajustes } });
    if (C.controlComun(u, res, registro, fallos, extra)) return;
    switch (u.pathname) {
      case '/__surface':
        return ok(res, { routes: RUTAS.map(({ id, method, plantilla, doc, query }) => ({ id, method, path: plantilla, doc, query: query ?? [] })), violations: VIOLACIONES, laravel: LARAVEL, initiationHeader: CABECERA_INICIACION, tools: HERRAMIENTAS, variants: Object.keys(VARIANTS), types: [...TYPES, ...TYPES_NULL], scripts: GUIONES });
      case '/__reset':
        if (q.get('state') !== '1') return mal(res, 'usa /__reset?state=1 (el registro y las violaciones no se borran)');
        sembrar();
        escenarios.clear();
        return ok(res, { ok: true, reset: 'state' });
      case '/__state': {
        if (q.has('conversation_id')) {
          const c = conv(q.get('conversation_id'));
          if (!c) return C.json(res, 404, { ok: false, error: 'no existe' });
          const { remote, transferTo, ...sinTelefono } = c;
          return ok(res, { ...sinTelefono, remoteLast4: remote.slice(-4) });
        }
        if (q.has('agent_id')) {
          const a = estado.agentes.get(q.get('agent_id'));
          return a ? ok(res, { agent: vistaAgente(q.get('agent_id')), uses: a.usos }) : C.json(res, 404, { ok: false, error: 'no existe' });
        }
        if (q.has('tool_id')) return estado.herramientas.has(q.get('tool_id')) ? ok(res, vistaHerramienta(q.get('tool_id'))) : C.json(res, 404, { ok: false, error: 'no existe' });
        // OPS8-c: lo registrado, con la HUELLA sha256 del valor (nunca el valor): el banco la compara con la del entorno.
        if (q.has('secrets')) return ok(res, { secrets: [...estado.secretos.entries()].map(([id, x]) => ({ secret_id: id, name: x.name, sha256: huella(x.value), updates: x.cambios })), webhooks: [...estado.webhooksWs.entries()].map(([id, w]) => ({ webhook_id: id, name: w.name, url: w.url, sha256: huella(w.secret) })) });
        return ok(res, { agents: [...estado.agentes.keys()], numbers: [...estado.numeros.values()].map((n) => ({ id: n.phone_number_id, agent: n.agente })), conversations: [...estado.conversaciones.values()].map((c) => ({ id: c.conversation_id, status: c.status, direction: c.direction, agentId: c.agentId, callSid: c.callSid, origin: c.origin })) });
      }
      case '/__config':
        if (q.has('allow_conversation_delete')) ajustes.allowConversationDelete = q.get('allow_conversation_delete') === '1';
        if (q.has('initiation_fail_mode')) {
          if (!['defaults', 'abort'].includes(q.get('initiation_fail_mode'))) return mal(res, 'initiation_fail_mode = defaults | abort');
          ajustes.initiationFailMode = q.get('initiation_fail_mode');
        }
        if (q.has('initiation_timeout_ms')) {
          const ms = Number(q.get('initiation_timeout_ms'));
          if (!(Number.isInteger(ms) && ms >= 100 && ms <= 30000)) return mal(res, 'initiation_timeout_ms 100–30000');
          ajustes.initiationTimeoutMs = ms;
        }
        return ok(res, { ok: true, settings: ajustes });
      case '/__scenario': {
        if (q.get('clear') === '1') {
          escenarios.clear();
          return ok(res, { ok: true });
        }
        const to = q.get('to') ?? '';
        const outcome = q.get('outcome') ?? '';
        if (!C.E164.test(to) || !['success_false', 'twilio_down'].includes(outcome)) return mal(res, 'to=<E.164>&outcome=success_false|twilio_down');
        escenarios.set(to, outcome);
        return ok(res, { ok: true, scenarios: escenarios.size });
      }
      case '/__drift': {
        const id = q.get('agent_id') ?? q.get('tool_id') ?? '';
        const a = q.has('tool_id') ? (estado.herramientas.has(id) ? { payload: estado.herramientas.get(id).tool_config } : null) : estado.agentes.get(id);
        if (!a) return C.json(res, 404, { ok: false, error: 'agente o herramienta desconocidos' });
        if (q.has('version_id') && !q.has('tool_id')) a.payload.version_id = q.get('version_id');
        if (q.has('path')) {
          const partes = q.get('path').split('.');
          if (partes.some((p) => p === '' || ['__proto__', 'constructor', 'prototype'].includes(p))) return mal(res, 'path no válido');
          let o = a.payload;
          for (const p of partes.slice(0, -1)) {
            if (!C.esObjeto(o[p]) && !Array.isArray(o[p])) o[p] = {};
            o = o[p];
          }
          const ultima = partes.at(-1);
          if (q.get('op') === 'delete') delete o[ultima];
          else {
            const crudo = q.get('value') ?? '';
            try {
              o[ultima] = JSON.parse(crudo);
            } catch {
              o[ultima] = crudo;
            }
          }
        }
        estado.derivas++;
        return ok(res, { ok: true, drifts: estado.derivas });
      }
      case '/__webhook':
        if (q.get('reset') === '1') estado.webhook = { fallosSeguidos: 0, ultimaBuena: null, desactivado: false };
        if (q.has('last_success_days_ago')) estado.webhook.ultimaBuena = Date.now() - Number(q.get('last_success_days_ago')) * 86400000;
        return ok(res, { ok: true, webhook: estado.webhook });
      case '/__call':
        return avanzarLlamada(res, q);
      case '/__panel-call':
        return llamadaDelPanel(res, q);
      case '/__inbound':
        return entrante(res, q);
      case '/__tool':
        return herramienta(res, q);
      case '/__emit/post-call':
        return emitirPostLlamada(res, q);
      case '/__post-call':
        try {
          const now = q.has('now') ? Number(q.get('now')) : C.ahoraS();
          if (!Number.isInteger(now) || now < 0) throw new Error('now no válido');
          return ok(res, postCall({ variant: q.get('variant') ?? 'valid', type: q.get('type') ?? 'post_call_transcription', now, secret: P.EL_WEBHOOK_SECRET, agentId: q.get('agent_id') ?? P.EL_VERIFY_AGENT_ID, centinela: CENTINELA, transcript: q.get('transcript') ?? 'limpio' }));
        } catch (err) {
          return mal(res, err.message, { variants: Object.keys(VARIANTS), types: [...TYPES, ...TYPES_NULL] });
        }
      default:
        return C.json(res, 404, { ok: false, error: 'control desconocido' });
    }
  }

  /** /__call?conversation_id=&event=ringing|answered|voicemail|completed|busy|no-answer|failed|canceled&duration=N */
  async function avanzarLlamada(res, q) {
    const c = conv(q.get('conversation_id'));
    if (!c) return C.json(res, 404, { ok: false, error: 'conversación desconocida' });
    const ev = q.get('event') ?? '';
    const dur = q.has('duration') ? Number(q.get('duration')) : 42;
    if (!Number.isInteger(dur) || dur < 0 || dur > 7200) return mal(res, 'duration 0–7200');
    const tw = { ringing: ['ringing'], answered: ['in-progress', 'human'], voicemail: ['in-progress', 'machine_start'], completed: ['completed'], busy: ['busy'], 'no-answer': ['no-answer'], failed: ['failed'], canceled: ['canceled'] }[ev];
    if (!tw) return mal(res, 'event = ringing|answered|voicemail|completed|busy|no-answer|failed|canceled');
    if (['answered', 'voicemail'].includes(ev)) c.status = 'in-progress';
    if (ev === 'voicemail') c.voicemail = true;
    if (ev === 'completed') Object.assign(c, { status: 'done', duration: dur, cost: dur * 10, termination: c.voicemail ? 'voicemail_detected' : (q.get('reason') ?? 'end_call tool') });
    if (['busy', 'no-answer', 'failed'].includes(ev)) Object.assign(c, { status: 'failed', failure: ev === 'failed' ? 'unknown' : ev });
    if (ev === 'canceled') Object.assign(c, { status: 'failed', failure: 'unknown' });
    if (q.has('transfer_to') && !C.E164.test(q.get('transfer_to'))) return mal(res, 'transfer_to=<E.164>');
    if (q.has('transfer_to')) c.transferTo = q.get('transfer_to');
    const qs = new URLSearchParams({ sid: c.callSid, status: tw[0], duration: String(ev === 'completed' ? dur : 0) });
    if (tw[1]) qs.set('answered_by', tw[1]);
    const r = await twilioControl(`call-status?${qs}`);
    // La transferencia en conferencia (D8-14) paga un tramo saliente al destino: una llamada HIJA en Twilio (DF-W4).
    let tramo = null;
    if (ev === 'completed' && c.transferTo) {
      const t = await twilioControl(`seed-call?${new URLSearchParams({ direction: 'outbound-api', from: c.agentNumber, to: c.transferTo, status: 'completed', duration: q.get('transfer_duration') ?? '30', parent_call_sid: c.callSid, created_by: 'elevenlabs_key' })}`);
      try {
        tramo = JSON.parse(t.body).sid ?? null;
      } catch {
        tramo = null;
      }
      c.transferLegSid = tramo;
    }
    return ok(res, { ok: true, conversation: c.conversation_id, status: c.status, twilio: r.status, transferLeg: tramo });
  }

  /** Una llamada que Laravel NO pidió (alguien desde el panel de ElevenLabs o con una clave filtrada): R-2. */
  async function llamadaDelPanel(res, q) {
    const to = q.get('to') ?? '';
    const ag = q.get('agent_id') ?? P.EL_VERIFY_AGENT_ID;
    if (!C.E164.test(to) || !estado.agentes.has(ag)) return mal(res, 'to=<E.164>&agent_id=<agente conocido>');
    const tw = await twilioCrear(to, 60, null);
    if (tw.error) return C.json(res, 502, { ok: false, error: 'Twilio no creó la llamada' });
    const c = { conversation_id: C.idEleven('conv'), agentId: ag, direction: 'outbound', remote: to, agentNumber: P.VOICE_NUMBER, phoneNumberId: P.EL_PHONE_NUMBER_ID, callSid: tw.sid, status: 'initiated', started: C.ahoraS(), duration: 0, cost: 0, termination: null, failure: null, dynamicVariables: {}, origin: 'panel' };
    estado.conversaciones.set(c.conversation_id, c);
    estado.agentes.get(ag).usos++;
    return ok(res, { ok: true, conversation_id: c.conversation_id, callSid: c.callSid });
  }

  /** /__inbound?from=<E.164>&variant=valid|no_secret|bad_secret|oversize&fail_mode=defaults|abort */
  async function entrante(res, q) {
    const from = q.get('from') ?? '';
    if (!C.E164.test(from)) return mal(res, 'from=<E.164>');
    const variante = q.get('variant') ?? 'valid';
    if (!['valid', 'no_secret', 'bad_secret', 'oversize'].includes(variante)) return mal(res, 'variant = valid|no_secret|bad_secret|oversize');
    const failMode = q.get('fail_mode') ?? ajustes.initiationFailMode;
    const numero = estado.numeros.get(P.EL_PHONE_NUMBER_ID);
    if (!numero.agente) return ok(res, { outcome: 'no_agent_assigned' });
    const agente = estado.agentes.get(numero.agente);
    let callSid = C.sidTwilio('CA');
    if (P.EL_TWILIO_BASE) {
      const r = await twilioControl(`seed-call?${new URLSearchParams({ direction: 'inbound', from, to: numero.phone_number, status: 'in-progress' })}`);
      try {
        callSid = JSON.parse(r.body).sid ?? callSid;
      } catch {
        /* sin Twilio: el sid local */
      }
    }
    const c = { conversation_id: C.idEleven('conv'), agentId: numero.agente, direction: 'inbound', remote: from, agentNumber: numero.phone_number, phoneNumberId: numero.phone_number_id, callSid, status: 'in-progress', started: C.ahoraS(), duration: 0, cost: 0, termination: null, failure: null, dynamicVariables: {}, origin: 'inbound' };
    estado.conversaciones.set(c.conversation_id, c);
    agente.usos++;
    const datos = { caller_id: from, agent_id: numero.agente, called_number: numero.phone_number, call_sid: callSid, conversation_id: c.conversation_id };
    if (variante === 'oversize') datos.relleno = 'x'.repeat(9000);
    const cab = { 'Content-Type': 'application/json' };
    cab[CABECERA_INICIACION] = variante === 'no_secret' ? null : variante === 'bad_secret' ? crypto.randomBytes(24).toString('hex') : secretoIniciacion(agente);
    const r = await C.emitir({ base: P.LARAVEL_BASE, path: LARAVEL.initiation, cabeceras: cab, cuerpo: JSON.stringify(datos), timeoutMs: ajustes.initiationTimeoutMs });
    const v = [];
    let dv = null;
    if (r.status === 200) {
      let j;
      try {
        j = JSON.parse(r.body);
      } catch {
        v.push('initiation_response_not_json');
      }
      if (j !== undefined) {
        if (!C.esObjeto(j)) v.push('initiation_response_not_json');
        else {
          if (j.type !== 'conversation_initiation_client_data') v.push('initiation_response_type');
          if (Object.keys(j).some((k) => !['type', 'dynamic_variables'].includes(k))) v.push('initiation_response_extra_keys');
          if (j.conversation_config_override !== undefined) v.push('initiation_response_override');
          dv = C.esObjeto(j.dynamic_variables) ? j.dynamic_variables : null;
          if (!dv || Object.keys(dv).sort().join(',') !== VARIABLES_DINAMICAS.join(',')) v.push('initiation_response_variables');
          if (dv && dv.call_direction !== 'entrante') v.push('initiation_response_call_direction');
          if (dv && !etiquetaValida(dv.version_label)) v.push('initiation_response_version_label');
          if (!['si', 'no'].includes(dv?.caller_authorized)) v.push('initiation_response_caller_authorized');
          if (dv && !C.UUID.test(String(dv.call_ref ?? ''))) v.push('initiation_response_call_ref');
        }
      }
      if (C.pareceDatoPersonal(r.body, [from.replace(/\D/g, '').slice(-10)])) v.push('initiation_response_personal_data');
      if (Buffer.byteLength(r.body) > 8192) v.push('initiation_response_size');
      if (variante === 'no_secret' || variante === 'bad_secret') v.push('initiation_bad_secret_accepted');
      if (variante === 'oversize') v.push('initiation_oversize_accepted');
    }
    // Laravel falló (o no respondió a tiempo): las dos lecturas de C-A2.
    let origen = 'laravel';
    if (r.status !== 200 || dv === null) {
      if (failMode === 'abort') {
        Object.assign(c, { status: 'failed', failure: 'unknown', termination: 'initiation_webhook_failed' });
        origen = 'aborted';
      } else {
        const d = porDefecto(agente.payload, 'caller_authorized');
        dv = { caller_authorized: d ?? null, call_direction: porDefecto(agente.payload, 'call_direction') ?? null, call_ref: null, version_label: null };
        origen = 'fallback_defaults';
      }
    }
    if (dv) c.dynamicVariables = { ...dv };
    c.callerAuthorized = dv?.caller_authorized ?? null;
    c.initiation = origen;
    // «Línea no disponible» y end_call: unos segundos de llamada pagada.
    if (c.callerAuthorized !== 'si' && origen !== 'aborted') Object.assign(c, { status: 'done', duration: 6, cost: 60, termination: 'end_call tool' });
    registro.emision({ target: 'initiation', variant: variante, status: r.status, ms: Math.round(r.ms * 10) / 10, violations: v, conversationId: c.conversation_id });
    return ok(res, { conversation_id: c.conversation_id, call_sid: callSid, laravel: { status: r.status, ms: Math.round(r.ms * 10) / 10 }, initiation: origen, caller_authorized: c.callerAuthorized, conversation_status: c.status, violations: v });
  }

  /** Cuerpo de una herramienta: las propiedades con `dynamic_variable` del esquema compilado se rellenan (C-A3). */
  function cuerpoHerramienta(tool, c, params) {
    const ag = c ? estado.agentes.get(c.agentId) : null;
    const esquema = ag ? esquemaDe(ag.payload, estado.herramientas, tool) : null;
    const sistema = { system__conversation_id: c?.conversation_id ?? null, system__call_sid: c?.callSid ?? null, system__caller_id: c?.remote ?? null };
    const props = esquema?.properties;
    if (C.esObjeto(props)) {
      const out = {};
      for (const [k, p] of Object.entries(props)) {
        if (C.esObjeto(p) && typeof p.dynamic_variable === 'string') out[k] = Object.hasOwn(sistema, p.dynamic_variable) ? sistema[p.dynamic_variable] : c?.dynamicVariables?.[p.dynamic_variable] ?? null;
        else if (Object.hasOwn(params, k)) out[k] = params[k];
      }
      return { cuerpo: out, desde: 'schema' };
    }
    return { cuerpo: { conversation_id: c?.conversation_id ?? null, call_sid: c?.callSid ?? null, ...params }, desde: 'defecto' };
  }

  /** /__tool?conversation_id=&tool=&variant=valid|no_auth|bad_bearer|basic_scheme|unknown_tool|oversize&params=<json> */
  async function herramienta(res, q) {
    const c = conv(q.get('conversation_id'));
    const variante = q.get('variant') ?? 'valid';
    if (!['valid', 'no_auth', 'bad_bearer', 'basic_scheme', 'unknown_tool', 'oversize'].includes(variante)) return mal(res, 'variant = valid|no_auth|bad_bearer|basic_scheme|unknown_tool|oversize');
    const tool = variante === 'unknown_tool' ? 'cobrar_tarjeta' : q.get('tool') ?? 'consultar_horarios';
    if (variante !== 'unknown_tool' && !HERRAMIENTAS.includes(tool)) return mal(res, `tool = ${HERRAMIENTAS.join('|')}`);
    let params = {};
    if (q.has('params')) {
      try {
        params = JSON.parse(q.get('params'));
      } catch {
        return mal(res, 'params no es JSON');
      }
      if (!C.esObjeto(params)) return mal(res, 'params tiene que ser un objeto JSON');
    }
    const { cuerpo, desde } = cuerpoHerramienta(tool, c, params);
    if (variante === 'oversize') cuerpo.relleno = 'x'.repeat(9000);
    const buena = autorizacionHerramienta(c, tool);
    const auth = { valid: buena, no_auth: null, bad_bearer: `Bearer ${crypto.randomBytes(24).toString('hex')}`, basic_scheme: `Basic ${Buffer.from(`elevenlabs:${buena.replace(/^Bearer /, '')}`).toString('base64')}`, unknown_tool: buena, oversize: buena }[variante];
    const r = await C.emitir({ base: P.LARAVEL_BASE, path: `${LARAVEL.tools}${tool}`, cabeceras: { 'Content-Type': 'application/json', Authorization: auth }, cuerpo: JSON.stringify(cuerpo), timeoutMs: 10000 });
    const v = [];
    const aceptada = typeof r.status === 'number' && r.status >= 200 && r.status < 300;
    if (r.status === 200) {
      if (C.pareceDatoPersonal(r.body, [c?.remote?.replace(/\D/g, '').slice(-10)])) v.push('tool_response_personal_data');
      if (Buffer.byteLength(r.body) > 8192) v.push('tool_response_size');
    }
    if (aceptada && ['no_auth', 'bad_bearer', 'basic_scheme'].includes(variante)) v.push('tool_bad_auth_accepted');
    if (aceptada && variante === 'unknown_tool') v.push('tool_outside_catalog_accepted');
    if (aceptada && variante === 'oversize') v.push('tool_oversize_accepted');
    registro.emision({ target: 'tool', tool, variant: variante, status: r.status, ms: Math.round(r.ms * 10) / 10, violations: v, conversationId: c?.conversation_id ?? null });
    let respuesta = r.body.slice(0, 2048);
    try {
      respuesta = JSON.parse(r.body);
    } catch {
      /* texto */
    }
    // expectedAuth habla de la autenticación y la forma; que la llamada esté viva y sea la sellada lo decide Laravel (D8-10).
    return ok(res, { tool, variant: variante, expectedAuth: variante === 'valid' ? 'accepted' : 'rejected', bodyFrom: desde, laravel: { status: r.status, ms: Math.round(r.ms * 10) / 10, body: respuesta }, violations: v });
  }

  /** /__emit/post-call?conversation_id=&type=&variant=&transcript=&agent_id=&version_id=&branch_id=&reason=&repeat=N&now=
   *  · con web=1&agent_id=<agente de laboratorio>: la conversación web de ese agente (la de su URL firmada; si no la hay,
   *    una nueva), sin phone_call ni call_sid (D8-23). */
  async function emitirPostLlamada(res, q) {
    let c = conv(q.get('conversation_id'));
    if (q.get('web') === '1') {
      const ag = q.get('agent_id') ?? '';
      if (!AGENT_ID.test(ag)) return mal(res, 'web=1 pide agent_id (el agente de laboratorio)');
      if (c && c.direction !== 'web') return mal(res, 'conversation_id no es una conversación web');
      c = c ?? [...estado.conversaciones.values()].filter((x) => x.direction === 'web' && x.agentId === ag).pop() ?? conversacionWeb(ag, C.ahoraS());
    }
    const repetir = q.has('repeat') ? Number(q.get('repeat')) : 1;
    if (!(Number.isInteger(repetir) && repetir >= 1 && repetir <= 20)) return mal(res, 'repeat 1–20');
    let w;
    try {
      const now = q.has('now') ? Number(q.get('now')) : C.ahoraS();
      if (!Number.isInteger(now) || now < 0) throw new Error('now no válido');
      w = postCall({ variant: q.get('variant') ?? 'valid', type: q.get('type') ?? 'post_call_transcription', now, secret: secretoPostLlamada(q.get('agent_id') ?? c?.agentId), agentId: q.get('agent_id') ?? c?.agentId ?? P.EL_VERIFY_AGENT_ID, conv: c, centinela: CENTINELA, transcript: q.get('transcript') ?? 'limpio', versionId: q.get('version_id'), branchId: q.get('branch_id'), reason: q.get('reason') });
    } catch (err) {
      return mal(res, err.message, { variants: Object.keys(VARIANTS), types: [...TYPES, ...TYPES_NULL], scripts: GUIONES });
    }
    const entregas = [];
    for (let i = 0; i < repetir; i++) {
      if (estado.webhook.desactivado) {
        entregas.push({ status: 'webhook_disabled', ms: 0 });
        continue;
      }
      const r = await C.emitir({ base: P.LARAVEL_BASE, path: LARAVEL.postCall, cabeceras: { 'Content-Type': 'application/json', 'ElevenLabs-Signature': w.header }, cuerpo: w.rawBody, timeoutMs: 10000 });
      const v = [];
      if (w.expected !== 'ok' && typeof r.status === 'number' && r.status >= 200 && r.status < 300) v.push('post_call_bad_signature_accepted');
      // Solo las entregas que ElevenLabs firmaría bien cuentan para la desactivación automática (auditoría §d.1).
      if (w.expected === 'ok') {
        if (typeof r.status === 'number' && r.status >= 200 && r.status < 300) Object.assign(estado.webhook, { fallosSeguidos: 0, ultimaBuena: Date.now() });
        else {
          estado.webhook.fallosSeguidos++;
          const vieja = estado.webhook.ultimaBuena === null || Date.now() - estado.webhook.ultimaBuena > 7 * 86400000;
          if (estado.webhook.fallosSeguidos >= 10 && vieja) {
            estado.webhook.desactivado = true;
            v.push('laravel_webhook_disabled');
          }
        }
      }
      registro.emision({ target: 'post_call', type: w.type, variant: w.variant, status: r.status, ms: Math.round(r.ms * 10) / 10, violations: v, conversationId: c?.conversation_id ?? null });
      entregas.push({ status: r.status, ms: Math.round(r.ms * 10) / 10 });
    }
    return ok(res, { type: w.type, variant: w.variant, expected: w.expected, expectedType: w.expectedType, deliveries: entregas, webhook: { ...estado.webhook } });
  }

  const servidor = http.createServer((req, res) => {
    atender(req, res).catch((err) => {
      console.error(`${NOMBRE}: ${err.message}`);
      C.json(res, 500, { detail: { status: 'internal_error', message: 'Simulador: error interno' } });
    });
  });
  C.escuchar(servidor, A.port, 'elevenlabs', registro);
}
