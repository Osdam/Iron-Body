-- h6-schema-check.sql · SOLO LECTURA · Hito 6, antes y después de ironbody-migrate (y tras un 55P03, para saber si
-- la 100002 quedó aplicada). No escribe nada: SELECT, \gset y \if.
-- Producción (como root; por la ENTRADA ESTÁNDAR, porque el usuario postgres no puede leer /root ni un fichero 600 de root):
--   sudo -u postgres psql -X -v ON_ERROR_STOP=1 -v owner=iron -v app=iron_app -x -d ironbody < /root/h6-schema-check.sql
-- Ensayo local (PostgreSQL 14 desechable; con el modelo de roles de producción, como en informe-db.md §7.4):
--   psql -X -v ON_ERROR_STOP=1 -v owner=h5_owner -v app=h5_app -x -h 127.0.0.1 -p 55432 -U ironbody -d ultron_studio_h6_db < h6-schema-check.sql
--   (con -v owner=ironbody -v app=ironbody también corre, pero ironbody es superusuario: los privilegios salen todos t)
-- Basada en plans/informes-h6/informe-db.md §5 y en h5-schema-check.sql. Se ejecuta como postgres, así que el rol dueño
-- y el de la aplicación se nombran explícitamente (current_user sería postgres y siempre daría «t»).
-- Lo esperado va en el comentario de cada columna: «antes → después». Si algo no cuadra, PARAR: no se sirve el código
-- nuevo (route:cache) con las tablas sin guardas (Enmienda 1 §E1.3).
SELECT
  (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
    WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron_studio_%')                         AS disparadores_studio,   -- 30 → 43
  (SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid
    WHERE k.contype = 'c' AND c.relname LIKE 'ultron_studio_%')                            AS check_studio,          -- 27 → 49 (usae_subject_type_chk se sustituye, no se suma)
  (SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal AND tgname LIKE 'uscl\_%')       AS disparadores_uscl,     -- 0 → 5 (incluye uscl_replacement_once, D-1)
  (SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal AND tgname LIKE 'uscn\_%')       AS disparadores_uscn,     -- 0 → 5
  (SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal AND tgname LIKE 'usis\_%')       AS disparadores_usis,     -- 0 → 3
  (SELECT count(*) FROM pg_constraint WHERE contype = 'c' AND conname LIKE 'uscl\_%')      AS check_uscl,            -- 0 → 4
  (SELECT count(*) FROM pg_constraint WHERE contype = 'c' AND conname LIKE 'uscn\_%')      AS check_uscn,            -- 0 → 12 (con los dos de cuatro ojos)
  (SELECT count(*) FROM pg_constraint WHERE contype = 'c' AND conname LIKE 'usis\_%')      AS check_usis,            -- 0 → 6 (usis_outcome_chk con la forma de D-2)
  (SELECT count(*) FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid
    WHERE c.relname IN ('uscl_one_active', 'uscn_one_live') AND i.indisunique AND i.indpred IS NOT NULL) AS parciales_h6,  -- 0 → 2
  (SELECT count(*) FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid
    WHERE t.relname LIKE 'ultron_studio_%' AND i.indpred IS NOT NULL)                      AS parciales_studio,      -- 2 → 4 (los 2 del H5 sobre versiones siguen)
  (SELECT pg_get_constraintdef(oid) LIKE '%''campaign_link''%' AND pg_get_constraintdef(oid) LIKE '%''canary_number''%'
          AND pg_get_constraintdef(oid) LIKE '%''integration''%'
     FROM pg_constraint WHERE conname = 'usae_subject_type_chk' AND conrelid = 'public.ultron_studio_audit_events'::regclass) AS check_7_sujetos, -- f → t
  (SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE proname = 'ultron_studio_forbid')   AS dueno_funcion,         -- iron (si no: PARAR, la 100002 falla con 42501)
  (SELECT md5(pg_get_functiondef(oid)) FROM pg_proc WHERE proname = 'ultron_studio_forbid') AS huella_funcion,       -- 4ad748b5f17f09b398f7f641c197d7ee, igual antes y después
  (SELECT md5(pg_get_functiondef(oid)) = '4ad748b5f17f09b398f7f641c197d7ee'
     FROM pg_proc WHERE proname = 'ultron_studio_forbid')                                  AS funcion_es_la_de_f,    -- t (si f: PARAR; la 100002 la redeclara con el cuerpo de F y la cambiaría)
  (SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = 'ultron_studio_audit_events') AS dueno_libro,     -- iron (el ALTER TABLE del CHECK lo exige)
  (SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = 'ultron_studio_versions')     AS dueno_versiones, -- iron (las FK lo exigen)
  has_schema_privilege(:'owner', 'public', 'CREATE')                                       AS create_en_public,      -- t
  (SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'ultron_studio_%') AS tablas_studio, -- 11 → 14
  (SELECT count(*) FROM pg_tables WHERE schemaname = 'public'
    AND tablename IN ('ultron_studio_campaign_links', 'ultron_studio_canary_numbers', 'ultron_studio_integration_snapshots')) AS tablas_h6; -- 0 → 3
-- Lo que valida el ADD CONSTRAINT del libro bajo ACCESS EXCLUSIVE (R-2 de informe-db.md): cuanto más largo, más dura el
-- cerrojo. Fase 1: 0 eventos de los sujetos nuevos (la bandera está apagada y los rechazos por bandera no se auditan).
SELECT count(*) AS eventos_libro,                                                                      -- informativo
       count(*) FILTER (WHERE subject_type NOT IN ('draft', 'version', 'rule', 'system')) AS eventos_sujetos_nuevos -- 0 antes; 0 tras la fase 1
  FROM ultron_studio_audit_events;
-- Cerrojos que harían caer la migración por lock_timeout (55P03): la 100002 pide ACCESS EXCLUSIVE sobre el libro (choca
-- TAMBIÉN con un SELECT en una transacción abierta) y sobre las 3 tablas nuevas; la 100001, SHARE ROW EXCLUSIVE sobre
-- versiones (choca con quien escribió en ella). Esperado: 0 filas. Con filas: esperar a que terminen y repetir.
SELECT a.pid, a.state, now() - a.xact_start AS en_transaccion, c.relname, l.mode, l.granted
  FROM pg_locks l JOIN pg_class c ON c.oid = l.relation JOIN pg_stat_activity a ON a.pid = l.pid
 WHERE c.relname IN ('ultron_studio_audit_events', 'ultron_studio_versions', 'ultron_studio_campaign_links',
                     'ultron_studio_canary_numbers', 'ultron_studio_integration_snapshots')
   AND a.pid <> pg_backend_pid()
 ORDER BY a.pid, c.relname;
SELECT to_regclass('public.ultron_studio_canary_numbers') IS NOT NULL
   AND to_regclass('public.ultron_studio_campaign_links') IS NOT NULL
   AND to_regclass('public.ultron_studio_integration_snapshots') IS NOT NULL AS hay_h6 \gset
\if :hay_h6
-- Privilegios de la aplicación, uno a uno (m-5 del H5: has_table_privilege con una lista responde t con CUALQUIERA).
-- UPDATE lo necesitan asociaciones (desactivar) y canarios (transiciones); en las instantáneas es informativo, como
-- DELETE en las tres: lo prohibido lo paran los disparadores (P0001). Revocarlos es candidato del Hito 7.
SELECT t AS tabla,
       has_table_privilege(:'app', 'public.' || t, 'SELECT')   AS app_select,    -- t
       has_table_privilege(:'app', 'public.' || t, 'INSERT')   AS app_insert,    -- t
       has_table_privilege(:'app', 'public.' || t, 'UPDATE')   AS app_update,    -- t (imprescindible en campaign_links y canary_numbers)
       has_table_privilege(:'app', 'public.' || t, 'DELETE')   AS app_delete,    -- informativo: t por los privilegios por defecto; borrar lo impide el disparador
       has_table_privilege(:'app', 'public.' || t, 'TRUNCATE') AS app_truncate,  -- f
       has_sequence_privilege(:'app', 'public.' || t || '_id_seq', 'USAGE') AS app_secuencia, -- t
       (SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = t) AS dueno, -- iron
       (xpath('/row/n/text()', query_to_xml('SELECT count(*) AS n FROM public.' || t, false, true, '')))[1]::text::int AS filas -- 0 / 0 / 0 al migrar; 3 instantáneas de sistema tras integrations-snapshot
  FROM unnest(ARRAY['ultron_studio_campaign_links', 'ultron_studio_canary_numbers', 'ultron_studio_integration_snapshots']) AS t;
-- Instantáneas por proveedor, tipo y actor (informativo). Tras la fase 1: meta_ads, twilio y voice con 1 fila
-- config/system cada uno; ninguna admin ni probe. Sin valores: ni facts ni huellas.
SELECT provider, kind, actor_type, count(*) AS filas, max(created_at) AS ultima
  FROM ultron_studio_integration_snapshots
 GROUP BY provider, kind, actor_type
 ORDER BY provider, kind, actor_type;
\endif
