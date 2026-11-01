// API simulada de las INTEGRACIONES (Hito 6) para la prueba en Chrome del paso 3
// del CRM. Responde a lo que pide el CRM a 127.0.0.1:8080 sin backend: las
// formas salen del fixture que exporta el backend (`backend-integrations.testing.ts`,
// byte a byte el de `4e6f5fe`) y un estado mutable para asociar, registrar,
// autorizar, activar, revocar, reconocer y la sonda. El resto del Studio (el
// tablero) lo responde el simulador del H5. Nada sale de la máquina.
'use strict';

const fs = require('node:fs');
const path = require('node:path');

const CRM_DIR = process.env.CRM_DIR || path.resolve(__dirname, '../../../../../../../../Iron-Body_Front');
const H5 = require(path.resolve(__dirname, '../../../informes-h5/capturas-crm/arnes/mock.cjs'));

function leerTs(rel, nombre) {
  const s = fs.readFileSync(path.join(CRM_DIR, 'src/app/modules/ultron-studio', rel), 'utf8');
  const i = s.indexOf(`${nombre} = `) + nombre.length + 3;
  return JSON.parse(s.slice(i, s.lastIndexOf(' as const')));
}
const FIX = leerTs('testing/backend-integrations.testing.ts', 'BACKEND_INTEGRATIONS');
const copia = (v) => JSON.parse(JSON.stringify(v));
const ej = (k) => copia(FIX.examples[k]);

const ANA = { name: 'Ana Pérez', role: 'Administrador' };
const ROOT = { name: 'Root', role: 'Super Admin' };
const ETIQUETAS = { pending: 'Pendiente de autorizar (registro)', authorized: 'Autorizado (registro)', active: 'Activo (registro)', revoked: 'Revocado' };
const AHORA = '2026-11-01T15:30:00+00:00';
const V_CANARIO = '22222222-2222-4222-8222-222222222222';
const V_APROBADA = '33333333-3333-4333-8333-333333333333';

/**
 * Escenarios: `superadmin` (todas las llaves, escrituras y sonda encendidas),
 * `admin` (sin autorizar ni configuración), `apagado` (la fase 1 de producción:
 * escrituras y sonda apagadas), `h5` (backend sin integraciones).
 */
function crear(escenario) {
  const quien = escenario === 'admin' ? ANA : ROOT;
  const can =
    escenario === 'admin'
      ? { campaignsManage: true, canaryManage: true, canaryAuthorize: false, integrationsManage: false }
      : { campaignsManage: true, canaryManage: true, canaryAuthorize: true, integrationsManage: escenario !== 'apagado' };
  const flags = escenario === 'apagado' ? { writesEnabled: false, twilioProbeEnabled: false } : { writesEnabled: true, twilioProbeEnabled: true };
  const canarios = ej('canaries');
  const twilio = ej('twilio');
  twilio.probe.enabled = flags.twilioProbeEnabled;
  if (escenario !== 'apagado') twilio.probe.last = ej('probe').snapshot;
  return {
    escenario,
    quien,
    can,
    flags,
    numeros: canarios.numbers.data.map((n) => ({ ...n, _registro: n.registeredBy.name })),
    campanas: ej('metaCampaigns'),
    links: ej('links').data,
    twilio,
    config: ej('config'),
    sondas: 0,
    reconocimientos: 0,
    escrituras: [],
    seq: 10,
  };
}

/** Las acciones de un número para quien mira (I4.9, con su precedencia). */
function acciones(e, n) {
  const motivo = (llave, estadoOk, cuatroOjos = false, tope = false) => {
    if (!llave) return 'forbidden';
    if (!e.flags.writesEnabled) return 'writes_disabled';
    if (!estadoOk) return 'invalid_transition';
    if (cuatroOjos && n._registro === e.quien.name) return 'four_eyes_required';
    if (tope) return 'active_limit';
    return null;
  };
  const a = (r) => ({ allowed: r === null, reason: r });
  const vivo = n.status !== 'revoked';
  return {
    authorize: a(motivo(e.can.canaryAuthorize, n.status === 'pending', true)),
    activate: a(motivo(e.can.canaryManage, n.status === 'authorized', true)),
    deactivate: a(motivo(e.can.canaryManage, n.status === 'active')),
    revoke: a(motivo(e.can.canaryManage, vivo)),
    expectation: a(motivo(e.can.canaryManage, vivo)),
  };
}

