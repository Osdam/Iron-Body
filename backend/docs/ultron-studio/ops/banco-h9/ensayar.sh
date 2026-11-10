#!/bin/bash
# Envoltorio de los ensayos del banco del Hito 9 (OPS9-a). TODO proceso PHP del banco va con RED (comun.sh): lo que no
# sea 127.0.0.1 sale por la valla del H8, que lo bloquea y lo anota. Los sustitutos de $B/bin van delante del PATH.
#   bash ensayar.sh <banco> arrancar | parar | estado         la valla y la API (artisan serve, 4 workers)
#   bash ensayar.sh <banco> valla                              la valla caza las sondas http y https y lo local no pasa por ella
#   bash ensayar.sh <banco> desplegar [--solo-comprobar] [--acepta-main=…] [--indices [--acepta-hora]]
#                                                              la copia del banco de deploy-h9-backend-fase1.sh (preparar.sh),
#                                                              con RED: el ensayo del paso 0, el despliegue o el paso aparte
#   bash ensayar.sh <banco> esquema                            h9-schema-check.sql del REPO contra la base del banco (owner
#                                                              h5_owner, app h5_app) y los 10 recuentos clave de L9-34
#   bash ensayar.sh <banco> smoke [<id de admin>] [2]          smoke-run-h9.sh del REPO con las variables locales y la valla
#                                                              como prueba estricta de cero red (fase 1; «2» = sombra)
#   bash ensayar.sh <banco> alcance <orden del mando…>         la copia del banco de h9-outreach-scope.sh, con RED
#   bash ensayar.sh <banco> cierre                             la valla sin más hosts que sus sondas y nada local por ella
#   bash ensayar.sh <banco> todo                               REPETIBLE: paso 0 → despliegue → paso aparte de la 100003 →
#                                                              esquema → smoke → cierre, con el resumen final (código 0 solo
#                                                              con todo en verde; sobre un banco ya desplegado, reanuda)
. "$(dirname "$0")/comun.sh"
B=$(banco_dir "${1:-}")
cargar_banco "$B"
ORDEN=${2:-}
shift 2 || true
export PATH="$B/bin:$PATH"
BE="$B/api/backend"
stats() { curl -s --max-time 3 "http://127.0.0.1:$1/__stats"; }

