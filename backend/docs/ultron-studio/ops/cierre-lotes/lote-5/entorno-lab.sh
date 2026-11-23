#!/bin/bash
# Unidad 2b del cierre operativo: el modelo del laboratorio en el fichero de entorno de producción (decisión del
# propietario, 22-11-2026; los presupuestos NO cambian). Las líneas que se escriben están en entorno-lab.txt, al lado.
# Ficha, orden y vuelta atrás: ops/DEPLOY_CIERRE_LOTES.md §5 bis, «2b». El mecanismo de escritura es el de
# h8-voice-scope.sh (python aislado; temporal del mismo directorio con O_EXCL y O_NOFOLLOW; el dueño y el modo del
# original por el descriptor, nunca por ruta; fsync; os.replace) y la lectura de comprobación, la de su parsea():
# Dotenv como www-data.
#
# NO TOCA NINGUNA CACHÉ. Quien regenera la de configuración es deploy-cierre-lote.sh al desplegar la unidad 2 (el paso M,
# ironbody-migrate con setpriv a www-data, y el paso K, config:cache como www-data con umask 077), que comprueba los
# cuatro valores en la caché con su --config (cierre-lotes/lote-5/config.txt) y reinicia las colas (paso 3).
#
# Uso (servidor, root):
#   bash entorno-lab.sh comprobar                     solo lectura: cómo están hoy las claves (nunca su valor)
#   bash entorno-lab.sh aplicar                       respaldo 600 en /var/backups/ironbody/entorno-lab-<fecha UTC> y
#                                                     escritura atómica; si la relectura no cuadra, repone el respaldo solo
#   bash entorno-lab.sh vuelta=<respaldo> [--regenera]  repone el fichero del respaldo, byte a byte; con --regenera,
#                                                     además config:cache como www-data (umask 077) y queue:restart
# No imprime ninguna línea del fichero de entorno ni ningún valor previo: de cada clave, solo «ausente», «vacía»,
# «= lo decidido» u «otro valor». Lo único que imprime con su valor es lo decidido (entorno-lab.txt, público).
# Códigos: 0 bien · 1 no se ha tocado nada · 2 uso · 4 la escritura no cuadró y se repuso el respaldo · 5 PARAR.
set -euo pipefail
export LC_ALL=C
umask 022

# ── Lo propio de producción (el banco sustituye estas líneas y ninguna otra) ──────────────────────────────────────────────
APP=/var/www/api
WWW=www-data
ROOT_U=root
BK_RAIZ=/var/backups/ironbody

# ── Lo fijo ───────────────────────────────────────────────────────────────────────────────────────────────────────────────
AQUI=$(cd "$(dirname "$0")" && pwd -P)
DECL=$AQUI/entorno-lab.txt
BACK=$APP/backend
ENVF=$BACK/.env
muere() { echo "ENTORNO-LAB: $* (no se ha tocado nada)"; exit 1; }
uso() { echo "uso: bash $0 comprobar | aplicar | vuelta=<respaldo> [--regenera]"; exit 2; }
[ "$#" -ge 1 ] || uso
MODO=$1; REGENERA=0; BK=""
case "$MODO" in
  comprobar|aplicar) [ "$#" = 1 ] || uso ;;
  vuelta=?*) BK=${MODO#vuelta=}; MODO=vuelta; [ "$#" = 1 ] || { [ "$#" = 2 ] && [ "$2" = --regenera ] && REGENERA=1; } || uso ;;
  *) uso ;;
esac
[ "$(id -un)" = "$ROOT_U" ] || muere "se ejecuta como $ROOT_U"
[ -f "$ENVF" ] && [ ! -L "$ENVF" ] || muere "$ENVF no existe o no es un fichero normal"
[ -f "$DECL" ] && [ -r "$DECL" ] || muere "falta $DECL"
cd "$BACK"

