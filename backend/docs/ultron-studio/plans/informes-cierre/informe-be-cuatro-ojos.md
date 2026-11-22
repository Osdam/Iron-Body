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

## 10. Ronda 2 (tras la revisión de API)

**Alcance:** M-1 y M-2 de `informe-apisec-cuatro-ojos.md`, sin rediseñar nada. M-3 (el veredicto del laboratorio) es una decisión de gobierno y C-1 (producción) es del lead: no los he tocado. Sin red y sin producción.

### 10.1 M-1 · El libro no atribuye una autoría que no consta [C]

- **Causa raíz.** `auditDiff()` metía en `rulePhraseAuthor` **todos** los códigos que daba `of()`, también los que solo estaban `unverified`. Además, los tres resúmenes decían «que escribió» fijo. Con una revisión sin línea, Carla, que no tocó nada, quedaba en una línea inmutable como autora de S-001.
- **Cambio** (`app/Services/UltronStudio/`):
  - `StudioRulePhraseAuthors.php:145` · `auditDiff()`: `rulePhraseAuthor` lleva **solo** las reglas con `authored ≠ []`, y puede quedar `[]`. `rulePhraseUnverified` no cambia.
  - `StudioRulePhraseAuthors.php:161` · `auditReason()`: el final del resumen, según el caso. Apoyo: `codesWhere()` (`:219`).
  - Las tres llamadas: `StudioVersions.php:558` (aprobar), `StudioPublications.php:2076` (publicar y promover) y `StudioVoicePublishing.php:1101` (la voz).
- **La línea `*.four_eyes_denied`, por caso:**

  | Caso | El resumen termina en | `diff.rulePhraseAuthor` | `diff.rulePhraseUnverified` |
  |---|---|---|---|
  | Escribió alguna revisión copiada | «…que escribió» (byte a byte, como antes) | sus códigos | no aparece |
  | Solo falta rastro | «…sin autoría probada en el libro» | `[]` | los códigos sin rastro |
  | Las dos cosas | «…que escribió y de reglas sin autoría probada en el libro» | sus códigos | los códigos sin rastro |

- **El 403 y la vista previa no cambian** [C]. Ya distinguían los dos casos, entrada por entrada (`authored: []` y `unverified: true`) y en el mensaje («No consta en el libro quién escribió…» frente a «Escribiste…»). `detail` y el código HTTP siguen iguales. Lo que faltaba era fijarlo para quien no escribió nada, y ahora una prueba lo hace en cada puerta:
  - aprobar;
  - publicar: `requirements.fourEyes`, `requirements.rulePhraseAuthor`, `actions.publish` y el 403;
  - promover: `actions.promote` y el 403;
  - en la voz, publicar y promover.
- **Pruebas** (`tests/Feature/UltronStudio/UltronStudioRulePhraseAuthorsTest.php`):
  - `:180` `test_una_revision_sin_su_linea_del_libro_o_sin_actor_se_niega_a_todos` (**ajustada**: era la que fijaba la lista mezclada). Comprueba la fila de Carla (aprobar, solo sin rastro), la de Ana (aprobar, los dos casos) y la de Pablo (publicar, solo sin rastro): actor, `diff` entero y resumen. Para Pablo, además, la vista previa, `actions.publish` y el 403.
  - `:247` `test_sin_autoria_probada_promover_se_niega_y_el_libro_no_nombra_autor_a_quien_lo_intenta` (**nueva**). La versión ya está en canario cuando se escribe una línea sin persona para la revisión copiada (`lineaSinPersona()`, `:555`). Pablo no consta como autor. Root sí, de su revisión, y aparte figura lo que no consta.
  - `:485` `test_la_voz_sin_autoria_probada_no_nombra_autor_a_quien_publica_ni_a_quien_promueve` (**nueva**). Usa `voice_publication.four_eyes_denied` y comprueba que no se despliega nada.
- **Contra el código de antes** [C]. Con los cinco servicios de `a843aeff` cargados delante del autoload, caen **3 de 14**: las tres de arriba, en `diff.rulePhraseAuthor`. Con la mutación «`auditReason()` siempre "que escribió"» caen **las mismas 3**, en el resumen.

