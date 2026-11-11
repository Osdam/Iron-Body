# Informe de backend · Alerta M1-A (INC-2) y texto de fidelidad del laboratorio (INC-1, T6)

**Fecha:** 11-11-2026. **Autor:** `backend`. **Árbol:** worktree `ultron-h8`, rama `feat/ultron-studio-h9`, HEAD `1d81ae57` (producción ejecuta `2687119`, el mismo código). **Sin commit, sin push, sin tocar producción, el CRM ni los contratos.** Rutas relativas a `backend/`.

## Resumen para el lead

1. **M1-A hecha:** el monitor del H7 gana una cuarta comprobación, `prompts`, que abre o suma al incidente `ultron_studio · prompts_drift` (alto) cuando un turno de la canaria sale `mismatch` o `legacy_drift`. Va dentro de `ultron-studio:monitor`: **ninguna tarea nueva** en el programador.
2. **Ventana:** la media hora anterior, alineada con la cadencia del monitor (`*/30`) y cortada 2 min antes (a las 10:00:04 mira de 09:28 a 09:58). Las pasadas programadas no se solapan ni dejan huecos, y no hace falta guardar ningún cursor.
3. **Deduplicación:** la de IRON GUARD, con la huella = los estados (igual que `publication`). La misma deriva en pasadas seguidas es un solo incidente que suma ocurrencias.
4. **El incidente solo lleva** estados, recuentos, la ventana, la versión ofrecida recortada a 8 y las huellas ofrecida y observada recortadas a 8. No lleva ids de conversación, de filas ni de ejecuciones, teléfonos ni textos.
5. **Llave de prompts en `off`:** no lee nada y dice `not_watched · prompts_off`. Sin canario configurado dice `no_canary`.
6. **No frena nada:** el plan (M1-A de `informe-api-security-ola.md` y M1-13 del H5) solo pide la alerta, y la vía de forzar la salida curada la descarta («hoy no la recomiendo»). Por eso no hubo nada que consultarte antes de implementar.
7. **T6 hecho:** `StudioLabRuns::FIDELITY` es tu literal, byte a byte (sha256 `d8949aa9…`, 354 caracteres). Se calcula al leer, no se guarda, no entra en `resultsHash` y no lo limita ningún validador.
8. **Ninguna guarda del backend fijaba el texto antiguo.** `UltronStudioEngineReadOnlyTest:36` es un comentario sobre otra cosa. Añadí una prueba que fija el literal nuevo.
9. **Pruebas:** batería nueva 10/10. Guardas estáticas: 15 ficheros, 138/138. Monitor y caos: 23/23. Laboratorio: 40/40. Publicación de prompts, canario y traza: 28/28. **7 mutaciones, las 7 muertas**, con el código restaurado y la huella comprobada. **En PostgreSQL 14** (§7): batería nueva 10/10, `UltronStudioMonitorTest` 12/12 y `UltronStudioMonitorDriftAndFailuresH7Test` 4/4, sin ningún fallo solo de PostgreSQL.
10. **Decidido por el lead (§6.1): se queda así.** con la llave en `off`, un `legacy_drift` no alerta, aunque n8n S1 negocia igual y el sello se escribe. Lo hice como pediste en (e). Además, hay que actualizar dos documentos que dicen «no hay alerta automática» (§5).

---

## 1. Qué cambió, por fichero

### `app/Console/Commands/UltronStudio/MonitorCommand.php`

