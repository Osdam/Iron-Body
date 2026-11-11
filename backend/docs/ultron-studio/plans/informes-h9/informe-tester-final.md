# Hito 9 · Tester final · informe

**Fecha:** 10-11-2026.
**Backend:** `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h9`, sobre `d701b0c8`.
**CRM:** `_worktrees/ultron-h8/Iron-Body_Front`, rama `feat/ultron-studio-h9`, sobre `9572426`.
**Sin commit** y sin tocar el índice. **Producción:** nada. **Red:** ninguna.
**Ojo:** el worktree del backend tiene, a la vez, cambios sin confirmar de otros agentes (W9, V9, endurecimientos del H8 en
`app/`, `config/`, `routes/` y pruebas). No son míos y no los toqué; mis cambios son solo los de §A.7 y §B.7.
**Encargos:** A) los 56 rojos de la regresión completa; B) la prueba #26 (E2E del CRM de automatizaciones con navegador real).

---

## A. Los 56 rojos de la regresión completa

### A.1 Veredicto por familia

| Familia | Rojos | Clase | Causa exacta | Qué lo resuelve | Estado |
|---|---|---|---|---|---|
| `MemberContractTest` | 29 | **(a) entorno** | El `setUp` copiaba al disco falso los PDF **oficiales** leyéndolos de `storage/app/private/contract_templates/source/*.pdf` (`MemberContractTest.php:24-28` en HEAD). Esos PDF no se versionan (`storage/app/private/.gitignore` = `*`; `config/contracts.php`: «NO versionadas en git») y no existen en ninguna copia de esta máquina (`find ~/Documents/Dev/IRON_BODY -name 'basic_registration*.pdf'` → 0). Las 29 caían en el `setUp` sin probar nada. | Plantillas **sintéticas** generadas en el propio `setUp` con TCPDF (`plantillaSintetica()`): tamaño Letter, tantas páginas como declara `contracts.templates.<clave>.pages` (2, 2 y 1), un único texto «PLANTILLA SINTETICA DE PRUEBAS - <clave> - pagina n/N - no es un documento oficial». Ningún dato real, ningún binario nuevo en el repo. | **28 verdes + 1 omitida por diseño** (ver A.5) |
| `FiscalReconciliationTest` | 12 | **(a) entorno** | Las cuatro clases simulan `oauth/token` con `Http::fake`, pero no fijan `billing.credentials`; `FactusTokenManager::cred()` (`FactusTokenManager.php:133-139`) lanza «credencial 'client_id' no configurada» antes de pedir el token. Dependían de que el entorno trajera `FACTUS_*`: este worktree no tiene `.env`, y la máquina donde se escribió la línea base (20-09) tampoco las tenía. | `'billing.credentials' => ['username' => 'u', 'password' => 'p', 'client_id' => 'c', 'client_secret' => 's']` en el `config([...])` del `setUp`, con un comentario. Es la convención que ya usan las clases que pasan (`FactusV2IntegrationTest.php:34`, `StuckProcessingTest.php:56`). `Http::preventStrayRequests()` sigue en las cuatro: nada sale a la red. | **12 verdes** |
| `FactusPayloadNoVatTest` | 10 | (a) entorno | Ídem | Ídem | **10 verdes** |
| `EmissionBlockedTest` | 2 | (a) entorno | Ídem | Ídem | **2 verdes** |
| `ProductionEmissionGuardTest` | 2 | (a) entorno | Ídem | Ídem | **2 verdes** |
| `PricingV2SalesAndPaymentsTest` | 1 (fallo) | **(c) defecto latente**, no prueba desactualizada | Dos reglas del mismo `POST /api/payments` se contradicen para un plan `base_plus_tax` con IVA cuando Pricing V2 está encendido (A.3). | **Requiere decisión** del dueño de pagos. No se toca la prueba ni la regla. | **Sigue rojo**, en la línea base |

**Cifras:** de 56 rojos, **55 en verde** (54 por entorno resuelto + 1 omitida legítima) y **1 rojo real** pendiente de decisión.

### A.2 Ejecución real (solo las familias afectadas, un proceso)

