# receipt-agent · Servicio local de impresión de recibos (Windows, .NET 8)

Servicio de Windows `IronBodyReceiptPrinter` que imprime en la térmica Digital POS DIG-58IIK los
recibos que autoriza el CRM, en **texto + ESC/POS por el spooler en RAW** (nunca raster). Escucha
solo en `127.0.0.1:17888`, solo atiende al origen del CRM y solo imprime lo que el backend entrega a
cambio de un ticket de un solo uso.

Contrato (fuente de verdad, compartido con el backend Laravel y el CRM Angular): `CONTRATO_AGENTE_IMPRESION.md`
v1 — §1 constantes, §2 documento y ESC/POS, §3 API local, §6 códigos de error.

Es independiente de LocalGym (software de ingreso): no comparte código ni datos con él y no gestiona
pagos, membresías, inventario, facturación, biometría ni acceso.

## Estructura

```
receipt-agent/
├── IronBody.ReceiptAgent.sln
├── Directory.Build.props            versión 1.0.0, net8.0, nullable
├── build-windows.sh                 pruebas → publish win-x64 → dist/*.zip + SHA256SUMS.txt
├── src/IronBody.ReceiptAgent/       (exe: IronBody.ReceiptPrinter.exe)
│   ├── Program.cs                   arranque; no arranca con configuración inválida
│   ├── AgentHost.cs                 composición: Kestrel 127.0.0.1, guardas, límite, endpoints
│   ├── Configuration/               appsettings.json (sección ReceiptPrinter) y su validación
│   ├── Settings/                    settings.json de la estación (station_key estable, escritura atómica)
│   ├── Http/                        guardas §3.1, cabeceras, endpoints §3.2
│   ├── Backend/                     cliente de /api/receipt-agent/redeem|report y parser de respuestas
│   ├── Tickets/                     flujo del ticket, códigos §6, mensajes al cajero
│   ├── Printing/                    cola de un trabajador, validación §2, seguimiento puro del trabajo
│   │   ├── EscPos/                  renderizador §2.1 y codificador §2.2 (tablas CP850/CP437 propias)
│   │   └── Windows/                 P/Invoke a winspool.drv (solo Windows)
│   └── Diagnostics/                 búfer de 100 eventos y log JSON por línea (14 días)
├── tests/IronBody.ReceiptAgent.Tests/   xUnit; todo corre en macOS sin Windows
└── packaging/                       install/uninstall (.ps1 + .cmd) y documentación para el técnico
```

## Desarrollo en macOS

SDK .NET 8 (`~/.dotnet/dotnet`).

```bash
~/.dotnet/dotnet build
~/.dotnet/dotnet test
```

Ejecutarlo en el Mac contra un backend local (impresora de archivo: cada trabajo se guarda como
`.bin` en `<DataDirectory>/spool-out`):

```bash
DOTNET_ENVIRONMENT=Development ~/.dotnet/dotnet run --project src/IronBody.ReceiptAgent -- \
  --ReceiptPrinter:DataDirectory="$PWD/.data" \
  --ReceiptPrinter:ApiBaseUrl=http://127.0.0.1:8000 \
  --ReceiptPrinter:AllowedOrigins:0=http://localhost:4200
```

- `PrinterBackend=file` solo se admite en `Development`; en cualquier otro entorno el servicio no arranca.
- La impresora de archivo lista `POS-58` (imprime a `.bin`) y `SIMULAR-OFFLINE`, `SIMULAR-PAPEL`,
  `SIMULAR-ERROR`, `SIMULAR-TIMEOUT`, `SIMULAR-RAW`, que devuelven ese error, para probar el CRM de
  punta a punta. `SIMULAR-RED` tiene un puerto TCP/IP y se rechaza como impresora de red; cualquier
  otro nombre da `printer_not_found`.
- `appsettings.Development.json` permite los orígenes `http://localhost:4200` y `http://127.0.0.1:4200`.
- Las peticiones a `/v1/*` necesitan `Origin` permitido; los POST además `Content-Type: application/json`
  y `X-IronBody-Client: crm`:

```bash
curl -s -H 'Origin: http://localhost:4200' http://127.0.0.1:17888/v1/status
```

## Paquete para Windows

```bash
./build-windows.sh                      # PWSH=/ruta/pwsh para comprobar además la sintaxis de los .ps1
```

