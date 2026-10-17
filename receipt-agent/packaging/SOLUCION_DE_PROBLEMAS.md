# Solución de problemas · Servicio de impresión de recibos de Iron Body

## Dónde mirar primero

| Fuente | Qué tiene | Cómo verlo |
|---|---|---|
| Mensaje del CRM | Código y texto para el cajero | Pantalla de cobro / recibos |
| Logs del servicio | Todo lo que hace, un JSON por línea, 14 días | `C:\ProgramData\IronBody\ReceiptPrinter\logs\receipt-printer-AAAAMMDD.log` |
| Visor de eventos | Solo avisos y errores (y por qué no arranca) | Registros de Windows → Aplicación → origen `IronBodyReceiptPrinter` |
| Registro de instalación | Cada paso de install/uninstall | `...\logs\install-*.log`, `uninstall-*.log` (si la instalación se detuvo antes de preparar ProgramData: `%TEMP%\IronBody-ReceiptPrinter-*.log` del administrador) |
| Diagnóstico | Últimos 100 eventos (sin datos de clientes) | Ver abajo |

Leer las últimas 50 líneas del log de hoy en PowerShell:

```powershell
$log = "$env:ProgramData\IronBody\ReceiptPrinter\logs\receipt-printer-$(Get-Date -Format yyyyMMdd).log"
Get-Content $log -Tail 50 -Encoding UTF8 | ForEach-Object { $_ | ConvertFrom-Json } |
    Format-Table at, level, message -Wrap
```

Estado y diagnóstico del servicio (las peticiones a `/v1/*` deben llevar el origen del CRM):

```powershell
$h = @{ Origin = 'https://ironbodyneiva.cloud' }
Invoke-RestMethod http://127.0.0.1:17888/v1/status -Headers $h | ConvertTo-Json -Depth 5
(Invoke-RestMethod http://127.0.0.1:17888/v1/diagnostics -Headers $h).events | Format-Table at, level, event, error_code, message -Wrap
Invoke-RestMethod http://127.0.0.1:17888/v1/printers -Headers $h | ConvertTo-Json -Depth 5
```

Los logs y el diagnóstico **nunca** llevan nombres de clientes, importes ni permisos de impresión:
se pueden enviar a soporte tal cual.

## El servicio no arranca

En el Visor de eventos (origen `IronBodyReceiptPrinter`) aparece el motivo. Código de salida del
proceso:

| Código | Causa | Qué hacer |
|---|---|---|
| 2 | Configuración inválida («Configuración inválida: …»): `appsettings.json` mal, o una sección `Kestrel` (en appsettings.json, una variable de entorno `Kestrel__…` del equipo o un argumento) que abriría el servicio a la red | Reinstala con `install.cmd` (reescribe appsettings.json) y los parámetros correctos; si el mensaje habla de `Kestrel`, busca la variable del equipo con `[Environment]::GetEnvironmentVariables('Machine').Keys \| Where-Object { $_ -like 'Kestrel*' }` y quítala (o pide a quien la puso que la limite a su aplicación) |
| 3 | No pudo crear o leer `settings.json` en ProgramData | Reinstala (repone los permisos de la carpeta) |
| 4 | El puerto está ocupado | Ver «Puerto ocupado» |

Otras comprobaciones:

- La «Cola de impresión» debe estar en marcha (el servicio depende de ella):
  `Get-Service Spooler` → si está detenida, `Start-Service Spooler`.
- Si falla, Windows lo reinicia a los 5 s, a los 10 s y después cada 60 s. Con la configuración mal
  seguirá fallando en bucle: corrige la causa y `Restart-Service IronBodyReceiptPrinter`.

## Puerto ocupado

```powershell
Get-NetTCPConnection -LocalPort 17888 -State Listen | ForEach-Object { Get-Process -Id $_.OwningProcess -IncludeUserName }
```

Cierra o reconfigura el otro programa. No cambies el puerto del servicio: el CRM usa 17888.

Si quien escucha en 17888 **no** es `IronBody.ReceiptPrinter` (cuenta `NT AUTHORITY\LOCAL SERVICE`),
trátalo como un incidente de seguridad, no como una avería: ver «Riesgos conocidos».

