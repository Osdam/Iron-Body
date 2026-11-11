#!/bin/bash
# SESIÓN TÉCNICA DEL LABORATORIO HABLADO (lead, 11-11-2026) · la cuenta temporal de humo, como en smoke-run-h8.sh.
#   bash /root/lab-tecnica-cuenta.sh abrir    activa la cuenta 32 «Studio smoke (temporal)» con una contraseña de un solo
#                                             uso, inicia sesión por la API pública y deja {token,user} en un fichero 600
#                                             de root (NO se imprime). Programa un cierre automático a los 25 minutos.
#   bash /root/lab-tecnica-cuenta.sh cerrar   deja la cuenta en reposo (inactiva, sesiones borradas), comprueba que la
#                                             sesión ya no vale y borra el fichero.
# No toca ninguna otra cuenta ni ninguna bandera. Nunca imprime la contraseña, el hash ni la sesión.
set -euo pipefail
export LC_ALL=C
umask 077
cd /var/www/api/backend
ADMIN_ID=32
CORREO=studio-smoke@ironbodyneiva.cloud
API=https://api.ironbodyneiva.cloud/api
F=/root/.lab-tecnica-sesion.json
tinker() { sudo -u www-data php artisan tinker --execute="$1" 2>&1 | tail -1; }

reposo() {
  local l
  l=$(tinker '$a = App\Models\Admin::query()->findOrFail('"$ADMIN_ID"'); $a->forceFill(["status" => "inactive", "role" => App\Models\Admin::ROLE_SUPER_ADMIN])->save(); $n = DB::table("admin_sessions")->where("admin_id", '"$ADMIN_ID"')->delete(); $f = $a->fresh(); echo $f->status === "inactive" ? "cuenta {$f->id} en reposo: inactiva, sesiones borradas: {$n}" : "cuenta {$f->id} NO quedó en reposo", PHP_EOL;')
  case "$l" in "cuenta $ADMIN_ID en reposo:"*) echo "$l" ;; *) echo "FALLO: no se pudo dejar en reposo la cuenta $ADMIN_ID: desactivarla a mano"; return 1 ;; esac
}

case "${1:-}" in
  abrir)
    l=$(tinker '$a = App\Models\Admin::query()->findOrFail('"$ADMIN_ID"'); echo $a->email === "'"$CORREO"'" ? "es la cuenta de humo: {$a->status}" : "NO es la cuenta de humo", PHP_EOL;')
    case "$l" in "es la cuenta de humo: inactive") ;; *) echo "ABORTA: la cuenta $ADMIN_ID no es la de humo en reposo ($l)"; exit 1 ;; esac
    PW=$(openssl rand -base64 30 | tr -d '/+=' | cut -c1-28)
    HASH=$(PW="$PW" php -r 'echo password_hash(getenv("PW"), PASSWORD_BCRYPT);')
    l=$(printf '%s\n' "$HASH" | sudo -u www-data php artisan tinker --execute='$h = trim((string) fgets(STDIN)); $a = App\Models\Admin::query()->findOrFail('"$ADMIN_ID"'); $a->forceFill(["name" => "Studio smoke (temporal)", "email" => "'"$CORREO"'", "password" => $h, "role" => App\Models\Admin::ROLE_SUPER_ADMIN, "status" => "active"])->save(); $f = $a->fresh(); echo $f->status === "active" && $f->role === App\Models\Admin::ROLE_SUPER_ADMIN ? "cuenta {$f->id} activa como Super Admin" : "cuenta {$f->id} sin activar", PHP_EOL;' 2>&1 | tail -1)
    unset HASH
    case "$l" in "cuenta $ADMIN_ID activa como Super Admin") echo "$l" ;; *) echo "ABORTA: no se pudo activar la cuenta (su salida no se imprime)"; reposo || true; exit 1 ;; esac
    # Cierre automático: si la sesión del lead muere, la cuenta vuelve sola a su reposo.
    nohup bash -c "sleep 1500; bash /root/lab-tecnica-cuenta.sh cerrar" > /root/lab-tecnica-autocierre.log 2>&1 &
    echo "cierre automático programado en 25 min (pid $!)"
    PW="$PW" CORREO="$CORREO" API="$API" F="$F" python3 -I - <<'PY' || { echo "ABORTA: no se pudo iniciar sesión"; reposo || true; exit 1; }
import json, os, sys, urllib.request
api = os.environ['API']
def llamar(metodo, ruta, cuerpo=None, portador=None):
    datos = json.dumps(cuerpo).encode() if cuerpo is not None else None
    req = urllib.request.Request(api + ruta, data=datos, method=metodo, headers={'Accept': 'application/json', 'Content-Type': 'application/json'})
    if portador:
        req.add_header('Authorization', 'Bearer ' + portador)
    with urllib.request.urlopen(req, timeout=30) as r:
        return r.status, json.loads(r.read().decode() or 'null')
st, cuerpo = llamar('POST', '/admin/auth/login', {'email': os.environ['CORREO'], 'password': os.environ['PW']})
portador = cuerpo.get('token') if isinstance(cuerpo, dict) else None
if st != 200 or not portador:
    print('login:', st, sorted(cuerpo.keys()) if isinstance(cuerpo, dict) else type(cuerpo).__name__); sys.exit(1)
st, yo = llamar('GET', '/admin/auth/me', portador=portador)
u = (yo or {}).get('user') or {}
if st != 200 or not u.get('id'):
    print('me:', st); sys.exit(1)
usuario = {'id': u['id'], 'name': u.get('name'), 'email': u.get('email'), 'role': u.get('role'), 'permissions': u.get('permissions') or []}
fd = os.open(os.environ['F'], os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
with os.fdopen(fd, 'w') as f:
    json.dump({'token': portador, 'user': usuario}, f)
tiene = lambda p: p in usuario['permissions'] or '*' in usuario['permissions']
print('sesión guardada (600, sin imprimir) · cuenta', usuario['id'], '·', usuario['role'], '·', len(usuario['permissions']), 'permisos ·',
      'voice_lab_run' if tiene('ultron.voice_lab_run') else 'SIN ultron.voice_lab_run', '·', 'ultron.view' if tiene('ultron.view') else 'SIN ultron.view')
PY
    unset PW
    ;;
  cerrar)
    reposo
    if [ -s "$F" ]; then
      c=$(F="$F" API="$API" python3 -I - <<'PY'
import json, os, urllib.request, urllib.error
t = json.load(open(os.environ['F']))['token']
req = urllib.request.Request(os.environ['API'] + '/admin/auth/me', headers={'Accept': 'application/json', 'Authorization': 'Bearer ' + t})
try:
    with urllib.request.urlopen(req, timeout=20) as r: print(r.status)
except urllib.error.HTTPError as e: print(e.code)
PY
)
      case "$c" in 401|403) echo "la sesión ya no vale → $c" ;; *) echo "FALLO: la sesión sigue valiendo ($c): revocar a mano" ;; esac
      shred -u "$F" 2>/dev/null || rm -f "$F"
      echo "fichero de sesión borrado"
    else
      echo "sin fichero de sesión (nada que borrar)"
    fi
    pkill -f "sleep 1500; bash /root/lab-tecnica-cuenta.sh cerrar" 2>/dev/null || true
    ;;
  *) echo "uso: lab-tecnica-cuenta.sh abrir|cerrar"; exit 2 ;;
esac
