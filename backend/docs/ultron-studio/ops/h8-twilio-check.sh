#!/bin/bash
# Hito 8 · comprobación de la cuenta de Twilio de la voz, SOLO LECTURA (contrato H8 D8-4, D8-5, D8-19, D8-21; E2-3;
# nuevo rumbo del propietario del 09-11-2026: el número empresarial importado en ElevenLabs como «Outbound Only»). Con la
# key RESTRINGIDA de Laravel que el propietario pone en el entorno (TWILIO_VOICE_ACCOUNT_SID, TWILIO_VOICE_API_KEY_SID y
# TWILIO_VOICE_API_KEY_SECRET; «h8-voice-scope.sh fase 2 secreto <fichero>»), solo GET:
#   GET /2010-04-01/Accounts/{sid}.json                       la cuenta: activa, madre o subcuenta (owner_account_sid)
#   GET /2010-04-01/Accounts/{sid}/IncomingPhoneNumbers.json  si TWILIO_VOICE_FROM es un número COMPRADO de esa cuenta:
#                                                             capacidad de voz, voice_url (¿choca con ElevenLabs?),
#                                                             aplicación o trunk, dirección y bundle
#   GET /2010-04-01/Accounts/{sid}/OutgoingCallerIds.json     si es un CALLER ID VERIFICADO («Outbound Only» en ElevenLabs:
#                                                             solo salientes, no se asigna a un agente)
#   GET https://numbers.twilio.com/v2/RegulatoryCompliance/Bundles/{BU}   el estado del bundle regulatorio, si lo hay
#   GET /2010-04-01/Accounts/{sid}/Messages.json?PageSize=1   SONDA: la key NO tiene que poder leer mensajes (la del OTP
#                                                             vive aparte); 401/403 es lo esperado
#   GET /2010-04-01/Accounts/{sid}/Calls.json?StartTime>=…    las llamadas de la ventana, por dirección y estado, SIN
#                                                             números: la conciliación (voice:reconcile) lista TODAS las
#                                                             de la cuenta y cada una sin su fila echa el freno
# Nunca pone un teléfono en una URL (se lista y se compara aquí; D8-17) ni imprime números, SID completos ni secretos:
# solo clases, recuentos, hosts y los cuatro últimos de lo que haga falta reconocer. Compara SIN imprimirlos el número de
# voz con el remitente del OTP (TWILIO_FROM) y con los de WhatsApp (VoiceNumberGuard), y la cuenta de voz con la del OTP
# (TWILIO_SID / TWILIO_ACCOUNT_SID). Imprime un VEREDICTO para la decisión del propietario: «misma cuenta con claves
# restringidas» frente a «subcuenta con el número trasladado», con el procedimiento oficial según el tipo de número.
#   bash /root/h8-twilio-check.sh [--dias=N]      N = días de la ventana de llamadas (1–30; 7 por defecto)
# Se ejecuta EN EL SERVIDOR como root (lee el fichero de entorno como www-data) o en el banco. Códigos: 0 apta (sin
# FALLO), 3 NO apta o falta una decisión (dice cuál), 1 error, 2 uso. No escribe nada en ningún sitio.
# Fuentes (leídas el 09-11-2026): twilio.com/docs/iam/api/subaccounts (#exchanging-numbers, autenticación y facturación),
# twilio.com/docs/phone-numbers/api/incomingphonenumber-resource, twilio.com/docs/voice/api/outgoing-caller-ids,
# twilio.com/docs/iam/api-keys/restricted-api-keys (y sus PDF de permisos), elevenlabs.io/docs/eleven-agents/phone-numbers/
# twilio-integration/native-integration y elevenlabs.io/docs/api-reference/phone-numbers/create.
set -euo pipefail
export LC_ALL=C
umask 077

# ── Lo propio de producción (el banco sustituye estas líneas y ninguna otra) ──────────────────────────────────────────
APPDIR=/var/www/api/backend
WWW=www-data
TW_API=https://api.twilio.com
TW_NUMBERS=https://numbers.twilio.com

