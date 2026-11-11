#!/bin/bash
# Cierre del Hito 9 en producción (G-9 de L9-81; OPS9-c, informe-ops9-c.md): la OLA que sigue a la fase 1, en CÓDIGO y
# MIGRACIONES con TODO APAGADO. Entra: W9 (WhatsApp por plantillas: el canal, la puerta de Marketing, el normalizador y
# la tabla de comprobaciones de Meta), V9 con la Enmienda 17 del H8 (la voz por estrategia por
# OutboundGate::requestForStrategy y la ampliación de vc_requester_chk), el cierre de B9 (cupo, techo y reserva de la voz
# del H9; la orden que registra lo aprobado por Meta), P9-25 (el vale de un solo uso del canal en vivo y de las fotos),
# el endurecimiento del H8 (L9-82: B-T4, B-T5, B-T16 y B-T17), la plantilla de voz /3 (pm2-voz, P-M2), el arreglo del
# E2E que es del backend (H9-E2E-1: el borrador vacío; H9-E2E-2 y 3 son del CRM) y los de la revisión de seguridad del
# cierre (SC-3, SC-6, SC-7, SC-10, SC-13…). Derivado de deploy-h9-backend-fase1.sh: mismos pasos y
# mismas lecturas, con la puerta de diff de ESTA ola.
# Lo que NO cambia: OutreachSendGate::SENDS_ENABLED_IN_CODE y VoiceCallPolicy::CALLS_ENABLED_IN_CODE siguen en false (un
# commit `outreach(sends-open):` o `voice(calls-open):` ABORTA: se despliegan aparte, con su orden escrita); ninguna
# bandera se enciende (las claves nuevas se escriben apagadas o en 0); ninguna ruta nueva; ningún worker nuevo; no se
# ejecuta composer; nginx, DNS, TLS, el crontab y supervisor no se tocan.
# ORDEN OBLIGATORIO (P9-25, plans/informes-h9/informe-p9-25.md §4): 1) el CRM (deploy-h9-crm-cierre.sh, desde el Mac);
# 2) recargar las pestañas de recepción y del punto facial; 3) este guion. Al revés, una pestaña con el CRM viejo reparte
# un vale de un solo uso entre varios streams y sus fotos fallan (el reconocimiento facial se queda sin catálogo). El paso
# 0 lo comprueba: el version.json del CRM tiene que ser el de --crm, y el access.log de nginx no puede tener, en los
# últimos 10 minutos, peticiones de vale SIN `scope` (las que hace el CRM viejo; el nuevo pide `?scope=`).
# Se ejecuta EN EL SERVIDOR como root, tras el respaldo, que va JUSTO ANTES (< 2 h; el paso 0 exige que el fichero de
# entorno sea idéntico a env.backup) y DESPUÉS de publicar el CRM y recargar las pestañas:
#   TS=$(date -u +%Y%m%d-%H%M%S); BK=/var/backups/ironbody/ultron-studio-h9-cierre-$TS
#   ( umask 077; mkdir -p "$BK"
#     git -C /var/www/api rev-parse HEAD > "$BK/api-commit.txt"                      # empieza por PREVIO (ce7c150)
#     sudo -u postgres pg_dump -Fc ironbody > "$BK/ironbody.dump"
#     sha256sum "$BK/ironbody.dump" > "$BK/ironbody.dump.sha256"
#     install -m 600 -o root -g root /var/www/api/backend/.env "$BK/env.backup"
#     cp -a /var/www/api/backend/bootstrap/cache "$BK/bootstrap-cache"
#     cp -a /etc/supervisor/conf.d "$BK/supervisor-conf.d" )
#   pg_restore -l "$BK/ironbody.dump" | grep -c 'TABLE DATA'                      # tantas como tablas tiene hoy la base (253)
#   stat -c '%a %U %n' "$BK" "$BK"/*; du -h "$BK/ironbody.dump"; echo "BK=$BK"    # 700 el directorio; 600 y root los ficheros
#   0. comprobaciones de solo lectura (abortan sin tocar nada; con --solo-comprobar se termina aquí): el respaldo, los
#      commits (sin `outreach(sends-open):` ni `voice(calls-open):`), la puerta de diff CERRADA de esta ola, las
#      migraciones (solo las de la lista), las dos constantes cerradas en origin/main, el esquema de partida (el de la fase
#      1), el H9 todo apagado, el CRM ya publicado y sin pestañas viejas, workers, programador y rutas
#   1. código (git como root con umask 022, merge --ff-only) y lectura por www-data de cada fichero del merge
#   2. las claves nuevas de la ola (apagadas o en 0) ANTES de migrar, y el fichero de entorno parsea
#   3. ironbody-migrate (solo las migraciones de la ola; un 55P03 se reintenta hasta 3 veces); si el esquema no da el
#      esperado (ultron_studio_* 127/141/9/26, outreach_* 51/75/3/8 y voice_* igual con vc_requester_chk ampliado),
#      ABORTA sin cachés
#   4. aserciones del esquema (funciones 34 sin SECURITY DEFINER, ninguna guarda apagada, F intacta, dueños, privilegios
#      de iron_app en la tabla nueva y en las de antes, la tabla nueva vacía y las demás con las mismas filas)
#   5. config:cache y route:cache como www-data con umask 077, cachés www-data 600 y queue:restart
#   6. rutas (las mismas: esta ola no añade ninguna) y auth:coverage --unmapped vacío
#   7. configuración efectiva: el H9 todo apagado (también el cupo, el techo y la reserva de la voz del H9 y los precios
#      de WhatsApp), las dos constantes cerradas en ejecución, VOICE_CONTACTS_ENABLED apagada, el motor/Legacy/H4–H8
#      iguales, el programador con el mismo total y 0 tareas outreach:* · 8. workers como antes, dos lecturas
#   9. sanidad HTTP (401 y no-store, 413 temprano, OTP 403, voz 401, el vale sin sesión 401), colas, secretos,
#      publication-check, el monitor del H7 en seco igual, el entorno = respaldo salvo las claves de la ola
#  10. integrations-snapshot sin cambios · 11. ERROR nuevos en el log
#  12. los smokes del H9 (fase 1: 0 tareas outreach:*) y del H8, DEL COMMIT DESPLEGADO (copiados de su checkout)
# Cada aserción que falla se dice y el script termina con 1; «DESPLIEGUE H9 CIERRE (BACKEND) COMPLETADO» solo sale con 0
# desvíos. Nunca imprime valores del fichero de entorno, ni secretos, ni la salida de un tinker que no case con lo
# esperado. Todo lo que imprime queda además en /root/h9-cierre-<fecha UTC>.log (600, root).
# Uso: bash /root/deploy-h9-backend-cierre.sh <commit esperado (7)> <directorio del respaldo> --crm=<commit del CRM ya publicado (7)>
#           [--solo-comprobar] [--acepta-main=<commit>[,<commit>…]] [--acepta-pestanas-viejas] [--sin-smoke]
#      bash /root/deploy-h9-backend-cierre.sh <commit esperado (7)> <directorio del respaldo> --solo-smoke
#   --solo-comprobar: solo el paso 0 (lecturas y git fetch); es el ensayo en el servidor.
#   --acepta-main=…: los commits AJENOS a esta ola (de origin/main) que producción no tiene y que viajarían con el merge, que
#     el propietario acepta desplegar EN ESE MOMENTO; sin la bandera, el paso 0 los lista y el despliegue aborta.
#   --acepta-pestanas-viejas: el propietario acepta desplegar aunque el access.log muestre pestañas con el CRM viejo (o no
#     se pueda leer): esas pestañas perderán las fotos y el canal en vivo hasta recargarlas.
#   --sin-smoke: no corre el paso 12 (imprime las órdenes para correrlo a mano después).
#   --solo-smoke: con producción ya en el commit esperado, solo el paso 12. Dejar ≥ 2 minutos desde otra pasada de un smoke:
#     comparten la cuenta 32 y el cupo de inicios de sesión por IP (una tercera pasada seguida da 429 y falla; ensayado).
# VUELTA ATRÁS (nivel 3 del código; la imprime también al final con el respaldo de hoy), EN ORDEN INVERSO AL DESPLIEGUE:
# primero el backend y después, si hace falta, el CRM (un CRM viejo con el backend nuevo pierde las fotos: P9-25).
#   backend: solo si producción sigue en el commit de la ola: `git checkout -q --detach $(cat $BK/api-commit.txt)` como
#   root con umask 022 (= PREVIO), config:cache y route:cache como www-data, queue:restart. Las claves nuevas pueden
#   quedarse (el código anterior no las lee) o restaurarse con $BK/env.backup (mismo dueño y modo) y config:cache.
#   esquema: la tabla de W9 (ultron_studio_template_meta_checks) QUEDA, vacía y sin lector; el CHECK ampliado de
#   voice_calls QUEDA (admite un solicitante más; el código anterior nunca lo escribe). NUNCA `migrate:rollback` en
#   producción: quitarlos es de database-specialist con autorización (sus down() se niegan con filas). Volver el código no
#   es volver los datos.
#   CRM: la vuelta rápida que imprime deploy-h9-crm-cierre.sh (index.html y version.json del respaldo).
set -euo pipefail
export LC_ALL=C
umask 022
g() { git "$@"; }
uso() { echo "uso: bash /root/deploy-h9-backend-cierre.sh <commit esperado (7)> <respaldo> --crm=<commit del CRM (7)> [--solo-comprobar] [--acepta-main=<c>[,<c>…]] [--acepta-pestanas-viejas] [--sin-smoke] | <commit> <respaldo> --solo-smoke"; }
[ "$#" -ge 2 ] && [ "$#" -le 7 ] || { uso; echo "no se ha tocado nada"; exit 2; }
ESPERADO="$1"
BK="$2"
shift 2
SOLO_COMPROBAR=""; SOLO_SMOKE=""; ACEPTA=""; CRM=""; ACEPTA_PESTANAS=""; SIN_SMOKE=""
for a in "$@"; do
  case "$a" in
    --solo-comprobar) [ -z "$SOLO_COMPROBAR" ] || { echo "--solo-comprobar repetido; no se ha tocado nada"; exit 2; }; SOLO_COMPROBAR=--solo-comprobar ;;
    --solo-smoke) SOLO_SMOKE=--solo-smoke ;;
    --acepta-pestanas-viejas) ACEPTA_PESTANAS=1 ;;
    --sin-smoke) SIN_SMOKE=1 ;;
    --crm=*) [ -z "$CRM" ] || { echo "--crm repetido; no se ha tocado nada"; exit 2; }; CRM=${a#--crm=}
             [[ "$CRM" =~ ^[0-9a-f]{7}$ ]] || { echo "--crm va con el commit de 7 cifras que sirve hoy https://ironbodyneiva.cloud/version.json; no se ha tocado nada"; exit 2; } ;;
    --acepta-main=*) [ -z "$ACEPTA" ] || { echo "--acepta-main repetido; no se ha tocado nada"; exit 2; }
                     ACEPTA=${a#--acepta-main=}
                     [[ "$ACEPTA" =~ ^[0-9a-f]{7}(,[0-9a-f]{7})*$ ]] || { echo "--acepta-main va con commits de 7 cifras separados por comas (los que imprime el paso 0); no se ha tocado nada"; exit 2; } ;;
    *) echo "argumento desconocido: $a; no se ha tocado nada"; uso; exit 2 ;;
  esac
done
[ -z "$SOLO_SMOKE" ] || [ -z "$SOLO_COMPROBAR$ACEPTA$CRM$ACEPTA_PESTANAS$SIN_SMOKE" ] || { echo "--solo-smoke va solo; no se ha tocado nada"; exit 2; }
[ -n "$SOLO_SMOKE" ] || [ -n "$CRM" ] || { echo "falta --crm=<commit del CRM ya publicado>: esta ola va DESPUÉS del CRM (P9-25); no se ha tocado nada"; exit 2; }
[[ "$ESPERADO" =~ ^[0-9a-f]{7}$ ]] || { echo "el commit esperado va con 7 cifras hexadecimales (git rev-parse --short=7 origin/main); no se ha tocado nada"; exit 2; }
[ "$(id -u)" = 0 ] || { echo "se ejecuta como root en el servidor; no se ha tocado nada"; exit 2; }

