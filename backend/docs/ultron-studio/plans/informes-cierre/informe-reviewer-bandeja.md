# Informe del revisor · Cierre de la bandeja asistida (`cierre/bandeja`, `git diff 0c57d7c4..15a2f9e5`, 17 commits)

**22-11-2026 · `reviewer` · un solo pase, solo lectura.** No he editado ni confirmado nada en el repositorio. Mis sondas están en mi scratchpad, fuera del repositorio.
Marcas: **[C]** comprobado ejecutando · **[L]** leído · **[S]** supuesto.

## Veredicto

**APROBADO para desplegar en producción con el modo asistido APAGADO.**
- **Bloqueantes: 0.**
- **Encender el asistido en vivo: todavía NO.** Antes hay que cerrar I-1 (abajo) y C-1 de security (los textos del CRM).

## Bloqueantes

Ninguno. Con el asistido apagado, F-1 (`AssistedQueue.php:388-396`) pone `global` a true. Todo queda retenido como `brake` antes de leer a la persona o el guion: `withholdReasons()`, `:449-450`.
- Lo sin reclamar no se lee: lo filtra el SQL, `queueRows()` `:741`.
- Lo reclamado sale sin `person` ni `script`.
- Ninguna identidad sale por la bandeja mientras esté apagado [C: `test_f1_…`, en los dos motores, y mi sonda P-1].

## No bloqueantes

**I-1 · Importante · condición para encender el asistido.** Por WhatsApp comercial, perder el consentimiento sin un `denied` no retiene. Es `AssistedQueue.php:585-589`: la rama `default => false` para WhatsApp.
- **Escenario [C, sonda P-2, SQLite]:**
  1. Un asistido comercial por WhatsApp sale con el prospecto `granted`, y Ana lo reclama.
  2. Después, la persona escribe desde otro número. Nace un prospecto suyo con `consent_status = null` y conversación de WhatsApp.
  3. `LeadFacts.php:250` toma ese prospecto, el más reciente, y da `consent.whatsapp.commercial = false`. El hecho `lead.consent_denied` sigue en `false` (`:304-311`: null no es «denegado»).
  4. La entrada de N1 queda en `consent.whatsapp = "missing"`: la escalera negaría con `no_consent_for_purpose_channel`.
  5. La bandeja, en cambio, sigue entregando `person` y el guion en `mine` y en la repetición.
- **Por qué pasa:** lo fija a propósito la unitaria `AssistedQueueOptedOutTest.php:87`. El argumento del código (`:571-573`, la ventana de 24 h) solo vale para la finalidad `service`. El de security («quien escribe `consent_status` solo pone `granted` o `denied`») no cubre un prospecto nuevo: la columna es nullable y por defecto null.
- **Corrección propuesta:** para `purpose = commercial` por WhatsApp, que un consentimiento distinto de `granted` y sin `denied` dé `null` (es decir, `policy_unknown`), como ya se hace por voz. `service` se queda como está.
- **A quién:** a **backend**. La decisión, a **security**.
- **Sin comprobar:** cuántas veces ocurre en producción que un socio tenga un segundo prospecto [S].

**m-1 · Menor · mantenibilidad: ninguna prueba vigila que la bandeja no diverja de la escalera.**
- `optedOut()`, `ineligible()` y `outOfCanaryScope()` (`:575-649`) rehacen a mano una parte de N1, N3 y N5 sobre `CoreProjection::policyInput()`.
- Las baterías fijan `WITHHELD_REASONS` en tres sitios (`H9AssistedConditionsTest:395`, `H9AssistedEligibilityTest:444`, `H9AssistedOptOutStrategyTest:505`), pero ninguna mira `PolicyLadder::REASON_ORDER`.
- **Escenario:** alguien añade a la escalera un motivo de N1, o a `LeadFacts` una señal negativa nueva. La bandeja sigue entregando y ninguna prueba cae.
- **Corrección propuesta:** una prueba con una tabla que clasifique CADA motivo de `REASON_ORDER` como «retiene con X» o «excluido por la decisión Y» (D-3, F-4, L9-66), y que exija que sus claves sean exactamente las de la escalera. A **tester** o **backend**.

**m-2 · Menor · ya conocido, lo dejo constar.** C-1, los textos de los cuatro motivos nuevos en el CRM, sigue abierto. La salida segura aguanta:
- he leído la copia `subsanacion-20209@ce695d7`: `commercial-automation-parse.ts:1368` convierte un motivo desconocido en `unrecognized`, que se pinta como «no contactes», y `ca-assisted.ts:241-247` oculta la ficha y el guion [L];
- con el asistido apagado, el único motivo que puede salir es `brake`, que el CRM ya conoce [L].

## Las siete preguntas

1. **Corrección.** El código hace lo que dicen los informes, en los once motivos, en su orden (`:442-473`, `:527-535`) [C, en los dos motores].
   - La relectura tras el `UPDATE` (`:305-314`) usa lecturas nuevas de los frenos, la cadena, la foto, el libro y la inscripción. Lo prueba la carrera con dos procesos.
   - El único camino de entrega fuera de la regla que he encontrado es I-1.
   - No retiene de más sin decir el motivo. F-3 nunca afecta a un trabajo vivo legítimo: N4 niega en vivo toda plantilla no aprobada (`PolicyLadder.php:381`) [L].