DIAS=7
for a in "$@"; do
  case "$a" in
    --dias=*) DIAS=${a#*=}; [[ "$DIAS" =~ ^[0-9]{1,2}$ ]] && [ "$DIAS" -ge 1 ] && [ "$DIAS" -le 30 ] || { echo "--dias de 1 a 30"; exit 2; } ;;
    *) echo "uso: bash /root/h8-twilio-check.sh [--dias=N]"; exit 2 ;;
  esac
done
[ "$(id -u)" = 0 ] || { echo "se ejecuta como root en el servidor"; exit 2; }
cd "$APPDIR"

read -r -d '' PHP_CHECK <<'PHP' || true
ini_set("display_errors", "stderr");
[$_, $api, $numbers, $dias] = $argv;
require "vendor/autoload.php";
$e = Dotenv\Dotenv::createArrayBacked(".")->load();
$g = fn (string $k): string => trim((string) ($e[$k] ?? ""));
$dig = fn (string $t): string => App\Services\Telephony\VoiceNumberGuard::digits($t);
$sid = $g("TWILIO_VOICE_ACCOUNT_SID"); $ksid = $g("TWILIO_VOICE_API_KEY_SID"); $ksec = $g("TWILIO_VOICE_API_KEY_SECRET"); $from = $g("TWILIO_VOICE_FROM");
$otpSids = array_values(array_filter([$g("TWILIO_SID"), $g("TWILIO_ACCOUNT_SID")]));
$out = ["piezas" => [
    "account" => preg_match('/^AC[0-9a-f]{32}$/D', $sid) === 1,
    "account_last4" => strlen($sid) >= 4 ? substr($sid, -4) : null,
    "key_sid" => $ksid === "" ? "falta" : (str_starts_with($ksid, "SK") && preg_match('/^SK[0-9a-f]{32}$/D', $ksid) === 1 ? "SK" : (str_starts_with($ksid, "AC") ? "AC" : "otra")),
    "key_secret" => $ksec !== "",
    "from" => preg_match('/^\+[1-9][0-9]{7,14}$/D', $from) === 1,
    "from_last4" => strlen($from) >= 4 ? substr($from, -4) : null,
    "signing" => $g("TWILIO_VOICE_SIGNING_TOKEN") !== "",
], "otp" => [
    "misma_cuenta" => $sid === "" || $otpSids === [] ? null : in_array($sid, $otpSids, true),
    "key_es_la_del_otp" => $ksid !== "" && in_array($ksid, $otpSids, true),
    "mismo_numero" => $from === "" || $g("TWILIO_FROM") === "" ? null : hash_equals($dig($g("TWILIO_FROM")), $dig($from)),
], "whatsapp" => $from === "" ? null : App\Services\Telephony\VoiceNumberGuard::collides($from, $g("WHATSAPP_DISPLAY_PHONE"), $g("WHATSAPP_PROTECTED_NUMBERS") !== "" ? $g("WHATSAPP_PROTECTED_NUMBERS") : $g("WHATSAPP_DISPLAY_PHONE")),
"cuenta" => null, "numero" => null, "sms" => null, "llamadas" => null, "errors" => []];
$fin = function () use (&$out) { echo json_encode($out, JSON_UNESCAPED_SLASHES), PHP_EOL; exit(0); };
if (!$out["piezas"]["account"] || $out["piezas"]["key_sid"] !== "SK" || !$out["piezas"]["key_secret"]) { $out["errors"][] = "piezas_incompletas"; $fin(); }
if (!function_exists("curl_init")) { $out["errors"][] = "sin_extension_curl"; $fin(); }
$get = function (string $url) use ($ksid, $ksec): array {
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_HTTPGET => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_HTTPAUTH => CURLAUTH_BASIC, CURLOPT_USERPWD => $ksid . ":" . $ksec, CURLOPT_HTTPHEADER => ["Accept: application/json"]]);
    $r = curl_exec($c);
    $st = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $err = curl_errno($c);
    curl_close($c);
    return [$err !== 0 ? 0 : $st, $err !== 0 ? null : json_decode((string) $r, true)];
};
$host = fn ($u) => is_string($u) && $u !== "" ? (parse_url($u, PHP_URL_HOST) ?: "?") : null;
$cuenta = "/2010-04-01/Accounts/" . rawurlencode($sid);
// La cuenta.
[$st, $j] = $get($api . $cuenta . ".json");
$out["cuenta"] = ["http" => $st, "status" => is_array($j) ? ($j["status"] ?? null) : null, "type" => is_array($j) ? ($j["type"] ?? null) : null,
    "es_subcuenta" => is_array($j) && is_string($j["owner_account_sid"] ?? null) ? $j["owner_account_sid"] !== $sid : null,
    "madre_es_otp" => is_array($j) && is_string($j["owner_account_sid"] ?? null) && $otpSids !== [] ? in_array($j["owner_account_sid"], $otpSids, true) : null];
