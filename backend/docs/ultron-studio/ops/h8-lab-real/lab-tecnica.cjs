// SESIÓN TÉCNICA REAL DEL LABORATORIO HABLADO (lead, 11-11-2026).
// Abre el CRM de PRODUCCIÓN en un Chrome sin cabeza con un micrófono falso que reproduce un WAV (voz sintética de
// macOS), elige la versión y el perfil, abre UNA sesión del laboratorio, deja hablar al agente, cuelga y lee cómo queda
// la sesión. Es audio real por el camino real (cliente oficial → WebSocket de ElevenLabs → agente compilado), no una
// simulación de texto. NUNCA escribe en la salida la sesión del CRM, la URL firmada ni el id completo del agente.
//   node lab-tecnica.cjs <sesion.json> <audio.wav> <dir de salida> <versionUuid> <profileUuid> [segundos en vivo]
'use strict';
const fs = require('fs');
const path = require('path');
const [, , F_SESION, F_AUDIO, DIR, VERSION, PERFIL, SEG = '70'] = process.argv;
const CRM = 'https://ironbodyneiva.cloud';
const API = 'https://api.ironbodyneiva.cloud/api';
const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const puppeteer = require('/Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/ultron-h8/Iron-Body_Front/node_modules/puppeteer-core');
const dormir = (ms) => new Promise((r) => setTimeout(r, ms));
const t0 = Date.now();
const seg = () => ((Date.now() - t0) / 1000).toFixed(1);
const inf = { inicio: new Date().toISOString(), pasos: [], red: [], ws: { creados: [], cerrados: 0, recibidos: {}, enviados: {}, bytesAudioRecibidos: 0, bytesAudioEnviados: 0 }, dialogo: [], linea: [], consola: [], errores: [] };
const paso = (nombre, ok, dato) => { inf.pasos.push({ t: seg(), nombre, ok: !!ok, dato: dato ?? null }); console.log(`${ok ? 'OK   ' : 'FALLO'} [${seg()} s] ${nombre}${dato !== undefined && dato !== null ? ' · ' + JSON.stringify(dato).slice(0, 260) : ''}`); return !!ok; };
const sinValores = (u) => { try { const x = new URL(u); const k = [...new Set([...x.searchParams.keys()])].sort(); return `${x.protocol}//${x.host}${x.pathname}${k.length ? '?' + k.map((c) => `${c}=…`).join('&') : ''}`; } catch { return '?'; } };

