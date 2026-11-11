# Mutaciones de `supresion-1581.sql` contra `VoiceSuppression1581Test` (10-11-2026)

Cada mutación rompe a propósito una pieza de la sección de la voz, se ejecuta la prueba en PostgreSQL 14
(`UNIT_DB=ultron_studio_1581_voz docs/ultron-studio/ops/pgunit.sh tests/Feature/Telephony/Schema/VoiceSuppression1581Test.php`)
y se restaura el guion (copia previa + `cmp` igual después de cada una). La prueba tiene que caer, y caer en el paso que
protege esa pieza. Las cinco cayeron; el guion final (sha256 en el informe) pasa: 1 prueba, 1119 aserciones.

| # | Mutación (expresión `perl -0pi -e` sobre el guion) | Paso que cae | Lo que se vio |
|---|---|---|---|
| M1 | Quitar `voice_forbid` de la lista blanca: `s/, 'outreach_forbid', 'voice_forbid'\)/, 'outreach_forbid')/` | 4 (redacción real) | `ERROR: supresión: voice_brakes.vbr_forbid_update es un disparador de UPDATE con otra función (voice_forbid) …`: aborta sin tocar nada |
| M2 | Quitar el aborto por filas vivas (`IF v_voz_vivos > 0 THEN RAISE …`) | 2 (con algo vivo) | Falla el mensaje «2 fila(s) VIVAS…»; la SEGUNDA red funciona: `ERROR: supresión: tras redactar siguen 2 coincidencias: se deshace todo` (el contacto vivo y la llamada viva no se redactan y la comprobación final lo deshace todo) |
| M3 | Quitar el filtro de filas de `voice_calls.requested_by_name` (`"requested_by_type = ''admin''"`) | 7 (llamada de una estrategia) | `ERROR: new row for relation "voice_calls" violates check constraint "vc_requester_chk"`: el nombre «Estrategia …» redactado no cumple el CHECK de V9; todo se deshace |
| M4 | Quitar `["voice_contacts", "retire_reason", "r"]` de la política | 1 (en seco) | Aparece `WARNING: columna sin política …: voice_contacts.retire_reason` y la aserción «toda columna de texto de la voz tiene política» cae |
| M5 | La redacción por huella de `voice_exclusions` con `WHERE phone_hash IS NOT NULL` en vez de `= v_vhash` | 4c (solo cambian las filas de esa persona) | `voice_exclusions: cambian solo las filas esperadas` cae: la exclusión de otra persona cambió (la comprobación final del guion NO lo ve: solo busca la huella pedida; lo ve la prueba) |

M5 es la razón de la foto fila a fila de la prueba (`assertChangedRows`): el guion comprueba que no queda la huella
pedida, no que no tocó otras filas; eso solo se demuestra comparando antes y después.