### 10.2 M-2 · La lectura no depende del tamaño del `diff` [C]

- **Causa raíz.** `revisionLines()` traía `e.diff` entero de cada línea `rule.created`/`rule.updated` de las reglas copiadas, también de las posteriores a la copia, solo para leer `toRevision`.
- **Cambio:** `StudioRulePhraseAuthors.php:277` · `revisionLines()`.
  - Trae solo `r.code`, `e.event`, `e.actor_admin_id` y `toRevision` como **fragmento JSON** (`:281-292`), que se decodifica en PHP (`:300`).
  - En PostgreSQL es `"e"."diff" -> 'toRevision'`. En SQLite, `case when json_valid("e"."diff") then "e"."diff" -> 'toRevision' end`: la columna es texto, y un `diff` que no es JSON (solo se llega por SQL) haría fallar la consulta.
  - «Entero o sin rastro» no cambia. `2` es la revisión 2. `"2"`, `2.0`, `true`, `null`, un array, la clave ausente o un `diff` ilegible no cuentan.
  - Sigue siendo **una** consulta con el mismo `from`/`join`/`where`, así que el plan de §4 (`usae_subject_idx`) no debería cambiar [L; no he repetido el EXPLAIN].
  - **Requisito:** `->` en SQLite necesita la versión 3.38 o posterior. La local es 3.54.1. Producción es PG.
- **Medición** con la sonda R6 del revisor: N revisiones **posteriores** a la copiada, cada `diff` de 4,3 KB (3×10×60) y una sola llamada a `of()`. El pico es el de Zend, con `memory_reset_peak_usage()`.

  | | Antes (`a843aeff`) | Después |
  |---|---|---|
  | SQLite · 5 000 | 59 ms · 42,8 MiB | **16 ms · 3,9 MiB** |
  | SQLite · 20 000 | 229 ms · 171 MiB | **106 ms · 15,6 MiB** |
  | PG14 · 5 000 | 77 ms · 42,8 MiB | **66 ms · 3,9 MiB** |
  | PG14 · 20 000 | 320 ms · 171 MiB | **250 ms · 15,6 MiB** |

  La consulta es 1 antes y después. El valor más largo que trae pasa de 4 282 B a 12 B. La sonda del revisor daba +36/+40 MiB y 388 ms a 20 000, medidos con la memoria «real» y sin reiniciar el pico. Mi «antes» es del mismo orden; atribuyo la diferencia al método y a la carga de la máquina [H].
- **Lo que queda, medido** [C]:
  1. En PostgreSQL el tiempo **sigue** creciendo con el tamaño del `diff`, porque el servidor tiene que leer el `json` de cada línea para sacar `toRevision`: con 20 000 líneas, 25 ms con `diff` de 54 B frente a 250 ms con `diff` de 4,3 KB. Ya no cuesta memoria de PHP ni red, así que el riesgo de M-2 (un 500 por `memory_limit`) desaparece. Lo que queda es CPU del servidor.
  2. Memoria y tiempo siguen creciendo con el **número** de revisiones, por diseño: B-2 necesita todas las ≤ la copiada.
  3. Acotar en SQL a `toRevision ≤ copiada` no lo arreglaría. También tendría que leer el `json` de cada línea para comparar, y en el escenario del ataque cada versión nueva copia la revisión vigente, así que todas las líneas quedan ≤ la copiada.
  4. Para que no dependa de nada de eso, la revisión tendría que leerse sin abrir el `diff`: una columna propia en el libro o un índice de expresión. Es una decisión de esquema (**database-specialist**) y no la he tomado.
- **Prueba nueva:** `:417` `test_la_lectura_del_libro_no_trae_el_diff_ni_depende_de_su_tamano`, con `medirLectura()` en `:575`.
  - Escenario: Ana escribe S-001, Beto la edita y la versión copia la revisión 2. Se mide la lectura así. Después se meten por SQL 16 líneas de Beto con un `diff` de 512 KiB (8 MiB en total): una para la revisión 2 y quince posteriores. Se vuelve a medir.
  - Comprueba el mismo resultado, la misma consulta (1 y 1), que ningún valor leído pasa de 64 B (se repite la consulta capturada con sus valores) y que el pico está por debajo de 1 MiB.
  - Ahora el pico es de 22,8 KB (SQLite; en PG pasa la misma cota). Con el código de antes cae en SQLite y en PG: trae 323 B del `diff` normal. En una copia sin la aserción de los bytes, la de memoria también lo detecta sola: 9,5 MB de pico (SQLite).

