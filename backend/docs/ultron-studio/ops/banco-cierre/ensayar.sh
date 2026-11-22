#!/bin/bash
# Ensayos del banco del cierre (montar.sh) con deploy-cierre-lote.sh DEL REPO. Antes de cada ejecución se rehace la copia
# del banco en <banco>/root: SOLO cambian las líneas de la cabecera «Lo propio de producción» (cada una tiene que casar
# exactamente una vez, y al final se comprueba que el diff con el guion del repo son esas líneas y ninguna otra). El
# migrador es la copia versionada del envoltorio de producción (iron-ia/ops/ironbody-migrate), con config:clear antes y
# config:cache después como allí, y solo sin setpriv. Los dobles de <banco>/bin: sudo (sin cambio de usuario; apunta el
# modo de la caché tras cada config:cache en run/modos-config-cache), stat (GNU sobre BSD, con %Y), supervisorctl (14
# programas; el pid cambia con cada queue:restart; run/fallo-workers pone uno en BACKOFF y run/lento lo retrasa),
# systemctl, sha256sum, sleep (como mucho 0,3 s) y php (sin red: proxy a un puerto cerrado; cuenta los queue:restart y,
# con run/inyecta-error, escribe una línea de error en el registro en el siguiente). artisan, PostgreSQL, git y curl son
# de verdad.
#   bash ensayar.sh <banco> arrancar | parar | estado
#   bash ensayar.sh <banco> guion <argumentos de deploy-cierre-lote.sh…>
#   bash ensayar.sh <banco> borrar          para la API y BORRA la base del banco (el directorio queda en el scratchpad)
set -euo pipefail
export LC_ALL=C PGGSSENCMODE=disable
AQUI=$(cd "$(dirname "$0")" && pwd -P)
OPS=$(cd "$AQUI/.." && pwd -P)
DOCS=$(cd "$OPS/../.." && pwd -P)
muere() { echo "banco: $*" >&2; exit 1; }
[ "$#" -ge 2 ] || muere "uso: bash ensayar.sh <banco> arrancar|parar|estado|guion …|borrar"
B=$(python3 -I -c 'import os, sys; print(os.path.realpath(sys.argv[1]))' "$1"); ORDEN=$2; shift 2
[ -f "$B/banco.env" ] || muere "$B no es un banco montado (montar.sh)"
# shellcheck disable=SC1091
. "$B/banco.env"
PHPREAL=$(command -v php)
ME=$(id -un); GRUPO=$(id -gn)

dobles() {
  cat > "$B/bin/sudo" <<'S'
#!/bin/bash
# sudo del banco: ignora -u <usuario> y -H y ejecuta la orden como el usuario del Mac. Tras cada orden con
# «config:cache», apunta en run/modos-config-cache el modo con que quedó la caché (el instante antes del chmod de root).
while [ $# -gt 0 ]; do case "$1" in -u) shift 2 ;; -H) shift ;; *) break ;; esac; done
case " $* " in
  *" config:cache"*) "$@"; rc=$?; echo "$(/usr/bin/stat -f %Lp bootstrap/cache/config.php 2> /dev/null || echo -) $*" >> "$(dirname "$0")/../run/modos-config-cache"; exit $rc ;;
esac
exec "$@"
S
  cat > "$B/bin/stat" <<'S'
#!/bin/bash
# stat -c de GNU sobre el stat de BSD: %U %G %a %n %Y.
if [ "${1:-}" = -c ]; then f=$2; shift 2; f=${f//%U/%Su}; f=${f//%G/%Sg}; f=${f//%a/%Lp}; f=${f//%n/%N}; f=${f//%Y/%m}; exec /usr/bin/stat -f "$f" "$@"; fi
exec /usr/bin/stat "$@"
S
  cat > "$B/bin/supervisorctl" <<S
#!/bin/bash
# supervisorctl del banco: los 14 programas de producción. El pid cambia con cada queue:restart (los cuenta el php del
# banco); si $B/run/fallo-workers tiene un commit, mientras la «producción» lo sirva un worker queda en BACKOFF. Si
# $B/run/lento dice «<segundos> [backoff <commit>]», el primer worker no coge pid nuevo hasta esos segundos después del
# último queue:restart (un trabajo largo) y, con «backoff», al cogerlo queda en BACKOFF si la «producción» sirve <commit>.
case "\${1:-}" in status) ;; *) exit 0 ;; esac
r=\$(cat "$B/run/reinicios" 2> /dev/null || echo 0); f=\$(cat "$B/run/fallo-workers" 2> /dev/null || echo -)
h=\$(git -C "$B/prod" rev-parse HEAD); mal=0; case "\$h" in "\$f"*) mal=1 ;; esac
lento=""; if [ -s "$B/run/lento" ] && [ "\$r" -gt 0 ]; then read -r ls lm lc < "$B/run/lento"; t0=\$(cat "$B/run/reinicio-ts" 2> /dev/null || echo 0)
  if [ \$(( \$(date +%s) - t0 )) -lt "\$ls" ]; then lento=viejo; elif [ "\${lm:-}" = backoff ]; then case "\$h" in "\${lc:-x}"*) mal=1 ;; esac; fi; fi
