# Informe del paquete del Studio (B-S, paso 2 de la Enmienda M-1)

**Autor:** `backend` (B-S). **Fecha:** 11-11-2026. **Árbol:** `feat/ultron-studio-h9` (HEAD `120a8b23`), en local. **Sin commit, sin push, sin producción, sin red real, n8n sin tocar.**
**Contrato:** `plans/STUDIO_MOTORES_PLAN.md` §B.4, §D, E11–E13, R10 y R16, congelado por la Enmienda M-1 (`ULTRON_STUDIO_H5_CONTRACT.md`). API del motor usada: `informes-motores/api-compilador.md` (sin cambios en el paquete del motor).
**DTOs para el frontend:** `informes-motores/dtos-studio-prompts.md` (JSON reales).

---

## Resumen para el lead

**Qué quedó (implementado y verificado en local, SQLite y PostgreSQL 14):**

- Con la llave `marketing.ultron.studio_prompts` en `off` (el defecto) **todo es como hoy**: `/1`, la misma clasificación, la misma `previewHash`; el fixture congelado `publication-cases.json` vale entero (231 casos, sin tocarlo). Lo único nuevo visible: `effect: "declaration"` y `prompts: null` en la vista previa, y `engine.prompts` (`key: "off"`) en `status`.
- Con la llave en `canary`: la vista previa enseña el paquete exacto (tabla de cambios con el texto de cada parche, roles, huella, la parte que se arrastra), los cuatro gobernados pasan a la clase `prompt` (no se reconocen), `intent.fallback` y las S-NNN siguen en `noEffect`; `previewHash` incluye la huella del paquete; publicar escribe **`ultron-engine-config/2`** con `governed` + `prompts` firmados con la firma de siempre; el **lector real** del motor y la **oferta real de `decide`** lo leen (`studio`, huella `702890f7…` para G8, la de referencia de B-M). Un paquete que no compila → **409 `prompts_compile_failed`** y no se escribe nada. Volver a Legacy, siempre `/1` (R10).
- `status.engine.prompts` = `{key, base, compiler, legacyBundleHash, current, lastTurns ≤ 20}`, sin ids ni textos; `current` es la misma regla que `UltronPromptOffer` y una prueba la compara con la real en cada estado de la cadena. `engine.message` y la ayuda de `ultron.publish`/`ultron.rollback` ya no afirman sin condición «no cambia lo que ULTRON dice ni hace».

**Cifras (comando → resultado; todo con `nice -n 10`, un proceso a la vez):**

| Motor de base | Qué | Resultado |
|---|---|---|
| — (Unit) | `tests/Unit/UltronStudio` entero (incluye la nueva `StudioPromptDeclarationTest`: 62) | **1 912 OK**, 15 435 aserciones |
| SQLite | `UltronStudioPublicationPromptsTest` (nueva, 11) | OK, 338 aserciones |
| SQLite | Publicación del Studio (Access, Canary, Config, Lifecycle, Rollback, Verified, Schema, EngineConfigV2Schema), StatusCatalog, Permissions | **177 OK** (9 omitidas: solo PG) |
| SQLite | EngineReadOnly + EngineReadings | 15 OK |
| SQLite | LabNoEngineEffects, LabRunner, VersionLifecycle, Strategies ×3, `UltronPromptsTraceCommandTest` | 70 OK |
| SQLite | `UltronEngineIsolationTest` (no la toco; solo para comparar) | 11 OK |
| **PostgreSQL** | `UltronStudioPublicationPromptsTest` + `UltronStudioPublicationPromptsRaceTest` (nueva, 3 carreras con 6 sesiones reales) | **14 OK**, 522 aserciones |
| **PostgreSQL** | Las 10 suites de publicación de arriba | **177 OK** (1 omitida) |
| **PostgreSQL** | 2 carreras del H5 (para ver que el rasgo de carreras sigue igual) | 2 OK |
| — | `pint --test` sobre todo lo tocado | passed |
| — | 4 mutaciones (clasificación sin llave, huella sin paquete, publicar siempre en `/1`, quitar la puerta del compilado) | las 4 tumban pruebas (la 4.ª tras añadir una aserción de orden de puertas) |

