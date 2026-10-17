# Rollback · Servicio de impresión de recibos de Iron Body

## Qué se puede romper y qué no

El servicio **solo imprime**. No interviene en cobros, ventas, abonos, inventario, turnos ni
facturación: esos endpoints no cambiaron. Si el servicio falla o se quita:

- los cobros se registran igual;
- los recibos siguen existiendo en el servidor con su número (`RG-…`/`RP-…`);
- un recibo que no se imprimió aparece como PENDIENTE o ERROR y se puede reimprimir desde el CRM.

Por eso el rollback consiste en **dejar de usar el servicio en ese equipo**, no en deshacer datos.

## Rollback de un equipo (lo normal)

1. **CRM**: en ese PC y perfil de Chrome, vuelve el modo de impresión al anterior (navegador,
   silencioso o con diálogo). Desde ese momento el CRM no habla con el servicio.
2. **Servicio**, una de dos:
   - parar sin desinstalar (reversible en segundos), en PowerShell como administrador:
     ```powershell
     Stop-Service IronBodyReceiptPrinter
     Set-Service IronBodyReceiptPrinter -StartupType Disabled
     ```
     Para volver: `Set-Service IronBodyReceiptPrinter -StartupType Automatic; Start-Service IronBodyReceiptPrinter`
     (queda como lo deja el instalador: inicio automático, no retrasado).
   - o desinstalar con `uninstall.cmd` (ver DESINSTALACION.md). Conserva la clave de estación.
3. **Recibos pendientes**: revisa en el CRM la lista de recibos pendientes de las últimas horas y
   reimprime con el modo del navegador los que hagan falta.
4. Opcional, un administrador puede **desactivar la estación** en el CRM: el servidor dejará de
   emitir permisos de impresión para ella aunque el servicio siga instalado.

## Rollback de todos los equipos a la vez

Es una decisión de servidor, no de este paquete: el interruptor `RECEIPT_AGENT_ENABLED=false` del
backend hace que emitir y canjear permisos devuelva `agent_disabled` en todas las estaciones. El CRM
muestra «La impresión con el servicio local está desactivada temporalmente». Los equipos deben volver
al modo del navegador (paso 1) para seguir imprimiendo.

## Volver a una versión anterior del servicio

Extrae el ZIP de la versión anterior y ejecuta su `install.cmd`: reemplaza el programa y conserva
`settings.json`. Si la versión anterior usa un protocolo que el servidor ya no admite, el CRM
mostrará «Este servicio de impresión es demasiado antiguo» (`protocol_unsupported`).
