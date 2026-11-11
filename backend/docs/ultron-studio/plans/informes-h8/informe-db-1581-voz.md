# Informe DB · Supresión Ley 1581 de la voz (B-T3 / H-24) — database-specialist, 10-11-2026

Árbol `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h9` (HEAD `d701b0c8` = producción). **Sin commit,
sin red, sin producción.** Todo lo ejecutado fue en el clúster PostgreSQL 14 DESECHABLE local (`127.0.0.1:55432`).

## 0. En una frase

`ops/supresion-1581.sql` ya cubre las 11 tablas de la voz (texto, huella `voice-phone`, autoría del personal), con
`voice_forbid` en la lista blanca, `voice\_%` en las tres pasadas, aborto con filas vivas, una fila en el libro y en seco
por defecto; lo prueba de verdad en PG 14 `tests/Feature/Telephony/Schema/VoiceSuppression1581Test.php` (1 prueba, 1119
aserciones, verde; 5 mutaciones del guion, las 5 detectadas), y las secciones del H3–H7 y del H9 dan exactamente lo mismo
que antes (A/B en seco sobre 3 bases y A/B REAL sobre copias de `e2e`: 226 tablas, 0 diferencias).

## 1. Motor y versión

- **PostgreSQL 14** (local 14.24, la misma rama que producción según la memoria del proyecto; producción no consultada).
  `psql` 14. Nada del guion es de otra versión: `gen_random_uuid()` (≥ 13), `sha256()` (≥ 11), `\if`/`\gset` de psql.
- Laravel 12 + PHPUnit 11.5 para la prueba; las migraciones de la voz son `2026_11_10_100001` (gobierno, 4 tablas) y
  `2026_11_10_100002` (telefonía, 7 tablas), más la Enmienda 17 de V9 (`2026_11_13_100001`, **sin commit, de otro
  agente**) que amplía `vc_requester_chk` con el solicitante `strategy`.

## 2. Diagnóstico: lo comprobado y lo que no

**Hechos comprobados** (catálogo de la base migrada, código y ejecución):

| Hecho | Evidencia |
|---|---|
| El guion de HEAD no ve las 7 `voice_*` (ni las busca, ni avisa de sus columnas, ni cuenta sus disparadores) y da 32 avisos «columna sin política» de las 4 `ultron_studio_voice_*` | Seco de HEAD sobre la base recién migrada: 38 avisos = 32 de la voz + 6 de `ultron_studio_template_meta_checks` (W9) |
| 51 disparadores en las 11 tablas: UPDATE 22 (11 `ultron_studio_forbid` + 11 `voice_forbid`), DELETE 11, TRUNCATE 11, INSERT 7 (3 `ultron_studio_forbid` «nace…», 1 `voice_forbid` `vc_starts_live`, y `usvpub_chain_next`, `usvpub_agents_for_calls`, `vbr_release_targets_open` con función propia) | `pg_trigger` ⨝ `pg_proc` |
| 85 CHECK: 51 en `voice_*`, 34 en `ultron_studio_voice_*` | `pg_constraint` |
| La firma de la cadena de voz solo cubre `effective_json` y lo que `rowMatches()` cruza (seq, prev_hash, actor_admin_id, scope, agente, versión y perfil): `reason`, `actor_name`, `lab_gate` y `evidence` se pueden redactar | `StudioVoicePublications::verifyRowWith()`/`rowMatches()`; la prueba verifica la cadena (`ok`) antes y después de redactar esas cuatro columnas |
| `label` de un perfil no entra en `content_hash` (la huella es la de `content`) | `StudioVoiceProfiles::assertReviewable()` |
| La huella de voz es `HMAC(APP_KEY, 'voice-phone')` en la telefonía (`VoicePhone::hash`) y en el Studio (`StudioVoiceProfiles::phoneHash`, mismo `VOICE_PHONE_LABEL`), distinta de la del canario (`PhoneFingerprint`, `ultron-canary-phone/1`) | Código; en local, la orden de `tinker` del paso 2b da 64 hex, igual a la clase llamada directamente y distinta de la del canario |
| Un contacto retirado ya tiene el cifrado a NULL (`vct_cipher_chk`): la propuesta lo escribía; no hace falta | CHECK; la prueba lo asserta tras redactar |
| `ALTER TABLE … DISABLE TRIGGER` toma **`ShareRowExclusiveLock`** en PG 14 (no ACCESS EXCLUSIVE, como decía la cabecera del guion): bloquea escrituras, no lecturas | `pg_locks` dentro de una transacción con `ROLLBACK` sobre la base desechable |
| Con V9, el nombre de una llamada pedida por una estrategia tiene CHECK (`^Estrategia [a-z0-9][a-z0-9_-]{2,47}$`): redactarlo por texto viola `vc_requester_chk` y deshace TODA la supresión | Mutación M3 (abajo) |
| Nada del código ni de las pruebas lee la forma de la fila `system.personal_data_redacted` | `grep` en `app/`, `tests/`, `routes/`, `config/`, `resources/` |

