-- h8-schema-check.sql · SOLO LECTURA · Hito 8 (voz y telefonía), antes y después de ironbody-migrate (y tras un 55P03,
-- para saber qué migración quedó aplicada). No escribe nada: solo SELECT. Sirve aunque las tablas del H8 no existan
-- (todo va por pg_class y to_regclass, sin nombrar una tabla que quizá no está).
-- Producción (como root; por la ENTRADA ESTÁNDAR, porque el usuario postgres no puede leer /root ni un fichero 600 de root):
--   sudo -u postgres psql -X -v ON_ERROR_STOP=1 -v owner=iron -v app=iron_app -x -d ironbody < /root/h8-schema-check.sql
-- Ensayo local (PostgreSQL 14 desechable con el modelo de roles de producción, como el banco):
--   psql -X -v ON_ERROR_STOP=1 -v owner=h5_owner -v app=h5_app -x -h 127.0.0.1 -p 55432 -U ironbody -d <base> < h8-schema-check.sql
-- Migraciones: 2026_11_10_100001_create_ultron_studio_voice_tables (gobierno: 4 tablas ultron_studio_voice_*) y
-- 2026_11_10_100002_create_voice_tables (telefonía: 7 tablas voice_*), de DB8. Recuentos de la Enmienda 3 (E3-5) y de
-- plans/informes-h8/informe-db.md §5: PG, Studio 44 → 68 disparadores, 50 → 84 CHECK, 4 → 6 parciales y 14 → 18 tablas;
-- telefonía 0 → 27 / 51 / 3 / 7; 4 funciones nuevas, ninguna SECURITY DEFINER; ultron_studio_forbid() no cambia ni un
-- byte (md5 4ad748b5…). La aplicación queda en «ra» en las 6 de solo inserción y en «rawd» en las 5 con estados.
-- Lo esperado va en el comentario de cada columna: «antes → después». Si algo no cuadra, PARAR y avisar a
-- database-specialist. NO se ejecuta nunca `migrate:rollback` en producción: down() se niega con filas (D8-20, nivel 4).
SELECT
  (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
    WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron\_studio\_%')                       AS disparadores_studio,    -- 44 → 68
  (SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid
    WHERE k.contype = 'c' AND c.relname LIKE 'ultron\_studio\_%')                          AS check_studio,           -- 50 → 84
  (SELECT count(*) FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid
    WHERE t.relname LIKE 'ultron\_studio\_%' AND i.indpred IS NOT NULL)                    AS parciales_studio,       -- 4 → 6 (usva_one_canary, usva_one_rest)
  (SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'ultron\_studio\_%') AS tablas_studio, -- 14 → 18
  (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
    WHERE NOT t.tgisinternal AND c.relname LIKE 'voice\_%')                                AS disparadores_voz,       -- 0 → 27
  (SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid
    WHERE k.contype = 'c' AND c.relname LIKE 'voice\_%')                                   AS check_voz,              -- 0 → 51
  (SELECT count(*) FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid
    WHERE t.relname LIKE 'voice\_%' AND i.indpred IS NOT NULL)                             AS parciales_voz,          -- 0 → 3
  (SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'voice\_%') AS tablas_voz,           -- 0 → 7 (si antes no es 0, PARAR: hay tablas voice_* que no son del H8)
  (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
    WHERE NOT t.tgisinternal AND (c.relname LIKE 'voice\_%' OR c.relname LIKE 'ultron\_studio\_%')
      AND t.tgenabled <> 'O')                                                              AS disparadores_apagados,  -- 0 → 0 (una supresión 1581 a medias los dejaría apagados: no puede pasar, es una transacción)
  (SELECT count(*) FROM pg_proc WHERE proname IN ('ultron_studio_voice_publication_chain', 'ultron_studio_voice_publication_agents',
                                                  'voice_forbid', 'voice_brake_release'))  AS funciones_h8,           -- 0 → 4
  (SELECT count(*) FROM pg_proc WHERE proname IN ('ultron_studio_voice_publication_chain', 'ultron_studio_voice_publication_agents',
                                                  'voice_forbid', 'voice_brake_release') AND prosecdef) AS funciones_h8_definer, -- 0 → 0 (ninguna SECURITY DEFINER)
  (SELECT string_agg(proname || ':' || pg_get_userbyid(proowner), ',' ORDER BY proname COLLATE "C") FROM pg_proc
    WHERE proname IN ('ultron_studio_voice_publication_chain', 'ultron_studio_voice_publication_agents',
                      'voice_forbid', 'voice_brake_release'))                              AS duenos_funciones_h8,    -- (vacío) → las 4 del dueño (iron)
  (SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE proname = 'ultron_studio_forbid')   AS dueno_funcion_f,        -- iron
  (SELECT md5(pg_get_functiondef(oid)) FROM pg_proc WHERE proname = 'ultron_studio_forbid') AS huella_funcion_f,      -- 4ad748b5f17f09b398f7f641c197d7ee, igual antes y después
  (SELECT md5(pg_get_functiondef(oid)) = '4ad748b5f17f09b398f7f641c197d7ee'
     FROM pg_proc WHERE proname = 'ultron_studio_forbid')                                  AS funcion_es_la_de_f,     -- t → t
  (SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = 'ultron_studio_versions') AS dueno_versiones, -- iron (la FK de los agentes la exige)
  has_schema_privilege(:'owner', 'public', 'CREATE')                                       AS create_en_public,       -- t (crea tablas y funciones)
  (SELECT count(*) FROM pg_roles WHERE rolname = :'app')                                   AS existe_rol_app,         -- 1 (si 0, en production las dos migraciones FALLAN: el REVOKE no se puede aplicar)
  (SELECT count(*) FROM migrations WHERE migration IN ('2026_11_10_100001_create_ultron_studio_voice_tables',
                                                       '2026_11_10_100002_create_voice_tables')) AS migraciones_h8_registradas; -- 0 → 2 (1 = una cayó; relanzar el despliegue, que reanuda)
-- Dueños de las 11 tablas del H8 (después; antes, vacío): todas del dueño de las migraciones (iron). Una de otro rol
-- rompe el modelo dueño/aplicación (la aplicación no podría ni las guardas valdrían).
SELECT coalesce(string_agg(c.relname || ':' || pg_get_userbyid(c.relowner), ' ' ORDER BY c.relname COLLATE "C"), '') AS duenos_tablas_h8
  FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
 WHERE n.nspname = 'public' AND c.relkind = 'r' AND (c.relname LIKE 'voice\_%' OR c.relname LIKE 'ultron\_studio\_voice\_%');
-- Cerrojos que harían caer la 100001 por lock_timeout (55P03): su FK a ultron_studio_versions pide SHARE ROW EXCLUSIVE,
-- que choca con un ESCRITOR de versiones (ROW EXCLUSIVE en adelante), no con un lector (ACCESS SHARE / ROW SHARE).
-- La 100002 no toma cerrojo sobre ninguna tabla existente. Esperado: 0 filas; con filas, esperar y repetir.
SELECT a.pid, a.state, now() - a.xact_start AS en_transaccion, c.relname, l.mode, l.granted
  FROM pg_locks l JOIN pg_class c ON c.oid = l.relation JOIN pg_stat_activity a ON a.pid = l.pid
 WHERE c.relname = 'ultron_studio_versions' AND a.pid <> pg_backend_pid()
   AND l.mode NOT IN ('AccessShareLock', 'RowShareLock')
 ORDER BY a.pid;
-- Privilegios de la aplicación en las 11 tablas del H8, uno a uno (m-5 del H5) y por columna: r SELECT · a INSERT ·
-- w UPDATE (has_any_column_privilege) · d DELETE · D TRUNCATE · s USAGE de su secuencia. Después:
--   ra---s en las 6 de solo inserción: ultron_studio_voice_publications, voice_brakes, voice_call_events,
--          voice_consents, voice_exclusions, voice_rne_checks (REVOKE UPDATE, DELETE de DB8; 42501 si se intenta);
--   rawd-s en las 5 con estados: ultron_studio_voice_agents, ultron_studio_voice_lab_sessions,
--          ultron_studio_voice_profiles, voice_calls, voice_contacts (privilegios por defecto del dueño; los
--          disparadores paran el DELETE con P0001).
SELECT c.relname AS tabla,
       CASE WHEN has_table_privilege(:'app', c.oid, 'SELECT') THEN 'r' ELSE '-' END ||
       CASE WHEN has_table_privilege(:'app', c.oid, 'INSERT') THEN 'a' ELSE '-' END ||
       CASE WHEN has_any_column_privilege(:'app', c.oid, 'UPDATE') THEN 'w' ELSE '-' END ||
       CASE WHEN has_table_privilege(:'app', c.oid, 'DELETE') THEN 'd' ELSE '-' END ||
       CASE WHEN has_table_privilege(:'app', c.oid, 'TRUNCATE') THEN 'D' ELSE '-' END ||
       CASE WHEN to_regclass('public.' || c.relname || '_id_seq') IS NULL THEN '?'
            WHEN has_sequence_privilege(:'app', 'public.' || c.relname || '_id_seq', 'USAGE') THEN 's' ELSE '-' END AS app
  FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
 WHERE n.nspname = 'public' AND c.relkind = 'r' AND (c.relname LIKE 'voice\_%' OR c.relname LIKE 'ultron\_studio\_voice\_%')
 ORDER BY c.relname COLLATE "C";
-- Los privilegios de la aplicación en las 5 tablas del H6/H7 que el H8 no toca (igual antes y después): ra en la
-- cadena, el libro y las instantáneas (DB7); rawd en asociaciones y canarios.
SELECT t AS tabla_h7,
       CASE WHEN has_table_privilege(:'app', 'public.' || t, 'SELECT') THEN 'r' ELSE '-' END ||
       CASE WHEN has_table_privilege(:'app', 'public.' || t, 'INSERT') THEN 'a' ELSE '-' END ||
       CASE WHEN has_any_column_privilege(:'app', 'public.' || t, 'UPDATE') THEN 'w' ELSE '-' END ||
       CASE WHEN has_table_privilege(:'app', 'public.' || t, 'DELETE') THEN 'd' ELSE '-' END ||
       CASE WHEN has_table_privilege(:'app', 'public.' || t, 'TRUNCATE') THEN 'D' ELSE '-' END AS app
  FROM unnest(ARRAY['ultron_studio_audit_events', 'ultron_studio_campaign_links', 'ultron_studio_canary_numbers',
                    'ultron_studio_integration_snapshots', 'ultron_studio_publications']) AS t;
-- Filas de las 11 tablas del H8 (después: 0 en todas; la fase 1 no escribe nada) y de las del Studio que ya existían
-- (informativo; la migración no toca ninguna fila: antes = después).
SELECT coalesce(string_agg(c.relname || '=' || (xpath('/row/n/text()', query_to_xml(format('SELECT count(*) AS n FROM public.%I', c.relname), false, true, '')))[1]::text,
                           ' ' ORDER BY c.relname COLLATE "C"), '') AS filas_h8
  FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
 WHERE n.nspname = 'public' AND c.relkind = 'r' AND (c.relname LIKE 'voice\_%' OR c.relname LIKE 'ultron\_studio\_voice\_%');
SELECT (SELECT count(*) FROM ultron_studio_publications)          AS filas_cadena_h5,
       (SELECT count(*) FROM ultron_studio_audit_events)          AS filas_libro,
       (SELECT count(*) FROM ultron_studio_integration_snapshots) AS filas_instantaneas,
       (SELECT count(*) FROM ultron_studio_versions)              AS filas_versiones;
