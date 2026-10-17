# IRON_BODY_POS_FINAL_REPORT — Impresión térmica DIG-58IIK por servicio local ESC/POS

**Fecha:** 17-10-2026.

**Estado: READY_FOR_WINDOWS_PILOT.**

- Todo lo que se puede comprobar sin el PC de recepción está implementado, probado y revisado:
  - backend, CRM y servicio;
  - integración real Laravel ↔ servicio ↔ CRM;
  - Chrome real.
- **Backend y CRM DESPLEGADOS en producción el 17-10-2026** (autorizado por el propietario; §27).
  Para nadie cambia nada hasta que un administrador registre la estación de caja: el modo agent es
  por equipo.
- **El servicio NO está instalado en el PC de caja.**
- Falta lo que exige hardware real: Windows real (spooler, servicio, instalador), el permiso de red
  local de Chrome frente al dominio público y la impresora física. Ese piloto es `PRUEBAS_FISICAS.md`.

**Ramas (sin push):**
- monorepo `Iron-Body`: `feat/receipt-agent`, base `05e74b6`, la que corre en producción;
- CRM `Iron-Body_Front`: `feat/receipt-agent`, base `55ac7e5`, la que corre en producción.

**Componente Windows:** `IronBody-Receipt-Printer-Windows-x64.zip` versión **1.0.0** (protocolo 1).
La ruta y el SHA-256 están en §20–§21.

---

## 1. Diagnóstico del problema anterior

**Ruta anterior (desplegada el 17-10 en `55ac7e5`, y que sigue ahí como «método anterior»):**

```
CRM → iframe oculto → window.print()
    → Chrome (con o sin --kiosk-printing)
    → driver de Windows «POS-58»
    → spooler → USB → DIG-58IIK
```

Chrome no manda texto a la impresora: **pinta una página** del tamaño de papel elegido. El driver
la convierte en una imagen de 384 puntos de ancho a 203 ppp y la envía como raster.

**Lo que pasó en la prueba física (relato del propietario):**

1. Windows reconoció la impresora.
2. La página de prueba de Windows salió.
3. Chrome mostró el recibo y la impresora empezó a imprimir.
4. Se interrumpió y pitó; la tirilla quedó incompleta.
5. La cola de Windows quedó vacía.

Se probó con los formatos `58(48) x 210 mm` y `58(48) x 3276 mm`.

**Datos del fabricante** (ficha oficial de Digital POS, descargada el 17-10-2026):
- térmica directa, 48 mm imprimibles, **384 puntos por línea**, 90 mm/s;
- USB + RJ11 para el cajón;
- emulación ESC/POS;
- **Fuente A 12×24 → 32 columnas**; **Fuente B 9×17 → 42 columnas**;
- CHN 24×24, es decir, **tiene modo chino**;
- **búfer de entrada de 32 KB**;
- fuente de alimentación de **12 V / 2,6 A**;
- **sin cortador**;
- **no publica** tabla de páginas de código ni comandos de estado.

El driver oficial («DIG-58IIK Driver_V7.17») da 404 en la web del fabricante. El PC usa el genérico
«POS-58».

## 2. Causas confirmadas

| # | Hecho | Evidencia |
|---|---|---|
| C1 | La ruta anterior manda una **imagen**, no texto. Su tamaño lo fija el formato de papel, no el contenido. | Una página de 58×210 mm a 203 ppp son unos 80 KB de raster; una de 58×3276 mm, unos 1,25 MB. La impresora tiene **32 KB de búfer**. Un recibo real por la ruta nueva ocupa **903 bytes**: venta real del E2E, 23 líneas. |
| C2 | «Cola vacía» **no** significa «impreso». El spooler retira el trabajo al entregarlo al puerto USB. | Semántica del spooler. Es coherente con lo observado: la cola quedó vacía con la tirilla a medias. |
| C3 | Desde un navegador no se sabe si salió papel. | `thermal-print.service.ts` (`55ac7e5`). |
| C4 | Con modo chino y página de códigos desconocida, un texto con tildes sin preparar puede salir con caracteres chinos o símbolos. | Ficha: CHN 24×24. El servicio envía `FS .` y `ESC t n` en cada trabajo. |

**El motivo exacto del pitido NO está confirmado:** no hay manual de alarmas y la prueba no dejó
registro. Lo deciden las pruebas físicas (§3, §24).

## 3. Hipótesis pendientes

Las discrimina `PRUEBAS_FISICAS.md`.

| # | Hipótesis | Qué la confirma o descarta |
|---|---|---|
| H1 | **Alimentación insuficiente** en los picos del raster: fuente distinta de 12 V / 2,6 A o conector flojo. La impresora se reinicia y pita. | Prueba 0 (autoprueba) y prueba 4 (20 productos). |
| H2 | **Pérdida de sincronía del flujo raster** grande, con 32 KB de búfer y un driver genérico. | Prueba 1: 101 bytes de texto. Si sale bien donde Chrome fallaba, la causa estaba en la ruta raster, que ya no se usa. |
| H3 | **Sensor de papel o tapa** (rollo casi agotado, tapa mal cerrada, papel al revés). | Comprobaciones previas y prueba 0. |
| H4 | **Sobretemperatura** por la densidad del raster. | Prueba 4. |
| H5 | **Opción del driver** de zumbador o cajón al final. Explicaría el pitido, no el corte. | Preferencias del driver. El servicio no compone por el driver: manda RAW. |