| Corrida | Clases | Resultado |
|---|---|---|
| Antes (HEAD) | las 6 | `Tests: 101, Assertions: 113, Errors: 55, Failures: 1` — exactamente los mismos 56 nombres que la regresión completa (`diff` vacío) |
| Después | las 6 | `Tests: 101, Assertions: 310, Failures: 1, Skipped: 1` (el fallo: precios; la omitida: A.5) |
| `bin/suite` con la línea base nueva | las 6 | `fallos (1): PricingV2SalesAndPaymentsTest::test_manual_payment_uses_backend_quote` · `línea base: 1 tolerados` · `REGRESIONES: 0` · exit 0 |
| `bin/suite` repetido al terminar el encargo B, sobre el árbol de ese momento (con cambios sin confirmar de OTROS agentes en `app/`: W9, V9, endurecimientos) | las 6 | Igual: `Tests: 101, Assertions: 310, Failures: 1, Skipped: 1` · `REGRESIONES: 0` |
| Re-ejecución de las 63 entradas que ya pasaban | 11 clases (`AdminAuthTest`, `CrmTrainerBladeTest`, `CrmTrainerMembersTest`, `ExampleTest`, seis `Integrations\Whatsapp*`, `Security\StreamTicketTest`) | `OK (121 tests, 392 assertions)` |

`--log-junit` siempre al scratchpad: el `junit.xml` de la regresión completa (07:32 UTC) quedó intacto.

### A.3 Precios: por qué es un defecto y no una prueba desactualizada

`PaymentController::store()` aplica, una detrás de otra:

1. `assertAmountMatchesPlan()` (`PaymentController.php:320-345`, llamada en `:543`), del 08-09-2026 (`c8c89c9b`, «cambios pagos»; no es del 28-10): el importe tiene que ser `plan.price` (±0,5) salvo `amount_override`. Compara con el precio **crudo** del plan, sin mirar `pricing_mode` ni el IVA. Su propio docblock dice que replica la comprobación de V2 para cuando V2 está **apagado**.
2. `applyAuthoritativePricing()` (`:1284`, llamada en `:553`), solo con `billing.pricing.v2_enabled`: el importe tiene que ser el bruto de `PricingService::quoteForPlan()` (base + IVA según `pricing_mode`) salvo `amount_override`.

Para un plan `base_plus_tax` de 80.000 con IVA 19 %, la regla 1 exige 80.000 y la 2 exige 95.200. **Ningún importe sin justificación pasa las dos.** Demostrado con una prueba desechable (scratchpad, fuera de la suite):

```
[V2=on]  amount=95200 -> HTTP 422 · El plan «Mensual» cuesta 80.000 y el cobro es de 95.200. Ajusta el importe o justifica el cambio con amount_override y override_reason.
[V2=on]  amount=80000 -> HTTP 422 · El total del plan «Mensual» es $95.200 (base $80.000 + IVA $15.200). Recibido: 80.000,00. Para registrar un importe distinto marca amount_override e indica override_reason.
[V2=off] amount=80000 -> HTTP 201
OK (2 tests, 4 assertions)
```

La prueba roja afirma lo que promete V2 («el admin envía el total correcto: se acepta y se congela el desglose»). Hacerla pasar con `amount_override` sería justificar el cobro del precio correcto: eso es doblegar la prueba, y no se hace.

**Alcance hoy (hipótesis, no verificada en producción):** `BILLING_PRICING_V2_ENABLED` vale `false` por defecto (`config/billing.php:57`, `.env.example:1039`), y el docblock de la regla 1 dice que en producción está apagado. Si es así, la contradicción no se manifiesta hoy. Bloquea encender V2 mientras exista un plan `base_plus_tax` con IVA mayor que cero. No consulté producción.

**Decisión pendiente (dueño de pagos, `backend`):** que `assertAmountMatchesPlan()` compare contra el bruto cotizado cuando V2 está encendido, o que no corra cuando corre `applyAuthoritativePricing()`, que ya hace la comprobación estricta. Mientras tanto, la prueba se queda roja y en la línea base. Si se prefiere que `bin/suite` salga en rojo hasta corregirla, basta con quitar esa línea.

### A.4 La línea base (`tests/baseline-failures.txt`): de 119 entradas a 1

| Motivo | Entradas | Acción |
|---|---|---|
| Verdes tras la corrección de entorno (28 de contratos + 26 de Factus) | 54 | Quitadas |
| Ya pasaban en la regresión completa y en la re-ejecución (seis `Integrations\Whatsapp*` ×40, `CrmTrainer*` ×12, `StreamTicketTest` ×9, `ExampleTest` ×1, `AdminAuthTest` ×1; ver A.2) | 63 | Quitadas |
| Ya no existe: `MemberContractTest::test_register_resumes_when_user_already_has_member`, renombrada el 16-10 (`6850b81d`) a `…_with_the_email_of_another_member_creates_its_own_account` por un cambio deliberado de seguridad | 1 | Quitada (el nombre nuevo pasa) |
| Sigue roja por un defecto real (A.3) | 1 | **Se conserva** |