**Hipótesis / no comprobado:** el volumen real en producción (la voz está en fase 1: tablas a 0 o casi; no lo he
consultado); el comportamiento con escrituras concurrentes de la aplicación (riesgo de interbloqueo, §7); la valoración
jurídica de §11 (H-C).

## 3. Política columna a columna (las 11 tablas)

Claves: **T** = se busca por `:term`/`:phone` y lo que casa pasa a «[suprimido]» (subcadena); **A** = autoría del personal
por `:admin_id` (el nombre entero a «[suprimido]»; el id se queda); **H** = por `:voice_phone_hash` (huella → aleatoria
de 64 hex, 4 dígitos → `0000`); **I** = se informa (recuento en la salida y en el libro), **nunca** se toca; **N** = neutra
(código, estado, id, huella de configuración, instante, número): no es dato personal y no se busca.

| Tabla | Columna | Política | Por qué / con qué CHECK |
|---|---|---|---|
| `ultron_studio_voice_profiles` | `label` | T | Texto del personal; no entra en `content_hash` |
| | `review_note` | T | `usvp_review_chk`: un rechazado la exige → «[suprimido]» la cumple |
| | `created_by_name`, `submitted_by_name`, `reviewed_by_name` | T + A | Instantáneas del personal (por `*_admin_id`) |
| | `content` | **I** | Con `content_hash` (y en él, la huella de voz de cada destino de transferencia): se informa por texto y por huella; remedio, revisión nueva del perfil |
| | `transfer_destinations_encrypted` | **I** | Cifrado (`Crypt`): SQL no ve dentro; un destino se reconoce por su huella en `content` y en el agente |
| | resto (`uuid`, `revision`, `language`, límites, banderas, `content_hash`, `status`, `*_role`, instantes) | N | — |
| `ultron_studio_voice_agents` | `created_by_name` | T + A | — |
| | `transfer_destination_hashes` | **I** | `{alias, hash}` congelados con la compilación (`projection_hash`); por texto y por huella |
| | resto (`purpose`, huellas de compilación, `provider*`, `observed_*`, `status`, `failure_code`, `serves_*`) | N | — |
| `ultron_studio_voice_publications` (la cadena, solo inserción) | `reason`, `evidence`, `lab_gate` | T | Fuera de la firma; `usvpub_reason_chk` exige `reason` en rollback/off → «[suprimido]» la cumple. `lab_gate` **no estaba en la propuesta** (daba aviso «sin política») |
| | `actor_name` | T + A | El `actor_id` del mensaje firmado es el id, no el nombre |
| | `effective_json` | **I** | Firmado (E1-4) y con `usvpub_hash_matches_chk`: jamás se toca |
| | resto (`seq`, `action`, `scope`, `prev_hash`, `effective_hash`, `signature`, ids) | N | — |
| `ultron_studio_voice_lab_sessions` | `verdict_note` | T | `usvl_verdict_chk`: un rechazo la exige → «[suprimido]» la cumple |
| | `verdict_by_name`, `created_by_name` | T + A | — |
| | resto (`status`, coste, instantes, `end_reason`, `guard_codes`, `verdict`) | N | `guard_codes`: códigos y turnos, sin texto |
| `voice_contacts` | `phone_hash`, `phone_last4` | **H** | Solo contactos RETIRADOS (uno vivo: en seco «VIVO», en real aborta). `vct_phone_chk` (64 hex + 4 dígitos) |
| | `phone_encrypted` | (ya NULL) | `vct_cipher_chk`: retirado ⇒ NULL. No se escribe (y `vct_cipher_only_shrinks` no se apaga) |
| | `consent_request_evidence`, `retire_reason` | T | Además, por huella, los que NO casan se cuentan como «textos de esa persona» (I) |
| | `registered_by_name`, `consent_requested_by_name`, `retired_by_name` | T + A | `vct_consent_request_chk` exige el nombre con la petición → «[suprimido]» la cumple |
| | `source_id` | **I** | Enlace al lead o socio del motor. `vct_source_chk` no admite una forma suprimida: se conserva y se informa (H-A) |
| | resto (`source_type`, `status`, `consent_request_source`, `*_role`, instantes) | N | — |
| `voice_rne_checks` (solo inserción) | `phone_hash` | **H** | `vrne_phone_chk` (no hay 4 dígitos en esta tabla) |
| | `evidence_ref` | T | Por huella, si no casa, se informa (es la prueba de la consulta al RNE) |
| | `actor_name` | T + A | — |
| | resto (`result`, `method`, `actor_type`, instantes, `contact_id`) | N | — |
| `voice_calls` | `remote_hash`, `remote_last4` | **H** | Solo llamadas TERMINADAS (una viva —requested, ambiguous, dialing, active— aborta). `vc_remote_chk`: los dos puestos (una saliente los exige) |
| | `requested_by_name` | T (solo `requested_by_type = 'admin'`) + A | Filtro de filas NUEVO: la de una estrategia (V9) no es un dato personal y su CHECK no admite «[suprimido]» |
| | `provider_conversation_id`, `provider_call_sid` | **I** (por huella) | Punteros a lo que guardan ElevenLabs y Twilio: se borra allí (paso del runbook por confirmar, D8-17) |
| | resto (sello, huellas de compilación, estados, códigos, costes, `canary_number_uuid`, `voice_contact_id`, instantes) | N | — |
| `voice_call_events` (solo inserción) | `codes` | T | Códigos (≤ 4 KB, `vce_codes_chk`); casi nunca casará |
| | `actor_name` | T + A | `vce_actor_chk` lo exige con `actor_type = 'admin'` |
| | resto (`provider`, `kind`, `provider_event_key`, `tool`, `outcome`, `actor_type`, instantes) | N | `provider_event_key` y `tool`: neutras solo en esta tabla |
| `voice_consents` (solo inserción) | `evidence` | T | Por huella, si no casa, se informa: es **la prueba de la autorización** (H-C) |
| | `recorded_by_name`, `confirmed_by_name` | T + A | `vcs_four_eyes_chk` exige `confirmed_by_name` → «[suprimido]» la cumple |
| | resto (`kind`, `channel`, `purpose`, `source`, `actor_type`, instantes, `contact_id`) | N | — |
| `voice_exclusions` (solo inserción) | `phone_hash`, `phone_last4` | **H** | `vex_phone_chk`. **La exclusión deja de aplicarse a ese número** (D8-18: «solo la quita el procedimiento de supresión»; H-C) |
| | `note` | T | Por huella, si no casa, se informa |
| | `actor_name` | T + A | — |
| | resto (`source`, `actor_type`, ids, instantes) | N | — |
| `voice_brakes` (solo inserción) | `note`, `evidence` | T | `vbr_shape_chk` exige `note` al soltar → «[suprimido]» la cumple; `evidence` son códigos (≤ 4 KB) |
| | `actor_name` | T + A | — |
| | resto (`kind`, `reason`, `open_key`, `actor_type`, ids, instantes) | N | `reason` y `open_key`: neutras solo en esta tabla |

