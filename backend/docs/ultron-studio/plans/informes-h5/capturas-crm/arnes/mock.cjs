// API simulada del Studio para la prueba en Chrome del paso 4 (H5). Responde a
// lo que pide el CRM a 127.0.0.1:8080 sin backend: los fixtures del contrato
// (mismas formas que studio-api-fixtures.testing.ts) y un estado mutable para
// publicar, promover, revertir y Legacy. Nada sale de la máquina.
'use strict';

// El catálogo exportado por el backend, tal como lo usa el CRM (sin copiarlo aquí).
const CRM_DIR = process.env.CRM_DIR || require('node:path').resolve(__dirname, '../../../../../../../../Iron-Body_Front');
const CAT = (() => {
  const s = require('node:fs').readFileSync(require('node:path').join(CRM_DIR, 'src/app/modules/ultron-studio/testing/backend-studio-catalog.testing.ts'), 'utf8');
  return JSON.parse(s.slice(s.indexOf('{'), s.lastIndexOf('}') + 1));
})();
const UUID_V1 = '6f1c2a9e-3b4d-4c5e-8f60-7a8b9c0d1e2f';
const UUID_V2 = '0b7d9c11-5e2f-4a60-9b3c-1d2e3f405162';
const HUELLA = (c) => c.repeat(64 / c.length);

function crear(escenario) {
  const ahora = '2026-11-03T15:04:05+00:00';
  const estado = {
    escenario,
    v1: escenario === 'canario' ? 'CANARY' : 'APPROVED',
    cadena: [],
    deriva: escenario === 'deriva',
    publicar: escenario !== 'apagado',
    retry: escenario === 'limitado',
    llave: escenario === 'todas' ? 'all' : 'canary',
    can: escenario === 'admin' ? { publish: false, rollback: false } : { publish: true, rollback: true },
  };
  estado.escrituras = [];
  if (['canario', 'deriva', 'todas'].includes(escenario)) {
    estado.v1 = 'CANARY';
    estado.cadena.push(entrada(1, 'publish', UUID_V1, 'Cobro con enlaces (propuesta)', null, { mode: escenario === 'deriva' ? 'waived' : 'required' }, ahora));
  }
  return estado;
}

function entrada(seq, action, uuid, label, reason, labGate, at) {
  const h = '0123456789abcdef'.repeat(4);
  return {
    uuid: `00000000-0000-4000-8000-${String(seq).padStart(12, '0')}`,
    seq,
    action,
    version: uuid ? { uuid, label } : null,
    effectiveHash: h,
    shortHash: h.slice(0, 8),
    labGate,
    actor: { name: seq % 2 ? 'Publicadora E2E' : 'Root', role: seq % 2 ? 'E2E Publicadora' : 'Super Admin' },
    reason,
    at: at ?? `2026-11-0${3 + Math.floor(seq / 3)}T1${seq}:00:00+00:00`,
  };
}

const ETIQUETAS = {
  DRAFT: 'Creada, sin enviar',
  APPROVED: 'Aprobada',
  CANARY: 'Canario',
  PUBLISHED: 'Publicada',
  RETIRED: 'Sustituida',
  ROLLED_BACK: 'Revertida',
};

function cabeza(e) {
  const c = e.cadena.at(-1);
  return c ? { uuid: c.uuid, seq: c.seq, action: c.action, at: c.at, signatureOk: true } : null;
}

function partes(e) {
  const parte = { versionUuid: UUID_V1, label: 'Cobro con enlaces (propuesta)' };
  if (e.v1 === 'CANARY') return { canary: parte, rest: null };
  if (e.v1 === 'PUBLISHED') return { canary: parte, rest: parte };
  return { canary: null, rest: null };
}

function status(e) {
  const p = partes(e);
  return {
    engine: {
      readsVersions: true,
      activeVersion: p.rest ? UUID_V1 : null,
      message: 'El motor de ULTRON anota en cada turno la versión publicada. Publicar no cambia lo que ULTRON dice ni hace.',
      publication: {
        envScope: e.llave,
        head: cabeza(e),
        ...p,
        drift: e.deriva ? [{ param: 'rules.paymentLinksEnabled', declared: false, engine: true }] : [],
        state: p.canary || p.rest ? 'applied' : e.cadena.length ? 'legacy' : 'legacy',
      },
    },
    publishing: e.publicar
      ? { available: true, code: null, message: 'Publicar está disponible para quien tiene la llave.' }
      : { available: false, code: 'publishing_disabled', message: 'Publicar está desactivado en el servidor: hace falta ULTRON_STUDIO_PUBLISHING_ENABLED.' },
    can: { view: true, editDraft: true, manageRules: true, approve: true, ...e.can },
    catalog: { version: CAT.version, hash: CAT.hash },
    lifecycle: [
      { status: 'DRAFT', label: 'Creada, sin enviar', reachable: true },
      { status: 'VALIDATING', label: 'Validando', reachable: false, reason: 'La validación es inmediata: ninguna versión queda en este estado.' },
      { status: 'VALIDATED', label: 'Validada, espera revisión', reachable: true },
      { status: 'REVIEW_REQUIRED', label: 'Con avisos · espera revisión', reachable: true },
      { status: 'APPROVED', label: 'Aprobada', reachable: true },
      { status: 'REJECTED', label: 'Rechazada', reachable: true },
      { status: 'CANARY', label: 'Canario', reachable: true },
      { status: 'PUBLISHED', label: 'Publicada', reachable: true },
      { status: 'RETIRED', label: 'Sustituida', reachable: true },
      { status: 'ROLLED_BACK', label: 'Revertida', reachable: true },
    ],
    legacyBaseline: { label: 'LEGACY_BASELINE_V1', rulesImported: 12 },
  };
}

