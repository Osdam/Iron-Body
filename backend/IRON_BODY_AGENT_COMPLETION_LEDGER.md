
---

## Fase B — Herramientas operativas tipadas

Commits: `9ca29e1` (núcleo) · `43fdc4d` (once herramientas) · `5824381` (ejecutor + E2E).
Suite: **1941 pasan, 0 fallan** (+42). Desplegado con **todos los flags apagados**.

### 24. Núcleo de herramientas — **ENTREGADO** (13 pruebas)

Un único camino de ejecución. Todas las barreras viven en `ToolExecutor` y no
en cada herramienta, porque una barrera que hay que acordarse de poner es una
barrera que algún día falta.

Orden del pipeline: herramienta existe → flag encendido → argumentos válidos →
**sin campos de más** → autorización → **se reclama la clave de idempotencia** →
ejecución con presupuesto de tiempo → acta cerrada.

Reclamar la clave **antes** de salir a la red es lo que impide el cobro
duplicado: un segundo intento encuentra el trabajo tomado aunque el primero
siga en vuelo.

**La decisión que sostiene el diseño:** los campos no declarados se **rechazan**,
no se ignoran. Un `amount` propuesto desde la conversación muere en el ejecutor
y queda registrado que se intentó.

**Excepción explícita** (`requiresAutonomy()`): ceder la conversación a una
persona cambia el mundo y aun así no depende de ningún flag. Si dependiera, un
cliente enfadado durante la fase de pruebas se quedaría hablando con un robot
justo cuando menos lo tolera.

### 25. Las once herramientas — **ENTREGADO** (20 pruebas)

Ninguna reimplementa nada: envuelven servicios ya probados.

| Dominio | Herramientas | Servicio encapsulado |
|---|---|---|
| Comercial | `list_plans`, `update_lead`, `escalate_to_human` | catálogo `Plan`, ficha de lead |
| Wompi | `create_payment_link`, `get_payment_status` | `WompiPaymentLinkService` |
| Membresías | `get_membership_status`, `ensure_member` | `MembershipService` |
| Agenda | `book_appointment` | `MarketingAppointmentService` |
| Facturación | `validate_invoice_data`, `get_invoice_status` | `ElectronicInvoice` (**solo lectura**) |
| App | `get_app_account_status` | `MembershipService` + ficha |

Reglas fijadas por prueba, cada una correspondiente a una forma concreta de
hacer daño real:

- El precio sale del catálogo. `create_payment_link` **solo** acepta `plan_id`.
- Sin catálogo activo no se ofrece nada.
- Un plan retirado del CRM mientras la conversación sigue abierta se detecta al
  ejecutar, no solo al validar.
- **No se crea socio sin pago confirmado** por la pasarela.
- **No se duplica una persona**: se busca por documento y teléfono.
- **Identidad ambigua → revisión humana.** No se elige ni se fusiona.
- Una referencia de pago ajena no revela nada (búsqueda cercada al sujeto).
- La hora de una cita se interpreta en **Neiva**, no en el UTC del servidor.
- Facturación es **solo lectura**: la emisión es acción fiscal sensible.
- «Tiene cuenta pero no ve su membresía» **escala**, en vez de pedir registrarse
  otra vez y crearle un segundo usuario.

### 26. Ejecutor de decisiones — **ENTREGADO**

`OpportunityExecutor` traduce una oportunidad a una llamada concreta y registra
decisión, razón, herramienta, argumentos validados, resultado, estado, error,
reintentos y objetivo siguiente.

- La **política de contacto se recomprueba pegada al efecto**: la decisión pudo
  tomarse hace horas y entre medias la persona pudo pedir que no le escriban.
- Clave de idempotencia = oportunidad **+ número de intento**: un reintento del
  mismo intento no duplica; el seguimiento de la semana siguiente sí ejecuta.
- El intento **solo se cuenta cuando hubo efecto**. Contar los fallos agotaría
  los intentos de alguien sin haberle escrito nunca.

### 27. Recorridos E2E — **ENTREGADO** (9 pruebas)

Con dobles, sin datos productivos, con `Http::preventStrayRequests()`.

| Recorrido | Estado |
|---|---|
| A. Desconocido → socio → membresía → app → factura → objetivo siguiente | **PASS** |
| B. Mensual + uso demostrado → oportunidad de mejora | **PASS** |
| B'. Pagó y no vino → **no** se le ofrece plan más largo | **PASS** |
| C. Enlace abandonado → recuperación sin duplicar cobro | **PASS** |
| D. Vencida → reactivación que empieza por entender | **PASS** |
| E. Pide humano → IA detenida y oportunidades bloqueadas | **PASS** |
| F. Pasarela caída → fallo reintentable, cero transacciones a medias | **PASS** |
| F'. Facturación caída → solicitud preservada | **PASS** |
| Dos hechos simultáneos → una sola operación final | **PASS** |

### 28. Verificación en producción

| Comprobación | Resultado |
|---|---|
| Migración `commercial_tool_invocations` | Aplicada |
| Herramientas cargadas | 11 |
| **Expuestas al modelo** | **1** (`escalate_to_human`) |
| Flags comerciales | todos `false` |
| `META_ENABLED` | `false` |
| API / IRON GUARD | 200 / sin incidentes |

Que solo una herramienta esté expuesta con todos los flags apagados es la
verificación que importa: la **única** capacidad del agente hoy es ceder la
conversación a una persona.

### 29. Pendiente tras la Fase B

| Área | Estado |
|---|---|
| Inbox V2 (Fase D) | **TODO** — ya desbloqueado |
| Supervisión y métricas (Fase E) | **TODO** |
| Verificación visual (Fase F) | **TODO** |
| Herramientas de reprogramar/cancelar cita, upgrade y renovación | **TODO** — el patrón está; falta escribirlas |
| Emisión de factura desde el agente | **NO SE HARÁ** sin decisión explícita: acción fiscal sensible |
