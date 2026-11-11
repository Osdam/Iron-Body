#!/bin/bash
# El banco de los HECHOS de la voz y del recuento del monitor del H7 (plans/informes-motores/informe-devops-hechos-voz.md).
# Ensaya, sobre el banco de la ola M-1 (m1.sh: el código de b1959a0 con el estado de producción, H9 en sombra y el
# laboratorio hablado en 2a, en su fichero de entorno SINTÉTICO), sin cambiar m1.sh ni sus copias:
#   · h8-voice-scope.sh «hechos --sede=… --horario=…» y «hechos borrar» (tarea 1): los dos valores reales, tildes y ñ,
#     el límite de 300, 21 rechazos sin tocar nada, la repetición idempotente, uno solo, el borrado, idas y vueltas byte a
#     byte en 640 y en 600, las líneas vacías de .env.example, la vuelta exacta impresa, un fallo forzado en cada etapa
#     restaurado y COMPROBADO, el cerrojo común ocupado, la incoherencia fichero/caché, una clave repetida, registros en
#     600 y CERO red (la valla);
#   · h7-monitor-scope.sh (tarea 2): el recuento de SUS dos tareas con la sincronización de plantillas de Meta apagada y
#     encendida, comparado con el mando de HEAD (que la cuenta y se niega), y encendido y apagado de verdad en los dos
#     casos; h8-voice-scope.sh «expectativa fase1» se niega en el banco (aquí ElevenLabs no está verificado).
#   bash voz-hechos.sh <banco> arrancar    la valla (:PUERTO_VALLA del banco) y la API en un puerto PROPIO
#                                          (BANCO_HECHOS_PORT, 8541 por defecto: el puerto del banco puede estar ocupado)
#   bash voz-hechos.sh <banco> hechos      el ensayo de la tarea 1
#   bash voz-hechos.sh <banco> monitor     el ensayo de la tarea 2
#   bash voz-hechos.sh <banco> parar
# Cada ensayo deja el banco como estaba (el fichero de entorno de partida, byte a byte, su modo y la caché). Los mandos
# son COPIAS del banco de los del árbol: solo cambia su bloque «lo propio de producción», la comprobación de root, el
# dueño admitido en 640 (el grupo del Mac hace de www-data) y, en el de la voz, dos puntos de fallo forzado
# (BANCO_FALLA=fichero|cache). Cada sustitución tiene que casar las veces exactas: si el mando cambia, falla.
# El flock de este banco es el de verdad (fcntl sobre el descriptor heredado), no el sustituto que no bloquea nada.
. "$(dirname "$0")/comun.sh"
B=$(banco_dir "${1:-}")
ORDEN=${2:-}
cargar_banco "$B"
PUERTO_API=${BANCO_HECHOS_PORT:-8541}
URL="http://127.0.0.1:$PUERTO_API/api"
BH="$B/bin-hechos"
export PATH="$BH:$B/bin:$PATH"
BE="$B/api/backend"
ENVF="$BE/.env"
ME=$(whoami); GRUPO=$(id -gn)
SEDE_REAL='Cl. 24 Sur #33-53, Neiva, Huila'
HORARIO_REAL='Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m.'

# ── Procesos (como ensayar.sh, con sus propios pid) ─────────────────────────────────────────────────────────────────
arranca() { local n=$1 d=$2; shift 2; ( cd "$d" || exit 1; nohup "$@" > "$B/logs/$n.out" 2>&1 < /dev/null & echo $! > "$B/run/$n.pid" ); }
descendientes() { local c; for c in $(pgrep -P "$1" 2> /dev/null); do descendientes "$c"; echo "$c"; done; }
para() { local n=$1 p; [ -f "$B/run/$n.pid" ] || return 0; p=$(cat "$B/run/$n.pid"); for c in $(descendientes "$p") "$p"; do kill "$c" 2> /dev/null || true; done; rm -f "$B/run/$n.pid"; }
escuchando() { { lsof -nP -iTCP:"$1" -sTCP:LISTEN 2> /dev/null || true; } | tail -n +2 | awk '{print $2}' | sort -u | tr '\n' ' '; }
valla_total() { curl -s --max-time 3 "http://127.0.0.1:$PUERTO_VALLA/__stats" | python3 -I -c 'import json, sys; d = json.load(sys.stdin); print(int(d["blocked"]) + int(d.get("localThroughFence", 0)))' 2> /dev/null || echo '?'; }

