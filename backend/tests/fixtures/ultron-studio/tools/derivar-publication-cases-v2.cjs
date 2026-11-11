#!/usr/bin/env node
'use strict';
/*
 * Genera `tests/fixtures/ultron-studio/publication-cases-v2.json` desde los
 * CONTRATOS (tester, 11-11-2026), sin leer `StudioPublicationDeclaration.php`:
 *
 *  - H5 §A4.1/§A4.2 + Enmienda 1 (README de `publication-cases.json`, E-1, E-2, S-1..S-4): bytes y firma de `/1`;
 *  - contrato F §307: `StudioContentHasher` = JSON canónico (claves ordenadas por bytes, listas en su orden,
 *    sin escapar «/» ni Unicode, sin decimales) + sha256;
 *  - plan de motores §B.4 (Enmienda M-1, M1-5 y M1-12): `/2` = la misma entrada con `governed` y `prompts`
 *    exactos y tipados en cada parte no nula; «Firma, huella, decodificación estricta y tope de 16 KB: los de
 *    `/1`»; Legacy siempre en `/1`; con la llave, los cuatro gobernados salen de `noEffect`;
 *  - H5 Q-4: `previewHash` sin paquete.
 * Las huellas de paquete salen de `tests/fixtures/ultron-prompts/compile-cases.json` (también del tester).
 *
 *   node backend/tests/fixtures/ultron-studio/tools/derivar-publication-cases-v2.cjs              # escribe
 *   node backend/tests/fixtures/ultron-studio/tools/derivar-publication-cases-v2.cjs --comprobar  # sale 1 si no está al día
 */
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const FIX = path.resolve(__dirname, '..');
const BACKEND = path.resolve(FIX, '..', '..', '..');
const SALIDA = path.join(FIX, 'publication-cases-v2.json');
const H5 = fs.readFileSync(path.join(BACKEND, 'docs', 'ultron-studio', 'ULTRON_STUDIO_H5_CONTRACT.md'), 'utf8');
const F = fs.readFileSync(path.join(BACKEND, 'docs', 'ultron-studio', 'ULTRON_STUDIO_F_CONTRACT.md'), 'utf8');
const PLAN = fs.readFileSync(path.join(BACKEND, 'docs', 'ultron-studio', 'plans', 'STUDIO_MOTORES_PLAN.md'), 'utf8');
const V1 = JSON.parse(fs.readFileSync(path.join(FIX, 'publication-cases.json'), 'utf8'));
const COMPILE = JSON.parse(fs.readFileSync(path.join(BACKEND, 'tests', 'fixtures', 'ultron-prompts', 'compile-cases.json'), 'utf8'));

// ── Lo que este guion implementa, comprobado contra el texto de los contratos ──
const exige = (texto, trozo, donde) => { if (!texto.includes(trozo)) throw new Error(`El contrato (${donde}) ya no dice: ${trozo}`); };
exige(H5, 'signature = hex(HMAC-SHA256(k, "ultron-engine-config/1\\n" . effective_json))', 'H5 A4.1');
exige(H5, "`k = hash_hmac('sha256', 'ultron-studio-publication', <APP_KEY decodificada de base64 cuando lleva el prefijo `base64:`>, true)`", 'H5 Q-1');
exige(H5, '`StudioContentHasher::hash({versionUuid, contentHash, catalogVersion, mustMatch, matches, noEffect, labGate: {mode, latest}})`', 'H5 Q-4');
exige(F, 'claves de objeto ordenadas recursivamente, listas en su orden, `JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR`, decimales rechazados) más sha256', 'F §307');
exige(PLAN, '`governed`: `{"persona.emojis": bool, "persona.maxChars": int, "persona.tone": string, "strategy.goal": string}`', 'plan §B.4');
exige(PLAN, '`prompts`: `{"base": "n8n-29de5119", "bundleHash": "<64 hex>", "compiler": 1}`', 'plan §B.4');
exige(PLAN, 'Firma, huella, decodificación estricta y tope de 16 KB: los de `/1`', 'plan §B.4');
exige(PLAN, '**Las entradas de «volver a Legacy» (`{canary:null, rest:null}`) se escriben siempre en `/1`**', 'plan §B.4');
exige(PLAN, 'los cuatro gobernados pasan de `no_effect` a una clase nueva `prompt`', 'plan §B.4');
exige(H5, 'la mete en `previewHash`', 'H5 M1-12');

