# Hito 6 · paquete S (Studio: servicios, modelos, API, permisos, libro, comandos y pruebas): informe del backend

**Fechas:** tanda 1 el 31-10-2026 (confirmada por el lead en `4e6f5fe`); tanda 2 (esta) el 01-11-2026, Bogotá.
**Árbol:** `_worktrees/ultron-h6/Iron-Body/backend`, rama `feat/ultron-studio-h6`, HEAD `fb152fe`. Incluye la Enmienda 3 (`0b7b41d`) y N-5 de V (`bd1c7af`).

Límites de las dos tandas:
- **Sin commit.** La tanda 2 queda en el árbol y la confirma el lead.
- **Producción: nada.**
- Sin `composer` ni dependencias nuevas.
- Sin tocar el motor, las migraciones, `routes/console.php`, `StudioCatalog`, `tests/fixtures/` ni las clases de V y TW.

## 0. La tanda 2 en breve

1. **Enmiendas 1 y 2 leídas y aplicadas.** El detalle está en §5.
2. **La paridad recorre las 22 secciones del fixture con las clases reales (O-6).**
   - Se añaden 145 casos puros contra las clases de V y TW, y 170 pasos HTTP de `errorOrder`.
   - Una prueba índice comprueba que cada uno de los **719** casos lo ejecuta un arnés.
3. **Hay una discrepancia de V con el fixture.**
   - `ElevenLabsConfig` acepta un `agent_id` con un salto de línea final.
   - **N-5 (`bd1c7af`) no la cierra**: añadió `D` al patrón, pero `fromConfig()` sigue haciendo `trim()` antes de casarlo.
   - La prueba se queda **en rojo** a propósito: se corrige la clase, no el fixture (§6.1).
4. **Q-6 decidido** (D-9) y **O-2 aplicado** (D-10), cada uno con su prueba y su mutación.
5. **El intermitente de Marketing está explicado.**
   - Es una pausa del proceso de más de 120 s, durante la suspensión del equipo.
   - El token de `decide` vive 120 s.
   - Mecanismo reproducido: 3/3 pasadas solas y la suite completa en verde (§4.3).
6. **Un defecto mío, arreglado: `StudioCanaryNumbers::revoke()`.**
   - El libro guardaba el estado de partida leído **antes** de esperar la fila.
   - Con una autorización que se confirma mientras tanto, el evento decía `pending → revoked` cuando la transición real fue `authorized → revoked`.
   - Lo detectó la prueba de carreras de PG del tester, que aún no está confirmada (D-14).
7. **Un hallazgo para T-1, del motor y fuera de alcance.**
   - El validador privado de `usage-trigger` rechaza los 6 vectores oficiales válidos de Twilio, porque `fullUrl()` reordena la consulta.
   - Sin consulta en la URL coincide con el algoritmo publicado (§6.2).
8. **Los cinco puntos de S de la Enmienda 3 están hechos, con prueba y mutación.** El lead los encargó después: AS-1 y AS-2 de `informe-api-security.md`; B-2, N-1 y N-3 de `informe-security.md`.
   - **B-2**, que es D-14.
   - **AS-1**: teléfonos y correos con 422 en los motivos, la nota y el alias (D-15).
   - **AS-2**: tope de `page` en la ruta 40 (D-16).
   - **N-1**: el alta fallida no deja datos del número en el log (D-17).
   - **N-3**: «base oficial» estricta (D-18).
9. **AS-1 deja desfasado un caso del fixture.**
   - `alias · aliasError · un teléfono en grupos de 2 no tiene serie de 4 (literal; ver O-1 del README)` espera `null`.
   - La Enmienda 3 cierra O-1 y ese alias ahora es `invalid_alias`.
   - Ese caso queda **en rojo** hasta que el tester actualice el fixture (§6.1b).

## 1. Ficheros

### Tanda 2 (sin confirmar)