# ── Las copias del banco ────────────────────────────────────────────────────────────────────────────────────────────
copia() { # origen destino reglas(JSON: [[viejo, nuevo, veces], …]); veces 0 = al menos una
  python3 -I - "$1" "$2" "$3" <<'PY'
import json, sys
origen, destino, reglas = sys.argv[1], sys.argv[2], json.loads(sys.argv[3])
s = open(origen, encoding="utf-8").read()
for viejo, nuevo, veces in reglas:
    n = s.count(viejo)
    if n == 0 or (veces and n != veces):
        sys.exit(f"voz-hechos: «{viejo[:60]}» aparece {n} veces en {origen} (esperado {veces or '≥ 1'}): el mando cambió")
    s = s.replace(viejo, nuevo)
open(destino, "w", encoding="utf-8").write(s)
PY
  chmod 700 "$2"
  bash -n "$2"
}
preparar() {
  mkdir -p "$B/root" "$BH"
  # flock -n FD de verdad: el cerrojo es de la descripción de fichero abierta que comparte el mando, y sigue puesto al
  # salir este proceso (flock(2) de BSD y de Linux).
  printf '%s\n' '#!/bin/bash' '# flock del banco de los hechos: «flock -n FD» de verdad (fcntl.flock sobre el descriptor heredado).' \
    '[ "${1:-}" = -n ] && [[ "${2:-}" =~ ^[0-9]+$ ]] || { echo "flock del banco: solo «flock -n FD»" >&2; exit 64; }' \
    "exec python3 -I -c 'import fcntl, sys" 'try:' '    fcntl.flock(int(sys.argv[1]), fcntl.LOCK_EX | fcntl.LOCK_NB)' 'except BlockingIOError:' "    sys.exit(1)' \"\$2\"" > "$BH/flock"
  chmod 755 "$BH/flock"
  local reglas
  reglas=$(python3 -I -c 'import json, sys
be, url, me, grupo, b = sys.argv[1:6]
print(json.dumps([
  ["[ \"$(id -u)\" = 0 ]", "true", 1],
  ["APPDIR=/var/www/api/backend\n", f"APPDIR={be}\n", 1],
  ["API=https://api.ironbodyneiva.cloud/api\n", f"API={url}\n", 1],
  ["WWW=www-data\n", f"WWW={me}\n", 1], ["ROOT_U=root\n", f"ROOT_U={me}\n", 1], ["ROOT_G=root\n", f"ROOT_G={grupo}\n", 1],
  ["COPIAS=/root\n", f"COPIAS={b}/root\n", 1],
  ["CERROJO=/run/lock/h8-voice-scope.lock\n", f"CERROJO={b}/run/h8-voice-scope.lock\n", 1],
  ["NIVEL_ERROR=production.ERROR\n", "NIVEL_ERROR=local.ERROR\n", 1],
  ["= \"$ROOT_U:$WWW 640\" ]", "= \"$ROOT_U:$ROOT_G 640\" ]", 1],
  ["H1=$(leer_hechos)", "H1=$(leer_hechos); [ \"${BANCO_FALLA:-}\" != cache ] || H1=falla-forzada-del-banco", 1],
  ["F1=$(hechos_fichero)", "F1=$(hechos_fichero); [ \"${BANCO_FALLA:-}\" != fichero ] || F1=falla-forzada-del-banco", 1],
]))' "$BE" "$URL" "$ME" "$GRUPO" "$B")
  copia "$OPS/h8-voice-scope.sh" "$B/root/h8-voice-scope.sh" "$reglas"
  reglas=$(python3 -I -c 'import json, sys
b, url, me, grupo = sys.argv[1:5]
print(json.dumps([
  ["/var/www/api", f"{b}/api", 0], ["https://api.ironbodyneiva.cloud/api", url, 1], ["[ \"$(id -u)\" = 0 ]", "true", 1],
  ["/root/", f"{b}/root/", 0], ["install -m 600 -o root -g root", "install -m 600", 1], ["= www-data ]", f"= {me} ]", 1],
  ["ENV_600=www-data", f"ENV_600={me}", 1], ["ENV_640=root:www-data", f"ENV_640={me}:{grupo}", 1],
]))' "$B" "$URL" "$ME" "$GRUPO")
  copia "$OPS/h7-monitor-scope.sh" "$B/root/h7-monitor-scope.sh" "$reglas"
  # El mando del H7 de HEAD (el que está en producción hoy), con las mismas sustituciones: para enseñar el fallo.
  git -C "$WT" show HEAD:backend/docs/ultron-studio/ops/h7-monitor-scope.sh > "$B/root/h7-monitor-scope-head.orig"
  copia "$B/root/h7-monitor-scope-head.orig" "$B/root/h7-monitor-scope-head.sh" "$reglas"
  rm -f "$B/root/h7-monitor-scope-head.orig"
  local f
  for f in h8-voice-scope.sh h7-monitor-scope.sh h7-monitor-scope-head.sh; do
    ! grep -v '^[[:space:]]*#' "$B/root/$f" | grep -nE '/var/www|(^|[^A-Za-z0-9_./-])/root/|/run/lock|ironbodyneiva' || muere "la copia del banco $f todavía nombra rutas o la URL de producción: el mando cambió"
  done
  echo "   copias del banco: h8-voice-scope.sh (12 sustituciones: el bloque propio, root, el grupo de 640 y los dos fallos forzados), h7-monitor-scope.sh y el de HEAD (8 cada uno); flock de verdad en $BH"
}
mando() { ( export "${RED[@]}"; bash "$B/root/$1" "${@:2}" ); }
mando_falla() { ( export "${RED[@]}" BANCO_FALLA="$1"; bash "$B/root/h8-voice-scope.sh" "${@:2}" ); }   # fichero | cache
cache_banco() { ( cd "$BE" && env "${RED[@]}" php artisan config:cache -q && chmod 600 bootstrap/cache/config.php ); }
tinker() { ( cd "$BE" && env "${RED[@]}" php artisan tinker --execute="$1" 2> /dev/null | tail -1 ); }
# Lo que diría la herramienta, leído de forma INDEPENDIENTE del mando: VoiceTools::hours() (consultar_horarios) por
# reflexión y config('voice.facts.*'), en base64 (se compara aquí, no se imprime).
herramienta() { tinker '$m = new ReflectionMethod(App\Services\Telephony\VoiceTools::class, "hours"); $m->setAccessible(true); $r = $m->invoke(null); echo json_encode(["disponible" => $r["disponible"], "sede" => base64_encode((string) ($r["sede"] ?? "")), "horario" => base64_encode((string) ($r["horario"] ?? "")), "c_sede" => base64_encode(config("voice.facts.site")), "c_horario" => base64_encode(config("voice.facts.hours"))]), PHP_EOL;'; }
b64() { printf '%s' "$1" | base64 | tr -d '\n'; }
dice() { # sede horario → 0 si la configuración efectiva y la herramienta dan EXACTAMENTE eso (vacíos: «no los tiene»)
  local h; h=$(herramienta)
  H="$h" S="$(b64 "$1")" O="$(b64 "$2")" python3 -I -c 'import json, os, sys
d = json.loads(os.environ["H"]); s, o = os.environ["S"], os.environ["O"]
ok = d["c_sede"] == s and d["c_horario"] == o
ok = ok and (d["disponible"] is True and d["sede"] == s and d["horario"] == o if s and o else d["disponible"] is False)
sys.exit(0 if ok else 1)' 2> /dev/null
}
FALLOS=0; CASOS=0
paso() { # nombre código_esperado texto_esperado orden…
  local nombre=$1 esperado=$2 texto=$3 salida rc
  shift 3
  salida=$("$@" 2>&1); rc=$?
  printf '%s\n' "$salida" | sed "s#$B#\$B#g; s/^/      /"
  caso "$nombre" "$([ "$rc" = "$esperado" ] && printf '%s' "$salida" | grep -qF -- "$texto" && echo 0 || echo 1)" "código $rc"
  ULTIMA=$salida
}
igual() { caso "$1" "$(cmp -s "$ENVF" "$2" && echo 0 || echo 1)"; }
modo() { caso "$1: el fichero de entorno sigue en $2 y de $ME" "$([ "$(stat -c '%a %U' "$ENVF")" = "$2 $ME" ] && echo 0 || echo 1)" "$(stat -c '%a %U' "$ENVF")"; }
lineas() { grep -cxF -- "$1" "$ENVF" || true; }    # cuántas líneas EXACTAS (solo la cuenta; nada del fichero se imprime)
copias() { ls "$B"/root/env-pre-voice-hechos* 2> /dev/null | wc -l | tr -d ' '; }
registros() { ls "$B"/root/h8-voice-scope-hechos*.log 2> /dev/null | wc -l | tr -d ' '; }
pon_lineas() { # escribe a mano (fuera del mando) líneas de prueba en el fichero sintético; sin caché salvo que se pida
  python3 -I - "$ENVF" "$@" <<'PY'
import sys
p = sys.argv[1]
s = open(p, encoding="utf-8", newline="").read()
for l in sys.argv[2:]:
    s = (s if s.endswith("\n") else s + "\n") + l + "\n"
open(p, "w", encoding="utf-8", newline="").write(s)
PY
}
pon_env() { # CLAVE VALOR en el fichero sintético (fuera de los mandos, para preparar un estado), sin caché
  python3 -I -c 'import re, sys
p, k, v = sys.argv[1:4]
s = open(p, encoding="utf-8", newline="").read()
s, n = re.subn(r"^([ \t]*)" + k + r"=[^\r\n]*", lambda m: m.group(1) + k + "=" + v, s, count=1, flags=re.M)
if n == 0:
    s = (s if s.endswith("\n") else s + "\n") + "  " + k + "=" + v + "\n"
open(p, "w", encoding="utf-8", newline="").write(s)' "$ENVF" "$1" "$2"
}
listo() {
  [ "$(git -C "$B/api" rev-parse --short=7 HEAD)" = "$ESPERADO" ] || muere "el banco no está desplegado ($ESPERADO): bash $AQUI/m1.sh $B desplegar"
  [ -n "$(escuchando "$PUERTO_API")" ] || muere "arranca antes: bash $0 $B arrancar"
  [ "$(valla_total)" != '?' ] || muere "la valla no responde en :$PUERTO_VALLA: bash $0 $B arrancar"
}

case "$ORDEN" in
  arrancar)
    [ -z "$(escuchando "$PUERTO_API")" ] || muere "el puerto $PUERTO_API ya está ocupado (pid $(escuchando "$PUERTO_API")): BANCO_HECHOS_PORT=<otro>"
    if [ "$(valla_total)" = '?' ]; then
      [ -z "$(escuchando "$PUERTO_VALLA")" ] || muere "el puerto de la valla $PUERTO_VALLA está ocupado por otra cosa"
      arranca valla-hechos "$B" node "$VALLA_CJS" --log "$B/logs/valla-hechos.jsonl" --port "$PUERTO_VALLA"
      for _ in $(seq 1 40); do [ "$(valla_total)" != '?' ] && break; sleep 0.5; done
    fi
    arranca api-hechos "$BE" env "${RED[@]}" PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port="$PUERTO_API" --no-reload
    for _ in $(seq 1 40); do
      c=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$URL/admin/voice/status" || true)
      [ "$c" = 401 ] && break
      sleep 0.5
    done
    [ "$c" = 401 ] || muere "la API no respondió (ver $B/logs/api-hechos.out)"
    echo "banco de los hechos en marcha: valla :$PUERTO_VALLA (peticiones hasta ahora: $(valla_total)) · API :$PUERTO_API (admin/voice/status sin token → 401)" ;;
  parar)
    para api-hechos; para valla-hechos; sleep 0.5
    [ -z "$(escuchando "$PUERTO_API")" ] || muere "el puerto $PUERTO_API sigue ocupado"
    echo "banco de los hechos parado" ;;

  hechos)
    listo
    preparar
    set +e
    MODO_ORIG=$(stat -c '%a' "$ENVF")
    install -m 600 "$ENVF" "$B/root/env-ref-h0"
    rm -f "$B"/root/env-pre-voice-hechos* "$B"/root/h8-voice-scope-hechos*.log
    chmod 640 "$ENVF"
    V0=$(valla_total)
    LSEDE="  VOICE_FACTS_SITE=\"$SEDE_REAL\""; LHORARIO="  VOICE_FACTS_HOURS=\"$HORARIO_REAL\""
    echo "#### partida: sin hechos en el fichero, la herramienta dice que no los tiene; valla en $V0 peticiones"
    caso "partida: ninguna línea VOICE_FACTS_* en el fichero sintético" "$([ "$(grep -cE '^[[:space:]]*VOICE_FACTS_' "$ENVF")" = 0 ] && echo 0 || echo 1)"
    caso "partida: consultar_horarios dice que no los tiene (lectura independiente)" "$(dice '' '' && echo 0 || echo 1)"
    paso "estado: enseña los hechos (huellas) y que la herramienta no los dice" 0 "hechos de consultar_horarios (huellas, nunca el texto): sede: vacía · horario: vacía" mando h8-voice-scope.sh estado
    paso "uso: la ayuda enseña las dos órdenes de «hechos»" 2 "hechos borrar" mando h8-voice-scope.sh

    echo "#### rechazos de la forma (código 2, sin registro, sin copia, sin tocar el fichero)"
    LARGO=$(python3 -I -c 'print("a" * 301)')
    rechazo() { local n=$1 t=$2; shift 2; paso "rechazo: $n" 2 "$t" mando h8-voice-scope.sh hechos "$@"; igual "  … el fichero no cambió" "$B/root/env-ref-h0"; }
    rechazo "comillas dobles" "fuera del conjunto admitido (U+0022)" '--sede=Calle "5", Neiva'
    rechazo "comilla simple" "fuera del conjunto admitido (U+0027)" "--sede=Calle 5'B, Neiva"
    rechazo "dólar (interpolación de Dotenv)" "fuera del conjunto admitido (U+0024, U+005F, U+007B, U+007D)" '--sede=Calle ${APP_KEY} 5'
    rechazo "barra invertida" "fuera del conjunto admitido (U+005C)" '--horario=Lunes \n martes'
    rechazo "acento grave" "fuera del conjunto admitido (U+0060)" '--sede=Calle `id` 5'
    rechazo "salto de línea" "fuera del conjunto admitido (U+000A, U+003D, U+005F)" "--sede=$(printf 'Calle 5\nVOICE_LAB_ENABLED=false')"
    rechazo "tabulador" "fuera del conjunto admitido (U+0009)" "--horario=$(printf 'Lunes\t5:00')"
    rechazo "vacío" "la sede vacía" --sede=
    rechazo "301 caracteres" "301 caracteres (máximo 300)" "--horario=$LARGO"
    rechazo "fuera del conjunto (@ y <)" "fuera del conjunto admitido (U+003C, U+003E, U+0040)" '--sede=Calle 5 @ <Neiva>'
    rechazo "emoji" "fuera del conjunto admitido (U+1F4AA)" "--sede=$(printf 'Iron Body \360\237\222\252')"
    rechazo "tilde suelta (NFD)" "tilde SUELTA (NFD)" "--sede=$(printf 'Pen\314\203alisa')"
    rechazo "UTF-8 inválido" "no es UTF-8 válido" "--sede=$(printf 'Calle \377 5')"
    rechazo "espacio al principio" "espacios al principio o al final" '--sede= Calle 5'
    rechazo "palabra que env() convierte" "env() de Laravel convierte" --horario=null
    rechazo "URL" "parece una URL" '--sede=Ver https://ironbody.co'
    rechazo "teléfono" "parecen un teléfono" '--sede=Llamar al 310 123 4567'
    rechazo "argumento desconocido" "argumento desconocido (no se imprime)" '--direccion=Calle 5'
    rechazo "sin argumentos" "hechos borrar"
    rechazo "borrar con otra opción" "va solo" borrar "--sede=$SEDE_REAL"
    rechazo "opción repetida" "--sede va una sola vez" '--sede=Calle 5' '--sede=Calle 6'
    rechazo "solo --en-seco, sin nada que fijar" "falta --sede='…' o --horario='…'" --en-seco
    caso "rechazos: ni copias previas ni registros (se niegan antes)" "$([ "$(copias)" = 0 ] && [ "$(registros)" = 0 ] && echo 0 || echo 1)" "copias $(copias) · registros $(registros)"

    echo "#### en seco: las huellas que escribiría, sin copia ni escritura"
    huella() { printf '%s' "$1" | python3 -I -c 'import hashlib, sys; b = sys.stdin.buffer.read(); print("%d bytes, sha256 %s…" % (len(b), hashlib.sha256(b).hexdigest()[:12]))'; }
    paso "hechos <reales> --en-seco → EN SECO con las huellas de los dos textos" 0 "escribiría (en el fichero): sede: $(huella "$SEDE_REAL") · horario: $(huella "$HORARIO_REAL")" mando h8-voice-scope.sh hechos "--sede=$SEDE_REAL" "--horario=$HORARIO_REAL" --en-seco
    igual "  … el fichero no cambió" "$B/root/env-ref-h0"
    caso "  … sin copia previa" "$([ "$(copias)" = 0 ] && echo 0 || echo 1)" "$(copias)"

    echo "#### los dos valores REALES (en 640)"
    paso "hechos --sede=<real> --horario=<real> → APLICADO" 0 "MANDO hechos APLICADO" mando h8-voice-scope.sh hechos "--sede=$SEDE_REAL" "--horario=$HORARIO_REAL"
    caso "  … la vuelta funcional que imprime es «hechos borrar» (antes no estaban)" "$(printf '%s' "$ULTIMA" | grep -qF 'hechos borrar (antes no estaban en el fichero: vuelve idéntico byte a byte)' && echo 0 || echo 1)"
    caso "  … el fichero tiene EXACTAMENTE las dos líneas, entre comillas dobles, con dos espacios" "$([ "$(lineas "$LSEDE")" = 1 ] && [ "$(lineas "$LHORARIO")" = 1 ] && echo 0 || echo 1)"
    caso "  … config('voice.facts.*') y consultar_horarios dan los dos textos BYTE A BYTE (lectura independiente)" "$(dice "$SEDE_REAL" "$HORARIO_REAL" && echo 0 || echo 1)"
    modo "valores reales" 640
    install -m 600 "$ENVF" "$B/root/env-ref-h1"
    paso "repetir la misma orden → nada que escribir (idempotente)" 0 "nada que escribir" mando h8-voice-scope.sh hechos "--sede=$SEDE_REAL" "--horario=$HORARIO_REAL"
    igual "  … el fichero no cambió" "$B/root/env-ref-h1"
    caso "  … la repetición no hizo copia (una sola, la de la primera)" "$([ "$(copias)" = 1 ] && echo 0 || echo 1)" "$(copias)"

    echo "#### tildes, ñ y el límite de 300 caracteres"
    TILDES='Calle Ñandú 12, Peñalisa (Neiva); Mañana: Ángel, Úrsula, Éxito, Íñigo, Óscar, pingüino, Güiza'
    TRESCIENTOS=$(python3 -I -c 'b = "Lunes a sábado de 6:00 a. m. a 9:00 p. m.; niños y señoras: horario según cartelera. "; s = (b * 5)[:299] + "ñ"; print(s)')
    caso "  (el horario de prueba tiene 300 caracteres y más bytes)" "$([ "$(printf '%s' "$TRESCIENTOS" | python3 -I -c 'import sys; print(len(sys.stdin.buffer.read().decode("utf-8")))')" = 300 ] && echo 0 || echo 1)"
    paso "hechos con tildes, ñ, ü y 300 caracteres → APLICADO" 0 "MANDO hechos APLICADO" mando h8-voice-scope.sh hechos "--sede=$TILDES" "--horario=$TRESCIENTOS"
    caso "  … sustituidas en su sitio (no añadidas)" "$(printf '%s' "$ULTIMA" | grep -q 'VOICE_FACTS_SITE sustituida' && printf '%s' "$ULTIMA" | grep -q 'VOICE_FACTS_HOURS sustituida' && echo 0 || echo 1)"
    caso "  … config y la herramienta dan los dos textos BYTE A BYTE" "$(dice "$TILDES" "$TRESCIENTOS" && echo 0 || echo 1)"
    modo "tildes" 640

    echo "#### fallos forzados a medias (BANCO_FALLA): se restaura solo y se COMPRUEBA"
    install -m 600 "$ENVF" "$B/root/env-ref-h2"
    paso "etapa 1 (el fichero leído por Dotenv no es lo pedido) → código 1, restaurado y COMPROBADO" 1 "restaurado y COMPROBADO" mando_falla fichero hechos "--sede=$SEDE_REAL" "--horario=$HORARIO_REAL"
    caso "  … FALLO A MEDIAS (etapa 1)" "$(printf '%s' "$ULTIMA" | grep -qF 'FALLO A MEDIAS (etapa 1' && echo 0 || echo 1)"
    igual "  … el fichero, idéntico byte a byte al de antes del fallo" "$B/root/env-ref-h2"
    modo "tras el fallo de la etapa 1" 640
    caso "  … la configuración efectiva y la herramienta siguen con los de antes" "$(dice "$TILDES" "$TRESCIENTOS" && echo 0 || echo 1)"
    paso "etapa 2 (la configuración efectiva tras la caché no es la pedida) → código 1, caché regenerada, restaurado y COMPROBADO" 1 "restaurado y COMPROBADO" mando_falla cache hechos "--sede=$SEDE_REAL" "--horario=$HORARIO_REAL"
    caso "  … FALLO A MEDIAS (etapa 2) y la caché regenerada con el fichero de antes" "$(printf '%s' "$ULTIMA" | grep -qF 'FALLO A MEDIAS (etapa 2' && printf '%s' "$ULTIMA" | grep -qF 'caché regenerada con el fichero de antes' && echo 0 || echo 1)"
    igual "  … el fichero, idéntico byte a byte al de antes del fallo" "$B/root/env-ref-h2"
    modo "tras el fallo de la etapa 2" 640
    caso "  … la configuración efectiva y la herramienta siguen con los de antes (la caché nueva se deshizo)" "$(dice "$TILDES" "$TRESCIENTOS" && echo 0 || echo 1)"
    caso "  … las dos copias de los intentos fallidos se conservan (4 en total)" "$([ "$(copias)" = 4 ] && echo 0 || echo 1)" "$(copias)"

    echo "#### el cerrojo común ocupado por otro mando (ironbody-env.lock)"
    # Otro proceso toma ironbody-env.lock (como lo tomaría meta-templates-scope.sh) y lo retiene hasta que se le mata.
    python3 -I -c 'import fcntl, sys, time
