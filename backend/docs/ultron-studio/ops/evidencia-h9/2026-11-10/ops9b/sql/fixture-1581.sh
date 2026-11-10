#!/bin/bash
# Ensayo de la supresión 1581 del H9 en el banco (OPS9-b): filas sintéticas en las 5 tablas de la ejecución y una
# estrategia (como superusuario, con TODOS los disparadores apagados solo durante la inserción: también los de FK, así no
# hace falta una estrategia publicada), después el SQL del repo como el dueño h5_owner: en seco → aborto por filas vivas
# → cierre de lo vivo → redacción real → comprobaciones → la aplicación en seco. Solo en el banco.
# Uso: bash fixture-1581.sh <banco> <base> <admin_id de smoke> <puerto de la valla> [<uid del socio sintético>]
set -u
export PGGSSENCMODE=disable
B=$1; BANCO_DB=$2; ADMIN_ID=$3; PUERTO_VALLA=$4
SQL=/Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/ultron-h8/Iron-Body/backend/docs/ultron-studio/ops/supresion-1581.sql
P=(psql -X -q -v ON_ERROR_STOP=1 -h 127.0.0.1 -p 55432 -U ironbody -d "$BANCO_DB")
A=(psql -X -Atq -v ON_ERROR_STOP=1 -h 127.0.0.1 -p 55432 -U ironbody -d "$BANCO_DB")
UID_=${5:-$("${A[@]}" -c "SELECT min(id) FROM users WHERE email LIKE 'sintetica-%@banco.invalid';")}
[ -n "$UID_" ] || { echo "sin socios sintéticos: sembrar --personas=N"; exit 1; }
H() { printf "$1%.0s" $(seq 1 64); }
REF=$(H d)
apagados() { "${A[@]}" -c "SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid WHERE NOT t.tgisinternal AND (c.relname LIKE 'outreach\_%' OR c.relname LIKE 'ultron\_studio\_%') AND t.tgenabled <> 'O'"; }
echo "== 0. fixture para m:$UID_ (sin imprimir nada más de la persona) · admin de smoke: $ADMIN_ID · base $BANCO_DB"
"${P[@]}" <<SQL
BEGIN;
ALTER TABLE outreach_enrollments DISABLE TRIGGER ALL; ALTER TABLE outreach_jobs DISABLE TRIGGER ALL;
ALTER TABLE outreach_decisions DISABLE TRIGGER ALL; ALTER TABLE outreach_outcomes DISABLE TRIGGER ALL;
ALTER TABLE outreach_budget_counters DISABLE TRIGGER ALL; ALTER TABLE ultron_studio_strategies DISABLE TRIGGER ALL;
INSERT INTO outreach_enrollments (uuid, strategy_id, strategy_version_id, content_hash, publication_seq, part, studio_ref_kind, studio_content_hash, catalog_version, evaluator_version, mode, subject_key, subject_ref, member_id, cycle_key, purpose, status, step_index, touches, enrolled_at, window_ends_at, status_changed_at, created_at)
VALUES (gen_random_uuid(), 1, 1, '$(H a)', 1, 'rest', 'baseline', '$(H b)', 'outreach-catalog/2026-11-12.1', 'outreach-evaluator/1', 'shadow', 'm:$UID_', '$(H c)', $UID_, 'ciclo-1', 'commercial', 'active', 0, 0, now() - interval '2 days', now() + interval '10 days', now(), now()) RETURNING id \gset e_
INSERT INTO outreach_jobs (uuid, enrollment_id, strategy_id, strategy_version_id, content_hash, publication_seq, part, studio_ref_kind, studio_content_hash, catalog_version, evaluator_version, mode, subject_key, subject_ref, phone_ref, step_index, attempt, channel, idempotency_key, status, claimed_at, not_before, expires_at, budget_day, reserved_units, reserved_micros, status_changed_at, created_at)
VALUES (gen_random_uuid(), :e_id, 1, 1, '$(H a)', 1, 'rest', 'baseline', '$(H b)', 'outreach-catalog/2026-11-12.1', 'outreach-evaluator/1', 'shadow', 'm:$UID_', '$(H c)', '$REF', 0, 1, 'simulated', '$(H e)', 'simulated', now() - interval '1 hour', now() - interval '2 hours', now() + interval '1 hour', current_date, 0, 0, now(), now()) RETURNING id \gset j1_
INSERT INTO outreach_jobs (uuid, enrollment_id, strategy_id, strategy_version_id, content_hash, publication_seq, part, studio_ref_kind, studio_content_hash, catalog_version, evaluator_version, mode, subject_key, subject_ref, phone_ref, step_index, attempt, channel, idempotency_key, status, not_before, expires_at, budget_day, reserved_units, reserved_micros, status_changed_at, created_at)
VALUES (gen_random_uuid(), :e_id, 1, 1, '$(H a)', 1, 'rest', 'baseline', '$(H b)', 'outreach-catalog/2026-11-12.1', 'outreach-evaluator/1', 'shadow', 'm:$UID_', '$(H c)', '$REF', 1, 1, 'simulated', '$(H 7)', 'planned', now() + interval '1 hour', now() + interval '2 hours', current_date, 0, 0, now(), now()) RETURNING id \gset j2_
INSERT INTO outreach_decisions (uuid, enrollment_id, strategy_id, strategy_version_id, content_hash, publication_seq, part, studio_ref_kind, studio_content_hash, catalog_version, evaluator_version, mode, subject_key, subject_ref, kind, reasons, facts_json, facts_hash, trace_json, trace_hash, evaluated_at, created_at)
VALUES (gen_random_uuid(), :e_id, 1, 1, '$(H a)', 1, 'rest', 'baseline', '$(H b)', 'outreach-catalog/2026-11-12.1', 'outreach-evaluator/1', 'shadow', 'm:$UID_', '$(H c)', 'enroll', '[]', '{}', '$(H f)', '[]', '$(H 1)', now(), now());
INSERT INTO outreach_outcomes (uuid, enrollment_id, strategy_id, job_id, subject_key, subject_ref, channel, outcome, source, source_key, deterministic, occurred_at, created_at)
VALUES (gen_random_uuid(), :e_id, 1, :j1_id, 'm:$UID_', '$(H c)', 'simulated', 'not_attempted', 'simulated', 'sim-1', true, now(), now());
INSERT INTO outreach_budget_counters (budget_day, scope, mode, cap_units, cap_micros, reserved_units, reserved_micros, created_at)
VALUES (current_date, 'phone:$REF', 'shadow', 5, 0, 1, 0, now());
INSERT INTO ultron_studio_strategies (uuid, client_request_id, key, name, purpose, draft_json, draft_revision, created_by_admin_id, created_by_name, created_at)
VALUES (gen_random_uuid(), gen_random_uuid(), 'prueba_1581', 'Prueba Persona Sintetica', 'commercial', '{"name": "Persona Sintetica", "steps": []}', 1, $ADMIN_ID, 'Persona Sintetica', now());
ALTER TABLE outreach_enrollments ENABLE TRIGGER ALL; ALTER TABLE outreach_jobs ENABLE TRIGGER ALL;
ALTER TABLE outreach_decisions ENABLE TRIGGER ALL; ALTER TABLE outreach_outcomes ENABLE TRIGGER ALL;
ALTER TABLE outreach_budget_counters ENABLE TRIGGER ALL; ALTER TABLE ultron_studio_strategies ENABLE TRIGGER ALL;
COMMIT;
SQL
echo "rc=$? · filas con la clave (enr jobs dec out): $("${A[@]}" -c "SELECT (SELECT count(*) FROM outreach_enrollments WHERE subject_key='m:$UID_')||' '||(SELECT count(*) FROM outreach_jobs WHERE subject_key='m:$UID_')||' '||(SELECT count(*) FROM outreach_decisions WHERE subject_key='m:$UID_')||' '||(SELECT count(*) FROM outreach_outcomes WHERE subject_key='m:$UID_')||' · contadores '||(SELECT count(*) FROM outreach_budget_counters WHERE scope='phone:$REF')||' · estrategias con el texto '||(SELECT count(*) FROM ultron_studio_strategies WHERE name ~* 'Persona Sintetica')") · disparadores apagados: $(apagados)"
LIBRO0=$("${A[@]}" -c "SELECT count(*) FROM ultron_studio_audit_events WHERE event='system.personal_data_redacted'")
correr() { psql -X -q -h 127.0.0.1 -p 55432 -U ironbody -d "$BANCO_DB" -v owner=h5_owner -v radicado=OPS9B-ENSAYO "$@" < "$SQL" 2>&1 | grep -v '^$'; echo "rc=${PIPESTATUS[0]}"; }
echo; echo "== 1. en seco, subject (con filas vivas: el aviso)"; correr -v dry=1 -v subject="m:$UID_"
echo; echo "== 2. redacción con filas VIVAS: tiene que abortar (55000) sin cambiar nada"; correr -v dry=0 -v subject="m:$UID_" | grep -E 'ERROR|rc=|ROLLBACK|CONFIRMADA'
echo "   filas vivas con la clave tras el aborto: $("${A[@]}" -c "SELECT (SELECT count(*) FROM outreach_enrollments WHERE subject_key='m:$UID_' AND status='active')+(SELECT count(*) FROM outreach_jobs WHERE subject_key='m:$UID_' AND status='planned')") (esperado 2) · disparadores apagados: $(apagados)"
echo; echo "== 3. la aplicación para lo vivo (aquí a mano, como superusuario: lo que hace outreach:suppress-subject --apply)"
"${P[@]}" <<SQL
BEGIN;
ALTER TABLE outreach_enrollments DISABLE TRIGGER ALL; ALTER TABLE outreach_jobs DISABLE TRIGGER ALL;
UPDATE outreach_enrollments SET status = 'exited', ended_at = now(), exit_reason = 'suppressed_1581', status_changed_at = now() WHERE subject_key = 'm:$UID_' AND status = 'active';
UPDATE outreach_jobs SET status = 'canceled', cancel_reason = 'suppressed_1581', status_changed_at = now() WHERE subject_key = 'm:$UID_' AND status = 'planned';
ALTER TABLE outreach_enrollments ENABLE TRIGGER ALL; ALTER TABLE outreach_jobs ENABLE TRIGGER ALL;
COMMIT;
SQL
echo "rc=$?"
echo; echo "== 4. redacción REAL (dry 0) como h5_owner: term (bloque A sobre ultron_studio_strategies) + subject (bloque B)"; correr -v dry=0 -v subject="m:$UID_" -v term='Persona Sintetica'
echo; echo "== 5. comprobaciones"
"${A[@]}" -c "SELECT 'con la clave: '||((SELECT count(*) FROM outreach_enrollments WHERE subject_key='m:$UID_')+(SELECT count(*) FROM outreach_jobs WHERE subject_key='m:$UID_')+(SELECT count(*) FROM outreach_decisions WHERE subject_key='m:$UID_')+(SELECT count(*) FROM outreach_outcomes WHERE subject_key='m:$UID_'))||' (esperado 0) · forma suprimida enr/jobs/dec/out: '||(SELECT count(*) FROM outreach_enrollments WHERE subject_key = 'x:'||id AND subject_ref = repeat('0',64) AND member_id IS NULL AND redacted_at IS NOT NULL)||'/'||(SELECT count(*) FROM outreach_jobs WHERE subject_key = 'x:'||id AND subject_ref = repeat('0',64) AND phone_ref IS NULL AND redacted_at IS NOT NULL)||'/'||(SELECT count(*) FROM outreach_decisions WHERE subject_key = 'x:'||id AND redacted_at IS NOT NULL)||'/'||(SELECT count(*) FROM outreach_outcomes WHERE subject_key = 'x:'||id AND redacted_at IS NOT NULL)||' (esperado 1/2/1/1) · contadores: '||(SELECT count(*) FROM outreach_budget_counters WHERE scope='phone:$REF')||' (esperado 0) · estrategias con el texto: '||(SELECT count(*) FROM ultron_studio_strategies WHERE name ~* 'Persona Sintetica' OR created_by_name ~* 'Persona Sintetica' OR draft_json::text ~* 'Persona Sintetica')||' (esperado 0) · estrategia redactada: '||(SELECT count(*) FROM ultron_studio_strategies WHERE name = 'Prueba [suprimido]' AND created_by_name = '[suprimido]')||' (esperado 1)';"
"${A[@]}" -c "SELECT 'libro: '||(count(*) - $LIBRO0)||' fila(s) nueva(s) system.personal_data_redacted (esperado 2: bloque A y bloque B)' FROM ultron_studio_audit_events WHERE event='system.personal_data_redacted';"
"${A[@]}" -c "SELECT '   · '||left(summary, 150) FROM ultron_studio_audit_events WHERE event='system.personal_data_redacted' ORDER BY id DESC LIMIT 2;"
echo "disparadores apagados en outreach_* y ultron_studio_*: $(apagados) (esperado 0)"
echo; echo "== 6. la aplicación, en seco (outreach:suppress-subject m:$UID_): tiene que ver 0 filas"
( cd "$B/api/backend" && env PGGSSENCMODE=disable http_proxy=http://127.0.0.1:$PUERTO_VALLA https_proxy=http://127.0.0.1:$PUERTO_VALLA HTTP_PROXY=http://127.0.0.1:$PUERTO_VALLA HTTPS_PROXY=http://127.0.0.1:$PUERTO_VALLA no_proxy=127.0.0.1,localhost NO_PROXY=127.0.0.1,localhost php artisan outreach:suppress-subject "m:$UID_" --json 2>&1 | python3 -c 'import json,sys; d=json.load(sys.stdin); print("rows:", d.get("rows"), "liveJobs:", d.get("liveJobs"), "openEnrollments:", d.get("openEnrollments"), "phoneRefs:", d.get("phoneRefs"))' )
