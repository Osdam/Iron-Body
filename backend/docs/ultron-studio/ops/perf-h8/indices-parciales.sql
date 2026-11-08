-- PERF8 (Hito 8): ¿se usan los índices parciales de DB8 (E3-5)? Y la ganancia esperada del índice de P8-1.
--
--   psql -h 127.0.0.1 -p 55432 -U ironbody -d ultron_studio_h8_perf -f indices-parciales.sql > resultados/indices-parciales.txt
--
-- SOLO en la copia desechable `ultron_studio_h8_perf` (la base se comprueba). TODO va dentro de UNA transacción que
-- se DESHACE al final (volumen, ANALYZE e índice candidato incluidos): la copia queda como estaba.
-- Las sentencias son las de la aplicación con sus valores (Laravel prepara cada consulta: plan a medida, con los
-- valores a la vista del planificador, que es lo que permite usar un índice parcial).
\set ON_ERROR_STOP on
\pset pager off
SELECT current_database() = 'ultron_studio_h8_perf' AS es_la_mia \gset
\if :es_la_mia
\else
  \echo 'base no permitida: solo ultron_studio_h8_perf'
  \quit
\endif

BEGIN;
SELECT id AS llamada FROM voice_calls WHERE transfer_of_call_id IS NULL ORDER BY id DESC LIMIT 1 \gset
SELECT phone_hash AS huella_contacto FROM voice_contacts ORDER BY id LIMIT 1 \gset
SELECT remote_hash AS huella_viva FROM voice_calls WHERE status = 'ambiguous' ORDER BY id LIMIT 1 \gset

\echo '════ 1. Volumen de la siembra (30 exclusiones, 200 contactos), planificador natural ════'
\echo '── vex_one_opt_out_per_call: no_volver_a_llamar (VoiceTools.php:222)'
EXPLAIN (ANALYZE, BUFFERS, TIMING OFF) SELECT EXISTS (SELECT * FROM voice_exclusions WHERE call_id = :llamada AND source = 'spoken_opt_out');
\echo '── vex_phone_idx: VoiceCallFacts::excluded (VoiceCallFacts.php:61)'
EXPLAIN (ANALYZE, BUFFERS, TIMING OFF) SELECT EXISTS (SELECT * FROM voice_exclusions WHERE phone_hash = :'huella_contacto');
\echo '── vct_one_live: contacto vivo por huella'
EXPLAIN (ANALYZE, BUFFERS, TIMING OFF) SELECT id FROM voice_contacts WHERE phone_hash = :'huella_contacto' AND status <> 'retired';
\echo '── usva_one_canary / usva_one_rest: el agente que atiende cada parte'
EXPLAIN (ANALYZE, BUFFERS, TIMING OFF) SELECT spec_hash FROM ultron_studio_voice_agents WHERE serves_canary = true;
EXPLAIN (ANALYZE, BUFFERS, TIMING OFF) SELECT spec_hash FROM ultron_studio_voice_agents WHERE serves_rest = true;
\echo '── vc_one_live_outbound: la saliente viva de un número (ninguna consulta de la aplicación lo lee: guarda de unicidad)'
EXPLAIN (ANALYZE, BUFFERS, TIMING OFF) SELECT id FROM voice_calls WHERE remote_hash = :'huella_viva' AND direction = 'outbound' AND transfer_of_call_id IS NULL AND status IN ('requested', 'ambiguous', 'dialing', 'active');

\echo '════ 2. Mismo volumen con enable_seqscan = off: ¿el planificador PUEDE usarlos? ════'
SET LOCAL enable_seqscan = off;
EXPLAIN (ANALYZE, BUFFERS, TIMING OFF) SELECT EXISTS (SELECT * FROM voice_exclusions WHERE call_id = :llamada AND source = 'spoken_opt_out');
EXPLAIN (ANALYZE, BUFFERS, TIMING OFF) SELECT EXISTS (SELECT * FROM voice_exclusions WHERE phone_hash = :'huella_contacto');
EXPLAIN (ANALYZE, BUFFERS, TIMING OFF) SELECT id FROM voice_contacts WHERE phone_hash = :'huella_contacto' AND status <> 'retired';
EXPLAIN (ANALYZE, BUFFERS, TIMING OFF) SELECT spec_hash FROM ultron_studio_voice_agents WHERE serves_canary = true;
EXPLAIN (ANALYZE, BUFFERS, TIMING OFF) SELECT spec_hash FROM ultron_studio_voice_agents WHERE serves_rest = true;
EXPLAIN (ANALYZE, BUFFERS, TIMING OFF) SELECT id FROM voice_calls WHERE remote_hash = :'huella_viva' AND direction = 'outbound' AND transfer_of_call_id IS NULL AND status IN ('requested', 'ambiguous', 'dialing', 'active');
SET LOCAL enable_seqscan = on;

