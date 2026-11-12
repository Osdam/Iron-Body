#!/usr/bin/env bash
# V9 · LA VÍA ABIERTA DE LA VOZ POR ESTRATEGIA, en una COPIA del árbol de trabajo. NUNCA en el árbol compartido
# (protección 2 del H9 y del H8: las constantes se giran solo en un commit `outreach(sends-open):` /
# `voice(calls-open):` con la autorización escrita del propietario). Es el patrón de `h8-e2e.sh` (la constante
# abierta solo en el clon).
#
# Copia el backend (clon APFS con `cp -c`; si no se puede, copia normal; sin `.env`) a un directorio temporal y,
# SOLO EN LA COPIA:
#   1. `VoiceCallPolicy::CALLS_ENABLED_IN_CODE = true`   (H8; si la fuente ya la trae abierta, desde el commit
#      `voice(calls-open):`, esa línea no se gira y solo se comprueba)
#   2. `OutreachSendGate::SENDS_ENABLED_IN_CODE = true`  (H9)
#   3. `'voice_call'` en `OutreachSendGate::LIVE_CHANNELS`, SOLO si faltara: el lead la declaró en el árbol (L9-81,
#      Enmienda 17 del H8), así que hoy se giran dos líneas (una, con la voz ya abierta en la fuente).
# Comprueba que cada literal estaba EXACTAMENTE una vez y que el giro no cambió nada más de la copia (dos o tres líneas en
# dos ficheros; una o dos en la puerta si la voz ya venía abierta; por la huella de la copia antes y después: el árbol vivo puede estar cambiando por otros agentes). Corre `tests/Feature/Outreach/V9/V9OpenGateTest.php` (en el árbol compartido se omite) en SQLite
# y/o en el PostgreSQL desechable (`pgunit.sh`) y borra la copia al salir. Cero red: las pruebas llevan
# `Http::preventStrayRequests()` y el worker de la saliente es FALSO.
#
# Uso: docs/ultron-studio/ops/v9-abierta.sh [sqlite|pg|ambas]      (por defecto, ambas)
#      UNIT_DB (por defecto ultron_studio_v9_abierta; tiene que existir y empezar por ultron_studio_), PG_PORT (55432).
set -euo pipefail

muere() { echo "v9-abierta: $*" >&2; exit 2; }

BE="$(cd "$(dirname "$0")/../../.." && pwd)"
MODO="${1:-ambas}"
case "$MODO" in sqlite|pg|ambas) ;; *) muere "modo desconocido: $MODO (sqlite|pg|ambas)" ;; esac
[ -d "$BE/vendor" ] || muere "falta $BE/vendor (composer install)"

POL=app/Services/Voice/VoiceCallPolicy.php
PUERTA=app/Services/Outreach/Run/OutreachSendGate.php
PRUEBA=tests/Feature/Outreach/V9/V9OpenGateTest.php
[ -f "$BE/$PRUEBA" ] || muere "falta $PRUEBA"

TMP="$(mktemp -d "${TMPDIR:-/tmp}/v9-abierta.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT
COPIA="$TMP/backend"
echo "── v9-abierta · copia del árbol en $COPIA"
cp -cR "$BE" "$COPIA" 2> /dev/null || cp -R "$BE" "$COPIA"
rm -f "$COPIA/.env"

# Los literales, exactamente una vez cada uno; la voz, declarada en el árbol (L9-81) o, si no, solo en la copia.
# CALLS_ENABLED_IN_CODE (I-2 de la revisión de la apertura, 12-11-2026): la fuente la trae cerrada (una `= false;` y
# ninguna `= true;`: se gira en la copia, como siempre) o, desde el commit `voice(calls-open):`, abierta (una `= true;` y
# ninguna `= false;`: la copia no gira esa línea). Cualquier otra forma (ninguna, dos, las dos) aborta.
N_CERRADA="$(grep -c 'public const CALLS_ENABLED_IN_CODE = false;' "$COPIA/$POL" || true)"
N_ABIERTA="$(grep -c 'public const CALLS_ENABLED_IN_CODE = true;' "$COPIA/$POL" || true)"
case "$N_CERRADA $N_ABIERTA" in
  "1 0") GIRA_VOZ=1 ;;
  "0 1") GIRA_VOZ=0 ;;
  *) muere "CALLS_ENABLED_IN_CODE no está en un solo estado en $POL (= false: $N_CERRADA, = true: $N_ABIERTA; se admite exactamente una de las dos y ninguna de la otra)" ;;
esac
[ "$(grep -c 'public const SENDS_ENABLED_IN_CODE = false;' "$COPIA/$PUERTA")" = 1 ] || muere "SENDS_ENABLED_IN_CODE no está en false exactamente una vez en $PUERTA"
[ "$(grep -c 'public const LIVE_CHANNELS = \[' "$COPIA/$PUERTA")" = 1 ] || muere "LIVE_CHANNELS no está declarada exactamente una vez en $PUERTA"
DECLARADA=0
if grep 'public const LIVE_CHANNELS' "$COPIA/$PUERTA" | grep -q "'voice_call'"; then
  DECLARADA=1
