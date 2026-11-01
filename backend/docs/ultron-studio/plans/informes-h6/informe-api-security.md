# ULTRON STUDIO · Hito 6 · Seguridad de la API (`api-security-specialist`)

**Fecha:** 01-11-2026. **Revisado:** backend `feat/ultron-studio-h6`, HEAD `8de30ed`. El código revisado es el del paquete S confirmado en `4e6f5fe`: `git diff --stat 4e6f5fe 8de30ed -- backend/app backend/routes backend/config backend/database backend/bootstrap` sale vacío (desde entonces solo entraron documentación y `ops/banco-h6`). Contrato `ULTRON_STUDIO_H6_CONTRACT.md` (I1–I13, Enmiendas 1 y 2), `informe-api.md` de S e `informe-twilio.md` (§«Para security / api-security-specialist»).

**Modo:** solo lectura sobre el repo. Este informe es el único fichero del repo que he escrito. Sin commit ni push, y sin tocar producción. Las pruebas de concepto (P) son tests de PHPUnit **fuera del repo**, en `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h6-Iron-Body/f0224b74-486e-4776-9b58-baa1c1ee9b1f/scratchpad/apisec/poc/`, ejecutados con `vendor/bin/phpunit --no-logging --do-not-cache-result <ruta absoluta>`. Para PostgreSQL creé `ultron_studio_h6_apisec` en el clúster desechable local (127.0.0.1:55432) con `ops/pgunit.sh` y la borré al terminar (`count = 0`). La lectura empezó sobre el árbol sin confirmar de S, antes de la suspensión del equipo. Al reanudar sobre `d2c04a0`, el árbol estaba limpio y el código confirmado tenía los mismos ficheros y longitudes. Todas las pruebas se ejecutaron después, sobre el código confirmado, y las líneas que cito están comprobadas en `8de30ed`. Lo que apareció entretanto es de otros agentes: `ops/banco-h6/` (confirmado después en `8de30ed`), `tests/Feature/UltronStudio/UltronStudioIntegrations{Race,GuardsAdversarial}Test.php`, `tests/Support/IntegrationRaceSessions.php` y el cambio en `StudioIntegrationsParityTest.php`.

**Reparto con `security`:** cuando escribo esto su informe del H6 no está en el árbol. Aquí va la superficie de la API: autorización por ruta y por objeto, cuatro ojos, orden de errores, `clientRequestId`, validación, exposición, cupos, SSRF, libro y logs del hito. AS-4 y AS-5 son de su terreno (criptografía y entorno): los dejo como notas para que los cierre o los contradiga, sin duplicar severidad.

---

## 0. En una pantalla

**Veredicto: APROBADO CON OBSERVACIONES. Bloqueantes: ninguno. Altas: ninguna.** Ninguna de las 18 rutas deja a un rol leer o escribir más de lo que dice su llave. La segunda puerta (`requirePerson` + `require`) está en las 18. Los cuatro ojos comparan identidades en el servicio y además en la base (`uscn_four_eyes_chk`, `uscn_activation_four_eyes_chk`). No hay oráculo de existencia por uuid. `clientRequestId` está atado a persona y cuerpo. Las tres carreras de topes y el doble clic, ejecutados con sesiones rivales de verdad en PostgreSQL, salen bien. Ninguna respuesta lleva secretos, teléfonos completos, huellas de teléfono ni el id de la conversación canaria. La sonda solo hace GET a los dos hosts oficiales en `production`, con 6/h por persona y 20/h por red.

| Id | Severidad | Qué | Dónde | Estado |
|---|---|---|---|---|
| AS-1 | **Baja** (corregir antes de la fase 2) | Un motivo o una nota de texto libre admite un teléfono o un correo. Queda en una columna que ya no se puede corregir y en el libro (solo inserción), y vuelve a todo `ultron.view`. Contradice P5, I8 e I13 por un camino normal de la interfaz | `UltronStudioCanaryController.php:130-133`, `UltronStudioCampaignLinkController.php:106`, `UltronStudioIntegrationController.php:92` → `StudioCanaryNumbers.php:313,339,346`, `StudioCampaignLinks.php:335,343`, `StudioIntegrationConfig.php:382` | Confirmado ejecutando en SQLite y PG (P-B5, P-E1) |
| AS-2 | **Baja** | `GET integrations/meta/campaigns?page=9223372036854775807` responde **500** (`TypeError` de `array_slice` con desplazamiento `float`) y deja una línea ERROR con traza | `StudioCampaignLinks.php:131`; `UltronStudioCampaignLinkController.php:49,116` | Confirmado ejecutando en SQLite y PG (P-B4, P-C3) |
| AS-3 | Nota | Oráculos de pertenencia de un teléfono adivinado: `409 canary_number_exists` y `isEngineCanary`/`verdict`. Son inherentes a I4.4/I4.5, ruidosos y acotados por el tope de vivos | `StudioCanaryNumbers.php:227-235`; `StudioCanaryCoherence.php:93-110,123-132` | Confirmado ejecutando (P-C1, P-C2) |
| AS-4 | Nota (para `security`/DOC) | I13 dice que la huella del teléfono «no se puede recuperar ni con la `APP_KEY`», pero con la clave, la huella y los 4 dígitos se recupera en 58 ms. Además, las dos subclaves nuevas comparten clave HMAC con `Workforce\Secrets::hash()` (la clase AS-6 del H5) | `PhoneFingerprint.php:64`, `StudioIntegrationConfig.php:103`, `Support/Workforce/Secrets.php:14-16` | Confirmado ejecutando (P-F1) y leyendo |
| AS-5 | Nota (para OPS/`security`) | Fijar los hosts de la sonda depende solo de `app()->isProduction()`. Fuera de `production`, la credencial va a la base que diga el entorno (ya señalado por TW). Ningún script afirma de forma explícita que el proceso web está en `production` | `TwilioReadOnlyClient.php:186`; `deploy-h6-backend-fase1.sh` | Confirmado ejecutando (P-D2) |
| AS-6 | Nota (documentación) | `clientRequestId` vale por recurso: la misma llave sirve para un canario y para una asociación. Dentro de cada tabla, otra persona u otro cuerpo dan 409 | `StudioCanaryNumbers.php:669-688`, `StudioCampaignLinks.php:479-503`, `StudioTwilioProbe.php:203-216` | Confirmado ejecutando (P-A4) |