| Fichero | Líneas | Cambio |
|---|---|---|
| `app/Services/UltronStudio/StudioCanaryCoherence.php` | 191 | Q-6 (D-9): con `unreadable` por el teléfono, `activeWhatsapp` lleva el recuento ya leído |
| `app/Services/UltronStudio/StudioCanaryPhone.php` | 241 | O-2 (D-10): texto general, `NOT_MOBILE_MESSAGE` y `message()`. AS-1 (D-15): `contactDataError()`, `contactDataRule()` y el alias |
| `app/Services/UltronStudio/StudioCanaryNumbers.php` | 705 | D-14 / B-2: `revoke()` lee el estado con la fila bloqueada. N-1 (D-17): `PDOException` relanzada sin valores y `#[\SensitiveParameter]` |
| `app/Services/UltronStudio/StudioCampaignLinks.php` | 509 | AS-2 (D-16): `CAMPAIGNS_PAGE_MAX` y un desplazamiento que no desborda |
| `app/Services/UltronStudio/StudioIntegrationConfig.php` | 636 | N-3 (D-18): `base()` exige `https`, el host exacto, el puerto 443 y la URL sin credenciales |
| `app/Services/UltronStudio/StudioIntegrationsExport.php` | 431 | Mensajes `phoneNotMobileForWhatsapp` y `contactData` (nuevos); el ejemplo `unreadable` lleva `activeWhatsapp: 1` |
| `app/Http/Controllers/Crm/UltronStudio/UltronStudioCanaryController.php` | 163 | 422 del teléfono con `message()`; AS-1 en los motivos de 52 y 53 |
| `app/Http/Controllers/Crm/UltronStudio/UltronStudioCampaignLinkController.php` | 124 | AS-1 en el motivo de 48; AS-2 en `page` de la ruta 40 |
| `app/Http/Controllers/Crm/UltronStudio/UltronStudioIntegrationController.php` | 132 | AS-1 en la nota de 55 |
| `tests/Unit/UltronStudio/StudioIntegrationsParityTest.php` | 588 | Índice de las 22 secciones; V (firma, sobre, estado, URL base, verificación, decisión); TW (saneador); vector oficial de Twilio |
| `tests/Feature/UltronStudio/UltronStudioIntegrationsParityHttpTest.php` | 1089 | `errorOrder`: 170 pasos con su compañera |
| `tests/Feature/UltronStudio/UltronStudioIntegrationsCanaryTest.php` | 462 | Q-6; O-2 (4 casos); AS-1 en 52, 53 y el alias; N-1 (tiempo agotado e interbloqueo) |
| `tests/Feature/UltronStudio/UltronStudioIntegrationsCampaignLinkTest.php` | 283 | AS-1 en 48; AS-2 |
| `tests/Feature/UltronStudio/UltronStudioIntegrationsConfigTest.php` | 344 | AS-1 en 55; N-3 (9 bases) |
| `docs/ultron-studio/plans/informes-h6/informe-api.md` | — | Este informe |

### Tanda 1 (en `4e6f5fe`)

**Nuevos:**

| Fichero | Qué |
|---|---|
| `app/Support/Privacy/PhoneFingerprint.php` | Huella HMAC con la subclave `ultron-canary-phone/1` |
| `app/Services/UltronStudio/StudioCanaryPhone.php` | Teléfono y alias del número canario |
| `app/Services/UltronStudio/StudioIntegrationConfig.php` | Hechos de configuración, deriva, ruta 46 y reconocimiento (55) |
| `app/Services/UltronStudio/StudioMetaCampaigns.php` | Lector del espejo de Meta |
| `app/Services/UltronStudio/StudioCampaignLinks.php` | Rutas 40, 41, 47 y 48 |
| `app/Services/UltronStudio/StudioCanaryCoherence.php` | Coherencia del registro con el canario del motor |
| `app/Services/UltronStudio/StudioCanaryNumbers.php` | Rutas 42 y 49–54 |
| `app/Services/UltronStudio/StudioIntegrations.php` | Banderas, ruta 39 y ruta 45 |
| `app/Services/UltronStudio/StudioTwilioPanel.php` | Rutas 43 y 44 |
| `app/Services/UltronStudio/StudioTwilioProbe.php` | Ruta 56 |
| `app/Services/UltronStudio/StudioIntegrationsExport.php` | Formas para el fichero de pruebas del CRM |
| `app/Models/UltronStudio/{StudioCampaignLink,StudioCanaryNumber,StudioIntegrationSnapshot}.php` | Modelos |
| `app/Http/Controllers/Crm/UltronStudio/UltronStudio{Integration,CampaignLink,Canary}Controller.php` | Controladores |
| `app/Console/Commands/UltronStudio/Integrations{Doctor,Snapshot}Command.php` | Comandos |

**Modificados:**
- `UltronStudioException.php`, `StudioAuditEvent.php`, `StudioAudit.php`, `StudioAccess.php`.
- `AuthorizationMap.php`, `PermissionCatalog.php`, `CrmPermission.php`.
- `AppServiceProvider.php` (solo `limitadoresDelStudio()`).
- `ExportStudioFixturesCommand.php`, `config/ultron_studio.php`, `routes/ultron-studio.php`, `.env.example`.

**Pruebas:**
- Nuevas: `IntegrationFixtures.php`, nueve `UltronStudioIntegrations*Test.php` y `StudioIntegrationsParityTest.php`.
- Ajustadas: `UltronEngineIsolationTest`, `UltronStudioEngineReadOnlyTest`, `UltronStudioPublicationSchemaTest`, `UltronStudioPermissionsTest`, `UltronStudioLabNoEngineEffectsTest` y `ExportStudioFixturesCommandTest`.

**Ficheros de otros agentes.** Estaban sin confirmar en el árbol durante mis corridas y no los he tocado:
- `tests/Feature/UltronStudio/UltronStudioIntegrationsRaceTest.php` y `tests/Support/IntegrationRaceSessions.php` (carreras de PG del tester).
- `tests/Feature/UltronStudio/UltronStudioIntegrations{EnvSentinel,Guards,Sentinel}AdversarialTest.php`.
- `docs/ultron-studio/plans/informes-h6/informe-api-security.md`.

