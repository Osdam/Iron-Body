#!/bin/bash
# Hito 9 · el MANDO de la automatización comercial (outreach) para los niveles de vuelta atrás de §9.2 y las fases
# 2 → 6 de §14 del contrato H9 (fila OPS9 de §11: «el mando h9-outreach-scope.sh, con copia previa; admite 600 y 640»;
# D9-14 PARADA GLOBAL; L9-4 y L9-6 en el runbook). Sin él no se enciende ninguna fase: las ediciones a mano del fichero
# de entorno de producción no son el procedimiento. Calcado de h8-voice-scope.sh (OPS8-c), h7-monitor-scope.sh y
# h6-integrations-scope.sh. Las banderas van por NIVELES, de todo apagado a los canales, y cada orden escribe SOLO sus
# claves (§9.1: las de ejecución son de config/outreach.php, B9-run; las de gobierno, del bloque `strategies` de
# config/ultron_studio.php, S9; la del tiempo real, de config/ultron_studio_stream.php, SSE9; y una sola del motor
# comercial, COMMERCIAL_EVENTS_ENABLED, con su orden propia `eventos`: P9-24).
# OPS9-c (cierre del H9, informe-ops9-c.md): `apagar` pone además los cupos y techos a 0 y apaga el clasificador (L9-52
# Q-7); el horario propio admite el tramo de los sábados (P9-17); `canal whatsapp on` escribe los precios por categoría y
# `canal voz on` el cupo, el techo y la reserva de la voz del H9; `eventos` mide, enciende y apaga P9-24.
#
# Uso:
#   bash /root/h9-outreach-scope.sh estado
#   bash /root/h9-outreach-scope.sh apagar                                    nivel 0 (§9.2): TODO APAGADO, cupos y techos a 0, clasificador apagado
#   bash /root/h9-outreach-scope.sh parada-global --motivo="…"                nivel 0b: `outreach:stop --all` (sin tocar el entorno)
#   bash /root/h9-outreach-scope.sh sombra on                                 fase 2: MODE=shadow con el programador y el monitor
#   bash /root/h9-outreach-scope.sh asistido on --cupo=N | off                fase 3: MODE=live solo con el canal asistido y su tope
#   bash /root/h9-outreach-scope.sh canal whatsapp on --cupo=N --presupuesto=USD --precio-marketing=USD --precio-utilidad=USD | off
#                                                                             fases 4–5 (W9): exige la constante abierta
#   bash /root/h9-outreach-scope.sh canal voz on --cupo=N --presupuesto=USD --reserva=USD | off
#                                                                             fase 6 (V9): ídem, y las palancas del H8 abiertas
#   bash /root/h9-outreach-scope.sh programador on|off                        COMMERCIAL_AUTOMATION_SCHEDULER_ENABLED (el bloque de routes/console.php: 5 tareas)
#   bash /root/h9-outreach-scope.sh monitor on|off                            COMMERCIAL_AUTOMATION_MONITOR_ENABLED (outreach:monitor, solo apaga)
#   bash /root/h9-outreach-scope.sh clasificador on|off                       COMMERCIAL_AUTOMATION_CLASSIFIER_ENABLED (AI9; solo frena o deriva)
#   bash /root/h9-outreach-scope.sh horario set HH:MM-HH:MM [--sabado=HH:MM-HH:MM|cerrado] | legal
#                                                                             COMMERCIAL_AUTOMATION_OWN_HOURS (solo más estricto que el legal, P9-17)
#   bash /root/h9-outreach-scope.sh gobierno on|off                           ULTRON_STUDIO_STRATEGIES_ENABLED (las 30 rutas strategies/**)
#   bash /root/h9-outreach-scope.sh publicar on|off                           ULTRON_STUDIO_STRATEGIES_PUBLISHING_ENABLED (publicar, promover, reanudar)
#   bash /root/h9-outreach-scope.sh ventana set HH:MM-HH:MM | ninguna --orden-escrita=<ref>   ULTRON_STUDIO_STRATEGIES_PUBLISH_WINDOW
#   bash /root/h9-outreach-scope.sh canario set --horas=N --contactos=N       ULTRON_STUDIO_STRATEGIES_CANARY_MIN_HOURS / _MIN_TOUCHES
#   bash /root/h9-outreach-scope.sh simulacion required | waived --orden-escrita=<ref>        ULTRON_STUDIO_STRATEGIES_SIMULATION_GATE
#   bash /root/h9-outreach-scope.sh stream on|off                             ULTRON_STUDIO_STREAM_ENABLED (SSE9; apagado, 409 stream_disabled y el CRM sondea)
#   bash /root/h9-outreach-scope.sh eventos medir | on --orden-escrita=<ref> | off
#                                                                             COMMERCIAL_EVENTS_ENABLED (P9-24; «medir» no escribe nada)
#
# Se ejecuta EN EL SERVIDOR como root, con la fase 1 del H9 desplegada y la AUTORIZACIÓN ESCRITA de la fase (§14: las
# fases 2 → 6 la exigen); apagar («apagar», «… off») es la vuelta atrás de nivel 0 o 2 y no espera a nadie. Cada orden
# que escribe: el fichero de entorno tiene que ser un fichero normal (nunca un enlace) de www-data en 600 o de
# root:www-data en 640 (C-14) → las claves pedidas con su forma validada y, si la orden lo exige, sus condiciones
# (abajo) → copia previa 600 de root en /root/env-pre-outreach-<orden>-<fecha> (nunca se sobrescribe una copia) →
# SOLO las claves pedidas, con la sangría que tuvieran (dos espacios si faltan), en una escritura atómica que conserva
# dueño y modo → el fichero parsea → config:cache como www-data con umask 077 (caché de www-data, 600, nunca un
# enlace) → la configuración efectiva cambió SOLO en lo pedido y el motor comercial, ULTRON Legacy, el H4–H8 y la voz
# siguen igual (con `eventos`, solo cambia commercial.events_enabled) → el fichero es la copia salvo esas claves → el
# programador cambia solo lo que la orden programa (las tareas outreach:* del bloque del H9) → queue:restart → los
# workers como antes, dos lecturas → 401 sin token en las rutas del H9 → 0 ERROR nuevos. Imprime el antes y el después
# (el H9 no tiene secretos en sus banderas, pero NUNCA se imprime un valor del fichero de entorno: solo la configuración
# efectiva) y la orden exacta de vuelta atrás. «APLICADO» solo sale con 0 desvíos; con alguno, código 1 y la vuelta
# atrás. Códigos: 0 hecho o nada que hacer, 1 aborto o desvío, 2 uso o condición no cumplida (no se ha tocado nada).
#
# Se NIEGA (código 2, sin tocar nada):
#   · toda orden sobre COMMERCIAL_AUTOMATION_*, si config/outreach.php no está desplegado (la configuración efectiva no
#     se puede comprobar); toda orden sobre ULTRON_STUDIO_STRATEGIES_*, sin el bloque `strategies` (S9); «stream», sin
#     config/ultron_studio_stream.php (SSE9); «eventos», sin config/commercial.php;
#   · sombra on, asistido on, canal … on, programador on y monitor on, si las órdenes `outreach:monitor` y
#     `outreach:stop` no existen en el código DESPLEGADO (sin ellas no hay monitor ni PARADA GLOBAL, nivel 0b);
#   · asistido on, si la maestra está apagada (primero `sombra on`: la fase 3 sigue a la 2) o sin --cupo (1–500);
#   · canal whatsapp on / canal voz on, si la constante OutreachSendGate::SENDS_ENABLED_IN_CODE no es exactamente `true`
#     en el código DESPLEGADO (en ejecución y en el fichero, una sola declaración anclada, y el último commit que tocó
#     el fichero empieza por `outreach(sends-open):`, el prefijo de §14 fase 4), si el modo no es `live` (primero
#     `asistido on`), o si la clase EXACTA del canal no existe o el canal no está declarado en
#     OutreachSendGate::LIVE_CHANNELS (W9: Channels\WhatsappTemplateChannel y `whatsapp_template`; V9:
#     Channels\VoiceStrategyChannel y `voice_call`);
#     whatsapp además sin --cupo (1–500), sin --presupuesto (0.01–999 US$) o sin los dos precios por mensaje entregado
#     (--precio-marketing y --precio-utilidad, > 0 y ≤ 1 US$, hasta 6 decimales: con un precio 0 la escalera niega
#     `budget_zero` a esa categoría, PolicyLadder N4), o si el presupuesto no alcanza para un mensaje del precio mayor;
#     voz además con las palancas del H8 cerradas (VoiceCallPolicy::CALLS_ENABLED_IN_CODE o VOICE_OUTBOUND_ENABLED),
#     sin --cupo (1–20 y ≤ TWILIO_VOICE_DAILY_CALL_CAP del H8), sin --presupuesto (0.01–999 y ≤ VOICE_DAILY_BUDGET_USD
#     del H8) o sin --reserva (≥ la reserva del H8 de una llamada a móvil de la duración máxima, VoiceCostGuard, y ≤ el
#     presupuesto): con cualquiera de los tres en 0 la voz queda en `budget_zero`;
#   · clasificador on, sin el código de AI9 en el árbol desplegado;
#   · horario set, con una ventana fuera de [08:00, 19:00) entre semana o de [08:00, 15:00) el sábado, o que no sea más
#     estricta (P9-17 y L9-32); el tramo del sábado va con --sabado=… (un «;» en la línea de órdenes lo cortaría el shell);
#   · ventana set ninguna, simulacion waived y eventos on, sin --orden-escrita=<referencia> (solo el propietario los
#     decide y la referencia queda en la salida y en la copia previa; §9.1, P9-24);
#   · eventos on, con COMMERCIAL_NBA_ENABLED o COMMERCIAL_AUTONOMY_ENABLED encendidos (P9-24 se decidió con el motor
#     que detecta pero no decide ni ejecuta: con NBA cada hecho encolaría una evaluación y abriría oportunidades);
#   · canario set, con horas fuera de 1–168 o contactos fuera de 1–100;
#   · parada-global, sin --motivo (5–200 caracteres) o sin la orden `outreach:stop` desplegada.
# Nunca imprime valores del fichero de entorno ni la salida de un tinker que no case con lo esperado. Del motor comercial
# solo toca COMMERCIAL_EVENTS_ENABLED y solo con `eventos` (NBA y AUTONOMY nunca); no toca ULTRON Legacy, ni el H4–H7,
# ni la voz del H8 (eso es de h8-voice-scope.sh), ni las piezas de WhatsApp o de Meta. No reinicia FPM (el proceso web
# lee la caché en cada petición) ni ningún programa de supervisor. No suelta frenos: soltarlos es siempre un acto humano
# desde el CRM (`commercial_automation.release`, otra persona, con nota).
set -euo pipefail
export LC_ALL=C
umask 077

# ── Lo propio de producción, en un solo bloque (el banco sustituye estas líneas y ninguna otra) ──────────────────────
APPDIR=/var/www/api/backend
API=https://api.ironbodyneiva.cloud/api
WWW=www-data
ROOT_U=root
ROOT_G=root
COPIAS=/root
CERROJO=/run/lock/h9-outreach-scope.lock
NIVEL_ERROR=production.ERROR

