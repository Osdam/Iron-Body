#!/usr/bin/env bash
# La suite de una rama contra su base, EL MISMO DÍA y EN SECUENCIA, comparada por NOMBRE de prueba.
#   - Memoria «La suite depende del calendario»: un test de Reports falla los días 1–10; la línea base se saca
#     el mismo día y se compara por nombre, nunca por recuento.
#   - Cada lado es una instantánea `git archive` con vendor/ COPIADO (no enlazado: memoria del worktree con vendor
#     enlazado) y `.env.testing`, con su propio storage/ (dos PHPUnit de Marketing en el mismo árbol se pisan el
#     disco `whatsapp`). Primero la rama y después la base, nunca a la vez.
# Uso:   docs/ultron-studio/ops/suite-compare.sh <dir-de-trabajo-vacío> <ref-base> [<ref-rama>=HEAD] [-- <rutas de phpunit>]
#        Sin rutas, la suite completa en SQLite.
# PG:    SUITE_PG_DB_HEAD=ultron_studio_… SUITE_PG_DB_BASE=ultron_studio_… (bases desechables que ya existen) → con ops/pgunit.sh.
# Copiar ficheros sin confirmar a la rama (p. ej. pruebas nuevas): SUITE_EXTRA="tests/Feature/X.php tests/Feature/Y.php".
# Sale 1 si la rama añade algún rojo (un nombre que falla en la rama y no en la base); 2 si algo no se pudo correr.
# Artefactos conocidos de la instantánea (fallan IGUAL en los dos lados, no son de la rama):
#   SecretExposureTest::test_git_never_tracks_environment_files_with_secrets (la instantánea no es un repo git) y
#   MemberContractTest (la plantilla PDF de storage/app/private/contract_templates es un fichero ignorado; en el
#   worktree de este Mac tampoco está).
set -uo pipefail
WORK="${1:?dir de trabajo}"
BASE_REF="${2:?ref de la base}"
HEAD_REF="${3:-HEAD}"
shift $(($# >= 3 ? 3 : $#))
[ "${1:-}" = "--" ] && shift
RUTAS=("$@")
BACKEND="$(cd "$(dirname "$0")/../../.." && pwd)"
REPO="$(git -C "$BACKEND" rev-parse --show-toplevel)"
mkdir -p "$WORK/logs" || exit 2
L="$WORK/logs"

nombres() { # <junit> <failed|all> → «Clase::prueba» ordenados
  php -r '$d = new SimpleXMLElement(file_get_contents($argv[1])); $x = $argv[2] === "failed" ? "//testcase[failure or error]" : "//testcase";
    $n = []; foreach ($d->xpath($x) as $t) { $n[(string) $t["class"]."::".(string) $t["name"]] = 1; } $n = array_keys($n); sort($n); echo implode("\n", $n), $n ? "\n" : "";' "$1" "$2"
}

lado() { # <nombre> <ref> <base PG o vacío>
  local nombre=$1 ref=$2 db=$3 dir="$WORK/$1"
  mkdir -p "$dir" || return 2
  git -C "$REPO" archive "$ref" backend | tar -x -C "$dir" || return 2
  rsync -a "$BACKEND/vendor/" "$dir/backend/vendor/" || return 2
  cp -p "$BACKEND/.env.testing" "$dir/backend/.env.testing" && chmod 600 "$dir/backend/.env.testing"
  if [ "$nombre" = rama ] && [ -n "${SUITE_EXTRA:-}" ]; then
    for f in $SUITE_EXTRA; do mkdir -p "$dir/backend/$(dirname "$f")" && cp -p "$BACKEND/$f" "$dir/backend/$f" || return 2; done
  fi
  echo "[$nombre] $(git -C "$REPO" rev-parse --short "$ref") inicio $(date -u +%FT%TZ)" | tee -a "$L/timeline.txt"
  local rc
  if [ -n "$db" ]; then
    (cd "$dir/backend" && PGGSSENCMODE=disable UNIT_DB="$db" bash docs/ultron-studio/ops/pgunit.sh --do-not-cache-result --log-junit "$L/junit-$nombre.xml" "${RUTAS[@]}") > "$L/$nombre.txt" 2>&1
    rc=$?
  else
    (cd "$dir/backend" && php -d memory_limit=2G vendor/bin/phpunit --do-not-cache-result --log-junit "$L/junit-$nombre.xml" "${RUTAS[@]}") > "$L/$nombre.txt" 2>&1
    rc=$?
  fi
  echo "[$nombre] fin $(date -u +%FT%TZ) exit=$rc" | tee -a "$L/timeline.txt"
  [ -f "$L/junit-$nombre.xml" ] || return 2
  nombres "$L/junit-$nombre.xml" failed > "$L/$nombre-rojos.txt"
  nombres "$L/junit-$nombre.xml" all > "$L/$nombre-todas.txt"
  grep -E '^(Tests:|OK)' "$L/$nombre.txt" | tail -1
}

lado rama "$HEAD_REF" "${SUITE_PG_DB_HEAD:-}" || exit 2
lado base "$BASE_REF" "${SUITE_PG_DB_BASE:-}" || exit 2

comm -23 "$L/rama-rojos.txt" "$L/base-rojos.txt" > "$L/rojos-nuevos.txt"
comm -13 "$L/rama-todas.txt" "$L/base-todas.txt" > "$L/pruebas-de-la-base-que-ya-no-existen.txt"
echo "rojos en la rama: $(wc -l < "$L/rama-rojos.txt" | tr -d ' ') · en la base: $(wc -l < "$L/base-rojos.txt" | tr -d ' ') · NUEVOS: $(wc -l < "$L/rojos-nuevos.txt" | tr -d ' ')"
echo "pruebas de la base que ya no existen en la rama: $(wc -l < "$L/pruebas-de-la-base-que-ya-no-existen.txt" | tr -d ' ') (ver $L/pruebas-de-la-base-que-ya-no-existen.txt)"
if [ -s "$L/rojos-nuevos.txt" ]; then
  sed 's/^/  + /' "$L/rojos-nuevos.txt"
  exit 1
fi
exit 0