# ── Lo propio de producción ────────────────────────────────────────────────────────────────────────────────────────
API=https://api.ironbodyneiva.cloud/api
CRM_WEB=https://ironbodyneiva.cloud
NGINX_ACCESS=/var/log/nginx/access.log
PSQL="sudo -u postgres psql -X -Atq -v ON_ERROR_STOP=1 -d ironbody"
LOGDIR=/root
ENVF=/var/www/api/backend/.env
LOGS=/var/www/api/backend/storage/logs
WWW=www-data
ROOT_U=root
DUENO_BD=iron
APP_BD=iron_app
NIVEL_ERROR=production.ERROR
MIGRADOR=/usr/local/bin/ironbody-migrate

# ── Lo propio de ESTA ola (se revisa con el árbol final antes de cada ensayo: informe-ops9-c.md §2) ─────────────────
# PREVIO = lo que sirve producción: la fase 1 del H9 (L9-76; d701b0c, docs, no se desplegó). CRM_PREVIO = el CRM de hoy.
PREVIO=ce7c150
CRM_PREVIO=9572426
# Las migraciones de la ola, CERRADAS: ninguna otra puede entrar ni tocarse (un fichero de migración ya desplegado que
# cambie también aborta).
MIG_OLA="2026_11_13_100001_allow_strategy_requester_on_voice_calls 2026_11_13_200001_create_ultron_studio_template_meta_checks_table
  2026_11_14_100001_allow_webhook_unhealthy_reason_on_voice_brakes 2026_11_14_100002_add_one_call_per_strategy_job_index_on_voice_calls"
MIG_OLA=$(echo $MIG_OLA)
# El EFECTO de cada una, en el mismo orden (Q_EFECTOS): vc_requester_chk admite `strategy` (V9) · existe la tabla de W9 ·
# vbr_reason_chk admite `webhook_unhealthy` (SC-3) · existe el índice único válido vc_one_call_per_strategy_job (SC-13).
# Antes «f f f f», después «t t t t». Si MIG_OLA cambia, Q_EFECTOS y EFECTOS_DESPUES cambian con ella.
EFECTOS_ANTES="f f f f"
EFECTOS_DESPUES="t t t t"
# Las zonas propias del Studio y de la ejecución (libres dentro de la ola) y lo que no corre en producción (pruebas y docs).
ZONAS_LIBRES="backend/app/Services/Outreach/ backend/app/Models/Outreach/ backend/app/Jobs/Outreach/ backend/app/Console/Commands/Outreach/
  backend/app/Services/UltronStudio/ backend/app/Models/UltronStudio/ backend/app/Http/Controllers/Crm/UltronStudio/
  backend/app/Console/Commands/UltronStudio/ backend/config/outreach.php backend/config/ultron_studio.php
  backend/config/ultron_studio_stream.php backend/routes/ultron-studio.php backend/tests/ backend/docs/"