**Heredado y conocido, no lo cuento como nuevo** (detalle en §3.7):
- AS-5 del H5: invisibles fuera de `PlainText`, que ahora llegan también a tres columnas inmutables.
- O-1: alias con dígitos en grupos.
- Alias con nombre de persona.
- `marketing_calls.reason` como texto libre.
- Cuatro ojos por cuenta y no por persona.

**Lo que está bien, comprobado** (§4):
- **Mapa:** 18 + 38 = 56 rutas, `auth:coverage --unmapped` vacío.
- **Matriz I7.1 y llaves:**
  - Sin sesión → 401 y token compartido → 403.
  - Recepción, Entrenador y Administrativo → 403 en las 18.
  - El Administrador recibe 403 en 50, 55 y 56.
  - Una llave sin `ultron.view` → 403 antes de buscar el recurso.
- **Puertas y oráculos:**
  - Barra final, `%2F` y uuid en MAYÚSCULAS no cambian la llave.
  - 405/404 en métodos no previstos.
  - Sin oráculo de uuid con bandera cerrada, sin llave o sin `view`.
- **Orden y cuatro ojos:**
  - 422 antes que 409.
  - Cuatro ojos con todas las llaves.
  - `four_eyes_denied` deduplicado.
- **Escrituras:**
  - Carreras en PG: tope de activos, tope de asociaciones, misma campaña, doble clic → `replayed`.
  - 32 variantes del registro con el teléfono nunca repetido en el 422.
  - Campos de más ignorados en las tres inserciones.
- **Tamaño y cupos:**
  - 413 por encima de 64 KB y 422 con 600 niveles de JSON.
  - Cupos de la sonda que dos sesiones o `X-Forwarded-For` no esquivan.
  - Enfriamiento sin red ni evento.
  - `auth_token` de la ficha de Twilio fuera de respuestas y `facts`.
- **Logs:** las cuatro líneas de log del hito llevan solo la clase.

---

## 1. Alcance y modelo de amenazas

**Revisado:**
- Las 18 rutas nuevas `/api/admin/ultron-studio/integrations/*` y sus tres controladores.
- `StudioAccess`, `AuthorizationMap` (`:767-784`), `PermissionCatalog`, `CrmPermission` (`:285-286`, `:237`), `limitadoresDelStudio()` (`AppServiceProvider.php:585-663`) y el bloque `integrations` de `config/ultron_studio.php` (`:200-250`).
- Las fábricas nuevas de `UltronStudioException`.
- Los servicios `StudioIntegrations`, `StudioMetaCampaigns`, `StudioCampaignLinks`, `StudioCanaryNumbers`, `StudioCanaryCoherence`, `StudioCanaryPhone`, `StudioIntegrationConfig`, `StudioTwilioPanel` y `StudioTwilioProbe`.
- Los modelos con sus transiciones, `PhoneFingerprint`, y `StudioAudit::recordDenied`/`SUBJECT_TYPES`.
- `TwilioReadOnlyClient`, solo en lo que consume la sonda: hosts, método, redirecciones y `auth_token`.

**Fuera de alcance:**
- El CRM (FE y `security`).
- Los scripts de operación, salvo lo que cito.
- Los paquetes V y TW más allá de lo que consume S.
- El motor.
- Las 38 rutas anteriores: solo comprobé que siguen mapeadas (56 en total, cobertura vacía); las revisiones de F, G, H4 y H5 siguen valiendo.
- Los comandos de consola.
- Producción.

**Activos, por impacto:**
1. Los teléfonos de los titulares de números canarios (WhatsApp: huella HMAC y 4 dígitos; voz: cifrado) y el teléfono del lead de la conversación canaria del motor. Son datos personales (Ley 1581).
2. Los secretos de las integraciones: token de Twilio, token de Meta, claves de ElevenLabs y llave restringida de la sonda. Solo deben salir sus huellas de 8 hex.
3. La integridad del registro de canarios: quién está «activo» y con qué cuatro ojos. En T2 será una restricción del motor.
4. La credencial de Twilio que usa la sonda: la única petición saliente del hito.
5. El libro y las instantáneas de solo inserción.
6. La disponibilidad: workers de PHP-FPM, que una sonda ocupa hasta unos 31 s.

**Actores:**
- (a) Anónimo.
- (b) Rol sin Studio: Recepción, Entrenador, Administrativo o un rol a medida.
- (c) Token compartido de automatizaciones.
- (d) Administrador: `view`, `campaigns_manage` y `canary_manage`.
- (e) Super Admin: las cuatro llaves.
- (f) Rol a medida con una llave de escritura y sin `ultron.view`.
- (g) **Quien registró un número**, legítimo pero equivocado: intenta autorizarlo o activarlo él mismo.
- (h) Quien opera y escribe el entorno.
- (i) Atacante con DML de la aplicación sobre la base, o con un respaldo.

**Fronteras de confianza:**
- CRM↔API: bearer de sesión, sin cookies, así que no aplica CSRF; desde `7ba0ce8` no cambian CORS, middleware, `bootstrap/` ni autenticación (diff vacío).
- API↔base: CHECK y disparadores de las tres tablas.
- API→Twilio: solo la sonda.
- Twilio→API (API10): respuestas saneadas por `TwilioProbeSanitizer`.
- Espejo de Meta: nombres escritos por terceros, que pasan por `maskDigits`.
- Configuración del motor (id de la conversación canaria y teléfono del lead): entra solo a la coherencia y nunca sale.
- Entorno→configuración: las banderas.

---

## 2. Las 18 rutas: llave, segunda puerta, cupo y veredicto

`route:list` (§5, comando 3): las 18 llevan el grupo `api` (`ProtectAdminPaths` → 401, `EnforceAdminAuthorization` → 403 por la llave), `LimitRequestBodySize:65536` y su cupo:
- **L** = `ultron-studio-read`: 120/min por token y 600/min por red.
- **E** = `ultron-studio-write`: 30/min por token y 120/min por red.
- **S** = `ultron-studio-probe`: 6/h por persona y 20/h por red.

«Persona» = `StudioAccess::requirePerson()`, que exige persona y `ultron.view` (`StudioAccess.php:78-93`). «require» = la llave otra vez dentro del servicio (`:114-124`).

