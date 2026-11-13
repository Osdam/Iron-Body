#!/usr/bin/env bash
# Pruebas del envoltorio ironbody-migrate ANTES de instalarlo (docs/iron-ia/ops/ironbody-migrate).
#
# En el servidor, como root:
#   bash probar-ironbody-migrate.sh <candidato>              T0–T2: no tocan la base ni la caché
#   bash probar-ironbody-migrate.sh <candidato> --completa   además T3: el candidato entero con --pretend
#                                                            (config:clear + migrate --pretend + config:cache,
#                                                            lo mismo que cualquier migración, sin ejecutar SQL)
#
# Nunca imprime la contraseña ni la pasa como argumento: se busca por entorno
# (awk ENVIRON) en la lista de procesos, en /var/log/auth.log y en el journal,
# y solo se escriben recuentos. Las credenciales REALES solo se usan (T2, T3) si
# antes el mecanismo salió limpio con un valor FALSO (T0, T1): un mecanismo que
# filtre no llega a ver la contraseña de verdad.
#
# INSTALACIÓN (solo si todo sale OK):
#   TS=$(date -u +%Y%m%d-%H%M%S)
#   install -m 700 -o root -g root /usr/local/bin/ironbody-migrate /var/backups/ironbody/ironbody-migrate.$TS
#   install -m 700 -o root -g root <candidato> /usr/local/bin/ironbody-migrate.new
#   mv /usr/local/bin/ironbody-migrate.new /usr/local/bin/ironbody-migrate
# VUELTA ATRÁS:
#   install -m 700 -o root -g root /var/backups/ironbody/ironbody-migrate.$TS /usr/local/bin/ironbody-migrate
set -euo pipefail
export LC_ALL=C

[ "$(id -u)" = 0 ] || { echo "se ejecuta como root"; exit 2; }
[ "$#" -ge 1 ] && [ -r "$1" ] || { echo "uso: $0 <candidato> [--completa]"; exit 2; }
CAND="$1"; MODO="${2:-}"
B=/var/www/api/backend
API=https://api.ironbodyneiva.cloud/api
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT

fallos=0
ok()  { echo "OK    $*"; }
mal() { echo "FALLO $*"; fallos=$((fallos + 1)); }

# Recuento de líneas de la entrada que contienen el valor ($1 llega por entorno a awk, nunca por argv).
cuenta() { BUSCA="$1" awk 'index($0, ENVIRON["BUSCA"]) > 0 { c++ } END { print c + 0 }'; }

# Muestrea `ps` cada 0,1 s mientras exista $2.activo y deja en $2 un recuento por muestra.
vigilar() {
  local valor="$1" out="$2"
  : > "$out"
  while [ -e "$out.activo" ]; do ps -eo args= | cuenta "$valor" >> "$out"; sleep 0.1; done
}
maximo() { sort -n "$1" | tail -1; }
en_registros() { # recuento del valor en auth.log + journal de hoy
  local a j
  a=$(cuenta "$1" < /var/log/auth.log)
  j=$(journalctl -q --no-pager --since today 2>/dev/null | cuenta "$1")
  echo $((a + j))
}

# Igual que el candidato: entorno limpio y setpriv a www-data. $@ = orden a ejecutar.
como_www() {
  for v in $(compgen -e); do
    case "$v" in LANG|LC_ALL|TERM|DB_OWNER_USERNAME|DB_OWNER_PASSWORD|BUSCA) ;; *) unset "$v" 2>/dev/null || true ;; esac
  done
  export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin HOME=/var/www USER=www-data LOGNAME=www-data \
    PGOPTIONS="-c lock_timeout=5s"
  exec setpriv --reuid=www-data --regid=www-data --init-groups "$@"
}

