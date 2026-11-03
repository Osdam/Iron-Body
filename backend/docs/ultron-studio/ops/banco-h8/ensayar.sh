#!/bin/bash
# Envoltorio de los ensayos del banco del Hito 8 (OPS8-a). TODO proceso PHP del banco va con RED (comun.sh): lo que no
# sea 127.0.0.1 sale por la valla, que lo bloquea y lo anota. Los sustitutos de $B/bin van delante del PATH.
#   bash ensayar.sh <banco> arrancar | parar | estado    la valla, los dos simuladores y la API (artisan serve, 4 workers)
#   bash ensayar.sh <banco> valla                          la valla caza las sondas http y https, lo local no pasa por ella
#                                                          y los procesos de la API llevan el proxy en su entorno
#   bash ensayar.sh <banco> doctor                         voice:doctor de la «producción» contra el simulador: fase 1 sin
#                                                          red (NOT_CONFIGURED) y --verify con las piezas en el entorno de
#                                                          UN proceso (credentials_ok, un GET, 0 violaciones)
#   bash ensayar.sh <banco> worker revisar|comprobar|instalar|retirar
#                                                          el BORRADOR de supervisor de ironbody-voice: sus propiedades, su
#                                                          orden contra la «producción» (¿existe la conexión voice?) y su
#                                                          instalación simulada (13 → 14 programas y vuelta)
#   bash ensayar.sh <banco> cierre                         0 violaciones en los dos simuladores y la valla sin más que sondas
#   bash ensayar.sh <banco> desplegar|smoke|alcance        de OPS8-b (tras la puerta 1): todavía no existen
# Sin banco: node sim-pruebas.cjs (las pruebas propias de los simuladores y la valla).
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
  local f="$B/run/$1.pid" pid arbol i p vivo
  [ -f "$f" ] || return 0
  pid=$(cat "$f"); arbol="$(descendientes "$pid") $pid"
  kill -TERM $arbol 2> /dev/null || true
  for i in $(seq 1 50); do vivo=0; for p in $arbol; do kill -0 "$p" 2> /dev/null && vivo=1; done; [ "$vivo" = 0 ] && break; sleep 0.1; done
  kill -KILL $arbol 2> /dev/null || true
  rm -f "$f"
}
# Nunca falla: lsof sin resultados sale con 1 y, con pipefail y set -e, abortaría una asignación.
escuchando() { { lsof -nP -iTCP:"$1" -sTCP:LISTEN 2> /dev/null || true; } | tail -n +2 | awk '{print $2}' | sort -u | tr '\n' ' '; }
espera_http() { local i; for i in $(seq 1 "$2"); do curl -s -o /dev/null --max-time 2 "$1" && return 0; sleep 0.5; done; return 1; }

