"""Imprime los casos de las ejecuciones nuevas del laboratorio (datos sintéticos). python3 -I lab-casos.py <salida.txt> [run_id]"""
import json, sys
solo = int(sys.argv[2]) if len(sys.argv) > 2 else None
for l in open(sys.argv[1], encoding='utf-8'):
    if l.startswith('RUN | '):
        r = json.loads(l[6:])
        if solo is None or r['id'] == solo:
            print('RUN', r['id'], r['uuid'], r['status'], r['prompt_version'], r['target_kind'], '·', r['target_label'], '· casos', r['case_count'], '· propuesta', r['ai_recommendation'], '· micros', r['cost_micros'], '· huella', r['results_hash'], '·', (r.get('summary') or {}).get('outcomes'))
    elif l.startswith('VER | '):
        print('VEREDICTO', l[6:].strip()[:300])
    elif l.startswith('CASE | '):
        c = json.loads(l[7:])
        if solo is not None and c['run_id'] != solo:
            continue
        j = c.get('judge') or {}
        print('-' * 96)
        print('run', c['run_id'], c['scenario_code'], '·', c['status'], '·', c['outcome'], '·', len(c.get('reply') or ''), 'car.')
        print('  RESPUESTA:', (c.get('reply') or '')[:700])
        for k in (j.get('criteria') or []):
            print('  criterio', k.get('id'), k.get('verdict'), '·', (k.get('reason') or '')[:230])
        for r in (j.get('rules') or []):
            if r.get('verdict') in ('fail', 'unclear'):
                print('  regla', r.get('code'), r.get('verdict'), '·', (r.get('reason') or '')[:230])
        na = [r.get('code') for r in (j.get('rules') or []) if r.get('verdict') == 'not_applicable']
        ok = [r.get('code') for r in (j.get('rules') or []) if r.get('verdict') == 'pass']
        print('  reglas pass:', ' '.join(ok), '· no aplican:', ' '.join(na))
        for h in (c.get('checks') or []):
            if h.get('level') in ('blocking', 'warning'):
                print('  hallazgo', h.get('level'), h.get('id'), '·', str(h.get('title') or '')[:200], '·', str(h.get('quote') or '')[:120])