# ── Procesos: PID real y parada de nietos, hijos y padre (php -S deja hijos; memoria «php -S en macOS») ─────────
arranca() { # nombre directorio orden…
  local n=$1 d=$2; shift 2
  ( cd "$d" || exit 1; nohup "$@" > "$B/logs/$n.out" 2>&1 < /dev/null & echo $! > "$B/run/$n.pid" )
}
descendientes() { local c; for c in $(pgrep -P "$1" 2> /dev/null); do descendientes "$c"; echo "$c"; done; }
para() {
  local n=$1 p
  [ -f "$B/run/$n.pid" ] || return 0
  p=$(cat "$B/run/$n.pid")
  for c in $(descendientes "$p") "$p"; do kill "$c" 2> /dev/null || true; done
  rm -f "$B/run/$n.pid"
}
escuchando() { { lsof -nP -iTCP:"$1" -sTCP:LISTEN 2> /dev/null || true; } | tail -n +2 | awk '{print $2}' | sort -u | tr '\n' ' '; }
espera_http() { local i; for i in $(seq 1 "$2"); do curl -s -o /dev/null --max-time 2 "$1" && return 0; sleep 0.5; done; return 1; }
BPSQL=(psql -X -Atq -v ON_ERROR_STOP=1 -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$BANCO_DB")

case "$ORDEN" in
  arrancar)
    for p in "$PUERTO_VALLA" "$BANCO_PORT"; do [ -z "$(escuchando "$p")" ] || muere "el puerto $p ya está ocupado (pid $(escuchando "$p")): no arranco encima"; done
    arranca valla "$B" node "$VALLA_CJS" --log "$B/logs/valla.jsonl" --port "$PUERTO_VALLA"
    espera_http "http://127.0.0.1:$PUERTO_VALLA/__stats" 20 || muere "la valla no responde en :$PUERTO_VALLA (ver $B/logs/valla.out)"
    # --no-reload: sin él, artisan serve ignora PHP_CLI_SERVER_WORKERS y atiende de una en una.
    arranca api "$BE" env "${RED[@]}" PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port="$BANCO_PORT" --no-reload
    for _ in $(seq 1 40); do
      c=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "http://127.0.0.1:$BANCO_PORT/api/admin/ultron-studio/status" || true)
      [ "$c" = 401 ] && break
      sleep 0.5
    done
    [ "$c" = 401 ] || muere "la API no respondió (ver $B/logs/api.out)"
    echo "banco en marcha: valla :$PUERTO_VALLA · API :$BANCO_PORT (status sin token → 401)" ;;
  parar)
    for n in api valla; do para "$n"; done
    sleep 0.5
    for p in "$BANCO_PORT" "$PUERTO_VALLA"; do [ -z "$(escuchando "$p")" ] || muere "el puerto $p sigue ocupado (pid $(escuchando "$p")): pararlo a mano"; done
    echo "banco parado" ;;
  estado)
    for x in "valla:$PUERTO_VALLA" "api:$BANCO_PORT"; do
      n=${x%%:*}; p=${x#*:}; l=$(escuchando "$p")
      if [ -n "$l" ]; then echo "$n :$p escuchando (pid ${l% })"; else echo "$n :$p parado"; fi
    done
    echo "producción del banco: $(git -C "$B/api" rev-parse --short=7 HEAD) (PREVIO=$PREVIO_BANCO · ESPERADO=$ESPERADO) · base $BANCO_DB: $("${BPSQL[@]}" -c "SELECT (SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'ultron\_studio\_%')||' ultron_studio_* · '||(SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'outreach\_%')||' outreach_*'")" ;;

  valla)
    vivo "$PUERTO_VALLA" || muere "arranca antes: bash $AQUI/ensayar.sh $B arrancar"
    A=$(stats "$PUERTO_VALLA")
    read -r -d '' PHP_SONDA <<'PHP' || true