case "$ORDEN" in
  arrancar)
    for p in "$PUERTO_VALLA" "$PUERTO_EL" "$PUERTO_TW" "$BANCO_PORT"; do [ -z "$(escuchando "$p")" ] || muere "el puerto $p ya está ocupado (pid $(escuchando "$p")): no arranco encima"; done
    arranca valla "$B" node "$AQUI/valla.cjs" --log "$B/logs/valla.jsonl" --port "$PUERTO_VALLA"
    arranca sim-twilio "$B" node "$AQUI/sim-twilio.cjs" --config "$B/sim/piezas.conf" --log "$B/logs/sim-twilio.jsonl" --port "$PUERTO_TW"
    arranca sim-elevenlabs "$B" node "$AQUI/sim-elevenlabs.cjs" --config "$B/sim/piezas.conf" --log "$B/logs/sim-elevenlabs.jsonl" --port "$PUERTO_EL"
    for p in "$PUERTO_VALLA" "$PUERTO_TW" "$PUERTO_EL"; do espera_http "http://127.0.0.1:$p/__stats" 20 || muere "no responde :$p (ver $B/logs/*.out)"; done
    # --no-reload: sin él, artisan serve ignora PHP_CLI_SERVER_WORKERS y atiende de una en una.
    arranca api "$BE" env "${RED[@]}" PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port="$BANCO_PORT" --no-reload
    for _ in $(seq 1 40); do
      c=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "http://127.0.0.1:$BANCO_PORT/api/admin/ultron-studio/status" || true)
      [ "$c" = 401 ] && break
      sleep 0.5
    done
    [ "$c" = 401 ] || muere "la API no respondió (ver $B/logs/api.out)"
    echo "banco en marcha: valla :$PUERTO_VALLA · ElevenLabs :$PUERTO_EL · Twilio :$PUERTO_TW · API :$BANCO_PORT (status sin token → 401)" ;;
  parar)
    for n in api sim-elevenlabs sim-twilio valla; do para "$n"; done
    sleep 0.5
    for p in "$BANCO_PORT" "$PUERTO_EL" "$PUERTO_TW" "$PUERTO_VALLA"; do [ -z "$(escuchando "$p")" ] || muere "el puerto $p sigue ocupado (pid $(escuchando "$p")): pararlo a mano"; done
    echo "banco parado" ;;
  estado)
    for x in "valla:$PUERTO_VALLA" "sim-elevenlabs:$PUERTO_EL" "sim-twilio:$PUERTO_TW" "api:$BANCO_PORT"; do
      n=${x%%:*}; p=${x#*:}; l=$(escuchando "$p")
      if [ -n "$l" ]; then echo "$n :$p escuchando (pid ${l% })"; else echo "$n :$p parado"; fi
    done ;;

  valla)
    vivo "$PUERTO_VALLA" && vivo "$PUERTO_EL" && vivo "$PUERTO_TW" || muere "arranca antes: bash $AQUI/ensayar.sh $B arrancar"
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
    R=$(cd "$BE" && env "${RED[@]}" php -r "$PHP_SONDA" "$BE" https://h8-valla-sonda.invalid/x http://h8-valla-sonda.invalid/x "http://127.0.0.1:$PUERTO_EL/__stats" "http://127.0.0.1:$PUERTO_TW/__stats")
    read -r R1 R2 R3 R4 <<< "$R"
    Z=$(stats "$PUERTO_VALLA")
    n_https=$(printf '%s' "$Z" | python3 -I -c 'import json, sys; print(json.load(sys.stdin)["byHost"].get("h8-valla-sonda.invalid:443", 0))')
    n_http=$(printf '%s' "$Z" | python3 -I -c 'import json, sys; print(json.load(sys.stdin)["byHost"].get("h8-valla-sonda.invalid", 0))')
    a_https=$(printf '%s' "$A" | python3 -I -c 'import json, sys; print(json.load(sys.stdin)["byHost"].get("h8-valla-sonda.invalid:443", 0))')
    a_http=$(printf '%s' "$A" | python3 -I -c 'import json, sys; print(json.load(sys.stdin)["byHost"].get("h8-valla-sonda.invalid", 0))')
    locales=$(printf '%s' "$Z" | campo localThroughFence)
    caso "https a un host de fuera: el cliente de Laravel no llega (la valla corta el CONNECT)" "$([ "$R1" = ConnectionException ] && echo 0 || echo 1)" "$R1"
    caso "http a un host de fuera: 403 de la valla" "$([ "$R2" = 403 ] && echo 0 || echo 1)" "$R2"
    caso "la valla anotó la sonda https (h8-valla-sonda.invalid:443)" "$([ "$n_https" -gt "$a_https" ] && echo 0 || echo 1)" "$a_https → $n_https"
    caso "la valla anotó la sonda http (h8-valla-sonda.invalid)" "$([ "$n_http" -gt "$a_http" ] && echo 0 || echo 1)" "$a_http → $n_http"
    caso "PHP del banco alcanza los dos simuladores SIN la valla (no_proxy)" "$([ "$R3 $R4" = "200 200" ] && echo 0 || echo 1)" "$R3 $R4"
    caso "ningún tráfico local entró por la valla" "$([ "$locales" = 0 ] && echo 0 || echo 1)" "localThroughFence=$locales"
    # El entorno de los procesos de la API (php -S): `ps eww` enseña el entorno de los procesos propios en macOS.
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

  doctor)
    vivo "$PUERTO_VALLA" && vivo "$PUERTO_EL" || muere "arranca antes: bash $AQUI/ensayar.sh $B arrancar"
    E0=$(stats "$PUERTO_EL"); V0=$(stats "$PUERTO_VALLA")
    F1=$(cd "$BE" && env "${RED[@]}" php artisan voice:doctor --json 2> /dev/null || true)
    E1=$(stats "$PUERTO_EL")
    st=$(printf '%s' "$F1" | campo state)
    caso "fase 1: voice:doctor = ELEVENLABS_NOT_CONFIGURED (la aplicación no tiene ninguna pieza de voz)" "$([ "$st" = ELEVENLABS_NOT_CONFIGURED ] && echo 0 || echo 1)" "$st"
    caso "fase 1: ninguna petición al simulador de ElevenLabs" "$([ "$(printf '%s' "$E0" | campo requests)" = "$(printf '%s' "$E1" | campo requests)" ] && echo 0 || echo 1)"
    # Fase 2 simulada en UN proceso: las piezas del simulador en su entorno (leídas del fichero en 600 por PHP, nunca en
    # la línea de órdenes) y APP_CONFIG_CACHE a un fichero que no existe, para que valga el entorno y no la caché.
    read -r -d '' PHP_DOCTOR <<'PHP' || true
