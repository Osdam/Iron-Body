#!/bin/bash
# Pruebas aisladas de las expansiones delicadas del modo 4a de deploy-h8-backend-fase1.sh (OPS8-e). No toca nada.
set -euo pipefail
export LC_ALL=C
ok() { echo "OK    $*"; }
ko() { echo "FALLO $*"; FALLOS=$((FALLOS + 1)); }
FALLOS=0
admite() { [[ "$1" =~ ^[A-Za-z0-9][A-Za-z0-9\ ._:/#()-]{5,119}$ ]]; }
for a in "AUT-4A-2026-11-12" "orden del propietario 12-11-2026 14:05 UTC" "Chat 12 (propietario) 2026/11/12"; do
  admite "$a" && ok "admite [$a]" || ko "rechaza [$a]"
done
for a in "#12 (chat) 2026/11/12" "x" "AUT\$(id)" "AUT;ls" "AUT-4A con tilde á" 'AUT`id`' "#AUT-12345" "AUT|cat" "AUT'x'" "$(printf 'AUT-4A\nX')"; do
  admite "$a" && ko "admite [$a]" || ok "rechaza [$(printf '%s' "$a" | tr '\n' '~')]"
done
H8_0='{"calls_in_code":false,"inbound":false,"outbound":false}'
E=${H8_0/'"calls_in_code":false'/'"calls_in_code":true'}
[ "$E" = '{"calls_in_code":true,"inbound":false,"outbound":false}' ] && ok "sustitución de la constante en el JSON: $E" || ko "$E"
P_POLITICA=backend/app/Services/Voice/VoiceCallPolicy.php
A40=$(printf '0%.0s' $(seq 1 40)); B40=$(printf 'f%.0s' $(seq 1 40))
casa() { [[ "$1" =~ ^:100644\ 100644\ [0-9a-f]{40}\ [0-9a-f]{40}\ M$'\t'"$P_POLITICA"$ ]]; }
raw=$(printf ':100644 100644 %s %s M\t%s' "$A40" "$B40" "$P_POLITICA")
casa "$raw" && ok "raw del cambio legítimo casa" || ko "raw no casa: $raw"
casa "$(printf ':100644 100644 %s %s A\t%s' "$A40" "$B40" "$P_POLITICA")" && ko "un fichero AÑADIDO casa" || ok "un fichero añadido no casa"
casa "$(printf ':100644 100755 %s %s M\t%s' "$A40" "$B40" "$P_POLITICA")" && ko "un cambio de modo casa" || ok "un cambio de modo (755) no casa"
casa "$(printf ':100644 100644 %s %s M\t%sX' "$A40" "$B40" "$P_POLITICA")" && ko "otra ruta casa" || ok "otra ruta (sufijo) no casa"
casa "$(printf ':100644 100644 %s %s M\tbackendXapp/Services/Voice/VoiceCallPolicy.php' "$A40" "$B40")" && ko "un punto de la ruta actúa como comodín" || ok "la ruta es literal (sin comodines)"
TEL0="estado=ELEVENLABS_UNVERIFIED constante=cerrada encendidas=2 cadena=canary:none,rest:none freno=suelto cupos=0/0/0 numero=si subcuenta=si proveedor=ELEVENLABS_VERIFIED"
tel4a() { [[ "$1" =~ ^estado=[A-Z_]+\ constante=cerrada\ encendidas=[0-9]+\ cadena=[a-z_:,]+\ freno=(suelto|echado)\ cupos=0/0/0\ numero=si\ subcuenta=si\ proveedor=ELEVENLABS_VERIFIED$ ]]; }
tel4a "$TEL0" && ok "telefonía de una fase 2 completa casa" || ko "TEL0"
tel4a "${TEL0/proveedor=ELEVENLABS_VERIFIED/proveedor=ELEVENLABS_UNVERIFIED}" && ko "sin verificar casa" || ok "telefonía sin verificar no casa"
tel4a "${TEL0/cupos=0\/0\/0/cupos=1\/0\/2000000}" && ko "con cupos casa" || ok "con salientes encendidas no casa"
tel4a "${TEL0/numero=si/numero=no}" && ko "sin número casa" || ok "sin número no casa"
[ "${TEL0/constante=cerrada/constante=abierta}" = "estado=ELEVENLABS_UNVERIFIED constante=abierta encendidas=2 cadena=canary:none,rest:none freno=suelto cupos=0/0/0 numero=si subcuenta=si proveedor=ELEVENLABS_VERIFIED" ] && ok "telefonía esperada tras la 4a" || ko "TEL tras la 4a"
menos="-    public const CALLS_ENABLED_IN_CODE = false;"; e=${menos#-}; e=${e/= false;/= true;}
[ "$e" = "    public const CALLS_ENABLED_IN_CODE = true;" ] && ok "línea esperada tras el giro: [$e]" || ko "[$e]"
LINEA_CERRADA='^[[:space:]]*public const CALLS_ENABLED_IN_CODE = false;[[:space:]]*$'
LINEA_ABIERTA='^[[:space:]]*public const CALLS_ENABLED_IN_CODE = true;[[:space:]]*$'
[[ "${menos#-}" =~ $LINEA_CERRADA ]] && [[ "$e" =~ $LINEA_ABIERTA ]] && ok "las dos formas ancladas casan" || ko "formas ancladas"
[[ "    public const CALLS_ENABLED_IN_CODE = true; // x" =~ $LINEA_ABIERTA ]] && ko "con comentario casa" || ok "con comentario detrás no casa"
DOCS_4A='^(backend/docs/|docs/)|^[^/]+\.md$'
for p in backend/docs/ultron-studio/X.md docs/a/b.txt README.md backend/docs/ops/supervisor/ironbody-voice.conf; do
  printf '%s\n' "$p" | grep -qE "$DOCS_4A" && ok "documentación: $p" || ko "no documentación: $p"
done
for p in backend/app/X.php backend/README.md frontend/a.md backend/config/voice.php n8n/x.json backend/docsX/a.md; do
  printf '%s\n' "$p" | grep -qE "$DOCS_4A" && ko "documentación: $p" || ok "no es documentación: $p"
done
FASE=""; [ "$FASE" = 4a ] || case "x" in x) ok "case detrás de || funciona" ;; esac
echo "== $FALLOS FALLO"
[ "$FALLOS" = 0 ]
