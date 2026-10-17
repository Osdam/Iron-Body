# Desinstalación · Servicio de impresión de recibos de Iron Body

## Antes de desinstalar

En el CRM, en ese PC y perfil de Chrome, vuelve el modo de impresión al anterior (navegador). Si no,
el CRM seguirá intentando hablar con el servicio y mostrará que no responde.

## Desinstalar

Doble clic en **`uninstall.cmd`** (está en la carpeta extraída del ZIP y también en
`C:\Program Files\IronBody\ReceiptPrinter`) y acepta el aviso de Windows (UAC).

El desinstalador:

1. detiene el servicio `IronBodyReceiptPrinter`,
2. lo elimina (`sc.exe delete`),
3. borra `C:\Program Files\IronBody\ReceiptPrinter`,
4. **conserva** `C:\ProgramData\IronBody\ReceiptPrinter` (ajustes con la clave de estación y logs).

Conservar los ajustes permite reinstalar más tarde sin volver a registrar la estación en el CRM.

### Borrarlo todo

```bat
uninstall.cmd -Purge
```

Además borra `C:\ProgramData\IronBody\ReceiptPrinter` y el origen del Visor de eventos. Tras esto,
una reinstalación genera una **clave de estación nueva** que un administrador debe registrar otra vez
en el CRM (y conviene desactivar la estación antigua).

Antes de tocar nada, `-Purge` revisa esa carpeta sin seguir enlaces: si ella, su carpeta padre o algo
dentro es una unión o un vínculo simbólico, **no desinstala** y lo explica (un enlace ahí podría llevar
el borrado, como administrador, a otra carpeta). Si está limpia la borra con `rmdir /s /q`, que elimina
los enlaces sin entrar en ellos.

El registro de la desinstalación se escribe en `%TEMP%` del administrador y, sin `-Purge`, se copia
después a `C:\ProgramData\IronBody\ReceiptPrinter\logs\uninstall-*.log`. La última línea de la
ventana dice dónde quedó.

## Comprobar

```powershell
Get-Service IronBodyReceiptPrinter   # debe decir que no existe
Test-Path 'C:\Program Files\IronBody\ReceiptPrinter'   # False
```

Y en Chrome, `http://127.0.0.1:17888/` ya no responde.

## Si el desinstalador no puede

En PowerShell como administrador:

```powershell
Stop-Service IronBodyReceiptPrinter -Force
sc.exe delete IronBodyReceiptPrinter
Remove-Item 'C:\Program Files\IronBody\ReceiptPrinter' -Recurse -Force
# Solo si se quiere borrar también la clave de estación y los logs. rmdir no entra en uniones ni
# vínculos simbólicos (Remove-Item -Recurse de Windows PowerShell 5.1 podría seguirlos):
cmd /c rmdir /s /q "C:\ProgramData\IronBody\ReceiptPrinter"
```

Si `sc.exe delete` responde que el servicio está «marcado para eliminación», cierra la consola de
Servicios (`services.msc`) y el Administrador de tareas; desaparece al cerrarlos o al reiniciar.