# ── T0: el candidato no pone credenciales en ningún argv ni en sudo ──────────
codigo=$(grep -Ev '^[[:space:]]*#' "$CAND")   # sin comentarios
if grep -q 'exec setpriv --reuid=www-data --regid=www-data --init-groups' <<< "$codigo" \
  && ! grep -Eq 'DB_OWNER_(USERNAME|PASSWORD)="?\$' <<< "$codigo" \
  && ! grep -q -- '--preserve-env' <<< "$codigo"; then
  ok "T0 el candidato cambia a www-data con setpriv y no pasa credenciales por argumento ni por sudo"
else
  mal "T0 el candidato todavía pasa credenciales por la línea de órdenes o por sudo"
fi
bash -n "$CAND" && ok "T0 sintaxis del candidato" || mal "T0 sintaxis del candidato"

# ── T1: el mecanismo, con un valor FALSO ──────────────────────────────────────
marca="MARCA-PRUEBA-$(date +%s)-$RANDOM"
touch "$T/viejo.activo"; vigilar "$marca" "$T/viejo" & v=$!
sudo -u www-data env DB_OWNER_PASSWORD="$marca" sleep 2
rm -f "$T/viejo.activo"; wait "$v"; sleep 1
[ "$(maximo "$T/viejo")" -ge 1 ] && ok "T1 el método ANTERIOR sí deja el valor a la vista de ps (control)" \
  || mal "T1 el control no reproduce la exposición: la prueba no sirve"
[ "$(en_registros "$marca")" -ge 1 ] && ok "T1 el método ANTERIOR sí lo deja en auth.log/journal (control)" \
  || echo "AVISO T1 el control no aparece en los registros (¿sudo sin registro?)"

# sudo --preserve-env tampoco sirve: registra «ENV=…» (control con valor falso).
marca="MARCA-PRUEBA-$(date +%s)-$RANDOM"
( export DB_OWNER_PASSWORD="$marca"; exec sudo --preserve-env=DB_OWNER_PASSWORD -u www-data true )
sleep 1
[ "$(en_registros "$marca")" -ge 1 ] && ok "T1 sudo --preserve-env también registra el valor (control: por eso no se usa sudo)" \
  || echo "AVISO T1 sudo --preserve-env no registró el valor falso"

marca="MARCA-PRUEBA-$(date +%s)-$RANDOM"
touch "$T/nuevo.activo"; vigilar "$marca" "$T/nuevo" & v=$!
visto=$(
  export DB_OWNER_PASSWORD="$marca"
  como_www sh -c 'if [ -n "$DB_OWNER_PASSWORD" ]; then echo "presente $(id -un) $PGOPTIONS"; else echo ausente; fi; sleep 2'
)
rm -f "$T/nuevo.activo"; wait "$v"; sleep 1
[ "$visto" = "presente www-data -c lock_timeout=5s" ] && ok "T1 www-data recibe la variable y PGOPTIONS con setpriv" || mal "T1 setpriv: «$visto»"
[ "$(maximo "$T/nuevo")" = 0 ] && ok "T1 el método nuevo no deja el valor a la vista de ps" || mal "T1 el método nuevo deja el valor en ps"
[ "$(en_registros "$marca")" = 0 ] && ok "T1 el método nuevo no deja el valor en auth.log/journal" || mal "T1 el método nuevo lo deja en los registros"
if [ "$fallos" -gt 0 ]; then
  echo "PARADA: el mecanismo no salió limpio con un valor falso; no se usan las credenciales reales. fallos: $fallos"
  exit 1
fi