function numeroDto(e, n) {
  const { _registro, ...dto } = n;
  return { ...dto, statusLabel: ETIQUETAS[n.status], actions: acciones(e, n) };
}

function coherencia(e) {
  const activos = e.numeros.filter((n) => n.channel === 'whatsapp' && n.status === 'active');
  const motor = e.numeros.find((n) => n.isEngineCanary === true);
  let verdict = 'engine_canary_not_registered';
  if (motor && motor.status !== 'revoked') verdict = motor.status !== 'active' ? 'engine_canary_registered_inactive' : activos.length > 1 ? 'registry_has_extra_active' : 'in_sync';
  return { engineCanaryConfigured: true, verdict, activeWhatsapp: activos.length };
}

function estado(e) {
  const st = ej('status');
  st.can = e.can;
  st.flags = e.flags;
  const c = coherencia(e);
  const cuenta = (s) => e.numeros.filter((n) => n.status === s).length;
  st.providers[3].evidence = {
    active: { whatsapp: c.activeWhatsapp, voice: e.numeros.filter((n) => n.channel === 'voice' && n.status === 'active').length },
    pending: cuenta('pending'),
    authorized: cuenta('authorized'),
    verdict: c.verdict,
  };
  return st;
}

function versiones() {
  const v = (uuid, label, status, statusLabel) => ({
    uuid,
    label,
    status,
    statusLabel,
    contentHash: `a1b2c3d4${'e'.repeat(56)}`,
    shortHash: uuid === V_CANARIO ? '5f3e9a21' : 'a1b2c3d4',
    catalogVersion: '2026-10-19.1',
    createdBy: ANA,
    mine: false,
    createdAt: '2026-10-28T15:10:00+00:00',
    submittedAt: '2026-10-28T15:12:00+00:00',
    warnings: [],
    blocking: 0,
    review: { decision: 'approved', by: ROOT, at: '2026-10-28T15:20:00+00:00', note: null, acknowledgedWarnings: [] },
    engineReads: true,
  });
  return [v(V_CANARIO, 'Tono profesional (propuesta)', 'CANARY', 'Canario'), v(V_APROBADA, 'Cobro con enlaces', 'APPROVED', 'Aprobada')];
}

const ok = (data, extra = {}) => ({ ok: true, data, ...extra });
const pagina = (items) => ({ current_page: 1, data: items, last_page: 1, per_page: 20, total: items.length });
const uuid = (e) => `c9c9c9c9-0000-4000-8000-${String(++e.seq).padStart(12, '0')}`;
const error = (status, code, message, extra = {}) => [status, { ok: false, code, message, ...extra }];

function destino(t) {
  if (t.kind === 'baseline') return { kind: 'baseline', label: 'LEGACY_BASELINE_V1' };
  const v = versiones().find((x) => x.uuid === t.versionUuid);
  return { kind: 'version', versionUuid: t.versionUuid, label: v?.label ?? 'Versión', status: v?.status ?? 'APPROVED', statusLabel: v?.statusLabel ?? 'Aprobada', integrityOk: true };
}

