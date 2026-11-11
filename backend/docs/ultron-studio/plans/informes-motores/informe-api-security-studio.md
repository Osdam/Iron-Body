# Revisión de seguridad de API · 2.ª pasada · el paquete del Studio que escribe `/2` (Enmienda M-1)

Autor: `api-security-specialist`. Fecha: 2026-11-11.
Árbol: `_worktrees/ultron-h8/Iron-Body`, rama `feat/ultron-studio-h9`, HEAD `1c8f70bb`. El paquete está en el commit `4445abb7`.
Pendiente que cierra: §4.3 de `informe-api-security-ola.md`.

He hecho una revisión de **solo lectura**:
- no he editado código ni pruebas; este informe es el único fichero que he creado;
- no he usado red real ni producción;
- **no he ejecutado ninguna prueba**: otro agente hacía un ensayo de despliegue en la máquina. Todo lo «confirmado» sale de leer el código y las pruebas del autor (§6).

---

## Veredicto para E2

**SÍ: desde la seguridad de la API del Studio, se puede encender la llave de prompts en canario (E2).**

- **No hay bloqueantes, ni altos, ni medios.**
- Quedan 2 BAJO y 5 INFO. Ninguno impide E2.
- El de más peso para el propietario (S-2) **no se puede dar con la configuración de E2 a E5**:
  - con `MARKETING_ULTRON_STUDIO_CONFIG=canary`, promover está prohibido (`StudioPublications.php:1139-1141`), así que nunca se llena la parte del resto;
  - y, según el reviewer, la cadena de producción no tiene filas (N-8). Esto no lo he comprobado yo.

Lo que **garantiza** el paquete, comprobado en el código:
1. Lo que se firma en `/2` es exactamente el paquete que ató la vista previa en `previewHash`.
2. `governed` sale solo del contenido íntegro de la versión aprobada, nunca de la petición.
3. Legacy se escribe siempre en `/1`.
4. Un paquete que no compila no firma nada.
5. `status.engine.prompts` no saca ids ni textos.

**Condiciones operativas para E2–E5.** No son defectos del código, pero de ellas depende la garantía:
1. **Mantener `MARKETING_ULTRON_STUDIO_CONFIG=canary`, no `all`.** Así nadie puede promover y no aparecen partes arrastradas (S-2).
2. **El CRM tiene que enseñar `prompts.changes` y `prompts.bundleHash` en el diálogo de publicar antes de confirmar.**
   - La garantía «lo vio» acaba en el DTO. Si el diálogo no los pinta, quien publica firma a ciegas.
   - No he revisado el CRM (crm-fe).
3. **M1-A sigue sin alerta automática.**
   - Tras cada turno de E3–E5, mirar `ultron:prompt-trace` o `status.engine.prompts.lastTurns`: ningún `mismatch` ni `legacy_drift`. Es lo que ya se acordó en M1-13.
   - El backend sí los entrega: `word()` deja pasar esos dos estados (`StudioCanaryMeasures.php:159-162`).
4. **Antes de E3, mirar `GET status` → `engine.publication`.**
   - Tiene que salir `rest: null` (cadena vacía o Legacy).
   - Si no, una reversión en E5 **no** vuelve a Legacy: la canaria pasaría a la parte del resto (S-2 / N-7).

### Hallazgos por severidad

