# Informe del backend · cuatro ojos por regla (B-2 de seguridad), 22-11-2026

**Base:** rama `cierre/gobierno` sobre `dfcee951`. Diseño: opción A del plan (`plans/cierre/plan-quejas-consentimiento-cuatro-ojos.md` §3), sin rediseñar nada. Sin red y sin producción.
**Marcas:** **[C]** comprobado ejecutando · **[L]** leído en el código · **[H]** hipótesis.

## 0. En corto

1. **B-2 queda cerrado en el servidor** [C]. Aprobar, publicar y promover (WhatsApp y voz) se niegan a quien escribió **cualquier revisión ≤ la copiada** de una S-NNN que aporta frases a la versión. Vale aunque la versión la creara otra persona y vale para el Super Admin. La autoría sale del **libro**, nunca de `updated_by`. Si a una revisión le falta su línea o su actor, se niega a **todos**. Rechazar sí se puede (D15).
2. **Es aditivo.** Mismo 403 `four_eyes_required` en el mismo paso, ahora con `detail: rule_phrase_author`, y el mismo evento `*.four_eyes_denied`, ahora con `diff.rulePhraseAuthor`.
3. **`requirements` NO entra en `previewHash`** [L+C]. Para una versión sin frases, la huella **no cambia**: lo he medido (§2).
4. **Versión sin frases de reglas: nada cambia, byte a byte** [C], y no se consulta el libro. La versión aprobada que espera en producción sigue igual: su vista previa ya abierta no queda `preview_stale`.
5. **Un fichero fuera de la lista:** `StudioPublishing.php`, con un parámetro opcional en `actions()`. Sin él, el detalle enseñaría «Publicar» activo a quien el servidor se lo niega (§1).

## 1. Qué cambió

- **`app/Services/UltronStudio/StudioRulePhraseAuthors.php` (nuevo).**
  - `of()` (`:67`) devuelve `null` si la versión no lleva frases de reglas (no hay consulta), `[]` si quien mira no escribió ninguna, o la lista `{code, revision, authored, unverified}`.
  - `copiedRevisions()` (`:198`) aplica el mismo filtro que `StudioRulePhrases::byRule` y toma la revisión copiada. Una revisión que no es un entero ≥ 1 da `null` y la regla sale `unverified`.
  - `revisionLines()` (`:232`) hace **una** consulta: el libro unido con las reglas por `uuid`, `subject_type = rule`, `rule.created` (la revisión 1) y `rule.updated` (`diff.toRevision`).
  - En `of()`, `count($conLinea) < $copiada` es lo que falla cerrado. Lo completan `requirement()` (`:112`), `refusal()` (`:125`), `auditDiff()` (`:140`) y `message()` (`:157`).
- **`StudioVersions.php`:** `actions()` (`:492`), donde `approve` lleva `detail` y `reject` no cambia; `phraseAuthorsFor()` (`:517`), que calcula la autoría una vez por detalle; y `assertFourEyes()` (`:536`): primero quien creó la versión (como antes); al rechazar, sale ahí; al aprobar, `of()`.
- **`StudioPublications.php`:** `publishGated` reutiliza la autoría que devuelve `assertFourEyes()` (`:2056`, `:2070`), así que hay una lectura por petición; `preview()` (`:598`); en `facts()`, `fourEyes`, `fourEyesDetail` y `rulePhraseAuthor` (`:1337-1339`); y en `previewFrom()`, `requirements.fourEyes` con su motivo (`:956`) y `requirements.rulePhraseAuthor` al final (`:973`), solo si la versión lleva frases.
- **`StudioPublishing.php:272, :284, :293`:** `?Closure $phraseAuthors = null`. Sin él, la respuesta es la de siempre.
- **`StudioVoicePublishing.php:1084, :1096`:** lo mismo para la voz, en publicar y en promover.
- **Contrato** `ULTRON_STUDIO_H5_CONTRACT.md:164` (la enmienda marcada). **Fixture**: tres casos y el README. **Arnés**: `UltronStudioPublicationLifecycleTest.php:846`.

## 2. `previewHash` y «sin frases, nada cambia»

- **La fórmula** (Q-4, `StudioPublicationDeclaration.php:406`, llamada en `StudioPublications.php:1320`) es `{versionUuid, contentHash, catalogVersion, mustMatch, matches, noEffect, labGate, prompts?}`. Ni `requirements` ni nada de quien mira entra en ella.
  - Lo fija una prueba: con frases, la huella de su autor es la misma que la de otra persona.
- **La comparación byte a byte** [C]. Volqué 36 respuestas con uuids deterministas y reloj fijo, antes y después del cambio:
  - reglas creadas;
  - el detalle de 3 personas en 3 estados;
  - aprobar 403 y 200, y rechazar 403 y 200;
  - la vista previa con la llave de prompts en `off` y en `canary` para 3 personas (`previewHash 4ce3e385…`);
  - publicar 403 y 201 y promover 403, cada uno con su evento del libro;
  - la voz: publicar 403 y 201 y promover 403.

  La versión copiaba dos S-NNN de quien aprueba: una sin frases y otra con frases cuyo «si» no se cumple. Los sha256 coinciden: WhatsApp `aca7629b…`, voz `f64ddb64…`. La prueba de apoyo no está en el repositorio; está en el scratchpad (`ZzB2BaselineDumpTest.php`) y se corre con `B2_DUMP=<dir>`.
