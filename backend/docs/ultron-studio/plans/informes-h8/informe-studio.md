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
7. **Aviso P-9:** el texto provisional no dice cómo pedir que no llamen más, que D8-17 sí exige. Es solo texto en la plantilla y lo aprueba el propietario. *(Resuelto por E1-3: ver §6.)*

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

## 6. Enmienda 1 (03-11-2026): el aviso de E1-3

Las decisiones de §3 y §4 quedan aceptadas en E1-5 a E1-9. Esta ronda solo aplica **E1-3**. Sin commit.

**Qué cambia:**
- `StudioVoiceTemplate::AI_NOTICE` pasa al texto de E1-3, que sigue siendo provisional hasta que lo apruebe el propietario antes de la fase 3. Dice quién atiende, la finalidad y cómo pedir que no llamen más.
- La prueba lo lee de la fila E1-3 del contrato y fija esas tres partes. También comprueba que el texto de P-9 ya no es el aviso.
- La plantilla sigue en `ultron-voice-template/1`. Su huella se vuelve a fijar: `276cb258…` → `6914e2e1…`.
  - Por qué no subo de versión: ningún agente se ha compilado con la v1 fuera de las pruebas, y el fixture fija `ultron-voice-compiler/1`.
  - Si el lead prefiere versionado estricto: subir a `/2` la plantilla y el compilador, y TEST8-a actualiza el fixture.

**Paridad:**
- `compiler-cases.json` aún trae el aviso de P-9 (TEST8-a no ha reexportado según E1-9).
- La prueba traduce el aviso aprobado del fixture al de la plantilla, en el perfil y en las invariantes del primer mensaje. Solo lo hace si es exactamente el texto de P-9 que se lee del contrato, y lo fija.
- Cualquier otro texto llega tal cual: vacío, ausente o con una palabra cambiada siguen dando `notice_missing`.
- Cuando el fixture traiga E1-3, la traducción es la identidad.

**Verificado:**
- Mis cuatro ficheros: **354 pruebas y 2 333 aserciones, OK**. Por fichero: plantilla 91, compilador 151, paridad 108 y Feature 4.
- Guardas del Studio: 3 + 8 + 202 OK.
- Mutaciones en copia aislada: **79, cazadas 78, sobreviven 0**. La que no aplica es el código redundante que se quitó. Dos de las cazadas son nuevas y estropean el aviso de E1-3.
- Pint: OK.

**Pendiente de otros paquetes:**
- **TEST8-a (E1-9).** Al reexportar, los 6 casos de `StudioVoiceCompilerParityTest::CONFLICTS` fallarán a propósito. Hay que vaciar o reclasificar la lista.
- **TEST8-a y OPS8.** El texto viejo del aviso sigue en:
  - `tests/fixtures/voice/elevenlabs-webhook-bodies.json`;
  - `tests/fixtures/voice/elevenlabs-webhook-vectors.json`;
  - `tests/fixtures/voice/tools/build/sec_eleven_bodies.py`;
  - `docs/ultron-studio/ops/banco-h8/sim-elevenlabs.cjs`.

  Importará cuando B8-b compruebe el aviso en el primer turno (D8-10, capa 4).
