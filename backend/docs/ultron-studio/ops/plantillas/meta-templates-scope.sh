#!/bin/bash
# Plantillas de WhatsApp de Meta desde el CRM (L9-96, ola M-1) · el MANDO de sus tres banderas. Se ejecuta EN EL SERVIDOR
# como root y SOLO con la orden expresa del propietario para la etapa que corresponda (RUNBOOK_M1.md §5, P1 y P2). El
# segundo argumento es el estado que hay HOY: si no coincide con el fichero de entorno Y con la configuración efectiva,
# no se toca nada.
#   bash meta-templates-scope.sh read off    (P1: la maestra y la sincronización; el CRM ve el estado en Meta, no envía)
#   bash meta-templates-scope.sh full read   (P2: además, enviar plantillas a Meta desde el CRM: IRREVERSIBLE en Meta)
#   bash meta-templates-scope.sh read full   (vuelta de P2: se deja de enviar; lo enviado sigue en Meta y se sigue leyendo)
#   bash meta-templates-scope.sh off read    (vuelta de P1: todo apagado; las rutas responden 409 meta_templates_disabled)
#   bash meta-templates-scope.sh off full    (vuelta directa de urgencia)
# TRES estados y ninguno más (ULTRON_STUDIO_META_TEMPLATES_{ENABLED, SYNC_ENABLED, SUBMIT_ENABLED}):
#   off  = false false false · read = true true false · full = true true true
# No existe «enviar sin sincronizar» a propósito (N-1 de la revisión: con el envío encendido y la sincronización
# apagada, un envío que se quede en SENDING no se recupera desde el CRM). Cualquier otra combinación es una MEZCLA: el
# mando dice los tres valores y no toca nada. off → full directo no se admite: primero read (P1) y su evidencia.
# Antes de tocar nada: el código desplegado tiene las 4 rutas de Meta (125 en el Studio) y las tres claves están una vez
# en el fichero; para read y full, el cliente efectivo es el de Graph en producción (PM-6) y la cuenta de WhatsApp
# (WABA, solo dígitos) y el token están «presente» tal como los lee el código (nunca se imprime un valor); para full,
# además, META_ENABLED efectivo en true (el código lo exige para enviar).
# Después: la configuración efectiva es la pedida (artisan, no HTTP), `schedule:list` enseña
# ultron-studio:meta-templates-sync cada 5 minutos solo en read y full, la orden en seco (`--dry-run --json`) sale con 0
# (sin nada que vigilar no hay red; con algo, 1–3 GET de lectura, los mismos que la pasada programada), workers, status
# sin token → 401, y publication-check y la traza de los prompts iguales que antes.
# Las mismas garantías que motores/motores-prompts-scope.sh: cerrojos comunes, copia previa 600, el fichero parsea antes
# de la caché, config:cache como www-data en 600 (sin chmod de root), queue:restart, espera de workers, una trampa que
# restaura el fichero y la caché si falla a medias y lo COMPRUEBA, curl con --max-time y `|| c=000`, registro en /root
# (600), la copia previa borrada al terminar sin desvíos y purga de sus copias de más de 14 días. Nunca imprime valores
# del fichero de entorno.
set -euo pipefail
export LC_ALL=C
NUEVO="${1:?uso: meta-templates-scope.sh off|read|full <estado actual>}"
DESDE="${2:?falta el estado actual (off|read|full): se comprueba antes de tocar nada}"
case "$NUEVO" in off|read|full) ;; *) echo "estado no válido: $NUEVO (off|read|full; no hay «envío sin sincronización»)"; exit 2 ;; esac
case "$DESDE" in off|read|full) ;; *) echo "estado actual no válido: $DESDE (off|read|full)"; exit 2 ;; esac
[ "$NUEVO" != "$DESDE" ] || { echo "las plantillas de Meta ya están en $NUEVO: nada que hacer"; exit 0; }
[ "$DESDE:$NUEVO" != "off:full" ] || { echo "off → full directo no: primero «read» (P1) y su evidencia"; exit 2; }

# ── Lo propio de producción (el banco sustituye SOLO este bloque) ───────────────────────────────────────────────────
[ "$(id -u)" = 0 ] || { echo "se ejecuta como root en el servidor; no se ha tocado nada"; exit 2; }
APPDIR=/var/www/api/backend
API=https://api.ironbodyneiva.cloud/api
WWW=www-data
ROOT_U=root
ROOT_G=root
COPIAS=/root
CERROJOS=/run/lock
EXIGE_ENTORNO=production      # PM-6: read y full solo con el cliente de Graph de producción (el doble no se construye)
EXIGE_DRIVER=graph