function contenido() {
  return {
    definition: { layers: CAT.baseline.layers ?? CAT.layers.map((l) => l.id), edgeIds: CAT.baseline.edgeIds },
    configs: CAT.baseline.configs,
    layout: { positions: CAT.baseline.positions, viewport: null },
  };
}

function validacion() {
  return { catalogVersion: CAT.version, publishable: true, warnings: [], findings: [] };
}

function resumen(e, uuid = UUID_V1) {
  const otra = uuid === UUID_V2;
  const st = otra ? 'DRAFT' : e.v1;
  return {
    uuid,
    label: otra ? 'Tono cercano (borrador de Ana)' : 'Cobro con enlaces (propuesta)',
    status: st,
    statusLabel: ETIQUETAS[st],
    contentHash: `a1b2c3d4${'e'.repeat(56)}`,
    shortHash: 'a1b2c3d4',
    catalogVersion: CAT.version,
    createdBy: { name: 'Ana Pérez', role: 'Administrador' },
    mine: false,
    createdAt: otra ? '2026-10-19T10:00:00+00:00' : '2026-10-18T15:10:00+00:00',
    submittedAt: '2026-10-18T15:12:00+00:00',
    warnings: [],
    blocking: 0,
    review: otra ? null : { decision: 'approved', by: { name: 'Root', role: 'Super Admin' }, at: '2026-10-18T15:20:00+00:00', note: null, acknowledgedWarnings: [] },
    engineReads: true,
  };
}

function detalle(e, uuid = UUID_V1) {
  const r = resumen(e, uuid);
  const no = (code, message) => ({ allowed: false, code, message });
  const st = r.status;
  const actions = {
    submit: no('invalid_transition', 'Solo se envía una versión recién creada.'),
    approve: no('invalid_transition', 'Solo se revisa una versión enviada a revisión.'),
    reject: no('invalid_transition', 'Solo se revisa una versión enviada a revisión.'),
    publish: !e.can.publish
      ? no('forbidden', 'Hace falta el permiso «Publicar versiones».')
      : !e.publicar
        ? no('publishing_disabled', 'Publicar está desactivado en el servidor: hace falta ULTRON_STUDIO_PUBLISHING_ENABLED.')
        : st === 'APPROVED'
          ? { allowed: true }
          : no('invalid_transition', 'Solo se publica una versión aprobada.'),
    promote: !e.can.publish
      ? no('forbidden', 'Hace falta el permiso «Publicar versiones».')
      : e.llave !== 'all'
        ? no('scope_not_allowed', 'Promover exige que el motor lea la cadena en todas las conversaciones (su llave en «all»).')
        : st === 'CANARY'
          ? { allowed: true }
          : no('invalid_transition', 'Solo se promueve la versión en canario.'),
    rollback: !e.can.rollback ? no('forbidden', 'Hace falta el permiso «Revertir versiones».') : st === 'CANARY' || st === 'PUBLISHED' ? { allowed: true } : no('not_active_publication', 'Solo se revierte la versión en canario o la publicada.'),
  };
  return {
    ...r,
    note: null,
    content: { ...contenido(), format: 2, rules: [{ code: 'S-003', revision: 2, source: 'studio', enforcement: 'none', status: 'draft', description: 'Mensajes cortos cuando se vende: como mucho tres líneas.', type: 'condition', layers: ['persona'], priority: 'normal', refs: { configs: [], edges: [], knowledgeKeys: [], knowledgeCategories: [] }, conditions: null }] },
    lastValidation: { ...validacion(), validatedAt: '2026-10-18T15:12:00+00:00' },
    integrityOk: true,
    actions,
    publications: uuid === UUID_V1 ? e.cadena.filter((c) => c.version?.uuid === UUID_V1).slice().reverse() : [],
  };
}