# La LISTA CERRADA y enumerada de lo que esta ola puede cambiar FUERA del Studio y de la ejecución (ruta exacta y dueño).
# Cualquier otro fichero de backend/ que cambie entre PREVIO y origin/main ABORTA (Legacy, motor comercial, n8n, OTP,
# Meta, rutas de la API, dependencias, la constante de llamadas del H8…): si es de otro equipo, se despliega antes por su
# vía. Las tres marcadas con «*» SOLO AÑADEN CÓDIGO: las líneas que quitan solo pueden ser comentarios y ninguna con
# `Schedule::`.
LISTA_CERRADA='backend/.env.example                                                   ejemplo, no corre: VOICE_CONTACTS_ENABLED (B-T4) y la voz del H9 (b9-cierre)
backend/app/Services/Marketing/MarketingMessageDispatcher.php        * W9: la puerta dispatchWhatsappTemplate (L9-80)
backend/app/Services/Marketing/WhatsappOutboxService.php               W9: la rama de plantillas del outbox (L9-80; SC-10)
backend/app/Services/Marketing/OutreachTemplateMessage.php             W9: el mensaje de plantilla (nuevo)
backend/app/Services/Telephony/OutboundGate.php                      * V9: requestForStrategy (Enmienda 17, L9-81; G-11 solo comentario)
backend/app/Services/Telephony/VoiceContacts.php                       B-T4: VOICE_CONTACTS_ENABLED (L9-82)
backend/app/Services/Telephony/VoiceTelephonyStatus.php                B-T4: flags.contacts en el estado (L9-82)
backend/app/Services/Telephony/VoiceWebhookHealth.php                  B-T5: la salud del webhook post-llamada (L9-82, nuevo)
backend/app/Services/Telephony/VoiceTools.php                          SC-3 / E17-8: la baja de viva voz (no_volver_a_llamar) se acepta con el freno echado
backend/app/Services/Telephony/VoiceTranscriptAllowlist.php            pm2-voz: las negativas exactas de la plantilla /3 (P-M2)
backend/app/Services/Telephony/VoiceTranscriptGuard.php                pm2-voz: solo el comentario de M-2 (P-M2)
backend/app/Services/Voice/ElevenLabs/ElevenLabsAgentDeployer.php      B-T5: las dos lecturas GET del webhook y los secretos (L9-82)
backend/app/Services/Voice/VoiceAdapters.php                           B-T5: el observador del webhook (L9-82)
backend/app/Services/Voice/VoiceWebhookObserver.php                    B-T5 (nuevo)
backend/app/Services/Voice/NullVoiceWebhookObserver.php                B-T5 (nuevo)
backend/app/Services/Voice/Dto/VoiceSecretList.php                     B-T5 (nuevo)
backend/app/Services/Voice/Dto/VoiceWebhookList.php                    B-T5 (nuevo)
backend/app/Console/Commands/Voice/VoiceDoctorCommand.php              B-T4: flags.contacts en el doctor (L9-82)
backend/app/Console/Commands/Voice/VoiceWebhookHealthCommand.php       B-T5: voice:webhook-health (L9-82, nuevo)
backend/app/Http/Controllers/Crm/Voice/VoiceContactController.php      B-T4: 409 voice_contacts_disabled (L9-82)
backend/app/Console/Commands/CheckSecretExposure.php                   B-T17: las claves de la voz y los logs legibles (L9-82)
backend/app/Console/Commands/QueueHealthCommand.php                    B-T16: el trabajo reservado de un worker muerto (L9-82)
backend/app/Services/Observability/QueueHealthService.php              B-T16 (L9-82)
backend/app/Services/IronGuard/ChannelHealthDetector.php               B-T16: queue_reserved_orphaned (L9-82)
backend/config/queue.php                                               B-T16: watch_reserved en tres carriles (L9-82)
backend/config/voice.php                                               B-T4: voice.contacts.enabled (L9-82)
backend/app/Http/Controllers/Api/Admin/StreamTicketController.php      P9-25: el vale de un solo uso con su finalidad (L9-78)
backend/app/Http/Middleware/EnsureAdminAuth.php                        P9-25: el canje del vale (L9-78)
backend/app/Http/Middleware/SecurityHeaders.php                        P9-25: no-store y no-referrer con vale (L9-78)
backend/app/Services/Admin/StreamTicketService.php                     P9-25: emisión y canje atómico (L9-78); SC-6 y SC-7
backend/app/Console/Commands/PruneStreamTicketsCommand.php             SC-7: admin:prune-stream-tickets, purga de los vales caducados (nuevo)
backend/app/Models/Voice/VoiceBrakeEntry.php                           SC-3: el motivo webhook_unhealthy del freno de voz
backend/app/Support/Access/CrmPermission.php                           G-11: solo el comentario de la Enmienda 17 (L9-81)
backend/app/Support/Access/PermissionCatalog.php                       el texto de la llave ultron.strategies_publish (doc9 #8)
backend/routes/console.php                                           * B-T5 (voice:webhook-health, bajo VOICE_MONITOR_ENABLED), G-7 (los dos normalizadores, bajo el programador del H9) y SC-7 (admin:prune-stream-tickets, SIN bandera)'
SOLO_CODIGO_ANADIDO="backend/routes/console.php backend/app/Services/Telephony/OutboundGate.php backend/app/Services/Marketing/MarketingMessageDispatcher.php"
# Las claves nuevas de la ola, explícitas aunque coincidan con el defecto (protección 1: todo nace apagado). Las mueven
# después los mandos (h8-voice-scope.sh para la voz del H8; h9-outreach-scope.sh canal voz on para la del H9).
VARS="VOICE_CONTACTS_ENABLED=false COMMERCIAL_AUTOMATION_VOICE_DAILY_CAP=0 COMMERCIAL_AUTOMATION_VOICE_DAILY_BUDGET_USD=0 COMMERCIAL_AUTOMATION_VOICE_RESERVE_USD=0"
N_VARS=$(echo $VARS | wc -w | tr -d ' ')
CLAVES_OLA=$(for kv in $VARS; do printf '%s|' "${kv%%=*}"; done); CLAVES_OLA=${CLAVES_OLA%|}
# Tareas del programador que la ola añade SIN bandera (con todo apagado): 1, admin:prune-stream-tickets cada 10 min
# (SC-7: borra solo las anotaciones caducadas de los vales en la caché `database`). voice:webhook-health cuelga de
# VOICE_MONITOR_ENABLED y los dos normalizadores del programador del H9: con todo apagado, 0.
TAREAS_NUEVAS_SIEMPRE=1
HUELLA_F=4ad748b5f17f09b398f7f641c197d7ee        # md5(pg_get_functiondef) de ultron_studio_forbid(): la de F (H5–H9)
ESQ_ANTES="123 137 9 25 51 75 3 8"                  # la fase 1 en producción (L9-76)
ESQ_DESPUES="127 141 9 26 51 75 3 8"                # + W9: 4 disparadores, 4 CHECK y 1 tabla en ultron_studio_% (Anexo A)
FUNC_ANTES="33 0 1 $DUENO_BD"
FUNC_DESPUES="34 0 1 $DUENO_BD"                   # + ultron_studio_chk_ustmc_template_of_whatsapp (W9)
TABLA_W9=ultron_studio_template_meta_checks
PRIV_W9_ESPERADO="$TABLA_W9=ra---s"                 # solo inserción: SELECT, INSERT y la secuencia (REVOKE UPDATE, DELETE)

TS=$(date -u +%Y%m%d-%H%M%S)
if [ -n "$SOLO_SMOKE" ]; then LOG="$LOGDIR/h9-cierre-smoke-$TS.log"; elif [ -n "$SOLO_COMPROBAR" ]; then LOG="$LOGDIR/h9-cierre-comprobar-$TS.log"; else LOG="$LOGDIR/h9-cierre-$TS.log"; fi
( umask 077; : > "$LOG" ) || { echo "no se pudo crear el registro $LOG; no se ha tocado nada"; exit 2; }
exec > >(tee -a "$LOG") 2>&1
echo "== deploy-h9-backend-cierre.sh · $(date -u +%Y-%m-%dT%H:%M:%SZ) · ESPERADO=$ESPERADO · PREVIO=$PREVIO · CRM=${CRM:-—} · BK=$BK${SOLO_COMPROBAR:+ · $SOLO_COMPROBAR}${SOLO_SMOKE:+ · $SOLO_SMOKE} · registro $LOG"
aborta() { echo "ABORTA: $*"; exit 1; }
DESVIOS=0
desvio() { echo "   ✗ $*"; DESVIOS=$((DESVIOS + 1)); }
artisan() { ( cd /var/www/api/backend && sudo -u "$WWW" php artisan "$@" ); }
cuenta_var() { grep -Ec "^[[:space:]]*(export[[:space:]]+)?$1[[:space:]]*=" "$ENVF" || true; }
valor_var() { grep -E "^[[:space:]]*(export[[:space:]]+)?$1[[:space:]]*=" "$ENVF" | sed -E "s/^[[:space:]]*(export[[:space:]]+)?$1[[:space:]]*=[[:space:]]*//; s/[[:space:]]+\$//; s/^\"(.*)\"\$/\\1/"; }
estado_var() { # CLAVE VALOR → falta | igual | distinto | repetida (sin imprimir el valor)
  local n
  n=$(cuenta_var "$1")
  [ "$n" = 0 ] && { echo falta; return; }
  [ "$n" = 1 ] || { echo repetida; return; }
  [ "$(valor_var "$1")" = "$2" ] && echo igual || echo distinto
}
lineas_log() { local f; for f in "$LOGS"/laravel*.log; do [ -f "$f" ] && echo "$f $(wc -l < "$f" | tr -d ' ')"; done; return 0; }
workers() { # <RUNNING esperados> <segundos>
  local hasta s r m
  hasta=$(( $(date +%s) + $2 ))
  while :; do
    s=$(supervisorctl status 2>/dev/null || true)
    r=$(printf '%s\n' "$s" | grep -c RUNNING || true)
    m=$(printf '%s\n' "$s" | grep -cE 'BACKOFF|FATAL' || true)
    if { [ "$r" = "$1" ] && [ "$m" = 0 ]; } || [ "$(date +%s)" -ge "$hasta" ]; then echo "$r $m"; return 0; fi
    sleep 2
  done
}
# Las rutas en la caché: Studio total · strategies/** · ejecución · sus GET · sus POST · el canal SSE (L9-59: 121 30 13 9 4 1).
rutas() {
  artisan route:list --json 2>/dev/null | python3 -c '
import json, sys
try:
    r = json.load(sys.stdin)
except Exception:
    print("?")
    sys.exit(0)
u = lambda x: "/" + str(x.get("uri", "")).lstrip("/")
m = lambda x: str(x.get("method", ""))
s = [x for x in r if u(x).startswith("/api/admin/ultron-studio/")]
e = [x for x in s if u(x).startswith("/api/admin/ultron-studio/strategies")]
c = [x for x in s if u(x).startswith("/api/admin/ultron-studio/outreach/")]
t = [x for x in s if u(x) == "/api/admin/ultron-studio/stream"]
print(len(s), len(e), len(c), sum(1 for x in c if "GET" in m(x)), sum(1 for x in c if m(x) == "POST"), len(t))' 2>/dev/null || echo '?'
}
# El programador por `schedule:list --json`, con su código de salida APARTE (m-1 del H7): «total del_Studio de_voz de_outreach».
programador() {
  local salida rc=0
  salida=$(artisan schedule:list --json 2>/dev/null) || rc=$?
  [ "$rc" = 0 ] || { echo "FALLO $rc"; return 0; }
  printf '%s' "$salida" | python3 -c '
import json, re, sys
try:
    r = json.load(sys.stdin)
    assert isinstance(r, list)
except Exception:
    print("ILEGIBLE")
    sys.exit(0)
c = [str(x.get("command", "")) for x in r]
voz = [x for x in c if re.search(r"(?<![a-z])voice:", x) or "--verify-voice" in x]
out = [x for x in c if re.search(r"(?<![a-z])outreach:", x)]
print(len(r), sum(1 for x in c if "ultron-studio:" in x and x not in voz), len(voz), len(out))' 2>/dev/null || echo ILEGIBLE
}
# Motor comercial, ULTRON Legacy, el H4–H7 y la voz del H8 (solo CONFIGURACIÓN y la constante del H8: la ola no cambia
# ningún valor; lo nuevo del H8, VOICE_CONTACTS_ENABLED, se lee aparte). Antes y después tienen que ser iguales.
leer_previa() {
  local l
  l=$(artisan tinker --execute='$p = "ultron_studio.integrations."; echo json_encode(["engine_scope" => config("marketing.ultron.studio_config"), "ultron_enabled" => config("marketing.ultron.enabled"), "canary_set" => config("marketing.ultron.canary_conversation_id") !== null, "followups_scheduler" => config("marketing.followups.scheduler_enabled"), "commercial_nba" => config("commercial.enabled"), "commercial_events" => config("commercial.events_enabled"), "commercial_autonomy" => config("commercial.autonomy_enabled"), "publishing_enabled" => config("ultron_studio.publishing_enabled"), "lab_gate" => config("ultron_studio.publishing.lab_gate"), "lab_enabled" => config("ultron_studio.lab.enabled"), "writes_enabled" => config($p."writes_enabled"), "probe_enabled" => config($p."twilio_probe.enabled"), "monitor_enabled" => config("ultron_studio.monitor.enabled"), "monitor_expect" => config("ultron_studio.monitor.expect"), "voice_publishing" => config("ultron_studio.voice.publishing_enabled"), "voice_inbound" => config("voice.inbound.enabled"), "voice_outbound" => config("voice.outbound.enabled"), "voice_monitor" => config("voice.monitor.enabled"), "voice_lab" => config("voice.lab.enabled"), "twilio_voice" => config("voice.twilio.enabled"), "elevenlabs_enabled" => config("voice.elevenlabs.enabled"), "calls_in_code" => App\Services\Voice\VoiceCallPolicy::CALLS_ENABLED_IN_CODE, "studio_voice" => App\Services\UltronStudio\StudioIntegrations::voiceState()]), PHP_EOL;' 2>&1 | tail -1) || true
  case "$l" in '{"engine_scope"'*) printf '%s\n' "$l" ;; esac
}
# La configuración efectiva del H9 (la del mando, h9-outreach-scope.sh) más lo nuevo de la ola: el cupo, el techo y la
# reserva de la voz del H9, los precios de WhatsApp, VOICE_CONTACTS_ENABLED y las dos constantes en ejecución.
leer_h9() {
  local l
  l=$(artisan tinker --execute='$o = "outreach."; $s = "ultron_studio.strategies."; $dec = fn ($k) => config($k) === null ? null : (string) config($k); $g = null; foreach (array_merge(glob(app_path("Services/Outreach/OutreachSendGate.php")) ?: [], glob(app_path("Services/Outreach/*/OutreachSendGate.php")) ?: []) as $f) { $c = "App\\" . str_replace("/", "\\", substr($f, strlen(app_path()) + 1, -4)); if (class_exists($c) && defined($c . "::SENDS_ENABLED_IN_CODE")) { $g = $c; break; } } echo json_encode(["deployed" => is_array(config("outreach")), "enabled" => config($o."enabled"), "mode" => config($o."mode"), "scheduler" => config($o."scheduler.enabled"), "monitor" => config($o."monitor.enabled"), "assisted" => config($o."assisted.enabled"), "assisted_cap" => config($o."assisted.daily_cap"), "whatsapp" => config($o."whatsapp.enabled"), "whatsapp_cap" => config($o."whatsapp.daily_cap"), "whatsapp_budget" => $dec($o."whatsapp.daily_budget_usd"), "price_marketing" => $dec($o."whatsapp.price_marketing_usd"), "price_utility" => $dec($o."whatsapp.price_utility_usd"), "voice" => config($o."voice.enabled"), "voice_cap" => config($o."voice.daily_cap"), "voice_budget" => $dec($o."voice.daily_budget_usd"), "voice_reserve" => $dec($o."voice.reserve_usd_per_call"), "classifier" => config($o."classifier.enabled"), "sends_in_code" => $g === null ? null : constant($g . "::SENDS_ENABLED_IN_CODE"), "sends_gate" => $g, "calls_in_code" => class_exists("App\\Services\\Voice\\VoiceCallPolicy") ? App\Services\Voice\VoiceCallPolicy::CALLS_ENABLED_IN_CODE : null, "voice_contacts" => config("voice.contacts.enabled"), "strategies_deployed" => is_array(config("ultron_studio.strategies")), "strategies" => config($s."enabled"), "strategies_publishing" => config($s."publishing_enabled"), "simulation_gate" => config($s."simulation_gate"), "stream_deployed" => is_array(config("ultron_studio_stream")), "stream" => config("ultron_studio_stream.enabled"), "studio_routes_total" => defined("App\\Http\\Controllers\\Crm\\UltronStudio\\StudioRouteCount::TOTAL") ? App\Http\Controllers\Crm\UltronStudio\StudioRouteCount::TOTAL : null]), PHP_EOL;' 2>&1 | tail -1) || true
  case "$l" in '{"deployed"'*) printf '%s\n' "$l" ;; esac
}
# ¿El H9 todo apagado? «antes» (el código de la fase 1: lo nuevo sale null) o «despues» (lo nuevo, apagado o en 0, y
# VOICE_CONTACTS_ENABLED apagada). En los dos, las dos constantes cerradas. → «OK» o «MAL campo=valor …».
h9_apagado() { # momento JSON
  printf '%s' "$2" | python3 -I -c '
import json, sys
momento = sys.argv[1]
d = json.load(sys.stdin)
esp = {"deployed": True, "enabled": False, "mode": "off", "scheduler": False, "monitor": False, "assisted": False, "assisted_cap": 0,
       "whatsapp": False, "whatsapp_cap": 0, "voice": False, "classifier": False, "sends_in_code": False, "calls_in_code": False,
       "strategies_deployed": True, "strategies": False, "strategies_publishing": False, "simulation_gate": "required",
       "stream_deployed": True, "stream": False}
if momento == "despues":
    esp.update({"voice_cap": 0, "voice_contacts": False})
mal = ["%s=%s" % (k, json.dumps(d.get(k))) for k, v in esp.items() if d.get(k) != v]
cero = ["whatsapp_budget"] + (["voice_budget", "voice_reserve"] if momento == "despues" else [])
for k in cero:
    v = d.get(k)
    try:
        if v is None or float(v) != 0.0:
            mal.append("%s=%s" % (k, json.dumps(v)))
    except ValueError:
        mal.append("%s=%s" % (k, json.dumps(v)))
print("OK" if not mal else "MAL " + " ".join(mal))' "$1" 2>/dev/null || echo "MAL ilegible"
}
# El monitor del H7 en seco, solo las integraciones: sus problemas ordenados (o «ok», o «?»). No abre incidentes.
monitor_integraciones() {
  local salida
  salida=$(artisan ultron-studio:monitor --check=integrations --dry-run --json 2>/dev/null) || true
  printf '%s' "$salida" | python3 -c '
import json, re, sys
try:
    d = json.load(sys.stdin)
    r = d["checks"]["integrations"]
except Exception:
    print("?")
    sys.exit(0)
p = sorted(str(x) for x in r.get("problems", []) if re.fullmatch(r"[A-Za-z0-9_.:>\-]{1,120}", str(x)))
print("ok" if r.get("ok") is True else (",".join(p) if p else "?"))' 2>/dev/null || echo '?'
}
# Pestañas con el CRM viejo (P9-25): en los últimos 10 minutos del access.log de nginx, peticiones GET al vale SIN
# `scope` (el CRM viejo) y CON `scope=` (el nuevo), y las peticiones a /api/ (contexto: 0 de noche es normal).
# Imprime «viejas nuevas api» o «?» si no se puede leer. No imprime ninguna línea del registro (llevan IP).
pestanas() {
  [ -r "$NGINX_ACCESS" ] || { echo "?"; return 0; }
  tail -c 50000000 "$NGINX_ACCESS" 2>/dev/null | python3 -I -c '
import datetime, re, sys, time
fecha = re.compile(r"\[(\d{2}/[A-Za-z]{3}/\d{4}:\d{2}:\d{2}:\d{2} [+-]\d{4})\]")
vale = re.compile(r"\"GET /api/admin/stream-ticket(\?[^ \"]*)? HTTP")
api = re.compile(r"\"[A-Z]+ /api/")
limite = time.time() - 600
viejas = nuevas = total = 0
for linea in sys.stdin.buffer:
    s = linea.decode("utf-8", "replace")
    f = fecha.search(s)
    if not f:
        continue
    try:
        t = datetime.datetime.strptime(f.group(1), "%d/%b/%Y:%H:%M:%S %z").timestamp()
    except ValueError:
        continue
    if t < limite:
        continue
    if api.search(s):
        total += 1
    m = vale.search(s)
    if m:
        if m.group(1) and "scope=" in m.group(1):
            nuevas += 1
        else:
            viejas += 1
print(viejas, nuevas, total)' 2>/dev/null || echo "?"
}
GOBIERNO_H9="'ultron_studio_person_bindings', 'ultron_studio_strategies', 'ultron_studio_message_templates', 'ultron_studio_strategy_versions', 'ultron_studio_strategy_simulations', 'ultron_studio_strategy_reviews', 'ultron_studio_strategy_publications', '$TABLA_W9'"
H9_REL="(c.relname LIKE 'outreach\_%' OR c.relname IN ($GOBIERNO_H9))"
FUNC_REL="(proname LIKE 'outreach\_%' OR proname LIKE 'ultron\_studio\_chk\_%' OR proname = 'ultron_studio_strategy_publication_chain')"
Q_ESQUEMA="SELECT (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron\_studio\_%')||' '||(SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname LIKE 'ultron\_studio\_%')||' '||(SELECT count(*) FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid WHERE t.relname LIKE 'ultron\_studio\_%' AND i.indpred IS NOT NULL)||' '||(SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'ultron\_studio\_%')||' '||(SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'outreach\_%')||' '||(SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname LIKE 'outreach\_%')||' '||(SELECT count(*) FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid WHERE t.relname LIKE 'outreach\_%' AND i.indpred IS NOT NULL)||' '||(SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'outreach\_%');"
# La voz: disparadores · CHECK · tablas de voice_% · cuántos vc_requester_chk · cuántos vbr_reason_chk. Las migraciones
# de la ola reescriben dos CHECK (DROP + ADD en una sentencia) y añaden un índice: esto es IGUAL antes y después.
Q_VOZ="SELECT (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'voice\_%')||' '||(SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname LIKE 'voice\_%')||' '||(SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'voice\_%')||' '||(SELECT count(*) FROM pg_constraint WHERE conname = 'vc_requester_chk')||' '||(SELECT count(*) FROM pg_constraint WHERE conname = 'vbr_reason_chk');"
Q_EFECTOS="SELECT CASE WHEN coalesce((SELECT bool_or(pg_get_constraintdef(oid) LIKE '%''strategy''%') FROM pg_constraint WHERE conname = 'vc_requester_chk'), false) THEN 't' ELSE 'f' END||' '||CASE WHEN to_regclass('public.$TABLA_W9') IS NOT NULL THEN 't' ELSE 'f' END||' '||CASE WHEN coalesce((SELECT bool_or(pg_get_constraintdef(oid) LIKE '%''webhook_unhealthy''%') FROM pg_constraint WHERE conname = 'vbr_reason_chk'), false) THEN 't' ELSE 'f' END||' '||CASE WHEN EXISTS (SELECT 1 FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = 'vc_one_call_per_strategy_job' AND i.indisunique AND i.indisvalid) THEN 't' ELSE 'f' END;"
Q_LARGAS="SELECT count(*) FROM pg_stat_activity a WHERE a.xact_start < now() - interval '30 seconds' AND a.pid <> pg_backend_pid();"
Q_BLOQ_VOZ="SELECT count(*) FROM pg_locks l JOIN pg_class c ON c.oid = l.relation WHERE c.relname IN ('voice_calls', 'voice_brakes') AND l.pid <> pg_backend_pid();"
Q_HUELLA="SELECT md5(pg_get_functiondef(oid)) FROM pg_proc WHERE proname = 'ultron_studio_forbid';"
MIG_LISTA_SQL=$(for m in $MIG_OLA; do printf "'%s', " "$m"; done); MIG_LISTA_SQL=${MIG_LISTA_SQL%, }
Q_MIG_OLA="SELECT coalesce(string_agg(migration, ' ' ORDER BY migration), '') FROM migrations WHERE migration IN ($MIG_LISTA_SQL);"
Q_MIGRACIONES="SELECT migration FROM migrations;"
Q_FUNC="SELECT count(*)||' '||count(*) FILTER (WHERE prosecdef)||' '||count(DISTINCT pg_get_userbyid(proowner))||' '||coalesce(min(pg_get_userbyid(proowner)), '') FROM pg_proc WHERE $FUNC_REL;"
Q_APAGADOS="SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND (c.relname LIKE 'outreach\_%' OR c.relname LIKE 'ultron\_studio\_%' OR c.relname LIKE 'voice\_%') AND t.tgenabled <> 'O';"
Q_DUENOS_H9="SELECT coalesce(string_agg(c.relname || ':' || pg_get_userbyid(c.relowner), ' ' ORDER BY c.relname COLLATE \"C\"), '') FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind = 'r' AND $H9_REL;"
Q_PRIV_H9="SELECT coalesce(string_agg(c.relname || '=' || CASE WHEN has_table_privilege('$APP_BD', c.oid, 'SELECT') THEN 'r' ELSE '-' END || CASE WHEN has_table_privilege('$APP_BD', c.oid, 'INSERT') THEN 'a' ELSE '-' END || CASE WHEN has_any_column_privilege('$APP_BD', c.oid, 'UPDATE') THEN 'w' ELSE '-' END || CASE WHEN has_table_privilege('$APP_BD', c.oid, 'DELETE') THEN 'd' ELSE '-' END || CASE WHEN has_table_privilege('$APP_BD', c.oid, 'TRUNCATE') THEN 'D' ELSE '-' END || CASE WHEN to_regclass('public.' || c.relname || '_id_seq') IS NULL THEN '?' WHEN has_sequence_privilege('$APP_BD', 'public.' || c.relname || '_id_seq', 'USAGE') THEN 's' ELSE '-' END, ' ' ORDER BY c.relname COLLATE \"C\"), '') FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind = 'r' AND $H9_REL;"
Q_FILAS_H9="SELECT coalesce(string_agg(c.relname || '=' || (xpath('/row/n/text()', query_to_xml(format('SELECT count(*) AS n FROM public.%I', c.relname), false, true, '')))[1]::text, ' ' ORDER BY c.relname COLLATE \"C\"), '') FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind = 'r' AND $H9_REL;"
# Los privilegios de iron_app en las tablas del H5–H8 y de la voz (antes = después) y las filas del Studio y de la voz.
Q_PRIV_PREVIAS="SELECT string_agg(t||'='||has_table_privilege('$APP_BD', 'public.' || t, 'SELECT')||'/'||has_table_privilege('$APP_BD', 'public.' || t, 'INSERT')||'/'||has_any_column_privilege('$APP_BD', 'public.' || t, 'UPDATE')||'/'||has_table_privilege('$APP_BD', 'public.' || t, 'DELETE')||'/'||has_table_privilege('$APP_BD', 'public.' || t, 'TRUNCATE'), ' ' ORDER BY t) FROM unnest(ARRAY['ultron_studio_publications', 'ultron_studio_audit_events', 'ultron_studio_integration_snapshots', 'ultron_studio_campaign_links', 'ultron_studio_canary_numbers', 'ultron_studio_voice_publications', 'voice_calls', 'voice_brakes', 'voice_contacts', 'voice_consents']) AS t WHERE to_regclass('public.' || t) IS NOT NULL;"
Q_FILAS_PREVIAS="SELECT (SELECT count(*) FROM ultron_studio_publications)||' '||(SELECT count(*) FROM ultron_studio_audit_events)||' '||(SELECT count(*) FROM ultron_studio_integration_snapshots)||' '||(SELECT count(*) FROM ultron_studio_versions)||' '||(SELECT count(*) FROM ultron_studio_voice_publications)||' '||(SELECT count(*) FROM voice_calls)||' '||(SELECT count(*) FROM voice_contacts);"
Q_COLA_H9="SELECT CASE WHEN to_regclass('public.jobs') IS NULL THEN 'sin_tabla' ELSE (SELECT count(*) FROM jobs WHERE queue LIKE 'outreach%')::text END;"
# Las tablas de hoy, sin la de W9 (en una reanudación ya existe y el respaldo, de antes, no la tiene).
Q_TABLAS_VIVAS="SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename <> '$TABLA_W9';"
sin_w9() { printf '%s\n' $1 | grep -v "^$TABLA_W9=" | paste -sd ' ' -; }
OPS=/var/www/api/backend/docs/ultron-studio/ops