**Lo que nunca se toca**: la cadena firmada (`effective_json`, `effective_hash`, `signature`, `prev_hash`), las huellas
de la compilación, el libro (solo recibe su fila nueva), las instantáneas de configuración (`content`, destinos
compilados), los ids y las FKs, y la definición de cualquier disparador, función, CHECK o privilegio.

**Disparadores**: se apagan SOLO los de UPDATE (`tgtype & 16`) que saltarían con las columnas que nombra el SET (los de
toda la fila y los `UPDATE OF` que incluyen una de ellas), solo si su función es una guarda conocida
(`ultron_studio_forbid`, `ultron_studio_lab_forbid`, `outreach_forbid`, **`voice_forbid`**; otra función aborta), dentro de
la transacción, y se reactivan exactamente esos; los de INSERT con función propia ni se miran. En la redacción real de la
prueba se apagaron, por tabla: `voice_contacts` → `vct_forbid_immutable_update`, `vct_retired_is_final`; `voice_calls` →
`vc_forbid_immutable_update` (no `vc_status_forward` ni `vc_bindings_once`); `voice_exclusions` → `vex_forbid_update`;
`voice_rne_checks` → `vrne_forbid_update`; `voice_consents` → `vcs_forbid_update`; `voice_brakes` → `vbr_forbid_update`;
`ultron_studio_voice_publications` → `usvpub_forbid_update`; perfiles → `usvp_reviewed_is_final`; sesiones →
`usvl_closed_is_final`. Activos antes y después: 205 (Studio + outreach + voz) en la base de la prueba.

