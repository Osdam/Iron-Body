# DIGITAL_POS_INTEGRATION_REPORT — 16/17-10-2026

> **Sustituido por `IRON_BODY_POS_FINAL_REPORT.md` (17-10-2026).** Este informe describe la primera versión (impresión desde el navegador), que sigue desplegada y disponible como «método anterior». La impresión física por esa vía falló (corte y pitido); la integración definitiva usa el servicio local ESC/POS.

**Estado: READY_FOR_PRINTER_PILOT** — desplegado en producción el 17-10-2026 (backend `e7e5afc`, CRM `55ac7e5`) y verificado sin crear cobros. **No es PRODUCTION_VERIFIED**: falta el piloto con la impresora física y el PC de caja configurado (`GUIA_PC_CAJA_IMPRESORA.md`).

## 1. Arquitectura encontrada

- **Caja del gimnasio** (`/payments`): 7 flujos que crean dinero confirmado, en 4 controladores:
  - cobro o renovación (`PaymentController`);
  - confirmar pago pendiente;
  - mejora de plan;
  - plan a plazos (el dinero real es el primer abono);
  - abono a una deuda;
  - cobrar todo a un deudor;
  - piso de entrenador.
  El comprobante `REC-####` lo genera el navegador y no es fiable.
- **Caja de productos** (`/caja`): venta de mostrador, venta fiada y confirmar pedido de la app (`CajaController`). El código `V-000123` lo genera el servidor. El inventario se descuenta en `markPaid`/`markCredit`.
- **Cierres:** `CashShiftReport` es la única fuente de la pantalla y del PDF. El gimnasio lista cobros y abonos; productos lista ventas, pero no sus abonos (hallazgo previo). El botón «Imprimir» del informe era un `window.print()` del panel. El PDF se genera en el servidor.
- **RBAC:**
  - El catálogo de permisos sale de las rutas y de `CONTROLLER_ENFORCED`.
  - La pantalla de roles pinta ese catálogo.
  - Trampas: el token compartido recibe todo lo que termina en `*.view`; Administrador recibe por defecto cualquier permiso nuevo; Super Admin tiene todo por invariante.
- **Factus:** `ElectronicInvoice` polimórfico (Payment/ProductSale), emisión asíncrona. En un cobro recién hecho la factura sale `pending`, sin número.
- **No había impresión de recibos.** Los datos del negocio con valor real son `FACTUS_COMPANY_*`; los de la pantalla de configuración solo viven en `localStorage`.
- **Impresora:** Digital POS DIG-58IIK, USB, 58 mm, 48 mm imprimibles, puerto RJ11 para cajón, drivers de Windows. Velocidad y nombre coinciden con la Xprinter XP-58IIK. No encontré su manual de comandos oficial.

## 2. Cambios implementados

Por decisión del propietario **no hay agente de Windows**: todo se imprime desde el CRM.

- **Backend (Laravel):**
  - tablas nuevas (recibos, orígenes, numeración por caja, intentos de impresión);
  - recibo inmutable con los datos reales de la operación;
  - 6 endpoints;
  - permiso `receipts.print_optional`;
  - campos `source_type`/`source_id` añadidos al informe de cierre.
  - **No se tocó ningún endpoint de cobro**, ni Factus, ni el inventario, ni los cierres.
- **CRM (Angular):**
  - impresión automática tras confirmar en los 9 puntos de cobro y en la venta fiada;
  - casilla «Imprimir recibo», solo con permiso;
  - aviso de estado con reintento;
  - columna «Acciones» con reimpresión por operación en el cierre;
  - botón «Imprimir» del informe de cierre retirado (el PDF se conserva);
  - contador de pendientes en el turno;
  - tarjeta «Impresora de recibos» con página de prueba.

## 3. Módulos afectados

**Backend, nuevos:**
- migración `2026_10_16_000001_create_receipts_tables`;
- modelos `Receipt`, `ReceiptSource` y `ReceiptPrint`;
- `ReceiptException`;
- servicios `ReceiptSourceResolver`, `ReceiptComposer`, `ReceiptIssuer`, `ReceiptPrints`, `ReceiptPresenter`, `ShiftReceipts` y `ReceiptCandidate`;
- `ReceiptController`;
- 32 pruebas.