| Línea | Cambio |
|---|---|
| 22-65 (docblock) | La cuarta comprobación, el nuevo `kind` y que un `mismatch` tampoco frena (E15). |
| 68 | `CHECKS = ['publication', 'integrations', 'queues', 'prompts']`. Va al final, para no cambiar el orden de las otras tres. |
| 76 | `KIND_PROMPTS = 'prompts_drift'`, en la misma familia que `publication_drift` e `integrations_drift`. |
| 86 | `PROMPTS_WINDOW_MINUTES = 30`: la cadencia del programador. Una prueba ata las dos. |
| 96 | `PROMPTS_GRACE_MINUTES = 2`: la gracia para los turnos fechados justo antes del corte y confirmados después (§2.2). |
| 99, 101 | Mando y runbook del incidente: `php artisan ultron:prompt-trace --last=20` y `RUNBOOK_M1.md` §5 «Qué vigilar». |
| 110, 113 | Firma (`--check=…` admite `prompts`; por defecto, las cuatro) y descripción. |
| 134 | `'prompts' => $this->prompts()` en el `match` de `handle()`. `handle()` no cambia nada más: en seco, `--json`, la captura de excepciones y el registro son los de siempre. |
| 315-346 | `prompts()`: lee la llave, calcula la ventana, llama a `StudioCanaryMeasures::promptAlerts()` y convierte cada estado con al menos un turno en un problema. |
| 361-368 | `promptsWindow(CarbonInterface $now)`, pública y pura. |
| 385-389 | `$comando` pasa a ser un `match`; `prompts` usa la traza. |
| 403-427 | La rama `prompts` de `incidentFor()`: fuente, `kind`, huella, título, severidad y evidencia (§2.4). |
| 462-466 | `details()`: la línea de texto de `prompts`. |

### `app/Services/UltronStudio/StudioCanaryMeasures.php`

| Línea | Cambio |
|---|---|
| 47-51 (docblock) | Menciona `promptTurns()` y `promptAlerts()`. |
| 80 | `PROMPT_ALERT_STATES = ['legacy_drift', 'mismatch']`. Son `UltronPromptStamp::LEGACY_DRIFT` y `MISMATCH`, atados por una prueba: el Studio no puede importar el sello del motor y esta clase solo nombra lo que ya nombraba. |
| 83, 86 | `PROMPT_ALERT_SAMPLE = 20` (cuántos turnos de alerta se leen para sacar las huellas) y `PROMPT_ALERT_FINGERPRINTS = 5` (cuántas combinaciones distintas se devuelven como mucho). |
| 174-257 | `promptAlerts(CarbonInterface $from, CarbonInterface $to): ?array` (§2.3). |

No escribe nada, no usa fachadas nuevas (la única sigue siendo `Artisan`, la del acta) y no importa clases nuevas. Las aserciones de `UltronStudioEngineReadOnlyTest` sobre esta clase pasan sin tocarlas.

### `app/Services/UltronStudio/StudioLabRuns.php`

| Línea | Cambio |
|---|---|
| 54-60 | `FIDELITY` = el literal decidido por el lead, con un docblock: se calcula al leer, es literal del H4 §A7 y está enmendado tras M-1 (INC-1, T6). |

### Pruebas

| Fichero | Cambio |
|---|---|
| `tests/Feature/UltronStudio/UltronStudioMonitorPromptsTest.php` | **Nuevo**, 10 pruebas (§3). |
| `tests/Feature/UltronStudio/UltronStudioMonitorTest.php:157,160` | La lista de comprobaciones por defecto incluye `prompts`. Se comprueba además que, con la llave de la suite (`off`), dice `not_watched · prompts_off`. **Es un cambio de contrato, no una relajación:** la prueba sigue exigiendo la lista exacta. |
| `tests/Feature/UltronStudio/UltronStudioMonitorDriftAndFailuresH7Test.php:190,195` | Lo mismo: «las cuatro se comprobaron», y `prompts` sigue en verde aunque `publication` reviente. |
| `tests/Feature/UltronStudio/UltronStudioLabRunsTest.php:68` | **Nueva** `test_la_fidelidad_es_el_literal_del_contrato_en_el_estado_y_en_cada_ejecucion`: el literal exacto, escrito en la prueba y no tomado de la constante, en `lab/status` y en el detalle de una ejecución, y sin «el motor no lee el Studio». |

**Migraciones:** ninguna. **Variables de entorno o configuración nuevas:** ninguna (se reutiliza `marketing.ultron.studio_prompts` mediante `StudioEngineReadings::promptsKey()`).

---

## 2. Tarea 1: alerta M1-A, decisiones

### 2.1 Contrato de la comprobación (`--json`, `checks.prompts`)

Vigilando, con la llave en `canary` y canario configurado:

```json
{"ok": false, "status": "drift", "problems": ["mismatch"], "reason": null, "key": "canary",
 "window": {"from": "2026-11-11T14:28:00Z", "to": "2026-11-11T14:58:00Z"},
 "turns": 3, "byState": {"legacy_drift": 0, "mismatch": 2},
 "fingerprints": [{"state": "mismatch", "version8": "b0000000", "offered8": "702890f7", "observed8": "deadbeef"}]}
```

- `status`: `ok`, `drift`, `not_watched` o `error`. `error` lo pone `handle()` si la lectura revienta, con `problems = ["check_failed"]`.
- `reason`: `null` cuando se vigila; `prompts_off` (llave distinta de `canary`, con el mismo criterio que la oferta: `all`, vacía o `Canario` cuentan como `off`); o `no_canary`.
- Sin vigilar, `turns` y `byState` valen `null` y `fingerprints` vale `[]`.
- Salida de texto: `prompts: NO CUADRA (mismatch) · llave de prompts canary · ventana 2026-11-11T14:28:00Z a 2026-11-11T14:58:00Z · 3 turnos de la canaria · legacy_drift 0 · mismatch 2`. Sin vigilar: `prompts: OK · llave de prompts off · ventana … · no se vigila (prompts_off)`.
- Código de salida: el de siempre. 0 si todo cuadra (también sin vigilar), 1 si hay `drift` o `check_failed`.

### 2.2 La ventana (requisito b): cuál es y por qué

**Ventana:** `[S − 32 min, S − 2 min)`, donde S es el inicio de la media hora en que corre la pasada (`promptsWindow()`). Ejemplos: a las 10:00:04 mira de 09:28 a 09:58; a las 10:30:02, de 09:58 a 10:28. El inicio entra; el final, no.

**Por qué esta y no otra:**

- **No hay «pasada anterior» guardada.** Las tres comprobaciones existentes no guardan estado, y guardar un cursor (en la caché o en el incidente) sería un mecanismo nuevo, que pediste evitar. Además, en seco o si IRON GUARD no puede anotar, el cursor no debería avanzar: más lógica genérica en `handle()`.
- **«La ventana que ya usa el monitor» es su cadencia, `*/30`.** Si la ventana se alinea con la media hora y no se calcula como «los últimos 30 minutos», dos pasadas programadas consecutivas miran tramos contiguos y disjuntos, aunque arranquen con segundos (o minutos) de retraso. Así **un turno no lo cuentan dos pasadas programadas ni cae entre dos**, sin guardar nada. Lo prueba `test_cada_pasada_programada_…`, con 4 turnos en los bordes y 5 pasadas: cada turno malo cuenta una vez y deja 3 ocurrencias. Lo prueba también la mutación M3.
- **La gracia de 2 minutos:** `created_at` se fija al guardar, dentro de la transacción del commit. Si la ventana se cortara justo en la media hora, un turno fechado a las 09:59:59 y confirmado a las 10:00:01 no lo vería ninguna pasada. Con la gracia lo ve la de las 10:30. El coste es que la latencia máxima de la alerta pasa de unos 30 a unos 32 minutos más la pasada. Lo vigila la mutación M2.
- **Tras desplegar, no hay alertas retroactivas:** la primera pasada solo mira la media hora anterior.

**Límites que dejo dichos:**

- Una pasada **a mano sin `--dry-run`** repite la ventana de la última programada y suma una ocurrencia al mismo incidente. Si alguien lo acaba de resolver, lo reabre. Los guiones de operación ya llaman al monitor en seco.
- Si el programador salta una pasada entera (la tarea no corre en toda la media hora), esa media hora no se vigila. Con el servidor caído no hay turnos (n8n no puede hacer commit). La traza y el CRM siguen enseñando el turno en rojo.

### 2.3 La lectura (`StudioCanaryMeasures::promptAlerts()`)