## 4. Diferencias con la propuesta de OPS8-d (`supresion-1581-voz.propuesta.sql`)

1. **El cifrado no se escribe**: un contacto retirado ya lo tiene a NULL (`vct_cipher_chk`); escribirlo solo apagaba
   `vct_cipher_only_shrinks` sin necesidad.
2. **`lab_gate`** de las publicaciones de voz entra en la política (T), como el del H5; la propuesta no lo nombraba.
3. **Filtro de filas** en `voice_calls.requested_by_name` (`requested_by_type = 'admin'`): con V9, la propuesta habría
   abortado toda la supresión al casar un término con la clave de una estrategia (M3).
4. **Lo vivo se avisa en seco y aborta en real** (patrón del H9), en vez de abortar también en seco: la pasada en seco
   enseña el inventario completo. Con algo vivo, la redacción real aborta ANTES de tocar nada; y, si algo se volviera
   vivo entre el inventario y el UPDATE, la comprobación final (0 filas con la huella) lo deshace todo (M2).
5. **Se informa por huella** lo que la propuesta no veía: la huella en la configuración compilada (perfil y agente),
   los textos de esa persona que no casan con `:term`/`:phone`, los enlaces al motor (`source_id`) y los punteros a los
   proveedores; todo, en recuentos, en la fila del libro (clave nueva `voice_informed`).
6. **Guardas de entrada nuevas**: `:voice_phone_hash` mal formado (22023), igual que `:phone_hash` (22023: una de las dos
   está mal calculada) o sin las tablas de la voz (42P01).
7. **Comprobación de dueño antes de redactar**: toda tabla con coincidencias tiene que ser del dueño (42501) — antes
   fallaba igual, pero a mitad de camino; mismo resultado (nada cambia), mensaje claro.
8. **Neutras por tabla** (`v_neutras_tc`): `reason`, `result`, `method`, `tool`, `scope`, `part`, `open_key` y
   `provider_event_key` son códigos SOLO en su tabla de la voz; marcarlas neutras por nombre habría silenciado el aviso de
   una columna de texto libre con ese nombre en una tabla futura.
9. **Paso 2b del runbook** escrito en la cabecera del guion y probado en local (la propuesta lo dejaba como idea).
10. **La cabecera decía ACCESS EXCLUSIVE**: es SHARE ROW EXCLUSIVE (corregido).

## 5. Cambios por archivo

