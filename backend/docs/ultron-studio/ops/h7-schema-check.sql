-- h7-schema-check.sql · SOLO LECTURA · Hito 7, antes y después de ironbody-migrate (y tras un 55P03, para saber si la
-- migración de endurecimiento quedó aplicada). No escribe nada: SELECT, \gset y \if.
-- Producción (como root; por la ENTRADA ESTÁNDAR, porque el usuario postgres no puede leer /root ni un fichero 600 de root):
--   sudo -u postgres psql -X -v ON_ERROR_STOP=1 -v owner=iron -v app=iron_app -x -d ironbody < /root/h7-schema-check.sql
-- Ensayo local (PostgreSQL 14 desechable, con el modelo de roles de producción: dueño sin superusuario y aplicación solo
-- DML, como el banco):
--   psql -X -v ON_ERROR_STOP=1 -v owner=h5_owner -v app=h5_app -x -h 127.0.0.1 -p 55432 -U ironbody -d <base> < h7-schema-check.sql
--   (con -v app=ironbody también corre, pero ironbody es superusuario: los privilegios salen todos t)
-- Migración: 2026_11_02_100001_add_ultron_studio_hardening.php (DB7). Recuentos de plans/informes-h7/informe-db.md y de
-- su prueba (UltronStudioHardeningH7Test): PG 43 → 44 disparadores y 49 → 50 CHECK en ultron_studio_%; la cadena gana
-- uspub_chain_next (BEFORE INSERT, función propia ultron_studio_publication_chain) y uspub_seq_chk (seq >= 1); el rol de
-- la aplicación pierde UPDATE y DELETE en la cadena, el libro y las instantáneas (no en asociaciones ni en canarios, que
-- cambian de estado). ultron_studio_forbid() no cambia ni un byte (md5 4ad748b5…).
-- Lo esperado va en el comentario de cada columna: «antes → después». Si algo no cuadra, PARAR y avisar a
-- database-specialist (las rutas del H7 son las mismas 56; lo que falta es la guarda, no el código).
SELECT
  (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
    WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron_studio_%')                         AS disparadores_studio,   -- 43 → 44
  (SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid
    WHERE k.contype = 'c' AND c.relname LIKE 'ultron_studio_%')                            AS check_studio,          -- 49 → 50
  (SELECT count(*) FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid
    WHERE t.relname LIKE 'ultron_studio_%' AND i.indpred IS NOT NULL)                      AS parciales_studio,      -- 4 → 4
  (SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'ultron_studio_%') AS tablas_studio, -- 14 → 14
  (SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal AND tgname LIKE 'uspub\_%')      AS disparadores_uspub,    -- 3 → 4
  (SELECT string_agg(tgname || ':' || tgenabled, ',' ORDER BY tgname) FROM pg_trigger
    WHERE NOT tgisinternal AND tgrelid = 'public.ultron_studio_publications'::regclass)    AS disparadores_cadena,   -- uspub_forbid_delete:O,uspub_forbid_truncate:O,uspub_forbid_update:O → + uspub_chain_next:O
  (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
    WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron_studio_%' AND t.tgenabled <> 'O')  AS disparadores_apagados, -- 0 → 0 (una supresión 1581 a medias los dejaría apagados: no puede pasar, es una transacción)
  (SELECT count(*) FROM pg_constraint WHERE contype = 'c' AND conname = 'uspub_seq_chk'
      AND conrelid = 'public.ultron_studio_publications'::regclass)                        AS check_seq,             -- 0 → 1
  (SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'uspub_seq_chk'
      AND conrelid = 'public.ultron_studio_publications'::regclass)                        AS check_seq_def,         -- (vacío) → CHECK ((seq >= 1))
  (SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE proname = 'ultron_studio_publication_chain') AS dueno_funcion_cadena, -- (vacío) → iron
  (SELECT p.prosecdef FROM pg_proc p WHERE p.proname = 'ultron_studio_publication_chain')  AS funcion_cadena_definer, -- (vacío) → f (INVOKER, sin SECURITY DEFINER)
  (SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE proname = 'ultron_studio_forbid')   AS dueno_funcion,         -- iron
  (SELECT md5(pg_get_functiondef(oid)) FROM pg_proc WHERE proname = 'ultron_studio_forbid') AS huella_funcion,       -- 4ad748b5f17f09b398f7f641c197d7ee, igual antes y después
  (SELECT md5(pg_get_functiondef(oid)) = '4ad748b5f17f09b398f7f641c197d7ee'
     FROM pg_proc WHERE proname = 'ultron_studio_forbid')                                  AS funcion_es_la_de_f,    -- t → t
  (SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = 'ultron_studio_publications') AS dueno_cadena, -- iron (el ADD CONSTRAINT y el REVOKE lo exigen)
  (SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = 'ultron_studio_audit_events') AS dueno_libro,  -- iron
  (SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = 'ultron_studio_integration_snapshots') AS dueno_instantaneas, -- iron
  has_schema_privilege(:'owner', 'public', 'CREATE')                                       AS create_en_public,      -- t (crea la función)
  (SELECT count(*) FROM pg_roles WHERE rolname = :'app')                                   AS existe_rol_app;        -- 1 (si 0, en production la migración FALLA: el REVOKE no se puede aplicar)
-- La cadena tiene que ser consecutiva desde 1 y encadenada: si no, up() se NIEGA (la reparación es del rol dueño con la
-- autorización del propietario, runbook del H5 AS-3/N-3). Esperado: rotas 0. Sin filas, todo 0.
SELECT count(*) AS entradas_cadena,                                                         -- informativo (decenas como mucho)
       max(seq) AS cabeza_seq,                                                              -- = entradas_cadena
       count(*) FILTER (WHERE seq <> rn OR previous_publication_id IS DISTINCT FROM prev_id) AS rotas  -- 0
  FROM (SELECT seq, previous_publication_id, row_number() OVER (ORDER BY seq) AS rn,
               lag(id) OVER (ORDER BY seq) AS prev_id
          FROM ultron_studio_publications) x;
-- Cerrojos que harían caer la migración por lock_timeout (55P03): pide ACCESS EXCLUSIVE sobre la cadena (choca TAMBIÉN
-- con un SELECT de la cadena en una transacción abierta: el motor la lee con su llave distinta de off, y el Studio en
-- GET publications y en el estado). GRANT/REVOKE no toman cerrojo de tabla. Esperado: 0 filas; con filas, esperar y repetir.
SELECT a.pid, a.state, now() - a.xact_start AS en_transaccion, c.relname, l.mode, l.granted
  FROM pg_locks l JOIN pg_class c ON c.oid = l.relation JOIN pg_stat_activity a ON a.pid = l.pid
 WHERE c.relname = 'ultron_studio_publications' AND a.pid <> pg_backend_pid()
 ORDER BY a.pid;
-- Privilegios de la aplicación, uno a uno (m-5 del H5) y por columna (has_any_column_privilege: un GRANT de UPDATE por
-- columna también cuenta). Antes: UPDATE y DELETE t en las cinco (privilegios por defecto del dueño; los paraban los
-- disparadores). Después: f en la cadena, el libro y las instantáneas; t en asociaciones y canarios (cambian de estado).
SELECT t AS tabla,
       has_table_privilege(:'app', 'public.' || t, 'SELECT')    AS app_select,     -- t → t
       has_table_privilege(:'app', 'public.' || t, 'INSERT')    AS app_insert,     -- t → t
       has_any_column_privilege(:'app', 'public.' || t, 'UPDATE') AS app_update,   -- t → f en las 3 primeras · t → t en asociaciones y canarios
       has_table_privilege(:'app', 'public.' || t, 'DELETE')    AS app_delete,     -- t → f en las 3 primeras · t → t (informativo; lo paran los disparadores) en las otras 2
       has_table_privilege(:'app', 'public.' || t, 'TRUNCATE')  AS app_truncate,   -- f → f
       has_sequence_privilege(:'app', 'public.' || t || '_id_seq', 'USAGE') AS app_secuencia, -- t → t
       (SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = t) AS dueno -- iron
  FROM unnest(ARRAY['ultron_studio_publications', 'ultron_studio_audit_events', 'ultron_studio_integration_snapshots',
                    'ultron_studio_campaign_links', 'ultron_studio_canary_numbers']) AS t;
-- Filas de las tablas tocadas (informativo; la migración no toca ninguna fila: antes = después).
SELECT (SELECT count(*) FROM ultron_studio_publications)          AS filas_cadena,
       (SELECT count(*) FROM ultron_studio_audit_events)          AS filas_libro,
       (SELECT count(*) FROM ultron_studio_integration_snapshots) AS filas_instantaneas,
       (SELECT count(*) FROM ultron_studio_audit_events WHERE event = 'system.personal_data_redacted') AS supresiones_1581; -- informativo