function integraciones(e, method, p, q, body) {
  if (method === 'GET' && p === '/status') return [200, ok(estado(e))];
  if (method === 'GET' && p === '/meta/campaigns') {
    const c = copia(e.campanas);
    if (q.get('linked') === 'yes') c.campaigns.data = c.campaigns.data.filter((x) => x.ultron.link);
    if (q.get('linked') === 'no') c.campaigns.data = c.campaigns.data.filter((x) => !x.ultron.link);
    c.campaigns.total = c.campaigns.data.length;
    return [200, ok(c)];
  }
  if (method === 'GET' && p === '/meta/links') {
    const camp = q.get('campaign');
    return [200, ok(pagina(e.links.filter((l) => !camp || l.campaign.id === camp)))];
  }
  if (method === 'GET' && p === '/canaries') {
    const s = q.get('status');
    const lista = e.numeros.filter((n) => (s === 'all' ? true : s ? n.status === s : n.status !== 'revoked'));
    return [200, ok({ coherence: coherencia(e), limits: { maxActivePerChannel: 3, maxLivePerChannel: 10, countryCodes: ['57'], writesEnabled: e.flags.writesEnabled }, numbers: pagina(lista.map((n) => numeroDto(e, n))) })];
  }
  if (method === 'GET' && p === '/twilio') return [200, ok(e.twilio)];
  if (method === 'GET' && p === '/twilio/calls') return [200, ok(pagina(ej('twilioCalls').data))];
  if (method === 'GET' && p === '/voice') return [200, ok(ej('voice'))];
  if (method === 'GET' && p === '/config') return [200, ok(e.config)];

  if (method === 'POST') e.escrituras.push(p);
  if (method === 'POST' && p === '/meta/links') {
    if (!e.flags.writesEnabled) return error(409, 'integrations_writes_disabled', 'Las escrituras de las integraciones están apagadas en el servidor. Leer sigue disponible.');
    const campana = e.campanas.campaigns.data.find((c) => c.id === body.campaign);
    if (!campana) return error(404, 'campaign_not_found', 'La campaña no está entre las de las cuentas de Meta conectadas.');
    const actual = campana.ultron.link;
    if (actual && !body.replaces) return error(409, 'campaign_already_linked', 'La campaña ya tiene una asociación activa. Para cambiarla, sustitúyela.', { current: actual });
    const nueva = {
      uuid: uuid(e),
      status: 'active',
      campaign: { id: campana.id, ref: campana.ref, nameAtLink: campana.name, connected: true, currentName: campana.name },
      target: destino(body.target),
      createdBy: e.quien,
      createdAt: AHORA,
      deactivated: null,
    };
    let sustituida = null;
    if (actual) {
      sustituida = { ...actual, status: 'inactive', deactivated: { at: AHORA, by: e.quien, reason: 'Sustituida por otra asociación', replacedBy: nueva.uuid } };
      e.links = e.links.map((l) => (l.uuid === actual.uuid ? sustituida : l));
    }
    e.links.unshift(nueva);
    campana.ultron.link = nueva;
    return [201, ok({ replayed: false, link: nueva, replaced: sustituida })];
  }
  const desactivar = p.match(/^\/meta\/links\/([0-9a-f-]{36})\/deactivate$/);
  if (method === 'POST' && desactivar) {
    const l = e.links.find((x) => x.uuid === desactivar[1]);
    if (!l) return error(404, 'link_not_found', 'La asociación no existe.');
    if (l.status !== 'active') return error(409, 'invalid_transition', 'La asociación ya no está activa: no se puede desactivar.', { currentStatus: 'inactive', allowedFrom: ['active'] });
    const hecha = { ...l, status: 'inactive', deactivated: { at: AHORA, by: e.quien, reason: body.reason, replacedBy: null } };
    e.links = e.links.map((x) => (x.uuid === l.uuid ? hecha : x));
    for (const c of e.campanas.campaigns.data) if (c.ultron.link?.uuid === l.uuid) c.ultron.link = null;
    return [200, ok({ link: hecha })];
  }
  if (method === 'POST' && p === '/canaries') {
    if (!e.flags.writesEnabled) return error(409, 'integrations_writes_disabled', 'Las escrituras de las integraciones están apagadas en el servidor. Leer sigue disponible.');
    const digitos = String(body.phone).replace(/\D/g, '');
    const n = {
      uuid: uuid(e),
      channel: body.channel,
      alias: body.alias,
      masked: `+57 ••• ••• ${digitos.slice(-4)}`,
      status: 'pending',
      statusLabel: ETIQUETAS.pending,
      registeredBy: e.quien,
      registeredAt: AHORA,
      authorizedBy: null,
      authorizedAt: null,
      activatedBy: null,
      activatedAt: null,
      revoked: null,
      expectedVersion: null,
      effectiveVersion: body.channel === 'voice' ? { source: 'none', reason: 'no_voice_engine' } : { source: 'none', reason: 'not_engine_canary' },
      expectation: 'none',
      isEngineCanary: body.channel === 'voice' ? null : false,
      statusChangedAt: AHORA,
      _registro: e.quien.name,
    };
    e.numeros.push(n);
    return [201, ok({ replayed: false, number: numeroDto(e, n) })];
  }
  const t = p.match(/^\/canaries\/([0-9a-f-]{36})\/(authorize|activate|deactivate|revoke|expectation)$/);
  if (method === 'POST' && t) {
    const n = e.numeros.find((x) => x.uuid === t[1]);
    if (!n) return error(404, 'canary_not_found', 'El número canario no existe.');
    const a = acciones(e, n)[t[2]];
    if (!a.allowed && a.reason === 'four_eyes_required') return error(403, 'four_eyes_required', `Nadie ${t[2] === 'activate' ? 'activa' : 'autoriza'} un número que registró: tiene que hacerlo otra persona con permiso.`, { action: t[2] });
    if (!a.allowed) return error(409, 'invalid_transition', `Un número canario en estado «${ETIQUETAS[n.status]}» no admite «${t[2]}».`, { currentStatus: n.status, allowedFrom: [] });
    if (t[2] === 'authorize') Object.assign(n, { status: 'authorized', authorizedBy: e.quien, authorizedAt: AHORA });
    if (t[2] === 'activate') Object.assign(n, { status: 'active', activatedBy: e.quien, activatedAt: AHORA });
    if (t[2] === 'deactivate') Object.assign(n, { status: 'authorized' });
    if (t[2] === 'revoke') Object.assign(n, { status: 'revoked', revoked: { at: AHORA, by: e.quien, reason: body.reason }, isEngineCanary: n.channel === 'voice' ? null : false });
    if (t[2] === 'expectation') {
      const v = versiones().find((x) => x.uuid === body.versionUuid);
      n.expectedVersion = v ? { uuid: v.uuid, label: v.label, status: v.status, statusLabel: v.statusLabel, stale: false } : null;
      const rige = n.effectiveVersion.source === 'publication_chain' && n.effectiveVersion.state === 'applied' ? n.effectiveVersion.versionUuid : null;
      n.expectation = !v ? 'none' : n.effectiveVersion.source === 'none' ? 'not_applicable' : rige === v.uuid ? 'match' : 'mismatch';
    }
    if (t[2] === 'revoke' || t[2] === 'deactivate') {
      if (n.channel === 'whatsapp' && n.isEngineCanary !== null && n.status !== 'active') n.effectiveVersion = n.isEngineCanary ? n.effectiveVersion : { source: 'none', reason: 'not_engine_canary' };
      if (n.status === 'revoked' && n.channel === 'whatsapp') n.effectiveVersion = { source: 'none', reason: 'not_engine_canary' };
      if (n.status === 'revoked' && n.expectation !== 'none') n.expectation = n.expectedVersion ? 'not_applicable' : 'none';
    }
    n.statusChangedAt = AHORA;
    return [200, ok({ number: numeroDto(e, n) })];
  }
  if (method === 'POST' && p === '/config/acknowledge') {
    const prov = e.config.providers.find((x) => x.provider === body.provider);
    // La primera vez, la configuración «cambia» entre ver y confirmar (409 config_changed): hay que volver a mirarla.
    if (e.reconocimientos++ === 0) {
      const nueva = 'b'.repeat(64);
      prov.effectiveFingerprint = nueva;
      if (prov.observed) prov.observed.fingerprint = nueva;
      return error(409, 'config_changed', 'La configuración cambió desde que la viste.', { provider: prov.provider, effectiveFingerprint: nueva, drift: prov.drift });
    }
    prov.authorized = { uuid: uuid(e), fingerprint: body.fingerprint, at: AHORA, by: e.quien, note: body.note };
    prov.state = 'authorized';
    prov.drift = [];
    return [201, ok({ replayed: false, snapshot: { uuid: prov.authorized.uuid, provider: prov.provider, fingerprint: body.fingerprint, at: AHORA, by: e.quien, note: body.note } })];
  }
  if (method === 'POST' && p === '/twilio/probe') {
    if (e.sondas++ >= 1) return error(429, 'too_many_requests', 'Demasiadas comprobaciones seguidas: espera un poco.', { retry_after: 40 });
    const s = { ...ej('probe').snapshot, uuid: uuid(e), at: AHORA, by: e.quien };
    e.twilio.probe.last = s;
    return [201, ok({ replayed: false, reused: false, snapshot: s })];
  }
  return error(404, 'canary_not_found', `Sin simular: ${method} ${p}`);
}

/** Responde a una petición del CRM: [status, cuerpo] o null (no es del Studio). */
function responder(e, method, url, body) {
  const u = new URL(url);
  if (!u.pathname.startsWith('/api/admin/ultron-studio')) return null;
  const p = u.pathname.replace(/^\/api\/admin\/ultron-studio/, '');
  if (p.startsWith('/integrations')) {
    if (e.escenario === 'h5') return [404, { message: 'Not Found' }];
    return integraciones(e, method, p.replace(/^\/integrations/, ''), u.searchParams, body);
  }
  if (method === 'GET' && p === '/versions' && u.searchParams.get('status') === 'APPROVED,CANARY,PUBLISHED') return [200, ok(pagina(versiones()))];
  if (!e.h5) e.h5 = H5.crear('aprobada');
  return H5.responder(e.h5, method, url, body);
}

module.exports = { crear, responder };