# ── Lo propio del H9 que B9-run fija (si cambia un nombre, cambia AQUÍ y en routes/console.php) ────────────────────────
# Las dos órdenes que tienen que existir para encender nada (D9-14): el monitor y la PARADA GLOBAL.
ORDENES_REQUERIDAS="outreach:monitor outreach:stop"
# Tareas que programa el bloque del H9 de routes/console.php (L9-58, L9-60c, L9-81): con el programador encendido, SIEMPRE 5
# (outreach:sweep cada 10 min, los normalizadores outreach:whatsapp-outcomes cada minuto y outreach:voice-outcomes cada
# 5 min, outreach:dispatch --inline cada minuto y outreach:monitor cada 10 min, que va plano en el
# bloque y comprueba su propia bandera al correr); con el programador apagado, 0 aunque el monitor esté encendido (el
# bloque entero cuelga del programador). COMMERCIAL_AUTOMATION_MONITOR_ENABLED no cambia el recuento: solo si la pasada
# del monitor hace trabajo.
TAREAS_PROGRAMADOR=5
PREFIJO_ENVIOS='outreach(sends-open):'
# Las clases EXACTAS de los canales automáticos (W9 y V9) y su canal de trabajo en OutreachSendGate::LIVE_CHANNELS.
CLASE_WHATSAPP='App\Services\Outreach\Run\Channels\WhatsappTemplateChannel'
CLASE_VOZ='App\Services\Outreach\Run\Channels\VoiceStrategyChannel'
# Clave del fichero de entorno → [campo de leer_outreach, tipo]: b booleano, i entero, d decimal (texto), s texto,
# t texto o nulo (vacío). COMMERCIAL_EVENTS_ENABLED no está: es del motor y se lee con leer_resto.
MAPA='{"COMMERCIAL_AUTOMATION_ENABLED":["enabled","b"],"COMMERCIAL_AUTOMATION_MODE":["mode","s"],
"COMMERCIAL_AUTOMATION_SCHEDULER_ENABLED":["scheduler","b"],"COMMERCIAL_AUTOMATION_MONITOR_ENABLED":["monitor","b"],
"COMMERCIAL_AUTOMATION_ASSISTED_ENABLED":["assisted","b"],"COMMERCIAL_AUTOMATION_ASSISTED_DAILY_CAP":["assisted_cap","i"],
"COMMERCIAL_AUTOMATION_WHATSAPP_ENABLED":["whatsapp","b"],"COMMERCIAL_AUTOMATION_WHATSAPP_DAILY_CAP":["whatsapp_cap","i"],
"COMMERCIAL_AUTOMATION_WHATSAPP_DAILY_BUDGET_USD":["whatsapp_budget","d"],"OUTREACH_WHATSAPP_PRICE_MARKETING_USD":["price_marketing","d"],
"OUTREACH_WHATSAPP_PRICE_UTILITY_USD":["price_utility","d"],"COMMERCIAL_AUTOMATION_VOICE_ENABLED":["voice","b"],
"COMMERCIAL_AUTOMATION_VOICE_DAILY_CAP":["voice_cap","i"],"COMMERCIAL_AUTOMATION_VOICE_DAILY_BUDGET_USD":["voice_budget","d"],
"COMMERCIAL_AUTOMATION_VOICE_RESERVE_USD":["voice_reserve","d"],"COMMERCIAL_AUTOMATION_CLASSIFIER_ENABLED":["classifier","b"],
"COMMERCIAL_AUTOMATION_OWN_HOURS":["own_hours","t"],"ULTRON_STUDIO_STRATEGIES_ENABLED":["strategies","b"],
"ULTRON_STUDIO_STRATEGIES_PUBLISHING_ENABLED":["strategies_publishing","b"],"ULTRON_STUDIO_STRATEGIES_PUBLISH_WINDOW":["strategies_window","s"],
"ULTRON_STUDIO_STRATEGIES_CANARY_MIN_HOURS":["canary_min_hours","i"],"ULTRON_STUDIO_STRATEGIES_CANARY_MIN_TOUCHES":["canary_min_touches","i"],
"ULTRON_STUDIO_STRATEGIES_SIMULATION_GATE":["simulation_gate","s"],"ULTRON_STUDIO_STREAM_ENABLED":["stream","b"]}'

uso() {
  awk '/^# Uso:$/ {f = 1; next} f && /^#$/ {exit} f {sub(/^# ?/, ""); print}' "$0"
}
aborta() { echo "ABORTA: $*"; exit 1; }
niega() { echo "NO SE APLICA (no se ha tocado nada): $*"; exit 2; }
DESVIOS=0
desvio() { echo "   ✗ $*"; DESVIOS=$((DESVIOS + 1)); }

[ "$#" -ge 1 ] || { uso; exit 2; }
[ "$(id -u)" = 0 ] || { echo "se ejecuta como root en el servidor"; exit 2; }
cd "$APPDIR"
ENVF="$APPDIR/.env"

# ── Lecturas (como www-data; solo la configuración efectiva, nunca el fichero) ───────────────────────────────────────
# Las banderas del H9, planas. La constante de envío se busca por su fichero (B9-run decide el subdirectorio de
# OutreachSendGate dentro de app/Services/Outreach): «sends_gate» es la clase encontrada o null; «live_*» dice si el canal
# está declarado en su LIVE_CHANNELS y «class_*» si existe la clase EXACTA del canal (W9, V9).
leer_outreach() {
  local l
  l=$(sudo -u "$WWW" php artisan tinker --execute='$o = "outreach."; $s = "ultron_studio.strategies."; $txt = fn ($v) => is_string($v) && trim($v) !== "" ? trim($v) : null; $dec = fn ($k) => config($k) === null ? null : (string) config($k); $g = null; foreach (array_merge(glob(app_path("Services/Outreach/OutreachSendGate.php")) ?: [], glob(app_path("Services/Outreach/*/OutreachSendGate.php")) ?: []) as $f) { $c = "App\\" . str_replace("/", "\\", substr($f, strlen(app_path()) + 1, -4)); if (class_exists($c) && defined($c . "::SENDS_ENABLED_IN_CODE")) { $g = $c; break; } } $vivo = fn ($canal) => $g !== null && method_exists($g, "channelLive") ? (bool) $g::channelLive($canal) : null; echo json_encode(["deployed" => is_array(config("outreach")), "enabled" => config($o."enabled"), "mode" => $txt(config($o."mode")), "scheduler" => config($o."scheduler.enabled"), "monitor" => config($o."monitor.enabled"), "assisted" => config($o."assisted.enabled"), "assisted_cap" => config($o."assisted.daily_cap"), "whatsapp" => config($o."whatsapp.enabled"), "whatsapp_cap" => config($o."whatsapp.daily_cap"), "whatsapp_budget" => $dec($o."whatsapp.daily_budget_usd"), "price_marketing" => $dec($o."whatsapp.price_marketing_usd"), "price_utility" => $dec($o."whatsapp.price_utility_usd"), "voice" => config($o."voice.enabled"), "voice_cap" => config($o."voice.daily_cap"), "voice_budget" => $dec($o."voice.daily_budget_usd"), "voice_reserve" => $dec($o."voice.reserve_usd_per_call"), "classifier" => config($o."classifier.enabled"), "own_hours" => $txt(config($o."own_hours")), "sends_in_code" => $g === null ? null : constant($g . "::SENDS_ENABLED_IN_CODE"), "sends_gate" => $g, "live_whatsapp" => $vivo("whatsapp_template"), "live_voice" => $vivo("voice_call"), "class_whatsapp" => class_exists("App\\Services\\Outreach\\Run\\Channels\\WhatsappTemplateChannel"), "class_voice" => class_exists("App\\Services\\Outreach\\Run\\Channels\\VoiceStrategyChannel"), "strategies_deployed" => is_array(config("ultron_studio.strategies")), "strategies" => config($s."enabled"), "strategies_publishing" => config($s."publishing_enabled"), "strategies_window" => config($s."window") === null ? null : (string) config($s."window"), "canary_min_hours" => config($s."canary_min_hours"), "canary_min_touches" => config($s."canary_min_touches"), "simulation_gate" => config($s."simulation_gate"), "stream_deployed" => is_array(config("ultron_studio_stream")), "stream" => config("ultron_studio_stream.enabled")]), PHP_EOL;' 2>&1 | tail -1) || true
  case "$l" in '{"deployed":'*) printf '%s\n' "$l" ;; esac
}
# Lo que el mando NO toca y tiene que seguir igual: el motor comercial (salvo `eventos`, que mueve solo
# commercial_events), ULTRON Legacy, el H4–H7 y la voz del H8.
leer_resto() {
  local l
  l=$(sudo -u "$WWW" php artisan tinker --execute='echo json_encode(["engine_scope" => config("marketing.ultron.studio_config"), "ultron_enabled" => config("marketing.ultron.enabled"), "canary_set" => config("marketing.ultron.canary_conversation_id") !== null, "followups_scheduler" => config("marketing.followups.scheduler_enabled"), "commercial_deployed" => is_array(config("commercial")), "commercial_nba" => config("commercial.enabled"), "commercial_events" => config("commercial.events_enabled"), "commercial_autonomy" => config("commercial.autonomy_enabled"), "studio_publishing" => config("ultron_studio.publishing_enabled"), "studio_monitor" => config("ultron_studio.monitor.enabled"), "studio_lab" => config("ultron_studio.lab.enabled"), "integrations_writes" => config("ultron_studio.integrations.writes_enabled"), "voice_publishing" => config("ultron_studio.voice.publishing_enabled"), "voice_inbound" => config("voice.inbound.enabled"), "voice_outbound" => config("voice.outbound.enabled"), "voice_monitor" => config("voice.monitor.enabled"), "voice_lab" => config("voice.lab.enabled"), "voice_contacts" => config("voice.contacts.enabled"), "twilio_voice" => config("voice.twilio.enabled"), "calls_in_code" => class_exists("App\\Services\\Voice\\VoiceCallPolicy") ? App\Services\Voice\VoiceCallPolicy::CALLS_ENABLED_IN_CODE : null]), PHP_EOL;' 2>&1 | tail -1) || true
  case "$l" in '{"engine_scope"'*) printf '%s\n' "$l" ;; esac
}
# Los límites de la voz del H8 con los que se comparan el cupo, el techo y la reserva de la voz del H9 (canal voz on):
# el cupo diario de llamadas, el presupuesto diario en micros y la reserva de UNA llamada (VoiceCostGuard, la misma
# cuenta que OutboundGate) a móvil y a fijo con la duración máxima configurada y la transferencia a fijo.
leer_voz_h8() {
  local l
  l=$(sudo -u "$WWW" php artisan tinker --execute='$g = "App\\Services\\Telephony\\VoiceCostGuard"; $ok = class_exists($g) && method_exists($g, "reserveMicros"); $max = (int) config("voice.limits.per_call_max_seconds", 300); $p = $ok ? $g::prices() : null; echo json_encode(["h8_cap" => config("voice.twilio.daily_call_cap"), "h8_budget_micros" => $ok ? $g::budgetFromConfig() : null, "h8_max_seconds" => $max, "h8_reserve_mobile_micros" => $ok ? $g::reserveMicros($g::KIND_MOBILE, $max, $g::KIND_LANDLINE, $p) : null, "h8_reserve_landline_micros" => $ok ? $g::reserveMicros($g::KIND_LANDLINE, $max, $g::KIND_LANDLINE, $p) : null]), PHP_EOL;' 2>&1 | tail -1) || true
  case "$l" in '{"h8_cap"'*) printf '%s\n' "$l" ;; esac
}
# Recuentos informativos de solo lectura (cambian por sí solos: no entran en la comparación antes → después).
# «live_today»: lo reservado HOY (día de Bogotá) en vivo por canal; el monitor lo compara con los cupos de AHORA.
leer_cuentas() {
  local l
  l=$(sudo -u "$WWW" php artisan tinker --execute='$h = fn ($t) => Illuminate\Support\Facades\Schema::hasTable($t); $hoy = (new DateTimeImmutable("now", new DateTimeZone("America/Bogota")))->format("Y-m-d"); echo json_encode(["brakes_open" => $h("outreach_brakes") ? DB::table("outreach_brakes")->whereNull("released_at")->count() : null, "brakes_global" => $h("outreach_brakes") ? DB::table("outreach_brakes")->whereNull("released_at")->where("scope", "global")->count() : null, "jobs_live" => $h("outreach_jobs") ? DB::table("outreach_jobs")->whereIn("status", ["planned", "claimed", "dispatching"])->count() : null, "runs" => $h("outreach_runs") ? DB::table("outreach_runs")->count() : null, "bindings_verified" => $h("ultron_studio_person_bindings") ? DB::table("ultron_studio_person_bindings")->whereNotNull("verified_at")->whereNull("revoked_at")->count() : null, "versions_live" => $h("ultron_studio_strategy_versions") ? DB::table("ultron_studio_strategy_versions")->whereIn("status", ["CANARY", "PUBLISHED"])->count() : null, "tables_h9" => (int) $h("outreach_runs") + (int) $h("ultron_studio_strategies"), "live_today" => $h("outreach_budget_counters") ? DB::table("outreach_budget_counters")->where("budget_day", $hoy)->where("mode", "live")->where("scope", "like", "channel:%")->pluck("reserved_units", "scope") : null]), PHP_EOL;' 2>&1 | tail -1) || true
  case "$l" in '{"brakes_open":'*) printf '%s\n' "$l" ;; *) echo '{}' ;; esac
}
# P9-24, SOLO LECTURA: lo que escribiría COMMERCIAL_EVENTS_ENABLED y lo que pesa, sin encender nada. La pasada horaria
# de commercial:scan se cuenta EN SECO (--dry-run) con la bandera puesta SOLO en la memoria de este proceso de tinker
# (no escribe: en seco solo cuenta; su salida con ids no se imprime, solo los cuatro totales); la actividad de los
# últimos 7 días que vigilan los observadores (recuentos); y el plan (EXPLAIN sin ANALYZE: no ejecuta la consulta) de la
# consulta que el observador de asistencias añade a CADA entrada de recepción.
leer_medida_eventos() {
  local l
  l=$(sudo -u "$WWW" php artisan tinker --execute='$h = fn ($t) => Illuminate\Support\Facades\Schema::hasTable($t); $pg = DB::connection()->getDriverName() === "pgsql"; $desde = now()->subDays(7); $n = fn ($t, $q) => $h($t) ? (int) $q(DB::table($t))->count() : null; $scan = null; if (is_array(config("commercial"))) { $antes = config("commercial.events_enabled"); config(["commercial.events_enabled" => true]); try { Illuminate\Support\Facades\Artisan::call("commercial:scan", ["--dry-run" => true]); preg_match_all("/^ {2}(renewal_window|expired|inactivity|abandoned_links) +([0-9]+) *$/m", Illuminate\Support\Facades\Artisan::output(), $m); $scan = $m[1] === [] ? null : array_combine($m[1], array_map("intval", $m[2])); } catch (Throwable $e) { $scan = null; } finally { config(["commercial.events_enabled" => $antes]); } } $plan = null; $q = chr(39); if ($pg && $h("attendances")) { $r = DB::select("EXPLAIN (FORMAT JSON) SELECT count(DISTINCT date(captured_at)) FROM attendances WHERE member_id = 0 AND action = {$q}entry{$q}"); $j = json_decode((string) array_values((array) $r[0])[0], true); $nodos = []; $anda = function ($p) use (&$anda, &$nodos) { if (isset($p["Relation Name"])) { $nodos[] = $p["Node Type"]; } foreach ($p["Plans"] ?? [] as $c) { $anda($c); } }; $anda($j[0]["Plan"] ?? []); $plan = implode("+", $nodos); } $tam = fn ($t) => ($pg && $h($t)) ? (string) DB::scalar("SELECT pg_size_pretty(pg_total_relation_size(?::regclass))", [$t]) : null; $est = fn ($t) => ($pg && $h($t)) ? (int) DB::scalar("SELECT greatest(reltuples, 0)::bigint FROM pg_class WHERE oid = ?::regclass", [$t]) : null; echo json_encode(["medida" => true, "events" => config("commercial.events_enabled"), "nba" => config("commercial.enabled"), "autonomy" => config("commercial.autonomy_enabled"), "ce_rows" => $h("commercial_events") ? DB::table("commercial_events")->count() : null, "ce_pending" => $h("commercial_events") ? DB::table("commercial_events")->whereNull("evaluated_at")->count() : null, "ce_size" => $tam("commercial_events"), "scan" => $scan, "entries_7d" => $n("attendances", fn ($q) => $q->where("action", "entry")->where("captured_at", ">=", $desde)), "payments_7d" => $n("payment_transactions", fn ($q) => $q->where("created_at", ">=", $desde)), "leads_7d" => $n("marketing_leads", fn ($q) => $q->where("created_at", ">=", $desde)), "appointments_7d" => $n("marketing_appointments", fn ($q) => $q->where("created_at", ">=", $desde)), "invoices_7d" => $n("electronic_invoices", fn ($q) => $q->where("created_at", ">=", $desde)), "att_plan" => $plan, "att_size" => $tam("attendances"), "att_rows" => $est("attendances"), "att_member_idx" => ($pg && $h("attendances")) ? (bool) DB::scalar("SELECT EXISTS (SELECT 1 FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = i.indkey[0] WHERE t.relname = {$q}attendances{$q} AND a.attname = {$q}member_id{$q})") : null]), PHP_EOL;' 2>&1 | tail -1) || true
  case "$l" in '{"medida":true'*) printf '%s\n' "$l" ;; esac
}
medir_eventos() {
  local m
  m=$(leer_medida_eventos)
  [ -n "$m" ] || { echo "   (no se pudo medir: tinker falló o el motor comercial no está; su salida no se imprime)"; return 0; }
  printf '%s' "$m" | python3 -I -c '
import json, sys
d = json.load(sys.stdin)
x = lambda k: d.get(k) if d.get(k) is not None else "—"
b = lambda k: "sí" if d.get(k) is True else ("no" if d.get(k) is False else "?")
s = d.get("scan") or {}
dia = lambda k: ("≈ %.0f/día" % (d[k] / 7.0)) if isinstance(d.get(k), int) else "—"
print("== P9-24 · lo que escribiría COMMERCIAL_EVENTS_ENABLED (solo lectura; recuentos, ningún dato personal)")
print("   banderas del motor comercial: eventos %s · NBA (decide) %s · autonomía (ejecuta) %s" % (b("events"), b("nba"), b("autonomy")))
print("   commercial_events ahora: %s filas (%s sin evaluar) · %s" % (x("ce_rows"), x("ce_pending"), x("ce_size")))
if s:
    print("   commercial:scan (cada hora, en seco ahora, ≤ 500 por familia; una fila por persona, familia y día): renovación %s · vencida ayer %s · inactividad %s · enlaces de pago abandonados %s → hasta %d filas/día" % (s.get("renewal_window", "?"), s.get("expired", "?"), s.get("inactivity", "?"), s.get("abandoned_links", "?"), sum(v for v in s.values() if isinstance(v, int))))
else:
    print("   commercial:scan en seco: no se pudo contar (la orden no respondió como se espera)")
print("   lo que vigilan los 8 observadores, últimos 7 días (cota superior: solo algunos cambios dan un hecho): entradas en recepción %s (%s) · pagos %s · prospectos %s · citas %s · facturas %s" % (x("entries_7d"), dia("entries_7d"), x("payments_7d"), x("leads_7d"), x("appointments_7d"), x("invoices_7d")))
print("   coste en la entrada de recepción: el observador de asistencias hace, en CADA entrada y dentro de su petición, un COUNT(DISTINCT día) de las entradas de esa persona en attendances (%s filas estimadas, %s); plan hoy: %s · índice que empiece por member_id: %s" % (x("att_rows"), x("att_size"), x("att_plan"), b("att_member_idx")))
if d.get("att_member_idx") is False:
    print("   AVISO: attendances no tiene índice que empiece por member_id (las migraciones solo crean user_id+captured_at, captured_at y access_via+captured_at): cada entrada recorre la tabla entera. Con decenas de miles de filas son milisegundos; medir el tiempo de la entrada una hora después de encender y, si crece, un índice (database-specialist)")
print("   escribe SOLO en commercial_events (1 SELECT + 1 INSERT por hecho nuevo, idempotente por dedupe_key; nunca lanza dentro de la transacción del pago) y una línea commercial.event.recorded por hecho en el registro del canal. Con NBA apagado NO evalúa, NO abre oportunidades, NO encola trabajos y NO contacta a nadie; las filas quedan sin evaluar (si algún día se enciende NBA, commercial:evaluate-pending evaluaría las de los últimos 7 días)")
print("   lo que gana el H9: la fuente crm_fact deja de estar «sin datos» y N6 deja de negar a todo el mundo por human_requested_recently desconocido (L9-26)")'
}
# Las órdenes outreach:* que existen en el código desplegado (artisan list --format=json), separadas por espacios.
ordenes_outreach() {
  sudo -u "$WWW" php artisan list --format=json 2>/dev/null | python3 -I -c '
import json, sys
try:
    d = json.load(sys.stdin)
    print(" ".join(sorted(c["name"] for c in d.get("commands", []) if str(c.get("name", "")).startswith("outreach:"))))
except Exception:
    print("")' 2>/dev/null || echo ""
}
# El programador por `schedule:list --json`, con su código de salida APARTE (m-1 del H7): «total del_Studio de_voz de_outreach».
programador() {
  local salida rc=0
  salida=$(sudo -u "$WWW" php artisan schedule:list --json 2>/dev/null) || rc=$?
  [ "$rc" = 0 ] || { echo "FALLO $rc"; return 0; }
  printf '%s' "$salida" | python3 -I -c '
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
workers() {
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
errores_log() { local n=0 f; for f in "$APPDIR"/storage/logs/laravel*.log; do [ -f "$f" ] && n=$((n + $(grep -c "$NIVEL_ERROR" "$f" || true))); done; echo "$n"; }
# El fichero de entorno: un fichero normal, de www-data en 600 o de root:www-data en 640 (C-14), nunca un enlace.
fichero_ok() {
  [ -f "$ENVF" ] && [ ! -L "$ENVF" ] || return 1
  local um
  um=$(stat -c '%U %a' "$ENVF")
  [ "$um" = "$WWW 600" ] && return 0
  [ "$(stat -c '%U:%G %a' "$ENVF")" = "$ROOT_U:$WWW 640" ] && return 0
  return 1
}
cuenta_var() { grep -Ec "^[[:space:]]*(export[[:space:]]+)?$1[[:space:]]*=" "$ENVF" || true; }
# La constante de envío del código desplegado (§9.1, §14 fase 4): «abierta» solo si en ejecución es true, hay UN fichero
# OutreachSendGate.php bajo app/Services/Outreach con exactamente una declaración `= true;` anclada y ninguna `= false`,
# y el último commit que tocó ese fichero empieza por `outreach(sends-open):`. Sin el fichero: «sin desplegar».
constante() {
  local f n abiertas cerradas asunto
  n=$(find app/Services/Outreach -name OutreachSendGate.php 2>/dev/null | wc -l | tr -d ' ')
  [ "$n" = 1 ] || { [ "$n" = 0 ] && echo "sin desplegar" || echo "incoherente ($n ficheros OutreachSendGate.php)"; return; }
  f=$(find app/Services/Outreach -name OutreachSendGate.php)
  abiertas=$(grep -cE '^[[:space:]]*public const SENDS_ENABLED_IN_CODE = true;[[:space:]]*$' "$f" || true)
  cerradas=$(grep -cE 'SENDS_ENABLED_IN_CODE[[:space:]]*=[[:space:]]*false' "$f" || true)
  asunto=$(git -C "$APPDIR/.." log -1 --format=%s -- "backend/$f" 2>/dev/null || true)
  case "$1" in
    '"sends_in_code":true') [ "$abiertas" = 1 ] && [ "$cerradas" = 0 ] && [ "${asunto:0:${#PREFIJO_ENVIOS}}" = "$PREFIJO_ENVIOS" ] && { echo abierta; return; }
                            echo "incoherente (en ejecución true; declaraciones true: $abiertas, false: $cerradas; último commit del fichero: «${asunto:0:60}»)" ;;
    '"sends_in_code":false') echo cerrada ;;
    *) echo "sin desplegar" ;;
  esac
}
# ¿Existe en el árbol desplegado una clase cuyo nombre case con el patrón? Imprime cuántas. Solo para AI9 (el
# clasificador, sin nombre fijado); W9 y V9 se comprueban por su clase EXACTA y LIVE_CHANNELS (doc9, «comprobante débil»).
clases_outreach() { grep -rlE "^(final |abstract )?class [A-Za-z]*$1[A-Za-z]*([^A-Za-z0-9_]|$)" app/Services/Outreach 2>/dev/null | wc -l | tr -d ' '; }

