#!/usr/bin/env node
'use strict';
/**
 * Simulador local de la API de Twilio, SUBCUENTA DE VOZ, para el banco y el E2E del Hito 8 (OPS8-a; contrato H8
 * D8-1, D8-3 `TelephonyCarrier`, D8-4, D8-5, D8-16, D8-20 nivel 0b, D8-21; auditoría §c.3 y §d.2). Hereda la forma
 * del simulador de la sonda del H6 (CRM, `e2e/ultron-studio/mock-twilio.cjs`): forma pública de los recursos,
 * credenciales por clase y nunca su valor, registro JSONL y control GET.
 *
 * CREDENCIALES (Basic) y lo que puede cada una (D8-5; la de ElevenLabs, [por confirmar] con C-A1):
 *   laravel_key     la API key restringida de Laravel: cuenta y llamadas (leer, listar, COLGAR), números (leer), uso (leer)
 *   elevenlabs_key  la de ElevenLabs: crear, leer y actualizar llamadas; leer y actualizar números
 *   signing_token   el Auth Token de la subcuenta: SOLO firma; usarlo contra la API es una violación (se atiende, como Twilio)
 *   master          la credencial maestra (la del OTP): violación (D8-4); se atiende, como Twilio con la cuenta madre
 *   mixed / none    usuario de una y secreto de otra, o sin Basic: 401 y violación; `unknown`: 401 sin violación
 * Una key sin el permiso: 401 (código 20003) [por confirmar el código exacto de una key restringida], y si es la de
 * Laravel, violación: Laravel NUNCA crea llamadas ni toca números con la opción A (D8-1).
 *
 * RUTAS (lista CERRADA; `{sid}` tiene que ser la subcuenta de voz):
 *   GET  /2010-04-01/Accounts/{sid}.json                          cuenta (la sonda GET de la fase 2)
 *   POST /2010-04-01/Accounts/{sid}/Calls.json                    crear (solo ElevenLabs)
 *   GET  /2010-04-01/Accounts/{sid}/Calls.json                    listar (conciliación: una página, PageToken)
 *   GET  /2010-04-01/Accounts/{sid}/Calls/{CallSid}.json          leer
 *   POST /2010-04-01/Accounts/{sid}/Calls/{CallSid}.json          colgar: de Laravel, SOLO `Status=completed` (D8-20 0b)
 *   GET  /2010-04-01/Accounts/{sid}/IncomingPhoneNumbers.json     listar números
 *   GET  /2010-04-01/Accounts/{sid}/IncomingPhoneNumbers/{PN}.json   leer
 *   POST /2010-04-01/Accounts/{sid}/IncomingPhoneNumbers/{PN}.json   actualizar (solo ElevenLabs: la importación)
 *   GET  /2010-04-01/Accounts/{sid}/Usage/Records.json            uso
 *   GET  /2010-04-01/Accounts/{sid}/Usage/Records/{Today|Yesterday|ThisMonth|LastMonth|Daily|Monthly}.json
 * Crear avisos de gasto (`Usage/Triggers`), caller ID, Monitor, Conferences, Recordings… no están: violaciones.
 *
 * EMISOR: /__emit/usage-trigger firma con `X-Twilio-Signature` (HMAC-SHA1 del token de la SUBCUENTA) sobre la BASE
 * CANÓNICA (`TW_SIGN_BASE`, la de `VOICE_WEBHOOK_BASE_URL`) + la URI tal como se envía, y lo manda a `LARAVEL_BASE`
 * (127.0.0.1): un validador que use `fullUrl()` falla con la firma buena, que es justo lo que D8-4 prohíbe.
 *
 * Precios de las llamadas: los de la auditoría §d.2 (leídos el 03-11-2026, «$» sin código de moneda).
 * Uso: node sim-twilio.cjs --config <piezas en 600> --log <registro.jsonl> --port <n|0>
 */
const crypto = require('node:crypto');
const http = require('node:http');
const C = require('./sim-comun.cjs');

const NOMBRE = 'sim-twilio';
const MAX_CUERPO = 64 * 1024;
const LARAVEL_AVISOS = '/api/webhooks/voice/twilio/usage-trigger'; // D8-16
const TARIFAS = { inbound: 0.0945, outbound_mobile: 0.0377, outbound_fixed: 0.07, media_streams: 0.0044 };
const ESTADOS = ['queued', 'ringing', 'in-progress', 'completed', 'busy', 'failed', 'no-answer', 'canceled'];
const FINALES = new Set(['completed', 'busy', 'failed', 'no-answer', 'canceled']);
const CONSULTA_USO = ['Category', 'StartDate', 'EndDate', 'IncludeSubaccounts', 'PageSize', 'Page', 'PageToken'];