| # | Ruta | Llave (mapa) | Segunda puerta | Cupo | Veredicto |
|---|---|---|---|---|---|
| 39 | `GET integrations/status` | view | Persona | L | OK: bloques independientes que, si caen, registran solo la clase (`StudioIntegrations.php:523-534`); sin valores de configuración |
| 40 | `GET integrations/meta/campaigns` | view | Persona | L | OK en autorización y exposición (ids opacos, `***` + 4 cifras, `maskDigits`) · **AS-2** |
| 41 | `GET integrations/meta/links` | view | Persona | L | OK · AS-1 (`deactivated.reason`) |
| 42 | `GET integrations/canaries` | view | Persona | L | OK: solo `masked`, sin huella ni cifrado (`StudioCanaryNumbers.php:434-469`; `$hidden` en el modelo, `:99`) · AS-1 (`revoked.reason`), AS-3 |
| 43 | `GET integrations/twilio` | view | Persona | L | OK: `ref`, `fp` de 8 hex con clave, `last4` de los números del gimnasio y `probe.last` saneado |
| 44 | `GET integrations/twilio/calls` | view | Persona | L | OK: columnas en lista blanca (`StudioTwilioPanel.php:50`); nunca `to_phone`, `transcript` ni `metadata` |
| 45 | `GET integrations/voice` | view | Persona | L | OK: sin red; `policy.allowed=false` |
| 46 | `GET integrations/config` | view | Persona | L | OK: `effective` sin secretos y un GET que no escribe · AS-1 (`authorized.note`) |
| 47 | `POST integrations/meta/links` | campaigns_manage | Persona + require (`StudioCampaignLinks.php:174`) | E | OK (carreras R3) |
| 48 | `POST …/meta/links/{uuid}/deactivate` | campaigns_manage | Persona + require (`:321`) | E | OK · AS-1 |
| 49 | `POST integrations/canaries` | canary_manage | Persona + require (`StudioCanaryNumbers.php:149`) | E | OK (R2, P-B1, P-B2) · AS-3 |
| 50 | `POST …/canaries/{uuid}/authorize` | **canary_authorize** | Persona + require + cuatro ojos (`:595-626`) + CHECK | E | OK (P-A2, P-E1) |
| 51 | `POST …/canaries/{uuid}/activate` | canary_manage | Persona + require + cuatro ojos + CHECK + tope con cerrojo | E | OK (P-A2, R1, P-E1) |
| 52 | `POST …/canaries/{uuid}/deactivate` | canary_manage | Persona + require | E | OK · AS-1 (motivo en el libro) |
| 53 | `POST …/canaries/{uuid}/revoke` | canary_manage | Persona + require | E | OK (borra el cifrado en la misma sentencia) · **AS-1** |
| 54 | `POST …/canaries/{uuid}/expectation` | canary_manage | Persona + require | E | OK: `present` + `uuid`; versión asociable |
| 55 | `POST integrations/config/acknowledge` | **integrations_manage** | Persona + require (`StudioIntegrationConfig.php:353`) | E | OK: no depende de la bandera; la huella tiene que coincidir · AS-1 (`note`) |
| 56 | `POST integrations/twilio/probe` | **integrations_manage** | Persona + require (`StudioTwilioProbe.php:74`) | E + **S** | OK (P-D1, P-D2) · AS-5 |

**Orden de los códigos y qué revela cada uno** (P-A1, P-A3; ejecutado):

| Quién | Petición | Respuesta |
|---|---|---|
| Rol con `canary_manage` + `canary_authorize` y sin `view` | `revoke` o `authorize` con uuid existente o inventado | **403 `forbidden` `ultron.view` los cuatro**, sin buscar el número |
| Administrador | `authorize` con uuid existente o inventado | **403 `forbidden` `ultron.canary_authorize` los dos** (la puerta) |
| Administrador, bandera cerrada | `revoke` o `meta/links/{u}/deactivate` con uuid existente o inventado | **409 `integrations_writes_disabled` todos** |
| Administrador, bandera cerrada, `reason: "x"` | `revoke` con uuid inventado | **422**: la forma antes que la bandera |
| Administrador, bandera abierta | `revoke` con uuid inventado | 404 `canary_not_found`. No es un oráculo útil: `GET canaries` enseña todos los números a `ultron.view`, que la llave presupone |
| Super Admin | `DELETE`/`PUT`/`PATCH`/`GET canaries/{uuid}` | 404 (no existe esa ruta) |
| Super Admin | `GET canaries/{u}/authorize`, `PUT config/acknowledge`, `GET twilio/probe`, `DELETE meta/links`, `PATCH`/`DELETE canaries` | 405 |
| Administrador | `POST canaries/{u}/authorize/` (barra final), `canaries%2F{u}%2Fauthorize`, uuid en MAYÚSCULAS | 403 (la misma llave) |
| Administrador | `…/AUTHORIZE`, `Integrations/status` | 404 (las rutas distinguen mayúsculas) |
| Administrador | `expectation` con el uuid en MAYÚSCULAS | 200: resuelve la misma fila (`strtolower`, `StudioCanaryNumbers.php:602`) |

El registro, las asociaciones y la configuración son un **espacio compartido** de `ultron.view` en un CRM de un solo gimnasio: no hay dueño por objeto ni tenants. El control de objeto que importa es el de identidad (cuatro ojos), y está en el servicio y en la base.

---

## 3. Hallazgos

### AS-1 (baja) · Motivos y notas de texto libre admiten teléfonos y correos; quedan inmutables y se sirven a todo `ultron.view`

- **Qué:** `reason` (rutas 48, 52 y 53) y `note` (ruta 55) se validan con `required|string|min:5|max:500|PlainText(multiline)` (`UltronStudioCanaryController.php:130-133`, `UltronStudioCampaignLinkController.php:106`, `UltronStudioIntegrationController.php:92`). Nada impide un teléfono o un correo. Ese texto acaba en:
  - `ultron_studio_canary_numbers.revoke_reason` (`StudioCanaryNumbers.php:339`): con la fila revocada, `uscn_revoked_is_final` impide cualquier UPDATE;
  - `ultron_studio_campaign_links.deactivation_reason` (`StudioCampaignLinks.php:335`): `uscl_inactive_is_final`;
  - `ultron_studio_integration_snapshots.note` (`StudioIntegrationConfig.php:382`): solo inserción;
  - el `diff.reason` del libro (`StudioCanaryNumbers.php:313,346`; `StudioCampaignLinks.php:343`), también de solo inserción.

  Vuelve a todo `ultron.view` en `revoked.reason` (`StudioCanaryNumbers.php:451-455`), `deactivated.reason` (`StudioCampaignLinks.php:415-420`), `authorized.note` (`StudioIntegrationConfig.php:331`) y `GET audit`.
