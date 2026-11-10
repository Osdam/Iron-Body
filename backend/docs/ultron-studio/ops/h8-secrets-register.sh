#!/bin/bash
# Hito 8 · registro en ElevenLabs de los dos secretos propios y del webhook post-llamada (contrato H8 D8-5, D8-16,
# E1-12, E2-2, E11-6; plan, «Hito 8», «Registrar en ElevenLabs los dos secretos propios… sin imprimirlos»). Verificado
# en la referencia oficial el 09-11-2026 (OPS8-c):
#   GET   /v1/convai/secrets?search=&page_size=&cursor=   → {secrets: [{type: stored, secret_id, name, used_by}], next_cursor}
#   POST  /v1/convai/secrets          {type: new, name, value}       → {type: stored, secret_id, name}
#   PATCH /v1/convai/secrets/{id}     {type: update, name, value}    → {type: stored, secret_id, name}
#   GET   /v1/workspace/webhooks                                     → {webhooks: [{name, webhook_id, webhook_url, is_disabled,
#                                                                       is_auto_disabled, auth_type, …}]}
#   POST  /v1/workspace/webhooks      {settings: {auth_type: hmac, name, webhook_url}} → {webhook_id, webhook_secret}
# El secreto HMAC del webhook lo GENERA ElevenLabs al crearlo («Securely storing the shared secret generated upon creation
# of the webhook», post-call-webhooks): por eso este script SUSTITUYE ELEVENLABS_WEBHOOK_SECRET con el que devuelve la
# creación (el de E1-12 nunca se registró en ningún sitio). El agente lo usa por agente
# (platform_settings.workspace_overrides.webhooks.post_call_webhook_id = ELEVENLABS_POST_CALL_WEBHOOK_REF, que compila
# ElevenLabsAgentMapper); este script no toca la configuración del workspace ni ningún agente.
#
#   bash /root/h8-secrets-register.sh --solo-comprobar        solo GET: qué existe en ElevenLabs y qué haría (no escribe nada)
#   bash /root/h8-secrets-register.sh [opciones]               registra lo que falte y escribe las referencias
# Opciones:
#   --clave-fichero=<f>   una clave TEMPORAL de ElevenLabs en un fichero 600 de root (se destruye al terminar), si la de
#                         producción no tiene permiso para crear secretos o webhooks (convai_write, webhooks_write). Se
#                         recomienda una clave de usuario con caducidad de 15 min y solo esos permisos (api-keys).
#   --actualizar          si un secreto con su nombre ya existe con otro id que el del entorno: sube el valor del entorno
#                         a ESE id (PATCH; los agentes que lo citan siguen valiendo). Tras rotar con el-verificar.sh (E11-6).
#   --reusar              si ya existe (secreto o webhook): escribe su id sin tocar su valor. Solo si se sabe que su valor
#                         es el del entorno (p. ej. lo registró una ejecución anterior que se cortó antes de escribir).
#   --webhook-consola     el webhook se creó a mano en la consola con el nombre de abajo: lee su secreto con `read -rs`
#                         (sin eco, nunca por argumentos ni por el chat) y lo escribe con su id (que se busca con un GET).
# Nombres fijos en ElevenLabs: los secretos «ironbody-voz-herramientas» (valor «Bearer <ELEVENLABS_TOOL_SECRET>», la
# cabecera Authorization de las herramientas, VoiceToolAuth) e «ironbody-voz-iniciacion» (valor
# ELEVENLABS_INITIATION_SECRET, la cabecera X-Voice-Initiation-Secret, VoiceInitiationAuth); el webhook
# «ironbody-voz-post-llamada» → <VOICE_WEBHOOK_BASE_URL>/api/webhooks/voice/elevenlabs/post-call.
#
# Se ejecuta EN EL SERVIDOR como root y SOLO con la autorización escrita de la fase 2 (crea recursos en la cuenta real).
# Orden: comprobaciones (fichero de entorno 600/640 de C-14, cerrojo del mando, la región) → copia previa 600 en
# /root/env-pre-voice-secretos-<fecha> → si falta ELEVENLABS_INITIATION_SECRET, se genera (openssl rand -hex 32) y se escribe
# ANTES de registrarlo → las peticiones, en un proceso PHP de www-data con la extensión curl (la clave, en una cabecera en
# memoria; nunca en la línea de órdenes) → las referencias (y el secreto del webhook creado) al fichero de entorno en una
# escritura atómica que conserva dueño y modo → parsea → config:cache como www-data → queue:restart → workers y 401.
# Nunca imprime un valor de un secreto ni la respuesta cruda del proveedor; sí los ids (no son secretos, config/voice.php).
# Nunca BORRA nada en ElevenLabs. Códigos: 0 todo registrado; 3 falta una decisión o una pieza (dice cuál; lo que se pudo
# hacer, hecho); 1 error; 2 uso o condición previa (no se ha tocado nada).
# VUELTA ATRÁS: el fichero de entorno, con la orden que imprime (install de la copia previa + caché). Lo creado en
# ElevenLabs se queda (no se borra desde aquí): si hay que quitarlo, lo borra el propietario en la consola (Agents →
# Settings → Secrets / Webhooks), sabiendo que un agente que lo cite deja de autenticar.
set -euo pipefail
export LC_ALL=C
umask 077