const RUTAS = [
  { id: 'account_get', method: 'GET', re: /^\/2010-04-01\/Accounts\/([^/]+)\.json$/, plantilla: '/2010-04-01/Accounts/{sid}.json' },
  { id: 'calls_create', method: 'POST', re: /^\/2010-04-01\/Accounts\/([^/]+)\/Calls\.json$/, plantilla: '/2010-04-01/Accounts/{sid}/Calls.json' },
  { id: 'calls_list', method: 'GET', re: /^\/2010-04-01\/Accounts\/([^/]+)\/Calls\.json$/, plantilla: '/2010-04-01/Accounts/{sid}/Calls.json', query: ['PageSize', 'Page', 'PageToken', 'StartTime>', 'StartTime<', 'StartTime', 'EndTime>', 'EndTime<', 'Status', 'ParentCallSid'] },
  { id: 'calls_get', method: 'GET', re: /^\/2010-04-01\/Accounts\/([^/]+)\/Calls\/([^/]+)\.json$/, plantilla: '/2010-04-01/Accounts/{sid}/Calls/{CallSid}.json' },
  { id: 'calls_update', method: 'POST', re: /^\/2010-04-01\/Accounts\/([^/]+)\/Calls\/([^/]+)\.json$/, plantilla: '/2010-04-01/Accounts/{sid}/Calls/{CallSid}.json' },
  { id: 'numbers_list', method: 'GET', re: /^\/2010-04-01\/Accounts\/([^/]+)\/IncomingPhoneNumbers\.json$/, plantilla: '/2010-04-01/Accounts/{sid}/IncomingPhoneNumbers.json', query: ['PageSize', 'Page', 'PageToken'] },
  { id: 'numbers_get', method: 'GET', re: /^\/2010-04-01\/Accounts\/([^/]+)\/IncomingPhoneNumbers\/([^/]+)\.json$/, plantilla: '/2010-04-01/Accounts/{sid}/IncomingPhoneNumbers/{PhoneNumberSid}.json' },
  { id: 'numbers_update', method: 'POST', re: /^\/2010-04-01\/Accounts\/([^/]+)\/IncomingPhoneNumbers\/([^/]+)\.json$/, plantilla: '/2010-04-01/Accounts/{sid}/IncomingPhoneNumbers/{PhoneNumberSid}.json' },
  { id: 'usage_records', method: 'GET', re: /^\/2010-04-01\/Accounts\/([^/]+)\/Usage\/Records\.json$/, plantilla: '/2010-04-01/Accounts/{sid}/Usage/Records.json', query: CONSULTA_USO },
  { id: 'usage_records_sub', method: 'GET', re: /^\/2010-04-01\/Accounts\/([^/]+)\/Usage\/Records\/(Today|Yesterday|ThisMonth|LastMonth|Daily|Monthly)\.json$/, plantilla: '/2010-04-01/Accounts/{sid}/Usage/Records/{Periodo}.json', query: CONSULTA_USO },
];
const TODAS = new Set(RUTAS.map((r) => r.id));
const PERMISOS = {
  laravel_key: new Set(['account_get', 'calls_list', 'calls_get', 'calls_update', 'numbers_list', 'numbers_get', 'usage_records', 'usage_records_sub']),
  elevenlabs_key: new Set(['calls_create', 'calls_list', 'calls_get', 'calls_update', 'numbers_list', 'numbers_get', 'numbers_update']),
  signing_token: TODAS,
  master: TODAS,
};

const VIOLACIONES = {
  unknown_route: 'ruta no listada (fila OPS8)',
  method_not_allowed: 'verbo no listado en una ruta conocida (fila OPS8)',
  account_sid_mismatch: 'la ruta no es de la subcuenta de voz (p. ej. la cuenta madre del OTP)',
  call_sid_pattern: 'CallSid que no casa con CA + 32 hex',
  number_sid_pattern: 'PhoneNumberSid que no casa con PN + 32 hex',
  no_basic_auth: 'sin Authorization: Basic',
  credential_mixed: 'usuario de una credencial y secreto de otra',
  signing_token_used_for_api: 'el Auth Token de la subcuenta contra la API: solo firma (D8-5)',
  master_credential_used: 'la credencial maestra (la del OTP) en la voz (D8-4)',
  call_create_by_laravel: 'Laravel creando una llamada en Twilio: con la opción A solo la crea ElevenLabs (D8-1, D8-5)',
  number_update_by_laravel: 'Laravel cambiando un número: su key solo lee números (D8-5)',
  'permission_denied:*': 'otra operación sin permiso para la key que la pide',
  call_update_beyond_hangup: 'Laravel actualizando una llamada con algo distinto de exactamente Status=completed (D8-3, D8-20)',
  'unexpected_query:*': 'parámetro de consulta fuera de la lista de la ruta',
  'phone_in_query:*': 'un teléfono en la consulta (To, From, PhoneNumber…): acabaría en registros (D8-17)',
  page_token_forged: 'un PageToken que el simulador no emitió',
  get_with_body: 'GET con cuerpo',
  content_type: 'POST sin Content-Type application/x-www-form-urlencoded',
  from_not_voice_number: 'llamada creada con un From que no es el número dedicado de voz',
  from_is_whatsapp_number: 'llamada creada con el número de WhatsApp como From (D8-5, R-13)',
  record_enabled: 'llamada creada con Record=true (P-7)',
  usage_trigger_bad_signature_accepted: 'Laravel aceptó (2xx) un aviso de gasto con firma mala (D8-16)',
  control_method: 'verbo distinto de GET en el control',
};

/**
 * Variantes del aviso de gasto. `expected`: accepted (2xx), rejected (401/403) o signature_ok (no 401/403: la firma
 * vale aunque el manejador pueda rechazar el contenido). `signed_over_real_url` y `scheme_flip` solo tienen sentido
 * con TW_SIGN_BASE ≠ LARAVEL_BASE (el banco usa una base canónica https en .invalid).
 */