La «prueba de contratos del 16-10» que §7 de la aceptación pedía añadir no se añade: ya pasa.

### A.5 Verificación de que las pruebas sirven (mutaciones, revertidas con `cp` y `git diff --quiet -- app` vacío)

| Mutante | Sin credenciales (HEAD) | Con credenciales ficticias |
|---|---|---|
| `FactusClient::send()` sin `assertEmissionAllowed()` (la barrera de emisión retirada) | Solo 3 de las 6 pruebas del bloqueo lo detectan. **`test_no_sale_ninguna_peticion_http`, `test_el_bloqueo_ocurre_antes_de_pedir_el_token` y `test_un_reintento_del_job_tampoco_puede_emitir` seguían verdes** porque `cred()` abortaba antes de cualquier POST | **6 de 6 lo detectan**; las 2 «lo que sí debe funcionar» siguen verdes |

En este entorno, la falta de credenciales no solo ponía rojas 26 pruebas: dejaba en verde, por el motivo equivocado, 3 pruebas de la barrera fiscal.

| Mutante en contratos | Detectado por |
|---|---|
| `ContractPdfService::generate()` devuelve `'MUTANTE'` en vez del PDF | `test_adult_flow_draft_sign_download` (`%PDF`) |
| `ContractTemplateService::assertSourceExists()` no lanza con la plantilla ausente | `test_missing_template_fails_clearly` (503) |
| `ContractPdfService::generate()` no importa ninguna página de la plantilla (el PDF firmado sale solo con la constancia) | **Nadie: sobrevive** (A.6) |

### A.6 Lo que sigue sin cubrir en esta familia

- **El PDF firmado conserva las páginas del documento oficial.** El docblock de `ContractPdfService` lo promete («respeta el documento original y su número de páginas») y ninguna prueba lo afirma: el tercer mutante sobrevive. Propuesta: contar las páginas del PDF firmado (FPDI `setSourceFile`) y exigir `pages + 1` (la constancia). No lo añadí: queda fuera del encargo.
- **Que los PDF oficiales reales sean legibles por FPDI** (por ejemplo, sin tablas de referencias comprimidas) nunca lo cubrió la suite en una copia limpia, y con las plantillas sintéticas tampoco. Lo vigila en el servidor `contracts:install-templates`, que solo comprueba existencia y checksum, y lo vigila el uso diario. Una comprobación de despliegue que importe cada plantilla con FPDI lo cerraría.
- **`test_alpha_png_signature_without_gd_is_rejected_cleanly`** se omite sola cuando hay GD o Imagick (aquí hay GD). Es una omisión preexistente del cuerpo de la prueba, no añadida. Con GD, el camino real («el alfa se aplana») no lo prueba nadie: es la única prueba que usa `signature_alpha.png`.
- **Pint:** `MemberContractTest.php` ya fallaba `pint --test` en HEAD con `no_unused_imports` y `binary_operator_spaces`; sigue igual, sin hallazgos nuevos. No reformateé el fichero.

### A.7 Ficheros tocados (encargo A)

- `tests/Feature/MemberContractTest.php`: `setUp` con plantillas sintéticas, `plantillaSintetica()` y `use TCPDF`.
- `tests/Feature/Billing/{FiscalReconciliationTest,FactusPayloadNoVatTest,EmissionBlockedTest,ProductionEmissionGuardTest}.php`: credenciales ficticias en el `setUp`.
- `tests/baseline-failures.txt`: 119 → 1.

Ninguna aserción relajada, ningún `skip` añadido, ningún fichero de `app/` modificado.

---

## B. Prueba #26: E2E del CRM de automatizaciones con navegador real

### B.0 Resumen

**La prueba #26 NO pasa con el código desplegado.** Un bloqueante (H9-E2E-1): una estrategia creada desde el CRM no se
puede abrir en el CRM, así que «crear una estrategia nueva desde el CRM sin tocar código» no es posible hoy. Con UNA
costura documentada (Ana guarda por la API un borrador mínimo), todo lo demás funciona de punta a punta en el navegador
real y contra la pila real: plantilla con cuatro ojos; tres estrategias (vencimientos con seguimiento `waitFor`/
`on.no_response`, clase con cupo y prospectos) construidas, validadas en el servidor con la misma huella, simuladas,
congeladas, aprobadas por la otra persona y publicadas en canario; `outreach:sweep` y `outreach:dispatch --inline` por
consola con 8 personas sintéticas; el panel con la pasada, las inscripciones, la traza en frases con su `traceHash` y
los trabajos simulados; el canal en vivo; la PARADA GLOBAL y su suelta por la otra persona; la pausa de una campaña; y la
bandeja asistida (reclamar y resultado). 0 hosts alcanzados fuera de 127.0.0.1; 0 nombres o teléfonos en respuestas,
eventos SSE, DOM, almacenamiento, registros ni tablas del H9.