# ── Paso 12: los smokes del H9 (fase 1) y del H8, DEL COMMIT DESPLEGADO ───────────────────────────────────────────────
# Se copian de su checkout a un directorio 700 bajo $LOGDIR (los lanzadores borran sus copias al terminar, por eso cada uno
# lleva la suya, también de smoke-h6.py) y se corren uno tras otro (la misma cuenta 32 y el cupo de inicios de sesión).
# Las variables SMOKE_* que ya traiga el entorno (el banco) se respetan; SMOKE_WORKDIR y SMOKE_PHASE los fija este guion.
smoke() { # h9|h8 → código de salida del lanzador (0 OK · 1 FALLO · 2 uso · 3 OMITIDOS)
  local h=$1 d rc=0
  d="$LOGDIR/smoke-cierre-$h-$TS"
  ( umask 077; mkdir -p "$d" ) || return 2
  for f in "smoke-run-$h.sh" "smoke-$h.py" smoke-h6.py; do
    [ -f "$OPS/$f" ] || { echo "   ✗ falta $OPS/$f en el código desplegado"; return 2; }
    install -m 600 "$OPS/$f" "$d/$f"
  done
  if [ "$h" = h8 ]; then
    [ -f "$OPS/perf-h8/presupuestos-h8.json" ] || { echo "   ✗ falta $OPS/perf-h8/presupuestos-h8.json"; return 2; }
    install -m 600 "$OPS/perf-h8/presupuestos-h8.json" "$d/presupuestos-h8.json"
  elif [ -f "$OPS/perf-h9/presupuestos-h9.json" ]; then
    install -m 600 "$OPS/perf-h9/presupuestos-h9.json" "$d/presupuestos-h9.json"
  fi
  echo "   smoke del $(printf '%s' "$h" | tr a-z A-Z) (huellas: $(cd "$d" && sha256sum smoke-run-$h.sh smoke-$h.py | awk '{print substr($1, 1, 12)}' | paste -sd ' ' -)):"
  set +e
  ( export SMOKE_WORKDIR="$d" SMOKE_PHASE=1; unset SMOKE_PY SMOKE_H6_PY SMOKE_BUDGETS; bash "$d/smoke-run-$h.sh" ) 2>&1 | sed 's/^/      /'
  rc=${PIPESTATUS[0]}
  set -e
  rmdir "$d" 2> /dev/null || true   # los lanzadores borran sus copias; si algo quedó (sus tokens no: los borra su trap), se queda
  return "$rc"
}
correr_smokes() {
  local r9=0 r8=0
  echo "12. smokes (cuenta 32 «Studio smoke»; vuelve a su reposo al terminar, también si fallan):"
  smoke h9 || r9=$?
  smoke h8 || r8=$?
  echo "   smoke del H9: código $r9 · smoke del H8: código $r8 (0 OK · 3 sin fallos con OMITIDOS · 1 FALLO · 2 uso)"
  case "$r9" in 0|3) ;; *) desvio "el smoke del H9 salió con $r9" ;; esac
  case "$r8" in 0|3) ;; *) desvio "el smoke del H8 salió con $r8" ;; esac
}
vuelta_atras() {
  echo "   VUELTA ATRÁS (nivel 3; primero el backend, después el CRM si hace falta; NUNCA migrate:rollback):"
  echo "     [ \"\$(git -C /var/www/api rev-parse --short=7 HEAD)\" = $ESPERADO ] && ( cd /var/www/api && umask 022 && git checkout -q --detach \"\$(cat $BK/api-commit.txt)\" )"
  echo "     cd /var/www/api/backend && sudo -u www-data sh -c 'umask 077; php artisan config:cache -q && php artisan route:cache -q' && sudo -u www-data php artisan queue:restart"
  echo "     stat -c '%U %a' bootstrap/cache/config.php bootstrap/cache/routes-v7.php   # www-data 600 · rutas del Studio: $(echo "${RUTAS0:-121 …}" | cut -d' ' -f1)"
  echo "     (opcional) el fichero de entorno de antes: install -m <modo> -o <dueño> -g <grupo> $BK/env.backup $ENVF y config:cache como arriba"
  echo "     la tabla $TABLA_W9 y el CHECK ampliado de voice_calls QUEDAN (vacía la una, superconjunto el otro): quitarlos es de database-specialist con autorización"
}

