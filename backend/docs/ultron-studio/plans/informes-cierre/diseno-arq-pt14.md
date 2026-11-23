# PT-14 · Gobierno de lo que Sofía dice de los planes (B-1 / C-1 iii) · diseño de architect · 22-11-2026

**Base:** `_worktrees/lote-3/Iron-Body`, rama `integracion/voz` @ `b1f66c32` (backend); CRM `_worktrees/cierre-crm/Iron-Body_Front`, rama `cierre/crm-lote5` @ `9d5a8e6`. Solo lectura; sin red, sin producción.
**Marcas:** [L] leído en el código · [S] supuesto, a comprobar por quien implemente.
**Alcance (literal del propietario):** A (permiso específico, por defecto solo Super Admin) + B (publicación con cuatro ojos sobre la publicación de voz existente), sin duplicar lógica, sin tocar precios y sin que una persona satisfaga las dos aprobaciones.

---

## 1. Resumen en diez líneas

1. **A:** permiso nuevo `plans.commercial_text` («Planes → Nombre y beneficios»), comprobado en el controlador como `payments.backdate`. Por defecto solo lo tiene Super Admin: se excluye del Administrador igual que `plans.archive`.
2. **Qué exige A:** toda escritura que deje un plan **vendible** (`Plan::sellable()`) con nombre o beneficios distintos, y toda **entrada** de un plan al conjunto vendible: crearlo vendible, activarlo, ofrecerlo fuera, darle precio o vigencia > 0 o reactivarlo. La comparación es por valor, así que reenviar lo mismo no lo pide. Precio y demás campos no cambian.
3. **Autoría:** quien escribe ese texto, o pone el plan a la venta, queda como autor en `plans.commercial_text_by`. Es una columna nueva, se escribe en la misma transacción y va oculta en el listado público.
4. **B, la captura:** al publicar la voz, el servidor captura el texto comercial de los planes vendibles: id, nombre y beneficios, nunca el precio. Lo guarda con su huella en la `evidence` de la entrada `publish` de la cadena de voz, que es de solo inserción. El Studio no necesita migración.
5. **B, lo que se ve:** la vista previa enseña ese catálogo y su huella. Publicar exige `expectedCatalogHash` (409 `catalog_changed` si cambió).
6. **Cuatro ojos:** quien publica o promueve no puede ser autor de ningún plan capturado (403 `four_eyes_required`, `detail: plan_text_author`). Es la misma regla que ya aplica `StudioRulePhraseAuthors`: se comparan identidades, no permisos.
7. **En la llamada:** `consultar_planes` entrega lo publicado ∩ lo vigente. Un plan que cambió de nombre, o que no estaba publicado, no se entrega. De sus beneficios solo salen los publicados que siguen en el catálogo. El precio y las condiciones salen en vivo. Sin catálogo publicado legible no sale ningún plan (falla cerrado).
8. **Deriva:** la del catálogo se calcula sin red en `GET voice/publications` y se pinta en «Lo que rige ahora». No echa el freno. Se corrige volviendo a publicar; el `specHash` no cambia, así que el veredicto del laboratorio sigue valiendo.
9. **Una sola definición** del texto comercial, en `Plan`: la usan A, la captura, la deriva y `VoiceTools`. WhatsApp y Legacy siguen leyendo en vivo: A los protege; B protege solo la voz.
10. **Dos frentes:** servidor (una migración y unos 15 ficheros) y CRM (permiso, editor de planes y publicación de voz). Se despliega después del lote 3 y **antes de la primera publicación de voz**; así no hay que volver a publicar.

---

## 2. Cómo funciona hoy (solo lo necesario)

**Lo que dice Sofía de los planes**
- `consultar_planes` lee **en vivo** `Plan::query()->sellable()->orderBy('sort_order')->orderBy('duration_days')->limit(20)`, con `name, price, duration_days, benefit_only, benefits` (`app/Services/Telephony/VoiceTools.php:388-392`). Solo filtra: el nombre por `spokenCodes()` (`:402-410`, C-1 ii) y los beneficios por `benefitsOf()` (`:487-531`). Ese filtro mira control/longitud/`spokenCodes`, cuenta `benefitsWithheld/benefitsOmitted/plansWithheld` (`:451-456`) y examina como mucho 18 beneficios por plan (`:116`) [L].
- El laboratorio hablado recibe exactamente lo mismo (`labAnswer`, `:343-351`) [L].
- Ni huella, ni publicación, ni deriva. B-1 en `plans/informes-cierre/informe-security-lote3.md:45-51` [L].

**Quién edita los planes**
- `PlanController` → dominio `plans` (`app/Support/Access/AuthorizationMap.php:184`). Las escrituras exigen `plans.manage`, que tienen Super Admin y Administrador. El Administrador es «todo menos la lista» (`app/Support/Access/CrmPermission.php:289-348`) y Recepción solo tiene `plans.view` (`:365`) [L].
- `archive`/`reactivate` exigen `plans.archive` (`AuthorizationMap.php:588-589`), que es solo de Super Admin por defecto (`CrmPermission.php:347`) [L].
- `update` admite editar el nombre y los beneficios de cualquier plan, también uno archivado. Solo impide volver a encenderlo editándolo (`PlanController.php:221-235`). La auditoría guarda el **nombre** del campo cambiado, pero el antes/después solo de precio, vigencia y canales (`:247-260`, `app/Services/Audit/AuditTrail.php:103-130`) [L].
- `benefits` va `nullable` sin forma: un array se guarda como JSON y una cadena, tal cual (`PlanController.php:841`, `:883-889`) [L].
- **El CRM manda siempre `name` y `benefits` como TEXTO.** Si el plan no tiene beneficios, rellena unos por defecto al guardar (`Iron-Body_Front/src/app/modules/components/edit-plan-modal.ts:1360-1364`, `:1622`, `:1697-1712`) [L].
- Plan vendible = `active && sellable && archived_at IS NULL && price > 0 && duration_days > 0` (`app/Models/Plan.php:302-311`). `sellable` nace en `true` [L: `2026_09_17_040000_add_sellable_to_plans_table.php:33`].
- WhatsApp lee el mismo `Plan::sellable()` y `benefitsArray()` (`app/Services/Marketing/MarketingKnowledgeBaseService.php:125-145`, `app/Services/Marketing/Ultron/GymFactsProvider.php:91-96`). Además lee `badge` y `restrictions` [L].

**La publicación de voz (lo que B reutiliza)**
- **Cuatro ojos que ya existen:**
  - versión aprobada por otra persona (`StudioVoiceCompiler.php:382-384`);
  - perfil aprobado por otra persona (`:458-460`);
  - quien publica o promueve no creó la versión ni el perfil (`StudioVoicePublishing.php:1084-1094`);
  - ni escribió frases de reglas que la versión copia (`:1096-1103`, `StudioRulePhraseAuthors.php:13-45`).
  - El veredicto del laboratorio lo da otra identidad, con un CHECK en la base [L].
- **Lo que se vio es lo que se publica:** `expectedSpecHash` → 409 `spec_changed` (`StudioVoicePublishing.php:285`) [L].
- **Precedente directo:** los destinos de transferencia se fijan **al publicar** y la telefonía los lee sin nombrar el Studio (`StudioVoicePublishing.php:607`, `VoicePublishedConfig.php:257-300`, `:351-364`). Una llamada usa los del agente que la selló; si la parte apunta a otro agente, ninguno (`VoiceTransferDirectory.php:52-61`) [L].
- **La entrada `publish` ya lleva una `evidence` sin firmar** (`{number: …}`). La tabla es de solo inserción: hay disparador y REVOKE de UPDATE/DELETE para `iron_app` (`2026_11_10_100001_create_ultron_studio_voice_tables.php:650`, `:457`, `revokeAppWrites` `:899-937`). `entryDto` solo lee de ahí `number` (`StudioVoicePublications.php:501-515`) [L].
- **Los agentes, en cambio, congelan sus columnas por LISTA** con un disparador `BEFORE UPDATE OF …` (`…voice_tables.php:226-229`, `:446`), y `UltronStudioVoiceSchemaTest.php:279-300` fija sus columnas [L].
- **Producción:** 0 publicaciones de voz, 0 llamadas, llamadas cerradas en el código (`MATRIZ_CIERRE_V2.md` §8.2; `VoiceCallPolicy.php:23`) [L].