| Cifra | Valor |
|---|---|
| Vuelta definitiva | `20261111T001701Z`, backend `d701b0c8` (= producción `ce7c150` en `app/`, rutas, config y migraciones), CRM `9572426` (= producción) |
| Comprobaciones | **194: 182 OK, 12 FALLO** |
| Los 12 rojos | H9-E2E-1 ×9 (3 por estrategia), H9-E2E-2 ×1, H9-E2E-3 ×1 y «el guion salió con 0» (consecuencia de los anteriores) |
| Hash de resultados | `ae61f0299714bd9998190838261c1f3676b287ff297fe3f117b96111c5bb4e46`, **idéntico** en dos vueltas completas seguidas (`20261111T000411Z` y `20261111T001701Z`): reproducible |
| Arnés | sha256 `7977ab5cca4cf750` (guion, ayudante, barrido, lanzador y plantilla) |
| Reloj del motor | T = `2026-11-11T15:00:00Z` (10:00 de Bogotá, día hábil); ventana de publicar vacía (la vuelta publicó pasadas las 19:00) |
| Evidencia | `ops/evidencia-h9/2026-11-11/e2e/20261111T001701Z-d701b0c8-crm-9572426/` (101 ficheros, 61 capturas, 16 tablas, red, comprobaciones; barrida: 0 hallazgos en 100 ficheros; `sha256-evidencia.txt`) |
| Mutación del E2E | Retirar en la COPIA la guarda de cuatro ojos al soltar un freno (`OutreachStop.php:327`) ⇒ el guion lo caza (`403 four_eyes_required` → 500) y, de paso, demuestra la defensa en profundidad: la base lo para con `obrk_four_eyes_chk` |

### B.1 El arnés (`docs/ultron-studio/ops/h9-e2e/`, nuevo)

`h9-e2e.sh` (lanzador), `recorridos-h9.cjs` (guion), `lib-h9.cjs`, `ayudante-h9.php`, `barrido-h9.py`, `reloj-h9.php`,
`servidor-crm.cjs`, `cinco-xx.py`, `entorno-h9.ejemplo` y `README.md` (10 ficheros). Mismo diseño que el del H8 (copias `git archive` fuera de los
repos, PostgreSQL desechable con roles de producción, valla, sesiones en `sessionStorage`, registro de red por CDP con
barrido de cuerpos) y lo propio del H9: las dos personas vinculadas por la orden de consola del arranque, la siembra
sintética para los tres disparadores, el canal en vivo (eventos SSE contados y barridos) y la fase asistida.

**Banderas, solo en la copia:** las seis de la orden (`ULTRON_STUDIO_STRATEGIES_ENABLED`, `…_PUBLISHING_ENABLED`,
`COMMERCIAL_AUTOMATION_ENABLED`, `COMMERCIAL_AUTOMATION_MODE=shadow`, `COMMERCIAL_EVENTS_ENABLED`,
`ULTRON_STUDIO_STREAM_ENABLED`); la constante `OutreachSendGate::SENDS_ENABLED_IN_CODE` sigue en `false` y el lanzador lo
exige dos veces (en el fichero de la copia y en la aplicación arrancada).

### B.2 Hallazgos (código ajeno: documentados, NO corregidos)

#### H9-E2E-1 · BLOQUEANTE para la prueba #26: una estrategia creada desde el CRM no se puede abrir en el CRM

