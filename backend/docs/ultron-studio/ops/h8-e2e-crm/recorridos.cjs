/**
 * Recorridos del CRM real («Voz y telefonía», `/marketing/ultron-studio/voz`) contra la pila del arnés del H8, con las
 * dos cuentas Super Admin (Ana autora, Beto revisor: cuatro ojos) y la Administradora (Carla: solo lo que su `can` deja).
 *
 *   node recorridos.cjs <crm-context.json>           (lo llama h8-e2e-crm.sh; H8_CRM_PASOS=a,b,… para elegir pasos)
 *
 * Precondiciones de hitos anteriores, por la API (no son de esta sección): los dos canarios de voz del H6 (Ana registra,
 * Beto autoriza y activa) y la versión del Studio del H5 (Ana la crea, Beto la aprueba). Todo lo demás, por la interfaz.
 * Lo que viene «de fuera» (entrantes, post-llamadas, el sello roto) lo emiten los simuladores por su control GET, como
 * en `H8StackE2ETestCase`; la saliente en `requested`/`dialing`, con las costuras documentadas del arnés (ayudante.php
 * `saliente` y `despachar`, y el worker de UN trabajo con `reloj.php`).
 */
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const L = require('./lib.cjs');

const PESTANAS = ['estado', 'perfil', 'publicacion', 'llamadas', 'costes', 'contactos', 'laboratorio'];
// El reloj del worker de UN trabajo (`reloj.php`): el PRÓXIMO día hábil (lunes a viernes, sin los festivos del fixture de
// TEST8-a) a las 10:00 de Bogotá, siempre en el futuro (se calcula al cargar el contexto). Era una fecha fija (martes
// 10-11-2026 10:00): desde el 10-11 a las 15:00Z quedó en el PASADO y el worker, con `now()` por detrás del
// `available_at` del trabajo (hora real), no lo veía: la saliente se quedaba en `requested` (B-T6, 11-11).
let EN_HORARIO = null;
function proximoHabilEnHorario(ctx) {
  const fx = JSON.parse(fs.readFileSync(path.join(ctx.h.backend, 'tests/fixtures/voice/holidays-co-2026-2028.json'), 'utf8'));
  const festivo = (ymd) => (fx.years?.[ymd.slice(0, 4)] || []).some((x) => x.date === ymd);
  const d = new Date(Date.now() - 5 * 3600 * 1000); // Bogotá (sin horario de verano)
  for (let i = 0; i < 30; i++) {
    d.setUTCDate(d.getUTCDate() + 1);
    const ymd = d.toISOString().slice(0, 10);
    const sem = d.getUTCDay();
    if (sem >= 1 && sem <= 5 && !festivo(ymd)) return `${ymd}T10:00:00-05:00`;
  }
  throw new Error('no hay un día hábil en los próximos 30 días del fixture');
}
const PERFIL = 'Voz del E2E del CRM';
const SIN_LEYENDO = (sel) => `return !!document.querySelector(${JSON.stringify(sel)});`;