## El CRM dice que el servicio no responde

1. Abre `http://127.0.0.1:17888/` en Chrome **en ese PC**. Si no carga, el servicio no está en
   marcha (ver arriba): `Get-Service IronBodyReceiptPrinter`.
2. Si carga pero el CRM sigue sin verlo: **permiso de Chrome denegado**. Candado del CRM →
   Configuración del sitio → acceso a apps y servicios del dispositivo / red local → Permitir, y
   recarga. En equipos administrados, revisa en `chrome://policy` las políticas
   `LocalNetworkAccessAllowedForUrls` y `LoopbackNetworkAllowedForUrls` (INSTALACION.md, paso 6).
3. Si el servicio **sí está instalado y responde**, comprueba que entras al CRM por una de estas dos
   direcciones, exactamente: `https://ironbodyneiva.cloud` o `https://www.ironbodyneiva.cloud`. Otra
   dirección (con `http://`, otro subdominio, un puerto) se rechaza con `origin_not_allowed`. Si el CRM
   pasara a servirse por otra dirección, reinstala con `-AllowedOrigin` incluyéndola.

## Códigos de error

| Código | Qué significa | Qué hacer |
|---|---|---|
| `origin_not_allowed` | La petición no viene del CRM autorizado | Abrir el CRM en `https://ironbodyneiva.cloud` o `https://www.ironbodyneiva.cloud`; si el origen cambió, reinstalar con `-AllowedOrigin` |
| `bad_host` | Se llamó al servicio con otro nombre de host | Usar `127.0.0.1` |
| `not_loopback` | La conexión no viene de este mismo equipo | El servicio solo atiende al navegador del propio PC; nunca debería verlo el CRM |
| `rate_limited` | Demasiadas solicitudes: más de 30 impresiones o 120 consultas por minuto al servicio, o el servidor de Iron Body limitó a este equipo | Esperar un minuto y reintentar. Si se repite sin estar imprimiendo mucho, ver «Riesgos conocidos» |
| `ticket_invalid` / `ticket_expired` | El permiso de impresión no vale o caducó (2 min) | Reintentar desde el CRM |
| `ticket_used` | Ese permiso ya se usó | Si no salió el papel, reimprimir |
| `station_mismatch` | El recibo se pidió para otra estación | Revisar en el CRM qué estación usa este PC |
| `station_inactive` | La estación está desactivada en el CRM | Un administrador la activa |
| `agent_disabled` | El servidor desactivó el servicio local para todos | Usar el modo del navegador (ROLLBACK.md) |
| `protocol_unsupported` | Servicio demasiado antiguo | Instalar la versión nueva |
| `attempt_closed` | El intento ya estaba cerrado | Reimprimir si hace falta |
| `api_unreachable` | Sin conexión con `api.ironbodyneiva.cloud` | Revisar internet, proxy, firewall |
| `api_error` | El servidor respondió con un error | Reintentar; si persiste, avisar a soporte con la hora. Si el mensaje dice «El servidor no tiene el servicio de impresión (¿backend sin actualizar?)», el servidor aún no tiene la versión que atiende al servicio: avisar a soporte |
| `incompatible_job` | El servidor mandó algo que esta versión no entiende | Actualizar el servicio |
| `invalid_job` | El recibo no cumple el formato (línea demasiado larga, columnas distintas…) | El detalle dice la línea; revisar que la estación tenga las columnas correctas y avisar a soporte |
| `printer_not_configured` | La estación no tiene impresora | Enviar la configuración desde el CRM |
| `printer_not_found` | Windows no tiene una impresora local con ese nombre, o es de red | Ver «Impresora no encontrada» |
| `printer_offline` | La impresora no responde | Ver «Impresora sin conexión» |
| `paper_out` | Sin papel o tapa abierta | Poner papel, cerrar la tapa |
| `printer_error` | La impresora o la cola informan un error | Ver «Pitidos o recibo cortado» |
| `job_timeout` | El trabajo no salió de la cola en 12 s; se canceló | Ver «Impresora sin conexión» |
| `spooler_error` | Windows no aceptó el trabajo | Si el mensaje habla de impresión directa: driver «Generic / Text Only» (INSTALACION.md, paso 7) |
| `queue_full` | Más de 20 recibos esperando | Esperar y reintentar |