- **Evidencia (P-B5 en SQLite y PG; P-E1 en PG):**
  ```
  B5 revocar: 200 · columna revoke_reason con el número: true · libro con el número: true
     · GET canaries?status=revoked (Administrador) con el número: true · GET audit (Administrador) 200 con el número: true
  B5 la fila revocada no se puede corregir: 409 invalid_transition
  E1 "limpiar el motivo de una revocada": P0001 «un número canario revocado es definitivo» · "editar el libro": P0001 «la auditoría es de solo inserción»
  ```
- **Actor y escenario:** fase 2 (escrituras encendidas). (d), un Administrador, revoca un número de voz y escribe en el diálogo de motivo «El titular del +57 300 000 0280 (Juan, juan@correo.co) retiró el consentimiento»: `POST /api/admin/ultron-studio/integrations/canaries/{uuid}/revoke {"reason":"…"}` → 200. El registro guardaba ese número solo como huella y 4 dígitos (y el cifrado de voz se borra en esa misma sentencia). Desde ese momento el número está en claro en dos almacenes de solo inserción. Además, cualquier persona con `ultron.view` lo lee en el listado de revocados y en el libro, y no se puede quitar sin el procedimiento de redacción con el rol dueño (pendiente del Hito 7, I13).
- **Impacto real:** no da nada a un atacante. Lo que hace es anular por un camino normal de la interfaz la minimización que el contrato promete: P5 «sin teléfonos en claro… en el libro», I8 «Nunca en el libro: teléfonos…» e I13. Es baja porque exige que quien opera lo escriba. Pero el módulo existe para manejar teléfonos, el dato no se puede borrar y lo ve todo el Studio.
- **OWASP API3** (propiedades expuestas) / minimización (Ley 1581).
- **Corrección propuesta (sin aplicar; dueño S, con FE):**
  - **Regla:** una regla del Studio (por ejemplo `StudioCanaryPhone::contactDataError()` o una regla `NoContactData`) para `reason` en 48, 52 y 53 y para `note` en 55, con un 422 de texto fijo que no repita lo escrito. Rechaza:
    - una serie de **8 o más dígitos con separadores cortos que no son letras**, respetando las fechas: el criterio de `LeadSourceClassifier::DIGIT_RUN`/`DATE_SHAPE` (`LeadSourceClassifier.php:260,267`), que ya enmascara «300 123 4567» y «+57 300-123-4567» y respeta «2026-11-01»;
    - una dirección de correo (su patrón `EMAIL`).

    Conviene copiar las expresiones al Studio con su prueba, para no añadir un lector nuevo del motor. Aplicada también al alias, cierra O-1.
  - **FE:** el texto de aviso en `ib-reason-dialog` (`AVISO_DATOS_MOTIVO`, I10).
  - **Cuándo:** antes de la fase 2. En la fase 1 no se puede escribir nada: 409 con la bandera cerrada (P-A3).
- **Prueba negativa:** en 48, 52, 53 y 55, `reason`/`note` con «+57 300 000 0280», «300-000-0280», «3000000280» o «juan@correo.co» → 422, sin filas ni evento. «Pauta 2026-11-01», «versión 3» y «tope de 10» → aceptados.
- **Dueños:** S (backend), FE (texto), tester (prueba) y DOC (I13).

### AS-2 (baja) · `GET integrations/meta/campaigns` responde 500 con un `page` enorme

- **Qué:** la ruta 40 pagina en PHP: `array_slice($filas, ($page - 1) * $perPage, $perPage)` (`StudioCampaignLinks.php:131`). El controlador valida `page` como `integer|min:1` sin máximo (`UltronStudioCampaignLinkController.php:116`) y hace `(int)` (`:49`). Con `page > ⌊PHP_INT_MAX / per_page⌋ + 1`, el producto es `float` y `array_slice()` lanza `TypeError`. Las otras tres listas (41, 42 y 44) usan el paginador de Eloquent, cuyo `offset()` convierte a entero, y responden 200.
- **Evidencia (P-B4 en SQLite y PG; P-C3 con `APP_DEBUG=false`):**
  ```
  meta/campaigns?page=9223372036854775807 → 500 {"message":"Server Error"} · log: error array_slice(): Argument #2 ($offset) must be of type int, float given
  meta/campaigns?page=461168601842738791 → 200 · per_page=50&page=184467440737095517 → 200 (el umbral)
  meta/links, canaries, twilio/calls con page=9223372036854775807 → 200 · page=99999999999999999999 → 422 en las cuatro
  ```
- **Actor:** cualquiera con `ultron.view` (el Administrador por defecto), con `GET /api/admin/ultron-studio/integrations/meta/campaigns?page=9223372036854775807`. Falla igual con Meta sin configurar: la lista vacía también pasa por `array_slice`.
- **Impacto:** un 5xx y una línea `production.ERROR` con traza por petición, hasta 120/min por token y 600/min por red. Sin fuga si `APP_DEBUG=false` en producción. Ensucia justo las dos señales del despliegue: el criterio «0 respuestas 5xx nuevas» de la fase 1 (paso 9) y el recuento de `production.ERROR` de `deploy-h6-backend-fase1.sh:378`.
- **OWASP API8** (error no controlado).
- **Corrección propuesta (sin aplicar; dueño S):** acotar `page` en las cuatro listas del hito con un `max:` razonable (p. ej. `max:100000`), o calcular el desplazamiento con guarda: `$desde = ($page - 1) > intdiv(PHP_INT_MAX, $perPage) ? count($filas) : ($page - 1) * $perPage`.
- **Prueba negativa:** en 40, 41, 42 y 44, `?page=9223372036854775807` y `?page=184467440737095518&per_page=50` → 200 con la página vacía o 422; nunca 500.
- **Dueños:** S y tester.

### AS-3 (nota) · Oráculos de pertenencia de un teléfono adivinado

