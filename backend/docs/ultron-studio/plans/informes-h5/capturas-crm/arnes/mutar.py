#!/usr/bin/env python3
"""Mutaciones del paso 4 (H5, CRM): cada una cambia UNA cosa del código de
producción, corre los specs que deberían tumbarla y restaura el fichero.

La restauración se comprueba con cmp contra la copia y con sha256 antes/después.
Uso: python3 -I mutar.py <raíz del CRM> <directorio de salida>
"""
import hashlib
import shutil
import subprocess
import sys
from pathlib import Path

RAIZ = Path(sys.argv[1])
SALIDA = Path(sys.argv[2])
SALIDA.mkdir(parents=True, exist_ok=True)
M = 'src/app/modules/ultron-studio/'

MUTACIONES = [
    ('M1 enviar sin reconocer todo lo sin efecto', 'studio-publication-dialog.ts',
     "    if (this.faltan() > 0) return this.faltan() === 1 ?",
     "    if (false) return this.faltan() === 1 ?",
     ['studio-publication-dialog.spec.ts']),
    ('M2 no borrar lo reconocido con otra vista previa', 'studio-publication-dialog.ts',
     "        this.huellaVista = h;\n        this.reconocidos.set(new Set());",
     "        this.huellaVista = h;",
     ['studio-publication-dialog.spec.ts']),
    ('M3 enviar la huella de contenido en vez de previewHash', 'studio-publication-dialog.ts',
     "{ previewHash: p.previewHash, acknowledgedNoEffect",
     "{ previewHash: p.contentHash, acknowledgedNoEffect",
     ['studio-publication-dialog.spec.ts']),
    ('M4 ignorar la cuenta atrás del 429', 'studio-canary-evidence.ts',
     "!this.session.acting() && this.espera() === 0);",
     "!this.session.acting());",
     ['studio-canary-evidence.spec.ts']),
    ('M5 botón de publicar sin mirar actions', 'studio-versions-panel.ts',
     "@if (d.actions.publish.allowed) {",
     "@if (true) {",
     ['studio-versions-panel-publication.spec.ts']),
    ('M6 sin relectura cuando la cabeza se mueve', 'studio-versions-panel.ts',
     "        const d = this.detalle();\n        if (d) void this.releerDetalle(d.uuid);\n      });\n    });\n  }",
     "      });\n    });\n  }",
     ['studio-versions-panel-publication.spec.ts']),
    ('M7 doble relectura tras escribir (sin dar la cabeza por vista)', 'studio-versions-panel.ts',
     "    this.cabezaVista = this.session.status()?.engine.publication?.head?.uuid ?? null;\n    void this.releerDetalle(uuid);",
     "    void this.releerDetalle(uuid);",
     ['studio-versions-panel-publication.spec.ts']),
    ('M8 «Volver a Legacy» sin la llave ultron.rollback', 'studio-publication-bar.ts',
     "this.session.can().rollback && this.partes().kind !== 'legacy'",
     "this.partes().kind !== 'legacy'",
     ['studio-publication-bar.spec.ts']),
    ('M9 la deriva no manda sobre el sello', 'studio-publication-text.ts',
     "  if (p.drift.length) return { tone: 'bad'",
     "  if (false) return { tone: 'bad'",
     ['studio-publication-text.spec.ts', 'studio-publication-bar.spec.ts']),
    ('M10 insignia de huella con integrityOk ausente', 'studio-compare-view.ts',
     "@if (c.to.integrityOk === false) {",
     "@if (!c.to.integrityOk) {",
     ['studio-compare-view.spec.ts']),
    ('M11 texto obligatorio alterado', 'studio-publication-text.ts',
     "En este hito no cambia lo que ULTRON dice ni hace.';",
     "En este hito no cambia lo que ULTRON dice.';",
     ['studio-publication-text.spec.ts', 'studio-publication-dialog.spec.ts', 'studio-versions-panel-publication.spec.ts']),
    ('M12 validation_stale como error', 'ultron-studio-page.ts',
     "if (r.error.code === 'validation_stale') this.store()?.info(r.error.message);",
     "if (r.error.code === 'validation_stale') this.store()?.error(r.error.message);",
     ['ultron-studio-page.spec.ts']),
    ('M13 motivo de la cadena como innerHTML', 'studio-publication-history.ts',
     "@if (e.reason) { <p class=\"ph-motivo\">{{ e.action === 'rollback' || e.action === 'legacy' ? 'Motivo' : 'Nota' }}: {{ e.reason }}</p> }",
     "@if (e.reason) { <p class=\"ph-motivo\" [innerHTML]=\"e.reason\"></p> }",
     ['studio-hostile-text-h5.spec.ts', 'studio-publication-history.spec.ts']),
    ('M14 la puerta dispensada no sale en rojo', 'studio-publication-history.ts',
     '[class.is-dispensada]="g.waived"',
     '[class.is-dispensada]="false"',
     ['studio-publication-history.spec.ts', 'studio-versions-panel-publication.spec.ts']),
    ('M15 Administrador: motivo del servidor en vez de la llave', 'studio-versions-panel.ts',
     "const sinLlave = a.code === 'forbidden' || !(accion === 'rollback' ? can.rollback : can.publish);",
     "const sinLlave = a.code === 'forbidden';",
     ['studio-versions-panel-publication.spec.ts']),
    ('M16 lectura del motor dice «correcta» de lo no comprobado', 'studio-engine-text.ts',
     "(v === null ? 'sin comprobar' : v ? 'correcta' : 'no se verifica')",
     "(v === null || v ? 'correcta' : 'no se verifica')",
     ['studio-publication-text.spec.ts']),
]


