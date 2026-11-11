#!/usr/bin/env node
'use strict';
/**
 * Sirve el CRM COMPILADO del E2E del H9 (`ng build --configuration development` de la copia `git archive` de su commit:
 * el `environment.ts` de desarrollo, que apunta la API a 127.0.0.1:8080, el proxy del arnés). Sustituye a `ng serve`
 * porque, con la máquina cargada, la primera compilación del servidor de desarrollo pasaba de 10 minutos en cada vuelta;
 * el resultado compilado se reutiliza mientras no cambie el commit del CRM.
 *
 * Solo 127.0.0.1; nunca sale de su directorio (una ruta que se escapa recibe 404); lo que no es un fichero recibe
 * `index.html` (las rutas de Angular). Sin caché: cada vuelta ve lo compilado.
 *
 * Uso: node servidor-crm.cjs --dir <dist/browser> --port <n>
 */
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');

let dir = null;
let port = null;
for (let i = 2; i < process.argv.length; i += 2) {
  if (process.argv[i] === '--dir') dir = process.argv[i + 1];
  else if (process.argv[i] === '--port') port = Number(process.argv[i + 1]);
}
if (!dir || !fs.existsSync(path.join(dir, 'index.html')) || !Number.isInteger(port)) {
  console.error('servidor-crm: uso: node servidor-crm.cjs --dir <directorio con index.html> --port <n>');
  process.exit(2);
}
const raiz = fs.realpathSync(dir);
const MIME = {
  '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.mjs': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8',
  '.json': 'application/json', '.map': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.ico': 'image/x-icon',
  '.woff': 'font/woff', '.woff2': 'font/woff2', '.txt': 'text/plain; charset=utf-8', '.webmanifest': 'application/manifest+json',
};

http.createServer((req, res) => {
  let ruta;
  try { ruta = decodeURIComponent(new URL(req.url, 'http://x').pathname); } catch { res.writeHead(400).end(); return; }
  let f = path.resolve(raiz, '.' + ruta);
  if (f !== raiz && !f.startsWith(raiz + path.sep)) { res.writeHead(404).end(); return; }
  if (!fs.existsSync(f) || fs.statSync(f).isDirectory()) f = path.join(raiz, 'index.html');
  res.writeHead(200, { 'content-type': MIME[path.extname(f)] ?? 'application/octet-stream', 'cache-control': 'no-store' });
  fs.createReadStream(f).pipe(res);
}).listen(port, '127.0.0.1', () => console.log(`servidor-crm: http://127.0.0.1:${port} → ${raiz}`));