**No se asume** que ESC/POS cure el pitido. Si H1 o H3 son ciertas, también pitará; por eso el plan
empieza por la autoprueba y se detiene al primer pitido.

## 4. Arquitectura definitiva

```
CRM ANGULAR (Chrome, https://ironbodyneiva.cloud)
  1. cobra             → endpoints de cobro SIN CAMBIOS (las cajas no se reescribieron)
  2. POST /api/admin/receipts                 → recibo inmutable (existente)
  3. POST /api/admin/receipts/{uuid}/prints   → intento + agent_ticket (32 bytes, un uso, 120 s)
  4. POST http://127.0.0.1:17888/v1/tickets  {ticket}          ← fetch sin credenciales
        SERVICIO LOCAL WINDOWS (IronBodyReceiptPrinter, solo 127.0.0.1)
        5. POST https://api…/api/receipt-agent/redeem {ticket, station_key, agent}
              ← líneas YA MAQUETADAS por Laravel desde el snapshot congelado
        6. ESC/POS propio → spooler RAW → USB → DIG-58IIK (seguimiento ≤ 12 s)
        7. POST https://api…/api/receipt-agent/report {ticket, outcome}
     ← {state: accepted|error, error_code, message, reported}
  8. si reported=false → PATCH /api/admin/receipts/{uuid}/prints/{attempt} (existente)
```

- **Laravel decide qué se imprime.** El navegador nunca manda texto al servicio. El servicio solo
  acepta líneas del backend, por TLS, a cambio de un ticket válido.
- **El ticket es la autorización:**
  - de un solo uso y caduca;
  - ligado a una estación registrada y a un intento abierto;
  - en la base solo queda su SHA-256.
- **Nada toca dinero:** ni cobros, ventas, abonos, inventario, turnos ni Factus. Comprobado con
  conteos antes y después en las pruebas y en el E2E.
- **Aceptado ≠ impreso.** El CRM dice «Aceptado por el sistema» y nunca «Impreso».

Contrato completo: `CONTRATO_SERVICIO_IMPRESION.md`.

## 5. Tecnología seleccionada

| Criterio | A. Servicio propio .NET 8 (**elegido**) | B. QZ Tray |
|---|---|---|
| Windows | Servicio nativo, autocontenido, arranque automático, reinicio en fallo. | Aplicación Java con JRE incluido; corre en la sesión del usuario. |
| ESC/POS | Comandos propios y cerrados (§11): imposible enviar bytes arbitrarios. | RAW genérico: un mensaje firmado puede mandar cualquier byte a cualquier impresora. |
| Impresión silenciosa | Sí. Un único permiso de red local por perfil de Chrome, o por política. | **Solo con certificado de confianza de pago.** Con las claves de demostración muestra avisos. |
| Seguridad | Loopback; Host y Origin exactos; ticket emitido por Laravel; contenido traído del backend; sin secretos en el equipo ni en el navegador. | wss://localhost con certificado local; firma con clave privada en el backend; más superficie. |
| Licencia y coste | Propio; 0. | LGPL 2.1. Gratis **con avisos**. Premium **USD 599–749 al año** (cifras públicas distintas en buy.qz.io y qz.io); Company Branded USD 2.999–3.499. Renovación anual. |
| Mantenimiento | Mismas herramientas que LocalGym (.NET 8), pero componente **independiente**. Pruebas en el Mac. | Dependencia de terceros. |