**Decisiones que necesitan al lead (bloqueantes o de criterio):**

1. **El catálogo NO lo he cambiado** (ni `VERSION` ni los textos de los bindings): choca con la congelación de `2026-10-19.1` (H8 protección 11, `ULTRON_STUDIO_H8_CONTRACT.md:70`; H9 protección 15, `ULTRON_STUDIO_H9_CONTRACT.md:303`) y lo atan tres fixtures congelados del tester (`StudioPublicationParityTest.php:128` con `publication-cases.json`, que tengo prohibido; `StudioValidationParityTest.php:65`; `StudioCoherenceParityTest.php:69`). En producción, subir `VERSION` deja **toda versión aprobada** sin poder compilarse a voz (`StudioVoiceCompiler.php:393`, `version_catalog_mismatch`) y cualquier cambio de texto cambia `StudioCatalog::hash()`, con lo que las estrategias del H9 que referencian la línea base caen en `baseline_mismatch` (`StudioStrategyValidator.php:148`). Dejo el diff listo y **sin aplicar**: `informes-motores/propuesta-catalogo-bs.diff` (VERSION `2026-11-12.1` + los textos de `persona.tone/emojis/maxChars`, `intent.fallback`, `strategy.goal`). La verdad, mientras tanto, la dicen `engine.message`, `preview.effect/prompts` y la ayuda de los permisos. Es el primer recorte del plan («Recorte si falta tiempo: textos del catálogo»). **Recomiendo no aplicarlo en esta entrega.**
2. **El normalizador admite `maxChars` fuera de paso** (`StudioDraftNormalizer.php:219-226` solo mira 160–480): una versión con 330 se aprueba y, con la llave encendida, no se puede publicar (409 `prompts_compile_failed`, probado). Con la llave apagada se publica como siempre. Corregirlo toca validación y `validation-cases.json`: fuera de mi alcance.
3. **Regla de las partes que se arrastran** (decisión mía, ver §2.3): publicar en `/2` compila la parte del resto desde su versión si era `/1` y la **enseña en la vista previa** (`prompts.carried`); promover y revertir solo escriben `/2` si todas las partes ya lo eran (si no, `/1`: la canaria vuelve a Legacy). Ninguna escritura da efecto a un paquete que nadie vio. Confírmalo o cámbialo.

**Enmiendas a pruebas que NO son de la lista prohibida y he hecho (aditivas, nada relajado):** `UltronStudioEngineReadOnlyTest.php:109-119` y `:257-267` (el adaptador nuevo `StudioPromptBundle` entra en la lista y solo puede nombrar las tres clases puras del compilador, M1-6); `UltronStudioStatusCatalogTest.php:38-46` (`engine.prompts`); `UltronStudioPublicationLifecycleTest.php:528-532` y `ExportStudioFixturesCommandTest.php:117-128,152-153` (`effect`/`prompts`, 22 errores); `UltronStudioPermissionsTest.php:307-317` (el texto nuevo); `PublicationRaceSessions.php:56-57` (los hijos heredan la llave de prompts). **Ninguna guarda de la lista prohibida cae por mi cambio** (`UltronEngineIsolationTest` 11/11). **Nada que cambiar en el paquete del motor.**

**Lo que NO he verificado:** la suite completa; el CRM (build, specs, paridad con el `backend-publication.testing.ts` regenerado); la suite de carreras del H5 entera en PG (solo 2 de sus 27; la RAM); E14 (E2E con el doble de n8n); n8n; producción. Las carreras de `/2` son con 6 sesiones, no 20.

---

## 1. Qué cambió y por qué (por fichero)

### Nuevos

