# Instalación · Servicio de impresión de recibos de Iron Body 1.0.0

Guía para el técnico, en el PC de recepción donde está conectada la impresora térmica.

## Qué es

Un servicio de Windows (`IronBodyReceiptPrinter`) que imprime en la térmica **Digital POS DIG-58IIK**
los recibos que autoriza el CRM. Manda **texto + comandos ESC/POS** (1–3 KB por recibo) en modo RAW
por la cola de impresión de Windows; no rasteriza la página como hacía Chrome, que es lo que llenaba
el búfer de 32 KB de la impresora y la hacía cortarse y pitar.

- Escucha **solo en `127.0.0.1:17888`**: ningún otro equipo de la red puede usarlo.
- Solo atiende al CRM (`https://ironbodyneiva.cloud` y `https://www.ironbodyneiva.cloud`) y solo
  imprime lo que le entrega el servidor de Iron Body (`https://api.ironbodyneiva.cloud`) a cambio de
  un permiso de un solo uso.
- Solo imprime en impresoras **conectadas a este equipo** (USB, COM, LPT). Rechaza impresoras
  compartidas de otro equipo (`\\equipo\cola`), de red (puerto `IP_…`, `WSD-…`, una dirección IP),
  por internet (`http://…`) o redirigidas por Escritorio remoto (puerto `TS001`…): los recibos llevan
  datos de clientes y no deben salir del PC. Tampoco usa las que escriben a un archivo (`FILE:`,
  `PORTPROMPT:` como «Microsoft Print to PDF», `NUL:` o una ruta).
- **No toca cobros**, ventas, abonos, inventario, turnos ni facturación. Si se desinstala, la caja
  sigue funcionando igual que antes.
- «Aceptado por el sistema» significa que Windows recibió el trabajo y lo entregó al puerto USB.
  **No prueba que el papel saliera**: la DIG-58IIK no devuelve su estado por USB.

## Requisitos

| Requisito | Cómo comprobarlo |
|---|---|
| Windows 10 u 11 de 64 bits | Configuración → Sistema → Información → «Tipo de sistema: 64 bits» |
| Cuenta con permisos de administrador | La instalación pide confirmación (UAC) |
| Impresora conectada por USB, encendida y con papel | Luz encendida, tapa cerrada |
| Driver «POS-58» instalado y **página de prueba de Windows correcta** | Configuración → Bluetooth y dispositivos → Impresoras y escáneres → POS-58 → Imprimir página de prueba |
| Servicio «Cola de impresión» en ejecución | `Get-Service Spooler` en PowerShell → `Running` |
| Chrome 142 o posterior con el CRM | `chrome://settings/help` |
| El CRM se abre por `https://ironbodyneiva.cloud` o `https://www.ironbodyneiva.cloud` | Barra de direcciones de Chrome |
| Internet hacia `https://api.ironbodyneiva.cloud` | Abrir esa dirección en Chrome responde (aunque sea con un error JSON) |
| Puerto 17888 libre | Ver «Puerto ocupado» en SOLUCION_DE_PROBLEMAS.md |

Anota el **nombre exacto** de la impresora tal como aparece en Windows (por ejemplo `POS-58`):
el CRM lo pedirá al configurar la estación. En Propiedades de impresora → **Puertos**, la térmica USB
debe estar en un puerto `USB001`, `USB002`…: es lo que el servicio reconoce como impresora local.

Comprueba también en la impresora → Propiedades de impresora → **Opciones avanzadas** que esté
marcado «Usar la cola de impresión…» y **no** «Imprimir directamente en la impresora». Con impresión
directa, una impresora apagada puede bloquear el servicio hasta que Windows desista.

## Pasos

### 1. Descargar y comprobar el paquete

Copia al PC `IronBody-Receipt-Printer-Windows-x64.zip` y `SHA256SUMS.txt`. En PowerShell:

```powershell
Get-FileHash .\IronBody-Receipt-Printer-Windows-x64.zip -Algorithm SHA256
```

El valor debe coincidir con la línea del ZIP en `SHA256SUMS.txt`. Si no coincide, **no instales**.

### 2. Desbloquear y extraer

