-- h5-schema-check.sql · SOLO LECTURA · Hito 5, antes y después de ironbody-migrate.
-- Producción (como root; por la ENTRADA ESTÁNDAR, porque el usuario postgres no puede leer /root ni un fichero 600 de root): sudo -u postgres psql -X -v ON_ERROR_STOP=1 -v owner=iron -v app=iron_app -x -d ironbody < /root/h5-schema-check.sql
-- Ensayo local:           psql -X -v ON_ERROR_STOP=1 -v owner=ironbody -v app=ironbody -x -h 127.0.0.1 -p 55432 -U ironbody -d ultron_studio_smoke_h5 -f h5-schema-check.sql
-- Basada en informe-db.md §13; se ejecuta como postgres, así que el rol dueño y el de la aplicación se nombran
-- explícitamente (current_user sería postgres y siempre daría «t»).
SELECT
  (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
    WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron_studio_%')                         AS disparadores_studio,  -- 27 antes → 30 después
  (SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid
    WHERE k.contype = 'c' AND c.relname LIKE 'ultron_studio_%')                            AS check_studio,         -- 24 → 27
  (SELECT count(*) FROM pg_trigger WHERE tgname LIKE 'uspub_%')                            AS disparadores_uspub,   -- 0 → 3
  (SELECT count(*) FROM pg_constraint WHERE contype = 'c' AND conname LIKE 'uspub_%')      AS check_uspub,          -- 0 → 3
  (SELECT count(*) FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid
    WHERE c.relname IN ('usv_one_canary', 'usv_one_published') AND i.indisunique AND i.indpred IS NOT NULL) AS indices_parciales, -- 0 → 2
  (SELECT pg_get_constraintdef(oid) LIKE '%ROLLED_BACK%' FROM pg_constraint WHERE conname = 'usv_status_chk') AS check_9_estados, -- f → t
  (SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE proname = 'ultron_studio_forbid')   AS dueno_funcion,        -- iron (si no: PARAR, la 100002 falla con 42501)
  (SELECT md5(pg_get_functiondef(oid)) FROM pg_proc WHERE proname = 'ultron_studio_forbid') AS huella_funcion,      -- igual antes y después
  (SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = 'ultron_studio_versions') AS dueno_versiones, -- iron (ALTER TABLE lo exige)
  has_schema_privilege(:'owner', 'public', 'CREATE')                                       AS create_en_public,     -- t
  to_regclass('public.ultron_studio_publications') IS NOT NULL                             AS existe_cadena;        -- f → t
SELECT count(*) AS versiones_fuera_de_los_5_estados_de_f
  FROM ultron_studio_versions WHERE status NOT IN ('DRAFT', 'VALIDATED', 'REVIEW_REQUIRED', 'APPROVED', 'REJECTED');  -- 0 antes
-- Cerrojos sobre versiones: la 100002 pide ACCESS EXCLUSIVE, que choca TAMBIÉN con AccessShareLock (un SELECT en
-- una transacción abierta). A diferencia de H4, aquí no se filtra AccessShareLock. Esperado: 0 filas.
SELECT a.pid, a.state, now() - a.xact_start AS en_transaccion, l.mode
  FROM pg_locks l JOIN pg_class c ON c.oid = l.relation JOIN pg_stat_activity a ON a.pid = l.pid
 WHERE c.relname IN ('ultron_studio_versions', 'ultron_studio_publications') AND a.pid <> pg_backend_pid();
SELECT to_regclass('public.ultron_studio_publications') IS NOT NULL AS hay_cadena \gset
\if :hay_cadena
SELECT has_table_privilege(:'app', 'public.ultron_studio_publications', 'SELECT')                      AS app_select,    -- t  (m-5: por separado; una lista responde t con CUALQUIERA)
       has_table_privilege(:'app', 'public.ultron_studio_publications', 'INSERT')                      AS app_insert,    -- t
       has_table_privilege(:'app', 'public.ultron_studio_publications', 'UPDATE')                      AS app_update,    -- informativo: t por los privilegios por defecto (arwd); modificar lo impide el disparador uspub_*
       has_table_privilege(:'app', 'public.ultron_studio_publications', 'DELETE')                      AS app_delete,    -- informativo: idem (borrar lo impide el disparador). Revocarlos: candidato del Hito 7
       has_table_privilege(:'app', 'public.ultron_studio_publications', 'TRUNCATE')                    AS app_truncate,  -- f
       has_sequence_privilege(:'app', 'public.ultron_studio_publications_id_seq', 'USAGE')             AS app_secuencia, -- t
       (SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = 'ultron_studio_publications') AS dueno_cadena, -- iron
       (SELECT count(*) FROM ultron_studio_publications)                                                    AS publicaciones; -- 0
\endif