Corren dentro de mis directorios. Sus resultados van aparte en §4.

## 2. Rutas, permisos y orden de errores

**Las 18 rutas.**
- Son las de §I7.1, con su llave en `AuthorizationMap::OVERRIDES` (56 rutas en total).
- La 56 lleva además `throttle:ultron-studio-probe` (6 por hora y persona, 20 por hora y red).

**Llaves por defecto.**
- El Administrador tiene `campaigns_manage` y `canary_manage`.
- `canary_authorize` e `integrations_manage` son solo del Super Admin.
- El token compartido no tiene ninguna.

**Orden comprobado** con `errorOrder`, paso a paso. Cada caso rompe el paso y una comprobación posterior a la vez (§3, D-11):
1. Sesión: 401, con el código del middleware.
2. Puerta de `AuthorizationMap`: 403. Aquí la puerta exige **la llave de la ruta**.
3. Persona: `person_required` (con el token compartido responde antes la puerta, CN-4).
4. `ultron.view`: 403 `forbidden {required_permission: ultron.view}`.
5. Forma: 422.
6. Bandera: 409. La 55 no depende de la bandera de escrituras; la 56 depende solo de la suya.
7. Reintento: `replayed` o `client_request_reused`.
8. Recurso: 404.
9. Identidad: 403 `four_eyes_required {action}`.
10. Estado y topes: 409.
11. 23505: con el mismo código que la comprobación previa.

**Cambios de contrato de esta tanda** (todos de la Enmienda 3, más O-2):
- **48, 52, 53 y 55:** un motivo o una nota con un teléfono (8 o más dígitos con separadores cortos que no son una fecha) o con un correo da 422. El texto fijo es «Sin teléfonos ni correos: este texto queda en el libro y no se puede corregir.».
- **49:** el alias con un teléfono escrito en grupos da 422 con el texto de siempre.
- **40:** un `page` mayor que 100 000 da 422.
- Es forma, así que va antes que la bandera, como cualquier 422.

**Lo que no se puede provocar por HTTP:**
- El paso `permission` del fixture (después de la forma) lo contesta la puerta. Responde con el mismo 403 `forbidden {required_permission: llave}`. Es la pregunta P-1.
- La forma de las rutas sin entradas: 39, 43, 45 y 46 no tienen consulta, y 50 y 51 no tienen cuerpo. El arnés comprueba que una entrada ajena se ignora.

## 3. Decisiones fuera del contrato (D-n)

**Tanda 1:**
- **D-1.** El `state` de Twilio en las rutas 39 y 43 es `ok`, `degraded` o `not_configured`.
- **D-2.** La sonda se serializa con `Cache::lock`. La espera es de 40 s como mucho; si el cerrojo queda colgado, se sigue sin él.
- **D-3.** El tope global de asociaciones se cuenta bajo el cerrojo consultivo `ultron_studio_campaign_links`.
- **D-4.** Un mapa vacío es `[]` en lo que se hashea y `{}` en los DTO que no se hashean (O-4).
- **D-5.** El reintento del registro compara la versión esperada **actual**.
- **D-6.** Los hechos de voz tienen un constructor puro, `voiceFactsFrom`. Así el exportador no nombra los secretos de voz.
- **D-7.** `StudioIntegrations` no nombra clases del motor. Hay 5 lectores nuevos.
- **D-8.** `voiceFromConfigured` es un E.164 estricto, sin recortar. En HTTP, `TrimStrings` recorta antes de validar.

**Tanda 2:**
- **D-9 · Q-6.** El caso: el teléfono del lead canario no se puede leer y el registro sí.
  - El veredicto es **`unreadable`**, que está en el conjunto cerrado; por eso no hace falta caer a `engine_canary_not_registered`.
  - `activeWhatsapp` es el recuento de filas WhatsApp activas, leído antes que el teléfono. Solo es `null` si falla el propio recuento.
  - Ninguna fila se afirma canario del motor: `isEngineCanary` es `null` en todas las de WhatsApp.
  - La ruta 39 muestra los mismos recuentos y el veredicto.
  - Es compatible con el fixture: `activeWhatsapp: null` con `failure` significa «no se afirma».
  - La prueba es `test_con_el_telefono_del_canario_ilegible_…`. Su mutación (volver a `null`) cae.
- **D-10 · O-2.**
  - `MESSAGES[not_allowed_for_channel]` pasa a ser «Ese número no sirve para este canal.».
  - El texto con la pista, «Ese número no sirve para este canal (WhatsApp exige un móvil).», sale solo si el canal es `whatsapp` y el número colombiano no empieza por `+573`.
  - Un `+573…` con un dígito de más o de menos, o cualquier rechazo en voz, lleva el texto general.
  - `parse()` no cambia, porque su forma la fija el fixture. La decisión está en la función pura `StudioCanaryPhone::message()`.
  - En la exportación cambia `messages.phoneNotAllowedForChannel` y aparece `messages.phoneNotMobileForWhatsapp`.
