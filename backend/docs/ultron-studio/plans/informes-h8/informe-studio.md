# Hito 8 · S8-a (paso 1) · informe del Studio: la plantilla y el compilador puro

**Fecha:** 03-11-2026. **Árbol:** `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h8-voice`, sobre `57bab8a`. **Sin commit.** Producción y red: nada.

## 1. Qué entrego

| Fichero | Qué es |
|---|---|
| `app/Services/UltronStudio/StudioVoiceTemplate.php` | Plantilla en código (P-6): aviso P-9 provisional literal, «línea no disponible», tono/objetivo/«si no entiende» por opción CERRADA del catálogo, huecos `presentation` y `closing`, catálogo cerrado de herramientas (D8-10) con su descripción, P-3 (`PRICES_FROM_CATALOG_TOOL`), variables dinámicas con defectos fallo-cerrado, comprobación de textos y huella fijada por versión (`ultron-voice-template/1`). |
| `app/Services/UltronStudio/StudioVoiceCompiler.php` | `compile(version, profile, maxCallSeconds, purpose): {spec, specHash}` puro, sin teléfonos ni secretos; `profileHash()`; `assertDestinationsMatch(spec, ref→E.164, huella)` (R-07 al desplegar); 422 `voice_compile_refused` con `{reason, field}` (38 motivos cerrados, nunca repite el texto). |
| `tests/Unit/UltronStudio/StudioVoiceTemplateTest.php` · `StudioVoiceCompilerTest.php` · `StudioVoiceCompilerParityTest.php` | Unitarias, paridad con `tests/fixtures/voice/compiler-cases.json` (TEST8-a). |
| `tests/Feature/UltronStudio/UltronStudioVoiceCompilerTest.php` | Versiones reales aprobadas por la API (dos personas) y reglas S-NNN por la API; 0 consultas a la base al compilar; el 422 tal como lo pinta Laravel. |

La salida la lee tal cual `VoiceAgentSpec::fromCompiled()` de B8-a (misma `specHash`, mismos bytes canónicos: prueba de paridad).

## 2. Verificado (comandos ejecutados)

- `phpunit` de mis 4 ficheros: **354 pruebas, 2 326 aserciones, OK**.
- Guardas del Studio que rastrean mis ficheros: `UltronStudioEngineReadOnlyTest` (3 estáticas) OK; `UltronEngineIsolationTest` OK (8); `StudioPlainTextInvisiblesTest`, `StudioLabIsolationTest`, `StudioCatalogTest`, `StudioContentHasherTest`, `StudioLabChecksTest`, `StudioLabPersonalDataTest`, `InvisibleCharactersTest`: **202 pruebas OK**.
- Paridad con el fixture: **107 casos** (73 de compilación, 14 relaciones de huella, 9 vectores canónicos, 11 de recorte) más la de forma: OK. Cazó un fallo mío (un emoji con ZWJ salía como «teléfono»): corregido con su regresión.
- Mutaciones en copia aislada (scratchpad, `app/` de la copia antepuesto al autoload): **78 mutantes, 77 cazados, 0 supervivientes**, 1 ya no aplica (quité el código redundante que mutaba).
- Pint `--test` en los 6 ficheros: OK.

## 3. Decisiones que tiene que confirmar el lead

1. **`call_direction`** (`entrante`/`saliente`, defecto `entrante`) es una variable dinámica nueva: D8-11 y D8-12 no la listan. B8-b tiene que mandarla en la iniciación y en la saliente. B8-a ya la recoge en el DTO.
2. **Primer mensaje = solo el aviso**; el saludo de cada sentido va en el prompt, en su rama. Alternativa (el primer DTO de B8-a): aviso + `{{greeting}}` puesto por Laravel, con defecto «no disponible». Así se oyen el aviso y el saludo en el mismo turno, pero cambia el DTO y B8-b.
3. Versión aprobada = APPROVED, CANARY o PUBLISHED (como `StudioCampaignLinks::LINKABLE`). El laboratorio admite además VALIDATED y REVIEW_REQUIRED, y el perfil `submitted` (D8-23).
4. Segundo cerrojo: se niega si la versión tiene `strictFacts≠true`, `discountsAllowed≠false`, `paymentLinksEnabled≠false` o `handoffOnlyCorroborated≠true`.
5. `no_volver_a_llamar` es obligatoria (Ley 2300) y `transfer_to_number` exige `solicitar_humano` (D8-14). Buzón: solo `hang_up`; `leave_message` [P-10] necesita un texto aprobado.
6. Textos del perfil con lista de caracteres admitidos (sin llaves, sin `«»`, sin emojis), además de PlainText estricto. Las llaves permitirían inyectar `{{system__caller_id}}`; las comillas angulares, romper la cita del prompt.
7. **Aviso P-9:** el texto provisional no dice cómo pedir que no llamen más, que D8-17 sí exige. Es solo texto en la plantilla y lo aprueba el propietario.

## 4. Conflictos con el fixture de TEST8-a (fijados por nombre en la prueba de paridad)

- **Emoji válido:** el fixture lo da por bueno. Mi compilador lo niega (`text_characters`) y el DTO de B8 tampoco admite ZWJ.
- **`leave_message`:** ver la decisión 5.
- **«solo dos herramientas»:** el fixture transfiere sin `solicitar_humano`; mi compilador lo niega.
- **«una herramienta menos»:** el caso quita `no_volver_a_llamar`, que para mí es obligatoria.
- **Orden entre campos:** el fixture da el primer motivo de su orden; el mío, el primer campo.
- **uuids en `specHash`:** DF-C7 los excluye; el DTO de B8 los incluye en `source`. Recomiendo mantenerlos (procedencia por artefacto).
- **No aplican:** `handoffAliases` (ningún parámetro del catálogo nombra destinos: R-07 se aplica al desplegar) y la versión del compilador como entrada (es una constante).

## 5. Para S8-b, B8 y TEST8

- **S8-b:** el perfil guarda `{alias, phoneHash}` (huella `voice-phone`) y el número cifrado aparte. Antes del mapper hay que llamar a `assertDestinationsMatch`.
- **B8-b:** la guarda posterior tiene `forbiddenPhrases` y `prices` en la especificación.
- **TEST8:** cerrar los conflictos de §4.