if [ -n "$SOLO_SMOKE" ]; then
  [ "$(g -C /var/www/api rev-parse --short=7 HEAD)" = "$ESPERADO" ] || aborta "producción no está en $ESPERADO: --solo-smoke es para después del despliegue"
  correr_smokes
  if [ "$DESVIOS" = 0 ]; then echo "SMOKES DEL CIERRE DEL H9 EN VERDE"; else echo "SMOKES DEL CIERRE DEL H9 CON $DESVIOS DESVÍOS"; vuelta_atras; exit 1; fi
  exit 0
fi

echo "0. comprobaciones previas (solo lectura; si algo falla, no se toca nada):"
cd /var/www/api
g fetch -q origin
ACTUAL=$(g rev-parse --short=7 HEAD)
BASE=$PREVIO
case "$ACTUAL" in
  "$PREVIO")   REANUDA=0 ;;
  "$ESPERADO") REANUDA=1; echo "   reanudación: el código ya está en $ESPERADO (una ejecución anterior se cortó); se repiten variables, migraciones y comprobaciones" ;;
  *) aborta "producción no está en $PREVIO ni en $ESPERADO (HEAD $ACTUAL): si se desplegó otra cosa después, revisar qué entró y actualizar PREVIO en un commit" ;;
esac
[ "$(g status --porcelain --untracked-files=no | wc -l | tr -d ' ')" = 0 ] || aborta "el checkout de producción tiene cambios locales"
[ "$(g rev-parse --short=7 origin/main)" = "$ESPERADO" ] || aborta "origin/main es $(g rev-parse --short=7 origin/main), no $ESPERADO"
g merge-base --is-ancestor "$BASE" origin/main || aborta "origin/main no es avance rápido desde $BASE"
# El respaldo de hoy (bloque del plan de despliegue): api-commit.txt = PREVIO, volcado de < 2 h con su huella, 700/600 de
# root, tantas tablas con datos como tablas tiene hoy la base y, salvo en una reanudación, el entorno = env.backup.
case "$(cat "$BK/api-commit.txt" 2>/dev/null)" in
  "$BASE"*) ;;
  *) aborta "$BK/api-commit.txt no es $BASE (la vuelta atrás del código lo lee; si el respaldo se rehizo en una reanudación: git -C /var/www/api rev-parse $BASE > $BK/api-commit.txt)" ;;
esac
[ -s "$BK/ironbody.dump" ] && [ -n "$(find "$BK/ironbody.dump" -mmin -120)" ] || aborta "no hay volcado de menos de 2 h en $BK"
[ "$(stat -c '%a' "$BK")" = 700 ] || aborta "el directorio del respaldo tiene que ser 700 (es $(stat -c '%a' "$BK"))"
[ "$(stat -c '%U %a' "$BK/ironbody.dump")" = "$ROOT_U 600" ] && [ "$(stat -c '%U %a' "$BK/env.backup")" = "$ROOT_U 600" ] || aborta "el volcado y env.backup tienen que ser de $ROOT_U y 600"
[ -f "$BK/ironbody.dump.sha256" ] || aborta "falta $BK/ironbody.dump.sha256: un volcado sin su huella no se puede comprobar después"
( cd "$BK" && sha256sum -c --status ironbody.dump.sha256 ) || aborta "el volcado no coincide con su ironbody.dump.sha256"
TABLAS_BK=$(pg_restore -l "$BK/ironbody.dump" | grep -c 'TABLE DATA' || true)
TABLAS_VIVAS=$($PSQL -c "$Q_TABLAS_VIVAS") || aborta "no se pudo contar las tablas de la base (psql)"
[ "$TABLAS_BK" -ge "$TABLAS_VIVAS" ] || aborta "el volcado lista $TABLAS_BK tablas con datos y la base tiene $TABLAS_VIVAS: el respaldo no es de hoy o está incompleto"
if [ "$REANUDA" = 0 ]; then cmp -s "$ENVF" "$BK/env.backup" || aborta "el fichero de entorno cambió desde el respaldo: rehacer el respaldo (env.backup tiene que ser el de partida)"; fi
echo "   respaldo $BK: $TABLAS_BK tablas con datos (la base tiene $TABLAS_VIVAS) · 700/600 de root · huella del volcado comprobada"
# Las migraciones: entre PREVIO y origin/main solo las de la lista, AÑADIDAS (ninguna ya desplegada se toca ni se borra).
NUEVAS=$(g diff --name-status --no-renames "$BASE" origin/main -- backend/database/migrations | awk '{print $1 ":" $2}' | sed 's#backend/database/migrations/##; s#\.php$##' | sort | tr '\n' ' ')
ESPERADAS_DIFF=$(for m in $MIG_OLA; do echo "A:$m"; done | sort | tr '\n' ' ')
[ "$NUEVAS" = "$ESPERADAS_DIFF" ] || aborta "migraciones nuevas o tocadas entre $BASE y origin/main [$NUEVAS], distintas de la lista cerrada de la ola [$ESPERADAS_DIFF]. Una migración ajena se despliega antes por su vía; una que falte en la lista se añade a MIG_OLA en un commit con su revisión (database-specialist)"
# Los commits que traería el merge y que producción no tiene (por parche, frente a los últimos 400 de producción). Los de
# la ola se reconocen por su prefijo (`tipo(ultron-studio-h9):` o `tipo(ultron-studio-h8):`, tipo simple o con barras).
# `outreach(sends-open):` o `voice(calls-open):` ABORTAN: abren las constantes, y eso se despliega aparte, con su orden.
EN_PROD=$(g log --no-merges -p --max-count=400 "$BASE" | g patch-id --stable | awk '{print $1}' | sort -u)
AJENOS=""; ACEPTADOS_VISTOS=""; PROPIOS=0
while read -r pid cid; do
  [ -n "${cid:-}" ] || continue
  grep -qxF "$pid" <<< "$EN_PROD" && continue
  ASUNTO=$(g log -1 --format=%s "$cid")
  [[ "$ASUNTO" =~ ^outreach\(sends-open\): ]] && aborta "el commit ${cid:0:7} abre la constante de envío (\`outreach(sends-open):\`): se despliega aparte, con la autorización escrita del propietario y el visto bueno legal (§14 fase 4), nunca con esta ola"
  [[ "$ASUNTO" =~ ^voice\(calls-open\): ]] && aborta "el commit ${cid:0:7} abre la constante de llamadas del H8 (\`voice(calls-open):\`): se despliega aparte (deploy-h8-backend-fase1.sh --fase=4a), nunca con esta ola"
  if [[ "$ASUNTO" =~ ^[a-z]+(/[a-z]+)*\(ultron-studio-h[89]\): ]]; then PROPIOS=$((PROPIOS + 1)); continue; fi
  case ",$ACEPTA," in *",${cid:0:7},"*) ACEPTADOS_VISTOS="$ACEPTADOS_VISTOS ${cid:0:7}"; continue ;; esac
  AJENOS="$AJENOS ${cid:0:7}"
done < <(g log --no-merges -p "$BASE..origin/main" | g patch-id --stable)
for c in ${ACEPTA//,/ }; do
  case " $ACEPTADOS_VISTOS " in *" $c "*) ;; *) aborta "--acepta-main=$c: ese commit no está entre los ajenos que traería el merge desde $BASE: no se acepta nada a ciegas" ;; esac
done
[ -z "$ACEPTADOS_VISTOS" ] || echo "   commits ajenos ACEPTADOS por el propietario con --acepta-main (viajan con la ola):$ACEPTADOS_VISTOS"
FALTA_DECIDIR=""
if [ -n "$AJENOS" ]; then
  LISTA="$(for c in $AJENOS; do printf '%s «%s» · ' "$c" "$(g log -1 --format='%s' "$c" | cut -c1-60)"; done)"
  if [ -n "$SOLO_COMPROBAR" ]; then
    FALTA_DECIDIR=$(echo $AJENOS | tr ' ' ',')
    echo "   PENDIENTE DE DECISIÓN: commits ajenos a la ola que producción no tiene y viajarían con el merge: ${LISTA% · }"
  else
    aborta "commits ajenos a la ola entre $BASE y origin/main: ${LISTA% · }. O su dueño los despliega antes por su vía, o el propietario los acepta ahora con --acepta-main=$(echo $AJENOS | tr ' ' ',')"
  fi
fi
# La puerta de diff CERRADA: cada fichero de backend/ que cambie entre PREVIO y origin/main está en una zona del Studio o
# de la ejecución, en pruebas o docs, en la lista de migraciones o en la LISTA CERRADA; si no, aborta. Fuera de backend/
# no se despliega nada (el repositorio del servidor es el del backend).
PERMITIDOS=$(printf '%s\n' "$LISTA_CERRADA" | awk '{print $1}')
FUERA=""; EN_LISTA=""; LIBRES=0
while IFS=$'\t' read -r st f; do
  [ -n "${f:-}" ] || continue
  case "$f" in backend/database/migrations/*) continue ;; esac
  libre=0; for z in $ZONAS_LIBRES; do case "$f" in "$z"*) libre=1; break ;; esac; done
  if [ "$libre" = 1 ]; then LIBRES=$((LIBRES + 1)); continue; fi
  if printf '%s\n' "$PERMITIDOS" | grep -qxF "$f"; then EN_LISTA="$EN_LISTA $f"; else FUERA="$FUERA $st:$f"; fi
done < <(g diff --name-status --no-renames "$BASE" origin/main)
[ -z "$FUERA" ] || aborta "la puerta de diff de la ola tiene ficheros que su lista cerrada no nombra:$FUERA. Si son de esta ola, se añaden a LISTA_CERRADA en un commit con su dueño y su revisión; si no, se despliegan antes por su vía"
for f in $SOLO_CODIGO_ANADIDO; do
  QUITADAS=$(g diff -U0 "$BASE" origin/main -- "$f" | grep '^-' | grep -v '^---' || true)
  [ -z "$QUITADAS" ] && continue
  NO_COMENTARIO=$(printf '%s\n' "$QUITADAS" | sed 's/^-//' | grep -vE '^[[:space:]]*(//|\*|/\*|\*/|#)' | grep -c . || true)
  CON_SCHEDULE=$(printf '%s\n' "$QUITADAS" | grep -c 'Schedule::' || true)
  [ "$NO_COMENTARIO" = 0 ] && [ "$CON_SCHEDULE" = 0 ] || aborta "$f quita $NO_COMENTARIO línea(s) de código (o $CON_SCHEDULE con Schedule::) entre $BASE y origin/main: en esta ola solo AÑADE código (las líneas que quita solo pueden ser comentarios)"
done
N_LISTA=$(echo $EN_LISTA | wc -w | tr -d ' ')
echo "   puerta de diff: $LIBRES ficheros en las zonas del Studio, de la ejecución, pruebas y docs · $N_LISTA de la lista cerrada (fuera del Studio):"
for f in $EN_LISTA; do printf '%s\n' "$LISTA_CERRADA" | awk -v f="$f" '$1 == f {$1 = ""; sub(/^ +/, ""); printf "      %s — %s\n", f, $0}'; done
# Las dos constantes, ANTES del merge, en el commit que se va a desplegar: cerradas (una declaración `= false;` anclada
# y ninguna `= true`). VoiceCallPolicy.php no puede cambiar (no está en la lista cerrada: la puerta ya habría abortado).
GATES=$(g ls-tree -r --name-only origin/main -- backend/app/Services/Outreach | grep -E '/OutreachSendGate\.php$' || true)
[ "$(printf '%s\n' "$GATES" | grep -c . || true)" = 1 ] || aborta "en origin/main hay $(printf '%s\n' "$GATES" | grep -c . || true) ficheros OutreachSendGate.php bajo app/Services/Outreach (esperado 1)"
[ "$(g show "origin/main:$GATES" | grep -cE '^[[:space:]]*public const SENDS_ENABLED_IN_CODE = false;[[:space:]]*$' || true)" = 1 ] \
  && [ "$(g show "origin/main:$GATES" | grep -cE 'SENDS_ENABLED_IN_CODE[[:space:]]*=[[:space:]]*true' || true)" = 0 ] \
  || aborta "OutreachSendGate::SENDS_ENABLED_IN_CODE no está exactamente cerrada en origin/main ($GATES): esta ola se despliega con la constante en false"