# ── Lo propio de producción (el banco sustituye estas líneas y ninguna otra) ──────────────────────────────────────────
APPDIR=/var/www/api/backend
API=https://api.ironbodyneiva.cloud/api
WWW=www-data
ROOT_U=root
ROOT_G=root
COPIAS=/root
CERROJO=/run/lock/h8-voice-scope.lock
EL_BASE_DEFAULT=https://api.elevenlabs.io
EL_BASE_EU=https://api.eu.residency.elevenlabs.io

N_HERRAMIENTAS=ironbody-voz-herramientas
N_INICIACION=ironbody-voz-iniciacion
N_WEBHOOK=ironbody-voz-post-llamada
RUTA_POST_LLAMADA=/api/webhooks/voice/elevenlabs/post-call

aborta() { echo "ABORTA: $*"; exit 1; }
niega() { echo "NO SE APLICA (no se ha tocado nada): $*"; exit 2; }
DESVIOS=0
desvio() { echo "   ✗ $*"; DESVIOS=$((DESVIOS + 1)); }

MODO=aplicar; POLITICA=ninguna; CLAVE_FICHERO=""; CONSOLA=0
for a in "$@"; do
  case "$a" in
    --solo-comprobar) MODO=comprobar ;;
    --clave-fichero=*) CLAVE_FICHERO=${a#*=} ;;
    --actualizar) [ "$POLITICA" = ninguna ] || niega "--actualizar y --reusar son excluyentes"; POLITICA=actualizar ;;
    --reusar) [ "$POLITICA" = ninguna ] || niega "--actualizar y --reusar son excluyentes"; POLITICA=reusar ;;
    --webhook-consola) CONSOLA=1 ;;
    *) niega "argumento desconocido: $a (ver la cabecera)" ;;
  esac
done
[ "$(id -u)" = 0 ] || { echo "se ejecuta como root en el servidor"; exit 2; }
cd "$APPDIR"
ENVF="$APPDIR/.env"
[ -f "$ENVF" ] && [ ! -L "$ENVF" ] || niega "el fichero de entorno no es un fichero normal"
{ [ "$(stat -c '%U %a' "$ENVF")" = "$WWW 600" ] || [ "$(stat -c '%U:%G %a' "$ENVF")" = "$ROOT_U:$WWW 640" ]; } \
  || niega "el fichero de entorno no es de $WWW en 600 ni de $ROOT_U:$WWW en 640 (C-14): es $(stat -c '%U:%G %a' "$ENVF")"
if [ -n "$CLAVE_FICHERO" ]; then
  [ -f "$CLAVE_FICHERO" ] && [ ! -L "$CLAVE_FICHERO" ] && [ -s "$CLAVE_FICHERO" ] || niega "$CLAVE_FICHERO no es un fichero normal con contenido"
  [ "$(stat -c '%U %a' "$CLAVE_FICHERO")" = "$ROOT_U 600" ] || niega "$CLAVE_FICHERO tiene que ser de root y 600"
fi
[ "$CONSOLA" = 0 ] || [ "$MODO" = aplicar ] || niega "--webhook-consola no va con --solo-comprobar"
[ "$CONSOLA" = 0 ] || [ -t 0 ] || niega "--webhook-consola necesita un terminal (el secreto se teclea o se pega sin eco)"
exec 9> "$CERROJO"
flock -n 9 || niega "otro mando de la voz está en curso ($CERROJO)"