const C1 = 'ultron-engine-config/1';
const C2 = 'ultron-engine-config/2';
const APP_KEY = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
if (!V1.signature.some((c) => c.appKey === APP_KEY)) throw new Error('La clave de prueba no es la del fixture del H5');
const GOVERNED = ['persona.emojis', 'persona.maxChars', 'persona.tone', 'strategy.goal'];

/** contrato F §307: JSON canónico. */
function canon(v) {
  if (v === null) return 'null';
  if (typeof v === 'boolean') return v ? 'true' : 'false';
  if (typeof v === 'number') {
    if (!Number.isInteger(v)) throw new Error('decimal');
    return String(v);
  }
  if (typeof v === 'string') return JSON.stringify(v);
  if (Array.isArray(v)) return '[' + v.map(canon).join(',') + ']';
  const claves = Object.keys(v).sort((a, b) => Buffer.compare(Buffer.from(a, 'utf8'), Buffer.from(b, 'utf8')));
  return '{' + claves.map((k) => JSON.stringify(k) + ':' + canon(v[k])).join(',') + '}';
}
const sha256 = (s) => crypto.createHash('sha256').update(Buffer.from(s, 'utf8')).digest('hex');

/** S-1, S-2, S-3: la firma. `prefijo` es el contrato que va delante del salto de línea. */
function firma(json, prefijo = C1) {
  const clave = Buffer.from(APP_KEY.slice('base64:'.length), 'base64');
  const sub = crypto.createHmac('sha256', clave).update('ultron-studio-publication').digest();
  return crypto.createHmac('sha256', sub).update(Buffer.concat([Buffer.from(prefijo + '\n', 'utf8'), Buffer.from(json, 'utf8')])).digest('hex');
}

/** E-2: `issuedAt` en UTC, sin fracciones (truncadas). */
function utc(iso) {
  const ms = new Date(iso).getTime();
  if (Number.isNaN(ms)) throw new Error('issuedAt ' + iso);
  return new Date(Math.floor(ms / 1000) * 1000).toISOString().replace('.000Z', 'Z');
}

/** A4.2 / E-1: los bytes de la entrada. */
const efectivo = (e) => canon({ canary: e.canary, contract: e.contract, entry: e.entry, issuedAt: utc(e.issuedAt), rest: e.rest, seq: e.seq });

// Autocomprobación: este canonicalizador, `issuedAt` y la firma reproducen los casos `/1` del fixture
// congelado del H5 (escrito a mano desde el contrato). Si no, nada de lo que sigue vale.
let vistos = 0;
for (const c of V1.effectiveJson.filter((x) => x.kind === 'effectiveJson')) {
  if (efectivo({ ...c.input, contract: C1 }) !== c.expected.effectiveJson || sha256(c.expected.effectiveJson) !== c.expected.effectiveHash) throw new Error('No reproduzco el caso /1 «' + c.name + '»');
  vistos++;
}
for (const c of V1.signature.filter((x) => x.kind === 'sign' && x.appKey === APP_KEY)) {
  if (firma(c.effectiveJson) !== c.expected.signature) throw new Error('No reproduzco la firma «' + c.name + '»');
  vistos++;
}
if (vistos < 15) throw new Error('La autocomprobación contra el H5 ve demasiado poco: ' + vistos);

// ── Partes ──────────────────────────────────────────────────────────────────
const DECLARED = { 'rules.paymentLinksEnabled': false, 'intent.driver': 'entorno', 'governance.enabled': true, 'governance.canaryOnly': true };
/** Una parte `/1` (§A4.2), con las claves en un orden cualquiera (el canónico lo pone la canonicalización). */
const parte1 = (version, contentHash) => ({ version, publishedBy: 7, declared: { ...DECLARED }, contentHash, catalogVersion: '2026-10-19.1', approvedBy: 12 });
const bundle = (g) => {
  const c = COMPILE.cases.find((x) => GOVERNED.every((k) => x.governed[k] === g[k]));
  if (!c) throw new Error('compile-cases no tiene ' + JSON.stringify(g));
  return c.expect.bundleHash;
};
/** §B.4: la parte `/2` = la `/1` + `governed` + `prompts`. */
const parte2 = (p1, g) => ({ ...p1, prompts: { compiler: 1, bundleHash: bundle(g), base: 'n8n-29de5119' }, governed: { 'strategy.goal': g['strategy.goal'], 'persona.tone': g['persona.tone'], 'persona.maxChars': g['persona.maxChars'], 'persona.emojis': g['persona.emojis'] } });
const G = (tone, emojis, maxChars, goal) => ({ 'persona.emojis': emojis, 'persona.maxChars': maxChars, 'persona.tone': tone, 'strategy.goal': goal });