const VARIANTES_AVISO = {
  valid: { expected: 'accepted' },
  replay: { expected: 'accepted', repeat: 2 },
  master_token: { token: 'master', expected: 'rejected' },
  wrong_token: { token: 'random', expected: 'rejected' },
  tampered_param: { tamper: true, expected: 'rejected' },
  extra_param: { extra: true, expected: 'rejected' },
  missing_header: { header: 'absent', expected: 'rejected' },
  empty_header: { header: 'empty', expected: 'rejected' },
  scheme_flip: { scheme: true, expected: 'rejected' },
  signed_over_real_url: { real: true, expected: 'rejected' },
  query_order: { query: 'b=2&a=1', expected: 'accepted' },
  repeated_keys: { repeated: true, expected: 'signature_ok' },
  json_body_sha256: { json: true, expected: 'signature_ok' },
};

/** Los parámetros del aviso de gasto (UsageTrigger, documentación de Twilio), en el orden en que van. */
function paramsAviso({ sid, utSid, categoria = 'totalprice', triggerBy = 'price', triggerValue = '4.00', currentValue = '4.12', recurring = 'daily', ahora = Date.now() }) {
  const dia = new Date(ahora).toISOString().slice(0, 10);
  return [
    ['AccountSid', sid], ['UsageTriggerSid', utSid], ['DateFired', C.fechaTwilio(ahora)], ['Recurring', recurring],
    ['UsageCategory', categoria], ['TriggerBy', triggerBy], ['TriggerValue', triggerValue], ['CurrentValue', currentValue],
    ['UsageRecordUri', `/2010-04-01/Accounts/${sid}/Usage/Records/Today.json?Category=${categoria}`],
    ['IdempotencyToken', `${sid}-FIRES-${utSid}-${dia}`],
  ];
}

module.exports = { RUTAS, VIOLACIONES, VARIANTES_AVISO, TARIFAS, PERMISOS, paramsAviso, LARAVEL_AVISOS };