cd "$APPDIR"
ENVF=.env
K_MAESTRA=ULTRON_STUDIO_META_TEMPLATES_ENABLED
K_SYNC=ULTRON_STUDIO_META_TEMPLATES_SYNC_ENABLED
K_ENVIO=ULTRON_STUDIO_META_TEMPLATES_SUBMIT_ENABLED
aborta() { echo "ABORTA: $*"; exit 1; }
DESVIOS=0
desvio() { echo "   ✗ $*"; DESVIOS=$((DESVIOS + 1)); }
valores() { case "$1" in off) echo "false false false" ;; read) echo "true true false" ;; full) echo "true true true" ;; esac; }
# La configuración efectiva en una línea JSON (o nada: la salida de un tinker fallido no se imprime). De la WABA y del
# token solo «presente» o «falta», tal como los lee el código (StudioMetaTemplates::wabaId() y el configurationProblem()
# del cliente efectivo); nunca su valor.
leer() {
  local l
  l=$(sudo -u "$WWW" php artisan tinker --execute='$s = "App\\Services\\UltronStudio\\StudioMetaTemplates"; $m = app($s); $a = app(App\Services\Meta\MetaAuthService::class); $cp = "error"; try { $cp = $m->client()->configurationProblem() ?? "ninguno"; } catch (Throwable $e) { $cp = "error"; } echo json_encode(["enabled" => $s::enabled(), "sync" => $s::syncEnabled(), "submit" => $s::submitEnabled(), "env" => app()->environment(), "driver" => $s::driver(), "graph" => $s::graphVersion(), "waba" => $m->wabaId() === null ? "falta" : "presente", "token" => match ($cp) { "ninguno" => "presente", "missing_token" => "falta", "malformed_token" => "mal formado", default => "sin comprobar (" . $cp . ")" }, "meta_enabled" => $a->enabled(), "credential_source" => $a->credentialSource(), "studio_routes_total" => defined("App\\Http\\Controllers\\Crm\\UltronStudio\\StudioRouteCount::TOTAL") ? App\Http\Controllers\Crm\UltronStudio\StudioRouteCount::TOTAL : null]), PHP_EOL;' 2>&1 | tail -1) || true
  case "$l" in '{"enabled"'*) printf '%s\n' "$l" ;; esac
}
campo() { printf '%s' "$1" | python3 -I -c 'import json, sys; v = json.load(sys.stdin).get(sys.argv[1]); print(json.dumps(v) if isinstance(v, bool) or v is None else v)' "$2" 2>/dev/null || echo "?"; }
# El estado (off|read|full|mezcla) de los tres valores efectivos.
estado_de() { # JSON → off | read | full | mezcla
  printf '%s' "$1" | python3 -I -c 'import json, sys
d = json.load(sys.stdin)
t = (d.get("enabled"), d.get("sync"), d.get("submit"))
print({(False, False, False): "off", (True, True, False): "read", (True, True, True): "full"}.get(t, "mezcla"))' 2>/dev/null || echo mezcla
}
tres() { echo "maestra=$(campo "$1" enabled) sincronización=$(campo "$1" sync) envío=$(campo "$1" submit)"; }
cuenta_var() { grep -Ec "^[[:space:]]*(export[[:space:]]+)?$1[[:space:]]*=" "$ENVF" || true; }
# El valor de una clave del fichero como lo entiende la configuración (filter_var FILTER_VALIDATE_BOOLEAN): true o false.
bool_var() {
  local v
  v=$(grep -E "^[[:space:]]*(export[[:space:]]+)?$1[[:space:]]*=" "$ENVF" | sed -E "s/^[[:space:]]*(export[[:space:]]+)?$1[[:space:]]*=[[:space:]]*//; s/[[:space:]]+\$//; s/^\"(.*)\"\$/\\1/" | tr 'A-Z' 'a-z')
  case "$v" in true|1|on|yes) echo true ;; *) echo false ;; esac
}
estado_fichero() { # off | read | full | mezcla, de lo que dice el fichero
  case "$(bool_var $K_MAESTRA) $(bool_var $K_SYNC) $(bool_var $K_ENVIO)" in
    "false false false") echo off ;; "true true false") echo read ;; "true true true") echo full ;; *) echo mezcla ;;
  esac
}
set_var() { sed -i -E "s/^([[:space:]]*)(export[[:space:]]+)?$1[[:space:]]*=.*/\\1$1=$2/" "$ENVF"; }   # la clave existe (se comprueba antes)
# Las rutas de Meta en la caché de rutas (4) y la sincronización en el programador: «tareas expresión».
rutas_meta() {
  sudo -u "$WWW" php artisan route:list --json 2>/dev/null | python3 -I -c 'import json, sys
r = json.load(sys.stdin)
print(sum(1 for x in r if str(x.get("uri", "")).lstrip("/").startswith("api/admin/ultron-studio/strategies/templates/") and "/meta/" in str(x.get("uri", ""))))' 2>/dev/null || echo "?"
}
sincronizacion() {
  local salida rc=0
  salida=$(sudo -u "$WWW" php artisan schedule:list --json 2>/dev/null) || rc=$?
  [ "$rc" = 0 ] || { echo "FALLO $rc"; return 0; }
  printf '%s' "$salida" | python3 -I -c 'import json, sys
r = [x for x in json.load(sys.stdin) if "ultron-studio:meta-templates-sync" in str(x.get("command", ""))]
print(len(r), ",".join(sorted({str(x.get("expression", "")).replace(" ", "_") for x in r})) or "-")' 2>/dev/null || echo ILEGIBLE
}
# La orden en seco: «código resultado vigiladas peticiones» (sin nombres ni ids).
en_seco() {
  local salida rc=0
  salida=$(sudo -u "$WWW" php artisan ultron-studio:meta-templates-sync --dry-run --json 2>/dev/null) || rc=$?
  printf '%s' "$salida" | python3 -I -c 'import json, sys
try:
    d = json.load(sys.stdin)
    print(sys.argv[1], d.get("outcome"), d.get("watched"), d.get("requests"))
except Exception:
    print(sys.argv[1], "ilegible - -")' "$rc" 2>/dev/null || echo "$rc ilegible - -"
}
traza() { sudo -u "$WWW" php artisan ultron:prompt-trace --json --last=1 2>/dev/null | python3 -I -c 'import json, sys
t = json.load(sys.stdin); c = t.get("current") or {}
print(t.get("key"), c.get("mode"), c.get("reason") if c.get("reason") is not None else "versión-" + str(c.get("version"))[:8])' 2>/dev/null || echo ilegible; }
publicacion() { if sudo -u "$WWW" php artisan ultron-studio:publication-check > /dev/null 2>&1; then echo 0; else echo "no-0"; fi; }

# MB-5: todo lo que se imprime queda además en un registro de root (600).
LOG="$COPIAS/meta-templates-scope-$(date -u +%Y%m%d-%H%M%S).log"
( umask 077; : > "$LOG" ) || { echo "no se pudo crear el registro $LOG; no se ha tocado nada"; exit 2; }
exec > >(tee -a "$LOG") 2>&1
echo "== meta-templates-scope.sh $NUEVO $DESDE · $(date -u +%Y-%m-%dT%H:%M:%SZ) · registro $LOG"
# MB-4: exclusión mutua con cualquier otro mando que escriba el fichero de entorno.
exec 7> "$CERROJOS/ironbody-env.lock" 8> "$CERROJOS/h8-voice-scope.lock" 9> "$CERROJOS/h9-outreach-scope.lock"
{ flock -n 7 && flock -n 8 && flock -n 9; } || aborta "otro mando que escribe el fichero de entorno está en curso: esperar a que termine"

ANTES=$(leer)
[ -n "$ANTES" ] || aborta "no se pudo leer la configuración efectiva (tinker; su salida no se imprime): ¿está desplegada la ola M-1?"
# Requisitos de código: las 4 rutas de Meta en la caché y 125 en el Studio.
R=$(rutas_meta); T0=$(campo "$ANTES" studio_routes_total)
[ "$R" = 4 ] && [ "$T0" = 125 ] || aborta "el código desplegado no tiene las plantillas de Meta (rutas de Meta en la caché: $R; StudioRouteCount::TOTAL: $T0; esperado 4 y 125)"
for k in $K_MAESTRA $K_SYNC $K_ENVIO; do
  n=$(cuenta_var "$k")
  [ "$n" = 1 ] || aborta "$k aparece $n veces en el fichero de entorno (tiene que estar una vez: la escribió el despliegue de la ola, deploy-m1-backend.sh)"
done
EF=$(estado_de "$ANTES"); FI=$(estado_fichero)
[ "$EF" = "$FI" ] || aborta "el fichero dice «$FI» y la configuración efectiva «$EF» ($(tres "$ANTES")): falta un config:cache o alguien tocó el fichero a mano; aclararlo antes"
[ "$EF" != mezcla ] || aborta "las tres banderas están en una MEZCLA que este mando no admite ($(tres "$ANTES")): solo off, read o full (N-1: nunca enviar sin sincronizar)"
[ "$EF" = "$DESDE" ] || aborta "el estado de hoy es «$EF», no «$DESDE» ($(tres "$ANTES"))"
echo "0. desde $DESDE ($(tres "$ANTES")) · entorno $(campo "$ANTES" env) · cliente $(campo "$ANTES" driver) $(campo "$ANTES" graph) · credencial $(campo "$ANTES" credential_source) · WABA $(campo "$ANTES" waba) · token $(campo "$ANTES" token) · META_ENABLED $(campo "$ANTES" meta_enabled)"
if [ "$NUEVO" != off ]; then
  [ "$(campo "$ANTES" env)" = "$EXIGE_ENTORNO" ] && [ "$(campo "$ANTES" driver)" = "$EXIGE_DRIVER" ] \
    || aborta "para «$NUEVO» el cliente efectivo tiene que ser «$EXIGE_DRIVER» en «$EXIGE_ENTORNO» (PM-6): hay «$(campo "$ANTES" driver)» en «$(campo "$ANTES" env)»"
  [ "$(campo "$ANTES" waba)" = presente ] || aborta "falta la cuenta de WhatsApp (WABA, solo dígitos) tal como la lee el código: sin ella la sincronización no puede leer nada"
  [ "$(campo "$ANTES" token)" = presente ] || aborta "el token de Meta: «$(campo "$ANTES" token)» (tal como lo lee el código): sin él la sincronización no puede leer nada"
fi
if [ "$NUEVO" = full ]; then
  [ "$(campo "$ANTES" meta_enabled)" = true ] || aborta "para «full» META_ENABLED tiene que estar en true en la configuración efectiva (el código lo exige para enviar: 409 meta_messaging_disabled)"
fi
SYNC0=$(sincronizacion); PUB0=$(publicacion); TRAZA0=$(traza)
case "$SYNC0" in "FALLO "*|ILEGIBLE) aborta "schedule:list no se puede leer ($SYNC0): sin el programador no se toca nada" ;; esac
echo "   programador (sincronizaciones · expresión): $SYNC0 · publication-check: $PUB0 · traza de los prompts: $TRAZA0"
DUENO=$(stat -c '%U:%G' "$ENVF")
MODO=$(stat -c '%a' "$ENVF")
CACHE=bootstrap/cache/config.php
TS=$(date -u +%Y%m%d-%H%M%S)
COPIA="$COPIAS/env-pre-plantillas-$NUEVO-$TS"
install -m 600 -o "$ROOT_U" -g "$ROOT_G" "$ENVF" "$COPIA" || { rm -f "$COPIA"; aborta "no se pudo hacer la copia previa del fichero de entorno (¿disco lleno?): no se ha tocado nada"; }
ETAPA=0   # 0 nada tocado · 1 fichero escrito · 2 caché regenerada (el valor nuevo ya está activo en FPM) · 3 aplicado
restaurar() { install -m "$MODO" -o "${DUENO%%:*}" -g "${DUENO##*:}" "$COPIA" "$ENVF"; }
al_salir() { # un fallo a medias no deja el estado nuevo escrito (ni activo) sin que nadie lo sepa
  local rc=$?
  set +e
  if [ "$ETAPA" = 1 ] || [ "$ETAPA" = 2 ]; then
    echo "FALLO A MEDIAS (etapa $ETAPA, código $rc; 0 si lo cortó una señal): se restaura el fichero de entorno"
    if restaurar; then echo "   fichero de entorno restaurado ($(stat -c '%U:%G %a' "$ENVF"))"; else echo "   ✗ NO se pudo restaurar: install -m $MODO -o ${DUENO%%:*} -g ${DUENO##*:} $COPIA $ENVF"; fi
    if [ "$ETAPA" = 2 ]; then
      if sudo -u "$WWW" sh -c 'umask 077; php artisan config:cache -q' && [ -f "$CACHE" ] && [ ! -L "$CACHE" ] && sudo -u "$WWW" chmod 600 "$CACHE"; then echo "   caché regenerada con el estado anterior"; else echo "   ✗ la caché NO se regeneró: sudo -u $WWW sh -c 'umask 077; php artisan config:cache -q' && sudo -u $WWW chmod 600 $CACHE"; fi
      sudo -u "$WWW" php artisan queue:restart -q && echo "   colas reiniciadas"
    fi
    D=$(leer)   # no basta el código de salida: se vuelve a leer la configuración efectiva
    if [ -n "$D" ] && [ "$D" = "$ANTES" ]; then echo "   restaurado y COMPROBADO: la configuración efectiva es la de antes ($(tres "$D"))"; else echo "   ✗ tras restaurar, la configuración efectiva NO es la de antes: ${D:-ilegible} (antes: $ANTES)"; fi
    echo "   la copia previa se conserva para revisarla: $COPIA (lleva secretos: borrarla con shred -u al terminar)"
  fi
}
trap al_salir EXIT
echo "   copia previa del fichero de entorno: $COPIA (600, $ROOT_U)"