**Backend, modificados:** `routes/api.php`, `AuthorizationMap`, `CrmPermission`, `PermissionCatalog`, `CashShiftReport` (solo campos añadidos) y `config/caja.php`.

**CRM, nuevos:** en `shared/receipts/`, el formateador, la impresión en `iframe`, el adaptador, el cliente HTTP, el orquestador, el aviso, la casilla, la tarjeta de configuración y sus pruebas.

**CRM, modificados:** `payments.ts`, `plan-upgrade-modal.ts`, `receivables.ts`, `debtor-cards.ts`, `commissions.ts`, `caja.ts`, `cash-shift-report.ts`, `cash-shift-card.ts`, `api.service.ts`, `app.ts`/`app.html`, el enum de permisos y el catálogo regenerado.

## 4. Servicio de impresión

- **Cómo imprime:** `iframe` oculto con `srcdoc` y `sandbox="allow-same-origin allow-modals"`, **sin scripts** (verificado en Chrome: imprime, y el HTML inyectado no se ejecuta).
- **Papel:** se mide la altura de la tirilla y se fija `@page { size: 58mm <alto>mm; margin: 0 }`. El cuerpo mide 48 mm.
- **Letra:** 9 pt comprimida en horizontal para que quepan 32 columnas; negro sólido.
- **Impresiones seguidas:** van en cola.
- **Sin diálogo:** Chrome con `--kiosk-printing` y la DIG-58IIK como predeterminada (ver la guía). El driver de Windows rasteriza la página y envía los comandos a la impresora.
- **Estados honestos:**
  - Preparando e Imprimiendo.
  - **Enviado:** el navegador lo entregó a Windows.
  - **«¿Salió la tirilla?»:** en modo diálogo, porque cancelar también dispara `afterprint`.
  - Pendiente, Error y Sin recibo.
- **Lo que no se puede saber:** desde un navegador no hay forma de detectar papel agotado ni impresora apagada (Windows deja el trabajo en cola). **Nunca se marca «impreso físicamente».**

## 5. Configuración de roles

- **Dónde:** Configuración › Roles (catálogo del servidor), dominio «Recibos de caja», permiso **«Permitir omitir la impresión de recibos»** (`receipts.print_optional`).
- **Asignación:** se asigna o retira a cualquier rol.
- **Por defecto:**
  - no lo tiene nadie, **ni el Administrador**;
  - el Super Admin lo tiene siempre, porque lo impone el invariante del sistema.
- **Validación:** en el backend.

## 6. Impresión obligatoria (sin el permiso)

- **Automática** tras confirmar.
- **Sin casilla ni opción de omitir.**
- **Un error no se puede cerrar como completado:** solo «Reintentar», o «Dejar pendiente» cuando el servidor ya registró el fallo, y entonces queda pendiente en el cierre.
- **Intento de omitir:** si alguien lo intenta igualmente (llamando a la API), el backend responde 403, guarda el intento como «denegado» con su auditoría y **el CRM imprime igual**.

## 7. Impresión opcional (con el permiso)

- La casilla «Imprimir recibo» viene marcada por defecto.
- Si se desmarca antes de confirmar, se registra la omisión con quién y cuándo, y en el cierre sale «Omitido».

## 8. Formato de las tirillas

Siempre con datos reales:
- **Cabecera:** nombre, razón social, NIT, dirección y teléfono, tomados de la configuración de facturación.
- **Identificación:** número de recibo (`RG-000001` gimnasio, `RP-000001` productos), comprobante de la operación, fecha y hora de Bogotá, caja, cajero y **solo el nombre** del cliente.
- **Detalle:**
  - productos: cantidad × unitario y descuento;
  - membresías: plan, vigencia y mejora;
  - deudas: saldo anterior, saldo actual y fecha límite.
- **Totales:** subtotal, descuentos y TOTAL; el IVA aparece como «incluido» e informativo.
- **Pago:** medios con su referencia.
- **Leyenda:** «Factura electrónica N» si existe; si no, «Este documento no es una factura electrónica de venta».
- **Copias:** las reimpresiones llevan `*** REIMPRESIÓN ***` y los reintentos `*** REINTENTO N ***`.
- **Valor recibido y cambio: no se imprimen**, porque no existen en ninguna tabla. Queda como fase 2.