(async () => {
  const { token, user } = JSON.parse(fs.readFileSync(F_SESION, 'utf8'));
  fs.mkdirSync(DIR, { recursive: true });
  const api = async (metodo, ruta, cuerpo) => {
    const r = await fetch(`${API}/${ruta}`, { method: metodo, headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${token}` }, body: cuerpo ? JSON.stringify(cuerpo) : undefined });
    let j = null; try { j = await r.json(); } catch { /* sin cuerpo */ }
    return { status: r.status, json: j };
  };
  const resumen = (s) => s && ({ uuid8: String(s.uuid || '').slice(0, 8), status: s.status, endReason: s.endReason ?? null, mode: s.mode ?? s.agent?.mode ?? null, metrics: s.metrics ?? null, verdict: s.verdict ?? null, actions: s.actions ?? null, claves: Object.keys(s).sort() });

  // Antes: estado del laboratorio y sesiones que ya hubiera.
  const st0 = await api('GET', 'admin/ultron-studio/voice-lab/status');
  paso('API: voice-lab/status', st0.status === 200, st0.json?.data ? { claves: Object.keys(st0.json.data).sort(), enabled: st0.json.data.enabled ?? st0.json.data.available ?? null, mode: st0.json.data.mode ?? null, reasons: st0.json.data.reasons ?? st0.json.data.locks ?? null } : st0.status);
  fs.writeFileSync(path.join(DIR, 'status-antes.json'), JSON.stringify(st0.json, null, 1));
  const l0 = await api('GET', 'admin/ultron-studio/voice-lab/sessions?page=1&per_page=20');
  const antes = (l0.json?.data?.data || []).map((x) => x.uuid);
  paso('API: sesiones antes', l0.status === 200, { total: antes.length });

  const browser = await puppeteer.launch({
    executablePath: CHROME, headless: true, defaultViewport: { width: 1440, height: 900 },
    args: ['--no-first-run', '--no-default-browser-check', '--disable-extensions', '--disable-sync', '--disable-default-apps', '--no-pings',
      '--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', `--use-file-for-fake-audio-capture=${F_AUDIO}`,
      // Sin esto, el servicio de audio de Chrome (en su caja de arena) no puede leer el WAV y el micrófono falso da silencio.
      '--disable-features=AudioServiceSandbox,AudioServiceOutOfProcess',
      '--autoplay-policy=no-user-gesture-required', '--window-size=1440,900'],
  });
  let sesionUuid = null;
  try {
    const bctx = await browser.createBrowserContext();
    await bctx.overridePermissions(CRM, ['microphone']);
    const page = await bctx.newPage();
    const cdp = await page.createCDPSession();
    await cdp.send('Network.enable');
    await cdp.send('Log.enable');
    const pet = new Map();
    cdp.on('Network.requestWillBeSent', (e) => { const u = sinValores(e.request.url); if (/^(data|blob):/.test(e.request.url)) return; const l = { t: seg(), m: e.request.method, u }; pet.set(e.requestId, l); if (!/\.(js|css|woff2?|png|svg|ico|webp)(\?|$)/.test(u)) inf.red.push(l); });
    cdp.on('Network.responseReceived', (e) => { const l = pet.get(e.requestId); if (l) l.s = e.response.status; });
    cdp.on('Network.loadingFailed', (e) => { const l = pet.get(e.requestId); if (l) l.fallo = String(e.errorText || '').slice(0, 80); });
    cdp.on('Network.webSocketCreated', (e) => inf.ws.creados.push({ t: seg(), u: sinValores(e.url) }));
    cdp.on('Network.webSocketClosed', () => { inf.ws.cerrados++; inf.linea.push({ t: seg(), ws: 'cerrado' }); });
    cdp.on('Network.webSocketFrameError', (e) => inf.errores.push(`ws: ${String(e.errorMessage || '').slice(0, 160)}`));
    cdp.on('Network.webSocketHandshakeResponseReceived', (e) => inf.linea.push({ t: seg(), ws: 'handshake', status: e.response?.status }));
    const contar = (bolsa, datos, enviado) => {
      let tipo = 'no-json';
      try {
        const j = JSON.parse(datos);
        if (enviado && typeof j.user_audio_chunk === 'string') { tipo = 'user_audio_chunk'; inf.ws.bytesAudioEnviados += Math.floor(j.user_audio_chunk.length * 0.75); }
        else tipo = String(j.type || Object.keys(j)[0] || '?');
        if (!enviado) {
          if (tipo === 'audio') inf.ws.bytesAudioRecibidos += Math.floor(String(j.audio_event?.audio_base_64 || '').length * 0.75);
          if (tipo === 'agent_response') inf.dialogo.push({ t: seg(), quien: 'agente', texto: String(j.agent_response_event?.agent_response || '').slice(0, 600) });
          if (tipo === 'agent_response_correction') inf.dialogo.push({ t: seg(), quien: 'agente (corregido al interrumpir)', texto: String(j.agent_response_correction_event?.corrected_agent_response || '').slice(0, 600) });
          if (tipo === 'user_transcript') inf.dialogo.push({ t: seg(), quien: 'persona', texto: String(j.user_transcription_event?.user_transcript || '').slice(0, 600) });
          if (tipo === 'conversation_initiation_metadata') { const m = j.conversation_initiation_metadata_event || {}; inf.ws.inicio = { t: seg(), conversacion: '…' + String(m.conversation_id || '').slice(-4), salida: m.agent_output_audio_format, entrada: m.user_input_audio_format }; }
          if (tipo === 'client_tool_call' || tipo === 'agent_tool_response') inf.dialogo.push({ t: seg(), quien: tipo, texto: JSON.stringify(j).slice(0, 200) });
        }
      } catch { /* binario o no JSON */ }
      bolsa[tipo] = (bolsa[tipo] || 0) + 1;
    };
    cdp.on('Network.webSocketFrameReceived', (e) => contar(inf.ws.recibidos, e.response?.payloadData || '', false));
    cdp.on('Network.webSocketFrameSent', (e) => contar(inf.ws.enviados, e.response?.payloadData || '', true));
    page.on('console', (m) => inf.consola.push(`${m.type()}: ${m.text().slice(0, 240)}`));
    cdp.on('Log.entryAdded', (e) => { if (e.entry.level === 'error' || e.entry.level === 'warning') inf.consola.push(`chrome/${e.entry.source}/${e.entry.level}: ${String(e.entry.text || '').slice(0, 240)}`); });
    page.on('pageerror', (e) => inf.errores.push(String(e.message || e).slice(0, 300)));
    await page.evaluateOnNewDocument((t, u) => { try { sessionStorage.setItem('auth_token', t); sessionStorage.setItem('auth_user', u); sessionStorage.setItem('auth_session_type', 'session'); } catch { /* sin almacenamiento */ } }, token, JSON.stringify(user));
    const leer = async (expr) => { const r = await cdp.send('Runtime.evaluate', { expression: `(() => { ${expr} })()`, returnByValue: true, awaitPromise: true, userGesture: false }); if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description?.slice(0, 200) || r.exceptionDetails.text); return r.result.value; };
    const esperar = async (expr, ms = 20000, cada = 250) => { const fin = Date.now() + ms; let v; do { try { v = await leer(expr); } catch { v = undefined; } if (v) return v; await dormir(cada); } while (Date.now() < fin); return v; };
    const N = "const n = (t) => String(t || '').replace(/[\\uE500\\u00a0]/g, ' ').replace(/\\s+/g, ' ').trim();";
    const texto = (sel) => leer(`${N} const e = document.querySelector(${JSON.stringify(sel)}); return e ? n(e.innerText || e.textContent) : null;`);
    const atr = (sel, a) => leer(`const e = document.querySelector(${JSON.stringify(sel)}); return e ? e.getAttribute(${JSON.stringify(a)}) : null;`);
    const foto = async (nombre) => { try { await page.screenshot({ path: path.join(DIR, `${nombre}.png`), fullPage: true }); } catch (e) { inf.errores.push(`captura ${nombre}: ${String(e.message).slice(0, 80)}`); } };

    await page.goto(`${CRM}/marketing/ultron-studio/voz?pestana=laboratorio`, { waitUntil: 'domcontentloaded', timeout: 120000 });
    const panel = await esperar("return !!document.querySelector('#vt-panel-laboratorio:not([hidden])') && document.querySelector('[data-voz-pestana=\"laboratorio\"]')?.getAttribute('aria-selected') === 'true';", 90000);
    if (!paso('CRM de producción: pestaña Laboratorio pintada', panel, panel ? null : { url: sinValores(page.url()), titulo: await page.title() })) { await foto('00-no-pinta'); throw new Error('la pestaña no pinta'); }
    await esperar("return ![...document.querySelectorAll('#vt-panel-laboratorio [role=status], #vt-panel-laboratorio .lab-empty')].some((e) => /^Leyendo/.test((e.textContent || '').trim()));", 45000);
    await esperar("return !!document.querySelector('[data-laboratorio-estado]');", 30000);
    const disp = [await atr('[data-laboratorio-estado]', 'data-disponible'), await atr('[data-laboratorio-estado]', 'data-modo')];
    paso('laboratorio disponible en modo elevenlabs', disp[0] === 'si' && disp[1] === 'elevenlabs', { disponible: disp[0], modo: disp[1], estado: await texto('[data-laboratorio-estado]'), cerrojos: await texto('[data-laboratorio-cerrojos]') });
    paso('se ven la reserva, lo que queda hoy y el cupo', true, { reserva: await texto('[data-laboratorio-reserva]'), queda: await texto('[data-laboratorio-queda]'), cupo: await texto('[data-laboratorio-cupo]') });
    await foto('01-laboratorio-antes');
    if (disp[0] !== 'si') throw new Error('el laboratorio no está disponible');

    for (const [sel, val, que] of [['[data-laboratorio-version]', VERSION, 'versión'], ['[data-laboratorio-perfil]', PERFIL, 'perfil']]) {
      await page.waitForSelector(sel, { visible: true, timeout: 30000 });
      await page.waitForSelector(`${sel} option[value=${JSON.stringify(val)}]`, { timeout: 30000 });
      await page.select(sel, val);
      const elegido = await leer(`const e = document.querySelector(${JSON.stringify(sel)}); return e ? { v: e.value, t: e.selectedOptions[0]?.textContent.trim().slice(0, 80) } : null;`);
      if (!paso(`elegida la ${que}`, elegido?.v === val, elegido?.t)) throw new Error(`no se pudo elegir la ${que}`);
    }
    await dormir(800);
    await foto('02-elegidos');
    const boton = await page.waitForSelector('[data-laboratorio-abrir-enviar]', { visible: true, timeout: 15000 });
    paso('botón «Abrir la sesión»', true, { texto: await texto('[data-laboratorio-abrir-enviar]'), deshabilitado: await leer("return document.querySelector('[data-laboratorio-abrir-enviar]').disabled;") });
    await boton.click();
    const tAbrir = Date.now();
    const viva = await esperar("return document.querySelector('[data-laboratorio-vivo]')?.getAttribute('data-estado') || (document.querySelector('[data-laboratorio-error-abrir]') ? 'error:' + document.querySelector('[data-laboratorio-error-abrir]').textContent.trim() : null);", 60000);
    if (!paso('la sesión se abre (franja en vivo)', !!viva && !String(viva).startsWith('error:'), { estado: viva, tardo_ms: Date.now() - tAbrir })) { await foto('03-no-abre'); throw new Error('no abre'); }
    const conectada = await esperar("const e = document.querySelector('[data-laboratorio-vivo]')?.getAttribute('data-estado'); return e === 'connected' ? 'connected' : (e === 'failed' || e === 'ended' ? 'fin:' + e : null);", 45000);
    paso('conversación CONECTADA con el agente (WebSocket real)', conectada === 'connected', { estado: conectada, conexion: await texto('[data-laboratorio-conexion]'), tardo_ms: Date.now() - tAbrir, ws: inf.ws.creados });
    await foto('04-en-vivo');
    const l1 = await api('GET', 'admin/ultron-studio/voice-lab/sessions?page=1&per_page=20');
    const nueva = (l1.json?.data?.data || []).find((x) => !antes.includes(x.uuid));
    sesionUuid = nueva?.uuid || null;
    paso('API: la sesión nueva existe en el servidor', !!nueva, resumen(nueva));

    if (conectada === 'connected') {
      // En vivo: quién habla, medio segundo a medio segundo, hasta SEG segundos o hasta que termine sola.
      const fin = Date.now() + Number(SEG) * 1000;
      let ultimo = '';
      while (Date.now() < fin) {
        const e = await leer("const v = document.querySelector('[data-laboratorio-vivo]'); return v ? v.getAttribute('data-estado') + '|' + (document.querySelector('.vl-franja')?.getAttribute('data-habla') || '?') + '|' + (document.querySelector('[data-laboratorio-reloj]')?.textContent.trim() || '') : 'sin-franja';").catch(() => 'error');
        const [estado, habla] = String(e).split('|');
        if (`${estado}|${habla}` !== ultimo) { ultimo = `${estado}|${habla}`; inf.linea.push({ t: seg(), estado, habla }); }
        if (estado !== 'connected') break;
        await dormir(500);
      }
      await foto('05-antes-de-colgar');
      const sigue = await atr('[data-laboratorio-vivo]', 'data-estado');
      if (sigue === 'connected') {
        const colgar = await page.$('[data-laboratorio-colgar]');
        if (colgar) { await colgar.click(); paso('«Colgar» pulsado', true, null); } else paso('«Colgar» no está', false, null);
      } else paso('la conversación terminó sola antes de colgar', true, { estado: sigue, conexion: await texto('[data-laboratorio-conexion]') });
      await esperar("const e = document.querySelector('[data-laboratorio-vivo]')?.getAttribute('data-estado'); return !e || e === 'ended' || e === 'failed';", 20000);
      paso('tras colgar', true, { estado: await atr('[data-laboratorio-vivo]', 'data-estado'), conexion: await texto('[data-laboratorio-conexion]'), wsCerrados: inf.ws.cerrados });
      await foto('06-tras-colgar');
    }

    // El cierre por el informe del proveedor (post-llamada firmada de ElevenLabs): hasta 3 minutos.
    let ultima = null;
    if (sesionUuid) {
      const hasta = Date.now() + 180000;
      while (Date.now() < hasta) {
        const d = await api('GET', `admin/ultron-studio/voice-lab/sessions/${sesionUuid}`);
        ultima = d.json?.data ?? null;
        if (ultima && ultima.status !== 'open') break;
        await dormir(5000);
      }
      paso('la sesión se CIERRA por el informe del proveedor (post-llamada)', !!ultima && ultima.status === 'ended' && ultima.endReason === 'provider_report', resumen(ultima));
      fs.writeFileSync(path.join(DIR, 'sesion-final.json'), JSON.stringify(ultima, (k, v) => (typeof v === 'string' && /^agent_[a-z0-9]{12,}$/i.test(v) ? '…' + v.slice(-4) : v), 1));
      await page.goto(`${CRM}/marketing/ultron-studio/voz?pestana=laboratorio`, { waitUntil: 'domcontentloaded', timeout: 120000 });
      await esperar(`return !!document.querySelector('li[data-sesion="${sesionUuid}"]');`, 60000);
      paso('el CRM enseña la sesión en la lista', true, { estado: await atr(`li[data-sesion="${sesionUuid}"]`, 'data-estado'), cuentas: await texto(`li[data-sesion="${sesionUuid}"] [data-sesion-cuentas]`), fila: (await texto(`li[data-sesion="${sesionUuid}"]`) || '').slice(0, 300) });
      await foto('07-lista-final');
    }
    const st1 = await api('GET', 'admin/ultron-studio/voice-lab/status');
    fs.writeFileSync(path.join(DIR, 'status-despues.json'), JSON.stringify(st1.json, null, 1));
  } catch (e) {
    paso('EXCEPCIÓN', false, String(e.message || e).slice(0, 300));
  } finally {
    await browser.close().catch(() => {});
  }
  inf.sesionUuid = sesionUuid;
  inf.fin = new Date().toISOString();
  const txt = JSON.stringify(inf, null, 1);
  if (txt.includes(token) || /conversation_signature=[A-Za-z0-9]/.test(txt)) { console.log('FALLO  el informe llevaría la sesión o la URL firmada: NO se escribe'); process.exit(3); }
  fs.writeFileSync(path.join(DIR, 'informe.json'), txt);
  console.log('\n== WebSocket ==\n' + JSON.stringify(inf.ws, null, 1));
  console.log('\n== Diálogo ==');
  for (const d of inf.dialogo) console.log(`[${d.t} s] ${d.quien}: ${d.texto}`);
  console.log('\n== Línea de tiempo ==\n' + inf.linea.map((l) => JSON.stringify(l)).join('\n'));
  if (inf.errores.length) console.log('\n== Errores ==\n' + inf.errores.join('\n'));
  const fallos = inf.pasos.filter((p) => !p.ok);
  console.log(`\n${inf.pasos.length - fallos.length} OK · ${fallos.length} FALLO`);
  process.exit(fallos.length ? 1 : 0);
})();
