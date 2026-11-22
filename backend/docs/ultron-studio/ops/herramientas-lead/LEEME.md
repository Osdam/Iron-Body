# Herramientas del lead (cierre V2)

Guiones pequeños que el lead usa entre sesiones. Vivían en una carpeta temporal que se purga sola.

- `matriz.py`: cambia líneas de un documento por prefijo. `python3 -I matriz.py <fichero> <cambios.json>`, con `{"lineas": {prefijo: línea nueva}, "tras": {prefijo: líneas que se insertan después}}`. Cada prefijo debe casar con una sola línea.
- `genera-textos-usted.py`: genera la página «Textos de usted» (los textos fijos del servidor, hoy y de usted, con lo que cambia resaltado) a partir de `plans/informes-motores/a4-textos-usted.md`, que fija una prueba. `python3 -I genera-textos-usted.py <a4-textos-usted.md> <salida.html>`. La página publicada para el propietario es https://claude.ai/artifact/Gxs1QAg9dRAcx8sZi6XbHw: para actualizarla hay que publicar el HTML nuevo sobre esa misma dirección.
- `comparar-usted-doc.php`: compara cada «Hoy» con su «De usted» de `plans/informes-motores/a4-textos-usted.md` (cifras, vocabulario comercial y de compromiso, negaciones, frases, preguntas y el ajuste al tope) y lista las palabras que cambian. Solo lectura. Desde `backend/`: `php docs/ultron-studio/ops/herramientas-lead/comparar-usted-doc.php`. Salida del 22-11: `plans/informes-motores/a4-evidencia/146`.