const VA = '22222222-2222-4222-8222-222222222222';
const VB = '33333333-3333-4333-8333-333333333333';
const VC = '44444444-4444-4444-8444-444444444444';
const V0 = '00000000-0000-4000-8000-0000000000a0';
const pA = parte1(VA, 'a'.repeat(64));
const pB = parte1(VB, 'b'.repeat(64));
const pC = parte1(VC, 'c'.repeat(64));
const p0 = parte1(V0, '0'.repeat(64));
const G8 = G('profesional', true, 200, 'cita');
const BASE = G('cercano', true, 320, 'cita');
const ENT = G('entusiasta', false, 480, 'venta');
const OTRA = G('profesional', false, 160, 'informar');
const E1 = 'eeeeeeee-0000-4000-8000-000000000001';
const E2 = 'eeeeeeee-0000-4000-8000-000000000002';

// ── Entradas que SÍ se escriben ─────────────────────────────────────────────
const validas = [
  ['/2 · canaria sola: el cambio de aceptación (profesional + 200), sin resto', { entry: E1, seq: 1, issuedAt: '2026-11-12T15:04:05Z', contract: C2, canary: parte2(pA, G8), rest: null }],
  ['/2 · canaria + resto: dos paquetes distintos (entusiasta sin emojis 480 venta | profesional 200)', { entry: E2, seq: 7, issuedAt: '2026-11-12T15:04:05Z', contract: C2, canary: parte2(pB, ENT), rest: parte2(pA, G8) }],
  ['/2 · resto arrastrado: la línea base compilada desde una versión /1 (huella Legacy, M1-12)', { entry: E2, seq: 12, issuedAt: '2026-11-12T15:04:05Z', contract: C2, canary: parte2(pC, OTRA), rest: parte2(p0, BASE) }],
  ['/2 · promover: la MISMA parte en las dos (Q-3)', { entry: E1, seq: 2, issuedAt: '2026-11-13T15:04:05Z', contract: C2, canary: parte2(pA, G8), rest: parte2(pA, G8) }],
  ['/2 · revertir conservando el resto: {R, R} con la línea base publicada (E3)', { entry: E1, seq: 3, issuedAt: '2026-11-13T16:00:00Z', contract: C2, canary: parte2(p0, BASE), rest: parte2(p0, BASE) }],
  ['/2 · {canary: null, rest: X} (E-5: texto válido que ninguna transición escribe hoy)', { entry: E1, seq: 4, issuedAt: '2026-11-13T16:00:00Z', contract: C2, canary: null, rest: parte2(pA, G8) }],
  ['/2 · issuedAt de noche en Bogotá con fracciones: el día UTC siguiente, truncado (E-2)', { entry: E1, seq: 10, issuedAt: '2026-11-12T21:30:59.999-05:00', contract: C2, canary: parte2(pA, G8), rest: null }],
  ['/1 · Legacy {null, null}: siempre /1 (R10)', { entry: E1, seq: 5, issuedAt: '2026-11-13T17:00:00Z', contract: C1, canary: null, rest: null }],
  ['/1 · una parte /1 sigue escribiéndose en /1 (llave apagada)', { entry: E1, seq: 6, issuedAt: '2026-11-13T17:00:00Z', contract: C1, canary: pA, rest: null }],
];
const casosValidos = validas.map(([name, e]) => {
  const json = efectivo(e);
  if (Buffer.byteLength(json) > 16384) throw new Error('más de 16 KB');
  return {
    name,
    input: { entry: e.entry, seq: e.seq, issuedAt: e.issuedAt, contract: e.contract, canary: e.canary, rest: e.rest },
    expected: { effectiveJson: json, effectiveHash: sha256(json), signature: firma(json), signatureWithOwnContractPrefix: e.contract === C1 ? null : firma(json, e.contract) },
  };
});

