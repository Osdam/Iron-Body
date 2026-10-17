# Guía: impresora de recibos DIG-58IIK en el PC de caja

> **MÉTODO ANTERIOR (navegador).** Esta guía es la del 17-10-2026: imprimir desde Chrome con `--kiosk-printing`. En la prueba física la DIG-58IIK se cortó y pitó con este método. El método recomendado es el **servicio de impresión Iron Body** (ESC/POS): ver `IRON_BODY_POS_FINAL_REPORT.md` y el paquete `receipt-agent/` (`packaging/INSTALACION.md`, `packaging/PRUEBAS_FISICAS.md`). Esta guía se conserva para los equipos que sigan con el método anterior.

No se instala ningún programa de Iron Body. Solo hace falta el driver de la impresora, Chrome y un acceso directo.

## 1. Impresora en Windows

1. Conecta la DIG-58IIK por USB e instala el driver del fabricante. Si Windows no la reconoce, usa el driver que trae la caja o el del proveedor; el «POS-58» genérico de Xprinter suele servir.
2. Configuración → Impresoras: marca la DIG-58IIK como **predeterminada**.
   - Desactiva «Permitir que Windows administre la impresora predeterminada»: si queda activado, Windows puede cambiarla por otra.
3. En «Preferencias de impresión» del driver:
   - papel: rollo de 58 mm;
   - márgenes: 0;
   - si existe la opción, «Cortar / avanzar al final del documento».
4. Imprime una página de prueba de Windows. Si sale, la impresora funciona.

## 2. Acceso directo de Chrome para la caja (sin diálogo de impresión)

Crea un acceso directo nuevo en el escritorio con este destino (ajusta la ruta de Chrome si está en otro sitio):

```
"C:\Program Files\Google\Chrome\Application\chrome.exe" --kiosk-printing --user-data-dir="C:\IronBodyCaja\Chrome" https://ironbodyneiva.cloud/
```

- `--kiosk-printing`: imprime directamente en la impresora predeterminada, sin diálogo.
- `--user-data-dir`: crea un perfil de Chrome **solo para la caja**. Así la opción funciona aunque haya otro Chrome abierto, porque solo actúa en un Chrome que arranca de cero.
- **Atención:** con ese acceso directo, **cualquier** cosa que se imprima sale por la térmica. Para imprimir otra cosa en carta, usa el Chrome normal.

Inicia sesión en el CRM desde ese acceso directo; la sesión es por perfil.

## 3. Configurar el equipo en el CRM (una vez, con un administrador)

1. Desde el acceso directo de la caja: Configuración › Pagos › «Impresora de recibos».
2. Modo del equipo: **«Impresión directa»** si usas el acceso directo con `--kiosk-printing`. «Con diálogo» pregunta «¿Salió la tirilla?» en cada recibo.
3. «Imprimir página de prueba». Debe salir:
   - una regla de 32 columnas en **una sola línea**;
   - «Izquierda……Derecha» pegado a los dos bordes;
   - `á é í ó ú ñ Ñ ¿? ¡!` legibles.
4. El modo se guarda en ese perfil de Chrome (es por equipo). Recepción no tiene acceso a Configuración: debe hacerlo un administrador en ese mismo acceso directo.

## 4. Pruebas físicas (piloto)

| # | Prueba | Esperado |
|---|---|---|
| 1 | Venta de un producto en Caja | Sale la tirilla sola, sin diálogo |
| 2 | Venta de varios productos con descuento | Líneas, descuento y total correctos; columnas alineadas |
| 3 | Cobro de una membresía | Plan, vigencia e importe |
| 4 | Abono parcial | Saldo anterior y saldo actual |
| 5 | Recepcionista (sin permiso) | No ve la casilla; la tirilla sale siempre |
| 6 | Administrador al que se le dio el permiso | Ve la casilla marcada; si la desmarca no sale nada y el cierre dice «Omitido» |
| 7 | Impresora apagada al cobrar | El CRM dice «Recibo enviado» (Windows lo encoló): **limitación conocida**. Al encenderla, normalmente sale. Si no sale, «Reimprimir» desde el aviso o desde el cierre |
| 8 | Papel agotado | Igual que la anterior: el navegador no lo detecta. Cambia el rollo y reimprime desde el cierre |
| 9 | Historial de cierres → Ver detalles | No hay «Imprimir» arriba; «Descargar PDF» funciona; «Imprimir» de una fila saca solo esa operación con «REIMPRESIÓN» |
| 10 | Tildes y ñ | Legibles. Si salen símbolos raros, el driver no tiene la fuente: prueba con otro driver |
| 11 | Avance y corte | Sin metros de papel en blanco al final; corte manual con la sierra |
| 12 | Las dos cajas | Gimnasio y productos imprimen desde el mismo equipo |

## 5. Contingencia (sin perder cobros)

- **El cobro manda.** Si la tirilla no sale, el cobro **ya está registrado**: no cobres otra vez.
- Usa «Reintentar» en el aviso, o el cierre del turno → «Imprimir» en esa fila.
- Si la impresora no funciona durante un rato: sigue cobrando. Los recibos quedan pendientes (el turno muestra «N recibos pendientes») y se reimprimen después desde el cierre.

## 6. Rollback

- **CRM:** volver a publicar el build anterior (`11100fb`). La caja vuelve a funcionar exactamente como antes, sin tirillas.
- **Backend:** `git revert` del commit y avance directo. La migración solo crea tablas nuevas; puede quedarse o revertirse con `migrate:rollback --step=1`. No toca datos de cobros.
- **PC de caja:** borrar el acceso directo o quitar `--kiosk-printing`.
