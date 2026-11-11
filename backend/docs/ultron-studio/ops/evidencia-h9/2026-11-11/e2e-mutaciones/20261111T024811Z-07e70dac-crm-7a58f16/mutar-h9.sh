#!/bin/bash
# Mutaciones del E2E #26 sobre una pila retenida (`h9-e2e.sh pila`): cada una rompe SOLO una copia (el backend de la
# ejecución, que `php -S` relee en cada petición, o una copia clonada del CRM compilado) y corre los pasos que la cubren.
#   bash mutar-h9.sh <run de la pila> <dist del CRM> <salida>
set -uo pipefail
RUN=$1; DIST=$2; OUT=$3
AQUI=/Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/ultron-h8/Iron-Body/backend/docs/ultron-studio/ops/h9-e2e
mkdir -p "$OUT"
CRM_PID=$(cat "$RUN/run/crm.pid")
servir() { # dist
  kill -TERM "$CRM_PID" 2> /dev/null; for _ in $(seq 1 50); do lsof -nP -iTCP:4300 -sTCP:LISTEN > /dev/null 2>&1 || break; sleep 0.1; done
  node "$AQUI/servidor-crm.cjs" --dir "$1" --port 4300 > "$OUT/servidor-$(basename "$(dirname "$1")").out" 2>&1 &
  CRM_PID=$!
  for _ in $(seq 1 50); do curl -s -o /dev/null --max-time 1 http://127.0.0.1:4300/ && break; sleep 0.2; done
}
guion() { # nombre pasos
  echo "== $1 · H9_PASOS=$2 · $(date -u +%H:%M:%SZ)"
  E2E_TIMEOUT_SCALE=2 H9_PASOS="$2" node "$AQUI/recorridos-h9.cjs" "$RUN/context.json" > "$OUT/$1.out" 2>&1; echo "   salida del guion: $?"
  grep -E 'FALLO|→ ' "$OUT/$1.out" | cut -c1-260 | sed 's/^/   /'
}
mutar() { # fichero viejo nuevo   (exactamente una aparición)
  python3 -I - "$1" "$2" "$3" <<'PY'
import sys
f, a, b = sys.argv[1:4]
t = open(f, encoding='utf-8').read()
n = t.count(a)
if n != 1:
    sys.exit(f'mutar: {n} apariciones en {f}')
open(f, 'w', encoding='utf-8').write(t.replace(a, b))
PY
}
copia_dist() { # nombre → dist mutable
  rm -rf "$OUT/dist-$1"; mkdir -p "$OUT/dist-$1"; cp -cR "$DIST" "$OUT/dist-$1/browser"; echo "$OUT/dist-$1/browser"
}
CHUNK=$(cd "$DIST" && grep -l '  abrirEstrategia(uuid) {' ./*.js | head -1)

# A · H9-E2E-1 (backend): `draftApiDto()` vuelve a dar el borrador con `assoc` (el `[]` del vacío).
PHP="$RUN/backend/app/Services/UltronStudio/StudioStrategies.php"
cp "$PHP" "$OUT/StudioStrategies.php.orig"
mutar "$PHP" 'if ($dto['"'"'content'"'"'] !== null && is_string($s->draft_json)) {' 'if (false && $dto['"'"'content'"'"'] !== null && is_string($s->draft_json)) {'
guion A-borrador-como-lista plantillas,e1
cp "$OUT/StudioStrategies.php.orig" "$PHP"; cmp -s "$OUT/StudioStrategies.php.orig" "$PHP" && echo "   A revertida (cmp idéntico)"

# B · H9-E2E-2 (CRM): la simulación sintética vuelve a mandar null sin eventos ni contactos.
D=$(copia_dist B)
mutar "$D/$CHUNK" 'contacts: p.contacts.map((c) => __spreadValues({}, c)),' 'contacts: p.contacts.length ? p.contacts.map((c) => __spreadValues({}, c)) : null,'
mutar "$D/$CHUNK" 'events: p.events.map((e) => __spreadValues({}, e))' 'events: p.events.length ? p.events.map((e) => __spreadValues({}, e)) : null'
servir "$D"; guion B-simulacion-con-null plantillas,e1

# C · H9-E2E-3 (CRM): el panel deja de explicar quién suelta la parada (la frase vacía).
D=$(copia_dist C)
mutar "$D/$CHUNK" '("La ech\xF3 ", ctx_r0.quienAbrio(ctx), ". Quien la ech\xF3 no puede soltarla: la suelta otra persona y el servidor lo exige (cuatro ojos).");' '("", "", "");'
servir "$D"; guion C-parada-sin-explicacion parada

# D · H9-E2E-4 al revés: `abrirEstrategia()` con UNA navegación (estrategia y pestaña juntas) → las dos comprobaciones en verde.
D=$(copia_dist D)
mutar "$D/$CHUNK" '    void this.router.navigate([], { relativeTo: this.route, queryParams: { estrategia: uuid }, queryParamsHandling: "merge", replaceUrl: true });
    this.elegir("constructor");' '    this.pestana.set("constructor");
    void this.router.navigate([], { relativeTo: this.route, queryParams: { estrategia: uuid, pestana: "constructor" }, queryParamsHandling: "merge", replaceUrl: true });'
servir "$D"; guion D-url-arreglada plantillas,e1,url

# El CRM sin mutar otra vez en :4300 (el lanzador lo para al recibir SIGTERM por el puerto).
servir "$DIST"
echo "== fin · $(date -u +%H:%M:%SZ) · servidor del CRM original pid $CRM_PID"
echo "$CRM_PID" > "$OUT/crm-original.pid"
