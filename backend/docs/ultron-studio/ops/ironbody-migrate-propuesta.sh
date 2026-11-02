#!/bin/bash
# ironbody-migrate · PROPUESTA del Hito 7 (contrato H7, OPS7 (c)). NO ESTÁ INSTALADA: la instala el propietario
# (instrucciones al final de este fichero y en plans/informes-h7/informe-devops-h7.md, «ironbody-migrate sin root»).
#
# Qué resuelve. El envoltorio de hoy (/usr/local/bin/ironbody-migrate) ejecuta `php artisan migrate` COMO ROOT, y al
# arrancar Laravel ese root incluye bootstrap/cache/{config,services,packages,routes-v7,events}.php, que son de
# www-data: un compromiso de la aplicación (PHP-FPM corre como www-data) se convertiría en root en la siguiente
# migración (clase M-3 del H5; D2-3 del reviewer; N-7). Esta versión:
#   1. NO ejecuta como root nada del árbol: la migración corre como el usuario dedicado `ironbody-migrate` (sin shell,
#      sin login, fuera de www-data y de sudo), con setpriv --no-new-privs y un entorno vacío (env -i);
#   2. NO ejecuta NADA escribible por www-data: las cachés de arranque van a un directorio privado y vacío de /run
#      (APP_CONFIG_CACHE, APP_SERVICES_CACHE, APP_PACKAGES_CACHE, APP_ROUTES_CACHE y APP_EVENTS_CACHE), y antes de
#      empezar comprueba que el código (artisan, bootstrap/app.php, bootstrap/providers.php, app, config, database,
#      routes, vendor, composer.*) y sus directorios padre son de root y no los puede escribir nadie más;
#   3. la credencial del dueño vive en /etc/ironbody-migrate/owner.env, 600 y del usuario dedicado: root no la lee
#      nunca, www-data no puede leerla, y no pasa por la línea de órdenes (ps) ni por el entorno de nadie más;
#   4. el DESTINO de la conexión del dueño (DB_HOST, DB_PORT, DB_DATABASE, DB_URL) sale de esa credencial y no del
#      .env de la aplicación: si www-data pudiera escribir el .env, no podría desviar la contraseña del dueño a otro
#      servidor. Lo demás del .env lo lee una copia privada (600, en /run, borrada al salir);
#   5. `lock_timeout` lo pone el envoltorio (5 s por defecto); quien llama solo puede cambiar ESE valor por PGOPTIONS
#      (cualquier otra opción del servidor, p. ej. search_path, se rechaza: el dueño puede desactivar disparadores);
#   6. regenera la caché de configuración COMO www-data, con umask 077 (600 desde que nace, N-7), y SOLO si el .env
#      parsea; NO toca la caché de rutas (los scripts de despliegue la regeneran tras sus aserciones del esquema);
#   7. una sola ejecución a la vez (flock) y una línea por ejecución en /var/log/ironbody-migrate.log.
# A diferencia del de hoy, no vacía la caché compartida ANTES de migrar: la aplicación sigue sirviendo con la suya
# durante la migración, y si el .env no parsea la caché vieja se queda (B-1 del H5).
#
# Uso (como root; o con sudo acotado a este fichero, ver el final):
#   ironbody-migrate              migrate --force --database=pgsql_owner y config:cache como www-data
#   ironbody-migrate --pretend    las sentencias que ejecutaría, sin ejecutarlas (no toca la caché)
#   ironbody-migrate --status     migrate:status con el rol dueño (solo lectura)
#   ironbody-migrate --check      comprueba la instalación y la conexión del dueño (migrate:status) sin migrar
# Compatible con los scripts de despliegue: `sudo env PGOPTIONS="-c lock_timeout=5s" /usr/local/bin/ironbody-migrate`.
# Códigos: 0 bien · 1 la migración o la caché fallaron · 2 instalación o uso incorrectos (no se tocó nada) · 75 otra
# ejecución en curso. Nunca imprime la credencial, el .env ni valores de configuración.
set -euo pipefail
export LC_ALL=C
umask 077