// ── Entradas que NO se escriben (§B.4 «exactas y tipadas»; R10; A4.2 «claves exactas») ──
const mal = (base, cambio) => { const p = JSON.parse(JSON.stringify(base)); cambio(p); return p; };
const ok = parte2(pA, G8);
const sinClave = (o, k) => { delete o[k]; };
const invalidas = [
  ['Legacy en /2 (R10)', { contract: C2, canary: null, rest: null }],
  ['contrato desconocido (/3) con partes /2 válidas', { contract: 'ultron-engine-config/3', canary: ok, rest: null }],
  ['/2 con una parte /1 (sin governed ni prompts)', { contract: C2, canary: pA, rest: null }],
  ['/2 con el resto /1 y la canaria /2', { contract: C2, canary: ok, rest: pA }],
  ['/1 con una parte /2', { contract: C1, canary: ok, rest: null }],
  ['/2 sin governed', { contract: C2, canary: mal(ok, (p) => sinClave(p, 'governed')), rest: null }],
  ['/2 sin prompts', { contract: C2, canary: mal(ok, (p) => sinClave(p, 'prompts')), rest: null }],
  ['governed sin strategy.goal', { contract: C2, canary: mal(ok, (p) => sinClave(p.governed, 'strategy.goal')), rest: null }],
  ['governed con una clave de más', { contract: C2, canary: mal(ok, (p) => { p.governed['persona.extra'] = 1; }), rest: null }],
  ['governed con otra grafía (persona.maxchars)', { contract: C2, canary: mal(ok, (p) => { sinClave(p.governed, 'persona.maxChars'); p.governed['persona.maxchars'] = 200; }), rest: null }],
  ['governed.persona.emojis como cadena ("true")', { contract: C2, canary: mal(ok, (p) => { p.governed['persona.emojis'] = 'true'; }), rest: null }],
  ['governed.persona.emojis como entero (1)', { contract: C2, canary: mal(ok, (p) => { p.governed['persona.emojis'] = 1; }), rest: null }],
  ['governed.persona.maxChars como cadena ("200")', { contract: C2, canary: mal(ok, (p) => { p.governed['persona.maxChars'] = '200'; }), rest: null }],
  ['governed.persona.maxChars booleano', { contract: C2, canary: mal(ok, (p) => { p.governed['persona.maxChars'] = true; }), rest: null }],
  ['governed.persona.maxChars nulo', { contract: C2, canary: mal(ok, (p) => { p.governed['persona.maxChars'] = null; }), rest: null }],
  ['governed.persona.tone como entero', { contract: C2, canary: mal(ok, (p) => { p.governed['persona.tone'] = 1; }), rest: null }],
  ['governed.strategy.goal nulo', { contract: C2, canary: mal(ok, (p) => { p.governed['strategy.goal'] = null; }), rest: null }],
  ['governed nulo', { contract: C2, canary: mal(ok, (p) => { p.governed = null; }), rest: null }],
  ['governed como lista', { contract: C2, canary: mal(ok, (p) => { p.governed = [true, 200, 'profesional', 'cita']; }), rest: null }],
  ['prompts sin bundleHash', { contract: C2, canary: mal(ok, (p) => sinClave(p.prompts, 'bundleHash')), rest: null }],
  ['prompts con una clave de más', { contract: C2, canary: mal(ok, (p) => { p.prompts.version = 1; }), rest: null }],
  ['prompts.bundleHash de 63 hex', { contract: C2, canary: mal(ok, (p) => { p.prompts.bundleHash = p.prompts.bundleHash.slice(1); }), rest: null }],
  ['prompts.bundleHash no hexadecimal', { contract: C2, canary: mal(ok, (p) => { p.prompts.bundleHash = 'g'.repeat(64); }), rest: null }],
  ['prompts.bundleHash nulo', { contract: C2, canary: mal(ok, (p) => { p.prompts.bundleHash = null; }), rest: null }],
  ['prompts.compiler como cadena ("1")', { contract: C2, canary: mal(ok, (p) => { p.prompts.compiler = '1'; }), rest: null }],
  ['prompts.base como entero', { contract: C2, canary: mal(ok, (p) => { p.prompts.base = 1; }), rest: null }],
  ['prompts nulo', { contract: C2, canary: mal(ok, (p) => { p.prompts = null; }), rest: null }],
  ['prompts como lista', { contract: C2, canary: mal(ok, (p) => { p.prompts = ['n8n-29de5119', p.prompts.bundleHash, 1]; }), rest: null }],
  ['el resto /2 roto aunque la canaria esté bien', { contract: C2, canary: ok, rest: mal(parte2(pB, ENT), (p) => sinClave(p.governed, 'persona.tone')) }],
].map(([name, e]) => ({ name, input: { entry: E1, seq: 1, issuedAt: '2026-11-12T15:04:05Z', ...e } }));