- **Regresión confirmada:** `test_el_autor_de_reglas_sin_frases_o_cuyo_si_no_se_cumple_…` comprueba exactamente las 10 claves de siempre, `fourEyes = {ok: true}` y **0** lecturas del libro.
- **El export del CRM** (`ultron-studio:export-fixtures`), antes y después: idéntico.

## 3. Casos y su prueba (`tests/Feature/UltronStudio/UltronStudioRulePhraseAuthorsTest.php`, 12 métodos)

| Caso | Prueba |
|---|---|
| El autor de la revisión 1 queda bloqueado aunque la 2 sea de otra cuenta (y la de la 2 también); una tercera aprueba | `test_el_autor_de_la_revision_1_no_aprueba_aunque_la_revision_2_sea_de_otra_cuenta` |
| Una revisión intermedia cuenta; el mensaje nombra «revisiones 1 y 3» | `test_una_revision_intermedia_cuenta_y_…` |
| Quien edita la regla **después** de copiarla no es autor de lo copiado | `test_quien_edita_la_regla_despues_de_copiarla_…` |
| El autor de una regla sin frases (o con un «si» que no se cumple) no queda bloqueado y no se lee el libro | `test_el_autor_de_reglas_sin_frases_…` |
| Una regla archivada no cuenta (ni por la API ni con una copia `archived` en el contenido) | `test_una_regla_archivada_no_cuenta` |
| Una revisión sin línea del libro (SQL) o con una línea sin actor → se niega a todos, también publicar | `test_una_revision_sin_su_linea_del_libro_o_sin_actor_…` |
| Rechazar, permitido al autor | `test_quien_escribio_las_frases_si_puede_rechazar` |
| Dos cuentas que funcionan: quien escribe las frases crea la versión y la otra aprueba y publica | `test_dos_cuentas_que_funcionan_…` |
| Dos cuentas que se bloquean (las dos escribieron frases); el Super Admin tampoco pasa; la salida es rechazar | `test_dos_cuentas_que_se_bloquean_…` |
| Publicar y promover: vista previa, acciones, 403, evento, la misma `previewHash`, una lectura al publicar; revertir sí | `test_publicar_y_promover_se_niegan_al_autor_de_frases_y_revertir_no` |
| Una lectura por versión con 3 reglas y 6 revisiones; la creadora, 0 | `test_una_sola_lectura_del_libro_por_version_…` |
| La voz igual que WhatsApp (nada se despliega; `voice_publication.four_eyes_denied` con `rulePhraseAuthor`) | `test_la_voz_igual_que_whatsapp` |
| Fixture: publicar (antes que el estado, y con todo lo demás abierto) y promover (antes que `scope_not_allowed`) → 403 con `detail` | `publication-cases.json` (+3, hecho `actor.isRulePhraseAuthor`), por HTTP en `UltronStudioPublicationLifecycleTest` |

**El fixture y el CRM.** El CRM **no** tiene copia de `publication-cases.json`. Lo que sí tiene es `testing/backend-publication.testing.ts`, generado por `ultron-studio:export-fixtures`, y con B-2 no cambia. **No hay nada que regenerar.** Recuentos que cambian a propósito: 71 → 74 puertas, 231 → 234 casos y 45/13 → 47/14. Los tres casos los escribí yo desde la enmienda, así que la segunda derivación del tester no los cubre (así consta en el README).

## 4. Salidas finales

- **SQLite, fichero a fichero** [C]: las 46 baterías relacionadas (versiones, `Publication*`, `Voice*`, permisos, reglas, auditoría, paridades del fixture y de la voz, `UltronPublishedConfigTest` y `ExportStudioFixturesCommandTest`), **todas OK**. Lo que sale *skipped* es solo de PG (carreras).
- **Nuevas:** `OK (12 tests, 218 assertions)`. **Ciclo de vida:** `OK (77 tests, 758 assertions)`. **Paridad de puertas:** `OK (75 tests, 199 assertions)`. **Paridad:** `OK (106 tests, 675 assertions)`.
- **PostgreSQL 14** (`ultron_studio_cierre_gobierno`, con `subject_uuid uuid` y `diff json`) [C], todo OK: nuevas 12/218 · ciclo de vida 77/758 · carreras de publicar 38/2763 · V2Race 2 · PromptsRace 3 · RulePhrases 12 · VersionLifecycle 22 · VoicePublishing 16 · VoiceRace 1 · Rollback 23 · Audit 9.