---

## 3. Decisiones

**D1 · A es un permiso nuevo, comprobado en el controlador:** `plans.commercial_text`.
- Va en `AuthorizationMap::CONTROLLER_ENFORCED` (`:1026`), para que el catálogo lo ofrezca.
- Por defecto solo Super Admin: se añade a la lista de exclusiones del Administrador, junto a `plans.archive`.
- *Descartado:*
  - reutilizar `plans.archive` (es otra autoridad: retirar de la venta);
  - un permiso por ruta: el nombre viaja en el mismo `PUT` que el precio, y la ruta no puede distinguirlos (mismo caso que `payments.backdate`).

**D2 · Lo que gobierna A: el conjunto vendible.** Pide el permiso cuando el plan resultante es vendible y además se cumple una de dos:
- **(a)** su texto comercial cambió;
- **(b)** antes no era vendible.

Así se cierra el agujero de dos pasos: editar el texto con el plan fuera de la venta y luego encenderlo. Apagar, archivar y borrar van en la dirección segura y no lo piden.
- *Descartado (T):* exigirlo para el texto de **cualquier** plan. El Administrador no podría crear ni duplicar ningún plan, tampoco los de solo mostrador, que no llegan a ninguna máquina. Además va más allá de «planes vendibles».
- *Descartado:* (a) sin (b). Deja el agujero abierto para WhatsApp, que lee en vivo.

**D3 · «Cambió» se compara por VALOR, no por presencia de la clave.**
- El texto se normaliza con `Plan::commercialText()`: nombre con `trim` y beneficios como los da `benefitsArray()`.
- Una clave que no llega cuenta como sin cambio.
- *Descartado:* mirar qué claves llegan. El CRM manda `name` y `benefits` en cada guardado (§2), así que cada cambio de precio del Administrador sería un 403.

**D4 · El autor queda en una columna de `plans`:** `commercial_text_by` (FK a `admins`, `nullOnDelete`, como `archived_by`).
- Se escribe en la misma transacción cada vez que el texto cambia o el plan entra en la venta.
- Significa: «la última persona que escribió el texto comercial vigente o lo puso a la venta».
- *Descartado:*
  - **sacarlo de `audit_logs`.** La tabla nació «auto-reportado por el CRM; nunca para autorizar» (`2026_06_15_000001_create_audit_logs_table.php:34`), porque las filas antiguas las escribía el navegador. Además ataría los cuatro ojos al formato de `changes`.
  - **autoría por beneficio:** obliga a cambiar el formato de `benefits`, que leen WhatsApp y la app.

**D5 · La instantánea va en `evidence.catalog` de la entrada `publish`, no en el agente ni en la especificación.**
- La entrada ya es inmutable (disparador y REVOKE) y su `evidence` ya lleva datos sin firmar del acto de publicar, como `number` y `lab_gate`.
- *Descartado:*
  - **columnas en `ultron_studio_voice_agents`:** obliga a migrar una tabla con disparadores por lista de columnas, a recrear `usva_forbid_immutable_update` y a cambiar las columnas fijadas en la prueba de esquema;
  - **meterla en la especificación compilada:** cambia `specHash`, el mapper, las fixtures y la carga del proveedor, y cada edición de un beneficio invalidaría el veredicto del laboratorio y crearía un agente distinto en ElevenLabs.

**D6 · Qué se captura.**
- Los planes de la MISMA consulta que `consultar_planes`: vendibles, en su orden y como mucho 20.
- De cada uno: `{id, name, benefits}` normalizados por `Plan::commercialText()`, como mucho 18 beneficios.
- Los beneficios de más de 255 caracteres no se guardan: nunca se dicen, porque el tope de la voz es 90.
- **Nunca** precio, vigencia ni reglas de acceso: «precios intactos».
- La huella es el sha256 del JSON canónico de `plans`. Al lado van los autores: `authors: [{planId, by|null}]`.

**D7 · Qué dice Sofía tras un cambio del catálogo: lo publicado ∩ lo vigente.** Plan a plan, por id:
- no estaba en la instantánea → no se entrega (`plansUnpublished`);
- el nombre ya no es el publicado → no se entrega;
- beneficios → solo los actuales que estaban publicados (`benefitsUnpublished` cuenta los demás);
- precio y condiciones → los de ahora.

Encima siguen todos los filtros de hoy (`benefitsOf`, `spokenCodes`, topes).

Si la parte no tiene catálogo publicado o no se puede leer (huella o forma mal), no sale ningún plan y se usa la nota existente `NO_DELIVERABLE_PLANS_NOTE`.

*Por qué es la opción más simple que no deja a Sofía muda sin motivo:* editar un beneficio no la deja sin el plan; nunca dice un texto no aprobado; nunca dice un beneficio que ya se quitó; y no añade notas ni flujos.
- *Descartado (a): decir el texto publicado tal cual.* Afirmaría beneficios retirados, que pueden ser una oferta falsa (Ley 1480 [S]), y podría casar un nombre viejo con un precio nuevo.
- *Descartado (b): retener el plan entero ante cualquier cambio.* La deja muda por corregir una tilde de un beneficio.

**D8 · Qué catálogo usa una llamada: el del agente que la selló.** Es la regla de los destinos (`VoiceTransferDirectory::partFor`, `:52-61`): vale el catálogo de la parte vigente si apunta al mismo agente; si no, falla cerrado.
- `partFor` se sube a `VoicePublishedConfig::partForCall()` y lo usan los dos: la regla no se duplica.
- *Descartado:* leer el agente por su id desde la telefonía. Sería una consulta y una semántica distintas de las de los destinos.

**D9 · El laboratorio hablado sigue leyendo EN VIVO (sin cambios).**
- Prueba una compilación todavía no publicada, y lo que capturaría publicarla ahora es justo el catálogo vigente. Quien aprueba lo ve en la vista previa (D11).
- E18-8 («los mismos datos que una llamada») se enmienda a «los que capturaría publicar ahora».
- *Descartado:* que el laboratorio use la instantánea publicada. Antes de la primera publicación no daría ningún plan, y probaría otra cosa distinta de lo que se va a publicar.

**D10 · Cuatro ojos de B.**
- **Proponente** = autor del texto (`commercial_text_by`, D4).
- **Aprobador** = quien publica (o promueve) la voz que lo recoge.
- **Regla:** quien publica o promueve no puede ser autor de ningún plan de la instantánea, sea Super Admin o no.

Esa es la frase del propietario: una misma persona no satisface la aprobación de A (escribir o poner a la venta con el permiso) y la de B (publicar).
- Se compara la identidad, como en `StudioRulePhraseAuthors`, y se suma a las comprobaciones de `assertFourEyes`, después de la de las frases de reglas.
- Promover mira los autores guardados en la instantánea del agente canario.
- Revertir y apagar no la miran: van en la dirección segura, como hoy.
- **Autor `null`** (texto anterior a PT-14, o escrito por consola) no excluye a nadie. La vista previa lo marca como «sin autor registrado».
- *Descartado:* fallar cerrado con `null`. Nadie podría publicar hasta inventar una acción de «reclamar el texto», que es una funcionalidad nueva.
- *Descartado:* eximir los planes que no cambiaron desde la publicación anterior. Es complejidad opcional; el coste de no hacerlo es solo que publica otra persona.

Quien edita el texto de un plan **sí puede** aprobar la versión del Studio, el perfil o el veredicto del laboratorio: ninguno de esos lleva el texto del plan. **No puede** publicar ni promover la voz que lo recoge.

**D11 · Lo que se vio es lo que se publica.**
- `compile-preview` (propósito `publication`) devuelve `catalog` con la lista, la huella y `fourEyes`.
- `publish` exige `expectedCatalogHash`. Si la captura de ahora da otra, 409 `catalog_changed` con `{expected, actual}`, antes de desplegar.
- Es obligatorio: un CRM viejo no puede publicar (falla cerrado) porque no enseña el catálogo.
- *Descartado:* hacerlo opcional, porque permitiría aprobar a ciegas.

