#!/bin/bash
# Ola «Enmienda M-1 + plantillas de Meta» en producción, en CÓDIGO y MIGRACIONES con TODO LO DE LA OLA APAGADO
# (informe-devops-ola.md; RUNBOOK_M1.md). Entra: el paquete del motor de la Enmienda M-1 del H5 (informe-bm.md: la
# oferta de prompts en `decide`, el sello en el commit, el lector de `ultron-engine-config/2`, `ultron:prompt-trace`,
# `ultron:prompts-n8n` y la base de los cinco prompts en resources/ultron-prompts), el paquete del Studio de M-1
# (informe-bs.md: publicar en /2 con la llave encendida, la vista previa y `status.engine.prompts`), las plantillas de
# WhatsApp de Meta desde el CRM (informes-plantillas/informe-backend.md: 4 rutas, la llave ultron.meta_templates_submit,
# el bloque propio del programador tras su bandera, `ultron-studio:meta-templates-sync`), las tres migraciones de la
# lista y las enmiendas de guardas (informe-tester-guardas.md). Derivado de deploy-h9-backend-cierre.sh: mismos pasos y
# mismas lecturas, con la puerta de diff de ESTA ola.
# Lo que NO cambia: OutreachSendGate::SENDS_ENABLED_IN_CODE y VoiceCallPolicy::CALLS_ENABLED_IN_CODE siguen en false (un
# commit `outreach(sends-open):` o `voice(calls-open):` ABORTA); ninguna bandera se enciende (las 9 claves nuevas se
# escriben apagadas o con su valor por defecto); lo que ya estaba encendido sigue IGUAL (el laboratorio hablado en 2a, el
# H6 en fase 2, el H9 en sombra: este guion lo lee antes y exige lo mismo después, no «todo apagado» como el cierre);
# ningún worker nuevo; no se ejecuta composer; nginx, DNS, TLS, el crontab, supervisor y n8n no se tocan.
# ORDEN OBLIGATORIO (RUNBOOK_M1.md §2): 1) el CRM de la ola (tolera la AUSENCIA de los campos nuevos; un CRM viejo puede
# no tolerar su PRESENCIA); 2) este guion. El paso 0 lo comprueba: el version.json del CRM tiene que ser el de --crm y
# distinto del CRM de antes de la ola.
# Se ejecuta EN EL SERVIDOR como root, tras el respaldo, que va JUSTO ANTES (< 2 h; el paso 0 exige que el fichero de
# entorno sea idéntico a env.backup) y DESPUÉS de publicar el CRM:
#   TS=$(date -u +%Y%m%d-%H%M%S); BK=/var/backups/ironbody/ultron-studio-m1-$TS
#   ( umask 077; mkdir -p "$BK"
#     git -C /var/www/api rev-parse HEAD > "$BK/api-commit.txt"                      # empieza por PREVIO (728f208)
#     sudo -u postgres pg_dump -Fc ironbody > "$BK/ironbody.dump"
#     sha256sum "$BK/ironbody.dump" > "$BK/ironbody.dump.sha256"
#     install -m 600 -o root -g root /var/www/api/backend/.env "$BK/env.backup"
#     cp -a /var/www/api/backend/bootstrap/cache "$BK/bootstrap-cache"
#     cp -a /etc/supervisor/conf.d "$BK/supervisor-conf.d" )
#   pg_restore -l "$BK/ironbody.dump" | grep -c 'TABLE DATA'                      # tantas como tablas tiene hoy la base (254)
#   stat -c '%a %U %n' "$BK" "$BK"/*; du -h "$BK/ironbody.dump"; echo "BK=$BK"    # 700 el directorio; 600 y root los ficheros
#   0. comprobaciones de solo lectura (abortan sin tocar nada; con --solo-comprobar se termina aquí): el respaldo, los
#      commits (sin `outreach(sends-open):` ni `voice(calls-open):`), la puerta de diff CERRADA de esta ola, las
#      migraciones (solo las tres de la lista), las dos constantes cerradas en origin/main, el esquema de partida (el del
#      cierre del H9), el estado de partida (H9 en sombra o apagado; la voz en 2a o apagada: si no, no hay smoke que lo
#      cubra y aborta), el CRM ya publicado, workers (13 o 14 según exista ironbody-voice), programador y rutas (121)
#   1. código (git como root con umask 022, merge --ff-only) y lectura por www-data de cada fichero del merge
#   2. las 9 claves nuevas de la ola (apagadas o con su valor por defecto) ANTES de migrar, y el fichero de entorno parsea
#   3. ironbody-migrate (solo las tres migraciones de la ola; un 55P03 se reintenta hasta 3 veces); si el esquema no da
#      el esperado (ultron_studio_* 137/153/11/27, outreach_* 51/75/3/8 y voice_* igual), ABORTA sin cachés
#   4. aserciones del esquema (37 funciones sin SECURITY DEFINER, ninguna guarda apagada, F intacta, dueños, el CHECK
#      uspub_engine_contract_chk con /1 y /2, la tabla de comprobaciones de W9 con 18 columnas, 5 disparadores y 6 CHECK,
#      la tabla de envíos con 9 y 10 y vacía, privilegios de iron_app: la nueva raw--s, W9 ra---s y las demás iguales; las
#      tablas del Studio con las mismas filas y ninguna entrada /2)
#   5. config:cache y route:cache como www-data con umask 077, cachés www-data 600 y queue:restart
#   6. rutas (125 del Studio, 34 de strategies/**, 4 de las plantillas de Meta) y auth:coverage --unmapped vacío
#   7. configuración efectiva: lo de la ola apagado (la llave de prompts en off, las banderas de plantillas en false, los
#      hosts de URL), `ultron:prompt-trace` = «off legacy prompts_off»; el motor, Legacy, H4–H8 y el H9 IGUALES que antes;
#      las dos constantes cerradas; el programador con el mismo total y sin la sincronización de Meta
#   8. workers como antes, dos lecturas · 9. sanidad HTTP (401 y no-store también en las 4 rutas nuevas, 413 temprano,
#      OTP 403, voz 401, el vale sin sesión 401, decide y commit internos sin firma 401), colas, ningún trabajo de outreach
#      en vivo, secretos, publication-check, el monitor del H7 en seco igual, el entorno = respaldo salvo las claves de la ola
#  10. integrations-snapshot sin cambios · 11. ERROR nuevos en el log
#  12. los smokes del H9 (en la fase que se dedujo: 2 = sombra) y del H8 (2a), DEL COMMIT DESPLEGADO, con lo de esta ola
#      (SMOKE_EXPECT_M1=1: las 4 rutas de plantillas cerradas y sin red, `status.engine.prompts` con la llave en off)
# Cada aserción que falla se dice y el script termina con 1; «DESPLIEGUE M-1 (BACKEND) COMPLETADO» solo sale con 0
# desvíos. Nunca imprime valores del fichero de entorno, ni secretos, ni la salida de un tinker que no case con lo
# esperado. Todo lo que imprime queda además en /root/m1-<fecha UTC>.log (600, root).
# Uso: bash /root/deploy-m1-backend.sh <commit esperado (7)> <directorio del respaldo> --crm=<commit del CRM ya publicado (7)>
#           [--solo-comprobar] [--acepta-main=<commit>[,<commit>…]] [--sin-smoke]
#      bash /root/deploy-m1-backend.sh <commit esperado (7)> <directorio del respaldo> --solo-smoke
#   --solo-comprobar: solo el paso 0 (lecturas y git fetch); es el ensayo en el servidor.
#   --acepta-main=…: los commits AJENOS a esta ola (de origin/main) que producción no tiene y que viajarían con el merge, que
#     el propietario acepta desplegar EN ESE MOMENTO; sin la bandera, el paso 0 los lista y el despliegue aborta.
#   --sin-smoke: no corre el paso 12 (imprime las órdenes para correrlo a mano después).
#   --solo-smoke: con producción ya en el commit esperado, solo el paso 12. Dejar ≥ 2 minutos desde otra pasada de un smoke:
#     comparten la cuenta 32 y el cupo de inicios de sesión por IP.
# VUELTA ATRÁS (nivel 3 del código; la imprime también al final con el respaldo de hoy):
#   0. si la llave de prompts ya se movió (E2): `bash /root/motores-prompts-scope.sh off canary`; si alguien publicó en /2
#      (E3+), antes «volver a Legacy» en el Studio, que escribe /1 (R10).
#   1. backend: solo si producción sigue en el commit de la ola: `git checkout -q --detach $(cat $BK/api-commit.txt)` como
#      root con umask 022 (= PREVIO), config:cache y route:cache como www-data, queue:restart. Las claves nuevas pueden
#      quedarse (el código anterior no las lee) o restaurarse con $BK/env.backup (mismo dueño y modo) y config:cache.
#   esquema: la tabla de envíos (ultron_studio_meta_template_submissions) QUEDA, vacía y sin lector; las tres columnas
#   nuevas de ultron_studio_template_meta_checks QUEDAN (el código anterior escribe filas `manual` que las cumplen); el
#   CHECK ampliado de la cadena QUEDA (admite /1 y /2; el código anterior solo escribe /1). NUNCA `migrate:rollback` en
#   producción: quitarlos es de database-specialist con autorización (sus down() se niegan con filas). Volver el código no
#   es volver los datos.
#   n8n: la copia S1 puede seguir publicada con el código anterior (sin oferta en `decide` no manda el bloque `prompts`;
#   ver RUNBOOK_M1.md §5); su vuelta, si se quiere, es la de n8n-copia-s1/LEEME.md.
#   CRM: no hace falta volverlo (el CRM de la ola tolera la ausencia de los campos nuevos).
set -euo pipefail
export LC_ALL=C
umask 022
g() { git "$@"; }
uso() { echo "uso: bash /root/deploy-m1-backend.sh <commit esperado (7)> <respaldo> --crm=<commit del CRM (7)> [--solo-comprobar] [--acepta-main=<c>[,<c>…]] [--sin-smoke] | <commit> <respaldo> --solo-smoke"; }
[ "$#" -ge 2 ] && [ "$#" -le 6 ] || { uso; echo "no se ha tocado nada"; exit 2; }
ESPERADO="$1"
BK="$2"
shift 2
SOLO_COMPROBAR=""; SOLO_SMOKE=""; ACEPTA=""; CRM=""; SIN_SMOKE=""
for a in "$@"; do
  case "$a" in
    --solo-comprobar) [ -z "$SOLO_COMPROBAR" ] || { echo "--solo-comprobar repetido; no se ha tocado nada"; exit 2; }; SOLO_COMPROBAR=--solo-comprobar ;;
    --solo-smoke) SOLO_SMOKE=--solo-smoke ;;
    --sin-smoke) SIN_SMOKE=1 ;;
    --crm=*) [ -z "$CRM" ] || { echo "--crm repetido; no se ha tocado nada"; exit 2; }; CRM=${a#--crm=}
             [[ "$CRM" =~ ^[0-9a-f]{7}$ ]] || { echo "--crm va con el commit de 7 cifras que sirve hoy https://ironbodyneiva.cloud/version.json; no se ha tocado nada"; exit 2; } ;;
    --acepta-main=*) [ -z "$ACEPTA" ] || { echo "--acepta-main repetido; no se ha tocado nada"; exit 2; }
                     ACEPTA=${a#--acepta-main=}
                     [[ "$ACEPTA" =~ ^[0-9a-f]{7}(,[0-9a-f]{7})*$ ]] || { echo "--acepta-main va con commits de 7 cifras separados por comas (los que imprime el paso 0); no se ha tocado nada"; exit 2; } ;;
    *) echo "argumento desconocido: $a; no se ha tocado nada"; uso; exit 2 ;;
  esac