// El número: primero entre los comprados, después entre los caller ID verificados. Se listan y se comparan aquí.
$buscar = function (string $ruta, string $clave) use ($get, $api, $dig, $from): array {
    $url = $api . $ruta . "?PageSize=100"; $paginas = 0; $http = null;
    while ($url !== null && $paginas++ < 10) {
        [$st, $j] = $get($url);
        $http = $st;
        if ($st !== 200 || !is_array($j) || !is_array($j[$clave] ?? null)) { return [$st, null]; }
        foreach ($j[$clave] as $n) { if (is_array($n) && is_string($n["phone_number"] ?? null) && hash_equals($dig($n["phone_number"]), $dig($from))) { return [200, $n]; } }
        $url = is_string($j["next_page_uri"] ?? null) && $j["next_page_uri"] !== "" ? $api . $j["next_page_uri"] : null;
    }
    return [$http ?? 0, null];
};
[$hIn, $n] = $buscar($cuenta . "/IncomingPhoneNumbers.json", "incoming_phone_numbers");
$num = ["tipo" => null, "http_comprados" => $hIn, "http_caller_ids" => null];
if (is_array($n)) {
    $cap = is_array($n["capabilities"] ?? null) ? $n["capabilities"] : [];
    $num += ["tipo" => "comprado", "voz" => ($cap["voice"] ?? null) === true, "sms" => ($cap["sms"] ?? null) === true, "status" => $n["status"] ?? null,
        "voice_url_host" => $host($n["voice_url"] ?? null), "app" => is_string($n["voice_application_sid"] ?? null) && $n["voice_application_sid"] !== "",
        "trunk" => is_string($n["trunk_sid"] ?? null) && $n["trunk_sid"] !== "", "sms_url_host" => $host($n["sms_url"] ?? null),
        "address_requirements" => $n["address_requirements"] ?? null, "bundle" => is_string($n["bundle_sid"] ?? null) && $n["bundle_sid"] !== ""];
    $num["tipo"] = "comprado";
    if ($num["bundle"]) {
        [$sb, $b] = $get($numbers . "/v2/RegulatoryCompliance/Bundles/" . rawurlencode($n["bundle_sid"]));
        $num["bundle_http"] = $sb;
        $num["bundle_status"] = $sb === 200 && is_array($b) && is_string($b["status"] ?? null) ? $b["status"] : null;
    }
} else {
    [$hCi, $ci] = $buscar($cuenta . "/OutgoingCallerIds.json", "outgoing_caller_ids");
    $num["http_caller_ids"] = $hCi;
    $num["tipo"] = is_array($ci) ? "caller_id" : ($hIn === 200 && $hCi === 200 ? "no_encontrado" : "no_legible");
}
$out["numero"] = $num;
// La sonda de mensajería: la key de la voz no tiene que poder leer (ni, por tanto, enviar con la lista de permisos que
// pide D8-5) mensajes. 401/403 = sin permiso, lo esperado.
[$st] = $get($api . $cuenta . "/Messages.json?PageSize=1");
$out["sms"] = ["http" => $st, "denegado" => $st === 401 || $st === 403];
// Las llamadas de la ventana (sin números en la URL; StartTime>=AAAA-MM-DD, UTC), por dirección y estado.
$desde = gmdate("Y-m-d", time() - (int) $dias * 86400);
$url = $api . $cuenta . "/Calls.json?" . rawurlencode("StartTime>") . "=" . $desde . "&PageSize=100";
$ll = ["http" => null, "dias" => (int) $dias, "desde" => $desde, "total" => 0, "con_el_numero" => 0, "ajenas" => 0, "por_direccion" => [], "por_estado" => [], "paginas" => 0, "truncado" => false];
while ($url !== null) {
    if ($ll["paginas"] >= 5) { $ll["truncado"] = true; break; }
    [$st, $j] = $get($url);
    $ll["http"] = $st; $ll["paginas"]++;
    if ($st !== 200 || !is_array($j) || !is_array($j["calls"] ?? null)) { break; }
    foreach ($j["calls"] as $c) {
        if (!is_array($c)) { continue; }
        $ll["total"]++;
        $d = preg_replace('/[^a-z-]/', '', (string) ($c["direction"] ?? "?")) ?: "?"; $s = preg_replace('/[^a-z-]/', '', (string) ($c["status"] ?? "?")) ?: "?";
        $ll["por_direccion"][$d] = ($ll["por_direccion"][$d] ?? 0) + 1; $ll["por_estado"][$s] = ($ll["por_estado"][$s] ?? 0) + 1;
        $mio = $from !== "" && ((is_string($c["from"] ?? null) && hash_equals($dig($c["from"]), $dig($from))) || (is_string($c["to"] ?? null) && hash_equals($dig($c["to"]), $dig($from))));
        $ll[$mio ? "con_el_numero" : "ajenas"]++;
    }
    $url = is_string($j["next_page_uri"] ?? null) && $j["next_page_uri"] !== "" ? $api . $j["next_page_uri"] : null;
}
$out["llamadas"] = $ll;
$fin();
PHP