**D12 · La deriva del catálogo: pura, en la lectura, sin freno.**
- `StudioVoiceDrift::catalogVerdict($publicado, $vigente)` es estático y sin red, junto a `numberVerdict`.
- `StudioVoicePublications::state()` la añade por parte.
- No echa el freno ni bloquea promover: Sofía ya retiene lo que no está aprobado (D7).
- *Descartado:* el freno ante una deriva del catálogo. Cada edición del catálogo pararía la voz.

**D13 · Contadores propios en el evento y en la línea:** `plansUnpublished` y `benefitsUnpublished`, separados de los retenidos por la guarda.
- Uno se arregla volviendo a publicar y el otro corrigiendo el texto.
- `codes` admite números (`VoiceEvents.php:16`).

**D14 · Sin banderas ni configuración nuevas.**
- B queda bajo la bandera de publicar que ya existe; las llamadas siguen cerradas.
- A depende del permiso, que el propietario reparte desde Roles.

**D15 · La única definición del texto comercial vive en `App\Models\Plan`.**
- `Plan` ya lo usan WhatsApp, la telefonía y el Studio, sin cruzar capas.
- La regla de A (qué escritura pide el permiso y si el editor bloquea) va en una pieza pequeña, `App\Support\Access\PlanCommercialText`. Sigue el precedente de `InventoryCosts`, la clase que aplica `inventory.cost`, y la usan `PlanController` y `PlanArchive`.
- *Descartado:* repetir la regla en los dos sitios.

---

## 4. Cambios exactos

### 4.1 Migración (una, aditiva) · [backend] + revisión [database-specialist]
`database/migrations/2026_11_23_100001_add_commercial_text_author_to_plans_table.php`

**up():**
```php
Schema::table('plans', function (Blueprint $t) {
    $t->foreignId('commercial_text_by')->nullable()->constrained('admins')->nullOnDelete();
});
```
- Es el mismo patrón que `archived_by` (`2026_10_31_100002_add_archive_to_plans_table.php`).
- **PostgreSQL 14:** `SET LOCAL lock_timeout = '5s'` antes del ALTER, como en E19-3. `plans` recibe FOR KEY SHARE de cada cobro: si una transacción larga retiene la tabla, cae limpia con 55P03 y se reintenta.
- Columna anulable sin valor por defecto: no reescribe la tabla.
- **SQLite:** la reconstrucción que ya hizo `archived_by`.

**down():** `dropConstrainedForeignId('commercial_text_by')`.

**Privilegios:** no se tocan. `iron_app` tiene DML sobre `plans` a nivel de tabla, y una columna nueva lo hereda [S: comprobar en el banco con `has_column_privilege('iron_app','public.plans','commercial_text_by','UPDATE')`]. La migración la corre el rol que migra, como siempre.

**Sin relleno de datos:** los textos anteriores quedan con `null` (D10).

**El Studio no tiene migración** (D5).

### 4.2 Servidor · A (texto y permiso)

**`app/Models/Plan.php`** (la definición única, D15)
- Constantes:
  - `COMMERCIAL_TEXT_FIELDS = ['name','benefits']`
  - `COMMERCIAL_CATALOG_MAX_PLANS = 20`
  - `COMMERCIAL_CATALOG_MAX_BENEFITS = 18`
  - `COMMERCIAL_BENEFIT_MAX_STORED = 255`
- `commercialText(): array{name:string, benefits:list<string>}`. Hace `trim(name)` y usa `benefitsArray()`. Si salta `ErrorException` (lista anidada, M-4), devuelve `benefits: []` y NO lanza.
- `static commercialTextEquals(array $a, array $b): bool`.
- `scopeCommercialCatalog($q)`: `sellable()->orderBy('sort_order')->orderBy('duration_days')->limit(self::COMMERCIAL_CATALOG_MAX_PLANS)`. **`VoiceTools::plans()` pasa a usarlo** (`VoiceTools.php:391`): una sola consulta.
- `static commercialCatalog(): list<array{id:int,name:string,benefits:list<string>}>`. Aplica los topes de D6.
- `static commercialCatalogHash(list $plans): string`: sha256 de `json_encode` con claves en orden fijo (`benefits`, `id`, `name`), `JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES`.
- `$hidden` (`Plan.php:87`) añade `commercial_text_by`, porque el listado es público. Cast `integer`.

**`app/Support/Access/PlanCommercialText.php`** (nueva, pequeña; precedente `InventoryCosts`)
- `PERMISSION = 'plans.commercial_text'`.
- `static reasonFor(?array $textoAntes, bool $vendibleAntes, array $textoDespues, bool $vendibleDespues): ?string` → `null | 'text_changed' | 'becomes_sellable'` (D2 y D3). Es pura.
- `static assertAllowed(Request $r, string $reason): void` → `PlanArchiveException::commercialTextForbidden($reason)` si `! CrmPermission::allows(actor, PERMISSION)`. El token compartido no es nadie: ya no puede escribir planes.
- `static lockedFor(?Admin $viewer, Plan $plan): ?bool` → `null` sin sesión de administrador; `true` si el plan es vendible ahora y quien mira no tiene el permiso.

**`app/Http/Controllers/Api/PlanController.php`**
- `store` (`:199-219`): `new Plan($data)`, luego `reasonFor(null, false, texto, isSellable())`, luego `assertAllowed` si hace falta. Siempre `forceFill(['commercial_text_by' => actor])`, porque es texto nuevo. Después, `save()` y la auditoría de siempre.
- `update` (`:221-282`): dentro de la transacción y del cerrojo actual, en este orden:
  1. capturar el texto y `isSellable()` de antes;
  2. `$plan->fill($data)` sin guardar;
  3. calcular el texto y `isSellable()` del modelo relleno;
  4. `reasonFor` → `assertAllowed` (el `throw` deshace la transacción);
  5. si el texto cambió o entra en la venta, `forceFill(['commercial_text_by' => actor])`;
  6. `save()`.

  El resto se queda igual: el `throw` de los archivados, `features`, la auditoría y el aviso a los socios.
- `adminViewer` (`:99-108`) devuelve el `?Admin`, no un booleano. `payload()` (`:74-97`) añade `'commercial_text_locked' => PlanCommercialText::lockedFor($viewer, $plan)`.
- `archive`, `destroy`, `updateFeatures` (`:463-505`, solo booleanos de `features`) y `updateAiCapabilities`: **sin cambios**.

**`app/Services/Membership/PlanArchive.php::reactivate`** (`:108`, `forceFill` en `:127-136`)
- Tras el `forceFill`: si `isSellable()` → `PlanCommercialText::assertAllowed($request, 'becomes_sellable')` y se añade `'commercial_text_by' => actor` en el mismo `forceFill`.

**`app/Exceptions/PlanArchiveException.php`**
- Factoría nueva `commercialTextForbidden(string $reason)`: 403, `code: plan_commercial_text_forbidden`, `reasons: [$reason]`. El `render()` es el que ya existe (`{ok:false, code, message, reasons}`). Textos en §5.

**`app/Support/Access/CrmPermission.php`**
- Constante `PLANS_COMMERCIAL_TEXT = 'plans.commercial_text'`.
- Se añade a la lista del Administrador, junto a `self::PLANS_ARCHIVE` (`:347`), con su comentario: «solo Super Admin por defecto; el propietario la asigna en Usuarios y roles».

**`app/Support/Access/AuthorizationMap.php`**
- `'plans.commercial_text'` en `CONTROLLER_ENFORCED` (`:1026`), con su comentario: «va en el mismo PUT que el precio».

**`app/Support/Access/PermissionCatalog.php`**
- `LABELS['plans.commercial_text'] = 'Nombre y beneficios'`.
- Ayuda: «Cambiar el nombre o los beneficios de un plan que se ofrece fuera (ULTRON, Sofía, enlaces de pago y la app) y ponerlo a la venta. Quien los escribe no puede publicar después la voz que los recoge. No llega por defecto a ningún rol.»