# ── Estado de partida, sin valores (como www-data, del fichero de entorno; nunca un secreto) ──────────────────────────
PRES=$(sudo -u "$WWW" php -r 'require "vendor/autoload.php"; try { $e = Dotenv\Dotenv::createArrayBacked(".")->load(); } catch (Throwable $x) { echo "ILEGIBLE", PHP_EOL; exit(0); } $h = fn ($k) => trim((string) ($e[$k] ?? "")) !== "" ? "si" : "no"; $r = strtolower(trim((string) ($e["ELEVENLABS_REGION"] ?? "default"))); echo "clave=", $h("ELEVENLABS_API_KEY"), " herramientas=", $h("ELEVENLABS_TOOL_SECRET"), " iniciacion=", $h("ELEVENLABS_INITIATION_SECRET"), " webhook=", $h("ELEVENLABS_WEBHOOK_SECRET"), " base=", preg_match("~^https://[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+(:[0-9]{1,5})?$~", trim((string) ($e["VOICE_WEBHOOK_BASE_URL"] ?? ""))) === 1 ? "si" : "no", " region=", in_array($r, ["", "default", "eu"], true) ? ($r === "" ? "default" : $r) : "otra", PHP_EOL;' 2>/dev/null || true)
[[ "$PRES" =~ ^clave=(si|no)\ herramientas=(si|no)\ iniciacion=(si|no)\ webhook=(si|no)\ base=(si|no)\ region=(default|eu|otra)$ ]] \
  || aborta "no se pudo leer el fichero de entorno como $WWW (no parsea; su salida no se imprime)"
echo "== h8-secrets-register.sh · $(date -u +%Y-%m-%dT%H:%M:%SZ) · $MODO · política $POLITICA$([ -n "$CLAVE_FICHERO" ] && echo ' · clave temporal de fichero')$([ "$CONSOLA" = 1 ] && echo ' · webhook de la consola')"
echo "   presencia en el entorno (sin valores): $PRES"
case "$PRES" in *region=otra*) niega "ELEVENLABS_REGION no es default ni eu" ;; esac
case "$PRES" in *region=eu*) BASE=$EL_BASE_EU ;; *) BASE=$EL_BASE_DEFAULT ;; esac
[ -n "$CLAVE_FICHERO" ] || case "$PRES" in clave=no*) niega "no hay ELEVENLABS_API_KEY en el entorno ni --clave-fichero" ;; esac
case "$PRES" in *herramientas=no*) niega "falta ELEVENLABS_TOOL_SECRET (lo genera el-verificar.sh; E1-12): este script no lo inventa" ;; esac

# ── Escritor atómico del fichero de entorno (el del mando), como función de python que usan las dos escrituras ──────
# Sustituye cada clave con la sangría que tuviera (o la añade con dos espacios), temporal en el mismo directorio
# (O_EXCL, O_NOFOLLOW, 600) con el dueño y el modo del original, fsync y os.replace. Una clave repetida aborta. Los
# valores llegan en memoria (nunca por argumentos) y nunca se imprimen: solo «sustituida» o «añadida».
read -r -d '' PY_ESCRITOR <<'PY' || true
import json, os, re, stat, sys
def escribir_env(p, cambios):
    fd = os.open(p, os.O_RDONLY | os.O_NOFOLLOW)
    st = os.fstat(fd)
    with os.fdopen(fd, encoding="utf-8") as fh:
        s = fh.read()
    for k, v in cambios.items():
        if not re.fullmatch(r"[A-Z][A-Z0-9_]{1,63}", k) or not isinstance(v, str) or v == "" or re.search(r"[\s#\"'\\$`]", v):
            raise SystemExit("clave o valor fuera de forma (no se imprime)")
        pat = re.compile(r"^([ \t]*)(?:export[ \t]+)?" + re.escape(k) + r"[ \t]*=.*$", re.M)
        n = len(pat.findall(s))
        if n > 1:
            raise SystemExit(k + " repetida en el fichero de entorno: deduplicar a mano")
        if n == 1:
            s = pat.sub(lambda m: m.group(1) + k + "=" + v, s, count=1)
            print("   " + k + " sustituida")
        else:
            if s and not s.endswith("\n"):
                s += "\n"
            s += "  " + k + "=" + v + "\n"
            print("   " + k + " añadida")
    d = os.path.dirname(p)
    tmp = os.path.join(d, ".env.h8-secretos-" + str(os.getpid()))
    tfd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(tfd, "w", encoding="utf-8") as fh:
        fh.write(s)
        fh.flush()
        os.fchown(fh.fileno(), st.st_uid, st.st_gid)
        os.fchmod(fh.fileno(), stat.S_IMODE(st.st_mode))
        os.fsync(fh.fileno())
    os.replace(tmp, p)
    dfd = os.open(d, os.O_RDONLY)
    os.fsync(dfd)
    os.close(dfd)