- **El plan en PG14**, con 40 reglas y unas 50 000 líneas del libro (en una transacción deshecha): `usae_subject_idx` y `usae_event_idx` combinados, 120 filas leídas y **0,99 ms**.
- **Guardas estáticas:** `OK (141 tests, 183008 assertions)`. **Pint:** `passed`.
- **Contra el código de antes** (los cuatro servicios de `HEAD` con la clase nueva): caen **9 de 12**. Las 3 que pasan son las que afirman que *nada* se bloquea (sin frases, archivada y rechazar).

## 5. Las mutaciones (todas mueren) [C]

| Mutación en `StudioRulePhraseAuthors` | Muere en |
|---|---|
| M1a · quitar el `≤`: solo cuenta la revisión copiada (`!==`) | 4 pruebas (revisión 1, intermedia, sin línea, una lectura) |
| M1b · quitar la cota: cuentan también las revisiones posteriores | 1 prueba (`quien_edita_la_regla_despues_de_copiarla`) |
| M2 · «el último editor» (`updated_by ?? created_by`) en vez del libro | 6 pruebas (revisión 1, intermedia, después, sin línea, publicar, una lectura) |

Restaurado con comprobación por sha256 y vuelto a verde después de cada mutación.

## 6. Lo que security (B2-5) debe mirar primero

1. **Es por cuenta, no por persona** (auditoría A2), igual que antes: una persona con dos cuentas se lo salta.
2. **Exposición:** el requisito y el 403 solo dicen las revisiones que escribió **quien mira** y `unverified`, siempre sobre reglas que la versión ya enseña. No dicen quién escribió las demás. El libro de reglas ya lo ve `ultron.view`.
3. **Fallo cerrado:** falta la línea, la línea no tiene actor, la revisión no es entera o el código no tiene fila. Se puede reproducir con SQL (la tabla de reglas no tiene disparador de `UPDATE`).
4. **Orden:** primero quien creó la versión (respuesta idéntica a la de hoy) y después la autoría de las frases. Va **antes** que el estado, la integridad y la ventana, como el paso 4.
5. **Sigue abierto:**
   - B-1 (el resto `/1` arrastrado con frases): la condición E-3 sigue vigente.
   - El **veredicto del laboratorio** (`StudioLabVerdicts`, `StudioVoiceLabVerdicts`) solo excluye a quien creó la versión. Quien escribió las frases puede emitirlo; aprobar y publicar siguen necesitando a otra persona.

## 7. Para el CRM (B2-3; no he tocado su repositorio)

- **403** (aprobar; publicar y promover en WhatsApp y en voz): `{ok: false, code: "four_eyes_required", message, detail: "rule_phrase_author", rulePhraseAuthor: [{code: "S-003", revision: 2, authored: [2], unverified: false}]}`.
  - `revision` es la copiada; `authored`, las tuyas ≤ esa.
  - Texto: «Escribiste S-003, revisión 2: tiene que aprobarla otra persona.» (con «publicarla» o «promoverla» según la acción).
  - Con `unverified`: «No consta en el libro quién escribió todas las revisiones de S-003: no se aprueba una versión que la copie.»
- **Vista previa:** `requirements.fourEyes = {ok: false, message, detail: "rule_phrase_author"}` y `requirements.rulePhraseAuthor = {ok, message?, rules: [...]}`, al final y **solo** si la versión lleva frases de reglas. `blocking` no cambia: `four_eyes_required` una vez, en su sitio.
- **`actions`:** `approve = {allowed: false, code: "four_eyes", message, detail}`; `publish` y `promote` = `{allowed: false, code: "four_eyes_required", message, detail}`; `reject` igual que hoy.
- **Hoy ya se ve bien** [L]: `requirementRows` pinta primero el `message` del servidor, y `leerRequisitos` y `leerAccion` ignoran las claves que no conocen. B2-3 solo añade pintar la lista.

## 8. Qué NO he podido verificar

- **El CRM pintando el motivo:** no es mi repositorio.
- **Los datos reales de producción:** el plan dice 0 S-NNN; no he entrado.
- **El plan de PG con estadísticas de producción:** solo con volumen sintético.
- **La batería completa:** la corre el lead al integrar.
- **La vista previa de compilar la voz:** no dice nada de los cuatro ojos (tampoco del creador). El CRM se entera por el 403.

## 9. Hallazgos fuera de alcance

- **El `backend-publication.testing.ts` del CRM va atrasado respecto al backend:** compilador 2 frente a 4, y le falta la frase de la longitud medida. Árbol `_worktrees/ultron-h8/Iron-Body_Front` (rama `feat/ultron-studio-h9`). Ya pasaba antes de este cambio.
- **El veredicto del laboratorio por autoría de frases** (§6.5): es una decisión de gobierno.
- **Ediciones por SQL que no suben la revisión:** el libro no las detecta. Se detectarían comparando el `content_hash` de la regla con el `contentHash` de su última línea.

**Commits:** `b50a3321` feat (código y pruebas) · `3b762389` test (fixture y arnés) · `a26d4a7d` docs (enmienda) · este informe.