APP=/var/www/api/backend
REPO=/var/www/api
USUARIO=ironbody-migrate
CRED=/etc/ironbody-migrate/owner.env
LOG=/var/log/ironbody-migrate.log
CERROJO=/run/lock/ironbody-migrate.lock
RUNBASE=/run
WWW=www-data
PHP=/usr/bin/php
DUENO_ARBOL=root
# Ejecuta un mandato como otro usuario: uid y gid propios y sus grupos, sin poder ganar privilegios (no_new_privs),
# sin capacidades heredables. setpriv es de util-linux (Ubuntu); no pasa por PAM ni por sudo.
como() { local u=$1; shift; setpriv --reuid="$u" --regid="$u" --init-groups --no-new-privs --inh-caps=-all -- "$@"; }
es_root() { [ "$(id -u)" = 0 ]; }

muere() { echo "ironbody-migrate: $*" >&2; exit 2; }
uso() { echo "uso: ironbody-migrate [--pretend|--status|--check]" >&2; exit 2; }

[ "$#" -le 1 ] || uso
case "${1:-}" in
  "") MODO=migrate ;;
  --pretend) MODO=pretend ;;
  --status) MODO=status ;;
  --check) MODO=check ;;
  *) uso ;;
esac
es_root || muere "se ejecuta como root (o con sudo); no se ha tocado nada"
command -v setpriv > /dev/null || muere "falta setpriv (util-linux)"
[ -x "$PHP" ] || muere "falta $PHP"

# ── lock_timeout: el del envoltorio, o el de quien llama si es EXACTAMENTE «-c lock_timeout=N(ms|s)» ──────────────
PGOPT="-c lock_timeout=5s"
if [ -n "${PGOPTIONS:-}" ]; then
  [[ "$PGOPTIONS" =~ ^-c\ lock_timeout=[0-9]{1,6}(ms|s)$ ]] || muere "PGOPTIONS solo admite «-c lock_timeout=N(ms|s)» (otra opción del servidor, con el rol dueño, no se pasa); no se ha tocado nada"
  PGOPT="$PGOPTIONS"
fi

# ── Una sola ejecución a la vez ───────────────────────────────────────────────────────────────────────────────────
exec 9> "$CERROJO"
flock -n 9 || { echo "ironbody-migrate: otra ejecución en curso; no se ha tocado nada" >&2; exit 75; }

# ── El usuario dedicado ───────────────────────────────────────────────────────────────────────────────────────────
ENT=$(getent passwd "$USUARIO" || true)
[ -n "$ENT" ] || muere "no existe el usuario $USUARIO (instalación, paso 1)"
UID_M=$(printf '%s' "$ENT" | cut -d: -f3); SHELL_M=$(printf '%s' "$ENT" | cut -d: -f7)
[ "$UID_M" != 0 ] || muere "$USUARIO tiene uid 0"
case "$SHELL_M" in /usr/sbin/nologin|/sbin/nologin|/bin/false|/usr/bin/false) ;; *) muere "$USUARIO tiene shell de login ($SHELL_M): tiene que ser /usr/sbin/nologin" ;; esac
for g in $(id -nG "$USUARIO"); do
  case "$g" in "$WWW"|sudo|adm|root|wheel|admin|staff|postgres) muere "$USUARIO está en el grupo $g: no puede compartir grupo con la aplicación ni administrar" ;; esac
done

# ── La credencial del dueño: un fichero 600 del usuario dedicado, en un directorio de root ────────────────────────
DIRCRED=$(dirname "$CRED")
[ -d "$DIRCRED" ] && [ ! -L "$DIRCRED" ] || muere "falta $DIRCRED (instalación, paso 2)"
[ "$(stat -c '%U' "$DIRCRED")" = "$DUENO_ARBOL" ] || muere "$DIRCRED tiene que ser de $DUENO_ARBOL"
case "$(stat -c '%a' "$DIRCRED")" in 700|710|750) ;; *) muere "$DIRCRED tiene que ser 750 (o 710/700), no $(stat -c '%a' "$DIRCRED")" ;; esac
[ -f "$CRED" ] && [ ! -L "$CRED" ] || muere "falta $CRED, o es un enlace (instalación, paso 2)"
[ "$(stat -c '%U %a' "$CRED")" = "$USUARIO 600" ] || muere "$CRED tiene que ser de $USUARIO y 600 (es $(stat -c '%U %a' "$CRED"))"