ETAPA=1
read -r V_MAESTRA V_SYNC V_ENVIO <<< "$(valores "$NUEVO")"
set_var $K_MAESTRA "$V_MAESTRA"; set_var $K_SYNC "$V_SYNC"; set_var $K_ENVIO "$V_ENVIO"
echo "1. banderas → maestra=$V_MAESTRA sincronización=$V_SYNC envío=$V_ENVIO (permisos $(stat -c '%U %a' "$ENVF"))"
[ "$(stat -c '%U:%G %a' "$ENVF")" = "$DUENO $MODO" ] || aborta "el fichero de entorno cambió de dueño o de modo al escribir (era $DUENO $MODO; se restaura solo)"
[ "$(estado_fichero)" = "$NUEVO" ] || aborta "el fichero no quedó en «$NUEVO» tras escribir (se restaura solo)"
# El fichero tiene que parsear ANTES de tocar la caché (config:cache la vacía primero; si Laravel no arranca, la API cae).
sudo -u "$WWW" php -r 'require "vendor/autoload.php"; try { Dotenv\Dotenv::createArrayBacked(".")->load(); } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' \
  || aborta "el fichero de entorno NO parsea tras escribir las banderas (se restaura solo)"
ETAPA=2
sudo -u "$WWW" sh -c 'umask 077; php artisan config:cache -q'
[ -f "$CACHE" ] && [ ! -L "$CACHE" ] || aborta "$CACHE no es un fichero normal (¿un enlace?); se restaura solo"
[ "$(stat -c '%U' "$CACHE")" = "$WWW" ] || aborta "$CACHE no es de $WWW: no se reinician los workers (quedarían en BACKOFF); se restaura solo"
[ "$(stat -c '%a' "$CACHE")" = 600 ] || sudo -u "$WWW" chmod 600 "$CACHE"   # nunca como root en un directorio que escribe www-data
echo "2. caché: $(stat -c '%U %a' "$CACHE")"
DESPUES=$(leer)
[ -n "$DESPUES" ] && [ "$(estado_de "$DESPUES")" = "$NUEVO" ] || { echo "ABORTA: la configuración efectiva no es la pedida: $([ -n "$DESPUES" ] && tres "$DESPUES" || echo ilegible) (se restaura sola)"; exit 1; }
echo "   configuración efectiva: $(tres "$DESPUES")"
sudo -u "$WWW" php artisan queue:restart -q
ETAPA=3   # aplicado en FPM y en los workers: desde aquí lo que falle es un DESVÍO que se dice, no se deshace solo
sleep 8
# 13 programas de producción y ironbody-voice si el H8 lo instaló (14). Se espera hasta 45 s tras reiniciar las colas.
W_ESPERADOS=$( n=$(supervisorctl status 2>/dev/null | grep -c '^ironbody-voice:' || true); [ "${n:-0}" -ge 1 ] && echo 14 || echo 13 )
HASTA=$(( $(date +%s) + 45 ))
while :; do
  RUNNING=$(supervisorctl status | grep -c RUNNING || true); MAL=$(supervisorctl status | grep -c 'BACKOFF\|FATAL' || true)
  { [ "$RUNNING" = "$W_ESPERADOS" ] && [ "$MAL" = 0 ]; } || [ "$(date +%s)" -ge "$HASTA" ] && break
  sleep 3