# ── Validación de valores (por forma) ────────────────────────────────────────────────────────────────────────────────
# Un importe en US$ con hasta N decimales dentro de [min, max] (Decimal, sin coma flotante); la forma es la que acepta
# config/outreach.php ($dolares: hasta 6 cifras enteras y 6 decimales).
es_importe() { # valor decimales min max
  local re="^[0-9]{1,6}(\\.[0-9]{1,$2})?\$"
  [[ "$1" =~ $re ]] && python3 -I -c 'import sys; from decimal import Decimal as D; v = D(sys.argv[1]); sys.exit(0 if D(sys.argv[2]) <= v <= D(sys.argv[3]) else 1)' "$1" "$3" "$4"
}
es_entero() { [[ "$1" =~ ^[0-9]{1,3}$ ]] && [ "$1" -ge "$2" ] && [ "$1" -le "$3" ]; }
es_referencia() { [[ "$1" =~ ^[A-Za-z0-9][A-Za-z0-9._/-]{2,59}$ ]]; }
# Una ventana HH:MM-HH:MM con inicio < fin; imprime los minutos «inicio fin» o nada.
minutos_ventana() {
  [[ "$1" =~ ^([01][0-9]|2[0-3]):([0-5][0-9])-([01][0-9]|2[0-3]):([0-5][0-9])$ ]] || return 1
  local a=$((10#${BASH_REMATCH[1]} * 60 + 10#${BASH_REMATCH[2]})) b=$((10#${BASH_REMATCH[3]} * 60 + 10#${BASH_REMATCH[4]}))
  [ "$a" -lt "$b" ] || return 1
  echo "$a $b"
}
# El horario anterior (texto de la configuración o null) → la orden funcional que lo vuelve a escribir.
vuelta_horario() {
  local v=$1 a b
  case "$v" in null|""|legal) echo "bash $0 horario set legal"; return 0 ;; esac
  a=${v%%;*}; b=""; [ "$a" = "$v" ] || b=${v#*;}
  if minutos_ventana "$a" > /dev/null && { [ -z "$b" ] || [ "$b" = - ] || minutos_ventana "$b" > /dev/null; }; then
    echo "bash $0 horario set $a${b:+ --sabado=$([ "$b" = - ] && echo cerrado || echo "$b")}"
  else
    echo "(el horario anterior no es una franja que este mando escriba: usar la vuelta exacta)"
  fi
}
# WhatsApp: ¿cabe en el presupuesto al menos un mensaje del precio mayor? Imprime «ok [aviso]» o «no motivo».
comprobar_whatsapp() { # cupo presupuesto precio_marketing precio_utilidad
  python3 -I -c '
import sys
from decimal import Decimal as D
cupo, pres, pm, pu = int(sys.argv[1]), D(sys.argv[2]), D(sys.argv[3]), D(sys.argv[4])
mayor = max(pm, pu)
if pres < mayor:
    print("no --presupuesto=%s US$ no alcanza ni para un mensaje del precio mayor (%s US$): la escalera daría budget_exhausted a todo" % (sys.argv[2], mayor))
    sys.exit()
caben = int(pres // mayor)
print("ok" + (" el presupuesto deja %d mensajes al día al precio mayor, menos que el cupo (%d): frena antes el presupuesto" % (caben, cupo) if caben < cupo else ""))' "$@"
}
# Voz: el cupo, el techo y la reserva del H9 frente a los del H8 (JSON de leer_voz_h8). Imprime «ok [avisos]» o «no motivo».
comprobar_voz() { # cupo presupuesto reserva json_h8
  python3 -I -c '
import json, sys
from decimal import Decimal as D
M = D(1000000)
cupo, pres, res, h8 = int(sys.argv[1]), D(sys.argv[2]), D(sys.argv[3]), json.loads(sys.argv[4])
pm, rm = int(pres * M), int(res * M)
cap8, bud8, rmov, rfij, seg = h8.get("h8_cap"), h8.get("h8_budget_micros"), h8.get("h8_reserve_mobile_micros"), h8.get("h8_reserve_landline_micros"), h8.get("h8_max_seconds")
usd = lambda m: "%.4f" % (D(m) / M)
def no(m):
    print("no " + m)
    sys.exit()
if not isinstance(cap8, int) or cap8 <= 0:
    no("el cupo diario de llamadas del H8 (TWILIO_VOICE_DAILY_CALL_CAP) es %s: el H8 no deja llamar (h8-voice-scope.sh salientes on --cupo=N antes)" % cap8)
if cupo > cap8:
    no("--cupo=%d supera el cupo diario del H8 (%d): el del H9 es un recorte del del H8" % (cupo, cap8))
if not isinstance(bud8, int) or bud8 <= 0:
    no("el presupuesto diario de la voz del H8 (VOICE_DAILY_BUDGET_USD) es 0 o ilegible: el H8 no deja gastar")
if pm > bud8:
    no("--presupuesto=%s supera el presupuesto diario de la voz del H8 (%s US$)" % (sys.argv[2], usd(bud8)))
if not isinstance(rmov, int) or rmov <= 0:
    no("no se pudo calcular la reserva del H8 de una llamada (VoiceCostGuard)")
if rm < rmov:
    no("--reserva=%s es menor que la reserva del H8 de UNA llamada a móvil de %s s (%s US$, VoiceCostGuard): el techo del H9 contaría menos de lo que la puerta reserva" % (sys.argv[3], seg, usd(rmov)))
if rm > pm:
    no("--reserva=%s supera --presupuesto=%s: no cabría ni una llamada" % (sys.argv[3], sys.argv[2]))
avisos = []
if isinstance(rfij, int) and rm < rfij:
    avisos.append("la reserva es menor que la del H8 para un FIJO (%s US$): una llamada a fijo gasta más de lo que el H9 cuenta (el presupuesto del H8 sigue mandando)" % usd(rfij))
caben = pm // rm
if caben < cupo:
    avisos.append("el techo deja %d llamadas al día, menos que el cupo (%d): frena antes el techo" % (caben, cupo))
print("ok" + ("" if not avisos else " " + " · ".join(avisos)))' "$@"
}

# ── La escritura (python aislado, como root; los valores llegan por la entrada estándar, nunca por argumentos) ─────────
# Sustituye cada clave con la sangría que tuviera (o la añade con dos espacios), escribe un temporal en el mismo
# directorio (O_EXCL, O_NOFOLLOW, 600) con el dueño y el modo del original, fsync y os.replace. Una clave repetida aborta.
# Un valor vacío solo se admite para las claves de VACIAS_OK («horario legal» y «ventana ninguna»).
escribir() { # «$1» = JSON {CLAVE: valor} por la entrada estándar
  ENVF="$ENVF" VACIAS_OK="${VACIAS_OK:-}" python3 -I -c '
import json, os, re, stat, sys
p = os.environ["ENVF"]
vacias = set(os.environ.get("VACIAS_OK", "").split())
cambios = json.load(sys.stdin)
fd = os.open(p, os.O_RDONLY | os.O_NOFOLLOW)
st = os.fstat(fd)
if not stat.S_ISREG(st.st_mode):
    sys.exit("no es un fichero normal")
with os.fdopen(fd, encoding="utf-8") as fh:
    s = fh.read()
for k, v in cambios.items():
    if not re.fullmatch(r"[A-Z][A-Z0-9_]{1,63}", k) or not isinstance(v, str) or re.search(r"[\s#\"\x27\\$`]", v) or (v == "" and k not in vacias):
        sys.exit("clave o valor fuera de forma (no se imprime)")
    pat = re.compile(r"^([ \t]*)(?:export[ \t]+)?" + re.escape(k) + r"[ \t]*=.*$", re.M)
    n = len(pat.findall(s))
    if n > 1:
        sys.exit(k + " repetida en el fichero de entorno: deduplicar a mano")
    if n == 1:
        s = pat.sub(lambda m: m.group(1) + k + "=" + v, s, count=1)
        print("   " + k + " sustituida")
    else:
        if s and not s.endswith("\n"):
            s += "\n"
        s += "  " + k + "=" + v + "\n"
        print("   " + k + " añadida")
d = os.path.dirname(p)
tmp = os.path.join(d, ".env.h9-outreach-" + str(os.getpid()))
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
os.close(dfd)'
}
parsea() { sudo -u "$WWW" php -r 'require "vendor/autoload.php"; try { Dotenv\Dotenv::createArrayBacked(".")->load(); } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' > /dev/null 2>&1; }
cachea() {
  local c=bootstrap/cache/config.php
  sudo -u "$WWW" sh -c 'umask 077; php artisan config:cache -q' || return 1
  # bootstrap/cache lo escribe www-data: root no hace chmod ahí (seguiría un enlace puesto en su lugar; H-5 de SEC7).
  [ -f "$c" ] && [ ! -L "$c" ] || return 1
  [ "$(stat -c '%U' "$c")" = "$WWW" ] || return 1
  [ "$(stat -c '%a' "$c")" = 600 ] || sudo -u "$WWW" chmod 600 "$c" || return 1
}

# ── Las órdenes: cada una deja en CAMBIOS el JSON {CLAVE: valor} y en ORDEN su nombre ───────────────────────────────
ORDEN=""; CAMBIOS=""; VUELTA_FUNCIONAL=""; VACIAS_OK=""; ORDEN_ESCRITA=""; MOTIVO=""; AVISO_ORDEN=""; VACIO='{}'
json_de() { python3 -I -c 'import json, sys; print(json.dumps(dict(a.split("=", 1) for a in sys.argv[1:]), sort_keys=True))' "$@"; }
# Solo las claves que el código DESPLEGADO lee (su campo no es null en ANTES): `apagar` y `canal voz off` no escriben un
# cupo que la configuración desplegada no conoce (el mando de otro commit no deja claves huérfanas en el entorno).
json_leidas() {
  ANTES="$ANTES" MAPA="$MAPA" python3 -I -c '
import json, os, sys
a = json.loads(os.environ["ANTES"]); mapa = json.loads(os.environ["MAPA"])
c = dict(x.split("=", 1) for x in sys.argv[1:])
fuera = sorted(k for k in c if k in mapa and a.get(mapa[k][0]) is None)
for k in fuera:
    del c[k]
    print("   (" + k + " no se escribe: el código desplegado no la lee)", file=sys.stderr)
print(json.dumps(c, sort_keys=True))' "$@"
}

ANTES=$(leer_outreach)
[ -n "$ANTES" ] || aborta "no se pudo leer la configuración efectiva del H9 (tinker falló o la aplicación no arranca; su salida no se imprime)"
campo() { printf '%s' "$ANTES" | python3 -I -c 'import json, sys; v = json.load(sys.stdin).get(sys.argv[1]); print(json.dumps(v) if not isinstance(v, str) else v)' "$1"; }
CONST=$(constante "$(printf '%s' "$ANTES" | grep -oE '"sends_in_code":(true|false|null)')")
DEPLOYED=$(campo deployed); STRAT_DEPLOYED=$(campo strategies_deployed); STREAM_DEPLOYED=$(campo stream_deployed)
ENABLED=$(campo enabled); MODE=$(campo mode); ASSISTED=$(campo assisted); WHATSAPP=$(campo whatsapp); VOICE=$(campo voice)
# La fase que se deduce de las banderas (§14), para el estado y los avisos.
fase_de() { # enabled mode assisted whatsapp voice
  case "$1 $2" in
    "true shadow") echo "2 (sombra: todo simulado, ni un contacto)" ;;
    "true live")
      if [ "$4" = true ] && [ "$5" = true ]; then echo "4–6 (live: WhatsApp y voz encendidos)"
      elif [ "$4" = true ]; then echo "4–5 (live: WhatsApp encendido)"
      elif [ "$5" = true ]; then echo "6 (live: voz por estrategia encendida)"
      elif [ "$3" = true ]; then echo "3 (asistido: live solo con el canal asistido)"
      else echo "live sin canales (nada sale; el selector aplaza)"; fi ;;
    "true "*) echo "incoherente (maestra encendida con modo «$2»: el código lo trata como apagado)" ;;
    *) if [ "$3" = true ] || [ "$4" = true ] || [ "$5" = true ]; then echo "1 (todo apagado por la maestra; AVISO: hay canales con su bandera encendida, inertes)"; else echo "1 (todo apagado)"; fi ;;
  esac
}