\echo '════ 3. Con volumen: 5 000 bajas orales (una por llamada) + 15 000 exclusiones manuales, 5 000 contactos más ════'
INSERT INTO voice_exclusions (uuid, phone_hash, phone_last4, source, call_id, actor_type, actor_name, created_at)
SELECT gen_random_uuid(), md5('perf-baja-' || c.id) || md5('perf-baja2-' || c.id), '0001', 'spoken_opt_out', c.id, 'system', 'Llamada de voz (no_volver_a_llamar)', now()
FROM voice_calls c WHERE c.transfer_of_call_id IS NULL AND c.status <> 'denied'
  AND NOT EXISTS (SELECT 1 FROM voice_exclusions e WHERE e.call_id = c.id AND e.source = 'spoken_opt_out')
ORDER BY c.id LIMIT 5000;
INSERT INTO voice_exclusions (uuid, phone_hash, phone_last4, source, actor_type, actor_admin_id, actor_name, created_at)
SELECT gen_random_uuid(), md5('perf-ex-' || g) || md5('perf-ex2-' || g), lpad((g % 10000)::text, 4, '0'), 'admin', 'admin', 1, 'Perf volumen', now()
FROM generate_series(1, 15000) AS g;
INSERT INTO voice_contacts (uuid, client_request_id, phone_hash, phone_last4, phone_encrypted, source_type, source_id, status, registered_by_admin_id, registered_by_name, status_changed_at, created_at)
SELECT gen_random_uuid(), gen_random_uuid(), md5('perf-ct-' || g) || md5('perf-ct2-' || g), lpad((g % 10000)::text, 4, '0'), 'cifrado-de-ficcion', 'manual', NULL, 'active', 1, 'Perf volumen', now(), now()
FROM generate_series(1, 5000) AS g;
ANALYZE voice_exclusions;
ANALYZE voice_contacts;
SELECT (SELECT count(*) FROM voice_exclusions) AS exclusiones, (SELECT count(*) FROM voice_contacts) AS contactos;
SELECT id AS llamada_con_baja FROM voice_exclusions WHERE source = 'spoken_opt_out' ORDER BY id DESC LIMIT 1 \gset
EXPLAIN (ANALYZE, BUFFERS, TIMING OFF) SELECT EXISTS (SELECT * FROM voice_exclusions WHERE call_id = (SELECT call_id FROM voice_exclusions WHERE id = :llamada_con_baja) AND source = 'spoken_opt_out');
EXPLAIN (ANALYZE, BUFFERS, TIMING OFF) SELECT EXISTS (SELECT * FROM voice_exclusions WHERE call_id = :llamada AND source = 'spoken_opt_out');
EXPLAIN (ANALYZE, BUFFERS, TIMING OFF) SELECT EXISTS (SELECT * FROM voice_exclusions WHERE phone_hash = :'huella_contacto');
EXPLAIN (ANALYZE, BUFFERS, TIMING OFF) SELECT id FROM voice_contacts WHERE phone_hash = :'huella_contacto' AND status <> 'retired';
\echo '── la unicidad sigue viva: una segunda saliente viva al mismo número choca con vc_one_live_outbound (23505)'
SAVEPOINT segunda;
\set ON_ERROR_STOP off
INSERT INTO voice_calls (uuid, call_ref, direction, status, target_source, canary_number_uuid, remote_hash, remote_last4, client_request_id, requested_by_type,
  requested_by_admin_id, requested_by_name, attempt, voice_publication_seq, part, stamp_state, studio_version_uuid, studio_content_hash, voice_profile_hash, spec_hash,
  payload_hash, compiler_version, provider, provider_agent_id, budget_day, reserved_micros, tool_calls, status_changed_at, created_at)
