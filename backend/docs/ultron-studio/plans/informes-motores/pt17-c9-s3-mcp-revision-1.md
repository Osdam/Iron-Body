# PT-17 C9 — revisión local S3/MCP 1

**Identificador:** `PT17-C9-S3-MCP-1`. La autorización literal del propietario y la fecha UTC de registro figuran en el [manifiesto nuevo](pt17-c9-s3-mcp-manifiesto-1.json).

**Dictamen: READY para iniciar la fase de integración GitHub sobre la base sellada `e750dd2c77972a4055dcbd7524d533ed10af84bb`.** Es un dictamen local, no una autorización para commit, push, merge o producción. Antes de incorporar se debe revalidar el HEAD remoto y detenerse si cambió. La guarda H9 conserva su FAIL histórico; la aceptación comercial de PT-17 sigue sin certificar.

## Cambio autorizado y comprobado

El generador oficial fija `active:false` y `settings.availableInMCP:false`. El comparador de integración exige ambos booleanos `false`, aunque se le suministre una referencia igualmente insegura. Ausencia, `null`, `0`, `"false"` y `true` no son aceptados.

Solo se modificaron cuatro rutas del paquete anterior:

| Archivo | Cambio |
|---|---|
| `backend/app/Services/UltronStudio/StudioListN8nFlow.php` | Generación no disponible en MCP y comprobación estricta de los dos flags. |
| `backend/tests/Unit/UltronStudio/StudioListN8nFlowTest.php` | Pruebas de configuración, valores inválidos, referencia insegura, preservación de S2 y artefacto exportado. |
| `n8n/flujos/ultron-studio-s3/flujo-s3.json` | Regenerado exclusivamente mediante el generador existente. Única diferencia JSON: `settings.availableInMCP`, de `true` a `false`. |
| `n8n/flujos/ultron-studio-s3/LEEME.md` | Documenta el contrato de inactividad/MCP y los límites de la validación offline. |

S3 conserva 53 nodos. Sus nodos, conexiones, webhooks, referencias de credenciales y restantes ajustes coinciden exactamente con la preimagen. No se modifica el compilador 9, el runtime comercial de Laravel, Legacy, S2, el MCP global ni las credenciales.

Los flags de exportación no impiden la ejecución manual desde el editor por un operador autorizado. S3 conserva destinos operativos: no debe ejecutarse, reintentarse ni invocarse como prueba sin autorización específica. Esta ronda no accedió al VPS ni a n8n productivo.

## Evidencia histórica e inventario actual

La [enmienda PT17-C9-H9-1](enmienda-h9-pt17-c9.md), H5 A4-10, H9 L9-125, su manifiesto y su sello se conservan byte a byte. **Ninguno de sus cinco documentos se reescribe.** Los 14 archivos protegidos y sus cambios autorizados por H9 permanecen idénticos. Esta revisión no amplía la excepción H9.

El manifiesto nuevo conserva las 91 huellas anteriores, inventaría las mismas 91 rutas con su contenido actual y registra exactamente cuatro diferencias con preimagen, hash anterior/posterior y diff verificable. Para generador y prueba, las preimágenes se reconstruyeron en memoria y solo se aceptaron al reproducir exactamente sus SHA-256 v1. JSON y LEEME se capturaron antes de modificarlos. Las cuatro preimágenes completas quedan conservadas como `zlib+base64`, junto con el método de captura; los diffs son entre revisiones verificadas, no un supuesto diff Git contra una base donde esos archivos aún no existían.

Se añaden exclusivamente tres documentos: esta acta, el manifiesto nuevo y su sello. Por eso el conjunto actual contiene **94 rutas: 91 anteriores + 3 documentos de revisión**. Los conteos históricos de 86 y 91 no se alteran retroactivamente.

El verificador completo v1 describe la instantánea anterior: ahora detectaría legítimamente los cuatro contenidos modificados. Se conserva como evidencia histórica. El nuevo verificador acepta exclusivamente esas cuatro diferencias y exige que los cinco documentos v1 sigan intactos.