fi

# La huella de la COPIA antes del giro (no del árbol vivo: otros agentes pueden estar escribiendo en él).
huella() { (cd "$COPIA" && find app bootstrap config database routes tests -type f -not -path '*/cache/*' -print0 | sort -z | xargs -0 shasum -a 256); }
huella > "$TMP/antes.txt"
cp "$COPIA/$POL" "$TMP/pol.orig"
cp "$COPIA/$PUERTA" "$TMP/puerta.orig"

if [ "$GIRA_VOZ" = 1 ]; then
  perl -pi -e 's/public const CALLS_ENABLED_IN_CODE = false;/public const CALLS_ENABLED_IN_CODE = true;/' "$COPIA/$POL"
fi
perl -pi -e 's/public const SENDS_ENABLED_IN_CODE = false;/public const SENDS_ENABLED_IN_CODE = true;/' "$COPIA/$PUERTA"
[ "$DECLARADA" = 1 ] || perl -pi -e "s/(public const LIVE_CHANNELS = \[[^\]]*)\];/\$1, 'voice_call'];/" "$COPIA/$PUERTA"

# La copia cambió SOLO en esos dos ficheros, y en ellos solo dos líneas (tres si hubo que declarar la voz). Con la voz ya
# abierta en la fuente, solo cambia la puerta: una línea (dos si hubo que declarar la voz).
huella > "$TMP/despues.txt"
CAMBIADOS="$( { diff "$TMP/antes.txt" "$TMP/despues.txt" || true; } | { grep '^>' || true; } | awk '{print $3}' | sort | tr '\n' ' ')"
if [ "$GIRA_VOZ" = 1 ]; then
  ESPERADOS="$(printf '%s\n%s\n' "$PUERTA" "$POL" | sort | tr '\n' ' ')"
else
  ESPERADOS="$(printf '%s\n' "$PUERTA" | tr '\n' ' ')"
fi
[ "$CAMBIADOS" = "$ESPERADOS" ] || muere "el giro cambió otros ficheros de la copia: $CAMBIADOS (esperados: $ESPERADOS)"
LINEAS="$( { diff "$TMP/pol.orig" "$COPIA/$POL" || true; diff "$TMP/puerta.orig" "$COPIA/$PUERTA" || true; } | { grep -c '^>' || true; } )"
ESPERADAS=$((GIRA_VOZ + 2 - DECLARADA))
[ "$LINEAS" = "$ESPERADAS" ] || muere "se esperaban $ESPERADAS líneas giradas y hay $LINEAS"
[ "$(grep -c 'public const CALLS_ENABLED_IN_CODE = true;' "$COPIA/$POL" || true) $(grep -c 'public const CALLS_ENABLED_IN_CODE = false;' "$COPIA/$POL" || true)" = "1 0" ] \
  || muere "CALLS_ENABLED_IN_CODE no quedó abierta exactamente una vez (y ninguna cerrada) en la copia"
grep -q 'public const SENDS_ENABLED_IN_CODE = true;' "$COPIA/$PUERTA" || muere "no se giró SENDS_ENABLED_IN_CODE"
grep 'public const LIVE_CHANNELS' "$COPIA/$PUERTA" | grep -q "'voice_call'\]" || muere "no se declaró la voz en LIVE_CHANNELS"
if [ "$GIRA_VOZ" = 1 ]; then GIRADAS="CALLS_ENABLED_IN_CODE y SENDS_ENABLED_IN_CODE"; else GIRADAS="SENDS_ENABLED_IN_CODE (CALLS_ENABLED_IN_CODE ya venía abierta en la fuente: voice(calls-open))"; fi
if [ "$DECLARADA" = 1 ]; then
  echo "   giradas en la copia (y solo en ella): $GIRADAS; la voz ya estaba declarada (L9-81)"
else
  echo "   giradas en la copia (y solo en ella): $GIRADAS y 'voice_call' en LIVE_CHANNELS"
fi

ESTADO=0
cd "$COPIA"
if [ "$MODO" != pg ]; then
  echo "── v9-abierta · SQLite"
  php -d memory_limit=2G vendor/bin/phpunit --no-logging --do-not-cache-result "$PRUEBA" || ESTADO=1
fi
if [ "$MODO" != sqlite ]; then
  echo "── v9-abierta · PostgreSQL (${UNIT_DB:-ultron_studio_v9_abierta})"
  UNIT_DB="${UNIT_DB:-ultron_studio_v9_abierta}" docs/ultron-studio/ops/pgunit.sh --no-logging --do-not-cache-result "$PRUEBA" || ESTADO=1
fi
exit "$ESTADO"