## 9. Historial de cierres

- **Arriba:** sin «Imprimir» y con «Descargar PDF».
- **«Operaciones del turno»:** columna «Acciones» con «Imprimir» por fila, que saca solo esa operación como tirilla marcada REIMPRESIÓN, y su estado (Impreso/Omitido/Error/Pendiente).
- **Filas no admitidas** (anuladas o revertidas): botón deshabilitado con el motivo.
- **Contador** de pendientes.

## 10. Reimpresión

Cada reimpresión es un intento nuevo sobre el **mismo recibo**, con la misma foto de datos. No crea pago, factura ni movimiento, y no toca el cierre.

## 11. Manejo de errores

- **Si la impresión falla:** el cobro ya está hecho y no se repite ni se revierte; el recibo queda pendiente; se puede reintentar.
- **Sin conexión al emitir:** el aviso dice «el cobro sí quedó registrado» y el recibo se recupera desde el cierre.
- **Duplicados:** los intentos son idempotentes (`client_attempt_id`), hay un único original por recibo (índice único parcial) y la numeración está bloqueada con `lockForUpdate`.
- Contingencia: ver la guía.

## 12. Pruebas ejecutadas

**Backend**
- 37 pruebas de recibos, en SQLite y en **PostgreSQL 14.24**, la misma versión que producción. Cubren:
  - emisión por cada tipo de operación, con totales que cuadran con el origen;
  - idempotencia y numeración correlativa por caja;
  - recibo inmutable;
  - permisos por caja: quien solo ve, no escribe;
  - omitir (con y sin permiso, Administrador, Super Admin);
  - original, reintento y reimpresión; un «checkout» tardío o una operación anulada nunca salen como original;
  - repetir el intento de otra persona da 409;
  - más de 50 abonos se rechazan antes de validar;
  - la clave de un «cobrar todo» tiene longitud fija;
  - venta sin turno imprimible;
  - abonos de productos marcados y con sus datos;
  - **conteos y firma de pagos, ventas, deudas, abonos, turnos, inventario y facturas intactos** tras cada llamada.
- Concurrencia real con procesos separados en PostgreSQL (hecha por el implementador):
  - numeración sin huecos ni duplicados;
  - cuatro peticiones del mismo cobro → un solo recibo.
- Caja y acceso (incluye la cobertura de autorización y la política de roles): **600/600**.
- Suite completa con la versión final: **8877 pruebas. Fallan las mismas 58 de la línea base, una a una (contratos de socio, facturación, entorno local y precios). Ninguna nueva.**

**CRM**
- **1.674 en verde y 16 fallos, los mismos 16 de la línea base, comparados por nombre.**
- `check:literals` y la compilación de producción, correctos.
- Qué cubren las pruebas:
  - formateador de 32 columnas, tildes y leyenda;
  - adaptador desde el recibo del servidor;
  - orquestador: orden de llamadas, omisión denegada que imprime igual, error sin repetir el cobro;
  - casilla oculta sin permiso;
  - enganche de punta a punta en la venta de caja y en el modal de cobro;
  - informe de cierre: sin «Imprimir», con el PDF, columna «Acciones», «Enviado», motivos traducidos, bloque de abonos de productos;
  - limpieza de avisos al cerrar sesión;
  - **contrato:** todo `source_type` que envía el CRM está en la lista del backend.
- `iframe` aislado verificado en un Chrome real (headless): imprime, no ejecuta HTML inyectado y genera un PDF de 58 × 143 mm.

**No verificado:** impresora física, Windows con `--kiosk-printing` y navegador real del PC de caja.

## 13. Seguridad

- **Revisión de seguridad de la API: sin bloqueantes.**
  - Ninguna caja accede a la otra (BOLA).
  - No hay escalada (BFLA).
  - El token de automatizaciones y los entrenadores quedan fuera.
  - Una omisión sin permiso queda registrada como denegada y auditada antes del 403.
  - No hay datos personales de más: solo el nombre del cliente.
  - Ninguna escritura en tablas financieras.