| Archivo | Cambio |
|---|---|
| `docs/ultron-studio/ops/supresion-1581.sql` | +304/−31, 1036 líneas (sha256 `6c4af2f5832a8245857dddabe8a3a560796d19666f62ad4d568ac1fd6a218520`, el mismo que pasó la prueba final). Cabecera «HITO 8 · LA VOZ» y paso 2b; `\set voice_phone_hash`; política (+35 columnas, 1 con filtro de filas), autoría (+17), neutras (+34 por nombre, +8 por tabla); guardas de entrada; las tres pasadas con `voice\_%` (también en el bloque del H9); inventario y redacción por huella (3b-voz); aborto con lo vivo; dueño de cada tabla tocada; `voice_forbid` en la lista blanca; comprobación final por huella; `voice_informed` en el libro; el mensaje final con `voice:doctor --telefonia`. Corrige la nota del cerrojo |
| `tests/Feature/Telephony/Schema/VoiceSuppression1581Test.php` | **Nuevo** (sha256 `ac4fbd6de6ce51eb0923d7619ff216f6dc7127e6ced337f04691c44c7bbafc91`). Una prueba de punta a punta, solo PG (en SQLite se salta), que ejecuta el `.sql` del repo con `psql`. Pint: pasa |
| `docs/ultron-studio/ops/supresion-1581-voz.propuesta.sql` | +7/−0: solo una cabecera, «INTEGRADA, no aplicar otra vez», con el puntero a §4 de este informe |
| `docs/ultron-studio/ops/evidencia-h8/2026-11-10/db-1581-voz/` | **Nuevo**: las 9 salidas de psql de la prueba y su `phpunit.log`, el A/B en seco (3 bases) y real (`e2e`), `mutaciones.md` y `ab-1581.sh` (portable; compara con `d701b0c8`). Ninguna salida lleva datos ni huellas (comprobado con `grep`) |
| `docs/ultron-studio/plans/informes-h8/informe-db-1581-voz.md` | Este informe |

No he tocado ninguna migración, modelo, servicio ni los ficheros de otros agentes (`app/Services/Outreach`,
`OutboundGate.php`, `VoiceContacts.php`, `app/Services/Voice`, `ops/deploy-h8-*`, `ops/smoke-h8.py`,
`ops/h8-voice-scope.sh`).

## 6. Impacto

- **Mejora**: una solicitud Ley 1581 ya alcanza la voz (antes, cero filas). La salida enseña lo que no se puede redactar
  y por qué, en vez de callarlo.
- **H3–H7 y H9, sin cambios de comportamiento** (A/B §9): las mismas líneas, los mismos totales y, en real, las mismas
  filas. Lo único que cambia fuera de la voz: el recuento de disparadores activos del encabezado incluye la voz (p. ej.
  174 → 201 en `h9_e2e`), el aviso de columnas mira también `voice_*`, la fila del libro lleva la clave nueva
  `voice_informed` (`{}` sin voz), y `language` pasa a neutra (silencia 1 de los 6 avisos de la tabla de W9: es un código).
- **Escrituras y espacio**: ninguno fuera de la ejecución (no hay índices ni migraciones). Durante una redacción real,
  cada tabla tocada queda en SHARE ROW EXCLUSIVE hasta el COMMIT: las escrituras de la aplicación en esa tabla esperan
  (milisegundos con los volúmenes de hoy); las lecturas no.
- **Planes de ejecución**: no medidos. Las búsquedas por texto son barridos secuenciales (`~*` sobre cada columna de la
  política), como en el H3–H9; por huella, `voice_calls` (`vc_remote_idx`), `voice_rne_checks` (`vrne_phone_idx`) y
  `voice_exclusions` (`vex_phone_idx`) tienen índice que empieza por la huella; `voice_contacts.phone_hash` solo por el
  único parcial `vct_one_live` (vivos) → barrido de los retirados. Con las tablas casi vacías no hay nada que medir (un
  EXPLAIN sobre tablas vacías no demuestra nada); a vigilar si `voice_contacts` crece a decenas de miles.

## 7. Riesgos y reversibilidad

- **Irreversible por diseño**: la huella aleatoria no se puede «desredactar» (como la del canario). La única vuelta es
  el respaldo previo (que sigue llevando el dato hasta que caduca: el runbook ya lo exige).