- **Filas:** las mismas de `promptTurns()`: la conversación canaria (el id solo filtra), `source_type = inbound_message` y `created_at` en `[from, to)`.
- **Consulta 1:** un recuento con sumas condicionadas, con la ruta JSON de la gramática (`metadata->studio_prompts->state`), como hace `turns()`: el total de turnos y cuántos de cada estado de alerta. La comparación es exacta: `MISMATCH` o `mismatch ` no cuentan (lo cubre una prueba).
- **Consulta 2:** solo si hay algo que contar. Los 20 turnos de alerta más recientes (`whereIn` sobre la ruta JSON). De ellos, las 5 primeras combinaciones distintas de estado, versión ofrecida y huellas ofrecida y observada, todas recortadas a 8 con `short()` (que solo admite hexadecimal), y ordenadas para que sean deterministas.
- Sin canario configurado devuelve `null`. Un fallo de lectura se propaga y el monitor lo convierte en `check_failed` (incidente medio, sin el mensaje).
- **«La versión publicada»:** uso la versión **ofrecida en ese turno** (`offered.version`, recortada a 8), que es la que `decide` sacó de la cadena en ese momento. Es la misma etiqueta que enseñan `ultron:prompt-trace` y «Turnos de la canaria». La comprobación `publication` del monitor no enseña ningún uuid de versión, solo el `seq` de la cabeza, así que no había un precedente distinto que seguir. En un `legacy_drift` la oferta es Legacy y la versión sale `null`.

### 2.4 El incidente

| Campo | Valor |
|---|---|
| `source` · `kind` | `ultron_studio` · `prompts_drift` |
| `severity` | **alta**, como `publication_drift`: cambia lo que ULTRON dice en la canaria. No es crítica porque el motor sigue respondiendo. |
| `fingerprint_keys` | los estados ordenados (`["mismatch"]`, `["legacy_drift"]` o los dos), nunca la ventana ni el turno. Si se meten (mutación M6), cada pasada abre otro incidente. |
| `title` | `Los prompts que n8n aplicó en la conversación canaria no son los esperados (M1-A): mismatch` |
| `evidence` | `check`, `problems`, `check_command` (`php artisan ultron:prompt-trace --last=20`), `runbook` (`backend/docs/ultron-studio/ops/RUNBOOK_M1.md`), `window`, `turns`, `by_state` y `fingerprints`. Nada más. |
| `affected_*`, `correlation_ids` | 0, 0 y `[]`, como el resto del monitor. |

**Deduplicación (c):** es la de `IncidentRecorder`. Con una huella por clase y la ventana de agrupación de 60 minutos, la misma deriva cada 30 minutos es un solo incidente que suma ocurrencias, sin reabrir y con la evidencia del momento. Con los dos estados a la vez se abre su propio incidente: la clase es el conjunto de estados, como en `publication` (`["drift"]` frente a `["drift", "head_invalid"]`). Lo documenta la prueba de deduplicación. Si pasan más de 60 minutos sin repetirse, IRON GUARD reabre la misma fila, como con cualquier otro incidente.

**Requisito (f):** el plan **no** pide frenar. Las fuentes son M1-13 del H5 (`ULTRON_STUDIO_H5_CONTRACT.md:413`: «la alerta automática (incidente) queda pendiente») y M1-A de `informe-api-security-ola.md` («Alertar (vigía o monitor, con su incidente)», y la alternativa de forzar la salida curada: «hoy no la recomiendo»). M1-13 y M1-A no están en `STUDIO_MOTORES_PLAN.md`: los encontré en esos dos documentos. La prueba 1 comprueba que en la pasada solo se escribe en `incidents` e `incident_events`, que no se echa ningún `UltronAbort` y que la llave no cambia.

---

## 3. Pruebas

### 3.1 La batería nueva: `UltronStudioMonitorPromptsTest` (10 pruebas, 132 aserciones)