def sha(p: Path) -> str:
    return hashlib.sha256(p.read_bytes()).hexdigest()


def correr(specs):
    args = ['npx', 'ng', 'test', '--watch=false'] + [f'--include={M}{s}' for s in specs]
    r = subprocess.run(args, cwd=RAIZ, capture_output=True, text=True)
    salida = r.stdout + r.stderr
    resumen = [l for l in salida.splitlines() if ('Tests' in l and ('passed' in l or 'failed' in l)) or 'FAIL' in l or 'ERROR' in l or 'error TS' in l or 'NG' in l and 'error' in l.lower()]
    return r.returncode, salida, resumen


informe = []
for nombre, fichero, viejo, nuevo, specs in MUTACIONES:
    ruta = RAIZ / M / fichero
    copia = SALIDA / (fichero + '.orig')
    shutil.copyfile(ruta, copia)
    antes = sha(ruta)
    texto = ruta.read_text(encoding='utf-8')
    n = texto.count(viejo)
    if n != 1:
        informe.append(f'{nombre}: NO APLICADA (el patrón aparece {n} veces)')
        continue
    ruta.write_text(texto.replace(viejo, nuevo), encoding='utf-8')
    try:
        code, salida, resumen = correr(specs)
        (SALIDA / (nombre.split()[0] + '.txt')).write_text(salida, encoding='utf-8')
    finally:
        shutil.copyfile(copia, ruta)
    igual = subprocess.run(['cmp', str(copia), str(ruta)]).returncode == 0
    despues = sha(ruta)
    cae = code != 0
    lineas = ' | '.join(l.strip() for l in resumen if 'Tests' in l)
    informe.append(f'{nombre}: {"CAE" if cae else "SOBREVIVE"} (exit {code}) · {lineas} · restaurado cmp={"idéntico" if igual else "DISTINTO"} sha256 {antes[:12]}→{despues[:12]}')
    print(informe[-1], flush=True)

(SALIDA / 'resumen.txt').write_text('\n'.join(informe) + '\n', encoding='utf-8')
print('\n'.join(informe))
