#!/usr/bin/env node
'use strict';
/*
 * Genera `tests/fixtures/ultron-prompts/compile-cases.json` desde el CONTRATO
 * (plan de motores §B.3, Enmienda M-1), sin el compilador de PHP.
 *
 *   node backend/tests/fixtures/ultron-prompts/tools/derivar-compile-cases.cjs              # escribe el fixture
 *   node backend/tests/fixtures/ultron-prompts/tools/derivar-compile-cases.cjs --comprobar  # sale 1 si el del disco no es el que sale hoy
 *
 * Antes de escribir nada comprueba que la base del repositorio es el flujo vivo
 * 29de5119 (respaldo exportado y nuevo4.json) byte a byte y que los textos
 * transcritos aparecen literalmente en §B.3. Determinista: dos ejecuciones dan
 * los mismos bytes.
 */
const fs = require('fs');
const path = require('path');
const M = require('./parches-m1.cjs');

const SALIDA = path.join(__dirname, '..', 'compile-cases.json');

M.comprobarTextosContraElPlan();
const base = M.leerBase();
M.comprobarBaseContraFlujo(base);

const huellaDe = (roles) => Object.fromEntries(M.ROLES.map((r) => [r, { sha256: M.sha256(roles[r]), bytes: M.bytes(roles[r]) }]));
const nombre = (g) => `tone=${g['persona.tone']} emojis=${g['persona.emojis']} maxChars=${g['persona.maxChars']} goal=${g['strategy.goal']}`;
const cambiados = (g) => M.PARAMETERS.filter((p) => g[p] !== M.BASELINE[p]);

// La propiedad central (E2), comprobada aquí también: compilar la línea base devuelve la base.
const linea = M.compilar(base, M.BASELINE);
for (const r of M.ROLES) if (linea.roles[r] !== base[r]) throw new Error('compile(línea base) ≠ base en ' + r);
if (linea.patches.length !== 0) throw new Error('la línea base no puede llevar parches');
const legacy = M.bundleHash(base);

// Todos los valores válidos: 3 tonos × 2 emojis × 17 maxChars × 3 objetivos = 306.
const todos = [];
for (const tone of M.TONES) for (const emojis of [true, false]) for (const maxChars of M.MAX_CHARS) for (const goal of M.GOALS) {
  todos.push({ 'persona.emojis': emojis, 'persona.maxChars': maxChars, 'persona.tone': tone, [M.PHRASES]: [], 'strategy.goal': goal });
}
// Orden del fixture: la línea base, después los de un solo parámetro cambiado y después las combinaciones.
const orden = (g) => cambiados(g).length;
todos.sort((a, b) => orden(a) - orden(b));

const textos = {};
const casos = todos.map((g, i) => {
  const c = M.compilar(base, g);
  for (const p of c.patches) textos[p.textKey] = { parameter: p.parameter, value: p.value, text: p.text };
  // Solo los roles que cambian, como [sha256, bytes]; los demás tienen que ser la base (baseRoles), y la prueba lo afirma igual.
  const roles = Object.fromEntries(M.ROLES.filter((r) => c.roles[r] !== base[r]).map((r) => [r, [M.sha256(c.roles[r]), M.bytes(c.roles[r])]]));
  return {
    id: `c${String(i).padStart(3, '0')}`,
    name: nombre(g),
    governed: g,
    changed: cambiados(g),
    changedRoles: M.ROLES.filter((r) => c.roles[r] !== base[r]),
    expect: {
      bundleHash: c.bundleHash,
      bytes: c.bytes,
      roles,
      // [rol, operación, ancla, clave en `texts`] en orden de aplicación (el orden entre roles no es contrato).
      patches: c.patches.map((p) => [p.role, p.operation, p.anchor, p.textKey]),
    },
  };
});

// Determinismo del guion: el mismo resultado sin depender del orden de claves de entrada.
for (const caso of casos.slice(0, 40)) {
  const alReves = Object.fromEntries(Object.entries(caso.governed).reverse());
  if (M.compilar(base, alReves).bundleHash !== caso.expect.bundleHash) throw new Error('el guion no es determinista');
}
// Ninguna huella se repite: cada combinación produce un paquete distinto.
const huellas = new Set(casos.map((c) => c.expect.bundleHash));
if (huellas.size !== casos.length) throw new Error('dos combinaciones dan la misma huella');