if [ "$1" = estado ]; then
  [ "$#" = 1 ] || { uso; exit 2; }
  echo "== estado de la automatización comercial del H9 (configuración efectiva del proceso CLI, la misma caché que el web; sin valores del fichero de entorno)"
  echo "   código desplegado: HEAD $(git -C "$APPDIR/.." rev-parse --short=7 HEAD 2>/dev/null || echo '?') · constante de envío (OutreachSendGate): $CONST · clase: $(campo sends_gate)"
  if [ "$DEPLOYED" = true ]; then
    echo "   fase deducida: $(fase_de "$ENABLED" "$MODE" "$ASSISTED" "$WHATSAPP" "$VOICE")"
    printf '%s' "$ANTES" | python3 -I -c '
import json, sys
d = json.load(sys.stdin)
b = lambda k: "sí" if d.get(k) is True else ("no" if d.get(k) is False else "?")
x = lambda k: d.get(k) if d.get(k) not in (None, "") else "—"
print("   nivel 0 · maestra COMMERCIAL_AUTOMATION_ENABLED: %s · modo: %s · programador: %s · monitor: %s%s" % (b("enabled"), x("mode"), b("scheduler"), b("monitor"), "" if d.get("enabled") is True else " · las 12 rutas de la ejecución salvo GET status responden 409 outreach_disabled (L9-47)"))
print("   asistido: %s · tope/día %s" % (b("assisted"), x("assisted_cap")))
print("   WhatsApp (W9): %s · tope/día %s · presupuesto/día %s US$ · precio por mensaje: marketing %s, utilidad %s US$ (0 = budget_zero) · clase %s · declarado %s" % (b("whatsapp"), x("whatsapp_cap"), x("whatsapp_budget"), x("price_marketing"), x("price_utility"), b("class_whatsapp"), b("live_whatsapp")))
print("   voz por estrategia (V9): %s · cupo/día %s · techo/día %s US$ · reserva/llamada %s US$ (cualquiera en 0 = budget_zero) · clase %s · declarado %s" % (b("voice"), x("voice_cap"), x("voice_budget"), x("voice_reserve"), b("class_voice"), b("live_voice")))
print("   clasificador (AI9): %s" % b("classifier"))
print("   horario propio (COMMERCIAL_AUTOMATION_OWN_HOURS): %s" % ("el legal (vacío)" if d.get("own_hours") in (None, "") else d.get("own_hours") + ("  (entre semana;sábado)" if ";" in d.get("own_hours") else "  (todos los días)")))'
  else
    echo "   fase deducida: SIN DESPLEGAR (config/outreach.php no existe en el código desplegado: la fase 1 del H9 no está, o B9-run no entregó la ejecución)"
  fi
  if [ "$STRAT_DEPLOYED" = true ]; then
    printf '%s' "$ANTES" | python3 -I -c '
import json, sys
d = json.load(sys.stdin)
b = lambda k: "sí" if d.get(k) is True else ("no" if d.get(k) is False else "?")
x = lambda k: d.get(k) if d.get(k) not in (None,) else "—"
print("   gobierno del Studio (S9): strategies/** %s · publicar/promover/reanudar %s · ventana %s · canario ≥ %s h y ≥ %s contactos · simulación %s" % (b("strategies"), b("strategies_publishing"), ("ninguna (sin ventana)" if d.get("strategies_window") == "" else x("strategies_window")), x("canary_min_hours"), x("canary_min_touches"), x("simulation_gate")))'
  else
    echo "   gobierno del Studio (S9): sin desplegar (config/ultron_studio.php no tiene el bloque strategies)"
  fi
  if [ "$STREAM_DEPLOYED" = true ]; then echo "   tiempo real del Studio (SSE9, ULTRON_STUDIO_STREAM_ENABLED): $([ "$(campo stream)" = true ] && echo sí || echo "no (409 stream_disabled; el CRM sondea)")"; else echo "   tiempo real del Studio (SSE9): sin desplegar (config/ultron_studio_stream.php no existe)"; fi
  printf '%s' "$(leer_cuentas)" | python3 -I -c '