# ── El código que se va a ejecutar: de root y sin escritura para nadie más (ni el árbol ni sus directorios padre) ──
cd "$APP"
# find -L: un enlace se juzga por su destino (un enlace de root que apunte a un fichero de www-data no pasa).
MALOS=$(find -L artisan bootstrap/app.php bootstrap/providers.php app config database routes vendor composer.json composer.lock \
  \( ! -user "$DUENO_ARBOL" -o -perm /022 \) -print 2> /dev/null | head -10 || true)
[ -z "$MALOS" ] || muere "código que no es de $DUENO_ARBOL o que otro puede escribir (los primeros 10):
$MALOS
(chown $DUENO_ARBOL y chmod go-w; nunca se migra ejecutando código que www-data pueda cambiar)"
D="$APP"
while :; do
  [ "$(stat -c '%U' "$D")" = "$DUENO_ARBOL" ] && [ $(( 0$(stat -c '%a' "$D") & 022 )) = 0 ] \
    || muere "el directorio $D no es de $DUENO_ARBOL o lo puede escribir otro: quien lo escribe puede sustituir el código"
  [ "$D" = / ] && break
  D=$(dirname "$D")
done
for d in bootstrap; do
  [ "$(stat -c '%U' "$d")" = "$DUENO_ARBOL" ] && [ $(( 0$(stat -c '%a' "$d") & 022 )) = 0 ] || muere "$APP/$d no es de $DUENO_ARBOL o lo puede escribir otro"
done
[ -f .env ] && [ ! -L .env ] || muere "falta $APP/.env, o es un enlace"
# El .env lo copia aquí root (copiarlo no ejecuta nada) y lo lee el proceso que migra. Si www-data pudiera escribirlo,
# podría cambiar lo que ese proceso hace con la credencial del dueño (p. ej., variables PG* que libpq lee del entorno):
# se RECHAZA (N-4b del H6; SEC7 del H7). Lo recomendado: root:www-data 640 (www-data lo lee para config:cache).
if [ "$(stat -c '%U' .env)" != "$DUENO_ARBOL" ] || [ $(( 0$(stat -c '%a' .env) & 022 )) != 0 ]; then
  muere "$APP/.env tiene que ser de $DUENO_ARBOL y sin escritura para el grupo ni para otros (recomendado $DUENO_ARBOL:$WWW 640; instalación, paso 3b): es $(stat -c '%U:%G %a' .env)"
fi

# ── Directorio privado de esta ejecución (tmpfs): la copia del .env y las cachés de arranque del proceso que migra ──
RUN=$(mktemp -d "$RUNBASE/ironbody-migrate.XXXXXX")
limpia() { rm -rf -- "$RUN"; }
trap limpia EXIT
chown "$USUARIO:$USUARIO" "$RUN" && chmod 700 "$RUN"
install -d -m 700 -o "$USUARIO" -g "$USUARIO" "$RUN/cache" "$RUN/env"
install -m 600 -o "$USUARIO" -g "$USUARIO" .env "$RUN/env/.env"

COMMIT=$(git -C "$REPO" rev-parse --short=7 HEAD 2> /dev/null || echo '?')
echo "ironbody-migrate: $MODO como $USUARIO (sin root sobre el árbol) · $PGOPT · código $COMMIT"

# ── La migración (o la vista previa, o el estado), como el usuario dedicado ───────────────────────────────────────
case "$MODO" in check) FASE=status ;; *) FASE=$MODO ;; esac
set +e
como "$USUARIO" env -i PATH=/usr/bin:/bin LANG=C.UTF-8 HOME="$RUN" \
  APP_CONFIG_CACHE="$RUN/cache/config.php" APP_ROUTES_CACHE="$RUN/cache/routes-v7.php" \
  APP_SERVICES_CACHE="$RUN/cache/services.php" APP_PACKAGES_CACHE="$RUN/cache/packages.php" \
  APP_EVENTS_CACHE="$RUN/cache/events.php" LOG_CHANNEL=stderr PGOPTIONS="$PGOPT" \
  IRONBODY_MIGRATE_APP="$APP" IRONBODY_MIGRATE_ENV_DIR="$RUN/env" IRONBODY_MIGRATE_CRED="$CRED" \
  IRONBODY_MIGRATE_RUN="$RUN" IRONBODY_MIGRATE_MODE="$FASE" IRONBODY_MIGRATE_PGOPTIONS="$PGOPT" \
  "$PHP" -d display_errors=stderr -d log_errors=0 -- <<'LANZADOR'