| Fichero | Qué es |
|---|---|
| `app/Services/UltronStudio/StudioPromptBundle.php` | Adaptador **puro** y único fichero del Studio que nombra el compilador (`UltronPromptBase`, `UltronPromptCompiler`, `UltronPromptException`; M1-6). `compile()` da lo que se firma (`governed`, `prompts`) y lo que enseña la vista previa (`changes` por parámetro con roles y el texto exacto de cada parche; `baseline`; `bytes`), nunca los prompts enteros. `offer()` es la oferta de `decide` (§B.5 pasos 1–8, mismos motivos) sobre la cabeza que verifica el Studio. `failureReason()` da el motivo cerrado del compilador para el log. |
| `tests/Unit/UltronStudio/StudioPromptDeclarationTest.php` | E11 puro (62): constantes atadas al motor; **bytes exactos de una entrada `/2` escritos a mano** desde §B.4; 15 formas que no se escriben y 5 que no se leen; los **34 casos `classify` congelados** con la llave apagada (idénticos) y encendida (solo mueven los gobernados a `prompt`); `previewHash`; el paquete (Legacy = base, G8 = `702890f7…`/113 672 B); valores que no compilan; la oferta paso a paso. |
| `tests/Feature/UltronStudio/UltronStudioPublicationPromptsTest.php` | E11/E12 por HTTP (11): llave apagada = hoy; encendida: vista previa → `/2` → lector y oferta reales; cambiar la llave entre vista previa y publicar = `preview_stale`; reconocer los gobernados con la llave encendida = `no_effect_not_acknowledged`; 409 `prompts_compile_failed` (versión y parte arrastrada) en su sitio de las puertas; la parte arrastrada se enseña y revertir la mantiene en `/2`; revertir sobre `/1` escribe `/1`; Legacy `/1`; `current` = oferta real en 6 estados; `lastTurns` solo de la canaria, ≤ 20, sin ids ni textos; 401/403; la llave se lee como en `decide`. |
| `tests/Feature/UltronStudio/UltronStudioPublicationPromptsRaceTest.php` | E13 (solo PG, 3 carreras con sesiones reales del H5): la misma publicación ×6 → una entrada `/2` y 5 `replayed`; 6 versiones con 6 paquetes distintos → la entrada firma **el paquete de la ganadora**; publicar contra Legacy → coherente y Legacy en `/1`. |
| `docs/…/informes-motores/dtos-studio-prompts.md` | Formas para `frontend`, con JSON reales. |
| `docs/…/informes-motores/propuesta-catalogo-bs.diff` | El cambio del catálogo, **sin aplicar** (decisión 1). |

### Modificados

| Fichero | Cambio |
|---|---|
| `StudioPublicationDeclaration.php` | `CONTRACT_V2`, `CONTRACTS`, `GOVERNED` (tipos), `PROMPTS`, `CLASS_PROMPT`. `classify(…, bool $promptsGoverned = false)`: con true, los gobernados que se apartan de la línea base van a la sexta lista `prompt` (solo existe con la llave). `previewHash(…, ?array $prompts = null)`: con prompts, entran la lista `prompt` y `{base, bundleHash, carried, compiler}`; sin ellos, el objeto de Q-4 de siempre. `partV2()`, `partV1()`, `isPartV2()`. `effectiveJson()`/`declaration()` con `$contract` (por defecto `/1`): en `/2` toda parte es `/2` y **Legacy en `/2` lanza**. `entry()` (lee `/1` y `/2` con su contrato); `parts()` delega. `assertPart()` valida la forma de su contrato (en `/2`, claves y tipos del lector + formatos: huella hex minúscula, compilador ≥ 1, textos cortos). La firma no cambia (la del motor, prefijo `/1`, §2.4 de B-M). |
| `StudioPublications.php` | `facts()`: lee la llave (`StudioEngineReadings::promptsKey()`), clasifica con ella, compila la versión y la parte del resto que se arrastraría (`promptFacts()`), y mete el paquete en `previewHash`. `publishRefusal()`: tras el laboratorio, `prompts_compile_failed`. `previewFrom()`: filas `prompt`, bloqueo `prompts_compile_failed`, `effect` y `prompts` al final (`promptsDto()`). Publicar: `promptedForPublish()` (`/2` con lo de la vista previa; si la parte arrastrada ya no es la que se vio → `publication_moved`). Promover/revertir: `promptedForMove()`. `insertEntry(…, $contract)`. `verify()` → `verification()` con el motivo del lector y contrato del texto = columna. `headSignatureOk()` acepta los dos contratos. `engineBlock()`: `engineMessage()` según la llave y `prompts` = `promptsTrace()` (con su propio `try`: si falla, la clave no sale). `ENGINE_MESSAGES` intacto; `ENGINE_MESSAGES_PROMPTS` nuevo (**los textos de las líneas 106–107 ya no se enseñan con la llave encendida**). |
| `StudioEngineReadings.php` | `PROMPTS_OFF`/`PROMPTS_CANARY` y `promptsKey()` (estático, sin consultas; mismo criterio que `UltronPromptOffer::key()`, probado). `GET engine/readings` no cambia. |
| `StudioCanaryMeasures.php` | `promptTurns(int $last)`: los últimos turnos `inbound_message` de la canaria, del más antiguo al más reciente, con la forma de `ultron:prompt-trace`; de cada clave solo un vocabulario cerrado (`[A-Za-z0-9_.:-]{1,64}`) o un recorte de 8 hex; nada de ids, ejecuciones ni motivos libres. |
| `StudioPublicationExport.php` | Vocabularios nuevos y `examples.{previewPrompts, enginePrompts}`; `examples.engine.prompts`; el 409 nuevo en `errors`. |
| `app/Exceptions/UltronStudioException.php` | `promptsCompileFailed()` (409, mensaje sin motivo técnico). |
| `app/Support/Access/PermissionCatalog.php:244-245` | Solo la frase de `ultron.publish` y `ultron.rollback` que afirmaba sin condición que no cambia lo que ULTRON dice ni hace. |
| CRM `…/testing/backend-publication.testing.ts` | Regenerado con `php artisan ultron-studio:export-fixtures`. **El de integraciones lo he devuelto a su estado** (ver §5). |

