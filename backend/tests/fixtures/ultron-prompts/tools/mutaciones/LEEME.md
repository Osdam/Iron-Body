# Mutaciones del tester (Enmienda M-1 y enmiendas de guardas, 11-11-2026)

Con esto se reproduce la evidencia de `docs/ultron-studio/plans/informes-motores/informe-tester-guardas.md`. **No son pruebas de la suite**: PHPUnit solo recoge `tests/Unit` y `tests/Feature`.

- `mutar.php`: el ejecutor. Por cada mutación:
  1. aplica sustituciones exactas (cada cadena tiene que aparecer **una sola vez**) y pasa `php -l`;
  2. ejecuta PHPUnit (SQLite o PG) o una comprobación de Node;
  3. cuenta solo los rojos **nuevos** frente a una línea base sin mutar;
  4. restaura en `finally` con el sha256 comprobado.

  Al final compara la huella de `app/ tests/ config/ database/ routes/ resources/ ../n8n/flujos` de antes y de después.
- `spec-m1.php`: las cinco del plan (paso 5b) con sus variantes, más tres que miden los dientes de `UltronPromptCompileCasesTest`.
- `spec-guardas.php` y `spec-programador.php`: las de las enmiendas de guardas (listas del H9, zona neutra, llave de prompts, contrato `/3`, banderas del programador).
- `spec-v2.php`: las de la declaración `/2` del Studio (tarea 3). Necesita PG para la carrera: `MUT_PG_DB=ultron_studio_tg_mut`.

**Siempre sobre una COPIA, nunca sobre el worktree.** Otros agentes corren pruebas en el árbol, y el ejecutor se niega a correr en un árbol de git. La copia lleva `vendor` COPIADO, no enlazado: con el enlace, Composer y Laravel cargarían las clases y la ruta base del árbol original.

```bash
COPIA=/ruta/a/la/copia; SRC=/ruta/al/repo
mkdir -p "$COPIA" && cd "$COPIA"
rsync -a --exclude /backend/vendor --exclude /backend/storage/logs --exclude /backend/storage/framework/testing \
  --exclude /backend/storage/framework/views --exclude /backend/storage/framework/cache --exclude /backend/storage/framework/sessions \
  --exclude .git "$SRC/backend" "$SRC/n8n" ./
mkdir -p backend/storage/logs backend/storage/framework/{testing,views,cache/data,sessions}
cp -R "$SRC/backend/vendor" backend/vendor
# Comprobar que la copia carga SUS clases: debe imprimir rutas de la copia.
(cd backend && php -r 'require "vendor/autoload.php"; $a = require "bootstrap/app.php"; echo $a->basePath(), "\n";')
php "$SRC/backend/tests/fixtures/ultron-prompts/tools/mutaciones/mutar.php" "$COPIA/backend" \
  "$SRC/backend/tests/fixtures/ultron-prompts/tools/mutaciones/spec-m1.php" /tmp/salida-mutaciones [ids…]
```

Con PG: `MUT_PG_DB=ultron_studio_…` (base desechable que ya exista; `PGGSSENCMODE=disable` lo pone el ejecutor).

Las cadenas que se sustituyen son las del árbol del 11-11-2026. Si el código cambia, el ejecutor dice qué cadena ya no aparece exactamente una vez y sale con 3, sin tocar nada.