done
[ -z "$SOLO_SMOKE" ] || [ -z "$SOLO_COMPROBAR$ACEPTA$CRM$SIN_SMOKE" ] || { echo "--solo-smoke va solo; no se ha tocado nada"; exit 2; }
[ -n "$SOLO_SMOKE" ] || [ -n "$CRM" ] || { echo "falta --crm=<commit del CRM ya publicado>: esta ola va DESPUÉS del CRM; no se ha tocado nada"; exit 2; }
[[ "$ESPERADO" =~ ^[0-9a-f]{7}$ ]] || { echo "el commit esperado va con 7 cifras hexadecimales (git rev-parse --short=7 origin/main); no se ha tocado nada"; exit 2; }
[ "$(id -u)" = 0 ] || { echo "se ejecuta como root en el servidor; no se ha tocado nada"; exit 2; }

# ── Lo propio de producción ────────────────────────────────────────────────────────────────────────────────────────
API=https://api.ironbodyneiva.cloud/api
CRM_WEB=https://ironbodyneiva.cloud
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
N8N_PG=n8n-postgres-1                                # solo para LEER qué flujo atiende el webhook de ULTRON (informativo)

# ── Lo propio de ESTA ola (se revisa con el árbol final antes de cada ensayo: informe-devops-ola.md §2) ────────────────
# PREVIO = lo que sirve producción: 728f208 (E18-7 del H8; 9d6b325 solo añade docs, pruebas y ops). CRM_PREVIO = el CRM de hoy.
PREVIO=728f208
CRM_PREVIO=a539690
# Las migraciones de la ola, CERRADAS: ninguna otra puede entrar ni tocarse (un fichero de migración ya desplegado que
# cambie también aborta). Orden: las dos de plantillas (informe-db.md §3.3: 100002 cita a 100001) y la de M-1.
MIG_OLA="2026_11_15_100001_create_ultron_studio_meta_template_submissions_table 2026_11_15_100002_add_meta_api_source_to_ultron_studio_template_meta_checks
  2026_11_16_100001_allow_engine_config_v2_in_ultron_studio_publications"
MIG_OLA=$(echo $MIG_OLA)
# El EFECTO de cada una, en el mismo orden (Q_EFECTOS): existe la tabla de envíos · la tabla de W9 tiene `source`, su
# CHECK ustmc_source_chk y el disparador ustmc_submission_of_template · uspub_engine_contract_chk admite /2.
# Antes «f f f», después «t t t». Si MIG_OLA cambia, Q_EFECTOS y EFECTOS_DESPUES cambian con ella.
EFECTOS_ANTES="f f f"
EFECTOS_DESPUES="t t t"
# Las zonas propias del Studio (libres dentro de la ola) y lo que no corre en producción (pruebas y docs). A diferencia
# del cierre del H9, la ejecución (Outreach) NO es zona libre: esta ola solo toca un fichero suyo (en la lista).
ZONAS_LIBRES="backend/app/Services/UltronStudio/ backend/app/Models/UltronStudio/ backend/app/Http/Controllers/Crm/UltronStudio/
  backend/app/Console/Commands/UltronStudio/ backend/config/ultron_studio.php backend/routes/ultron-studio.php backend/tests/ backend/docs/"
# Lo que viaja en el repositorio y NADIE ejecuta en el servidor: los artefactos de n8n (S1 generado y el respaldo del flujo
# vivo que leen el generador y E10). n8n se cambia a mano en la etapa E1, nunca desde aquí. Revisado: sin pinData y con
# las credenciales solo como referencia (informe-devops-ola.md §2.3).
ZONAS_INERTES="n8n/flujos/ultron-studio-s1/ n8n/respaldos/20261111-ultron-29de5119/"
# La LISTA CERRADA y enumerada de lo que esta ola puede cambiar FUERA del Studio (ruta exacta y dueño). Cualquier otro
# fichero que cambie entre PREVIO y origin/main ABORTA (Legacy fuera de estos, la ejecución salvo el puente de W9, el
# webhook y el outbox de WhatsApp, rutas de la API, dependencias, las constantes…): si es de otro equipo, se despliega
# antes por su vía. Las marcadas con «*» SOLO AÑADEN CÓDIGO: las líneas que quitan solo pueden ser comentarios y ninguna
# con `Schedule::`.
LISTA_CERRADA='backend/.env.example                                                   ejemplo, no corre: MARKETING_ULTRON_STUDIO_PROMPTS y ULTRON_STUDIO_META_TEMPLATES_* (tester-guardas, be-tpl)
backend/config/marketing.php                                         * M-1: marketing.ultron.studio_prompts (off | canary) (bm-motor)
backend/app/Services/Marketing/Ultron/UltronPublishedConfig.php        M-1: el lector acepta /1 y /2, partFor() (bm-motor)
backend/app/Services/Marketing/Ultron/UltronDecideService.php          M-1: la oferta de prompts si n8n negocia (bm-motor)
backend/app/Services/Marketing/Ultron/UltronCommitService.php          M-1: el sello metadata.studio_prompts (bm-motor)
backend/app/Http/Controllers/Api/Internal/UltronController.php         M-1: prompt_contract en decide y el bloque prompts del commit (bm-motor)
backend/app/Services/Marketing/Ultron/Prompts/UltronPromptBase.php     M-1: la base y sus huellas (bm-motor, nuevo)
backend/app/Services/Marketing/Ultron/Prompts/UltronPromptCompiler.php M-1: el compilador (bm-motor, nuevo)
backend/app/Services/Marketing/Ultron/Prompts/UltronPromptException.php M-1 (bm-motor, nuevo)
backend/app/Services/Marketing/Ultron/Prompts/UltronPromptOffer.php    M-1: la oferta de decide (bm-motor, nuevo)
backend/app/Services/Marketing/Ultron/Prompts/UltronPromptStamp.php    M-1: el sello (bm-motor, nuevo)
backend/app/Services/Marketing/Ultron/Prompts/UltronPromptsN8nFlow.php M-1: el generador de S1 (bm-motor, nuevo)
backend/app/Console/Commands/Marketing/UltronPromptTrace.php           M-1: ultron:prompt-trace, solo lectura (bm-motor, nuevo)
backend/app/Console/Commands/Marketing/UltronPromptsN8n.php            M-1: ultron:prompts-n8n, NO se ejecuta en producción (bm-motor, nuevo)
backend/resources/ultron-prompts/.editorconfig                         M-1: la base no se «arregla» (bm-motor, nuevo)
backend/resources/ultron-prompts/.gitattributes                        M-1: *.txt -text (bm-motor, nuevo)
backend/resources/ultron-prompts/n8n-29de5119/strategist.txt           M-1: base, sha256 fijado (bm-motor, nuevo)
backend/resources/ultron-prompts/n8n-29de5119/composer.txt             M-1: base, sha256 fijado (bm-motor, nuevo)
backend/resources/ultron-prompts/n8n-29de5119/critic.txt               M-1: base, sha256 fijado (bm-motor, nuevo)
backend/resources/ultron-prompts/n8n-29de5119/composer_retry.txt       M-1: base, sha256 fijado (bm-motor, nuevo)
backend/resources/ultron-prompts/n8n-29de5119/critic_retry.txt         M-1: base, sha256 fijado (bm-motor, nuevo)
backend/app/Services/Meta/Templates/WhatsappTemplateClient.php         plantillas: el cliente neutro (be-tpl, nuevo)
backend/app/Services/Meta/Templates/WhatsappTemplateClientFactory.php  plantillas: en producción siempre graph (be-tpl, nuevo)
backend/app/Services/Meta/Templates/WhatsappTemplateProbe.php          plantillas: la sonda (be-tpl, nuevo)
backend/app/Services/Meta/Templates/GraphWhatsappTemplateClient.php    plantillas: Graph v25.0 (be-tpl, nuevo)
backend/app/Services/Meta/Templates/FakeWhatsappTemplateClient.php     plantillas: el doble, solo local y testing (be-tpl, nuevo)
backend/app/Services/Meta/Templates/MetaTemplate.php                   plantillas (be-tpl, nuevo)
backend/app/Services/Meta/Templates/TemplateApiErrors.php              plantillas (be-tpl, nuevo)
backend/app/Services/Meta/Templates/TemplateApiResult.php              plantillas (be-tpl, nuevo)
backend/app/Services/Meta/Templates/TemplateRequestGuard.php           plantillas (be-tpl, nuevo)
backend/app/Services/Outreach/Run/OutreachPublishedStrategies.php      W9: la huella de componentes en metaApproved() (be-tpl, enmienda de W9)
backend/app/Support/Access/AuthorizationMap.php                      * plantillas: las 4 entradas (be-tpl)
backend/app/Support/Access/CrmPermission.php                         * plantillas: la llave ultron.meta_templates_submit (be-tpl)
backend/app/Support/Access/PermissionCatalog.php                       plantillas: etiqueta y ayuda; M-1: la frase de publicar y revertir (be-tpl, bs-studio)
backend/app/Providers/AppServiceProvider.php                         * plantillas: el limitador ultron-studio-meta (be-tpl)
backend/app/Exceptions/UltronStudioException.php                     * M-1: 409 prompts_compile_failed (bs-studio)
backend/routes/console.php                                           * plantillas: el bloque propio de ultron-studio:meta-templates-sync, tras su bandera'
SOLO_CODIGO_ANADIDO="backend/routes/console.php backend/config/marketing.php backend/app/Support/Access/AuthorizationMap.php
  backend/app/Support/Access/CrmPermission.php backend/app/Providers/AppServiceProvider.php backend/app/Exceptions/UltronStudioException.php"
# Prefijos de los commits de la ola (la puerta de verdad es la de ficheros; esto es trazabilidad).
PREFIJO_OLA='^[a-z]+(/[a-z]+)*\((ultron-studio|ultron-studio-h[5-9]|ultron-studio-m1|ultron-studio-motores|ultron-studio-plantillas)\):'
# Las claves nuevas de la ola, explícitas aunque coincidan con el defecto (protección 1: todo nace apagado), con el
# valor por defecto de backend/.env.example (:298 y :426–:447) salvo URL_HOSTS, que lleva el valor decidido (Q1 de
# L9-96). Las mueven después los mandos (motores/motores-prompts-scope.sh para la llave de prompts) o una orden escrita.
VARS="MARKETING_ULTRON_STUDIO_PROMPTS=off ULTRON_STUDIO_META_TEMPLATES_ENABLED=false ULTRON_STUDIO_META_TEMPLATES_SUBMIT_ENABLED=false
  ULTRON_STUDIO_META_TEMPLATES_SYNC_ENABLED=false ULTRON_STUDIO_META_TEMPLATES_DRIVER=fake ULTRON_STUDIO_META_TEMPLATES_GRAPH_VERSION=v25.0
  ULTRON_STUDIO_META_TEMPLATES_GRAPH_BASE= ULTRON_STUDIO_META_TEMPLATES_URL_HOSTS=ironbodyneiva.cloud ULTRON_STUDIO_META_TEMPLATES_PHONE_BUTTON=false"