PY
escribir() { ENVF="$ENVF" python3 -I -c "$PY_ESCRITOR"$'\n''escribir_env(os.environ["ENVF"], json.load(sys.stdin))'; }
parsea() { sudo -u "$WWW" php -r 'require "vendor/autoload.php"; try { Dotenv\Dotenv::createArrayBacked(".")->load(); } catch (Throwable $e) { exit(1); }' > /dev/null 2>&1; }

COPIA=""; VUELTA=""
if [ "$MODO" = aplicar ]; then
  DUENO=$(stat -c '%U:%G' "$ENVF"); MODOF=$(stat -c '%a' "$ENVF")
  COPIA="$COPIAS/env-pre-voice-secretos-$(date -u +%Y%m%d-%H%M%S)"
  [ ! -e "$COPIA" ] || aborta "ya existe $COPIA: nunca se sobrescribe una copia"
  install -m 600 -o "$ROOT_U" -g "$ROOT_G" "$ENVF" "$COPIA"
  VUELTA="install -m $MODOF -o ${DUENO%%:*} -g ${DUENO##*:} $COPIA $ENVF && cd $APPDIR && sudo -u $WWW sh -c 'umask 077; php artisan config:cache -q' && sudo -u $WWW chmod 600 bootstrap/cache/config.php && sudo -u $WWW php artisan queue:restart -q"
  echo "0. copia previa del fichero de entorno: $COPIA (600, $ROOT_U)"
  case "$PRES" in
    *iniciacion=no*)
      # El de la iniciación se genera AQUÍ y se escribe ANTES de registrarlo: el entorno siempre tiene el valor que
      # tendrá ElevenLabs. 32 bytes aleatorios en hex; nunca sale de este proceso salvo hacia el fichero.
      printf '{"ELEVENLABS_INITIATION_SECRET": "%s"}' "$(openssl rand -hex 32)" | escribir || aborta "no se pudo escribir el secreto de iniciación. Vuelta: $VUELTA"
      parsea || aborta "el fichero de entorno NO parsea tras escribir el secreto de iniciación. Vuelta: $VUELTA"
      echo "   ELEVENLABS_INITIATION_SECRET generado aquí (openssl rand -hex 32) y escrito; no se imprime" ;;
  esac
fi

SECRETO_WH=""
if [ "$CONSOLA" = 1 ]; then
  echo "   pega el secreto del webhook «$N_WEBHOOK» que enseñó la consola de ElevenLabs al crearlo (no se ve ni queda en el historial) y pulsa Intro:"
  IFS= read -rs SECRETO_WH || true
  [[ "$SECRETO_WH" =~ ^[A-Za-z0-9_-]{16,256}$ ]] || { SECRETO_WH=""; aborta "el secreto pegado no tiene la forma esperada (16–256 de [A-Za-z0-9_-]; no se imprime). Vuelta: $VUELTA"; }
fi