f = open(sys.argv[1], "w"); fcntl.flock(f, fcntl.LOCK_EX); open(sys.argv[2], "w").close(); time.sleep(60)' "$B/run/ironbody-env.lock" "$B/run/cerrojo-tomado" &
    TENEDOR=$!
    for _ in $(seq 1 50); do [ -e "$B/run/cerrojo-tomado" ] && break; sleep 0.1; done
    paso "con ironbody-env.lock tomado → ABORTA sin tocar nada" 1 "otro mando que escribe el fichero de entorno está en curso" mando h8-voice-scope.sh hechos "--sede=$SEDE_REAL" "--horario=$HORARIO_REAL"
    kill "$TENEDOR" 2> /dev/null; wait "$TENEDOR" 2> /dev/null; rm -f "$B/run/cerrojo-tomado"
    igual "  … el fichero no cambió" "$B/root/env-ref-h2"
    caso "  … ninguna copia nueva" "$([ "$(copias)" = 4 ] && echo 0 || echo 1)" "$(copias)"

    echo "#### borrar: la ida y vuelta vuelve IDÉNTICA al fichero de partida (640)"
    paso "hechos borrar --en-seco → EN SECO (los quitaría), sin tocar nada" 0 "escribiría (en el fichero): sede: no está en el fichero · horario: no está en el fichero" mando h8-voice-scope.sh hechos borrar --en-seco
    igual "  … el fichero no cambió" "$B/root/env-ref-h2"
    paso "hechos borrar → APLICADO" 0 "MANDO hechos-borrar APLICADO" mando h8-voice-scope.sh hechos borrar
    caso "  … las dos líneas quitadas" "$(printf '%s' "$ULTIMA" | grep -q 'VOICE_FACTS_SITE quitada' && printf '%s' "$ULTIMA" | grep -q 'VOICE_FACTS_HOURS quitada' && echo 0 || echo 1)"
    igual "ida y vuelta (640): el fichero idéntico byte a byte al de partida" "$B/root/env-ref-h0"
    modo "ida y vuelta" 640
    caso "  … consultar_horarios vuelve a decir que no los tiene" "$(dice '' '' && echo 0 || echo 1)"
    caso "  … voice:doctor: facts.hoursConfigured=false" "$(printf '%s' "$ULTIMA" | grep -qF 'facts.hoursConfigured=false (esperado false' && echo 0 || echo 1)"
    paso "hechos borrar otra vez → nada que escribir" 0 "nada que escribir" mando h8-voice-scope.sh hechos borrar

    echo "#### uno solo y después el otro"
    paso "solo --sede → APLICADO con el AVISO de que la herramienta necesita los dos" 0 "solo los dice con los DOS puestos; falta el horario (--horario)" mando h8-voice-scope.sh hechos "--sede=$SEDE_REAL"
    caso "  … la herramienta sigue diciendo que no los tiene (config: la sede sí, el horario vacío)" "$(H=$(herramienta) S=$(b64 "$SEDE_REAL") python3 -I -c 'import json, os, sys; d = json.loads(os.environ["H"]); sys.exit(0 if d["disponible"] is False and d["c_sede"] == os.environ["S"] and d["c_horario"] == "" else 1)' && echo 0 || echo 1)"
    paso "después --horario → APLICADO; ahora la herramienta los dice" 0 "facts.hoursConfigured=true (esperado true" mando h8-voice-scope.sh hechos "--horario=$HORARIO_REAL"
    caso "  … config y la herramienta dan los dos textos reales BYTE A BYTE" "$(dice "$SEDE_REAL" "$HORARIO_REAL" && echo 0 || echo 1)"
    paso "hechos borrar → APLICADO" 0 "MANDO hechos-borrar APLICADO" mando h8-voice-scope.sh hechos borrar
    igual "  … el fichero idéntico byte a byte al de partida" "$B/root/env-ref-h0"

    echo "#### la misma ida y vuelta en 600"
    chmod 600 "$ENVF"
    paso "hechos <reales> en 600 → APLICADO" 0 "MANDO hechos APLICADO" mando h8-voice-scope.sh hechos "--sede=$SEDE_REAL" "--horario=$HORARIO_REAL"
    modo "600" 600
    paso "hechos borrar en 600 → APLICADO" 0 "MANDO hechos-borrar APLICADO" mando h8-voice-scope.sh hechos borrar
    igual "ida y vuelta (600): el fichero idéntico byte a byte al de partida" "$B/root/env-ref-h0"
    modo "ida y vuelta en 600" 600
    chmod 640 "$ENVF"

    echo "#### las líneas vacías de .env.example ya en el fichero (VOICE_FACTS_SITE= y VOICE_FACTS_HOURS=)"
    pon_lineas 'VOICE_FACTS_SITE=' 'VOICE_FACTS_HOURS=' '  APP_NAME_BANCO_TRAS_LOS_HECHOS=x'
    cache_banco; chmod 640 "$ENVF"; install -m 600 "$ENVF" "$B/root/env-ref-h3"
    paso "hechos <reales> con las líneas vacías → APLICADO, sustituidas en su sitio y con su sangría (ninguna)" 0 "VOICE_FACTS_SITE sustituida" mando h8-voice-scope.sh hechos "--sede=$SEDE_REAL" "--horario=$HORARIO_REAL"
    VUELTA_EXACTA=$(printf '%s\n' "$ULTIMA" | sed -n 's/^   vuelta atrás exacta (el fichero de antes): //p')
    caso "  … en su sitio y sin sangría (la que tenían)" "$([ "$(lineas "VOICE_FACTS_SITE=\"$SEDE_REAL\"")" = 1 ] && [ "$(lineas "VOICE_FACTS_HOURS=\"$HORARIO_REAL\"")" = 1 ] && echo 0 || echo 1)"
    caso "  … la vuelta funcional avisa de que el fichero tenía líneas (para idéntico, la exacta)" "$(printf '%s' "$ULTIMA" | grep -qF 'antes había líneas de los hechos' && echo 0 || echo 1)"
    caso "  … config y la herramienta dan los dos textos reales" "$(dice "$SEDE_REAL" "$HORARIO_REAL" && echo 0 || echo 1)"
    caso "  … el resto del fichero, igual que antes (también la línea que va DETRÁS)" "$(python3 -I -c 'import re, sys