| Id | Sev. | Qué | Dónde | Estado | Dueño |
|---|---|---|---|---|---|
| S-1 | **BAJO** | El libro y el historial no distinguen una publicación que **cambia los prompts** de una que solo declara: no guardan ni el contrato (`/1`/`/2`) ni la huella del paquete | `StudioPublications.php:251-263`, `:367-373`, `:464-470`, `:825-871` | Confirmado | backend (B-S) + crm-fe |
| S-2 | **BAJO** | La parte del resto que se arrastra se firma en `/2` enseñando **solo su huella**, sin su tabla de cambios. Después, «revertir» (sin vista previa, sin cuatro ojos, con `ultron.rollback`) la pone en efecto en la canaria | `StudioPublications.php:1008-1032` (`carried`), `:1529-1558`, `:1662-1678`; prueba del autor `UltronStudioPublicationPromptsTest.php:246-281` | Confirmado (por diseño, M1-12; es el N-7 del reviewer, medido aquí contra los cuatro ojos). No alcanzable en E2–E5 | backend (B-S) + crm-fe |
| S-3 | INFO | El 409 `prompts_compile_failed` dice «con los valores de esta publicación» también cuando el que no compila es el **resto**: confunde y bloquea toda publicación (N-8) | `UltronStudioException.php:404-410`; `StudioPublications.php:1543-1547`, `:1636-1638` | Confirmado | backend |
| S-4 | INFO | `lastTurns` enseña a cualquiera con `ultron.view` **el minuto de cada mensaje entrante** de la conversación canaria, también con la llave apagada | `StudioPublications.php:730`; `StudioCanaryMeasures.php:107-150` | Confirmado (por diseño, plan §D) | propietario (decisión) |
| S-5 | INFO | La vista previa (GET, `ultron.view`, 120/min) escribe un `warning` por petición cuando el paquete no compila. Quien solo lee puede llenar el log | `StudioPublications.php:1536`, `:1596`, `:1603` | Confirmado | backend |
| S-6 | INFO | Las versiones aprobadas antes de E2 se aprobaron con el texto «publicar no cambia lo que ULTRON dice». Con la llave encendida, el efecto real (los parches) solo lo ve quien publica. Esa persona no es el autor, pero **puede ser quien aprobó** | `StudioPublications.php:1918-1932`; `StudioVersions.php:270-273` | Confirmado | propietario (decisión) |
| S-7 | INFO | El fixture del CRM y el ejemplo del DTO enseñan `legacy/part_legacy` tras revertir sobre una cabeza que «arrastra V0». El backend daría `studio` con V0 (S-2) | `StudioPublicationExport.php:123-125`, `:151-156`; `dtos-studio-prompts.md` §2.1 | Confirmado (documental) | backend (B-S) |

---

## 1. Alcance

### Revisado (código real)

- **Diff de `4445abb7`** en:
  - `StudioPublicationDeclaration.php` (entero, 1 139 líneas);
  - `StudioPublications.php` (entero, 2 089 líneas);
  - `StudioPromptBundle.php`, `StudioEngineReadings.php` y `StudioCanaryMeasures.php`;
  - `StudioPublicationExport.php` (diff);
  - `UltronStudioException.php` (diff, constructor y fábricas que usa la publicación).
- **Rutas y controladores de publicación:**
  - `routes/ultron-studio.php:115-200`;
  - `UltronStudioVersionController.php:190-294`;
  - `UltronStudioController::status` (`:57-69`).
- **Permisos y acceso:**
  - `AuthorizationMap.php:696-755`;
  - `CrmPermission.php:302-306`;
  - `StudioAccess.php:85-209`;
  - `StudioPublishing` (llaves, ventana y laboratorio).
- **Lo que el Studio importa del motor:**
  - `UltronPromptCompiler::validate()/patches()` (`:199-270`, `:307-349`);
  - `UltronPublishedConfig::signatureFor()/describe()` (`:243-300`);
  - `UltronPromptOffer::key()` (`:110-115`).
- **Pruebas del autor, leídas y no ejecutadas:**
  - `UltronStudioPublicationPromptsTest.php`;
  - la ayuda `publishAs` de `PublicationFixtures.php:152-161`.
- **Informes:** `informe-bs.md`, `dtos-studio-prompts.md` e `informe-reviewer-ola.md` (para no repetir N-7 y N-8).

### Fuera de alcance (no declaro nada seguro de esto)

- **El CRM (crm-fe):**
  - si el diálogo de publicar enseña `prompts.changes`, `bundleHash` y `carried`;
  - si pinta en rojo `mismatch` y `legacy_drift`;
  - si interpola los textos sin `innerHTML`.
- **Las carreras reales en PostgreSQL** (`UltronStudioPublicationPromptsRaceTest`, `…V2RaceTest`): no las he ejecutado.
- **El lado del motor** (`decide`, `commit`, sello y selector de n8n): ya está revisado en `informe-api-security-ola.md` §4.
- **La puerta del laboratorio (M1-7):** no he vuelto a comprobar que el veredicto sea de otra persona. Es del H4.
- **Producción:**
  - el estado real de la cadena (me fío del reviewer, N-8);
  - el valor real de las llaves;
  - la conversación canaria.

---

## 2. Modelo de amenazas (lo que cambia respecto al de la ola)

**Activo nuevo: los prompts de sistema de la conversación canaria.**
- El motor **confía en la firma**: entrega lo que el Studio firme en `governed` y `prompts`.
- El activo es, por tanto, **la integridad de lo que se firma**.
- La firma usa una subclave derivada de `APP_KEY` con la etiqueta `ultron-studio-publication`, separada de la del `decide_token` (`UltronPublishedConfig.php:280-300`).