# ── T2: las credenciales REALES llegan y conectan como el dueño (solo lectura) ──
pw=$(bash -c 'set -a; . /root/.ironbody-owner.env; printf %s "$DB_OWNER_PASSWORD"')
antes=$(en_registros "$pw")
touch "$T/real.activo"; vigilar "$pw" "$T/real" & v=$!
quien=$(
  set -a; . /root/.ironbody-owner.env; set +a
  cd "$B"
  como_www php -r '$c = (require "bootstrap/cache/config.php")["database"]["connections"]["pgsql"];
      $pdo = new PDO("pgsql:host={$c["host"]};port={$c["port"]};dbname={$c["database"]}", getenv("DB_OWNER_USERNAME"), getenv("DB_OWNER_PASSWORD"));
      $r = $pdo->query("select current_user as u, current_setting(\x27lock_timeout\x27) as t")->fetch(PDO::FETCH_ASSOC);
      sleep(2); echo $r["u"], " ", $r["t"];' 2>&1
) || true
rm -f "$T/real.activo"; wait "$v"; sleep 1
case "$quien" in
  "iron 5s") ok "T2 conecta como el dueño (iron) con lock_timeout=5s" ;;
  *) mal "T2 no conecta como el dueño: $(printf %s "$quien" | BUSCA="$pw" awk '{ gsub(ENVIRON["BUSCA"], "***") } 1' | head -c 200 | tr -cd '[:print:] ')" ;;
esac
[ "$(maximo "$T/real")" = 0 ] && ok "T2 la contraseña real no aparece en ps" || mal "T2 la contraseña real aparece en ps"
[ "$(en_registros "$pw")" = "$antes" ] && ok "T2 la contraseña real no se añade a auth.log/journal ($antes previas, las del método anterior)" \
  || mal "T2 la contraseña real se añadió a los registros"

# ── T3: el candidato entero con --pretend ─────────────────────────────────────
if [ "$MODO" = --completa ]; then
  antes=$(en_registros "$pw")
  touch "$T/t3.activo"; vigilar "$pw" "$T/t3" & v=$!
  set +e; bash "$CAND" --pretend > "$T/t3.out" 2>&1; rc=$?; set -e
  rm -f "$T/t3.activo"; wait "$v"; sleep 1
  [ "$rc" = 0 ] && ok "T3 el candidato termina bien con --pretend" || mal "T3 el candidato falla con --pretend (rc=$rc)"
  [ "$(cuenta "$pw" < "$T/t3.out")" = 0 ] || mal "T3 la salida contiene la contraseña"
  grep -q "Nothing to migrate" "$T/t3.out" && ok "T3 nada pendiente: --pretend no tenía nada que mostrar" \
    || echo "AVISO T3 hay migraciones pendientes en producción; --pretend solo mostró su SQL (no se ejecutó): $(grep -c . "$T/t3.out") líneas"
  [ "$(maximo "$T/t3")" = 0 ] && ok "T3 la contraseña no aparece en ps durante la migración" || mal "T3 la contraseña aparece en ps"
  [ "$(en_registros "$pw")" = "$antes" ] && ok "T3 la contraseña no se añade a auth.log/journal" || mal "T3 la contraseña se añadió a los registros"
  [ "$(stat -c '%U:%G %a' "$B/bootstrap/cache/config.php")" = "www-data:www-data 600" ] \
    && ok "T3 caché de configuración www-data:www-data 600" || mal "T3 caché de configuración: $(stat -c '%U:%G %a' "$B/bootstrap/cache/config.php")"
  # root lee la caché (600 de www-data); la contraseña llega a php por entorno, no por argv.
  if BUSCA="$pw" php -r '$c = require "'"$B"'/bootstrap/cache/config.php"; $o = $c["database"]["connections"]["pgsql_owner"] ?? []; exit(($o["password"] ?? null) !== getenv("BUSCA") ? 0 : 1);'; then
    ok "T3 la caché regenerada NO lleva las credenciales del dueño"
  else
    mal "T3 la caché regenerada lleva las credenciales del dueño"
  fi
  c=$(curl -s --max-time 20 -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$API/trainer/exercises") || c=000
  [ "$c" = 401 ] && ok "T3 la API responde (401 sin sesión, como debe)" || mal "T3 la API responde $c"
fi

echo "fallos: $fallos"
exit $((fallos > 0))