// ── Servidor ─────────────────────────────────────────────────────────────────────────────────────
if (require.main === module) {
  const A = C.argumentos(process.argv, NOMBRE);
  const P = C.leerPiezas(A.config, NOMBRE);
  const sid = (k, pre) => new RegExp(`^${pre}[0-9a-f]{32}$`).test(P[k] ?? '') || C.fatal(NOMBRE, `falta ${k} (${pre} + 32 hex)`);
  sid('TW_ACCOUNT_SID', 'AC'); sid('TW_MASTER_SID', 'AC'); sid('TW_LARAVEL_KEY_SID', 'SK'); sid('TW_EL_KEY_SID', 'SK'); sid('TW_VOICE_NUMBER_SID', 'PN');
  for (const k of ['TW_LARAVEL_KEY_SECRET', 'TW_EL_KEY_SECRET', 'TW_SIGNING_TOKEN', 'TW_MASTER_TOKEN']) if ((P[k] ?? '').length < 16) C.fatal(NOMBRE, `falta ${k} (≥ 16)`);
  if (!C.E164.test(P.VOICE_NUMBER ?? '')) C.fatal(NOMBRE, 'falta VOICE_NUMBER (E.164)');
  if (!C.esLocal(P.LARAVEL_BASE ?? '')) C.fatal(NOMBRE, 'LARAVEL_BASE tiene que ser http://127.0.0.1:<puerto>');
  const BASE_FIRMA = (P.TW_SIGN_BASE || P.LARAVEL_BASE).replace(/\/+$/, '');
  if (!/^https?:\/\/[^/?#@]+$/.test(BASE_FIRMA)) C.fatal(NOMBRE, 'TW_SIGN_BASE tiene que ser una base http(s) sin ruta, usuario, consulta ni fragmento');
  const SID = P.TW_ACCOUNT_SID;
  const CENTINELA = P.SENTINEL_TWILIO || 'banco-centinela-twilio';

  const registro = new C.Registro(A.log, 'twilio');
  const fallos = new C.Fallos(RUTAS.map((r) => r.id));
  const estado = {};
  function sembrar() {
    estado.llamadas = new Map();
    estado.tokens = new Set();
    estado.numero = { sid: P.TW_VOICE_NUMBER_SID, phone: P.VOICE_NUMBER, voiceUrl: 'https://api.elevenlabs.invalid/twilio/inbound_call', updates: 0 };
  }
  sembrar();

  const errorTwilio = (status, code, message) => ({ status, body: { code, message, more_info: `https://www.twilio.com/docs/errors/${code}`, status }, extra: status === 401 ? { 'WWW-Authenticate': 'Basic realm="Twilio API"' } : {} });

  function credencial(cabecera) {
    const m = /^Basic\s+([A-Za-z0-9+/=]+)$/.exec(cabecera ?? '');
    if (!m) return 'none';
    const plano = Buffer.from(m[1], 'base64').toString('utf8');
    const i = plano.indexOf(':');
    if (i < 0) return 'unknown';
    const u = plano.slice(0, i);
    const p = plano.slice(i + 1);
    const pares = [
      ['laravel_key', P.TW_LARAVEL_KEY_SID, P.TW_LARAVEL_KEY_SECRET], ['elevenlabs_key', P.TW_EL_KEY_SID, P.TW_EL_KEY_SECRET],
      ['signing_token', SID, P.TW_SIGNING_TOKEN], ['master', P.TW_MASTER_SID, P.TW_MASTER_TOKEN], ['master', SID, P.TW_MASTER_TOKEN],
    ];
    for (const [clase, usr, sec] of pares) if (C.igual(u, usr) && C.igual(p, sec)) return clase;
    if (pares.some(([, usr]) => C.igual(u, usr)) && pares.some(([, , sec]) => C.igual(p, sec))) return 'mixed';
    return 'unknown';
  }

  // ── Recursos con la forma pública ───────────────────────────────────────────────────────────
  const uriCuenta = `/2010-04-01/Accounts/${SID}`;
  function vistaLlamada(c) {
    return {
      account_sid: SID, annotation: null, answered_by: c.answeredBy, api_version: '2010-04-01', caller_name: null,
      date_created: C.fechaTwilio(c.creada), date_updated: C.fechaTwilio(c.actualizada), direction: c.direction,
      duration: FINALES.has(c.status) ? String(c.duration) : null, end_time: c.fin ? C.fechaTwilio(c.fin) : null, forwarded_from: null,
      from: c.from, from_formatted: c.from, group_sid: null, parent_call_sid: c.parent ?? null, phone_number_sid: c.direction === 'inbound' ? P.TW_VOICE_NUMBER_SID : null,
      price: c.price, price_unit: 'USD', queue_time: '0', sid: c.sid, start_time: c.inicio ? C.fechaTwilio(c.inicio) : null, status: c.status,
      subresource_uris: { notifications: `${uriCuenta}/Calls/${c.sid}/Notifications.json`, recordings: `${uriCuenta}/Calls/${c.sid}/Recordings.json` },
      to: c.to, to_formatted: c.to, trunk_sid: null, uri: `${uriCuenta}/Calls/${c.sid}.json`,
    };
  }
  function vistaNumero() {
    const n = estado.numero;
    return {
      account_sid: SID, address_requirements: 'none', api_version: '2010-04-01', beta: false, capabilities: { voice: true, sms: false, mms: false, fax: false },
      date_created: C.fechaTwilio(Date.now() - 30 * 86400000), date_updated: C.fechaTwilio(Date.now() - 86400000), friendly_name: 'Voz Iron Body (banco)',
      origin: 'twilio', phone_number: n.phone, sid: n.sid, status: 'in-use', sms_url: '', status_callback: '', uri: `${uriCuenta}/IncomingPhoneNumbers/${n.sid}.json`,
      voice_application_sid: '', voice_caller_id_lookup: false, voice_method: 'POST', voice_receive_mode: 'voice', voice_url: n.voiceUrl,
    };
  }
  const tarifa = (c) => (c.direction === 'inbound' ? TARIFAS.inbound : /^\+573/.test(c.to) ? TARIFAS.outbound_mobile : TARIFAS.outbound_fixed) + TARIFAS.media_streams;
  /** Minutos que se facturan: por minuto empezado, y una llamada completada paga al menos uno. */
  const minutosFacturados = (c) => (c.status === 'completed' ? Math.max(1, Math.ceil(c.duration / 60)) : 0);
  function terminar(c, status, duracion) {
    const ahora = Date.now();
    c.status = status;
    c.actualizada = ahora;
    c.fin = ahora;
    c.duration = status === 'completed' ? duracion : 0;
    c.price = status === 'completed' ? `-${(minutosFacturados(c) * tarifa(c)).toFixed(5)}` : null;
  }
  function nuevaLlamada({ direction, from, to, status = 'queued', origen }) {
    const ahora = Date.now();
    const c = { sid: C.sidTwilio('CA'), direction, from, to, status, creada: ahora, actualizada: ahora, inicio: status === 'in-progress' ? ahora : null, fin: null, duration: 0, price: null, answeredBy: null, origen };
    estado.llamadas.set(c.sid, c);
    return c;
  }
  const pagina = (lista, clave, tam, pag, base) => {
    const desde = pag * tam;
    const items = lista.slice(desde, desde + tam);
    const mas = desde + tam < lista.length;
    let siguiente = null;
    if (mas) {
      const tok = `PA${crypto.randomBytes(16).toString('hex')}`;
      estado.tokens.add(`${tok}:${pag + 1}`);
      siguiente = `${base}${base.includes('?') ? '&' : '?'}PageSize=${tam}&Page=${pag + 1}&PageToken=${tok}`;
    }
    return { [clave]: items, end: Math.max(0, desde + items.length - 1), first_page_uri: `${base}${base.includes('?') ? '&' : '?'}PageSize=${tam}&Page=0`, next_page_uri: siguiente, page: pag, page_size: tam, previous_page_uri: null, start: desde, uri: base };
  };
  function leerPagina(q, v) {
    const tam = q.has('PageSize') ? Number(q.get('PageSize')) : 50;
    const pag = q.has('Page') ? Number(q.get('Page')) : 0;
    if (!(Number.isInteger(tam) && tam >= 1 && tam <= 1000) || !(Number.isInteger(pag) && pag >= 0)) return { error: errorTwilio(400, 20001, 'Invalid paging parameters') };
    if (pag > 0) {
      const tok = q.get('PageToken');
      if (!tok || !estado.tokens.has(`${tok}:${pag}`)) {
        if (tok) v.push('page_token_forged');
        return { error: errorTwilio(400, 20001, 'Invalid PageToken') };
      }
    }
    return { tam, pag };
  }
  const instante = (s) => (/^\d{4}-\d{2}-\d{2}$/.test(s) ? Date.parse(`${s}T00:00:00Z`) : Date.parse(s));

  // ── Uso: agregado de las llamadas por periodo y categoría ──────────────────────────────────
  const dia = (ms) => new Date(ms).toISOString().slice(0, 10);
  function periodo(nombre, q) {
    const hoy = new Date();
    const y = hoy.getUTCFullYear();
    const mo = hoy.getUTCMonth();
    const d0 = Date.UTC(y, mo, hoy.getUTCDate());
    switch (nombre) {
      case 'Today': return [[d0, d0 + 86400000]];
      case 'Yesterday': return [[d0 - 86400000, d0]];
      case 'ThisMonth': return [[Date.UTC(y, mo, 1), Date.UTC(y, mo + 1, 1)]];
      case 'LastMonth': return [[Date.UTC(y, mo - 1, 1), Date.UTC(y, mo, 1)]];
      case 'Daily': {
        const ini = q.has('StartDate') ? instante(q.get('StartDate')) : d0 - 6 * 86400000;
        const fin = q.has('EndDate') ? instante(q.get('EndDate')) + 86400000 : d0 + 86400000;
        const out = [];
        for (let t = ini; t < fin && out.length < 400; t += 86400000) out.push([t, t + 86400000]);
        return out;
      }
      case 'Monthly': return [[Date.UTC(y, mo, 1), Date.UTC(y, mo + 1, 1)]];
      default: {
        const ini = q.has('StartDate') ? instante(q.get('StartDate')) : Date.UTC(y - 1, mo, 1);
        const fin = q.has('EndDate') ? instante(q.get('EndDate')) + 86400000 : d0 + 86400000;
        return [[ini, fin]];
      }
    }
  }
  function registrosDeUso(nombre, q) {
    const cats = { calls: () => true, 'calls-inbound': (c) => c.direction === 'inbound', 'calls-outbound': (c) => c.direction !== 'inbound', totalprice: () => true };
    const pedida = q.get('Category');
    const out = [];
    for (const [ini, fin] of periodo(nombre, q)) {
      const enPeriodo = [...estado.llamadas.values()].filter((c) => c.status === 'completed' && c.creada >= ini && c.creada < fin);
      for (const [cat, f] of Object.entries(cats)) {
        if (pedida && pedida !== cat) continue;
        const ls = enPeriodo.filter(f);
        const precio = ls.reduce((s, c) => s + Math.abs(Number(c.price ?? 0)), 0);
        const minutos = ls.reduce((s, c) => s + minutosFacturados(c), 0);
        out.push({
          account_sid: SID, api_version: '2010-04-01', as_of: new Date().toISOString().replace(/\.\d{3}Z$/, '+00:00'), category: cat,
          count: String(ls.length), count_unit: 'calls', description: cat === 'totalprice' ? 'Total Price' : `Voice Minutes (${cat})`,
          end_date: dia(fin - 1), price: precio.toFixed(5), price_unit: 'usd', start_date: dia(ini),
          subresource_uris: {}, uri: `${uriCuenta}/Usage/Records/${nombre}.json?Category=${cat}`,
          usage: cat === 'totalprice' ? precio.toFixed(5) : String(minutos), usage_unit: cat === 'totalprice' ? 'usd' : 'minutes',
        });
      }
    }
    return out;
  }

  // ── Manejadores ─────────────────────────────────────────────────────────────────────────────
  const H = {
    account_get(ctx) {
      const c = { date_created: C.fechaTwilio(Date.now() - 60 * 86400000), date_updated: C.fechaTwilio(Date.now() - 86400000), friendly_name: 'Voz (banco)', owner_account_sid: P.TW_MASTER_SID, sid: SID, status: 'active', subresource_uris: { calls: `${uriCuenta}/Calls.json`, incoming_phone_numbers: `${uriCuenta}/IncomingPhoneNumbers.json` }, type: 'Full', uri: `${uriCuenta}.json` };
      // Como la de verdad: quien entra con el token de la cuenta lo recibe en la ficha (y tiene que descartarlo).
      return { status: 200, body: ctx.cred === 'signing_token' ? { auth_token: P.TW_SIGNING_TOKEN, ...c } : c };
    },
    calls_create(ctx) {
      const f = ctx.form;
      const to = f.get('To') ?? '';
      const from = f.get('From') ?? '';
      if (!C.E164.test(to)) return errorTwilio(400, 21211, "Invalid 'To' Phone Number");
      if (from === P.WHATSAPP_NUMBER && P.WHATSAPP_NUMBER) ctx.v.push('from_is_whatsapp_number');
      else if (from !== P.VOICE_NUMBER) ctx.v.push('from_not_voice_number');
      if (/^true$/i.test(f.get('Record') ?? '')) ctx.v.push('record_enabled');
      if (!f.get('Twiml') && !f.get('Url') && !f.get('ApplicationSid')) return errorTwilio(400, 21205, 'Url parameter is required');
      const c = nuevaLlamada({ direction: 'outbound-api', from, to, origen: ctx.cred });
      ctx.e.callSid = c.sid;
      return { status: 201, body: vistaLlamada(c) };
    },
    calls_list(ctx) {
      const q = ctx.u.searchParams;
      const p = leerPagina(q, ctx.v);
      if (p.error) return p.error;
      const desde = q.has('StartTime>') ? instante(q.get('StartTime>')) : -Infinity;
      const hasta = q.has('StartTime<') ? instante(q.get('StartTime<')) : Infinity;
      if (Number.isNaN(desde) || Number.isNaN(hasta)) return errorTwilio(400, 20001, 'Invalid StartTime');
      const st = q.get('Status');
      if (st !== null && !ESTADOS.includes(st)) return errorTwilio(400, 20001, 'Invalid Status');
      const lista = [...estado.llamadas.values()].filter((c) => c.creada >= desde && c.creada < hasta && (st === null || c.status === st)).sort((a, b) => b.creada - a.creada).map(vistaLlamada);
      const filtros = [...q.entries()].filter(([k]) => !['PageSize', 'Page', 'PageToken'].includes(k)).map(([k, x]) => `${encodeURIComponent(k)}=${encodeURIComponent(x)}`).join('&');
      return { status: 200, body: pagina(lista, 'calls', p.tam, p.pag, `${uriCuenta}/Calls.json${filtros ? `?${filtros}` : ''}`) };
    },
    calls_get(ctx) {
      const c = estado.llamadas.get(ctx.params[1]);
      return c ? { status: 200, body: vistaLlamada(c) } : errorTwilio(404, 20404, 'The requested resource was not found');
    },
    calls_update(ctx) {
      const c = estado.llamadas.get(ctx.params[1]);
      const pares = [...ctx.form.entries()];
      if (ctx.cred === 'laravel_key' && !(pares.length === 1 && pares[0][0] === 'Status' && pares[0][1] === 'completed')) ctx.v.push('call_update_beyond_hangup');
      if (!c) return errorTwilio(404, 20404, 'The requested resource was not found');
      ctx.e.callSid = c.sid;
      const st = ctx.form.get('Status');
      // Idempotente: una llamada ya terminada no cambia [por confirmar la respuesta exacta de Twilio en ese caso].
      if (st && !FINALES.has(c.status)) {
        if (st === 'completed') terminar(c, c.status === 'in-progress' ? 'completed' : 'canceled', c.inicio ? Math.round((Date.now() - c.inicio) / 1000) : 0);
        else if (st === 'canceled' && ['queued', 'ringing'].includes(c.status)) terminar(c, 'canceled', 0);
      }
      return { status: 200, body: vistaLlamada(c) };
    },
    numbers_list(ctx) {
      const p = leerPagina(ctx.u.searchParams, ctx.v);
      if (p.error) return p.error;
      return { status: 200, body: pagina([vistaNumero()], 'incoming_phone_numbers', p.tam, p.pag, `${uriCuenta}/IncomingPhoneNumbers.json`) };
    },
    numbers_get(ctx) {
      return ctx.params[1] === estado.numero.sid ? { status: 200, body: vistaNumero() } : errorTwilio(404, 20404, 'The requested resource was not found');
    },
    numbers_update(ctx) {
      if (ctx.params[1] !== estado.numero.sid) return errorTwilio(404, 20404, 'The requested resource was not found');
      if (ctx.form.get('VoiceUrl')) estado.numero.voiceUrl = ctx.form.get('VoiceUrl');
      estado.numero.updates++;
      return { status: 200, body: vistaNumero() };
    },
    usage_records(ctx) {
      const p = leerPagina(ctx.u.searchParams, ctx.v);
      if (p.error) return p.error;
      return { status: 200, body: pagina(registrosDeUso('All', ctx.u.searchParams), 'usage_records', p.tam, p.pag, `${uriCuenta}/Usage/Records.json`) };
    },
    usage_records_sub(ctx) {
      const p = leerPagina(ctx.u.searchParams, ctx.v);
      if (p.error) return p.error;
      return { status: 200, body: pagina(registrosDeUso(ctx.params[1], ctx.u.searchParams), 'usage_records', p.tam, p.pag, `${uriCuenta}/Usage/Records/${ctx.params[1]}.json`) };
    },
  };

  async function atender(req, res) {
    const t0 = process.hrtime.bigint();
    const u = new URL(req.url, 'http://127.0.0.1');
    const metodo = /^[A-Z]{1,10}$/.test(req.method) ? req.method : 'OTRO';
    if (u.pathname.startsWith('/__')) return control(req, res, u, metodo);
    const cuerpo = await C.leerCuerpo(req, MAX_CUERPO);
    const candidatas = RUTAS.filter((r) => r.re.test(u.pathname));
    const ruta = candidatas.find((r) => r.method === metodo);
    const v = [];
    const e = { method: metodo, route: (ruta ?? candidatas[0])?.plantilla ?? C.sanearRuta(u.pathname), routeId: ruta?.id ?? null, credential: null, status: null, ms: null, violations: v, fault: null };
    const fin = (r) => {
      e.status = r.status;
      e.ms = Math.round(Number(process.hrtime.bigint() - t0) / 1e5) / 10;
      registro.peticion(e);
      if (r.html) {
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        res.end(`<html><body>Simulador: esto no es JSON. ${CENTINELA}</body></html>`);
      } else C.json(res, r.status, r.body ?? {}, { 'Twilio-Request-Id': `RQ${crypto.randomBytes(16).toString('hex')}`, ...(r.extra ?? {}) });
    };
    const retener = (estadoFinal) => {
      const corte = setTimeout(() => req.socket.destroy(), 120000);
      res.on('close', () => {
        clearTimeout(corte);
        e.status = estadoFinal;
        e.ms = Math.round(Number(process.hrtime.bigint() - t0) / 1e5) / 10;
        registro.peticion(e);
      });
    };
    if (candidatas.length === 0) {
      v.push('unknown_route');
      return fin(errorTwilio(404, 20404, 'The requested resource was not found'));
    }
    if (!ruta) {
      v.push('method_not_allowed');
      return fin(errorTwilio(405, 20004, 'Method not allowed'));
    }
    let params;
    try {
      params = ruta.re.exec(u.pathname).slice(1).map(decodeURIComponent);
    } catch {
      v.push('unknown_route');
      return fin(errorTwilio(404, 20404, 'The requested resource was not found'));
    }
    if (params[0] !== SID) {
      v.push('account_sid_mismatch');
      return fin(errorTwilio(404, 20404, 'The requested resource was not found'));
    }
    if (['calls_get', 'calls_update'].includes(ruta.id) && !/^CA[0-9a-f]{32}$/.test(params[1])) {
      v.push('call_sid_pattern');
      return fin(errorTwilio(404, 20404, 'The requested resource was not found'));
    }
    if (['numbers_get', 'numbers_update'].includes(ruta.id) && !/^PN[0-9a-f]{32}$/.test(params[1])) {
      v.push('number_sid_pattern');
      return fin(errorTwilio(404, 20404, 'The requested resource was not found'));
    }
    e.credential = credencial(req.headers.authorization);
    if (e.credential === 'none') v.push('no_basic_auth');
    if (e.credential === 'mixed') v.push('credential_mixed');
    if (e.credential === 'signing_token') v.push('signing_token_used_for_api');
    if (e.credential === 'master') v.push('master_credential_used');
    if (e.credential === 'unknown') registro.stats.authRejected++;
    if (['none', 'mixed', 'unknown'].includes(e.credential)) return fin(errorTwilio(401, 20003, 'Authenticate'));
    if (!PERMISOS[e.credential].has(ruta.id)) {
      if (e.credential === 'laravel_key') v.push(ruta.id === 'calls_create' ? 'call_create_by_laravel' : ruta.id === 'numbers_update' ? 'number_update_by_laravel' : `permission_denied:${ruta.id}`);
      else v.push(`permission_denied:${ruta.id}`);
      return fin(errorTwilio(401, 20003, 'Authenticate'));
    }
    for (const k of new Set(u.searchParams.keys())) {
      if ((ruta.query ?? []).includes(k)) continue;
      v.push(/^(To|From|PhoneNumber|Caller|Called)$/.test(k) ? `phone_in_query:${k}` : `unexpected_query:${C.sanearClave(k)}`);
    }
    if (cuerpo.excedido) return fin(errorTwilio(413, 20001, 'Request too large'));
    let form = new URLSearchParams();
    if (metodo === 'GET') {
      if (cuerpo.buf.length > 0) v.push('get_with_body');
    } else {
      if (!/^application\/x-www-form-urlencoded\b/i.test(req.headers['content-type'] ?? '')) v.push('content_type');
      form = new URLSearchParams(cuerpo.buf.toString('utf8'));
    }
    const f = fallos.tomar(ruta.id);
    if (f) e.fault = f.kind;
    if (f?.kind === 'status') return fin(errorTwilio(f.status, f.status === 429 ? 20429 : 20500, 'Error simulado del banco'));
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
    const r = H[ruta.id]({ u, params, form, v, e, cred: e.credential });
    if (f?.kind === 'hang_after_effect') return retener('held_after_effect');
    return fin(r);
  }

  // ── Control ─────────────────────────────────────────────────────────────────────────────────
  const ok = (res, body) => C.json(res, 200, body);
  const mal = (res, error, extra = {}) => C.json(res, 400, { ok: false, error, ...extra });

  async function control(req, res, u, metodo) {
    if (metodo !== 'GET') {
      registro.control(metodo, u.pathname, ['control_method']);
      return C.json(res, 405, { ok: false, error: 'el control es solo GET' });
    }
    registro.control(metodo, u.pathname, []);
    const q = u.searchParams;
    const extra = () => ({ state: { calls: estado.llamadas.size, byStatus: [...estado.llamadas.values()].reduce((m, c) => ({ ...m, [c.status]: (m[c.status] ?? 0) + 1 }), {}), numberUpdates: estado.numero.updates } });
    if (C.controlComun(u, res, registro, fallos, extra)) return;
    switch (u.pathname) {
      case '/__surface':
        return ok(res, { routes: RUTAS.map(({ id, method, plantilla, query }) => ({ id, method, path: plantilla, query: query ?? [] })), permissions: Object.fromEntries(Object.entries(PERMISOS).map(([k, s]) => [k, [...s]])), violations: VIOLACIONES, usageTriggerVariants: Object.keys(VARIANTES_AVISO), laravel: LARAVEL_AVISOS, signBase: BASE_FIRMA, rates: TARIFAS });
      case '/__reset':
        if (q.get('state') !== '1') return mal(res, 'usa /__reset?state=1 (el registro y las violaciones no se borran)');
        sembrar();
        return ok(res, { ok: true, reset: 'state' });
      case '/__state': {
        const c = estado.llamadas.get(q.get('sid') ?? '');
        if (q.has('sid')) return c ? ok(res, { sid: c.sid, status: c.status, direction: c.direction, duration: c.duration, price: c.price, origin: c.origen, toLast4: c.to.slice(-4) }) : C.json(res, 404, { ok: false, error: 'no existe' });
        return ok(res, { calls: [...estado.llamadas.values()].map((x) => ({ sid: x.sid, status: x.status, direction: x.direction, origin: x.origen })) });
      }
      case '/__seed-call': {
        const direction = q.get('direction') ?? 'outbound-api';
        const st = q.get('status') ?? 'completed';
        const from = q.get('from') ?? (direction === 'inbound' ? '' : P.VOICE_NUMBER);
        const to = q.get('to') ?? (direction === 'inbound' ? P.VOICE_NUMBER : '');
        if (!['inbound', 'outbound-api'].includes(direction) || !ESTADOS.includes(st) || !C.E164.test(from) || !C.E164.test(to)) return mal(res, 'direction=inbound|outbound-api&from=<E.164>&to=<E.164>&status=<estado de Twilio>[&parent_call_sid=CA…]');
        // Un tramo de transferencia es una llamada hija de la conocida (TEST8-a, DF-W4): se concilia con su madre.
        const madre = q.get('parent_call_sid');
        if (madre !== null && !estado.llamadas.has(madre)) return mal(res, 'parent_call_sid no es una llamada conocida');
        const c = nuevaLlamada({ direction, from, to, status: FINALES.has(st) ? 'in-progress' : st, origen: q.get('created_by') ?? 'seed' });
        if (madre) c.parent = madre;
        if (FINALES.has(st)) {
          c.inicio = Date.now();
          terminar(c, st, Number(q.get('duration') ?? 30));
        }
        return ok(res, { ok: true, sid: c.sid, status: c.status });
      }
      case '/__call-status': {
        const c = estado.llamadas.get(q.get('sid') ?? '');
        const st = q.get('status') ?? '';
        if (!c) return C.json(res, 404, { ok: false, error: 'llamada desconocida' });
        if (!ESTADOS.includes(st)) return mal(res, `status = ${ESTADOS.join('|')}`);
        if (FINALES.has(c.status)) return C.json(res, 409, { ok: false, error: 'un estado final no cambia', status: c.status });
        if (FINALES.has(st)) terminar(c, st, Number(q.get('duration') ?? 0));
        else {
          c.status = st;
          c.actualizada = Date.now();
          if (st === 'in-progress') c.inicio = c.inicio ?? Date.now();
        }
        if (q.has('answered_by')) c.answeredBy = q.get('answered_by');
        return ok(res, { ok: true, sid: c.sid, status: c.status, price: c.price });
      }
      case '/__emit/usage-trigger':
        return emitirAviso(res, q);
      default:
        return C.json(res, 404, { ok: false, error: 'control desconocido' });
    }
  }

  async function emitirAviso(res, q) {
    const variante = q.get('variant') ?? 'valid';
    const V = VARIANTES_AVISO[variante];
    if (!V) return mal(res, 'variante desconocida', { variants: Object.keys(VARIANTES_AVISO) });
    let pares = paramsAviso({ sid: SID, utSid: C.sidTwilio('UT'), categoria: q.get('category') ?? 'totalprice', triggerBy: q.get('trigger_by') ?? 'price', triggerValue: q.get('trigger_value') ?? '4.00', currentValue: q.get('current_value') ?? '4.12', recurring: q.get('recurring') ?? 'daily' });
    if (V.repeated) pares = [...pares, ['Tag', 'b'], ['Tag', 'a']];
    const consulta = V.query ?? '';
    let ruta = `${LARAVEL_AVISOS}${consulta ? `?${consulta}` : ''}`;
    const token = V.token === 'master' ? P.TW_MASTER_TOKEN : V.token === 'random' ? crypto.randomBytes(16).toString('hex') : P.TW_SIGNING_TOKEN;
    let base = BASE_FIRMA;
    if (V.scheme) base = base.startsWith('https:') ? `http:${base.slice(6)}` : `https:${base.slice(5)}`;
    if (V.real) base = P.LARAVEL_BASE.replace(/\/+$/, '');
    let cuerpo;
    let tipo = 'application/x-www-form-urlencoded';
    let firma;
    if (V.json) {
      cuerpo = JSON.stringify(Object.fromEntries(pares));
      ruta = `${ruta}${ruta.includes('?') ? '&' : '?'}bodySHA256=${crypto.createHash('sha256').update(cuerpo).digest('hex')}`;
      tipo = 'application/json';
      firma = C.firmaTwilio(token, `${base}${ruta}`, []);
    } else {
      firma = C.firmaTwilio(token, `${base}${ruta}`, pares);
      let enviados = pares;
      if (V.tamper) enviados = enviados.map(([k, x]) => (k === 'CurrentValue' ? [k, '999.99'] : [k, x]));
      if (V.extra) enviados = [...enviados, ['Evil', '1']];
      cuerpo = new URLSearchParams(enviados).toString();
    }
    const cab = { 'Content-Type': tipo, 'User-Agent': 'TwilioProxy/1.1 (banco)', 'X-Twilio-Signature': V.header === 'absent' ? null : V.header === 'empty' ? '' : firma };
    const veces = V.repeat ?? (q.has('repeat') ? Number(q.get('repeat')) : 1);
    if (!(Number.isInteger(veces) && veces >= 1 && veces <= 20)) return mal(res, 'repeat 1–20');
    const entregas = [];
    for (let i = 0; i < veces; i++) {
      const r = await C.emitir({ base: P.LARAVEL_BASE, path: ruta, cabeceras: cab, cuerpo, timeoutMs: 10000 });
      const v = [];
      if (V.expected === 'rejected' && typeof r.status === 'number' && r.status >= 200 && r.status < 300) v.push('usage_trigger_bad_signature_accepted');
      registro.emision({ target: 'usage_trigger', variant: variante, status: r.status, ms: Math.round(r.ms * 10) / 10, violations: v });
      entregas.push({ status: r.status, ms: Math.round(r.ms * 10) / 10, violations: v });
    }
    return ok(res, { variant: variante, expected: V.expected, signedOver: `${base}${ruta}`, deliveries: entregas });
  }

  const servidor = http.createServer((req, res) => {
    atender(req, res).catch((err) => {
      console.error(`${NOMBRE}: ${err.message}`);
      C.json(res, 500, { code: 20500, message: 'Simulador: error interno', status: 500 });
    });
  });
  C.escuchar(servidor, A.port, 'twilio', registro);
}