**Actores:**
- personal con solo `ultron.view`;
- autor de una versión (`ultron.edit_draft`);
- revisor (`ultron.approve`; Administrador lo tiene por defecto);
- quien publica (`ultron.publish`; solo Super Admin por defecto, `CrmPermission.php:302-306`);
- quien revierte (`ultron.rollback`; solo Super Admin por defecto);
- n8n y el titular del secreto interno, que escribe `marketing_ai_actions.metadata.studio_prompts` a través del commit;
- el operador que cambia las llaves.

**Fronteras de confianza:**

| Frontera | Qué la cruza | Cómo se valida |
|---|---|---|
| CRM → publicar | `clientRequestId`, `previewHash`, `acknowledgedNoEffect` y `note` | Solo esas cuatro claves. `governed` no se acepta |
| Contenido de la versión → `governed` | Valores del personal | Integridad (`content_hash`) y el compilador, con valores cerrados |
| Cadena → motor | `effective_json` firmado | Contrato, huella, firma y forma |
| n8n → `metadata.studio_prompts` → `status` | Datos de un tercero | Recortes `word()` y `short()` |
| Configuración → Studio | La llave de prompts | Solo `canary` u `off` |

---

## 3. Lo que pidió el lead, punto por punto

### 3.1 La vista previa ata la huella exacta del paquete a los cuatro ojos: CONFIRMADO

**Qué ata `previewHash`** (`StudioPublicationDeclaration.php:379-408`):
- `versionUuid`, `contentHash` y `catalogVersion`;
- las listas de la clasificación, incluida `prompt`;
- `labGate {mode, latest}`;
- con la llave encendida, `prompts = {base, bundleHash, carried: {bundleHash, version}, compiler}` (`StudioPublications.php:1567-1575`).

`bundleHash` es la huella de los **cinco textos compilados**. Cualquier cambio de parche, de base o de compilador cambia la huella aunque nadie suba `VERSION`.

**Publicar recalcula la huella con las mismas puertas que la vista previa** (`StudioPublications.php:203-207`, `facts()` `:1195-1293`) y exige `hash_equals` con la enviada (`StudioPublicationDeclaration.php:349-351`). Si no coincide, 409 `preview_stale` con la vista previa nueva (`StudioPublications.php:1113`).

**Caminos revisados para firmar un paquete que la persona no vio:**

| Camino | Resultado | Dónde |
|---|---|---|
| **La cabeza se mueve entre las puertas y la transacción** | La parte canaria sale del mismo `$h['prompts']` que dio la huella (`:233`, `:1632`). La del resto se vuelve a leer en la transacción y se compara con la vista (versión y `bundleHash`); si difiere, 409 `publication_moved`. La carrera por `seq` o por `usv_one_canary` da 409 | `:215-216`, `:1634-1647`, `:1847-1864` |
| **La llave cambia entre la vista previa y publicar** | La huella cambia: `prompts` pasa de null a objeto (`StudioPublicationDeclaration.php:399-405`). Da 409 `preview_stale`, y reconocer los gobernados como `no_effect` ya no vale | Prueba `UltronStudioPublicationPromptsTest.php:177-202` |
| **La llave cambia dentro de una petición** | No puede: `config()` es constante en una petición de FPM. Al recargar FPM, un worker viejo puede servir un publicar con la llave anterior. Si era `canary` y ya está en `off`, escribe `/2` con el paquete que la persona vio, y el motor, con su llave en `off`, lo ignora (`prompts_off`). Inocuo | `StudioEngineReadings.php:171-176` |
| **Parte arrastrada** | Entra en la huella (versión y `bundleHash`), pero la vista previa enseña **solo** la etiqueta, la huella y `baseline`, no su tabla de cambios: ver **S-2** | `:1023-1029` |
| **Promover** | No compila y no cambia lo que recibe la canaria (`{canary: V, rest: V}`). La conversación canaria se rige por la parte canaria; las demás son `out_of_scope` en el motor. `/2` solo si todas las partes ya lo eran. Con `studio_config=canary`, prohibido | `:345`, `:1662-1678`, `:1139-1141` |
| **Revertir / volver a Legacy** | No compilan. Revertir conserva `/2` solo si todo era `/2` (la canaria pasa al resto: S-2). Legacy siempre en `/1` | `:446`, `:537`, `:1662-1678` |
| **Reintento** (`clientRequestId`) | Devuelve la entrada de la primera vez, sin firmar nada nuevo. Exige la misma persona, la misma acción, la misma versión y el mismo cuerpo, `previewHash` incluido | `:1771-1839` |