VCP=backend/app/Services/Voice/VoiceCallPolicy.php
[ "$(g show "origin/main:$VCP" | grep -cE '^[[:space:]]*public const CALLS_ENABLED_IN_CODE = false;[[:space:]]*$' || true)" = 1 ] \
  && [ "$(g show "origin/main:$VCP" | grep -cE 'CALLS_ENABLED_IN_CODE[[:space:]]*=[[:space:]]*true' || true)" = 0 ] \
  || aborta "VoiceCallPolicy::CALLS_ENABLED_IN_CODE no está exactamente cerrada en origin/main: esta ola no abre las llamadas"
echo "   constantes en origin/main: OutreachSendGate::SENDS_ENABLED_IN_CODE = false · VoiceCallPolicy::CALLS_ENABLED_IN_CODE = false"
# Las migraciones en la base: lo que falta de la ola (todas, o el resto en una reanudación) y NADA ajeno pendiente.
MIG_REG=$($PSQL -c "$Q_MIG_OLA") || aborta "no se pudo leer la tabla migrations (psql)"
APLICADAS=$($PSQL -c "$Q_MIGRACIONES" | sort -u) || aborta "no se pudo leer la tabla migrations (psql)"
PENDIENTES=$(comm -23 <(g ls-tree --name-only origin/main -- backend/database/migrations/ | sed -n 's#^backend/database/migrations/\([^/]*\)\.php$#\1#p' | sort -u) <(printf '%s\n' "$APLICADAS") | tr '\n' ' ')
ESPERADAS=""; for m in $MIG_OLA; do case " $MIG_REG " in *" $m "*) ;; *) ESPERADAS="$ESPERADAS$m " ;; esac; done
[ "$PENDIENTES" = "$ESPERADAS" ] || aborta "migraciones pendientes en origin/main frente a la base: [$PENDIENTES] (esperadas, solo las de la ola que faltan: [$ESPERADAS]). ironbody-migrate aplicaría TODAS: lo ajeno se despliega antes por su vía"
ESQ0=$($PSQL -c "$Q_ESQUEMA") || aborta "no se pudo leer el esquema (psql)"
VOZ0=$($PSQL -c "$Q_VOZ") || aborta "no se pudo leer el esquema de la voz (psql)"
EFE0=$($PSQL -c "$Q_EFECTOS") || aborta "no se pudo leer el efecto de las migraciones de la ola (psql)"
if [ -z "$MIG_REG" ]; then
  [ "$ESQ0" = "$ESQ_ANTES" ] || aborta "esquema de partida [$ESQ0], esperado el de la fase 1 [$ESQ_ANTES]: PARAR y avisar a database-specialist"
  case "$VOZ0" in *" 1 1") ;; *) aborta "voice_% de partida [$VOZ0]: se esperaba exactamente un vc_requester_chk y un vbr_reason_chk (los de DB8)" ;; esac
  [ "$EFE0" = "$EFECTOS_ANTES" ] || aborta "efecto de las migraciones de la ola ANTES de aplicarlas [$EFE0], esperado [$EFECTOS_ANTES]: el esquema no es el de la fase 1; PARAR y avisar a database-specialist"
fi
[ "$($PSQL -c "$Q_HUELLA")" = "$HUELLA_F" ] || aborta "ultron_studio_forbid() no es la de F: PARAR y avisar a database-specialist"
DUENOS_ID=$($PSQL -c "SELECT (SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE proname = 'ultron_studio_forbid')||' '||(SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = 'voice_calls')||' '||has_schema_privilege('$DUENO_BD', 'public', 'CREATE')||' '||(SELECT count(*) FROM pg_roles WHERE rolname = '$APP_BD');") || aborta "no se pudo leer los dueños (psql)"
[ "$DUENOS_ID" = "$DUENO_BD $DUENO_BD true 1" ] || aborta "dueños de partida [$DUENOS_ID] (esperado: F y voice_calls de $DUENO_BD, CREATE de $DUENO_BD en public y el rol $APP_BD): sin eso las migraciones de la ola dan 42501"
FUNC0=$($PSQL -c "$Q_FUNC")
[ -n "$MIG_REG" ] || [ "$FUNC0" = "$FUNC_ANTES" ] || aborta "funciones del H9 de partida [$FUNC0], esperado [$FUNC_ANTES]"
PRIVP_0=$($PSQL -c "$Q_PRIV_PREVIAS") || aborta "no se pudieron leer los privilegios de iron_app en el H5–H8 (psql)"
FILASP_0=$($PSQL -c "$Q_FILAS_PREVIAS") || aborta "no se pudieron contar las filas del Studio y de la voz (psql)"
FILAS9_0=$($PSQL -c "$Q_FILAS_H9") || aborta "no se pudieron contar las filas del H9 (psql)"
FILAS9_0=$(sin_w9 "$FILAS9_0")
BLOQ=$($PSQL -c "$Q_BLOQ_VOZ"); LARGAS=$($PSQL -c "$Q_LARGAS")
RUTAS0=$(rutas)
case "$REANUDA" in
  0) [ "$RUTAS0" = "121 30 13 9 4 1" ] || aborta "rutas en la caché de producción [$RUTAS0] (esperado [121 30 13 9 4 1]: la fase 1 del H9)" ;;
  *) echo "   rutas en la caché ahora (informativo en una reanudación): [$RUTAS0]" ;;
esac
PROG0=$(programador)
case "$PROG0" in "FALLO "*|ILEGIBLE) aborta "schedule:list no se puede leer ($PROG0): sin el programador no se despliega (m-1 del H7)" ;; esac
read -r TOTAL0 STUDIO0 VOZ_T0 OUT0 <<< "$PROG0"
[ "$OUT0" = 0 ] || aborta "el programador ya tiene $OUT0 tareas outreach:* antes de desplegar (esperado 0: el H9 apagado)"
PREVIA_ANTES=$(leer_previa)
[ -n "$PREVIA_ANTES" ] || aborta "no se pudo leer la configuración efectiva del motor, de Legacy, del H4–H7 y de la voz (tinker falló; su salida no se imprime)"
H9_ANTES=$(leer_h9)
[ -n "$H9_ANTES" ] || aborta "no se pudo leer la configuración del H9 (tinker falló; su salida no se imprime)"
if [ "$REANUDA" = 0 ]; then
  APAGADO0=$(h9_apagado antes "$H9_ANTES")
  [ "$APAGADO0" = OK ] || aborta "el H9 no está todo apagado antes de desplegar (${APAGADO0#MAL }): esta ola se despliega sobre la fase 1. Apagar antes con el mando (bash /root/h9-outreach-scope.sh apagar) o pedir otra orden"
fi
W0=$(workers 999 20); RUN0=${W0%% *}
[ "${W0#* }" = 0 ] || aborta "workers con BACKOFF o FATAL antes de empezar ($W0): no se despliega sobre un sistema degradado"
[ "$RUN0" -ge 13 ] && [ "$RUN0" -le 16 ] || aborta "workers RUNNING antes de empezar: $RUN0 (esperado de 13 a 16)"
FACT=$(systemctl is-active ironbody-billing-worker 2>/dev/null || true)
[ "$FACT" = active ] || aborta "ironbody-billing-worker no está activo antes de empezar ($FACT)"
# P9-25: el CRM ya publicado con el commit de --crm y sin pestañas viejas en los últimos 10 minutos.
PUB=$(curl -fsS "$CRM_WEB/version.json" 2>/dev/null || true)
printf '%s' "$PUB" | grep -Eq "\"commit\": *\"$CRM\"" || aborta "el CRM publicado no es $CRM ($CRM_WEB/version.json: ${PUB:0:120}): primero deploy-h9-crm-cierre.sh (P9-25: el CRM va ANTES que el backend)"
[ "$CRM" != "$CRM_PREVIO" ] || aborta "--crm=$CRM es el CRM de antes de la ola ($CRM_PREVIO): primero deploy-h9-crm-cierre.sh con el build de la ola (P9-25)"
PEST=$(pestanas)
case "$PEST" in
  "?") if [ -n "$ACEPTA_PESTANAS" ]; then echo "   AVISO: no se pudo leer $NGINX_ACCESS: el propietario acepta desplegar sin comprobar las pestañas (--acepta-pestanas-viejas)"; else aborta "no se pudo leer $NGINX_ACCESS para comprobar que no quedan pestañas con el CRM viejo (P9-25). Si el vhost de la API registra en otro fichero, corregir NGINX_ACCESS en un commit; o el propietario acepta con --acepta-pestanas-viejas"; fi ;;
  *) read -r P_VIEJAS P_NUEVAS P_API <<< "$PEST"
     echo "   pestañas (últimos 10 min del access.log): $P_VIEJAS vales sin scope (CRM viejo) · $P_NUEVAS con scope (CRM nuevo) · $P_API peticiones a /api/"
     if [ "$P_VIEJAS" != 0 ]; then
       if [ -n "$ACEPTA_PESTANAS" ]; then echo "   AVISO: hay pestañas con el CRM viejo y el propietario acepta desplegar (--acepta-pestanas-viejas): perderán las fotos y el canal en vivo hasta recargarlas"
       else aborta "$P_VIEJAS peticiones de vale sin scope en los últimos 10 minutos: quedan pestañas con el CRM viejo (recepción, punto facial). Recargarlas, esperar 10 minutos y repetir; o el propietario acepta con --acepta-pestanas-viejas"; fi
     fi ;;
esac
LOG_ANTES=$(lineas_log)
FALLIDAS_ANTES=$($PSQL -c "SELECT count(*) FROM failed_jobs;") || aborta "no se pudo leer failed_jobs (psql)"
INST_ANTES=$($PSQL -c "SELECT count(*) FROM ultron_studio_integration_snapshots;") || aborta "no se pudieron contar las instantáneas (psql)"
INCID_ANTES=$($PSQL -c "SELECT count(*) FROM incidents WHERE source IN ('ultron_studio', 'outreach', 'voice');") || aborta "no se pudieron contar los incidentes (psql)"
MONI0=$(monitor_integraciones)
[ "$MONI0" != "?" ] || aborta "el monitor del H7 en seco (integraciones) no devolvió un resultado legible"
case ",$MONI0," in *",config_drift:"*) aborta "el monitor del H7 ya ve deriva de la configuración autorizada antes de desplegar ([$MONI0]): aclararlo antes" ;; esac
sudo -u "$WWW" php -r 'require "/var/www/api/backend/vendor/autoload.php"; try { Dotenv\Dotenv::createArrayBacked("/var/www/api/backend")->load(); } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' \
  || aborta "el fichero de entorno de producción NO parsea antes de empezar: aclararlo antes de desplegar (no se ha tocado nada)"