[$_, $dir] = $argv;
require $dir.'/vendor/autoload.php';
$app = require $dir.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (array_slice($argv, 2) as $u) {
    try { echo Illuminate\Support\Facades\Http::timeout(5)->get($u)->status(), ' '; } catch (Throwable $e) { echo class_basename($e), ' '; }
}
PHP
    R=$(cd "$BE" && env "${RED[@]}" php -r "$PHP_SONDA" "$BE" https://h9-valla-sonda.invalid/x http://h9-valla-sonda.invalid/x "http://127.0.0.1:$PUERTO_VALLA/__stats")
    read -r R1 R2 R3 <<< "$R"
    Z=$(stats "$PUERTO_VALLA")
    n_https=$(printf '%s' "$Z" | python3 -I -c 'import json, sys; print(json.load(sys.stdin)["byHost"].get("h9-valla-sonda.invalid:443", 0))')
    n_http=$(printf '%s' "$Z" | python3 -I -c 'import json, sys; print(json.load(sys.stdin)["byHost"].get("h9-valla-sonda.invalid", 0))')
    a_https=$(printf '%s' "$A" | python3 -I -c 'import json, sys; print(json.load(sys.stdin)["byHost"].get("h9-valla-sonda.invalid:443", 0))')
    a_http=$(printf '%s' "$A" | python3 -I -c 'import json, sys; print(json.load(sys.stdin)["byHost"].get("h9-valla-sonda.invalid", 0))')
    locales=$(printf '%s' "$Z" | campo localThroughFence)
    caso "https a un host de fuera: el cliente de Laravel no llega (la valla corta el CONNECT)" "$([ "$R1" = ConnectionException ] && echo 0 || echo 1)" "$R1"
    caso "http a un host de fuera: 403 de la valla" "$([ "$R2" = 403 ] && echo 0 || echo 1)" "$R2"
    caso "la valla anotó la sonda https (h9-valla-sonda.invalid:443)" "$([ "$n_https" -gt "$a_https" ] && echo 0 || echo 1)" "$a_https → $n_https"
    caso "la valla anotó la sonda http (h9-valla-sonda.invalid)" "$([ "$n_http" -gt "$a_http" ] && echo 0 || echo 1)" "$a_http → $n_http"
    caso "PHP del banco alcanza lo local SIN la valla (no_proxy)" "$([ "$R3" = 200 ] && echo 0 || echo 1)" "$R3"
    caso "ningún tráfico local entró por la valla" "$([ "$locales" = 0 ] && echo 0 || echo 1)" "localThroughFence=$locales"
    API=$(cat "$B/run/api.pid" 2> /dev/null || true)
    if [ -n "$API" ]; then
      HIJOS=$(descendientes "$API" | tr '\n' ' ')
      CON=0; TOT=0
      for p in $HIJOS; do TOT=$((TOT + 1)); ps eww -o command= -p "$p" 2> /dev/null | grep -q "https_proxy=http://127.0.0.1:$PUERTO_VALLA" && CON=$((CON + 1)); done
      caso "los procesos de la API (artisan serve y sus php -S) llevan https_proxy hacia la valla" "$([ "$TOT" -gt 0 ] && [ "$CON" = "$TOT" ] && echo 0 || echo 1)" "$CON de $TOT"
    else
      caso "los procesos de la API llevan el proxy (la API no está arrancada)" 1
    fi
    echo "$((CASOS - FALLOS)) OK · $FALLOS FALLO"
    [ "$FALLOS" = 0 ] ;;

  desplegar)
    bash "$AQUI/preparar.sh" "$B"
    EXTRA=()
    for a in "$@"; do case "$a" in --solo-comprobar|--acepta-main=*|--indices|--acepta-hora) EXTRA+=("$a") ;; *) muere "opción desconocida: $a" ;; esac; done
    export "${RED[@]}"
    exec bash "$B/root/deploy-h9-backend-fase1.sh" "$ESPERADO" "$B/bk" ${EXTRA[@]+"${EXTRA[@]}"} ;;

  migrar)
    # Para el ensayo ANTES de que B9-run entregue OutreachSendGate y las rutas de la ejecución (el paso 0 del despliegue
    # aborta sin ellas, como debe): el código del H9 en la «producción» del banco (merge --ff-only origin/main), las
    # migraciones con el migrador del banco (rol dueño, lock_timeout 5 s) y las cachés. NO sustituye al despliegue: no
    # escribe las 15 variables ni hace las aserciones del paso 0.
    bash "$AQUI/preparar.sh" "$B" > /dev/null
    ( cd "$B/api" && git fetch -q origin && git merge -q --ff-only origin/main && echo "   código de la «producción» del banco: $(git rev-parse --short=7 HEAD) (ESPERADO=$ESPERADO)" )
    set +e
    ( export "${RED[@]}"; cd "$BE" && env PGOPTIONS="-c lock_timeout=5s" bash "$B/root/ironbody-migrate" ); rc=$?
    set -e
    echo "   ironbody-migrate del banco salió con $rc"
    ( cd "$BE" && env "${RED[@]}" php artisan config:cache -q && env "${RED[@]}" php artisan route:cache -q && chmod 600 bootstrap/cache/config.php bootstrap/cache/routes-v7.php )
    echo "   esquema: $("${BPSQL[@]}" -c "SELECT (SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'ultron\_studio\_%')||' ultron_studio_* · '||(SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'outreach\_%')||' outreach_* · migraciones del H9 registradas: '||(SELECT count(*) FROM migrations WHERE migration LIKE '2026\_11\_12\_1000%')") · rutas del Studio: $(cd "$BE" && env "${RED[@]}" php artisan route:list --path=admin/ultron-studio --json | python3 -c 'import json, sys; print(len(json.load(sys.stdin)))')"
    exit "$rc" ;;

  esquema)
    SAL=$(psql -X -v ON_ERROR_STOP=1 -v owner=h5_owner -v app=h5_app -x -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$BANCO_DB" < "$OPS/h9-schema-check.sql" 2>&1) || { printf '%s\n' "$SAL" | tail -5; muere "h9-schema-check.sql no se pudo ejecutar"; }
    v() { printf '%s\n' "$SAL" | awk -v k="$1" -F'|' '$1 ~ "^"k"[ ]*$" {gsub(/^[ ]+|[ ]+$/, "", $2); print $2; exit}'; }
    for kv in disparadores_studio=123 check_studio=137 parciales_studio=9 tablas_studio=25 disparadores_outreach=51 check_outreach=75 parciales_outreach=3 tablas_outreach=8 funciones_h9=33 funciones_h9_definer=0 disparadores_apagados=0 libro_11_sujetos=t funcion_es_la_de_f=t; do
      k=${kv%%=*}; e=${kv#*=}; r=$(v "$k")
      caso "$k = $e" "$([ "$r" = "$e" ] && echo 0 || echo 1)" "$r"
    done
    IDX=$(v indices_lectura_h9_validos); INV=$(v indices_lectura_h9_invalidos)
    caso "índices de lectura válidos 4 e inválidos 0 (tras el paso aparte de la 100003)" "$([ "$IDX $INV" = "4 0" ] && echo 0 || echo 1)" "$IDX $INV"
    echo "   privilegios de h5_app (r SELECT · a INSERT · w UPDATE · d DELETE · D TRUNCATE · s secuencia):"
    printf '%s\n' "$SAL" | awk -F'|' '$1 ~ /^tabla[ ]*$/ {t=$2} $1 ~ /^app[ ]*$/ && t != "" {gsub(/ /, "", $2); gsub(/^[ ]+|[ ]+$/, "", t); printf "      %s %s\n", t, $2; t=""}'
    echo "$((CASOS - FALLOS)) OK · $FALLOS FALLO"
    [ "$FALLOS" = 0 ] ;;

  smoke)
    ID=${1:-${ADMIN_ID:-}}
    FASE=${2:-1}
    [ -n "$ID" ] || muere "sin cuenta de smoke: bash $AQUI/sembrar.sh $B"
    mkdir -p "$B/smokework"
    exec env "${RED[@]}" APP_DIR="$BE" SMOKE_API="http://127.0.0.1:$BANCO_PORT/api" SMOKE_RUN_AS= \
      SMOKE_PSQL="psql -X -Atq -h $PG_HOST -p $PG_PORT -U $PG_USER -d $BANCO_DB" SMOKE_WORKDIR="$B/smokework" \
      SMOKE_PY="$OPS/smoke-h9.py" SMOKE_H6_PY="$OPS/smoke-h6.py" SMOKE_ADMIN_ID="$ID" SMOKE_PHASE="$FASE" \
      SMOKE_BUDGETS="${SMOKE_BUDGETS-}" SMOKE_BUDGETS_OUT="${SMOKE_BUDGETS_OUT:-}" SMOKE_ALLOW_MISSING_EXEC="${SMOKE_ALLOW_MISSING_EXEC:-}" \
      SMOKE_VALLA_STATS="http://127.0.0.1:$PUERTO_VALLA/__stats" SMOKE_LOGS="$BE/storage/logs" bash "$OPS/smoke-run-h9.sh" ;;

  alcance)
    bash "$AQUI/preparar.sh" "$B" > /dev/null
    export "${RED[@]}"
    exec bash "$B/root/h9-outreach-scope.sh" "$@" ;;

  cierre)
    vivo "$PUERTO_VALLA" || muere "arranca antes: bash $AQUI/ensayar.sh $B arrancar"
    sv=$(stats "$PUERTO_VALLA")
    otros=$(printf '%s' "$sv" | python3 -I -c 'import json, sys; print(" ".join(h for h in json.load(sys.stdin)["byHost"] if h not in ("h9-valla-sonda.invalid", "h9-valla-sonda.invalid:443")))')
    caso "la valla no vio más hosts que sus sondas" "$([ -z "$otros" ] && echo 0 || echo 1)" "${otros:-ninguno}"
    caso "ningún tráfico local por la valla" "$([ "$(printf '%s' "$sv" | campo localThroughFence)" = 0 ] && echo 0 || echo 1)"
    echo "$((CASOS - FALLOS)) OK · $FALLOS FALLO"
    [ "$FALLOS" = 0 ] ;;

  todo)
    # Repetible: sobre un banco recién montado hace la fase 1 entera; sobre uno ya desplegado, el despliegue reanuda
    # («nada que migrar») y el resto se repite. Cada paso deja su código; el resumen va al final.
    # Sin arrays asociativos: el bash de macOS es el 3.2.
    [ -n "$(escuchando "$BANCO_PORT")" ] || bash "$AQUI/ensayar.sh" "$B" arrancar
    [ -n "${ADMIN_ID:-}" ] || bash "$AQUI/sembrar.sh" "$B"
    cargar_banco "$B"
    set +e
    echo "#### 1. paso 0 (--solo-comprobar)"; bash "$AQUI/ensayar.sh" "$B" desplegar --solo-comprobar; RC_comprobar=$?
    echo "#### 2. despliegue de la fase 1"; bash "$AQUI/ensayar.sh" "$B" desplegar; RC_desplegar=$?
    RC_migrar=-
    if [ "$RC_desplegar" != 0 ]; then
      echo "#### 2b. el despliegue no pasó (B9-run pendiente: sin OutreachSendGate ni rutas de la ejecución): MULETA «migrar» (código del H9 + migraciones + cachés, sin las 15 variables ni el paso 0)"
      bash "$AQUI/ensayar.sh" "$B" migrar; RC_migrar=$?
    fi
    echo "#### 3. paso aparte de la 100003 (índices)"; bash "$AQUI/ensayar.sh" "$B" desplegar --indices --acepta-hora; RC_indices=$?
    echo "#### 4. esquema"; bash "$AQUI/ensayar.sh" "$B" esquema; RC_esquema=$?
    MULETA=""
    [ -f "$BE/config/outreach.php" ] || { MULETA=1; echo "#### 5. smoke (fase 1) con SMOKE_ALLOW_MISSING_EXEC=1: config/outreach.php no está en la «producción» del banco (B9-run pendiente); la ejecución sale OMITIDA"; }
    [ -n "$MULETA" ] || echo "#### 5. smoke (fase 1)"
    SMOKE_ALLOW_MISSING_EXEC="${MULETA:-}" bash "$AQUI/ensayar.sh" "$B" smoke; RC_smoke=$?
    echo "#### 6. cierre de la valla"; bash "$AQUI/ensayar.sh" "$B" cierre; RC_cierre=$?
    set -e
    FINAL=0
    echo "#### RESUMEN del ensayo del banco del H9 ($B)"
    for k in comprobar desplegar migrar indices esquema smoke cierre; do
      eval "rc=\$RC_$k"
      case "$rc" in
        0) e=OK ;;
        3) e="OK con OMITIDOS" ;;
        -) e="(no hizo falta)" ;;
        *) if [ "$k" = desplegar ] && [ "$RC_migrar" = 0 ]; then e="BLOQUEADO por B9-run pendiente (código $rc); la muleta migrar lo cubrió"; else e="FALLO (código $rc)"; FINAL=1; fi ;;
      esac
      printf '   %-10s %s\n' "$k" "$e"
    done
    [ "$FINAL" = 0 ] && echo "ENSAYO DEL BANCO DEL H9: EN VERDE" || echo "ENSAYO DEL BANCO DEL H9: CON FALLOS"
    exit "$FINAL" ;;
  *) muere "uso: bash ensayar.sh <banco> arrancar|parar|estado|valla|desplegar [--solo-comprobar|--acepta-main=…|--indices [--acepta-hora]]|esquema|smoke [<id>] [2]|alcance …|cierre|todo" ;;
esac