N_VARS=$(echo $VARS | wc -w | tr -d ' ')
CLAVES_OLA=$(for kv in $VARS; do printf '%s|' "${kv%%=*}"; done); CLAVES_OLA=${CLAVES_OLA%|}
# Tareas del programador que la ola añade SIN bandera: 0 (la sincronización de Meta cuelga de
# ULTRON_STUDIO_META_TEMPLATES_SYNC_ENABLED; informe-backend.md §6: 26 → 27 solo con la bandera encendida).
TAREAS_NUEVAS_SIEMPRE=0
HUELLA_F=4ad748b5f17f09b398f7f641c197d7ee        # md5(pg_get_functiondef) de ultron_studio_forbid(): la de F (H5–H9; informe-db.md §6)
ESQ_ANTES="127 141 9 26 51 75 3 8"                  # el cierre del H9 en producción (L9-94); sin migraciones desde entonces
ESQ_DESPUES="137 153 11 27 51 75 3 8"               # + 10 disparadores, 12 CHECK, 2 parciales y 1 tabla (informe-db.md §5 y §6; M-1 no suma: informe-db-motores.md §4)
FUNC_ANTES="34 0 1 $DUENO_BD"
FUNC_DESPUES="37 0 1 $DUENO_BD"                   # + ultron_studio_chk_usmsub_{template_approved_whatsapp,submitter_is_bound} y ultron_studio_chk_ustmc_submission_of_template (informe-db.md §2.1 y §2.2)
TABLA_NUEVA=ultron_studio_meta_template_submissions
TABLA_W9=ultron_studio_template_meta_checks
PRIV_NUEVA_ESPERADO="$TABLA_NUEVA=raw--s"           # SELECT, INSERT, UPDATE y la secuencia; REVOKE DELETE (informe-db.md §2.1)
PRIV_W9_ESPERADO="$TABLA_W9=ra---s"                 # solo inserción, como en W9; la ampliación lo vuelve a comprobar (§2.2)
W9_ANTES="15 4 4"                                   # columnas · disparadores · CHECK de la tabla de W9 (informe-db.md §3.2)
W9_DESPUES="18 5 6"                                 # informe-db.md §6
ENVIOS_DESPUES="9 10 2"                             # disparadores · CHECK · parciales de la tabla de envíos (informe-db.md §6)
CONTRATO_ANTES="1 t t f"                            # uspub_engine_contract_chk: cuántos · validado · admite /1 · admite /2
CONTRATO_DESPUES="1 t t t"                          # informe-db-motores.md §3
RUTAS_ANTES="121 30 13 9 4 1 0"                     # Studio · strategies/** · ejecución · sus GET · sus POST · SSE · plantillas de Meta
RUTAS_DESPUES="125 34 13 9 4 1 4"                   # informe-backend.md §1 y §2 (StudioRouteCount: 121 → 125; las 4 cuelgan de strategies/templates/**)
LEGACY_BUNDLE=3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902   # informe-bm.md §3
FLUJO_VIVO=YspRwsXnqt33NouP; FLUJO_S1=QSklqHlsDIxucRfk                              # n8n-copia-s1/LEEME.md