import json, sys
d = json.load(sys.stdin)
x = lambda k: d.get(k) if d.get(k) is not None else "—"
v = d.get("live_today") or {}
print("   base (solo lectura): frenos abiertos %s (globales %s) · trabajos vivos %s · pasadas %s · personas vinculadas y verificadas %s · versiones en canario o publicadas %s · tablas del H9 presentes %s/2" % (x("brakes_open"), x("brakes_global"), x("jobs_live"), x("runs"), x("bindings_verified"), x("versions_live"), x("tables_h9")))
print("   reservado hoy en vivo por canal: %s" % (", ".join("%s %s" % (k.split(":", 1)[1], n) for k, n in sorted(v.items())) if v else "nada"))'
  O=$(ordenes_outreach); echo "   órdenes outreach:* desplegadas: ${O:-ninguna} (encender exige: $ORDENES_REQUERIDAS)"
  P=$(programador); echo "   programador (total · del Studio · de voz · de outreach): $P (de outreach: $TAREAS_PROGRAMADOR con el programador encendido, 0 apagado; el monitor no cambia el recuento)"
  echo "   workers (RUNNING · BACKOFF/FATAL): $(workers 999 0)"
  if fichero_ok; then echo "   fichero de entorno: $(stat -c '%U:%G %a' "$ENVF") (C-14: www-data 600 o root:www-data 640)"; else echo "   fichero de entorno: $(stat -c '%U:%G %a' "$ENVF" 2>/dev/null || echo '?') — NO ADMITIDO (C-14): ninguna orden que escriba seguirá"; fi
  R=$(leer_resto)
  printf '%s' "${R:-$VACIO}" | python3 -I -c '
import json, sys
d = json.load(sys.stdin)
b = lambda k: "sí" if d.get(k) is True else ("no" if d.get(k) is False else "?")
print("   motor comercial (P9-24; el mando solo mueve los eventos, con `eventos`): eventos %s · NBA %s · autonomía %s" % (b("commercial_events"), b("commercial_nba"), b("commercial_autonomy")))' 2>/dev/null || true
  echo "   motor, Legacy, H4–H7 y voz del H8 (informativo; el mando no los toca salvo COMMERCIAL_EVENTS_ENABLED): ${R:-ilegible}"
  exit 0
fi