n=0
linea() { n=\$((n + 1)); local rr=\$r; [ "\$lento" = viejo ] && [ "\$n" = 1 ] && rr=\$((r - 1))
  if [ "\$mal" = 1 ] && [ "\$n" = 1 ]; then printf '%-38s BACKOFF   Exited too quickly (process log may have details)\n' "\$1:\$1_\$2"; else printf '%-38s RUNNING   pid %s, uptime 0:10:00\n' "\$1:\$1_\$2" "\$((20000 + rr * 100 + n))"; fi; }
for i in 00 01 02 03; do linea ironbody-whatsapp "\$i"; done
for i in 00 01 02 03; do linea ironbody-agent "\$i"; done
for i in 00 01; do linea ironbody-media "\$i"; done
for i in 00 01; do linea ironbody-commercial "\$i"; done
linea ironbody-lab 00
linea ironbody-voice 00
exit 0
S
  cat > "$B/bin/systemctl" <<'S'
#!/bin/bash
[ "${1:-}" = is-active ] && { echo active; exit 0; }
exit 0
S
  cat > "$B/bin/sha256sum" <<'S'
#!/bin/bash
exec shasum -a 256 "$@"
S
  cat > "$B/bin/sleep" <<'S'
#!/bin/bash
# sleep del banco: como mucho 0,3 s (los plazos del guion van por date, no por el número de vueltas).
exec /bin/sleep 0.3
S
  cat > "$B/bin/php" <<S
#!/bin/bash
# php del banco: sin red (proxy a un puerto cerrado) y cuenta los queue:restart para el supervisorctl del banco. Si
# $B/run/inyecta-error existe, en el siguiente queue:restart añade su contenido (con @APP@ = la «producción») a
# laravel.log y lo vacía: un error que llega mientras el guion espera a los workers.
if [ "\${1:-}" = artisan ] && [ "\${2:-}" = queue:restart ]; then
  echo \$(( \$(cat "$B/run/reinicios" 2> /dev/null || echo 0) + 1 )) > "$B/run/reinicios"; date +%s > "$B/run/reinicio-ts"
  if [ -s "$B/run/inyecta-error" ]; then sed "s#@APP@#$B/prod#g" "$B/run/inyecta-error" >> "$B/prod/backend/storage/logs/laravel.log"; : > "$B/run/inyecta-error"; fi
