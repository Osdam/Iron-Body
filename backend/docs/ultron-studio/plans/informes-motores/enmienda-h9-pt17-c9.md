# PT-17 C9 — acta documental H9 y manifiesto cerrado

Identificador: `PT17-C9-H9-1`. Registro UTC: `2026-11-25T02:18:13.902412+00:00` (sesión del 24-11-2026 en Bogotá). Rama: `fix/pt17-contrato-inicial`.

**Estado: autorización documental registrada. H9 permanece FAIL. NOT READY para integración; aceptación comercial sin certificar.**

## Autoridad y límites

El propietario aprobó expresamente en la sesión original: «APRUEBO LA ENMIENDA DOCUMENTAL H9 — PT-17 C9» y «Esta autorización es exclusivamente documental. No autoriza integrar ni desplegar». El texto íntegro del mensaje queda transcrito en `authorization.verbatim` del [manifiesto](pt17-c9-manifiesto-autorizado.json), con su SHA-256.

Este registro cumple las siete condiciones de esa autorización. La implementación c9 permanece intacta. La excepción documental se limita a los cambios actuales de las 14 rutas de este anexo, identificados por contenido; no autoriza otras rutas ni cambios futuros. Inventariar los otros 72 archivos del paquete no les concede una excepción H9. Tampoco autoriza commit, push, merge, deploy, SSH, cambios de producción, publicación, importación o activación de S3 ni llamadas pagadas.