- **D-11 · Método de `errorOrder`.** Cada paso se rompe junto a una **compañera**, una comprobación posterior que se puede romper a la vez (`COMPANIONS`). Si responde el paso, el paso existe con su código y va antes que ella.
  - Las carreras:
    - La de la 47 se simula con un `DB::listen` que confirma la asociación rival justo después de la comprobación previa y fuera del savepoint. Es el 23505 real de `uscl_one_active`, resuelto como `campaign_already_linked`.
    - La de la 49 es un 23505 real de `uscn_one_live`: no tiene comprobación previa a propósito.
  - Las tres mutaciones de orden caen: identidad tras estado en la 50, bandera tras reintento en la 47 y en la 56. También cae la de quitar la resolución del 23505 en la 47.
- **D-12 · `twilioSignatureOfficial`.** Ni V ni TW tienen clase para esto: T-1 dice «nada en el código».
  - Se recorre con una implementación de referencia del algoritmo publicado: URL más claves ordenadas con sus valores distintos, con y sin puerto, y `bodySHA256`.
  - Es la prueba que tendrá que pasar el validador el día que se extraiga.
- **D-14 · `revoke()` y el libro.**
  - Revocar admite tres estados de partida (`pending`, `authorized`, `active`). El UPDATE condicionado acepta la fila aunque haya cambiado mientras se esperaba su cerrojo; las acciones con un solo estado de partida no tienen el problema.
  - Ahora el estado se lee **dentro** de la transacción con `lockForUpdate()`. En PG espera a la rival y lee lo confirmado; en SQLite no hace nada, porque las escrituras ya van en serie.
  - Así `fromStatus` es la transición que de verdad hubo.
  - `cipherErased` sigue saliendo de la lectura previa, porque solo revocar borra el cifrado y dos revocaciones no pasan las dos.
  - `setExpectation` no registra estados.
  - Evidencia en PG: `test_autorizar_frente_a_revocar_forzado_gana_quien_toma_la_fila`, del tester, falla sin el arreglo y pasa con él 3 de 3 veces. La mutación inversa vuelve a fallar.
- **D-15 · AS-1.**
  - `StudioCanaryPhone::contactDataError()` copia las tres expresiones de `LeadSourceClassifier`: `DIGIT_RUN`, `DATE_SHAPE` y `EMAIL`. Son privadas y del motor; copiarlas evita un lector nuevo del motor.
  - Una serie de 8 o más dígitos que no tiene forma de fecha, o un correo, es `contact_data`. Con UTF-8 inválido falla cerrada.
  - Se aplica con `contactDataRule()` a 48, 52, 53 y 55, y dentro de `aliasError()` al alias, con el mensaje del alias.
  - `DIGIT_RUN` lleva `\p{L}` y `u` como el original, que ya corre en producción. Se añadió `D` a `DATE_SHAPE` (E1.1).
  - La regla más fuerte del alias (7 o más dígitos) es la que la Enmienda 3 difiere al Hito 7. No la apliqué.
- **D-16 · AS-2.**
  - `page` de la ruta 40 es `integer|min:1|max:100000`, solo en esa ruta. 41, 42 y 44 usan el paginador de Eloquent y ya no dan 500.
  - Por encima del tope, 422; en la página 100 000, la página vacía.
  - Además, `campaignsPage()` calcula el desplazamiento sin desbordar aunque se llame sin el controlador.
  - La prueba comprueba también que no queda ningún `error` en el log (`Log::spy()`): ni `production.ERROR` ni traza.
- **D-17 · N-1.**
  - En el alta, cualquier `PDOException` que no sea el 23505 se relanza como `RuntimeException`, sin `previous` y con solo la clase y el SQLSTATE.
  - `PDOException` cubre `QueryException` y la `DeadlockException` que Laravel lanza dentro de una transacción anidada.
  - La huella, los 4 dígitos y el alias van como `#[\SensitiveParameter]` en `register()` y `registerGated()`, para que tampoco salgan en la traza (aquí `zend.exception_ignore_args` está en `Off`).
  - El cliente recibe el 500 genérico; no hay código de error nuevo.
  - **Lo que queda en `laravel.log`** es una línea, la del handler:
    - el mensaje «ultron_studio: el registro de un número canario falló en la base (QueryException, SQLSTATE 57014).»;
    - la traza, con esos argumentos como `Object(SensitiveParameterValue)`. En producción, con `zend.exception_ignore_args=On`, sin argumentos; no lo he podido comprobar en el servidor.
  - Es el criterio del H5, registrar la clase y nunca el mensaje, más el SQLSTATE que propone `informe-security.md`.