fi
export http_proxy=http://127.0.0.1:9 https_proxy=http://127.0.0.1:9 HTTP_PROXY=http://127.0.0.1:9 HTTPS_PROXY=http://127.0.0.1:9 no_proxy=127.0.0.1,localhost NO_PROXY=127.0.0.1,localhost PGGSSENCMODE=disable
exec "$PHPREAL" "\$@"
S
  chmod 755 "$B"/bin/*
}

preparar() {
  python3 -I - "$OPS" "$DOCS" "$B" "$ME" "$GRUPO" "$BANCO_PORT" "$BANCO_DB" "$PHPREAL" <<'PY'
import sys
ops, docs, b, me, grupo, port, db, php = sys.argv[1:9]
def sustituye(origen, destino, reglas, exactas):
    s = open(origen, encoding='utf-8').read()
    lineas = s.split('\n')
    for viejo, nuevo in reglas:
        n = lineas.count(viejo) if exactas else s.count(viejo)
        if n == 0 or (exactas and n != 1):
            sys.exit(f'preparar: «{viejo[:80]}» aparece {n} veces en {origen} (esperado {"1 línea" if exactas else "≥ 1"}): el guion cambió')
        if exactas:
            lineas = [nuevo if l == viejo else l for l in lineas]
            s = '\n'.join(lineas)
        else:
            s = s.replace(viejo, nuevo)
            lineas = s.split('\n')
    open(destino, 'w', encoding='utf-8').write(s)
psql = f'-h 127.0.0.1 -p 55432 -U ironbody -d {db}'
sustituye(f'{ops}/deploy-cierre-lote.sh', f'{b}/root/deploy-cierre-lote.sh', [
    ('APP=/var/www/api', f'APP={b}/prod'),
    ('WWW=www-data', f'WWW={me}'),
    ('ROOT_U=root', f'ROOT_U={me}'),
    ('ROOT_G=root', f'ROOT_G={grupo}'),
    ('API=https://api.ironbodyneiva.cloud/api', f'API=http://127.0.0.1:{port}/api'),
    ('BK_RAIZ=/var/backups/ironbody', f'BK_RAIZ={b}/backups'),
    ('MIG_RAIZ=/var/lib/ironbody-deploy', f'MIG_RAIZ={b}/var-lib'),
    ('MIGRADOR=/usr/local/bin/ironbody-migrate', f'MIGRADOR={b}/root/ironbody-migrate'),
    ('PSQL=(sudo -u postgres psql -X -Atq -v ON_ERROR_STOP=1 -d ironbody)', f'PSQL=(psql -X -Atq -v ON_ERROR_STOP=1 {psql})'),
    ('PG_DUMP=(sudo -u postgres pg_dump -d ironbody)', f'PG_DUMP=(pg_dump {psql})'),
    ('DUENO_BD=iron', 'DUENO_BD=h5_owner'),
    ('APP_BD=iron_app', 'APP_BD=h5_app'),
    ('FPM_ETC=/etc/php', f'FPM_ETC={b}/etc-php'),
    ('NIVEL_ERROR=production.ERROR', 'NIVEL_ERROR=local.ERROR'),
    ('GUION=/root/deploy-cierre-lote.sh', f'GUION={b}/root/deploy-cierre-lote.sh'),
], True)
sustituye(f'{docs}/iron-ia/ops/ironbody-migrate', f'{b}/root/ironbody-migrate', [
    ('cd /var/www/api/backend', f'cd {b}/prod/backend'),
    ('/root/.ironbody-owner.env', f'{b}/root/.ironbody-owner.env'),
    ('  export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin HOME=/var/www USER=www-data LOGNAME=www-data \\',
     f'  export PATH={b}/bin:/usr/bin:/bin HOME={b} USER={me} LOGNAME={me} ULTRON_STUDIO_DB_APP_ROLE=h5_app PGGSSENCMODE=disable \\'),
    ('  exec setpriv --reuid=www-data --regid=www-data --init-groups \\', '  exec \\'),
], False)
PY
  chmod 700 "$B/root/deploy-cierre-lote.sh" "$B/root/ironbody-migrate"
  bash -n "$B/root/deploy-cierre-lote.sh"
  local d; d=$(diff "$OPS/deploy-cierre-lote.sh" "$B/root/deploy-cierre-lote.sh" | grep -c '^>' || true)
  [ "$d" = 15 ] || muere "la copia del banco difiere del guion del repo en $d líneas (esperadas 15, las de la cabecera)"
  echo "== preparado: $B/root/deploy-cierre-lote.sh (15 líneas de cabecera sustituidas, ninguna más) · migrador del banco $(diff "$DOCS/iron-ia/ops/ironbody-migrate" "$B/root/ironbody-migrate" | grep -c '^>' || true) líneas cambiadas"
}

pid_api() { cat "$B/run/api.pid" 2> /dev/null || true; }
case "$ORDEN" in
  arrancar)
    dobles
    [ -z "$(lsof -nP -iTCP:"$BANCO_PORT" -sTCP:LISTEN 2> /dev/null | tail -n +2)" ] || muere "el puerto $BANCO_PORT ya está ocupado"
    ( cd "$B/prod/backend" && nohup env PATH="$B/bin:$PATH" php artisan serve --host=127.0.0.1 --port="$BANCO_PORT" --no-reload > "$B/logs/api.out" 2>&1 < /dev/null & echo $! > "$B/run/api.pid" )
    for _ in $(seq 1 40); do
      c=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "http://127.0.0.1:$BANCO_PORT/api/admin/voice/status" || true)
      [ "$c" = 401 ] && break
      /bin/sleep 0.5
    done
    [ "$c" = 401 ] || muere "la API del banco no respondió 401 (ver $B/logs/api.out)"
    echo "API del banco en :$BANCO_PORT (admin/voice/status sin token → 401)" ;;
  parar)
    p=$(pid_api)
    if [ -n "$p" ]; then for c in $(pgrep -P "$p" 2> /dev/null) "$p"; do kill "$c" 2> /dev/null || true; done; fi
    : > "$B/run/api.pid"; echo "API del banco parada" ;;
  estado)
    echo "prod: HEAD $(git -C "$B/prod" rev-parse --short=8 HEAD) · origin/main $(git -C "$B/origen.git" rev-parse --short=8 main) · árbol $([ -z "$(git -C "$B/prod" status --porcelain --untracked-files=no)" ] && echo limpio || echo SUCIO)"
    echo "base $BANCO_DB: $(psql -X -Atq -h 127.0.0.1 -p 55432 -U ironbody -d "$BANCO_DB" -c "SELECT count(*) FROM migrations") migraciones registradas · reinicios de colas: $(cat "$B/run/reinicios" 2> /dev/null || echo 0)" ;;
  guion)
    dobles; preparar
    set +e; env PATH="$B/bin:$PATH" bash "$B/root/deploy-cierre-lote.sh" "$@"; rc=$?; set -e
    echo "→ código de salida $rc"; exit "$rc" ;;
  borrar)
    p=$(pid_api)
    if [ -n "$p" ]; then for c in $(pgrep -P "$p" 2> /dev/null) "$p"; do kill "$c" 2> /dev/null || true; done; fi
    RE_DB='^ultron_studio_ops2?_[a-z0-9_]{1,40}$'
    [[ "$BANCO_DB" =~ $RE_DB ]] || muere "la base $BANCO_DB no es del banco del cierre: no se borra"
    psql -X -q -v ON_ERROR_STOP=1 -h 127.0.0.1 -p 55432 -U ironbody -d postgres -c "DROP DATABASE IF EXISTS $BANCO_DB;"
    echo "base $BANCO_DB borrada; el directorio $B queda (scratchpad de la sesión)" ;;
  *) muere "orden desconocida: $ORDEN" ;;
esac