function vistaPrevia(e) {
  const mm = (layer, key, versionValue, engineValue, ok = true) => ({ layer, key, class: 'must_match', versionValue, engineValue, ok });
  const igual = (layer, key, v) => ({ layer, key, class: 'matches', versionValue: v, engineValue: v, ok: true });
  const bloqueada = e.escenario === 'bloqueada';
  return {
    versionUuid: UUID_V1,
    contentHash: `a1b2c3d4${'e'.repeat(56)}`,
    previewHash: HUELLA('b'),
    publishable: !bloqueada,
    params: [
      igual('knowledge', 'strictFacts', true),
      { layer: 'persona', key: 'tone', class: 'no_effect', versionValue: 'profesional', engineValue: null, ok: true },
      mm('intent', 'driver', 'entorno', 'openai'),
      igual('context', 'windowTurns', 10),
      igual('memory', 'useLeadProfile', true),
      igual('strategy', 'discountsAllowed', false),
      igual('rules', 'handoffOnlyCorroborated', true),
      mm('rules', 'paymentLinksEnabled', false, false),
      igual('integrations', 'channel', 'whatsapp'),
      igual('integrations', 'appointments', true),
      mm('governance', 'enabled', true, true),
      mm('governance', 'canaryOnly', true, true),
      igual('governance', 'autoBrake', true),
    ],
    noEffect: ['persona.tone', 'rule:S-003'],
    requirements: {
      status: { ok: true, current: 'APPROVED' },
      fourEyes: { ok: true },
      integrity: { ok: true },
      revalidation: { ok: true },
      labGate: bloqueada ? { mode: 'required', ok: false, latest: 'needs_changes', message: 'El último veredicto del laboratorio sobre esta huella pide cambios (ejecución del 24-10).' } : { mode: 'required', ok: true, latest: 'approved' },
      engineCanary: { ok: true },
      brake: { ok: true },
      canaryFree: { ok: true },
      window: { ok: true, window: '07:00–19:00' },
      keys: { studio: true, engine: e.llave },
    },
    blocking: bloqueada ? ['lab_verdict_required'] : [],
  };
}

function evidencia(e) {
  const insuf = e.escenario !== 'todas';
  const por = (n) => ({ applied: n, 'fallback/contract_unknown': 0, 'fallback/hash_mismatch': 0, 'fallback/signature_invalid': 0, 'fallback/malformed': 0, 'fallback/table_missing': 0, 'fallback/read_failed': 0, legacy: 0, out_of_scope: 0, unstamped: insuf ? 0 : 0 });
  const turns = insuf ? 2 : 4;
  return {
    versionUuid: UUID_V1,
    since: '2026-11-03T15:04:05Z',
    thresholds: { minHours: 24, minTurns: 3 },
    hours: insuf ? 7 : 26,
    turns,
    stamped: turns,
    byState: por(turns),
    brakes: { count: 0, engaged: false },
    acta: { exitCode: 0, total: 0, counts: { traspasos_no_autorizados: 0, horarios_aplazados_en_una_persona: 0, planes_no_vendibles: 0, precios_que_no_son_del_catalogo: 0, enlaces_no_oficiales: 0, datos_personales: 0, negaciones_de_un_cobro_disponible: 0 } },
    recoveries: 0,
    drift: [],
    signatureOk: true,
    evidenceHash: HUELLA('e'),
    passes: !insuf,
    failing: insuf ? ['hours', 'turns'] : [],
  };
}

const ok = (data, extra = {}) => ({ ok: true, data, ...extra });
const paginador = (items, total = items.length) => ({ current_page: 1, data: items, last_page: 1, per_page: 20, total });