<?php
// El lanzador del proceso que migra (lo ejecuta el usuario dedicado; viene por la entrada estándar, no es un fichero).
declare(strict_types=1);
$app_dir = (string) getenv('IRONBODY_MIGRATE_APP');
$cred = (string) getenv('IRONBODY_MIGRATE_CRED');
$run = (string) getenv('IRONBODY_MIGRATE_RUN');
$mode = (string) getenv('IRONBODY_MIGRATE_MODE');
$fail = static function (string $m): never { fwrite(STDERR, "ironbody-migrate: {$m}\n"); exit(2); };
require $app_dir.'/vendor/autoload.php';
// 1. La credencial del dueño, con el mismo analizador que Laravel; solo estas claves.
try {
    $c = Dotenv\Dotenv::createArrayBacked(dirname($cred), basename($cred))->load();
} catch (Throwable $e) {
    $fail('la credencial del dueño no se puede leer o no parsea ('.get_class($e).')');
}
$permitidas = ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_OWNER_USERNAME', 'DB_OWNER_PASSWORD', 'DB_SSLMODE', 'DB_URL'];
$obligatorias = ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_OWNER_USERNAME', 'DB_OWNER_PASSWORD'];
foreach (array_keys($c) as $k) {
    in_array($k, $permitidas, true) || $fail("clave no admitida en la credencial: {$k}");
}
foreach ($obligatorias as $k) {
    (isset($c[$k]) && $c[$k] !== '') || $fail("falta {$k} en la credencial");
}
// El destino de la base sale de la credencial, nunca del .env de la aplicación (DB_URL vacía salvo que la traiga).
$c += ['DB_URL' => ''];
foreach ($c as $k => $v) {
    putenv("{$k}={$v}");
    $_ENV[$k] = $_SERVER[$k] = $v;
}
$usuario = $c['DB_OWNER_USERNAME'];
$host = $c['DB_HOST'];
$base = $c['DB_DATABASE'];
unset($c);
// 2. La aplicación, con el .env de la copia privada y las cachés de arranque privadas (vacías).
$app = require $app_dir.'/bootstrap/app.php';
$app->useEnvironmentPath((string) getenv('IRONBODY_MIGRATE_ENV_DIR'));
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
str_starts_with($app->getCachedConfigPath(), $run.'/') || $fail('la caché de configuración no es la privada: no se migra');
str_starts_with($app->getCachedServicesPath(), $run.'/') && str_starts_with($app->getCachedPackagesPath(), $run.'/') || $fail('las cachés de arranque no son las privadas: no se migra');
$app->configurationIsCached() && $fail('hay una caché de configuración donde no debería: no se migra');
// Laravel exporta el .env al entorno del proceso (putenv), y libpq lee de ahí PGHOSTADDR, PGSERVICE, PGPASSFILE… al
// conectar: una de esas en el .env desviaría la conexión del dueño. Ninguna, salvo el PGOPTIONS del envoltorio.
foreach (array_keys(getenv()) as $k) {
    if (preg_match('/^(PG|LD_|DYLD_)/', (string) $k) === 1 && $k !== 'PGOPTIONS') {
        $fail("el entorno trae {$k} (¿del .env?): libpq o el enlazador lo leerían; no se migra");
    }
}
getenv('PGOPTIONS') === getenv('IRONBODY_MIGRATE_PGOPTIONS') || $fail('PGOPTIONS no es la del envoltorio: no se migra');
$o = (array) config('database.connections.pgsql_owner');
(($o['username'] ?? null) === $usuario && ($o['host'] ?? null) === $host && ($o['database'] ?? null) === $base && in_array($o['url'] ?? null, [null, ''], true))
    || $fail('la conexión pgsql_owner no es la de la credencial: no se migra');