## 2. Contratos nuevos o modificados

### 2.1 Publicación (`ultron_studio_publications`)

- Llave en `off`: `/1`, como hoy (las partes `/2` de una cabeza se copian sin sus dos claves).
- Llave en `canary`: **publicar** escribe `/2`. **Volver a Legacy**: siempre `/1`. **Promover/revertir**: `/2` solo si todas las partes no nulas de la entrada nueva ya eran `/2`; si no, `/1`. Una entrada sin partes (revertir el único canario) es Legacy → `/1`.
- La parte nueva lleva `governed` = `compile()['governed']` y `prompts` = `{base, bundleHash, compiler}` (B-M §2.3). Firma: `UltronPublishedConfig::signatureFor()` sin cambios.

### 2.2 HTTP

- `GET versions/{uuid}/publication-preview`: `effect`, `prompts`, clase `prompt` en `params`, bloqueo `prompts_compile_failed`. Detalle y ejemplos: `dtos-studio-prompts.md` §1.
- `POST versions/{uuid}/publish`: **409 `prompts_compile_failed`** (nuevo). Con la llave encendida, `acknowledgedNoEffect` ya no lleva los gobernados (llevarlos = `no_effect_not_acknowledged`, como cualquier conjunto que no es exacto).
- `GET status`: `engine.prompts` y `engine.message` según la llave. `dtos-studio-prompts.md` §2.
- Ayuda de los permisos `ultron.publish` y `ultron.rollback` (texto).

### 2.3 Decisiones de diseño que no estaban escritas

- **Parte del resto arrastrada al publicar** (`prompts.carried`): si la cabeza la tiene en `/2`, se copia tal cual (la firmó una publicación con su vista previa); si es `/1`, se compila desde su versión (existe, íntegra y con la huella de la parte), y se **enseña** en la vista previa y entra en `previewHash`: quien publica ve qué prompts recibirá la canaria si después revierte. Si no compila → 409.
- **Promover/revertir no compilan nada**: solo mueven partes; `/2` si todas ya lo eran, si no `/1` (log `notice` `ultron_studio.publication_written_v1`, sin datos). Ejemplo cubierto: publicada con la llave apagada y revertida con la llave encendida → `/1`.
- **Orden de la puerta nueva**: tras la del laboratorio y antes de los pasos 8 y 9 (`must_match`, reconocimiento, `preview_stale`), como un hecho del contenido. La transacción lo vuelve a impedir (dos capas).
- **Cabeza movida entre las puertas y la transacción** con otra parte en el resto: 409 `publication_moved`.
- Filas `prompt` en `params`: `engineValue: null` (no hay valor del motor que comparar), `ok: true`.
- `engine.prompts` falla aparte de `engine.publication`: si no se puede leer, la clave no sale (como `publication`).