[$_, $dir, $piezas] = $argv;
$p = [];
foreach (file($piezas, FILE_IGNORE_NEW_LINES) as $l) { if ($l === '' || $l[0] === '#' || ! str_contains($l, '=')) { continue; } [$k, $v] = explode('=', $l, 2); $p[trim($k)] = trim($v); }
$env = ['ELEVENLABS_ENABLED' => 'true', 'ELEVENLABS_API_KEY' => $p['EL_API_KEY'], 'ELEVENLABS_AGENT_ID' => $p['EL_VERIFY_AGENT_ID'],
    'ELEVENLABS_WEBHOOK_SECRET' => $p['EL_WEBHOOK_SECRET'], 'ELEVENLABS_REGION' => 'default', 'ELEVENLABS_BASE_URL' => $p['EL_BASE'],
    'APP_CONFIG_CACHE' => $dir.'/bootstrap/cache/banco-sin-cache-de-config.php'];
foreach ($env as $k => $v) { putenv("{$k}={$v}"); $_ENV[$k] = $v; $_SERVER[$k] = $v; }
unset($p, $env);
require $dir.'/vendor/autoload.php';
$app = require $dir.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$codigo = $kernel->call('voice:doctor', ['--verify' => true, '--json' => true]);
echo $kernel->output();
exit($codigo);
PHP
    F2=$(cd "$BE" && env "${RED[@]}" php -r "$PHP_DOCTOR" "$BE" "$B/sim/piezas.conf" 2> /dev/null) && rc=0 || rc=$?
    E2=$(stats "$PUERTO_EL"); V2=$(stats "$PUERTO_VALLA")
    out=$(printf '%s' "$F2" | campo verification.outcome)
    ulaw=$(printf '%s' "$F2" | campo verification.agentAudioUlaw8000)
    caso "fase 2 simulada: voice:doctor --verify = credentials_ok y sale con 0" "$([ "$out" = credentials_ok ] && [ "$rc" = 0 ] && echo 0 || echo 1)" "$out, código $rc"
    caso "el simulador sirve ulaw_8000 en tts y asr (lo que mira el adaptador del H6)" "$([ "$ulaw" = True ] || [ "$ulaw" = true ] && echo 0 || echo 1)" "$ulaw"
    dreq=$(( $(printf '%s' "$E2" | campo requests) - $(printf '%s' "$E1" | campo requests) ))
    dget=$(( $(printf '%s' "$E2" | python3 -I -c 'import json, sys; print(json.load(sys.stdin)["byRoute"].get("/v1/convai/agents/{agent_id}", 0))') - $(printf '%s' "$E1" | python3 -I -c 'import json, sys; print(json.load(sys.stdin)["byRoute"].get("/v1/convai/agents/{agent_id}", 0))') ))
    dvio=$(( $(printf '%s' "$E2" | campo violations) - $(printf '%s' "$E1" | campo violations) ))
    caso "exactamente UNA petición al simulador, y es GET /v1/convai/agents/{agent_id}" "$([ "$dreq" = 1 ] && [ "$dget" = 1 ] && echo 0 || echo 1)" "peticiones +$dreq, GET del agente +$dget"
    caso "0 violaciones nuevas en el simulador (clave en xi-api-key, sin Authorization, sin cuerpo)" "$([ "$dvio" = 0 ] && echo 0 || echo 1)" "+$dvio"
    caso "la valla no vio nada (todo fue a 127.0.0.1)" "$([ "$(printf '%s' "$V0" | campo blocked)" = "$(printf '%s' "$V2" | campo blocked)" ] && echo 0 || echo 1)"
    echo "$((CASOS - FALLOS)) OK · $FALLOS FALLO"
    [ "$FALLOS" = 0 ] ;;

  worker)
    SUB=${1:-revisar}
    case "$SUB" in
      revisar)
        python3 -I - "$SUPERVISOR_VOZ" <<'PY'