R=$(sudo -u "$WWW" php -r "$PHP_CHECK" -- "$TW_API" "$TW_NUMBERS" "$DIAS" 2>/dev/null | head -1 || true)
case "$R" in '{"piezas":'*) ;; *) echo "ERROR: la comprobación no devolvió un resultado legible (la salida no se imprime; ¿fase 1 desplegada, extensión curl?)"; exit 1 ;; esac

printf '%s' "$R" | python3 -I -c '
import json, sys
d = json.load(sys.stdin)
p, otp, cu, nu, sms, ll = d["piezas"], d["otp"], d.get("cuenta") or {}, d.get("numero") or {}, d.get("sms") or {}, d.get("llamadas") or {}
fallos, avisos = [], []
def linea(tipo, texto):
    print("%-7s %s" % (tipo, texto))
    (fallos if tipo == "FALLO" else avisos if tipo == "AVISO" else []).append(texto)
print("== h8-twilio-check · cuenta de voz AC…%s · número …%s · ventana de llamadas %s días (desde %s UTC)" % (p.get("account_last4") or "?", p.get("from_last4") or "?", ll.get("dias", "?"), ll.get("desde", "?")))
# Piezas
if not p["account"]: linea("FALLO", "TWILIO_VOICE_ACCOUNT_SID falta o no es AC + 32 hex")
if p["key_sid"] == "AC": linea("FALLO", "TWILIO_VOICE_API_KEY_SID es un Account SID: la voz usa una API KEY RESTRINGIDA (SK…), nunca el Account SID + Auth Token de una cuenta (D8-5)")
elif p["key_sid"] != "SK": linea("FALLO", "TWILIO_VOICE_API_KEY_SID falta o no es SK + 32 hex")
if not p["key_secret"]: linea("FALLO", "falta TWILIO_VOICE_API_KEY_SECRET")
if not p["from"]: linea("FALLO", "TWILIO_VOICE_FROM falta o no es E.164")
if otp.get("key_es_la_del_otp"): linea("FALLO", "la key de la voz es la credencial del OTP (D8-4)")
if d.get("errors"):
    for e in d["errors"]: linea("FALLO", "no se comprueba nada más: " + str(e))
    print("VEREDICTO: NO APTA (faltan piezas; ponerlas con «h8-voice-scope.sh fase 2 set» y «fase 2 secreto»)")
    sys.exit(3)