- **Qué:** con la bandera abierta, quien tiene `canary_manage` (el Administrador por defecto) puede confirmar un número que adivina:
  - `409 canary_number_exists` (`StudioCanaryNumbers.php:227-235`) dice si ese número está vivo en el canal;
  - `isEngineCanary: true` y el cambio de `verdict` (`StudioCanaryCoherence.php:93-110`, `:123-132`) dicen si es el del lead de la conversación canaria del motor.
- **Evidencia (P-C1, P-C2):**
  ```
  C1 Bea ve +57 ••• ••• 0042 · intentos {"41":"201","42":"409 canary_number_exists","43":"201"} · filas tras 3 intentos: 3 (eventos registered: 3)
     · con el tope lleno, el número bueno da: 409 canary_live_limit
  C2 veredicto antes: engine_canary_not_registered · isEngineCanary(…9875)=false · isEngineCanary(…9876)=true · veredicto después: engine_canary_registered_inactive
  ```
- **Por qué es una nota:**
  - cada intento fallido deja una fila que no se borra y un evento `canary_number.registered`;
  - con 10 vivos, `canary_live_limit` responde antes del INSERT y el oráculo calla;
  - seguir exige revocar, con otra escritura auditada y otra fila permanente;
  - un móvil colombiano con los 4 últimos conocidos son unos 10⁵ candidatos, con un cupo de 30 escrituras por minuto y por token;
  - el teléfono del lead canario ya lo ve en el CRM quien tiene los permisos de Mercadeo.

  Son inherentes a la unicidad (I4.1) y a la coherencia (I4.5).
- **Recomendación (Hito 7, opcional):** una alerta cuando la misma persona registra y revoca más de N números en una hora.

### AS-4 (nota, para `security` y DOC) · La huella del teléfono es un seudónimo recuperable con la `APP_KEY`, y sus subclaves comparten clave con `Workforce\Secrets`

- **I13 no es exacto.** La tabla dice del canario de WhatsApp: «Solo huella HMAC y 4 dígitos; no se puede recuperar ni con la `APP_KEY`». Con la clave, la huella (`phone_hash`) y `phone_last4`, que guarda la propia fila, quedan unos 10⁵ candidatos (`+573` + 5 cifras + las 4 conocidas):
  ```
  F1 recuperado=true en 15392 intentos y 58 ms (espacio de 100 000)        (APP_KEY sintética)
  ```
  Es seudonimización equivalente a cifrar con la `APP_KEY`, no anonimización. Quien tenga la base y la clave tiene los números. El respaldo de la fase 1 guarda juntos `ironbody.dump` y `env.backup` (`deploy-h6-backend-fase1.sh:142-143`, root 600 en un directorio 700). El riesgo incremental es pequeño, porque ese volcado ya trae `marketing_leads.phone` en claro.
- **La misma clase que AS-6 del H5.** `PhoneFingerprint.php:64` y `StudioIntegrationConfig.php:103` derivan `k = HMAC(APP_KEY_bytes, etiqueta)`, que es exactamente `hex2bin(Secrets::hash(etiqueta))` (`Support/Workforce/Secrets.php:14-16`, misma clave decodificada). Hoy no es explotable: todos los que llaman ponen un prefijo con `|`, y ninguna de las dos etiquetas nuevas (`ultron-canary-phone/1`, `ultron-studio-config-fingerprint/1`) lleva `|`. Los prefijos son `pairing|`, `kiosk|`, `pin|` y `{purpose}|{id}|`, en `DeviceRegistry.php:47,88,105`, `KioskTokens.php:75,98,108`, `WorkforcePins.php:259` y `AuthorizationCodeService.php:76,124`. Si algún día se devolviera un `Secrets::hash(<entrada del usuario>)`, la subclave del teléfono permitiría recuperar fuera de línea todos los números del registro **sin** la `APP_KEY`.
- **Recomendación:**
  - DOC: corregir I13 («seudónimo: recuperable con la `APP_KEY`; se protege como un cifrado»).
  - `security`/architect: escribir la invariante en los dos ficheros y, en un contrato `/2`, derivar con `hash_hkdf('sha256', $appKey, 32, etiqueta)` o dar a `Secrets` su propia subclave.

### AS-5 (nota, para OPS y `security`) · Los hosts de la sonda dependen de `APP_ENV`

- **Comprobado (P-D2):**
  ```
  production, con ULTRON_STUDIO_TWILIO_PROBE_BASE=http://169.254.169.254/latest/meta-data y TWILIO_BASE=http://10.0.0.5:8080:
    201 · GET https://api.twilio.com/2010-04-01/Accounts/AC…/{.json, IncomingPhoneNumbers.json, OutgoingCallerIds.json, Calls.json} · GET https://monitor.twilio.com/v1/Alerts
  fuera de production: los cinco GET van a http://169.254.169.254/latest/meta-data/… con la credencial en Basic
  ```
  Ninguna URL del cliente llega a una petición. El cliente solo construye URL con sus dos constantes o con la base del entorno y un SID validado `^AC[0-9a-f]{32}$` (`TwilioReadOnlyClient.php:184-204`), sin redirecciones (`:145`).
- **Lo que queda:**
  - Fuera de `production`, la credencial va a la base que diga el entorno. Ya lo señaló TW (`informe-twilio.md`, «Para security / api-security-specialist», punto 6) y E1.4: solo puede ponerla quien ya puede leer el token.
  - La fijación en producción descansa en una sola condición (`app()->isProduction()`, `TwilioReadOnlyClient.php:186`), y ningún script la afirma. El despliegue solo cuenta líneas `production.ERROR`, que lo sugieren sin comprobarlo.
- **Recomendación (OPS):** una comprobación en `deploy-h6-backend-fase1.sh` o en `smoke-run-h6.sh` de que el proceso web ve `production`, sin imprimir valores (por ejemplo, `php artisan about --only=environment --json` filtrado a ese campo). Esto vale antes de `h6-integrations-scope.sh probe on`.

### AS-6 (nota, documentación) · `clientRequestId` vale por recurso

- **P-A4:**
  ```
  A4 ana 1.ª: 201 · ana mismo número con separadores y llave en MAYÚS: 200 replayed=true · bea mismo cuerpo: 409 client_request_reused (sin el número)
     · ana otro número / otro alias / otro canal: 409 client_request_reused · root ack: 201 · root sonda con la llave del ack: 409 client_request_reused
     · ana asociación con la llave del canario: 201
  ```