# ── Las peticiones (www-data, PHP con curl; la clave solo en memoria). Salida: UNA línea JSON hacia el escritor ────────
read -r -d '' PHP_REGISTRO <<'PHP' || true
ini_set("display_errors", "stderr");
[$_, $modo, $baseDef, $baseEu, $politica, $deStdin, $consola, $nH, $nI, $nW, $rutaPC] = $argv;
require "vendor/autoload.php";
$e = Dotenv\Dotenv::createArrayBacked(".")->load();
$g = fn (string $k): string => trim((string) ($e[$k] ?? ""));
$base = strtolower($g("ELEVENLABS_REGION")) === "eu" ? $baseEu : $baseDef;
$clave = $deStdin === "1" ? trim((string) fgets(STDIN)) : $g("ELEVENLABS_API_KEY");
$out = ["registro" => $modo, "errors" => [], "secrets" => [], "webhook" => null, "webhook_secret" => null];
$fin = function () use (&$out) { echo json_encode($out, JSON_UNESCAPED_SLASHES), PHP_EOL; exit(0); };
if ($clave === "" || !function_exists("curl_init")) { $out["errors"][] = $clave === "" ? "sin_clave" : "sin_extension_curl"; $fin(); }
$http = function (string $m, string $ruta, ?array $cuerpo = null) use ($base, $clave): array {
    $c = curl_init($base . $ruta);
    $h = ["xi-api-key: " . $clave, "Accept: application/json"];
    if ($cuerpo !== null) { $h[] = "Content-Type: application/json"; curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($cuerpo, JSON_UNESCAPED_SLASHES)); }
    curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_HTTPHEADER => $h]);
    $r = curl_exec($c);
    $st = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $err = curl_errno($c);
    curl_close($c);
    return [$err !== 0 ? 0 : $st, $err !== 0 ? null : json_decode((string) $r, true)];
};
$id = fn ($v): ?string => is_string($v) && preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $v) === 1 ? $v : null;
// Los secretos que ya existen con nuestros nombres (prefijo común), página a página; si la lista falla, NO se crea nada
// (no se sabría si se duplica).
$existentes = []; $cursor = null; $paginas = 0; $listaOk = true;
do {
    [$st, $j] = $http("GET", "/v1/convai/secrets?search=" . rawurlencode("ironbody-voz-") . "&page_size=100" . ($cursor !== null ? "&cursor=" . rawurlencode($cursor) : ""));
    if ($st !== 200 || !is_array($j) || !is_array($j["secrets"] ?? null)) { $out["errors"][] = "secrets_list_http_" . $st; $listaOk = false; break; }
    foreach ($j["secrets"] as $s) {
        if (is_array($s) && is_string($s["name"] ?? null) && ($i = $id($s["secret_id"] ?? null)) !== null) { $existentes[$s["name"]][] = $i; }
    }
    $cursor = is_string($j["next_cursor"] ?? null) && $j["next_cursor"] !== "" ? $j["next_cursor"] : null;
} while ($cursor !== null && ++$paginas < 10);
$secretos = [
    "tool" => [$nH, $g("ELEVENLABS_TOOL_SECRET") !== "" ? "Bearer " . $g("ELEVENLABS_TOOL_SECRET") : "", "ELEVENLABS_TOOL_SECRET_REF"],
    "initiation" => [$nI, $g("ELEVENLABS_INITIATION_SECRET"), "ELEVENLABS_INITIATION_SECRET_REF"],
];
foreach ($secretos as $tipo => [$nombre, $valor, $refKey]) {
    $r = ["name" => $nombre, "id" => null, "action" => null, "http" => null, "env" => $refKey];
    $ref = $g($refKey);
    if (!$listaOk) { $r["action"] = "no_legible"; }
    elseif ($valor === "") { $r["action"] = $modo === "comprobar" ? "se_generaria_y_crearia" : "falta_en_entorno"; }
    elseif (count($existentes[$nombre] ?? []) > 1) { $r["action"] = "duplicado"; }
    elseif (count($existentes[$nombre] ?? []) === 1) {
        $r["id"] = $existentes[$nombre][0];
        if ($politica === "actualizar") {
            if ($modo === "aplicar") {
                [$st, $j] = $http("PATCH", "/v1/convai/secrets/" . rawurlencode($r["id"]), ["type" => "update", "name" => $nombre, "value" => $valor]);
                $r["http"] = $st;
                $r["action"] = $st === 200 && $id($j["secret_id"] ?? null) === $r["id"] ? "actualizado" : "fallo_actualizar";
            } else { $r["action"] = "se_actualizaria"; }
        } elseif ($ref === $r["id"]) { $r["action"] = "registrado"; }
        elseif ($politica === "reusar") { $r["action"] = $modo === "aplicar" ? "reusado" : "se_reusaria"; }
        else { $r["action"] = "existe_sin_decidir"; }
    } else {
        if ($modo === "aplicar") {
            [$st, $j] = $http("POST", "/v1/convai/secrets", ["type" => "new", "name" => $nombre, "value" => $valor]);
            $r["http"] = $st;
            $r["id"] = $st === 200 ? $id($j["secret_id"] ?? null) : null;
            $r["action"] = $r["id"] !== null ? "creado" : ($st === 401 || $st === 403 ? "sin_permiso" : "fallo_crear");
        } else { $r["action"] = "se_crearia"; }
    }
    $out["secrets"][$tipo] = $r;
}
unset($valor, $secretos);
// El webhook post-llamada del workspace (HMAC): por su nombre; su URL tiene que ser la de la base canónica.
$w = ["name" => $nW, "id" => null, "action" => null, "http" => null, "url_ok" => null, "disabled" => null, "auto_disabled" => null, "auth_type" => null];
$baseWh = $g("VOICE_WEBHOOK_BASE_URL");
if (preg_match('~^https://[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+(:[0-9]{1,5})?$~', $baseWh) !== 1) { $w["action"] = "falta_base"; }
else {
    $url = $baseWh . $rutaPC;
    [$st, $j] = $http("GET", "/v1/workspace/webhooks");
    $w["http"] = $st;
    if ($st !== 200 || !is_array($j) || !is_array($j["webhooks"] ?? null)) { $w["action"] = $st === 401 || $st === 403 ? "sin_permiso_lectura" : "no_legible"; }
    else {
        $mios = array_values(array_filter($j["webhooks"], fn ($x) => is_array($x) && ($x["name"] ?? null) === $nW));
        if (count($mios) > 1) { $w["action"] = "duplicado"; }
        elseif (count($mios) === 1) {
            $x = $mios[0];
            $w["id"] = $id($x["webhook_id"] ?? null);
            $w["url_ok"] = ($x["webhook_url"] ?? null) === $url;
            $w["disabled"] = ($x["is_disabled"] ?? null) === true;
            $w["auto_disabled"] = ($x["is_auto_disabled"] ?? null) === true;
            $w["auth_type"] = is_string($x["auth_type"] ?? null) ? $x["auth_type"] : null;
            $ref = $g("ELEVENLABS_POST_CALL_WEBHOOK_REF");
            if ($w["id"] === null) { $w["action"] = "no_legible"; }
            elseif ($consola === "1") { $w["action"] = "consola"; }
            elseif ($ref === $w["id"]) { $w["action"] = "registrado"; }
            elseif ($politica === "reusar") { $w["action"] = $modo === "aplicar" ? "reusado" : "se_reusaria"; }
            else { $w["action"] = "existe_sin_decidir"; }
        } elseif ($consola === "1") { $w["action"] = "consola_sin_webhook"; }
        elseif ($modo === "aplicar") {
            [$st, $j] = $http("POST", "/v1/workspace/webhooks", ["settings" => ["auth_type" => "hmac", "name" => $nW, "webhook_url" => $url]]);
            $w["http"] = $st;
            $w["id"] = $st === 200 ? $id($j["webhook_id"] ?? null) : null;
            $w["url_ok"] = true;
            if ($w["id"] === null) { $w["action"] = $st === 401 || $st === 403 ? "sin_permiso" : "fallo_crear"; }
            else {
                $sec = $j["webhook_secret"] ?? null;
                $out["webhook_secret"] = is_string($sec) && preg_match('/^[A-Za-z0-9_-]{16,256}$/D', $sec) === 1 ? $sec : null;
                $w["action"] = $out["webhook_secret"] !== null ? "creado" : "creado_sin_secreto";
            }
        } else { $w["action"] = "se_crearia"; }
    }
}
$out["webhook"] = $w;
$fin();
PHP