linea("OK", "credencial: API key SK… con su secreto (las de ElevenLabs y el OTP no se leen)") if p["key_sid"] == "SK" and p["key_secret"] else None
linea("INFO", "TWILIO_VOICE_SIGNING_TOKEN " + ("presente" if p["signing"] else "vacío: el aviso de gasto de Twilio de la voz falla cerrado (401); no lo necesitan las salientes"))
# Número de voz frente a WhatsApp y al OTP
if d.get("whatsapp"): linea("FALLO", "el número de voz es el de WhatsApp o uno protegido (protección 6, D8-5)")
else: linea("OK", "el número de voz no es ninguno de WhatsApp (VoiceNumberGuard)")
if otp.get("mismo_numero"): linea("FALLO", "el número de voz es el remitente del OTP (TWILIO_FROM): trasladarlo o tocarlo rompería el OTP")
elif otp.get("mismo_numero") is False: linea("OK", "el número de voz no es el remitente del OTP")
# Cuenta
st = cu.get("http")
if st == 200:
    if cu.get("status") != "active": linea("FALLO", "la cuenta no está activa (%s)" % cu.get("status"))
    else: linea("OK", "cuenta activa · tipo %s" % cu.get("type"))
    if cu.get("type") == "Trial": linea("AVISO", "cuenta Trial: solo llama a números verificados y antepone un aviso de Twilio")
elif st in (401, 403): linea("AVISO", "la key no puede leer la cuenta (HTTP %s; permiso /twilio/iam/accounts/read): no se sabe si es subcuenta salvo por la comparación con el OTP" % st)
else: linea("AVISO", "la cuenta no se pudo leer (HTTP %s)" % st)
misma = otp.get("misma_cuenta"); sub = cu.get("es_subcuenta")
if misma: linea("INFO", "la cuenta de voz ES LA DEL OTP (cuenta madre)")
elif sub is True: linea("INFO", "la cuenta de voz es una SUBCUENTA" + (" de la cuenta del OTP" if cu.get("madre_es_otp") else ""))
elif sub is False: linea("INFO", "la cuenta de voz es una CUENTA MADRE distinta de la del OTP")
# Número
t = nu.get("tipo")
if t == "comprado":
    linea("OK" if nu.get("voz") else "FALLO", "número COMPRADO de esta cuenta · voz %s · SMS %s · estado %s" % ("sí" if nu.get("voz") else "NO", "sí" if nu.get("sms") else "no", nu.get("status")))
    h = nu.get("voice_url_host")
    if nu.get("app") or nu.get("trunk"): linea("AVISO", "lo atiende hoy una aplicación TwiML o un trunk: importarlo con entrantes en ElevenLabs lo reconfiguraría (choque)")
    elif h and "elevenlabs" in h: linea("INFO", "su voice_url apunta a ElevenLabs (%s): importado con entrantes" % h)
    elif h: linea("AVISO", "su voice_url apunta a %s: importarlo con entrantes en ElevenLabs lo sustituiría (choque con lo que lo atiende hoy)" % h)
    else: linea("INFO", "sin voice_url: nadie atiende hoy sus entrantes")
    if nu.get("sms_url_host"): linea("AVISO", "recibe SMS en %s: no tocar su configuración de mensajería" % nu["sms_url_host"])
    if nu.get("address_requirements") not in (None, "none"): linea("INFO", "exige dirección (%s): para trasladarlo hay que crear antes una dirección conforme en la subcuenta destino" % nu["address_requirements"])
    if nu.get("bundle"):
        bs = nu.get("bundle_status")
        if bs == "twilio-approved": linea("OK", "bundle regulatorio aprobado (twilio-approved)")
        elif bs: linea("AVISO", "bundle regulatorio en estado %s (no aprobado)" % bs)
        else: linea("AVISO", "el bundle no se pudo leer (HTTP %s; permiso twilio/phone-numbers/regulatory-compliance.bundles/read)" % nu.get("bundle_http"))
    else: linea("INFO", "sin bundle regulatorio asociado")
