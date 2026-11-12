# Informe del reviewer · Ola 7 (`4a3d7eea`, `306b83e3`, `b10d593`)

**12-11-2026 · `reviewer` · solo lectura.**
- **Qué reviso:** tres commits LOCALES, sin subir ni desplegar.
  - Backend: `4a3d7eea` (la bandeja asistida retiene) y `306b83e3` (E2E de la voz sin fechas fijas y el `|| true` del arnés), en `_worktrees/ultron-h8/Iron-Body`.
  - CRM: `b10d593` (la bandeja enseña el motivo y `meta.withheld`), en `_worktrees/ultron-h8/Iron-Body_Front`.
  - Producción sirve hoy backend `b90f84c4` y CRM `d16e26a`.
- **Dónde ejecuté:** nunca en los árboles de trabajo.
  - Backend: en una copia de `_worktrees/ultron-suite/Iron-Body/backend` (HEAD `306b83e3`, comprobado). En la copia, `AssistedQueue.php` es idéntico a `git show 4a3d7eea:…`, y las tres pruebas y el controlador son idénticos a `306b83e3` [C].
  - CRM: en un `git archive b10d593` con `node_modules` clonado.
  - Copias, mutaciones y sondas están en el directorio temporal de la sesión, no en ningún repositorio.
- **Qué no he tocado:** código, pruebas, contratos ni producción. No hice commits. Lo único que he escrito es este informe.
- **Qué no repito:** lo que security ya dejó escrito (`informe-security-bandeja-condiciones.md`: N-1, N-2, D-3 y m-1 a m-8). Otro agente lo está cerrando en `AssistedQueue.php`. Aquí va solo lo que security no vio.

Marcas: **[C]** comprobado ejecutando · **[L]** leído en el código · **[S]** supuesto.

## Veredicto

| Commit | Veredicto | ¿Se puede subir y desplegar hoy? |
|---|---|---|
| **`4a3d7eea`** (backend, bandeja asistida) | **APROBADO** | **Sí.** Solo retiene más, nunca menos [L + C]. Las condiciones previas al asistido EN VIVO son las de security (N-1, N-2 y D-3). Yo no añado ninguna. Mis tres menores son de pruebas y se pueden cerrar en la ronda que ya está en curso |
| **`306b83e3`** (pruebas E2E de la voz y arnés) | **APROBADO** | **Sí.** No toca `app/`, `routes/` ni `config/` [C: `git show --stat`], así que no cambia nada de lo que se despliega |
| **`b10d593`** (CRM) | **APROBADO** | **Sí, en cualquier orden respecto al backend.** Con el backend de hoy se ve igual que ahora. Con el CRM de hoy y el backend nuevo, la fila retenida llega sin ficha ni guion, aunque sin aviso. Ninguna de las dos combinaciones expone nada [L + C] |

**Ningún bloqueante y ningún importante nuevo.** Hay cuatro menores, todos de calidad de pruebas o de riesgo residual. Ninguno cambia el comportamiento que se despliega hoy.

## Verificado