### 4.3 Servidor · B (publicación, entrega y deriva)

**`app/Services/Telephony/VoicePublishedPart.php`**
- Propiedad nueva `public readonly ?array $commercialCatalog`: `list<{id,name,benefits}>`, o `null` si no hay o no se puede leer.
- Invariante: solo en una parte `ok`, con la misma validación de forma que `transferDestinations`.

**`app/Services/Telephony/VoicePublishedConfig.php`**
- **`rows()`** (`:351-364`), en la MISMA sentencia: subconsulta correlacionada con la `evidence` de la entrada `publish` de menor `seq` cuyo `agent_id = p.agent_id`, como `a_origin_evidence`. Vale en PostgreSQL y en SQLite.
- **`part()`** (`:257-300`), lectura estricta de `evidence.catalog`, sin lanzar. Exige:
  - `format === 'ultron-voice-catalog/1'`;
  - `plans` como lista de `{id:int≥1, name:string≤255, benefits:list<string>}`;
  - como mucho 20 planes y 18 beneficios;
  - `hash === Plan::commercialCatalogHash(plans)`.

  Si algo falla, `null` (falla cerrado). La firma de la cadena no cambia: `evidence` nunca entró en el mensaje.
- **`partForCall(VoiceCall $call): ?VoicePublishedPart`:** es `VoiceTransferDirectory::partFor` (`:52-61`) movido aquí, sin cambiarlo.

**`app/Services/Telephony/VoiceTransferDirectory.php`**
- Usa `VoicePublishedConfig::partForCall()`. Su comportamiento no cambia.

**`app/Services/Telephony/VoiceTools.php`**
- `handle` (`:182-188`): `consultar_planes` → `self::plans($cuenta, VoicePublishedConfig::partForCall($llamada)?->commercialCatalog ?? [])`. Que la parte no exista, no se pueda leer o no sea del mismo agente cuenta como «sin catálogo» (un valor distinto del catálogo vacío publicado).
- `labAnswer` (`:343-351`): `self::plans($cuenta, self::LIVE)`, el centinela del laboratorio. Lee en vivo, como hoy (D9).
- **`plans()`** (`:388-457`), con el catálogo publicado indexado por id:
  - sin catálogo → `['planes' => [], 'nota' => NO_DELIVERABLE_PLANS_NOTE]` y `plansUnpublished = número de vendibles`;
  - plan que no está o con otro nombre (`Plan::commercialTextEquals` sobre el nombre) → `plansUnpublished++` y `continue`, antes del filtro de C-1 ii;
  - el nombre que se entrega es el vigente, que es el publicado.
- `benefitsOf(Plan $plan, ?array $publicados)`:
  - si hay lista publicada, los beneficios crudos que no estén en ella (comparación exacta tras `benefitsArray()`) → `benefitsUnpublished++` y fuera, antes de `benefitText()`;
  - el resto de filtros, igual.
- `countsForLog` (`:565-576`): mapea `plansUnpublished → plans_unpublished` y `benefitsUnpublished → benefits_unpublished`.
- El precio sigue saliendo de `self::price((float) $p->price)`. Sin cambios en `MAX_*`, en la nota ni en la forma de la respuesta.

**`app/Services/UltronStudio/StudioVoicePublishing.php`**
- `preview` (`:234-255`):
  - si `purpose === publication`: `'catalog' => self::catalogPreview($access)`, con la lista, la huella, `authoredByYou`, `authorRecorded` y `fourEyes` (§5);
  - si es del laboratorio: `'catalog' => null`.
- `publish` (`:261-272`) y su controlador: parámetro nuevo `string $expectedCatalogHash`, que entra en `$cuerpo`, el de la idempotencia.
- `publishGated` (`:274-354`), en este orden:
  1. lo de hoy, hasta `assertSeenSpec` (`:285`);
  2. `$catalogo = Plan::commercialCatalog()` y sus autores (una consulta sobre `plans.commercial_text_by` de esos ids);
  3. cuatro ojos del catálogo;
  4. `assertSeenCatalog($expectedCatalogHash, hash)` → 409 `catalog_changed`;
  5. `labGate` y lo que sigue, igual.

  En la transacción, `insertEntry(… evidence: ['number' => …, 'catalog' => ['format','hash','plans','authors']] …)` (`:328`). La auditoría `voice_publication.published` añade al `diff` `catalogHash` y `catalogPlans`.
- `promote`: tras los cuatro ojos de hoy, los del catálogo con `authors` de la instantánea del agente canario (`StudioVoicePublications::catalogOf($agente)`).
- `assertFourEyes` (`:1084-1104`): tercera comprobación, después de las frases de reglas. Si `$access->id()` está entre los `by` → `recordDenied('voice_publication.four_eyes_denied', … diff: {action, planTextAuthor: [ids]})` y 403 (§5).
- Revertir y apagar: sin cambios.

**`app/Services/UltronStudio/StudioVoicePublications.php`**
- `catalogOf(StudioVoiceAgent|int)`: lee `evidence.catalog` de la entrada `publish` del agente, con la misma lectura estricta. Para no duplicar la forma, se apoya en un validador estático compartido (`Plan::readCommercialCatalog(array): ?array`), que también usa `VoicePublishedConfig`.
- `state()` (`:436-470`): `catalog` por parte (§5), con `StudioVoiceDrift::catalogVerdict()` frente a `Plan::commercialCatalog()`. Una consulta a `plans` por petición.
- `entryDto` (`:501-525`): en las `publish`, `catalog: {hash, shortHash, plans}` o `null`. Nunca la lista ni los autores.

**`app/Services/UltronStudio/StudioVoiceDrift.php`**
- `public static function catalogVerdict(?array $publicado, array $vigente): array{outcome:'ok'|'changed'|'missing', changes:list<{planId,name,change}>}`. Pura, sin red.
- `change ∈ {renamed, benefits_changed, new}`. Un cambio de precio no es deriva.

**`app/Http/Controllers/Crm/UltronStudio/UltronStudioVoiceController.php::publish`** (`:245-257`)
- `'expectedCatalogHash' => ['required','string','regex:'.self::SHA256]`.

**Documentación** · [lead]
- Enmienda 21 del contrato H8: D8-10 capa 2, E18-8 y D8-7 paso 5.
- La fila PT-14 de `MATRIZ_CIERRE_V2.md` §11.
- La condición (2).10 del runbook pasa a ser «vista previa del catálogo + deriva `ok`»; es de devops.

### 4.4 CRM · [frontend]
- `src/app/models/permissions.enum.ts`: `PLANS_COMMERCIAL_TEXT = 'plans.commercial_text'`.
- `src/app/models/backend-permission-catalog.ts`: regenerado con `php artisan permissions:export --path=…` sobre el backend de PT-14 (`ExportPermissionCatalogCommand.php:24-34`). Sin esto falla `permission-contract.spec.ts:41-60`.
- **Tipo del plan** (`services/api.service.ts`, `PlanSummary`): `commercial_text_locked?: boolean | null`.
- **`modules/components/edit-plan-modal.ts`:**
  - con `plan.commercial_text_locked === true`, los controles `name` y `benefits` se deshabilitan, con la pista «Solo quien tenga “Nombre y beneficios” cambia el nombre y los beneficios de un plan que se ofrece fuera»;
  - y **se omiten** `name` y `benefits` del cuerpo (`:1697-1712`). Así ya no se manda el `defaultBenefits` de `:1622`;
  - el resto, igual. Los errores ya enseñan `err.error.message` (`:1742-1746`).
- **`modules/plans.ts`:** en `duplicatePlan` (`:1561-1582`) y `toggleStatus` (`:1584-1597`), el error enseña `err?.error?.message` cuando lo hay, en vez del texto genérico. `create-plan-modal.ts` ya lo hace (`:1652-1656`).
- **`ultron-studio/studio-voice-api.types.ts` y `studio-voice-parse.ts`:**
  - `VoiceCompilePreviewDto.catalog`, `VoicePartState.catalog` y `VoiceChainEntryDto.catalog`;
  - el lector tolera que falten: lo traduce a `null` (servidor viejo);
  - `PublishVoiceRequest.expectedCatalogHash`;
  - el 403 con `detail: plan_text_author` y el 409 `catalog_changed`.