**`governed` manipulado en la petición: NO se puede.**
- El controlador valida y pasa solo `clientRequestId`, `previewHash`, `acknowledgedNoEffect` y `note` (`UltronStudioVersionController.php:200-218`); `publish()` no tiene ningún parámetro de valores.
- `governed` sale de `$version->content` (`StudioPublications.php:1534`, `:1632`), con la integridad comprobada antes de compilar: puerta 5 (`:1090-1092`) antes de la de prompts (`:1103-1105`).
- El compilador acepta solo valores cerrados (`UltronPromptCompiler.php:236-270`).
- No existe ninguna ruta que cambie el contenido de una versión.
- **Hueco de prueba:** ninguna prueba manda `governed` en el cuerpo. Ver §5.

**Quién publica y quién aprueba:**
- **Permiso:** `ultron.publish` en la ruta (`AuthorizationMap.php:710`, `:753`) y otra vez en el dominio, junto con `ultron.view` (`StudioPublications.php:176`; `StudioAccess.php:121-131`).
- **Persona:** un `Admin` identificado (`StudioAccess.php:85-100`). Un token compartido no vale. No hay más requisito de «persona verificada» en este camino, y el contrato H5 no lo pide.
- **Cuatro ojos por identidad:**
  - quien publica o promueve no puede ser el autor (`StudioPublications.php:1918-1932`);
  - quien aprueba tampoco (`StudioVersions.php:270-273`);
  - **quien aprueba y quien publica pueden ser la misma persona**. Ver S-6.
- **Ventana:** publicar (`:1126-1128`) y promover (`:1173-1175`). Revertir y Legacy no tienen ventana, por diseño (dirección segura).

### 3.2 `/2` solo con la llave de prompts encendida; Legacy siempre en `/1`: CONFIRMADO

- **Publicar:** `/2` si, y solo si, `$h['prompts'] !== null`, es decir, si `promptsKey() === 'canary'` en esa petición (`:1231`, `:1255`, `:1625-1626`).
- **Promover y revertir:** `/2` solo con la llave en `canary`, alguna parte viva y todas ya `/2` (`:1662-1678`).
- **Legacy:**
  - `insertEntry` usa por defecto `D::CONTRACT` (`:1730`, llamada en `:537`);
  - además, `effectiveJson()` lanza si alguien intenta `/2` con las dos partes nulas (`StudioPublicationDeclaration.php:615-617`);
  - revertir hasta dejar la cadena sin partes también da `/1` (`:1665`; prueba `:169-172`).
- **Matiz.** La puerta de publicar exige además la llave del sello (`studio_config ≠ off`, `assertKeys`). Revertir no mira llaves: con `studio_config=off` y todo en `/2` escribiría `/2`, pero el motor responde `studio_config_off`. Inocuo.

### 3.3 409 `prompts_compile_failed` sin firmar nada: CONFIRMADO

**No se firma nada, en dos sitios:**
- es la puerta 7b, antes de la transacción (`:1103-1105`);
- y, dentro de la transacción, `promptedForPublish` lanza **antes** de `insertEntry` (`:233` frente a `:242`; `:1628-1638`). La excepción deshace la transacción.

**El mensaje no lleva nada interno:**
- es fijo y no lleva el motivo del compilador, ni valores, ni textos (`UltronStudioException.php:404-410`);
- 409 por defecto (`:26-33`);
- no va al log de errores (`:35-38`).

**El log tampoco:** `ultron_studio.prompts_compile_failed` lleva `part` (`new`/`carried`) y `reason`, que sale de un vocabulario cerrado de `UltronPromptException` (`base_unreadable`, `base_corrupt`, `invalid_governed`, `anchor`, `too_large`, `roles`) o es `error` (`StudioPromptBundle.php:172-177`). Ni valores ni textos.

**Matices:** S-3 (el mensaje no distingue la parte) y S-5 (ruido de log).

### 3.4 `status.engine.prompts`: CONFIRMADO, sin hallazgo de exposición más allá de S-4

**Sin ids ni textos:**
- La salida es `{key, base, compiler, legacyBundleHash, current, lastTurns}` (`StudioPublications.php:724-731`).
- Por turno (`StudioCanaryMeasures.php:121-147`):
  - `at`, al minuto en UTC;
  - `declared {state, version8}`;
  - `offered {mode, reason, version8, bundle8}`;
  - `observed {mode, bundle8, flowRev}`;
  - `state`.
- **No salen:** el id de la conversación (solo filtra, `:165-170`), ni el de la fila, ni el del mensaje, ni `execution`, ni `observed.reason`, ni `contentHash`, ni `entry`.

**Solo la canaria:** `where conversation_id = <canaria> and source_type = inbound_message` (`:114-116`). Sin canario configurado, la lista sale vacía (`:110-112`).