echo "   HEAD $ACTUAL limpio · $PROPIOS commits de la ola · migraciones de la ola pendientes [${PENDIENTES% }] · esquema [$ESQ0] · voz [$VOZ0] · efectos [$EFE0] · función de F · rutas [$RUTAS0] · programador $TOTAL0 tareas ($STUDIO0 del Studio, $VOZ_T0 de voz, 0 de outreach) · workers $W0 · facturación activa"
echo "   CRM publicado: $CRM · H9 antes (informativo): $H9_ANTES"
echo "   motor, Legacy, H4–H7 y voz (informativo; no cambia): $PREVIA_ANTES"
echo "   iron_app en el H5–H8 y la voz: $PRIVP_0 · filas (publicaciones, libro, instantáneas, versiones, voz publicada, llamadas, contactos): $FILASP_0"
echo "   colas fallidas $FALLIDAS_ANTES · incidentes del Studio, outreach y voz $INCID_ANTES · monitor del H7 en seco (integraciones): [$MONI0]"
[ "$BLOQ" = 0 ] || echo "   AVISO: $BLOQ cerrojos sobre voice_calls o voice_brakes de otras sesiones: las migraciones de la voz piden ACCESS EXCLUSIVE o SHARE (lock_timeout 5 s, se reintenta)"
[ "$LARGAS" = 0 ] || echo "   AVISO: $LARGAS transacciones abiertas desde hace más de 30 s"
echo "   el fichero de entorno parsea (antes de tocar nada)"
if [ -n "$SOLO_COMPROBAR" ]; then
  if [ -n "$FALTA_DECIDIR" ]; then
    echo "COMPROBACIONES PREVIAS LEÍDAS, FALTA DECIDIR los commits ajenos [$FALTA_DECIDIR] (no se ha tocado nada): el despliegue solo sigue con --acepta-main=$FALTA_DECIDIR (decisión del propietario) o cuando su dueño los haya desplegado"
    exit 3
  fi
  echo "COMPROBACIONES PREVIAS EN VERDE (no se ha tocado nada)"
  exit 0
fi

g merge -q --ff-only origin/main
HEAD=$(g rev-parse --short=7 HEAD)
[ "$HEAD" = "$ESPERADO" ] || aborta "HEAD $HEAD no es $ESPERADO"
echo "1. código: HEAD=$HEAD"
ILEGIBLES=0; REVISADOS=0
CAMBIADOS=$(g diff --name-only "$PREVIO" HEAD) || aborta "git diff --name-only $PREVIO HEAD falló"
while IFS= read -r f; do
  [ -n "$f" ] || continue
  [ -e "$f" ] || continue
  REVISADOS=$((REVISADOS + 1))
  sudo -u "$WWW" test -r "$f" || { echo "   ✗ $WWW no puede leer $f ($(stat -c '%U:%G %a' "$f"))"; ILEGIBLES=$((ILEGIBLES + 1)); }
done <<< "$CAMBIADOS"
[ "$REVISADOS" -gt 0 ] || aborta "la comprobación de lectura no revisó ningún fichero (el diff $PREVIO..HEAD salió vacío)"
[ "$ILEGIBLES" = 0 ] || aborta "$ILEGIBLES de $REVISADOS ficheros del merge no los lee www-data: chmod 644 (ficheros) / 755 (directorios) sobre git diff --name-only $PREVIO HEAD antes de seguir; relanzar este mismo script (reanuda)"
echo "   los $REVISADOS ficheros del merge son legibles por www-data"
cd backend
echo "2. variables ($N_VARS: las claves nuevas de la ola, apagadas o en 0):"
[ -z "$(tail -c1 "$ENVF")" ] || printf '\n' >> "$ENVF"
for kv in $VARS; do
  k=${kv%%=*}; v=${kv#*=}
  case "$(estado_var "$k" "$v")" in
    falta) printf '  %s=%s\n' "$k" "$v" >> "$ENVF"; echo "   $k añadida" ;;
    igual) echo "   $k ya estaba con el valor de la ola" ;;
    repetida) aborta "$k aparece repetida en el fichero de entorno (gana la última): deduplicar antes; restáuralo desde $BK/env.backup si hace falta" ;;
    distinto) aborta "$k ya existe con OTRO valor (no se imprime): esta ola exige todo apagado; aclararlo antes (los mandos son el procedimiento para moverla después)" ;;
  esac
done
echo "   permisos del fichero: $(stat -c '%U %a' "$ENVF")"
sudo -u "$WWW" php -r 'require "vendor/autoload.php"; try { Dotenv\Dotenv::createArrayBacked(".")->load(); } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' \
  || aborta "el fichero de entorno NO parsea: restáuralo desde $BK/env.backup (mismo dueño y modo) antes de seguir"
echo "   el fichero de entorno parsea"
echo "3. migraciones (rol dueño, lock_timeout 5 s; un 55P03 se reintenta hasta 3 veces, 15 s entre intentos):"
INTENTO=0; RC=0
if [ -z "${PENDIENTES// /}" ]; then
  echo "   nada que migrar: las de la ola ya estaban registradas (paso 0); no se llama a ironbody-migrate"
else
  while :; do
    INTENTO=$((INTENTO + 1))
    set +e
    SALIDA=$(sudo env PGOPTIONS="-c lock_timeout=5s" "$MIGRADOR" 2>&1)
    RC=$?
    set -e
    printf '%s\n' "$SALIDA" | sed 's/^/   /'
    ESQ=$($PSQL -c "$Q_ESQUEMA") || aborta "no se pudo leer el esquema tras ironbody-migrate (psql). NO se han hecho las cachés; relanzar este mismo script (reanuda)"
    MIG_REG=$($PSQL -c "$Q_MIG_OLA")
    [ "$ESQ $MIG_REG" != "$ESQ_DESPUES $(echo $MIG_OLA | tr ' ' '\n' | sort | paste -sd ' ' -)" ] || break
    case "$SALIDA" in
      *55P03*|*"lock timeout"*)
        if [ "$INTENTO" -lt 3 ]; then echo "   intento $INTENTO: 55P03 (una sesión con voice_calls abierta) · esquema [$ESQ] · reintento en 15 s"; sleep 15; continue; fi ;;
    esac
    aborta "esquema tras ironbody-migrate [$ESQ], esperado [$ESQ_DESPUES] (código $RC, intento $INTENTO; registro [$MIG_REG]). Cada migración es una transacción: o está entera o no está. NO se han hecho las cachés; relanzar este mismo script (reanuda) o la vuelta atrás del código"
  done
fi
ESQ=$($PSQL -c "$Q_ESQUEMA"); VOZ=$($PSQL -c "$Q_VOZ"); EFE=$($PSQL -c "$Q_EFECTOS"); MIG_REG=$($PSQL -c "$Q_MIG_OLA")
[ "$ESQ" = "$ESQ_DESPUES" ] || aborta "esquema [$ESQ], esperado [$ESQ_DESPUES]: NO se han hecho las cachés; PARAR y avisar a database-specialist"
[ "$MIG_REG" = "$(echo $MIG_OLA | tr ' ' '\n' | sort | paste -sd ' ' -)" ] || aborta "registro de las migraciones de la ola [$MIG_REG] incompleto: NO se han hecho las cachés"
[ "$VOZ" = "$VOZ0" ] || aborta "voice_% tras la ola [$VOZ] (antes [$VOZ0]): se esperaban los mismos disparadores, CHECK, tablas y un solo vc_requester_chk y vbr_reason_chk. NO se han hecho las cachés"
[ "$EFE" = "$EFECTOS_DESPUES" ] || aborta "efecto de las migraciones de la ola [$EFE], esperado [$EFECTOS_DESPUES] (vc_requester_chk con strategy · tabla de W9 · vbr_reason_chk con webhook_unhealthy · índice vc_one_call_per_strategy_job). NO se han hecho las cachés; avisar a database-specialist"
[ "$RC" = 0 ] || desvio "ironbody-migrate salió con $RC aunque el esquema cuadra: revisar su salida (arriba)"
echo "   esquema [$ESQ] (intento ${INTENTO:-0}) · voz [$VOZ] (igual que antes) · efecto de las migraciones [$EFE]: vc_requester_chk con strategy, la tabla de W9, vbr_reason_chk con webhook_unhealthy y el índice de una llamada por trabajo"
echo "4. esquema de la ola (antes de las cachés):"
FUNC=$($PSQL -c "$Q_FUNC")
echo "   funciones del H9 (número · SECURITY DEFINER · dueños distintos · dueño): $FUNC"
[ "$FUNC" = "$FUNC_DESPUES" ] || aborta "funciones del H9 [$FUNC], esperado [$FUNC_DESPUES]. NO se han hecho las cachés"
APAG=$($PSQL -c "$Q_APAGADOS")
[ "$APAG" = 0 ] || aborta "$APAG disparadores apagados en ultron_studio_*, outreach_* o voice_*: NO se han hecho las cachés; PARAR y avisar a database-specialist"
[ "$($PSQL -c "$Q_HUELLA")" = "$HUELLA_F" ] || aborta "ultron_studio_forbid() cambió de cuerpo con la migración. NO se han hecho las cachés"
DUENOS=$($PSQL -c "$Q_DUENOS_H9")
case " $DUENOS " in *" $TABLA_W9:$DUENO_BD "*) ;; *) aborta "la tabla $TABLA_W9 no es de $DUENO_BD ([$DUENOS]). NO se han hecho las cachés" ;; esac
[ "$(printf '%s\n' $DUENOS | grep -vc ":$DUENO_BD\$" || true)" = 0 ] || aborta "tablas del H9 que no son de $DUENO_BD: [$DUENOS]. NO se han hecho las cachés"
PRIV9=$($PSQL -c "$Q_PRIV_H9")
case " $PRIV9 " in *" $PRIV_W9_ESPERADO "*) ;; *) aborta "privilegios de iron_app en $TABLA_W9 en [$PRIV9], esperado $PRIV_W9_ESPERADO (solo inserción). NO se han hecho las cachés; avisar a database-specialist" ;; esac
echo "   iron_app en las tablas del H9 (r SELECT · a INSERT · w UPDATE · d DELETE · D TRUNCATE · s secuencia): $PRIV9"
FILAS9=$($PSQL -c "$Q_FILAS_H9")
case " $FILAS9 " in *" $TABLA_W9=0 "*) ;; *) desvio "la tabla $TABLA_W9 no está vacía tras crearla: [$FILAS9]" ;; esac
[ "$(sin_w9 "$FILAS9")" = "$FILAS9_0" ] && echo "   las tablas del H9 con las mismas filas que antes y la de W9 vacía" || desvio "las filas de las tablas del H9 cambiaron durante el despliegue: antes [$FILAS9_0] · después [$FILAS9]"
PRIVP=$($PSQL -c "$Q_PRIV_PREVIAS")
[ "$PRIVP" = "$PRIVP_0" ] && echo "   iron_app en las tablas del H5–H8 y la voz: igual que antes" || desvio "los privilegios de iron_app en el H5–H8 o la voz cambiaron: antes [$PRIVP_0] · después [$PRIVP]"
FILASP=$($PSQL -c "$Q_FILAS_PREVIAS")
[ "$FILASP" = "$FILASP_0" ] && echo "   filas del Studio y de la voz: $FILASP (las mismas)" || desvio "las filas del Studio o de la voz cambiaron durante el despliegue: antes [$FILASP_0] · después [$FILASP]"
echo "5. cachés y workers:"
sudo -u "$WWW" sh -c 'umask 077; php artisan config:cache -q && php artisan route:cache -q'
# bootstrap/cache lo escribe www-data: root no hace chmod ahí (seguiría un enlace puesto en su lugar; H-5 de SEC7).
for f in bootstrap/cache/config.php bootstrap/cache/routes-v7.php; do
  [ -f "$f" ] && [ ! -L "$f" ] || aborta "$f no es un fichero normal (¿un enlace?): no se reinician los workers"
  [ "$(stat -c '%U' "$f")" = "$WWW" ] || aborta "$f no es de $WWW: no se reinician los workers (quedarían en BACKOFF)"
  [ "$(stat -c '%a' "$f")" = 600 ] || { sudo -u "$WWW" chmod 600 "$f" || aborta "$WWW no pudo dejar $f en 600"; echo "   $f pasado a 600 (como $WWW)"; }