- **D-18 · N-3.**
  - `{official:true}` solo con `https`, el host oficial exacto, sin otro puerto que el 443 y sin usuario ni contraseña.
  - El host se compara sin distinguir mayúsculas, como el DNS, porque el fixture evitó ese borde.
  - Con las bases por defecto (`https://api.twilio.com` y `https://verify.twilio.com`) los hechos y la huella no cambian.
  - Con una base no oficial (`http`, otro puerto o credenciales) pasa a `{official:false, host}` y la huella cambia, que es lo que se busca. Por eso tiene que estar desplegado antes de reconocer Twilio en la fase 2.
- **D-13.** El índice de la paridad está en `StudioIntegrationsParityTest::test_cada_caso_del_fixture_lo_ejecuta_un_arnes`.
  - Exige las 22 secciones en el orden del fichero y que cada nombre de caso lo produzca algún proveedor de los dos arneses.
  - Fija el total en 719.

## 4. Verificación (cifras reales)

### 4.1 Paridad con `integrations-cases.json` (719 casos, 22 secciones)

| Sección | Casos | Arnés | Clase real | Resultado |
|---|---|---|---|---|
| phone | 88 | puro | `StudioCanaryPhone` | OK |
| alias | 36 | puro | `StudioCanaryPhone` | **35/36: 1 caso desfasado por AS-1 (§6.1b)** |
| fingerprint | 18 | puro | `PhoneFingerprint` | OK |
| canaryTransitions | 121 | HTTP | rutas 42, 49–54 | OK |
| coherence | 34 | HTTP | `StudioCanaryCoherence` | OK |
| expectation | 24 | puro | `StudioCanaryNumbers` | OK |
| campaignLinkRules | 64 | HTTP | rutas 47 y 48 | OK |
| metaClassification | 39 | puro 22 + HTTP 17 | `StudioMetaCampaigns` | OK |
| providerClassification | 21 | puro | `StudioIntegrations` | OK |
| configFacts | 11 | puro | `StudioIntegrationConfig` (byte a byte) | OK |
| configDrift | 8 | puro | `StudioIntegrationConfig` | OK |
| elevenlabsSignature | 52 | puro | V `ElevenLabsWebhookSignature` | OK |
| elevenlabsEnvelope | 13 | puro | V `ElevenLabsWebhookEnvelope` | OK |
| voiceDoctor | 38 | puro | V `VoiceProviders` / `ElevenLabsConfig` (status 22, baseUrl 8); Studio (studioState 8) | **37/38: 1 fallo de V (§6.1)** |
| voiceVerify | 15 | puro con `Http::fake` | V `VoiceProviders::current()->verifyCredentials()`; Studio `VOICE_OUTCOMES` | OK |
| voiceCallPolicy | 24 | puro | V `VoiceCallPolicy::decide()`; Studio `voiceLevels` y `voiceCanaryFrom` | OK |
| twilioProbeSanitize | 28 | puro | TW `TwilioProbeSanitizer` (con Q-13 de `3995b01`) | OK |
| twilioSignatureOfficial | 7 | puro | sin clase (D-12) | OK |
| probeRules | 22 | HTTP | ruta 56 | OK |
| acknowledgeRules | 9 | HTTP | ruta 55 | OK |
| routeMatrix | 29 | HTTP | 56 rutas | OK |
| errorOrder | 18 (170 pasos) | HTTP | rutas 39–56 | OK |

**Totales:**
- Paridad pura: **408 pruebas, 2 fallos**: el de V (§6.1) y el alias que AS-1 cierra (§6.1b).
- Paridad HTTP: **466 pruebas, 1475 aserciones, OK**.
- El índice de cobertura: OK.

### 4.2 Suites

| Qué | Resultado |
|---|---|
| Verificación 1 (Studio, Access, Chaos, Voice y Twilio, SQLite), final | **2806 pruebas, 37908 aserciones, 2 fallos**: el de V (§6.1) y el alias de §6.1b. 102 omitidas, que son solo de PG. 2:11. Incluye los ficheros sin confirmar de otros agentes (§1) |
| Puerta PostgreSQL (`UNIT_DB=ultron_studio_h6_s`), final | **2452 pruebas, 30663 aserciones, 3 fallos**: los dos de arriba y `UltronStudioHardeningTest::test_una_lista_por_encima_de_su_tope_es_422_solo_por_el_tope_y_sin_recorrerla` (H5). Es un presupuesto de 150 ms rebasado en 7 ms con la máquina compartida (65 % de CPU, el lead en paralelo); sola pasa 3 de 3 en PG, y no he tocado ni las reglas ni esa prueba. 9:05 de reloj. Las carreras del tester, incluida la de D-14, en verde |
| Corrida final anterior, antes de los puntos de la Enmienda 3 (09:16–09:24) | SQLite: 2799 pruebas, 37778 aserciones, 1 fallo (V). PG: 2445 pruebas, 30546 aserciones, 1 fallo (V), 5:41 |
| OTP (`tests/Feature/Otp`, `OtpProductionGuardTest`, `TwilioVerifyOtpTest`), tanda 1 | 82 pruebas, 208 aserciones, OK |
| Marketing (`tests/Feature/Marketing` y `tests/Unit/Marketing`), 01-11 | **4977 pruebas, 21689 aserciones, OK**, en 3:44 al 88 % de CPU |
| Mutaciones de la tanda 2 | **19/19 cazadas**, ficheros restaurados (comprobado con `cmp`). 7 de la paridad, Q-6, O-2 y D-14 (orden en 47, 50 y 56; 23505 en la 47; Q-6; O-2; `revoke` en PG). 12 de la Enmienda 3: AS-1 sin la regla en 52/53, 48, 55 y el alias, y sin respetar la fecha; AS-2 sin tope y sin la guarda; N-1 sin atrapar y sin `SensitiveParameter`; N-3 sin `https`, sin el usuario y sin el puerto |
| 1.ª pasada de la tanda 2 (antes de D-14 y con la exportación editada a mitad de corrida) | SQLite: 2794 pruebas, 37775 aserciones, 1 fallo (V), 98 omitidas, 2:21. PG: 2445 pruebas, 30554 aserciones, 2 fallos (V y la carrera de `revoke`, que dio D-14), 12:05 de reloj con el lead corriendo en paralelo |
| Mutaciones de la tanda 1 | 9/9 cazadas |