2. **Regresiones.** `voice.consent_revoked` solo lo lee `AssistedQueue.php:587` [L, grep].
   - `policyInput()` no lo lee (`CoreProjection.php:138-196`), y `VoiceStrategyChannel.php:111-113` solo mira la clave de consentimiento.
   - `facts_json` y la traza no lo llevan, y `replay` da `same` [C].
   - `BatchSnapshot::fromArray` (`:186`) acepta cualquier subconjunto de las claves. Lo único que cambia es `snapshotHash`, en la entrada de la simulación por muestra del Studio (`StudioStrategySimulations.php:123`). Ya cambiaba en cada pasada porque lleva `at` = ahora, y nadie lo compara [L].
   - Los hashes de `OutreachCatalog` y `StudioCatalog` son idénticos en HEAD y en la base [C]. Ninguna referencia «línea base» se invalida al desplegar.
   - `assistedScript()` solo la llaman la bandeja y `OutreachIsolationTest:499`; el parámetro nuevo es opcional [L].
3. **Producción hoy.** El diff no trae migraciones, rutas, configuración ni cambios en `Http/` [C, `git diff --name-only`].
   - Lo programado (`sweep` cada 10 minutos, `dispatch` cada minuto, `monitor`) no usa `AssistedQueue`. La clave nueva es un booleano más, calculado con eventos ya leídos y sin ninguna consulta [L].
   - Sonda P-1, con sombra, el asistido apagado, cupo 0 y las tablas vacías: las vistas `all`, `mine` y `unclaimed` dan `{items: [], withheld: 0, scanCapped: false}` con 11 consultas entre las tres, y el reclamo da 404 [C, SQLite y PG].
4. **Lo que no puede tocar.** Ningún fichero de `Services/Marketing`, `UltronStudio`, `Voice`, `Telephony` ni de pagos [C].
   - El código solo cambia `Services/Outreach/{Run,Facts}`. `VoiceConsentRule` (de Telephony) se usa, pero no se modifica.
   - Ni la publicación del Studio ni el envío de WhatsApp leen `AssistedQueue` ni la clave nueva [L].
5. **Pruebas.** Prueban las clases reales: el planificador, el despachador, `VoiceContacts::revoke` y la bandeja [L].
   - Las que cambiaron añaden siembra (canario, consentimiento, RNE) y aserciones más estrictas (+1 consulta exacta); ninguna aserción se ha aflojado [L].
   - La carrera se valida sola. Con la señal antes de la comprobación daría 409 en lugar de 201, y la prueba exige `barrierAt < updateAt`, ninguna transacción envolvente y la reclamación confirmada [L, C en PG].
   - No dependen del reloj real: `setTestNow` fijo y ningún `time()` ni `date()` [C, grep].
6. **Rendimiento.** Para una acción de una persona, con límite de 30 por minuto, son aceptables las 59 consultas (unos 30 ms en local) del reclamo y las 44 constantes de la página (prueba `test_m1_…` [C]; el banco no lo he repetido).
   - Lo único que crece con los datos ya existía: `LeadFacts::optedOutPhones()` lee todos los DNC en cada tanda (hasta 4 por página; es F-6 del autor), más `queueRows` y `viewParts` sobre `outreach_jobs`.
   - Hoy, apagado, la bandeja no construye la foto [C, P-1].
7. **Mantenibilidad.** `AssistedQueue.php` pasa de 721 a 935 líneas. La duplicación con `PolicyLadder` es real y puede divergir en silencio, sin ninguna prueba que lo impida: es m-1. No esconde ningún fallo, salvo el de I-1.

## Pruebas que ejecuté (línea final)

| Qué | SQLite | PG 14 (`ultron_studio_cierre_bandeja`) |
|---|---|---|
| `H9AssistedEligibilityTest` | OK (27, 406) | OK (27, 406) |
| `H9AssistedOptOutStrategyTest` | OK (27, 309) | OK (27, 309) |
| `H9AssistedConditionsTest` | OK (11, 185) | OK (11, 185) |
| `H9AssistedComplaintTest` | OK (4, 39) | OK (4, 39) |
| `H9AssistedClaimRaceTest` | 4 saltadas (solo PG) | OK (4, 114) |
| `AssistedQueueOptedOutTest` | OK (48, 49) | OK (48, 49) |
| `LeadAndVoiceFactsTest` | OK (9, 74) | OK (9, 74) |
| `tests/Unit/Outreach` | OK (1042, 53256) | — |
| `tests/Feature/Outreach` | OK, Tests: 726, Assertions: 41850, Skipped: 168 | — |
| Guardas (16 ficheros) | OK (141, 182977) | — |
| `replay` (`OutreachPolicyTest`, `H9ComplaintRecentTest` filtradas; `CancelDecisionTimelineTest`, `H9ShadowCanaryOnlyTest` enteras) | OK (1, 9) · (2, 7) · (3, 21) · (4, 28) | las mismas |
| Pint `--test`, los 13 PHP del diff | passed | — |
| Sonda P-1 (condiciones de producción) | OK (1, 7) | OK (1, 7) |
| Sonda P-2 (I-1) | reproduce I-1 | — |
| Hashes de los dos catálogos, HEAD frente a la base (`ultron-h8@8bf2aad7`, sin cambios en `app/` desde `0c57d7c4`) | idénticos | — |

## Lo que no pude verificar

- **Producción:** nada (sin ssh). Ni que hoy haya 0 asistidos en vivo, ni las banderas reales [S, según el encargo].
- **El CRM desplegado:** solo he leído la copia `ce695d7`, no lo que sirve producción.
- **El banco de carga:** no lo he repetido; los tiempos son los del autor. La constancia de consultas por página sí la he comprobado (`test_m1_…`).
- **Las mutaciones del autor:** no las he rehecho, porque el repositorio es de solo lectura.
- **La frecuencia real del escenario de I-1:** no la sé [S].
- **Operación:** el diff no interfiere en código con la publicación de las 7:00, pero el despliegue en sí (reinicio de FPM, `config:cache`) conviene hacerlo fuera de esa ventana. Esto es una recomendación para el lead o `devops`, no un hallazgo.
