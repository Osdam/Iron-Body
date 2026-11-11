#!/bin/bash
# A/B de supresion-1581.sql (database-specialist, B-T3, 10-11-2026): el guion de una versión anterior (REF, por defecto
# d701b0c8, lo desplegado al empezar B-T3) frente al del árbol, con los MISMOS parámetros y sobre la MISMA base.
# SOLO en el clúster DESECHABLE local (127.0.0.1:55432, usuario ironbody); nunca en producción ni en el banco de otro.
#
#   bash ab-1581.sh seco <base> <dueño> <admin_id> <subject 0|1>
#        En seco (READ ONLY + ROLLBACK) sobre una base que ya existe. Compara línea a línea lo que no es de la voz: tiene
#        que salir «0» en las dos comparaciones y el mismo total salvo lo que la voz añade.
#   bash ab-1581.sh real <base_plantilla> <admin_id>
#        Redacción REAL sobre DOS copias desechables (CREATE DATABASE … TEMPLATE; la plantilla sin sesiones abiertas),
#        una con cada versión, y compara cada tabla fila a fila (salvo la huella aleatoria del canario y la fila nueva del
#        libro). Borra sus dos copias al terminar.
#
# Término 'Canario' (casa con los datos sintéticos de los bancos); la huella del primer canario revocado, si lo hay, sin
# imprimirla. Salidas en $SALIDA (por defecto un directorio temporal nuevo, 700). Ninguna salida lleva datos.
set -u
export PGGSSENCMODE=disable
W=$(cd "$(dirname "$0")/../../../../../.." && pwd)          # backend/
REF=${REF:-d701b0c8}
SALIDA=${SALIDA:-$(mktemp -d)}; mkdir -p "$SALIDA" && chmod 700 "$SALIDA" || exit 2
P=(psql -X -Atq -v ON_ERROR_STOP=1 -h 127.0.0.1 -p 55432 -U ironbody)
git -C "$W" show "$REF:./docs/ultron-studio/ops/supresion-1581.sql" > "$SALIDA/anterior.sql" || exit 2
NUEVO="$W/docs/ultron-studio/ops/supresion-1581.sql"
parametros() { # $1 base, $2 dueño, $3 admin, $4 dry, $5 subject → fichero 600
  local h; h=$("${P[@]}" -d "$1" -c "SELECT phone_hash FROM ultron_studio_canary_numbers WHERE status = 'revoked' ORDER BY id LIMIT 1" 2>/dev/null)
  ( umask 077
    printf '%s\n' "\\set radicado AB-$1" "\\set term 'Canario'" "\\set admin_id $3" "\\set owner $2" "\\set dry $4" > "$SALIDA/params-$1.psql"
    [ -n "$h" ] && printf '%s\n' "\\set phone_hash '$h'" >> "$SALIDA/params-$1.psql"
    [ "$5" = 1 ] && printf '%s\n' "\\set subject 'm:1'" >> "$SALIDA/params-$1.psql" )
  echo "$SALIDA/params-$1.psql"
}
correr() { cat "$1" "$2" | psql -X -q -h 127.0.0.1 -p 55432 -U ironbody -d "$3" > "$4" 2>&1; echo "rc=$?" >> "$4"; }
case "${1:-}" in
  seco)
    DB=$2; f=$(parametros "$DB" "$3" "$4" 1 "$5")
    correr "$f" "$SALIDA/anterior.sql" "$DB" "$SALIDA/$DB.anterior.log"; correr "$f" "$NUEVO" "$DB" "$SALIDA/$DB.nuevo.log"
    norm() { grep -v -E "columna sin política|columnas de la política no existen|disparadores del Studio" "$1" | grep -v '^NOTICE:  total:'; }
    voz='voice_|huella de voz|se INFORMA|destinos cifrados|VIVO'
    echo "== $DB · rc anterior/nuevo: $(tail -1 "$SALIDA/$DB.anterior.log") / $(tail -1 "$SALIDA/$DB.nuevo.log")"
    echo "   avisos «sin política» anterior/nuevo: $(grep -c 'columna sin política' "$SALIDA/$DB.anterior.log") / $(grep -c 'columna sin política' "$SALIDA/$DB.nuevo.log")"
    echo "   líneas del anterior que el nuevo no da: $(comm -23 <(norm "$SALIDA/$DB.anterior.log" | sort) <(norm "$SALIDA/$DB.nuevo.log" | sort) | wc -l | tr -d ' ')"
    echo "   líneas nuevas que no son de la voz: $(comm -13 <(norm "$SALIDA/$DB.anterior.log" | sort) <(norm "$SALIDA/$DB.nuevo.log" | grep -v -E "$voz" | sort) | wc -l | tr -d ' ')"
    echo "   totales anterior/nuevo: $(grep -o 'total: [0-9]*' "$SALIDA/$DB.anterior.log" | head -1) / $(grep -o 'total: [0-9]*' "$SALIDA/$DB.nuevo.log" | head -1) (la diferencia, en las líneas de la voz)"
    grep -E "^NOTICE:    (voice_|ultron_studio_voice_)" "$SALIDA/$DB.nuevo.log" | sed 's/^/     voz: /'
    ;;
  real)
    T=$2
    for v in anterior nuevo; do "${P[@]}" -d postgres -c "CREATE DATABASE ultron_studio_1581_ab_$v TEMPLATE $T" || exit 1; done
    OWNER=$("${P[@]}" -d ultron_studio_1581_ab_anterior -c "SELECT tableowner FROM pg_tables WHERE tablename = 'ultron_studio_audit_events'")
    f=$(parametros ultron_studio_1581_ab_anterior "$OWNER" "$3" 0 0)
    L0=$("${P[@]}" -d ultron_studio_1581_ab_anterior -c "SELECT coalesce(max(id), 0) FROM ultron_studio_audit_events")
    H=$("${P[@]}" -d ultron_studio_1581_ab_anterior -c "SELECT phone_hash FROM ultron_studio_canary_numbers WHERE status = 'revoked' ORDER BY id LIMIT 1")
    correr "$f" "$SALIDA/anterior.sql" ultron_studio_1581_ab_anterior "$SALIDA/real.anterior.log"
    correr "$f" "$NUEVO" ultron_studio_1581_ab_nuevo "$SALIDA/real.nuevo.log"
    echo "== real sobre copias de $T · rc anterior/nuevo: $(tail -1 "$SALIDA/real.anterior.log") / $(tail -1 "$SALIDA/real.nuevo.log")"
    dif=0; n=0
    for t in $("${P[@]}" -d ultron_studio_1581_ab_anterior -c "SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY 1"); do
      case "$t" in
        ultron_studio_canary_numbers) q="SELECT md5(coalesce(string_agg((to_jsonb(x) - 'phone_hash')::text, '|' ORDER BY x.id), '')) FROM public.\"$t\" x" ;;
        ultron_studio_audit_events) q="SELECT md5(coalesce(string_agg(x::text, '|' ORDER BY x.id), '')) FROM public.\"$t\" x WHERE x.id <= $L0" ;;
        *) q="SELECT md5(coalesce(string_agg(x::text, '|' ORDER BY x::text), '')) FROM public.\"$t\" x" ;;
      esac
      n=$((n+1)); [ "$("${P[@]}" -d ultron_studio_1581_ab_anterior -c "$q")" = "$("${P[@]}" -d ultron_studio_1581_ab_nuevo -c "$q")" ] || { dif=$((dif+1)); echo "   DISTINTA: $t"; }
    done
    echo "   tablas comparadas: $n · distintas: $dif"
    for v in anterior nuevo; do
      echo "   $v: libro +$("${P[@]}" -d ultron_studio_1581_ab_$v -c "SELECT count(*) FROM ultron_studio_audit_events WHERE id > $L0") · $("${P[@]}" -d ultron_studio_1581_ab_$v -c "SELECT summary FROM ultron_studio_audit_events WHERE id > $L0") · canarios con la huella original: $([ -n "$H" ] && "${P[@]}" -d ultron_studio_1581_ab_$v -c "SELECT count(*) FROM ultron_studio_canary_numbers WHERE phone_hash = '$H'" || echo -) · disparadores apagados: $("${P[@]}" -d ultron_studio_1581_ab_$v -c "SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron\_studio\_%' AND t.tgenabled <> 'O'")"
    done
    a=$("${P[@]}" -d ultron_studio_1581_ab_anterior -c "SELECT (metadata::jsonb - 'voice_informed')::text FROM ultron_studio_audit_events WHERE id > $L0")
    b=$("${P[@]}" -d ultron_studio_1581_ab_nuevo -c "SELECT (metadata::jsonb - 'voice_informed')::text FROM ultron_studio_audit_events WHERE id > $L0")
    echo "   metadata del libro igual salvo voice_informed: $([ "$a" = "$b" ] && echo sí || echo NO)"
    for v in anterior nuevo; do "${P[@]}" -d postgres -c "DROP DATABASE ultron_studio_1581_ab_$v"; done   # solo sus dos copias desechables
    ;;
  *) echo "uso: $0 seco <base> <dueño> <admin_id> <subject 0|1> | real <base_plantilla> <admin_id>" >&2; exit 2 ;;
esac
rm -f "$SALIDA"/params-*.psql   # llevan la huella de un canario del banco
echo "   salidas: $SALIDA"