elif t == "caller_id":
    linea("OK", "CALLER ID VERIFICADO de esta cuenta (en ElevenLabs, «Outbound Only»): sirve para SALIENTES sin comprar nada")
    linea("AVISO", "no recibe llamadas por Twilio ni se asigna a un agente: la fase 3 (entrantes) exige un número comprado o portado")
    linea("AVISO", "declararlo ANTES de publicar: «h8-voice-scope.sh fase 2 set VOICE_NUMBER_INBOUND_CAPABLE=false» (código de B8-g/S8-d). Sin la declaración, publicar el canario intenta asignarle el agente y la deriva del número (voice:reconcile --numbers) echaría el freno con el monitor de voz encendido")
elif t == "no_encontrado": linea("FALLO", "TWILIO_VOICE_FROM no está en esta cuenta ni como número comprado ni como caller ID verificado")
else: linea("AVISO", "no se pudo buscar el número (comprados HTTP %s, caller ID HTTP %s; permisos twilio/phone-numbers/active-numbers/list y /twilio/voice/outgoing-caller-ids/list)" % (nu.get("http_comprados"), nu.get("http_caller_ids")))
# Mensajería
if sms.get("denegado"): linea("OK", "la key NO puede leer mensajes (HTTP %s): no tiene permisos de mensajería" % sms.get("http"))
elif sms.get("http") == 200: linea("FALLO", "la key PUEDE leer mensajes: no es la restringida de la voz (su lista de permisos no puede llevar twilio/messaging/*)")
else: linea("AVISO", "la sonda de mensajes no fue concluyente (HTTP %s)" % sms.get("http"))
linea("INFO", "una key podría tener messages/create sin messages/list: la prueba definitiva es su lista de permisos en la consola (API keys → la key)")
# Llamadas de la ventana
if ll.get("http") == 200:
    # Un mapa vacío llega de PHP como lista JSON ([]): dict() admite los dos.
    dirs = ", ".join("%s %s" % (k, v) for k, v in sorted(dict(ll.get("por_direccion") or {}).items())) or "—"
    sts = ", ".join("%s %s" % (k, v) for k, v in sorted(dict(ll.get("por_estado") or {}).items())) or "—"
    linea("INFO", "llamadas en la cuenta: %s%s (con el número de voz %s · ajenas %s) · por dirección: %s · por estado: %s" % (ll["total"], "+ (TRUNCADO a 5 páginas)" if ll.get("truncado") else "", ll["con_el_numero"], ll["ajenas"], dirs, sts))
    if ll["total"] > 0:
        linea("AVISO", "la conciliación lista TODAS las llamadas de la cuenta (48 h por defecto, sin filtrar por número) y cada una sin su fila en voice_calls echa el freno (unknown_provider_call): antes de «monitor on», el mando lo comprueba en seco")
    if ll["ajenas"] > 0:
        linea("AVISO", "%s llamada(s) de la ventana NO son del número de voz: hay tráfico de voz ajeno en esta cuenta; con la conciliación encendida, cada una echaría el freno" % ll["ajenas"])
