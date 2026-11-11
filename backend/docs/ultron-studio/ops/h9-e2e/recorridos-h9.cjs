/**
 * Guion del E2E del Hito 9 con navegador real (prueba #26 del contrato, §13.1; el encargo del lead del 10-11). Dos
 * personas con cuenta propia y vínculo verificado por la orden de consola: Ana (autora) y Beto (revisor).
 *
 *   node recorridos-h9.cjs <run>/context.json
 *
 * Pasos (H9_PASOS=a,b… para desarrollar; por defecto todos, en orden):
 *   humo        las dos sesiones abren la página; banderas, constante, persona vinculada y canal en vivo
 *   plantillas  Ana crea la plantilla asistida; no puede aprobarla; Beto la aprueba
 *   e1          vencimientos: `membership.expiring_window` con condición y seguimiento (`waitFor` y `on.no_response`):
 *               Ana crea, valida en el servidor, simula y congela; Beto aprueba y publica en canario (sombra)
 *   e2          evento de clase: `class.upcoming_with_capacity` (mismo ciclo)
 *   e3          prospectos: `lead.followup_due` (mismo ciclo)
 *   consola     `outreach:sweep` y `outreach:dispatch --inline` por consola con las personas sintéticas
 *   panel       la pasada, las inscripciones con la traza en frases, los trabajos simulados y el canal en vivo
 *   parada      PARADA GLOBAL de Ana; Ana no puede soltarla; Beto la suelta con nota
 *   pausa       Beto pausa la campaña de prospectos; la pasada siguiente no la ejecuta
 *   asistido    (desviación documentada) modo `live` con el asistido encendido: una socia nueva, la bandeja,
 *               reclamar y registrar el resultado
 *   url         H9-E2E-4: abrir una estrategia la deja en la URL (F5 y enlaces), también con otra ya elegida
 *   barrido     DOM, almacenamiento, consola y red de las dos sesiones; hosts; respuestas de la API y eventos SSE
 */
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const L = require('./lib-h9.cjs');

const ctx = L.cargarContexto(process.argv[2]);
const inf = new L.Informe(ctx, L.prohibidos(ctx));
inf.ctx = ctx;
const SELLO = new Date().toISOString().replace(/\D/g, '').slice(8, 14); // hhmmss: claves únicas por vuelta
const estado = { plantilla: null, estrategias: {}, versiones: {}, consola: {} };
let A;
let B;
/**
 * El reloj del MOTOR: toda orden `outreach:*` por consola corre con `now()` de Carbon en este instante (T, el próximo
 * día hábil a las 10:00 de Bogotá; la siembra es relativa a T) y avanza de una orden a la siguiente. El navegador, la
 * API y la base siguen con el reloj real (README, «Costuras»).
 */
let relojMotor = Date.parse(ctx.relojMotor);
const motor = (args, avance = 60000) => {
  const r = L.artisan(ctx, args, {}, new Date(relojMotor).toISOString());
  r.reloj = new Date(relojMotor).toISOString();
  relojMotor += avance;
  return r;
};
/** El despacho: en el último `not_before` planificado + 1 s (nunca antes del reloj del motor). */
const despachar = (notBefores, extra = []) => {
  const ultimo = notBefores.map((x) => Date.parse(String(x).replace(' ', 'T') + (String(x).endsWith('Z') ? '' : 'Z'))).reduce((a, b) => Math.max(a, b), relojMotor);
  relojMotor = Math.max(relojMotor, ultimo + 1000);
  return motor(['outreach:dispatch', '--inline', ...extra]);
};

// ── Utilidades del guion ──────────────────────────────────────────────────────────────────────────────────────────
const sel = (x) => JSON.stringify(x);

/** Pone el valor de un control localizado por una expresión JS (select o input) y dispara los eventos de Angular. */
async function poner(s, exprElemento, v, evento = 'change') {
  const r = await L.leer(s, `
    const e = (() => { ${exprElemento} })();
    if (!e) return 'no-existe';
    if (e.tagName === 'SELECT' && ![...e.options].some((o) => o.value === ${sel(String(v))})) return 'sin-opcion:' + [...e.options].map((o) => o.value).join('|').slice(0, 300);
    e.focus();
    e.value = ${sel(String(v))};
    e.dispatchEvent(new Event('input', { bubbles: true }));
    e.dispatchEvent(new Event(${sel(evento)}, { bubbles: true }));
    e.blur();
    return e.value === ${sel(String(v))} ? 'ok' : 'quedo:' + e.value;
  `);
  if (r !== 'ok') throw new L.Requisito(`control ${exprElemento.slice(0, 120)} → ${v}: ${r}`);
  await L.dormir(150);
}

/** Espera a que aparezca un selector (sin gesto). */
async function hay(s, selector, ms = L.T(20000)) {
  return !!(await L.esperar(s, `return !!document.querySelector(${sel(selector)});`, ms));
}

/** Texto normalizado de la página (para comprobar frases). */
async function textoPagina(s) {
  return L.leer(s, `${L.NORMALIZA} return n(document.querySelector('[data-ca-pagina]')?.innerText || document.body.innerText);`);
}

/** El aviso (toast) o la alerta visibles, para el detalle de una comprobación. */
async function avisos(s) {
  return L.leer(s, `${L.NORMALIZA} return [...document.querySelectorAll('[data-ca-aviso], [role=alert], .lab-alert')].map((e) => n(e.innerText)).filter(Boolean).slice(0, 4);`);
}

/** El diálogo abierto (el panel `[role=dialog][aria-modal]`). */
const DIALOGO = '[role=dialog][aria-modal=true]';

/** El título del constructor (nombre y clave de la estrategia que tiene abierta). */
const TITULO_CONSTRUCTOR = '[data-ca-constructor] h2, [data-ca-constructor] .ig-h2';
const tituloConstructor = (s) => L.texto(s, TITULO_CONSTRUCTOR);

/** La estrategia de esa clave según la API (la lista de Ana), con reintentos. */
async function creadaPorClave(clave) {
  for (let i = 0; i < 20; i++) {
    const r = await L.api(ctx, 'a', 'GET', 'admin/ultron-studio/strategies?per_page=50');
    const x = (r.json?.data?.data ?? []).find((e) => e.key === clave);
    if (x) return x;
    await L.dormir(500);
  }
  return null;
}

/** La pestaña y la estrategia de la URL de una sesión, esperando hasta `ms` a que la estrategia sea `uuid`. */
async function urlDe(s, uuid, ms) {
  const fin = Date.now() + ms;
  let u;
  do {
    u = await L.leer(s, `const u = new URL(location.href); return { pestana: u.searchParams.get('pestana'), estrategia: u.searchParams.get('estrategia') };`);
    if (u.estrategia === uuid) return u;
    await L.dormir(250);
  } while (Date.now() < fin);
  return u;
}

/** Escribe el motivo en un `ib-reason-dialog` y confirma. */
async function motivoYConfirmar(s, texto) {
  if (!(await hay(s, `${DIALOGO} textarea`))) throw new L.Requisito('no se abrió el diálogo con motivo');
  const ta = await s.page.$(`${DIALOGO} textarea`);
  await ta.click();
  await ta.type(texto);
  await L.dormir(200);
  const ok = await L.clic(s, `${DIALOGO} button[type=submit]`);
  if (!ok) throw new L.Requisito('no se pudo confirmar el diálogo');
}

/** Espera a que no haya diálogo abierto. */
async function sinDialogo(s, ms = L.T(20000)) {
  return !!(await L.esperar(s, `return !document.querySelector(${sel(DIALOGO)});`, ms));
}

async function foto(s, nombre) {
  await L.dormir(400);
  return L.captura(s, ctx, nombre);
}

/** El próximo día hábil (lunes a viernes, sin el festivo del 16-11-2026) a las 10:00 de Bogotá, en UTC. */
function instanteHabil() {
  const festivos = new Set(['2026-11-16', '2026-12-08', '2026-12-25']);
  const d = new Date(Date.now() - 5 * 3600 * 1000); // Bogotá (UTC-5, sin horario de verano)
  for (;;) {
    d.setUTCDate(d.getUTCDate() + 1);
    const dia = d.getUTCDay();
    const ymd = d.toISOString().slice(0, 10);
    if (dia >= 1 && dia <= 5 && !festivos.has(ymd)) return `${ymd}T15:00:00Z`;
  }
}

// ── El constructor ──────────────────────────────────────────────────────────────────────────────────────────────
/** Las rutas de los nodos de un tipo dentro de un editor de condiciones (`audience`, `success`, …). */
async function nodos(s, raiz, tipo) {
  return L.leer(s, `return [...document.querySelectorAll('fieldset[data-ca-condiciones=${sel(raiz)}] [data-cce-tipo=${sel(tipo)}]')].map((e) => e.getAttribute('data-cce-nodo'));`);
}

/** Construye en el editor `raiz` un grupo Y de hechos `[hecho, op, valor]` (o un solo hecho si hay uno). */
async function condiciones(s, raiz, hechos) {
  const fs_ = `fieldset[data-ca-condiciones=${sel(raiz)}]`;
  if (hechos.length === 1) {
    if (!(await L.clic(s, `${fs_} [data-cce-empezar="fact"]`))) throw new L.Requisito(`${raiz}: no hay «Un hecho»`);
    await L.esperar(s, `return !!document.querySelector('${fs_} [data-cce-tipo="fact"]');`, L.T(10000));
  } else {
    if (!(await L.clic(s, `${fs_} [data-cce-empezar="all"]`))) throw new L.Requisito(`${raiz}: no hay «Un grupo Y»`);
    // El grupo nace con un hecho vacío: se añaden solo los que faltan.
    await L.esperar(s, `return !!document.querySelector('${fs_} [data-cce-tipo="all"]');`, L.T(5000));
    for (let i = (await nodos(s, raiz, 'fact')).length; i < hechos.length; i++) {
      const antes = (await nodos(s, raiz, 'fact')).length;
      if (!(await L.clic(s, `${fs_} [data-cce-anadir="fact"]`))) throw new L.Requisito(`${raiz}: no se pudo añadir un hecho`);
      await L.esperar(s, `return document.querySelectorAll('${fs_} [data-cce-tipo="fact"]').length > ${antes};`, L.T(5000));
    }
  }
  const rutas = await nodos(s, raiz, 'fact');
  if (rutas.length !== hechos.length) throw new L.Requisito(`${raiz}: ${rutas.length} hechos en el árbol, se esperaban ${hechos.length}`);
  for (let i = 0; i < hechos.length; i++) {
    const [hecho, op, v] = hechos[i];
    const nodo = `${fs_} [data-cce-nodo=${sel(rutas[i])}]`;
    await poner(s, `return document.querySelector(${sel(nodo + ' [data-cce-fact]')});`, hecho);
    await poner(s, `return document.querySelector(${sel(nodo + ' [data-cce-op]')});`, op);
    const esSelect = await L.leer(s, `const e = document.querySelector(${sel(nodo + ' [data-cce-value]')}); return e ? e.tagName : null;`);
    if (!esSelect) throw new L.Requisito(`${raiz}: el hecho ${hecho} no tiene control de valor`);
    await poner(s, `return document.querySelector(${sel(nodo + ' [data-cce-value]')});`, String(v), esSelect === 'SELECT' ? 'change' : 'input');
  }
}

