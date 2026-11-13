#!/bin/bash
# MUEVE EL CANARIO DE ULTRON A UNA CONVERSACIÓN LIMPIA DEL MISMO PROSPECTO (cierre V2, apartado 4, gate 4-B).
#
# Por qué: la conversación canaria acumula memoria comercial (fase, precios entregados, objetivo) e historial, y una
# prueba funcional sobre ella mide la conversación gastada y no el motor (hallazgo H4-5). Cerrarla NO da una limpia:
# `MetaLeadService::ensureConversation()` devuelve la más reciente aunque esté cerrada. Hay que CREAR una en blanco,
# cerrar la anterior (así la nueva es la única abierta y el entrante cae en ella) y apuntar las dos variables.
#
# ES UN CAMBIO DE PRODUCCIÓN (dos filas de `marketing_conversations`, dos líneas del `.env`, caché de configuración y
# reinicio de los workers): solo con la autorización expresa del propietario para ESTA operación.
#
# Uso (en el servidor, como root):
#   bash -s -- comprobar                                   solo lectura: estado actual y qué haría `aplicar`
#   bash -s -- aplicar <id canario actual>                 crea la limpia, cierra la actual y mueve las dos variables
#   bash -s -- revertir <id nuevo> <id anterior>           deshace `aplicar` (la conversación nueva queda cerrada, no se borra)
#
# Qué NO hace: no toca n8n, ni el Studio, ni la cadena de publicaciones, ni el prospecto (su ficha y sus hechos del CRM
# se conservan: «limpia» es la conversación). No imprime teléfonos, nombres ni textos de mensajes.
set -euo pipefail

API=/var/www/api/backend
ENVF="$API/.env"
V1=MARKETING_ULTRON_CANARY_CONVERSATION_ID
V2=MARKETING_ULTRON_PAYMENT_CANARY_CONVERSATION_ID
MODO="${1:-}"

aborta() { echo "ABORTADO: $*" >&2; exit 1; }
[ "$(id -u)" = "0" ] || aborta "se ejecuta como root"
[ -f "$ENVF" ] || aborta "no existe $ENVF"
cd "$API"

valor() { grep -E "^[[:space:]]*$1=" "$ENVF" | tail -1 | sed -E "s/^[[:space:]]*$1=//" | tr -d '"'"'"' \r'; }
lineas() { grep -cE "^[[:space:]]*$1=" "$ENVF" || true; }
workers() { supervisorctl status | awk '{print $2}' | sort | uniq -c | tr '\n' ' '; }
vivos() { supervisorctl status | awk '$2=="RUNNING"' | wc -l | tr -d ' '; }
total() { supervisorctl status | wc -l | tr -d ' '; }
# `sudo` limpia el entorno: las tres variables con las que las sondas reciben los ids se conservan explícitamente.
artisan() { sudo --preserve-env=CANARIA_ACTUAL,CANARIA_NUEVA,CANARIA_ANTERIOR -u www-data php artisan "$@"; }

# Una sonda PHP que corre como www-data con la aplicación cargada. Se escribe en /tmp, se ejecuta y se borra.
sonda() {
  local f; f=$(mktemp /tmp/canaria-XXXXXX.php); chmod 644 "$f"; cat > "$f"
  local rc=0; artisan tinker --execute="require '$f';" || rc=$?
  rm -f "$f"; return $rc
}

estado() {
  echo "variables: $V1=$(valor $V1) ($(lineas $V1) línea) · $V2=$(valor $V2) ($(lineas $V2) línea)"
  echo "ficheros: $(stat -c '%U:%G %a %n' "$ENVF" bootstrap/cache/config.php 2>&1 | tr '\n' ' ')"
  echo "workers: $(workers)"
  # Si FPM no revalida las fechas de los ficheros, la configuración nueva no se vería hasta recargarlo (y eso es otro permiso).
  echo "opcache de FPM (validate_timestamps): $(grep -rhE '^[[:space:]]*opcache\.validate_timestamps' /etc/php/*/fpm/ 2>/dev/null | tr -d ' ' | sort -u | tr '\n' ' '; echo '(sin línea = 1, el valor por defecto)')"
  sonda <<'PHP'
<?php
use App\Models\MarketingConversation;
$id = config('marketing.ultron.canary_conversation_id');
$pago = (int) config('marketing.ultron.payment_canary_conversation_id', 0);
echo 'configuración en caché: canario='.var_export($id, true).' · canario de pago='.$pago.' · ULTRON '.(config('marketing.ultron.enabled') ? 'encendido' : 'apagado')."\n";
$c = is_numeric($id) ? MarketingConversation::find((int) $id) : null;
if ($c === null) { echo "la conversación canaria configurada no existe\n"; return; }
echo sprintf("canaria #%d: %s · %s · IA %s · relevo humano %s · fase %s · %d mensajes · último %s UTC\n", $c->id, $c->status, $c->channel,
    $c->ai_enabled ? 'sí' : 'no', $c->human_takeover ? 'sí' : 'no', $c->commercial_phase ?: '-', $c->messages()->count(), optional($c->last_message_at)->format('Y-m-d H:i') ?: '-');
$otras = MarketingConversation::query()->where('lead_id', $c->lead_id)->where('channel', $c->channel)->orderBy('id')->get(['id', 'status']);
echo 'conversaciones del mismo prospecto y canal: '.$otras->map(fn ($o) => '#'.$o->id.' '.$o->status)->implode(', ')."\n";
echo 'abiertas: '.$otras->where('status', 'open')->count()."\n";
PHP
  artisan ultron-studio:publication-check 2>&1 | sed 's/^/  /' | head -8 || true
  artisan ultron:abort 2>&1 | sed 's/^/  /' | head -2 || true
}