| # | Prueba | Requisito |
|---|---|---|
| 1 | `test_un_mismatch_de_la_canaria_abre_prompts_drift_alto_sin_ids_ni_textos_y_no_frena_nada` | (a), (d) y (f); forma exacta de la evidencia y salida en texto |
| 2 | `test_un_legacy_drift_tambien_abre_el_incidente_sin_version_ofrecida` | (a) |
| 3 | `test_los_turnos_correctos_y_los_de_fuera_de_la_canaria_no_alertan` | (a): `applied`, `legacy`, `engine_fallback`, `unverified`, `not_reported`, `stamp_error`, variantes de mayúsculas y espacios, sin sello, otra conversación y un seguimiento saliente |
| 4 | `test_cada_pasada_programada_mira_la_media_hora_anterior_y_un_turno_no_alerta_dos_veces` | (b), con los bordes de la ventana |
| 5 | `test_la_ventana_es_la_cadencia_del_programador_y_no_hay_tarea_nueva` | (b) y (g) |
| 6 | `test_la_misma_deriva_en_pasadas_seguidas_es_un_solo_incidente_con_mas_ocurrencias` | (c) |
| 7 | `test_con_la_llave_de_prompts_en_off_o_sin_canario_no_vigila_y_dice_por_que` | (e): `off`, `all`, `' '` y `Canario`, sin leer `marketing_ai_actions`; sin canario |
| 8 | `test_en_seco_lo_dice_y_no_escribe_nada` | modo en seco: cero escrituras; tope de 5 huellas, las más recientes |
| 9 | `test_si_la_lectura_de_la_canaria_revienta_es_check_failed_sin_el_mensaje` | `check_failed` medio, sin el mensaje |
| 10 | `test_los_estados_de_alerta_son_los_del_sello_del_motor` | paridad con `UltronPromptStamp` |

Todas usan las clases reales: el comando por `Artisan`, `StudioCanaryMeasures`, `IncidentRecorder` y SQLite, con turnos sembrados con `PublicationFixtures::stampTurns()`. El único doble es el de la prueba 9, un `bind` que lanza la excepción, con el mismo patrón que `UltronStudioMonitorDriftAndFailuresH7Test`.

En las pruebas 1 y 2, la salida JSON, la evidencia y el título **no** contienen:

- el id de la conversación canaria (`987654321`) ni el teléfono del lead (`3009998877`);
- el motivo libre de n8n (`TEXTO LIBRE …`) ni el id de su ejecución (`EJEC-…`);
- los marcadores de texto del turno;
- el uuid entero de la versión, las huellas enteras (la ofrecida, la alterada y la de deriva) ni el `contentHash`;
- ninguna clave `lead*`, `conversation*`, `execution*` ni `entry`.

### 3.2 Salida real de lo ejecutado

Todo con `nice -n 10 php -d memory_limit=2G vendor/bin/phpunit --no-logging`, en SQLite y un proceso cada vez:

| Ficheros | Resultado |
|---|---|
| `UltronStudioMonitorPromptsTest.php` | **OK (10 tests, 132 assertions)** |
| `UltronStudioMonitorTest.php` + `UltronStudioMonitorDriftAndFailuresH7Test.php` + `tests/Feature/Chaos/ChaosQueueOrphanedReservationTest.php` | **OK (23 tests, 256 assertions)** |
| `UltronStudioLabRunsTest.php` + `UltronStudioLabRunnerTest.php` | **OK (40 tests, 774 assertions)** |
| `UltronStudioPublicationPromptsTest.php` + `UltronStudioPublicationCanaryTest.php` + `tests/Feature/Marketing/UltronPromptsTraceCommandTest.php` (los otros consumidores de `StudioCanaryMeasures`) | **OK (28 tests, 596 assertions)** |
| Paquete de guardas estáticas completo (el comando del lead: 15 ficheros) | **OK (138 tests, 178 638 assertions)** |
| Repetición tras Pint: la batería nueva, las dos del monitor y la del laboratorio | **OK (45 tests, 713 assertions)** |
| `vendor/bin/pint` sobre los 7 ficheros tocados | `{"tool":"pint","result":"passed"}` (sin cambios) |

### 3.3 Mutaciones (siete; código restaurado)

Cada mutación se aplicó sobre el fichero real, se corrió la batería nueva y se restauró desde una copia del scratchpad (no con `git checkout`, que habría borrado el trabajo sin confirmar). Al terminar, las huellas sha256 de los dos ficheros coinciden con las originales: `MonitorCommand.php` `f25306f0…`, `StudioCanaryMeasures.php` `cf29e0f1…`.

