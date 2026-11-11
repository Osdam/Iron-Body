-- motores-schema-check.sql · SOLO LECTURA · Enmienda M-1 del Hito 5 (plans/STUDIO_MOTORES_PLAN.md §B.4, paso 3, E13, R10):
-- migración 2026_11_16_100001_allow_engine_config_v2_in_ultron_studio_publications, antes y después de ironbody-migrate
-- (y tras un 55P03, para saber si quedó aplicada). No escribe nada: solo SELECT, en una transacción READ ONLY. Sirve
-- aunque la cadena no exista (pregunta por pg_class y to_regclass antes de nombrarla).
-- Producción (como root; por la ENTRADA ESTÁNDAR, porque el usuario postgres no puede leer /root ni un fichero 600 de root):
--   sudo -u postgres psql -X -v ON_ERROR_STOP=1 -v app=iron_app -x -d ironbody < /root/motores-schema-check.sql
-- Ensayo local (PostgreSQL 14 desechable):
--   psql -X -v ON_ERROR_STOP=1 -v app=ironbody -x -h 127.0.0.1 -p 55432 -U ironbody -d <base> < motores-schema-check.sql
-- La migración SUSTITUYE un CHECK (DROP + ADD en un solo ALTER TABLE): no suma ni quita CHECK, disparadores, índices,
-- columnas ni privilegios, y no toca filas. Lo esperado va en el comentario de cada columna: «antes → después». Si algo no
-- cuadra, PARAR y avisar a database-specialist. NO se ejecuta nunca `migrate:rollback` en producción: down() se niega con
-- una entrada en /2 (R10: para volver atrás, «volver a Legacy» en el Studio, que escribe /1, y devolver el código).
BEGIN TRANSACTION READ ONLY;
SELECT to_regclass('public.ultron_studio_publications') IS NOT NULL AS hay_cadena \gset
SELECT
  :'hay_cadena'::boolean                                                                   AS existe_cadena,          -- t → t
  (SELECT count(*) FROM migrations
    WHERE migration = '2026_11_16_100001_allow_engine_config_v2_in_ultron_studio_publications') AS migracion_m1,     -- 0 → 1
  (SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid
    WHERE k.contype = 'c' AND c.relname LIKE 'ultron\_studio\_%')                          AS check_studio,           -- N → N (el mismo)
  (SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid
    WHERE k.contype = 'c' AND c.relname = 'ultron_studio_publications')                    AS check_cadena,           -- 4 → 4
  (SELECT count(*) FROM pg_constraint
    WHERE contype = 'c' AND conname = 'uspub_engine_contract_chk')                         AS check_contrato,         -- 1 → 1
  (SELECT bool_and(convalidated) FROM pg_constraint
    WHERE contype = 'c' AND conname = 'uspub_engine_contract_chk')                         AS check_validado,         -- t → t
  (SELECT pg_get_constraintdef(oid) LIKE '%''ultron-engine-config/1''%' FROM pg_constraint
    WHERE contype = 'c' AND conname = 'uspub_engine_contract_chk')                         AS admite_1,               -- t → t
  (SELECT pg_get_constraintdef(oid) LIKE '%''ultron-engine-config/2''%' FROM pg_constraint
    WHERE contype = 'c' AND conname = 'uspub_engine_contract_chk')                         AS admite_2,               -- f → t
  (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
    WHERE NOT t.tgisinternal AND c.relname = 'ultron_studio_publications')                 AS disparadores_cadena,    -- 4 → 4
  (SELECT string_agg(t.tgname || ':' || t.tgenabled, ', ' ORDER BY t.tgname) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
    WHERE NOT t.tgisinternal AND c.relname = 'ultron_studio_publications')                 AS disparadores_activos,   -- uspub_chain_next:O, uspub_forbid_delete:O, uspub_forbid_truncate:O, uspub_forbid_update:O (igual)
  (SELECT md5(coalesce(relacl::text, '')) FROM pg_class WHERE relname = 'ultron_studio_publications' AND relkind = 'r') AS huella_acl_cadena; -- igual antes y después
-- La definición vigente, tal como la escribe PostgreSQL.
--   Antes:   CHECK (((engine_contract)::text = 'ultron-engine-config/1'::text))
--   Después: CHECK (((engine_contract)::text = ANY ((ARRAY['ultron-engine-config/1'::character varying, 'ultron-engine-config/2'::character varying])::text[])))
SELECT conname, pg_get_constraintdef(oid) AS definicion
  FROM pg_constraint WHERE contype = 'c' AND conname = 'uspub_engine_contract_chk';
-- Cerrojos sobre la cadena: el ALTER pide ACCESS EXCLUSIVE, que choca TAMBIÉN con un SELECT en una transacción abierta
-- (el lector del motor, UltronPublishedConfig). Esperado: 0 filas; si hay, esperar a que terminen (lock_timeout 5 s).
SELECT a.pid, a.state, now() - a.xact_start AS en_transaccion, l.mode
  FROM pg_locks l JOIN pg_class c ON c.oid = l.relation JOIN pg_stat_activity a ON a.pid = l.pid
 WHERE c.relname = 'ultron_studio_publications' AND a.pid <> pg_backend_pid();
\if :hay_cadena
-- Recuento de entradas por contrato (en producción, 0 filas antes; con /2 > 0, down() se niega).
SELECT engine_contract AS contrato, count(*) AS entradas
  FROM ultron_studio_publications GROUP BY engine_contract ORDER BY engine_contract;
SELECT count(*)                                                                             AS entradas_total,         -- igual antes y después
       count(*) FILTER (WHERE engine_contract = 'ultron-engine-config/2')                   AS entradas_v2,            -- 0 → 0 (las escribe el Studio después, con la llave de prompts)
       has_table_privilege(:'app', 'public.ultron_studio_publications', 'SELECT')           AS app_select,             -- t → t
       has_table_privilege(:'app', 'public.ultron_studio_publications', 'INSERT')           AS app_insert,             -- t → t
       has_any_column_privilege(:'app', 'public.ultron_studio_publications', 'UPDATE')      AS app_update,             -- f → f (REVOKE del H7)
       has_table_privilege(:'app', 'public.ultron_studio_publications', 'DELETE')           AS app_delete              -- f → f (REVOKE del H7)
  FROM ultron_studio_publications;
\endif
ROLLBACK;