elif ll.get("http") in (401, 403): linea("FALLO", "la key no puede listar llamadas (HTTP %s): la conciliación y el corte de emergencia (D8-20, 0b) la necesitan (/twilio/voice/calls/list, /read y /update)" % ll["http"])
else: linea("AVISO", "las llamadas no se pudieron listar (HTTP %s)" % ll.get("http"))
# Veredicto
print()
madre = misma is True or sub is False
como = {"comprado": "trasladarlo SIN COMPRAR: POST /2010-04-01/Accounts/{MADRE}/IncomingPhoneNumbers/{PN…}.json con AccountSid={SUBCUENTA}, con las credenciales de la cuenta MADRE (twilio.com/docs/iam/api/subaccounts#exchanging-numbers); si exige dirección, crearla antes en la subcuenta; los registros de Trust Hub y A2P se reenvían; un remitente de WhatsApp conectado no se mueve (ticket)",
        "caller_id": "un caller ID verificado NO se traslada: se verifica OTRA VEZ en la subcuenta (POST /2010-04-01/Accounts/{SUBCUENTA}/OutgoingCallerIds.json con PhoneNumber: Twilio llama al número del negocio y se teclea el código de 6 dígitos; twilio.com/docs/voice/api/outgoing-caller-ids), sin comprar nada; después se reimporta en ElevenLabs con la key de la subcuenta y se quita la importación vieja"}.get(t, "según el tipo de número (comprado: traslado; caller ID: verificación nueva)")
if fallos:
    print("VEREDICTO: NO APTA: %d FALLO (arriba). Corregir antes de cualquier fase." % len(fallos))
    rc = 3
elif madre:
    print("VEREDICTO: SUBCUENTA (recomendada; D8-4 y D8-5). La voz está hoy en la cuenta MADRE%s:" % (" (la del OTP)" if misma else ""))
    print("  · la conciliación ve TODAS las llamadas de la cuenta: toda llamada de voz que no pida Laravel echa el freno (en la ventana: %s);" % ll.get("total", "?"))
    print("  · validar los avisos de gasto de voz exigiría el Auth Token MAESTRO en TWILIO_VOICE_SIGNING_TOKEN (dejarlo vacío: sin avisos de Twilio para la voz);")
    print("  · los avisos y techos de gasto de Twilio se comparten con el OTP; una key filtrada de la voz vive en la cuenta del OTP.")
    print("  «Misma cuenta con claves restringidas» solo es VIABLE con: 0 llamadas ajenas en la cuenta; la importación de ElevenLabs hecha con una key SK… restringida (solo llamadas y números)")
    print("   y NUNCA con el Account SID + Auth Token (desde aquí no se ve: consola de ElevenLabs → Phone numbers → el número; si se usó el Auth Token, ElevenLabs tiene la credencial")
    print("   maestra del OTP: reimportar con una key SK… y rotar el Auth Token tras B-6); TWILIO_VOICE_SIGNING_TOKEN vacío; y una ENMIENDA del contrato con el visto bueno de security.")
    print("  Subcuenta sin comprar número (crearla no tiene coste propio: el uso se factura a la madre): las keys se crean DENTRO de la subcuenta (las de la madre no ven sus recursos);")
    print("  el número: " + como)
    rc = 3
elif sub is True:
    print("VEREDICTO: APTA: subcuenta dedicada con la key restringida (lo que pide D8-5)%s." % (" y avisos arriba" if avisos else ""))
    rc = 0
else:
    print("VEREDICTO: INDETERMINADO: la key no puede leer la cuenta y no es la del OTP; no se sabe si es una subcuenta. Darle /twilio/iam/accounts/read o comprobarlo en la consola (Account → Subaccounts).")
    rc = 3
sys.exit(rc)' || exit $?