**Un valor hostil en `metadata.studio_prompts`** no puede romper el DTO ni inyectar nada:
- **Textos:** `word()` deja pasar solo `\A[A-Za-z0-9_.:-]{1,64}\z` (`:159-162`); no admite `<`, `>`, `"`, `'`, `&`, espacios ni saltos de línea, así que no hay XSS almacenado.
- **Recortes:** `short()` exige 8 caracteres hexadecimales o guiones al principio y devuelve solo esos 8 (`:153-156`).
- **Tipos:** un tipo distinto de cadena da `null`, y un nodo que no es array da `null` (`:122-126`).
- **Tamaño:** como mucho 20 filas. Lo que llega a la metadata ya lo acota el commit (motor, §4.1 de la ola).
- **Lo prueba el autor:** `UltronStudioPublicationPromptsTest.php:345-385` mete `TEXTO LIBRE`, `EJEC-…`, otra conversación y otro `source_type`. **No** prueba valores con `<…>`, demasiado largos o de otro tipo: ver §5.

**401/403:**
- `GET status` exige `ultron.view` (`AuthorizationMap.php:696`) y persona (`UltronStudioController.php:59`);
- prueba `:387-393` (401 sin sesión, 403 sin `ultron.view`, 200 con solo `ultron.view`).

**Fallo de lectura:** se traga y deja en el log solo la clase. El bloque sale sin `prompts` y nunca con una traza inventada (`StudioPublications.php:673-680`).

### 3.5 `StudioEngineReadings`: la llave de prompts no expone otros valores del entorno: CONFIRMADO

- `promptsKey()` lee una sola clave de configuración y devuelve solo `'canary'` u `'off'` (`StudioEngineReadings.php:171-176`).
- Es el mismo criterio que `UltronPromptOffer::key()` (`:110-115`), y la prueba `:304-310` las compara con 11 valores.
- `read()` y `describe()` no cambian en esta ola. `describe()` saca booleanos, el contrato, `entry` y `seq` de la cabeza, y si hay canario, nunca su id (`UltronPublishedConfig.php:243-278`).

### 3.6 BOLA/BFLA, exposición, errores y límites: CONFIRMADO sin hallazgo nuevo

**BFLA:** no hay rutas nuevas. Cada escritura exige su permiso en la puerta (`AuthorizationMap.php:710-711`, `:753`, `:755`) y otra vez en el dominio (`StudioPublications.php:176`, `:291`, `:404`, `:507`), siempre junto con `ultron.view`.

**BOLA:**
- El Studio es de un solo gimnasio, y las versiones y la cadena son globales para quien tiene `ultron.view`. Es por diseño.
- El uuid se resuelve sin route-model binding y sin oráculo de existencia: `ultron.view` se comprueba antes de buscar nada (`StudioAccess.php:92-97`).

**La vista previa para quien solo tiene `ultron.view`** (`UltronStudioVersionController.php:262-267`):
- **Qué enseña:**
  - los valores gobernados de la versión (ya visibles en su detalle);
  - el texto **fijo** de cada parche y su ancla (líneas cortas de la base, como `COMO SUENAS:` o `FORMA:`, `UltronPromptCompiler.php:110-120`);
  - la huella y la etiqueta de la parte arrastrada.
- **Qué no enseña:** los prompts enteros (`StudioPromptBundle.php:24-26`, `:151-166`).
- **Mi juicio:** es correcto que la vea; quien revisa necesita saber qué cambia. No lo considero exposición excesiva.

**Errores:**
- `prompts_compile_failed` va sin detalle (§3.3);
- `publication_moved` y `preview_stale` ya existían;
- `conflictRefusal` vuelve a lanzar el UNIQUE que no reconoce (`:1863`), que el manejador general convierte en 500 genérico. Ya existía y no lo toca esta ola.

**Límites:**
- cuerpo de 64 KB (`routes/ultron-studio.php:115`);
- lecturas, 120 por minuto; escrituras, 30 por minuto; promover, además el limitador de la evidencia;
- cada vista previa compila como mucho dos paquetes de unos 113 KB, sin red. Es un coste acotado;
- respuesta de la vista previa: como mucho 4 parámetros con 5 parches de 1 200 bytes (`UltronPromptCompiler.php:102`, `:207`);
- `lastTurns`: como mucho 20 turnos.

### 3.7 ¿Puede entrar texto libre del personal en un prompt por este camino? NO: CONFIRMADO

- **Lo que se firma:** `governed` son exactamente cuatro claves de tipo y valor cerrados (`StudioPublicationDeclaration.php:91-96`, `:1042-1061`). El compilador las vuelve a validar:
  - `tone` entre 3 valores;
  - `goal` entre 3 valores;
  - `emojis` booleano;
  - `maxChars`, entero de 160 a 480 de 20 en 20 (`UltronPromptCompiler.php:236-270`).