- **`studio-voice-session.ts`** (`publish`, `:662`) y **`studio-voice-api.service.ts`** (`:293`): pasar `expectedCatalogHash`.
- **`studio-voice-publications.ts`:**
  - en el diálogo de publicar, tras «Previsualizar» (hoy `:190-200`), la lista «Lo que Sofía dirá de los planes»: nombre y beneficios de cada plan, la marca «Lo escribiste tú» o «Sin autor registrado» y `fourEyes.message`. El botón de publicar se deshabilita si `fourEyes.ok === false`;
  - el envío lleva `expectedCatalogHash` (`:404`);
  - en «Lo que rige ahora», la huella del catálogo y su deriva, con los textos de §5;
  - en la cadena, la huella corta del catálogo de cada `publish`.
- **`studio-voice-text.ts`:** los textos. Fixtures `testing/backend-voice.testing.ts` y `studio-voice-fixtures.testing.ts`.

---

## 5. Contrato servidor ↔ CRM

### A · Planes
- **`GET /api/plans`, `GET /api/plans/{id}` y las respuestas de crear, editar, archivar y reactivar:**
  - clave nueva `commercial_text_locked: true|false|null`. Va en `null` sin sesión de administrador, que es la convención de `archived_by_name` (`PlanController.php:69-70`, `:92-94`);
  - `commercial_text_by` **no sale nunca** (`$hidden`).
- **403** en `POST /api/plans`, `PUT|PATCH /api/plans/{id}` y `POST /api/plans/{id}/reactivate`:
  ```json
  {"ok": false, "code": "plan_commercial_text_forbidden", "reasons": ["text_changed"], "message": "…"}
  ```
  - `text_changed`: «Cambiar el nombre o los beneficios de un plan que se ofrece fuera (ULTRON, Sofía, enlaces de pago y la app) pide el permiso “Nombre y beneficios” de Planes. Guarda el resto sin tocarlos, o pide a quien lo tenga que los cambie.»
  - `becomes_sellable`: «Poner a la venta un plan (crearlo o activarlo con “Se ofrece fuera: Sí”, darle precio o vigencia, o reactivarlo) pide el permiso “Nombre y beneficios” de Planes: así ningún texto llega a la venta sin que lo decida quien tiene ese permiso. Puedes guardarlo con “Se ofrece fuera: No”.»
- **Permiso:** `plans.commercial_text`, en el dominio «Planes» y con la etiqueta «Nombre y beneficios». Por defecto: Super Admin sí; Administrador, Administrativo, Recepción y Entrenador, no.

### B · Voz (`/api/admin/ultron-studio/…`, prefijo de siempre)

**`POST voice/compile-preview`** (propósito `publication`) añade:
```json
"catalog": {
  "format": "ultron-voice-catalog/1",
  "hash": "<sha256>", "shortHash": "<8>",
  "plans": [{"id": 7, "name": "Mensual", "benefits": ["Acceso ilimitado al gimnasio"], "authoredByYou": false, "authorRecorded": true}],
  "fourEyes": {"ok": true}
}
```
- Con autoría propia: `fourEyes: {ok: false, message: "…"}`.
- Con propósito `lab`: `catalog: null`.
- Nunca dice quién escribió los demás planes.

**`POST voice/publish`**: `{clientRequestId, versionUuid, profileUuid, expectedSpecHash, expectedCatalogHash, note?}`.
- Sin `expectedCatalogHash` → 422 (validación).
- Distinto → **409 `catalog_changed`**, con `{expected, actual}`: «El catálogo de planes cambió desde que lo previsualizaste: vuelve a previsualizar y revisa lo que dirá Sofía antes de publicar.»
- **403 `four_eyes_required`**, con `{detail: "plan_text_author", planTextAuthor: [{planId, name}]}` (solo los de quien publica): «Escribiste o pusiste a la venta el nombre o los beneficios de «Mensual»: tiene que publicarla otra persona.» Al promover, «promoverla».

**`POST voice/promote`**: el mismo 403 si quien promueve es autor de algún plan del catálogo del agente canario.

**`GET voice/publications`**:
- `state.canary` y `state.rest` añaden:
  ```json
  "catalog": {"hash": "…", "shortHash": "…", "plans": 5,
              "drift": {"outcome": "ok|changed|missing",
                        "changes": [{"planId": 7, "name": "Mensual Plus", "change": "renamed|benefits_changed|new"}]}}
  ```
  Es `null` si la parte no tiene agente.
- `history[]` de una `publish`: `catalog: {hash, shortHash, plans} | null`.

**Textos de la deriva** (CRM):
- `renamed`: «Sofía no dice «{name}»: cambió su nombre después de publicar.»
- `benefits_changed`: «De «{name}» solo dice los beneficios que ya estaban publicados.»
- `new`: ««{name}» se puso a la venta después de publicar: Sofía no lo ofrece.»
- `missing`: «Esta publicación no tiene catálogo de planes aprobado: Sofía no dice ningún plan.»
- Cierre común: «Vuelve a publicar la voz para que diga el catálogo de ahora; la tiene que publicar alguien que no lo haya escrito.»

**Telefonía:** `consultar_planes` no cambia de forma (`planes`, `nota`). Los contadores nuevos solo van al evento `tool_call.codes` y a la línea `voice.tool`.

---

## 6. Vías de A y matriz de B

### 6.1 Por dónde cambia el texto comercial o un plan entra en la venta

| Vía | ¿Toca nombre/beneficios o la venta? | ¿Pide `plans.commercial_text`? | Hoy la puede hacer |
|---|---|---|---|
| `POST /api/plans` (crear) que deja el plan vendible | texto nuevo + entra en la venta | **Sí** (`becomes_sellable`) | SA, Admin |
| `POST /api/plans` no vendible (`sellable:false`, `active:false`, precio o vigencia 0) | texto nuevo, fuera de la venta | No (queda como autor) | SA, Admin |
| Duplicar en el CRM (= `POST`, `active:true`, `sellable` por defecto `true`) | ídem | **Sí** si sale vendible | SA, Admin |
| `PUT/PATCH /api/plans/{id}`: nombre o beneficios de un plan vendible | texto | **Sí** (`text_changed`) | SA, Admin |
| `PUT/PATCH`: nombre o beneficios de un plan NO vendible que sigue sin serlo | texto | No (queda como autor) | SA, Admin |
| `PUT/PATCH`: `active` false→true, `sellable` false→true, `price` 0→>0 o `duration_days` 0→>0, con el plan ya vendible al terminar | entra en la venta | **Sí** (`becomes_sellable`) | SA, Admin |
| `PUT/PATCH`: precio, vigencia (>0→>0), acceso, `tier`, `badge`, `features`, `visible_in_app`, `counter_sellable`, `sort_order`, reenviando el mismo nombre y beneficios | ninguno | No | SA, Admin |
| `PUT/PATCH`: apagar `active`/`sellable`, poner precio o vigencia 0 | sale de la venta | No | SA, Admin |
| `POST /api/plans/{id}/reactivate` | entra en la venta (`PlanArchive.php:127-133`) | **Sí**, además de `plans.archive` | SA (Admin solo si se la dieron) |
| `POST /api/plans/{id}/archive`, `DELETE /api/plans/{id}` | sale | No | archivar: SA; borrar: SA, Admin |
| `PUT /api/plans/{id}/features` (booleanos) | no (`PlanController.php:463-505`) | No | SA, Admin |
| `PUT /api/plans/{id}/ai-capabilities`, `PUT admin/billing/plans/{id}/tax-rate|pricing-mode`, traslados de plan | no | No | según su llave |
| Consola: `plans:restore-deleted` (lo inserta ARCHIVADO, `DeletedPlanRestorer.php:30-34`), `GrantTesterDemoAccess`/`EnsureReviewDemoMember` (precio 0 = no vendible), `PlansSeeder`/`LegacyPlansSeeder` | los seeders sí pueden | Fuera de A: no hay persona. Lo cubre B en la voz (autor `null`, se ve en la vista previa y la deriva) | operación, no el CRM |
| SQL a mano | sí | fuera de A; B lo ve como deriva | quien tenga la base |