# Las líneas decididas, validadas: clave del laboratorio (nunca una clave de API, un secreto ni un token) y un valor
# sencillo (o vacío). Deja DECIDIDO como JSON {CLAVE: valor} y las imprime (son públicas: están en el repositorio).
DECIDIDO=$(python3 -I - "$DECL" <<'PY'
import json, re, sys
out = {}
for n, l in enumerate(open(sys.argv[1], encoding="utf-8").read().replace("\r", "").split("\n"), 1):
    l = l.rstrip()
    if l == "" or l.lstrip().startswith("#"):
        continue
    m = re.fullmatch(r"(ULTRON_STUDIO_LAB_[A-Z0-9_]{1,40})=([A-Za-z0-9._:-]{0,64})", l)
    if not m or re.search(r"API_KEY|SECRET|TOKEN|PASSWORD", m.group(1)) or m.group(1) in out:
        sys.exit("entorno-lab.txt, línea %d: no es CLAVE=valor del laboratorio, o es una clave secreta, o está repetida" % n)
    out[m.group(1)] = m.group(2)
if not 1 <= len(out) <= 8:
    sys.exit("entorno-lab.txt: entre 1 y 8 claves")
print(json.dumps(out, sort_keys=True))
PY
) || muere "la declaración no vale"
echo "== entorno-lab.sh · $MODO · $(date -u +%Y-%m-%dT%H:%M:%SZ) · $ENVF ($(stat -c '%U:%G %a' "$ENVF"))"
printf '%s' "$DECIDIDO" | python3 -I -c 'import json, sys; [print("   decidido: %s=%s" % (k, v if v != "" else "(vacía)")) for k, v in json.load(sys.stdin).items()]'

# El estado de cada clave en el fichero (sin valores) y si se puede escribir: «CLAVE estado» por línea; sale con 1 si una
# clave está repetida (el parser se quedaría con una de las dos: deduplicar a mano antes).
estado() { # <fichero>
  DECIDIDO="$DECIDIDO" python3 -I - "$1" <<'PY'
import json, os, re, sys
d = json.loads(os.environ["DECIDIDO"])
s = open(sys.argv[1], encoding="utf-8").read()
mal = 0
for k, v in d.items():
    m = re.findall(r"^[ \t]*(?:export[ \t]+)?" + re.escape(k) + r"[ \t]*=(.*)$", s, flags=re.M)
    if len(m) > 1:
        print(k, "REPETIDA (%d veces)" % len(m)); mal = 1; continue
    if not m:
        print(k, "ausente"); continue
    x = m[0].strip()
    if len(x) >= 2 and x[0] == x[-1] and x[0] in "\"'":
        x = x[1:-1]
    print(k, "= lo decidido" if x == v else ("vacía" if x == "" else "otro valor"))
sys.exit(mal)
PY
}
# Lo que lee el parser de la aplicación (Dotenv, como www-data) de un contenido que le pasa root por la entrada: sale con
# 0 si se lee. Con dos contenidos separados por NUL, dice qué claves cambian de uno a otro (sus nombres, nunca valores),
# si no cambia ninguna que no esté decidida y, con «aplicar», si en el segundo las decididas valen lo decidido.
parsea() { sudo -u "$WWW" php -r 'require "vendor/autoload.php"; try { Dotenv\Dotenv::parse(stream_get_contents(STDIN)); } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' > /dev/null 2>&1; }
compara() { # <antes> <después> <aplicar|vuelta>
  { cat "$1"; printf '\0'; cat "$2"; } | DECIDIDO="$DECIDIDO" COMO="$3" sudo -u "$WWW" php -r '
require "vendor/autoload.php";
[$a, $b] = explode("\0", stream_get_contents(STDIN), 2) + [1 => ""];
try { $va = Dotenv\Dotenv::parse($a); $vb = Dotenv\Dotenv::parse($b); } catch (Throwable $e) { echo "no se lee\n"; exit(1); }
$d = json_decode(getenv("DECIDIDO"), true);
$cambian = [];
foreach (array_unique(array_merge(array_keys($va), array_keys($vb))) as $k) { if (($va[$k] ?? null) !== ($vb[$k] ?? null)) { $cambian[] = $k; } }
sort($cambian);
$ok = true;
foreach ($cambian as $k) { if (! array_key_exists($k, $d)) { $ok = false; } }
if (getenv("COMO") === "aplicar") { foreach ($d as $k => $v) { if (($vb[$k] ?? null) !== $v) { $ok = false; } } }
echo count($va), " claves antes · ", count($vb), " después · cambian [", implode(" ", $cambian), "] · ", getenv("COMO") === "aplicar" ? "las decididas valen lo decidido y no cambia ninguna otra: " : "no cambia ninguna que no esté decidida: ", $ok ? "sí" : "NO", "\n";
exit($ok ? 0 : 1);'
}
# Escribe <contenido> sobre el fichero de entorno, atómico, con el dueño y el modo que tiene AHORA.
escribe() { # <fichero con el contenido nuevo> <etiqueta del temporal>
  ENVF="$ENVF" python3 -I - "$1" "$2" <<'PY'
import os, stat, sys
p = os.environ["ENVF"]
nuevo = open(sys.argv[1], "rb").read()
fd = os.open(p, os.O_RDONLY | os.O_NOFOLLOW)
st = os.fstat(fd)
os.close(fd)
if not stat.S_ISREG(st.st_mode):
    sys.exit("no es un fichero normal")
d = os.path.dirname(p)
tmp = os.path.join(d, ".env.%s-%d" % (sys.argv[2], os.getpid()))
tfd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
with os.fdopen(tfd, "wb") as fh:
    fh.write(nuevo)
    fh.flush()
    os.fchown(fh.fileno(), st.st_uid, st.st_gid)
    os.fchmod(fh.fileno(), stat.S_IMODE(st.st_mode))
    os.fsync(fh.fileno())
os.replace(tmp, p)
dfd = os.open(d, os.O_RDONLY)
os.fsync(dfd)
os.close(dfd)
PY
}