**Fuentes:**
- [Ficha DIG-58IIK](https://www.digitalinc.com.co/DIG-58IIK.php)
- [QZ: precios](https://qz.io/)
- [QZ: licencia](https://qz.io/docs/licensing)
- [QZ: firma de mensajes](https://github.com/qzind/tray/wiki/Signing)
- [Chrome Local Network Access](https://developer.chrome.com/blog/local-network-access)

## 6. Seguridad implementada

**Servicio local:**
- Escucha **solo** en `127.0.0.1:17888`.
- Guardas, en orden:
  1. IP remota loopback;
  2. `Host` exacto, contra DNS rebinding;
  3. `Origin` exacto: `https://ironbodyneiva.cloud` y `https://www.ironbodyneiva.cloud`;
  4. preflight con `Access-Control-Allow-Private-Network`;
  5. `Content-Type: application/json`;
  6. cabecera `X-IronBody-Client: crm`, que fuerza el preflight;
  7. cuerpo ≤ 16 KB;
  8. 30 POST y 120 GET por minuto;
  9. `no-store` y `nosniff`.
- Nunca envía credenciales CORS.
- Rechaza **antes** de procesar, es decir, no confía solo en CORS.
- Solo imprime en **impresoras locales**: rechaza `\\host\cola`, URL, puertos de red (por nombre **y por el monitor de Windows** del puerto), RDP y `FILE:`.
- El ESC/POS lo genera el propio servicio. Un texto (nombre de producto o de cliente) **no puede**
  producir bytes de control: probado con ESC, GS, DLE, NUL, CR/LF y emojis.
- El ticket nunca aparece en logs, diagnósticos ni nombre del documento.
- Logs sin nombres de clientes ni importes.

**Laravel:**
- `/api/receipt-agent/*` sin sesión, autenticado solo por el ticket.
- Respuesta idéntica para un ticket inexistente, revocado o mal formado: no hay oráculo.
- Canje y reporte en transacción con `lockForUpdate`, en orden intento → ticket → estación.
- Ningún rechazo escribe.
- Solo JSON con UTF-8 válido.
- Textos del agente saneados: controles, bidi y anchura cero.
- `Cache-Control: no-store` en las respuestas con ticket.
- Límite propio de 60 por minuto y red.
- Interruptor `RECEIPT_AGENT_ENABLED` y protocolo mínimo.

**RBAC:**
- Imprimir: los permisos de la caja del recibo, como antes.
- Administrar estaciones, emitir tickets de prueba o configuración y ver eventos: `cash.gym.manage`
  o `cash.products.manage`.
- Omitir: `receipts.print_optional`, validado **solo en el servidor**.
- **Ningún permiso nuevo** y ninguna regla por nombre de rol.

**CRM:**
- `fetch` nativo con `credentials:'omit'`, `redirect:'error'` y `no-referrer`. Nunca `HttpClient`,
  cuyos interceptores añadirían el token de sesión.
- El ticket no se guarda ni se registra.
- Todo el texto del servicio o del backend se pinta por interpolación, sin `innerHTML`.

## 7. Integración Angular

En `src/app/shared/receipts/`:

- **`receipt-agent.client.ts`:** cliente del servicio. Distingue «no instalado o apagado», «Chrome
  bloqueó el acceso» (vía Permissions API) y respuesta malformada. Tiempos: estado 2,5 s, tickets
  30 s.
- **`receipt-printer.service.ts`:** modo nuevo `agent`, por equipo, en el `localStorage` del perfil
  de Chrome.
  - `afterCharge` y `reprint` **no cambiaron de firma**: las 6 pantallas de cobro no se tocaron.
  - En modo agent:
    1. estado del servicio, cacheado 60 s;
    2. `registerPrint(print_mode:'agent', station_key)`;
    3. ticket al servicio;
    4. si `reported=false`, PATCH de respaldo.
  - Nunca usa `window.print` y nunca reintenta solo.
  - Si falla, el aviso dice «El pago fue registrado correctamente, pero el recibo no pudo
    imprimirse.», el motivo y Reintentar.
  - Si se acepta, el aviso dura 15 s con «¿No salió completa? Reimprimir».
- **Estados:** `Pendiente`, `Enviando`, `Aceptado por el sistema`, `Error`, `Omitido`, y los
  rótulos REINTENTO N y REIMPRESIÓN.
- **Modos anteriores:** `silent` y `dialog` siguen exactamente igual, como «método anterior».

## 8. Integración Laravel

- **Migración** `2026_10_17_000001_create_receipt_agent_tables`, aditiva y reversible:
  - tabla `receipt_stations`;
  - tabla `receipt_agent_tickets`;
  - en `receipt_prints`, las columnas `station_id` y `dispatched_at`.
  - Conserva el índice parcial «un solo original»; en SQLite lo rehace.
  - Medida en PostgreSQL 14: 26 ms.
- **`ReceiptTextLayout`:** port del maquetador del CRM, idéntico línea a línea en 50 casos frente
  al TS real. Admite 32 y 42 columnas. Garantías comprobadas: ≤ columnas, NFC, sin controles.
  - Producción **no tiene ext-intl**: se usa `nfcSinIntl()`, que tiene sus propias pruebas forzadas.
- **Servicios:** `ReceiptAgentTickets` (emitir, canjear, reportar, revocar) y `ReceiptStations`
  (alta, edición, eventos y tickets de prueba o configuración, auditados).
- **Controladores:** `ReceiptAgentController` (público) y `ReceiptStationController` (admin).
- **`ReceiptController`:**
  - `startPrint` admite el modo agent;
  - endpoint nuevo `GET /receipts/pending`.
- **Estado y vistas:**
  - `Receipt::printState()` añade `sending`;
  - `ShiftReceipts` expone `last_attempt` por fila;
  - `ReceiptPrint` admite solo la transición extra «despacho».
- **Encabezado:** `CAJA_RECEIPT_BUSINESS_NAME` pasa a valer por defecto **«IRON BODY WORKOUT»**,
  como pidió el propietario. Los recibos ya emitidos conservan su snapshot.

## 9. Servicio local Windows

- .NET 8 autocontenido, `win-x64`, un solo archivo `IronBody.ReceiptPrinter.exe`.
- Servicio de Windows `IronBodyReceiptPrinter` («Iron Body · Impresora de recibos»):
  - inicio automático;
  - depende de `Spooler`;
  - reinicio en fallo (5 s, 10 s, 60 s);
  - cuenta `NT AUTHORITY\LocalService`.
- Impresión RAW por `winspool.drv`. Sigue el trabajo hasta 12 s:
  - si el trabajo queda en error o no sale, **se cancela** para que no salga horas después y duplique
    una reimpresión.
- Cola de un solo trabajador, con dedupe de tickets en vuelo.
- Tope de **≤ 24,75 s** por ticket: canje 8 s + seguimiento 12 s + reporte con lo que quede. Queda
  por debajo de los 30 s del CRM.
- Ajustes en `%ProgramData%\IronBody\ReceiptPrinter\settings.json` (sin secretos).
- Logs JSON de 14 días y Visor de eventos.
- **Instalador:** `install.cmd`/`install.ps1` como administrador. Ver §22 y `INSTALACION.md`.
  - Solo admite instalar en `Program Files`.
  - Fija ACL sin herencia.
  - Aborta si en ProgramData hay enlaces o contenido creado por otra cuenta.
  - Verifica el SHA-256 del exe ya copiado.
  - Deja la ruta del servicio entre comillas.

## 10. Configuración desde el CRM

**Configuración › Pagos › Impresora de recibos.** La primera opción es «Servicio de impresión Iron
Body (recomendado)». El panel muestra:

- **Conexión:** estado del servicio, versión, protocolo y compatibilidad. Si no está instalado,
  instrucciones de instalación y del permiso de Chrome.
- **Estación:** clave abreviada, registro con nombre, activar o desactivar.
- **Impresora:** lista de las impresoras **locales** de Windows con las térmicas primero, columnas
  (32/42), página de códigos (`cp850`/`cp437`/`ascii`) y avance final (0–8). «Guardar y aplicar»
  hace PATCH de la estación, pide un ticket de configuración y el servicio lo aplica.
- **Pruebas:**
  - mínima (IRON BODY · PRUEBA POS · OK);
  - página completa;
  - **recibos de muestra sin valor**: 1, 5 y 20 productos y membresía. Son sintéticos, marcados
    `*** PRUEBA · SIN VALOR ***`, y no crean ventas ni tocan inventario.
- **Estado:** impresora, último trabajo, cola y errores recientes, del servidor y del equipo.
- **Recibos pendientes (48 h):** con Reintentar, que hace una reimpresión.
- **Permisos:** enlace a Configuración › Usuarios y roles › «Recibos de caja».

Sin `cash.*.manage` el panel se ve en solo lectura. Cada estación tiene su impresora; un trabajo
nunca va a otra estación, porque el ticket está ligado a la clave de la estación.

## 11. Impresión dinámica (ESC/POS)

**Cabecera:**

```
ESC @ · FS . (cancela el modo chino) · ESC t 2 (CP850) · ESC M 0 (Fuente A, 32 col.) · ESC 2
```

**Por línea:** alineación, negrita y tamaño explícitos, más el texto y LF. El título va a doble alto,
centrado.

**Final:** `ESC d 4`. **Sin corte** (`GS V`), porque la ficha no declara cortador. No hay raster, ni
páginas, ni longitudes fijas: el papel mide las líneas más el avance.

**Medido en el E2E**, sobre los archivos ESC/POS reales que generó el servicio:

| Documento | Bytes | Líneas | Papel aprox. (3,75 mm/línea + avance) |
|---|---|---|---|
| Prueba mínima | 101 | 3 | ≈ 3 cm |
| Muestra 1 producto | 875 | 22 | ≈ 10 cm |
| Venta real, 2 productos | 903 | 23 | ≈ 10 cm |
| Membresía real | 924 | 23 | ≈ 10 cm |
| Muestra 5 productos | 1.322 | 34 | ≈ 14 cm |
| Muestra 20 productos | 3.069 | 82 | ≈ 32 cm |

Las tildes van en CP850, que tiene las mismas posiciones que CP437 para las minúsculas. Hay
alternativas `cp437` y `ascii` seleccionables desde el CRM. **El ancho real (32 columnas) y la
página de códigos se confirman en papel** en las pruebas 0 y 1B.

## 12. Caja de productos

Venta → recibo → ticket → servicio → impresora → reporte. Probado en el E2E con el endpoint real
`POST /api/admin/caja/sales`.

**Lo que no ocurre al imprimir:** no se crea otro cobro, no se descuenta inventario y no hay
movimientos nuevos. Lo verifican los conteos.

**Casos con prueba:**
- un producto y varios;
- descuento;
- varios medios de pago;
- con y sin cliente;
- fiado (recibo `product_credit_sale` emitido por el servidor);
- anulada («*** OPERACIÓN ANULADA ***»);
- reimpresión.

Un pago fallido o no imprimible no lleva recibo: 422 `not_printable`, como antes.

## 13. Pagos del gimnasio

Mismo flujo para:
- membresía y renovación (`POST /api/payments`, probado en el E2E);
- abonos (con saldo anterior y actual);
- cobro de varias deudas (un recibo por caja);
- plan a plazos («Pagado hoy» y «Saldo pendiente»).

Factus no se toca. La factura se **referencia** («Ref. factura electrónica N») solo si ya existe;
reimprimir no emite nada.

## 14. Roles y permisos

- **Sin `receipts.print_optional`:** la tirilla sale siempre. No hay casilla para omitir, y omitir
  por la API da 403, queda registrado como `denied` y se audita.
- **Con el permiso:** casilla «Imprimir recibo» marcada por defecto. Omitir queda `skipped` con su
  autor.
- **Dónde se asigna o retira, a cualquier rol:** Configuración › Usuarios y roles › «Recibos de
  caja» › «Permitir omitir la impresión de recibos».
- **Efecto en caliente:** el cambio vale en la siguiente petición, sin desplegar nada. Probado.
- Producción tiene hoy el permiso solo en los 3 Super Admin.

## 15. Reimpresión individual

Caja › Historial de cierres › Ver detalles › «Operaciones del turno» › **Imprimir** en una fila:

- sale solo ese comprobante con `*** REIMPRESIÓN ***` arriba y abajo;
- no crea pagos, no toca inventario, balances ni el cierre;
- «Descargar PDF» se conserva;
- no hay botón general de impresión.

El reintento tras un error en el cobro se rotula `*** REINTENTO N ***`. La reimpresión desde el
informe de cierre se probó en el E2E.

## 16. Recuperación de errores

**Estados** (el servidor decide el rótulo):
- PENDIENTE
- ENVIANDO (el servicio lo tomó y aún no hay resultado)
- ACEPTADO POR EL SISTEMA
- ERROR
- REINTENTO N
- REIMPRESIÓN
- OMITIDO

**Confirmación física: NO disponible.** La DIG-58IIK por USB y spooler no devuelve estado del papel
por esta vía. Se documenta, y el CRM ofrece «¿No salió completa? Reimprimir».

**Códigos de error con mensaje para el cajero:**
- impresora apagada o sin respuesta (`job_timeout`, `printer_offline`): el trabajo se cancela y no
  sale después;
- sin papel;
- servicio detenido;
- backend inaccesible;
- ticket caducado o usado;
- estación inactiva;
- y el resto de §6 del contrato.

**El cobro siempre queda.** El recibo aparece en «Recibos pendientes» y en el cierre del turno, y se
reintenta sin cobrar de nuevo. Los tickets son idempotentes: un ticket repetido no imprime.

**Límite conocido:** si se corta la conexión justo cuando el servicio imprimía, puede ser imposible
saber si salió el papel. El intento queda «Enviando» o «error», y el cajero comprueba antes de
reimprimir.

## 17. Pruebas automatizadas

| Componente | Resultado |
|---|---|
| Laravel, `tests/Feature/Caja` | **566/566** en SQLite y **566/566** en **PostgreSQL 14.24**. |
| Laravel, suite completa | **8.993 pruebas**. Por nombre contra la línea base (`05e74b6`): **0 fallos nuevos**. Los 55 errores + 3 fallos existían antes: Factus, Reports y Classes, ajenos a esto. 116 pruebas nuevas, todas en verde. |
| CRM, suite completa (vitest) | **1.756 en verde**. Los 16 fallos son **idénticos por nombre** a la línea base `55ac7e5`. Más de 80 pruebas nuevas. Build de producción sin errores. |
| Servicio .NET (xUnit, en el Mac) | **488/488** en verde, 0 advertencias. |
| E2E real en el Mac | **62/62** (`e2e.py`): Laravel con SQLite sintética, servicio .NET real e impresora de archivo; el guion hace de CRM. Se repitió con el backend final y con el servicio endurecido; tras reiniciar el servicio se conservaron la clave de estación y la impresora. |
| Chrome real | El `fetch` del CRM contra el servicio pasa preflight y CORS, y el ticket se acepta (un archivo impreso). |
| Mutaciones | Se rompieron a propósito las protecciones clave (backend ≥ 16 casos, servicio 30, CRM 5) y **todas** hicieron fallar su prueba. |

**Todo con datos sintéticos.** Ninguna venta real y nada en producción.

## 18. Resultados de seguridad

| Revisión | Bloqueantes | Importantes | Menores | Estado |
|---|---|---|---|---|
| API Laravel (`api-security-specialist`) | 0 | 0 | 6 | M-1…M-5 corregidos con prueba. M-6 es de diseño: «aceptado» no prueba papel; documentado. |
| Migración y concurrencia (`database-specialist`) | 0 | 1 (procedimiento) | 6 | Migrar con `lock_timeout=5s` (§29). Bloqueo de estación y `internal_error` corregidos. El resto, documentado. |
| Canal local, instalador y CRM (`security`) | 0 | 2 | 6 | Los dos importantes (ACL de `InstallDir` y ProgramData con uniones) corregidos. Menores 1, 2, 3, 5 y 6 corregidos. El 4 (límite agotable por un proceso local) aceptado y documentado. |
| Revisión final (`reviewer`) | 0 | 2 | 4 | **Aprobado con reservas.** Los 2 importantes se corrigieron: avisos que Recepción no podía cerrar con el servicio caído, y mensajes y comentario del interruptor que mandaban al navegador. También los 4 menores: rótulo del cierre tras un reintento fallido, docblock fuera de sitio, método muerto y comentario del tope de tiempo. |
| Re-revisión del instalador endurecido (`security`) | 0 | 1 | 4 | IMPORTANTE-A corregido: el instalador **aborta** si `ProgramData\IronBody` tiene contenido de otra cuenta, en vez de adoptarlo. MENOR-C (desinstalar solo borra una carpeta que contiene el exe) y MENOR-D (PowerShell por ruta completa) corregidos. MENOR-A queda cubierto por el aborto. MENOR-B (`ASPNETCORE_PREFERHOSTINGURLS` abre otra escucha; solo puede ponerla un administrador) documentado: aun así, la primera guarda `not_loopback` rechaza lo que no venga del propio equipo. PowerShell 5.1: 0 construcciones incompatibles. |
| Revisión automática del commit | — | 1 | — | **Diferencial de interpretación** en `LocalPrinterPolicy`: la política decidía si un puerto es de red por su NOMBRE (`IP_…`, `WSD-…`) y Windows lo decide por su MONITOR. Un puerto TCP/IP estándar renombrado («CAJA») pasaba por local. Corregido en `6459d96`: `EnumPortsW` nivel 2 da el monitor de cada puerto; se rechazan los de red (TCP/IP, WSD, LPR, IPP, Internet, HTTP) al listar, al configurar y justo antes de imprimir. Si Windows no da la lista, siguen las reglas por nombre. 11 pruebas nuevas; quitar la regla hace fallar 5. |

## 19. Versión del componente Windows

`1.0.0`, protocolo `1`, .NET 8.0.

En `VERSION.txt` van la fecha UTC, el SHA-256 del exe y el commit de origen.

## 20. Ruta exacta del paquete

- `/Users/alejodev/Documents/Dev/IRON_BODY/IronBody-Receipt-Printer-releases/1.0.0-2026-10-17/IronBody-Receipt-Printer-Windows-x64.zip`
- `/Users/alejodev/Documents/Dev/IRON_BODY/IronBody-Receipt-Printer-releases/1.0.0-2026-10-17/SHA256SUMS.txt`

Copia del build: `Iron-Body/receipt-agent/dist/`. Se regenera con `build-windows.sh`.

## 21. Hash SHA-256

| Archivo | Bytes | SHA-256 |
|---|---|---|
| `IronBody-Receipt-Printer-Windows-x64.zip` | 41.771.328 | `00f3ac2029a620aeb344154af843f030590de66a9b746be062b3aabac2ed7e5b` |
| `IronBody.ReceiptPrinter.exe` (dentro del ZIP) | 47.141.639 | `6ac600036f474e9fbdabed88593e81d51dd3cb7dfbe31d768797eb71ff26dc2b` |

## 22. Guía de instalación en Windows (resumen)

Detalle en `INSTALACION.md`, dentro del ZIP.

1. Windows 10/11 x64.
2. El driver de la DIG-58IIK / POS-58 está instalado y la página de prueba de Windows sale.
3. Copiar el ZIP a `Descargas`, extraerlo y ejecutar **`install.cmd` como administrador**. Instala
   en `C:\Program Files\IronBody\ReceiptPrinter`, crea y arranca el servicio y comprueba
   `http://127.0.0.1:17888/`.
4. En el navegador del PC, `http://127.0.0.1:17888/` debe decir «en funcionamiento».
5. Chrome 142+: la primera vez que el CRM hable con el servicio, Chrome pedirá permiso para
   **«acceder a apps y servicios de este dispositivo»** → **Permitir**.
   - Alternativa por política de empresa: `LocalNetworkAccessAllowedForUrls`, y desde Chrome 146
     `LoopbackNetworkAllowedForUrls`, con `https://ironbodyneiva.cloud`. Comprobar en
     `chrome://policy`.
6. Si el driver rechaza RAW (`spooler_error`), instalar el driver **«Generic / Text Only»** en el
   mismo puerto USB y elegir esa impresora en el CRM.

**Desinstalar:** `uninstall.cmd` como administrador. Conserva ajustes y logs salvo con `-Purge`.
Ver `DESINSTALACION.md`.

## 23. Guía de configuración en el CRM

Un administrador con `cash.*.manage`, **en el mismo perfil de Chrome que usará la caja**:

1. Configuración › Pagos › Impresora de recibos › «Servicio de impresión Iron Body».
2. «Volver a comprobar» → debe decir **Conectado**. Si no, ver las instrucciones del propio panel.
3. «Registrar este equipo», con un nombre, p. ej. «Recepción».
4. Impresora: la DIG-58IIK / POS-58, **32 columnas**, **cp850**, avance **4** → «Guardar y aplicar».
5. Hacer las pruebas físicas (§24), **en orden**.
6. Solo cuando pasen: dejar seleccionado «Servicio de impresión Iron Body (recomendado)» en ese
   equipo. Desde ahí, cada cobro imprime solo.

## 24. Checklist de pruebas físicas

Detalle en `PRUEBAS_FISICAS.md`, dentro del ZIP y en `receipt-agent/packaging/`.

| # | Prueba | Papel |
|---|---|---|
| 0 | Autoprueba de la impresora (FEED al encender), sin PC | ≈ 10 cm |
| 1 | Prueba mínima: IRON BODY / PRUEBA POS / OK | ≈ 3 cm |
| 1B | Página completa: regla de 32 col., tildes, bordes | ≈ 12 cm |
| 2 | Muestra 1 producto | ≈ 9 cm |
| 3 | Muestra 5 productos | ≈ 13 cm |
| 4 | Muestra 20 productos (prueba de carga) | ≈ 35 cm |
| 5 | Muestra membresía | ≈ 11 cm |
| 6 | Reimpresión desde el Historial de cierres | ≈ 10 cm |
| F1–F4 | Apagada, sin papel, servicio detenido, reinicio de Windows | mínimo |

Reglas:
- No pasar a la siguiente si la anterior falla.
- **Si pita: DETENERSE** y seguir la guía de comprobación (papel, tapa, temperatura, fuente de
  12 V / 2,6 A, USB, cola, driver, autoprueba).
- Anotar cada resultado en la tabla de `PRUEBAS_FISICAS.md`.

## 25. Rollback

| Capa | Cómo | Efecto |
|---|---|---|
| Equipo de caja | En el CRM, volver el equipo a «método anterior», o desinstalar el servicio con `uninstall.cmd`. | Vuelve exactamente a lo de hoy. |
| Interruptor (sin desplegar) | `RECEIPT_AGENT_ENABLED=false` en el `.env` del backend. Producción no usa `config:cache`, así que se lee en caliente. | Emitir y canjear tickets responde 503. El CRM muestra el error y el cobro sigue. |
| CRM | Volver a publicar `55ac7e5`: `rsync` sin `--delete` y `chown root:root`. | Desaparece el modo agent. |
| Backend | `git revert` del commit, avance directo, y `PGOPTIONS='-c lock_timeout=5s' php artisan migrate:rollback --step=1 --force` **tras respaldar** `receipt_stations`, `receipt_agent_tickets` y las columnas nuevas de `receipt_prints` (SQL en §29). | Las tablas y columnas nuevas se van; las filas previas de `receipt_prints` quedan idénticas (md5 comprobado). |

Nada de esto toca cobros, ventas, inventario ni facturas.

## 26. Commits y archivos modificados

Commits **locales**, sin push: monorepo `Iron-Body` → `6738bfa` (backend), `b001909` + `6459d96` (receipt-agent) y el commit de documentación que contiene este informe; CRM `Iron-Body_Front` → `c09c58c`.

**Backend** (`Iron-Body/backend`):

| Tipo | Archivos |
|---|---|
| Modificados | `ReceiptController`, `Receipt`, `ReceiptPrint`, `AppServiceProvider` (limitador `receipt-agent`), `ReceiptComposer` (`business()` pública y `titleFor()`), `ReceiptPrints`, `ShiftReceipts`, `AuthorizationMap`, `config/caja.php`, `routes/api.php`, `ThermalReceiptIssueTest`, `ThermalReceiptPrintTest`. |
| Nuevos: código | `ReceiptStationController`, `ReceiptAgentController`, `ReceiptAgentTicket`, `ReceiptStation`, `Rules/PlainText`, `ReceiptAgentTickets`, `ReceiptStations`, `ReceiptTextLayout`, `Support/Caja/UntrustedText`, migración `2026_10_17_000001`. |
| Nuevos: pruebas | `BuildsReceiptAgent`, `ReceiptAgentFlowTest`, `ReceiptAgentSecurityTest`, `ReceiptStationsTest`, `ReceiptTextLayoutTest`. |
| Docs | Este informe, `CONTRATO_SERVICIO_IMPRESION.md`, y la nota de «método anterior» en `GUIA_PC_CAJA_IMPRESORA.md` y `DIGITAL_POS_INTEGRATION_REPORT.md`. |

**Servicio** (`Iron-Body/receipt-agent/`, nuevo): `src/IronBody.ReceiptAgent/`, `tests/`,
`packaging/` (instaladores y guías), `build-windows.sh` y `README.md`.

**CRM** (`Iron-Body_Front`):

| Tipo | Archivos |
|---|---|
| Modificados | `cash-shift-card.ts`, `cash-shift-report.ts` y su spec, `settings.ts`, `receipt-contract.spec.ts`, `receipt-printer-settings.ts`, `receipt-printer.service.ts`, `receipt-snapshot.ts`, `receipt-status.ts`, `receipts-api.service.ts`. |
| Nuevos | `receipt-agent.client.ts`, `receipt-agent-panel.ts`, `receipt-pending-list.ts`, `receipt-agent-labels.ts` y 4 specs. |

**No se tocaron:**
- las cajas (`caja.ts`, `payments.ts`, `plan-upgrade-modal.ts`, `receivables.ts`, `debtor-cards.ts`,
  `commissions.ts`);
- Factus, biometría, EmployeeAccess, jornadas, clases, planes y LocalGym.

## 27. Estado de producción

**Desplegado el 17-10-2026 entre las 12:26 y las 12:31 UTC (07:26–07:31 en Neiva), con autorización
expresa del propietario. No había ningún turno de caja abierto.**

| Paso | Resultado |
|---|---|
| Respaldo previo | `/var/backups/ironbody/pre-servicio-impresion-20261017T122650Z`: volcado de 30 MB (196 tablas), CRM publicado, HEAD de la API y `SHA256SUMS`. |
| Backend | `origin/main` `05e74b6` → `462b2f2`, con avance directo en `/var/www/api` y propietario `www-data` en los 27 archivos del backend; `php -l` correcto. |
| Migración | `PGOPTIONS='-c lock_timeout=5s' … migrate --force`: `2026_10_17_000001_create_receipt_agent_tables` en **67 ms**, lote 116. No había transacciones largas. |
| Esquema verificado | El índice parcial «un solo original» conserva su `WHERE kind = 'original'`. Existen `receipt_stations` y `receipt_agent_tickets`, y `receipt_prints` tiene `station_id` y `dispatched_at`. Los 9 intentos y el recibo previos siguen igual. |
| Sondas | `redeem` con un ticket falso da 404 `ticket_invalid`; con `text/plain`, 415. Las rutas de estaciones y de pendientes sin sesión dan 401. `health` responde 200. |
| Producción sin ext-intl | Un recibo real existente, maquetado como reimpresión a 32 columnas, pasa `assertPrintable` (32 líneas, ≤ 32 columnas). Conserva su encabezado congelado; los recibos nuevos dirán «IRON BODY WORKOUT». Interruptor activo y protocolo mínimo 1. |
| CRM | `origin/main` `55ac7e5` → `c09c58c`. Build `npm run build:prod` sellado `c09c58c` con `dirty:false` (`main-2F5EVYC4.js`), publicado con `rsync` sin borrar archivos y `chown root:root`. El `main` anterior (`main-ELAS3VTA.js`) sigue en 200 para las pestañas abiertas. `app-source` avanzado (conserva el `package-lock.json` modificado que ya tenía). |
| Salud tras el despliegue | 0 errores de Laravel, **0 respuestas 5xx en 1.085 peticiones** y 12 workers RUNNING. |

**Hallazgo adyacente, anterior y no tocado:** `receivable.notify_failed` (WARNING, 46 veces en el log,
la última a las 06:00 UTC) por una violación de NOT NULL al crear el aviso de deuda «vence hoy».

**Pendiente en el PC de recepción:** instalar el paquete (§20–§22), configurar la estación (§23) y
hacer las pruebas físicas (§24). Sin eso, la caja sigue imprimiendo por el método anterior.

## 28. Riesgos pendientes

1. **Hardware sin verificar.** Pitido o corte por alimentación, papel o cabezal (H1, H3, H4): lo
   decide la prueba 0.
2. **Página de códigos y ancho reales** de la DIG-58IIK. La ficha no los publica; se confirman en la
   prueba 1B y se puede cambiar a `cp437` o `ascii` desde el CRM.
3. **Windows real sin verificar:**
   - winspool con el driver POS-58 (alternativa: «Generic / Text Only»);
   - el servicio como LocalService;
   - ACL e `icacls` con uniones;
   - `sc.exe`;
   - Windows PowerShell 5.1: los `.ps1` solo se parsearon con PowerShell 7.4.6.
4. **Chrome 142+.** El permiso de red local con el dominio público solo se ve en el PC real. Si se
   deniega, el CRM lo explica; se concede en Configuración del sitio o por política.
5. **Impresora apagada.** Con USB, Windows suele dejar el trabajo en cola sin marcarlo como error. El
   servicio lo cancela a los 12 s (`job_timeout`). Una impresora que acepta datos sin papel puede
   dar «aceptado»; se ve en F2.
6. **Sin confirmación física.** «Aceptado» no prueba papel (M-6). Mitigación: el aviso de 15 s con
   «Reimprimir» y la marca REINTENTO N.
7. **Suplantación del puerto 17888** por otro proceso de una cuenta de Windows del mismo PC antes de
   que arranque el servicio (MENOR-3). Mitigado con arranque `auto`. La solución de fondo, un secreto
   de estación verificado por el backend, queda como mejora v2.
8. **Límite local agotable:** un proceso local puede gastar los 30 POST/min (MENOR-4). Aceptado.
9. **Paquete sin firma Authenticode.** El instalador verifica el SHA-256 del exe. Publicar el hash
   por un canal distinto del ZIP, por ejemplo este informe.
10. **Retención:** `receipt_agent_tickets` crece con una fila por impresión, sin política de
    retención. Volumen pequeño; queda pendiente.
11. **Adyacentes, ya documentados el 17-10 y no tocados:**
    - el informe de cierre del gimnasio pinta al cajero como «Socio» en abonos;
    - los pedidos de la app cobrados en mostrador no entran en el arqueo;
    - «Marcar pagado» daría 403 a Recepción;
    - el estilo global `p, small {color !important}` deja en gris el resultado de la página de
      prueba del método anterior.

## 29. Procedimiento de activación final

**Pasos 1–3 HECHOS el 17-10-2026 (§27). Los siguientes son en el PC de recepción.**

1. **Despliegue del backend.** Respaldo previo como el del 17-10.

   Comprobación previa, solo lectura:

   ```sql
   SELECT pid, usename, state, now() - xact_start AS en_transaccion, left(query, 80)
   FROM pg_stat_activity
   WHERE datname = current_database() AND xact_start IS NOT NULL AND pid <> pg_backend_pid()
   ORDER BY xact_start;
   ```

   Despliegue:
   - `git merge --ff-only` en `/var/www/api`;
   - `PGOPTIONS='-c lock_timeout=5s' sudo -u www-data php artisan migrate --force`. Si falla con
     55P03 no se aplicó nada: se repite.

   Comprobación posterior:

   ```sql
   SELECT indexdef FROM pg_indexes WHERE indexname = 'receipt_prints_one_original_idx'; -- conserva WHERE kind = 'original'
   SELECT to_regclass('receipt_stations'), to_regclass('receipt_agent_tickets');
   ```

   - **No usar** `config:cache` ni `route:cache`.
   - Sondas sin escritura: `POST /api/receipt-agent/redeem` con un ticket falso → 404
     `ticket_invalid`; las rutas de estaciones sin sesión → 401.
2. **Despliegue del CRM:** `npm run build:prod`, `rsync` **sin `--delete`** y `chown -R root:root`.
   Comprobar `version.json`.
3. **Hasta aquí no cambia nada para nadie:** el modo agent es por equipo.
4. **PC de recepción:**
   1. instalar el ZIP (§22) y verificar su SHA-256 (§21);
   2. el administrador configura la estación (§23);
   3. hacer las pruebas físicas 0 → 6 y F1–F4 (§24), anotando los resultados.
5. Si todo pasa: dejar el equipo en «Servicio de impresión Iron Body» y observar el primer día de
   cobros reales. En el cierre: «Aceptado por el sistema», sin pendientes inexplicados.
6. Solo con evidencia de los puntos 4 y 5 en **las dos cajas**, permisos comprobados y reimpresión
   funcional se puede declarar **PRODUCTION_VERIFIED**.
7. **Rollback** en cualquier momento: §25.
