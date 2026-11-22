# database-specialist · cierre de ULTRON STUDIO · A (laboratorio, lote 5) y B (libro de autorizaciones, lote 4)

Motor: PostgreSQL 14 en producción; ensayo en PG 14.24 local (`C.UTF-8`) y SQLite 3.54 (PHP) / 3.51 (Python). **[C]** comprobado ejecutando · **[L]** leído · **[S]** supuesto.

## Veredictos
- **A · `2026_11_22_100001_add_engine_to_ultron_studio_lab_run_cases` + filtro por modo: APTO CON CONDICIONES. 0 bloqueantes.**
- **B · `2026_11_22_100001_create_outreach_consents_table` (ronda 2) + regla de los hermanos: APTO CON CONDICIONES. 0 bloqueantes.**

## A · Respuestas
**A.1 Migración.**
- **Solo toca el catálogo** [C]. El SQL es `alter table "ultron_studio_lab_run_cases" add column "engine" json null` (`--pretend`). Con 30.000 casos (22 MB, 3.750 con `engine`), el `relfilenode` (28136698) y el tamaño (22.347.776 B) son idénticos antes, después de `down()` y después de `up()`: no se reescribe la tabla.
- **Bloqueo** [C]: `AccessExclusiveLock` solo sobre `ultron_studio_lab_run_cases` (`pg_locks`). `up()` tarda 9,9 ms y `down()` 16 ms.
  - Riesgo de cola [C]: con una transacción de lectura abierta 4 s, el ALTER esperó 3,5 s y un lector que llegó después esperó 3,0 s detrás de él.
  - Con `lock_timeout = 5s`, una espera más larga da 55P03, la migración no queda registrada y se reintenta.
  - Solo el laboratorio usa la tabla [L]: el `grep` de `app/` solo la encuentra en `UltronStudio`; el motor de WhatsApp no la toca. El runner llama al modelo fuera de transacción; reclamar y liquidar son transacciones cortas (`StudioLabRunner.php:404`, `:649`).
- **`json` y no `jsonb`: es lo correcto aquí.**
  - `engine` entra en `results_hash` en las ejecuciones del motor (`StudioLabRuns.php:604-619`) [L]. La huella se calcula sobre las filas releídas de la base (`StudioLabRunner.php:298`, `StudioLabVerdicts.php:70`) y en forma canónica (claves ordenadas, decimales rechazados, `StudioContentHasher.php:163-171`). Con `json` el texto vuelve tal cual y la huella es estable.
  - `jsonb` rechaza `\u0000` [C: `'{"a":"x\u0000y"}'::jsonb` → `unsupported Unicode escape sequence`; `json` lo admite]. `engine` guarda texto del modelo (`drafts[].text`, `notes`): con `jsonb`, ese UPDATE de liquidación podría fallar.
  - Además: es coherente con `checks`, `judge` y `calls`; nadie consulta dentro de `engine`; ningún `DISTINCT`, `UNION` ni `GROUP BY` lo nombra (el único `groupBy` es `run_id, status, outcome`, `:1298`) [L].
- **Disparadores** [C]:
  - `uslc_forbid_terminal_update` salta con cualquier UPDATE cuando `OLD.status` es terminal, así que cubre `engine`. `uslc_forbid_immutable_update` va por lista de columnas y no la nombra: es mutable mientras el caso vive, que es lo que se pretende.
  - PG: los 4 disparadores siguen tras `down()`+`up()`. SQLite: el `sqlite_master` de la tabla es idéntico byte a byte antes y después (md5 a42f4881…; 3 disparadores y 2 índices).
  - `UltronStudioLabEngineColumnTest`: OK en PG (4 pruebas, 44 aserciones) y en SQLite (4, 41).
- **`down()`** es `DROP COLUMN` (solo catálogo, `AccessExclusive`) y no pasa por los disparadores.
  - Borra `engine` de casos terminales que la base declara inmutables.
  - Además **rompe para siempre la verificación de `results_hash`** de las ejecuciones del motor ya terminadas [L]: tras `down()` (y aunque se vuelva a hacer `up()`), `engine` es NULL, la huella recalculada no coincide y `record()` rechaza el veredicto (`StudioLabVerdicts.php:70-72`). El docblock solo dice «se pierde el recorrido».