/** Responde a una petición del CRM: [status, cuerpo] o null (no es del Studio). */
function responder(e, method, url, body) {
  const u = new URL(url);
  const p = u.pathname.replace(/^\/api\/admin\/ultron-studio/, '');
  if (!u.pathname.startsWith('/api/admin/ultron-studio')) return null;
  if (method === 'GET' && p === '/status') return [200, ok(status(e))];
  if (method === 'GET' && p === '/catalog') return [200, ok(CAT)];
  if (method === 'GET' && p === '/draft') return [200, ok({ exists: true, isBaseline: false, uuid: 'd7e6f5a4-3b2c-4d1e-9f0a-1b2c3d4e5f60', revision: 7, savedAt: '2026-10-18T15:00:00+00:00', catalogVersion: CAT.version, contentHash: 'c'.repeat(64), content: contenido(), validation: validacion() })];
  if (method === 'GET' && p === '/rules') return [200, ok([])];
  if (method === 'GET' && p === '/versions') return [200, ok(paginador([resumen(e), resumen(e, UUID_V2)]))];
  if (method === 'GET' && p === '/engine/readings')
    return [
      200,
      ok({
        readAt: '2026-11-03T15:00:00+00:00',
        params: {
          'intent.driver': { available: true, value: 'openai' },
          'rules.paymentLinksEnabled': { available: true, value: e.deriva },
          'governance.enabled': { available: true, value: true },
          'governance.canaryOnly': { available: true, value: true },
          'strategy.offerPriority': { available: true, value: ['Mensualidad', 'Trimestral'] },
        },
        extras: {
          'governance.paused': { available: true, value: false },
          'governance.brake': { available: true, value: false },
          'rules.paymentCanary': { available: true, value: false },
          'knowledge.base': { available: true, value: { version: '42:1729212345', activeByCategory: { tone: 3, brand_copy: 2 }, pendingReview: 0 } },
          'strategy.plans': { available: true, value: [{ name: 'Mensualidad', recommended: true }] },
          'governance.studioPublication': {
            available: true,
            value: { env: e.llave, head: cabeza(e) ? { entry: cabeza(e).uuid, seq: cabeza(e).seq, contract: 'ultron-engine-config/1', hashOk: true, signatureOk: true, parseOk: true } : null, parts: { canary: !!partes(e).canary, rest: !!partes(e).rest }, canaryConfigured: true },
          },
        },
        note: 'Lectura del proceso web. Tras cambiar el entorno, los workers lo ven cuando se reinician.',
      }),
    ];
  const ver = p.match(/^\/versions\/([0-9a-f-]{36})$/);
  if (method === 'GET' && ver) return [200, ok(detalle(e, ver[1]))];
  if (method === 'GET' && p === `/versions/${UUID_V1}/publication-preview`) return [200, ok(vistaPrevia(e))];
  if (method === 'GET' && p === `/versions/${UUID_V1}/canary-evidence`) {
    if (e.retry) {
      e.retry = false;
      return [429, { ok: false, code: 'too_many_requests', message: 'Demasiadas lecturas de la evidencia.', retry_after: 42 }];
    }
    return [200, ok(evidencia(e))];
  }
  if (method === 'GET' && p === '/publications') return [200, ok(paginador(e.cadena.slice().reverse()))];
  if (method === 'GET' && p === '/compare')
    return [
      200,
      ok({
        from: { kind: 'baseline', uuid: null, label: 'LEGACY_BASELINE_V1', catalogVersion: CAT.version, format: 2, integrityOk: null },
        to: { kind: 'version', uuid: UUID_V1, label: 'Cobro con enlaces (propuesta)', catalogVersion: CAT.version, format: 2, integrityOk: false },
        same: false,
        edges: { added: [], removed: [] },
        configs: [{ layer: 'persona', key: 'tone', label: 'Tono', from: 'cercano', to: 'profesional' }],
        rules: { added: [{ code: 'S-003', description: 'Mensajes cortos cuando se vende' }], removed: [], changed: [] },
        warnings: { added: [], removed: [] },
        catalogChanged: false,
      }),
    ];
  const n = e.cadena.length + 1;
  if (method === 'POST') e.escrituras.push(p);
  if (method === 'POST' && p === `/versions/${UUID_V1}/publish`) {
    const en = entrada(n, 'publish', UUID_V1, 'Cobro con enlaces (propuesta)', body?.note ?? null, { mode: 'required' });
    e.cadena.push(en);
    e.v1 = 'CANARY';
    return [201, ok(en)];
  }
  if (method === 'POST' && p === `/versions/${UUID_V1}/promote`) {
    const en = entrada(n, 'promote', UUID_V1, 'Cobro con enlaces (propuesta)', body?.note ?? null, null);
    e.cadena.push(en);
    e.v1 = 'PUBLISHED';
    return [201, ok(en)];
  }
  if (method === 'POST' && p === `/versions/${UUID_V1}/rollback`) {
    const en = entrada(n, 'rollback', UUID_V1, 'Cobro con enlaces (propuesta)', body?.reason, null);
    e.cadena.push(en);
    e.v1 = 'ROLLED_BACK';
    return [201, ok(en)];
  }
  if (method === 'POST' && p === '/publications/legacy') {
    const en = entrada(n, 'legacy', null, null, body?.reason, null);
    e.cadena.push(en);
    e.v1 = e.v1 === 'CANARY' ? 'ROLLED_BACK' : e.v1 === 'PUBLISHED' ? 'RETIRED' : e.v1;
    return [201, ok(en)];
  }
  if (p.startsWith('/audit')) return [200, ok(paginador([]))];
  return [404, { ok: false, code: 'version_not_found', message: `Sin simular: ${method} ${p}` }];
}

module.exports = { crear, responder };