| Qué | Dónde | Resultado |
|---|---|---|
| `H9AssistedConditionsTest` | Copia del backend, SQLite | `OK (11 tests, 182 assertions)` [C] |
| Mis mutaciones (5) sobre `AssistedQueue.php`, contra `H9AssistedConditionsTest`, `H9AssistedComplaintTest`, `OutreachApiTest` y `OutreachOutcomesTest` (28 pruebas) | Copia, SQLite | **Sobreviven 4 de 5** (detalle en m-R1, m-R2 y Notas). El fichero quedó restaurado (mismo md5) [C] |
| Tres mutaciones del informe de backend, rehechas por mí, y una mía del cursor | Copia, SQLite | I2-b (`<` en vez de `<=`): muerta. I3-f (`claim()` sin leer frenos): muerta. I1-f solo en `dto()` (enseñar el motivo a todos): sobrevive, pero es **equivalente** (ver Notas). Mi C (el cursor sin desempate): muerta [C] |
| Sondas RV1 (página que se corta dentro de una tanda llena) y RV2 (`withheld` con freno, un reclamado y un vencido) con el código real | Copia, SQLite y **PostgreSQL 14** (`ultron_studio_1581_voz`) | Las dos en verde en los dos motores. Matan las mutaciones que sobrevivieron (A, B y D) [C] |
| `instantesDelRelojDesde()` (por reflexión) en 15 instantes de borde y en el fin del fixture | Copia | 505 comprobaciones con un oráculo propio leído del fixture: el día de la semana, que no sea festivo o sí, a las 10:00 −05:00, más de 1 h por delante y que sea **el primero** que cumple. El fin del fixture falla con su mensaje [C] |
| La trampa de `set -e` con `X="…$([ -d no ] && echo …)…"`, antes y después de `|| true` | bash | Sin el arreglo, el guion muere con 1 y sin mensaje. Con él, sigue y la cadena es correcta en los dos casos [C] |
| CRM: `npx tsc -p tsconfig.app.json --noEmit` y `npx tsc -p tsconfig.spec.json --noEmit` | `git archive b10d593` | rc 0 y rc 0 [C] |
| CRM: `node scripts/check-literals.mjs` | ídem | `417 ficheros revisados · literales correctos` [C] |
| CRM: `ng test` de `commercial-automation/**` y de `supervision/**` | ídem | 20 ficheros con 615 pruebas, y 1 fichero con 21 pruebas: **636 en verde** [C] |

**No lo he ejecutado:**
- el E2E de la voz con la pila (me baso en el tester y en el lead);
- `H9AssistedConditionsTest` y `H9AssistedComplaintTest` completos en PostgreSQL (los corrió el lead en tres bases; yo solo mis sondas);
- la batería completa (por indicación del encargo);
- `ng build`.

---

## 1 · `4a3d7eea` · la bandeja asistida

### Bloqueantes
Ninguno.

### Importantes
Ninguno nuevo.

### Menores

**m-R1 · Las pruebas no fijan el recuento de los frenados (`brakedCount()`, `AssistedQueue.php:429`).**
- **Qué pasa:** dos mutaciones dejan verdes las 28 pruebas de los cuatro ficheros [C]:
  - (A) quitar `->whereNull('j.assisted_claimed_by_admin_id')`, es decir, contar también lo reclamado;
  - (B) quitar `->where('j.expires_at', '>', …)`, es decir, contar también lo vencido.
- **Por qué no se ve:** todas las aserciones de `withheld` con freno usan la vista `unclaimed`, que ya excluye lo reclamado, o un mundo sin reclamados ni vencidos (`test_i4_…:420`).
- **Escenario de fallo (sonda RV2) [C]:**
  1. Hay un freno de estrategia.
  2. Ana tiene reclamado un trabajo, otro está libre y un tercero libre ya venció.
  3. Beto pide `page('all')`.
  - Con el código real, `withheld` es 1, en SQLite y en PG.
  - Con A o con B, es 2, y el CRM pintaría «Hay al menos 2…».
- **Impacto si alguien lo rompe:** el número se infla. No expone datos.
- **Corrección:** añadir a `test_i3_…` o a `test_i4_…` una aserción de `page($beto, 'all')['withheld']` con un reclamado y un vencido presentes. La sonda RV2 sirve tal cual. → **backend / tester**

**m-R2 · Las pruebas no fijan el corte de la página (`break 2`, `AssistedQueue.php:153`).**
- **Qué pasa:** con `break` en vez de `break 2`, todo sigue en verde [C].
- **Por qué no se ve:** las pruebas solo llenan la página dentro de una tanda corta (`count($tanda) < $tamano`), que termina el bucle de todos modos.
- **Escenario de fallo (sonda RV1) [C]:**
  - Hay 120 trabajos ofrecibles y la petición pide `limit=20`.
  - Con la mutación, la página devuelve **22 filas**: 1–20, 51 y 101, que no son consecutivas.
  - Con el código real devuelve las 20 primeras, en SQLite y en PG.
- **Corrección:** una prueba con más de `SCAN_BATCH` filas ofrecibles y `limit < SCAN_BATCH` que fije el número y el orden exacto. → **backend / tester**

### Lo que pediste mirar, y está bien