Norma: [H5, Enmienda A4-10](../../ULTRON_STUDIO_H5_CONTRACT.md#enmienda-a4-10--pt-17-c9-autorización-documental-acotada-de-h9-24-11-2026), [bitácora H9, L9-125](../../ULTRON_STUDIO_H9_CONTRACT.md). Precedentes conservados: H5 M1-15 y A49-9 (c8); [reviewer de la ola, adenda B-1](informe-reviewer-ola.md#adenda-del-11-11-0855-b-1-cerrado--sí), que cerró aquel bloqueo sin modificar la prueba. Esta acta no hereda una autorización abierta de c8.

## Referencia y definición exacta del cambio

- Base y HEAD locales: `e750dd2c77972a4055dcbd7524d533ed10af84bb`.
- Referencia protegida: `origin/main`, sin fetch ni modificación.
- Compilador existente: 9; no se crea otra arquitectura.
- Los archivos se identifican por sus bytes completos SHA-256; los modificados incluyen además SHA-256 del contenido base, objeto Git base y SHA-256 del diff. En los nuevos, los identificadores de base y diff son `null`: no existían en la base.
- El comando exacto y las reglas canónicas de los sellos figuran en `integrity` del manifiesto. Ninguna lista por ruta autoriza contenidos diferentes.

## Los 14 cambios protegidos autorizados documentalmente

La ruta completa, el método afectado y la huella actual se enumeran aquí. Los identificadores anteriores y del parche están en `protectedChanges` del JSON.

| Ruta desde la raíz del repositorio | Estado | Modificación concreta | SHA-256 actual |
|---|---|---|---|
| `backend/app/Http/Controllers/Api/Internal/UltronController.php` | Modificado | decide(): transmite generation_contract sin cambiar rutas ni permisos. | `e64ad3e9ed6b8ff7b089ab6f266d9de86fd4609b52214e71f01bd31f49973ac4` |
| `backend/app/Services/Marketing/Ultron/Prompts/UltronListTurnPrompts.php` | Nuevo | pack(), select() y listInstructions(): variantes firmadas e instrucciones exclusivas de lista, sin cotización individual contradictoria. | `7f7da5f47dbebc1b18fab675c8792e10e4de8ff31149c8d95829323966f7d56f` |
| `backend/app/Services/Marketing/Ultron/Prompts/UltronPromptCompiler.php` | Modificado | VERSION=9; compile() firma variantes de roles y conserva la reproducción explícita del compilador 8. | `b96bc99422156ab5874fc8aca2efd0041e292414bbedd8075a79d884de11fa47` |
| `backend/app/Services/Marketing/Ultron/Prompts/UltronPromptOffer.php` | Modificado | forRuntime(): exige la negociación del contrato c9 para ofrecer variantes y falla cerrado a Legacy ante consumidores incompatibles. | `69ca2d4ea96c887e9e8825982abe8aa5a56a35170ac1248c3887f589d9419729` |
| `backend/app/Services/Marketing/Ultron/Prompts/UltronPromptsN8nFlow.php` | Modificado | COMPILER=8; build() y fixtures() conservan los artefactos históricos S1/S2 tras introducir c9. | `cc5b1e21d60ef11aceefd15b5a2d3a3b207bdd3d66e7806f31da2255d020683e` |
| `backend/app/Services/Marketing/Ultron/Prompts/UltronRegenN8nFlow.php` | Modificado | build(): conserva el compilador histórico en los metadatos de S2. | `5bca59c20e9259833166e6bfcb50d0a88d42b072c752871bad3e906dc759c476` |
| `backend/app/Services/Marketing/Ultron/UltronCommitService.php` | Modificado | execute() y listaDelModelo(): exigen ranura explícita para c9 y conservan la obligación de lista ante herramientas compatibles. | `52764baeb5ab43696b987384b8ee42459d2116290764d5569360e0d6e8adfafa` |
| `backend/app/Services/Marketing/Ultron/UltronDecideService.php` | Modificado | listaDelPanorama() y decide(): calculan el contrato y presupuesto previo a generar y aplican la negociación del consumidor. | `541498ebbbcf093d3b2e404a79f90ddc6aa1d71efc40b334e317cebdf8b4c2f6` |
| `backend/app/Services/Marketing/Ultron/UltronDraftCheck.php` | Modificado | expandido(): utiliza el renderer con la exigencia de ranura explícita c9. | `35b6610904693eb1d6fedc2adc482527d1a94c07b4a6f35e4402167033627d6d` |
| `backend/app/Services/Marketing/Ultron/UltronDraftDefects.php` | Modificado | of() e isListFinal(): validan la ranura y longitud c9 y producen notas coherentes con el contrato inicial. | `b9fee4dcc835cceee2df9f82279df048035d84a39abe19b4597d7c23a3be66b8` |
| `backend/app/Services/Marketing/Ultron/UltronListCriticGate.php` | Nuevo | allows() y javascript(): arbitraje compartido del Gate 1 sobre una lista ya validada por el backend; conserva vetos graves. | `9e81ad3f9a3357aecc491b366e7ee14d67ce375533a80f3264e4bb98794c143b` |
| `backend/app/Services/Marketing/Ultron/UltronListTurnContract.php` | Nuevo | build(), fromPayload() y javascript(): contrato y presupuesto inicial verificable, con reserva opcional de APP_LINKS. | `c67c0613460dacced3ee3444fadb6d29b2c689104732d64e50262ba6ac8e8246` |
| `backend/app/Services/Marketing/Ultron/UltronPlansOverview.php` | Modificado | requiresExplicitSlot(), render() y verify(): exigen el marcador literal una vez en c9 y eliminan su inserción implícita. | `2d7a558d9943e47b6b3439815f38c3f8819991bccf9fe0d211feadb12e1f10cd` |
| `backend/app/Services/Marketing/Ultron/UltronRegenerationNotes.php` | Modificado | explicitListNote(): notas específicas de sintaxis, lista completa, límite y presupuesto, incluida la factibilidad de APP_LINKS. | `cc02d56838b741ad8c1a2e6f440a4fc4dd3fe77e86819d4814b88ed6c364c4f0` |

Sello canónico de `protectedChanges`: `91e67bdfb44d79a60b48b50dc7481aa1f478b44bf7a8939c182bbe5cc55c159c`.
Sello de contenido que coincide con la revisión anterior de los 14 archivos: `43a3e1f95612c675214931b7be345492f8df9cec395e5a8f94b49f922782def5`.

## Inventario completo del paquete original: 86 archivos

El [manifiesto JSON](pt17-c9-manifiesto-autorizado.json), campo `packageManifest`, incorpora **las 86 rutas completas** con estado, tamaño y SHA-256 actual; para las 61 modificadas incorpora también sus identificadores base y de diff. Las otras 25 son nuevas. Se cotejó el conjunto con `fileManifest` de [la verificación original](pt17-c9-verificacion-local.json): coincide exactamente.

Sello canónico de `packageManifest`: `9a719435456bd4b0a195ead7840be87da1dba4b60e757c66d89139f930e3bbaf`.

Los 86 archivos originales se conservan byte a byte, incluidos el informe de cierre y la verificación original. Esos informes reflejan el estado anterior a la aprobación documental; esta acta actualiza únicamente el registro de autorización, sin reescribir sus resultados ni su veredicto técnico.

**Documentación de esta autorización, fuera de los 86:** dos documentos existentes modificados y tres nuevos; total posterior esperado: **91 archivos locales distintos de la base**. No son cinco ampliaciones del alcance funcional autorizado.

| Documento | Intervención de esta ronda |
|---|---|
| `backend/docs/ultron-studio/ULTRON_STUDIO_H5_CONTRACT.md` | Añadir la Enmienda A4-10 al final, conservando el texto previo. |
| `backend/docs/ultron-studio/ULTRON_STUDIO_H9_CONTRACT.md` | Añadir únicamente la fila L9-125 de la bitácora, sin modificar §3 ni §9.3. |
| `backend/docs/ultron-studio/plans/informes-motores/enmienda-h9-pt17-c9.md` | Esta acta, nueva. |
| `backend/docs/ultron-studio/plans/informes-motores/pt17-c9-manifiesto-autorizado.json` | Manifiesto nuevo de los 86, subconjunto de 14, autorización e identificadores. |
| `backend/docs/ultron-studio/plans/informes-motores/pt17-c9-enmienda.sha256` | Sello separado de los cuatro documentos anteriores. |

El manifiesto no se incluye dentro de los 86 ni intenta guardar su propio hash. El fichero `.sha256` sella los cuatro documentos anteriores, incluido el JSON; no contiene su propia suma. Así se evita una dependencia circular.

## Guarda H9 y evidencia de pruebas

`OutreachIsolationTest::test_la_puerta_de_diff_de_legacy_n8n_otp_y_motor_comercial_esta_vacia_y_la_voz_no_gana_ficheros()` exige diff vacío contra `origin/main`. La aserción de la línea 374 falla por los 11 modificados y no alcanza la de la línea 376, que detectaría los tres nuevos.

La autorización es de alcance documental y **no convierte ese FAIL en PASS**, no omite ni desactiva la prueba, no añade excepciones dentro de ella ni cambia la línea base. Se conservan todas las guardas y la referencia. El SHA-256 de `OutreachIsolationTest.php` es `3c41ba74197516dc582173f3c3d3fd9807c362051fa8556593625aad89b9098a`, idéntico a la base.

Evidencia histórica congelada: **8443 pruebas, 492742 aserciones, 0 errores, 1 fallo y 279 omitidas**; 8163 casos pasaron. Dentro de `OutreachIsolationTest`, pasaron las otras cuatro pruebas. Esta ronda documental no reejecuta la suite ni una medición real. Los logs citados por el informe original están fuera del control de versiones; sus conteos y caso fallido se conservan en el manifiesto, sin atribuirles una nueva corrida.

S3 mantiene `active:false` y su SHA-256 `51b6d1c2bf0c8224e92c2b684e29b2a7758007bf9f0579f8bea1f4a8ee84daa3`; no se activó ni se consultó un runtime remoto.

## Comprobación reproducible del inventario y sello

Desde la raíz de este worktree, el siguiente comando comprueba los cuatro documentos sellados, sin escribir:

```bash
shasum -a 256 -c backend/docs/ultron-studio/plans/informes-motores/pt17-c9-enmienda.sha256
```

Este fragmento comprueba las 86 huellas, las bases y parches, el subconjunto de 14, los conjuntos actuales, el índice, las guardas, el carácter aditivo de H5/H9 y las referencias. Solo lee archivos y Git; no ejecuta modelos ni pruebas Laravel:

```python
import hashlib, json, subprocess
from pathlib import Path

root = Path.cwd()
p = root / "backend/docs/ultron-studio/plans/informes-motores/pt17-c9-manifiesto-autorizado.json"
m = json.loads(p.read_text(encoding="utf-8"))
def git(*args):
    return subprocess.check_output(["git", *args])
def sha(data):
    return hashlib.sha256(data).hexdigest()
def canon(value):
    return (json.dumps(value, ensure_ascii=False, sort_keys=True,
                       separators=(",", ":")) + "\n").encode("utf-8")
base = m["base"]["commit"]
assert git("rev-parse", "origin/main").decode().strip() == base
assert git("rev-parse", "HEAD").decode().strip() == m["base"]["head"]
assert git("branch", "--show-current").decode().strip() == m["branch"]
assert sha(git("ls-files", "--stage", "-z")) == m["guards"]["stagedIndexEntriesSha256"]
entries = m["packageManifest"]
assert len(entries) == 86 and len({e["path"] for e in entries}) == 86
assert sha(canon(entries)) == m["integrity"]["packageManifestSha256"]
for e in entries:
    data = (root / e["path"]).read_bytes()
    assert len(data) == e["bytes"] and sha(data) == e["worktreeSha256"], e["path"]
    if e["status"] == "modified":
        assert sha(git("show", base + ":" + e["path"])) == e["baseSha256"]
        assert git("rev-parse", base + ":" + e["path"]).decode().strip() == e["baseGitBlob"]
        command = m["integrity"]["diffCommand"][:-1] + [e["path"]]
        assert sha(subprocess.check_output(command)) == e["gitDiffSha256"]
    else:
        assert e["status"] == "new" and e["baseSha256"] is None
        assert e["baseGitBlob"] is None and e["gitDiffSha256"] is None
protected = m["protectedChanges"]
assert len(protected) == 14 and len({e["path"] for e in protected}) == 14
assert sha(canon(protected)) == m["integrity"]["protectedChangesSha256"]
by_path = {e["path"]: e for e in entries}
for e in protected:
    assert {k: v for k, v in e.items() if k != "change"} == by_path[e["path"]]
review = [{"path": e["path"], "sha256": e["worktreeSha256"]} for e in protected]
assert sha(json.dumps(review, ensure_ascii=False, separators=(",", ":")).encode()) == m["integrity"]["previousReviewedProtectedSha256"]
original = json.loads((root / m["evidence"]["originalVerification"]).read_text())
assert {(e["path"], e["status"]) for e in entries} == {(e["path"], e["status"]) for e in original["fileManifest"]}
assert sum(e["status"] == "modified" for e in entries) == 61
assert sum(e["status"] == "new" for e in entries) == 25
assert sum(e["status"] == "modified" for e in protected) == 11
assert sum(e["status"] == "new" for e in protected) == 3
current = set(git("diff", "--name-only", "-z", "origin/main").decode().split("\0")) - {""}
new = set(git("ls-files", "--others", "--exclude-standard", "-z").decode().split("\0")) - {""}
docs = set(m["scope"]["documentaryFilesOutsideOriginalPackage"])
assert current | new == set(by_path) | docs
assert len(current | new) == 91
expected_new = {e["path"] for e in entries if e["status"] == "new"}
assert new == expected_new | {v for v in docs if v not in {e["path"] for e in m["guards"]["normativeDocumentsBefore"]}}
for e in m["guards"]["files"]:
    assert sha((root / e["path"]).read_bytes()) == e["sha256"]
    assert (root / e["path"]).read_bytes() == git("show", base + ":" + e["path"])
for e in m["guards"]["normativeDocumentsBefore"]:
    before = git("show", base + ":" + e["path"])
    assert sha(before) == e["beforeSha256"] == e["baseSha256"]
    after = (root / e["path"]).read_bytes()
    if e["path"].endswith("H5_CONTRACT.md"):
        restored = after.split(b"\n## Enmienda A4-10", 1)[0]
        assert restored == before
    else:
        restored = b"".join(line for line in after.splitlines(keepends=True) if not line.startswith(b"| L9-125 |"))
        assert restored == before
assert sha(m["authorization"]["verbatim"].encode()) == m["authorization"]["verbatimSha256"]
flow = json.loads((root / m["evidence"]["s3"]["path"]).read_text())
assert flow["active"] is False
print("OK: 86 archivos originales; 14 protegidos; solo 5 documentos nuevos/modificados; guardas, indice y referencias intactos; S3 inactivo.")
```

Para el subconjunto protegido, la revisión actual también reprodujo las dos órdenes reales de H9: once rutas modificadas en `git diff` y tres nuevas en `git ls-files --others --exclude-standard`. Es comprobación de alcance, no una nueva ejecución de la prueba.

El inventario queda sellado por SHA-256 y respaldado por la autorización transcrita. **SHA-256 prueba integridad de contenido; no es una firma digital de identidad ni certifica quién emitió el mensaje.** No se utilizaron claves privadas ni credenciales para este registro.

## Condiciones pendientes para autorizar la integración controlada

1. El propietario debe autorizar por separado las operaciones concretas de commit, push e integración; esta acta no las autoriza.
2. Revisar el paquete exacto contra la base que vaya a integrarse, junto con este registro. Si cambian los 14 contenidos, el resto del paquete o la base, detener y revisar la diferencia; esta autorización no se extiende automáticamente.
3. Acordar el tratamiento de la guarda previa a integración conforme al precedente: registrar el FAIL explícito por este diff autorizado, sin declararlo PASS. Solo una incorporación real a `main`, seguida de una actualización legítima de la referencia y la ejecución de las guardas originales, puede acreditar su verde posterior. No sustituir la referencia por HEAD, omitir la prueba ni manipular refs.
4. Mantener separadas la integración de código y cualquier cambio de runtime. El export autorizado del n8n real, su comparación con S2/S3, credenciales y compatibilidad remota siguen pendientes; no se asume que el n8n remoto coincide con lo versionado.
5. Producción, activación de S3, publicaciones y medición real requieren autorizaciones posteriores distintas. El mínimo de 17/20 listas finales del modelo, 12/20 primeros borradores, máximo 320 caracteres, cero errores graves, recortes o fallbacks injustificados permanece sin certificar. Las pruebas offline y las inserciones de Laravel no acreditan tasas reales del modelo.

La autorización documental queda registrada sin ampliar el alcance funcional ni convertir el fallo H9 en una aprobación técnica.