// Valores que §B.3 no admite (E2: «valor desconocido → excepción»).
const B = M.BASELINE;
const sin = (k) => Object.fromEntries(Object.entries(B).filter(([c]) => c !== k));
const con = (k, v) => ({ ...B, [k]: v });
const invalidos = [
  ['maxChars fuera de paso (310)', con('persona.maxChars', 310)],
  ['maxChars fuera de paso (330, junto a la línea base)', con('persona.maxChars', 330)],
  ['maxChars bajo el mínimo (140)', con('persona.maxChars', 140)],
  ['maxChars sobre el máximo (500)', con('persona.maxChars', 500)],
  ['maxChars 159 (límite inferior − 1)', con('persona.maxChars', 159)],
  ['maxChars 481 (límite superior + 1)', con('persona.maxChars', 481)],
  ['maxChars 0', con('persona.maxChars', 0)],
  ['maxChars negativo (−20)', con('persona.maxChars', -20)],
  ['maxChars como cadena ("200")', con('persona.maxChars', '200')],
  ['maxChars nulo', con('persona.maxChars', null)],
  ['tone con mayúscula ("Profesional")', con('persona.tone', 'Profesional')],
  ['tone desconocido ("frio")', con('persona.tone', 'frio')],
  ['tone vacío', con('persona.tone', '')],
  ['tone con espacios (" profesional ")', con('persona.tone', ' profesional ')],
  ['tone nulo', con('persona.tone', null)],
  ['goal con mayúscula ("Venta")', con('strategy.goal', 'Venta')],
  ['goal desconocido ("cerrar")', con('strategy.goal', 'cerrar')],
  ['emojis como cadena ("false")', con('persona.emojis', 'false')],
  ['emojis como entero (0)', con('persona.emojis', 0)],
  ['emojis como entero (1)', con('persona.emojis', 1)],
  ['emojis nulo', con('persona.emojis', null)],
  ['falta strategy.goal', sin('strategy.goal')],
  ['falta persona.emojis', sin('persona.emojis')],
  ['una clave de más', { ...B, 'persona.extra': 1 }],
  ['clave con otra grafía (persona.maxchars)', { ...sin('persona.maxChars'), 'persona.maxchars': 320 }],
  ['vacío', {}],
  ['falta rules.forbiddenPhrases (la forma del compilador 1)', sin(M.PHRASES)],
  ['rules.forbiddenPhrases nulo', con(M.PHRASES, null)],
  ['rules.forbiddenPhrases como texto ("gratis")', con(M.PHRASES, 'gratis')],
  ['rules.forbiddenPhrases como objeto con clave', con(M.PHRASES, { frase: 'gratis' })],
].map(([name, governed]) => {
  if (M.valido(governed)) throw new Error('el caso inválido «' + name + '» es válido según el guion');
  return { name, governed };
});

const fixture = {
  about: {
    what: 'Casos de compilación de los prompts de ULTRON (Enmienda M-1 del H5; plan de motores §B.3). Derivados del CONTRATO por un guion Node propio del tester (tools/derivar-compile-cases.cjs), sin el compilador de PHP. Ver compile-cases.README.md.',
    generator: 'tests/fixtures/ultron-prompts/tools/derivar-compile-cases.cjs',
    source: 'docs/ultron-studio/plans/STUDIO_MOTORES_PLAN.md §B.3 (M1-3: textos aprobados tal cual)',
  },
  contract: M.CONTRACT,
  base: 'n8n-29de5119',
  compiler: M.COMPILER,
  roles: M.ROLES,
  baseline: M.BASELINE,
  baseRoles: huellaDe(base),
  legacyBundleHash: legacy,
  limits: M.LIMITS,
  texts: Object.fromEntries(Object.entries(textos).sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))),
  cases: casos,
  invalid: invalidos,
};

// Un caso (o inválido) por línea: un cambio en un caso cambia una línea del diff.
const json = JSON.stringify({ ...fixture, cases: '@CASES@', invalid: '@INVALID@' }, null, 2)
  .replace('"@CASES@"', '[\n' + casos.map((c) => '    ' + JSON.stringify(c)).join(',\n') + '\n  ]')
  .replace('"@INVALID@"', '[\n' + invalidos.map((c) => '    ' + JSON.stringify(c)).join(',\n') + '\n  ]') + '\n';
JSON.parse(json);
if (process.argv.includes('--comprobar')) {
  const disco = fs.existsSync(SALIDA) ? fs.readFileSync(SALIDA, 'utf8') : '';
  if (disco !== json) {
    console.error('compile-cases.json NO es el que sale hoy del contrato (vuelve a generarlo y mira el diff).');
    process.exit(1);
  }
  console.log(`OK: compile-cases.json es el que sale del contrato (${casos.length} casos, ${invalidos.length} inválidos, Legacy ${legacy}).`);
  process.exit(0);
}
fs.writeFileSync(SALIDA, json);
const v2 = casos.find((c) => c.name === 'tone=profesional emojis=true maxChars=200 goal=cita');
console.log(`Escrito ${path.relative(process.cwd(), SALIDA)}: ${casos.length} casos, ${invalidos.length} inválidos, ${Object.keys(textos).length} textos, ${json.length} bytes.`);
console.log(`Legacy ${legacy}`);
console.log(`V2 de aceptación (profesional + 200): ${v2.expect.bundleHash}, ${v2.expect.bytes} B, ${v2.expect.patches.length} parches`);