### 4.3 El intermitente de `UltronHandoffHardGuardTest`

- **Lo que pasó.** En la corrida del 31-10 dio 1 fallo: 422 en lugar de 200, en la línea 295.
- **Por qué no es de la suite.** Esa corrida tardó **65 min de reloj con 190 s de CPU**: el proceso estuvo casi todo el tiempo parado (la suspensión del equipo).
- **Repetición.**
  - El test solo, 3 de 3 pasadas: **17 pruebas, 120 aserciones, OK** en cada una.
  - La suite completa de Marketing: OK (§4.2).
- **El mecanismo, reproducido.**
  - Lo hice con una subclase del test en el scratchpad, fuera del repositorio, que adelanta el reloj tras `decide`.
  - Con una pausa de **119 s**, el commit da 200. Con **121 s**, da 422 `stale_decision {reason: decide_token_expired}`.
  - La causa es `UltronDecideToken::TTL_SECONDS = 120`.
- **Conclusión.** No es de S: el motor está intacto y la puerta de diff, vacía. Es un artefacto de una pausa de más de 120 s entre `decide` y `commit`.
- **Para el dueño del motor (fuera de alcance):** congelar el reloj en ese test.

### 4.4 Comandos y puertas

| Qué | Resultado |
|---|---|
| Puerta de diff del motor desde `e1b1d26` | **Vacía**. Tampoco cambian `composer.json`/`.lock`, `app/Services/Voice`, `app/Services/Twilio`, las migraciones ni `tests/fixtures` respecto a HEAD |
| `route:list --path=admin/ultron-studio` | **56** |
| `auth:coverage --unmapped` | Vacío, exit 0 |
| `permissions:export` (tanda 1) | 105 permisos, idéntico byte a byte al catálogo del CRM. Sin cambios de permisos en la tanda 2 |
| `ultron-studio:export-fixtures --path=<scratch>` | 6 ficheros; 5 idénticos a los de la tanda 1. `backend-integrations.testing.ts` cambia solo en lo previsto: el texto general de O-2, las claves nuevas `phoneNotMobileForWhatsapp` y `contactData`, y `activeWhatsapp: 1` en el ejemplo `unreadable` |
| `ultron-studio:integrations-doctor` (SQLite de usar y tirar, llaves por defecto) | `meta_ads`, `twilio`, `twilio_voice` y `elevenlabs`: `not_configured`; `canaries`: `registry` (`no_engine_canary`); exit 0 |
| `ultron-studio:integrations-doctor --json --expect=meta_ads:connected_read,twilio:connected_read` | `ok: false`, 2 discrepancias; exit 1 |
| Pint `--test` sobre los 14 PHP de la tanda 2 | passed |

## 5. Las Enmiendas 1 y 2, aplicadas

**Enmienda 1:**
- **E1.1.** Importes como texto con dos decimales, `[]` en lo que se hashea, `StudioVersionStatus::label()`, y PCRE con `D` y `[0-9]`. Ya estaba en la tanda 1. Lo confirman `configFacts` 11/11, `phone` 88/88 y `alias` 36/36.
- **E1.2.** `secretFingerprints()` es la única vía (D-6) y `VoiceIsolationTest` está en verde. Las decisiones de V se recorren contra su clase: firma 52/52, verificación 15/15 y URL base 8/8.
- **E1.3.** Recuentos 43/49 en `UltronStudioPublicationSchemaTest`, comprobados en la puerta PG.
- **E1.4.** Las convenciones del saneador las cumplen los 28 casos contra la clase real.
- **E1.5.** OTP de `snake_case` a `camelCase`, `lastVerification.outcome` de voz y `applied`/`legacy`, en la tanda 1.

