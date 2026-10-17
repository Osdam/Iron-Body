#!/usr/bin/env bash
# Compila, prueba y empaqueta el servicio de impresión de recibos para Windows x64.
# Se ejecuta en macOS o Linux con el SDK de .NET 8; no hace falta Windows para generar el paquete.
#
#   ./build-windows.sh
#
# Variables opcionales:
#   DOTNET  ruta del ejecutable dotnet (por defecto ~/.dotnet/dotnet, o el del PATH)
#   PWSH    ruta de PowerShell 7 para comprobar la sintaxis de install.ps1/uninstall.ps1
set -euo pipefail

cd "$(dirname "$0")"
ROOT="$(pwd)"

VERSION="1.0.0"
PROTOCOL="1"
NAME="IronBody-Receipt-Printer-Windows-x64"
EXE_NAME="IronBody.ReceiptPrinter.exe"
DIST="$ROOT/dist"
PKG="$DIST/$NAME-$VERSION"
PUBLISH="$ROOT/artifacts/publish/win-x64"

if [[ -z "${DOTNET:-}" ]]; then
  if [[ -x "$HOME/.dotnet/dotnet" ]]; then DOTNET="$HOME/.dotnet/dotnet"; else DOTNET="$(command -v dotnet)"; fi
fi

step() { printf '\n== %s\n' "$1"; }
fail() { printf 'ERROR: %s\n' "$1" >&2; exit 1; }

step "1/7 Pruebas (todas deben pasar)"
"$DOTNET" test "$ROOT/IronBody.ReceiptAgent.sln" -c Release --nologo

step "2/7 Publicación win-x64 autocontenida, un solo archivo, sin recorte, sin PDB"
# IncludeNativeLibrariesForSelfExtract=false: nada se extrae a %TEMP% al arrancar. El runtime va
# enlazado dentro del exe; el único nativo que queda fuera es aspnetcorev2_inprocess.dll, el módulo de
# IIS, que Kestrel nunca carga y no viaja en el paquete (abajo se comprueba que no sale ningún otro).
rm -rf "$PUBLISH"
"$DOTNET" publish "$ROOT/src/IronBody.ReceiptAgent/IronBody.ReceiptAgent.csproj" \
  -c Release -r win-x64 --self-contained true \
  -p:PublishSingleFile=true \
  -p:EnableCompressionInSingleFile=true \
  -p:IncludeNativeLibrariesForSelfExtract=false \
  -p:PublishTrimmed=false \
  -o "$PUBLISH" --nologo

