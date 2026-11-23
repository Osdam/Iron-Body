#!/usr/bin/env node
'use strict';
/*
 * T3 DEL APARTADO 5 (diseño `plans/informes-cierre/diseno-arq-lab.md` §3.4):
 * EL TRAMO DE `flujo-s2.json` EJECUTADO DE VERDAD, PARA COMPARARLO CON EL PORT
 * DEL LABORATORIO (`StudioLabEngineFlow`).
 *
 * Uso: node recorrer-s2-lab.cjs <spec.json>   →   JSON por la salida estándar.
 *
 * `spec`: { flow, decide, event, pins: { <nodo>: <json> }, mode: "lanza"|"undefined" }.
 * Desde «Elegir Prompts» (el selector REAL, con la oferta de `decide`) hasta
 * «Preparar Commit», nodo a nodo como n8n: los IF con su condición, los Code
 * con su `jsCode` (en `vm`, sin red ni ficheros), los nodos de OpenAI y de la
 * comprobación con la salida fijada en `pins`. Es el mismo recorrido que
 * `docs/ultron-studio/ops/motores/verificar-flujo-s2.cjs` (de ahí salen
 * `evaluar`, `ejecutarCodigo` y `evaluarIf`), empezando un nodo antes: el
 * estratega y Validar Estrategia también corren.
 *
 * Salida: { status: done|failed, order, sent: { nodo: { system, user } },
 * bodies: { nodo: cuerpo }, ran: { nodo: json }, commit, at, error }.
 * Un Code que lanza (Validar Estrategia, Preparar Commit) es `failed` con su
 * mensaje: en n8n la ejecución falla.
 */
const fs = require('fs');
const vm = require('vm');
const util = require('util');

const OPENAI = '@n8n/n8n-nodes-langchain.openAi';
const HTTP = 'n8n-nodes-base.httpRequest';
const IF = 'n8n-nodes-base.if';
const CODE = 'n8n-nodes-base.code';
const SELECTOR = 'Elegir Prompts';
const EVENTO = 'Normalizar Evento';
const DECIDE = 'Pedir Contexto al CRM';
const COMMIT = 'Preparar Commit';

const insp = v => util.inspect(v, { depth: 4, breakLength: Infinity });
const clon = v => JSON.parse(JSON.stringify(v));
const has = (o, k) => Object.prototype.hasOwnProperty.call(o, k);

function evaluar(expresion, json, $) {
  if (typeof expresion !== 'string' || !expresion.startsWith('={{') || !expresion.endsWith('}}')) throw new Error(`no es una expresión de n8n: ${insp(expresion)}`);
  return vm.runInContext(`(${expresion.slice(3, -2)})`, vm.createContext({ $json: json, $ }), { timeout: 5000 });
}

function ejecutarCodigo(codigo, entrada, $) {
  const $input = { first: () => ({ json: entrada }), all: () => [{ json: entrada }] };
  const r = vm.runInContext(`(function () {\n${codigo}\n})()`, vm.createContext({ $, $input, $execution: { id: '4242' } }), { timeout: 10000 });
  if (!Array.isArray(r) || !r[0] || !r[0].json || typeof r[0].json !== 'object') throw new Error('el Code no devuelve [{ json }]');
  return r[0].json;
}

function evaluarIf(n, json, $) {
  const c = n.parameters && n.parameters.conditions;
  if (!c || c.combinator !== 'and' || !Array.isArray(c.conditions) || c.conditions.length !== 1) throw new Error(`IF no previsto: «${n.name}»`);
  const k = c.conditions[0];
  const v = evaluar(k.leftValue, json, $);
  if (k.operator.type === 'boolean' && k.operator.operation === 'true') {
    if (typeof v !== 'boolean') throw new Error(`«${n.name}» no recibe un booleano: ${insp(v)}`);
    return v === true;
  }
  if (k.operator.type === 'string' && k.operator.operation === 'equals') {
    if (typeof v !== 'string' && c.options && c.options.typeValidation === 'strict') throw new Error(`«${n.name}» (estricto) no recibe un texto: ${insp(v)}`);
    return v === k.rightValue;
  }
  throw new Error(`operador no previsto en «${n.name}»: ${insp(k.operator)}`);
}

function recorrer(spec) {
  const flujo = JSON.parse(fs.readFileSync(spec.flow, 'utf8'));
  const porNombre = Object.fromEntries(flujo.nodes.map(x => [x.name, x]));
  const corridos = { [EVENTO]: clon(spec.event), [DECIDE]: clon(spec.decide) };
  const $ = nombre => ({ first: () => {
    if (has(corridos, nombre)) return { json: corridos[nombre] };
    if (spec.mode === 'lanza') throw new Error(`El nodo «${nombre}» no se ejecutó`);
    return undefined;
  } });
  const order = [];
  const sent = {};
  const bodies = {};
  const ran = {};
  corridos[SELECTOR] = ejecutarCodigo(porNombre[SELECTOR].parameters.jsCode, clon(spec.decide), $);
  const salida = (status, extra) => Object.assign({ status, order, sent, bodies, ran, commit: null, at: null, error: null }, extra || {});

  const cola = [{ nodo: 'Strategist', item: corridos[SELECTOR] }];
  while (cola.length) {
    const { nodo, item } = cola.shift();
    if (order.includes(nodo)) throw new Error(`«${nodo}» se ejecutaría dos veces`);
    if (order.length > 60) throw new Error('el recorrido no termina');
    order.push(nodo);
    const n = porNombre[nodo];
    if (!n) throw new Error(`una conexión lleva a «${nodo}», que no existe`);
    let salidas;
    try {
      if (nodo === COMMIT) {
        const commit = ejecutarCodigo(n.parameters.jsCode, clon(item), $);
        corridos[nodo] = commit;
        return salida('done', { commit });
      }
      if (n.type === OPENAI) {
        const v = n.parameters.responses.values;
        sent[nodo] = { system: evaluar(v[0].content, item, $), user: evaluar(v[1].content, item, $) };
        if (!has(spec.pins, nodo)) throw new Error(`llamada al modelo no prevista: «${nodo}»`);
        salidas = [clon(spec.pins[nodo])];
      } else if (n.type === HTTP) {
        bodies[nodo] = JSON.parse(evaluar(n.parameters.jsonBody, item, $));
        if (!has(spec.pins, nodo)) throw new Error(`llamada a ai/check no prevista: «${nodo}»`);
        salidas = [clon(spec.pins[nodo])];
      } else if (n.type === IF) {
        salidas = evaluarIf(n, item, $) ? [item, null] : [null, item];
      } else if (n.type === CODE) {
        salidas = [ejecutarCodigo(n.parameters.jsCode, clon(item), $)];
      } else throw new Error(`tipo no previsto en el recorrido: ${n.type} («${nodo}»)`);
    } catch (e) {
      if (n.type === CODE) return salida('failed', { at: nodo, error: String(e && e.message) });
      throw e;
    }
    corridos[nodo] = salidas.find(x => x !== null);
    if (n.type !== OPENAI && n.type !== HTTP) ran[nodo] = corridos[nodo];
    const con = (flujo.connections[nodo] && flujo.connections[nodo].main) || [];
    salidas.forEach((json, i) => { if (json !== null) for (const c of con[i] || []) cola.push({ nodo: c.node, item: json }); });
  }
  throw new Error(`el recorrido no llega a ${COMMIT}`);
}

try {
  const spec = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
  process.stdout.write(JSON.stringify(recorrer(spec)));
} catch (e) {
  process.stderr.write(String(e && e.stack || e));
  process.exitCode = 2;
}