| # | Mutación | Resultado |
|---|---|---|
| M1 | `PROMPT_ALERT_STATES = ['mismatch']` (`legacy_drift` deja de alertar) | **6 fallos** (pruebas 1, 2, 3, 6, 7 y 10) |
| M2 | Sin la gracia (`$hasta = $inicio`) | **3 fallos** (1, 4 y 7) |
| M3 | Ventana sin alinear (los últimos 30 minutos desde `now`) | **3 fallos** (1, 4 y 7) |
| M4 | Con la llave en `off` también vigila (`if (false)`) | **1 fallo** (7) |
| M5 | La versión ofrecida entera en la evidencia (sin `short()`) | **1 fallo** (1) |
| M6 | La ventana dentro de `fingerprint_keys` | **1 fallo y 1 error** (4 y 6) |
| M7 | Sin el filtro de la conversación canaria | **1 fallo** (3) |

Un primer intento de M5 no llegó a aplicarse: el patrón aparecía dos veces, también en `promptTurns()`, y el ayudante exige una sola aparición. Su «OK» se descartó y se repitió con el patrón acotado. El texto de la tarea 2 no lleva mutación propia: la prueba nueva compara con el literal escrito en ella, así que cualquier cambio del texto la rompe.

---

## 4. Tarea 2: el texto de fidelidad (INC-1, T6)

- **Cambio:** `app/Services/UltronStudio/StudioLabRuns.php:60`, con el literal copiado tal cual: 354 caracteres, 360 bytes, sha256 `d8949aa933326affc6a194eef48ebd9860d8d879871ae25e58595bb5f051448a`. Calculé el mismo sha256 sobre tu literal con `printf | shasum`.
- **¿Se guarda o se calcula al leer?** **Se calcula al leer.** Sale de la constante en `status()` (`:147`, antes `:141`) y en `detail()` (`:409`, antes `:403`). No hay ninguna columna `fidelity` en las migraciones y **no entra en `resultsHash()`** (`:554-582`). Las ejecuciones y los veredictos existentes no cambian de huella.
- **¿Algún validador o DTO limita su longitud?** **No.** En el backend es una constante de salida, sin validación. En el CRM, `studio-lab-parse.ts:289` y `:712` lo leen con `texto(…)` sin `max` (`studio-parse.ts:321`): solo exige que sea texto no vacío. El tope de 600 está en `studio-voice-parse.ts:997`, que es la fidelidad del laboratorio **hablado**, otra constante (`StudioVoiceLabSessions::FIDELITY`), y no se toca.
- **Las guardas:** ninguna prueba del backend fijaba el texto antiguo (busqué `No es ULTRON`, `FIDELITY`, `fidelity`, «viven en n8n» y «el motor no lee el Studio» en `tests/`). `UltronStudioEngineReadOnlyTest:36` solo lo nombra en un comentario («`UltronEngineIsolationTest` prueba que el motor no lee el Studio»), que habla de la frontera de importaciones y no de este texto. Ninguna guarda prohíbe el cambio y no se relajó ninguna.
- **Contrato:** `ULTRON_STUDIO_H4_CONTRACT.md:453` (§A7) sigue con el texto antiguo. **No lo toqué**: su enmienda es tuya.
- **Ficheros del CRM que lo copian o parafrasean** (solo los nombro):
  - `Iron-Body_Front/src/app/modules/ultron-studio/studio-lab-fixtures.testing.ts:43` (`LAB_FIDELIDAD`, usado en `:66` y `:318`). **Ya está cambiado** en el árbol de trabajo del CRM (` M`) con el mismo literal: comprobé el sha256 `d8949aa9…`.
  - `Iron-Body_Front/src/app/modules/ultron-studio/studio-lab-page.ts:28-29`: un comentario de código con la explicación antigua («un actor de laboratorio interpreta la configuración; el motor no lee nada de esto»). No es visible, pero ya no es verdad.
  - Lo pintan sin copiarlo `studio-lab-page.ts:105` y `studio-lab-run-view.ts:61`, y lo comparan con el fixture `studio-lab-page.spec.ts:105` y `studio-hostile-text.spec.ts`. La etiqueta fija «Simulación del Studio · no es ULTRON» (`studio-lab-text.ts:44`, `studio-lab-page.spec.ts:104`) no forma parte de este literal.