TS=$(date -u +%Y%m%d-%H%M%S)
if [ -n "$SOLO_SMOKE" ]; then LOG="$LOGDIR/m1-smoke-$TS.log"; elif [ -n "$SOLO_COMPROBAR" ]; then LOG="$LOGDIR/m1-comprobar-$TS.log"; else LOG="$LOGDIR/m1-$TS.log"; fi
( umask 077; : > "$LOG" ) || { echo "no se pudo crear el registro $LOG; no se ha tocado nada"; exit 2; }
exec > >(tee -a "$LOG") 2>&1
echo "== deploy-m1-backend.sh · $(date -u +%Y-%m-%dT%H:%M:%SZ) · ESPERADO=$ESPERADO · PREVIO=$PREVIO · CRM=${CRM:-—} · BK=$BK${SOLO_COMPROBAR:+ · $SOLO_COMPROBAR}${SOLO_SMOKE:+ · $SOLO_SMOKE} · registro $LOG"
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
# 13 programas de producción y ironbody-voice si el H8 lo instaló (14 desde el 11-11-2026; L9-102), como los mandos.
workers_esperados() { local n; n=$(supervisorctl status 2>/dev/null | grep -c '^ironbody-voice:' || true); [ "${n:-0}" -ge 1 ] && echo 14 || echo 13; }
# Las rutas en la caché: Studio total · strategies/** · ejecución · sus GET · sus POST · el canal SSE · plantillas de Meta.
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
mt = [x for x in s if u(x).startswith("/api/admin/ultron-studio/strategies/templates/") and "/meta/" in u(x)]
print(len(s), len(e), len(c), sum(1 for x in c if "GET" in m(x)), sum(1 for x in c if m(x) == "POST"), len(t), len(mt))' 2>/dev/null || echo '?'
}
# El programador por `schedule:list --json`, con su código de salida APARTE (m-1 del H7):
# «total del_Studio de_voz de_outreach de_la_sincronización_de_Meta».
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
meta = [x for x in c if "ultron-studio:meta-templates-sync" in x]
print(len(r), sum(1 for x in c if "ultron-studio:" in x and x not in voz), len(voz), len(out), len(meta))' 2>/dev/null || echo ILEGIBLE
}
# Motor comercial, ULTRON Legacy, el H4–H7 y la voz del H8 (solo CONFIGURACIÓN y la constante del H8). Antes y después
# tienen que ser iguales (la ola no cambia ningún valor). Con voice_lab_driver: el laboratorio hablado sigue en elevenlabs.
leer_previa() {
  local l
  l=$(artisan tinker --execute='$p = "ultron_studio.integrations."; echo json_encode(["engine_scope" => config("marketing.ultron.studio_config"), "ultron_enabled" => config("marketing.ultron.enabled"), "canary_set" => config("marketing.ultron.canary_conversation_id") !== null, "followups_scheduler" => config("marketing.followups.scheduler_enabled"), "commercial_nba" => config("commercial.enabled"), "commercial_events" => config("commercial.events_enabled"), "commercial_autonomy" => config("commercial.autonomy_enabled"), "publishing_enabled" => config("ultron_studio.publishing_enabled"), "lab_gate" => config("ultron_studio.publishing.lab_gate"), "lab_enabled" => config("ultron_studio.lab.enabled"), "writes_enabled" => config($p."writes_enabled"), "probe_enabled" => config($p."twilio_probe.enabled"), "monitor_enabled" => config("ultron_studio.monitor.enabled"), "monitor_expect" => config("ultron_studio.monitor.expect"), "voice_publishing" => config("ultron_studio.voice.publishing_enabled"), "voice_inbound" => config("voice.inbound.enabled"), "voice_outbound" => config("voice.outbound.enabled"), "voice_monitor" => config("voice.monitor.enabled"), "voice_lab" => config("voice.lab.enabled"), "voice_lab_driver" => config("voice.lab.driver"), "twilio_voice" => config("voice.twilio.enabled"), "elevenlabs_enabled" => config("voice.elevenlabs.enabled"), "calls_in_code" => App\Services\Voice\VoiceCallPolicy::CALLS_ENABLED_IN_CODE, "studio_voice" => App\Services\UltronStudio\StudioIntegrations::voiceState()]), PHP_EOL;' 2>&1 | tail -1) || true
  case "$l" in '{"engine_scope"'*) printf '%s\n' "$l" ;; esac
}
# La configuración efectiva del H9 (la del mando, h9-outreach-scope.sh) y las dos constantes en ejecución. Antes y
# después tienen que ser IGUALES (la ola no toca el H9: en producción está en sombra desde L9-101).
leer_h9() {
  local l
  l=$(artisan tinker --execute='$o = "outreach."; $s = "ultron_studio.strategies."; $dec = fn ($k) => config($k) === null ? null : (string) config($k); $g = null; foreach (array_merge(glob(app_path("Services/Outreach/OutreachSendGate.php")) ?: [], glob(app_path("Services/Outreach/*/OutreachSendGate.php")) ?: []) as $f) { $c = "App\\" . str_replace("/", "\\", substr($f, strlen(app_path()) + 1, -4)); if (class_exists($c) && defined($c . "::SENDS_ENABLED_IN_CODE")) { $g = $c; break; } } echo json_encode(["deployed" => is_array(config("outreach")), "enabled" => config($o."enabled"), "mode" => config($o."mode"), "scheduler" => config($o."scheduler.enabled"), "monitor" => config($o."monitor.enabled"), "assisted" => config($o."assisted.enabled"), "assisted_cap" => config($o."assisted.daily_cap"), "whatsapp" => config($o."whatsapp.enabled"), "whatsapp_cap" => config($o."whatsapp.daily_cap"), "whatsapp_budget" => $dec($o."whatsapp.daily_budget_usd"), "price_marketing" => $dec($o."whatsapp.price_marketing_usd"), "price_utility" => $dec($o."whatsapp.price_utility_usd"), "voice" => config($o."voice.enabled"), "voice_cap" => config($o."voice.daily_cap"), "voice_budget" => $dec($o."voice.daily_budget_usd"), "voice_reserve" => $dec($o."voice.reserve_usd_per_call"), "classifier" => config($o."classifier.enabled"), "sends_in_code" => $g === null ? null : constant($g . "::SENDS_ENABLED_IN_CODE"), "sends_gate" => $g, "calls_in_code" => class_exists("App\\Services\\Voice\\VoiceCallPolicy") ? App\Services\Voice\VoiceCallPolicy::CALLS_ENABLED_IN_CODE : null, "voice_contacts" => config("voice.contacts.enabled"), "strategies_deployed" => is_array(config("ultron_studio.strategies")), "strategies" => config($s."enabled"), "strategies_publishing" => config($s."publishing_enabled"), "simulation_gate" => config($s."simulation_gate"), "stream_deployed" => is_array(config("ultron_studio_stream")), "stream" => config("ultron_studio_stream.enabled")]), PHP_EOL;' 2>&1 | tail -1) || true
  case "$l" in '{"deployed"'*) printf '%s\n' "$l" ;; esac
}
# Lo NUEVO de la ola (antes, con el código anterior, sale null) y el total de rutas del código desplegado.
leer_ola() {
  local l
  l=$(artisan tinker --execute='$m = "ultron_studio.meta_templates."; echo json_encode(["prompts_key" => config("marketing.ultron.studio_prompts"), "meta_deployed" => is_array(config("ultron_studio.meta_templates")), "meta_enabled" => config($m."enabled"), "meta_submit" => config($m."submit_enabled"), "meta_sync" => config($m."sync_enabled"), "meta_driver_config" => config($m."driver"), "meta_graph_version" => config($m."graph_version"), "meta_url_hosts" => config($m."url_hosts"), "meta_phone_button" => config($m."phone_button"), "production" => app()->environment("production"), "studio_routes_total" => defined("App\\Http\\Controllers\\Crm\\UltronStudio\\StudioRouteCount::TOTAL") ? App\Http\Controllers\Crm\UltronStudio\StudioRouteCount::TOTAL : null]), PHP_EOL;' 2>&1 | tail -1) || true
  case "$l" in '{"prompts_key"'*) printf '%s\n' "$l" ;; esac
}
# ¿Lo de la ola apagado? → «OK» o «MAL campo=valor …». El driver se informa: en producción es SIEMPRE graph, diga lo
# que diga la configuración (informe-backend.md §4.1), y con las banderas apagadas no sale nada.
ola_apagada() { # JSON
  printf '%s' "$1" | python3 -I -c '
import json, sys
d = json.load(sys.stdin)
esp = {"prompts_key": "off", "meta_deployed": True, "meta_enabled": False, "meta_submit": False, "meta_sync": False,
       "meta_graph_version": "v25.0", "meta_url_hosts": ["ironbodyneiva.cloud"], "meta_phone_button": False, "studio_routes_total": 125}
mal = ["%s=%s" % (k, json.dumps(d.get(k))) for k, v in esp.items() if d.get(k) != v]
print("OK" if not mal else "MAL " + " ".join(mal))' 2>/dev/null || echo "MAL ilegible"
}
# La fase del H9 que cubre el smoke del H9: 2 (sombra: la de producción desde L9-101), 1 (todo apagado, como el cierre)
# o «?» (cualquier otra cosa: asistido, un canal, la constante abierta… esta ola no se despliega encima).
fase_h9() { # JSON de leer_h9
  printf '%s' "$1" | python3 -I -c '
import json, sys
d = json.load(sys.stdin)
cerrado = d.get("sends_in_code") is False and d.get("calls_in_code") is False and d.get("assisted") is False and d.get("whatsapp") is False and d.get("voice") is False
sombra = d.get("deployed") is True and d.get("enabled") is True and d.get("mode") == "shadow" and d.get("scheduler") is True and d.get("monitor") is True
apagado = d.get("deployed") is True and d.get("enabled") is False and d.get("mode") == "off" and d.get("scheduler") is False and d.get("monitor") is False and d.get("strategies") is False and d.get("strategies_publishing") is False and d.get("stream") is False
print("2" if cerrado and sombra else ("1" if cerrado and apagado else "?"))' 2>/dev/null || echo "?"
}
# La fase de la voz que cubre el smoke del H8: 2a (el laboratorio hablado, sin llamadas: la de producción desde L9-100),
# 1 (todo apagado) o «?».
fase_h8() { # JSON de leer_previa
  printf '%s' "$1" | python3 -I -c '
import json, sys
d = json.load(sys.stdin)
sin_llamadas = d.get("calls_in_code") is False and d.get("voice_inbound") is False and d.get("voice_outbound") is False and d.get("voice_publishing") is False and d.get("twilio_voice") is False
if sin_llamadas and d.get("voice_lab") is True:
    print("2a")
elif sin_llamadas and d.get("voice_lab") is False and d.get("voice_monitor") is False and d.get("monitor_enabled") is False:
    print("1")
else:
    print("?")' 2>/dev/null || echo "?"
}
# La traza de M-1 (solo lectura): «llave modo motivo» de lo que decide ofrecería AHORA a la canaria. Sin ids ni textos.
traza() {
  local salida rc=0
  salida=$(artisan ultron:prompt-trace --json --last=1 2>/dev/null) || rc=$?
  [ "$rc" = 0 ] || { echo "FALLO $rc"; return 0; }
  printf '%s' "$salida" | python3 -I -c '
import json, sys
try:
    t = json.load(sys.stdin)
    c = t.get("current") or {}
    r = c.get("reason")
    print(t.get("key"), c.get("mode"), r if r is not None else "versión-" + str(c.get("version"))[:8])
except Exception:
    print("ILEGIBLE")' 2>/dev/null || echo ILEGIBLE
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
# Qué flujo de n8n atiende hoy el webhook de ULTRON (solo lectura en la base de n8n; informativo: E1 es a mano).
n8n_flujo() {
  local w
  command -v docker > /dev/null 2>&1 || { echo "?"; return 0; }
  w=$(timeout 10 docker exec "$N8N_PG" psql -U n8n -d n8n -X -Atq -c "select \"workflowId\" from webhook_entity where \"webhookPath\" = 'iron-body-ultron' and method = 'POST'" 2>/dev/null | head -1) || w=""
  case "$w" in
    "$FLUJO_VIVO") echo "el flujo vivo 29de5119 ($FLUJO_VIVO): E1 sin hacer" ;;
    "$FLUJO_S1") echo "la copia S1 ($FLUJO_S1): E1 ya hecha" ;;
    "") echo "?" ;;
    *) echo "OTRO flujo ($w): revisar n8n antes de las etapas" ;;
  esac
}
Q_ESQUEMA="SELECT (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron\_studio\_%')||' '||(SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname LIKE 'ultron\_studio\_%')||' '||(SELECT count(*) FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid WHERE t.relname LIKE 'ultron\_studio\_%' AND i.indpred IS NOT NULL)||' '||(SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'ultron\_studio\_%')||' '||(SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'outreach\_%')||' '||(SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname LIKE 'outreach\_%')||' '||(SELECT count(*) FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid WHERE t.relname LIKE 'outreach\_%' AND i.indpred IS NOT NULL)||' '||(SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'outreach\_%');"
# La voz: disparadores · CHECK · tablas de voice_% · cuántos vc_requester_chk · cuántos vbr_reason_chk. La ola no la toca.
Q_VOZ="SELECT (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'voice\_%')||' '||(SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname LIKE 'voice\_%')||' '||(SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'voice\_%')||' '||(SELECT count(*) FROM pg_constraint WHERE conname = 'vc_requester_chk')||' '||(SELECT count(*) FROM pg_constraint WHERE conname = 'vbr_reason_chk');"
Q_EFECTOS="SELECT CASE WHEN to_regclass('public.$TABLA_NUEVA') IS NOT NULL THEN 't' ELSE 'f' END||' '||CASE WHEN EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = '$TABLA_W9' AND column_name = 'source') AND EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ustmc_source_chk') AND EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'ustmc_submission_of_template' AND NOT tgisinternal) THEN 't' ELSE 'f' END||' '||CASE WHEN coalesce((SELECT bool_or(pg_get_constraintdef(oid) LIKE '%''ultron-engine-config/2''%') FROM pg_constraint WHERE conname = 'uspub_engine_contract_chk' AND contype = 'c'), false) THEN 't' ELSE 'f' END;"
# El CHECK del contrato de la cadena (informe-db-motores.md §3 y motores-schema-check.sql): cuántos · validado · /1 · /2.
Q_CONTRATO="SELECT count(*)||' '||CASE WHEN bool_and(convalidated) THEN 't' ELSE 'f' END||' '||CASE WHEN bool_and(pg_get_constraintdef(oid) LIKE '%''ultron-engine-config/1''%') THEN 't' ELSE 'f' END||' '||CASE WHEN bool_and(pg_get_constraintdef(oid) LIKE '%''ultron-engine-config/2''%') THEN 't' ELSE 'f' END FROM pg_constraint WHERE contype = 'c' AND conname = 'uspub_engine_contract_chk';"
# Forma de una tabla: columnas · disparadores · CHECK (y parciales con «parciales»).
q_forma() { # tabla [parciales]
  if [ "${2:-}" = parciales ]; then
    echo "SELECT (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname = '$1')||' '||(SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname = '$1')||' '||(SELECT count(*) FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid WHERE t.relname = '$1' AND i.indpred IS NOT NULL AND i.indisunique);"
  else
    echo "SELECT (SELECT count(*) FROM information_schema.columns WHERE table_schema = 'public' AND table_name = '$1')||' '||(SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname = '$1')||' '||(SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname = '$1');"
  fi
}
Q_LARGAS="SELECT count(*) FROM pg_stat_activity a WHERE a.xact_start < now() - interval '30 seconds' AND a.pid <> pg_backend_pid();"
# Las dos tablas que las migraciones de la ola bloquean con ACCESS EXCLUSIVE (la cadena del H5 y la de W9).
Q_BLOQ="SELECT count(*) FROM pg_locks l JOIN pg_class c ON c.oid = l.relation WHERE c.relname IN ('ultron_studio_publications', '$TABLA_W9') AND l.pid <> pg_backend_pid();"
Q_HUELLA="SELECT md5(pg_get_functiondef(oid)) FROM pg_proc WHERE proname = 'ultron_studio_forbid';"
MIG_LISTA_SQL=$(for m in $MIG_OLA; do printf "'%s', " "$m"; done); MIG_LISTA_SQL=${MIG_LISTA_SQL%, }
Q_MIG_OLA="SELECT coalesce(string_agg(migration, ' ' ORDER BY migration), '') FROM migrations WHERE migration IN ($MIG_LISTA_SQL);"
Q_MIGRACIONES="SELECT migration FROM migrations;"
FUNC_REL="(proname LIKE 'outreach\_%' OR proname LIKE 'ultron\_studio\_chk\_%' OR proname = 'ultron_studio_strategy_publication_chain')"
Q_FUNC="SELECT count(*)||' '||count(*) FILTER (WHERE prosecdef)||' '||count(DISTINCT pg_get_userbyid(proowner))||' '||coalesce(min(pg_get_userbyid(proowner)), '') FROM pg_proc WHERE $FUNC_REL;"
Q_APAGADOS="SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND (c.relname LIKE 'outreach\_%' OR c.relname LIKE 'ultron\_studio\_%' OR c.relname LIKE 'voice\_%') AND t.tgenabled <> 'O';"
REL_OLA="(c.relname LIKE 'outreach\_%' OR c.relname LIKE 'ultron\_studio\_%')"
Q_DUENOS="SELECT coalesce(string_agg(c.relname || ':' || pg_get_userbyid(c.relowner), ' ' ORDER BY c.relname COLLATE \"C\"), '') FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind = 'r' AND $REL_OLA;"
# iron_app en TODAS las tablas del Studio y de la ejecución (r SELECT · a INSERT · w UPDATE · d DELETE · D TRUNCATE ·
# s secuencia): las de antes, iguales; la nueva, la suya.
Q_PRIV="SELECT coalesce(string_agg(c.relname || '=' || CASE WHEN has_table_privilege('$APP_BD', c.oid, 'SELECT') THEN 'r' ELSE '-' END || CASE WHEN has_table_privilege('$APP_BD', c.oid, 'INSERT') THEN 'a' ELSE '-' END || CASE WHEN has_any_column_privilege('$APP_BD', c.oid, 'UPDATE') THEN 'w' ELSE '-' END || CASE WHEN has_table_privilege('$APP_BD', c.oid, 'DELETE') THEN 'd' ELSE '-' END || CASE WHEN has_table_privilege('$APP_BD', c.oid, 'TRUNCATE') THEN 'D' ELSE '-' END || CASE WHEN to_regclass('public.' || c.relname || '_id_seq') IS NULL THEN '?' WHEN has_sequence_privilege('$APP_BD', 'public.' || c.relname || '_id_seq', 'USAGE') THEN 's' ELSE '-' END, ' ' ORDER BY c.relname COLLATE \"C\"), '') FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind = 'r' AND $REL_OLA;"
# Las filas de cada tabla del Studio (la ejecución en sombra escribe pasadas cada minuto: sus tablas no se comparan).
Q_FILAS_STUDIO="SELECT coalesce(string_agg(c.relname || '=' || (xpath('/row/n/text()', query_to_xml(format('SELECT count(*) AS n FROM public.%I', c.relname), false, true, '')))[1]::text, ' ' ORDER BY c.relname COLLATE \"C\"), '') FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind = 'r' AND c.relname LIKE 'ultron\_studio\_%';"
Q_FILAS_OUT="SELECT coalesce(string_agg(c.relname || '=' || (xpath('/row/n/text()', query_to_xml(format('SELECT count(*) AS n FROM public.%I', c.relname), false, true, '')))[1]::text, ' ' ORDER BY c.relname COLLATE \"C\"), '') FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind = 'r' AND c.relname LIKE 'outreach\_%';"
Q_PUB="SELECT count(*)||' '||count(*) FILTER (WHERE engine_contract = 'ultron-engine-config/2') FROM ultron_studio_publications;"
Q_PRIV_PREVIAS="SELECT string_agg(t||'='||has_table_privilege('$APP_BD', 'public.' || t, 'SELECT')||'/'||has_table_privilege('$APP_BD', 'public.' || t, 'INSERT')||'/'||has_any_column_privilege('$APP_BD', 'public.' || t, 'UPDATE')||'/'||has_table_privilege('$APP_BD', 'public.' || t, 'DELETE')||'/'||has_table_privilege('$APP_BD', 'public.' || t, 'TRUNCATE'), ' ' ORDER BY t) FROM unnest(ARRAY['voice_calls', 'voice_brakes', 'voice_contacts', 'voice_consents', 'marketing_ai_actions']) AS t WHERE to_regclass('public.' || t) IS NOT NULL;"
Q_COLA_H9="SELECT CASE WHEN to_regclass('public.jobs') IS NULL THEN 'sin_tabla' ELSE (SELECT count(*) FROM jobs WHERE queue LIKE 'outreach%')::text END;"
Q_LIVE="SELECT CASE WHEN to_regclass('public.outreach_jobs') IS NULL THEN 'sin_tabla' ELSE (SELECT count(*) FROM outreach_jobs WHERE mode = 'live')::text END;"
# Las tablas de hoy, sin la nueva (en una reanudación ya existe y el respaldo, de antes, no la tiene).
Q_TABLAS_VIVAS="SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename <> '$TABLA_NUEVA';"
sin_nueva() { printf '%s\n' $1 | grep -v "^$TABLA_NUEVA=" | paste -sd ' ' -; }
OPS=/var/www/api/backend/docs/ultron-studio/ops