- **El orden de `claim()` [L + C]:**
  - el orden es `assistedJob`, luego el estado, luego `openScopes()` y luego la decisión de repetir; después, `requireOpenEnrollment`, `requireNotWithheld` y, al final, el `UPDATE … WHERE status='started' AND assisted_claimed_by_admin_id IS NULL`;
  - los frenos van antes de la queja y antes del `UPDATE`, y se leen una sola vez (`test_i3_los_frenos_se_leen_antes…`). Mi mutación I3-f (sin leer frenos) muere;
  - la repetición usa la fila ya leída (`$trabajo`), con las mismas columnas que pide `withholdReasons()`: `strategy_uuid`, `channel`, `subject_key`, `purpose` y `expires_at` (`:450`);
  - la carrera entre la lectura y el `UPDATE` es m-2 de security.
- **El cursor `(expires_at, id)` [L + C]:**
  - `expires_at` está en `JOB_IMMUTABLE_COLUMNS` (migración `2026_11_12_100002…:352-355`, con su disparador). La clave del cursor no cambia entre tandas, así que no hay repetición ni salto por actualización;
  - la comparación por tupla es estricta y es la misma que el `ORDER BY`. La mutación sin desempate muere (55 empates que cruzan el corte);
  - una fila que nace entre tandas con una clave menor no sale en esa página, sino en la siguiente lectura. Es aceptable;
  - una fila reclamada por otro entre tandas sale de `unclaimed` sin duplicarse.
- **`queue()` para sus llamadores [L]:**
  - fuera de las pruebas, el único llamador era el controlador, que ahora usa `page()`;
  - `queue()` devuelve la misma lista, con la clave nueva `contactWithheld`;
  - los cambios de comportamiento son los buscados: la página se llena, la vista `mine` mira la queja (+5 consultas fijas) y lo vencido o frenado sin reclamar no sale;
  - las otras pruebas que usan `queue()` (`OutreachOutcomesTest`, `H9LeadFollowupTest`, `H9MonitorThresholdsTest`) están dentro de las 141 que corrió el lead [L]. `OutreachOutcomesTest` también la corrí yo [C].
- **El borde `expires_at <= ahora` [L]:** coincido con security. `timestamp(0)` y `OutreachStore::sql()` truncan a segundos. El SQL usa `>` y el PHP `<=` con el mismo `$ahora`. La mutación `<` muere [C].
- **Las pruebas cambiadas de `H9AssistedComplaintTest` [L]:** no se aflojan.
  - Una pasa de «conserva la ficha» a «`person`, `script` en null y `complaint_recent`», que es exactamente lo que I-1 pide.
  - Otra pasa de «`mine` no lee quejas» a «las lee una vez», que es consecuencia directa de I-1.
  - Las dos son más estrictas que antes, no menos.

## 2 · `306b83e3` · las fechas del E2E de la voz y el arnés

### Bloqueantes
Ninguno.

### Importantes
Ninguno.

### Menores
Ninguno que pida cambio.

### Lo que pediste mirar, y está bien

- **Ninguna aserción se ha aflojado [L]** (`git show 306b83e3 -- backend/tests`):
  - solo cambian las tres constantes por `instanteDelReloj(…)` y los mensajes de tres `must()`;
  - sus condiciones (`=== true` / `=== false`) son las mismas;
  - el resto son comentarios;
  - no quedan fechas fijas ni constantes viejas en `tests/Feature/Voice/E2E/` [C: `git grep`].
- **El cálculo de las tres fechas [C, sonda propia por reflexión]:**

| «Ahora» (Bogotá) | Martes | Domingo | Festivo |
|---|---|---|---|
| mar 17-11-2026 08:59:59 | **17-11** (1 h 0 min 1 s por delante) | 22-11 | mar 08-12 |
| mar 17-11-2026 09:00:00 | 24-11 (el límite es estricto: a las 10:00 justas no vale) | 22-11 | 08-12 |
| mar 17-11-2026 10:00 y 23:59:59 | 24-11 | 22-11 | 08-12 |
| dom 06-12-2026 08:00 | 15-12 (se salta el martes festivo 08-12) | **06-12** (el mismo día, 2 h por delante) | 08-12 |
| vie 24-12-2027 09:30 | 28-12 | 26-12 | **sáb 25-12** (festivo en sábado) |
| lun 25-12-2028 08:59 | 26-12 | 31-12 | lun 25-12 |
| lun 25-12-2028 09:00 y mar 26-12-2028 | — | — | **falla**: «el fixture … no cubre 2029», con su mensaje |

  - No sale ningún martes festivo, ningún «domingo» que no lo sea ni ninguna hora en el pasado.
  - Cada instante es el **primero** que cumple, comprobado día a día contra el fixture.