Genera `dist/IronBody-Receipt-Printer-Windows-x64-1.0.0/` (exe, appsettings.json de producción,
scripts, docs, VERSION.txt), `dist/IronBody-Receipt-Printer-Windows-x64.zip` y `dist/SHA256SUMS.txt`.
Instalación, desinstalación, rollback y problemas: `packaging/*.md`.

Además de las pruebas, el script comprueba que la publicación no trae nada que el paquete no lleve
(solo queda fuera `aspnetcorev2_inprocess.dll`, el módulo de IIS, que Kestrel no carga; con
`IncludeNativeLibrariesForSelfExtract=false` no se extrae nada a `%TEMP%`), **extrae el bundle
single-file** y verifica que `IronBody.ReceiptPrinter.dll` no lleva PDB ni entrada CodeView y que
ningún archivo contiene rutas de este equipo ni el nombre del repositorio (Release usa `DebugType
none`), y sustituye en el `install.ps1` del paquete los marcadores `@@SHA256:<archivo>@@` por el
SHA-256 del exe y del desinstalador. El `install.ps1` de `packaging/` sin sustituir se niega a instalar.

## Decisiones

- **El navegador solo manda el ticket.** `POST /v1/tickets` rechaza cualquier campo que no sea
  `ticket` (`invalid_body`): el texto a imprimir llega solo del backend, por TLS.
- **Validar, no arreglar.** Un documento que incumple el §2 se rechaza entero (`invalid_job`) y se
  reporta; nunca se recorta una línea.
- **El texto no puede generar comandos.** El codificador solo emite 0x20–0x7E o bytes ≥ 0x80 de su
  tabla; una prueba barre todo Unicode y otra recorre la salida con la gramática exacta del §2.1.
- **Se cancela lo que no sale.** Un trabajo con error o que sigue en cola a los 12 s se borra de la
  cola para que no salga horas después y duplique una reimpresión. La decisión es una clase pura
  (`JobTracker`) probada con secuencias simuladas.
- **El flujo no depende del navegador.** Si el CRM cierra la pestaña a mitad, el ticket ya está
  canjeado: se imprime y se reporta igual. Dos peticiones con el mismo ticket comparten resultado.
- **Configure pasa por la cola**, así un cambio de columnas o impresora nunca cae a mitad de un recibo.
- **Solo impresoras locales** (`LocalPrinterPolicy`): enumeración PRINTER_ENUM_LOCAL; se rechazan
  nombres `\\…`, `//…`, `…://…`, impresoras con PRINTER_ATTRIBUTE_NETWORK y puertos `\\…`, `IP_…`,
  `WSD-…`, direcciones IP, `TS###` (Escritorio remoto), `FILE:`, `PORTPROMPT:`, `NUL:` y rutas de
  archivo, al configurar, al publicar `/v1/printers` y otra vez justo antes de imprimir. Una impresora
  con varios puertos (`USB001,IP_…`) se evalúa puerto a puerto. Es lista de **denegación** a propósito:
  los drivers POS de fabricante usan nombres de puerto propios.
- **Solo conexiones de este equipo.** Primera guarda: si la dirección remota no es loopback (o no se
  conoce) → 403 `not_loopback`, antes que Host/Origin, que un cliente de la red falsifica. Una sección
  `Kestrel` en la configuración (que añadiría direcciones de escucha, p. ej. una variable
  `Kestrel__Endpoints__…` de la máquina) es un error de configuración: el servicio no arranca (exit 2).
  Kestrel acepta como mucho 64 conexiones a la vez.
- **Instalador:** solo dentro de Program Files, con ACL explícita sin herencia; ProgramData\IronBody se
  revisa sin seguir enlaces antes de escribir nada (enlace → aborta; propietario ajeno → toma
  posesión y rehace permisos); el exe y el desinstalador se comprueban por SHA-256 ya copiados en la
  carpeta protegida; la ruta del servicio se fija por WMI (entre comillas) y se verifica; inicio
  automático no retrasado.

## Riesgos aceptados

Documentados para el técnico en `packaging/SOLUCION_DE_PROBLEMAS.md` («Riesgos conocidos»):

- otro proceso del PC puede ocupar `127.0.0.1:17888` antes que el servicio y leer los recibos que
  canjee con la clave de estación (no secreta por contrato v1, §3.4). Mejora futura: secreto de
  estación verificado por el backend en `redeem` (cambio de contrato);
- cualquier proceso local puede agotar el límite global de 30 POST/min (una web de otro origen no).