# ── Análisis de la orden ───────────────────────────────────────────────────────────────────────────────────────────
exige_outreach() { [ "$DEPLOYED" = true ] || niega "config/outreach.php no está desplegado: la configuración efectiva de COMMERCIAL_AUTOMATION_* no se puede comprobar (¿está la fase 1 del H9?)"; }
exige_studio() { [ "$STRAT_DEPLOYED" = true ] || niega "el bloque strategies de config/ultron_studio.php (S9) no está desplegado"; }
exige_motor() {
  RESTO0=$(leer_resto)
  [ -n "$RESTO0" ] || niega "no se pudo leer la configuración del motor comercial (tinker; su salida no se imprime)"
  case "$RESTO0" in *'"commercial_deployed":true'*) ;; *) niega "config/commercial.php no está desplegado: no hay motor comercial cuyos eventos encender ni apagar" ;; esac
}
exige_ordenes() {
  local o k
  o=$(ordenes_outreach)
  for k in $ORDENES_REQUERIDAS; do case " $o " in *" $k "*) ;; *) niega "la orden $k no existe en el código desplegado (hay: ${o:-ninguna}): sin el monitor y la PARADA GLOBAL (nivel 0b) no se enciende nada" ;; esac; done
}
exige_constante() { [ "$CONST" = abierta ] || niega "la constante OutreachSendGate::SENDS_ENABLED_IN_CODE está «$CONST» en el código desplegado: el envío automático hacia personas exige el commit de una línea \`$PREFIJO_ENVIOS …\` con la autorización escrita del propietario y el visto bueno legal (§14 fase 4), desplegado antes de este mando"; }
exige_live() { [ "$ENABLED" = true ] && [ "$MODE" = live ] || niega "la automatización no está en modo live (maestra $ENABLED, modo $MODE): primero \`$0 asistido on --cupo=N\` (la fase 3 precede a la 4 y a la 6)"; }
opcion_orden_escrita() { # recorre "$@" buscando --orden-escrita=<ref>
  local a
  for a in "$@"; do case "$a" in --orden-escrita=*) ORDEN_ESCRITA=${a#*=} ;; *) niega "opción desconocida: $a" ;; esac; done
  [ -n "$ORDEN_ESCRITA" ] || niega "esta orden la decide el propietario (§9.1): hace falta --orden-escrita=<referencia de su autorización escrita>"
  es_referencia "$ORDEN_ESCRITA" || niega "--orden-escrita: letras, dígitos, punto, guion y barra (3–60)"
}
case "$1 ${2:-}" in
  "apagar ")
    [ "$#" = 1 ] || { uso; exit 2; }
    exige_outreach
    ORDEN=apagar
    # L9-52 Q-7: además de la maestra, el modo, el programador, el monitor y los tres canales, los cupos y techos a 0 y
    # el clasificador apagado. Los precios de WhatsApp y la reserva por llamada de la voz NO son topes (son tarifas):
    # se quedan; con su cupo y su techo a 0 el canal ya es `budget_zero`.
    CAMBIOS=$(json_leidas COMMERCIAL_AUTOMATION_ENABLED=false COMMERCIAL_AUTOMATION_MODE=off COMMERCIAL_AUTOMATION_SCHEDULER_ENABLED=false COMMERCIAL_AUTOMATION_MONITOR_ENABLED=false COMMERCIAL_AUTOMATION_ASSISTED_ENABLED=false COMMERCIAL_AUTOMATION_WHATSAPP_ENABLED=false COMMERCIAL_AUTOMATION_VOICE_ENABLED=false COMMERCIAL_AUTOMATION_CLASSIFIER_ENABLED=false COMMERCIAL_AUTOMATION_ASSISTED_DAILY_CAP=0 COMMERCIAL_AUTOMATION_WHATSAPP_DAILY_CAP=0 COMMERCIAL_AUTOMATION_WHATSAPP_DAILY_BUDGET_USD=0 COMMERCIAL_AUTOMATION_VOICE_DAILY_CAP=0 COMMERCIAL_AUTOMATION_VOICE_DAILY_BUDGET_USD=0) ;;
  "parada-global "*)
    ORDEN=parada-global; shift 1
    for a in "$@"; do case "$a" in --motivo=*) MOTIVO=${a#*=} ;; *) niega "opción desconocida: $a (solo --motivo=\"…\")" ;; esac; done
    exige_outreach
    [ -n "$MOTIVO" ] || niega "la PARADA GLOBAL pide un motivo: --motivo=\"…\" (5–200 caracteres; queda en el freno)"
    [ "${#MOTIVO}" -ge 5 ] && [ "${#MOTIVO}" -le 200 ] && [[ ! "$MOTIVO" =~ [\"\'\`\$\\] ]] || niega "--motivo: 5–200 caracteres, sin comillas, acentos graves, \$ ni barras invertidas"
    O=$(ordenes_outreach); case " $O " in *" outreach:stop "*) ;; *) niega "la orden outreach:stop no existe en el código desplegado (hay: ${O:-ninguna}): el nivel 0b no está disponible; usar el nivel 0: $0 apagar" ;; esac
    exec 9> "$CERROJO"
    flock -n 9 || aborta "otro mando del H9 está en curso ($CERROJO)"
    C0=$(leer_cuentas)
    echo "0. PARADA GLOBAL (nivel 0b, D9-14) · antes: $C0"
    set +e
    SALIDA=$(sudo -u "$WWW" php artisan outreach:stop --all --reason="$MOTIVO" 2>&1); RC=$?
    set -e
    # Solo las líneas sin 10 cifras seguidas (ningún teléfono ni id largo) y de caracteres imprimibles.
    printf '%s\n' "$SALIDA" | grep -vE '[0-9]{10,}' | grep -E '^[[:print:]]{0,240}$' | head -20 | sed 's/^/   /'
    C1=$(leer_cuentas)
    GLOBALES=$(printf '%s' "$C1" | python3 -I -c 'import json, sys; print(json.load(sys.stdin).get("brakes_global"))')
    echo "1. outreach:stop --all salió con $RC · después: $C1"
    [ "$RC" = 0 ] || desvio "outreach:stop --all no salió con 0 (código $RC)"
    [ "$GLOBALES" != None ] && [ "$GLOBALES" -ge 1 ] 2>/dev/null || desvio "tras la parada no hay un freno global abierto en outreach_brakes (globales: $GLOBALES)"
    echo "   efecto (D9-14): en ≤ 1 pasada del despachador (≈ 60 s) no se reclama ni sale nada nuevo; lo que ya está en el proveedor no se recoge (W9 depende de Meta; V9 tiene el corte del H8, voice:hangup, que el H9 no acciona). Con la voz viva, echa además el freno de voz del H8: el job de una saliente ya aceptada solo mira los cerrojos del H8 (SC-5)"
    echo "   soltarla: una persona con commercial_automation.release, distinta de quien la echó, con nota, desde el CRM (POST brakes/{uuid}/release); NUNCA desde aquí. Los retenidos pasan otra vez por la etapa 6"
    if [ "$DESVIOS" = 0 ]; then echo "MANDO parada-global APLICADO (sin tocar el fichero de entorno)"; exit 0; else echo "MANDO parada-global CON $DESVIOS DESVÍOS: comprobar el freno global en el CRM (GET brakes) ya; si no está, el nivel 0: $0 apagar"; exit 1; fi ;;
  "sombra on")
    [ "$#" = 2 ] || { uso; exit 2; }
    exige_outreach; exige_ordenes
    ORDEN=sombra-on
    CAMBIOS=$(json_de COMMERCIAL_AUTOMATION_ENABLED=true COMMERCIAL_AUTOMATION_MODE=shadow COMMERCIAL_AUTOMATION_SCHEDULER_ENABLED=true COMMERCIAL_AUTOMATION_MONITOR_ENABLED=true)
    VUELTA_FUNCIONAL="bash $0 apagar" ;;
  "sombra off")
    niega "la sombra se apaga con el nivel 0: $0 apagar" ;;
  "asistido on")
    ORDEN=asistido-on; shift 2
    exige_outreach; exige_ordenes
    CUPO=""
    for a in "$@"; do case "$a" in --cupo=*) CUPO=${a#*=} ;; *) niega "opción desconocida: $a (solo --cupo=N)" ;; esac; done
    [ -n "$CUPO" ] || niega "asistido on pide --cupo=N (trabajos asistidos al día, 1–500)"
    es_entero "$CUPO" 1 500 || niega "--cupo: entero de 1 a 500"
    [ "$ENABLED" = true ] || niega "la maestra está apagada: primero la fase 2 (\`$0 sombra on\`, ≥ 7 días mirando decisiones, P9-11) y la autorización escrita de la fase 3"
    CAMBIOS=$(json_de COMMERCIAL_AUTOMATION_MODE=live COMMERCIAL_AUTOMATION_ASSISTED_ENABLED=true "COMMERCIAL_AUTOMATION_ASSISTED_DAILY_CAP=$CUPO")
    VUELTA_FUNCIONAL="bash $0 asistido off  (y bash $0 sombra on para volver a la fase 2)" ;;
  "asistido off")
    [ "$#" = 2 ] || { uso; exit 2; }
    exige_outreach
    ORDEN=asistido-off
    CAMBIOS=$(json_de COMMERCIAL_AUTOMATION_ASSISTED_ENABLED=false COMMERCIAL_AUTOMATION_ASSISTED_DAILY_CAP=0) ;;
  "canal whatsapp"|"canal voz")
    CANAL=$2; shift 2
    exige_outreach
    case "${1:-}" in
      on)
        shift 1
        # Primero la forma de las opciones (no lee nada); después el estado (las órdenes, el modo live, la constante, la
        # clase y su declaración); por último lo que se cruza con otros límites. Todas las negativas salen con código 2.
        CUPO=""; PRES=""; PM=""; PU=""; RES=""
        if [ "$CANAL" = whatsapp ]; then
          ORDEN=canal-whatsapp-on
          for a in "$@"; do case "$a" in --cupo=*) CUPO=${a#*=} ;; --presupuesto=*) PRES=${a#*=} ;; --precio-marketing=*) PM=${a#*=} ;; --precio-utilidad=*) PU=${a#*=} ;; *) niega "opción desconocida: $a" ;; esac; done
          [ -n "$CUPO" ] && [ -n "$PRES" ] && [ -n "$PM" ] && [ -n "$PU" ] || niega "canal whatsapp on pide --cupo=N (mensajes al día, 1–500), --presupuesto=USD (0.01–999 al día), --precio-marketing=USD y --precio-utilidad=USD (la tarifa de Meta por mensaje entregado de cada categoría, > 0 y ≤ 1, hasta 6 decimales). Con un precio 0 la escalera niega budget_zero a esa categoría (PolicyLadder N4): el canal quedaría encendido y negado"
          es_entero "$CUPO" 1 500 || niega "--cupo: entero de 1 a 500"
          es_importe "$PRES" 2 0.01 999 || niega "--presupuesto: US$ con hasta dos decimales, de 0.01 a 999"
          es_importe "$PM" 6 0.000001 1 || niega "--precio-marketing: US$ por mensaje entregado, > 0 y ≤ 1, hasta seis decimales (0 deja la categoría en budget_zero)"
          es_importe "$PU" 6 0.000001 1 || niega "--precio-utilidad: US$ por mensaje entregado, > 0 y ≤ 1, hasta seis decimales (0 deja la categoría en budget_zero)"
          exige_ordenes; exige_live; exige_constante
          [ "$(campo class_whatsapp)" = true ] && [ "$(campo live_whatsapp)" = true ] || niega "el canal de WhatsApp por plantillas (W9) no está en el código desplegado o no está declarado (clase $CLASE_WHATSAPP: $(campo class_whatsapp); whatsapp_template en LIVE_CHANNELS: $(campo live_whatsapp))"
          [ "$(campo price_marketing)" != null ] && [ "$(campo price_utility)" != null ] || niega "el código desplegado no lee los precios de WhatsApp (outreach.whatsapp.price_marketing_usd y price_utility_usd)"
          R=$(comprobar_whatsapp "$CUPO" "$PRES" "$PM" "$PU")
          case "$R" in ok*) AVISO_ORDEN=${R#ok} ;; "no "*) niega "${R#no }" ;; *) niega "la comprobación del presupuesto de WhatsApp no devolvió nada legible" ;; esac
          CAMBIOS=$(json_de COMMERCIAL_AUTOMATION_WHATSAPP_ENABLED=true "COMMERCIAL_AUTOMATION_WHATSAPP_DAILY_CAP=$CUPO" "COMMERCIAL_AUTOMATION_WHATSAPP_DAILY_BUDGET_USD=$PRES" "OUTREACH_WHATSAPP_PRICE_MARKETING_USD=$PM" "OUTREACH_WHATSAPP_PRICE_UTILITY_USD=$PU")
          VUELTA_FUNCIONAL="bash $0 canal whatsapp off"
        else
          ORDEN=canal-voz-on
          for a in "$@"; do case "$a" in --cupo=*) CUPO=${a#*=} ;; --presupuesto=*) PRES=${a#*=} ;; --reserva=*) RES=${a#*=} ;; *) niega "opción desconocida: $a" ;; esac; done
          [ -n "$CUPO" ] && [ -n "$PRES" ] && [ -n "$RES" ] || niega "canal voz on pide --cupo=N (llamadas al día, 1–20, ≤ el cupo del H8), --presupuesto=USD (techo diario, 0.01–999, ≤ el del H8) y --reserva=USD (lo que se reserva por llamada, ≥ la reserva del H8 de una llamada a móvil). Con cualquiera de los tres en 0 la voz queda en budget_zero"
          es_entero "$CUPO" 1 20 || niega "--cupo: entero de 1 a 20 (el recorte del cupo del H8)"
          es_importe "$PRES" 2 0.01 999 || niega "--presupuesto: US$ con hasta dos decimales, de 0.01 a 999"
          es_importe "$RES" 4 0.01 50 || niega "--reserva: US$ por llamada con hasta cuatro decimales, de 0.01 a 50"
          exige_ordenes; exige_live; exige_constante
          [ "$(campo class_voice)" = true ] && [ "$(campo live_voice)" = true ] || niega "la voz por estrategia (V9) no está en el código desplegado o no está declarada (clase $CLASE_VOZ: $(campo class_voice); voice_call en LIVE_CHANNELS: $(campo live_voice))"
          [ "$(campo voice_cap)" != null ] && [ "$(campo voice_budget)" != null ] && [ "$(campo voice_reserve)" != null ] || niega "el código desplegado no lee el cupo, el techo y la reserva de la voz del H9 (outreach.voice.daily_cap, daily_budget_usd y reserve_usd_per_call)"
          RESTO0=$(leer_resto)
          case "$RESTO0" in *'"calls_in_code":true'*) ;; *) niega "VoiceCallPolicy::CALLS_ENABLED_IN_CODE está cerrada: la fase 6 exige las fases 4a/4b del H8 activas (h8-voice-scope.sh)" ;; esac
          case "$RESTO0" in *'"voice_outbound":true'*) ;; *) niega "las salientes del H8 están apagadas (VOICE_OUTBOUND_ENABLED): la fase 6 exige las fases 4a/4b del H8 activas (h8-voice-scope.sh salientes on)" ;; esac
          V8=$(leer_voz_h8)
          [ -n "$V8" ] || niega "no se pudieron leer los límites de la voz del H8 (VoiceCostGuard; tinker: su salida no se imprime)"
          R=$(comprobar_voz "$CUPO" "$PRES" "$RES" "$V8")
          case "$R" in ok*) AVISO_ORDEN=${R#ok} ;; "no "*) niega "${R#no }" ;; *) niega "la comprobación de la voz frente al H8 no devolvió nada legible" ;; esac
          CAMBIOS=$(json_de COMMERCIAL_AUTOMATION_VOICE_ENABLED=true "COMMERCIAL_AUTOMATION_VOICE_DAILY_CAP=$CUPO" "COMMERCIAL_AUTOMATION_VOICE_DAILY_BUDGET_USD=$PRES" "COMMERCIAL_AUTOMATION_VOICE_RESERVE_USD=$RES")
          VUELTA_FUNCIONAL="bash $0 canal voz off"
        fi ;;
      off)
        [ "$#" = 1 ] || niega "canal $CANAL off no lleva opciones"
        if [ "$CANAL" = whatsapp ]; then ORDEN=canal-whatsapp-off; CAMBIOS=$(json_de COMMERCIAL_AUTOMATION_WHATSAPP_ENABLED=false COMMERCIAL_AUTOMATION_WHATSAPP_DAILY_CAP=0 COMMERCIAL_AUTOMATION_WHATSAPP_DAILY_BUDGET_USD=0)
        else ORDEN=canal-voz-off; CAMBIOS=$(json_leidas COMMERCIAL_AUTOMATION_VOICE_ENABLED=false COMMERCIAL_AUTOMATION_VOICE_DAILY_CAP=0 COMMERCIAL_AUTOMATION_VOICE_DAILY_BUDGET_USD=0); fi ;;
      *) uso; exit 2 ;;
    esac ;;
  "programador on"|"programador off"|"monitor on"|"monitor off"|"clasificador on"|"clasificador off")
    [ "$#" = 2 ] || { uso; exit 2; }
    exige_outreach
    ORDEN="$1-$2"
    V=false; [ "$2" = on ] && V=true
    case "$1" in
      programador) [ "$2" = off ] || exige_ordenes; K=COMMERCIAL_AUTOMATION_SCHEDULER_ENABLED ;;
      monitor) [ "$2" = off ] || exige_ordenes; K=COMMERCIAL_AUTOMATION_MONITOR_ENABLED ;;
      clasificador) [ "$2" = off ] || [ "$(clases_outreach Classifier)" -ge 1 ] || niega "el clasificador de respuestas (AI9) no está en el código desplegado"; K=COMMERCIAL_AUTOMATION_CLASSIFIER_ENABLED ;;
    esac
    CAMBIOS=$(json_de "$K=$V")
    [ "$2" = off ] || VUELTA_FUNCIONAL="bash $0 $1 off" ;;
  "horario set")
    shift 2
    exige_outreach
    ORDEN=horario-set
    VAL=${1:-}; [ -n "$VAL" ] || { uso; exit 2; }; shift 1
    SAB=""
    for a in "$@"; do case "$a" in --sabado=*) [ -z "$SAB" ] || niega "--sabado repetido"; SAB=${a#*=}; [ -n "$SAB" ] || niega "--sabado=HH:MM-HH:MM o --sabado=cerrado" ;; *) niega "opción desconocida: $a (solo --sabado=HH:MM-HH:MM|cerrado)" ;; esac; done
    if [ "$VAL" = legal ]; then
      [ -z "$SAB" ] || niega "horario set legal no lleva --sabado (legal = sin horario propio: rige la ley todos los días)"
      VACIAS_OK=COMMERCIAL_AUTOMATION_OWN_HOURS
      CAMBIOS=$(json_de COMMERCIAL_AUTOMATION_OWN_HOURS=)
    else
      case "$VAL" in *';'*) niega "el tramo de los sábados va con --sabado=HH:MM-HH:MM (o --sabado=cerrado), no con «;» en la línea de órdenes (sin comillas, el shell lo cortaría en dos órdenes)" ;; esac
      MV=$(minutos_ventana "$VAL") || niega "horario set: HH:MM-HH:MM con inicio anterior al fin, o «legal»"
      read -r INI FIN <<< "$MV"
      [ "$INI" -ge 480 ] && [ "$FIN" -le 1140 ] || niega "el horario propio solo puede ser MÁS estricto que el permitido (P9-17, L9-32): entre semana dentro de 08:00-19:00 (la ley permite desde las 07:00, pero las horas de silencio del gimnasio terminan a las 08:00)"
      H=$VAL
      if [ -n "$SAB" ]; then
        if [ "$SAB" = cerrado ]; then
          H="$VAL;-"
        else
          MS=$(minutos_ventana "$SAB") || niega "--sabado: HH:MM-HH:MM con inicio anterior al fin, o «cerrado»"
          read -r SI SF <<< "$MS"
          [ "$SI" -ge 480 ] && [ "$SF" -le 900 ] || niega "el tramo de los sábados solo puede ser MÁS estricto que el permitido: dentro de 08:00-15:00 (Ley 2300, art. 3, y el silencio hasta las 08:00; P9-17)"
          H="$VAL;$SAB"
        fi
      fi
      CAMBIOS=$(json_de "COMMERCIAL_AUTOMATION_OWN_HOURS=$H")
    fi
    VUELTA_FUNCIONAL=$(vuelta_horario "$(campo own_hours)") ;;
  "gobierno on"|"gobierno off"|"publicar on"|"publicar off")
    [ "$#" = 2 ] || { uso; exit 2; }
    exige_studio
    ORDEN="$1-$2"
    V=false; [ "$2" = on ] && V=true
    case "$1" in gobierno) K=ULTRON_STUDIO_STRATEGIES_ENABLED ;; publicar) K=ULTRON_STUDIO_STRATEGIES_PUBLISHING_ENABLED ;; esac
    CAMBIOS=$(json_de "$K=$V")
    [ "$2" = off ] || VUELTA_FUNCIONAL="bash $0 $1 off" ;;
  "ventana set")
    exige_studio
    ORDEN=ventana-set; shift 2
    VAL=${1:-}; [ -n "$VAL" ] || { uso; exit 2; }; shift 1
    if [ "$VAL" = ninguna ]; then
      opcion_orden_escrita "$@"
      VACIAS_OK=ULTRON_STUDIO_STRATEGIES_PUBLISH_WINDOW
      CAMBIOS=$(json_de ULTRON_STUDIO_STRATEGIES_PUBLISH_WINDOW=)
    else
      [ "$#" = 0 ] || niega "ventana set HH:MM-HH:MM no lleva más opciones"
      minutos_ventana "$VAL" > /dev/null || niega "ventana set: HH:MM-HH:MM (Bogotá) con inicio anterior al fin, o «ninguna» con --orden-escrita"
      CAMBIOS=$(json_de "ULTRON_STUDIO_STRATEGIES_PUBLISH_WINDOW=$VAL")
    fi
    W=$(campo strategies_window); VUELTA_FUNCIONAL="bash $0 ventana set $([ -z "$W" ] || [ "$W" = null ] && echo 'ninguna --orden-escrita=<ref>' || echo "$W")" ;;
  "canario set")
    exige_studio
    ORDEN=canario-set; shift 2
    HORAS=""; CONTACTOS=""
    for a in "$@"; do case "$a" in --horas=*) HORAS=${a#*=} ;; --contactos=*) CONTACTOS=${a#*=} ;; *) niega "opción desconocida: $a" ;; esac; done
    [ -n "$HORAS" ] && [ -n "$CONTACTOS" ] || niega "canario set pide --horas=N (1–168) y --contactos=N (1–100)"
    es_entero "$HORAS" 1 168 || niega "--horas: entero de 1 a 168"
    es_entero "$CONTACTOS" 1 100 || niega "--contactos: entero de 1 a 100"
    CAMBIOS=$(json_de "ULTRON_STUDIO_STRATEGIES_CANARY_MIN_HOURS=$HORAS" "ULTRON_STUDIO_STRATEGIES_CANARY_MIN_TOUCHES=$CONTACTOS")
    VUELTA_FUNCIONAL="bash $0 canario set --horas=$(campo canary_min_hours) --contactos=$(campo canary_min_touches)" ;;
  "simulacion required"|"simulacion waived")
    exige_studio
    ORDEN="simulacion-$2"; shift 2
    if [ "$ORDEN" = simulacion-waived ]; then opcion_orden_escrita "$@"; else [ "$#" = 0 ] || niega "simulacion required no lleva opciones"; fi
    CAMBIOS=$(json_de "ULTRON_STUDIO_STRATEGIES_SIMULATION_GATE=${ORDEN#simulacion-}")
    VUELTA_FUNCIONAL="bash $0 simulacion $(campo simulation_gate)$([ "$(campo simulation_gate)" = waived ] && echo ' --orden-escrita=<ref>')" ;;
  "stream on"|"stream off")
    [ "$#" = 2 ] || { uso; exit 2; }
    [ "$STREAM_DEPLOYED" = true ] || niega "config/ultron_studio_stream.php (SSE9) no está desplegado: no hay canal que encender ni apagar"
    ORDEN="stream-$2"
    V=false; [ "$2" = on ] && V=true
    CAMBIOS=$(json_de "ULTRON_STUDIO_STREAM_ENABLED=$V")
    [ "$2" = off ] || VUELTA_FUNCIONAL="bash $0 stream off  (el CRM vuelve solo al sondeo)" ;;
  "eventos medir")
    # P9-24, solo lectura: ni copia, ni escritura, ni cerrojo.
    [ "$#" = 2 ] || { uso; exit 2; }
    exige_motor
    medir_eventos
    echo "MEDIDA DE P9-24 (no se ha tocado nada). Encender: bash $0 eventos on --orden-escrita=<referencia de la autorización del propietario>"
    exit 0 ;;
  "eventos on")
    ORDEN=eventos-on; shift 2
    exige_outreach; exige_motor
    opcion_orden_escrita "$@"
    case "$RESTO0" in *'"commercial_nba":false'*) ;; *) niega "COMMERCIAL_NBA_ENABLED no está apagado: P9-24 se decidió con el motor que detecta pero no decide; con NBA encendido cada hecho encolaría una evaluación y abriría oportunidades (otra decisión del propietario, con otro análisis)" ;; esac
    case "$RESTO0" in *'"commercial_autonomy":false'*) ;; *) niega "COMMERCIAL_AUTONOMY_ENABLED no está apagado: P9-24 se decidió con el motor que no ejecuta nada" ;; esac
    medir_eventos
    CAMBIOS=$(json_de COMMERCIAL_EVENTS_ENABLED=true)
    VUELTA_FUNCIONAL="bash $0 eventos off" ;;
  "eventos off")
    [ "$#" = 2 ] || { uso; exit 2; }
    exige_motor
    ORDEN=eventos-off
    CAMBIOS=$(json_de COMMERCIAL_EVENTS_ENABLED=false) ;;
  *) uso; exit 2 ;;