- **Qué pasa:** dentro de cada tabla, la llave queda atada a la persona y al cuerpo: canal, huella, alias y versión en el registro; campaña, destino y `replaces` en las asociaciones; proveedor, huella y nota en el reconocimiento. Reconocimiento y sonda comparten tabla y se rechazan entre sí. Entre tablas distintas la llave no choca. No hay doble efecto posible y el contrato no pide otra cosa (en el H5 la llave también era de una tabla). Basta con decirlo en la documentación del CRM: cada acción genera su uuid, como ya hace.

### 3.7 Heredado y conocido (no son hallazgos nuevos)

- **AS-5 del H5, invisibles que `PlainText` no conoce (P-G1).** U+00AD, U+2060, U+2028 y U+E0041 pasan a `revoke_reason`; U+200B y U+202E dan 422. Desde H6 llegan a tres columnas más que no se pueden corregir (`revoke_reason`, `deactivation_reason` y la `note` de las instantáneas), además del libro. Sigue pendiente para el Hito 7. Conviene ampliar `UntrustedText::INVISIBLE` antes de la fase 2, con la lista del informe del H5.
- **O-1, alias con dígitos en grupos.** P-B1: «Móvil 300 123 45 67» → 201. La regla propuesta en AS-1 lo cierra.
- **Alias con nombre de persona.** «Móvil de Juan Pérez» → 201. Lo acepta el contrato, con el aviso del CRM (I13), y el libro no lleva el alias.
- **`marketing_calls.reason` en la ruta 44.** Es una columna de texto libre del motor, y su único escritor hoy escribe `'followup'` (`DispatchMarketingFollowups.php:136`). En el hito de voz: lista blanca de valores o fuera del DTO.
- **Cuatro ojos por cuenta, no por persona.** Un Super Admin con `users.manage` puede crearse una segunda cuenta con `canary_authorize`. Es un control organizativo, como en el H5 §7.

---

## 4. Los ocho puntos del encargo, uno a uno (lo comprobado)

1. **Autorización por ruta y por objeto.**
   - El mapa resuelve exactamente las 18 llaves de I7.1 (prueba de S `test_el_mapa_resuelve_exactamente_las_56_rutas…`, ejecutada).
   - La matriz entera de I7.1 sale en las pruebas de S, ejecutadas en SQLite y PG: sin sesión 401, token compartido 403 (dominio `ultron` excluido, `CrmPermission.php:237`), Recepción, Entrenador y Administrativo 403 en las 18, Administrador 200 en 39–46, permitido en 47–49 y 51–54 y 403 en 50, 55 y 56, Super Admin en todas.
   - Una llave sin `ultron.view` da 403 antes de buscar el recurso (P-A3).
   - Las variantes de ruta no cambian la llave (P-A1).
   - Los uuids son texto, sin binding: `whereUuid`, `strtolower` en el servicio y 404 sin fuga (§2).
   - Cuatro ojos por **identidad** (`StudioAccess.php:172-175`), probados con dos Super Admin que tienen todas las llaves (P-A2):
     ```
     A2 X authorize: 403 four_eyes_required "authorize" · otra vez: 403 · Y authorize: 200 · X activate: 403 four_eyes_required "activate" · otra vez: 403
        · Y activate: 200 · eventos four_eyes_denied: 2
     ```
     En la base, sin pasar por el servicio (P-E1, PG): `uscn_four_eyes_chk`, `uscn_activation_four_eyes_chk` y `uscn_active_chk` dan 23514. Cambiar `registered_by_admin_id` da P0001. `registered_by_admin_id` es inmutable, así que la comprobación fuera de la transacción no tiene ventana de carrera.
2. **Escrituras cerradas, orden e idempotencia.**
   - **Bandera y orden:**
     - con la bandera cerrada, 47–54 dan 409 después de la forma (P-A3: 422 con `reason: "x"`);
     - 55 no depende de la bandera (prueba de S);
     - 56 da 409 `probe_disabled` (prueba de S).
   - **Carreras con sesiones rivales de verdad en PostgreSQL** (P-R, con el arnés `PublicationRaceSessions` del H5 y una barrera de cerrojo consultivo):
     ```
     R1 4 activaciones contra tope 3: ["200","200","200","409 canary_active_limit"] · activos al final: 3
     R2 misma llave y cuerpo: ["200 replayed","201"] · mismo número con dos llaves: ["409 canary_number_exists","201"] · filas: 2
     R3 misma campaña: ["201","409 campaign_already_linked"] · tope 2 con una activa y dos nuevas: ["201","409 campaign_links_limit"] · activas: 2
     ```
     El doble clic responde **200 `replayed`**: el registro vuelve a mirar el reintento al recibir el 23505, fuera de la transacción (`StudioCanaryNumbers.php:227-232`, y el `catch` de `:174-180`). La clase AS-4 del H5 aquí no aparece.
   - **Cerrojo:** `SET TRANSACTION ISOLATION LEVEL READ COMMITTED` y `pg_advisory_xact_lock` van primero en cada transacción con tope (`StudioIntegrations.php:127-133`; usos en `StudioCanaryNumbers.php:192,276` y `StudioCampaignLinks.php:233`).
3. **Exposición excesiva.**
   - La prueba centinela de S (ejecutada en SQLite y PG) recorre las 18 rutas, los 422 y 409 con teléfono, el libro, los `facts`, la consola y el log. No aparecen el token de Twilio, el de Meta, las claves de ElevenLabs, la llave de la sonda, el teléfono del lead canario, los números registrados ni sus huellas.
   - Mis pruebas añaden:
     - el 422 del teléfono no repite lo escrito en ninguno de los 32 casos de P-B1;
     - `auth_token`, que devuelve la ficha de la cuenta de Twilio, no llega a la respuesta ni a `facts` (P-D2; `TwilioReadOnlyClient.php:173`);
     - `GET status` no lleva valores de configuración;
     - los DTO salen de listas explícitas (`StudioCanaryNumbers.php:434-469`, `StudioCampaignLinks.php:389-422`, `StudioTwilioPanel.php:87-117`, `:170-188`);
     - el id de la conversación canaria y `engineHash` no salen (`StudioCanaryCoherence::publicBlock`, `:135-142`).
   - Lo único que sí sale es AS-1.