**Enmienda 2:**
- **Q-4:** `studioState` 8/8.
- **Q-5:** firma 52/52.
- **Q-6:** D-9.
- **Q-7:** `expectation` 24/24.
- **Q-8:** `baseUrl` 8/8.
- **Q-9:** `alias` 36/36.
- **Q-10:** `configFacts` 11/11.
- **Q-11:** `probeRules` 22/22 y `errorOrder` 56.
- **Q-12 y Q-13:** saneador 28/28, incluido el `last4` con E.164 de 8 a 15 cifras de `3995b01`.
- **O-2:** D-10.
- **O-4:** es del CRM.
- **O-6:** §4.1.

**Enmienda 3** (lo de S):
- **B-2:** D-14.
- **AS-1:** D-15. La parte del CRM, el aviso en `ib-reason-dialog`, es del frontend.
- **AS-2:** D-16.
- **N-1:** D-17. El caso centinela del tester sigue siendo suyo.
- **N-3:** D-18.
- **I13** (la huella se recupera con la clave y los 4 dígitos) es texto del contrato: no cambia código de S.
- **N-5** es de V: §6.1.

## 6. Hallazgos para otros dueños, dudas y lo no verificado

### 6.1 Discrepancia de V (prueba en rojo, sin ocultar)

- **Caso.** `voiceDoctor · status · agent_id con un salto de línea final ($ de PCRE sin D lo dejaría pasar)`.
- **Qué hace la clase.**
  - `ElevenLabsConfig::fromConfig()` hace `trim($c['agent_id'])` antes de casar el patrón.
  - `AGENT_ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/'` no lleva `D`.
  - Con `"agent_h6fixture_0001\n"` sale `ELEVENLABS_DISABLED` con `missing: []`.
- **Qué exige el fixture.** `ELEVENLABS_NOT_CONFIGURED` con `missing: ['agent_id']`. Lo respaldan VZ-3 y E1.1, que dice «afecta… al `agent_id` de ElevenLabs».
- **Lo que hizo N-5 (`bd1c7af`).** Añadió `D` al patrón y una prueba que casa la constante a pelo (`preg_match(AGENT_ID_PATTERN, "agent_abc-123\n") === 0`).
  - Pero `fromConfig()` sigue haciendo `trim($c['agent_id'])` antes de casar, así que el salto de línea se quita antes y el caso sigue saliendo `ELEVENLABS_DISABLED`.
  - La paridad lo confirma después de `bd1c7af`: la verificación 1 final empezó a las 09:16:37 y el commit es de las 09:15:28.
- **Arreglo que falta, para V.** No recortar el `agent_id` antes de casarlo (o rechazarlo si el recorte lo cambia). No lo he tocado.
- **Impacto real.** Ninguno desde `.env`, porque Dotenv no deja un salto de línea final. Solo afecta a una configuración puesta a mano.
- **Consecuencia.** Hasta que V lo corrija, la verificación 1 tiene exactamente este fallo.

### 6.1b Un caso del fixture desfasado por la Enmienda 3 (prueba en rojo, sin ocultar)

- **Caso.** `alias · aliasError · un teléfono en grupos de 2 no tiene serie de 4 (literal; ver O-1 del README)`.
- **Qué fija.** Espera `null`, el comportamiento de O-1 de la Enmienda 2.
- **Qué dice la Enmienda 3.** AS-1 «cierra O-1»: con la regla de AS-1 ese alias es `invalid_alias`.
- **Qué hice.** El código sigue la enmienda más reciente. El fixture es del tester y no lo he tocado.
- **Qué falta.** Que el tester cambie el valor esperado de ese caso (y, si quiere, añada los de AS-1).
- **Consecuencia.** Hasta entonces, la paridad pura tiene este segundo fallo.

### 6.2 El validador de `usage-trigger` frente a los vectores oficiales (motor, T-1 y O-5)

- **Cómo lo medí.** Con una sonda de solo lectura en el scratchpad, que llama al método privado `TwilioUsageTriggerController::firmaValida()` sobre los 7 vectores del fixture.
- **Resultado.** Rechaza **los 6 válidos**. El derivado, que no es válido, también lo rechaza.
- **Causa.**
  - `fullUrl()` reordena la consulta: `?foo=1&bar=2` pasa a `?bar=2&foo=1`.
  - Quita los puertos 443 y 80.
  - No trata `bodySHA256`.
  - Y las claves repetidas (O-5).
- **Sin consulta en la URL** (como la de `usage-trigger`), coincide con el algoritmo publicado.
- **Impacto hoy.** Ninguno si la URL configurada en Twilio no lleva consulta. No lo puedo comprobar: no veo la consola de Twilio.
- **El día de la extracción (T-1),** el validador tiene que firmar la URI tal como llega y pasar `twilioSignatureValid()` de la paridad.
- No he tocado nada.

### 6.3 Preguntas para el lead