esac
[ -n "$CAMBIOS" ] && [ "$CAMBIOS" != '{}' ] || aborta "la orden no dejó cambios (error del mando)"

# ── Comprobaciones comunes de solo lectura (si algo falla, no se toca nada) ──────────────────────────────────────────
fichero_ok || aborta "el fichero de entorno no es un fichero normal de $WWW en 600 ni de $ROOT_U:$WWW en 640 (es $(stat -c '%U:%G %a' "$ENVF" 2>/dev/null || echo '?')): corregirlo a mano antes (C-14, N-4)"
exec 9> "$CERROJO"
flock -n 9 || aborta "otro mando del H9 está en curso ($CERROJO)"
for k in $(printf '%s' "$CAMBIOS" | python3 -I -c 'import json, sys; print(" ".join(json.load(sys.stdin)))'); do
  [ "$(cuenta_var "$k")" -le 1 ] || aborta "$k aparece repetida en el fichero de entorno (gana la última): deduplicar antes"
done
YA=$(printf '%s' "$CAMBIOS" | ENVF="$ENVF" python3 -I -c '
import json, os, re, sys
c = json.load(sys.stdin)
s = open(os.environ["ENVF"], encoding="utf-8").read()
def valor(k):
    m = re.search(r"^[ \t]*(?:export[ \t]+)?" + re.escape(k) + r"[ \t]*=[ \t]*(.*?)[ \t]*$", s, re.M)
    return None if m is None else m.group(1).strip("\"")
print("si" if all(valor(k) == v for k, v in c.items()) else "no")')
RESTO_ANTES=$(leer_resto)
[ -n "$RESTO_ANTES" ] || aborta "no se pudo leer la configuración del motor, de Legacy, del H4–H7 y de la voz (tinker falló; su salida no se imprime)"
PROG0=$(programador)
case "$PROG0" in "FALLO "*|ILEGIBLE) aborta "schedule:list no se puede leer ($PROG0): sin el programador no se toca nada (m-1 del H7)" ;; esac
read -r TOTAL0 STUDIO0 VOZ0 OUT0 <<< "$PROG0"
W0=$(workers 999 0); RUN0=${W0%% *}
[ "${W0#* }" = 0 ] || aborta "workers con BACKOFF o FATAL antes de empezar ($W0): no se toca nada sobre un sistema degradado"
[ "$RUN0" -ge 13 ] && [ "$RUN0" -le 16 ] || aborta "workers RUNNING antes de empezar: $RUN0 (esperado de 13 a 16: los 13 de producción, ironbody-voice si el H8 lo instaló y, si B9-run lo trae, el del H9)"
CUENTAS0=$(leer_cuentas)
if [ "$YA" = si ]; then
  echo "== $ORDEN: el fichero de entorno ya tiene esos valores (no se imprimen); nada que escribir"
  echo "   fase deducida: $(fase_de "$ENABLED" "$MODE" "$ASSISTED" "$WHATSAPP" "$VOICE") · base: $CUENTAS0"
  exit 0
fi

# ── Copia previa y escritura ───────────────────────────────────────────────────────────────────────────────────────
DUENO=$(stat -c '%U:%G' "$ENVF"); MODO=$(stat -c '%a' "$ENVF")
TS=$(date -u +%Y%m%d-%H%M%S)
COPIA="$COPIAS/env-pre-outreach-$ORDEN-$TS"
[ ! -e "$COPIA" ] || aborta "ya existe $COPIA: nunca se sobrescribe una copia (esperar un segundo y repetir)"
install -m 600 -o "$ROOT_U" -g "$ROOT_G" "$ENVF" "$COPIA"
VUELTA="install -m $MODO -o ${DUENO%%:*} -g ${DUENO##*:} $COPIA $ENVF && cd $APPDIR && sudo -u $WWW sh -c 'umask 077; php artisan config:cache -q' && sudo -u $WWW chmod 600 bootstrap/cache/config.php && sudo -u $WWW php artisan queue:restart -q"
ERR0=$(errores_log)
echo "0. $ORDEN · copia previa: $COPIA (600, $ROOT_U) · fichero $DUENO $MODO · programador $PROG0 · workers $W0 · constante de envío $CONST${ORDEN_ESCRITA:+ · orden escrita del propietario: $ORDEN_ESCRITA}"
echo "   fase antes: $(fase_de "$ENABLED" "$MODE" "$ASSISTED" "$WHATSAPP" "$VOICE") · base: $CUENTAS0"
printf '%s' "$CAMBIOS" | VACIAS_OK="$VACIAS_OK" escribir || aborta "no se pudo escribir el fichero de entorno. Vuelta: $VUELTA"
CLAVES_TOCADAS=$(printf '%s' "$CAMBIOS" | python3 -I -c 'import json, sys; print(" ".join(json.load(sys.stdin)))')
[ "$(stat -c '%U:%G %a' "$ENVF")" = "$DUENO $MODO" ] || aborta "el dueño o el modo del fichero de entorno cambiaron al escribir. Vuelta: $VUELTA"
echo "1. claves escritas (sin valores): $CLAVES_TOCADAS · fichero $(stat -c '%U:%G %a' "$ENVF")"
# B-1 del H5: el fichero tiene que parsear ANTES de tocar la caché (config:cache la vacía primero; si Laravel no arranca,
# la API cae).
parsea || aborta "el fichero de entorno NO parsea tras escribir. Vuelta: $VUELTA"
cachea || aborta "config:cache falló o la caché no quedó de $WWW en 600 (su salida no se imprime). Vuelta: $VUELTA"
echo "2. el fichero parsea · caché: $(stat -c '%U %a' bootstrap/cache/config.php)"

# ── Comprobaciones posteriores ─────────────────────────────────────────────────────────────────────────────────────
DESPUES=$(leer_outreach)
[ -n "$DESPUES" ] || { echo "ABORTA: la configuración efectiva no se puede leer tras el cambio (tinker; su salida no se imprime). Vuelta: $VUELTA"; exit 1; }
# Lo que tiene que cambiar, campo a campo, y lo que no. COMMERCIAL_EVENTS_ENABLED es del motor: no tiene campo aquí
# (lo comprueba leer_resto, abajo) y con `eventos` NINGÚN campo del H9 puede moverse.
COMP=$(ANTES="$ANTES" DESPUES="$DESPUES" CAMBIOS="$CAMBIOS" MAPA="$MAPA" python3 -I -c '
import json, os, re
from decimal import Decimal
a = json.loads(os.environ["ANTES"]); b = json.loads(os.environ["DESPUES"])
c = json.loads(os.environ["CAMBIOS"]); mapa = json.loads(os.environ["MAPA"])
del_motor = {"COMMERCIAL_EVENTS_ENABLED"}
conv = {"b": lambda v: v == "true", "i": int, "d": str, "s": str, "t": lambda v: v if v != "" else None}
def norm(t, v):
    if t == "d" and isinstance(v, str) and re.fullmatch(r"[0-9]+(\.[0-9]+)?", v):
        return str(Decimal(v).normalize())
    return v
tipos = {campo: t for campo, t in mapa.values()}
esperado = {}
for k, v in c.items():
    if k in del_motor:
        continue
    if k not in mapa:
        print("FALLO clave sin lectura: " + k); raise SystemExit
    campo, t = mapa[k]
    esperado[campo] = conv[t](v)
mal = []; cambios = []
for k in sorted(set(a) | set(b)):
    t = tipos.get(k, "")
    if k in esperado:
        if norm(t, b.get(k)) != norm(t, esperado[k]):
            mal.append("%s=%s (esperado %s)" % (k, json.dumps(b.get(k)), json.dumps(esperado[k])))
        if a.get(k) != b.get(k):
            cambios.append("%s: %s → %s" % (k, json.dumps(a.get(k)), json.dumps(b.get(k))))
    elif a.get(k) != b.get(k):
        mal.append("%s cambió sin pedirlo: %s → %s" % (k, json.dumps(a.get(k)), json.dumps(b.get(k))))
print(("FALLO " + " · ".join(mal)) if mal else ("OK " + (" · ".join(cambios) if cambios else "sin cambios visibles en el H9 (los valores ya eran esos o la orden es del motor)")))' 2>&1)
case "$COMP" in
  OK*) echo "3. configuración efectiva del H9, antes → después: ${COMP#OK }" ;;
  *) echo "ABORTA: la configuración efectiva no es la pedida: ${COMP#FALLO }"; echo "   vuelta: $VUELTA"; exit 1 ;;
esac
campo_despues() { printf '%s' "$DESPUES" | python3 -I -c 'import json, sys; v = json.load(sys.stdin).get(sys.argv[1]); print(json.dumps(v) if not isinstance(v, str) else v)' "$1"; }
echo "   fase después: $(fase_de "$(campo_despues enabled)" "$(campo_despues mode)" "$(campo_despues assisted)" "$(campo_despues whatsapp)" "$(campo_despues voice)")"
RESTO=$(leer_resto)
case "$ORDEN" in
  eventos-on|eventos-off)
    ESP_EV=false; [ "$ORDEN" = eventos-on ] && ESP_EV=true
    if RA="$RESTO_ANTES" RD="${RESTO:-$VACIO}" EV="$ESP_EV" python3 -I -c '
import json, os, sys
a = json.loads(os.environ["RA"]); b = json.loads(os.environ["RD"]); e = os.environ["EV"] == "true"
otros = [k for k in sorted(set(a) | set(b)) if k != "commercial_events" and a.get(k) != b.get(k)]
sys.exit(0 if b.get("commercial_events") is e and not otros else 1)' 2>/dev/null; then
      echo "   motor comercial: commercial.events_enabled → $ESP_EV; NBA, autonomía, Legacy, H4–H7 y voz del H8 sin cambios"
    else
      desvio "el motor comercial no quedó como se pidió (commercial_events = $ESP_EV y lo demás igual): antes $RESTO_ANTES · después ${RESTO:-ilegible}"
    fi ;;
  *) [ "$RESTO" = "$RESTO_ANTES" ] && echo "   motor comercial, Legacy, H4–H7 y voz del H8 sin cambios" || desvio "la configuración del motor, de Legacy, del H4–H7 o de la voz cambió: antes $RESTO_ANTES · después ${RESTO:-ilegible}" ;;