# La clave (de fichero o del entorno) llega a PHP por la entrada estándar o la lee PHP del fichero de entorno: nunca por
# argumentos. La salida de PHP va DIRECTA al escritor (python como root): el secreto del webhook creado no pasa por el
# terminal. El escritor imprime un resumen sin secretos y devuelve 0 (todo registrado), 3 (falta algo) o 1 (error).
DE_STDIN=0; [ -z "$CLAVE_FICHERO" ] || DE_STDIN=1
fuente_clave() { if [ -n "$CLAVE_FICHERO" ]; then cat -- "$CLAVE_FICHERO"; else :; fi; }
RC=0
fuente_clave | sudo -u "$WWW" php -r "$PHP_REGISTRO" -- "$MODO" "$EL_BASE_DEFAULT" "$EL_BASE_EU" "$POLITICA" "$DE_STDIN" "$CONSOLA" "$N_HERRAMIENTAS" "$N_INICIACION" "$N_WEBHOOK" "$RUTA_POST_LLAMADA" 2>/dev/null \
  | ENVF="$ENVF" MODO="$MODO" SECRETO_WH="$SECRETO_WH" python3 -I -c "$PY_ESCRITOR"$'\n''
linea = sys.stdin.readline()
try:
    d = json.loads(linea)
    assert isinstance(d, dict) and "registro" in d
except Exception:
    print("   la salida del proceso de registro no es legible (no se imprime)")
    sys.exit(1)