recachear() {
  artisan config:cache >/dev/null
  chown www-data:www-data bootstrap/cache/config.php
  chmod 600 bootstrap/cache/config.php
  artisan queue:restart >/dev/null
}

comprobar_workers() {
  local antes="$1"
  sleep 12; local a; a=$(vivos)
  sleep 10; local b; b=$(vivos)
  echo "workers tras el reinicio: $a y, diez segundos después, $b RUNNING (antes: $antes de $(total))"
  [ "$a" = "$antes" ] && [ "$b" = "$antes" ]
}

poner() { # poner <variable> <valor anterior> <valor nuevo>: exactamente una línea, conservando la sangría
  local n; n=$(grep -cE "^[[:space:]]*$1=$2[[:space:]]*$" "$ENVF" || true)
  [ "$n" = "1" ] || aborta "$1 no vale $2 en exactamente una línea del .env (líneas que casan: $n)"
  sudo -u www-data sed -i -E "s/^([[:space:]]*)$1=$2[[:space:]]*$/\1$1=$3/" "$ENVF"
  [ "$(valor "$1")" = "$3" ] || aborta "$1 no quedó en $3"
}

case "$MODO" in
  comprobar)
    estado
    echo "«aplicar $(valor $V1)» crearía una conversación en blanco del mismo prospecto, cerraría la #$(valor $V1) y pondría las dos variables en el id nuevo."
    ;;

  aplicar)
    ACTUAL="${2:-}"; [[ "$ACTUAL" =~ ^[0-9]+$ ]] || aborta "uso: aplicar <id canario actual>"
    [ "$(valor $V1)" = "$ACTUAL" ] || aborta "$V1 vale $(valor $V1), no $ACTUAL"
    [ "$(valor $V2)" = "$ACTUAL" ] || aborta "$V2 vale $(valor $V2), no $ACTUAL"
    [ "$(lineas $V1)" = "1" ] && [ "$(lineas $V2)" = "1" ] || aborta "alguna de las dos variables aparece más de una vez en el .env"
    ANTES=$(vivos); [ "$ANTES" = "$(total)" ] || aborta "hay workers que no están RUNNING: $(workers)"

    RESPALDO=/root/respaldos/canaria-$(date -u +%Y%m%d-%H%M%S)
    ( umask 077; mkdir -p "$RESPALDO"; cp -p "$ENVF" "$RESPALDO/env"; echo "$ACTUAL" > "$RESPALDO/canario-anterior" )
    echo "respaldo del .env y del id anterior: $RESPALDO"

    NUEVO=$(CANARIA_ACTUAL="$ACTUAL" sonda <<'PHP' | tee /dev/stderr | sed -n 's/^NUEVA=\([0-9][0-9]*\)$/\1/p'
<?php
use App\Models\MarketingConversation;
use App\Services\Marketing\CommercialPhaseMachine;
use App\Services\Marketing\Ultron\ConversationMemory;
use Illuminate\Support\Facades\DB;

$actual = MarketingConversation::findOrFail((int) getenv('CANARIA_ACTUAL'));
if ($actual->status !== 'open' || $actual->channel !== 'whatsapp') { echo "la canaria actual no está abierta o no es de WhatsApp\n"; return; }
if ($actual->human_takeover) { echo "la canaria actual está en relevo humano: no se mueve\n"; return; }
$reciente = $actual->messages()->where('created_at', '>=', now()->subMinutes(3))->exists();
if ($reciente) { echo "hay un mensaje de hace menos de tres minutos en la canaria: espera a que termine el turno\n"; return; }
$abiertas = MarketingConversation::query()->where('lead_id', $actual->lead_id)->where('channel', $actual->channel)->where('status', 'open')->count();
if ($abiertas !== 1) { echo "el prospecto tiene {$abiertas} conversaciones abiertas en WhatsApp: se esperaba una\n"; return; }

$nueva = DB::transaction(function () use ($actual) {
    $n = MarketingConversation::create([
        'lead_id' => $actual->lead_id,
        'channel' => $actual->channel,
        'status' => 'open',
        'ai_enabled' => true,
        'human_takeover' => false,
        'memory' => ConversationMemory::blank(),
        'commercial_phase' => CommercialPhaseMachine::NEW_LEAD,
    ]);
    $actual->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

    return $n;
});
echo 'creada la conversación #'.$nueva->id.' (en blanco, '.$nueva->commercial_phase.') y cerrada la #'.$actual->id."\n";
echo 'NUEVA='.$nueva->id."\n";
PHP
)
    [[ "$NUEVO" =~ ^[0-9]+$ ]] || aborta "no se creó la conversación nueva (nada más ha cambiado; el .env sigue igual)"

    deshacer() {
      echo "FALLO a mitad: se deshace." >&2
      cp -p "$RESPALDO/env" "$ENVF"; chown www-data:www-data "$ENVF"; chmod 600 "$ENVF"
      recachear || true
      CANARIA_NUEVA="$NUEVO" CANARIA_ANTERIOR="$ACTUAL" sonda <<'PHP' || true
<?php
use App\Models\MarketingConversation;
use Illuminate\Support\Facades\DB;
DB::transaction(function () {
    MarketingConversation::findOrFail((int) getenv('CANARIA_NUEVA'))->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
    MarketingConversation::findOrFail((int) getenv('CANARIA_ANTERIOR'))->forceFill(['status' => 'open', 'closed_at' => null])->save();
});
echo "reabierta la anterior y cerrada la nueva\n";
PHP
    }
    trap deshacer ERR

    poner $V1 "$ACTUAL" "$NUEVO"
    poner $V2 "$ACTUAL" "$NUEVO"
    recachear
    comprobar_workers "$ANTES" || { echo "los workers no volvieron todos" >&2; false; }
    trap - ERR

    echo "=== DESPUÉS ==="
    estado
    echo "CANARIO MOVIDO A LA CONVERSACIÓN #$NUEVO. Vuelta atrás: bash -s -- revertir $NUEVO $ACTUAL   (respaldo: $RESPALDO)"
    ;;

  revertir)
    NUEVO="${2:-}"; ANTERIOR="${3:-}"
    [[ "$NUEVO" =~ ^[0-9]+$ && "$ANTERIOR" =~ ^[0-9]+$ ]] || aborta "uso: revertir <id nuevo> <id anterior>"
    [ "$(valor $V1)" = "$NUEVO" ] && [ "$(valor $V2)" = "$NUEVO" ] || aborta "las variables no valen $NUEVO"
    ANTES=$(vivos)
    RESPALDO=/root/respaldos/canaria-revertir-$(date -u +%Y%m%d-%H%M%S)
    ( umask 077; mkdir -p "$RESPALDO"; cp -p "$ENVF" "$RESPALDO/env" )
    poner $V1 "$NUEVO" "$ANTERIOR"
    poner $V2 "$NUEVO" "$ANTERIOR"
    CANARIA_NUEVA="$NUEVO" CANARIA_ANTERIOR="$ANTERIOR" sonda <<'PHP'
<?php
use App\Models\MarketingConversation;
use Illuminate\Support\Facades\DB;
DB::transaction(function () {
    MarketingConversation::findOrFail((int) getenv('CANARIA_NUEVA'))->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
    MarketingConversation::findOrFail((int) getenv('CANARIA_ANTERIOR'))->forceFill(['status' => 'open', 'closed_at' => null])->save();
});
echo "reabierta la anterior y cerrada la nueva\n";
PHP
    recachear
    comprobar_workers "$ANTES" || aborta "los workers no volvieron todos: $(workers)"
    echo "=== DESPUÉS ==="
    estado
    echo "CANARIO DEVUELTO A LA CONVERSACIÓN #$ANTERIOR (respaldo del .env previo: $RESPALDO)"
    ;;

  *) aborta "uso: comprobar | aplicar <id canario actual> | revertir <id nuevo> <id anterior>" ;;
esac