## 3. Migraciones, entorno y orden de despliegue

- **Ninguna migración mía.** Escribir `/2` en PostgreSQL exige la del especialista (`2026_11_16_100001_…`, CHECK `/1|/2`). **Si la llave se enciende sin esa migración, el INSERT de `/2` viola el CHECK y publicar da 500** (no lo he forzado; es la consecuencia directa del CHECK). El plan ya la despliega en E0 con las llaves en `off`.
- **Ninguna variable nueva** (`MARKETING_ULTRON_STUDIO_PROMPTS` es de B-M; el Studio la lee por `config('marketing.ultron.studio_prompts')`).

## 4. Para `security` / `api-security-specialist`

- `status.engine.prompts` sale para cualquiera con `ultron.view`: sin ids de conversación, mensaje, fila ni ejecución; sin textos (un `reason`/`flowRev` que no sea vocabulario cerrado sale `null`; probado con marcadores). `current.version` es un uuid de versión del Studio (ya visible en el Studio).
- La vista previa enseña los textos de los parches: son constantes del compilador (sin datos personales ni texto del personal). Ninguna entrada de texto libre del personal llega a un prompt (R15).
- Lo que se firma es exactamente lo que la vista previa ató en `previewHash` (paquete nuevo y arrastrado); probado que cambiar la llave en medio da `preview_stale`.

## 5. Hallazgos fuera de mi alcance (no los he arreglado)

1. **Fixture del CRM de integraciones desfasado del backend**: al regenerar salen `twilio_voice: connected_voice` y 4 sujetos del libro del H9 (`strategy`, `strategy_version`, `message_template`, `person_binding`) que el `backend-integrations.testing.ts` del CRM no tiene. No es mío: lo he dejado como estaba (solo regenero `backend-publication.testing.ts`). Lo regenera quien pueda absorberlo en el CRM.
2. `PermissionCatalog.php:238` (`ultron.view`): «El motor de ULTRON solo anota en cada turno la versión publicada» ya es incompleto con la llave encendida. No lo toco (encargo: solo las líneas de publicar y revertir).
3. `D::entry()` es más estricto que el lector del motor en los VALORES de `/2` (huella en minúsculas, compilador ≥ 1): una fila escrita a mano con esos valores la leería el motor y el Studio la llamaría `malformed`. Solo con filas alteradas.
4. Un `MARKETING_ULTRON_CANARY_CONVERSATION_ID` raro (p. ej. `0`) se ve «configurado» en el Studio y `out_of_scope` en `decide` (el Studio usa la lectura `governance.canaryOnly`, que solo mira null). Solo con una configuración mal escrita.
5. `maxChars` fuera de paso (decisión 2 del resumen).

## 6. Para `tester` (casos límite desde dentro)

- `publication-cases-v2.json` **no lo he escrito**: es el 5a del tester (derivado de forma independiente). Mis bytes exactos de `/2` están escritos a mano en `StudioPromptDeclarationTest::test_los_bytes_de_una_entrada_v2_son_los_del_contrato`.
- Carreras no cubiertas: la cabeza que cambia de parte del resto **entre `facts()` y la transacción** (debe dar `publication_moved`; necesita una pausa forzada del rasgo del H5); la llave que cambia entre las puertas y la transacción (la transacción usa la decisión de las puertas: misma petición, misma llave).
- Revertir la PUBLISHED con la llave encendida cuando la parte restaurada viene de una entrada `/1` → `/1`.
- Promover con la llave encendida sobre una cabeza `/1` → `/1` (la versión promovida no gobierna prompts hasta otra publicación).
- `prompts_compile_failed` con una huella vieja: la puerta va antes que `preview_stale` (cubierto).