del linea
modo = os.environ["MODO"]
TEXTO = {
    "registrado": "ya registrado (el id del entorno es el de ElevenLabs; su VALOR no se puede leer: si el secreto del entorno cambió después, repetir con --actualizar)",
    "creado": "CREADO", "actualizado": "ACTUALIZADO (mismo id, valor del entorno)", "reusado": "REUSADO (id escrito, valor sin tocar)",
    "se_crearia": "no existe: se crearía", "se_generaria_y_crearia": "falta en el entorno: se generaría y se crearía",
    "se_actualizaria": "existe: se actualizaría su valor", "se_reusaria": "existe: se reusaría su id",
    "existe_sin_decidir": "EXISTE con otro id que el del entorno: FALTA DECIDIR --actualizar (sube el valor del entorno a ese id) o --reusar (escribe su id sin tocar el valor)",
    "duplicado": "HAY VARIOS con ese nombre: aclararlo en la consola antes (este script no elige ni borra)",
    "sin_permiso": "la clave no tiene permiso (401/403): repetir con --clave-fichero (convai_write, webhooks_write) o por la consola",
    "sin_permiso_lectura": "la clave no puede leer los webhooks (401/403): repetir con --clave-fichero o por la consola",
    "fallo_crear": "la creación falló (ver el código HTTP); nada escrito para él", "fallo_actualizar": "la actualización falló (ver el código HTTP)",
    "no_legible": "la lista del proveedor no se pudo leer: no se crea nada a ciegas",
    "falta_en_entorno": "falta el secreto en el entorno", "falta_base": "falta VOICE_WEBHOOK_BASE_URL (bash h8-voice-scope.sh fase 2 set VOICE_WEBHOOK_BASE_URL=https://…) y repetir: el webhook se crea en la siguiente pasada",
    "creado_sin_secreto": "CREADO, pero ElevenLabs no devolvió su secreto: leerlo en la consola y repetir con --webhook-consola",
    "consola": "creado en la consola: se escribe su id y el secreto tecleado", "consola_sin_webhook": "no hay un webhook con ese nombre: crearlo antes en la consola con ese nombre exacto",
}
OK = {"registrado", "creado", "actualizado", "reusado", "consola"}
for e in d.get("errors", []):
    print("   ERROR: " + str(e)[:60])
cambios = {}
pendiente = False
for tipo in ("tool", "initiation"):
    s = d.get("secrets", {}).get(tipo) or {}
    a = s.get("action")
    print("   secreto %-26s %s%s%s" % (s.get("name", "?"), TEXTO.get(a, str(a)), (" · id " + s["id"]) if s.get("id") else "", (" · HTTP %s" % s["http"]) if s.get("http") else ""))
    if a in OK and s.get("id"):
        cambios[s["env"]] = s["id"]
    else:
        pendiente = True
w = d.get("webhook") or {}
a = w.get("action")
detalle = []
if w.get("url_ok") is False:
    detalle.append("su URL NO es la de VOICE_WEBHOOK_BASE_URL + la ruta de la post-llamada")
if w.get("auth_type") not in (None, "hmac"):
    detalle.append("auth_type " + str(w.get("auth_type")))
if w.get("disabled") or w.get("auto_disabled"):
    detalle.append("DESACTIVADO" + (" (automático: 10 fallos seguidos)" if w.get("auto_disabled") else ""))
print("   webhook %-26s %s%s%s%s" % (w.get("name", "?"), TEXTO.get(a, str(a)), (" · id " + w["id"]) if w.get("id") else "", (" · HTTP %s" % w["http"]) if w.get("http") else "", (" · " + "; ".join(detalle)) if detalle else ""))
if a in OK and w.get("id") and not detalle:
    cambios["ELEVENLABS_POST_CALL_WEBHOOK_REF"] = w["id"]
    if a == "creado":
        cambios["ELEVENLABS_WEBHOOK_SECRET"] = d.get("webhook_secret")
    if a == "consola":
        cambios["ELEVENLABS_WEBHOOK_SECRET"] = os.environ.get("SECRETO_WH") or None
else:
    pendiente = True
cambios = {k: v for k, v in cambios.items() if v}
d.pop("webhook_secret", None)
if modo == "aplicar" and cambios:
    try:
        escribir_env(os.environ["ENVF"], cambios)
    except (SystemExit, OSError) as e:
        print("   ERROR: no se pudo escribir el fichero de entorno (%s)" % (str(e)[:80] if isinstance(e, SystemExit) else type(e).__name__))
        if "ELEVENLABS_WEBHOOK_SECRET" in cambios and w.get("action") == "creado":
            print("   ATENCIÓN: el webhook quedó CREADO en ElevenLabs con un secreto que no se pudo guardar: borrarlo en la consola (Agents → Settings → Webhooks) y repetir")
        sys.exit(1)
sys.exit(1 if d.get("errors") and not cambios else (3 if pendiente else 0))' || RC=$?
SECRETO_WH=""; unset SECRETO_WH
[ -z "$CLAVE_FICHERO" ] || { shred -u "$CLAVE_FICHERO" 2>/dev/null || rm -f -- "$CLAVE_FICHERO"; echo "   $CLAVE_FICHERO destruido (la clave temporal no queda en disco; revocarla en la consola o dejar que caduque)"; }