esac
# El fichero es la copia previa salvo las claves de la orden (sin imprimir nada de él).
if CLAVES="$CLAVES_TOCADAS" python3 -I -c '
import os, re, sys
ks = os.environ["CLAVES"].split()
pat = re.compile(r"^[ \t]*(?:export[ \t]+)?(" + "|".join(map(re.escape, ks)) + r")[ \t]*=")
f = lambda p: [l for l in open(p, encoding="utf-8").read().splitlines() if l.strip() and not pat.match(l)]
sys.exit(0 if f(sys.argv[1]) == f(sys.argv[2]) else 1)' "$ENVF" "$COPIA"; then
  echo "   el fichero de entorno es la copia previa salvo $(echo $CLAVES_TOCADAS | wc -w | tr -d ' ') clave(s) de la orden"
else
  desvio "el fichero de entorno difiere de la copia previa en algo más que las claves de la orden (comparar a mano sin imprimir valores)"
fi
# El programador: las tareas outreach:* esperadas se deducen de la bandera del programador de DESPUÉS (el bloque del H9
# de routes/console.php agenda sus $TAREAS_PROGRAMADOR tareas con ella y ninguna sin ella; el monitor no cambia el
# recuento, L9-60c); lo del Studio y la voz no cambia (commercial:scan se programa siempre: `eventos` no lo mueve).
esperadas_outreach() { # scheduler
  if [ "$1" = true ]; then echo "$TAREAS_PROGRAMADOR"; else echo 0; fi
}
ESP_OUT=$(esperadas_outreach "$(campo_despues scheduler)")
[ "$DEPLOYED" = true ] || ESP_OUT=$OUT0
PROG=$(programador)
case "$PROG" in
  "FALLO "*|ILEGIBLE) desvio "schedule:list no se puede leer tras el cambio ($PROG)" ;;
  *) ESP="$((TOTAL0 - OUT0 + ESP_OUT)) $STUDIO0 $VOZ0 $ESP_OUT"
     echo "4. programador (total · del Studio · de voz · de outreach): $PROG0 → $PROG (esperado $ESP)"
     [ "$PROG" = "$ESP" ] || desvio "schedule:list da [$PROG] y se esperaba [$ESP] (si las tareas de outreach no aparecen con el programador encendido, el bloque del H9 de routes/console.php no está o las órdenes no se llaman como espera: ver TAREAS_PROGRAMADOR en la cabecera)" ;;
esac
sudo -u "$WWW" php artisan queue:restart -q || desvio "queue:restart no salió con 0"
sleep 6
W1=$(workers "$RUN0" 45); sleep 8; W2=$(workers "$RUN0" 45)
echo "5. workers (RUNNING · BACKOFF/FATAL), dos lecturas: [$W1] y [$W2] (esperado $RUN0 0 las dos veces)"
[ "$W1" = "$RUN0 0" ] && [ "$W2" = "$RUN0 0" ] || desvio "workers [$W1] y [$W2] (esperado $RUN0 0)"
c1=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$API/admin/ultron-studio/outreach/status" || true)
c2=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$API/admin/ultron-studio/strategies" || true)
echo "6. sin token: admin/ultron-studio/outreach/status → $c1 · admin/ultron-studio/strategies → $c2 (esperado 401 y 401)"
[ "$c1" = 401 ] || desvio "admin/ultron-studio/outreach/status sin token respondió $c1 (esperado 401; 404 = las 13 rutas de la ejecución del H9 no están desplegadas, L9-57)"
[ "$c2" = 401 ] || desvio "admin/ultron-studio/strategies sin token respondió $c2 (esperado 401)"
ERR=$(errores_log)
echo "7. $NIVEL_ERROR en laravel*.log: $ERR0 → $ERR"; [ "$ERR" = "$ERR0" ] || desvio "$((ERR - ERR0)) ERROR nuevos en el log durante el cambio"
echo "   base después (informativo): $(leer_cuentas)"

# ── Avisos de la orden (informativos: no cambian el veredicto) ──────────────────────────────────────────────────────
C_BIND=$(printf '%s' "$CUENTAS0" | python3 -I -c 'import json, sys; print(json.load(sys.stdin).get("bindings_verified"))' 2>/dev/null || echo None)
C_VERS=$(printf '%s' "$CUENTAS0" | python3 -I -c 'import json, sys; print(json.load(sys.stdin).get("versions_live"))' 2>/dev/null || echo None)
C_GLOB=$(printf '%s' "$CUENTAS0" | python3 -I -c 'import json, sys; print(json.load(sys.stdin).get("brakes_global"))' 2>/dev/null || echo None)
# Lo reservado HOY en vivo en los canales que la orden deja con cupo 0 (deducido del código: el monitor compara lo
# reservado hoy con el cupo configurado AHORA y, si lo supera, abre `budget_breach` en ese canal; OutreachMonitor).
reservas_hoy() { # canales… → «canal N, …» de los que tienen reservas en vivo hoy (vacío si ninguno)
  printf '%s' "$CUENTAS0" | python3 -I -c '
import json, sys
d = json.load(sys.stdin).get("live_today") or {}
print(", ".join("%s %s" % (c, d.get("channel:" + c)) for c in sys.argv[1:] if int(d.get("channel:" + c) or 0) > 0))' "$@" 2>/dev/null || true
}
aviso_breach() { # canales…
  local r
  r=$(reservas_hoy "$@")
  [ -z "$r" ] || echo "   AVISO (deducido del código): hoy ya hay reservas en vivo ($r) y el cupo queda en 0: en la siguiente pasada del monitor con la maestra y el monitor encendidos (hoy, o al volver a encender hoy) se abrirá budget_breach en ese canal; es la dirección segura, y se suelta a cuatro ojos con nota antes de volver a encenderlo"
}
case "$ORDEN" in
  sombra-on)
    echo "   AVISO: la fase 2 (§14) exige la fase 1 estable 24 h, las dos primeras personas vinculadas por consola (ultron-studio:person-bindings), [P9-17]/[P9-18] decididos y P9-24 (bash $0 eventos medir; sin eventos, N6 niega a todo el mundo, L9-26); vinculadas y verificadas ahora: $C_BIND · versiones en canario o publicadas: $C_VERS (sin ellas el barrido no inscribe a nadie)"
    [ "$C_GLOB" = 0 ] || echo "   AVISO: hay $C_GLOB freno(s) global(es) abierto(s): en sombra no sale nada de todos modos, pero el despachador no reclamará hasta que una persona lo suelte desde el CRM"
    [ "$ASSISTED" = false ] && [ "$WHATSAPP" = false ] && [ "$VOICE" = false ] || echo "   AVISO: hay canales con su bandera encendida (asistido $ASSISTED, WhatsApp $WHATSAPP, voz $VOICE): en sombra son inertes; apagarlos deja el entorno coherente (canal … off / asistido off)"
    case "$RESTO_ANTES" in *'"commercial_events":true'*) ;; *) echo "   AVISO: COMMERCIAL_EVENTS_ENABLED está apagado (P9-24): las condiciones de eventos dan «unknown» y N6 negará a todo el mundo por human_requested_recently (L9-26). Medir y decidir: bash $0 eventos medir" ;; esac
    echo "   AVISO: mirar ≥ 7 días las decisiones y los volúmenes (GET metrics, GET decisions) antes de pedir la fase 3 [P9-11]"
    echo "   AVISO: con la maestra encendida las 12 rutas de la ejecución salvo GET status dejan de responder 409 outreach_disabled (L9-47): el panel del CRM pasa de «apagado» a vivo" ;;
  asistido-on)
    echo "   AVISO: la bandeja la trabaja el personal con commercial_automation.assisted_work (GET assisted/queue, claim, outcome); los resultados son «staff» (D9-10); la sombra tiene que haber salido sin hallazgos y [P9-12] decidido"
    [ "$C_GLOB" = 0 ] || echo "   AVISO: hay $C_GLOB freno(s) global(es) abierto(s): no se reclama nada hasta que una persona lo suelte desde el CRM" ;;
  asistido-off) aviso_breach assisted ;;
  canal-whatsapp-on)
    echo "   AVISO: la puerta de WhatsApp deniega igualmente sin plantillas aprobadas por Meta, sin números canarios de WhatsApp activos (fase 2 del H6), con la pausa del agente o con un freno de canal (D9-9.3); el primer mensaje, a un canario. El monitor (outreach:monitor) echa el freno del canal si la tasa de fallos o el gasto superan el umbral"
    [ -z "${AVISO_ORDEN# }" ] || echo "   AVISO:${AVISO_ORDEN}"
    [ "$(campo_despues monitor)" = true ] || echo "   AVISO: el monitor del H9 está apagado: sin él nadie frena solo el canal (bash $0 monitor on)" ;;
  canal-whatsapp-off) aviso_breach whatsapp_template ;;
  canal-voz-on)
    echo "   AVISO: la voz por estrategia entra por OutboundGate con todas las guardas del H8 (constante, cupos, presupuesto, horario, consentimiento); el H9 no acciona voice:hangup: el corte de emergencia sigue siendo el del H8"
    [ -z "${AVISO_ORDEN# }" ] || echo "   AVISO:${AVISO_ORDEN}" ;;
  canal-voz-off) aviso_breach voice_call ;;
  monitor-on)
    echo "   AVISO: la primera pasada corre en el siguiente múltiplo de 10 min; el monitor SOLO apaga (abre frenos e incidentes de IRON GUARD, fuente outreach) y nunca suelta nada (D9-14)" ;;
  programador-on)
    [ "$(campo_despues enabled)" = true ] || echo "   AVISO: la maestra está apagada: las tareas programadas correrán y saldrán con 0 sin hacer trabajo (§9.1)" ;;
  gobierno-on)
    echo "   AVISO: con el gobierno encendido las 30 rutas strategies/** responden; publicar, promover y reanudar siguen en 409 hasta \`$0 publicar on\` (dentro de la ventana de Bogotá)" ;;
  publicar-on)
    [ "$(campo_despues strategies)" = true ] || echo "   AVISO: la maestra del gobierno (ULTRON_STUDIO_STRATEGIES_ENABLED) está apagada: publicar no tendrá efecto hasta \`$0 gobierno on\`" ;;
  horario-set)
    echo "   AVISO: el horario propio lo lee la ejecución en cada pasada y nunca amplía la ley (de lunes a viernes 08:00-19:00 y sábados 08:00-15:00 con el silencio; ni domingos ni festivos); con un tramo cerrado (--sabado=cerrado) ese día no sale nada" ;;
  simulacion-waived|ventana-set)
    [ -z "$ORDEN_ESCRITA" ] || echo "   AVISO: decisión del propietario (referencia $ORDEN_ESCRITA): queda en esta salida y en la copia previa $COPIA; la publicación la escribe en su columna simulation_gate (D9-12)" ;;
  stream-on)
    echo "   AVISO: el canal SSE emite solo ids, estados y huellas (nunca datos personales); si cae, el CRM vuelve al sondeo; el limitador studio-stream va por persona (L9-78)" ;;
  eventos-on)
    echo "   AVISO (P9-24, orden escrita $ORDEN_ESCRITA): desde ya, los 8 observadores escriben en commercial_events en el proceso web (la caché nueva) y en los workers tras su reinicio (queue:restart, arriba); la pasada de commercial:scan de la próxima hora en punto registra las cuatro familias. Nadie evalúa ni contacta (NBA y autonomía apagados)"
    echo "   AVISO: comprobar en 1–2 h, en solo lectura: bash $0 eventos medir (filas nuevas y sin evaluar) y el tiempo de la entrada en recepción (el observador de asistencias añade una consulta a cada entrada)" ;;
  eventos-off)
    echo "   AVISO: las filas de commercial_events se quedan (nunca se borran); las condiciones de eventos del H9 vuelven a «unknown» y, en sombra, N6 niega a todo el mundo por human_requested_recently (L9-26)" ;;
  apagar)
    echo "   AVISO (nivel 0, L9-52 Q-7): maestra, modo, programador, monitor, canales y clasificador apagados y los cupos y techos a 0; los trabajos planned esperan y vencen; nada planifica ni despacha; las 12 rutas de la ejecución salvo GET status responden 409 outreach_disabled (L9-47: el CRM lo enseña como estado, no como error). Los precios de WhatsApp y la reserva de la voz se quedan (son tarifas, no topes). Las tablas quedan (nivel 3 es el código, nivel 4 los proveedores). COMMERCIAL_EVENTS_ENABLED no se toca (P9-24 es del motor: bash $0 eventos off)"
    echo "   AVISO: con una PARADA GLOBAL echada, soltarla exige volver a encender la maestra (la API de frenos responde 409 con ella apagada)"
    aviso_breach assisted whatsapp_template voice_call ;;
esac
echo "   vuelta atrás exacta (el fichero de antes): $VUELTA"
[ -z "$VUELTA_FUNCIONAL" ] || echo "   vuelta atrás funcional: $VUELTA_FUNCIONAL"
echo "   PARADA GLOBAL (nivel 0b, sin tocar el entorno, ≤ 60 s): bash $0 parada-global --motivo=\"…\""
if [ "$DESVIOS" = 0 ]; then
  echo "MANDO $ORDEN APLICADO (copia previa: $COPIA)"
else
  echo "MANDO $ORDEN ESCRITO CON $DESVIOS DESVÍOS: no seguir hasta aclararlos (copia previa: $COPIA; vuelta: $VUELTA)"
  exit 1
fi