- **El `|| true` del arnés (`ops/h8-e2e.sh:244` y `:246`) [C + L]:**
  - dentro de la sustitución solo hay `[ -d … ]` y `echo`;
  - lo único que puede «fallar» es el `test`, que es justo el caso que se quiere tolerar, así que no esconde nada;
  - la otra sustitución parecida del guion (`:559`) va dentro del argumento de `echo` y no de una asignación, así que `set -e` no la ve [L];
  - las de `banco-h8/ensayar-ops8*.sh` llevan `|| echo 1`.

### Notas (riesgo residual, no hallazgos)

- **El reloj del job puede ir lejos [C para el cálculo, S para el job].**
  - Hasta 14 días para el «martes» y hasta **unas 10 semanas** para el «festivo» (desde el 12-01-2027 sale el 22-03-2027; desde el 17-08-2027, el 18-10-2027) [C].
  - La ejecución de hoy usó instantes a 3–5 días.
  - He leído lo que el job evalúa con su reloj antes de marcar:
    - la observación del número: el arnés la rehace con el mismo reloj (`workerDeUnTrabajo`);
    - el RNE: 30 días en el banco, y solo con alcance `contacts`, donde solo se usa el «martes»;
    - el horario y los festivos.
  - No he encontrado nada que caduque antes de 10 semanas [L]. Pero esa distancia **nunca ha corrido** en un E2E real [S].
  - Si algún día falla, falla en rojo con otro motivo, no en silencio.
- **Los festivos en sábado** (01-05-2027, 07-08-2027, 25-12-2027 y 01-01-2028) se elegirán como «festivo».
  - Con `VOICE_OUTBOUND_HOURS_*` vacías, `OutboundSchedule::evaluate` queda solo en la franja legal (sábado de 08 a 15). A las 10:00 daría `outsideHours=false` y `holiday=true`, es decir, `holiday` [L].
  - No se ha ejecutado [S].
- **Caducidad programada:** desde el 25-12-2028 a las 09:00 de Bogotá, la fase abierta falla hasta que se amplíe el fixture de festivos [C]. Conviene apuntarlo en el calendario de operación.

## 3 · `b10d593` · el CRM

### Bloqueantes
Ninguno.

### Importantes
Ninguno.

### Menores
Ninguno nuevo. El texto de la lista vacía con `withheld > 0`, «Ahora no hay trabajos asistidos que se puedan ofrecer» (`ca-assisted.ts:87`), afirma algo que el servidor no garantiza cuando la lectura llega a `MAX_SCAN` o en el caso m-8. Es **m-3 de security** (cita la misma línea): no lo duplico.

### Lo que pediste mirar, y está bien

