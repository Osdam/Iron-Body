#!/usr/bin/env node
'use strict';

// Offline wrapper over the PHP source of truth. Never calls n8n or OpenAI.
const path = require('path');
const {spawnSync} = require('child_process');
const args = process.argv.slice(2);
if (args.length < 1 || args.length > 2 || args.slice(1).some(value => !value.startsWith('--s2='))) {
  process.stderr.write('Uso: node verificar-flujo-s3.cjs <export-local-s3.json> [--s2=export-local-s2.json]\n');
  process.exitCode = 2;
} else {
  const result = spawnSync('php', [path.join(__dirname, 'generar-flujo-s3.php'), '--verify=' + args[0], ...args.slice(1)], {encoding:'utf8', maxBuffer:1024*1024});
  if (result.error) {
    process.stderr.write('No se pudo ejecutar el comparador local de PHP.\n');
    process.exitCode = 2;
  } else {
    process.stdout.write(result.stdout || '');
    process.stderr.write(result.stderr || '');
    process.exitCode = result.status === null ? 2 : result.status;
  }
}
