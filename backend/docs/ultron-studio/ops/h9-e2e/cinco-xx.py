#!/usr/bin/env python3
"""Cuenta los 5xx del registro del proxy del E2E del H9: «fuera» y «dentro» de las ventanas de reinicio deliberado de la
API (fase asistida, `reinicios-api.jsonl`, con 5 s de margen). Dentro solo se excusan los 502 (el proxy no llega a la
API mientras se reinicia). Imprime «<fuera> <dentro>».

Uso: python3 -I cinco-xx.py <proxy.jsonl> <reinicios-api.jsonl>
"""
import json
import os
import sys
from datetime import datetime, timedelta


def instante(x):
    return datetime.fromisoformat(x.replace('Z', '+00:00'))


ventanas = []
if os.path.exists(sys.argv[2]):
    for linea in open(sys.argv[2], encoding='utf-8'):
        if linea.strip():
            v = json.loads(linea)
            ventanas.append((instante(v['desde']) - timedelta(seconds=5), instante(v['hasta']) + timedelta(seconds=5)))
fuera = dentro = 0
for linea in open(sys.argv[1], encoding='utf-8'):
    if not linea.strip():
        continue
    d = json.loads(linea)
    if d.get('status', 0) < 500:
        continue
    if d.get('status') == 502 and any(a <= instante(d['ts']) <= b for a, b in ventanas):
        dentro += 1
    else:
        fuera += 1
print(fuera, dentro)