- **Todo o nada**: una transacción; cualquier fallo (CHECK, cerrojo, interbloqueo, algo vivo, coincidencias que quedan,
  disparadores que no cuadran) la deshace entera y no deja ningún disparador apagado (demostrado con M1–M3).
- **Interbloqueo con la aplicación** (no probado con carga): si la aplicación escribe en dos tablas de la voz dentro de
  una transacción mientras el guion las va bloqueando en otro orden, PostgreSQL aborta a una de las dos (40P01). Si cae
  el guion: nada cambió, se repite. Si cae la aplicación: esa petición falla (p. ej. un webhook; el proveedor reintenta).
  Mitigación: ejecutar fuera del horario de llamadas, con las salientes paradas.
- **Duración estimada**: milisegundos con los volúmenes de hoy (la prueba entera, con 27 filas de voz y 254 tablas,
  hace cada pasada en < 1 s); `lock_timeout` 5 s y `statement_timeout` 120 s como antes.
- **Rollback del cambio de código**: es un fichero de `ops/` y una prueba; volver a la versión anterior es `git checkout`
  del fichero (no hay esquema que revertir).

## 8. Operaciones pendientes de autorización

Ninguna para cerrar B-T3: no hay migración ni nada que ejecutar en producción. Cuando llegue una solicitud real, la
ejecuta el operador como root con la orden del propietario, como el H7 (§10 del runbook): en seco, respaldo fresco, y
después `\set dry 0`. Para la voz, antes:

```bash
# paso 2b (la huella de voz sin que el número pase por la pantalla, la línea de órdenes ni el historial)
install -m 600 /dev/null /root/supresion/<radicado>.tel      # el E.164 dentro, una línea, con un editor
sudo -u www-data php /var/www/api/backend/artisan tinker --execute='echo App\Services\Telephony\VoicePhone::hash(trim((string) fgets(STDIN))), PHP_EOL;' \
  < /root/supresion/<radicado>.tel 2>/dev/null | tail -1 > /root/supresion/<radicado>.vhash     # con umask 077
printf "\\set voice_phone_hash '%s'\n" "$(cat /root/supresion/<radicado>.vhash)" >> /root/supresion/<radicado>.psql
shred -u /root/supresion/<radicado>.tel /root/supresion/<radicado>.vhash
# después, como siempre: cat /root/supresion/<radicado>.psql /root/supresion-1581.sql | sudo -u postgres psql -X -q -d ironbody
```

Propuesta (NO aplicada; decide el lead con architect y backend) para poder suprimir también el enlace al motor (H-A):

```sql
-- migración nueva, DROP + ADD en una sentencia (patrón V9), con lock_timeout; las filas actuales cumplen el CHECK nuevo
ALTER TABLE voice_contacts DROP CONSTRAINT vct_source_chk, ADD CONSTRAINT vct_source_chk CHECK (
  (source_type IN ('lead', 'member') AND (source_id IS NOT NULL OR status = 'retired'))
  OR (source_type = 'manual' AND source_id IS NULL));
-- y en supresion-1581.sql (3b-voz, voice_contacts): SET …, source_id = NULL (solo retirados), con su prueba.
```

## 9. Qué verifiqué ejecutando de verdad