---

## 5. Operación: ficheros que hay que actualizar por este cambio

Ninguno es necesario para que el cambio funcione, porque los guiones de despliegue llaman al monitor con `--check=` explícito. Lo que conviene:

1. **Documentos que ya no dicen la verdad** (son del lead; no los toqué):
   - `docs/ultron-studio/ops/RUNBOOK_M1.md:298-299` («hoy no hay alerta automática»): ahora la alerta es el incidente `prompts_drift`, y su evidencia apunta a este runbook.
   - `docs/ultron-studio/ULTRON_STUDIO_CIERRE_9_HITOS.md:42` y `:96` («NO IMPLEMENTADO», «completos salvo la alerta M1-A»).
   - La matriz de la auditoría A (filas A3 y L3).
2. **`docs/ultron-studio/ops/h7-monitor-scope.sh:292`:** la comprobación previa a la primera pasada solo ensaya `--check=publication --check=integrations --dry-run`. Recomiendo añadir `--check=prompts`, para que su «INFO» prevea también un `prompts_drift`.
3. **Smoke tras el despliegue** (propuesta para devops): `sudo -u www-data php artisan ultron-studio:monitor --check=prompts --dry-run --json`. Hay que esperar `status` `ok` (o `not_watched`), `key: canary` y una ventana coherente con la hora. Con la llave en `canary` en producción, es la prueba de que la comprobación lee de verdad.
4. **`docs/ultron-studio/ops/banco-h7/ensayo-vuelta.sh:50`** corre el monitor completo sobre un banco sano y espera 0 sin incidentes. Con la llave de prompts por defecto (`off`) seguirá dando 0 (`not_watched`). No requiere cambios.
5. **Cuenta de tareas del monitor:** no cambia (siguen 2: `ultron-studio:monitor` e `integrations-snapshot`). Los guiones que vigilan ese recuento no se tocan.

---

## 6. Observaciones y lo que NO verifiqué

### 6.1 `legacy_drift` con la llave en `off` (decidido por el lead: se queda así)

`UltronDecideService::decide()` (`:402`) negocia los prompts siempre que n8n los pide, **sea cual sea la llave**. La copia S1 los pide siempre, así que con la llave en `off` el turno sigue llevando su sello: lo prueba el turno 941514 de L9-109, `legacy · no_publication`. Por tanto, un `legacy_drift` (alguien edita a mano las constantes Legacy en n8n) también es detectable con la llave apagada. Con (e) tal como lo pediste, **no alerta**. Si se quiere vigilar `legacy_drift` siempre y `mismatch` solo con la llave en `canary`, es una condición en `prompts()` y una prueba. No lo cambié.

### 6.2 Riesgos y límites

- **Índice:** `marketing_ai_actions` no tiene índice por `conversation_id` (la FK no lo crea en PostgreSQL). Mis dos lecturas filtran como ya lo hacen `promptTurns()` y `turns()`, y corren una vez cada 30 minutos. **No lo medí en producción** ni conozco el volumen de la tabla. No lo escalo a `database-specialist` porque sigue el patrón existente y su frecuencia es mínima. Si la tabla fuese grande, el índice `(conversation_id, created_at)` beneficiaría a las tres lecturas.
- **Seguridad:** el incidente no expone datos personales. Es un cambio de observabilidad, sin superficie de API nueva, así que no lo escalo a `api-security-specialist`.
- **Para `tester`:** los casos límite que conozco desde dentro son los turnos justo en los bordes (cubiertos), las pasadas a mano sin `--dry-run` (§2.2), la reapertura tras más de 60 minutos sin repetirse (comportamiento estándar de IRON GUARD, sin prueba propia aquí) y el sello con un `state` desconocido (no alerta: cubierto con las variantes de mayúsculas y espacios).

### 6.3 Lo que NO verifiqué