// 3. El mandato.
$args = match ($mode) {
    'migrate' => ['artisan', 'migrate', '--database=pgsql_owner', '--force', '--no-interaction'],
    'pretend' => ['artisan', 'migrate', '--database=pgsql_owner', '--force', '--pretend', '--no-interaction'],
    'status' => ['artisan', 'migrate:status', '--database=pgsql_owner', '--no-interaction'],
    default => $fail("modo desconocido: {$mode}"),
};
$input = new Symfony\Component\Console\Input\ArgvInput($args);
$status = $kernel->handle($input, new Symfony\Component\Console\Output\ConsoleOutput);
$kernel->terminate($input, $status);
exit($status);
LANZADOR
RC=$?
set -e

# ── La caché de configuración, como www-data (umask 077: nace 600), solo si el .env parsea ────────────────────────
CACHE=ok
if [ "$MODO" = migrate ]; then
  if como "$WWW" env -i PATH=/usr/bin:/bin "$PHP" -r 'require "vendor/autoload.php"; try { Dotenv\Dotenv::createArrayBacked(".")->load(); } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }'; then
    if como "$WWW" env -i PATH=/usr/bin:/bin HOME=/nonexistent sh -c 'umask 077; exec "$0" artisan config:cache -q' "$PHP"; then
      # bootstrap/cache lo escribe www-data: root no hace chmod ahí (seguiría un enlace que www-data hubiera puesto en
      # su lugar; H-5 de SEC7). Con umask 077 nace 600; si no, el chmod lo hace www-data, que solo cambia lo suyo.
      F=bootstrap/cache/config.php
      if [ -L "$F" ] || [ ! -f "$F" ]; then
        echo "ironbody-migrate: $F no es un fichero normal (¿un enlace?): no se toca" >&2; CACHE=mal
      elif [ "$(stat -c '%U' "$F")" != "$WWW" ]; then
        echo "ironbody-migrate: $F no es de $WWW" >&2; CACHE=mal
      elif [ "$(stat -c '%a' "$F")" != 600 ]; then
        if como "$WWW" chmod 600 "$F"; then echo "ironbody-migrate: $F pasado a 600 (como $WWW)" >&2; else CACHE=mal; fi
      fi
    else
      echo "ironbody-migrate: config:cache falló como $WWW" >&2; CACHE=mal
    fi
  else
    echo "ironbody-migrate: el .env NO parsea: la caché de configuración vieja se queda (repararlo y relanzar)" >&2; CACHE=mal
  fi
fi

FIN=$RC; [ "$CACHE" = ok ] || [ "$FIN" != 0 ] || FIN=1
{ umask 027; printf '%s modo=%s quien=%s commit=%s rc=%s cache=%s %s\n' "$(date -u +%FT%TZ)" "$MODO" "${SUDO_USER:-root}" "$COMMIT" "$RC" "$CACHE" "$PGOPT" >> "$LOG"; } 2> /dev/null || true
echo "ironbody-migrate: $MODO terminado con código $FIN (migración $RC · caché $CACHE)"
exit "$FIN"