- **SQLite** [L]: `ADD COLUMN` es nativo (`SQLiteGrammar.php:309`). `DROP COLUMN` es nativo desde SQLite 3.35 (`:525-537`); por debajo, Laravel reconstruye la tabla y se llevaría los disparadores. No sé qué versión usa la CI [S].

**A.2 Consulta del filtro por modo.**
- `prompt_version` es NOT NULL [C, `information_schema`]: la negación no deja fuera ninguna fila por NULL.
- **Planes** con 3.000 ejecuciones (1 de cada 8 del motor) y 1.960 veredictos, tras ANALYZE [C]:
  - lista del motor: `Index Scan Backward` por la clave primaria con filtro, 20 filas, 133 descartadas, 0,77 ms; de la simulación, 0,009 ms;
  - `count(*)` del paginador: `Seq Scan` de 150 páginas, 1,7 ms; `latestFor`: 0,14 ms el último y 0,12 ms `stale_prompts`.
- **No hace falta índice.** Lo más caro es el recuento, lineal: unos 0,6 ms por cada 1.000 ejecuciones.
- **LIKE e índice.** Laravel compila `"prompt_version"::text like ?` (`PostgresGrammar.php:56-65`). Un btree normal solo sirve al prefijo con intercalación C o con `varchar_pattern_ops`. El clúster local es `C.UTF-8`; la intercalación de producción no la sé [S]. Da igual: no hace falta.
- **Escape e intercalación.**
  - El patrón va como parámetro y sin `ESCAPE`, así que la trampa de PDO no aplica. El `-` no es comodín [C: `'motor_x' LIKE 'motor-%'` = f].
  - `LIKE` distingue mayúsculas en PG y no en SQLite; `str_starts_with` sí las distingue. Solo divergiría con un `MOTOR-…`, y ningún código lo escribe (`StudioLabEngineFlow::promptVersion()`, `:241`) [L].

**A.3 La misma marca en la puerta.**
- Sobre una columna NOT NULL, `LIKE` y `NOT LIKE` parten la tabla: 3.000 = 375 + 2.625, y 0 filas fuera de ambos [C].
- En `latestFor`, el veredicto vigente sale por igualdad exacta de `prompt_version`; la familia solo filtra `stale_prompts`. `PROMPT_VERSION` (`2026-11-12.6`) no empieza por `motor-` [L]. Ninguna fila cuenta en los dos modos ni en ninguno.

**Condiciones de A.**
- **C-A1** · No ejecutar `down()` en producción si hay filas con `engine`. Para volver al código anterior no hace falta: la columna NULL no le afecta (modelo `guarded`, columnas de la lista explícitas) [L].
  - Mejora no bloqueante (para quien edite la migración): que `down()` tome el cerrojo y se niegue si hay una fila con `engine IS NOT NULL`, como `refuseWithRows()` (`2026_11_02_100001:258-268`, libro C9).
- **C-A2** · Migrar con `ironbody-migrate` (`lock_timeout` 5 s), sin ejecuciones del laboratorio en curso. Si da 55P03, reintentar.

## B · Respuestas
**B.1 Invariantes del libro: se mantienen todas.**
- **Privilegios** [C], con un rol que no es el dueño y hereda los privilegios por defecto, como `iron_app`: SELECT t · INSERT t · UPDATE f (`has_any_column_privilege` también f) · DELETE f · TRUNCATE f · USAGE de la secuencia t. El lector no usa `FOR UPDATE` [L].
- **21 filas límite en PG (como rol de la aplicación) y en SQLite: mismo resultado en los 21 casos** [C]. Se admiten:
  - retiradas sin número, con seudónimo y del sistema con número;
  - evidencia con «», con `＜＞` de ancho completo o con `&lt;`.
- Se rechazan, con la regla que corresponde:
  - en una retirada, un seudónimo en mayúsculas, de 63 caracteres o de 64 que no son hexadecimales (`ocon_phone_chk`);
  - una petición sin número (`ocon_phone_chk`) y un rechazo con número (`ocon_phone_chk`);
  - `<` o `>` en la evidencia (`ocon_evidence_chk`);
  - una confirmación por la misma cuenta que la pidió (`ocon_four_eyes_chk`) o con otro número (`ocon_resolves_request`);
  - una segunda resolución de la misma petición (`ocon_request_unique`);
  - confirmar una petición anterior a una retirada (`ocon_grant_not_superseded`).
