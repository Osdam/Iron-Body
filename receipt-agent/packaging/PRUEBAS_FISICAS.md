# Pruebas físicas — DIG-58IIK con el servicio de impresión Iron Body

Estas pruebas se hacen en el PC de recepción, con la impresora real, **en este orden**. Cada una
gasta muy poco papel.

**No pases a la siguiente si la anterior falla.**

**Si la impresora PITA: DETENTE.** No repitas la prueba. Anota en qué prueba ocurrió y sigue la
§ «Si pita».

Ninguna de estas pruebas crea ventas, cobros ni movimientos de inventario:

- Las pruebas 1 a 5 imprimen documentos de prueba que fabrica el servidor en memoria. Llevan la
  marca `*** PRUEBA · SIN VALOR ***`.
- La prueba 6 reimprime una operación que ya existe.

Anota cada resultado en la tabla del final.

---

## Antes de empezar (sin gastar papel)

| Comprobar | Cómo |
|---|---|
| Fuente de alimentación | Es la original de **12 V / 2,6 A**, enchufada a la pared, no a una regleta saturada. **Nunca** un cargador USB. |
| Cable | USB directo al PC, sin hub. |
| Papel | Rollo térmico de 57/58 mm con la **cara térmica hacia el cabezal**. Para saber cuál es: raspa con la uña; la cara que se oscurece es la térmica. Rollo no casi agotado. |
| Tapa | Cerrada hasta oír el clic, con el papel asomando. |
| Luces | Encendida sin luz de error (STATUS apagada o fija según el modelo, sin parpadeo). |
| Windows | La impresora aparece en «Impresoras y escáneres». La página de prueba de Windows **ya salió** (hecho). |
| Servicio | En el navegador del PC, `http://127.0.0.1:17888/` responde «en funcionamiento». |
| CRM | Configuración › Pagos › Impresora de recibos → «Servicio de impresión Iron Body» dice **Conectado**. La estación está registrada, la impresora elegida es la DIG-58IIK / POS-58, con 32 columnas, `cp850` y avance de 4 líneas. |

---

## PRUEBA 0 — Autoprueba de la impresora (sin PC, ~10 cm de papel)

1. Apaga la impresora.
2. Mantén pulsado **FEED** y enciéndela.
3. Suelta FEED cuando empiece a imprimir.

**Esperado:** sale la hoja de configuración del equipo (firmware, interfaz, páginas de código,
fuente, caracteres por línea), completa y sin pitar.

**Anota de esa hoja:**
- caracteres por línea;
- si aparece «Cutter»;
- la página de código por defecto;
- si menciona «Chinese»/«GB18030».

**Por qué:** separa hardware de software. Si aquí pita o se corta, el problema es de alimentación,
papel, tapa o cabezal, no del CRM. **DETENTE** y ve a § «Si pita».

## PRUEBA 1 — Mínima por el servicio (~3 cm)

En el CRM: Impresora de recibos › **Prueba mínima**.

**Esperado:**

```
          IRON BODY        (más alto, en negrita)
          PRUEBA POS
              OK           (negrita)
```

- Después avanza unos 2 cm (4 líneas) y se detiene.
- No pita y no corta.
- El CRM dice «Aceptado por el sistema».

**Qué demuestra:** que el camino Iron Body → Windows (RAW) → USB → impresora funciona con unos
60 bytes de texto, sin imágenes.

Si la prueba de Chrome pitaba y esta no, la causa estaba en la ruta raster del navegador y el
driver. Esa ruta ya no se usa.

### PRUEBA 1B — Página completa (~12 cm)

En el CRM: **Página de prueba completa**.

Comprueba:
- **regla** `12345678901234567890123456789012` en **una sola línea** (si se parte, el equipo no tiene
  32 columnas: cambia a 42 o anota lo que dice la autoprueba);
- `Izquierda` y `Derecha` pegadas a los dos bordes;
- `á é í ó ú ñ Ñ ¿? ¡!` y `Á É Í Ó Ú ü Ü` legibles.

**Si las tildes salen como símbolos:**
1. Cambia la página de códigos a `cp437`, «Guardar y aplicar», y repite la 1B.
2. Si siguen mal, prueba `ascii` (sin tildes).

Si salen caracteres chinos, avísanos: el servicio envía `FS .` para cancelar ese modo, pero
conviene confirmarlo.

## PRUEBA 2 — Recibo corto de muestra (1 producto, ~9 cm)

**Recibos de muestra › 1 producto.**

**Esperado:**
- `*** PRUEBA · SIN VALOR ***` arriba y abajo;
- encabezado con IRON BODY WORKOUT, NIT, fecha, caja y cajero;
- una línea de producto, total y medio de pago;
- leyenda;
- la tirilla mide **solo lo necesario**: nada de papel en blanco al final, salvo el avance para
  cortar.

## PRUEBA 3 — Cinco productos (~13 cm)

**Recibos de muestra › 5 productos.**