| Identificador | SHA-256 |
|---|---|
| Inventario actual de las 91 rutas | `f50a46ef0decd64a7c1aa55466ebae46ec7cbdcd7c773b477ab5e7ac54cfe5c2` |
| Delta exacto de cuatro archivos | `df1d101bbb9fc2eb18383a7bb8b142df0afc513fa2aeb4ce62839cf69b15745d` |
| Artefacto S3 actual | `92c36932278092d5b2d7cf6dd733e91f57195242d21a77177e93829df4378430` |
| Huella semántica S3 actual | `cdc9785c3861a160198eb83f59dacd1b5d8bacb553487b0c041bc1d540af14c7` |
| Huella semántica S2 conservada | `0555d4c755d4b350b445cba69401eaa9245b4934d3547cd77e2b70a5f5a1f515` |

El sello separado cubre esta acta y el manifiesto nuevo. El manifiesto contiene las 91 huellas actuales. Ningún fichero incluye su propio hash; no existe dependencia circular. SHA-256 acredita integridad de contenido, no identidad criptográfica del propietario.

## Pruebas y límites

Prueba roja previa al fix: el caso de configuración estricta falló en `settings.availableInMCP: missing` (1 prueba, 12 aserciones, 1 fallo; observación registrada por el autor). Después pasaron los dos casos dirigidos, 94 aserciones.

La batería combinada final pasó: **92 pruebas, 3446 aserciones, cero fallos, cero errores y cero omitidas**.

| Clase | Pruebas | Aserciones |
|---|---:|---:|
| `StudioListN8nFlowTest` | 8 | 375 |
| `StudioLabEngineTransportTest` | 7 | 99 |
| `StudioLabEngineFlowParityTest` | 46 | 2342 |
| `UltronPromptsN8nCommandTest` | 8 | 118 |
| `UltronPromptsN8nS2CommandTest` | 23 | 512 |

La paridad ejecuta código JavaScript sintético mediante el arnés offline; no ejecuta workflows en n8n. No hubo llamadas pagadas ni medición real. El comparador devuelve `ok:true`, `differences:[]`, `runtimeVerified:false`. El artefacto coincide byte a byte con la salida del generador oficial. Sintaxis PHP y Pint pasaron para los dos PHP modificados.

JUnit nuevo: `backend/storage/test-results/pt17-s3-mcpfalse-offline.xml`, con SHA-256 registrado en el manifiesto. Los logs son evidencia local fuera del control de versiones. La corrida histórica de 8443 pruebas, 492742 aserciones, 1 fallo H9 y 279 omitidas no se repitió ni se sobrescribió.

También se cotejaron **544 archivos protegidos** con la base Git: cero diferencias y cero archivos nuevos. Referencias, índice, guardas H9, S1/S2, configuración, rutas, migraciones, catálogo y componentes de voz permanecen intactos.

## Reproducción local

Desde la raíz, sin acceso remoto:

```sh
shasum -a 256 -c backend/docs/ultron-studio/plans/informes-motores/pt17-c9-enmienda.sha256
shasum -a 256 -c backend/docs/ultron-studio/plans/informes-motores/pt17-c9-s3-mcp-1.sha256
node backend/docs/ultron-studio/ops/motores/verificar-flujo-s3.cjs n8n/flujos/ultron-studio-s3/flujo-s3.json
```

El siguiente verificador solo lee archivos, Git y la salida local del generador; no ejecuta Laravel, workflows ni llamadas HTTP:

```python
import base64, difflib, hashlib, json, subprocess, zlib
from pathlib import Path

root = Path.cwd()
docs = "backend/docs/ultron-studio/plans/informes-motores/"
m = json.loads((root / (docs + "pt17-c9-s3-mcp-manifiesto-1.json")).read_text())
v1 = json.loads((root / (docs + "pt17-c9-manifiesto-autorizado.json")).read_text())
def sha(data):
    return hashlib.sha256(data).hexdigest()
def git(*args):
    return subprocess.check_output(["git", *args])
def canon(value):
    return (json.dumps(value, ensure_ascii=False, sort_keys=True,
                       separators=(",", ":")) + "\n").encode()
base = m["base"]["commit"]
assert git("rev-parse", "HEAD").decode().strip() == m["base"]["head"]
assert git("rev-parse", "origin/main").decode().strip() == base
assert git("branch", "--show-current").decode().strip() == m["branch"]
assert sha(git("ls-files", "--stage", "-z")) == m["invariants"]["stagedIndexEntriesSha256"]
historical = m["history"]["parentHashes"]
assert len(historical) == 91
assert sha(canon(historical)) == m["integrity"]["historicalPackageMapSha256"]
parent = {e["path"]: e["worktreeSha256"] for e in v1["packageManifest"]}
old_seal = docs + "pt17-c9-enmienda.sha256"
for line in (root / old_seal).read_text().splitlines():
    digest, name = line.split("  ", 1)
    assert sha((root / name).read_bytes()) == digest
    parent[name] = digest
parent[old_seal] = sha((root / old_seal).read_bytes())
assert parent == historical
assert parent[old_seal] == "ba006bd25fb504ffbc523d796ec5ddfd6e5baaa65d02807975db0fed9bba6be0"
entries = m["currentPackageManifest"]
assert len(entries) == len({e["path"] for e in entries}) == 91
assert {e["path"] for e in entries} == set(historical)
assert sha(canon(entries)) == m["integrity"]["currentPackageManifestSha256"]
for e in entries:
    data = (root / e["path"]).read_bytes()
    assert len(data) == e["bytes"] and sha(data) == e["worktreeSha256"], e["path"]
    if e["status"] == "modified":
        assert sha(git("show", base + ":" + e["path"])) == e["baseSha256"]
        assert git("rev-parse", base + ":" + e["path"]).decode().strip() == e["baseGitBlob"]
        command = v1["integrity"]["diffCommand"][:-1] + [e["path"]]
        assert sha(subprocess.check_output(command)) == e["gitDiffSha256"]
    else:
        assert e["status"] == "new" and e["baseSha256"] is None
        assert e["baseGitBlob"] is None and e["gitDiffSha256"] is None
changed = {e["path"] for e in entries if e["worktreeSha256"] != historical[e["path"]]}
assert changed == set(m["scope"]["allowedModifiedPaths"]) and len(changed) == 4
assert sha(canon(m["delta"])) == m["integrity"]["deltaSha256"]
preimages = {}
for e in m["history"]["historicalPreimages"]:
    data = zlib.decompress(base64.b64decode(e["content"]))
    assert len(data) == e["bytes"] and sha(data) == e["sha256"] == historical[e["path"]]
    preimages[e["path"]] = data
assert set(preimages) == changed == {e["path"] for e in m["delta"]}
for e in m["delta"]:
    old = preimages[e["path"]]
    now = (root / e["path"]).read_bytes()
    patch = "".join(difflib.unified_diff(old.decode().splitlines(keepends=True),
        now.decode().splitlines(keepends=True), fromfile="a/" + e["path"],
        tofile="b/" + e["path"], n=3))
    assert sha(old) == e["beforeSha256"] and sha(now) == e["afterSha256"]
    assert patch == e["unifiedDiff"] and sha(patch.encode()) == e["unifiedDiffSha256"]
    for line in patch.splitlines():
        if line.startswith("+") and not line.startswith("+++"):
            assert line == line.rstrip(), e["path"]
for name in m["invariants"]["historicalDocumentsUnchanged"]:
    assert sha((root / name).read_bytes()) == historical[name]
assert m["invariants"]["protectedChanges"] == v1["protectedChanges"]
for e in m["invariants"]["protectedChanges"]:
    assert sha((root / e["path"]).read_bytes()) == e["worktreeSha256"]
for e in m["invariants"]["guardFiles"]:
    assert sha((root / e["path"]).read_bytes()) == e["sha256"]
protected = {e["path"]: e["sha256"] for e in m["invariants"]["protectedBaseFiles"]}
assert len(protected) == 544
roots = m["invariants"]["protectedBasePaths"]
items = []
for entry in git("ls-tree", "-r", "-z", base, "--", *roots).split(b"\0"):
    if entry:
        meta, name = entry.split(b"\t", 1)
        items.append((name.decode(), meta.split()[2]))
assert {name for name, oid in items} == set(protected)
stream = subprocess.run(["git", "cat-file", "--batch"], check=True, capture_output=True,
    input=b"".join(oid + b"\n" for name, oid in items)).stdout
position = 0
for name, oid in items:
    end = stream.index(b"\n", position)
    header = stream[position:end].split()
    size = int(header[2])
    data = stream[end + 1:end + 1 + size]
    position = end + 1 + size + 1
    assert header[0] == oid and sha(data) == protected[name]
    assert (root / name).read_bytes() == data, name
assert git("ls-files", "--others", "--exclude-standard", "--", *roots) == b""
current = set(git("diff", "--name-only", "-z", base).decode().split("\0")) - {""}
new = set(git("ls-files", "--others", "--exclude-standard", "-z").decode().split("\0")) - {""}
new_docs = set(m["scope"]["newDocumentPaths"])
assert len(new_docs) == 3 and not new_docs & set(historical)
assert current | new == set(historical) | new_docs
assert len(current | new) == 94
for name in new_docs:
    data = (root / name).read_bytes()
    assert data.endswith(b"\n")
    assert all(line == line.rstrip() for line in data.splitlines()), name
flow = json.loads((root / m["artifact"]["path"]).read_text())
assert flow["active"] is False and flow["settings"]["availableInMCP"] is False
old_flow = json.loads(preimages[m["artifact"]["path"]])
old_flow["settings"]["availableInMCP"] = False
assert old_flow == flow and len(flow["nodes"]) == 53
assert sha((root / m["artifact"]["path"]).read_bytes()) == m["artifact"]["sha256"]
generated = subprocess.check_output(["php", "backend/docs/ultron-studio/ops/motores/generar-flujo-s3.php"])
assert generated == (root / m["artifact"]["path"]).read_bytes()
assert sha(m["authorization"]["verbatim"].encode()) == m["authorization"]["verbatimSha256"]
log = root / m["validation"]["combined"]["junit"]
if log.is_file():
    assert sha(log.read_bytes()) == m["validation"]["combined"]["junitSha256"]
print("OK: 91 rutas revisadas + 3 documentos; delta exacto de 4; v1, 14 protegidos, 544 archivos base, índice y refs intactos; S3 inactivo y fuera de MCP.")
```

Este verificador conserva las condiciones de HEAD/índice previas al commit. Después de un commit autorizado deberán cotejarse los mismos hashes con el árbol confirmado; no se reescribe la evidencia histórica para cambiar ese requisito.

## Pendiente para las siguientes fases

- Commit, push y merge requieren autorización posterior; no se ejecutaron en esta revisión. La base remota no se consultó en esta ronda exclusivamente local.
- H9 debe comprobarse con sus guardas originales después de una incorporación real autorizada a `main`; el FAIL anterior no se omite ni se transforma en PASS.
- Producción mantiene sus puertas de autorización: respaldos, comparación tras importar como copia inactiva y fuera de MCP, drenaje, cambio de propietario del webhook y liberación del tráfico. No se importó ni ejecutó nada.
- La validez efectiva del runtime remoto tras una futura importación y las tasas del modelo no se deducen de estas pruebas offline.
- La aceptación sigue exigiendo al menos 17/20 listas finales del modelo, 12/20 primeros borradores, máximo 320 caracteres, cero errores graves, cero recortes y cero fallbacks injustificados. Ninguna inserción automática o fallback se cuenta como éxito del LLM.