Un trabajo con error o atascado **se cancela** de la cola de Windows a propósito: así no sale horas
después y duplica una reimpresión.

## Impresora no encontrada

- El nombre configurado en el CRM debe ser **idéntico** al de Windows. La lista exacta:
  `Invoke-RestMethod http://127.0.0.1:17888/v1/printers -Headers @{Origin='https://ironbodyneiva.cloud'}`.
- La impresora debe estar instalada **en este equipo** (impresora local por USB, puerto `USBxxx`).
  El servicio **rechaza a propósito** las impresoras compartidas de otro equipo (`\\equipo\cola`), las
  de red (puerto `IP_…`, `WSD-…` o una dirección IP), las de internet (`http://…`) y las redirigidas
  por Escritorio remoto (puerto `TS001`…, imprimen en el equipo desde el que te conectas), aunque
  Windows las tenga instaladas: los recibos llevan datos de clientes y no deben salir del PC. También
  las que escriben a un archivo o no imprimen (`FILE:`, `PORTPROMPT:` como «Microsoft Print to PDF»,
  `NUL:`, una ruta como `C:\…`). Si una impresora tiene varios puertos, basta uno así para rechazarla. En el CRM verás
  «Elige una impresora conectada a este equipo» al configurar, o «no está conectada a este equipo» al
  imprimir; el log lo detalla (evento `printer_rejected`).
- `/v1/printers` solo lista las impresoras que se pueden elegir.

## Impresora sin conexión (`printer_offline`, `job_timeout`)

1. Encendida, luz verde, papel puesto, tapa cerrada.
2. Cable USB directo al PC (sin hub) y bien encajado en los dos extremos.
3. En la cola de Windows (Impresoras → POS-58 → Abrir cola) desmarca **«Usar impresora sin
   conexión»** si está marcado, y cancela trabajos viejos atascados.
4. Página de prueba de Windows. Si tampoco sale, el problema es de impresora/driver/cable, no del servicio.

## Pitidos o recibo cortado

Si la impresora **pita, se detiene a mitad o corta el recibo**: **DETENTE**, no reintentes en bucle.
Revisa en este orden:

1. **Papel**: rollo térmico de 57/58 mm, con la cara térmica hacia el cabezal (al rasparla con la
   uña se pone negra), sin atascos.
2. **Tapa** bien cerrada (debe hacer clic).
3. **Temperatura**: tras muchas impresiones seguidas el cabezal se calienta y la impresora se
   protege. Déjala enfriar unos minutos.
4. **Fuente de alimentación**: debe ser la original de **12 V / 2,6 A**. Una fuente más débil
   provoca cortes y pitidos justo en las líneas en negrita o doble alto, que consumen más.
5. **Cable USB**: cámbialo por otro corto y directo al PC.
6. **Driver**: página de prueba de Windows; si falla, reinstala el driver POS-58 o usa
   «Generic / Text Only».

**Autoprueba de la impresora** (procedimiento habitual en estas térmicas de 58 mm; si no responde,
consulta su manual): apágala, mantén pulsado **FEED** y enciéndela sin soltarlo; suelta cuando
empiece a imprimir. La hoja de autoprueba muestra su configuración (páginas de código, modo chino).
Si la autoprueba también se corta o pita, el fallo es de la impresora, la fuente o el papel, no del
servicio.

## «Aceptado por el sistema» pero no salió el papel

Windows recibió el trabajo y lo entregó al USB, pero la DIG-58IIK no informa por USB si imprimió.
Revisa la impresora (papel, tapa, luz) y reimprime desde el CRM: la copia saldrá con el rótulo
REIMPRESIÓN.

## Tildes o eñes mal impresas

Cambia la página de códigos desde el CRM (configuración de la estación) y repite la prueba completa:
`cp850` (por defecto) → `cp437` → `ascii` (sin tildes, siempre legible).

## Las líneas se parten en dos