- **Lo único que se interpola** es ese entero (`:340`).
- **Ni las etiquetas, ni las notas, ni los motivos entran en la parte** (`StudioPublicationDeclaration.php:459-482`):
  - la nota va a la columna `reason`, fuera de `effective_json` (`StudioPublications.php:1753`);
  - la etiqueta solo sale en los DTO y en el libro.
- **Las S-NNN y `intent.fallback`** siguen en `noEffect`, con reconocimiento (`StudioPublicationDeclaration.php:256-268`). Solo viaja su código, que nunca llega a la parte firmada.

---

## 4. Hallazgos en detalle

### S-1 · BAJO · Auditoría: el libro no dice si una publicación cambió los prompts

**Qué pasa.** Con la llave encendida, «publicar» puede cambiar lo que ULTRON dice en la canaria, pero el evento `version.published` no lo distingue de una publicación que solo declara. Su `diff` lleva `entry`, `seq`, `effectiveHash`, `labGate` y `noEffect` (`StudioPublications.php:251-263`), pero no:
- el contrato (`/1` o `/2`);
- la huella del paquete (`bundleHash`);
- la de la parte arrastrada.

**El mismo hueco en otros sitios:**
- `version.promoted` y `version.rolled_back`, que pueden **activar** en la canaria la parte arrastrada (S-2) (`:367-373`, `:464-470`);
- `PublicationEntryDto` y el historial (`:825-871`), que no sacan `engine_contract`.

**Impacto.** En un incidente («¿quién puso estos prompts en la canaria y cuándo?») hay que leer `effective_json` en la base de datos: ni el libro ni el CRM lo responden. La cadena firmada y de solo inserción sí lo guarda, así que la información no se pierde, pero no es auditable desde la aplicación.

- **Actor:** ninguno lo explota. Es una carencia de trazabilidad.
- **Estado:** confirmado, por lectura.
- **OWASP:** API9 (inventario y trazabilidad) y API8 (el registro como control).
- **Arreglo mínimo (backend B-S, aditivo):**
  1. Añadir `contract` y `bundleHash` (de la canaria y del resto, o null) al `diff` de los tres eventos.
  2. Añadir `contract` y `bundle8` a `historyEntryDto`.
  3. Que crm-fe los pinte, por ejemplo con «cambió los prompts · 702890f7».
- **Prueba negativa:** ver §5.

### S-2 · BAJO · La parte arrastrada se firma sin que nadie vea su tabla de cambios, y «revertir» la activa

**Qué pasa:**
1. Con la llave encendida, publicar V1 sobre una cabeza cuyo resto es V0 en `/1` compila V0 y la firma en `/2` dentro de la misma entrada (`:1529-1558`, `:1634-1640`).
2. La vista previa enseña de V0 solo `{versionUuid, label, bundleHash, shortHash, baseline}` (`:1023-1029`), **sin `changes`**.
3. Después, «revertir V1» deja `{canary: V0, rest: V0}`. Sigue en `/2` porque todas las partes lo eran (`:1662-1678`), y la canaria recibe **el paquete de V0, que nunca estuvo en efecto**. La prueba del autor lo confirma (`UltronStudioPublicationPromptsTest.php:246-281`).

**Lo que lo hace delicado frente a los cuatro ojos:**
- revertir no tiene vista previa, ni cuatro ojos, ni ventana, ni llaves (`:402-413`). Basta `ultron.rollback`;
- quien revierte cree volver «a como estaba», que era Legacy (V0 en `/1`, `declaration_v1`), y pone en efecto un tercer paquete;
- quien publicó V1 solo vio la huella de V0, no sus parches.

**Por qué es BAJO y no más:**
- el contenido sale siempre de textos fijos aprobados por el propietario, con valores cerrados, y los valores de V0 pasaron sus cuatro ojos al aprobarse;
- el alcance es solo la conversación canaria;
- es la regla M1-12, que el lead confirmó;
- el reviewer lo dejó como N-7 («no es un defecto»). Aquí lo mido contra la pregunta del lead: sí hay un camino que firma, y luego activa, un paquete del que nadie vio el contenido, solo la huella;
- **en E2–E5 no se puede dar:** con `studio_config=canary` no se puede promover (`:1139-1141`), así que la cadena nunca tiene resto si empieza vacía, y la de producción lo está según el reviewer (N-8; no verificado por mí).