4. **Asignación masiva e inyección.**
   - Las tres inserciones usan `forceCreate` con columnas que calcula el servidor (`$guarded = ['*']`).
   - P-B2: los campos de más se ignoran: `status`, `registered_by_admin_id`, `authorized_by_admin_id`, `phone_hash`, `phone_last4`, `country_code`, `consent_attested:false`, `phone_encrypted`, `uuid`, `id`, `campaign_id`, `ad_account_id`, `created_by_admin_id`, `facts`, `kind`, `actor_type` y `outcome`:
     ```
     B2 canario ["pending",true,null,null,"0250","57",null] · enlace ["active",true,"120000000000000002","1000000000004714","Pauta 1","baseline",null] · instantánea ["config","admin",true,null,true]
     ```
   - **Validación campo a campo (P-B1, P-B3):**

     | Campo o caso | Resultado |
     |---|---|
     | Teléfono con tabulador, barra, ancho completo, arábigo-índico, sin `+`, colombiano corto, fijo en WhatsApp, otro país, 33 caracteres, lista o entero | 422 |
     | Teléfono con `\n` o espacio final | Lo recorta `TrimStrings` (D-8): 201, mismo número |
     | Fijo `60X` en voz | 201 |
     | Alias de 2 o de 41 caracteres, con 4 dígitos, con `@`, NFD, RLO o `<b>` | 422 |
     | `consentAttested` 1, `"1"`, `"true"`, `"yes"` o `false` | 422 (`boolean:strict` existe en Laravel 12.58) |
     | `reason` de 4 caracteres, de solo espacios, de 501, con BEL, con RLO o en lista | 422 |
     | Expectativa `{}` o no uuid | 422; inventada → 404 |
     | Asociación: `baseline` con `versionUuid`, `version` sin uuid, `target` texto o lista, campaña en MAYÚSCULAS, `replaces` no uuid, otro `kind` | 422 |
     | Reconocimiento: huella en MAYÚSCULAS o de 63, proveedor ajeno, nota de 501 | 422 |
     | Cuerpo de 70 KB | 413 `payload_too_large` |
     | JSON de 600 niveles | 422 |

     Las listas de consulta (`status`, `channel`, `linked`, `account`) van por lista blanca o expresión con `D`. No hay SQL construido con entrada: los filtros van por el constructor con parámetros.
5. **Cupos y abuso (P-D1, con los limitadores activos):**
   ```
   D1 misma persona, dos sesiones: ["201","200reused","200reused","200reused","200reused","200reused","429 too_many_requests"]
      · 3 personas más × 5 desde la misma red: {"200":14,"429":1} · GET reales a Twilio: 5 · instantáneas probe: 1 · eventos probe_run: 1
   ```
   - 6/h por **persona** (otra sesión no da más; `X-Forwarded-For` distinto en cada petición no cambia nada) y 20/h por red: las 6 de la primera persona y 14 más.
   - El enfriamiento de 300 s devuelve la última sin red ni evento.
   - El cerrojo de la caché pone en fila dos sondas simultáneas (`StudioTwilioProbe.php:97-110`; leído, no ejecutado en concurrencia).
   - Peor caso de una sonda, leyendo el código (no medido): unos 31 s. Si la cuenta no responde, se salta el resto; cada paso lleva lo que queda de los 30 s, como mucho 10 (`StudioTwilioProbe.php:172-195`).
6. **SSRF:** ninguna URL del cliente llega a una petición. En `production` se ignoran la base de la sonda y `TWILIO_BASE` (P-D2). Lo que queda es AS-5.
7. **Libro:**
   - Cada escritura registra su evento **dentro** de la transacción del cambio (`StudioCanaryNumbers.php:191-226`, `:250-263`, `:275-295`, `:306-315`, `:326-348`, `:365-369`; `StudioCampaignLinks.php:232-292`, `:334-345`; `StudioIntegrationConfig.php:369-393`; `StudioTwilioProbe.php:130-152`).
   - `four_eyes_denied` va aparte, deduplicado 10 minutos con un cerrojo (`StudioAudit.php:107-137`; P-A2: 2 eventos para 4 intentos).
   - Hay 3 sujetos nuevos en `SUBJECT_TYPES`. Los rechazos por bandera no se auditan (se lanzan antes de escribir).
   - El `diff` no lleva teléfonos, sus dígitos, huellas, alias ni ids de Meta en claro (`campaign` opaco, `accountRef` enmascarado). La excepción es el texto libre de AS-1.
   - El libro rechaza UPDATE (P-E1: P0001).
8. **Logs y excepciones:**
   - Las líneas nuevas son cuatro, y llevan solo la clase del error (y el proveedor o el paso):
     - `ultron_studio.integration_status_unavailable` (`StudioIntegrations.php:530`);
     - `ultron_studio.canary_coherence_unreadable` (`StudioCanaryCoherence.php:181`);
     - `ultron_studio.canary_chain_unreadable` (`StudioCanaryNumbers.php:580`);
     - el aviso de `TwilioReadOnlyClient` (`:155`).
   - Las fábricas nuevas de `UltronStudioException` tienen textos fijos, con números de tope o etiquetas de estado, y no se registran (`report()` devuelve `true` por debajo de 500).
   - `LogicException` e `InvalidArgumentException` del hito no llevan valores.
   - El único ERROR que provoqué es el de AS-2: el mensaje de `TypeError`, sin datos.

---

## 5. Lo que ejecuté (comando y resultado)

Todo desde `backend/` del worktree. La salida completa está en `…/scratchpad/apisec/out/*.txt`, que se purga a los pocos días; lo relevante va arriba.