Las columnas configuradas no coinciden con la fuente de la impresora. Con la DIG-58IIK:
**32 columnas** (Fuente A, la recomendada) o 42 (Fuente B, letra más pequeña). La regla
`1234567890…` de la prueba completa debe caber en una línea.

## Sale texto chino o símbolos extraños

El servicio cancela el modo chino al inicio de cada recibo (`FS .`). Si aun así sale texto chino, la
impresora está fijada en ese modo por configuración interna: revisa la hoja de autoprueba y su manual.

## Errores al instalar

| Mensaje | Qué significa | Qué hacer |
|---|---|---|
| «InstallDir no válido … debe estar dentro de C:\Program Files» | Se pidió instalar fuera de Program Files | Usa la carpeta por defecto. Fuera de Program Files la carpeta heredaría permisos que dejan a cualquier usuario cambiar el ejecutable del servicio |
| «… es un enlace (unión o vínculo simbólico) …» / «Hay enlaces … dentro de C:\ProgramData\IronBody» | Alguien dejó un enlace en la carpeta de datos antes de instalar | **No lo sigas ni borres su destino.** Bórralo como enlace (`rmdir "<enlace>"` en `cmd`, sin `/s`), revisa qué cuenta lo creó y vuelve a instalar |
| «Elementos de C:\ProgramData\IronBody creados por otra cuenta …» (aviso) | Otra cuenta creó esa carpeta o algo dentro | El instalador toma posesión para Administradores y rehace los permisos; revisa igualmente quién y por qué |
| «No se puede revisar el contenido de C:\ProgramData\IronBody …» | Otra cuenta dejó permisos que bloquean a los administradores | Sigue las órdenes del mensaje (`takeown`, `rmdir /s /q`); se pierde la clave de estación y hay que registrar la nueva |
| «El SHA-256 de … no coincide con el del paquete» | El archivo está dañado o se cambió después de generar el ZIP | No instales: descarga el ZIP otra vez, compruébalo con `SHA256SUMS.txt` y extráelo en tu carpeta `Descargas` |
| «Este install.ps1 no viene de un paquete generado por build-windows.sh» | Se ejecutó el script del repositorio, no el del ZIP | Usa el `install.cmd` del ZIP |
| «La ruta del servicio quedó como …» | Windows no guardó la ruta entre comillas | Avisa a soporte; no dejes el servicio así |

## Riesgos conocidos (aceptados en esta versión)

- **Otro programa puede adelantarse al servicio en el puerto 17888.** Cualquier cuenta de Windows
  del PC puede abrir `127.0.0.1:17888` si el servicio no lo tiene ocupado (antes de que arranque, o
  durante un reinicio tras un fallo). Ese programa recibiría los permisos de impresión del CRM y,
  como la clave de estación no es secreta, podría canjearlos y **leer las líneas de los recibos**
  (cliente, artículos, totales) e incluso responder «aceptado» sin que salga papel. No obtiene la
  sesión del CRM, no reutiliza un permiso ya usado y no toca dinero. Para reducir la ventana, el
  servicio arranca con inicio **automático no retrasado** (depende solo de «Cola de impresión»).
  En un PC de caja con varias cuentas de uso diario, comprueba de vez en cuando quién escucha
  (orden de «Puerto ocupado»): debe ser `IronBody.ReceiptPrinter` como `LOCAL SERVICE`. La solución
  de fondo (un secreto de estación que solo conozca el servicio y que el servidor verifique al
  canjear) cambia el contrato v1 y queda como mejora futura.
- **Cualquier programa del PC puede agotar el límite de 30 impresiones por minuto.** El límite es
  global: un programa local que mande 30 peticiones por minuto deja al CRM con `rate_limited` hasta
  que pasa el minuto. Una página web de otro sitio **no** puede hacerlo (la guarda de origen la
  rechaza antes de contar). Si `rate_limited` aparece sin estar imprimiendo mucho, busca en el
  Administrador de tareas qué programa habla con `127.0.0.1:17888`
  (`Get-NetTCPConnection -RemotePort 17888 | ForEach-Object { Get-Process -Id $_.OwningProcess }`).