# ── Paso 12: los smokes del H9 y del H8, DEL COMMIT DESPLEGADO, en la fase que se dedujo ─────────────────────────────
# Se copian de su checkout a un directorio 700 bajo $LOGDIR (los lanzadores borran sus copias al terminar) y se corren
# uno tras otro con una pausa (la misma cuenta 32 y el cupo de inicios de sesión por IP). Las variables SMOKE_* que ya
# traiga el entorno (el banco) se respetan; SMOKE_WORKDIR, SMOKE_PHASE y las de esta ola las fija este guion.
smoke() { # h9|h8 fase → código de salida del lanzador (0 OK · 1 FALLO · 2 uso · 3 OMITIDOS)
  local h=$1 fase=$2 d rc=0
  d="$LOGDIR/smoke-m1-$h-$TS"
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
  echo "   smoke del $(printf '%s' "$h" | tr a-z A-Z), fase $fase (huellas: $(cd "$d" && sha256sum smoke-run-$h.sh smoke-$h.py | awk '{print substr($1, 1, 12)}' | paste -sd ' ' -)):"
  set +e
  ( export SMOKE_WORKDIR="$d" SMOKE_PHASE="$fase"
    if [ "$h" = h9 ]; then export SMOKE_EXPECT_STUDIO_TABLES=27 SMOKE_EXPECT_OUTREACH_TASKS="$OUT0" SMOKE_EXPECT_M1=1 SMOKE_PROMPTS_KEY=off; fi
    unset SMOKE_PY SMOKE_H6_PY SMOKE_BUDGETS; bash "$d/smoke-run-$h.sh" ) 2>&1 | sed 's/^/      /'
  rc=${PIPESTATUS[0]}
  set -e
  rmdir "$d" 2> /dev/null || true   # los lanzadores borran sus copias; si algo quedó (sus tokens no: los borra su trap), se queda
  return "$rc"
}
correr_smokes() {
  local r9=0 r8=0
  echo "12. smokes (cuenta 32 «Studio smoke»; vuelve a su reposo al terminar, también si fallan): H9 en fase $FASE_H9 · H8 en fase $FASE_H8"
  smoke h9 "$FASE_H9" || r9=$?
  echo "   pausa de 65 s entre los dos smokes (el cupo de inicios de sesión por IP y las peticiones anónimas del primero)"
  sleep 65
  smoke h8 "$FASE_H8" || r8=$?
  echo "   smoke del H9: código $r9 · smoke del H8: código $r8 (0 OK · 3 sin fallos con OMITIDOS · 1 FALLO · 2 uso)"
  case "$r9" in 0|3) ;; *) desvio "el smoke del H9 salió con $r9" ;; esac
  case "$r8" in 0|3) ;; *) desvio "el smoke del H8 salió con $r8" ;; esac
}
vuelta_atras() {
  echo "   VUELTA ATRÁS (nivel 3; NUNCA migrate:rollback):"
  echo "     0. si se movió la llave de prompts (E2): bash /root/motores-prompts-scope.sh off canary · si hay entradas /2 ($PSQL -c \"$Q_PUB\" → la 2.ª cifra > 0): antes «volver a Legacy» en el Studio (R10)"
  echo "     1. [ \"\$(git -C /var/www/api rev-parse --short=7 HEAD)\" = $ESPERADO ] && ( cd /var/www/api && umask 022 && git checkout -q --detach \"\$(cat $BK/api-commit.txt)\" )"
  echo "     2. cd /var/www/api/backend && sudo -u www-data sh -c 'umask 077; php artisan config:cache -q && php artisan route:cache -q' && sudo -u www-data php artisan queue:restart"
  echo "     3. stat -c '%U %a' bootstrap/cache/config.php bootstrap/cache/routes-v7.php   # www-data 600 · rutas del Studio: ${RUTAS_ANTES%% *} · workers ${RUN0:-13 o 14} en RUNNING"
  echo "     (opcional) el fichero de entorno de antes: install -m <modo> -o <dueño> -g <grupo> $BK/env.backup $ENVF y config:cache como arriba"
  echo "     el esquema QUEDA: $TABLA_NUEVA (vacía, sin lector), las 3 columnas nuevas de $TABLA_W9 (sin lector) y el CHECK /1|/2 de la cadena: quitarlos es de database-specialist con autorización"
  echo "     n8n: S1 puede quedarse con el código anterior (sin oferta no manda el bloque); su vuelta: n8n-copia-s1/LEEME.md · CRM: no hace falta volverlo"
}