- **Síntoma (navegador real):** Ana pulsa «Nueva estrategia» → «Crear en borrador». El servidor responde **201** y la
  estrategia existe, pero el CRM enseña «La respuesta del servidor no tiene la forma esperada: el Studio no la usa.» y
  no abre el constructor. Abrirla después desde la lista falla igual (`[data-ca-constructor-error]`, mismo texto). Sin
  constructor, el CRM no tiene ninguna otra vía para escribir el borrador: **«crear una estrategia nueva desde el CRM»
  (#26) es imposible con el código desplegado.** Capturas `e1-01b-ana-alta-sin-constructor.png` y
  `e1-01c-ana-constructor-no-abre.png`.
- **Causa raíz (demostrada):** el alta sin borrador guarda `'{}'` (`StudioStrategies::draftJson()`,
  `app/Services/UltronStudio/StudioStrategies.php:291-295`), pero `draftDto()` lo lee con
  `json_decode($s->draft_json, true, …)` (`:274`): `{}` pasa a ser un array PHP vacío y sale como **`[]`**
  (`"draft":{"revision":1,"content":[],"updatedAt":null}`). El CRM exige objeto o `null`
  (`commercial-automation-parse.ts:406`, `objetoONulo(o['content'], 'strategy.draft.content')`, definido en `:89`).
  Reproducido ejecutando el parser REAL del commit del CRM (empaquetado con su esbuild) sobre la respuesta REAL del
  alta: `StudioParseError: strategy.draft.content: no es un objeto`.
- **Alcance:** el código de `app/`, rutas, configuración y migraciones de producción (`ce7c150`) es idéntico al
  probado (`git diff --stat ce7c1506 d701b0c8 -- app routes config database` vacío): el defecto está desplegado,
  dormido mientras la fase 1 lo tenga todo apagado. Bloquea la fase 2 en cuanto alguien cree una estrategia.
- **Por qué no lo cazó nadie:** ninguna prueba del backend afirma la forma del borrador vacío (solo
  `data.draft.revision`, `UltronStudioStrategyLifecycleTest.php:64`), y los ejemplos «de formas reales» del CRM solo
  traen un borrador lleno (`testing/ca-examples.testing.ts:125`); el arnés de capturas de FE9 también.
- **Arreglo propuesto (dueño: S9 en el backend; alternativa en FE9):** que `draftDto()` devuelva un objeto para el
  borrador vacío (decodificar sin `assoc`, o `$contenido === [] ? new \stdClass : $contenido`, o `null`), con su
  prueba de regresión que mire el JSON crudo (`"content":{}` o `null`, nunca `[]`); o que el CRM acepte `[]` como
  borrador vacío. Recomiendo el backend: la API promete un objeto.
- **Costura del E2E para seguir:** Ana guarda por la API un borrador mínimo `{"format":"strategy-content/1"}`
  (`PUT strategies/{uuid}/draft`, la misma ruta que «Guardar borrador», 200); con eso el constructor abre y TODO lo
  demás se hace en el navegador. Queda en rojo en las comprobaciones (tres por estrategia, nueve en total, con
  `hallazgo: H9-E2E-1`).

#### H9-E2E-2 · Medio: la simulación sintética niega a toda persona de prueba sin eventos con una explicación falsa

- **Síntoma:** una persona de prueba con todo concedido (WhatsApp `granted`, canario, hechos de la audiencia) sale
  `deny` en N6 con la frase «La persona pidió hablar con alguien hace poco: se para y se deriva»; nadie pidió nada.
- **Causa raíz:** el CRM manda `events: null` cuando la persona no tiene eventos
  (`src/app/modules/commercial-automation/ca-simulation.ts:345`; `contacts` hace lo mismo en `:344`, aunque en estas
  vueltas no cambió ninguna decisión) y el simulador trata
  `null` como «fuente sin datos» ⇒ `humanRequestedRecently = null` ⇒ N6 niega por el lado seguro
  (`app/Services/UltronStudio/StudioStrategySimulator.php:284-291`; regla en `Core/PolicyLadder.php:471-473`).
  Para una persona REAL eso es correcto (L9-26); para una sintética el formulario no tiene forma de decir «sin eventos,
  y lo sé».
- **Impacto:** la puerta de aprobar no se rompe (pide «sin bloqueantes», y una negativa no es un bloqueante), pero la
  simulación sintética no puede enseñar nunca un `plan` sin inventar un evento, y explica la negativa con un motivo
  falso: engaña a quien revisa.
- **Arreglo propuesto (decisión de FE9 + B9-run):** que el CRM mande `events: []` para las personas sintéticas (y,
  por coherencia, `contacts: []`), o que el simulador trate la ausencia en una persona `synthetic` como lista vacía.
- **Costura del E2E:** las personas de prueba que deben planificarse llevan un evento reciente inocuo (`app_linked`
  hace 24 h); una tercera persona sin eventos deja constancia del hallazgo en cada vuelta.

#### Observación (no es defecto): la simulación del borrador no vale para aprobar

Aprobar exige una simulación de ESA versión (`StudioStrategyVersions::passingSimulation()`, `:373-381`: misma
`version_id` y huella), aunque la del borrador tenga la misma huella. El CRM lo dice al rechazar («Aprobar exige una
simulación sin bloqueantes sobre la huella exacta de la versión»). El orden real es: validar → simular el borrador
(opcional) → congelar → **simular la versión** → aprobar. El texto del contrato (D9-12, «sobre la misma huella») se lee
más laxo que el código; conviene alinearlo.

### B.3 Desviaciones y costuras del E2E

| Qué | Por qué | Dónde se ve |
|---|---|---|
| Fase asistida en `live` con el asistido encendido (cupo 20), solo en la copia | En sombra el despacho usa siempre el canal simulado y la bandeja no recibe nada (docblock de `OutreachDispatcher`) | `asistido` en el guion; comprobación previa repetida; la valla comprobada tras el reinicio |
| `outreach:dispatch --inline` con `now()` de Carbon en el último `not_before` + 1 min | Desfase determinista de 0–14 min por persona y, fuera de horario, el primer hueco legal | `logs/consola-dispatch*.out` (primera línea: el reloj del proceso) |
| Borrador mínimo por la API tras el alta | H9-E2E-1 | Comprobaciones con `hallazgo: H9-E2E-1` |
| Evento inocuo en las personas de prueba | H9-E2E-2 | Comprobación con `hallazgo: H9-E2E-2` |
| CRM compilado (`development`) y servido estático, no `ng serve` | Con la máquina saturada por otros agentes, `ng serve` no compilaba en 10 min por vuelta | README del arnés |
| API con 16 workers de `php -S` | Con 8 y la máquina saturada, los canales SSE ocupaban la mitad y caían peticiones por `max_execution_time` | Primera vuelta (descartada): 8 respuestas 5xx, todas «Maximum execution time of 30 seconds exceeded» |

### B.4 Resultado de la vuelta definitiva, paso a paso

| Paso | OK / total | Lo que quedó demostrado (capturas en `capturas/`) |
|---|---|---|
| `humo` | 8/8 | Las dos sesiones: chips «Gobierno encendido», «Publicar: ventana abierta», «Persona vinculada»; canal en vivo `live`; el panel dice «Sombra (todo simulado)» y «Cerrada · Envío automático en el código»; `GET outreach/status` 200 con maestra, sombra y constante `false` (`00-*`) |
| `plantillas` | 10/10 | Ana crea `aviso-asistido-…` (asistido, WhatsApp, guion sin datos de contacto); ni botón ni servidor le dejan aprobarla (`four_eyes_required`); Beto la aprueba (`p-*`) |
| `e1` vencimientos | 29/33 | Trigger `membership.expiring_window` 7 días; audiencia Y de dos hechos; éxito `days_to_expiry > 30`; paso `aviso` con `answered→end_success`, `no_answer→wait` + `waitFor` 2 días y `no_response→end_exit`; paso `seguimiento` a los 2 días. Forma en vivo válida; validación del servidor sin bloqueantes y con la MISMA huella; simulación del borrador `plan`/`deny`; versión congelada con esa huella; simulación de la versión; Ana no ve «Aprobar» (`four_eyes_required`); Beto reconoce el aviso, aprueba y publica: entrada `publish` seq 1 verificada, canario activo con la huella del diálogo. Rojos: H9-E2E-1 ×3 y H9-E2E-2 (`e1-*`) |
| `e2` clase | 29/32 | Lo mismo con `class.upcoming_with_capacity` (7 días), audiencia «activo y sin reserva», éxito «reservó»; seq 2. Rojos: H9-E2E-1 ×3 (`e2-*`) |
| `e3` prospectos | 29/32 | Lo mismo con `lead.followup_due`, audiencia «es prospecto y tiene seguimiento vencido», éxito `commercial_phase = WON`; seq 3. Rojos: H9-E2E-1 ×3 (`e3-*`) |
| `consola` | 14/14 | Con el panel de Ana abierto, `outreach:sweep`: el canal trae `outreach.runs` sin recargar (`c-00`). Filas abajo. `outreach:dispatch --inline`: 4 trabajos `simulated` en sombra, 4 resultados `not_attempted` y 2 `blocked` (las negativas) |
| `panel` | 6/6 | Pasadas `sweep`/`dispatch` completadas; 8 inscripciones en la tabla; trabajos simulados; la inscripción de S1 con «Inscribe» y «Planifica», la traza en frases y su huella = la de la base (`c-01`, `c-02`) |
| `parada` | 8/9 | Ana echa la PARADA GLOBAL (a su nombre); el panel ABIERTO de Beto la ve sin recargar (`outreach.brakes`, `d-01b`); el despacho no reclama nada; Ana intenta soltarla desde el CRM y el servidor se niega («Nadie suelta un freno que abrió…», `d-01c`) y la API da `403 four_eyes_required`; Beto la suelta con nota (`d-03`). Rojo: H9-E2E-3 |
| `pausa` | 4/4 | Beto pausa la campaña de prospectos con motivo: entrada `pause` seq 4 verificada; la pasada siguiente (`--force`) no planifica nada de ella (`e-01`) |
| `asistido` | 19/19 | Modo `live` + asistido (cupo 20) SOLO en la copia; 17/17 procesos de la API con la valla tras el reinicio; preflight con la constante `false`; S4 planificada por el canal asistido; el despacho la abre en la bandeja (`started`); antes de reclamar no hay ficha ni guion; Beto reclama y los ve, Ana no; Beto registra `answered` (fuente `staff`), la tarea sale de la bandeja y la inscripción cierra `completed` (`f-*`) |
| `barrido` | 18/18 | DOM, almacenamiento, URL y consola de las dos sesiones en 5 pestañas: 0 nombres o teléfonos; 715 cuerpos de respuesta de la API y 147 eventos SSE barridos: 0; 2 986 peticiones del navegador, 53 intentos a `fonts.googleapis.com` (el `index.html`, anterior al H9) y los 53 muertos en el resolver: 0 alcanzados; 0 errores de página; 0 respuestas 5xx (en esta vuelta, ni siquiera durante el reinicio deliberado; en la anterior hubo 9 × 502 dentro de él, contados aparte) |
| cierre | 8/9 | Valla: 0 hosts salvo sus 2 sondas; `lsof`: 185 muestras, 0 líneas fuera de 127.0.0.1; `laravel.log` sin ERROR; `failed_jobs` 0; proxy: 0 5xx fuera del reinicio; barrido de registros, red, comprobaciones y las 16 tablas del H9: 0; parada limpia de los 4 puertos. Rojo: «el guion salió con 0» (código 1, por los 11 rojos de arriba) |

**Las filas de la pasada** (`filas/tras-consola.json`; sujetos por su clave interna, sin datos personales):

| Persona | Vencimientos (E1) | Clase (E2) | Prospectos (E3) |
|---|---|---|---|
| S1 (vence en 7, `granted`) | `plan` → trabajo `simulated` (traza `1104d453f31a…`) | `defer` N6 `lower_priority` (un solo contacto por persona, #5) | — |
| S2 (vence en 7, `denied`) | `deny` N1 `consent_denied` | `defer` N6 `lower_priority` | — |
| S3 (vence en 8) | no entra (ventana de 7) | `plan` → `simulated` | — |
| S5 (activa, sin reserva) | — | `plan` → `simulated` | — |
| S6 (ya reservó) | — | no entra (audiencia) | — |
| L1 (seguimiento vencido, `granted`) | — | — | `plan` → `simulated` |
| L2 (seguimiento vencido, `pending`) | — | — | `deny` N1 `no_consent_for_purpose_channel` |

`traceHash` completo de la decisión de S1 en vencimientos: `1104d453f31aeee2fcbe6ca5c7046d79c53bc55fc26e1a75b29bd8cacde596c6`
(el mismo en la base, en `GET outreach/decisions/{uuid}` y en el cajón del panel). Cadena: seq 1–3 `publish` (una por
estrategia) y seq 4 `pause`, todas verificadas.

### B.5 Observaciones (no bloquean; para sus dueños)

- **H9-E2E-3 · menor (FE9, UX):** el panel ofrece «Soltar la parada» también a quien la abrió
  (`ca-panel.ts:139-141` del commit `9572426`: solo mira el permiso `release`); el servidor lo niega bien (`403 four_eyes_required`, y la base
  con `obrk_four_eyes_chk`). El DTO del freno no trae `actions`/`can` (sí lo trae la aprobación de versiones), así que el
  CRM no puede saberlo: o el DTO lleva `actions.release.allowed`, o el botón explica que tiene que ser otra persona.
- **SSE9 · cursor compuesto:** `outreach.runs` y `outreach.jobs` avanzan por `(updated_at, id)`
  (`OutreachTopic::compositeSince()`); un proceso que escriba con el reloj adelantado deja callados esos temas hasta que
  el resto lo alcanza. En producción (un servidor) no debería pasar; lo destapó la costura del reloj del E2E.
- **TEST8 · arnés del H8:** `h8-e2e-crm.sh:181-182` hace `… | sed … || true` y luego lee `PIPESTATUS[0]`, que el `true`
  ya pisó: «los guiones salieron con 0» sale SIEMPRE en verde (verificado con bash). Las comprobaciones individuales sí
  se registran. Mi lanzador lo heredó y lo corregí (`set +e`; `EST=${PIPESTATUS[0]}`).
- **Contrato D9-12:** el texto dice «una simulación sobre la misma huella»; el código exige la de ESA versión (B.2,
  observación). Conviene alinear el texto.
- **Fuentes de Google en `index.html`** (anterior al H9): el navegador las intenta en cada carga; mueren en el resolver
  del E2E, pero en producción son una petición a un tercero por página.

### B.6 Lo que este E2E NO cubre (y por qué)

- **Los cambios sin confirmar del worktree.** El E2E corrió sobre `HEAD d701b0c8` (`git archive`), el código desplegado.
  Mientras tanto otros agentes tienen modificados en este mismo worktree `OutreachDispatcher`, `OutreachPlanner`,
  `OutreachSendGate`, `OutreachConfig`, canales de WhatsApp y voz, etc. (W9/V9 y endurecimientos): **nada de eso está
  probado aquí**. El arnés lo puede repetir sobre un commit nuevo con `H9_E2E_REF`.
- **La ventana de publicar del contrato:** la vuelta publicó pasadas las 19:00 de Bogotá, con la ventana vacía; el 409
  fuera de ventana lo cubren las pruebas de S9, no este E2E.
- **WhatsApp y voz reales:** son puertas vacías hasta W9/V9 y la constante sigue cerrada; el E2E no los ejerce.
- **El programador y el monitor** (`COMMERCIAL_AUTOMATION_SCHEDULER_ENABLED`/`…_MONITOR_ENABLED`) siguen apagados: las
  pasadas son por consola, como pidió el encargo.
- **Promover a todas, revertir y archivar** desde el CRM: fuera del encargo (los cubren las pruebas de S9 y #25).
- **Tiempo real con la máquina en reposo prolongado** (la «conexión medio cerrada» de la memoria del proyecto): no se
  provoca aquí.
- **Una persona sin `release`** (Administrador): el E2E usa dos Super Admin; los 403 de función los cubren las pruebas de
  permisos del backend.

### B.7 Ficheros del encargo B

- Nuevos: `docs/ultron-studio/ops/h9-e2e/` (10 ficheros, README incluido) y la evidencia
  `docs/ultron-studio/ops/evidencia-h9/2026-11-11/e2e/20261111T001701Z-d701b0c8-crm-9572426/`.
- Fuera del repo: las copias de cada vuelta (`~/.cache/ironbody-h9-e2e/run-*`), el CRM compilado
  (`~/.cache/ironbody-h9-e2e-crm/crm-95724261393a/dist-e2e`) y la base desechable `ultron_studio_h9_e2e` (con los roles
  `h9e2e_*`) en el PostgreSQL de pruebas de 127.0.0.1:55432. Las dos vueltas anteriores (una con el fallo de
  `PIPESTATUS` y otra con el volcado de `outreach_cursors` roto) se apartaron al scratchpad, no se borraron.
- Ningún fichero de `app/`, rutas, configuración ni del CRM tocado. Sin commit.

---

## C. Lo que requiere decisión (consolidado)

| # | Qué | Severidad | Dueño | Decisión |
|---|---|---|---|---|
| 1 | **H9-E2E-1**: `draft.content` sale como `[]` y el CRM no abre las estrategias que crea | **Bloqueante para #26 y la fase 2** | S9 (backend) o FE9 | Corregir antes de encender la fase 2; recomiendo el backend, con prueba de regresión del JSON crudo |
| 2 | **Precios V2**: dos reglas contradictorias para un plan `base_plus_tax` con IVA (A.3) | Latente (V2 apagado por defecto); bloquea encender V2 | Backend de pagos | Qué regla manda cuando V2 está encendido |
| 3 | **H9-E2E-2**: la simulación sintética niega a toda persona sin eventos con «pidió hablar con alguien» | Medio | FE9 + B9-run | `events: []` para sintéticas en el CRM, o lista vacía en el simulador |
| 4 | **H9-E2E-3**: botón de soltar la parada visible para quien la abrió | Menor (UX) | FE9 (+ DTO de B9-run) | `actions.release` en el DTO o texto explicativo |
| 5 | Texto de D9-12 frente al código (simulación de la versión) | Menor (documentación) | Lead | Alinear el contrato |
| 6 | `PIPESTATUS` en `h8-e2e-crm.sh:181-182` | Menor (arnés) | TEST8 | Corregir como en `h9-e2e.sh` |
| 7 | Línea base: dejar en ella el rojo de precios o que `bin/suite` salga en rojo hasta corregirlo | — | Lead | Hoy se conserva (1 entrada) |