**Qué deja de poder hacer cada rol respecto a hoy (por defecto):**
- **Super Admin:** nada; tiene el permiso. Nuevo: no puede **publicar ni promover** la voz si es autor de algún plan capturado (D10).
- **Administrador** (pierde, salvo que el propietario le dé la llave en Usuarios y roles):
  1. cambiar el nombre o los beneficios de un plan vendible;
  2. crear o duplicar un plan que quede vendible;
  3. encender (`active` o «Se ofrece fuera») un plan que con eso quede vendible, o darle precio o vigencia desde 0.

  Conserva todo lo demás: precios, vigencias, accesos, funciones, IA, visibilidad, mostrador, apagar, borrar y crear o editar planes no vendibles, también los de solo mostrador. Reactivar ya no lo tenía por defecto (`plans.archive`).
- **Administrativo, Recepción y Entrenador:** nada; no tenían `plans.manage`.
- **Token compartido:** nada; solo lee.

### 6.2 Quién aprueba qué en B

| Acto | Permiso | Quién NO puede |
|---|---|---|
| Escribir el texto o poner a la venta un plan vendible (aprobación 1, A) | `plans.commercial_text` | — (queda como autor) |
| Crear la versión del Studio y aprobarla | `ultron.edit` / `ultron.approve` | aprobar: su creador y quien escribió frases de reglas que copia (sin cambios) |
| Crear el perfil de voz y aprobarlo | `ultron.voice_manage` / `ultron.approve` | aprobar: su creador (sin cambios) |
| Veredicto del laboratorio hablado | `ultron.approve` | quien abrió la sesión (CHECK, sin cambios) |
| **Publicar la voz = capturar y aprobar el catálogo (aprobación 2, B)** | `ultron.voice_publish` | creador de la versión, creador del perfil, autor de frases de reglas copiadas, **y autor (`commercial_text_by`) de cualquier plan capturado** (nuevo) |
| Promover al resto | `ultron.voice_publish` | los mismos, con los **autores guardados en la instantánea** del agente canario (nuevo) |
| Revertir, apagar | `ultron.voice_publish` | nadie (dirección segura, sin cambios) |

---

## 7. Pruebas (primero en rojo) · [tester] diseña y [backend]/[frontend] las hacen pasar

### Servidor · A (`tests/Feature/Plans/PlanCommercialTextPermissionTest.php`, SQLite y PG)
1. `test_el_administrador_no_cambia_el_nombre_de_un_plan_vendible`
   - **Afirma:** 403 `plan_commercial_text_forbidden` / `text_changed`; el nombre sigue igual en la base; no hay línea de auditoría `update`.
   - **Hoy cae:** devuelve 200, porque `update` solo pide `plans.manage` (`PlanController.php:221`).
2. `test_el_administrador_no_cambia_los_beneficios_ni_como_texto_ni_como_lista`
   - **Afirma:** dos casos, la cadena del CRM y un array; 403.
   - **Hoy cae:** devuelve 200.
3. `test_reenviar_el_mismo_texto_no_pide_el_permiso`
   - **Afirma:** el Administrador cambia el precio de un plan vendible mandando el mismo `name` (con espacios al final) y los mismos `benefits` (el JSON legado H4-14 y la cadena con comas); 200. El precio se guarda con su antes y después en la auditoría.
   - Guarda D3. **No es roja hoy:** es la de no regresión.
4. `test_no_se_pone_a_la_venta_en_dos_pasos`
   - **Afirma:** con un plan `sellable:false`, el Administrador cambia el nombre (200, autor = él). Después: `sellable:true` → 403 `becomes_sellable`. Ídem `active` false→true, `price` 0→89000 y `duration_days` 0→30.
   - **Hoy cae:** devuelve 200.
5. `test_crear_o_duplicar_un_plan_vendible_pide_el_permiso_y_uno_no_vendible_no`
   - **Hoy cae:** el primero devuelve 201.
6. `test_reactivar_pide_tambien_el_permiso`
   - **Afirma:** un Administrador con `plans.archive` concedido → 403. Con las dos llaves → 200 y `commercial_text_by` = él.
   - **Hoy cae:** devuelve 200.
7. `test_el_super_admin_puede_y_queda_como_autor_sin_salir_en_el_listado_publico`
   - **Afirma:** `commercial_text_by` = SA; `GET /api/plans` sin sesión no trae la clave y trae `commercial_text_locked: null`.
   - **Hoy cae:** la columna no existe.
8. `test_commercial_text_locked_dice_lo_que_ve_cada_rol`
   - **Afirma:** Administrador `true` en un plan vendible y `false` en uno no vendible; SA `false`; sin sesión `null`.
9. `test_funciones_ia_archivar_y_borrar_no_piden_el_permiso`
   - **Afirma:** el Administrador recibe 200 en `features`, `ai-capabilities` y `destroy` sin historia; el SA recibe 200 en `archive`.
10. `test_el_permiso_existe_en_el_catalogo_y_por_defecto_solo_lo_tiene_super_admin`
    - **Afirma:** `PermissionCatalog::all()` lo contiene; `CrmPermission::defaultsFor()` por rol.
    - **Hoy cae:** la clave no existe.
11. **Migración:** `up`, `down` y `up`, en los dos motores; la FK `nullOnDelete`. En PG: `iron_app` puede hacer UPDATE en la columna. Es de [database-specialist].

### Servidor · B (telefonía: `tests/Feature/Telephony/VoiceToolsPublishedCatalogTest.php`)
Usa `publishVoice()` de `VoiceFixtures`, con la `evidence` de la publicación.
1. `test_la_llamada_dice_el_nombre_y_los_beneficios_publicados_con_el_precio_de_ahora`
   - **Afirma:** cambiar el precio después de publicar → sale el precio nuevo; ninguna deriva.
2. `test_un_plan_renombrado_despues_de_publicar_no_se_entrega_ni_con_el_nombre_viejo_ni_con_el_nuevo`
   - **Afirma:** `plansUnpublished = 1`.
   - **Hoy cae:** lo entrega con el nombre nuevo (`VoiceTools.php:391-417`).
3. `test_de_los_beneficios_solo_salen_los_publicados_que_siguen`
   - **Afirma:** uno añadido → no; uno quitado → no; los que se mantienen → sí; `benefitsUnpublished` lo cuenta.
   - **Hoy cae:** entrega el añadido.
4. `test_un_plan_puesto_a_la_venta_despues_de_publicar_no_se_entrega`
   - **Hoy cae:** lo entrega.
5. `test_sin_catalogo_publicado_ningun_plan_y_la_nota_de_siempre`
   - **Afirma:** la publicación no tiene `catalog` (anterior a PT-14).
   - **Hoy cae:** entrega todo.
6. `test_un_catalogo_manipulado_falla_cerrado`
   - **Afirma:** si la huella no cuadra, la forma no vale o hay 21 planes → ningún plan.
7. `test_una_llamada_sellada_a_otro_agente_falla_cerrado`
   - **Afirma:** la parte ya apunta a otro agente; mismo caso que en `VoiceTransferDestinationsTest`.
8. `test_las_guardas_siguen_encima`
   - **Afirma:** un beneficio publicado «Primer mes gratis» sigue retenido (`benefitsWithheld`), y un nombre publicado con código, también.
9. `test_el_laboratorio_sigue_leyendo_en_vivo`, en `VoiceLabToolsTest`
   - **Afirma:** sin publicación entrega los vendibles; con una publicación, los vigentes.
10. `TelephonyIsolationTest`, `OtpUntouchedByVoiceTest` y `VoiceIsolationTest`: siguen en verde, sin cambios.

### Servidor · B (Studio: `tests/Feature/UltronStudio/UltronStudioVoiceCatalogTest.php`)
1. `test_la_vista_previa_ensena_el_catalogo_su_huella_y_lo_que_escribiste_tu`
   - **Afirma:** no dice quién escribió los demás; `catalog: null` con propósito `lab`.