pat = re.compile(r"^[ \t]*(?:export[ \t]+)?VOICE_FACTS_(SITE|HOURS)[ \t]*=")
f = lambda p: [l for l in open(p, encoding="utf-8", newline="").read().splitlines(True) if not pat.match(l)]
sys.exit(0 if f(sys.argv[1]) == f(sys.argv[2]) else 1)' "$ENVF" "$B/root/env-ref-h3" && echo 0 || echo 1)"
    caso "  la vuelta exacta impresa (install de la copia, config:cache, chmod, queue:restart) sale con 0" "$( ( export "${RED[@]}"; eval "$VUELTA_EXACTA" ) > /dev/null 2>&1 && echo 0 || echo 1)"
    igual "  … y deja el fichero idéntico byte a byte al de antes (con sus líneas vacías)" "$B/root/env-ref-h3"
    modo "tras la vuelta exacta" 640
    caso "  … y la herramienta dice que no los tiene" "$(dice '' '' && echo 0 || echo 1)"
    paso "hechos borrar con las líneas vacías → APLICADO (las quita: «borrar» = no están)" 0 "MANDO hechos-borrar APLICADO" mando h8-voice-scope.sh hechos borrar
    caso "  … sin líneas VOICE_FACTS_* y el resto igual" "$([ "$(grep -cE '^[[:space:]]*VOICE_FACTS_' "$ENVF")" = 0 ] && echo 0 || echo 1)"

    echo "#### estados que no se tocan"
    install -m 640 "$B/root/env-ref-h0" "$ENVF"; cache_banco; chmod 640 "$ENVF"
    pon_lineas '  VOICE_FACTS_SITE="Calle 1"'; chmod 640 "$ENVF"; install -m 600 "$ENVF" "$B/root/env-ref-h4"   # sin config:cache
    paso "fichero y caché no dicen lo mismo (editado a mano sin caché) → fijar ABORTA sin tocar nada" 1 "no dicen lo mismo de los hechos" mando h8-voice-scope.sh hechos "--sede=$SEDE_REAL" "--horario=$HORARIO_REAL"
    igual "  … el fichero no cambió" "$B/root/env-ref-h4"
    paso "… y «hechos borrar» (la vuelta) no se condiciona a eso → APLICADO" 0 "MANDO hechos-borrar APLICADO" mando h8-voice-scope.sh hechos borrar
    igual "  … el fichero vuelve al de partida" "$B/root/env-ref-h0"
    pon_lineas '  VOICE_FACTS_HOURS="Lunes"' '  VOICE_FACTS_HOURS="Martes"'; chmod 640 "$ENVF"; install -m 600 "$ENVF" "$B/root/env-ref-h5"
    paso "VOICE_FACTS_HOURS repetida → se niega (código 2)" 2 "VOICE_FACTS_HOURS aparece repetida" mando h8-voice-scope.sh hechos "--horario=$HORARIO_REAL"
    igual "  … el fichero no cambió" "$B/root/env-ref-h5"
    install -m 640 "$B/root/env-ref-h0" "$ENVF"; cache_banco; chmod 640 "$ENVF"

    echo "#### cierre"
    L_MAL=$(for f in "$B"/root/h8-voice-scope-hechos*.log; do [ "$(stat -c '%a' "$f")" = 600 ] || echo "$f"; done | wc -l | tr -d ' ')
    caso "registros del mando en 600 ($(registros) registros)" "$([ "$L_MAL" = 0 ] && echo 0 || echo 1)" "$L_MAL fuera de 600"
    C_MAL=$(for f in "$B"/root/env-pre-voice-hechos*; do [ "$(stat -c '%a' "$f")" = 600 ] || echo "$f"; done | wc -l | tr -d ' ')
    caso "copias previas en 600 ($(copias) copias: una por escritura y una por intento fallido)" "$([ "$C_MAL" = 0 ] && echo 0 || echo 1)" "$C_MAL fuera de 600"
    caso "ningún texto de los hechos en los registros (solo huellas)" "$(! grep -lF -e 'Neiva' -e 'Lunes a viernes' -e 'Peñalisa' "$B"/root/h8-voice-scope-hechos*.log > /dev/null 2>&1 && echo 0 || echo 1)"
    caso "ningún temporal del escritor (.env.h8-voice-hechos-*) en el directorio de la aplicación" "$([ "$(ls -a "$BE" | grep -c '^\.env\.h8-voice-hechos-' || true)" = 0 ] && echo 0 || echo 1)"
    V1=$(valla_total)
    caso "CERO red: la valla no recibió ninguna petición en todo el ensayo" "$([ "$V0" = "$V1" ] && [ "$V0" != '?' ] && echo 0 || echo 1)" "valla: $V0 → $V1"
    install -m "$MODO_ORIG" "$B/root/env-ref-h0" "$ENVF"; cache_banco
    caso "el banco vuelve a su fichero de entorno de partida y a su modo ($MODO_ORIG)" "$(cmp -s "$ENVF" "$B/root/env-ref-h0" && [ "$(stat -c '%a' "$ENVF")" = "$MODO_ORIG" ] && echo 0 || echo 1)"
    rm -f "$B"/root/env-ref-h* "$B"/root/env-pre-voice-hechos*
    set -e
    echo "$((CASOS - FALLOS)) OK · $FALLOS FALLO"
    [ "$FALLOS" = 0 ] ;;

  monitor)
    listo
    preparar
    set +e
    MODO_ORIG=$(stat -c '%a' "$ENVF")
    install -m 600 "$ENVF" "$B/root/env-ref-m0"
    rm -f "$B"/root/env-pre-h7-monitor-*
    V0=$(valla_total)
    prog() { ( cd "$BE" && env "${RED[@]}" php artisan schedule:list --json 2> /dev/null ) | python3 -I -c 'import json, sys; r = json.load(sys.stdin); print(len(r), sum(1 for x in r if "ultron-studio:" in str(x.get("command", ""))))'; }
    # Las dos claves ya están en el fichero sintético (las escribió el despliegue de la ola): se sustituyen.
    sync_on() { pon_env ULTRON_STUDIO_META_TEMPLATES_ENABLED true; pon_env ULTRON_STUDIO_META_TEMPLATES_SYNC_ENABLED true; cache_banco; }
    for pasada in apagada encendida; do
      install -m "$MODO_ORIG" "$B/root/env-ref-m0" "$ENVF"; cache_banco
      [ "$pasada" = apagada ] || sync_on
      read -r T S <<< "$(prog)"
      echo "#### sincronización de plantillas de Meta $pasada: schedule:list da $T tareas, $S con «ultron-studio:»"
      install -m 600 "$ENVF" "$B/root/env-ref-m1"
      if [ "$pasada" = encendida ]; then
        paso "HEAD (lo instalado hoy): «estado» cuenta 1 «del Studio» con el monitor apagado (el fallo de producción)" 0 "schedule:list (total · del Studio): $T 1" mando h7-monitor-scope-head.sh estado
        paso "HEAD: «on off» ABORTA (el programador no cuadra) sin tocar nada" 1 "el programador no cuadra antes de empezar" mando h7-monitor-scope-head.sh on off
        igual "  … el fichero no cambió" "$B/root/env-ref-m1"
      fi
      paso "árbol: «estado» cuenta solo SUS tareas (0 con el monitor apagado)$([ "$pasada" = encendida ] && echo ' y nombra la sincronización como «otra»')" 0 "schedule:list (total · del monitor): $T 0" mando h7-monitor-scope.sh estado
      [ "$pasada" = apagada ] || caso "  … «otras ultron-studio:* que no cuentan: 1 (ultron-studio:meta-templates-sync)»" "$(printf '%s' "$ULTIMA" | grep -qF 'que no cuentan: 1 (ultron-studio:meta-templates-sync)' && echo 0 || echo 1)"
      paso "árbol: «on off» → APLICADO (programador $T 0 → $((T + 2)) 2)" 0 "MANDO monitor → on APLICADO" mando h7-monitor-scope.sh on off
      caso "  … el programador pasa de [$T 0] a [$((T + 2)) 2]" "$(printf '%s' "$ULTIMA" | grep -qF "$T 0 → $((T + 2)) 2 (esperado $((T + 2)) 2" && echo 0 || echo 1)"
      paso "árbol: «estado» con el monitor encendido → sus 2" 0 "schedule:list (total · del monitor): $((T + 2)) 2" mando h7-monitor-scope.sh estado
      paso "árbol: «off on» → APLICADO (vuelve a [$T 0])" 0 "MANDO monitor → off APLICADO" mando h7-monitor-scope.sh off on
      igual "  … ida y vuelta: el fichero idéntico byte a byte al de antes de encender" "$B/root/env-ref-m1"
    done
    echo "#### la expectativa del monitor: h8-voice-scope.sh «expectativa fase1» (la de hoy en producción) se niega en el banco"
    install -m "$MODO_ORIG" "$B/root/env-ref-m0" "$ENVF"; cache_banco
    paso "expectativa fase1 → NO SE APLICA (integrations-doctor no la confirma: el banco no tiene ElevenLabs verificado)" 2 "integrations-doctor no confirma" mando h8-voice-scope.sh expectativa fase1
    igual "  … el fichero no cambió" "$B/root/env-ref-m0"
    V1=$(valla_total)
    caso "CERO red: la valla no recibió ninguna petición" "$([ "$V0" = "$V1" ] && [ "$V0" != '?' ] && echo 0 || echo 1)" "valla: $V0 → $V1"
    install -m "$MODO_ORIG" "$B/root/env-ref-m0" "$ENVF"; cache_banco
    caso "el banco vuelve a su fichero de entorno de partida y a su modo" "$(cmp -s "$ENVF" "$B/root/env-ref-m0" && [ "$(stat -c '%a' "$ENVF")" = "$MODO_ORIG" ] && echo 0 || echo 1)"
    rm -f "$B"/root/env-ref-m* "$B"/root/env-pre-h7-monitor-*
    set -e
    echo "$((CASOS - FALLOS)) OK · $FALLOS FALLO"
    [ "$FALLOS" = 0 ] ;;
  *) muere "uso: bash voz-hechos.sh <banco> arrancar | hechos | monitor | parar" ;;
esac
