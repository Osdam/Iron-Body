#!/bin/bash
# Ensayo de recuperación de errores del Hito 7 (contrato OPS7 (g)), con números reales:
#  A. PostgreSQL cae mientras el motor sella un commit. En un clúster PROPIO (puerto 55440, en el banco: el de 55432 lo
#     comparten otros agentes y no se para), con una copia de la base del banco ya desplegada y un proceso largo que hace
#     de commit (lector.php, la llave del motor en canary): el sello tiene que ser fallback/read_failed y NUNCA una
#     excepción; al volver PostgreSQL, el mismo proceso reconecta y escribe. También: la cadena bloqueada por la
#     migración del H7 (ACCESS EXCLUSIVE) solo RETRASA la lectura, y una conexión terminada a mitad da read_failed.
#  B. El worker del laboratorio muere a mitad de un caso (kill -9 con el caso ya en «running»): el caso huérfano se
#     cierra (worker_lost) y la ejecución termina; se anota qué ve el monitor mientras el worker está muerto.
# Uso: bash ensayo-recuperacion.sh <banco> [A|B]      (por defecto, los dos; código 0 = todos los casos OK)
. "$(dirname "$0")/comun.sh"
B=$(banco_dir "${1:-}")
cargar_banco "$B"
PARTES=${2:-AB}
APPD="$B/api/backend"
Q() { psql -X -Atq -v ON_ERROR_STOP=1 -h "$PG_HOST" -p "$1" -U "$PG_USER" -d "$2" -c "$3"; }
# Campos de una línea JSON por su ruta («sello.state», «data.uuid»…), unidos por «/»; sin eval: solo se recorre el JSON.
campos() { python3 -c '
import json, sys
try:
    d = json.loads(sys.stdin.read() or "{}")
except ValueError:
    d = {}
def sigue(x, ruta):
    for p in ruta.split("."):
        x = x.get(p) if isinstance(x, dict) else (x[int(p)] if isinstance(x, list) and p.isdigit() and int(p) < len(x) else None)
    return x
print("/".join(str(sigue(d, r)) for r in sys.argv[1:]))' "$@" 2>/dev/null; }

if [[ "$PARTES" == *A* ]]; then
  echo "== A. PostgreSQL cae durante el commit del motor (clúster propio en 55440)"
  PGD="$B/pgrec"; RP=55440; RDB=ultron_studio_banco_h7_rec
  rm -rf "$PGD"; initdb -D "$PGD" -U "$PG_USER" --auth=trust > "$B/logs/initdb.log" 2>&1
  arranca() { pg_ctl -D "$PGD" -o "-p $RP -c listen_addresses=127.0.0.1 -c unix_socket_directories=''" -l "$B/logs/pgrec.log" -w start > /dev/null; }
  arranca
  trap 'pg_ctl -D "$PGD" -m immediate stop > /dev/null 2>&1 || true; rm -rf "$PGD"' EXIT
  Q "$RP" postgres "CREATE ROLE h5_owner LOGIN;" > /dev/null; Q "$RP" postgres "CREATE ROLE h5_app LOGIN;" > /dev/null
  Q "$RP" postgres "CREATE DATABASE $RDB;" > /dev/null
  pg_dump -Fc -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" "$BANCO_DB" | pg_restore -h "$PG_HOST" -p "$RP" -U "$PG_USER" --dbname="$RDB" 2> "$B/logs/rec-restore.log" || true
  caso "copia de la base desplegada en el clúster propio (44 disparadores activos, 1 entrada en la cadena)" "$( [ "$(Q "$RP" "$RDB" "SELECT (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron_studio_%' AND t.tgenabled = 'O')||' '||(SELECT count(*) FROM ultron_studio_publications);")" = "44 1" ] && echo 0 || echo 1)"
  # El proceso del commit, con dos FIFO (bash 3.2 de macOS no tiene coproc): 7 escribe sus órdenes, 8 lee sus respuestas.
  rm -f "$B/run/lector.in" "$B/run/lector.out"; mkfifo "$B/run/lector.in" "$B/run/lector.out"
  ( cd "$APPD" && exec env APP_CONFIG_CACHE="$B/run/no-cache.php" DB_PORT=$RP DB_DATABASE=$RDB MARKETING_ULTRON_STUDIO_CONFIG=canary \
      php "$AQUI/lector.php" "$APPD" < "$B/run/lector.in" > "$B/run/lector.out" 2> "$B/logs/lector.err" ) &
  exec 7> "$B/run/lector.in"; exec 8< "$B/run/lector.out"
  pide() { printf '%s\n' "$1" >&7; local l=""; IFS= read -r -t "${2:-60}" l <&8 || true; printf '%s\n' "$l"; }
  espera() { local l=""; IFS= read -r -t "${1:-60}" l <&8 || true; printf '%s\n' "$l"; }
  L=$(espera 60)
  caso "el proceso del commit arranca con la llave del motor en canary (lee la cadena)" "$(printf '%s' "$L" | grep -q '"env":"canary"' && echo 0 || echo 1)" "$L"
  R=$(pide leer); caso "con PostgreSQL arriba: sello legacy (la cabeza es una vuelta a Legacy que verifica)" "$( [ "$(printf '%s' "$R" | campos sello.state)" = legacy ] && echo 0 || echo 1)" "$(printf '%s' "$R" | campos sello.state ms)"
  pg_ctl -D "$PGD" -m immediate stop > /dev/null
  R=$(pide leer)
  caso "PostgreSQL CAÍDO durante el commit: sello fallback/read_failed, sin excepción (nunca un 500 por el Studio)" "$( [ "$(printf '%s' "$R" | campos sello.state sello.reason)" = "fallback/read_failed" ] && echo 0 || echo 1)" "$(printf '%s' "$R" | campos sello.state sello.reason excepcion ms)"
  R=$(pide escribir)
  caso "con PostgreSQL caído la escritura del commit falla (lo que no es del Studio no se puede tapar)" "$(printf '%s' "$R" | grep -q '"excepcion"' && echo 0 || echo 1)" "$R"
  arranca
  R=$(pide leer); caso "PostgreSQL vuelve: el MISMO proceso reconecta y el sello vuelve a legacy" "$( [ "$(printf '%s' "$R" | campos sello.state)" = legacy ] && echo 0 || echo 1)" "$(printf '%s' "$R" | campos sello.state excepcion ms)"
  R=$(pide escribir); caso "y la escritura del commit, tras reconectar, funciona" "$(printf '%s' "$R" | grep -q '"escrito":true' && echo 0 || echo 1)" "$R"
  # La migración del H7 toma ACCESS EXCLUSIVE sobre la cadena: la lectura del motor espera (no falla) mientras dura.
  (psql -X -q -h "$PG_HOST" -p "$RP" -U "$PG_USER" -d "$RDB" -c "BEGIN; LOCK TABLE ultron_studio_publications IN ACCESS EXCLUSIVE MODE; SELECT pg_sleep(3); COMMIT;" > /dev/null 2>&1 &)
  sleep 0.5
  R=$(pide leer 30); MS=$(printf '%s' "$R" | campos ms)
  caso "cadena bloqueada 3 s (como la migración del H7): la lectura ESPERA y sella legacy, no falla" "$( [ "$(printf '%s' "$R" | campos sello.state)" = legacy ] && [ "${MS:-0}" -ge 2000 ] && echo 0 || echo 1)" "esperó ${MS} ms"
  # Una conexión que se termina mientras espera: read_failed, y la siguiente reconecta.
  P=$(pide pid | campos pid)
  (psql -X -q -h "$PG_HOST" -p "$RP" -U "$PG_USER" -d "$RDB" -c "BEGIN; LOCK TABLE ultron_studio_publications IN ACCESS EXCLUSIVE MODE; SELECT pg_sleep(20); COMMIT;" > /dev/null 2>&1 &)
  sleep 0.5
  printf 'leer\n' >&7
  sleep 1
  Q "$RP" "$RDB" "SELECT pg_terminate_backend($P);" > /dev/null
  R=$(espera 30)
  # Fuera de una transacción, Laravel reconecta y REPITE una sentencia cuya conexión se perdió: la lectura vuelve a
  # esperar al cerrojo (aquí, hasta que acaba a los 20 s) y sella lo correcto. Sin excepción en ningún caso.
  P2=$(pide pid | campos pid)
  caso "su conexión terminada a mitad de la lectura: Laravel reconecta y repite; sello legacy, sin excepción" "$( [ "$(printf '%s' "$R" | campos sello.state excepcion)" = "legacy/None" ] && [ "$P2" != "$P" ] && echo 0 || echo 1)" "$(printf '%s' "$R" | campos sello.state ms) · conexión $P → $P2"
  R=$(pide leer); caso "la lectura siguiente, sin cerrojo, sella legacy enseguida" "$( [ "$(printf '%s' "$R" | campos sello.state)" = legacy ] && echo 0 || echo 1)" "$(printf '%s' "$R" | campos ms) ms"
  # El aviso va al canal de observabilidad (ChannelLog → storage/logs/channel-AAAA-MM-DD.log), con motivo y CLASE.
  N=$(cat "$APPD"/storage/logs/channel-*.log 2> /dev/null | grep -c '"ultron.published_config.fallback".*"reason":"read_failed"' || true)
  caso "el fallback deja su aviso en el canal de observabilidad, con el motivo y la clase del error (nunca el mensaje)" "$( [ "${N:-0}" -ge 1 ] && ! cat "$APPD"/storage/logs/channel-*.log | grep 'published_config.fallback' | grep -q 'SQLSTATE' && echo 0 || echo 1)" "$N avisos read_failed"
  printf 'salir\n' >&7 || true; exec 7>&- 8<&-; rm -f "$B/run/lector.in" "$B/run/lector.out"
  pg_ctl -D "$PGD" -m fast stop > /dev/null; rm -rf "$PGD"; trap - EXIT
fi

if [[ "$PARTES" == *B* ]]; then
  echo "== B. El worker del laboratorio muere a mitad de un caso (base del banco, API en :$BANCO_PORT)"
  API="http://127.0.0.1:$BANCO_PORT/api"
  PW=$(openssl rand -hex 16); HASH=$(PW="$PW" php -r 'echo password_hash(getenv("PW"), PASSWORD_BCRYPT);')
  Q "$PG_PORT" "$BANCO_DB" "INSERT INTO admins (uuid, name, email, password, role, status, created_at, updated_at) VALUES (gen_random_uuid(), 'Operadora banco', 'operadora-banco-h7@local', '$HASH', 'Super Admin', 'active', now(), now()) ON CONFLICT (email) DO UPDATE SET password = EXCLUDED.password, status = 'active';" > /dev/null
  TOK=$(curl -s -H 'Accept: application/json' -H 'Content-Type: application/json' -d "{\"email\":\"operadora-banco-h7@local\",\"password\":\"$PW\"}" "$API/admin/auth/login" | campos token)
  unset PW HASH
  caso "sesión de una operadora (Super Admin) en la API del banco" "$( [ -n "$TOK" ] && [ "$TOK" != None ] && echo 0 || echo 1)"
  H=(-s -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Authorization: Bearer $TOK")
  ( cd "$APPD" && php artisan ultron-studio:lab-import-scenarios > "$B/logs/lab-import.log" 2>&1 ) || true
  ESC=$(curl "${H[@]}" "$API/admin/ultron-studio/lab/scenarios?per_page=50" | python3 -c '
import json, sys
d = json.load(sys.stdin).get("data")
filas = d.get("data") if isinstance(d, dict) else (d or [])
print(",".join([x["uuid"] for x in filas if isinstance(x, dict) and x.get("status") == "active"][:2]))')
  caso "escenarios del laboratorio importados (2 para la ejecución)" "$( [ "$(printf '%s' "$ESC" | tr ',' '\n' | grep -c .)" -ge 2 ] && echo 0 || echo 1)" "$(printf '%s' "$ESC" | tr ',' '\n' | grep -c .)"
  U1=$(printf '%s' "$ESC" | cut -d, -f1); U2=$(printf '%s' "$ESC" | cut -d, -f2)
  BOR=$(curl "${H[@]}" "$API/admin/ultron-studio/draft")
  REV=$(printf '%s' "$BOR" | campos data.revision)
  if [ "${REV:-0}" = 0 ]; then
    CONT=$(printf '%s' "$BOR" | python3 -c 'import json,sys; print(json.dumps(json.load(sys.stdin)["data"]["content"]))')
    REV=$(curl "${H[@]}" -X PUT -d "{\"baseRevision\":0,\"content\":$CONT}" "$API/admin/ultron-studio/draft" | campos data.revision)
  fi
  # Una ejecución fake de 2 casos; el worker se queda dentro del paso (la fila de la ejecución retenida) y muere con
  # kill -9 con el trabajo ya reservado. CAMINO 1: muere ANTES de reclamar el caso. CAMINO 2: muere con el caso ya
  # reclamado («running»): reclamar y liquidar tocan las mismas filas, así que con el driver fake esa ventana dura
  # microsegundos y ningún cerrojo la atrapa sin frenar antes la reclamación; el ensayo mata el worker de verdad y deja
  # con SQL lo que la muerte habría dejado un instante después (ejecución y caso en «running», con su hora).
  for CAMINO in 1 2; do
    CRID=$(python3 -c 'import uuid; print(uuid.uuid4())')
    RUN=$(curl "${H[@]}" -X POST -d "{\"target\":{\"kind\":\"draft\",\"revision\":$REV},\"scenarioUuids\":[\"$U1\",\"$U2\"],\"acceptedMaxCostMicros\":0,\"clientRequestId\":\"$CRID\"}" "$API/admin/ultron-studio/lab/runs")
    RUUID=$(printf '%s' "$RUN" | campos data.uuid)
    caso "[camino $CAMINO] ejecución fake de 2 casos sobre el borrador, en cola" "$( [[ "$RUUID" =~ ^[0-9a-f-]{36}$ ]] && echo 0 || echo 1)" "$(printf '%s' "$RUN" | campos code data.status)"
    RID=$(Q "$PG_PORT" "$BANCO_DB" "SELECT id FROM ultron_studio_lab_runs WHERE uuid = '$RUUID';")
    (psql -X -q -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$BANCO_DB" -c "BEGIN; SELECT id FROM ultron_studio_lab_runs WHERE id = $RID FOR UPDATE; SELECT pg_sleep(60); ROLLBACK;" > /dev/null 2>&1 &)
    sleep 0.5
    ( cd "$APPD" && exec php artisan queue:work lab --queue=studio-lab --once --timeout=150 > "$B/logs/lab-worker-$CAMINO-a.log" 2>&1 ) &
    WPID=$!
    for _ in $(seq 1 40); do [ "$(Q "$PG_PORT" "$BANCO_DB" "SELECT count(*) FROM jobs WHERE queue = 'studio-lab' AND reserved_at IS NOT NULL;")" = 1 ] && break; sleep 0.5; done
    sleep 1
    kill -9 "$WPID" 2> /dev/null || true; wait "$WPID" 2> /dev/null || true
    Q "$PG_PORT" "$BANCO_DB" "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE query LIKE '%pg_sleep(60)%' AND pid <> pg_backend_pid();" > /dev/null
    sleep 1
    if [ "$CAMINO" = 2 ]; then
      Q "$PG_PORT" "$BANCO_DB" "UPDATE ultron_studio_lab_runs SET status = 'running', started_at = (now() AT TIME ZONE 'UTC') - interval '200 seconds' WHERE id = $RID AND status = 'queued';" > /dev/null
      Q "$PG_PORT" "$BANCO_DB" "UPDATE ultron_studio_lab_run_cases SET status = 'running', started_at = (now() AT TIME ZONE 'UTC') - interval '200 seconds' WHERE run_id = $RID AND position = (SELECT min(position) FROM ultron_studio_lab_run_cases WHERE run_id = $RID);" > /dev/null
    fi
    ESTADO=$(Q "$PG_PORT" "$BANCO_DB" "SELECT r.status || ' · ' || string_agg(c.status, ',' ORDER BY c.position) || ' · reservado ' || (SELECT count(*) FROM jobs WHERE queue = 'studio-lab' AND reserved_at IS NOT NULL) FROM ultron_studio_lab_runs r JOIN ultron_studio_lab_run_cases c ON c.run_id = r.id WHERE r.id = $RID GROUP BY r.status;")
    caso "[camino $CAMINO] kill -9 del worker dentro del paso: la ejecución queda a medias con su trabajo reservado" "$(printf '%s' "$ESTADO" | grep -q 'reservado 1' && echo 0 || echo 1)" "$ESTADO"
    MON=$( cd "$APPD" && php artisan ultron-studio:monitor --check=queues --dry-run --json 2>/dev/null | campos checks.queues.problems )
    echo "   INFO [camino $CAMINO]: el monitor con el worker muerto y el trabajo reservado: problemas $MON"
    # El paso del tiempo, simulado en la reserva del trabajo: 400 s, más que los 300 de retry_after del carril.
    Q "$PG_PORT" "$BANCO_DB" "UPDATE jobs SET reserved_at = reserved_at - 400, available_at = available_at - 400 WHERE queue = 'studio-lab';" > /dev/null
    S=$(date +%s)
    while [ $(( $(date +%s) - S )) -lt 150 ]; do
      ( cd "$APPD" && php artisan queue:work lab --queue=studio-lab --stop-when-empty --timeout=150 >> "$B/logs/lab-worker-$CAMINO-b.log" 2>&1 ) || true
      FIN=$(Q "$PG_PORT" "$BANCO_DB" "SELECT status FROM ultron_studio_lab_runs WHERE id = $RID;")
      case "$FIN" in completed|stopped|cancelled) break ;; esac
      # Los pasos diferidos (rescate a los 30 s, reencolado) se adelantan: el banco no espera relojes.
      Q "$PG_PORT" "$BANCO_DB" "UPDATE jobs SET available_at = extract(epoch from now())::int WHERE queue = 'studio-lab' AND available_at > extract(epoch from now())::int;" > /dev/null
      sleep 1
    done
    FINAL=$(Q "$PG_PORT" "$BANCO_DB" "SELECT r.status || ' · ' || string_agg(c.status || coalesce('/' || c.error_code, ''), ',' ORDER BY c.position) FROM ultron_studio_lab_runs r JOIN ultron_studio_lab_run_cases c ON c.run_id = r.id WHERE r.id = $RID GROUP BY r.status;")
    FALLIDO=$(Q "$PG_PORT" "$BANCO_DB" "SELECT count(*) FROM failed_jobs WHERE queue = 'studio-lab' AND payload LIKE '%StudioLabStepJob%' AND failed_at > now() - interval '5 minutes';")
    if [ "$CAMINO" = 1 ]; then
      caso "[camino 1] sin caso reclamado: el trabajo se da por fallido (intentos agotados), el paso de rescate sigue y la ejecución TERMINA" "$(printf '%s' "$FINAL" | grep -qE '^completed · done,done$' && echo 0 || echo 1)" "$FINAL · trabajos fallidos $FALLIDO · $(( $(date +%s) - S )) s"
    else
      caso "[camino 2] con el caso reclamado: el caso huérfano se cierra como error (worker perdido) y la ejecución TERMINA" "$(printf '%s' "$FINAL" | grep -qE '^completed · error/[a-z_]+,done$' && echo 0 || echo 1)" "$FINAL · trabajos fallidos $FALLIDO · $(( $(date +%s) - S )) s"
    fi
    API_ESTADO=$(curl "${H[@]}" "$API/admin/ultron-studio/lab/runs/$RUUID" | campos data.summary.status data.status)
    caso "[camino $CAMINO] la API enseña la ejecución terminada" "$(printf '%s' "$API_ESTADO" | grep -qE 'completed' && echo 0 || echo 1)" "$API_ESTADO"
  done
  Q "$PG_PORT" "$BANCO_DB" "UPDATE admins SET status = 'inactive' WHERE email = 'operadora-banco-h7@local';" > /dev/null
  Q "$PG_PORT" "$BANCO_DB" "DELETE FROM admin_sessions WHERE admin_id = (SELECT id FROM admins WHERE email = 'operadora-banco-h7@local');" > /dev/null
fi
echo "RECUPERACIÓN EN EL BANCO: $((CASOS - FALLOS))/$CASOS OK"
[ "$FALLOS" = 0 ]
