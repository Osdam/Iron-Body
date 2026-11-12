#!/bin/bash
# SOLO LECTURA, en el servidor. Con la sesión de la cuenta técnica (se lee del fichero, nunca se imprime), pide la
# bandeja asistida y el estado de la automatización a la API pública y enseña solo la forma y los recuentos.
set -euo pipefail
export LC_ALL=C
python3 - <<'PY'
import json, urllib.request, urllib.error
s = json.load(open('/root/.lab-tecnica-sesion.json'))
tok = s['token']
API = 'https://api.ironbodyneiva.cloud/api'

def pide(ruta):
    r = urllib.request.Request(API + '/' + ruta, headers={'Accept': 'application/json', 'Authorization': 'Bearer ' + tok})
    try:
        with urllib.request.urlopen(r, timeout=30) as x:
            return x.status, json.loads(x.read().decode('utf-8'))
    except urllib.error.HTTPError as e:
        try:
            cuerpo = json.loads(e.read().decode('utf-8'))
        except Exception:
            cuerpo = {}
        return e.code, cuerpo

for filtro in ('all', 'unclaimed', 'mine'):
    st, j = pide('admin/ultron-studio/outreach/assisted/queue?filter=' + filtro)
    datos = j.get('data') if isinstance(j, dict) else None
    print('bandeja %-9s → %s · ok=%s · filas=%s · meta=%s · code=%s' % (
        filtro, st, j.get('ok'), len(datos) if isinstance(datos, list) else None, j.get('meta'), j.get('code')))
    if isinstance(datos, list) and datos:
        print('   claves de la primera fila:', sorted(datos[0].keys()))
st, j = pide('admin/ultron-studio/outreach/status')
d = j.get('data', {}) if isinstance(j, dict) else {}
print('estado → %s · modo=%s · live=%s · eventos=%s' % (st, (d.get('config') or {}).get('mode'), d.get('live'), (d.get('sources') or {}).get('commercialEvents')))
PY
