-- h9-schema-check.sql · SOLO LECTURA · Hito 9 (automatización comercial: gobierno de las estrategias y ejecución
-- outreach), antes y después de ironbody-migrate (y tras un 55P03, para saber qué migración quedó aplicada). No escribe
-- nada: solo SELECT. Sirve aunque las tablas del H9 no existan (todo va por pg_class y to_regclass, sin nombrar una
-- tabla que quizá no está).
-- Producción (como root; por la ENTRADA ESTÁNDAR, porque el usuario postgres no puede leer /root ni un fichero 600 de root):
--   sudo -u postgres psql -X -v ON_ERROR_STOP=1 -v owner=iron -v app=iron_app -x -d ironbody < /root/h9-schema-check.sql
-- Ensayo local (PostgreSQL 14 desechable con el modelo de roles de producción, como el banco):
--   psql -X -v ON_ERROR_STOP=1 -v owner=h5_owner -v app=h5_app -x -h 127.0.0.1 -p 55432 -U ironbody -d <base> < h9-schema-check.sql
-- Migraciones (DB9): 2026_11_12_100001_create_ultron_studio_strategy_tables (gobierno: 7 tablas ultron_studio_*),
-- 2026_11_12_100002_create_outreach_tables (ejecución: 8 tablas outreach_*) y, APARTE y en hora de poco tráfico (L9-29,
-- §13 de informe-db.md), 2026_11_12_100003_add_outreach_read_indexes (4 índices %_h9_idx sobre tablas existentes, con
-- CREATE INDEX CONCURRENTLY fuera de transacción). Recuentos de L9-34 y de plans/informes-h9/informe-db.md §5: PG,
-- Studio 68 → 123 disparadores, 84 → 137 CHECK, 6 → 9 parciales y 18 → 25 tablas (gobierno del H9: 55 / 53 / 3 / 7);
-- outreach 0 → 51 / 75 / 3 / 8; 33 funciones nuevas sin SECURITY DEFINER; ultron_studio_forbid() no cambia ni un byte
-- (md5 4ad748b5…); el libro pasa de 7 a 11 sujetos (person_binding, strategy, message_template y strategy_version entran
-- en usae_subject_type_chk). La aplicación queda en «ra---» en las 5 tablas de solo inserción y en «raw--» (sin DELETE)
-- en las 10 con estado. Lo esperado va en el comentario de cada columna: «antes → después». Si algo no cuadra, PARAR y
-- avisar a database-specialist. NO se ejecuta nunca `migrate:rollback` en producción: down() se niega con filas
-- (§9.2 nivel 3: las tablas quedan).
SELECT
  (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
    WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron\_studio\_%')                       AS disparadores_studio,    -- 68 → 123
  (SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid
    WHERE k.contype = 'c' AND c.relname LIKE 'ultron\_studio\_%')                          AS check_studio,           -- 84 → 137
  (SELECT count(*) FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid
    WHERE t.relname LIKE 'ultron\_studio\_%' AND i.indpred IS NOT NULL)                    AS parciales_studio,       -- 6 → 9 (uspb_one_active, ussv_one_canary, ussv_one_published)
  (SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'ultron\_studio\_%') AS tablas_studio, -- 18 → 25
  (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
    WHERE NOT t.tgisinternal AND c.relname IN ('ultron_studio_person_bindings', 'ultron_studio_strategies',
      'ultron_studio_message_templates', 'ultron_studio_strategy_versions', 'ultron_studio_strategy_simulations',
      'ultron_studio_strategy_reviews', 'ultron_studio_strategy_publications'))             AS disparadores_gobierno_h9, -- 0 → 55 (8+7+11+12+4+6+7)
  (SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid
    WHERE k.contype = 'c' AND c.relname IN ('ultron_studio_person_bindings', 'ultron_studio_strategies',
      'ultron_studio_message_templates', 'ultron_studio_strategy_versions', 'ultron_studio_strategy_simulations',
      'ultron_studio_strategy_reviews', 'ultron_studio_strategy_publications'))             AS check_gobierno_h9,      -- 0 → 53 (5+6+12+11+5+4+10)
  (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
    WHERE NOT t.tgisinternal AND c.relname LIKE 'outreach\_%')                             AS disparadores_outreach,  -- 0 → 51 (6+4+10+11+7+5+5+3)
  (SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid
    WHERE k.contype = 'c' AND c.relname LIKE 'outreach\_%')                                AS check_outreach,         -- 0 → 75 (7+2+12+19+13+11+8+3)
  (SELECT count(*) FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid
    WHERE t.relname LIKE 'outreach\_%' AND i.indpred IS NOT NULL)                          AS parciales_outreach,     -- 0 → 3 (oenr_one_open, ojob_one_live, obrk_one_open)
  (SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'outreach\_%') AS tablas_outreach,   -- 0 → 8 (si antes no es 0, PARAR: hay tablas outreach_* que no son del H9)
  (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
    WHERE NOT t.tgisinternal AND (c.relname LIKE 'outreach\_%' OR c.relname LIKE 'ultron\_studio\_%')
      AND t.tgenabled <> 'O')                                                              AS disparadores_apagados,  -- 0 → 0 (una supresión 1581 a medias los dejaría apagados: no puede pasar, es una transacción)
  (SELECT count(*) FROM pg_proc WHERE proname LIKE 'outreach\_%' OR proname LIKE 'ultron\_studio\_chk\_%'
     OR proname = 'ultron_studio_strategy_publication_chain')                              AS funciones_h9,           -- 0 → 33 (20 del gobierno, 13 de la ejecución)
  (SELECT count(*) FROM pg_proc WHERE (proname LIKE 'outreach\_%' OR proname LIKE 'ultron\_studio\_chk\_%'
     OR proname = 'ultron_studio_strategy_publication_chain') AND prosecdef)              AS funciones_h9_definer,   -- 0 → 0 (ninguna SECURITY DEFINER)
  (SELECT count(DISTINCT pg_get_userbyid(proowner)) FROM pg_proc
    WHERE proname LIKE 'outreach\_%' OR proname LIKE 'ultron\_studio\_chk\_%'
       OR proname = 'ultron_studio_strategy_publication_chain')                            AS duenos_distintos_funciones_h9, -- 0 → 1 (todas del dueño: la siguiente columna dice cuál)
  (SELECT min(pg_get_userbyid(proowner)) FROM pg_proc
    WHERE proname LIKE 'outreach\_%' OR proname LIKE 'ultron\_studio\_chk\_%'
       OR proname = 'ultron_studio_strategy_publication_chain')                            AS dueno_funciones_h9,     -- (vacío) → iron
  (SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE proname = 'ultron_studio_forbid')   AS dueno_funcion_f,        -- iron
  (SELECT md5(pg_get_functiondef(oid)) FROM pg_proc WHERE proname = 'ultron_studio_forbid') AS huella_funcion_f,      -- 4ad748b5f17f09b398f7f641c197d7ee, igual antes y después
  (SELECT md5(pg_get_functiondef(oid)) = '4ad748b5f17f09b398f7f641c197d7ee'
     FROM pg_proc WHERE proname = 'ultron_studio_forbid')                                  AS funcion_es_la_de_f,     -- t → t
  (SELECT pg_get_constraintdef(oid) LIKE '%person_binding%' AND pg_get_constraintdef(oid) LIKE '%strategy_version%'
     FROM pg_constraint WHERE conname = 'usae_subject_type_chk')                           AS libro_11_sujetos,       -- f → t (el CHECK del libro admite los 4 sujetos nuevos)
  (SELECT count(*) FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid
    WHERE c.relname LIKE '%\_h9\_idx' AND i.indisvalid AND i.indisready)                  AS indices_lectura_h9_validos, -- 0 → 4 tras la 100003 (0 entre las dos y la tercera: es el paso aparte)
  (SELECT count(*) FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid
    WHERE c.relname LIKE '%\_h9\_idx' AND NOT (i.indisvalid AND i.indisready))            AS indices_lectura_h9_invalidos, -- 0 → 0 (uno inválido = un CONCURRENTLY que cayó: la 100003 lo rehace al relanzarla)
  (SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = 'ultron_studio_versions') AS dueno_versiones, -- iron
  (SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = 'identities') AS dueno_identities,    -- iron (la FK de person_bindings.identity_id la exige; L9-6)
  has_schema_privilege(:'owner', 'public', 'CREATE')                                       AS create_en_public,       -- t (crea tablas y funciones)
  (SELECT count(*) FROM pg_roles WHERE rolname = :'app')                                   AS existe_rol_app,         -- 1 (si 0, en production las dos migraciones FALLAN: el REVOKE no se puede aplicar)
  (SELECT count(*) FROM migrations WHERE migration IN ('2026_11_12_100001_create_ultron_studio_strategy_tables',
                                                       '2026_11_12_100002_create_outreach_tables')) AS migraciones_h9_registradas, -- 0 → 2 (1 = una cayó; relanzar el despliegue, que reanuda)
  (SELECT count(*) FROM migrations WHERE migration = '2026_11_12_100003_add_outreach_read_indexes') AS migracion_indices_registrada; -- 0 → 1 solo tras el paso aparte de la 100003
-- Dueños de las 15 tablas del H9 (después; antes, vacío): todas del dueño de las migraciones (iron). Una de otro rol
-- rompe el modelo dueño/aplicación (la aplicación no podría ni las guardas valdrían).
SELECT coalesce(string_agg(c.relname || ':' || pg_get_userbyid(c.relowner), ' ' ORDER BY c.relname COLLATE "C"), '') AS duenos_tablas_h9
  FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
 WHERE n.nspname = 'public' AND c.relkind = 'r' AND (c.relname LIKE 'outreach\_%' OR c.relname IN (
       'ultron_studio_person_bindings', 'ultron_studio_strategies', 'ultron_studio_message_templates',
       'ultron_studio_strategy_versions', 'ultron_studio_strategy_simulations', 'ultron_studio_strategy_reviews',
       'ultron_studio_strategy_publications'));
-- Cerrojos que harían caer la 100001 por lock_timeout (55P03): la FK de ultron_studio_person_bindings.identity_id a
-- identities pide SHARE ROW EXCLUSIVE sobre identities (L9-6), que choca con un ESCRITOR de identities (ROW EXCLUSIVE
-- en adelante), no con un lector (ACCESS SHARE / ROW SHARE). La 100002 no toma cerrojo sobre ninguna tabla existente;
-- la 100003 (CONCURRENTLY) espera a las transacciones abiertas sobre users, payment_transactions, class_reservations y
-- receivables sin bloquear a nadie. Esperado: 0 filas; con filas, esperar y repetir.
SELECT a.pid, a.state, now() - a.xact_start AS en_transaccion, c.relname, l.mode, l.granted
  FROM pg_locks l JOIN pg_class c ON c.oid = l.relation JOIN pg_stat_activity a ON a.pid = l.pid
 WHERE c.relname IN ('identities', 'users', 'payment_transactions', 'class_reservations', 'receivables')
   AND a.pid <> pg_backend_pid()
   AND l.mode NOT IN ('AccessShareLock', 'RowShareLock')
 ORDER BY a.pid;
-- Transacciones largas (para el paso aparte de la 100003, informe-db.md §13): CONCURRENTLY espera a que terminen las
-- que empezaron antes; una de minutos retrasa el índice (sin bloquear). Esperado antes de lanzarla: 0 filas.
SELECT a.pid, a.state, now() - a.xact_start AS dura, left(a.query, 60) AS consulta
  FROM pg_stat_activity a
 WHERE a.xact_start < now() - interval '30 seconds' AND a.pid <> pg_backend_pid()
 ORDER BY a.xact_start;
-- Privilegios de la aplicación en las 15 tablas del H9, uno a uno (m-5 del H5) y por columna: r SELECT · a INSERT ·
-- w UPDATE (has_any_column_privilege) · d DELETE · D TRUNCATE · s USAGE de su secuencia. Después:
--   ra---s en las 5 de solo inserción: ultron_studio_strategy_publications (la cadena), ultron_studio_strategy_reviews,
--          ultron_studio_strategy_simulations, outreach_decisions, outreach_outcomes (REVOKE UPDATE, DELETE de DB9;
--          42501 si se intenta);
--   raw--s en las 10 con estado: ultron_studio_person_bindings, ultron_studio_strategies,
--          ultron_studio_message_templates, ultron_studio_strategy_versions, outreach_runs, outreach_cursors,
--          outreach_enrollments, outreach_jobs, outreach_brakes, outreach_budget_counters (REVOKE DELETE de DB9; los
--          disparadores además acotan qué columnas cambian).
SELECT c.relname AS tabla,
       CASE WHEN has_table_privilege(:'app', c.oid, 'SELECT') THEN 'r' ELSE '-' END ||
       CASE WHEN has_table_privilege(:'app', c.oid, 'INSERT') THEN 'a' ELSE '-' END ||
       CASE WHEN has_any_column_privilege(:'app', c.oid, 'UPDATE') THEN 'w' ELSE '-' END ||
       CASE WHEN has_table_privilege(:'app', c.oid, 'DELETE') THEN 'd' ELSE '-' END ||
       CASE WHEN has_table_privilege(:'app', c.oid, 'TRUNCATE') THEN 'D' ELSE '-' END ||
       CASE WHEN to_regclass('public.' || c.relname || '_id_seq') IS NULL THEN '?'
            WHEN has_sequence_privilege(:'app', 'public.' || c.relname || '_id_seq', 'USAGE') THEN 's' ELSE '-' END AS app
  FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
 WHERE n.nspname = 'public' AND c.relkind = 'r' AND (c.relname LIKE 'outreach\_%' OR c.relname IN (
       'ultron_studio_person_bindings', 'ultron_studio_strategies', 'ultron_studio_message_templates',
       'ultron_studio_strategy_versions', 'ultron_studio_strategy_simulations', 'ultron_studio_strategy_reviews',
       'ultron_studio_strategy_publications'))
 ORDER BY c.relname COLLATE "C";
-- Los privilegios de la aplicación en las tablas del H5–H8 que el H9 no toca (igual antes y después): ra en la cadena,
-- el libro y las instantáneas (DB7) y en la cadena de voz, los eventos de llamada, los consentimientos, las exclusiones
-- y las comprobaciones RNE (DB8); rawd en asociaciones, canarios, agentes, perfiles, sesiones del laboratorio,
-- llamadas y contactos.
SELECT t AS tabla_h5_h8,
       CASE WHEN has_table_privilege(:'app', 'public.' || t, 'SELECT') THEN 'r' ELSE '-' END ||
       CASE WHEN has_table_privilege(:'app', 'public.' || t, 'INSERT') THEN 'a' ELSE '-' END ||
       CASE WHEN has_any_column_privilege(:'app', 'public.' || t, 'UPDATE') THEN 'w' ELSE '-' END ||
       CASE WHEN has_table_privilege(:'app', 'public.' || t, 'DELETE') THEN 'd' ELSE '-' END ||
       CASE WHEN has_table_privilege(:'app', 'public.' || t, 'TRUNCATE') THEN 'D' ELSE '-' END AS app
  FROM unnest(ARRAY['ultron_studio_audit_events', 'ultron_studio_campaign_links', 'ultron_studio_canary_numbers',
                    'ultron_studio_integration_snapshots', 'ultron_studio_publications', 'ultron_studio_voice_publications',
                    'voice_brakes', 'voice_call_events', 'voice_calls', 'voice_contacts']) AS t
 WHERE to_regclass('public.' || t) IS NOT NULL;
-- Los 4 índices de lectura de la 100003 (L9-31), con su validez: válidos los 4 tras el paso aparte; un índice inválido
-- (indisvalid = f) es un CONCURRENTLY que cayó: la migración lo borra y lo rehace al relanzarla.
SELECT c.relname AS indice, t.relname AS tabla, i.indisvalid AS valido, i.indisready AS listo,
       pg_size_pretty(pg_relation_size(c.oid)) AS tamano
  FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_class t ON t.oid = i.indrelid
 WHERE c.relname IN ('users_membership_end_date_h9_idx', 'payment_transactions_user_status_h9_idx',
                     'class_reservations_member_date_h9_idx', 'receivables_status_due_at_h9_idx')
 ORDER BY c.relname COLLATE "C";
-- Tamaño de las 4 tablas que reciben índice (informe-db.md §13 y D9-16): lo que CONCURRENTLY va a leer entero; en hora
-- de poco tráfico y con el disco holgado (el índice nuevo ocupa del orden del 5–15 % de su tabla).
SELECT t AS tabla, pg_size_pretty(pg_total_relation_size('public.' || t)) AS total,
       pg_size_pretty(pg_relation_size('public.' || t)) AS datos,
       (xpath('/row/n/text()', query_to_xml(format('SELECT count(*) AS n FROM public.%I', t), false, true, '')))[1]::text AS filas
  FROM unnest(ARRAY['users', 'payment_transactions', 'class_reservations', 'receivables']) AS t
 WHERE to_regclass('public.' || t) IS NOT NULL;
-- Filas de las 15 tablas del H9 (después: 0 en todas; la fase 1 no escribe nada) y de las del Studio que ya existían
-- (informativo; la migración no toca ninguna fila: antes = después).
SELECT coalesce(string_agg(c.relname || '=' || (xpath('/row/n/text()', query_to_xml(format('SELECT count(*) AS n FROM public.%I', c.relname), false, true, '')))[1]::text,
                           ' ' ORDER BY c.relname COLLATE "C"), '') AS filas_h9
  FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
 WHERE n.nspname = 'public' AND c.relkind = 'r' AND (c.relname LIKE 'outreach\_%' OR c.relname IN (
       'ultron_studio_person_bindings', 'ultron_studio_strategies', 'ultron_studio_message_templates',
       'ultron_studio_strategy_versions', 'ultron_studio_strategy_simulations', 'ultron_studio_strategy_reviews',
       'ultron_studio_strategy_publications'));
SELECT (SELECT count(*) FROM ultron_studio_publications)          AS filas_cadena_h5,
       (SELECT count(*) FROM ultron_studio_audit_events)          AS filas_libro,
       (SELECT count(*) FROM ultron_studio_integration_snapshots) AS filas_instantaneas,
       (SELECT count(*) FROM ultron_studio_versions)              AS filas_versiones;