SELECT gen_random_uuid(), gen_random_uuid(), 'outbound', 'requested', 'canary_registry', gen_random_uuid(), remote_hash, remote_last4, gen_random_uuid(), 'admin', 1, 'Perf', 1,
  voice_publication_seq, part, stamp_state, studio_version_uuid, studio_content_hash, voice_profile_hash, spec_hash, payload_hash, compiler_version, provider, provider_agent_id,
  budget_day, 1, 0, now(), now()
FROM voice_calls WHERE status = 'ambiguous' ORDER BY id LIMIT 1;
\set ON_ERROR_STOP on
ROLLBACK TO SAVEPOINT segunda;

\echo '════ 4. P8-1: las lecturas de VoiceEvidence::summary() (VoiceEvidence.php:93-110), antes y con el índice candidato ════'
SELECT seq AS seq_canario FROM ultron_studio_voice_publications WHERE scope = 'canary' ORDER BY seq DESC LIMIT 1 \gset
\echo '── antes (sin índice por voice_publication_seq)'
EXPLAIN (ANALYZE, BUFFERS, TIMING OFF) SELECT id FROM voice_calls WHERE voice_publication_seq = :seq_canario AND part = 'canary' AND transfer_of_call_id IS NULL;
EXPLAIN (ANALYZE, BUFFERS, TIMING OFF) SELECT count(*) FROM voice_calls WHERE voice_publication_seq = :seq_canario AND part = 'canary' AND transfer_of_call_id IS NULL AND status = 'completed';
EXPLAIN (ANALYZE, BUFFERS, TIMING OFF) SELECT count(*) FROM voice_calls WHERE stamp_state = 'applied' AND part = 'canary' AND voice_publication_seq = :seq_canario AND created_at >= now() - interval '30 days' AND transfer_of_call_id IS NULL AND status NOT IN ('denied', 'requested', 'canceled', 'failed_to_start');
\echo '── la agregación única con FILTER (la propuesta de P8-1), sin índice'
EXPLAIN (ANALYZE, BUFFERS, TIMING OFF) SELECT count(*), count(*) FILTER (WHERE status = 'completed'), count(*) FILTER (WHERE seal_verified), sum(reserved_micros), min(created_at), max(created_at)
FROM voice_calls WHERE voice_publication_seq = :seq_canario AND part = 'canary' AND transfer_of_call_id IS NULL;
CREATE INDEX vc_perf_candidato_seq_part ON voice_calls (voice_publication_seq, part) WHERE transfer_of_call_id IS NULL;
ANALYZE voice_calls;
\echo '── con el índice candidato (voice_publication_seq, part) WHERE transfer_of_call_id IS NULL'
EXPLAIN (ANALYZE, BUFFERS, TIMING OFF) SELECT id FROM voice_calls WHERE voice_publication_seq = :seq_canario AND part = 'canary' AND transfer_of_call_id IS NULL;
EXPLAIN (ANALYZE, BUFFERS, TIMING OFF) SELECT count(*) FROM voice_calls WHERE voice_publication_seq = :seq_canario AND part = 'canary' AND transfer_of_call_id IS NULL AND status = 'completed';
EXPLAIN (ANALYZE, BUFFERS, TIMING OFF) SELECT count(*) FROM voice_calls WHERE stamp_state = 'applied' AND part = 'canary' AND voice_publication_seq = :seq_canario AND created_at >= now() - interval '30 days' AND transfer_of_call_id IS NULL AND status NOT IN ('denied', 'requested', 'canceled', 'failed_to_start');
EXPLAIN (ANALYZE, BUFFERS, TIMING OFF) SELECT count(*), count(*) FILTER (WHERE status = 'completed'), count(*) FILTER (WHERE seal_verified), sum(reserved_micros), min(created_at), max(created_at)
FROM voice_calls WHERE voice_publication_seq = :seq_canario AND part = 'canary' AND transfer_of_call_id IS NULL;

ROLLBACK;
\echo '════ fin: transacción deshecha (la copia queda como estaba) ════'