case "$MODO" in
comprobar)
  E=$(estado "$ENVF") && rc=0 || rc=$?
  printf '%s\n' "$E" | sed 's/^/   hoy: /'
  parsea < "$ENVF" && echo "   el parser de la aplicación (Dotenv, como $WWW) lee el fichero: sí" || { echo "   el parser de la aplicación NO lee el fichero: PARAR"; exit 1; }
  [ "$rc" = 0 ] || { echo "ENTORNO-LAB: hay claves repetidas: deduplicarlas a mano (con respaldo) antes de aplicar"; exit 1; }
  echo "COMPROBADO: aplicar cambiaría $(printf '%s\n' "$E" | grep -vc '= lo decidido$' || true) de $(printf '%s\n' "$E" | grep -c . ) clave(s); no se ha tocado nada"
  ;;
aplicar)
  E0=$(estado "$ENVF") || muere "hay claves repetidas en el fichero de entorno: deduplicarlas a mano antes"
  parsea < "$ENVF" || muere "el parser de la aplicación no lee el fichero de entorno de hoy"
  TS=$(date -u +%Y%m%d-%H%M%S); BK="$BK_RAIZ/entorno-lab-$TS"
  [ ! -e "$BK" ] || muere "el respaldo $BK ya existe"
  ( umask 077; mkdir -p "$BK" ) || muere "no se pudo crear $BK"
  [ "$(stat -c '%U %a' "$BK")" = "$ROOT_U 700" ] || muere "el respaldo $BK no quedó 700 de $ROOT_U"
  # La copia, del mismo descriptor que se comprueba (no sigue enlaces), en 600; y lo que habrá después, en otro 600.
  ENVF="$ENVF" DECIDIDO="$DECIDIDO" python3 -I - "$BK" <<'PY' || muere "no se pudo escribir el respaldo"
import json, os, re, stat, sys
p, bk = os.environ["ENVF"], sys.argv[1]
d = json.loads(os.environ["DECIDIDO"])
fd = os.open(p, os.O_RDONLY | os.O_NOFOLLOW)
if not stat.S_ISREG(os.fstat(fd).st_mode):
    sys.exit(1)
with os.fdopen(fd, "rb") as fh:
    antes = fh.read()
s = antes.decode("utf-8")
for k, v in d.items():
    pat = re.compile(r"^([ \t]*)(?:export[ \t]+)?" + re.escape(k) + r"[ \t]*=.*$", re.M)
    if len(pat.findall(s)) == 1:
        s = pat.sub(lambda m: m.group(1) + k + "=" + v, s, count=1)
    else:
        if s and not s.endswith("\n"):
            s += "\n"
        s += k + "=" + v + "\n"