1. Clic derecho en el ZIP → Propiedades → si aparece **«Desbloquear»**, márcalo → Aceptar.
2. Clic derecho → **Extraer todo…** en **tu** carpeta `Descargas` (o el Escritorio de tu usuario).
   No lo extraigas en un USB, en `C:\Users\Public` ni en una carpeta compartida: otro usuario podría
   cambiar `install.ps1` entre que lo extraes y lo ejecutas, y ese script corre como administrador.
   No ejecutes nada desde dentro del ZIP sin extraer.

`install.ps1` vuelve a desbloquear los archivos del paquete, pero hacerlo antes evita el aviso de
SmartScreen. Además comprueba el **SHA-256** del programa y del desinstalador (las sumas van dentro de
`install.ps1`, puestas al generar el paquete) sobre la copia ya protegida en Program Files: si no
coinciden, no instala nada y el servicio que hubiera sigue igual.

### 3. Instalar

Doble clic en **`install.cmd`** y acepta el aviso de Windows (UAC). Se abre una ventana que:

1. comprueba los parámetros y que el paquete viene de `build-windows.sh`,
2. desbloquea los archivos del paquete,
3. revisa `C:\ProgramData\IronBody` **antes de escribir nada**: si dentro hay uniones o vínculos
   simbólicos, se detiene; si la creó otra cuenta, toma posesión para Administradores; y le pone
   permisos propios sin herencia (Usuarios solo lectura),
4. prepara `C:\Program Files\IronBody\ReceiptPrinter` con permisos explícitos (propietario
   Administradores, sin herencia) y comprueba ahí el SHA-256 del programa,
5. detiene el servicio si ya existía (actualización),
6. coloca el programa y la documentación,
7. escribe `appsettings.json` (puerto 17888, API y los dos orígenes del CRM),
8. crea o actualiza el servicio (inicio **automático**, depende de «Cola de impresión», se reinicia
   solo si falla, cuenta `NT AUTHORITY\LocalService`, ruta del ejecutable entre comillas),
9. lo inicia y comprueba que responde.

Al final muestra un resumen. **Anota la «Clave estación»** (un identificador como
`9e8efd16-b480-40de-89bb-9289bb2ae80b`): es la identidad de este PC para el CRM.

Parámetros opcionales (desde una consola en la carpeta extraída):