(async () => {
  const ctx = L.cargar(process.argv[2]);
  const inf = new L.Informe(ctx, L.prohibidos(ctx));
  EN_HORARIO = proximoHabilEnHorario(ctx);
  const elegidos = (process.env.H8_CRM_PASOS || '').split(',').map((x) => x.trim()).filter(Boolean);
  const E = {};
  const S = {};
  const browser = await L.lanzar(ctx);
  const todas = [];
  const requiere = (k) => {
    if (E[k] === undefined) throw new L.Requisito(`falta ${k} (lo deja un paso anterior)`);
    return E[k];
  };
  const paso = async (nombre, fn) => {
    if (elegidos.length && !elegidos.includes(nombre)) return;
    inf.en(nombre);
    try {
      await fn();
    } catch (e) {
      if (!(e instanceof L.Requisito)) inf.check(`${nombre}: terminó sin excepción`, false, String(e && e.stack ? e.stack : e).split('\n').slice(0, 3).join(' | '));
      else console.log(`  (paso cortado: ${e.message})`);
    }
  };
  const sesionDe = async (q, opciones = {}) => {
    const s = await L.sesion(browser, ctx, inf, q, {
      // Los teléfonos solo viajan en el formulario que los pide (alta de un contacto) y solo en esa petición.
      excepcionesEnvio: (m, p) => (m === 'POST' && p === '/api/admin/voice/contacts' ? ['teléfono …0071', 'teléfono …0071 (sin +)', 'teléfono …0071 (nacional)'] : []),
      // La URL firmada viaja, por diseño, en la respuesta de abrir la sesión del laboratorio (y con ella el id del agente).
      // El id del agente viaja ENTERO, por diseño, en el SessionDto del laboratorio (LAB8 §2.4) y en la telefonía (el
      // agente sellado de cada llamada y el de la parte vigente: `GET admin/voice/status`, `calls`, `calls/{uuid}`); el
      // CRM lo enmascara al leer (D-F13). El Studio (`voice/publications`) ya lo manda enmascarado: observación O-3 del
      // informe §TEST8-c. Se admite solo en esas respuestas y se sigue exigiendo que no llegue nunca al DOM.
      excepcionesCuerpo: (m, p) => {
        const lab = ['agente de laboratorio (id completo)', 'agente de laboratorio 1 (id completo)'];
        if (m === 'POST' && p === '/api/admin/ultron-studio/voice-lab/sessions') return ['URL firmada (su firma)', ...lab];
        if (p.startsWith('/api/admin/ultron-studio/voice-lab/sessions')) return lab;
        // La fila de una llamada (`VoiceCallsQuery::row`) es la misma en la lista, el detalle y la respuesta de pedirla
        // (202 de la puerta o 200 de la repetición, alcanzables desde E4-6).
        if ((m === 'GET' && (p === '/api/admin/voice/status' || p.startsWith('/api/admin/voice/calls'))) || (m === 'POST' && p === '/api/admin/voice/calls')) return ['agente publicado (id completo)'];
        return [];
      },
      ...opciones,
    });
    todas.push(s);
    return s;
  };
  const statsEl = () => L.sim(ctx, 'elevenlabs', 'stats');
  const statsTw = () => L.sim(ctx, 'twilio', 'stats');
  const delta = (a, b) => ({ requests: (b.requests ?? 0) - (a.requests ?? 0) });
  const dialogo = `[...document.querySelectorAll('[role=dialog][aria-modal=true]')].pop()`;
  const hayDialogo = (sel) => `const d = ${dialogo}; return !!(d && ${sel ? `d.querySelector(${JSON.stringify(sel)})` : 'true'});`;
  const sinDialogo = `return document.querySelectorAll('[role=dialog][aria-modal=true]').length === 0;`;
  const atr = (s, sel, a) => L.leer(s, `const e = document.querySelector(${JSON.stringify(sel)}); return e ? e.getAttribute(${JSON.stringify(a)}) : null;`);
  const existe = (s, sel) => L.leer(s, `return !!document.querySelector(${JSON.stringify(sel)});`);
  const hashesEn = (s, sel) => L.leer(s, `const r = document.querySelector(${JSON.stringify(sel)}); if (!r) return []; return [...new Set([...r.querySelectorAll('[title]')].map((e) => e.getAttribute('title')).concat((r.textContent.match(/[0-9a-f]{64}/g) || [])).filter((t) => /^[0-9a-f]{64}$/.test(t)))];`);
  const anchos = async (s, nombre, w) => {
    await s.page.setViewport({ width: w, height: 900 });
    await L.dormir(500);
    const d = await L.desborde(s);
    inf.check(`${nombre} a ${w} px: sin desplazamiento horizontal`, d.scrollWidth <= d.ancho && d.fuera.length === 0, d);
    await L.captura(s, ctx, `${nombre}-${w}`);
    await s.page.setViewport({ width: 1440, height: 900 });
    await L.dormir(300);
  };
  const enviarDialogo = (s, sel) => L.clic(s, `[role=dialog][aria-modal=true] ${sel}`);
  // El horario legal de las salientes (D8-15: lunes a viernes 07–19, sábados 08–15, Bogotá, sin domingos ni festivos), con
  // los festivos del fixture de TEST8-a: un oráculo INDEPENDIENTE del código (el mismo que `calendarioLegal()` del backend).
  const calendarioLegal = (ahora) => {
    const b = new Date(ahora.getTime() - 5 * 3600 * 1000); // Colombia no cambia de hora
    const fx = JSON.parse(fs.readFileSync(path.join(ctx.h.backend, 'tests/fixtures/voice/holidays-co-2026-2028.json'), 'utf8'));
    const dia = b.toISOString().slice(0, 10);
    const festivo = (fx.years?.[dia.slice(0, 4)] || []).some((x) => x.date === dia);
    const sem = b.getUTCDay(); // 0 domingo … 6 sábado
    const seg = b.getUTCHours() * 3600 + b.getUTCMinutes() * 60 + b.getUTCSeconds();
    const dentro = (sem >= 1 && sem <= 5 && seg >= 7 * 3600 && seg < 19 * 3600) || (sem === 6 && seg >= 8 * 3600 && seg < 15 * 3600);
    const motivos = [...(dentro ? [] : ['outside_hours']), ...(festivo ? ['holiday'] : [])];
    return { fuera: motivos.length > 0, motivos };
  };

  try {
    // ── 0. Precondiciones de hitos anteriores (API) ──────────────────────────────────────────────────────────────
    await paso('preparacion', async () => {
      const canarios = [];
      for (const [i, tel] of ctx.h.canaries.entries()) {
        const r = await L.api(ctx, 'a', 'POST', 'admin/ultron-studio/integrations/canaries', { clientRequestId: L.uuid(), channel: 'voice', phone: tel, alias: `Canario de voz ${i + 1}`, consentAttested: true });
        inf.must(`H6 (API): Ana registra el canario de voz ${i + 1}`, r.status === 201, r.status);
        const u = r.json.data.number.uuid;
        const au = await L.api(ctx, 'b', 'POST', `admin/ultron-studio/integrations/canaries/${u}/authorize`);
        const ac = await L.api(ctx, 'b', 'POST', `admin/ultron-studio/integrations/canaries/${u}/activate`);
        inf.must(`H6 (API): Beto lo autoriza y lo activa`, au.status === 200 && ac.status === 200 && ac.json?.data?.number?.status === 'active', [au.status, ac.status]);
        canarios.push(u);
      }
      E.canarios = canarios;
      const d = await L.api(ctx, 'a', 'GET', 'admin/ultron-studio/draft');
      let rev = d.json.data.revision;
      if (d.json.data.exists !== true) {
        const g = await L.api(ctx, 'a', 'PUT', 'admin/ultron-studio/draft', { baseRevision: rev, content: d.json.data.content });
        inf.must('H5 (API): borrador desde la línea base', g.status === 200, g.status);
        rev = g.json.data.revision;
      }
      const v = await L.api(ctx, 'a', 'POST', 'admin/ultron-studio/versions', { label: 'Versión del E2E del CRM de voz', note: null, draftRevision: rev, clientRequestId: L.uuid() });
      inf.must('H5 (API): Ana crea la versión del Studio', v.status === 201, v.status);
      const vu = v.json.data.uuid;
      const sub = await L.api(ctx, 'a', 'POST', `admin/ultron-studio/versions/${vu}/submit`);
      const ap = await L.api(ctx, 'b', 'POST', `admin/ultron-studio/versions/${vu}/approve`, { acknowledgedWarnings: sub.json?.data?.warnings ?? [] });
      inf.must('H5 (API): Beto la aprueba (cuatro ojos)', sub.status === 200 && ap.status === 200, [sub.status, ap.status]);
      E.version = vu;
      E.can = {};
      for (const q of ['a', 'b', 'c']) {
        const iv = await L.api(ctx, q, 'GET', 'admin/ultron-studio/integrations/voice');
        inf.must(`ruta 45 (${q}): 200 con can`, iv.status === 200 && iv.json?.data?.can, iv.status);
        E.can[q] = iv.json.data.can;
      }
      inf.check('ruta 45: Ana y Beto con las 7 llaves; Carla con menos', Object.values(E.can.a).filter(Boolean).length === 7 && Object.values(E.can.b).filter(Boolean).length === 7
        && Object.values(E.can.c).filter(Boolean).length < 7, { a: E.can.a, c: E.can.c });
      // B-T4 (L9-82): el alta de contactos tiene su bandera (`VOICE_CONTACTS_ENABLED`, apagada hasta la 4b). El lanzador
      // la enciende en la copia (H8_E2E_CONTACTOS=true) para que el paso «contactos» ejerza el alta y los cuatro ojos.
      const vs = await L.api(ctx, 'a', 'GET', 'admin/voice/status');
      inf.check('banco: el alta de contactos (B-T4, flags.contacts) está encendida para el paso «contactos»', vs.status === 200 && vs.json?.flags?.contacts === true, { status: vs.status, flags: vs.json?.flags ?? null });
      for (const q of ['a', 'b', 'c']) S[q] = await sesionDe(q);
    });

    // ── 1. Botones iguales a `can` (antes de crear nada) ──────────────────────────────────────────────────────────
    await paso('can', async () => {
      requiere('can');
      for (const q of ['a', 'b', 'c']) {
        const s = S[q];
        const c = E.can[q];
        const visto = {};
        await L.pestana(s, ctx, 'perfil');
        visto.voiceManage = await existe(s, '[data-perfil-nuevo]');
        const motivoPerfil = await existe(s, '[data-motivo-accion="profile"]');
        await L.pestana(s, ctx, 'llamadas');
        visto.callPlace = await existe(s, '[data-voz-saliente-nueva]');
        visto.telephonyView = !(await existe(s, '[data-motivo-accion="telephony"]'));
        await L.pestana(s, ctx, 'contactos');
        visto.contactsManage = await existe(s, '[data-contacto-alta]');
        await L.pestana(s, ctx, 'laboratorio', SIN_LEYENDO('[data-laboratorio-estado], [data-laboratorio-apagado], [data-motivo-accion="lab-open"]'));
        visto.voiceLabRun = await existe(s, '[data-laboratorio-abrir-enviar]');
        await L.pestana(s, ctx, 'publicacion');
        visto.voicePublish = (await L.leer(s, `return document.querySelectorAll('[data-voz-acciones] [data-voz-accion]').length;`)) > 0;
        // Las llaves de la ruta 45 como las nombra el servidor (el CRM traduce telephonyCallPlace → callPlace, etc.).
        const API = { callPlace: 'telephonyCallPlace', contactsManage: 'telephonyContactsManage' };
        for (const k of Object.keys(visto)) {
          const kk = API[k] ?? k;
          inf.check(`${q}: el control de «${k}» está si y solo si can.${kk} (${c[kk]})`, typeof c[kk] === 'boolean' && visto[k] === c[kk], { visto: visto[k], can: c[kk] });
        }
        if (!c.voiceManage) inf.check(`${q}: sin can.voiceManage el perfil dice el motivo en vez del botón`, motivoPerfil, null);
      }
    });

    // ── 2. Perfil: Ana lo crea y lo envía; Beto lo aprueba ───────────────────────────────────────────────────────
    await paso('perfil', async () => {
      const a = S.a;
      inf.must('Ana: pestaña Perfil de voz', await L.pestana(a, ctx, 'perfil', SIN_LEYENDO('[data-perfil-nuevo]')));
      inf.must('Ana: «Nuevo perfil»', await L.clic(a, '[data-perfil-nuevo]'));
      inf.must('el diálogo del perfil se abre', await L.esperar(a, hayDialogo('[data-perfil-campo="label"]')));
      const foco = await L.focoAtrapado(a, 40, 10);
      inf.check('diálogo «Nuevo perfil de voz»: el foco no sale (40 Tab y 10 Mayús+Tab)', foco.ok, foco);
      await L.escribir(a, '[role=dialog] [data-perfil-campo="label"]', PERFIL);
      if (!(await L.leer(a, `return document.querySelector('[role=dialog] [data-perfil-campo="voiceId"]').value;`))) await L.escribir(a, '[role=dialog] [data-perfil-campo="voiceId"]', 'pNInz6obpgDQGcFmaJgB');
      const textos = { 'greetings.inbound': '¿En qué te puedo ayudar hoy?', 'greetings.outbound': 'Te llamo porque pediste información de Iron Body.', 'holes.presentation': 'Te ayudo con planes, horarios y la sede.', 'holes.closing': 'Gracias por tu tiempo.' };
      for (const [k, t] of Object.entries(textos)) {
        const sel = `[role=dialog] textarea[id$="-${k}"]`;
        if (await existe(a, sel)) {
          if (!(await L.leer(a, `return document.querySelector(${JSON.stringify(sel)}).value;`))) await L.escribir(a, sel, t);
        }
      }
      for (const t of ['consultar_planes', 'consultar_horarios', 'solicitar_humano', 'no_volver_a_llamar']) {
        const sel = `[role=dialog] [data-perfil-herramienta="${t}"]`;
        if ((await existe(a, sel)) && !(await L.leer(a, `const e = document.querySelector(${JSON.stringify(sel)}); return e.checked || e.disabled;`))) await a.page.click(sel);
      }
      await anchos(a, 'perfil-dialogo', 390);
      await L.captura(a, ctx, 'perfil-dialogo-1440');
      inf.must('Ana guarda el perfil', await enviarDialogo(a, '[data-perfil-guardar]'));
      const cerrado = await L.esperar(a, sinDialogo, L.T(20000));
      if (!cerrado) inf.check('el perfil se guarda sin errores de campo', false, await L.leer(a, `const d = ${dialogo}; return d ? [...d.querySelectorAll('.is-error, [role=alert]')].map((e) => e.textContent.trim()).slice(0, 5) : null;`));
      inf.must('el diálogo se cierra al guardar', cerrado);
      const uuid = await L.esperar(a, `const n = [...document.querySelectorAll('[data-perfil]')].find((e) => e.querySelector('.vpl-nombre')?.textContent.trim() === ${JSON.stringify(PERFIL)}); return n ? n.getAttribute('data-perfil') : null;`);
      inf.must('el perfil aparece en la lista', !!uuid, uuid);
      E.perfil = uuid;
      const f0 = L.sql(ctx, 'SELECT status, created_by_admin_id FROM ultron_studio_voice_profiles WHERE uuid = $1', [uuid])[0];
      inf.check('SQL: el perfil nace en draft, de Ana', f0?.status === 'draft' && f0?.created_by_admin_id === ctx.h.accounts.a.id, f0);
      inf.must('Ana lo envía a revisión', await L.clic(a, `[data-perfil="${uuid}"] [data-perfil-accion="submit"]`));
      inf.must('… y queda enviado', await L.esperar(a, `return document.querySelector('[data-perfil="${uuid}"]')?.getAttribute('data-estado') === 'submitted';`));
      inf.check('Ana no tiene «Aprobar» en su propio perfil (cuatro ojos)', !(await existe(a, `[data-perfil="${uuid}"] [data-perfil-accion="approve"]`)),
        await L.texto(a, `[data-perfil="${uuid}"] [data-motivo-accion="approve"]`));
      const b = S.b;
      inf.must('Beto: pestaña Perfil de voz', await L.pestana(b, ctx, 'perfil', SIN_LEYENDO(`[data-perfil="${uuid}"]`)));
      inf.must('Beto: «Aprobar»', await L.clic(b, `[data-perfil="${uuid}"] [data-perfil-accion="approve"]`));
      inf.must('el diálogo de aprobar se abre', await L.esperar(b, hayDialogo('[data-perfil-aprobar-dialogo]')));
      const fa = await L.focoAtrapado(b, 20, 6);
      inf.check('diálogo de aprobar el perfil: el foco no sale', fa.ok, fa);
      inf.must('Beto aprueba', await enviarDialogo(b, '[data-perfil-aprobar-enviar]'));
      inf.must('… y queda aprobado', await L.esperar(b, `return document.querySelector('[data-perfil="${uuid}"]')?.getAttribute('data-estado') === 'approved';`));
      const f1 = L.sql(ctx, 'SELECT status, created_by_admin_id, reviewed_by_admin_id, content_hash FROM ultron_studio_voice_profiles WHERE uuid = $1', [uuid])[0];
      inf.check('SQL: approved, de Ana, revisado por Beto, con su huella', f1?.status === 'approved' && f1.created_by_admin_id === ctx.h.accounts.a.id && f1.reviewed_by_admin_id === ctx.h.accounts.b.id
        && /^[0-9a-f]{64}$/.test(f1.content_hash), f1);
    });

    // ── 3. Previsualizar (Beto): la huella, sin red ni escritura ─────────────────────────────────────────────────
    await paso('previa', async () => {
      const [v, p] = [requiere('version'), requiere('perfil')];
      const b = S.b;
      inf.must('Beto: pestaña Perfil de voz (previsualizar)', await L.pestana(b, ctx, 'perfil', SIN_LEYENDO('[data-previa-form]')));
      await L.elegir(b, '[data-previa-version]', v);
      await L.elegir(b, '[data-previa-perfil]', p);
      await b.page.click('[data-previa-proposito="publication"]');
      const antes = await statsEl();
      const agentes0 = L.valor(ctx, 'SELECT count(*) FROM ultron_studio_voice_agents');
      inf.must('«Compilar sin publicar»', await L.clic(b, '[data-previa-compilar]'));
      const res = await L.esperar(b, `return document.querySelector('[data-previa-resultado]')?.getAttribute('data-previa-resultado') ?? null;`);
      inf.must('la previsualización compila', res === 'ok', res);
      const h = await hashesEn(b, '[data-previa-resultado]');
      const api = await L.api(ctx, 'b', 'POST', 'admin/ultron-studio/voice/compile-preview', { versionUuid: v, profileUuid: p });
      E.spec = api.json?.data?.specHash;
      inf.check('la huella que enseña el CRM es la de compile-preview (64 hex)', h.includes(E.spec), { crm: h.map((x) => x.slice(0, 12)), api: String(E.spec).slice(0, 12) });
      const d = delta(antes, await statsEl());
      inf.check('previsualizar no llama a ElevenLabs ni escribe agentes', d.requests === 0 && L.valor(ctx, 'SELECT count(*) FROM ultron_studio_voice_agents') === agentes0, d);
      await L.captura(b, ctx, 'perfil-previa-1440');
    });

    // ── 4. Laboratorio: Ana abre (micrófono falso con permiso), el emisor del simulador cierra, Beto juzga ────────
    //
    // El simulador firma URLs `ws://127.0.0.1:<puerto>` (el banco no tiene TLS) y el lector del CRM solo acepta
    // `wss://` (`studio-voice-parse.ts:1110`): con la URL tal cual, el CRM NO se conecta (lo comprueba la sesión 1, que
    // Ana cierra contando lo que pasó). Para ejercer la conexión (sesión 2), el navegador reescribe el esquema de la
    // respuesta 201 a `wss://` (CDP Fetch, como la costura de `spec_changed`): el cliente oficial abre el WebSocket
    // con la URL firmada del simulador, que falla el TLS (el simulador no modela el WebSocket, igual que en el E2E del
    // backend) y la sesión la cierra la post-llamada web del emisor del simulador.
    await paso('laboratorio', async () => {
      const [v, p, spec] = [requiere('version'), requiere('perfil'), requiere('spec')];
      const a = S.a;
      const sesionesSql = "SELECT s.uuid, s.status, s.end_reason, a.provider AS mode, a.provider_agent_id, a.spec_hash FROM ultron_studio_voice_lab_sessions s JOIN ultron_studio_voice_agents a ON a.id = s.agent_id ORDER BY s.id";
      inf.must('Ana: pestaña Laboratorio', await L.pestana(a, ctx, 'laboratorio', SIN_LEYENDO('[data-laboratorio-estado]')));
      inf.check('laboratorio disponible, en modo elevenlabs (el adaptador real contra el simulador)', (await atr(a, '[data-laboratorio-estado]', 'data-disponible')) === 'si'
        && (await atr(a, '[data-laboratorio-estado]', 'data-modo')) === 'elevenlabs', [await atr(a, '[data-laboratorio-estado]', 'data-disponible'), await atr(a, '[data-laboratorio-estado]', 'data-modo')]);
      inf.check('antes de abrir se ven la reserva de esta sesión, lo que queda hoy y el cupo', !!(await L.texto(a, '[data-laboratorio-reserva]')) && !!(await L.texto(a, '[data-laboratorio-queda]')) && !!(await L.texto(a, '[data-laboratorio-cupo]')),
        [await L.texto(a, '[data-laboratorio-reserva]'), await L.texto(a, '[data-laboratorio-cupo]')]);

      // Sesión 1: la URL del simulador tal cual (ws://).
      await L.elegir(a, '[data-laboratorio-version]', v);
      await L.elegir(a, '[data-laboratorio-perfil]', p);
      const n0 = L.sql(ctx, sesionesSql).length;
      const red1 = a.red.length;
      inf.must('«Abrir la sesión» (sesión 1)', await L.clic(a, '[data-laboratorio-abrir-enviar]'));
      const err1 = await L.esperar(a, `const e = document.querySelector('[data-laboratorio-error-abrir]'); return e ? e.textContent.trim() : (document.querySelector('[data-laboratorio-vivo]') ? 'VIVA' : null);`, L.T(30000));
      const s1 = L.sql(ctx, sesionesSql).slice(n0)[0];
      inf.must('SQL: el servidor abrió la sesión 1 (open, elevenlabs, la huella previsualizada)', !!s1 && s1.status === 'open' && s1.mode === 'elevenlabs' && s1.spec_hash === spec, s1 && { status: s1.status, mode: s1.mode });
      inf.prohib['agente de laboratorio 1 (id completo)'] = s1.provider_agent_id;
      const ws1 = a.red.slice(red1).filter((r) => r.kind === 'websocket');
      inf.check('sesión 1: el CRM NO se conecta a una URL firmada sin TLS (ws:// del simulador): lo dice y no abre ningún WebSocket', !!err1 && err1 !== 'VIVA' && /forma esperada/.test(err1) && ws1.length === 0,
        { error: err1, websockets: ws1.length });
      await L.captura(a, ctx, 'laboratorio-sesion1-sin-tls-1440');
      // Ana cuenta lo que pasó (la sesión abierta sí se lee): la cierra `tester_ended` con lo observado.
      inf.must('Ana: «Contar lo que pasó» en la sesión 1', await L.pestana(a, ctx, 'laboratorio', SIN_LEYENDO(`li[data-sesion="${s1.uuid}"] [data-sesion-terminar]`))
        && await L.clic(a, `li[data-sesion="${s1.uuid}"] [data-sesion-terminar]`));
      inf.must('el formulario de lo observado se abre', !!(await L.esperar(a, SIN_LEYENDO('[data-laboratorio-contar] [data-observacion^="objectives:"]'))));
      const marcas = await L.leer(a, `return [...document.querySelectorAll('[data-laboratorio-contar] [data-observacion^="objectives:"]')].slice(0, 2).map((e) => e.getAttribute('data-observacion'));`);
      for (const m of marcas) await a.page.click(`[data-laboratorio-contar] [data-observacion="${m}"]`);
      inf.must('Ana guarda lo observado', await L.clic(a, '[data-laboratorio-contar-enviar]'));
      inf.check('… la sesión 1 enseña lo observado', !!(await L.esperar(a, SIN_LEYENDO(`li[data-sesion="${s1.uuid}"] [data-sesion-observado]`))), marcas);
      const f1 = L.sql(ctx, 'SELECT status, end_reason FROM ultron_studio_voice_lab_sessions WHERE uuid = $1', [s1.uuid])[0];
      inf.check('SQL: la sesión 1 queda ended por tester_ended', f1?.status === 'ended' && f1.end_reason === 'tester_ended', f1);
      inf.check('Ana no tiene «Aprobar» en su propia sesión (cuatro ojos)', !(await existe(a, `li[data-sesion="${s1.uuid}"] [data-sesion-aprobar]`)), await L.texto(a, `li[data-sesion="${s1.uuid}"]`));

      // Sesión 2: la misma URL firmada con el esquema `wss://` (costura del navegador sobre la respuesta 201).
      inf.must('Ana: pestaña Laboratorio (sesión 2)', await L.pestana(a, ctx, 'laboratorio', SIN_LEYENDO('[data-laboratorio-abrir-enviar]')));
      await L.elegir(a, '[data-laboratorio-version]', v);
      await L.elegir(a, '[data-laboratorio-perfil]', p);
      let reescritas = 0;
      await a.cdp.send('Fetch.enable', { patterns: [{ urlPattern: '*/api/admin/ultron-studio/voice-lab/sessions', requestStage: 'Response' }] });
      const sigue = async (id) => { try { await a.cdp.send('Fetch.continueResponse', { requestId: id }); } catch { try { await a.cdp.send('Fetch.continueRequest', { requestId: id }); } catch { /* resuelta */ } } };
      const reescribe = async (e) => {
        if (e.request.method !== 'POST' || e.responseStatusCode !== 201) return sigue(e.requestId);
        try {
          const b = await a.cdp.send('Fetch.getResponseBody', { requestId: e.requestId });
          const j = JSON.parse(b.base64Encoded ? Buffer.from(b.body, 'base64').toString('utf8') : b.body);
          const u = j?.data?.connection?.signedUrl;
          if (typeof u === 'string' && u.startsWith('ws://')) { j.data.connection.signedUrl = `wss://${u.slice(5)}`; reescritas++; }
          await a.cdp.send('Fetch.fulfillRequest', { requestId: e.requestId, responseCode: 201, responseHeaders: e.responseHeaders, body: Buffer.from(JSON.stringify(j)).toString('base64') });
        } catch { await sigue(e.requestId); }
      };
      a.cdp.on('Fetch.requestPaused', reescribe);
      const red2 = a.red.length;
      const n1 = L.sql(ctx, sesionesSql).length;
      inf.must('«Abrir la sesión» (sesión 2)', await L.clic(a, '[data-laboratorio-abrir-enviar]'));
      const viva = await L.esperar(a, `return document.querySelector('[data-laboratorio-vivo]')?.getAttribute('data-estado') || (document.querySelector('[data-laboratorio-error-abrir]') ? 'error:' + document.querySelector('[data-laboratorio-error-abrir]').textContent.trim() : null);`, L.T(30000));
      await L.dormir(3000);
      a.cdp.off('Fetch.requestPaused', reescribe);
      await a.cdp.send('Fetch.disable');
      const abierta = !!viva && !String(viva).startsWith('error:');
      inf.check('sesión 2: el CRM abre la conversación (franja en vivo) con la URL firmada en wss://', abierta && reescritas === 1, abierta ? { estado: await atr(a, '[data-laboratorio-vivo]', 'data-estado'), reescritas } : {
        viva, reescritas, boton: await L.texto(a, '[data-laboratorio-abrir-enviar]'), errores: a.errores.slice(-3),
        consola: a.consola.slice(-6).map((m) => `${m.type}: ${m.text.slice(0, 160)}`), chrome: (a.log || []).slice(-6).map((e) => `${e.source}/${e.level}: ${e.text.slice(0, 160)}`),
      });
      if (!abierta) await L.captura(a, ctx, 'laboratorio-no-abre-1440');
      const s2 = L.sql(ctx, sesionesSql).slice(n1)[0];
      inf.must('SQL: una sesión 2 del laboratorio (abierta, elevenlabs, la MISMA huella previsualizada)', !!s2 && s2.status === 'open' && s2.mode === 'elevenlabs' && s2.spec_hash === spec, s2 && { status: s2.status, mode: s2.mode });
      inf.prohib['agente de laboratorio (id completo)'] = s2.provider_agent_id;
      E.labSesion = s2.uuid;
      const nuevas = a.red.slice(red2);
      const post = nuevas.filter((r) => r.method === 'POST' && r.path === '/api/admin/ultron-studio/voice-lab/sessions');
      const ws = nuevas.filter((r) => r.kind === 'websocket');
      inf.check('una sola petición de abrir (POST voice-lab/sessions)', post.length === 1, post.length);
      inf.check('el cliente oficial abre el WebSocket con la URL firmada del SIMULADOR (wss://127.0.0.1, agent_id y conversation_signature)', ws.length >= 1 && ws.every((w) => w.scheme === 'wss' && w.host === `127.0.0.1:${ctx.h.ports.elevenlabs}`)
        && /^\/v1\/convai\/conversation\?(.*&)?agent_id=…&conversation_signature=…/.test(ws[0].path), ws.map((w) => `${w.scheme}://${w.host}${w.path}`));
      inf.check('el WebSocket falla (el simulador no habla TLS ni modela la conversación) y el CRM lo enseña sin reintentar', (await L.esperar(a, `return document.querySelector('[data-laboratorio-vivo]')?.getAttribute('data-estado') === 'failed';`, L.T(20000))) === true
        && a.red.slice(red2).filter((r) => r.method === 'POST' && r.path === '/api/admin/ultron-studio/voice-lab/sessions').length === 1, await L.texto(a, '[data-laboratorio-conexion]'));
      inf.check('SQL: la URL firmada no se guarda en ninguna sesión', L.valor(ctx, "SELECT count(*) FROM ultron_studio_voice_lab_sessions WHERE row_to_json(ultron_studio_voice_lab_sessions)::text LIKE '%conversation_signature%'") === 0, null);
      const bar = await L.barrerPagina(a, inf);
      inf.check('laboratorio abierto: ni la URL firmada ni el id completo del agente en el DOM, el almacenamiento ni la URL del navegador', bar.dom.length === 0 && bar.almacenamiento.length === 0 && bar.url.length === 0, bar);
      inf.check('laboratorio abierto: la consola de la PÁGINA no lleva la URL firmada', !(bar.consolaPorFuente.javascript || []).length && !(bar.consolaPorFuente['console-api'] || []).length, bar.consolaPorFuente);
      E.consolaChromeLab = bar.consolaPorFuente;
      await L.captura(a, ctx, 'laboratorio-sesion2-1440');

      // El emisor del simulador: la post-llamada web firmada de la conversación que registró la URL firmada.
      const pc = await L.sim(ctx, 'elevenlabs', 'emit/post-call', { web: '1', agent_id: s2.provider_agent_id });
      inf.check('emisor del simulador: post-llamada web → 200', (pc.deliveries?.[0]?.status ?? pc.laravel?.status) === 200, { http: pc._http, d: pc.deliveries?.[0]?.status });
      const cerrada = await (async () => { for (let i = 0; i < 50; i++) { const st = L.sql(ctx, 'SELECT status, end_reason FROM ultron_studio_voice_lab_sessions WHERE uuid = $1', [s2.uuid])[0]; if (st.status !== 'open') return st; await L.dormir(200); } return { status: 'open' }; })();
      inf.check('SQL: la sesión 2 se cierra por el informe del proveedor (provider_report)', cerrada.status === 'ended' && cerrada.end_reason === 'provider_report', cerrada);
      inf.check('el informe del laboratorio no abre el freno ni deja unknown_provider_call', L.valor(ctx, "SELECT count(*) FROM voice_brakes WHERE kind = 'open'") === 0
        && L.valor(ctx, "SELECT count(*) FROM voice_call_events WHERE kind = 'unknown_provider_call'") === 0, null);
      // Lo que ve su DUEÑA: el servidor le ofrece contar lo que pasó (LAB8: `end` cabe en una sesión terminada sin
      // veredicto ni lo observado); el lector del CRM lo tiene por incoherente (`studio-voice-parse.ts:1035`).
      await L.pestana(a, ctx, 'laboratorio', `return !!document.querySelector('li[data-sesion="${s2.uuid}"]') || /ilegible/.test(document.querySelector('#vt-panel-laboratorio')?.textContent || '');`);
      const apiA = await L.api(ctx, 'a', 'GET', 'admin/ultron-studio/voice-lab/sessions?page=1&per_page=20');
      const dtoA = (apiA.json?.data?.data || []).find((x) => x.uuid === s2.uuid);
      inf.check('Ana (su dueña) ve en el CRM la sesión 2 cerrada por el proveedor', (await atr(a, `li[data-sesion="${s2.uuid}"]`, 'data-estado')) === 'ended', {
        crm: await L.leer(a, `return [...document.querySelectorAll('#vt-panel-laboratorio .lab-alert, #vt-panel-laboratorio [role=alert]')].map((e) => e.textContent.trim()).slice(0, 3);`),
        servidor: dtoA && { status: dtoA.status, endReason: dtoA.endReason, actionsEnd: dtoA.actions?.end, reported: dtoA.metrics?.reported, verdict: dtoA.verdict },
      });
      await L.captura(a, ctx, 'laboratorio-sesion2-cerrada-ana-1440');

      // El veredicto, de OTRA identidad (Beto), sobre la huella ENTERA que se ve.
      const b = S.b;
      inf.must('Beto: pestaña Laboratorio', await L.pestana(b, ctx, 'laboratorio', SIN_LEYENDO(`li[data-sesion="${s2.uuid}"] [data-sesion-aprobar]`)));
      inf.check('Beto ve la sesión 2 cerrada por el proveedor, con su duración (64 s) y su coste', (await atr(b, `li[data-sesion="${s2.uuid}"]`, 'data-estado')) === 'ended'
        && /1 min 0?4 s|64 s|1:04/.test(String(await L.texto(b, `li[data-sesion="${s2.uuid}"] [data-sesion-cuentas]`))), await L.texto(b, `li[data-sesion="${s2.uuid}"] [data-sesion-cuentas]`));
      await b.page.click(`li[data-sesion="${s2.uuid}"] [data-sesion-aprobar]`);
      inf.must('el diálogo del veredicto se abre', await L.esperar(b, hayDialogo('[data-sesion-aprobar-dialogo]')));
      const huella = await L.texto(b, '[data-veredicto-huella] .vl-huella');
      inf.check('el veredicto enseña la huella ENTERA que se juzga, la del perfil previsualizado', huella === spec, String(huella).slice(0, 16));
      const fv = await L.focoAtrapado(b, 20, 6);
      inf.check('diálogo del veredicto: el foco no sale', fv.ok, fv);
      inf.must('Beto aprueba la sesión 2', await enviarDialogo(b, '[data-sesion-aprobar-enviar]'));
      inf.check('… y la sesión enseña el veredicto aprobado', !!(await L.esperar(b, SIN_LEYENDO(`li[data-sesion="${s2.uuid}"] [data-sesion-veredicto][data-valor="approved"]`))), null);
      const vr = L.sql(ctx, 'SELECT s.verdict AS decision, s.verdict_by_admin_id AS reviewer_admin_id, a.spec_hash FROM ultron_studio_voice_lab_sessions s JOIN ultron_studio_voice_agents a ON a.id = s.agent_id WHERE s.uuid = $1', [s2.uuid])[0];
      inf.check('SQL: veredicto approved de Beto sobre la MISMA huella', vr?.decision === 'approved' && vr.reviewer_admin_id === ctx.h.accounts.b.id && vr.spec_hash === spec, vr && { d: vr.decision, r: vr.reviewer_admin_id });
      await L.captura(b, ctx, 'laboratorio-veredicto-beto-1440');
    });

    // ── 5. Publicar en canario con la huella (Beto): primero un spec_changed, luego 201 ─────────────────────────
    await paso('publicar', async () => {
      const [v, p, spec] = [requiere('version'), requiere('perfil'), requiere('spec')];
      const b = S.b;
      inf.must('Beto: pestaña Publicación', await L.pestana(b, ctx, 'publicacion', SIN_LEYENDO('[data-voz-acciones]')));
      inf.check('la ventana de publicación está abierta (el arnés corre sin ventana)', (await atr(b, '[data-voz-ventana]', 'data-abierta')) === 'si', await L.texto(b, '[data-voz-ventana]'));
      inf.must('«Publicar en canario»', await L.clic(b, '[data-voz-accion="publish"]'));
      inf.must('el diálogo de publicar se abre', await L.esperar(b, hayDialogo('[data-voz-publicar-dialogo]')));
      const fp = await L.focoAtrapado(b, 30, 8);
      inf.check('diálogo de publicar: el foco no sale', fp.ok, fp);
      await L.elegir(b, '[data-publicar-version]', v);
      await L.elegir(b, '[data-publicar-perfil]', p);
      await b.page.click('[data-publicar-compilar]');
      inf.must('el diálogo enseña la huella que se va a publicar', !!(await L.esperar(b, SIN_LEYENDO('[data-publicar-huella]'))));
      const h = await hashesEn(b, '[data-publicar-huella]');
      inf.check('… y es la del perfil previsualizado', h.includes(spec), h.map((x) => x.slice(0, 12)));
      await anchos(b, 'publicar-dialogo', 390);
      // spec_changed: la compilación de un perfil y una versión APROBADOS no cambia sin un despliegue; se provoca
      // alterando en el navegador (CDP Fetch) la huella que viaja en la PRIMERA petición de publicar. El 409 es el real.
      await b.cdp.send('Fetch.enable', { patterns: [{ urlPattern: '*/api/admin/ultron-studio/voice/publish*', requestStage: 'Request' }] });
      let alteradas = 0;
      const pausa = async (e) => {
        try {
          if (e.request.method === 'POST' && alteradas === 0 && e.request.postData) {
            const cuerpo = JSON.parse(e.request.postData);
            const x = String(cuerpo.expectedSpecHash);
            cuerpo.expectedSpecHash = x.slice(0, 63) + (x[63] === '0' ? '1' : '0');
            alteradas++;
            await b.cdp.send('Fetch.continueRequest', { requestId: e.requestId, postData: Buffer.from(JSON.stringify(cuerpo)).toString('base64') });
          } else await b.cdp.send('Fetch.continueRequest', { requestId: e.requestId });
        } catch { /* petición ya resuelta */ }
      };
      b.cdp.on('Fetch.requestPaused', pausa);
      const red0 = b.red.length;
      const antes = await statsEl();
      await b.page.click('[data-publicar-enviar]');
      const cambiada = await L.esperar(b, SIN_LEYENDO('[data-publicar-spec-cambiada]'), L.T(20000));
      await L.dormir(2500);
      b.cdp.off('Fetch.requestPaused', pausa);
      await b.cdp.send('Fetch.disable');
      const posts = b.red.slice(red0).filter((r) => r.method === 'POST' && r.path === '/api/admin/ultron-studio/voice/publish');
      inf.check('spec_changed: el CRM lo dice («la compilación cambió») y borra la huella vista', !!cambiada && !(await existe(b, '[data-publicar-huella]')), await L.texto(b, '[data-publicar-spec-cambiada]'));
      inf.check('spec_changed: una sola petición de publicar, sin reintento', posts.length === 1 && alteradas === 1, { posts: posts.length, alteradas });
      inf.check('spec_changed: «Publicar en canario» desactivado hasta volver a previsualizar', await L.leer(b, `return document.querySelector('[data-publicar-enviar]')?.disabled === true;`), null);
      inf.check('spec_changed: nada en ElevenLabs, ningún agente de publicación ni entrada', delta(antes, await statsEl()).requests === 0
        && L.valor(ctx, "SELECT count(*) FROM ultron_studio_voice_agents WHERE purpose = 'publication'") === 0 && L.valor(ctx, 'SELECT count(*) FROM ultron_studio_voice_publications') === 0, null);
      await L.captura(b, ctx, 'publicar-spec-cambiada-1440');
      await b.page.click('[data-publicar-compilar]');
      inf.must('volver a previsualizar enseña la huella', !!(await L.esperar(b, SIN_LEYENDO('[data-publicar-huella]'))));
      await L.dormir(1100); // O-1 del informe: una persona no publica en el mismo segundo
      await b.page.click('[data-publicar-enviar]');
      const ok = await L.esperar(b, `return document.querySelector('[data-voz-publicacion-aviso]') ? 'aviso' : document.querySelector('[data-publicar-error], [data-publicar-negativa]') ? 'error:' + document.querySelector('[data-publicar-error], [data-publicar-negativa]').textContent.trim().slice(0, 200) : null;`, L.T(60000));
      inf.must('Beto publica en canario', ok === 'aviso', ok);
      const ag = L.sql(ctx, "SELECT id, provider_agent_id, status, serves_canary, spec_hash FROM ultron_studio_voice_agents WHERE purpose = 'publication' ORDER BY id DESC LIMIT 1")[0];
      const en = L.sql(ctx, "SELECT seq, action, scope, actor_admin_id, lab_gate->>'mode' AS puerta FROM ultron_studio_voice_publications ORDER BY seq")[0];
      inf.check('SQL: entrada 1 publish canary de Beto, puerta del laboratorio required; agente deployed con la huella vista', en?.seq === 1 && en.action === 'publish' && en.scope === 'canary'
        && en.actor_admin_id === ctx.h.accounts.b.id && en.puerta === 'required' && ag?.status === 'deployed' && ag.spec_hash === spec, { en, ag: ag && { status: ag.status, canary: ag.serves_canary } });
      inf.prohib['agente publicado (id completo)'] = ag.provider_agent_id;
      E.agente = ag.provider_agent_id;
      const st = await L.sim(ctx, 'elevenlabs', 'state');
      inf.check('simulador: el número apunta al agente publicado', st.numbers?.[0]?.agent === ag.provider_agent_id, null);
      await L.captura(b, ctx, 'publicacion-publicada-1440');
    });

    // ── 6. La cadena (Carla, solo lectura) ─────────────────────────────────────────────────────────────────────
    await paso('cadena', async () => {
      requiere('agente');
      const c = S.c;
      inf.must('Carla: pestaña Publicación', await L.pestana(c, ctx, 'publicacion', SIN_LEYENDO('[data-voz-cadena]')));
      inf.check('la cadena enseña la entrada 1 (publish) sin firma mala', (await existe(c, '[data-voz-cadena] [data-entrada="1"][data-accion="publish"]')) && !(await existe(c, '[data-entrada-firma-mala]')), null);
      inf.check('la parte canario vigente y verificada', (await atr(c, '[data-voz-parte="canary"]', 'data-verificada')) === 'ok', await atr(c, '[data-voz-parte="canary"]', 'data-verificada'));
      inf.check('Carla no tiene acciones de publicar (sin can.voicePublish)', (await L.leer(c, `return document.querySelectorAll('[data-voz-acciones] [data-voz-accion]').length;`)) === 0, null);
      const ref = await L.leer(c, `return [...document.querySelectorAll('[data-voz-cadena] .ig-ref')].map((e) => e.textContent.trim()).filter((t) => t.startsWith('…')).slice(0, 2);`);
      inf.check('el id del agente se ve enmascarado («…»)', ref.length > 0, ref);
      const bar = await L.barrerPagina(c, inf);
      inf.check('Publicación (Carla): ni el id completo del agente ni secretos en DOM, almacenamiento ni consola', bar.dom.length === 0 && bar.almacenamiento.length === 0 && bar.consola.length === 0, bar);
      await L.captura(c, ctx, 'publicacion-cadena-carla-1440');
    });

    // ── 7. Llamadas: 3 entrantes del canario por el simulador ─────────────────────────────────────────────────────
    await paso('llamadas', async () => {
      requiere('agente');
      const herramientas = [['consultar_planes', 'consultar_horarios'], ['consultar_horarios'], ['solicitar_humano']];
      const conv = [];
      for (const [i, lista] of herramientas.entries()) {
        const r = await L.sim(ctx, 'elevenlabs', 'inbound', { from: ctx.h.canaries[0] });
        inf.must(`entrante ${i + 1}: la iniciación autoriza (200, si)`, r.laravel?.status === 200 && r.caller_authorized === 'si', { http: r._http, l: r.laravel?.status, c: r.caller_authorized });
        for (const t of lista) await L.sim(ctx, 'elevenlabs', 'tool', { conversation_id: r.conversation_id, tool: t });
        await L.sim(ctx, 'elevenlabs', 'call', { conversation_id: r.conversation_id, event: 'completed', duration: 6 + i });
        const pc = await L.sim(ctx, 'elevenlabs', 'emit/post-call', { conversation_id: r.conversation_id });
        inf.check(`entrante ${i + 1}: post-llamada 200`, (pc.deliveries?.[0]?.status) === 200, pc.deliveries?.[0]?.status);
        conv.push(r.conversation_id);
      }
      const rc = L.artisan(ctx, ['voice:reconcile', '--json']);
      inf.check('voice:reconcile tras las 3 entrantes sale con 0', rc.code === 0, rc.code);
      const filas = L.sql(ctx, "SELECT uuid, status, seal_verified FROM voice_calls WHERE direction = 'inbound' ORDER BY id");
      inf.check('SQL: 3 entrantes completed y selladas', filas.length === 3 && filas.every((f) => f.status === 'completed' && f.seal_verified === true), filas.map((f) => [f.status, f.seal_verified]));
      E.entrantes = filas.map((f) => f.uuid);
      const a = S.a;
      inf.must('Ana: pestaña Llamadas', await L.pestana(a, ctx, 'llamadas', `return document.querySelectorAll('[data-llamada][data-direccion="inbound"]').length >= 3;`));
      const remotos = await L.leer(a, `${L.NORMALIZA} return [...document.querySelectorAll('[data-llamada][data-direccion="inbound"] [data-llamada-remoto]')].map((e) => n(e.textContent));`);
      // El servidor no manda del otro extremo más que los 4 últimos dígitos: ni el número, ni la huella, ni el alias de
      // un canario (`VoiceCallsQuery.php:13`, D8-22 «sin datos personales»). El alias se ve donde se elige a quién llamar.
      inf.check('las 3 entrantes: solo los 4 últimos dígitos del canario (ni el número ni el alias)', remotos.length === 3 && remotos.every((t) => /0011/.test(t) && !/3000000011/.test(t) && !/Canario de voz/.test(t)), remotos);
      const u = E.entrantes[0];
      inf.must('abrir el detalle de una', await L.clic(a, `[data-llamada="${u}"] [data-llamada-eventos]`));
      await L.esperar(a, SIN_LEYENDO(`[data-llamada="${u}"] [data-llamada-detalle] [data-evento]`));
      const det = await L.api(ctx, 'a', 'GET', `admin/voice/calls/${u}`);
      const evApi = det.json?.call?.events ?? [];
      const evDom = await L.leer(a, `${L.NORMALIZA} return [...document.querySelectorAll('[data-llamada="${u}"] [data-llamada-detalle] [data-evento]')].map((e) => ({ k: e.getAttribute('data-evento'), t: n(e.textContent) }));`);
      const codigos = evApi.flatMap((e) => (Array.isArray(e.codes) ? e.codes : []));
      inf.check('detalle: el CRM enseña TODOS los eventos de la API (eventos descartados = 0)', evApi.length > 0 && evDom.length === evApi.length
        && !(await existe(a, `[data-llamada="${u}"] [data-llamada-detalle] .lab-alert.is-warn`)), { api: evApi.length, dom: evDom.length, tipos: evDom.map((e) => e.k) });
      inf.check('detalle: los códigos de los eventos son una lista plana snake_case y se ven en el CRM', codigos.length > 0 && codigos.every((c) => typeof c === 'string' && /^[a-z0-9_]+$/.test(c))
        && codigos.every((c) => evDom.some((e) => e.t.includes(c))), codigos.slice(0, 12));
      const bar = await L.barrerPagina(a, inf);
      inf.check('Llamadas: ningún teléfono completo ni secreto en DOM, almacenamiento ni consola', bar.dom.length === 0 && bar.almacenamiento.length === 0 && bar.consola.length === 0, bar);
      await L.captura(a, ctx, 'llamadas-detalle-1440');
      await anchos(a, 'llamadas-detalle', 390);
    });

    // ── 8. Costes y métricas ────────────────────────────────────────────────────────────────────────────────────
    await paso('costes', async () => {
      const a = S.a;
      inf.must('Ana: pestaña Costes', await L.pestana(a, ctx, 'costes', SIN_LEYENDO('[data-voz-costes]')));
      const t = await L.texto(a, '[data-voz-costes]');
      inf.check('Costes y métricas se pintan con cifras', !!t && /\d/.test(t), String(t).slice(0, 240));
      const bar = await L.barrerPagina(a, inf);
      inf.check('Costes: ningún teléfono ni secreto en DOM, almacenamiento ni consola', bar.dom.length === 0 && bar.almacenamiento.length === 0 && bar.consola.length === 0, bar);
      await L.captura(a, ctx, 'costes-1440');
    });

    // ── 9. Contactos: alta (Ana), consentimiento cargado (Ana) y confirmado (Beto), exclusión ───────────────────
    await paso('contactos', async () => {
      const a = S.a;
      inf.must('Ana: pestaña Contactos', await L.pestana(a, ctx, 'contactos', SIN_LEYENDO('[data-contacto-alta]')));
      await a.page.click('[data-contacto-alta]');
      inf.must('el diálogo de alta se abre', await L.esperar(a, hayDialogo('[data-contacto-dialogo="alta"]')));
      const fa = await L.focoAtrapado(a, 20, 6);
      inf.check('diálogo de alta del contacto: el foco no sale', fa.ok, fa);
      await L.escribir(a, '[data-contacto-telefono]', '+573000000071');
      await L.elegir(a, '[data-contacto-origen]', 'manual');
      await enviarDialogo(a, '[data-contacto-enviar]');
      const k = await L.esperar(a, `const e = [...document.querySelectorAll('[data-contacto]')].find((x) => x.querySelector('[data-contacto-numero]')?.textContent.includes('0071')); return e ? e.getAttribute('data-contacto') : null;`);
      inf.must('el contacto aparece con ••0071', !!k, k);
      E.contacto = k;
      const f0 = L.sql(ctx, 'SELECT phone_encrypted IS NOT NULL AS cifrado, phone_last4 FROM voice_contacts WHERE uuid = $1', [k])[0];
      inf.check('SQL: el contacto guardado cifrado con sus 4 últimos', f0?.cifrado === true && f0.phone_last4 === '0071', f0);
      inf.must('Ana: «Cargar el consentimiento»', await L.clic(a, `[data-contacto="${k}"] [data-contacto-accion="consent_request"]`));
      inf.must('el diálogo del consentimiento se abre', await L.esperar(a, hayDialogo('[data-contacto-dialogo="consentimiento"]')));
      const fuente = await L.leer(a, `return document.querySelector('[role=dialog] [data-contacto-fuente]')?.getAttribute('data-contacto-fuente') ?? null;`);
      await a.page.click(`[role=dialog] [data-contacto-fuente="${fuente}"]`);
      await L.escribir(a, '[role=dialog] [data-contacto-evidencia]', 'Lo pidió en recepción el lunes; firmó el formulario de voz (E2E).');
      await enviarDialogo(a, '[data-contacto-enviar]');
      inf.check('… queda pendiente de confirmar por otra persona', !!(await L.esperar(a, SIN_LEYENDO(`[data-contacto="${k}"] [data-contacto-pendiente]`))), await atr(a, `[data-contacto="${k}"]`, 'data-consentimiento'));
      const propia = await existe(a, `[data-contacto="${k}"] [data-contacto-accion="consent_confirm"]`);
      if (propia) {
        await a.page.click(`[data-contacto="${k}"] [data-contacto-accion="consent_confirm"]`);
        await L.esperar(a, hayDialogo('[data-contacto-dialogo="confirmar"]'));
        await enviarDialogo(a, '[data-contacto-enviar]');
        const err = await L.esperar(a, `const e = document.querySelector('[data-contacto-error-form], [data-contacto-error]'); return e ? e.textContent.trim() : null;`, L.T(15000));
        inf.check('Ana no confirma lo que ella cargó (el servidor lo niega y el CRM lo dice)', !!err, err);
        await a.page.keyboard.press('Escape');
        await L.esperar(a, sinDialogo, L.T(5000));
      } else inf.check('Ana no tiene «Confirmar» en lo que ella cargó', true, null);
      const b = S.b;
      inf.must('Beto: pestaña Contactos', await L.pestana(b, ctx, 'contactos', SIN_LEYENDO(`[data-contacto="${k}"] [data-contacto-accion="consent_confirm"]`)));
      await b.page.click(`[data-contacto="${k}"] [data-contacto-accion="consent_confirm"]`);
      inf.must('el diálogo de confirmar se abre', await L.esperar(b, hayDialogo('[data-contacto-dialogo="confirmar"]')));
      await enviarDialogo(b, '[data-contacto-enviar]');
      inf.check('Beto confirma: el consentimiento queda vigente', !!(await L.esperar(b, `return document.querySelector('[data-contacto="${k}"]')?.getAttribute('data-consentimiento') !== 'pending' && !document.querySelector('[data-contacto="${k}"] [data-contacto-pendiente]');`)),
        await atr(b, `[data-contacto="${k}"]`, 'data-consentimiento'));
      const cs = L.sql(ctx, "SELECT c.kind, c.recorded_by_admin_id, c.confirmed_by_admin_id FROM voice_consents c JOIN voice_contacts k ON k.id = c.contact_id WHERE k.uuid = $1 ORDER BY c.id", [k]);
      inf.check('SQL: consentimiento cargado por Ana y confirmado por Beto', cs.length >= 1 && cs.some((x) => x.recorded_by_admin_id === ctx.h.accounts.a.id && x.confirmed_by_admin_id === ctx.h.accounts.b.id), cs);
      await b.page.click(`[data-contacto="${k}"] [data-contacto-accion="exclude"]`);
      inf.must('el diálogo de excluir se abre', await L.esperar(b, hayDialogo('[data-contacto-dialogo="excluir"]')));
      const fe = await L.focoAtrapado(b, 12, 4);
      inf.check('diálogo de excluir: el foco no sale', fe.ok, fe);
      await L.escribir(b, '[role=dialog] [data-contacto-nota]', 'Pidió no volver a recibir llamadas (E2E).');
      await enviarDialogo(b, '[data-contacto-enviar]');
      inf.check('el contacto queda excluido', !!(await L.esperar(b, SIN_LEYENDO(`[data-contacto="${k}"] [data-contacto-excluido]`))), null);
      const bar = await L.barrerPagina(b, inf);
      inf.check('Contactos: ningún teléfono completo ni secreto en DOM, almacenamiento ni consola', bar.dom.length === 0 && bar.almacenamiento.length === 0 && bar.consola.length === 0, bar);
      await L.captura(b, ctx, 'contactos-excluido-1440');
    });

    // ── 10. Freno: seal_mismatch del simulador, visible; lo suelta quien tiene permiso ───────────────────────────
    await paso('freno', async () => {
      requiere('agente');
      const r = await L.sim(ctx, 'elevenlabs', 'inbound', { from: ctx.h.canaries[1] });
      inf.must('entrante del canario 2 autorizada', r.caller_authorized === 'si', r.caller_authorized);
      await L.sim(ctx, 'elevenlabs', 'call', { conversation_id: r.conversation_id, event: 'completed', duration: 30 });
      const pc = await L.sim(ctx, 'elevenlabs', 'emit/post-call', { conversation_id: r.conversation_id, agent_id: 'agent_suplantado_e2e' });
      inf.check('post-llamada con OTRO agente → 200', pc.deliveries?.[0]?.status === 200, pc.deliveries?.[0]?.status);
      const fr = L.sql(ctx, "SELECT uuid, reason FROM voice_brakes WHERE kind = 'open' ORDER BY id DESC LIMIT 1")[0];
      inf.must('SQL: el freno abierto por seal_mismatch', fr?.reason === 'seal_mismatch', fr?.reason);
      const c = S.c;
      inf.must('Carla: pestaña Estado', await L.pestana(c, ctx, 'estado', SIN_LEYENDO('[data-voz-freno-ahora]')));
      inf.check('el freno se ve echado', (await atr(c, '[data-voz-freno-ahora]', 'data-estado')) === 'engaged' && (await atr(c, `[data-voz-freno-fila="${fr.uuid}"]`, 'data-suelto')) === 'no', await L.texto(c, '[data-voz-freno-ahora]'));
      inf.check('Carla no tiene «Soltar el freno»', !(await existe(c, '[data-voz-soltar]')), null);
      await L.captura(c, ctx, 'estado-freno-echado-carla-1440');
      const a = S.a;
      inf.must('Ana: pestaña Estado', await L.pestana(a, ctx, 'estado', SIN_LEYENDO(`[data-voz-freno-fila="${fr.uuid}"] [data-voz-soltar]`)));
      await a.page.click(`[data-voz-freno-fila="${fr.uuid}"] [data-voz-soltar]`);
      inf.must('el diálogo de soltar se abre', await L.esperar(a, hayDialogo('textarea')));
      const ff = await L.focoAtrapado(a, 12, 4);
      inf.check('diálogo de soltar el freno: el foco no sale', ff.ok, ff);
      await L.escribir(a, '[role=dialog] textarea', 'Revisado: el sello roto lo provocó el E2E del CRM.');
      await enviarDialogo(a, 'button[type=submit]');
      inf.check('el freno queda suelto en el CRM', !!(await L.esperar(a, `return document.querySelector('[data-voz-freno-ahora]')?.getAttribute('data-estado') === 'released' && document.querySelector('[data-voz-freno-fila="${fr.uuid}"]')?.getAttribute('data-suelto') === 'si';`)), null);
      const sr = L.sql(ctx, "SELECT r.actor_admin_id FROM voice_brakes r JOIN voice_brakes o ON o.id = r.brake_id WHERE r.kind = 'release' AND o.uuid = $1", [fr.uuid])[0];
      inf.check('SQL: la suelta es de Ana', sr?.actor_admin_id === ctx.h.accounts.a.id, sr);
      await L.captura(a, ctx, 'estado-freno-suelto-1440');
    });

    // ── 11. Salientes: pedir desde el CRM (lo que deja la puerta) y cortar una requested y una dialing ───────────
    await paso('salientes', async () => {
      const can = requiere('canarios');
      const a = S.a;
      inf.must('Ana: pestaña Llamadas', await L.pestana(a, ctx, 'llamadas', SIN_LEYENDO('[data-voz-saliente-nueva]')));
      const n0 = L.valor(ctx, "SELECT count(*) FROM voice_calls WHERE direction = 'outbound'");
      await a.page.click('[data-voz-saliente-nueva]');
      inf.must('el diálogo de pedir una llamada se abre', await L.esperar(a, hayDialogo('[data-saliente-dialogo]')));
      const fd = await L.focoAtrapado(a, 16, 5);
      inf.check('diálogo de pedir una llamada: el foco no sale', fd.ok, fd);
      await L.esperar(a, SIN_LEYENDO(`[data-saliente-canario="${can[0]}"]`));
      // El control es un radio; lo que se lee es su etiqueta (alias, máscara y «canario de voz activo»).
      const opciones = await L.leer(a, `${L.NORMALIZA} return [...document.querySelectorAll('[data-saliente-canario]')].map((e) => n(e.closest('label')?.textContent));`);
      inf.check('el diálogo de pedir una llamada enseña cada canario por su alias y sus 4 dígitos (nunca el número)', opciones.length === 2
        && opciones.some((t) => /Canario de voz 1/.test(t) && /0011/.test(t)) && opciones.some((t) => /Canario de voz 2/.test(t) && /0012/.test(t)) && opciones.every((t) => !/30000000\d\d/.test(t)), opciones);
      await a.page.click(`[data-saliente-canario="${can[0]}"]`);
      // E4-6 (B8-g): con la verificación del arranque, la puerta ya no dice elevenlabs_unverified. Fuera del horario legal
      // (oráculo propio con el fixture de festivos de TEST8-a) se niega con EXACTAMENTE el calendario; dentro, deja pasar.
      const cal = calendarioLegal(new Date());
      // B8-g (`number_unverified`): la puerta exige una conciliación del número de menos de 2 h que cuadre con
      // TWILIO_VOICE_FROM; la de verdad, con el reloj real, antes de pedir la saliente.
      const cn = L.artisan(ctx, ['voice:reconcile', '--numbers', '--json']);
      let numero = null;
      try { numero = JSON.parse(cn.out).number ?? null; } catch { /* sin JSON */ }
      inf.check('voice:reconcile --numbers antes de pedir la saliente: el número importado cuadra con TWILIO_VOICE_FROM', numero?.matchesFrom === true && numero?.result === 'ok', numero);
      const tw0g = await statsTw();
      await enviarDialogo(a, '[data-saliente-enviar]');
      if (cal.fuera) {
        const motivos = await L.esperar(a, `const r = document.querySelector('[data-saliente-rechazo]'); return r ? [...r.querySelectorAll('[data-saliente-motivo]')].map((e) => e.getAttribute('data-saliente-motivo')) : null;`, L.T(20000));
        inf.check(`la puerta (fuera del horario legal) no deja pasar la saliente: el CRM enseña EXACTAMENTE ${cal.motivos.join(', ')} (sin elevenlabs_unverified: E4-6)`, JSON.stringify(motivos) === JSON.stringify(cal.motivos), motivos);
        inf.check('… y no se escribe ninguna fila', L.valor(ctx, "SELECT count(*) FROM voice_calls WHERE direction = 'outbound'") === n0, null);
        await a.page.keyboard.press('Escape');
        await L.esperar(a, sinDialogo, L.T(5000));
      } else {
        // Dentro del horario: pedir desde el CRM → la puerta real (202) → el job en `voice` → el worker RESIDENTE marca.
        const aviso = await L.esperar(a, `const e = document.querySelector('[data-voz-saliente-pedida]'); return e ? e.textContent.replace(/\\s+/g, ' ').trim() : null;`, L.T(20000));
        inf.check('la puerta (dentro del horario legal, proveedor verificado) deja pasar la saliente: el CRM cierra el diálogo y avisa con los 4 dígitos', !!aviso && /0011/.test(aviso) && !/3000000011/.test(aviso)
          && (await L.leer(a, sinDialogo)) === true, aviso);
        const g = L.sql(ctx, "SELECT uuid, status, stamp_state, requested_by_admin_id FROM voice_calls WHERE direction = 'outbound' ORDER BY id DESC LIMIT 1")[0];
        inf.must('SQL: la fila de la puerta, sellada y pedida por Ana', L.valor(ctx, "SELECT count(*) FROM voice_calls WHERE direction = 'outbound'") === n0 + 1 && g?.stamp_state === 'applied'
          && g.requested_by_admin_id === ctx.h.accounts.a.id, g && { status: g.status, sello: g.stamp_state });
        const marcada = await (async () => { for (let i = 0; i < 150; i++) { const st = L.valor(ctx, 'SELECT status FROM voice_calls WHERE uuid = $1', [g.uuid]); if (st !== 'requested') return st; await L.dormir(200); } return 'requested'; })();
        inf.check('el worker residente (reloj real) la marca: dialing', marcada === 'dialing', marcada);
        await L.pestana(a, ctx, 'llamadas', SIN_LEYENDO(`[data-llamada="${g.uuid}"] [data-llamada-colgar]`));
        inf.check('… y el CRM la enseña marcando', (await atr(a, `[data-llamada="${g.uuid}"]`, 'data-estado')) === 'dialing', await atr(a, `[data-llamada="${g.uuid}"]`, 'data-estado'));
        const tw1g = await statsTw();
        await a.page.click(`[data-llamada="${g.uuid}"] [data-llamada-colgar]`);
        inf.must('el diálogo de cortar se abre (la saliente de la puerta)', await L.esperar(a, hayDialogo('[data-colgar-dialogo]')));
        await enviarDialogo(a, '[data-colgar-enviar]');
        await L.esperar(a, sinDialogo, L.T(15000));
        await L.dormir(1500);
        const eg = L.valor(ctx, 'SELECT status FROM voice_calls WHERE uuid = $1', [g.uuid]);
        inf.check('cortar desde el CRM la saliente que pidió el CRM: hung_up con UNA petición a Twilio', eg === 'hung_up' && delta(tw1g, await statsTw()).requests === 1 && delta(tw0g, tw1g).requests >= 1, { estado: eg });
        const convG = L.valor(ctx, 'SELECT provider_conversation_id FROM voice_calls WHERE uuid = $1', [g.uuid]);
        if (convG) await L.sim(ctx, 'elevenlabs', 'emit/post-call', { conversation_id: convG });
        E.salientePuerta = g.uuid;
      }
      // requested (costura de la fila sellada, sin despachar)
      const r1 = L.ayudante(ctx, ['saliente', 'canary', can[0], L.uuid(), String(ctx.h.accounts.a.id), '0']);
      const u1 = r1.call;
      inf.must('costura: una saliente requested sellada', !!u1 && L.valor(ctx, 'SELECT status FROM voice_calls WHERE uuid = $1', [u1]) === 'requested', r1);
      const tw0 = await statsTw();
      await L.pestana(a, ctx, 'llamadas', SIN_LEYENDO(`[data-llamada="${u1}"] [data-llamada-colgar]`));
      await a.page.click(`[data-llamada="${u1}"] [data-llamada-colgar]`);
      inf.must('el diálogo de cortar se abre', await L.esperar(a, hayDialogo('[data-colgar-dialogo]')));
      const fc = await L.focoAtrapado(a, 10, 4);
      inf.check('diálogo de cortar: el foco no sale', fc.ok, fc);
      await enviarDialogo(a, '[data-colgar-enviar]');
      await L.esperar(a, sinDialogo, L.T(15000));
      const e1 = L.valor(ctx, 'SELECT status FROM voice_calls WHERE uuid = $1', [u1]);
      inf.check('cortar una requested: sale de requested sin tocar Twilio', e1 !== 'requested' && delta(tw0, await statsTw()).requests === 0, e1);
      inf.check('… y el CRM lo enseña', (await L.esperar(a, `return document.querySelector('[data-llamada="${u1}"]')?.getAttribute('data-estado') === ${JSON.stringify(e1)};`, L.T(15000))) === true, await atr(a, `[data-llamada="${u1}"]`, 'data-estado'));
      // dialing: la fila sellada, despachada a `voice-e2e` y el worker de UN trabajo con el reloj en el próximo día hábil a
      // las 10:00 de Bogotá (EN_HORARIO, siempre en el futuro)
      const r2 = L.ayudante(ctx, ['saliente', 'canary', can[1], L.uuid(), String(ctx.h.accounts.a.id), '0']);
      const u2 = r2.call;
      L.ayudante(ctx, ['despachar', u2, 'voice-e2e']);
      // El job vuelve a exigir la observación del número de menos de 2 h, medida con SU reloj (B8-g): con el mismo reloj
      // desplazado justo antes, y después otra vez con el real (la puerta sigue con el reloj real).
      const o1 = L.observarNumero(ctx, EN_HORARIO);
      inf.must('la observación del número con el reloj del job cuadra', o1?.policy === 'verified', o1);
      const w = L.artisan(ctx, ['queue:work', 'voice', '--queue=voice-e2e', '--stop-when-empty', '--tries=1', '--timeout=60'],
        { H8_E2E_RELOJ_OBJETIVO: EN_HORARIO, H8_E2E_VENDOR: `${ctx.h.backend}/vendor/autoload.php` }, ctx.h.helpers.reloj);
      const o2 = L.observarNumero(ctx);
      inf.check('… y con el reloj real vuelve a cuadrar', o2?.policy === 'verified', o2);
      const e2 = L.valor(ctx, 'SELECT status FROM voice_calls WHERE uuid = $1', [u2]);
      inf.must('la saliente marca (dialing) con el worker de un trabajo en horario', e2 === 'dialing', { estado: e2, worker: w.code });
      const tw1 = await statsTw();
      await L.pestana(a, ctx, 'llamadas', SIN_LEYENDO(`[data-llamada="${u2}"] [data-llamada-colgar]`));
      await a.page.click(`[data-llamada="${u2}"] [data-llamada-colgar]`);
      inf.must('el diálogo de cortar se abre (dialing)', await L.esperar(a, hayDialogo('[data-colgar-dialogo]')));
      await enviarDialogo(a, '[data-colgar-enviar]');
      await L.esperar(a, sinDialogo, L.T(15000));
      await L.dormir(1500);
      const e3 = L.valor(ctx, 'SELECT status FROM voice_calls WHERE uuid = $1', [u2]);
      const dt = delta(tw1, await statsTw());
      inf.check('cortar una dialing: hung_up con UNA petición a Twilio', e3 === 'hung_up' && dt.requests === 1, { estado: e3, twilio: dt.requests });
      await L.captura(a, ctx, 'llamadas-cortadas-1440');
    });

    // ── 12. El 429 de la telefonía con retry_after (Carla agota su cubo de lectura, 120/min por persona, E9-4) ─────
    await paso('429', async () => {
      // En paralelo (tandas de 40; `php -S` atiende 4 a la vez y el resto espera en su cola): 140 lecturas SECUENCIALES, o
      // en tandas de 20, no caben en la ventana de 60 s del cubo si la máquina va cargada (visto a carga 36–70 el 09-11) y
      // el cubo se renueva antes de agotarse.
      // El cubo cuenta 60 s desde su primera lectura: con la API del banco lenta (4 workers de `php -S`, máquina compartida),
      // 5 tandas fijas de 40 se repartían entre DOS ventanas, cada una por debajo de 120, y no llegaba el 429 (pasada de B-T6
      // del 11-11: 89 + 123 lecturas en dos minutos). Se siguen lanzando tandas hasta el 429, con un tope de tiempo.
      let r = null;
      let lecturas = 0;
      const t0429 = Date.now();
      for (let tanda = 0; r?.status !== 429 && (tanda < 5 || Date.now() - t0429 < L.T(180000)); tanda++) {
        const rs = await Promise.all(Array.from({ length: 40 }, () => L.api(ctx, 'c', 'GET', 'admin/voice/status')));
        lecturas += rs.length;
        r = rs.find((x) => x.status === 429) ?? rs[rs.length - 1];
      }
      const t429 = Date.now();
      inf.must('el limitador de lectura responde 429 con el cuerpo uniforme (code too_many_requests, retry_after) y Retry-After igual', r?.status === 429 && r.json?.code === 'too_many_requests'
        && Number.isInteger(r.json?.retry_after) && r.json.retry_after > 0 && r.json.retry_after <= 60 && String(r.json.retry_after) === String(r.headers?.['retry-after']),
        { status: r?.status, code: r?.json?.code, retry_after: r?.json?.retry_after, header: r?.headers?.['retry-after'], lecturas, segundos: Math.round((t429 - t0429) / 1000) });
      const ra = r.json.retry_after;
      const c = S.c;
      const red0 = c.red.length;
      await L.ir(c, ctx, '/marketing/ultron-studio/voz?pestana=llamadas');
      const msg = await L.esperar(c, `const e = document.querySelector('[data-llamadas-error], #vt-panel-llamadas [role=alert]'); return e ? e.textContent.replace(/\\s+/g, ' ').trim() : null;`, L.T(30000));
      // E9-4 / FE8-3: el CRM enseña el MENSAJE DEL SERVIDOR del 429 (y lee retry_after sin gastar la clave). Los
      // segundos no forman parte de ese mensaje: si la pantalla los dice o no, queda en el detalle (observación).
      inf.check('el CRM enseña el aviso del 429 con el mensaje del servidor', !!msg && msg.includes(String(r.json.message || '').replace(/\s+/g, ' ').trim()),
        { msg: String(msg).slice(0, 200), servidor: r.json.message, retry_after: ra, diceLosSegundos: /\d/.test(String(msg)) });
      const vistas = c.red.slice(red0).filter((x) => x.method === 'GET' && x.path.startsWith('/api/admin/voice/'));
      inf.check('el navegador recibió el 429 de la API (registro de red, con Retry-After)', vistas.some((x) => x.status === 429 && Number(x.retryAfter) > 0),
        vistas.map((x) => `${x.path} ${x.status ?? '?'} ${x.retryAfter ?? ''}`).slice(0, 8));
      await L.dormir(4000);
      const llamadas = c.red.slice(red0).filter((x) => x.method === 'GET' && x.path.startsWith('/api/admin/voice/calls'));
      inf.check('sin reintentos automáticos mientras dura la espera', llamadas.length <= 1, llamadas.length);
      await L.captura(c, ctx, 'llamadas-429-carla-1440');
      // El cubo de Carla se vacía antes de seguir (las transversales la usan): espera lo que dijo el servidor.
      const resta = ra * 1000 - (Date.now() - t429) + 1500;
      if (resta > 0) await L.dormir(resta);
      const otra = await L.api(ctx, 'c', 'GET', 'admin/voice/status');
      inf.check('pasado retry_after, Carla vuelve a leer (200)', otra.status === 200, otra.status);
    });

    // ── 13. «Firefox»: sin la restricción sampleRate, ni sesión ni gasto ──────────────────────────────────────────
    await paso('firefox', async () => {
      const [v, p] = [requiere('version'), requiere('perfil')];
      const f = await sesionDe('a');
      await f.page.evaluateOnNewDocument(() => {
        const md = navigator.mediaDevices;
        if (md) {
          const o = md.getSupportedConstraints.bind(md);
          md.getSupportedConstraints = () => ({ ...o(), sampleRate: false });
          const g = md.getUserMedia.bind(md);
          window.__ibMic = 0;
          md.getUserMedia = (...x) => { window.__ibMic++; return g(...x); };
        }
      });
      inf.must('«Firefox» (Ana): pestaña Laboratorio', await L.pestana(f, ctx, 'laboratorio', SIN_LEYENDO('[data-laboratorio-abrir]')));
      await L.elegir(f, '[data-laboratorio-version]', v);
      await L.elegir(f, '[data-laboratorio-perfil]', p);
      const n0 = L.valor(ctx, 'SELECT count(*) FROM ultron_studio_voice_lab_sessions');
      const antes = await statsEl();
      const red0 = f.red.length;
      await f.page.click('[data-laboratorio-abrir-enviar]');
      const err = await L.esperar(f, `const e = document.querySelector('[data-laboratorio-error-abrir]'); return e ? e.textContent.trim() : null;`, L.T(15000));
      inf.check('el CRM dice que este navegador no puede y que no se gastó nada', !!err && /no se abrió ninguna sesión/i.test(err), err);
      await L.dormir(1500);
      inf.check('ninguna petición de abrir sesión, ni micrófono pedido', f.red.slice(red0).filter((x) => x.method === 'POST' && x.path.includes('voice-lab/sessions')).length === 0 && (await L.leer(f, 'return window.__ibMic;')) === 0, null);
      inf.check('ninguna sesión en la base ni petición a ElevenLabs', L.valor(ctx, 'SELECT count(*) FROM ultron_studio_voice_lab_sessions') === n0 && delta(antes, await statsEl()).requests === 0, null);
      await L.captura(f, ctx, 'laboratorio-firefox-1440');
    });

    // ── 14. Transversales en cada pantalla, con cada cuenta ──────────────────────────────────────────────────────
    await paso('transversal', async () => {
      for (const q of ['a', 'b', 'c']) {
        const s = S[q];
        if (!s) continue;
        for (const t of PESTANAS) {
          for (const w of [390, 1440]) {
            await s.page.setViewport({ width: w, height: 900 });
            const ok = await L.pestana(s, ctx, t);
            const d = await L.desborde(s);
            inf.check(`${q} · ${t} · ${w} px: se pinta y sin desplazamiento horizontal`, ok && d.scrollWidth <= d.ancho && d.fuera.length === 0, d);
            if (w === 1440) {
              const bar = await L.barrerPagina(s, inf);
              const pag = Object.entries(bar.consolaPorFuente).filter(([k]) => k !== 'network').flatMap(([, v]) => v);
              inf.check(`${q} · ${t}: ningún teléfono completo, URL firmada ni secreto en DOM, almacenamiento, URL ni consola de la página`, bar.dom.length === 0 && bar.almacenamiento.length === 0 && bar.url.length === 0 && pag.length === 0 && !bar.tokenEnDom, bar);
              const ih = await L.innerHtml(s);
              inf.check(`${q} · ${t}: ninguna escritura de innerHTML/outerHTML/insertAdjacentHTML/document.write`, ih.length === 0, ih.slice(0, 3));
              if (t === 'estado') {
                // L9-79 (la ola): «Estado de activación» encima de «La línea», con las seis funciones de la voz. Cada cuenta
                // ve las seis con un estado (o «Sin estado» si no puede leerlo) y ninguna se queda en «Leyendo…».
                const act = await L.leer(s, `return [...document.querySelectorAll('[data-activacion-panel="voz"] [data-activacion]')].map((e) => ({ id: e.getAttribute('data-activacion'), estado: e.getAttribute('data-estado'), lectura: e.getAttribute('data-lectura'), leyendo: !!e.querySelector('[data-activacion-leyendo]') }));`);
                inf.check(`${q} · estado: «Estado de activación» con las 6 funciones de la voz, cada una con su estado (ninguna se queda en «Leyendo…»)`,
                  act.length === 6 && act.every((r) => !r.leyendo && (!!r.estado || r.lectura === 'unreadable')), act);
              }
            }
            await L.captura(s, ctx, `${q}-${t}-${w}`);
          }
        }
        await s.page.setViewport({ width: 1440, height: 900 });
      }
    });
  } finally {
    // ── Cierre del navegador: errores, 5xx, fugas por la red y hosts fuera de 127.0.0.1 ───────────────────────────
    inf.en('navegador');
    try {
      L.guardarRed(ctx, todas);
      const errores = todas.flatMap((s) => s.errores.map((e) => `${s.quien}: ${e}`));
      inf.check('ningún error de JavaScript sin capturar en ninguna sesión', errores.length === 0, errores.slice(0, 5));
      const cinco = todas.flatMap((s) => s.api5xx.map((e) => `${s.quien}: ${e}`));
      inf.check('ninguna respuesta 5xx de la API', cinco.length === 0, cinco.slice(0, 5));
      const fr = todas.flatMap((s) => s.fugasRed.map((f) => ({ quien: s.quien, ...f })));
      inf.check('red: ningún teléfono completo, secreto, URL firmada ni id completo del agente en lo que el navegador envía o recibe (salvo donde viaja por diseño)', fr.length === 0, fr.slice(0, 6));
      const hosts = {};
      for (const s of todas) for (const r of s.red) hosts[`${r.kind}:${r.scheme}://${r.host}`] = (hosts[`${r.kind}:${r.scheme}://${r.host}`] || 0) + 1;
      const fuera = Object.keys(hosts).filter((h) => !/:\/\/(127\.0\.0\.1|localhost)(:\d+)?$/.test(h));
      const fuentes = ['http:https://fonts.googleapis.com', 'http:https://fonts.gstatic.com'];
      inf.check('red del navegador: ninguna petición fuera de 127.0.0.1 salvo las fuentes de Google del index.html (anteriores al H8; no resuelven)', fuera.every((h) => fuentes.includes(h)), { hosts });
      const sockets = Object.keys(hosts).filter((h) => h.startsWith('websocket:'));
      inf.check('red del navegador: el WebSocket del laboratorio solo contra el simulador, y solo con TLS (wss)', sockets.length > 0 && sockets.every((h) => h === `websocket:wss://127.0.0.1:${ctx.h.ports.elevenlabs}`), sockets);
      fs.writeFileSync(path.join(ctx.red, 'hosts-navegador.json'), JSON.stringify(hosts, null, 1));
      if (E.consolaChromeLab) fs.writeFileSync(path.join(ctx.red, 'consola-laboratorio-por-fuente.json'), JSON.stringify(E.consolaChromeLab, null, 1));
    } catch (e) {
      inf.check('cierre del navegador sin excepción', false, String(e).slice(0, 200));
    }
    await browser.close().catch(() => {});
    process.exitCode = inf.fin() ? 0 : 1;
  }
})();