- **Estado:** confirmado (lectura y prueba del autor).
- **OWASP:** API8 (el control de cuatro ojos no cubre esa ruta).
- **Arreglo mínimo, antes de que se pueda promover con la llave encendida:**
  1. **backend (B-S):** que `promptsDto` incluya `carried.changes`, con los mismos parches que `changes` (`UltronPromptCompiler::patches()` sobre el `governed` del resto). Es puro y barato.
  2. **crm-fe:** en el diálogo de publicar, una línea del tipo «Si revierte esta versión, la canaria pasará a «V0» (no a Legacy)» con su tabla; y en el de revertir, a qué parte queda la canaria.
- **Alternativa estructural (architect y propietario):** que revertir el canario con el resto en `/2` escriba la canaria en `/1`, de modo que la canaria vuelva a Legacy. Cambia M1-12; no la recomiendo sin decisión del propietario.

### S-3 · INFO · `prompts_compile_failed` no distingue la parte arrastrada

- **Qué pasa:**
  - si el **resto** no compila (por ejemplo, un `maxChars` fuera de paso aprobado según M1-11), **toda** publicación da 409 con «los valores de esta publicación» (`UltronStudioException.php:404-410`);
  - la vista previa trae `bundleHash` de la versión nueva, `carried: null` y `error` (`StudioPublications.php:1543-1547`), así que el CRM no puede explicar el motivo. Solo el log dice `part: carried`.
- **Impacto:** de operación (falla cerrado, con salida por revertir o Legacy, N-8). No hay fuga.
- **Arreglo:** `payload: {part: 'new' | 'carried'}` en el 409 y en `prompts.error` de la vista previa, y el texto según la parte (backend).

### S-4 · INFO · `lastTurns` enseña el ritmo de la conversación canaria a todo `ultron.view`

- **Qué pasa:** el minuto de cada mensaje entrante de la canaria, hasta 20, para cualquiera con `ultron.view` (`StudioPublications.php:730`; `StudioCanaryMeasures.php:129`).
  - También con la llave de prompts apagada: la consulta no depende de la llave.
  - Antes, la evidencia del H5 solo daba recuentos.
- **Por qué es INFO:** es lo que pide el plan (§D, «`at` (minuto UTC)»), y la canaria es el teléfono del propietario.
- **Si un día** la canaria fuera la conversación de un cliente, sería un dato personal de actividad expuesto a todo el personal con `ultron.view`.
- **Decisión del propietario:** aceptarlo, o devolver `lastTurns` solo con la llave en `canary` o solo a `ultron.publish`/`ultron.rollback`.

### S-5 · INFO · Ruido de log desde una lectura

- **Qué pasa:** cada `GET …/publication-preview` (`ultron.view`, 120/min por credencial) sobre una versión que no compila, o con un resto ilegible, escribe un `warning` (`:1536`, `:1596`, `:1603`). Un lector puede llenar el log sin escribir nada.
- **No hay fuga:** el log no lleva valores.
- **Arreglo:** registrar solo en publicar (no en la vista previa), o deduplicar por versión y minuto (backend).

### S-6 · INFO · Quién ve el efecto cuando se enciende la llave

- **Qué pasa:** las versiones APPROVED antes de E2 se aprobaron cuando la interfaz decía que publicar no cambia lo que ULTRON dice, y los cuatro gobernados se reconocían como `no_effect`.
  - Con la llave encendida, se publican con efecto, sin volver a aprobarlas (la huella de la reseña son avisos del grafo, no la clasificación).
  - El efecto exacto lo ve quien publica, que no es el autor. Pero **puede ser el mismo que aprobó**: no hay ninguna comprobación revisor ≠ publicador (`StudioPublications.php:1918-1932`).
  - Los cuatro ojos se cumplen (autor + publicador), pero solo una persona ha visto el efecto.
- **Decisión del propietario, sin cambio de código obligatorio:**
  - para E3/E4, publicar versiones aprobadas **después** de E2;
  - o que publique alguien distinto de quien aprobó.

### S-7 · INFO · El fixture del CRM contradice S-2

- **Qué pasa:** `StudioPublicationExport.php:126-127` describe una cabeza que «arrastra V0 en el resto», pero el turno «después de revertirla» (`:151-156`) sale `legacy/part_legacy`. Con V0 en el resto, el backend daría `studio` con V0 y huella Legacy, porque V0 es la línea base. Lo mismo pasa en `dtos-studio-prompts.md` §2.1.
- **Impacto:** documental. Quien verifique E5 o pinte el CRM espera «revertir → Legacy» en un caso en que no lo es.
- **Arreglo:** que el escenario de la traza sea «sin resto», o que el tercer turno sea `studio/V0` (backend B-S, regenerar los fixtures).

---

## 5. Pruebas negativas propuestas (para `tester`)