### 10.3 Lo que no puede romperse [C]

- **Sin frases, nada cambia.** `test_el_autor_de_reglas_sin_frases_o_cuyo_si_no_se_cumple_…` (`:145`) da OK: las 10 claves de siempre, `fourEyes = {ok: true}` y 0 lecturas del libro. Además corrí los volcados byte a byte del autor (§2, 2 escenarios) y del revisor (5 escenarios), con los servicios de `a843aeff` y con los de ahora dos veces. Los **7/7 sha256 son idénticos**. WhatsApp `aca7629b…` y voz `f64ddb64…` son los mismos de §2, y `previewHash` sale igual: `4ce3e385…` y `dc246bbb…`.
- **Una lectura por versión:**
  - `test_una_sola_lectura_del_libro_por_version_…` (`:379`): 1 en el detalle, 1 en la vista previa y 0 para quien la creó;
  - `test_publicar_y_promover_…` (`:332`): 1 al publicar;
  - la nueva de M-2: 1 con `diff` normales y 1 con `diff` enormes.

### 10.4 Lo que cambia para el CRM

- **403 y vista previa: ningún campo cambia.** Al pintar la lista en B2-3 hay que tener presente que `rulePhraseAuthor`, tanto en el 403 como en `requirements.rulePhraseAuthor.rules`, lleva **también** las reglas que solo están `unverified`, con `authored: []`. Esa entrada no dice que quien mira la escribiera.
  - «Escribiste…» va solo con `authored ≠ []`.
  - «No consta…» va con `unverified: true`.
  - O se pinta el `message` del servidor, que ya distingue los dos casos.
- **El libro** (`GET audit`, eventos `*.four_eyes_denied`): `diff.rulePhraseAuthor` ahora lleva solo autores reales y puede ser `[]`; `diff.rulePhraseUnverified` sigue igual. El resumen cambia solo en los casos sin rastro. El CRM no lee hoy ninguno de los dos: `grep` en `Iron-Body_Front/src` da 0 [C].
- **Contrato:** `ULTRON_STUDIO_H5_CONTRACT.md:164` dice «`diff.rulePhraseAuthor: [códigos]`». Sigue siendo cierto, pero conviene precisar: «los que escribió quien lo intentó; los que no constan, en `diff.rulePhraseUnverified`». No lo he tocado porque está fuera de los ficheros de esta ronda: queda para el lead.

### 10.5 Salidas finales [C]

- **SQLite**, fichero a fichero:
  - autoría: `OK (15 tests, 299 assertions)`;
  - ciclo de vida: `OK (77 tests, 758 assertions)`;
  - paridad de puertas: `OK (75 tests, 199 assertions)`;
  - paridad: `OK (106 tests, 675 assertions)`;
  - paridad V2: `OK (94 tests, 457 assertions)`;
  - paridad del compilador de voz: `OK (119 tests, 1502 assertions)`;
  - paridad de publicaciones de voz: `OK (37 tests, 123 assertions)`.
- **PostgreSQL 14** (`ultron_studio_cierre_gobierno`):
  - autoría: `OK (15 tests, 299 assertions)`;
  - carreras de publicar: `OK (38 tests, 2762 assertions)` y, repetida, `2763`. El recuento varía de una corrida a otra con el entrelazado; la ronda 1 dio 2763;
  - V2Race: `OK (2 tests, 75 assertions)`;
  - PromptsRace: `OK (3 tests, 184 assertions)`;
  - VoiceRace: `OK (1 test, 27 assertions)`.
- **Guardas estáticas:** `OK (141 tests, 183008 assertions)`. **Pint:** `passed`.

**Commits de la ronda 2:** `df5805e9` fix (M-1) · `cf84e203` perf (M-2) · esta sección.