**Esperado:** cinco líneas con cantidad × precio e importes alineados a la derecha, la línea del
descuento, el TOTAL en negrita y dos medios de pago.

Es más larga que la 2 en proporción a sus líneas.

## PRUEBA 4 — Veinte productos (~35 cm)

**Recibos de muestra › 20 productos.**

**Esperado:**
- los nombres largos se parten en varias líneas, sin cortarse a media palabra;
- **ningún corte a mitad**, sin pitido;
- avance final normal.

Es la prueba de carga: si la impresora tiene un problema de alimentación o de temperatura,
aparece aquí.

## PRUEBA 5 — Pago de membresía de muestra (~11 cm)

**Recibos de muestra › Membresía.**

**Esperado:** miembro, concepto, plan, vigencia `dd/mm/aaaa - dd/mm/aaaa`, valor pagado, saldo
pendiente y medio de pago.

## PRUEBA 6 — Reimpresión desde el historial (operación real ya existente)

1. Caja › Historial de cierres › Ver detalles de un turno.
2. En «Operaciones del turno», usa «Imprimir» de **una** fila.

**Esperado:**
- sale solo el recibo de esa operación con `*** REIMPRESIÓN ***`;
- **no** se abre ningún diálogo ni página A4;
- «Descargar PDF» sigue funcionando;
- no aparece ningún botón «Imprimir» general.

**No cobra, no mueve inventario y no cambia el cierre:** el recibo se lee de la operación que ya
existía.

---

## Pruebas de fallo controlado (tras pasar la 1–6)

| # | Qué haces | Esperado |
|---|---|---|
| F1 | Apagas la impresora y pulsas «Prueba mínima». | En unos 15–25 s el CRM dice «La impresora no responde…». El servicio espera 12 s a que la impresora tome el trabajo y luego lo cancela: **no** queda nada atascado en la cola de Windows. Al encenderla **no** sale sola. |
| F2 | Abres la tapa o quitas el papel y pulsas «Prueba mínima». | Error, o «Aceptado por el sistema» sin papel. Anota cuál de los dos: la DIG-58IIK puede no avisar a Windows. Cierra la tapa y usa «Prueba mínima» de nuevo. |
| F3 | Detienes el servicio (`services.msc` → «Iron Body · Impresora de recibos» → Detener) y pulsas «Prueba mínima». | El CRM dice que el servicio no responde. Al iniciarlo, vuelve a «Conectado». |
| F4 | Reinicias Windows y abres el CRM. | El servicio arranca solo; «Conectado» sin hacer nada. |

## Prueba con cobros reales (solo cuando el negocio cobre de verdad, no se fuerza)

Cuando la 1–6 y la F1–F4 estén bien, el administrador pone el equipo en modo «Servicio de
impresión Iron Body» (§ Activación del informe).

Con las **primeras ventas y cobros reales del día**, comprueba:
- la tirilla sale sola en Caja de productos y en Pagos del gimnasio;
- no aparece ningún diálogo;
- el aviso dice «Aceptado por el sistema»;
- una recepcionista no ve la opción de omitir;
- un usuario con el permiso «Permitir omitir la impresión de recibos» sí la ve.

---

## Si pita

1. **DETENTE.** No repitas la prueba ni reimprimas.
2. Anota:
   - en qué prueba fue;
   - cuántos pitidos y si se repiten;
   - qué luz está encendida o parpadea;
   - cuánto se imprimió.
3. Comprueba en este orden:
   1. **papel**: puesto, no agotado y con la cara correcta;
   2. **tapa**: bien cerrada;
   3. **temperatura**: el cabezal no está muy caliente; deja enfriar 5 minutos;
   4. **fuente**: la original de 12 V / 2,6 A, firme en el conector;
   5. **cable USB**: directo, sin hub; prueba otro puerto;
   6. **cola de Windows**: vacía, o cancela lo que haya;
   7. **driver**: el de la impresora.
4. Haz la **PRUEBA 0** (autoprueba).
   - Si también pita: es la impresora, su fuente o el papel. Garantía o soporte de Digital POS.
   - Si la autoprueba sale bien: avísanos con lo anotado y el archivo de log de
     `C:\ProgramData\IronBody\ReceiptPrinter\logs\` del día.

## Registro de resultados

| Prueba | Fecha / hora | ¿Salió completa? | ¿Pitó? | Estado en el CRM | Observaciones |
|---|---|---|---|---|---|
| 0 Autoprueba | | | | — | cols: · cutter: · codepage: |
| 1 Mínima | | | | | |
| 1B Completa | | | | | regla en 1 línea: · tildes: |
| 2 Un producto | | | | | |
| 3 Cinco productos | | | | | |
| 4 Veinte productos | | | | | |
| 5 Membresía | | | | | |
| 6 Reimpresión | | | | | |
| F1 Apagada | | | | | |
| F2 Sin papel | | | | | |
| F3 Servicio detenido | | | | | |
| F4 Reinicio | | | | | |
