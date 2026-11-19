"""Genera la página «Textos de usted» desde a4-textos-usted.md (el documento que fija una prueba).
python3 -I genera-textos-usted.py <a4-textos-usted.md> <salida.html>"""
import difflib
import html
import re
import sys

src, out = sys.argv[1], sys.argv[2]

NOMBRES = {
    'Por intención': 'Respuestas de respaldo, según lo que pregunta la persona',
    'Horario sin ficha': 'Horario, cuando no hay ficha del gimnasio',
    'Bienvenida': 'Bienvenida',
    'Últimos recursos': 'Últimos recursos, cuando nada más aplica',
    'Política: plan': 'Cuando se corrige el plan recomendado',
    'Política: visita': 'Cuando se está concretando una visita',
    'Política: retomar la visita': 'Para retomar una visita a medias',
    'Acta de la cortesía': 'Constancia de la visita de cortesía',
    'Commit: constantes': 'Avisos del servidor',
    'Tabla completa': 'Otras frases',
    'Colas': 'Frases que se añaden al final de un mensaje',
    'Ficha del gimnasio': 'Ficha del gimnasio: apertura y cierre',
    'Pregunta sugerida': 'Preguntas que el servidor le sugiere al modelo',
}
NOTAS = {
    'Pregunta sugerida': 'Van sin tildes ni signo de apertura porque se le entregan al modelo, no al cliente.',
    'Acta de la cortesía': 'Las fechas son de ejemplo.',
}

sec = None
hoy = None
pares = []
for ln in open(src, encoding='utf-8'):
    ln = ln.rstrip('\n')
    if ln.startswith('## '):
        sec = re.sub(r'\s*\(`[^`]*`[^)]*\)', '', ln[3:]).strip()
        continue
    m = re.match(r'^- \*\*(Hoy|De usted)\*\* \((\d+)\) «(.*)»$', ln)
    if not m:
        continue
    if m.group(1) == 'Hoy':
        hoy = (m.group(3), int(m.group(2)))
    else:
        pares.append((sec, hoy[0], hoy[1], m.group(3), int(m.group(2))))

vistos = set()
por = {}
for s, h, nh, u, nu in pares:
    k = (h.strip(), u.strip())
    if k in vistos:
        continue
    vistos.add(k)
    por.setdefault(s, []).append((h, nh, u, nu))
total = sum(len(v) for v in por.values())
assert total == 106, total


def marca(h, u):
    """La versión de usted con lo que cambia respecto de hoy envuelto en <mark>."""
    a, b = h.split(' '), u.split(' ')
    piezas = []
    for op, i1, i2, j1, j2 in difflib.SequenceMatcher(None, a, b, autojunk=False).get_opcodes():
        trozo = html.escape(' '.join(b[j1:j2]))
        if not trozo:
            continue
        piezas.append(trozo if op == 'equal' else '<mark>' + trozo + '</mark>')
    return ' '.join(piezas)


def ancla(s):
    t = s.lower()
    for x, y in zip('áéíóúñ', 'aeioun'):
        t = t.replace(x, y)
    return re.sub(r'[^a-z0-9]+', '-', t).strip('-')[:40]


indice = []
cuerpo = []
for s in por:
    filas = por[s]
    nombre = NOMBRES[s]
    a = ancla(nombre)
    indice.append('<li><a href="#%s">%s</a><span class="n">%d</span></li>' % (a, html.escape(nombre), len(filas)))
    cuerpo.append('<section id="%s"><div class="sh"><h2>%s</h2><span class="n">%d %s</span></div>' % (
        a, html.escape(nombre), len(filas), 'texto' if len(filas) == 1 else 'textos'))
    if s in NOTAS:
        cuerpo.append('<p class="nota">%s</p>' % html.escape(NOTAS[s]))
    cuerpo.append('<div class="filas">')
    for h, nh, u, nu in filas:
        igual = h.strip() == u.strip()
        cuerpo.append(
            '<article class="par"><div class="lado hoy"><span class="et">Hoy</span><p>%s</p></div>'
            '<div class="lado usted"><span class="et">De usted <span class="car">%d caracteres</span></span><p>%s</p>%s</div></article>' % (
                html.escape(h), nu, marca(h, u), '<span class="sin">No cambia: ya era neutro.</span>' if igual else ''))
    cuerpo.append('</div></section>')