- **Ley 1581** [C]: redactar una retirada con número sin vaciar `phone_ref` → rechazado por `ocon_redaction_only`. La redacción entera → UPDATE 1. Un UPDATE de la aplicación → 42501.
  - El script de supresión lee los seudónimos de todas las filas de la persona, de cualquier tipo, y las redacta todas (`supresion-1581.sql:975-976`, `:1064-1067`) [L].
- **El CHECK nuevo puede rechazar filas legítimas:** cualquier evidencia con `<` o `>` (`->`, `<3`). La API la para antes con un 422 (`OutreachConsents.php:108`, `:442`) [L].
- **Puede dejar pasar:** `＜＞` y `&lt;`, inofensivo si el CRM pinta la evidencia como texto (M-7) [C]. El escritor de la palabra de baja aún no existe en `ecbd17c1` [L] → **C-B3.**

**B.2 Índice para la regla de los hermanos. Decisión: SÍ, ahora, en la misma migración (no bloqueante).**
- **Volumen** [C]: 4.000 socios y 40.000 prospectos dan 25.334 filas (10.400 peticiones, 9.534 confirmaciones, 5.400 retiradas, 4.629 con número; heap 8 MB). Lote de 500 personas con 600 números: 113 coincidencias. Estrés: 72.134 filas (21.600 retiradas, 22 MB).

| Caso medido (PG, tras ANALYZE) | Sin índice | Con el índice parcial |
|---|---|---|
| 25k filas · parte de los hermanos, lote de 500 | 5,1 ms (`Seq Scan` 1.023 pág. + `Hash Join`) | 3,5 ms (`Bitmap` sobre el índice) |
| 25k · sentencia entera (5 veces) | 10,6–15,4 ms | 7,7–13,7 ms |
| 25k · una persona (`GET` de la lista) | 5,5 ms (`Seq Scan`) | 0,12 ms (`Index Scan`) |
| 72k · parte de los hermanos | 25,5 ms (`Parallel Seq Scan`) | 8,6 ms |
| 72k · sentencia entera | 16–40 ms | 11–17 ms |
| 72k · una persona | 15,0 ms | 0,05 ms |

- **Sin índice**, la parte nueva lee la tabla entera y crece con todo el libro, no con las retiradas: hoy son pocos ms por lote, no es urgente; pero con la tabla vacía añadirlo ahora no cuesta nada. El anti-join ya usa `ocon_subject_idx` (Index Only Scan).
- **Definición exacta**, en `createTable()`, después de `ocon_subject_idx`, igual en los dos motores:
  `DB::statement('CREATE INDEX ocon_revoked_phone_idx ON '.self::TABLE." (phone_ref) WHERE kind = 'revoked' AND channel = 'whatsapp'");`
  - Es parcial y NO único: si una migración futura reconstruyera la tabla en SQLite y perdiera el `WHERE`, el índice solo crecería; no cambiaría el comportamiento.
- **Coste:**
  - **Creación:** instantánea en la tabla vacía, en la misma transacción del `CREATE TABLE`, sin nadie esperando (27 ms con 25k filas [C]). **Escrituras:** una entrada por retirada. **Espacio:** 384 kB con 5.400 retiradas [C].
  - **Si se deja para después:** otra migración con `CONCURRENTLY` (fuera de transacción), o un `SHARE` que frena las inserciones durante la construcción.
- **Mantener `CAST(? AS CHAR(64))`:** compara `bpchar` con `bpchar`; sin él, el índice no sirve [L].
- **A quién:** a quien edite la migración en `lote-4` (lo asigna el lead), más una prueba de esquema (tester): el índice existe en los dos motores y conserva su `WHERE` en SQLite.

**B.3 Concurrencia. El lector no lee mal: gana la negativa** [C].
- Lo reproduje con dos sesiones, con el rol de la aplicación:
  - A deja en vuelo una retirada con número de P (id 72780).
  - B confirma una petición antigua de P (id 72777): entra en 13,6 ms sin esperar. La guarda no la ve, porque en READ COMMITTED la retirada no está confirmada.
  - Antes del COMMIT de A, la tanda lee *granted*. Después, la tanda de P lee *revoked* (posición 72780 > 72777), y la regla de los hermanos calla a Q (`l:900002`, que tiene su propia confirmación).
