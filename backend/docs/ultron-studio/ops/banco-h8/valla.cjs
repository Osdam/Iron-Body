#!/usr/bin/env node
'use strict';
/**
 * VALLA DE RED del banco del Hito 8 (protección 3: «ninguna llamada real en desarrollo ni en el banco»). Es la del
 * E2E del CRM (`e2e/ultron-studio/fence.cjs`), aquí en el backend para que el banco no dependa del otro repo.
 *
 * Un proxy HTTP que no deja pasar nada y lo apunta todo. `ensayar.sh` arranca la API, el worker y cada orden de
 * artisan del banco con `http_proxy`/`https_proxy` (y sus mayúsculas) apuntando aquí y `no_proxy=127.0.0.1,localhost`:
 * cualquier petición HTTP(S) a un destino que no sea esta máquina (ElevenLabs, Twilio, OpenAI, Meta…) llega aquí en
 * lugar de a Internet, se rechaza (403) y queda una línea con el HOST de destino: nunca la ruta, la consulta, las
 * cabeceras ni el cuerpo. El cierre de un ensayo exige que no haya más hosts que las sondas de la propia valla.
 *
 * Una petición DIRECTA (ruta relativa, no de proxy) a `/__stats` o `/__requests?since=N` devuelve el estado; nada
 * más se atiende directamente. Si llega tráfico de 127.0.0.1 o localhost POR la valla, `no_proxy` no se respeta: se
 * marca `local_through_fence` (el ensayo de la valla lo comprueba).
 *
 * Límite honesto: cubre los clientes que respetan las variables de proxy (Guzzle/cURL, que es el `Http::` de
 * Laravel). Un socket crudo (SMTP, Redis…) no pasaría por aquí; en el banco el correo va a `log` y no hay Redis.
 *
 * Uso: node valla.cjs --log <registro.jsonl> --port <n|0>
 */
const http = require('node:http');
const fs = require('node:fs');

let log = null;
let port = null;
for (let i = 2; i < process.argv.length; i += 2) {
  if (process.argv[i] === '--log') log = process.argv[i + 1];
  else if (process.argv[i] === '--port') port = Number(process.argv[i + 1]);
  else {
    console.error('valla: uso: node valla.cjs --log <registro.jsonl> --port <n|0>');
    process.exit(2);
  }
}
if (!log || !Number.isInteger(port) || port < 0 || port > 65535) {
  console.error('valla: uso: node valla.cjs --log <registro.jsonl> --port <n|0>');
  process.exit(2);
}

const registro = fs.createWriteStream(log, { flags: 'a', mode: 0o600 });
const entradas = [];
const stats = { blocked: 0, byHost: {}, byKind: {}, localThroughFence: 0 };
const local = (host) => /^(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/i.test(host);

function anotar(kind, host) {
  const h = String(host ?? '?').slice(0, 200);
  const e = { n: entradas.length, ts: new Date().toISOString(), kind, host: h, local_through_fence: local(h) };
  entradas.push(e);
  stats.blocked++;
  stats.byHost[h] = (stats.byHost[h] ?? 0) + 1;
  stats.byKind[kind] = (stats.byKind[kind] ?? 0) + 1;
  if (e.local_through_fence) stats.localThroughFence++;
  registro.write(JSON.stringify(e) + '\n');
}

const servidor = http.createServer((req, res) => {
  // Petición directa (no de proxy): solo el estado.
  if (req.url.startsWith('/')) {
    const u = new URL(req.url, 'http://127.0.0.1');
    if (req.method === 'GET' && u.pathname === '/__stats') {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      return res.end(JSON.stringify(stats));
    }
    if (req.method === 'GET' && u.pathname === '/__requests') {
      const desde = Math.max(0, Number.parseInt(u.searchParams.get('since') ?? '0', 10) || 0);
      res.writeHead(200, { 'Content-Type': 'application/json' });
      return res.end(JSON.stringify({ total: entradas.length, entries: entradas.slice(desde) }));
    }
    res.writeHead(404, { 'Content-Type': 'text/plain' });
    return res.end('valla: solo /__stats y /__requests\n');
  }
  let host = req.headers.host ?? '?';
  try {
    host = new URL(req.url).host || host;
  } catch {
    /* se queda la cabecera Host */
  }
  anotar('http', host);
  res.writeHead(403, { 'Content-Type': 'text/plain' });
  res.end('banco-h8 valla: salida a la red bloqueada\n');
});

servidor.on('connect', (req, socket) => {
  anotar('connect', req.url);
  socket.end('HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\n\r\n');
});

servidor.listen(port, '127.0.0.1', () => console.log(JSON.stringify({ sim: 'valla', port: servidor.address().port, pid: process.pid })));
const parar = () => servidor.close(() => registro.end(() => process.exit(0)));
process.on('SIGTERM', parar);
process.on('SIGINT', parar);