| Qué | Orden | Resultado |
|---|---|---|
| La prueba nueva en PG 14 | `UNIT_DB=ultron_studio_1581_voz docs/ultron-studio/ops/pgunit.sh --no-logging tests/Feature/Telephony/Schema/VoiceSuppression1581Test.php` | **OK (1 test, 1119 assertions)**, 91 s la primera vez y 34 s la final (migrar desde cero domina) |
| Que la prueba no es vacía | 5 mutaciones del guion (`evidencia-…/mutaciones.md`), una cada vez, con copia previa y `cmp` tras restaurar | **5/5 detectadas**: M1 sin `voice_forbid` (paso 4), M2 sin aborto por lo vivo (paso 2; la comprobación final deshizo todo igualmente), M3 sin filtro de estrategias (paso 7, `vc_requester_chk`), M4 una columna sin política (paso 1), M5 redacción de filas ajenas (paso 4c) |
| Lo que la prueba recorre | sus pasos 0–8 | En seco H3–H7 (libro) y H9 (`subject`) siguen pasando; en seco de la voz, 15 campos, 2 «VIVO», 0 columnas de la voz sin política, nada cambia; con algo vivo, aborta (rc 3) sin cambiar nada ni dejar disparadores apagados; la aplicación (su rol) retira y cancela; redacción real **conectado como el dueño sin superusuario**: 0 filas con la huella, 8 huellas aleatorias distintas, `0000`, cifrado NULL, textos redactados y JSON válido, lo informado intacto, solo cambian las filas de esa persona (fila a fila), fuera de la voz solo +1 fila del libro, mismos disparadores (51 de la voz activos), CHECK y privilegios, la aplicación sigue sin UPDATE/DELETE en las 6 de solo inserción, la cadena de voz verifica (`ok`) antes y después; repetir → 0 campos; personal (`admin_id` + teléfono de transferencia, por superusuario + `SET LOCAL ROLE`): sus 10 instantáneas a «[suprimido]», las 22 de la otra persona intactas, su tramo redactado, perfil y agente informados; estrategia (V9) intacta; entradas mal formadas abortan. Ninguna salida de psql ni la fila del libro llevan nombre, número ni huella |
| H3–H7 y H9 en seco, antes y después | `ab-1581.sh seco` contra `banco_h8_ops8d` (h5_owner), `e2e` (pre-H8) y `h9_e2e` (con `subject`) | rc 0/0 en las tres; **0 líneas** del H3–H9 distintas; totales 94 = 94 (`e2e`), 38 = 38 (`h9_e2e`), 16 → 24 en `ops8d` (las 8 instantáneas del personal en la voz); 32 avisos de la voz → 0 |
| H3–H7 en REAL, antes y después | `ab-1581.sh real ultron_studio_e2e 7` (dos copias desechables, borradas al terminar) | rc 0/0; **226 tablas, 0 distintas** (salvo la huella aleatoria del canario y la fila nueva del libro); 94 campos en 6 tablas en las dos; metadata del libro igual salvo `voice_informed: {}`; 0 disparadores apagados |
| La guarda 1581 del H9 y la prueba en SQLite | `vendor/bin/phpunit tests/Feature/Outreach/Guards/OutreachPersonalDataGuardTest.php tests/Feature/Telephony/Schema/VoiceSuppression1581Test.php` | 4/4 la guarda; la nueva, **saltada** (solo PG, por diseño) |
| Estilo | `vendor/bin/pint --test` sobre la prueba | `passed` (Pint no aplica al SQL) |
| El cerrojo de `DISABLE TRIGGER` | `BEGIN; ALTER TABLE voice_exclusions DISABLE TRIGGER …; SELECT mode FROM pg_locks …; ROLLBACK;` | `ShareRowExclusiveLock` |
| El paso 2b | `tinker --execute='… VoicePhone::hash(trim(fgets(STDIN))) …' < fichero` en local | 64 hex, igual a la clase directa, distinta de la del canario |

## 10. Qué NO verifiqué, y por qué

- **Producción**: nada (ni volúmenes, ni roles reales, ni `log_statement`). No se pidió y no hay autorización.
- **Los ensayos de banco originales** (`banco-h7/ensayo-supresion.sh` 17/17 y `evidencia-h9/…/fixture-1581.sh`): no están
  montados (`banco-h7` no existe en el clúster; montarlo clona y levanta la API) y `ultron_studio_h9_e2e` estaba en uso
  por el E2E de otro agente (3 conexiones vivas), así que no la copié. Los cubren el A/B en seco (3 bases) y real
  (`e2e`) y el paso 0 de la prueba; la sección del H9 en REAL no la he vuelto a ejecutar (su código no cambia salvo los
  recuentos de disparadores).
- **Concurrencia real** (interbloqueo con la aplicación, una llamada que se vuelve viva a mitad): solo por diseño y por
  M2; no hay prueba con dos conexiones.
- **El borde de 4 KB** de `codes`/`evidence` tras redactar (la normalización de jsonb puede alargar el texto): sin
  prueba; si pasara, el CHECK abortaría todo (seguro, pero bloquea esa solicitud).