for nombre, datos in (("env.antes", antes), ("env.nuevo", s.encode("utf-8"))):
    f = os.open(os.path.join(bk, nombre), os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(f, "wb") as fh:
        fh.write(datos)
        fh.flush()
        os.fsync(fh.fileno())
PY
  ( umask 077; printf '%s\n' "$E0" > "$BK/estado-antes.txt"; cp "$DECL" "$BK/entorno-lab.txt"; stat -c '%U:%G %a' "$ENVF" > "$BK/modo.txt"
    cd "$BK" && sha256sum env.antes estado-antes.txt entorno-lab.txt modo.txt > SHA256SUMS ) || muere "no se pudo completar el respaldo"
  cmp -s "$BK/env.antes" "$ENVF" || muere "el fichero de entorno cambió mientras se copiaba"
  echo "1. respaldo $BK (700 de $ROOT_U): env.antes (600, byte a byte), el estado de las claves sin valores, la declaración y SHA256SUMS"
  printf '%s\n' "$E0" | sed 's/^/   antes: /'
  compara "$BK/env.antes" "$BK/env.nuevo" aplicar | sed 's/^/   lo que se va a escribir: /' || muere "lo que se escribiría no cambia exactamente las claves decididas (arriba): no se escribe"
  escribe "$BK/env.nuevo" lab || { echo "PARAR: la escritura atómica falló a medias; el respaldo es $BK/env.antes"; exit 5; }
  rm -f "$BK/env.nuevo"
  E1=$(estado "$ENVF") || E1="?"
  C1=$(compara "$BK/env.antes" "$ENVF" aplicar) && ok=1 || ok=0
  printf '%s\n' "$E1" | sed 's/^/   después: /'
  echo "2. escrito (atómico, $(stat -c '%U:%G %a' "$ENVF"), el dueño y el modo de antes: $(cat "$BK/modo.txt")) · releído con Dotenv como $WWW: $C1"
  if [ "$ok" != 1 ] || [ "$(stat -c '%U:%G %a' "$ENVF")" != "$(cat "$BK/modo.txt")" ] || printf '%s\n' "$E1" | grep -qv '= lo decidido$'; then
    escribe "$BK/env.antes" lab-vuelta && cmp -s "$BK/env.antes" "$ENVF" || { echo "PARAR: no cuadró y no se pudo reponer el respaldo: $BK/env.antes"; exit 5; }
    echo "VUELTA ATRÁS HECHA: no cuadró (arriba); el fichero de entorno es otra vez el del respaldo, byte a byte"; exit 4
  fi
  echo "ESCRITO: la caché de configuración NO se ha tocado (la aplicación sigue con la de antes hasta que la regenere deploy-cierre-lote.sh)"
  echo "   vuelta atrás: bash $0 vuelta=$BK   (con --regenera si la unidad 2 se queda desplegada)"
  ;;
vuelta)
  { [ -d "$BK" ] && [ ! -L "$BK" ] && [ "$(stat -c '%U %a' "$BK")" = "$ROOT_U 700" ]; } || { echo "el respaldo $BK no existe o no es un directorio 700 de $ROOT_U; no se ha tocado nada"; exit 2; }
  ( cd "$BK" && sha256sum -c --status SHA256SUMS ) || { echo "el respaldo no coincide con su SHA256SUMS; no se ha tocado nada"; exit 2; }
  parsea < "$BK/env.antes" || muere "el parser de la aplicación no lee el respaldo"
  if cmp -s "$BK/env.antes" "$ENVF"; then echo "1. el fichero de entorno ya es el del respaldo, byte a byte: no se escribe"
  else
    compara "$ENVF" "$BK/env.antes" vuelta | sed 's/^/   lo que se repone: /' || echo "   AVISO: entre hoy y el respaldo cambian otras claves además de las decididas (arriba, solo nombres): se repone igual el respaldo entero"
    escribe "$BK/env.antes" lab-vuelta || { echo "PARAR: la escritura atómica falló a medias; el respaldo es $BK/env.antes"; exit 5; }
    cmp -s "$BK/env.antes" "$ENVF" || { echo "PARAR: tras reponer, el fichero no es el del respaldo"; exit 5; }
    echo "1. repuesto el del respaldo, byte a byte (atómico, $(stat -c '%U:%G %a' "$ENVF"))"
  fi
  estado "$ENVF" | sed 's/^/   ahora: /' || true
  if [ "$REGENERA" = 1 ]; then
    sudo -u "$WWW" sh -c 'umask 077; php artisan config:cache -q' || { echo "PARAR: config:cache (como $WWW) falló: la aplicación lee el entorno a pelo hasta que se regenere"; exit 5; }
    c=bootstrap/cache/config.php
    { [ -f "$c" ] && [ ! -L "$c" ] && [ "$(stat -c '%U' "$c")" = "$WWW" ]; } || { echo "PARAR: $c no es un fichero normal de $WWW"; exit 5; }
    [ "$(stat -c '%a' "$c")" = 600 ] || sudo -u "$WWW" chmod 600 "$c" || { echo "PARAR: $c no quedó en 600"; exit 5; }
    sudo -u "$WWW" php artisan queue:restart -q || { echo "PARAR: queue:restart falló"; exit 5; }
    echo "2. caché de configuración regenerada (config:cache como $WWW, umask 077; $(stat -c '%U %a' "$c")) y queue:restart: los workers leen el entorno repuesto"
  else
    echo "2. la caché de configuración NO se toca (sin --regenera): si la unidad 2 sigue desplegada, la caché aún tiene lo decidido; regenerarla es --regenera"
  fi
  echo "VUELTA ATRÁS HECHA: el fichero de entorno es el del respaldo $BK"
  ;;
esac
exit 0