| Parámetro | Para qué |
|---|---|
| `-ServiceAccount LocalSystem` | Solo si el driver de la impresora no deja imprimir con LocalService |
| `-Port 17888` | No lo cambies: el CRM usa 17888 |
| `-ApiBaseUrl https://api.ironbodyneiva.cloud` | Otra API (pruebas) |
| `-AllowedOrigin "https://ironbodyneiva.cloud,https://www.ironbodyneiva.cloud"` | Orígenes del CRM (este es el valor por defecto); varios separados por comas, coincidencia exacta, `https://` obligatorio |
| `-InstallDir "C:\Program Files\IronBody\ReceiptPrinter"` | Otra carpeta de programa, **siempre dentro de `C:\Program Files`**: fuera (por ejemplo en `D:\`) heredaría permisos que dejan a cualquier usuario cambiar el ejecutable del servicio, y el instalador la rechaza |
| `-NoPause` | No esperar una tecla al terminar |

Ejemplo: `install.cmd -ServiceAccount LocalSystem`

El registro de la instalación se escribe primero en `%TEMP%` del administrador y, cuando la carpeta de
datos ya tiene sus permisos, se copia a `C:\ProgramData\IronBody\ReceiptPrinter\logs\install-AAAAMMDD-HHMMSS.log`.
La última línea de la ventana dice dónde quedó (si la instalación se detuvo antes, sigue en `%TEMP%`).

### 4. Comprobar en el navegador

Abre en Chrome: **http://127.0.0.1:17888/**

Debe mostrar: `Iron Body · Servicio de impresión de recibos 1.0.0 · en funcionamiento`

### 5. Registrar y configurar la estación en el CRM

Con un usuario que tenga `cash.gym.manage` o `cash.products.manage`:

1. Registra la estación con la **clave** del paso 3 y un nombre (por ejemplo «Recepción»).
2. Envía la configuración: impresora = nombre exacto de Windows (`POS-58`), **32 columnas**,
   página de códigos **cp850**, avance final **4** líneas.
3. Imprime la **prueba mínima** (IRON BODY / PRUEBA POS / OK).
4. Imprime la **prueba completa** y revisa en el papel:
   - la regla `1234567890…` cabe en **una sola línea** (si se parte, las columnas están mal),
   - se leen bien `á é í ó ú ñ Ñ ¿? ¡!` y `Á É Í Ó Ú ü Ü` (si no, prueba `cp437` o `ascii`),
   - el recibo termina con unas líneas en blanco para cortarlo en la barra.
5. En ese mismo PC y perfil de Chrome, activa el modo de impresión con el servicio local.

### 6. Permiso de Chrome para hablar con el servicio local

Desde Chrome 142, la primera vez que el CRM habla con `127.0.0.1` Chrome pregunta si
`ironbodyneiva.cloud` puede **acceder a apps y servicios de este dispositivo** (el texto exacto varía
con la versión). Pulsa **Permitir**.

Si el CRM se usa por las dos direcciones (con y sin `www`), Chrome pregunta por cada una: son sitios
distintos para el navegador.

Si alguien lo denegó: candado de la barra de direcciones en el CRM → **Configuración del sitio** →
permiso de acceso a la red local / apps y servicios del dispositivo → **Permitir**, y recarga.

En equipos administrados, mejor por política (Chrome la aplica sin preguntar):

| Política | Valor | Desde |
|---|---|---|
| `LocalNetworkAccessAllowedForUrls` | `https://ironbodyneiva.cloud` y `https://www.ironbodyneiva.cloud` | Chrome 139 |
| `LoopbackNetworkAllowedForUrls` | `https://ironbodyneiva.cloud` y `https://www.ironbodyneiva.cloud` | Chrome 146 |

En el registro de Windows (PowerShell como administrador):

```powershell
foreach ($p in 'LocalNetworkAccessAllowedForUrls', 'LoopbackNetworkAllowedForUrls') {
    $key = "HKLM:\SOFTWARE\Policies\Google\Chrome\$p"
    New-Item -Path $key -Force | Out-Null
    New-ItemProperty -Path $key -Name '1' -Value 'https://ironbodyneiva.cloud' -PropertyType String -Force | Out-Null
    New-ItemProperty -Path $key -Name '2' -Value 'https://www.ironbodyneiva.cloud' -PropertyType String -Force | Out-Null
}
```

Comprueba en `chrome://policy` (botón «Volver a cargar políticas») que ambas aparecen con estado OK.

### 7. Si Windows no acepta impresión directa (RAW)

Si al imprimir el CRM muestra «Windows no acepta impresión directa con este driver…», el driver
POS-58 no admite el tipo de datos RAW. Instala un segundo driver sobre el **mismo puerto USB**:

1. Panel de control → Dispositivos e impresoras → **Agregar una impresora** →
   «La impresora que deseo no está en la lista».
2. «Agregar una impresora local o de red con configuración manual».
3. **Usar un puerto existente**: el mismo que usa POS-58 (normalmente `USB001`; míralo en
   Propiedades de impresora → Puertos). Tiene que ser ese puerto USB local: el servicio rechaza una
   impresora con puerto de red.
4. Fabricante **Generic** → impresora **Generic / Text Only**.
5. Nombre: por ejemplo `POS-58 RAW`. No compartir.
6. En el CRM, vuelve a enviar la configuración con impresora = `POS-58 RAW` y repite las pruebas.

## Qué queda instalado

| Qué | Dónde |
|---|---|
| Programa | `C:\Program Files\IronBody\ReceiptPrinter\IronBody.ReceiptPrinter.exe` |
| Configuración de instalación | `C:\Program Files\IronBody\ReceiptPrinter\appsettings.json` |
| Desinstalador | `C:\Program Files\IronBody\ReceiptPrinter\uninstall.cmd` |
| Ajustes de la estación (clave, impresora, columnas) | `C:\ProgramData\IronBody\ReceiptPrinter\settings.json` |
| Logs (JSON por línea, 14 días) | `C:\ProgramData\IronBody\ReceiptPrinter\logs\` |
| Visor de eventos (avisos y errores) | Registros de Windows → Aplicación → origen `IronBodyReceiptPrinter` |
| Servicio | `IronBodyReceiptPrinter` («Iron Body · Impresora de recibos») |

`settings.json` no contiene secretos. Los logs no contienen nombres de clientes, importes ni
permisos de impresión.

## Actualizar a otra versión

Extrae el ZIP nuevo y ejecuta su `install.cmd`. Detiene el servicio, reemplaza el programa y
**conserva `settings.json`**: la estación sigue registrada en el CRM con la misma clave.