# ── INSTALACIÓN (la hace el propietario en el servidor, como root, con autorización; no la hace este fichero) ─────────
# 0. Respaldo del envoltorio de hoy (vuelta atrás = devolverlo a su sitio):
#      install -m 700 /usr/local/bin/ironbody-migrate /root/ironbody-migrate.pre-h7
# 1. Usuario dedicado, sin shell, sin casa y con grupo propio:
#      useradd --system --user-group --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin ironbody-migrate
# 2. La credencial: de las mismas piezas que /root/.ironbody-owner.env, SIN imprimirlas (con un editor o así):
#      install -d -m 750 -o root -g ironbody-migrate /etc/ironbody-migrate
#      install -m 600 -o ironbody-migrate -g ironbody-migrate /dev/null /etc/ironbody-migrate/owner.env
#      grep -E '^(DB_OWNER_USERNAME|DB_OWNER_PASSWORD)=' /root/.ironbody-owner.env >> /etc/ironbody-migrate/owner.env
#      # y a mano, con los valores del .env de la aplicación (no son secretos): DB_HOST=… DB_PORT=… DB_DATABASE=…
#      # (DB_SSLMODE=… si el .env lo trae). Solo esas claves: otra cualquiera hace fallar --check.
# 3. El árbol de root y sin escritura ajena (desde el 25-10 ya lo es salvo bootstrap/cache y storage, que no se tocan):
#      find /var/www/api/backend/{artisan,bootstrap/app.php,bootstrap/providers.php,app,config,database,routes,vendor,composer.json,composer.lock} \( ! -user root -o -perm /022 \) | head
#      (vacío; si no, chown root y chmod go-w sobre lo que salga, y volver a comprobar que www-data lee: test -r)
#    3b. El fichero de entorno, de root y legible por www-data, sin escritura para él (el envoltorio lo exige):
#      install -m 600 /var/www/api/backend/.env /root/env-pre-ironbody-migrate-$(date -u +%Y%m%d)   # copia previa
#      chown root:www-data /var/www/api/backend/.env && chmod 640 /var/www/api/backend/.env
#      sudo -u www-data test -r /var/www/api/backend/.env && ! sudo -u www-data test -w /var/www/api/backend/.env && echo OK
#      (set_var de los scripts del Studio conserva dueño y modo: nada más cambia)
# 4. El envoltorio nuevo, a un nombre provisional, y su comprobación sin migrar:
#      install -m 755 -o root -g root ironbody-migrate-propuesta.sh /usr/local/bin/ironbody-migrate.h7
#      /usr/local/bin/ironbody-migrate.h7 --check      # → migrate:status con el rol dueño, código 0
#      /usr/local/bin/ironbody-migrate.h7 --pretend    # → «Nothing to migrate» o las sentencias pendientes
# 5. Sustitución atómica (el nombre que usan los scripts de despliegue no cambia):
#      mv -f /usr/local/bin/ironbody-migrate.h7 /usr/local/bin/ironbody-migrate
# 6. Opcional, solo si alguien que no es root despliega: sudo acotado a este fichero y a sus tres formas (sin env_keep:
#    con sudo, PGOPTIONS no llega y rige el lock_timeout de 5 s). /etc/sudoers.d/ironbody-migrate (440, root), validado:
#      Cmnd_Alias IRONBODY_MIGRATE = /usr/local/bin/ironbody-migrate "", /usr/local/bin/ironbody-migrate --pretend, \
#                                    /usr/local/bin/ironbody-migrate --status, /usr/local/bin/ironbody-migrate --check
#      %ironbody-deploy ALL=(root) NOPASSWD: IRONBODY_MIGRATE
#      visudo -cf /etc/sudoers.d/ironbody-migrate
#    Quien use esa regla llama `sudo /usr/local/bin/ironbody-migrate` a secas: `sudo env PGOPTIONS=… …` no casa con
#    ella y se deniega (los scripts de despliegue corren como root y sí pueden).
# 6b. H-1 (por comprobar en el servidor): si `schedule:run` corre en el crontab de ROOT, cada tarea programada (el
#    monitor del H7 incluido) incluye como root bootstrap/cache/*.php de www-data, cada minuto: la misma clase que este
#    envoltorio corrige. El programador no necesita root (los respaldos son timers de systemd). Comprobar y mover:
#      crontab -l -u root | grep -n 'schedule:run'; crontab -l -u www-data 2>/dev/null | grep -n 'schedule:run'
#      # antes de mover: que ninguna tarea programada escriba fuera de storage/ ni necesite root (schedule:list)
#      ( crontab -l -u www-data 2>/dev/null; echo '* * * * * cd /var/www/api/backend && php artisan schedule:run >> /dev/null 2>&1' ) | crontab -u www-data -
#      crontab -l -u root | grep -v 'artisan schedule:run' | crontab -u root -       # tras ver que la de www-data corre
#      # verificación: el minuto siguiente, `ls -l storage/framework/schedule-*` o el log de una tarea, de www-data
# 7. Cuando --check y un despliegue hayan ido bien: /root/.ironbody-owner.env ya no lo usa nadie. Se conserva (600) hasta
#    que el propietario decida; borrar credenciales es decisión suya, y rotar la del dueño también.
# Vuelta atrás: install -m 755 /root/ironbody-migrate.pre-h7 /usr/local/bin/ironbody-migrate (el usuario y la
# credencial nueva no molestan; se retiran con userdel ironbody-migrate y rm -r /etc/ironbody-migrate si se decide).