2. `test_publicar_exige_la_huella_del_catalogo_vista`
   - **Afirma:** 422 sin ella. 409 `catalog_changed` si un beneficio cambió entre la vista previa y la publicación; ningún agente creado y `Http::assertNothingSent()`.
3. `test_publicar_guarda_el_catalogo_en_la_evidence_y_la_cadena_sigue_verificando`
   - **Afirma:** sin precios dentro; `effective_json` con sus 10 claves de siempre.
4. `test_el_autor_del_texto_no_publica_ni_siendo_super_admin_y_otro_si`
   - **Afirma:** 403 `plan_text_author`; el evento `voice_publication.four_eyes_denied` con `planTextAuthor`; un autor `null` no excluye a nadie.
   - **Hoy cae:** publica.
5. `test_el_autor_del_catalogo_del_canario_no_promueve`
   - **Hoy cae:** promueve.
6. `test_revertir_y_apagar_no_miran_la_autoria`
7. `test_la_deriva_del_catalogo`
   - **Afirma:** `renamed`, `benefits_changed`, `new` y `missing`; un cambio de precio da `ok`; no echa el freno (`VoiceBrake::isOpen() === false`).
8. **Repetir en PostgreSQL 14:** la subconsulta de `rows()` y la `evidence` jsonb.

### Pruebas existentes que cambian de premisa
Es un cambio de requisito decidido por el propietario, no un ajuste al fallo: cada cambio va con su porqué en el commit.
- `tests/Feature/Telephony/VoiceToolsBenefitsTest.php` y los casos de planes de `VoiceToolsTest.php`. Hoy crean la llamada sellada (`VoiceFixtures`, sello por defecto en `:229-231`) sin catálogo publicado (`VoiceToolsBenefitsTest.php:323-333`). Tienen que publicar con el catálogo de los planes que siembran; si no, darán «ningún plan».
- `tests/Feature/Plans/PlanArchiveTest.php:127-134`: «quien tiene `plans.archive` reactiva» pasa a pedir también `plans.commercial_text`.
- `tests/Feature/Access/RolePolicyTest.php`: los permisos por defecto del Administrador.
- Las pruebas de publicar voz del Studio que llaman a `publish` sin `expectedCatalogHash`.

### CRM (vitest)
- `edit-plan-modal.spec.ts`:
  - con `commercial_text_locked: true`, nombre y beneficios deshabilitados y **ausentes** del cuerpo de `updatePlan`, sin `defaultBenefits`;
  - con `false`, igual que hoy.
- `plans-archive.spec.ts` y las pruebas de `plans.ts`: un 403 al duplicar o al activar enseña el `message` del servidor.
- `studio-voice-publications.spec.ts`:
  - el diálogo pinta la lista del catálogo;
  - con «Lo escribiste tú», el mensaje y el botón deshabilitado;
  - el envío lleva `expectedCatalogHash`;
  - un 409 `catalog_changed` enseña el texto y obliga a volver a previsualizar;
  - «Lo que rige ahora» pinta los cuatro tipos de deriva.
- `studio-voice-parse.spec.ts` y `studio-voice-parity.spec.ts`: leer `catalog` y su ausencia (`null`).
- `permission-contract.spec.ts`: en verde con el catálogo regenerado.

### Ejecución obligatoria antes de revisar
- La batería completa del servidor en SQLite y en PostgreSQL 14 sobre el commit exacto.
- `ng test` y `ng build` de producción del CRM.

---

## 8. Despliegue, vuelta atrás y el primer día

### Orden
1. PT-14 se construye **encima de `integracion/voz` @ `b1f66c32`** (lote 3) y se despliega como **lote propio, justo después del lote 3** (o junto a él, si el propietario aún no lo ha autorizado), pero **antes del paso «publicación de la voz en canario» del runbook**. Hoy hay 0 publicaciones y faltan V-2, V-3 y A0–A3, así que hay margen.
2. **Servidor:**
   - primero la migración `2026_11_23_100001_…`, aditiva; el código viejo la ignora;
   - después el código; `queue:restart`, por la regla del lote;
   - no hay `config:cache` que cambiar, porque no hay configuración nueva.
3. **CRM después del servidor.**
   - Con el servidor nuevo y el CRM viejo: el SA no nota nada. El Administrador recibe el 403 con su mensaje en el editor (que ya enseña `message`), y no puede guardar un plan vendible sin beneficios, por el `defaultBenefits`. Publicar la voz da 422: falla cerrado.
   - El orden contrario (CRM nuevo con servidor viejo) también aguanta: `commercial_text_locked` ausente se trata como `false`. **Rectificado (revisión final, NB-C3):** con un CRM nuevo y un servidor anterior, la vista previa no trae `catalog` y el CRM NO publica la voz (falla cerrado, con el motivo a la vista): publicar a ciegas contra un servidor que no gobierna el catálogo reabriría B-1.
4. **Si la voz ya se hubiera publicado antes de PT-14:** después de desplegar, Sofía no dice ningún plan (`missing`) hasta **volver a publicar**.
   - Vuelve a pedir: ventana, bandera, freno suelto y otra persona que no sea autora.
   - **No** pide otro veredicto del laboratorio: el `specHash` no cambia.
   - Sí crea un agente nuevo en ElevenLabs.

### Vuelta atrás
1. CRM anterior.
2. Código del servidor anterior (`git checkout` del commit previo y `queue:restart`).
3. `down()` de la migración, que borra `commercial_text_by` y pierde la autoría (aceptable).

`evidence.catalog` se queda en la cadena, que es de solo inserción, y el código viejo lo ignora. Las filas de `role_permissions` de la llave nueva, si se concedió, quedan inertes. **Al volver atrás se reabre B-1:** `consultar_planes` vuelve a leer en vivo.

### Qué ve el personal el primer día
- **Administradores:**
  - en los planes vendibles, el nombre y los beneficios aparecen bloqueados, con la pista;
  - pueden cambiar precios y todo lo demás;
  - si encienden, crean o duplican un plan que quedaría a la venta, ven el mensaje del servidor;
  - en Roles aparece «Planes → Nombre y beneficios», marcado solo para Super Admin.
- **Super Admin:**
  - el editor sigue igual;
  - en el Studio, el diálogo de publicar enseña «Lo que Sofía dirá de los planes». Si escribió alguno, ve el aviso y publica otra persona.
- **Recepción, Administrativo y Entrenador:** sin cambios.
- **Sofía:** no llama a nadie (constante cerrada). Cuando se publique por primera vez, dirá exactamente el catálogo aprobado y, después, solo lo aprobado que siga vigente.
- **WhatsApp y Legacy:** se comportan igual; solo cambia quién puede editar los textos.

---

## 9. Reparto (dos frentes, sin ficheros en común)

**Frente 1 · servidor** ([backend]; migración revisada por [database-specialist]; A y B revisados por [security] / [api-security-specialist], porque tocan autorización y cuatro ojos)
- **Sus ficheros:** los de §4.1, §4.2 y §4.3, más las pruebas del servidor de §7.
- **Orden y verificación de cada paso:**
  1. A: `Plan`, la migración, `PlanCommercialText`, el permiso, `PlanController` y `PlanArchive`. Verificación: pruebas A 1–11 en verde en los dos motores; `AdminRoutesSecurityTest` y `RolePolicyTest` actualizado.
  2. B, la entrega: `VoicePublishedPart`, `VoicePublishedConfig`, `VoiceTransferDirectory` y `VoiceTools`. Verificación: pruebas de telefonía 1–10 y las de destinos sin cambios.
  3. B, la captura: `StudioVoicePublishing`, `StudioVoicePublications`, `StudioVoiceDrift` y el controlador. Verificación: pruebas del Studio 1–8 y el resto de la publicación de voz.
  4. `permissions:export --path=<scratch>/backend-permission-catalog.ts` y entregar el fichero al lead.

  **El sistema vuelve a quedar en verde al terminar el paso 1:** A se puede revisar solo. Los pasos 2 y 3 van juntos: el 2 sin el 3 deja a Sofía sin planes, lo cual es aceptable porque no hay llamadas.