- ~~PostgreSQL~~: **verificado después** (§7). Siguen sin pasar por PostgreSQL las baterías del laboratorio y de la publicación de §3.2; mi único cambio en ellas es el literal de una constante.
- **Producción y banco:** nada ejecutado; no hay despliegue ni ensayo en el banco.
- **La batería completa:** no la lancé, como indicaste. La prueba intermitente conocida, `UltronStudioVoiceLabSessionsTest::test_la_lista_filtra_y_pagina_sin_consultas_por_fila`, no entró en lo que ejecuté.
- **El CRM:** no ejecuté sus specs. Solo leí sus ficheros.

### 6.4 Visto de paso, fuera del alcance (no lo arreglé)

- Ha aparecido, sin ser mío, `docs/ultron-studio/plans/cierre/auditoria-c-voz-telefonia.md` (sin seguimiento): es de otro agente.
- `CrmPermission.php:303-304` sigue con un comentario desfasado («el motor no lee versiones»). Ya lo citó la auditoría A §3.7.

---

## 7. PostgreSQL (verificación pedida por el lead antes de desplegar)

**Entorno:** el clúster local desechable `~/.ironbody-pg14-disposable` (PostgreSQL **14.24**, Homebrew, `127.0.0.1:55432`, usuario `ironbody`), que ya estaba arrancado (`pg_ctl status`: en marcha; `pg_isready`: acepta conexiones). Usé una base **propia**, `ultron_studio_m1a_unit`, creada con `createdb` para no pisar `ultron_studio_h4_unit` ni las de otros agentes. Comando, un fichero cada vez:

```
UNIT_DB=ultron_studio_m1a_unit nice -n 10 bash docs/ultron-studio/ops/pgunit.sh --no-logging <fichero>
```

**Comprobado que corrió contra PostgreSQL y no contra SQLite.** `phpunit.xml:49` declara `DB_CONNECTION=sqlite` sin `force`, así que manda el entorno del envoltorio. Después de la primera ejecución, la base `ultron_studio_m1a_unit` tenía 255 tablas y 340 migraciones, y `marketing_ai_actions.metadata` es `jsonb`.

| Fichero | Resultado en PostgreSQL 14.24 |
|---|---|
| `tests/Feature/UltronStudio/UltronStudioMonitorPromptsTest.php` (nueva) | **OK (10 tests, 132 assertions)**, 6,7 s |
| `tests/Feature/UltronStudio/UltronStudioMonitorTest.php` | **OK (12 tests, 136 assertions)**, 7,6 s |
| `tests/Feature/UltronStudio/UltronStudioMonitorDriftAndFailuresH7Test.php` | **OK (4 tests, 80 assertions)**, 13,8 s |

**Ningún fallo**, así que no hubo nada que comparar con el commit base, ni código ni pruebas que tocar.

**El SQL que genera Laravel en PostgreSQL para mis dos lecturas.** Lo obtuve con `toSql()`, sin ejecutar, en `artisan tinker` con la conexión `pgsql`:

```sql
select count(*) as turns, sum(case when "metadata"->'studio_prompts'->>'state' = ? then 1 else 0 end) as alert_0
  from "marketing_ai_actions"
 where "conversation_id" = ? and "source_type" = ? and "created_at" >= ? and "created_at" < ?

select "id", "metadata" from "marketing_ai_actions"
 where "conversation_id" = ? and "source_type" = ? and "created_at" >= ? and "created_at" < ?
   and "metadata"->'studio_prompts'->>'state' in (?, ?)
 order by "id" desc limit 20
```

- `->>` devuelve texto, así que la comparación con el estado es texto contra texto, sin conversión implícita.
- Las pruebas que alertan (las de `mismatch`, `legacy_drift`, la ventana, la deduplicación y el tope de 5 huellas) solo pasan si esas dos sentencias cuentan y filtran bien sobre `jsonb`. Las comparaciones de la evidencia, con las claves ordenadas, pasaron con la evidencia guardada en `jsonb`.

**Lo que queda:** la base `ultron_studio_m1a_unit` sigue en el clúster desechable; se puede borrar con `dropdb -h 127.0.0.1 -p 55432 -U ironbody ultron_studio_m1a_unit`. No toqué `h7-monitor-scope.sh` ni ningún documento fuera de este informe.