- **Los literales con acentos graves [C]:**
  - `check-literals.mjs` está en verde;
  - las plantillas nuevas no llevan ni `` ` `` ni `${`;
  - el único `${n}` es el de `assistedWithheldText()` (`commercial-automation-text.ts:255`), que es intencionado.
- **La API anterior [L + C]:**
  - `retencion()` (`parse.ts:1366`) da `null` si falta la clave;
  - `readAssistedQueuePage()` (`:1380`) da `withheld: null` si falta `meta` o si su valor no es un entero ≥ 0;
  - la prueba «una API anterior…» comprueba que la ficha, el guion y la vista son los de hoy;
  - en sentido inverso (el CRM `d16e26a` con el backend nuevo), el CRM viejo ignora `meta` y `contactWithheld`, y la fila llega sin `person` ni `script` desde el servidor.
- **Un motivo desconocido cuenta como retenido [L + C]:**
  - cualquier valor que no sea `null`/ausente y no esté en el conjunto (`''`, `false` u otro texto) pasa a `unrecognized`;
  - la prueba con `legal_review` lo fija.
- **Nunca la ficha ni el guion con un motivo [L + C]:**
  - la fila los pinta solo a través de `persona(j)` y `guion(j)` (`ca-assisted.ts:106` y `:120`, definidas en `:203-210`), que dan null con cualquier motivo;
  - no hay otro sitio que pinte `person`, `script` ni `raw`: el diálogo de resultado solo usa `subjectRef` y `strategy.key` [C: `git grep`];
  - las pruebas mandan `person` y `script` CON el motivo y comprueban que ni `[data-ca-asistido-ficha]` ni `[data-ca-asistido-guion]` existen, ni el texto «Socio #42» ni el del guion.
- **Accesibilidad [L]:**
  - el aviso empieza por «**Contacto retenido.**» en texto, así que no depende del color;
  - `lab-alert is-warn` e `is-info` existen en `studio-lab-kit.scss:30-31`;
  - `role="status"` en cada fila es aceptable. Un aviso que se inserta ya relleno no siempre se anuncia, pero queda en el orden de lectura dentro de la fila. No lo cuento como hallazgo.
- **Que ningún texto afirme de más [L]:**
  - los cuatro motivos coinciden con `withholdReasons()`;
  - «Hay al menos N contactos» es cierto porque el número es una cota inferior: frenados exactos más la queja hasta llenar la página;
  - los tres motivos del texto (freno, queja y sin datos) son los únicos que cuentan en `withheld` (lo vencido no se cuenta);
  - `TEXTO_BANDEJA_SIN_EVENTOS` coincide con `complaintRecently()`: con eventos apagados, las finalidades de queja (por defecto todas salvo servicio) dan `null` y se retienen. `sources.commercialEvents` es `config('commercial.events_enabled') === true` (`OutreachReadModels.php:62`);
  - «asistidos sin resultado del personal» describe bien `live.assistedQueue` (`OutreachReadModels.php:69-70`), que cuenta solo los que salieron (`started`).
- **«Modelo del agente anterior» [L]:** el valor sale de `SupervisionService::effectiveModel()`, que lee `marketing.ai.*`, es decir, el agente de mercadeo heredado y no ULTRON. La etiqueta es correcta. Ninguna prueba la fija, pero es una etiqueta.
- **Releer tras un 409 [L + C]:** `not_in_queue`, `already_claimed` y `enrollment_closed` releen con el mismo filtro (`commercial-automation-session.ts:709-712`), y hay una prueba por código. `enrollment_closed` ya lo mandaba el backend de producción (REV9 m-7), así que el CRM nuevo también mejora con el backend de hoy.

---

## Notas

- **Las «28 mutaciones muertas» del informe de backend:**
  - rehíce tres: I2-b e I3-f mueren como dice; la I1-f, solo en `dto()`, es equivalente (más abajo);
  - mis A, B y D sobreviven: son m-R1 y m-R2. El código de hoy es correcto en los tres casos (RV1 y RV2 en verde en SQLite y PG); lo que falta es la prueba que lo fije.
- **Mi mutación E** (quitar el corte por tanda corta) también sobrevive. Solo añade una consulta vacía por página y no cambia el resultado: no la cuento como hallazgo.
- **La mutación que enseña el motivo a quien no reclamó, solo en `dto()`, es equivalente:**
  - `page()` nunca calcula el motivo de una fila de otra persona;
  - la I1-f del informe cambiaba también el filtro, y esa sí muere;
  - la guarda `$reclamante ?` de `dto()` es una segunda barrera.
- **Rendimiento (no es hallazgo):**
  - el CRM no manda `limit` (20 por defecto), pero la primera tanda lee `SCAN_BATCH` = 50 filas y monta la foto de quejas de hasta 50 personas. Antes eran 20;
  - son las mismas consultas (≈4 por foto), con listas `IN` más largas, y sin efecto medible hoy, porque no hay asistidos en vivo [S];
  - si algún día importa, la primera tanda puede ser de `limit`.
- **El árbol principal del backend tiene ficheros nuevos de otro agente** (`informe-be-bandeja-ronda3.md` y `H9AssistedOptOutStrategyTest.php`). No son de esta revisión y no los he mirado.