done
echo "3. workers RUNNING: $RUNNING · BACKOFF/FATAL: $MAL (esperado $W_ESPERADOS y 0)"
[ "$RUNNING" = "$W_ESPERADOS" ] && [ "$MAL" = 0 ] || desvio "workers: $RUNNING RUNNING y $MAL en BACKOFF/FATAL (esperado $W_ESPERADOS y 0)"
SYNC=$(sincronizacion)
case "$NUEVO" in off) ESP_SYNC="0 -" ;; *) ESP_SYNC="1 */5_*_*_*_*" ;; esac
echo "4. programador (sincronizaciones · expresión): $SYNC (esperado $ESP_SYNC)"
[ "$SYNC" = "$ESP_SYNC" ] || desvio "la sincronización en el programador es [$SYNC] (esperado [$ESP_SYNC]: cada 5 minutos solo en read y full)"
SECO=$(en_seco)
read -r SECO_RC SECO_RES SECO_VIG SECO_PET <<< "$SECO"
echo "5. ultron-studio:meta-templates-sync --dry-run --json → código $SECO_RC · resultado $SECO_RES · vigiladas $SECO_VIG · peticiones a Meta $SECO_PET"
[ "$SECO_RC" = 0 ] || desvio "la orden en seco salió con $SECO_RC (1 = Meta falló en toda la pasada)"
case "$NUEVO:$SECO_RES" in off:disabled|read:nothing_to_watch|read:ok|read:partial|full:nothing_to_watch|full:ok|full:partial) ;; *) desvio "la orden en seco dio «$SECO_RES» con el estado «$NUEVO» (off → disabled; read/full → nothing_to_watch, ok o partial)" ;; esac
c=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$API/admin/ultron-studio/status") || c=000
echo "6. status sin token → $c"; [ "$c" = 401 ] || desvio "status sin token respondió $c"
c=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$API/admin/ultron-studio/strategies/templates/meta/status") || c=000
echo "   strategies/templates/meta/status sin token → $c"; [ "$c" = 401 ] || desvio "meta/status sin token respondió $c"
PUB=$(publicacion); TRAZA=$(traza)
echo "7. publication-check: $PUB (antes $PUB0) · traza de los prompts: $TRAZA (antes $TRAZA0)"
[ "$PUB" = "$PUB0" ] || desvio "publication-check cambió: antes $PUB0 · después $PUB"
[ "$TRAZA" = "$TRAZA0" ] || desvio "la traza de los prompts cambió: antes $TRAZA0 · después $TRAZA"
if [ "$DESVIOS" = 0 ]; then
  # La copia lleva TODOS los secretos del fichero de entorno; aplicado sin desvíos ya no hace falta (la vuelta atrás es
  # este mismo mando con el estado anterior). Se borra, y con ella las copias de este mando de más de 14 días.
  shred -u "$COPIA" 2>/dev/null || rm -f "$COPIA"
  VIEJAS=$(find "$COPIAS" -maxdepth 1 -type f -name 'env-pre-plantillas-*' -mtime +14 2>/dev/null | wc -l | tr -d ' ')
  [ "$VIEJAS" = 0 ] || { find "$COPIAS" -maxdepth 1 -type f -name 'env-pre-plantillas-*' -mtime +14 -exec shred -u {} \; 2>/dev/null; echo "   $VIEJAS copias antiguas de este mando borradas (más de 14 días)"; }
  echo "PLANTILLAS DE META $NUEVO APLICADO (copia previa borrada; vuelta atrás: bash meta-templates-scope.sh $DESDE $NUEVO)"
else
  echo "PLANTILLAS DE META $NUEVO ESCRITO CON $DESVIOS DESVÍOS: no seguir hasta aclararlos (copia previa, con secretos: $COPIA; vuelta atrás: bash meta-templates-scope.sh $DESDE $NUEVO)"; exit 1
fi