// ── previewHash sin paquete (Q-4), sobre clasificaciones del fixture congelado ──
const etiquetaLab = [{ mode: 'required', latest: null }, { mode: 'waived', latest: null }, { mode: 'required', latest: 'approved' }, { mode: 'required', latest: 'stale_prompts' }];
const preview = V1.classify.slice(0, 12).map((c, i) => {
  const versionUuid = [VA, VB, VC][i % 3];
  const contentHash = ['a', 'b', 'c', 'd'][i % 4].repeat(64);
  const labGate = etiquetaLab[i % etiquetaLab.length];
  const x = c.expected;
  return {
    name: `Q-4 · ${c.name} · lab ${labGate.mode}/${labGate.latest}`,
    input: { versionUuid, contentHash, catalogVersion: '2026-10-19.1', classification: x, labGate },
    expected: { previewHash: sha256(canon({ versionUuid, contentHash, catalogVersion: '2026-10-19.1', mustMatch: x.mustMatch, matches: x.matches, noEffect: x.noEffect, labGate: { mode: labGate.mode, latest: labGate.latest } })) },
  };
});

// ── Clasificación con la llave de prompts (§B.4): los cuatro gobernados salen de noEffect; lo demás, igual ──
const nombres = new Set();
const clasifica = V1.classify.map((c) => {
  if (nombres.has(c.name)) throw new Error('nombre repetido en classify: ' + c.name);
  nombres.add(c.name);
  const e = c.expected;
  return {
    name: c.name,
    expected: { mustMatch: e.mustMatch, matches: e.matches, noEffect: e.noEffect.filter((p) => !GOVERNED.includes(p)), mismatches: e.mismatches, engineReadingUnavailable: e.engineReadingUnavailable, governedOutOfNoEffect: e.noEffect.filter((p) => GOVERNED.includes(p)) },
  };
});

const fixture = {
  about: {
    what: 'Entradas ultron-engine-config/2 (Enmienda M-1): bytes, huella y firma; lo que no se escribe; previewHash sin paquete (Q-4); clasificación con la llave de prompts. Derivado de los contratos por un guion del tester, sin el Studio. Ver publication-cases-v2.README.md.',
    generator: 'tests/fixtures/ultron-studio/tools/derivar-publication-cases-v2.cjs',
    sources: ['ULTRON_STUDIO_H5_CONTRACT.md §A4.1, §A4.2, Q-1, Q-4, M1-5, M1-12', 'publication-cases.README.md E-1, E-2, S-1..S-4', 'ULTRON_STUDIO_F_CONTRACT.md §307', 'plans/STUDIO_MOTORES_PLAN.md §B.4', 'tests/fixtures/ultron-prompts/compile-cases.json'],
  },
  contracts: [C1, C2],
  appKey: APP_KEY,
  governedKeys: GOVERNED,
  effectiveJson: casosValidos,
  invalid: invalidas,
  previewHash: preview,
  classifyWithPrompts: clasifica,
};

const json = JSON.stringify(fixture, null, 2) + '\n';
if (process.argv.includes('--comprobar')) {
  const disco = fs.existsSync(SALIDA) ? fs.readFileSync(SALIDA, 'utf8') : '';
  if (disco !== json) {
    console.error('publication-cases-v2.json NO es el que sale hoy de los contratos.');
    process.exit(1);
  }
  console.log(`OK: publication-cases-v2.json al día (${casosValidos.length} entradas, ${invalidas.length} que no se escriben, ${preview.length} previewHash, ${clasifica.length} clasificaciones).`);
  process.exit(0);
}
fs.writeFileSync(SALIDA, json);
console.log(`Escrito ${path.relative(process.cwd(), SALIDA)}: ${casosValidos.length} entradas, ${invalidas.length} inválidas, ${preview.length} previewHash, ${clasifica.length} clasificaciones, ${json.length} bytes.`);