| # | Comando | Resultado |
|---|---|---|
| 1 | `phpunit … UltronStudioIntegrations{Permissions,Canary,CampaignLink,Config,Probe,NoNetwork,Sentinel,Status}Test.php UltronEngineIsolationTest.php` (SQLite) | `OK (61 tests, 2384 assertions)` en 15 s |
| 2 | `php artisan route:list --path=admin/ultron-studio[/integrations] --json -vv` | 56 del Studio; las 18 con `api`, `LimitRequestBodySize:65536` y su cupo; la 56 con `-write` y `-probe` |
| 3 | `php artisan auth:coverage --unmapped` | sin salida, exit 0 |
| 4 | `git diff --stat 7ba0ce8 HEAD -- config/cors.php bootstrap app/Http/Middleware config/auth.php config/sanctum.php routes/api.php routes/marketing.php app/Support/Access/AdminActor.php app/Services/Admin` y la puerta de diff del motor del contrato | las dos vacías |
| 5 | `phpunit … poc/ApiSecH6AccessProbeTest.php` (P-A1…A4) | `OK (4 tests, 33 assertions)` |
| 6 | `phpunit … poc/ApiSecH6InputProbeTest.php` (P-B1…B5) | `OK (5 tests, 42 assertions)`: AS-1, AS-2 y §4.4 |
| 7 | `phpunit … poc/ApiSecH6OracleProbeTest.php` (P-C1…C3) | `OK (3 tests, 6 assertions)`: AS-2 y AS-3 |
| 8 | `phpunit … poc/ApiSecH6ProbeProbeTest.php` (P-D1, P-D2) | `OK (2 tests, 9 assertions)`: §4.5 y AS-5 |
| 9 | `phpunit … poc/ApiSecH6FingerprintProbeTest.php` (P-F1) | `OK (1 test, 1 assertion)`: AS-4 |
| 10 | `phpunit … poc/ApiSecH6InvisibleProbeTest.php` (P-G1) | `OK (1 test, 7 assertions)`: §3.7 |
| 11 | `psql … create database ultron_studio_h6_apisec` · `UNIT_DB=ultron_studio_h6_apisec ops/pgunit.sh … poc/ApiSecH6PgGuardProbeTest.php` (P-E1) | `OK (1 test, 13 assertions)`: CHECK y disparadores |
| 12 | `UNIT_DB=… ops/pgunit.sh … UltronStudioIntegrations{Schema,Canary,CampaignLink,Permissions,Config,Probe,Sentinel}Test.php` + las 4 PoC de 5–8 | `OK (81 tests, 1737 assertions)` en 14 s: las mismas salidas que en SQLite (AS-1 y AS-2 también en PG) |
| 13 | `UNIT_DB=… ops/pgunit.sh … poc/ApiSecH6RaceProbeTest.php` (P-R1…R3) | `OK (3 tests, 11 assertions)`: carreras de §4.2 |
| 14 | `psql … drop database ultron_studio_h6_apisec` | `count = 0` |

Las PoC que **afirman el comportamiento correcto** pueden pasar tal cual a la suite: A1–A4, B1–B3, C1, C2, D1, D2, E1 y R1–R3. Las de los hallazgos afirman **lo de hoy** y hay que invertirlas al corregir: B5 (AS-1), y B4 y C3 (AS-2). F1 y G1 documentan notas.

---

## 6. Lo que no verifiqué

- **El servidor:** `APP_ENV=production` y `APP_DEBUG=false` (AS-2, AS-5), la ausencia de un proxy delante (`ClientNetwork` usa `REMOTE_ADDR`, sin `TrustProxies`, como en el H5) y los privilegios reales de `iron_app` sobre las tres tablas nuevas.
- **Twilio de verdad:** solo `Http::fake`.
- **La puerta PG completa** (el grupo entero del Studio), Marketing, OTP y el E2E con la pila real. Ejecuté solo las siete clases de integraciones de S en PG, más las mías.
- **Lo del tester:** `UltronStudioIntegrationsRaceTest.php`, que estaba apareciendo mientras escribía; no lo ejecuté ni lo leí.
- **El CRM:** el texto `AVISO_DATOS_MOTIVO` y que el botón de la sonda se oculte sin `can.integrationsManage` (FE/`security`).
- **Los comandos de consola** (`integrations-doctor`, `integrations-snapshot --verify-voice`), salvo lo que recorre la centinela de S.

---

## 7. Riesgos residuales y decisiones del propietario

- **AS-1 antes de la fase 2.** Hay que decidir entre la regla del servidor (recomendada) o aceptar solo el aviso del CRM hasta el Hito 7. En la fase 1 no se puede escribir nada.
- **Cuatro ojos por cuenta.** Es organizativo (§3.7).
- **La `APP_KEY` protege también los teléfonos del registro** (AS-4). Rotarla deja inservibles huellas, cifrados, ids opacos y huellas de configuración (I13). Filtrarla, con la base, los descubre.
- **Secretos que rotar: ninguno.** No he visto ni impreso ningún secreto real. Las pruebas usan valores centinela y una `APP_KEY` sintética.
- **Necesitan orden:** las correcciones de AS-1 y AS-2 (S); lo demás son notas.

## 8. Para cada agente

- **S (backend):**
  - AS-1: regla de datos de contacto para `reason` y `note` en 48, 52, 53 y 55, y para el alias; texto fijo en el 422.
  - AS-2: acotar `page` en 40, 41, 42 y 44, o guardar el desplazamiento.
- **FE:** AS-1, el aviso del diálogo de motivo y el texto del nuevo 422.
- **tester:**
  - pruebas negativas de AS-1 y AS-2;
  - pasar a la suite las PoC que afirman lo correcto (§5);
  - R1–R3 son la forma mínima de las carreras de I11, por si su `UltronStudioIntegrationsRaceTest` no las cubre.
- **OPS:** AS-5, que el proceso web está en `production` antes de `probe on`; AS-2 se vería como `production.ERROR`.
- **security:**
  - AS-4: invariante de la clave HMAC compartida y texto de I13;
  - AS-5: la base fuera de `production`, ya en su lista por TW y E1.4;
  - la parte de minimización de AS-1;
  - AS-5 del H5 (invisibles), que ahora alcanza tres columnas inmutables más.
- **lead/DOC:**
  - I13 (AS-4);
  - AS-6 en la documentación del CRM;
  - y lo diferido.

**Diferido al Hito 7 (o al hito que lo traiga):**
- la redacción con el rol dueño de las tres tablas nuevas (I13);
- invisibles de `PlainText` (AS-5 del H5) y O-1, si no entran antes con AS-1;
- la alerta de AS-3;
- HKDF y separación de dominios de las subclaves en un contrato `/2` (AS-4);
- `marketing_calls.reason` en el hito de voz;
- programar `integrations-snapshot`;
- T2 y T3, que necesitarán su propia revisión de API, porque el registro pasará a restringir al motor.