**Frente 2 · CRM** ([frontend])
- **Sus ficheros:** los de §4.4 y las pruebas del CRM.
- Puede empezar ya contra el contrato de §5, con fixtures.
- El único insumo del servidor es el `backend-permission-catalog.ts` regenerado. Lo copia el propio frente en su árbol, desde el fichero que deja el frente 1; nadie escribe en el árbol del otro.

**Interfaces que se congelan ahora:** las claves, los códigos y los textos de §5, más el formato `ultron-voice-catalog/1` de §3 D6. Cualquier cambio pasa por el lead.

**[tester]:** las pruebas de §7. Los casos límite son: la comparación por valor (legado H4-14, espacios, `defaultBenefits`), las entradas en la venta por precio o vigencia 0, la llamada sellada a otro agente, la instantánea manipulada, el autor `null` y la carrera entre la vista previa y la publicación.

**[security]:** revisión de los dos frentes; en particular, la regla del autor `null` (pregunta 2) y la de «último autor» (riesgo R2).

---

## 10. Fuera de alcance, riesgos y preguntas

**Queda FUERA (no se hace en PT-14):**
- `badge` y `restrictions`: también son texto libre y WhatsApp los lee (`MarketingKnowledgeBaseService.php:144-145`), pero el propietario acotó A a «nombres y beneficios». Sofía no los lee (`VoiceTools.php:392`). Se señala a [security] como hallazgo aparte.
- Las publicaciones de WhatsApp (H5) no capturan el catálogo: WhatsApp sigue en vivo y solo lo protege A.
- N-4, N-5 y N-6 (léxico y frenos falsos) y la nota «ofrece que una persona le escriba».
- Los seeders y la consola: fuera de A; los ve B.
- Rellenar la autoría desde `audit_logs`, la autoría por beneficio, y una «publicación solo de catálogo» sin agente nuevo.
- Avisar en el editor de planes de que «Sofía no lo dirá hasta volver a publicar»: la deriva del Studio es el aviso.
- Que el formulario de crear plan proponga «Se ofrece fuera: No» a quien no tiene la llave. Es mejora de UX; el 403 explica qué hacer.

**Riesgos:**
- **R1 · Coste operativo de la parte `rest` (4b, sin autorizar):** cada cambio de texto obliga a publicar en canario y promover con evidencia. Mientras tanto, `rest` retiene lo cambiado. Es el precio de «no alterar en silencio».
- **R2 · «Último autor»:** si A escribe el beneficio X, B corrige otro beneficio y después A publica, A no queda como autor. B guardó el texto completo con X a la vista. Exige dos personas con la llave (por defecto, dos Super Admin). Riesgo residual aceptado; a confirmar por [security].
- **R3 · Hacen falta dos personas distintas** con las llaves para publicar. Ya era así: quien publica no puede ser quien creó la versión o el perfil.
- **R4 · Punto de no retorno:** ninguno. Todo es aditivo y reversible; la `evidence` escrita no estorba al código viejo.

**Preguntas abiertas (como mucho tres; la implementación sigue la opción por defecto si no hay respuesta):**
1. **(Propietario)** ¿Encender o poner a la venta un plan (activarlo, «Se ofrece fuera: Sí», darle precio o vigencia, reactivarlo) debe pedir también el permiso nuevo, aunque su texto no cambie?
   - **Por defecto: sí.** Es lo único que cierra el agujero «editar fuera de la venta y luego encender», que en WhatsApp no cubre B.
   - Coste: el Administrador ya no enciende planes vendibles; los sigue pudiendo apagar. El propietario le puede dar la llave desde Roles sin tocar código.
2. **(Security)** El texto anterior a PT-14 no tiene autor registrado. ¿Basta con que lo apruebe quien publica, viéndolo en la vista previa?
   - **Por defecto: sí.** Fallar cerrado obligaría a inventar una acción para «reclamar» el texto.

---

## 11. Rectificación del lead tras la revisión de seguridad (23-11-2026)

La revisión de seguridad independiente (`informe-security-pt14.md`) dio el diseño por NO APTO en un punto y el lead lo rectificó. **Esta sección manda sobre D4, D10 y el §6.2 de arriba.**

**El defecto (B-1).** D4 guardaba en UNA columna a «la última persona que escribió el texto vigente o lo puso a la venta». Poner el plan a la venta sobrescribía, por tanto, a quien había escrito el texto. Quien escribía con el plan apagado podía publicarlo en la voz si otra persona con la llave solo pulsaba «Activar» (el CRM manda `{active: true}` y no enseña los beneficios): un texto que solo revisó quien lo escribió. Incumplía la frase del propietario: «sin … permitir que una misma persona satisfaga las dos aprobaciones».

**La rectificación (implementada en `90653164`).**
- **D4′ · Dos autorías.** `plans.commercial_text_by` = quien escribió el texto vigente: cambia SOLO cuando cambia el texto (y en el alta, siempre). `plans.commercial_sale_by` = quien puso el plan a la venta: cambia cuando el plan entra en la venta (alta que queda vendible, edición que lo mete en la venta, reactivar). Las dos, en la misma migración, ocultas y sin salir por ninguna respuesta.
- **D6′ · La instantánea** guarda `authors: [{planId, by, soldBy}]`. La lectura estricta rechaza la forma anterior. El formato sigue siendo `ultron-voice-catalog/1` (no se había publicado nada).
- **D10′ · Cuatro ojos.** Quien publica o promueve la voz no puede ser NI quien escribió el texto NI quien puso a la venta ningún plan capturado. En la vista previa, `authoredByYou` = eres una de las dos; `authorRecorded` = hay autor del texto. El 403, sus claves y su mensaje no cambian.
- **A falla cerrado ante un texto ilegible (N-2):** si los beneficios de después no se pueden leer (la lista anidada) y su valor cambió, pide la llave.

**Lo que queda aceptado, documentado y sin otra ronda** (seguridad: APTO CON CONDICIONES tras recomprobar):
- **R2, «último autor»:** si otra persona con la llave edita OTRO beneficio en el editor, con el texto delante, pasa a ser la autora y la primera puede publicar. Hacen falta dos personas con la llave y la segunda vio el texto.
- **N-9, «Duplicar» de un clic:** la copia nace con las dos autorías de quien duplica, sin abrir el editor. Para aprovecharlo hay que retirar además el original de la venta, y lo que se diría lleva el nombre «… (copia)». Si el propietario quiere cerrarlo, el alta puede heredar el autor del plan de origen cuando el texto coincide.
- **N-1, textos anteriores a PT-14 (autor `null`):** no excluyen a nadie. **Condición operativa, en el runbook:** antes de la PRIMERA publicación de la voz se comprueba en `audit_logs`, como evidencia, que quien publica no cambió el nombre ni los beneficios de los planes capturados.
- **N-4:** la instantánea no entra en la firma de la cadena; solo puede reescribirla el dueño de la tabla, que también puede cambiar `plans`.
- **N-5:** una orden para el modelo escrita en un beneficio y APROBADA por dos personas sigue pasando la guarda de voz (solo retiene precios y gratuidades): calibrar el léxico sigue pendiente, antes de llamar a clientes reales.
- **N-6:** `badge` y `restrictions` también son texto libre que lee WhatsApp y quedan fuera de A (el propietario la acotó a «nombres y beneficios»): decisión suya si se amplía.
- **N-7:** un Super Admin puede crear otra cuenta de Super Admin y cumplir con ella todos los cuatro ojos del sistema; queda auditado. Es anterior y sistémico.

**Lecturas de partida en producción (solo lectura, 23-11-2026, 00:21 UTC):** 26 planes, 12 vendibles; 0 vendibles sin beneficios y 0 con beneficios anidados; `iron_app` tiene SELECT, INSERT y UPDATE sobre `plans` a nivel de TABLA (las dos columnas nuevas los heredan); cuentas activas: 4 Super Admin, 1 Administrativo, 2 Recepción y 2 de QA; ninguna con el rol Administrador; 0 publicaciones de voz. **Consecuencia:** hoy solo los Super Admin editan planes, así que A no le quita nada a nadie el primer día.