- **Hallazgos corregidos:**
  - F1: clave del recibo con huella SHA-256. Daba 500 en PostgreSQL en un «cobrar todo» grande.
  - S1: tope de 50 abonos antes de validar.
  - S2: repetir el intento de otra persona da 409.
  - S3: emitir, imprimir y omitir exigen operar o supervisar la caja; el original queda atado al momento y al estado de la operación.
  - Límite de 120 peticiones por minuto en las rutas de recibos.
- **Revisión final:** dio un bloqueante, ya corregido. La venta fiada enviaba un origen inexistente; ahora hay una prueba de contrato. Observaciones corregidas:
  - «Enviado» en vez de «Impreso»;
  - motivos traducidos;
  - leyenda siempre visible;
  - abonos de productos reimprimibles.
- **CRM:**
  - todo el texto del recibo se escapa;
  - el `iframe` va con `sandbox` sin scripts;
  - en `localStorage` solo se guarda el modo de impresión;
  - los avisos se vacían al cerrar sesión.

**Hallazgos anteriores a este trabajo (no corregidos, por estar fuera de alcance):**
1. `CajaController::pay()` no asigna turno: los pedidos de la app cobrados en mostrador no entran en ningún arqueo. La tirilla sí sale.
2. **El informe de cierre del gimnasio muestra al CAJERO como «Socio» en los abonos.** A la consulta le falta `member_id`. En los recibos está corregido; en el informe financiero no se tocó.
3. El informe de la caja de productos no lista sus abonos (ahora se ven y reimprimen en un bloque aparte).
4. `ProductSale::nextCode()` puede repetir código si dos ventas entran a la vez.
5. El comprobante `REC-####` del gimnasio lo genera el navegador y no es fiable. El recibo trae su propio número `RG-…`.
6. `POST /api/payments` no tiene clave de idempotencia.
7. «Marcar pagado» se ofrece a quien tiene `payments.create`, pero el backend exige `payments.cancel`, así que Recepción recibiría 403.
8. En `receivables.ts`, si la relectura falla tras un abono correcto, el mensaje invita a repetir y se registraría un abono nuevo.
9. El informe de cierre muestra la hora del navegador, no la de Bogotá, y el estado `reversed` sale en crudo.

## 14. Compatibilidad con Factus

- Sin cambios: solo se lee `ElectronicInvoice` para mostrar el número.
- Las pruebas comprueban que ninguna llamada de recibos crea ni modifica facturas.

## 15. Paquete Windows

No aplica: el propietario descartó el agente local. En su lugar hay una guía de configuración del PC de caja (sección 16).

## 16. Instalación (PC de caja)

Ver `GUIA_PC_CAJA_IMPRESORA.md`.

## 17. Estado de Git

- Backend: `e7e5afc` en `main` (avance directo desde `b62bb92`).
- CRM: `55ac7e5` en `main` (avance directo desde `11100fb`).
- Ramas: `feat/thermal-receipts`.

## 18. Estado de despliegue

- **Respaldo previo:** `/var/backups/ironbody/pre-recibos-termicos-20261017T033905Z` (volcado de 30 MB con 192 tablas, CRM publicado y huellas).
- **Backend:** `merge --ff-only` en el VPS; `migrate` aplicó `2026_10_16_000001_create_receipts_tables` (lote 115). No hacía falta caché de rutas ni de configuración (producción no la usa) ni reiniciar workers.
- **CRM:** build sellado `55ac7e5` publicado con `rsync` sin `--delete`; el `main` anterior sigue disponible para las pestañas abiertas; `app-source` avanzado.
- **Verificado en producción:**
  - 6 rutas; sin sesión, 401;
  - tablas vacías e índice parcial creado;
  - solo los 3 Super Admin activos tienen el permiso de omitir;
  - recibos de un cobro y de una venta reales compuestos dentro de una transacción deshecha (`RG-000001`, `RP-000001`, datos del negocio, NIT y hora de Bogotá), con 0 filas persistidas;
  - 0 errores 5xx y 0 errores en Laravel tras desplegar.
- **Rollback:**
  - backend: `git revert e7e5afc`, avance directo y `migrate:rollback --step=1` (solo tablas nuevas);
  - CRM: volver a publicar el build de `11100fb`.

## 19. Pruebas físicas pendientes

Las de `GUIA_PC_CAJA_IMPRESORA.md` §4. Hasta hacerlas no hay PRODUCTION_VERIFIED.