| Hallazgo o punto | Prueba |
|---|---|
| §3.1, mass assignment | `POST …/publish` con la `previewHash` buena y, además, `governed: {"persona.tone":"entusiasta",…}`, `prompts: {…}` y `contract: "ultron-engine-config/2"` en el cuerpo. Resultado: 201, y lo firmado en `canary.governed` es lo de la versión (`profesional`/200), no lo del cuerpo. Mutación: pasar `$request->input('governed')` a `promptedForPublish` hace caer la prueba |
| §3.1, previewHash de otro | `previewHash` de la vista previa de OTRA versión, o calculada con la llave apagada → 409 `preview_stale` y cadena intacta (la segunda ya está, `:177-202`) |
| §3.4, valores hostiles | `metadata.studio_prompts` con `observed.flowRev = '<img src=x onerror=alert(1)>'`, `offered.reason = str_repeat('a', 65)`, `state = ['applied']`, `offered.version = 123`, `observed.bundleHash = 'ZZZZZZZZ…'` y `observed = "texto"`. Resultado: 200, y cada campo afectado es `null`. El cuerpo no contiene `<img`, `onerror` ni la cadena de 65 |
| §3.3 | Paquete que no compila → el cuerpo del 409 no contiene `invalid_governed`, `330` ni texto de parche; ninguna fila nueva en `ultron_studio_publications` ni evento `version.published` |
| S-1 | Con la llave encendida, el `diff` de `version.published` lleva `contract = /2` y `bundleHash`; con la llave apagada, `/1` y null. Revertir con el resto en `/2` deja en `version.rolled_back` la huella que queda en efecto |
| S-2 | La vista previa con resto arrastrado no `baseline` trae `carried.changes` igual a `UltronPromptCompiler::patches(governed del resto)` |
| S-3 | Resto que no compila → 409 con `payload.part = carried`; versión nueva que no compila → `part = new` |
| §3.2 | **Falta:** Legacy **con la llave encendida** y la cabeza en `/2` → `/1`. La prueba que hay (`UltronStudioPublicationPromptsTest.php:277-280`) apaga la llave antes. Mantener: revertir hasta dejar la cadena sin partes → `/1` (`:169-172`). Mutación: quitar la guarda R10 de `effectiveJson()` (`StudioPublicationDeclaration.php:615-617`) y forzar `/2` en Legacy hace caer una prueba |

---

## 6. Qué verifiqué de verdad, y qué no

**No ejecuté nada:** ni pruebas, ni PHP, ni PostgreSQL, ni red. La máquina estaba ocupada con un ensayo de despliegue y el lead lo prohibió.

**Lo que hice:**
- `git show --stat` y `git diff 9d6b3252..1c8f70bb` de los ficheros del paquete;
- lectura completa de `StudioPublications.php` y de `StudioPublicationDeclaration.php`;
- lectura de las piezas citadas del motor;
- lectura de las pruebas del autor citadas.

**Cada «confirmado» de este informe es por lectura de la ruta de ejecución,** con `fichero:línea`. Donde me apoyo en una prueba del autor, lo digo; **no la he visto pasar**.

**No pude verificar:**
- el CRM: si pinta `changes`, `bundleHash`, `carried` y los estados en rojo;
- las carreras en PostgreSQL;
- el estado real de la cadena y de las llaves en producción (me fío del reviewer: «hoy no hay filas en la cadena de producción»);
- que el veredicto del laboratorio sea de otra persona (H4).

**Datos sensibles:** no he visto ni impreso ningún secreto, token ni dato personal. Los únicos valores citados son huellas públicas del repositorio y textos fijos.

---

## 7. Riesgos residuales y decisiones del usuario

1. **M1-A (de la ola):** la alerta automática de `mismatch` y `legacy_drift` sigue pendiente. Para E2–E5, solo en la canaria, se acepta con la vigilancia manual acordada en M1-13. Antes de ampliar el alcance más allá de la canaria, la alerta debería existir.
2. **S-2:** decidir si se aceptan las partes arrastradas que solo se ven por su huella (hoy es M1-12) o si se añade `carried.changes`. Hay que hacerlo **antes** del primer `studio_config=all` con la llave de prompts encendida.
3. **S-4:** decidir si `lastTurns` debe verse con solo `ultron.view`.
4. **S-6:** para E3/E4, publicar versiones aprobadas después de E2, o que publique alguien distinto de quien aprobó.
5. **Condición de E2:** que el diálogo del CRM enseñe la tabla de cambios y la huella. Sin eso, la garantía de los cuatro ojos sobre el paquete es solo de la API.
6. **Secretos que rotar por esta revisión:** ninguno.