EXE="$PUBLISH/$EXE_NAME"
[[ -f "$EXE" ]] || fail "no se generó $EXE_NAME"
FILE_TYPE="$(file -b "$EXE")"
[[ "$FILE_TYPE" == PE32+\ executable* && "$FILE_TYPE" == *x86-64* ]] || fail "$EXE_NAME no es PE32+ x86-64: $FILE_TYPE"
echo "$EXE_NAME: $FILE_TYPE"
for f in "$PUBLISH"/*; do
  case "$(basename "$f")" in
    "$EXE_NAME"|appsettings.json|aspnetcorev2_inprocess.dll) ;;
    *) fail "la publicación trae $(basename "$f"), que el paquete no lleva: revisa si el exe lo necesita" ;;
  esac
done

step "3/7 Contenido del exe: sin PDB, sin configuración de desarrollo, sin rutas de este equipo"
# El ensamblado va COMPRIMIDO dentro del exe: buscar texto en el exe no basta. Se extrae cada archivo
# del bundle (formato single-file de .NET 8, versión 6.x) y se busca también dentro de cada uno.
python3 -I - "$EXE" "$HOME" <<'BUNDLE'
import struct, sys, zlib
exe, home = sys.argv[1], sys.argv[2]
data = open(exe, "rb").read()
signature = bytes([0x8b, 0x12, 0x02, 0xb9, 0x6a, 0x61, 0x20, 0x38, 0x72, 0x7b, 0x93, 0x02, 0x14, 0xd7, 0xa0, 0x32,
                   0x13, 0xf5, 0xb9, 0xe6, 0xef, 0xae, 0x33, 0x18, 0xee, 0x3b, 0x2d, 0xce, 0x24, 0xb3, 0x6a, 0xae])
at = data.find(signature)
if at < 8:
    sys.exit("no se encontró la cabecera del bundle single-file")
pos = struct.unpack_from("<q", data, at - 8)[0]

def read(fmt):
    global pos
    value = struct.unpack_from(fmt, data, pos)[0]
    pos += struct.calcsize(fmt)
    return value

def read_string():
    global pos
    length, shift = 0, 0
    while True:
        b = data[pos]
        pos += 1
        length |= (b & 0x7F) << shift
        shift += 7
        if b < 0x80:
            break
    text = data[pos:pos + length].decode("utf-8")
    pos += length
    return text

major, minor, count = read("<I"), read("<I"), read("<i")
read_string()  # id del bundle
if major < 6:
    sys.exit(f"versión de bundle inesperada: {major}.{minor}")
pos += 8 * 4 + 8  # deps.json y runtimeconfig.json (posición y tamaño) y banderas
entries = []
for _ in range(count):
    offset, size, compressed = read("<q"), read("<q"), read("<q")
    read("<B")  # tipo
    path = read_string()
    blob = data[offset:offset + (compressed or size)]
    content = zlib.decompress(blob, -15) if compressed else blob
    if len(content) != size:
        sys.exit(f"{path}: tamaño extraído {len(content)}, esperado {size}")
    entries.append((path, content))

own = [content for p, content in entries if p == "IronBody.ReceiptPrinter.dll"]
if len(own) != 1:
    sys.exit("el bundle no contiene IronBody.ReceiptPrinter.dll (¿formato distinto?)")
# Sin información de depuración: ni PDB embebido («MPDB») ni entrada CodeView con la ruta del PDB («RSDS»).
if b"MPDB" in own[0] or b"RSDS" in own[0]:
    sys.exit("IronBody.ReceiptPrinter.dll lleva información de depuración (DebugType debe ser none en Release)")
bad = [p for p, _ in entries if p.lower().endswith(".pdb") or p.lower() == "appsettings.development.json"]
if bad:
    sys.exit(f"el bundle no debe llevar: {bad}")

def expand(content):
    # Un PDB embebido va comprimido dentro de la DLL («MPDB» + tamaño + deflate): se descomprime para buscar.
    yield content
    start = content.find(b"MPDB")
    while start >= 0:
        try:
            yield zlib.decompress(content[start + 8:], -15)
        except zlib.error:
            pass
        start = content.find(b"MPDB", start + 4)

# Rutas y nombres propios de este equipo y del repositorio (no «/Users/» a secas: una DLL de
# Microsoft trae el ejemplo «/Users/Edit» en su documentación).
needles = sorted({home.encode(), home.rstrip("/").rsplit("/", 1)[-1].encode(), b"thermal-receipts", b"IRON_BODY", b"Osdam/Iron-Body"})
leaks = [(p, n.decode()) for p, content in entries for part in expand(content) for n in needles if n in part]
leaks += [("exe", n.decode()) for n in needles if n in data]
if leaks:
    sys.exit(f"rutas o nombres de este equipo dentro del paquete: {sorted(set(leaks))}")
print(f"bundle {major}.{minor}: {count} archivos; ninguno .pdb ni appsettings.Development.json; "
      f"IronBody.ReceiptPrinter.dll sin información de depuración; sin {[n.decode() for n in needles]}")
BUNDLE

step "4/7 Comprobación de appsettings.json de producción"
python3 -I - "$PUBLISH/appsettings.json" <<'PY'
import json, sys
cfg = json.load(open(sys.argv[1], encoding="utf-8"))["ReceiptPrinter"]
expected = {"Port": 17888, "ApiBaseUrl": "https://api.ironbodyneiva.cloud",
            "AllowedOrigins": ["https://ironbodyneiva.cloud", "https://www.ironbodyneiva.cloud"],
            "PrinterBackend": "winspool"}
for key, value in expected.items():
    if cfg.get(key) != value:
        sys.exit(f"appsettings.json: {key} = {cfg.get(key)!r}, se esperaba {value!r}")
print("appsettings.json de producción correcto")
PY
[[ ! -e "$PUBLISH/appsettings.Development.json" ]] || fail "appsettings.Development.json no debe publicarse"

step "5/7 Carpeta del paquete"
rm -rf "$PKG" "$DIST/$NAME.zip" "$DIST/SHA256SUMS.txt"
mkdir -p "$PKG"
cp "$EXE" "$PKG/"
cp "$PUBLISH/appsettings.json" "$PKG/"
cp "$ROOT"/packaging/{INSTALACION,DESINSTALACION,ROLLBACK,SOLUCION_DE_PROBLEMAS,PRUEBAS_FISICAS}.md "$PKG/"

# Scripts para Windows: CRLF; los .ps1 además con BOM UTF-8, porque Windows PowerShell 5.1 lee como
# ANSI un script sin BOM y estropearía las tildes de los mensajes. install.ps1 se escribe el último:
# sus marcadores «@@SHA256:<archivo>@@» se sustituyen por el SHA-256 de lo que ejecuta código (exe y
# desinstalador) tal como queda en el paquete; al instalar comprueba esas sumas sobre la copia protegida.
python3 -I - "$ROOT/packaging" "$PKG" "$EXE_NAME" <<'PY'
import hashlib, pathlib, re, sys
src, dst, exe_name = pathlib.Path(sys.argv[1]), pathlib.Path(sys.argv[2]), sys.argv[3]

def windows_text(name):
    text = (src / name).read_text(encoding="utf-8-sig").replace("\r\n", "\n").replace("\n", "\r\n")
    if name.endswith(".cmd") and not text.isascii():
        sys.exit(f"{name} debe ser ASCII (cmd.exe lo lee con la página de códigos OEM)")
    return text

def write(name, text):
    (dst / name).write_bytes(text.encode("utf-8-sig" if name.endswith(".ps1") else "ascii"))

for name in ("uninstall.ps1", "install.cmd", "uninstall.cmd"):
    write(name, windows_text(name))

install = windows_text("install.ps1")
for name in (exe_name, "uninstall.ps1", "uninstall.cmd"):
    marker = f"@@SHA256:{name}@@"
    if install.count(marker) != 1:
        sys.exit(f"install.ps1 debe tener exactamente un marcador {marker}")
    digest = hashlib.sha256((dst / name).read_bytes()).hexdigest()
    install = install.replace(marker, digest)
    print(f"SHA-256 incrustado en install.ps1: {name} {digest}")
left = re.findall(r"@@SHA256:[^@]*@@", install)
if left:
    sys.exit(f"install.ps1 tiene marcadores sin sustituir: {left}")
write("install.ps1", install)
PY

EXE_SHA="$(shasum -a 256 "$PKG/$EXE_NAME" | awk '{print $1}')"
BASE_COMMIT="$(git -C "$ROOT" rev-parse --short HEAD 2>/dev/null || echo desconocido)"
if [[ -n "$(git -C "$ROOT" status --porcelain -- . 2>/dev/null)" ]]; then
  BASE_COMMIT="$BASE_COMMIT (con cambios de receipt-agent sin commit)"
fi
BUILT_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
SDK="$("$DOTNET" --version)"
python3 -I - "$PKG/VERSION.txt" <<PY
import sys
lines = [
    "Iron Body · Servicio de impresión de recibos",
    "Versión: $VERSION",
    "Protocolo: $PROTOCOL",
    "Commit base del monorepo Iron-Body: $BASE_COMMIT",
    "Fecha de compilación (UTC): $BUILT_AT",
    "SDK .NET: $SDK (win-x64, autocontenido, un solo archivo)",
    "Ejecutable: $EXE_NAME",
    "SHA-256 del ejecutable: $EXE_SHA",
]
open(sys.argv[1], "w", encoding="utf-8-sig", newline="\r\n").write("\n".join(lines) + "\n")
PY

if [[ -n "${PWSH:-}" ]]; then
  step "5b Sintaxis de los scripts de PowerShell ($("$PWSH" -NoProfile -Command '$PSVersionTable.PSVersion.ToString()'))"
  CHECK="$ROOT/artifacts/check-ps-syntax.ps1"
  mkdir -p "$ROOT/artifacts"
  cat > "$CHECK" <<'PS1'
$bad = 0
foreach ($f in $args) {
    $t = $null; $e = $null
    [System.Management.Automation.Language.Parser]::ParseFile($f, [ref]$t, [ref]$e) | Out-Null
    if ($e.Count) { $bad++; $e | ForEach-Object { "{0}:{1}: {2}" -f $f, $_.Extent.StartLineNumber, $_.Message } }
    else { "{0}: sintaxis correcta" -f $f }
}
exit $bad
PS1
  # Los del paquete (CRLF, BOM y sumas ya sustituidas) son los que se ejecutan en Windows.
  "$PWSH" -NoProfile -File "$CHECK" "$ROOT/packaging/install.ps1" "$ROOT/packaging/uninstall.ps1" "$PKG/install.ps1" "$PKG/uninstall.ps1"
fi

step "6/7 ZIP y sumas SHA-256"
( cd "$DIST" && zip -r -X -q "$NAME.zip" "$NAME-$VERSION" )
( cd "$DIST" && shasum -a 256 "$NAME.zip" "$NAME-$VERSION/$EXE_NAME" > SHA256SUMS.txt )

step "7/7 Resultado"
unzip -l "$DIST/$NAME.zip"
echo
cat "$DIST/SHA256SUMS.txt"
echo
ls -l "$DIST/$NAME.zip"
echo "Paquete: $DIST/$NAME.zip"