if [ "$MODO" = comprobar ]; then
  case "$RC" in
    0) echo "SECRETOS Y WEBHOOK: TODO REGISTRADO (solo GET; no se ha tocado nada)" ;;
    3) echo "SECRETOS Y WEBHOOK: FALTA ALGO (arriba, qué; solo GET; no se ha tocado nada)" ;;
    *) echo "SECRETOS Y WEBHOOK: NO SE PUDO COMPROBAR (código $RC; no se ha tocado nada)" ;;
  esac
  exit "$RC"
fi

# ── Tras escribir: parsea, caché, workers, 401 ───────────────────────────────────────────────────────────────────────
[ "$RC" = 0 ] || [ "$RC" = 3 ] || echo "   (el registro salió con $RC: se comprueba igual lo que haya quedado escrito)"
parsea || aborta "el fichero de entorno NO parsea tras escribir. Vuelta: $VUELTA"
sudo -u "$WWW" sh -c 'umask 077; php artisan config:cache -q' || aborta "config:cache falló. Vuelta: $VUELTA"
C=bootstrap/cache/config.php
[ -f "$C" ] && [ ! -L "$C" ] && [ "$(stat -c '%U' "$C")" = "$WWW" ] || aborta "$C no es un fichero normal de $WWW. Vuelta: $VUELTA"
[ "$(stat -c '%a' "$C")" = 600 ] || sudo -u "$WWW" chmod 600 "$C" || aborta "$WWW no pudo dejar $C en 600. Vuelta: $VUELTA"
echo "1. el fichero parsea · caché $(stat -c '%U %a' "$C") · fichero de entorno $(stat -c '%U:%G %a' "$ENVF")"
RUN0=$(supervisorctl status 2>/dev/null | grep -c RUNNING || true)
sudo -u "$WWW" php artisan queue:restart -q || desvio "queue:restart no salió con 0"
sleep 6
s=$(supervisorctl status 2>/dev/null || true)
r=$(printf '%s\n' "$s" | grep -c RUNNING || true); m=$(printf '%s\n' "$s" | grep -cE 'BACKOFF|FATAL' || true)
echo "2. workers: $r RUNNING (antes $RUN0) · $m BACKOFF/FATAL"; [ "$r" = "$RUN0" ] && [ "$m" = 0 ] || desvio "workers $r/$m (esperado $RUN0/0)"
c=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Accept: application/json' -H 'Content-Type: application/json' --data '{}' "$API/webhooks/voice/elevenlabs/post-call" || true)
echo "3. post-llamada sin firma → $c (esperado 401)"; [ "$c" = 401 ] || desvio "la post-llamada sin firma respondió $c"
REFS=$(sudo -u "$WWW" php artisan tinker --execute='$e = "voice.elevenlabs."; echo json_encode(["tool_ref" => config($e."tool_secret_ref"), "initiation_ref" => config($e."initiation_secret_ref"), "post_call_ref" => config($e."post_call_webhook_ref"), "initiation_secret" => filled(config($e."initiation_secret")), "webhook_secret" => filled(config($e."webhook_secret"))]), PHP_EOL;' 2>&1 | tail -1 || true)
case "$REFS" in '{"tool_ref"'*) echo "4. configuración efectiva (ids y presencia, sin valores): $REFS" ;; *) desvio "la configuración efectiva no se pudo leer (tinker; su salida no se imprime)" ;; esac
echo "   AVISO: la huella de voz del Studio cambió: reconocer otra vez la configuración autorizada (Studio → Integraciones → Voz)"
echo "   AVISO: ElevenLabs no usa el webhook ni los secretos hasta que un agente los cite (la publicación y el laboratorio compilan las referencias)"
echo "   vuelta atrás del fichero: $VUELTA"
echo "   lo creado en ElevenLabs se queda: si hay que quitarlo, el propietario en la consola (Agents → Settings)"
if [ "$DESVIOS" != 0 ]; then echo "REGISTRO CON $DESVIOS DESVÍOS (copia previa: $COPIA)"; exit 1; fi
case "$RC" in
  0) echo "SECRETOS Y WEBHOOK REGISTRADOS (copia previa: $COPIA)" ;;
  3) echo "REGISTRO PARCIAL: falta lo indicado arriba (lo hecho, hecho y escrito; copia previa: $COPIA)"; exit 3 ;;
  *) echo "REGISTRO CON ERRORES (código $RC; copia previa: $COPIA)"; exit 1 ;;
esac