/** Un paso de la secuencia: id, canal y plantilla aprobada, mapa de resultados y espera. */
async function paso(s, i, { id, retraso, plantilla, on = {}, espera = null }) {
  const p = `[data-ca-paso="${i}"]`;
  await poner(s, `return document.querySelector(${sel(`${p} [data-ca-paso-id="${i}"]`)});`, id, 'input');
  if (retraso) {
    await poner(s, `return document.querySelector(${sel(`${p} input[aria-label="Cantidad del retraso"]`)});`, String(retraso.n), 'input');
    await poner(s, `return document.querySelector(${sel(`${p} select[aria-label="Unidad del retraso"]`)});`, retraso.unit);
  }
  const tieneCanal = await L.leer(s, `return !!document.querySelector(${sel(`${p} [data-ca-paso-plantilla="assisted:whatsapp"]`)});`);
  if (!tieneCanal && !(await L.clic(s, `${p} [data-ca-paso-canal-anadir="assisted:whatsapp"]`))) throw new L.Requisito(`paso ${i}: no se pudo añadir el canal asistido`);
  await poner(s, `return document.querySelector(${sel(`${p} [data-ca-paso-plantilla="assisted:whatsapp"]`)});`, plantilla);
  for (const [resultado, transicion] of Object.entries(on)) {
    await poner(s, `return document.querySelector(${sel(`${p} [data-ca-on="${resultado}"]`)});`, transicion);
  }
  if (espera) {
    if (!(await hay(s, `${p} [data-ca-waitfor]`, L.T(5000)))) throw new L.Requisito(`paso ${i}: no apareció «Cuánto se espera»`);
    await poner(s, `return document.querySelector(${sel(`${p} [data-ca-waitfor]`)});`, String(espera.n), 'input');
    await poner(s, `return document.querySelector(${sel(`${p} select[aria-label="Unidad de la espera"]`)});`, espera.unit);
  }
}

/** Los errores de forma que enseña el constructor (para el detalle). */
async function erroresForma(s) {
  return L.leer(s, `${L.NORMALIZA} return [...document.querySelectorAll('[data-ca-forma-lista] li, [data-cce-error]')].map((e) => n(e.innerText)).slice(0, 8);`);
}

/** Las decisiones simuladas cuadran con las esperadas (solo las personas que se nombran). */
function decisionesComoSeEsperan(reales, esperadas) {
  if (!esperadas) return true;
  return Object.entries(esperadas).every(([ref, kind]) => reales[ref] === kind);
}

/** La última simulación guardada de una estrategia (resultado sin datos personales: personas de prueba). */
function ultimaSimulacion(uuid) {
  const f = L.sql(ctx, `select s.result_json from ultron_studio_strategy_simulations s join ultron_studio_strategies e on e.id = s.strategy_id where e.uuid = '${uuid}' order by s.id desc limit 1`)[0];
  if (!f) return null;
  return typeof f.result_json === 'string' ? JSON.parse(f.result_json) : f.result_json;
}

/** Rellena y lanza la simulación sintética sobre el borrador (`draft`) o una versión (su uuid). */
async function simularEn(c, uuid, objetivo, prefijoFoto) {
  if (!(await L.leer(A, `return !!document.querySelector('[data-ca-sim-sintetica]');`))) {
    await L.pagina(A, ctx, `simulacion&estrategia=${uuid}`);
  }
  inf.must('la pestaña Simulación queda lista', await hay(A, '[data-ca-sim-sintetica]', L.T(20000)));
  await L.esperar(A, `return !!document.querySelector('[data-ca-sim-objetivo] option[value="${objetivo}"]');`, L.T(20000));
  await poner(A, `return document.querySelector('[data-ca-sim-objetivo]');`, objetivo);
  // El instante: el próximo día hábil a las 10:00 de Bogotá, para que el horario legal no dependa de la hora del guion.
  await poner(A, `return document.querySelector('[data-ca-sim-at]');`, ctx.relojMotor || instanteHabil(), 'input');
  for (let i = 0; i < c.simulacion.length; i++) {
    if (!(await L.leer(A, `return !!document.querySelector('[data-ca-sim-persona="${i}"]');`))) {
      if (!(await L.clic(A, '[data-ca-sim-persona-anadir]'))) throw new L.Requisito('no se pudo añadir una persona de prueba');
      await hay(A, `[data-ca-sim-persona="${i}"]`, L.T(5000));
    }
    const p = c.simulacion[i];
    const fsP = `fieldset[data-ca-sim-persona="${i}"]`;
    await poner(A, `return document.querySelector(${sel(`${fsP} [data-ca-sim-ref]`)});`, p.ref, 'input');
    await poner(A, `return [...document.querySelectorAll(${sel(`${fsP} label`)})].find((l) => /Autorización WhatsApp/.test(l.textContent))?.querySelector('select');`, p.whatsapp);
    const canario = await L.leer(A, `const l = [...document.querySelectorAll(${sel(`${fsP} label`)})].find((x) => /canario WhatsApp/.test(x.textContent)); return l ? l.querySelector('input').checked : null;`);
    if (canario !== p.canario) {
      await L.leer(A, `const l = [...document.querySelectorAll(${sel(`${fsP} label`)})].find((x) => /canario WhatsApp/.test(x.textContent)); l.querySelector('input').click(); return true;`);
    }
    for (const [hecho, v] of p.hechos) {
      const ya = await L.leer(A, `return !!document.querySelector(${sel(`${fsP} [aria-label="${hecho}"]`)});`);
      if (!ya) {
        // «Añadir un hecho» se vacía solo tras añadir (diseño): no se exige que conserve el valor, sí que aparezca el hecho.
        const r = await L.leer(A, `const e = document.querySelector(${sel(`${fsP} [data-ca-sim-hecho-anadir]`)}); if (!e) return 'no-existe'; if (![...e.options].some((o) => o.value === ${sel(hecho)})) return 'sin-opcion'; e.value = ${sel(hecho)}; e.dispatchEvent(new Event('change', { bubbles: true })); return 'ok';`);
        if (r !== 'ok') throw new L.Requisito(`simulación: no se pudo añadir el hecho ${hecho} (${r})`);
        await L.esperar(A, `return !!document.querySelector(${sel(`${fsP} [aria-label="${hecho}"]`)});`, L.T(10000));
      }
      const tag = await L.leer(A, `return document.querySelector(${sel(`${fsP} [aria-label="${hecho}"]`)})?.tagName || null;`);
      if (!tag) throw new L.Requisito(`simulación: el hecho ${hecho} no apareció`);
      await poner(A, `return document.querySelector(${sel(`${fsP} [aria-label="${hecho}"]`)});`, String(v), tag === 'SELECT' ? 'change' : 'input');
    }
    // Sin eventos ni contactos (ya no hay costura H9-E2E-2): «sin ellos, y se sabe».
    const conEventos = await L.leer(A, `return document.querySelectorAll(${sel(`${fsP} [data-ca-sim-evento]`)}).length;`);
    if (conEventos) throw new L.Requisito(`simulación: la persona ${p.ref} tiene ${conEventos} evento(s) y el guion no añadió ninguno`);
  }
  await foto(A, `${prefijoFoto}-ana-simulacion-personas`);
  const enviadasAntes = A.simulaciones.length;
  if (!(await L.clic(A, '[data-ca-sim-lanzar]'))) throw new L.Requisito('no se pudo lanzar la simulación');
  const antes = await L.leer(A, `return document.querySelector('[data-ca-sim-resultado]')?.outerHTML.length ?? 0;`);
  void antes;
  const pasa = await L.esperar(A, `const r = document.querySelector('[data-ca-sim-resultado]'); return r ? r.getAttribute('data-pasa') : (document.querySelector('[data-ca-sim-error]') ? 'error' : null);`, L.T(30000));
  await L.dormir(800);
  const decisiones = await L.leer(A, `return Object.fromEntries([...document.querySelectorAll('[data-ca-sim-persona-resultado]')].map((d) => [d.getAttribute('data-ca-sim-persona-resultado'), d.getAttribute('data-decision')]));`);
  await L.leer(A, `document.querySelectorAll('[data-ca-sim-persona-resultado]').forEach((d) => { d.open = true; }); return true;`);
  await L.dormir(300);
  const textos = await L.leer(A, `${L.NORMALIZA} return Object.fromEntries([...document.querySelectorAll('[data-ca-sim-persona-resultado]')].map((d) => [d.getAttribute('data-ca-sim-persona-resultado'), n(d.innerText).slice(0, 600)]));`);
  await foto(A, `${prefijoFoto}-ana-simulacion-resultado`);
  // Lo que mandó el navegador (CDP, solo la FORMA de `events` y `contacts` por persona de prueba; ver lib-h9 `sesion`).
  const enviadas = A.simulaciones.slice(enviadasAntes);
  return { pasa, decisiones, textos, enviada: enviadas.length === 1 ? enviadas[0] : { peticiones: enviadas.length, formas: enviadas.map((e) => e.formas) } };
}

/** ¿Cada persona de prueba viajó con `events: []` y `contacts: []` (listas vacías), nunca null ni ausentes? */
function listasVacias(enviada) {
  const f = enviada?.formas;
  return Array.isArray(f) && f.length > 0 && f.every((p) => p.events === 'lista(0)' && p.contacts === 'lista(0)');
}

/**
 * El ciclo de una estrategia desde el navegador: Ana la crea, la construye, la guarda, la valida en el servidor, la
 * simula y la congela; Beto la aprueba (cuatro ojos) y la publica en canario. Devuelve los uuid.
 */