- **El borrado en los proveedores** (ElevenLabs, Twilio): fuera de SQL; el endpoint está «por confirmar» (D8-17).
- **El ensayo como `ironbody-migrate`**: la prueba demuestra que basta el dueño sin superusuario (conectado como él);
  no he usado el envoltorio `ironbody-migrate` ni `/root/.ironbody-owner.env` (no existen en local).

## 11. Hallazgos para otros dueños

| # | Hallazgo | Dueño | Cuándo |
|---|---|---|---|
| H-A | `voice_contacts.source_id` (el lead o socio del motor) no se puede suprimir: `vct_source_chk` no admite una forma suprimida. Se informa. Si la persona sigue siendo socia, su historial de llamadas queda enlazado a ella. Propuesta exacta en §8 | lead → architect + backend (¿algo lee `source_id` de un retirado?) | antes de la fase 4b |
| H-B | `ultron_studio_template_meta_checks` (W9, migración sin commit) tiene 5 columnas de texto sin política (`recorded_by_name` es un nombre del personal: T + A; las otras, neutras): 5 avisos en cada pasada | W9 / OPS9 | cuando la migración se confirme |
| H-C | Jurídico, para el propietario: (1) redactar una exclusión hace que deje de aplicarse a ese número (D8-18 lo quiere así); (2) la evidencia de un consentimiento es la prueba de la autorización (la normativa pide conservarla —Decreto 1377 de 2013, hoy en el 1074 de 2015—, a confirmar con su asesoría): por eso, si no casa con el texto, se informa y no se borra entera | propietario + security | antes de la primera solicitud real de un tercero |
| H-D | El borrado de las conversaciones en ElevenLabs y de los registros en Twilio no lo hace SQL: la fila del libro deja cuántas hay (`voice_informed`); falta el paso del runbook | devops + backend | antes de la fase 4b |
| H-E | El runbook (§10 de `plans/informes-h7/informe-devops-h7.md`) y la fila de `ops/README.md` siguen diciendo «14 tablas»: añadir el parámetro `voice_phone_hash`, el paso 2b, las dos huellas de un mismo número, `voice_informed` y `voice:doctor --telefonia` tras el COMMIT | devops / DOC | con este cambio |
| H-F | Ejecutar una supresión real fuera del horario de llamadas (interbloqueo, §7) | devops (runbook) | con H-E |
| H-G | La prueba deja en el clúster local la base `ultron_studio_1581_voz` y los roles `ultron_studio_1581_owner` y `ultron_studio_1581_app` (sin superusuario), como las de otros agentes | — | informativo |
| H-H | Para tester: casos que solo se ven desde la base y no cubre la prueba: carrera «llamada que pasa a viva entre el inventario y el UPDATE» (debe abortar por la comprobación final), interbloqueo con un webhook concurrente, y `codes`/`evidence` cerca de 4 KB | tester | antes de la 4b |

**security** debería revisar H-A y H-C (datos personales de terceros) antes de la fase 4b.

## 12. Reproducir

```bash
cd Iron-Body/backend
UNIT_DB=ultron_studio_1581_voz docs/ultron-studio/ops/pgunit.sh --no-logging tests/Feature/Telephony/Schema/VoiceSuppression1581Test.php
#   (crea la base antes: psql -h 127.0.0.1 -p 55432 -U ironbody -d postgres -c 'CREATE DATABASE ultron_studio_1581_voz')
#   con SUPRESION_1581_LOG_DIR=<dir> guarda cada salida de psql
E=docs/ultron-studio/ops/evidencia-h8/2026-11-10/db-1581-voz
bash $E/ab-1581.sh seco ultron_studio_banco_h8_ops8d h5_owner 2 0
bash $E/ab-1581.sh seco ultron_studio_e2e ironbody 7 0
bash $E/ab-1581.sh seco ultron_studio_h9_e2e h9e2e_owner 1 1     # solo si nadie la está usando
bash $E/ab-1581.sh real ultron_studio_e2e 7                       # dos copias desechables, que borra al terminar
```