pagina = """<title>Textos de usted</title>
<meta name="claude:viewport-fit-sides" content="cover">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Space+Grotesk:wght@500;600&display=swap">
<style>
/* Una columna de lectura: cada texto es un par (hoy | de usted) que se apila en el teléfono; lo que cambia va resaltado. */
:root {
  --bg: #f2f4f6; --surface: #ffffff; --fg: #18212b; --muted: #5a6572; --rule: #d8dee5;
  --accent: #0f6a70; --mark: #d3eeee;
  --display: "Space Grotesk", "Segoe UI", system-ui, sans-serif;
  --body: "Inter", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
}
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) { --bg: #12161b; --surface: #1a2028; --fg: #e6ebf0; --muted: #9aa6b3; --rule: #2b343f; --accent: #63c7cc; --mark: #17474b; color-scheme: dark }
}
:root[data-theme="dark"] { --bg: #12161b; --surface: #1a2028; --fg: #e6ebf0; --muted: #9aa6b3; --rule: #2b343f; --accent: #63c7cc; --mark: #17474b; color-scheme: dark }
* { box-sizing: border-box }
body { background: var(--bg); color: var(--fg); font-family: var(--body); font-size: 16px; line-height: 1.55;
  padding-block: 28px 56px; padding-left: max(16px, env(safe-area-inset-left, 0px)); padding-right: max(16px, env(safe-area-inset-right, 0px)) }
.hoja { max-width: 980px; margin: 0 auto; display: flex; flex-direction: column; gap: 36px }
header { display: flex; flex-direction: column; gap: 14px }
.sobre { font-size: 13px; letter-spacing: .06em; text-transform: uppercase; color: var(--accent); font-weight: 600 }
h1 { font-family: var(--display); font-weight: 600; font-size: clamp(28px, 6vw, 40px); line-height: 1.1; margin: 0; text-wrap: balance }
.intro { max-width: 66ch; display: flex; flex-direction: column; gap: 10px }
.intro p { margin: 0 }
.intro .q { color: var(--muted) }
mark { background: var(--mark); color: inherit; border-radius: 3px; padding: 0 2px }
nav { border-top: 1px solid var(--rule); border-bottom: 1px solid var(--rule); padding-block: 14px }
nav ul { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 6px 28px }
nav li { display: flex; justify-content: space-between; gap: 12px; min-width: 0; font-size: 15px }
nav a { color: var(--fg); text-decoration: none; border-bottom: 1px solid var(--rule) }
nav a:hover, nav a:focus-visible { color: var(--accent); border-bottom-color: var(--accent) }
a:focus-visible { outline: 2px solid var(--accent); outline-offset: 3px }
.n { color: var(--muted); font-variant-numeric: tabular-nums; font-size: 14px; white-space: nowrap }
section { display: flex; flex-direction: column; gap: 12px; scroll-margin-top: 16px }
.sh { display: flex; align-items: baseline; justify-content: space-between; gap: 16px; flex-wrap: wrap }
h2 { font-family: var(--display); font-weight: 600; font-size: 21px; line-height: 1.25; margin: 0; text-wrap: balance }
.nota { margin: 0; color: var(--muted); font-size: 14px; max-width: 66ch }
.filas { display: flex; flex-direction: column; gap: 10px }
.par { display: grid; grid-template-columns: 1fr 1fr; background: var(--surface); border: 1px solid var(--rule); border-radius: 6px }
.lado { min-width: 0; padding: 14px 16px; display: flex; flex-direction: column; gap: 6px }
.lado p { margin: 0; overflow-wrap: anywhere }
.hoy { color: var(--muted); border-right: 1px solid var(--rule) }
.et { font-size: 12px; letter-spacing: .06em; text-transform: uppercase; font-weight: 600; color: var(--muted); display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap }
.usted .et { color: var(--accent) }
.car { letter-spacing: 0; text-transform: none; font-weight: 400; color: var(--muted); font-variant-numeric: tabular-nums }
.sin { font-size: 13px; color: var(--muted) }
footer { color: var(--muted); font-size: 14px; max-width: 66ch; border-top: 1px solid var(--rule); padding-top: 16px }
@media (max-width: 720px) {
  .par { grid-template-columns: 1fr }
  .hoy { border-right: 0; border-bottom: 1px solid var(--rule) }
}
</style>
<div class="hoja">
<header>
  <span class="sobre">ULTRON · WhatsApp · para aprobar</span>
  <h1>Textos de usted</h1>
  <div class="intro">
    <p>Son los __TOTAL__ textos fijos que escribe el servidor, no el modelo. Hoy están de tú. Con una versión publicada en tono «profesional» saldrían como en la columna «De usted». Con cualquier otro tono, o sin versión publicada, siguen como hoy.</p>
    <p>Los datos no cambian: precios, horarios, direcciones y fechas son los mismos. Lo que cambia va <mark>resaltado</mark>.</p>
    <p class="q">En seis textos, además del trato, una muletilla coloquial pasa a una fórmula sobria: «Listo.» a «Muy bien.», «Listo, tranquilo.» a «Entendido,», «De una, tranquilo.» a «Con gusto.», «Tranquilo,» a «Por supuesto,» o «Entiendo,», y «arrancas desde cero» a «empieza desde cero». Un texto conserva un emoji: el mensaje del enlace de pago, que hoy no se usa porque los enlaces de pago automáticos están apagados.</p>
  </div>
</header>
<nav aria-label="Secciones"><ul>
__INDICE__
</ul></nav>
__CUERPO__
<footer>Generado el 19 de noviembre de 2026 a partir del documento que fija una prueba automática del servidor: si alguien cambia uno de estos textos sin actualizar su versión de usted, la prueba falla.</footer>
</div>
"""
pagina = pagina.replace('__TOTAL__', str(total)).replace('__INDICE__', '\n'.join(indice)).replace('__CUERPO__', '\n'.join(cuerpo))
open(out, 'w', encoding='utf-8').write(pagina)
print('pares:', total, '· secciones:', len(por), '· bytes:', len(pagina.encode('utf-8')), '· marcas:', pagina.count('<mark>') - 1)