async function cicloEstrategia(c) {
  const clave = `${c.clave}-${SELLO}`;
  // 1. Ana crea la estrategia (Estrategias → Nueva).
  if (!(await L.pagina(A, ctx, 'estrategias'))) throw new L.Requisito('la pestaña Estrategias no quedó lista');
  inf.must('Ana ve «Nueva estrategia»', await L.clic(A, '[data-ca-estrategias-crear]'));
  await L.escribir(A, '[data-ca-crear-clave]', clave);
  await L.escribir(A, '[data-ca-crear-nombre]', c.nombre);
  await poner(A, `return document.querySelector('[data-ca-crear-finalidad]');`, 'commercial');
  await foto(A, `${c.n}-01-ana-crea-estrategia`);
  inf.must('Ana crea la estrategia', await L.clic(A, '[data-ca-crear-enviar]'));
  // REGRESIÓN H9-E2E-1 (informe §B.2; corregido en L9-84 con `StudioStrategies::draftApiDto()`): el alta (201) traía el
  // borrador vacío como `"content":[]` y el CRM no abría el constructor ni entonces ni después desde la lista. Sin
  // costura: TODO el alta se hace en el navegador, y cada síntoma del hallazgo queda como comprobación.
  const enConstructor = await hay(A, '[data-ca-constructor]', L.T(30000));
  if (!enConstructor) await foto(A, `${c.n}-01b-ana-alta-sin-constructor`);
  inf.must('H9-E2E-1: al crearla, el CRM lee el alta (201) y abre el constructor', enConstructor, enConstructor ? undefined : { aviso: await avisos(A), hallazgo: 'H9-E2E-1' });
  // El uuid, por su clave en la API: la URL no lo lleva tras abrir una estrategia (H9-E2E-4, paso `url`).
  const creada = await creadaPorClave(clave);
  inf.must('la estrategia nueva existe en el servidor (por su clave) y tiene uuid', !!creada && /^[0-9a-f-]{36}$/.test(String(creada.uuid)), creada && { uuid: creada.uuid, clave: creada.key });
  const uuid = creada.uuid;
  estado.estrategias[c.n] = { uuid, clave };
  const titulo = await tituloConstructor(A);
  inf.check('H9-E2E-1: el constructor que abre tras el alta es el de la estrategia nueva (su clave en el título)', (titulo || '').includes(clave), { titulo, clave });
  await foto(A, `${c.n}-01b-ana-constructor-tras-el-alta`);
  // El JSON CRUDO del detalle: el borrador vacío es un OBJETO (`{}`) o null; nunca una lista (`[]`).
  const det = await L.api(ctx, 'a', 'GET', `admin/ultron-studio/strategies/${uuid}`);
  const crudo = (det.texto.match(/"draft"\s*:\s*\{[^{}]*?"content"\s*:\s*(\{\}|\[\]|null)/) || [])[1] ?? '(otro: ¿borrador no vacío?)';
  inf.check('H9-E2E-1: el detalle trae el borrador vacío como objeto (`"content":{}`) o null, nunca `[]`', det.status === 200 && (crudo === '{}' || crudo === 'null'), { status: det.status, contenidoCrudo: crudo, revision: det.json?.data?.draft?.revision });
  // Abrirla DESPUÉS desde la lista (el segundo síntoma): Estrategias → «Abrir» de su fila → el constructor, sin error.
  if (!(await L.pagina(A, ctx, 'estrategias'))) throw new L.Requisito('la pestaña Estrategias no volvió a quedar lista');
  inf.must('la estrategia nueva aparece en la lista de Estrategias', await hay(A, `[data-ca-abrir="${uuid}"]`, L.T(20000)));
  await L.clic(A, `[data-ca-abrir="${uuid}"]`);
  const abre = await L.esperar(A, `if (document.querySelector('[data-ca-constructor-error]')) return 'error'; const t = document.querySelector(${sel(TITULO_CONSTRUCTOR)}); return t && t.textContent.includes(${sel(clave)}) ? 'ok' : null;`, L.T(30000));
  await foto(A, `${c.n}-01c-ana-abre-desde-la-lista`);
  inf.must('H9-E2E-1: abierta después desde la lista, el constructor carga el borrador vacío sin error', abre === 'ok', abre === 'error' ? { error: await L.texto(A, '[data-ca-constructor-error]'), hallazgo: 'H9-E2E-1' } : { resultado: abre ?? 'no apareció' });

  // 2. El constructor: objetivo, prioridad, disparador y parámetros, condiciones, secuencia y canario.
  await poner(A, `return document.querySelector('[data-ca-goal]');`, c.goal);
  await poner(A, `return document.querySelector('[data-ca-priority]');`, String(c.prioridad), 'input');
  await poner(A, `return document.querySelector('[data-ca-trigger]');`, c.evento);
  for (const [k, v] of Object.entries(c.params || {})) {
    if (!(await hay(A, `[data-ca-param="${k}"]`, L.T(5000)))) throw new L.Requisito(`no apareció el parámetro ${k}`);
    await poner(A, `return document.querySelector('[data-ca-param="${k}"]');`, String(v), 'input');
  }
  await condiciones(A, 'audience', c.audiencia);
  await condiciones(A, 'success', c.exito);
  for (let i = 0; i < c.pasos.length; i++) {
    if (i > 0 && !(await L.leer(A, `return !!document.querySelector('[data-ca-paso="${i}"]');`))) {
      if (!(await L.clic(A, '[data-ca-paso-anadir]'))) throw new L.Requisito('no se pudo añadir un paso');
      await hay(A, `[data-ca-paso="${i}"]`, L.T(5000));
    }
    await paso(A, i, { ...c.pasos[i], plantilla: estado.plantilla.uuid });
  }
  await poner(A, `return document.querySelector('[data-ca-canario-canal]');`, 'whatsapp');
  const formaOk = await hay(A, '[data-ca-forma-ok]', L.T(10000));
  inf.must('la forma en vivo es válida (espejo del servidor, sin texto libre)', formaOk, formaOk ? undefined : await erroresForma(A));
  const huellaVivo = await L.leer(A, `return document.querySelector('[data-ca-forma-ok] .ca-hash')?.getAttribute('title') || null;`);
  await foto(A, `${c.n}-02-ana-constructor`);

  // 3. Guardar (baseRevision) y validar en el servidor.
  inf.must('Ana guarda el borrador', await L.clic(A, '[data-ca-guardar]'));
  inf.must('el borrador queda guardado', !!(await L.esperar(A, `return document.querySelector('[data-ca-constructor]')?.getAttribute('data-ca-sucio') === 'no';`, L.T(20000))), await avisos(A));
  inf.must('Ana valida en el servidor', await L.clic(A, '[data-ca-validar]'));
  inf.must('el servidor contesta la validación', await hay(A, '[data-ca-validacion-servidor]', L.T(20000)));
  const val = await L.texto(A, '[data-ca-validacion-servidor]');
  inf.check('el servidor: sin bloqueantes y la misma huella que la forma en vivo', /Sin bloqueantes/.test(val || '') && !!huellaVivo && (val || '').includes(huellaVivo.slice(0, 16)), { servidor: val, enVivo: huellaVivo && huellaVivo.slice(0, 16) });
  await foto(A, `${c.n}-03-ana-validacion-servidor`);

  // 4. Simular el borrador con personas de prueba (sintéticas: nadie real).
  inf.must('Ana abre «Simular»', await L.clic(A, '[data-ca-ir-simulacion]'));
  const sb = await simularEn(c, uuid, 'draft', `${c.n}-04`);
  inf.check('la simulación del BORRADOR pasa (sin bloqueantes) con las decisiones esperadas por persona', sb.pasa === 'si' && decisionesComoSeEsperan(sb.decisiones, c.decisionesSimuladas), { pasa: sb.pasa, decisiones: sb.decisiones, esperadas: c.decisionesSimuladas });
  // REGRESIÓN H9-E2E-2 (informe §B.2; corregido en L9-84, `ca-simulation.ts`): sin la costura del evento inocuo, ninguna
  // persona de prueba lleva eventos. El CRM tiene que mandar listas vacías (no null) y ninguna puede salir negada en N6
  // por «pidió hablar con alguien» (`human_requested_recently`), que era la explicación falsa.
  inf.check('H9-E2E-2: el CRM manda `events: []` y `contacts: []` (listas vacías, no null) por cada persona de prueba', listasVacias(sb.enviada), { hallazgo: 'H9-E2E-2', ...sb.enviada });
  const rb = ultimaSimulacion(uuid);
  const porMotivo = rb?.counts?.byReason ?? null;
  const falsas = Object.entries(sb.textos).filter(([, t]) => /pidió hablar con alguien/i.test(t)).map(([ref]) => ref);
  inf.check('H9-E2E-2: ninguna persona de prueba sin eventos se niega por «pidió hablar con alguien» (ni en la frase ni en los recuentos)', !falsas.length && !(porMotivo && porMotivo.human_requested_recently), { conLaFrase: falsas, recuentosPorMotivo: porMotivo, hallazgo: 'H9-E2E-2' });
  if (c.sinEventos) {
    const texto = sb.textos['p-sin-eventos'] || '';
    inf.check('H9-E2E-2: la persona de prueba autorizada y SIN eventos se planifica (`plan`), como la del caso positivo', sb.decisiones['p-sin-eventos'] === 'plan', { decision: sb.decisiones['p-sin-eventos'], frase: texto.slice(0, 300), hallazgo: 'H9-E2E-2' });
  }

  // 5. Congelar la versión (exige el borrador guardado y la forma válida).
  if (!(await L.pagina(A, ctx, `constructor&estrategia=${uuid}`))) throw new L.Requisito('el constructor no volvió a quedar listo');
  await hay(A, '[data-ca-constructor]', L.T(20000));
  inf.must('Ana congela la versión', await L.clic(A, '[data-ca-congelar]'));
  let version = null;
  for (let i = 0; i < 40 && !version; i++) {
    const d = await L.api(ctx, 'a', 'GET', `admin/ultron-studio/strategies/${uuid}`);
    version = d.json?.data?.versions?.[0] ?? null;
    if (!version) await L.dormir(500);
  }
  inf.must('la versión 1 existe y su huella es la de la forma en vivo', !!version && version.contentHash === huellaVivo, { status: version?.status, huella: version?.contentHash?.slice(0, 16), enVivo: huellaVivo?.slice(0, 16) });
  estado.versiones[c.n] = version.uuid;
  await foto(A, `${c.n}-06-ana-version-congelada`);
  // Aprobar exige una simulación de ESTA versión (StudioStrategyVersions::passingSimulation: misma versión y huella).
  const sv = await simularEn(c, uuid, version.uuid, `${c.n}-06b`);
  inf.must('la simulación de la VERSIÓN pasa (sin bloqueantes): la puerta de aprobar', sv.pasa === 'si', { pasa: sv.pasa, decisiones: sv.decisiones });

  // 6. Ana NO puede aprobar su propia versión (cuatro ojos por persona).
  if (!(await L.pagina(A, ctx, `gobierno&estrategia=${uuid}`))) throw new L.Requisito('el gobierno de Ana no quedó listo');
  await L.clic(A, `[data-ca-version-abrir="${version.uuid}"]`);
  await hay(A, '[data-ca-version-detalle]', L.T(15000));
  const anaPuede = await L.leer(A, `return !!document.querySelector('[data-ca-aprobar]');`);
  const vAna = await L.api(ctx, 'a', 'GET', `admin/ultron-studio/strategies/versions/${version.uuid}`);
  inf.check('Ana (autora) no ve «Aprobar» y el servidor tampoco se lo permite', !anaPuede && vAna.json?.data?.actions?.approve?.allowed === false, { boton: anaPuede, servidor: vAna.json?.data?.actions?.approve });
  await foto(A, `${c.n}-07-ana-no-puede-aprobar`);

  // 7. Beto revisa, reconoce los avisos y aprueba.
  if (!(await L.pagina(B, ctx, `gobierno&estrategia=${uuid}`))) throw new L.Requisito('el gobierno de Beto no quedó listo');
  inf.must('Beto abre la versión', await L.clic(B, `[data-ca-version-abrir="${version.uuid}"]`));
  inf.must('Beto ve «Aprobar»', await hay(B, '[data-ca-aprobar]', L.T(15000)));
  await L.clic(B, '[data-ca-aprobar]');
  inf.must('se abre el diálogo de aprobar', await hay(B, '[data-ca-aprobar-form]', L.T(10000)));
  const nAvisos = await L.leer(B, `const cs = [...document.querySelectorAll('[data-ca-aprobar-avisos] input[type=checkbox]')]; cs.forEach((c) => { if (!c.checked) c.click(); }); return cs.length;`);
  await foto(B, `${c.n}-08-beto-aprobar-avisos`);
  inf.must('Beto confirma la aprobación', await L.clic(B, '[data-ca-aprobar-confirmar]'));
  await sinDialogo(B);
  let aprobada = null;
  for (let i = 0; i < 40; i++) {
    const d = await L.api(ctx, 'b', 'GET', `admin/ultron-studio/strategies/versions/${version.uuid}`);
    aprobada = d.json?.data;
    if (aprobada?.status === 'APPROVED') break;
    await L.dormir(500);
  }
  inf.must('la versión queda APROBADA por otra persona', aprobada?.status === 'APPROVED', { status: aprobada?.status, avisosReconocidos: nAvisos, avisos: await avisos(B) });

  // 8. Beto publica en canario (persona distinta de la autora; huella que vio; bandera de publicar encendida).
  await L.pagina(B, ctx, `gobierno&estrategia=${uuid}`);
  await L.clic(B, `[data-ca-version-abrir="${version.uuid}"]`);
  inf.must('Beto ve «Publicar en canario»', await hay(B, '[data-ca-publicar]', L.T(15000)));
  await L.clic(B, '[data-ca-publicar]');
  inf.must('el diálogo enseña la huella que se publica', await hay(B, '[data-ca-publicar-hash]', L.T(10000)));
  const huellaDialogo = await L.texto(B, '[data-ca-publicar-hash]');
  await foto(B, `${c.n}-09-beto-publicar`);
  inf.must('Beto confirma la publicación', await L.clic(B, '[data-ca-publicar-confirmar]'));
  await sinDialogo(B, L.T(30000));
  let pub = null;
  for (let i = 0; i < 40; i++) {
    const d = await L.api(ctx, 'b', 'GET', `admin/ultron-studio/strategies/${uuid}/publications`);
    pub = d.json;
    if ((pub?.data?.data ?? pub?.data ?? []).length) break;
    await L.dormir(500);
  }
  const entradas = pub?.data?.data ?? pub?.data ?? [];
  const cadena = pub?.meta?.state ?? null;
  const verificada = (v) => v === true || v === 'ok';
  inf.check('la cadena tiene la entrada `publish` VERIFICADA con la huella del diálogo y el canario activo', entradas.length === 1 && entradas[0].action === 'publish' && verificada(entradas[0].verified) && verificada(cadena?.verified) && huellaDialogo === version.contentHash && cadena?.status === 'active' && cadena?.canary?.contentHash === version.contentHash,
    { entradas: entradas.map((e) => ({ seq: e.seq, action: e.action, verified: e.verified })), cadena, huellaDialogo: (huellaDialogo || '').slice(0, 16) });
  await L.pagina(B, ctx, `gobierno&estrategia=${uuid}`);
  await hay(B, '[data-ca-cadena-tabla]', L.T(15000));
  await foto(B, `${c.n}-10-beto-cadena-canario`);
  return { uuid, version: version.uuid };
}

// ── Los pasos ────────────────────────────────────────────────────────────────────────────────────────────────────
const PASOS = {};

PASOS.humo = async () => {
  for (const s of [A, B]) {
    inf.must(`${s.quien === 'a' ? 'Ana' : 'Beto'} abre «Campañas automáticas»`, await L.pagina(s, ctx, 'panel'));
    const chips = await L.leer(s, `${L.NORMALIZA} return Object.fromEntries(['gobierno', 'publicar', 'persona', 'parada', 'cadena'].map((k) => [k, n(document.querySelector('[data-ca-chip-' + k + ']')?.innerText)]));`);
    inf.check(`${s.quien}: chips del gobierno (encendido, publicar, persona vinculada)`, !!chips.gobierno && !!chips.persona && /vinculad|verificad/i.test(chips.persona || ''), chips);
    const vivo = await L.esperar(s, `return document.querySelector('[data-ca-live]')?.getAttribute('data-ca-live') === 'live' ? 'live' : null;`, L.T(30000));
    inf.check(`${s.quien}: canal en vivo conectado (SSE)`, vivo === 'live', await L.leer(s, `return document.querySelector('[data-ca-live]')?.getAttribute('data-ca-live');`));
    // L9-79 (la ola): «Estado de activación» encima del panel, con las siete funciones de las campañas. Terminada su
    // lectura (la insignia «Leyendo…» desaparece), cada una tiene un estado (o «Sin estado» si no puede leerlo).
    await L.esperar(s, `const p = document.querySelector('[data-activacion-panel="campanas"]'); return !!p && !p.querySelector('[data-activacion-leyendo]');`, L.T(30000));
    const act = await L.leer(s, `return [...document.querySelectorAll('[data-activacion-panel="campanas"] [data-activacion]')].map((e) => ({ id: e.getAttribute('data-activacion'), estado: e.getAttribute('data-estado'), lectura: e.getAttribute('data-lectura'), leyendo: !!e.querySelector('[data-activacion-leyendo]') }));`);
    inf.check(`${s.quien}: «Estado de activación» con las 7 funciones de las campañas, cada una con su estado (ninguna se queda en «Leyendo…»)`,
      act.length === 7 && act.every((r) => !r.leyendo && (!!r.estado || r.lectura === 'unreadable')), act.map((r) => [r.id, r.estado || r.lectura]));
  }
  await L.esperar(A, `return (document.querySelector('[data-ca-banderas]')?.innerText || '').length > 40;`, L.T(30000));
  const banderas = await L.texto(A, '[data-ca-banderas]');
  inf.check('el panel enseña el modo sombra y la constante de envío cerrada', /Sombra \(todo simulado\)/.test(banderas || '') && /Cerrada Envío automático en el código/.test(banderas || ''), banderas);
  const st = await L.api(ctx, 'a', 'GET', 'admin/ultron-studio/outreach/status');
  inf.check('GET outreach/status: maestra, sombra, constante cerrada', st.status === 200 && st.json?.data?.config?.enabled === true && st.json?.data?.config?.mode === 'shadow' && st.json?.data?.config?.sendsEnabledInCode === false,
    { status: st.status, config: st.json?.data?.config && { enabled: st.json.data.config.enabled, mode: st.json.data.config.mode, sends: st.json.data.config.sendsEnabledInCode } });
  await foto(A, '00-ana-panel-inicial');
  await foto(B, '00-beto-panel-inicial');
};

PASOS.plantillas = async () => {
  const clave = `aviso-asistido-${SELLO}`;
  inf.must('Ana abre Plantillas', await L.pagina(A, ctx, 'plantillas'));
  inf.must('Ana ve «Nueva versión de plantilla»', await L.clic(A, '[data-ca-plantillas-crear]'));
  await hay(A, '[data-ca-plantilla-form]', L.T(10000));
  await L.escribir(A, '[data-ca-plantilla-clave]', clave);
  await poner(A, `return document.querySelector('[data-ca-plantilla-canal]');`, 'assisted');
  await poner(A, `return document.querySelector('[data-ca-plantilla-finalidad]');`, 'commercial');
  await hay(A, '[data-ca-plantilla-medio]', L.T(5000));
  await poner(A, `return document.querySelector('[data-ca-plantilla-medio]');`, 'whatsapp');
  const guion = 'Hola, te saluda el equipo del gimnasio. Tu plan está por terminar y queremos ayudarte a seguir entrenando. ¿Te cuento las opciones para renovarlo?';
  const ta = await A.page.$('[data-ca-plantilla-script]');
  await ta.click();
  await ta.type(guion);
  await foto(A, 'p-01-ana-plantilla-formulario');
  inf.must('Ana crea la plantilla en borrador', await L.clic(A, '[data-ca-plantilla-enviar]'));
  await sinDialogo(A);
  let t = null;
  for (let i = 0; i < 40 && !t; i++) {
    const r = await L.api(ctx, 'a', 'GET', `admin/ultron-studio/strategies/templates?key=${clave}`);
    t = (r.json?.data?.data ?? []).find((x) => x.key === clave) ?? null;
    if (!t) await L.dormir(500);
  }
  inf.must('la plantilla existe en borrador', !!t && t.status !== 'APPROVED', t && { status: t.status, canal: t.channel, finalidad: t.purpose });
  estado.plantilla = t;
  await L.pagina(A, ctx, 'plantillas');
  await L.clic(A, `[data-ca-plantilla-abrir="${t.uuid}"]`);
  await hay(A, '[data-ca-plantilla-detalle]', L.T(10000));
  const anaAprueba = await L.leer(A, `return !!document.querySelector('[data-ca-plantilla-aprobar]');`);
  const tAna = await L.api(ctx, 'a', 'GET', `admin/ultron-studio/strategies/templates/${t.uuid}`);
  inf.check('Ana (autora) no puede aprobar su plantilla (ni botón ni servidor)', !anaAprueba && tAna.json?.data?.actions?.approve?.allowed === false, { boton: anaAprueba, servidor: tAna.json?.data?.actions?.approve });
  await foto(A, 'p-02-ana-plantilla-sin-aprobar');
  inf.must('Beto abre Plantillas', await L.pagina(B, ctx, 'plantillas'));
  inf.must('Beto abre la plantilla de Ana', await L.clic(B, `[data-ca-plantilla-abrir="${t.uuid}"]`));
  inf.must('Beto ve «Aprobar»', await L.clic(B, '[data-ca-plantilla-aprobar]', L.T(10000)));
  await hay(B, '[data-ca-plantilla-aprobar-confirmar]', L.T(10000));
  await foto(B, 'p-03-beto-aprobar-plantilla');
  inf.must('Beto confirma la aprobación', await L.clic(B, '[data-ca-plantilla-aprobar-confirmar]'));
  await sinDialogo(B);
  let ap = null;
  for (let i = 0; i < 40; i++) {
    const r = await L.api(ctx, 'b', 'GET', `admin/ultron-studio/strategies/templates/${t.uuid}`);
    ap = r.json?.data;
    if (ap?.status === 'APPROVED') break;
    await L.dormir(500);
  }
  inf.must('la plantilla queda APROBADA por Beto (cuatro ojos por persona)', ap?.status === 'APPROVED', { status: ap?.status, revisor: ap?.review?.reviewer?.name });
  estado.plantilla = ap;
  await L.pagina(B, ctx, 'plantillas');
  await foto(B, 'p-04-beto-plantilla-aprobada');
};

const SIM_SOCIOS = [
  { ref: 'p-autorizada', whatsapp: 'granted', canario: true, hechos: [['membership.active', 'true'], ['membership.frozen', 'false'], ['membership.days_to_expiry', 7]] },
  { ref: 'p-negada', whatsapp: 'denied', canario: true, hechos: [['membership.active', 'true'], ['membership.frozen', 'false'], ['membership.days_to_expiry', 7]] },
  { ref: 'p-sin-eventos', whatsapp: 'granted', canario: true, hechos: [['membership.active', 'true'], ['membership.frozen', 'false'], ['membership.days_to_expiry', 7]] },
];

PASOS.e1 = async () => {
  await cicloEstrategia({
    n: 'e1', clave: 'renovacion-7d', nombre: 'Renovación a 7 días (E2E)', goal: 'renew', prioridad: 60,
    evento: 'membership.expiring_window', params: { daysBefore: 7 },
    audiencia: [['membership.active', 'eq', 'true'], ['membership.frozen', 'eq', 'false']],
    exito: [['membership.days_to_expiry', 'gt', 30]],
    pasos: [
      { id: 'aviso', on: { answered: 'end_success', no_answer: 'wait', no_response: 'end_exit' }, espera: { n: 2, unit: 'days' } },
      { id: 'seguimiento', retraso: { n: 2, unit: 'days' }, on: { no_answer: 'end_exit', answered: 'end_success' } },
    ],
    simulacion: SIM_SOCIOS,
    // Nadie lleva eventos: la autorizada y la «sin eventos» se planifican; la negada cae en N1 (H9-E2E-2).
    decisionesSimuladas: { 'p-autorizada': 'plan', 'p-negada': 'deny', 'p-sin-eventos': 'plan' },
    sinEventos: true,
  });
};

PASOS.e2 = async () => {
  await cicloEstrategia({
    n: 'e2', clave: 'invitacion-clase', nombre: 'Invitación a clase con cupo (E2E)', goal: 'increase_adherence', prioridad: 40,
    evento: 'class.upcoming_with_capacity', params: { withinDays: 7 },
    audiencia: [['membership.active', 'eq', 'true'], ['class.reserved_next_7d', 'eq', 'false']],
    exito: [['class.reserved_next_7d', 'eq', 'true']],
    pasos: [{ id: 'invitacion', on: { answered: 'end_success', no_answer: 'end_exit' } }],
    simulacion: [
      { ref: 'p-sin-reserva', whatsapp: 'granted', canario: true, hechos: [['membership.active', 'true'], ['class.reserved_next_7d', 'false']] },
      { ref: 'p-con-reserva', whatsapp: 'granted', canario: true, hechos: [['membership.active', 'true'], ['class.reserved_next_7d', 'true']] },
    ],
    // Sin eventos (H9-E2E-2): la que no reservó se planifica; la que ya reservó queda fuera de la audiencia.
    decisionesSimuladas: { 'p-sin-reserva': 'plan', 'p-con-reserva': 'exclude' },
  });
};

PASOS.e3 = async () => {
  await cicloEstrategia({
    n: 'e3', clave: 'seguimiento-prospectos', nombre: 'Seguimiento de prospectos autorizados (E2E)', goal: 'close_plan', prioridad: 50,
    evento: 'lead.followup_due', params: {},
    audiencia: [['subject.is_lead', 'eq', 'true'], ['lead.followup_due', 'eq', 'true']],
    exito: [['lead.commercial_phase', 'eq', 'WON']],
    pasos: [{ id: 'seguimiento', on: { answered: 'end_success', no_answer: 'end_exit' } }],
    simulacion: [
      { ref: 'l-autorizado', whatsapp: 'granted', canario: true, hechos: [['subject.is_lead', 'true'], ['lead.followup_due', 'true'], ['lead.commercial_phase', 'FOLLOW_UP']] },
      { ref: 'l-sin-permiso', whatsapp: 'missing', canario: true, hechos: [['subject.is_lead', 'true'], ['lead.followup_due', 'true'], ['lead.commercial_phase', 'FOLLOW_UP']] },
    ],
    // Sin eventos (H9-E2E-2): el autorizado se planifica; el que no tiene autorización se niega (N1).
    decisionesSimuladas: { 'l-autorizado': 'plan', 'l-sin-permiso': 'deny' },
  });
};

/** Lo que dejaron el barrido y el despacho en las tablas del H9 (sin datos personales: claves de sujeto). */
function filasEjecucion() {
  const m = ctx.manifiesto();
  const quien = Object.fromEntries(Object.entries(m.people).map(([id, p]) => [p.subjectKey, id]));
  const ins = L.sql(ctx, `select e.uuid, s.key as estrategia, e.subject_key, e.status, e.mode, e.part from outreach_enrollments e join ultron_studio_strategies s on s.id = e.strategy_id order by e.id`);
  const dec = L.sql(ctx, `select d.uuid, s.key as estrategia, d.subject_key, d.kind, d.level, d.reasons, d.trace_hash from outreach_decisions d left join ultron_studio_strategies s on s.id = d.strategy_id order by d.id`);
  const jobs = L.sql(ctx, `select j.uuid, s.key as estrategia, j.subject_key, j.channel, j.status, j.mode, j.not_before from outreach_jobs j join ultron_studio_strategies s on s.id = j.strategy_id order by j.id`);
  const runs = L.sql(ctx, `select uuid, kind, status, counts from outreach_runs order by id`);
  const con = (filas) => filas.map((f) => ({ ...f, persona: quien[f.subject_key] ?? null }));
  return { inscripciones: con(ins), decisiones: con(dec), trabajos: con(jobs), pasadas: runs };
}

PASOS.consola = async () => {
  // El panel de Ana abierto ANTES de la pasada: el canal en vivo tiene que traer `outreach.runs` sin recargar. Va antes
  // del despacho con el reloj desplazado, que deja `updated_at` en el futuro (README, «Costuras»).
  inf.must('Ana abre el panel antes de la pasada', await L.pagina(A, ctx, 'panel'));
  await L.esperar(A, `return document.querySelector('[data-ca-live]')?.getAttribute('data-ca-live') === 'live';`, L.T(30000));
  const runsAntes = A.sse.temas['outreach.runs'] || 0;
  const sweep = motor(['outreach:sweep']);
  fs.writeFileSync(path.join(ctx.run, 'logs', 'consola-sweep.out'), `reloj del motor: ${sweep.reloj}\n${sweep.out}\n${sweep.err || ''}`);
  inf.must('outreach:sweep por consola sale con 0', sweep.code === 0, (sweep.err || sweep.out).slice(0, 400));
  let runsDespues = runsAntes;
  for (let i = 0; i < 90 && runsDespues <= runsAntes; i++) { await L.dormir(500); runsDespues = A.sse.temas['outreach.runs'] || 0; }
  inf.check('el canal en vivo entrega al panel abierto los eventos `outreach.runs` de la pasada (sin recargar)', runsDespues > runsAntes, { antes: runsAntes, despues: runsDespues, temas: A.sse.temas });
  await L.esperar(A, `return [...document.querySelectorAll('[data-ca-pasada="sweep"]')].some((t) => /complet/i.test(t.innerText));`, L.T(30000));
  await foto(A, 'c-00-ana-panel-en-vivo-tras-la-pasada');
  const tras = filasEjecucion();
  const porPersona = (id, est) => tras.inscripciones.filter((e) => e.persona === id && (!est || e.estrategia.startsWith(est)));
  const e1 = estado.estrategias.e1?.clave ?? 'renovacion-7d';
  const e2 = estado.estrategias.e2?.clave ?? 'invitacion-clase';
  const e3 = estado.estrategias.e3?.clave ?? 'seguimiento-prospectos';
  inf.check('S1 (vence en 7, autorizada, canario) queda inscrita en vencimientos', porPersona('S1', e1).length === 1, porPersona('S1', e1));
  inf.check('S3 (vence en 8) NO entra en vencimientos (ventana de 7)', porPersona('S3', e1).length === 0);
  inf.check('L1 (seguimiento vencido, autorizado) queda inscrito en prospectos', porPersona('L1', e3).length === 1, porPersona('L1', e3));
  inf.check('S6 (ya reservó la clase) NO entra en la invitación a clase', porPersona('S6', e2).length === 0);
  const niega = (id) => tras.decisiones.filter((d) => d.persona === id && d.kind === 'deny');
  inf.check('S2 (autorización denegada): la escalera niega en N1 con `consent_denied`', niega('S2').some((d) => d.level === 'N1' && JSON.stringify(d.reasons).includes('consent_denied')), niega('S2').map((d) => ({ nivel: d.level, motivos: d.reasons })));
  inf.check('L2 (sin autorización expresa): N1 niega `no_consent_for_purpose_channel`', niega('L2').some((d) => d.level === 'N1' && JSON.stringify(d.reasons).includes('no_consent_for_purpose_channel')), niega('L2').map((d) => ({ nivel: d.level, motivos: d.reasons })));
  const planificados = tras.trabajos.filter((j) => j.status === 'planned');
  inf.check('el barrido planifica trabajos en sombra (uno por persona como mucho)', planificados.length >= 2 && new Set(planificados.map((j) => j.subject_key)).size === planificados.length,
    planificados.map((j) => ({ persona: j.persona, estrategia: j.estrategia, canal: j.channel, modo: j.mode })));
  // El despacho: en el último `not_before` + 1 s (el desfase determinista de 0–14 min por persona).
  const disp = despachar(planificados.map((j) => j.not_before));
  const objetivo = disp.reloj;
  fs.writeFileSync(path.join(ctx.run, 'logs', 'consola-dispatch.out'), `reloj del motor: ${objetivo}\n${disp.out}\n${disp.err || ''}`);
  inf.must('outreach:dispatch --inline por consola sale con 0', disp.code === 0, (disp.err || disp.out).slice(0, 400));
  const tras2 = filasEjecucion();
  const simulados = tras2.trabajos.filter((j) => j.status === 'simulated');
  inf.check('el despacho deja los trabajos SIMULADOS (sombra: canal `simulated`, nada sale)', simulados.length === planificados.length && simulados.every((j) => j.channel === 'simulated' && j.mode === 'shadow'),
    { simulados: simulados.map((j) => ({ persona: j.persona, estrategia: j.estrategia })), relojDelDespacho: objetivo });
  const res = L.sql(ctx, `select outcome, source, channel, detail_code, count(*) as n from outreach_outcomes group by 1, 2, 3, 4 order by 1`);
  inf.check('cada trabajo simulado tiene su resultado `not_attempted` (detalle `shadow_mode`)', res.some((r) => r.outcome === 'not_attempted' && Number(r.n) === simulados.length), res);
  inf.check('las pasadas `sweep` y `dispatch` constan como completadas', ['sweep', 'dispatch'].every((k) => tras2.pasadas.some((r) => r.kind === k && r.status === 'completed')), tras2.pasadas.map((r) => ({ tipo: r.kind, estado: r.status, recuentos: r.counts })));
  fs.writeFileSync(path.join(ctx.filas, 'tras-consola.json'), JSON.stringify(tras2, null, 1));
  estado.consola = tras2;
};

PASOS.panel = async () => {
  for (const s of [A, B]) await L.pagina(s, ctx, 'panel');
  await L.esperar(A, `return document.querySelectorAll('[data-ca-pasada]').length > 0 && !!document.querySelector('[data-ca-inscripcion]');`, L.T(40000));
  const pasadas = await L.leer(A, `${L.NORMALIZA} return [...document.querySelectorAll('[data-ca-pasada]')].map((t) => [t.getAttribute('data-ca-pasada'), n(t.innerText)]);`);
  inf.check('el panel enseña la última pasada de barrido y de despacho', pasadas.some(([k, t]) => k === 'sweep' && /complet/i.test(t)) && pasadas.some(([k, t]) => k === 'dispatch' && /complet/i.test(t)), pasadas);
  const filas = await L.leer(A, `return [...document.querySelectorAll('[data-ca-inscripcion]')].map((t) => t.getAttribute('data-ca-inscripcion'));`);
  const esperadas = estado.consola.inscripciones.map((e) => e.uuid);
  inf.check('la tabla de inscripciones lista las del barrido', esperadas.length > 0 && esperadas.every((u) => filas.includes(u)), { enDom: filas.length, enBase: esperadas.length });
  const trabajos = await L.leer(A, `${L.NORMALIZA} return [...document.querySelectorAll('[data-ca-trabajo]')].map((t) => n(t.innerText));`);
  inf.check('la tabla de trabajos enseña los simulados', trabajos.length >= 1 && trabajos.every((t) => /simulad/i.test(t)), trabajos.slice(0, 5));
  await foto(A, 'c-01-ana-panel-tras-pasadas');
  // Una inscripción: sus decisiones y la traza en frases (GET decisions/{uuid}), y el traceHash de la base.
  const ins = estado.consola.inscripciones.find((e) => e.persona === 'S1') ?? estado.consola.inscripciones[0];
  inf.must('Ana abre la inscripción de S1', await L.clic(A, `[data-ca-inscripcion-abrir="${ins.uuid}"]`));
  inf.must('el detalle de la inscripción se abre', await hay(A, '[data-ca-inscripcion-detalle]', L.T(15000)));
  const dec = estado.consola.decisiones.find((d) => d.subject_key === ins.subject_key && d.kind === 'plan');
  if (dec) {
    await L.clic(A, `[data-ca-decision-frases-boton="${dec.uuid}"]`);
    const frases = await L.esperar(A, `${L.NORMALIZA} const e = document.querySelector('[data-ca-decision="${dec.uuid}"] [data-ca-decision-frases]') || document.querySelector('[data-ca-decision-frases]'); const t = e ? n(e.innerText) : ''; return t.length > 20 ? t : null;`, L.T(15000));
    const api = await L.api(ctx, 'a', 'GET', `admin/ultron-studio/outreach/decisions/${dec.uuid}`);
    inf.check('la decisión se explica en frases y su traceHash coincide con la base', !!frases && api.json?.data?.traceHash === dec.trace_hash, { frases: (frases || '').slice(0, 300), traceHash: dec.trace_hash, api: api.json?.data?.traceHash });
    estado.traceHash = dec.trace_hash;
  } else {
    inf.check('hay una decisión `plan` de la inscripción', false, estado.consola.decisiones.filter((d) => d.subject_key === ins.subject_key));
  }
  await foto(A, 'c-02-ana-inscripcion-frases');
  await L.leer(A, `document.querySelector('${DIALOGO} button[aria-label*="errar"]')?.click(); return true;`);
};

PASOS.parada = async () => {
  // Beto tiene el panel abierto: la parada de Ana tiene que llegarle sola (tema `outreach.brakes`).
  await L.pagina(B, ctx, 'panel');
  await L.esperar(B, `return document.querySelector('[data-ca-live]')?.getAttribute('data-ca-live') === 'live' && document.querySelector('[data-ca-parada]')?.getAttribute('data-activa') === 'no';`, L.T(30000));
  const frenosAntes = B.sse.temas['outreach.brakes'] || 0;
  await L.pagina(A, ctx, 'panel');
  inf.must('Ana ve el botón PARADA GLOBAL', await L.clic(A, '[data-ca-parada-boton]'));
  await motivoYConfirmar(A, 'Parada de prueba del E2E del H9: verificar que se detiene todo y que la suelta otra persona.');
  await sinDialogo(A, L.T(20000));
  let abierta = null;
  for (let i = 0; i < 40; i++) {
    const r = await L.api(ctx, 'a', 'GET', 'admin/ultron-studio/outreach/brakes');
    abierta = (r.json?.data ?? []).find((b) => b.scope === 'global' && !b.released) ?? null;
    if (abierta) break;
    await L.dormir(500);
  }
  inf.must('la PARADA GLOBAL queda abierta, a nombre de Ana', !!abierta && abierta.openedBy?.type === 'person', abierta && { alcance: abierta.scope, abierta: abierta.openedBy, motivo: abierta.reason });
  await L.esperar(A, `return document.querySelector('[data-ca-parada]')?.getAttribute('data-activa') === 'si';`, L.T(20000));
  await foto(A, 'd-01-ana-parada-global-activa');
  const enVivo = await L.esperar(B, `return document.querySelector('[data-ca-parada]')?.getAttribute('data-activa') === 'si';`, L.T(45000));
  inf.check('el panel de Beto, abierto, ve la PARADA GLOBAL sin recargar (canal en vivo `outreach.brakes`)', !!enVivo && (B.sse.temas['outreach.brakes'] || 0) > frenosAntes, { activaEnBeto: !!enVivo, eventos: (B.sse.temas['outreach.brakes'] || 0) - frenosAntes });
  await foto(B, 'd-01b-beto-ve-la-parada-en-vivo');
  // Con la parada echada, el despacho no reclama nada.
  const disp = motor(['outreach:dispatch', '--inline', '--json']);
  fs.writeFileSync(path.join(ctx.run, 'logs', 'consola-dispatch-con-parada.out'), `reloj del motor: ${disp.reloj}\n${disp.out}\n${disp.err || ''}`);
  inf.check('con la PARADA GLOBAL el despacho por consola no reclama nada', disp.code === 0 && /"claimed"\s*:\s*0|"stopped"|global|parada/i.test(disp.out), disp.out.slice(0, 300));
  // REGRESIÓN H9-E2E-3 (informe §B.5; corregido en L9-84 con la opción «el botón explica»): el panel ofrecía «Soltar la
  // parada» a quien la echó sin decirle que no puede. Ahora, junto al botón, dice quién la echó y que la suelta otra
  // persona (cuatro ojos). El botón sigue (el DTO del freno no trae `actions.release`; mejora pendiente en L9-84).
  const anaVeBoton = await L.leer(A, `return !!document.querySelector('[data-ca-parada-soltar]');`);
  const nota = async (s) => L.texto(s, '[data-ca-parada-cuatro-ojos]');
  const explica = (t) => !!t && t.includes(`La echó ${abierta.openedBy?.name}.`) && /no puede soltarla/.test(t) && /la suelta otra persona/.test(t) && /cuatro ojos/.test(t);
  const notaAna = await nota(A);
  inf.check('H9-E2E-3: junto a «Soltar la parada», el panel le dice a quien la echó que la echó ella y que la suelta OTRA persona (cuatro ojos)', anaVeBoton && explica(notaAna), { boton: anaVeBoton, explicacion: notaAna, abrio: abierta.openedBy?.name, hallazgo: 'H9-E2E-3' });
  await foto(A, 'd-01a-ana-ve-quien-suelta-la-parada');
  if (anaVeBoton) {
    await L.clic(A, '[data-ca-parada-soltar]');
    await motivoYConfirmar(A, 'Intento de soltar mi propia parada desde el CRM (debe rechazarse).');
    const msg = await L.esperar(A, `${L.NORMALIZA} const e = document.querySelector('${DIALOGO} .rd-server, ${DIALOGO} [role=alert]'); return e ? n(e.innerText) : null;`, L.T(20000));
    await foto(A, 'd-01c-ana-intenta-soltar-su-parada');
    const sigue = L.sql(ctx, `select released_at from outreach_brakes where uuid = '${abierta.uuid}'`)[0];
    inf.check('el servidor rechaza que Ana suelte su propia parada y la parada sigue abierta', !!msg && sigue && sigue.released_at === null, { mensaje: msg });
    await L.leer(A, `[...document.querySelectorAll('${DIALOGO} button')].find((b) => /Cancelar/.test(b.textContent))?.click(); return true;`);
    await sinDialogo(A, L.T(10000));
  }
  const intento = await L.api(ctx, 'a', 'POST', `admin/ultron-studio/outreach/brakes/${abierta.uuid}/release`, { note: 'Intento de soltar mi propia parada', clientRequestId: L.uuid() });
  inf.check('la API rechaza la suelta por la misma persona (403 four_eyes_required)', intento.status === 403 && intento.json?.code === 'four_eyes_required', { servidor: intento.status, codigo: intento.json?.code });
  // Beto la suelta desde el panel con nota. Antes: la explicación nombra a quien la ECHÓ (Ana), no a quien mira.
  await L.pagina(B, ctx, 'panel');
  await hay(B, '[data-ca-parada-cuatro-ojos]', L.T(20000));
  const notaBeto = await nota(B);
  inf.check('H9-E2E-3: a otra persona (Beto) la explicación le nombra a quien la echó (Ana), no a quien mira', explica(notaBeto) && !notaBeto.includes(B.usuario.name), { explicacion: notaBeto, mira: B.usuario.name, hallazgo: 'H9-E2E-3' });
  inf.must('Beto ve «Soltar la parada»', await L.clic(B, '[data-ca-parada-soltar]', L.T(20000)));
  await foto(B, 'd-02-beto-soltar-parada-dialogo');
  await motivoYConfirmar(B, 'Suelta tras la verificación del E2E: la parada se comportó como se esperaba.');
  await sinDialogo(B, L.T(20000));
  let suelta = null;
  for (let i = 0; i < 40; i++) {
    const r = await L.api(ctx, 'b', 'GET', 'admin/ultron-studio/outreach/brakes');
    suelta = (r.json?.data ?? []).find((b) => b.uuid === abierta.uuid) ?? null;
    if (suelta?.released) break;
    await L.dormir(500);
  }
  inf.must('Beto suelta la PARADA GLOBAL con nota (otra persona)', !!suelta?.released && !!suelta?.releaseNote, suelta && { suelta: suelta.released, por: suelta.releasedBy, nota: !!suelta.releaseNote });
  await L.esperar(B, `return document.querySelector('[data-ca-parada]')?.getAttribute('data-activa') === 'no';`, L.T(20000));
  await foto(B, 'd-03-beto-parada-suelta');
};

PASOS.pausa = async () => {
  const e3 = estado.estrategias.e3;
  if (!e3) throw new L.Requisito('falta la estrategia de prospectos (paso e3)');
  inf.must('Beto abre el gobierno de la campaña de prospectos', await L.pagina(B, ctx, `gobierno&estrategia=${e3.uuid}`));
  inf.must('Beto ve «Pausar»', await L.clic(B, '[data-ca-pausar]', L.T(15000)));
  await motivoYConfirmar(B, 'Pausa de prueba del E2E del H9: la campaña de prospectos deja de ejecutarse.');
  await sinDialogo(B, L.T(20000));
  let est = null;
  for (let i = 0; i < 40; i++) {
    const r = await L.api(ctx, 'b', 'GET', `admin/ultron-studio/strategies/${e3.uuid}/publications`);
    est = r.json?.meta?.state ?? null;
    if (est?.status === 'paused') break;
    await L.dormir(500);
  }
  const ent = (await L.api(ctx, 'b', 'GET', `admin/ultron-studio/strategies/${e3.uuid}/publications`)).json?.data?.data ?? [];
  inf.must('la cadena registra la pausa VERIFICADA y la estrategia queda en pausa', est?.status === 'paused' && ent.some((e) => e.action === 'pause' && (e.verified === true || e.verified === 'ok')), { estado: est, entradas: ent.map((e) => ({ seq: e.seq, action: e.action, verified: e.verified })) });
  await foto(B, 'e-01-beto-campana-pausada');
  // `--force`: la pasada anterior es de hace menos que la cadencia del disparador (15–60 min) y se omitiría.
  const sw = motor(['outreach:sweep', '--force']);
  fs.writeFileSync(path.join(ctx.run, 'logs', 'consola-sweep-tras-pausa.out'), `reloj del motor: ${sw.reloj}\n${sw.out}\n${sw.err || ''}`);
  const tras = filasEjecucion();
  const nuevos = tras.trabajos.filter((j) => j.estrategia === e3.clave && j.status === 'planned');
  inf.check('tras la pausa, la pasada no planifica nada de la campaña pausada', sw.code === 0 && nuevos.length === 0, { codigo: sw.code, planificados: nuevos.length });
  fs.writeFileSync(path.join(ctx.filas, 'tras-pausa.json'), JSON.stringify(tras, null, 1));
};

PASOS.asistido = async () => {
  // DESVIACIÓN DOCUMENTADA (README): en sombra el despacho usa siempre el canal simulado y la bandeja no recibe nada
  // (OutreachDispatcher, docblock). La bandeja se ejerce con la COPIA en `live` y el asistido encendido (cupo 20). La
  // constante de envío sigue cerrada; WhatsApp y voz siguen apagados y son puertas vacías.
  const ok = await L.cambiarModo(ctx, { modo: 'live', asistido: true, cupo: 20 });
  inf.must('la API vuelve en modo live con el asistido encendido (solo la copia local)', ok);
  const vall = L.apiConValla(ctx);
  inf.check('tras el reinicio, todos los procesos de la API llevan la valla', vall.procesos > 0 && vall.procesos === vall.conValla, vall);
  const pre = L.ayudante(ctx, ['preflight']);
  inf.must('preflight: modo live, asistido encendido y constante de envío cerrada', pre.outreach?.mode === 'live' && pre.outreach?.assisted === true && pre.sendsEnabledInCode === false, { modo: pre.outreach?.mode, asistido: pre.outreach?.assisted, cupo: pre.outreach?.assistedCap, constante: pre.sendsEnabledInCode });
  const siembra = L.ayudante(ctx, ['sembrar', 'asistido', ctx.manifest, new Date(relojMotor).toISOString()]);
  inf.check('siembra de la fase asistida (una socia nueva que vence en 7 días)', siembra.ok === true, siembra);
  inf.prohib = L.prohibidos(ctx); // el manifiesto creció: el barrido incluye a la persona nueva
  const sw = motor(['outreach:sweep', '--force']);
  fs.writeFileSync(path.join(ctx.run, 'logs', 'consola-sweep-asistido.out'), `reloj del motor: ${sw.reloj}\n${sw.out}\n${sw.err || ''}`);
  inf.must('outreach:sweep (live) sale con 0', sw.code === 0, (sw.err || sw.out).slice(0, 300));
  const t1 = filasEjecucion();
  const plan = t1.trabajos.filter((j) => j.persona === 'S4' && j.status === 'planned');
  inf.must('S4 queda planificada en live por el canal asistido', plan.length === 1 && plan[0].mode === 'live' && String(plan[0].channel).startsWith('assisted'), t1.trabajos.filter((j) => j.persona === 'S4'));
  const disp = despachar([plan[0].not_before]);
  fs.writeFileSync(path.join(ctx.run, 'logs', 'consola-dispatch-asistido.out'), `reloj del motor: ${disp.reloj}\n${disp.out}\n${disp.err || ''}`);
  inf.must('outreach:dispatch --inline (live) sale con 0', disp.code === 0, (disp.err || disp.out).slice(0, 300));
  const job = L.sql(ctx, `select uuid, status, channel from outreach_jobs where uuid = '${plan[0].uuid}'`)[0];
  inf.must('el trabajo asistido queda abierto en la bandeja (`started`)', job?.status === 'started', job);
  // Beto reclama y registra el resultado desde la bandeja.
  inf.must('Beto abre la bandeja asistida', await L.pagina(B, ctx, 'bandeja'));
  inf.must('el trabajo aparece en la bandeja', await hay(B, `[data-ca-asistido="${job.uuid}"]`, L.T(20000)));
  const antesDeReclamar = await L.leer(B, `${L.NORMALIZA} const e = document.querySelector('[data-ca-asistido="${job.uuid}"]'); return { guion: !!e.querySelector('[data-ca-asistido-guion]'), ficha: !!e.querySelector('[data-ca-asistido-ficha]'), texto: n(e.innerText).slice(0, 400) };`);
  inf.check('antes de reclamar, la bandeja no enseña la ficha ni el guion (m-2)', !antesDeReclamar.guion && !antesDeReclamar.ficha, antesDeReclamar);
  await foto(B, 'f-01-beto-bandeja');
  inf.must('Beto reclama el trabajo', await L.clic(B, `[data-ca-reclamar="${job.uuid}"]`));
  inf.must('tras reclamar, aparece «Registrar el resultado»', await hay(B, `[data-ca-resultado="${job.uuid}"]`, L.T(20000)));
  const tras = await L.leer(B, `const e = document.querySelector('[data-ca-asistido="${job.uuid}"]'); return { guion: !!e.querySelector('[data-ca-asistido-guion]'), ficha: !!e.querySelector('[data-ca-asistido-ficha]') };`);
  inf.check('quien reclamó ve el guion y el enlace a la ficha', tras.guion && tras.ficha, tras);
  // Ana no ve el guion ni la ficha del trabajo que reclamó Beto.
  await L.pagina(A, ctx, 'bandeja');
  await hay(A, `[data-ca-asistido="${job.uuid}"]`, L.T(15000));
  const anaVe = await L.leer(A, `const e = document.querySelector('[data-ca-asistido="${job.uuid}"]'); return e ? { guion: !!e.querySelector('[data-ca-asistido-guion]'), ficha: !!e.querySelector('[data-ca-asistido-ficha]'), reclamar: !!e.querySelector('[data-ca-reclamar]'), resultado: !!e.querySelector('[data-ca-resultado]') } : null;`);
  inf.check('Ana no ve el guion, la ficha ni el botón de resultado del trabajo de Beto', !anaVe || (!anaVe.guion && !anaVe.ficha && !anaVe.resultado), anaVe);
  await foto(B, 'f-02-beto-reclamado');
  await L.clic(B, `[data-ca-resultado="${job.uuid}"]`);
  inf.must('se abre el formulario del resultado', await hay(B, '[data-ca-resultado-form]', L.T(10000)));
  await poner(B, `return document.querySelector('[data-ca-resultado-select]');`, 'answered');
  await foto(B, 'f-03-beto-resultado');
  inf.must('Beto registra el resultado', await L.clic(B, '[data-ca-resultado-enviar]'));
  await sinDialogo(B, L.T(20000));
  let fin = null;
  for (let i = 0; i < 40; i++) {
    fin = L.sql(ctx, `select j.status, o.outcome, o.source from outreach_jobs j left join outreach_outcomes o on o.job_id = j.id and o.source = 'staff' where j.uuid = '${job.uuid}'`)[0];
    if (fin?.outcome) break;
    await L.dormir(500);
  }
  // `started` es estado FINAL del trabajo asistido (JOB_FINAL_STATUSES): lo que cierra la tarea es el resultado del personal.
  const cola = await L.api(ctx, 'b', 'GET', 'admin/ultron-studio/outreach/assisted/queue');
  const sigue = (cola.json?.data ?? []).some((x) => x.uuid === job.uuid);
  inf.check('el resultado del personal queda registrado (`answered`, fuente `staff`) y la tarea sale de la bandeja', fin?.outcome === 'answered' && fin?.source === 'staff' && cola.status === 200 && !sigue, { ...fin, enLaBandeja: sigue });
  const ins = L.sql(ctx, `select e.status, e.last_outcome from outreach_enrollments e join outreach_jobs j on j.enrollment_id = e.id where j.uuid = '${job.uuid}'`)[0];
  inf.check('la inscripción sigue el mapa de resultados (answered → end_success)', ins?.status === 'completed', ins);
  await L.pagina(B, ctx, 'bandeja');
  await foto(B, 'f-04-beto-bandeja-tras-resultado');
  fs.writeFileSync(path.join(ctx.filas, 'tras-asistido.json'), JSON.stringify(filasEjecucion(), null, 1));
};

/**
 * HALLAZGO H9-E2E-4 (nuevo en la certificación de la ola; menor, FE9): abrir una estrategia no la deja en la URL.
 * `abrirEstrategia()` (`commercial-automation-page.ts:260-263`) navega con `?estrategia=<uuid>` y, sin esperar, `elegir()`
 * navega otra vez con `?pestana=constructor` (las dos con `queryParamsHandling: 'merge'`): la segunda sustituye a la
 * primera y su mezcla parte de la URL de antes. El constructor abre bien (la elección va por `session.select`), pero la
 * URL no lleva la estrategia (F5 o un enlace la pierden) y, si había OTRA elegida, sigue apuntando a la anterior: F5
 * cambia en silencio la estrategia que se estaba editando. Va al final para no tocar el flujo de e1–e3.
 */
PASOS.url = async () => {
  const e1 = estado.estrategias.e1;
  if (!e1) throw new L.Requisito('falta la estrategia de vencimientos (paso e1)');
  // 1. «Abrir» desde la lista: la URL tiene que llevar la estrategia abierta.
  if (!(await L.pagina(A, ctx, 'estrategias'))) throw new L.Requisito('la pestaña Estrategias no quedó lista');
  inf.must('Ana pulsa «Abrir» en la fila de vencimientos', await L.clic(A, `[data-ca-abrir="${e1.uuid}"]`, L.T(20000)));
  inf.must('el constructor abre vencimientos', !!(await L.esperar(A, `const t = document.querySelector(${sel(TITULO_CONSTRUCTOR)}); return !!t && t.textContent.includes(${sel(e1.clave)});`, L.T(30000))));
  const u1 = await urlDe(A, e1.uuid, L.T(10000));
  inf.check('H9-E2E-4: abierta con «Abrir» desde la lista, la URL lleva la estrategia (?estrategia=<uuid>) para F5 y enlaces', u1.estrategia === e1.uuid, { url: u1, esperada: e1.uuid, hallazgo: 'H9-E2E-4' });
  await foto(A, 'u-01-ana-abierta-desde-la-lista');
  // 2. Con OTRA estrategia elegida en la URL, Ana crea una nueva (queda en borrador) y recarga: ¿sigue en la nueva?
  await A.page.goto(`${ctx.crmUrl}/marketing/automatizaciones?pestana=estrategias&estrategia=${e1.uuid}`, { waitUntil: 'domcontentloaded', timeout: L.T(120000) });
  if (!(await L.esperar(A, `const p = document.querySelector('[data-ca-pagina]'); return !!p && p.getAttribute('data-ca-fase') === 'ready';`, L.T(60000)))) throw new L.Requisito('la página no quedó lista');
  const clave = `url-f5-${SELLO}`;
  inf.must('Ana ve «Nueva estrategia» (con vencimientos elegida en la URL)', await L.clic(A, '[data-ca-estrategias-crear]'));
  await L.escribir(A, '[data-ca-crear-clave]', clave);
  await L.escribir(A, '[data-ca-crear-nombre]', 'Estrategia de la prueba de la URL (E2E)');
  await poner(A, `return document.querySelector('[data-ca-crear-finalidad]');`, 'commercial');
  inf.must('Ana crea la estrategia nueva', await L.clic(A, '[data-ca-crear-enviar]'));
  inf.must('el constructor abre la nueva', !!(await L.esperar(A, `const t = document.querySelector(${sel(TITULO_CONSTRUCTOR)}); return !!t && t.textContent.includes(${sel(clave)});`, L.T(30000))));
  const nueva = await creadaPorClave(clave);
  inf.must('la estrategia nueva existe en el servidor', !!nueva, clave);
  const antes = await urlDe(A, nueva.uuid, L.T(10000));
  await foto(A, 'u-02-ana-nueva-antes-de-f5');
  await A.page.reload({ waitUntil: 'domcontentloaded', timeout: L.T(120000) });
  await L.esperar(A, `const p = document.querySelector('[data-ca-pagina]'); return !!p && p.getAttribute('data-ca-fase') === 'ready';`, L.T(60000));
  const tras = await L.esperar(A, `const t = document.querySelector(${sel(TITULO_CONSTRUCTOR)}); return t ? t.textContent : (document.querySelector('[data-ca-pestana-sin-estrategia]') ? '(sin estrategia elegida)' : null);`, L.T(30000));
  await foto(A, 'u-03-ana-tras-f5');
  const enLaNueva = String(tras || '').includes(clave);
  inf.check('H9-E2E-4: con otra estrategia elegida, tras crear una nueva y recargar (F5) el constructor sigue en la nueva', antes.estrategia === nueva.uuid && enLaNueva,
    { urlAntesDeF5: antes, nueva: nueva.uuid, anterior: e1.uuid, laUrlApuntaA: antes.estrategia === nueva.uuid ? 'la nueva' : antes.estrategia === e1.uuid ? 'la ANTERIOR' : antes.estrategia, constructorTrasF5: String(tras || '').replace(/\s+/g, ' ').trim().slice(0, 160), hallazgo: 'H9-E2E-4' });
};

PASOS.barrido = async () => {
  for (const s of [A, B]) {
    for (const q of ['panel', 'estrategias', 'plantillas', 'bandeja', 'vinculos']) {
      await L.pagina(s, ctx, q);
      await L.dormir(800);
      const b = await L.barrerPagina(s, inf);
      inf.check(`${s.quien} · ${q}: ningún nombre ni teléfono sintético en el DOM, el almacenamiento, la URL ni la consola`, !b.dom.length && !b.almacenamiento.length && !b.url.length && !b.consola.length && !b.tokenEnDom, b);
    }
    inf.check(`${s.quien}: ningún nombre ni teléfono sintético en las respuestas de la API ni en los eventos SSE`, s.fugasRed.length === 0, { cuerposBarridos: s.cuerpos, eventosSSE: s.sse.eventos, fugas: s.fugasRed.slice(0, 5) });
    // Las fuentes de Google del index.html (anteriores al H9) se INTENTAN; el resolver de Chrome las mata
    // (`--host-resolver-rules`): se exige que TODAS mueran ahí, sin una sola respuesta.
    const ajenas = s.red.filter((l) => !L.ES_LOCAL(l.host));
    const fuera = [...new Set(ajenas.map((l) => l.host))];
    const alcanzadas = ajenas.filter((l) => l.status !== undefined || l.error !== 'net::ERR_NAME_NOT_RESOLVED');
    inf.check(`${s.quien}: 0 hosts fuera de 127.0.0.1 alcanzados (los intentos mueren en el resolver)`, alcanzadas.length === 0 && fuera.every((h) => /^fonts\.(googleapis|gstatic)\.com$/.test(h)),
      { peticiones: s.red.length, intentosFuera: ajenas.length, hostsIntentados: fuera, alcanzadas: alcanzadas.slice(0, 3) });
    const enVentana = s.api5xx.filter((e) => e.status === 502 && L.enReinicio(ctx, e.ts));
    const fuera5xx = s.api5xx.filter((e) => !enVentana.includes(e));
    inf.check(`${s.quien}: ninguna respuesta 5xx de la API (salvo 502 dentro del reinicio deliberado de la fase asistida)`, fuera5xx.length === 0, { fuera: fuera5xx.map((e) => e.txt).slice(0, 5), durante502DelReinicio: enVentana.length });
    inf.check(`${s.quien}: ningún error de página`, s.errores.length === 0, s.errores.slice(0, 5));
  }
};

// ── Orden ───────────────────────────────────────────────────────────────────────────────────────────────────────
(async () => {
  const lista = (process.env.H9_PASOS || ctx.steps || '').split(',').map((x) => x.trim()).filter(Boolean);
  const orden = ['humo', 'plantillas', 'e1', 'e2', 'e3', 'consola', 'panel', 'parada', 'pausa', 'asistido', 'url', 'barrido'].filter((p) => !lista.length || lista.includes(p) || p === 'humo' || p === 'barrido');
  const browser = await L.lanzar(ctx);
  A = await L.sesion(browser, ctx, inf, 'a');
  B = await L.sesion(browser, ctx, inf, 'b');
  for (const p of orden) {
    inf.en(p);
    try {
      await PASOS[p]();
    } catch (e) {
      inf.check(`el paso «${p}» terminó sin excepción`, false, String(e.message || e).slice(0, 500));
      for (const s of [A, B]) await foto(s, `zz-fallo-${p}-${s.quien}`);
      if (!(e instanceof L.Requisito)) console.error(e);
      if (['plantillas', 'e1'].includes(p)) {
        console.log('  (paso imprescindible: el guion se detiene; el barrido final sí corre)');
        inf.en('barrido');
        try { await PASOS.barrido(); } catch (e2) { inf.check('el barrido terminó sin excepción', false, String(e2.message || e2).slice(0, 300)); }
        break;
      }
    }
  }
  L.guardarRed(ctx, [A, B]);
  fs.writeFileSync(path.join(ctx.run, 'estado-guion.json'), JSON.stringify({ sello: SELLO, plantilla: estado.plantilla?.uuid, estrategias: estado.estrategias, versiones: estado.versiones, traceHash: estado.traceHash ?? null, sse: { a: A.sse, b: B.sse } }, null, 1));
  const ok = inf.fin();
  // Con la máquina cargada, cerrar Chrome puede no volver: como mucho 20 s, y después se mata el proceso.
  await Promise.race([browser.close().catch(() => {}), L.dormir(20000)]);
  try { browser.process()?.kill('SIGKILL'); } catch { /* ya cerrado */ }
  process.exit(ok ? 0 : 1);
})().catch((e) => {
  console.error('recorridos-h9: ERROR', e);
  process.exit(2);
});
