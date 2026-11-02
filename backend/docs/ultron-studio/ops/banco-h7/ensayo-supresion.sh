#!/bin/bash
# Ensayo de supresion-1581.sql en el banco, con el modelo de roles de producción: el script se lanza desde el
# superusuario del clúster (en producción, sudo -u postgres psql) y hace SET LOCAL ROLE al dueño h5_owner, que NO es
# superusuario. Antes: bash sembrar-1581.php (datos sintéticos de «Zoila Pérez Sintética», +57 300 999 0777).
# Casos: en seco sin cambios · rol que no es el dueño → 42501 · canario vivo → 55000 · redacción real (0 coincidencias,
# disparadores iguales, 1 fila en el libro, la cadena sigue verificando) · personal por admin_id · repetición sin nada.
# Uso: bash ensayo-supresion.sh <banco>     (código 0 = todos los casos OK)
. "$(dirname "$0")/comun.sh"
B=$(banco_dir "${1:-}")
cargar_banco "$B"
SQLF="$OPS/supresion-1581.sql"
P="psql -X -q -h $PG_HOST -p $PG_PORT -U $PG_USER -d $BANCO_DB"
Q() { psql -X -Atq -v ON_ERROR_STOP=1 -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$BANCO_DB" -c "$1"; }
D="$B/root/supresion"; mkdir -p "$D"; chmod 700 "$D"
# La huella del canario como dice el runbook: la aplicación la calcula leyendo el teléfono por la entrada estándar.
huella() { ( cd "$B/api/backend" && printf '%s\n' "$1" | php artisan tinker --execute='echo App\Support\Privacy\PhoneFingerprint::of(trim((string) fgets(STDIN))), PHP_EOL;' 2>/dev/null | tail -1 ); }
H_REVOCADO=$(huella +573009990777); H_VIVO=$(huella +573009990888)
[[ "$H_REVOCADO" =~ ^[0-9a-f]{64}$ && "$H_VIVO" =~ ^[0-9a-f]{64}$ ]] || muere "no se pudieron calcular las huellas con la aplicación"
parametros() { # fichero de parámetros 600 (como en producción); $1 = nombre, el resto = líneas \set
  local f="$D/$1.psql"; shift
  ( umask 077; printf '%s\n' "$@" > "$f" ); echo "$f"
}
corre() { cat "$1" "$SQLF" | $P > "$2" 2>&1; }
foto() { Q "SELECT md5(string_agg(t || ':' || n, ',' ORDER BY t)) FROM (SELECT c.relname AS t, (xpath('/row/n/text()', query_to_xml(format('SELECT md5(coalesce(string_agg(x::text, ''|'' ORDER BY x::text), '''')) AS n FROM public.%I x', c.relname), false, true, '')))[1]::text AS n FROM pg_class c JOIN pg_namespace s ON s.oid = c.relnamespace WHERE c.relkind = 'r' AND s.nspname = 'public' AND c.relname LIKE 'ultron\_studio\_%') q;"; }
activos() { Q "SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron\_studio\_%' AND t.tgenabled = 'O';"; }
COMUNES=("\\set radicado BANCO-1581-001" "\\set term 'Zoila Pérez'" "\\set phone '+573009990777'" "\\set owner h5_owner")

echo "== supresión Ley 1581 en el banco ($BANCO_DB, dueño h5_owner sin superusuario)"
F0=$(foto); A0=$(activos)
echo "   disparadores activos del Studio: $A0 · huella de las 14 tablas: ${F0:0:12}…"
caso "h5_owner no es superusuario" "$( [ "$(Q "SELECT rolsuper FROM pg_roles WHERE rolname = 'h5_owner';")" = f ] && echo 0 || echo 1)"

# 1. En seco: recuentos y nada cambia.
RC=0; corre "$(parametros seco "${COMUNES[@]}" "\\set phone_hash '$H_REVOCADO'" '\set dry 1')" "$B/logs/supresion-seco.log" || RC=$?
sed 's/^/   | /' "$B/logs/supresion-seco.log" | grep -E 'fila|total|EN SECO|AVISO|WARNING' | head -20
caso "en seco: código 0, termina en ROLLBACK y no cambia ninguna tabla" "$( [ "$RC" = 0 ] && [ "$(foto)" = "$F0" ] && grep -q 'EN SECO: ROLLBACK' "$B/logs/supresion-seco.log" && echo 0 || echo 1)" "rc $RC"

# 2. Un rol que no es el dueño (el de la aplicación): aborta antes de nada.
RC=0; corre "$(parametros app "${COMUNES[@]}" '\set owner h5_app' '\set dry 0')" "$B/logs/supresion-app.log" || RC=$?
caso "con el rol de la aplicación (h5_app): aborta 42501 sin cambiar nada" "$( [ "$RC" != 0 ] && [ "$(foto)" = "$F0" ] && grep -q 'el libro no es de h5_app' "$B/logs/supresion-app.log" && echo 0 || echo 1)" "rc $RC · $(grep -o 'ERROR: .*' "$B/logs/supresion-app.log" | head -1 | cut -c1-90)"
# y la aplicación tampoco puede apagar un disparador por su cuenta:
R=$(psql -X -q -h "$PG_HOST" -p "$PG_PORT" -U h5_app -d "$BANCO_DB" -c "ALTER TABLE ultron_studio_audit_events DISABLE TRIGGER usae_forbid_update;" 2>&1 || true)
caso "h5_app no puede DISABLE TRIGGER (no es el dueño)" "$(printf '%s' "$R" | grep -q 'must be owner' && echo 0 || echo 1)" "$(printf '%s' "$R" | head -1 | cut -c1-80)"

# 3. La huella de un canario VIVO: aborta sin cambiar nada (su huella la usa la coherencia con el motor).
RC=0; corre "$(parametros vivo "\\set radicado BANCO-1581-002" "\\set phone_hash '$H_VIVO'" '\set owner h5_owner' '\set dry 0')" "$B/logs/supresion-vivo.log" || RC=$?
caso "canario vivo por huella: aborta (revocar primero) sin cambiar nada" "$( [ "$RC" != 0 ] && [ "$(foto)" = "$F0" ] && grep -q 'el número canario está vivo' "$B/logs/supresion-vivo.log" && echo 0 || echo 1)" "rc $RC"
caso "tras los dos abortos, los disparadores siguen activos ($A0)" "$( [ "$(activos)" = "$A0" ] && echo 0 || echo 1)"

# 4. La redacción real: término, teléfono y huella del canario revocado.
L0=$(Q "SELECT count(*) FROM ultron_studio_audit_events;")
RC=0; corre "$(parametros real "${COMUNES[@]}" "\\set phone_hash '$H_REVOCADO'" '\set dry 0')" "$B/logs/supresion-real.log" || RC=$?
sed 's/^/   | /' "$B/logs/supresion-real.log" | grep -E 'apagados|redactado:|CONFIRMADA' | cut -c1-220
caso "redacción: código 0 y COMMIT" "$( [ "$RC" = 0 ] && grep -q 'SUPRESIÓN CONFIRMADA' "$B/logs/supresion-real.log" && echo 0 || echo 1)" "rc $RC"
RESTO=$(Q "SELECT (SELECT count(*) FROM ultron_studio_audit_events WHERE summary ~* 'zoila p' OR diff::jsonb::text ~* 'zoila p' OR diff::jsonb::text ~ '999.?0777' OR summary ~ '999 0777')
         + (SELECT count(*) FROM ultron_studio_publications WHERE reason ~* 'zoila p')
         + (SELECT count(*) FROM ultron_studio_integration_snapshots WHERE note ~* 'zoila p')
         + (SELECT count(*) FROM ultron_studio_canary_numbers WHERE alias ~* 'zoila' OR phone_hash = '$H_REVOCADO')
         + (SELECT count(*) FROM ultron_studio_lab_events WHERE summary ~* 'zoila p');")
caso "no queda ninguna coincidencia (libro, cadena, instantánea, canario, laboratorio)" "$( [ "$RESTO" = 0 ] && echo 0 || echo 1)" "$RESTO"
caso "el canario revocado: alias «suprimido», últimos 4 «0000», huella nueva de 64 hex" "$( [ "$(Q "SELECT alias || ' ' || phone_last4 || ' ' || (phone_hash ~ '^[0-9a-f]{64}\$') FROM ultron_studio_canary_numbers WHERE status = 'revoked';")" = "suprimido 0000 true" ] && echo 0 || echo 1)"
caso "el canario vivo no se tocó" "$( [ "$(Q "SELECT count(*) FROM ultron_studio_canary_numbers WHERE status = 'active' AND phone_hash = '$H_VIVO' AND alias = 'Canario vivo banco';")" = 1 ] && echo 0 || echo 1)"
caso "disparadores activos del Studio iguales antes y después ($A0)" "$( [ "$(activos)" = "$A0" ] && echo 0 || echo 1)" "$(activos)"
caso "una fila más en el libro: system.personal_data_redacted, sin el dato" "$( [ "$(Q "SELECT count(*) FROM ultron_studio_audit_events;")" = $((L0 + 1)) ] && [ "$(Q "SELECT count(*) FROM ultron_studio_audit_events WHERE event = 'system.personal_data_redacted' AND actor_name = 'supresion-1581' AND metadata::text !~* 'zoila|0777' AND summary LIKE '%BANCO-1581-001%';")" = 1 ] && echo 0 || echo 1)"
RC=0; ( cd "$B/api/backend" && php artisan ultron-studio:publication-check > /dev/null 2>&1 ) || RC=$?
caso "la cadena sigue verificando tras redactar reason (publication-check 0: la firma no cubre reason ni el actor)" "$RC"

# 5. El personal por admin_id: su nombre en cada instantánea de autoría y su IP y navegador en el libro.
F1=$(foto)
RC=0; corre "$(parametros personal "\\set radicado BANCO-1581-003" '\set admin_id 7' '\set owner h5_owner' '\set dry 0')" "$B/logs/supresion-personal.log" || RC=$?
caso "personal (admin_id 7): código 0" "$RC" "$(grep -o 'redactado: .*' "$B/logs/supresion-personal.log" | cut -c1-160)"
caso "sus nombres son «[suprimido]» y su IP y navegador quedan vacíos; los de otra persona no se tocan" "$( [ "$(Q "SELECT (SELECT count(*) FROM ultron_studio_audit_events WHERE actor_admin_id = 7 AND (actor_name <> '[suprimido]' OR ip_address IS NOT NULL OR user_agent IS NOT NULL)) + (SELECT count(*) FROM ultron_studio_publications WHERE actor_admin_id = 7 AND actor_name <> '[suprimido]') + (SELECT count(*) FROM ultron_studio_canary_numbers WHERE registered_by_admin_id = 7 AND registered_by_name <> '[suprimido]');")" = 0 ] && [ "$(Q "SELECT count(*) FROM ultron_studio_audit_events WHERE actor_name = 'Otra Persona';")" = 1 ] && echo 0 || echo 1)"
caso "disparadores activos iguales tras la segunda redacción" "$( [ "$(activos)" = "$A0" ] && echo 0 || echo 1)"

# 6. Repetir la misma solicitud: nada que redactar, solo su fila de registro.
RC=0; corre "$(parametros otra-vez "${COMUNES[@]}" '\set dry 0')" "$B/logs/supresion-otra-vez.log" || RC=$?
caso "repetir: código 0 y 0 campos" "$( [ "$RC" = 0 ] && grep -q '0 campo(s) que redactar' "$B/logs/supresion-otra-vez.log" && echo 0 || echo 1)"

rm -rf -- "$D"        # los ficheros de parámetros llevan el dato: fuera al terminar
echo "SUPRESIÓN EN EL BANCO: $((CASOS - FALLOS))/$CASOS OK"
[ "$FALLOS" = 0 ]