import configparser, re, sys
c = configparser.RawConfigParser(strict=True)
c.read(sys.argv[1], encoding='utf-8')
fallos = 0
def caso(nombre, ok, detalle=''):
    global fallos
    print(('OK      ' if ok else 'FALLO   ') + nombre + (f'  [{detalle}]' if detalle else ''))
    fallos += 0 if ok else 1
secciones = c.sections()
caso('un único programa, [program:ironbody-voice]', secciones == ['program:ironbody-voice'], ', '.join(secciones))
p = c['program:ironbody-voice'] if 'program:ironbody-voice' in c else {}
cmd = p.get('command', '')
caso('queue:work con la CONEXIÓN voice y la cola voice (D8-4)', re.search(r'\bqueue:work voice --queue=voice\b', cmd) is not None, cmd[:90])
caso('nunca el carril del puente de n8n (commercial) ni el del laboratorio (studio-lab)', re.search(r'commercial|studio-lab', cmd) is None)
caso('--tries=1 (un reintento podría marcar dos veces: R-5, D8-12)', re.search(r'--tries=1\b', cmd) is not None)
m = re.search(r'--timeout=(\d+)', cmd)
t = int(m.group(1)) if m else None
caso('--timeout por encima del plazo de 30 s del job (D8-12)', t is not None and t > 30, f'--timeout={t}')
caso('stopwaitsecs > --timeout (la petición en vuelo termina y queda registrada)', t is not None and int(p.get('stopwaitsecs', '0')) > t, f"stopwaitsecs={p.get('stopwaitsecs')}")
caso('numprocs=1 (VOICE_MAX_CONCURRENT_CALLS=1, D8-15)', p.get('numprocs') == '1')
caso('user=www-data (dueño de la caché de configuración en 600)', p.get('user') == 'www-data')
caso('stopasgroup y killasgroup (no quedan nietos huérfanos)', p.get('stopasgroup') == 'true' and p.get('killasgroup') == 'true')
caso('autostart y autorestart', p.get('autostart') == 'true' and p.get('autorestart') == 'true')
caso('--max-jobs y --max-time (se recicla como los demás)', re.search(r'--max-jobs=\d+', cmd) is not None and re.search(r'--max-time=\d+', cmd) is not None)
env = p.get('environment', '')
caso('environment= sin piezas: solo APP_ENV, HOME y USER', sorted(x.split('=')[0] for x in env.split(',') if x) == ['APP_ENV', 'HOME', 'USER'], env)
caso('registro propio en storage/logs (worker-voice.log)', p.get('stdout_logfile', '').endswith('/storage/logs/worker-voice.log'))
print(f'{13 - fallos} OK · {fallos} FALLO')
sys.exit(1 if fallos else 0)
PY
        ;;
      comprobar)
        # 1) ¿Lleva el código que se despliega la conexión `voice`? (estático, sobre el origen del banco)
        if grep -qE "^\s*'voice'\s*=>\s*\[" "$B/src/backend/config/queue.php" 2> /dev/null; then
          echo "OK      el origen ($ESPERADO) declara la conexión voice en config/queue.php"
        else
          echo "PENDIENTE el origen ($ESPERADO) NO declara la conexión voice en config/queue.php (requisito previo del worker; dueño: lo asigna el lead)"
        fi
        # 2) La orden LITERAL del borrador, contra la «producción» del banco, una vez (--stop-when-empty) y por la valla.
        CMD=$(sed -n 's/^command=//p' "$SUPERVISOR_VOZ")
        [ -n "$CMD" ] || muere "el borrador no tiene command="
        CMD=${CMD/\/usr\/bin\/php/$(command -v php)}
        CMD=${CMD/\/var\/www\/api\/backend\/artisan/$BE/artisan}
        read -r -a PARTES <<< "$CMD"
        SALIDA=$(cd "$BE" && env "${RED[@]}" "${PARTES[@]}" --stop-when-empty 2>&1) && rc=0 || rc=$?
        if [ "$rc" = 0 ]; then
          echo "OK      la orden del borrador arranca contra la «producción» ($(git -C "$B/api" rev-parse --short=7 HEAD)) y sale vacía (sin trabajos)"
        elif printf '%s' "$SALIDA" | grep -q 'queue connection has not been configured\|\[voice\] queue connection'; then
          echo "PENDIENTE la «producción» del banco ($(git -C "$B/api" rev-parse --short=7 HEAD)) no tiene la conexión voice: con este código el worker quedaría en FATAL (se espera hasta el ensayo del despliegue de OPS8-b)"
          exit 3
        else
          echo "FALLO   la orden del borrador salió con $rc: $(printf '%s' "$SALIDA" | grep -v '^\s*$' | head -3 | cut -c1-200 | tr '\n' ' ')"
          exit 1
        fi ;;
      instalar)
        cp "$SUPERVISOR_VOZ" "$B/etc/supervisor/conf.d/ironbody-voice.conf"
        echo "reread: $(supervisorctl reread | tr '\n' ' ') · update: $(supervisorctl update | tr '\n' ' ')"
        echo "programas RUNNING: $(supervisorctl status | grep -c RUNNING) (14 con el worker de voz)" ;;
      retirar)
        rm -f "$B/etc/supervisor/conf.d/ironbody-voice.conf"
        echo "reread: $(supervisorctl reread | tr '\n' ' ') · update: $(supervisorctl update | tr '\n' ' ')"
        echo "programas RUNNING: $(supervisorctl status | grep -c RUNNING) (13 sin el worker de voz)" ;;
      *) muere "uso: bash ensayar.sh <banco> worker revisar|comprobar|instalar|retirar" ;;
    esac ;;

  cierre)
    vivo "$PUERTO_VALLA" && vivo "$PUERTO_EL" && vivo "$PUERTO_TW" || muere "arranca antes: bash $AQUI/ensayar.sh $B arrancar"
    se=$(stats "$PUERTO_EL"); st=$(stats "$PUERTO_TW"); sv=$(stats "$PUERTO_VALLA")
    caso "0 violaciones en el simulador de ElevenLabs" "$([ "$(printf '%s' "$se" | campo violations)" = 0 ] && echo 0 || echo 1)" "$(printf '%s' "$se" | campo violationsByCode)"
    caso "0 violaciones en el simulador de Twilio" "$([ "$(printf '%s' "$st" | campo violations)" = 0 ] && echo 0 || echo 1)" "$(printf '%s' "$st" | campo violationsByCode)"
    otros=$(printf '%s' "$sv" | python3 -I -c 'import json, sys; print(" ".join(h for h in json.load(sys.stdin)["byHost"] if h not in ("h8-valla-sonda.invalid", "h8-valla-sonda.invalid:443")))')
    caso "la valla no vio más hosts que sus sondas" "$([ -z "$otros" ] && echo 0 || echo 1)" "${otros:-ninguno}"
    caso "ningún tráfico local por la valla" "$([ "$(printf '%s' "$sv" | campo localThroughFence)" = 0 ] && echo 0 || echo 1)"
    echo "$((CASOS - FALLOS)) OK · $FALLOS FALLO"
    [ "$FALLOS" = 0 ] ;;

  desplegar|smoke|alcance)
    muere "«$ORDEN» es de OPS8-b (tras la puerta 1): deploy-h8-backend-fase1.sh, smoke-run-h8.sh y h8-voice-scope.sh todavía no existen" ;;
  *) muere "uso: bash ensayar.sh <banco> arrancar|parar|estado|valla|doctor|worker …|cierre" ;;
esac