done
echo "   cachés: $(stat -c '%U %a' bootstrap/cache/config.php) · $(stat -c '%U %a' bootstrap/cache/routes-v7.php)"
sudo -u "$WWW" php artisan queue:restart -q
H9=$(leer_h9)
RUTAS=$(rutas)
COBERTURA=$(artisan auth:coverage --unmapped 2>&1 | tr -d '\n' | cut -c1-80 || true)
STUDIO_TOTAL=$(printf '%s' "$H9" | python3 -I -c 'import json, sys; v = json.load(sys.stdin).get("studio_routes_total"); print(v if isinstance(v, int) else "?")' 2>/dev/null || echo '?')
echo "6. rutas (Studio · strategies/** · ejecución · GET · POST · canal SSE): $RUTAS (antes $RUTAS0; StudioRouteCount::TOTAL del código desplegado: $STUDIO_TOTAL) · auth:coverage --unmapped → [$COBERTURA]"
[ "$RUTAS" = "$RUTAS0" ] || desvio "rutas [$RUTAS] (antes [$RUTAS0]): esta ola no añade ni quita rutas"
[ "${RUTAS%% *}" = "$STUDIO_TOTAL" ] || desvio "el Studio sirve ${RUTAS%% *} rutas y StudioRouteCount::TOTAL dice $STUDIO_TOTAL"
[ -z "$COBERTURA" ] || desvio "auth:coverage --unmapped no está vacío"
echo "7. configuración efectiva:"
case "$H9" in '{"deployed"'*) echo "   $H9" ;; *) echo "   (tinker no devolvió la configuración del H9; su salida no se imprime)" ;; esac
APAGADO=$(h9_apagado despues "$H9")
[ "$APAGADO" = OK ] || desvio "la configuración efectiva del H9 no es la de la ola (todo apagado, las dos constantes cerradas, la voz del H9 en 0 y los contactos de voz apagados): ${APAGADO#MAL }"
PREVIA=$(leer_previa)
[ "$PREVIA" = "$PREVIA_ANTES" ] && echo "   motor comercial, Legacy, H4–H7 y voz del H8 iguales que antes del despliegue" || desvio "la configuración del motor, de Legacy, del H4–H7 o de la voz cambió: antes $PREVIA_ANTES · después ${PREVIA:-ilegible}"
PROG=$(programador)
ESP_PROG="$((TOTAL0 + TAREAS_NUEVAS_SIEMPRE)) $STUDIO0 $VOZ_T0 0"
case "$PROG" in
  "FALLO "*|ILEGIBLE) desvio "schedule:list no se puede leer tras el cambio ($PROG)" ;;
  *) echo "   programador (total · del Studio · de voz · de outreach): $PROG (esperado $ESP_PROG: +$TAREAS_NUEVAS_SIEMPRE sin bandera, admin:prune-stream-tickets; con todo apagado, ni voice:webhook-health ni los normalizadores)"
     [ "$PROG" = "$ESP_PROG" ] || desvio "el programador da [$PROG] y se esperaba [$ESP_PROG]" ;;
esac
COLA=$($PSQL -c "$Q_COLA_H9")
[ "$COLA" = 0 ] || [ "$COLA" = sin_tabla ] && echo "   colas outreach*: $COLA trabajos" || desvio "hay $COLA trabajos en colas outreach*: nada debería despacharse con todo apagado"
sleep 6
W1=$(workers "$RUN0" 45); sleep 8; W2=$(workers "$RUN0" 45)
echo "8. workers (RUNNING · BACKOFF/FATAL), dos lecturas separadas: [$W1] y [$W2] (esperado $RUN0 0: la ola no instala ningún worker)"
[ "$W1" = "$RUN0 0" ] && [ "$W2" = "$RUN0 0" ] || desvio "workers [$W1] y [$W2] (esperado $RUN0 0 las dos veces)"
FACT=$(systemctl is-active ironbody-billing-worker 2>/dev/null || true)
[ "$FACT" = active ] || desvio "ironbody-billing-worker: $FACT"
echo "9. sanidad:"
for p in admin/ultron-studio/outreach/status admin/ultron-studio/outreach/brakes admin/ultron-studio/strategies admin/ultron-studio/strategies/catalog admin/ultron-studio/strategies/templates admin/ultron-studio/status; do
  CAB=$(curl -sI -H 'Accept: application/json' "$API/$p" | tr -d '\r')
  c=$(printf '%s\n' "$CAB" | awk 'NR == 1 {print $2}')
  cc=$(printf '%s\n' "$CAB" | awk -F': *' 'tolower($1) == "cache-control" {print $2}' | tail -1)
  echo "   HEAD $p sin token → $c · Cache-Control: ${cc:-(ninguna)}"
  [ "$c" = 401 ] || desvio "HEAD $p respondió $c (esperado 401)"
  [ "$cc" = "no-store, private" ] || desvio "HEAD $p: Cache-Control «${cc:-ninguna}» (esperado «no-store, private»)"
done
c=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$API/admin/ultron-studio/stream?topics=ledger")
echo "   GET admin/ultron-studio/stream sin vale → $c"; [ "$c" = 401 ] || desvio "el canal SSE sin vale respondió $c (esperado 401)"
c=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$API/admin/stream-ticket?scope=stream")
echo "   GET admin/stream-ticket?scope=stream sin sesión → $c (P9-25)"; [ "$c" = 401 ] || desvio "la emisión del vale sin sesión respondió $c (esperado 401)"
GRANDE=$(python3 -c 'import json; print(json.dumps({"name": "x" * 70000}))')
for p in admin/ultron-studio/strategies admin/ultron-studio/outreach/brakes; do
  R413=$(printf '%s' "$GRANDE" | curl -s -o - -w ' %{http_code}' -X POST -H 'Accept: application/json' -H 'Content-Type: application/json' --data-binary @- "$API/$p" | tail -c 200)
  echo "   POST $p con 70 000 bytes sin token → ${R413##* } $(printf '%s' "$R413" | grep -o '"code":"[a-z_]*"' || true)"
  case "$R413" in *'"code":"payload_too_large"'*' 413') ;; *) desvio "el cuerpo de 70 000 bytes sin sesión a POST $p no dio 413 payload_too_large" ;; esac
done
c=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Accept: application/json' "$API/webhooks/twilio/usage-trigger")
echo "   usage-trigger del OTP sin firma → $c (falla cerrada; T-1, intacto)"; [ "$c" = 403 ] || desvio "usage-trigger del OTP sin firma respondió $c (esperado 403)"
c=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$API/admin/voice/status")
echo "   admin/voice/status sin token → $c"; [ "$c" = 401 ] || desvio "admin/voice/status sin token respondió $c (esperado 401)"
c=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Accept: application/json' -H 'Content-Type: application/json' --data-binary '{}' "$API/admin/voice/contacts")
echo "   POST admin/voice/contacts sin token → $c (B-T4 va detrás del permiso)"; [ "$c" = 401 ] || desvio "POST admin/voice/contacts sin token respondió $c (esperado 401)"
FALLIDAS=$($PSQL -c "SELECT count(*) FROM failed_jobs;")
echo "   colas fallidas: $FALLIDAS (antes $FALLIDAS_ANTES)"; [ "$FALLIDAS" -le "$FALLIDAS_ANTES" ] || desvio "colas fallidas nuevas durante el despliegue"
artisan security:check-secret-exposure > /dev/null 2>&1 && echo "   security:check-secret-exposure (el de la ola: también las claves de la voz y los logs legibles): OK" || desvio "security:check-secret-exposure no pasa (ejecutarlo a mano como www-data: dice solo NOMBRES de claves y ficheros)"
artisan ultron-studio:publication-check > /dev/null 2>&1 && echo "   ultron-studio:publication-check: 0 (cuadra)" || desvio "ultron-studio:publication-check no sale con 0"
if artisan ultron-studio:monitor --check=publication --dry-run > /dev/null 2>&1; then echo "   ultron-studio:monitor --dry-run (publicación): 0"; else desvio "ultron-studio:monitor --check=publication --dry-run no sale con 0"; fi
MONI=$(monitor_integraciones)
echo "   monitor en seco, integraciones: [$MONI] (antes [$MONI0])"
[ "$MONI" = "$MONI0" ] || desvio "el veredicto del monitor del H7 sobre las integraciones cambió con el despliegue: antes [$MONI0] · después [$MONI]"
INCID=$($PSQL -c "SELECT count(*) FROM incidents WHERE source IN ('ultron_studio', 'outreach', 'voice');")
[ "$INCID" = "$INCID_ANTES" ] && echo "   incidentes del Studio, outreach y voz: $INCID (los mismos)" || desvio "incidentes: $INCID_ANTES → $INCID durante el despliegue"
P="^[[:space:]]*(export[[:space:]]+)?($CLAVES_OLA)[[:space:]]*="
if cmp -s <(grep -vE "$P" "$ENVF" | sed '/^$/d') <(grep -vE "$P" "$BK/env.backup" | sed '/^$/d'); then echo "   fichero de entorno igual al respaldo salvo las $N_VARS claves de la ola"; else desvio "el fichero de entorno difiere del respaldo en algo más que las claves de la ola (comparar a mano sin imprimir valores)"; fi
N=0; for kv in $VARS; do if [ "$(estado_var "${kv%%=*}" "${kv#*=}")" = igual ]; then N=$((N + 1)); fi; done
[ "$N" = "$N_VARS" ] || desvio "solo $N de las $N_VARS claves de la ola están una vez y con su valor"
echo "10. integrations-snapshot (la ola no cambia ninguna huella de integración):"
if artisan ultron-studio:integrations-snapshot > /dev/null 2>&1; then
  INST=$($PSQL -c "SELECT count(*) FROM ultron_studio_integration_snapshots;")
  echo "   instantáneas: $INST_ANTES → $INST"
  [ "$INST" = "$INST_ANTES" ] || desvio "integrations-snapshot anotó $((INST - INST_ANTES)) instantánea(s): la huella de alguna integración cambió con la ola (revisar GET integrations/config antes de seguir)"
else
  desvio "ultron-studio:integrations-snapshot no salió con 0 (ejecutarlo a mano como www-data para ver por qué)"
fi
ERRORES=0; BLOQUEOS=0
for f in "$LOGS"/laravel*.log; do
  [ -f "$f" ] || continue
  n=$(printf '%s\n' "$LOG_ANTES" | awk -v f="$f" '$1 == f {print $2}')
  e=$(tail -n +"$(( ${n:-0} + 1 ))" "$f" | grep -c "$NIVEL_ERROR" || true)
  l=$(tail -n +"$(( ${n:-0} + 1 ))" "$f" | grep -c "$NIVEL_ERROR: SQLSTATE\[55P03\]" || true)
  ERRORES=$((ERRORES + e)); BLOQUEOS=$((BLOQUEOS + l))
done
ESPERADOS=$(( INTENTO > 1 ? (BLOQUEOS < INTENTO - 1 ? BLOQUEOS : INTENTO - 1) : 0 ))
ERRORES=$((ERRORES - ESPERADOS))
echo "11. laravel.log ERROR desde el inicio del despliegue: $ERRORES$([ "$ESPERADOS" = 0 ] || echo " (más $ESPERADOS del 55P03 reintentado, esperados)")"; [ "$ERRORES" -le 0 ] || desvio "$ERRORES errores en el log desde el inicio"
[ "$(g -C /var/www/api status --porcelain --untracked-files=no | wc -l | tr -d ' ')" = 0 ] || desvio "el checkout tiene cambios tras el despliegue"
if [ "$DESVIOS" = 0 ]; then
  if [ -n "$SIN_SMOKE" ]; then
    echo "12. smokes NO corridos (--sin-smoke): después, bash /root/deploy-h9-backend-cierre.sh $ESPERADO $BK --solo-smoke"
  else
    correr_smokes
  fi
else
  echo "12. smokes NO corridos: hay $DESVIOS desvíos antes (aclararlos primero)"
fi
echo "   registro de esta ejecución: $LOG"
echo "   VIGILANCIA (15 min, como L9-77): supervisorctl status (los $RUN0 en RUNNING) · 5xx en las últimas 2 000 líneas de $NGINX_ACCESS · failed_jobs · filas de outreach_* y de $TABLA_W9 · incidentes · ERROR nuevos; y que recepción y el punto facial cargan fotos y el canal en vivo (P9-25)"
vuelta_atras
if [ "$DESVIOS" = 0 ]; then echo "DESPLIEGUE H9 CIERRE (BACKEND) COMPLETADO"; else echo "DESPLIEGUE H9 CIERRE (BACKEND) CON $DESVIOS DESVÍOS: aclararlos antes de dar la ola por buena; si un smoke falla, la vuelta atrás de arriba y después el diagnóstico"; exit 1; fi