- Dos retiradas a la vez: entran las dos (sin UNIQUE) y el lector dice *revoked* [L]. Dos confirmaciones: `ocon_request_unique` deja pasar una [C G4].
- **El orden depende de los ids, que se asignan al insertar:** `outreach_consents_id_seq` tiene CACHE 1 [C]. No se debe cambiar: con CACHE > 1 por sesión, el orden de los ids dejaría de ser el de llegada.
- **Pruebas existentes** [L, `OutreachConsentsApiTest.php:628-708`]: dos confirmaciones de la misma petición, y confirmar y retirar a la vez sobre la clave propia.
- **Faltan (para tester):**
  1. La misma carrera vista desde el hermano: Q comparte el número; *granted* antes del COMMIT de la retirada de P, *denied* después, también con una confirmación en vuelo de una petición antigua de P (lo que reproduje).
  2. Dos retiradas de la misma persona en vuelo, una con número y otra sin él: entran las dos y la que lleva número calla al hermano.
  3. Opcional: comprobar que la secuencia tiene CACHE 1.

**Condiciones de B.**
- **C-B1** · Migrar con `ironbody-migrate` como dueño y con `iron_app` existente (la migración aborta si falta el rol o si quedan privilegios). Después, comprobar en producción los privilegios **uno a uno**.
  - El bloqueo es solo sobre objetos nuevos: no toca ninguna tabla existente [L]. Tarda 21 ms en local [C].
- **C-B2** · `php artisan queue:restart` después de migrar (lo pide el autor, C-5).
- **C-B3** · El futuro escritor de la palabra de baja debe usar una evidencia fija, sin `<`, `>`, dígitos ni `@`, y nunca el texto que llega. Si no, el INSERT falla con 23514 y **la retirada se pierde (falla abierto)**. Para backend y security.

## No bloqueantes de B
- **N-B1** · El índice de B.2.
- **N-B2** · Al suprimir a P (Ley 1581), su retirada se queda sin `phone_ref`: Q, que comparte el número, deja de estar callado por ella [L]. Es una decisión para security y el propietario: si «no contactar» es dato de P o del número.
- **N-B3** · El bloque C9 de `supresion-1581.sql` no toma cerrojo sobre `outreach_consents`. Una inserción de la misma persona en vuelo que confirme después de la comprobación final queda sin redactar.
  - Es anterior a esta ronda (ahora las retiradas llevan `phone_ref`). Corrección: `LOCK TABLE public.outreach_consents IN SHARE MODE;` al empezar el bloque (espera a las inserciones en vuelo ≤ 5 s y deja leer). Para database-specialist [L, razonado, no reproducido].
- **N-B4** · La parte propia de la tanda elige `Seq Scan` + `Hash` con 25k filas: es más barato a ese volumen. El índice cubridor entra con más volumen (informe §4) [C].

## Lo que NO pude verificar
- **Producción:** intercalación, volumen real, privilegios reales de `iron_app` y tiempos de bloqueo. Lo medí en local; el orden de magnitud debería ser el mismo por ser solo de catálogo [S].
- **Versión de SQLite de la CI** (umbral 3.35 de `DROP COLUMN`).
- **PHPUnit de `lote-4`:** no lo ejecuté (un agente de backend edita `app/` y `tests/` en ese árbol; mezclaría su trabajo a medias). B lo verifiqué con `psql` y `sqlite3` sobre la migración de `ecbd17c1`, idéntica a la del árbol (`cmp`).
- **A:** solo ejecuté `UltronStudioLabEngineColumnTest` (PG y SQLite) y `UltronStudioLabRunsFidelityFilterTest` (SQLite, 3 pruebas, 166 aserciones). No la batería completa.
- **Limpieza:** borré mis 3 bases `ultron_studio_dbcierre_*` (quedan 0) y mi rol de ensayo. El rol `ultron_studio_lab_app_prueba`, que crea la prueba de A con nombre fijo y compartido, sigue en el clúster: no lo borré. No edité ningún repositorio.