- **P-1 · `errorOrder`, paso `permission`.**
  - La llave de cada escritura está en la puerta: §I7.1 y paso 1 de §I3.4, el mismo patrón que el H5.
  - Por eso una persona con `ultron.view`, sin la llave y con un cuerpo malo recibe 403 `forbidden {llave}`. Leído al pie de la letra, el fixture pide 422.
  - Opciones:
    - **(a) Dejarlo como está y dar por entendido que ese paso lo contesta la puerta.** Es lo que recomiendo, porque la puerta cierra antes.
    - (b) Pasar la puerta de las 10 escrituras a `ultron.view`, para que la llave se mire después de la forma. Cambia §I7.1 y debilita la puerta.
  - El arnés no afirma el caso combinado de llave y forma; afirma el código, la carga y que va antes de la bandera.
- **P-2 · El número de casos del fixture.**
  - El commit `7df9ed9` y la Enmienda 2 dicen 704; el fichero confirmado tiene **719**.
  - El índice de la paridad fija 719.
- **P-3 · Quién completa N-5 en V (§6.1).** La prueba se queda en rojo hasta entonces.
- **P-4 · El caso del alias del fixture (§6.1b).** El lead confirmó aplicar AS-1 al alias. Solo falta que el tester cambie el valor esperado de ese caso.

### 6.4 Para el CRM (frontend)

- Volver a exportar `backend-integrations.testing.ts`. Cambia un texto, `phoneNotAllowedForChannel` (O-2), y hay una clave nueva, `phoneNotMobileForWhatsapp`.
- `activeWhatsapp` puede ser un número también con el veredicto `unreadable` (Q-6). Es `null` solo si el recuento falla.
- AS-1: hay un 422 nuevo en los motivos (48, 52 y 53) y en la nota (55). El texto es `messages.contactData`. El aviso en el diálogo es del CRM.
- AS-2: un `page` mayor que 100 000 en la ruta 40 es un 422.

### 6.5 Para el tester

- Su `UltronStudioIntegrationsRaceTest`, aún sin confirmar, encontró D-14: ya está arreglado en mi código, y su fichero no lo he tocado.
- Actualizar el caso del alias de §6.1b.
- Los casos negativos de AS-1 ya están en mis Feature: «+57 300 000 0280», «300-000-0280», «3000000280» y «juan@correo.co» dan 422; «Pauta 2026-11-01», «versión 3» y «tope de 10» pasan.
- El de N-1: un `QueryException` con 57014 y con «deadlock detected» en el INSERT.

Casos límite que conozco desde dentro:
- La carrera de la 47 entre la comprobación previa y el INSERT. En PG de verdad es su `UltronStudioIntegrationsRaceTest`.
- Q-6 con el fallo solo en `marketing_leads`.
- O-2 con `+57 300 000 000` y `+57 300 000 00012` (móviles de longitud equivocada) y con voz.

### 6.6 Sin verificar

- Producción, `iron`/`iron_app` y `ironbody-migrate`: nada.
- La URL configurada en Twilio para `usage-trigger` (§6.2).
- Irrepresentables en la base, ya dichos en la tanda 1:
  - `coherence · «lead_id nulo»`: se prueba como «el lead no existe».
  - `campaignLinkRules · versión VALIDATING`: se comprueba la regla.

## 7. Listo para confirmar (tanda 2)

Los 14 ficheros PHP de §1 «Tanda 2» y este informe. Se pueden confirmar en un commit o en dos.

- **Condición:** ya. Las corridas finales solo tienen dos fallos, y ninguno se oculta:
  - el de V, que se cierra completando N-5 en `ElevenLabsConfig`;
  - el caso del alias del fixture, que se cierra cuando el tester lo actualice.
  - En PG, además, el presupuesto de tiempo del H5, que sola pasa.
  - Si el lead prefiere confirmar con la suite en verde, antes tienen que entrar esos dos cambios. Ninguno es de S.
- **Commit 1, sugerido:** `test(ultron-studio-h6): paridad de las 22 secciones contra las clases reales de V y TW, errorOrder paso a paso, Q-6, O-2 y B-2 (Enmiendas 2 y 3)`.
  - Ficheros: `StudioCanaryCoherence.php`, `StudioIntegrationsExport.php` y `StudioCanaryNumbers.php` (solo `revoke()`), `StudioCanaryPhone.php` (solo O-2) y `UltronStudioCanaryController.php` (solo `message()`).
  - Pruebas: `StudioIntegrationsParityTest.php`, `UltronStudioIntegrationsParityHttpTest.php` y `UltronStudioIntegrationsCanaryTest.php`.
- **Commit 2, sugerido:** `fix(ultron-studio-h6): AS-1, AS-2, N-1 y N-3 de la Enmienda 3 (textos sin teléfonos ni correos, tope de page en la ruta 40, alta sin datos en el log, base oficial estricta)`.
  - Lo demás.
  - Hay ficheros con cambios de los dos commits: `StudioCanaryPhone.php`, `StudioCanaryNumbers.php`, `UltronStudioCanaryController.php`, la exportación y `UltronStudioIntegrationsCanaryTest.php`. Si se separan, toca hacerlo por bloques de cambio (`git add -p`). Si no, basta un solo commit.