if [ -n "$SOLO_SMOKE" ]; then
  [ "$(g -C /var/www/api rev-parse --short=7 HEAD)" = "$ESPERADO" ] || aborta "producción no está en $ESPERADO: --solo-smoke es para después del despliegue"
  PREVIA_ANTES=$(leer_previa); H9_ANTES=$(leer_h9)
  [ -n "$PREVIA_ANTES" ] && [ -n "$H9_ANTES" ] || aborta "no se pudo leer la configuración efectiva (tinker falló; su salida no se imprime)"
  FASE_H9=$(fase_h9 "$H9_ANTES"); FASE_H8=$(fase_h8 "$PREVIA_ANTES")
  [ "$FASE_H9" != "?" ] && [ "$FASE_H8" != "?" ] || aborta "el H9 (fase $FASE_H9) o la voz (fase $FASE_H8) no están en una fase que cubran los smokes"
  PROG0=$(programador); case "$PROG0" in "FALLO "*|ILEGIBLE) aborta "schedule:list no se puede leer ($PROG0)" ;; esac
  read -r _ _ _ OUT0 _ <<< "$PROG0"
  T=$(traza); echo "   ultron:prompt-trace: $T (esperado «off legacy prompts_off»)"; [ "$T" = "off legacy prompts_off" ] || desvio "la traza de M-1 no es la de la llave apagada: $T"
  correr_smokes
  if [ "$DESVIOS" = 0 ]; then echo "SMOKES DE LA OLA M-1 EN VERDE"; else echo "SMOKES DE LA OLA M-1 CON $DESVIOS DESVÍOS"; vuelta_atras; exit 1; fi
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
# la ola se reconocen por su prefijo (PREFIJO_OLA). `outreach(sends-open):` o `voice(calls-open):` ABORTAN.
EN_PROD=$(g log --no-merges -p --max-count=400 "$BASE" | g patch-id --stable | awk '{print $1}' | sort -u)
AJENOS=""; ACEPTADOS_VISTOS=""; PROPIOS=0
while read -r pid cid; do
  [ -n "${cid:-}" ] || continue
  grep -qxF "$pid" <<< "$EN_PROD" && continue
  ASUNTO=$(g log -1 --format=%s "$cid")
  [[ "$ASUNTO" =~ ^outreach\(sends-open\): ]] && aborta "el commit ${cid:0:7} abre la constante de envío (\`outreach(sends-open):\`): se despliega aparte, con la autorización escrita del propietario y el visto bueno legal, nunca con esta ola"
  [[ "$ASUNTO" =~ ^voice\(calls-open\): ]] && aborta "el commit ${cid:0:7} abre la constante de llamadas del H8 (\`voice(calls-open):\`): se despliega aparte (deploy-h8-backend-fase1.sh --fase=4a), nunca con esta ola"
  if [[ "$ASUNTO" =~ $PREFIJO_OLA ]]; then PROPIOS=$((PROPIOS + 1)); continue; fi
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
# La puerta de diff CERRADA: cada fichero que cambie entre PREVIO y origin/main está en una zona del Studio, en pruebas o
# docs, en la lista de migraciones, en una zona inerte (n8n) o en la LISTA CERRADA; si no, aborta.
PERMITIDOS=$(printf '%s\n' "$LISTA_CERRADA" | awk '{print $1}')
FUERA=""; EN_LISTA=""; LIBRES=0; INERTES=0
while IFS=$'\t' read -r st f; do
  [ -n "${f:-}" ] || continue
  case "$f" in backend/database/migrations/*) continue ;; esac
  libre=0; for z in $ZONAS_LIBRES; do case "$f" in "$z"*) libre=1; break ;; esac; done
  if [ "$libre" = 1 ]; then LIBRES=$((LIBRES + 1)); continue; fi
  inerte=0; for z in $ZONAS_INERTES; do case "$f" in "$z"*) inerte=1; break ;; esac; done
  if [ "$inerte" = 1 ]; then INERTES=$((INERTES + 1)); continue; fi
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
echo "   puerta de diff: $LIBRES ficheros en las zonas del Studio, pruebas y docs · $INERTES artefactos de n8n (no se ejecutan) · $N_LISTA de la lista cerrada (fuera del Studio):"
for f in $EN_LISTA; do printf '%s\n' "$LISTA_CERRADA" | awk -v f="$f" '$1 == f {$1 = ""; sub(/^ +/, ""); printf "      %s — %s\n", f, $0}'; done
# Las dos constantes, ANTES del merge, en el commit que se va a desplegar: cerradas (una declaración `= false;` anclada
# y ninguna `= true`). Ninguno de sus ficheros está en la lista cerrada: la puerta ya habría abortado si cambiaran.
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
CON0=$($PSQL -c "$Q_CONTRATO") || aborta "no se pudo leer el CHECK del contrato de la cadena (psql)"
W90=$($PSQL -c "$(q_forma "$TABLA_W9")") || aborta "no se pudo leer la forma de $TABLA_W9 (psql)"
if [ -z "$MIG_REG" ]; then
  [ "$ESQ0" = "$ESQ_ANTES" ] || aborta "esquema de partida [$ESQ0], esperado el del cierre del H9 [$ESQ_ANTES]: PARAR y avisar a database-specialist"
  [ "$EFE0" = "$EFECTOS_ANTES" ] || aborta "efecto de las migraciones de la ola ANTES de aplicarlas [$EFE0], esperado [$EFECTOS_ANTES]: PARAR y avisar a database-specialist"
  [ "$CON0" = "$CONTRATO_ANTES" ] || aborta "uspub_engine_contract_chk de partida [$CON0], esperado [$CONTRATO_ANTES] (uno, validado, solo /1): la migración de M-1 se negaría; PARAR y avisar a database-specialist"
  [ "$W90" = "$W9_ANTES" ] || aborta "$TABLA_W9 de partida [$W90] (columnas · disparadores · CHECK), esperado [$W9_ANTES]: la ampliación se negaría; PARAR y avisar a database-specialist"
fi
case "$VOZ0" in *" 1 1") ;; *) aborta "voice_% de partida [$VOZ0]: se esperaba exactamente un vc_requester_chk y un vbr_reason_chk" ;; esac
[ "$($PSQL -c "$Q_HUELLA")" = "$HUELLA_F" ] || aborta "ultron_studio_forbid() no es la de F: PARAR y avisar a database-specialist"
DUENOS_ID=$($PSQL -c "SELECT (SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE proname = 'ultron_studio_forbid')||' '||(SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = 'ultron_studio_publications')||' '||(SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = '$TABLA_W9')||' '||has_schema_privilege('$DUENO_BD', 'public', 'CREATE')||' '||(SELECT count(*) FROM pg_roles WHERE rolname = '$APP_BD');") || aborta "no se pudo leer los dueños (psql)"
[ "$DUENOS_ID" = "$DUENO_BD $DUENO_BD $DUENO_BD true 1" ] || aborta "dueños de partida [$DUENOS_ID] (esperado: F, la cadena y la tabla de W9 de $DUENO_BD, CREATE de $DUENO_BD en public y el rol $APP_BD): sin eso las migraciones de la ola dan 42501"
FUNC0=$($PSQL -c "$Q_FUNC")
[ -n "$MIG_REG" ] || [ "$FUNC0" = "$FUNC_ANTES" ] || aborta "funciones de partida [$FUNC0], esperado [$FUNC_ANTES]"
PRIV_0=$($PSQL -c "$Q_PRIV") || aborta "no se pudieron leer los privilegios de iron_app (psql)"
PRIV_0=$(sin_nueva "$PRIV_0")
case " $PRIV_0 " in *" $PRIV_W9_ESPERADO "*) ;; *) aborta "iron_app en $TABLA_W9 de partida no es $PRIV_W9_ESPERADO: la ampliación lo vuelve a comprobar y fallaría" ;; esac
PRIVP_0=$($PSQL -c "$Q_PRIV_PREVIAS") || aborta "no se pudieron leer los privilegios de iron_app en la voz (psql)"
FILAS_0=$($PSQL -c "$Q_FILAS_STUDIO") || aborta "no se pudieron contar las filas del Studio (psql)"
FILAS_0=$(sin_nueva "$FILAS_0")
PUB0=$($PSQL -c "$Q_PUB") || aborta "no se pudo leer la cadena de publicaciones (psql)"
[ "${PUB0#* }" = 0 ] || aborta "ya hay ${PUB0#* } entradas /2 en la cadena antes de desplegar: imposible con el código de hoy; PARAR y avisar a database-specialist"
LIVE0=$($PSQL -c "$Q_LIVE")
[ "$LIVE0" = 0 ] || [ "$LIVE0" = sin_tabla ] || aborta "hay $LIVE0 trabajos de outreach en modo live antes de desplegar: esta ola no se despliega sobre la fase en vivo"
BLOQ=$($PSQL -c "$Q_BLOQ"); LARGAS=$($PSQL -c "$Q_LARGAS")
RUTAS0=$(rutas)
case "$REANUDA" in
  0) [ "$RUTAS0" = "$RUTAS_ANTES" ] || aborta "rutas en la caché de producción [$RUTAS0] (esperado [$RUTAS_ANTES]: el cierre del H9)" ;;
  *) echo "   rutas en la caché ahora (informativo en una reanudación): [$RUTAS0]" ;;
esac
PROG0=$(programador)
case "$PROG0" in "FALLO "*|ILEGIBLE) aborta "schedule:list no se puede leer ($PROG0): sin el programador no se despliega (m-1 del H7)" ;; esac
read -r TOTAL0 STUDIO0 VOZ_T0 OUT0 META0 <<< "$PROG0"
[ "$META0" = 0 ] || aborta "el programador ya tiene la sincronización de Meta antes de desplegar ($META0)"
PREVIA_ANTES=$(leer_previa)
[ -n "$PREVIA_ANTES" ] || aborta "no se pudo leer la configuración efectiva del motor, de Legacy, del H4–H7 y de la voz (tinker falló; su salida no se imprime)"
H9_ANTES=$(leer_h9)
[ -n "$H9_ANTES" ] || aborta "no se pudo leer la configuración del H9 (tinker falló; su salida no se imprime)"
OLA_ANTES=$(leer_ola)   # con el código de hoy (o el de la ola, en una reanudación): informativo
FASE_H9=$(fase_h9 "$H9_ANTES"); FASE_H8=$(fase_h8 "$PREVIA_ANTES")
[ "$FASE_H9" != "?" ] || aborta "el H9 no está ni en sombra ni todo apagado (ver la configuración informativa de abajo): esta ola se despliega sobre la sombra (L9-101) o la fase 1; con otra fase no hay smoke que la cubra. Pedir otra orden"
[ "$FASE_H8" != "?" ] || aborta "la voz no está ni en 2a (el laboratorio hablado, sin llamadas) ni toda apagada: no hay smoke del H8 que la cubra. Pedir otra orden"
case "$FASE_H9" in 2) [ "$OUT0" -ge 1 ] || aborta "el H9 está en sombra y el programador no tiene tareas outreach:* ($PROG0)" ;; 1) [ "$OUT0" = 0 ] || aborta "el H9 está apagado y el programador tiene $OUT0 tareas outreach:*" ;; esac
W_ESPERADOS=$(workers_esperados)
W0=$(workers "$W_ESPERADOS" 20); RUN0=${W0%% *}
[ "${W0#* }" = 0 ] || aborta "workers con BACKOFF o FATAL antes de empezar ($W0): no se despliega sobre un sistema degradado"
[ "$RUN0" = "$W_ESPERADOS" ] || aborta "workers RUNNING antes de empezar: $RUN0 (esperado $W_ESPERADOS: 13 programas$([ "$W_ESPERADOS" = 14 ] && echo " y ironbody-voice"))"
FACT=$(systemctl is-active ironbody-billing-worker 2>/dev/null || true)
[ "$FACT" = active ] || aborta "ironbody-billing-worker no está activo antes de empezar ($FACT)"
# El CRM de la ola ya publicado (va ANTES que el backend) y distinto del de antes de la ola.
PUB=$(curl -fsS "$CRM_WEB/version.json" 2>/dev/null || true)
printf '%s' "$PUB" | grep -Eq "\"commit\": *\"$CRM\"" || aborta "el CRM publicado no es $CRM ($CRM_WEB/version.json: ${PUB:0:120}): primero el CRM de la ola (RUNBOOK_M1.md §2: el CRM va ANTES que el backend)"
[ "$CRM" != "$CRM_PREVIO" ] || aborta "--crm=$CRM es el CRM de antes de la ola ($CRM_PREVIO): primero el CRM de la ola"
N8N=$(n8n_flujo)
LOG_ANTES=$(lineas_log)
FALLIDAS_ANTES=$($PSQL -c "SELECT count(*) FROM failed_jobs;") || aborta "no se pudo leer failed_jobs (psql)"
INST_ANTES=$($PSQL -c "SELECT count(*) FROM ultron_studio_integration_snapshots;") || aborta "no se pudieron contar las instantáneas (psql)"
INCID_ANTES=$($PSQL -c "SELECT count(*) FROM incidents WHERE source IN ('ultron_studio', 'outreach', 'voice');") || aborta "no se pudieron contar los incidentes (psql)"
COLA0=$($PSQL -c "$Q_COLA_H9")
OUTF0=$($PSQL -c "$Q_FILAS_OUT")
MONI0=$(monitor_integraciones)
[ "$MONI0" != "?" ] || aborta "el monitor del H7 en seco (integraciones) no devolvió un resultado legible"
case ",$MONI0," in *",config_drift:"*) aborta "el monitor del H7 ya ve deriva de la configuración autorizada antes de desplegar ([$MONI0]): aclararlo antes" ;; esac
sudo -u "$WWW" php -r 'require "/var/www/api/backend/vendor/autoload.php"; try { Dotenv\Dotenv::createArrayBacked("/var/www/api/backend")->load(); } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' \
  || aborta "el fichero de entorno de producción NO parsea antes de empezar: aclararlo antes de desplegar (no se ha tocado nada)"
echo "   HEAD $ACTUAL limpio · $PROPIOS commits de la ola · migraciones de la ola pendientes [${PENDIENTES% }] · esquema [$ESQ0] · voz [$VOZ0] · efectos [$EFE0] · contrato [$CON0] · W9 [$W90] · función de F · rutas [$RUTAS0] · programador $TOTAL0 tareas ($STUDIO0 del Studio, $VOZ_T0 de voz, $OUT0 de outreach, 0 de Meta) · workers $W0 · facturación activa"
echo "   fases de partida: H9 $FASE_H9 ($([ "$FASE_H9" = 2 ] && echo "sombra" || echo "todo apagado")) · voz $FASE_H8 · CRM publicado: $CRM · n8n atiende con: $N8N"
echo "   H9 (informativo; no cambia): $H9_ANTES"
echo "   motor, Legacy, H4–H8 (informativo; no cambia): $PREVIA_ANTES"
echo "   lo de la ola con el código de hoy (informativo; null = sin desplegar): ${OLA_ANTES:-ilegible}"
echo "   iron_app en la voz y marketing_ai_actions: $PRIVP_0 · cadena (entradas · /2): $PUB0 · trabajos outreach en vivo: $LIVE0 · outreach_* (informativo: la sombra escribe pasadas): $OUTF0"
echo "   colas fallidas $FALLIDAS_ANTES · incidentes del Studio, outreach y voz $INCID_ANTES · colas outreach* $COLA0 · monitor del H7 en seco (integraciones): [$MONI0]"
[ "$BLOQ" = 0 ] || echo "   AVISO: $BLOQ cerrojos sobre ultron_studio_publications o $TABLA_W9 de otras sesiones: las migraciones de la ola piden ACCESS EXCLUSIVE (lock_timeout 5 s, se reintenta)"
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
# La base de los prompts: sus cinco sha256 están fijados en UltronPromptBase (informe-bm.md §3); un fichero que no casa
# haría caer `decide` a Legacy (`error`) en cuanto se encienda la llave, y con la llave en off nadie lo vería. Se
# comprueba aquí, en el checkout desplegado, byte a byte.
BASE_MAL=""
for par in strategist:c84387582f581640493545996c7c61ab9a46d11829d740f1319c34b5c50a6917 composer:78437378e4a3b7bfba0b407d73b9e165b3a28e1e0555371bf771d0e47780e866 \
           critic:180aa6c37257448f24a15e0ba7dafab918693a60cecf19e6b8141471e200c896 composer_retry:92d97a04316cf5a6fecb4a70c3f0e573c49d54f881bf6f2733b96524cb279ccd \
           critic_retry:a03940c2f4d0d5f21f3ee49007162b1057106bb619aa162292a22673009315ed; do
  f="backend/resources/ultron-prompts/n8n-29de5119/${par%%:*}.txt"
  [ "$(sha256sum "$f" 2>/dev/null | awk '{print $1}')" = "${par#*:}" ] || BASE_MAL="$BASE_MAL ${par%%:*}"
done
[ -z "$BASE_MAL" ] && echo "   la base de los prompts (5 ficheros) casa con sus sha256" || desvio "la base de los prompts no casa con sus sha256 en:$BASE_MAL (¿conversión de finales de línea?): decide caería a Legacy con la llave encendida"
echo "   los $REVISADOS ficheros del merge son legibles por www-data"
cd backend
echo "2. variables ($N_VARS: las claves nuevas de la ola, apagadas o con su valor por defecto):"
[ -z "$(tail -c1 "$ENVF")" ] || printf '\n' >> "$ENVF"
for kv in $VARS; do
  k=${kv%%=*}; v=${kv#*=}
  case "$(estado_var "$k" "$v")" in
    falta) printf '  %s=%s\n' "$k" "$v" >> "$ENVF"; echo "   $k añadida" ;;
    igual) echo "   $k ya estaba con el valor de la ola" ;;
    repetida) aborta "$k aparece repetida en el fichero de entorno (gana la última): deduplicar antes; restáuralo desde $BK/env.backup si hace falta" ;;
    distinto) aborta "$k ya existe con OTRO valor (no se imprime): esta ola exige lo suyo apagado; aclararlo antes (los mandos son el procedimiento para moverla después)" ;;
  esac
done
echo "   permisos del fichero: $(stat -c '%U %a' "$ENVF")"
sudo -u "$WWW" php -r 'require "vendor/autoload.php"; try { Dotenv\Dotenv::createArrayBacked(".")->load(); } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' \
  || aborta "el fichero de entorno NO parsea: restáuralo desde $BK/env.backup (mismo dueño y modo) antes de seguir"
echo "   el fichero de entorno parsea"
echo "3. migraciones (rol dueño, lock_timeout 5 s; un 55P03 se reintenta hasta 3 veces, 15 s entre intentos):"
INTENTO=0; RC=0
MIG_ESPERADO=$(echo $MIG_OLA | tr ' ' '\n' | sort | paste -sd ' ' -)
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
    [ "$ESQ $MIG_REG" != "$ESQ_DESPUES $MIG_ESPERADO" ] || break
    case "$SALIDA" in
      *55P03*|*"lock timeout"*)
        if [ "$INTENTO" -lt 3 ]; then echo "   intento $INTENTO: 55P03 (una sesión con la cadena o la tabla de W9 abierta) · esquema [$ESQ] · registradas [$MIG_REG] · reintento en 15 s"; sleep 15; continue; fi ;;
    esac
    aborta "esquema tras ironbody-migrate [$ESQ], esperado [$ESQ_DESPUES] (código $RC, intento $INTENTO; registro [$MIG_REG]). Cada migración es una transacción: o está entera o no está. NO se han hecho las cachés; relanzar este mismo script (reanuda) o la vuelta atrás del código"
  done
fi
ESQ=$($PSQL -c "$Q_ESQUEMA"); VOZ=$($PSQL -c "$Q_VOZ"); EFE=$($PSQL -c "$Q_EFECTOS"); MIG_REG=$($PSQL -c "$Q_MIG_OLA")
[ "$ESQ" = "$ESQ_DESPUES" ] || aborta "esquema [$ESQ], esperado [$ESQ_DESPUES]: NO se han hecho las cachés; PARAR y avisar a database-specialist"
[ "$MIG_REG" = "$MIG_ESPERADO" ] || aborta "registro de las migraciones de la ola [$MIG_REG] incompleto: NO se han hecho las cachés"
[ "$VOZ" = "$VOZ0" ] || aborta "voice_% tras la ola [$VOZ] (antes [$VOZ0]): la ola no toca la voz. NO se han hecho las cachés"
[ "$EFE" = "$EFECTOS_DESPUES" ] || aborta "efecto de las migraciones de la ola [$EFE], esperado [$EFECTOS_DESPUES] (la tabla de envíos · source/ustmc_source_chk/ustmc_submission_of_template en W9 · el CHECK con /2). NO se han hecho las cachés; avisar a database-specialist"
[ "$RC" = 0 ] || desvio "ironbody-migrate salió con $RC aunque el esquema cuadra: revisar su salida (arriba)"
echo "   esquema [$ESQ] (intento ${INTENTO:-0}) · voz [$VOZ] (igual que antes) · efecto de las migraciones [$EFE]"
echo "4. esquema de la ola (antes de las cachés):"
FUNC=$($PSQL -c "$Q_FUNC")
echo "   funciones (número · SECURITY DEFINER · dueños distintos · dueño): $FUNC"
[ "$FUNC" = "$FUNC_DESPUES" ] || aborta "funciones [$FUNC], esperado [$FUNC_DESPUES]. NO se han hecho las cachés"
APAG=$($PSQL -c "$Q_APAGADOS")
[ "$APAG" = 0 ] || aborta "$APAG disparadores apagados en ultron_studio_*, outreach_* o voice_*: NO se han hecho las cachés; PARAR y avisar a database-specialist"
[ "$($PSQL -c "$Q_HUELLA")" = "$HUELLA_F" ] || aborta "ultron_studio_forbid() cambió de cuerpo con la migración. NO se han hecho las cachés"
CON=$($PSQL -c "$Q_CONTRATO"); W9=$($PSQL -c "$(q_forma "$TABLA_W9")"); ENV9=$($PSQL -c "$(q_forma "$TABLA_NUEVA" parciales)")
echo "   uspub_engine_contract_chk [$CON] (cuántos · validado · /1 · /2) · $TABLA_W9 [$W9] (columnas · disparadores · CHECK) · $TABLA_NUEVA [$ENV9] (disparadores · CHECK · únicos parciales)"
[ "$CON" = "$CONTRATO_DESPUES" ] || aborta "uspub_engine_contract_chk [$CON], esperado [$CONTRATO_DESPUES]. NO se han hecho las cachés; avisar a database-specialist"
[ "$W9" = "$W9_DESPUES" ] || aborta "$TABLA_W9 [$W9], esperado [$W9_DESPUES]. NO se han hecho las cachés; avisar a database-specialist"
[ "$ENV9" = "$ENVIOS_DESPUES" ] || aborta "$TABLA_NUEVA [$ENV9], esperado [$ENVIOS_DESPUES]. NO se han hecho las cachés; avisar a database-specialist"
DUENOS=$($PSQL -c "$Q_DUENOS")
case " $DUENOS " in *" $TABLA_NUEVA:$DUENO_BD "*) ;; *) aborta "la tabla $TABLA_NUEVA no es de $DUENO_BD. NO se han hecho las cachés" ;; esac
[ "$(printf '%s\n' $DUENOS | grep -vc ":$DUENO_BD\$" || true)" = 0 ] || aborta "tablas del Studio o de la ejecución que no son de $DUENO_BD: [$DUENOS]. NO se han hecho las cachés"
PRIV=$($PSQL -c "$Q_PRIV")
case " $PRIV " in *" $PRIV_NUEVA_ESPERADO "*) ;; *) aborta "privilegios de iron_app en $TABLA_NUEVA no son $PRIV_NUEVA_ESPERADO (sin DELETE ni TRUNCATE). NO se han hecho las cachés; avisar a database-specialist" ;; esac
case " $PRIV " in *" $PRIV_W9_ESPERADO "*) ;; *) aborta "privilegios de iron_app en $TABLA_W9 ya no son $PRIV_W9_ESPERADO (solo inserción). NO se han hecho las cachés; avisar a database-specialist" ;; esac
[ "$(sin_nueva "$PRIV")" = "$PRIV_0" ] && echo "   iron_app: $PRIV_NUEVA_ESPERADO · $PRIV_W9_ESPERADO · las demás tablas del Studio y de la ejecución, iguales que antes" || desvio "los privilegios de iron_app en las tablas de antes cambiaron: antes [$PRIV_0] · después [$(sin_nueva "$PRIV")]"
PRIVP=$($PSQL -c "$Q_PRIV_PREVIAS")
[ "$PRIVP" = "$PRIVP_0" ] || desvio "los privilegios de iron_app en la voz o en marketing_ai_actions cambiaron: antes [$PRIVP_0] · después [$PRIVP]"
FILAS=$($PSQL -c "$Q_FILAS_STUDIO")
case " $FILAS " in *" $TABLA_NUEVA=0 "*) ;; *) desvio "la tabla $TABLA_NUEVA no está vacía tras crearla" ;; esac
[ "$(sin_nueva "$FILAS")" = "$FILAS_0" ] && echo "   las tablas del Studio con las mismas filas que antes y la de envíos vacía" || desvio "las filas de las tablas del Studio cambiaron durante el despliegue (¿alguien usó el Studio?): antes [$FILAS_0] · después [$(sin_nueva "$FILAS")]"
PUB1=$($PSQL -c "$Q_PUB")
[ "$PUB1" = "$PUB0" ] && echo "   la cadena: $PUB1 (entradas · /2), igual que antes" || desvio "la cadena de publicaciones cambió durante el despliegue: antes [$PUB0] · después [$PUB1]"
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
OLA=$(leer_ola)
RUTAS=$(rutas)
COBERTURA=$(artisan auth:coverage --unmapped 2>&1 | tr -d '\n' | cut -c1-80 || true)
STUDIO_TOTAL=$(printf '%s' "$OLA" | python3 -I -c 'import json, sys; v = json.load(sys.stdin).get("studio_routes_total"); print(v if isinstance(v, int) else "?")' 2>/dev/null || echo '?')
echo "6. rutas (Studio · strategies/** · ejecución · GET · POST · canal SSE · plantillas de Meta): $RUTAS (antes $RUTAS0; StudioRouteCount::TOTAL del código desplegado: $STUDIO_TOTAL) · auth:coverage --unmapped → [$COBERTURA]"
[ "$RUTAS" = "$RUTAS_DESPUES" ] || desvio "rutas [$RUTAS] (esperado [$RUTAS_DESPUES]: las 4 de las plantillas de Meta y nada más)"
[ "${RUTAS%% *}" = "$STUDIO_TOTAL" ] || desvio "el Studio sirve ${RUTAS%% *} rutas y StudioRouteCount::TOTAL dice $STUDIO_TOTAL"
[ -z "$COBERTURA" ] || desvio "auth:coverage --unmapped no está vacío"
echo "7. configuración efectiva:"
case "$OLA" in '{"prompts_key"'*) echo "   lo de la ola: $OLA" ;; *) echo "   (tinker no devolvió la configuración de la ola; su salida no se imprime)" ;; esac
APAGADA=$(ola_apagada "${OLA:-x}")
[ "$APAGADA" = OK ] && echo "   la ola apagada: llave de prompts off, plantillas de Meta apagadas (enviar y sincronizar también), hosts de URL [ironbodyneiva.cloud], Graph v25.0, 125 rutas en el código (el driver efectivo en producción es graph; con todo apagado no sale nada)" || desvio "lo de la ola no está como se despliega: ${APAGADA#MAL }"
T=$(traza)
echo "   ultron:prompt-trace (llave · oferta · motivo): $T (esperado «off legacy prompts_off»)"
[ "$T" = "off legacy prompts_off" ] || desvio "la traza de M-1 no es la de la llave apagada: $T (ver «sudo -u www-data php artisan ultron:prompt-trace»)"
H9=$(leer_h9)
[ "$H9" = "$H9_ANTES" ] && echo "   H9 igual que antes (fase $FASE_H9; las dos constantes cerradas)" || desvio "la configuración del H9 cambió con el despliegue: antes $H9_ANTES · después ${H9:-ilegible}"
[ "$(fase_h9 "${H9:-x}")" = "$FASE_H9" ] || desvio "la fase del H9 ya no es la de partida ($FASE_H9)"
PREVIA=$(leer_previa)
[ "$PREVIA" = "$PREVIA_ANTES" ] && echo "   motor comercial, Legacy, H4–H7 y voz del H8 iguales que antes (el laboratorio hablado: fase $FASE_H8)" || desvio "la configuración del motor, de Legacy, del H4–H7 o de la voz cambió: antes $PREVIA_ANTES · después ${PREVIA:-ilegible}"
PROG=$(programador)
ESP_PROG="$((TOTAL0 + TAREAS_NUEVAS_SIEMPRE)) $STUDIO0 $VOZ_T0 $OUT0 0"
case "$PROG" in
  "FALLO "*|ILEGIBLE) desvio "schedule:list no se puede leer tras el cambio ($PROG)" ;;
  *) echo "   programador (total · del Studio · de voz · de outreach · sincronización de Meta): $PROG (esperado $ESP_PROG: la ola no añade tareas sin bandera)"
     [ "$PROG" = "$ESP_PROG" ] || desvio "el programador da [$PROG] y se esperaba [$ESP_PROG]" ;;
esac
COLA=$($PSQL -c "$Q_COLA_H9")
[ "$COLA" = "$COLA0" ] && echo "   colas outreach*: $COLA trabajos (igual que antes; en sombra el despacho va en línea)" || desvio "colas outreach*: $COLA0 → $COLA durante el despliegue"
LIVE=$($PSQL -c "$Q_LIVE")
[ "$LIVE" = 0 ] || [ "$LIVE" = sin_tabla ] && echo "   trabajos outreach en modo live: $LIVE" || desvio "hay $LIVE trabajos de outreach en modo live tras el despliegue"
sleep 6
W1=$(workers "$RUN0" 45); sleep 8; W2=$(workers "$RUN0" 45)
echo "8. workers (RUNNING · BACKOFF/FATAL), dos lecturas separadas: [$W1] y [$W2] (esperado $RUN0 0: la ola no instala ningún worker)"
[ "$W1" = "$RUN0 0" ] && [ "$W2" = "$RUN0 0" ] || desvio "workers [$W1] y [$W2] (esperado $RUN0 0 las dos veces)"
FACT=$(systemctl is-active ironbody-billing-worker 2>/dev/null || true)
[ "$FACT" = active ] || desvio "ironbody-billing-worker: $FACT"
echo "9. sanidad:"
for p in admin/ultron-studio/outreach/status admin/ultron-studio/outreach/brakes admin/ultron-studio/strategies admin/ultron-studio/strategies/catalog admin/ultron-studio/strategies/templates admin/ultron-studio/strategies/templates/meta/status admin/ultron-studio/status; do
  CAB=$(curl -sI -H 'Accept: application/json' "$API/$p" | tr -d '\r')
  c=$(printf '%s\n' "$CAB" | awk 'NR == 1 {print $2}')
  cc=$(printf '%s\n' "$CAB" | awk -F': *' 'tolower($1) == "cache-control" {print $2}' | tail -1)
  echo "   HEAD $p sin token → $c · Cache-Control: ${cc:-(ninguna)}"
  [ "$c" = 401 ] || desvio "HEAD $p respondió $c (esperado 401)"
  [ "$cc" = "no-store, private" ] || desvio "HEAD $p: Cache-Control «${cc:-ninguna}» (esperado «no-store, private»)"
done
FAKE=00000000-0000-4000-8000-0000000009a9
for p in strategies/templates/meta/probe "strategies/templates/$FAKE/meta/submit" "strategies/templates/$FAKE/meta/refresh"; do
  c=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Accept: application/json' -H 'Content-Type: application/json' --data-binary '{}' "$API/admin/ultron-studio/$p")
  echo "   POST admin/ultron-studio/${p//$FAKE/<inventado>} sin token → $c (plantillas de Meta)"; [ "$c" = 401 ] || desvio "POST ${p//$FAKE/<inventado>} sin token respondió $c (esperado 401)"
done
c=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$API/admin/ultron-studio/stream?topics=ledger")
echo "   GET admin/ultron-studio/stream sin vale → $c"; [ "$c" = 401 ] || desvio "el canal SSE sin vale respondió $c (esperado 401)"
c=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$API/admin/stream-ticket?scope=stream")
echo "   GET admin/stream-ticket?scope=stream sin sesión → $c"; [ "$c" = 401 ] || desvio "la emisión del vale sin sesión respondió $c (esperado 401)"
GRANDE=$(python3 -c 'import json; print(json.dumps({"name": "x" * 70000}))')
for p in admin/ultron-studio/strategies admin/ultron-studio/outreach/brakes admin/ultron-studio/strategies/templates/meta/probe; do
  R413=$(printf '%s' "$GRANDE" | curl -s -o - -w ' %{http_code}' -X POST -H 'Accept: application/json' -H 'Content-Type: application/json' --data-binary @- "$API/$p" | tail -c 200)
  echo "   POST $p con 70 000 bytes sin token → ${R413##* } $(printf '%s' "$R413" | grep -o '"code":"[a-z_]*"' || true)"
  case "$R413" in *'"code":"payload_too_large"'*' 413') ;; *) desvio "el cuerpo de 70 000 bytes sin sesión a POST $p no dio 413 payload_too_large" ;; esac
done
# M-1: decide y commit internos siguen tras la firma HMAC (automation.internal va ANTES del limitador: una petición sin
# firma da 401 sin gastar el cupo del flujo de n8n).
for p in internal/marketing/ai/decide internal/marketing/ai/commit; do
  c=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Accept: application/json' -H 'Content-Type: application/json' --data-binary '{}' "$API/$p")
  echo "   POST $p sin firma → $c"; [ "$c" = 401 ] || desvio "POST $p sin firma respondió $c (esperado 401)"
done
c=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Accept: application/json' "$API/webhooks/twilio/usage-trigger")
echo "   usage-trigger del OTP sin firma → $c (falla cerrada; T-1, intacto)"; [ "$c" = 403 ] || desvio "usage-trigger del OTP sin firma respondió $c (esperado 403)"
c=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$API/admin/voice/status")
echo "   admin/voice/status sin token → $c"; [ "$c" = 401 ] || desvio "admin/voice/status sin token respondió $c (esperado 401)"
FALLIDAS=$($PSQL -c "SELECT count(*) FROM failed_jobs;")
echo "   colas fallidas: $FALLIDAS (antes $FALLIDAS_ANTES)"; [ "$FALLIDAS" -le "$FALLIDAS_ANTES" ] || desvio "colas fallidas nuevas durante el despliegue"
artisan security:check-secret-exposure > /dev/null 2>&1 && echo "   security:check-secret-exposure: OK" || desvio "security:check-secret-exposure no pasa (ejecutarlo a mano como www-data: dice solo NOMBRES de claves y ficheros)"
artisan ultron-studio:publication-check > /dev/null 2>&1 && echo "   ultron-studio:publication-check: 0 (cuadra)" || desvio "ultron-studio:publication-check no sale con 0"
if artisan ultron-studio:monitor --check=publication --dry-run > /dev/null 2>&1; then echo "   ultron-studio:monitor --dry-run (publicación): 0"; else desvio "ultron-studio:monitor --check=publication --dry-run no sale con 0"; fi
MONI=$(monitor_integraciones)
echo "   monitor en seco, integraciones: [$MONI] (antes [$MONI0])"
[ "$MONI" = "$MONI0" ] || desvio "el veredicto del monitor del H7 sobre las integraciones cambió con el despliegue: antes [$MONI0] · después [$MONI]"
INCID=$($PSQL -c "SELECT count(*) FROM incidents WHERE source IN ('ultron_studio', 'outreach', 'voice');")
[ "$INCID" = "$INCID_ANTES" ] && echo "   incidentes del Studio, outreach y voz: $INCID (los mismos)" || desvio "incidentes: $INCID_ANTES → $INCID durante el despliegue (en sombra, el monitor de outreach corre cada 10 min: mirar cuál es antes de culpar a la ola)"
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
    echo "12. smokes NO corridos (--sin-smoke): después, bash /root/deploy-m1-backend.sh $ESPERADO $BK --solo-smoke"
  else
    correr_smokes
  fi
else
  echo "12. smokes NO corridos: hay $DESVIOS desvíos antes (aclararlos primero)"
fi
echo "   registro de esta ejecución: $LOG"
echo "   VIGILANCIA (15 min, RUNBOOK_M1.md §4): supervisorctl status (los $RUN0 en RUNNING) · 5xx en las últimas 2 000 líneas del access.log de nginx · failed_jobs · $TABLA_NUEVA vacía y la cadena sin /2 · ultron:prompt-trace «off legacy prompts_off» · incidentes · ERROR nuevos; y el próximo turno canario de ULTRON sale (sin sello de prompts: n8n sigue en $([ -n "$N8N" ] && echo "$N8N" || echo "?"))"
vuelta_atras
if [ "$DESVIOS" = 0 ]; then echo "DESPLIEGUE M-1 (BACKEND) COMPLETADO"; else echo "DESPLIEGUE M-1 (BACKEND) CON $DESVIOS DESVÍOS: aclararlos antes de dar la ola por buena; si un smoke falla, la vuelta atrás de arriba y después el diagnóstico"; exit 1; fi
