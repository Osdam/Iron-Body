-- supresion-1581.sql · ULTRON STUDIO · Ley 1581 de 2012 (habeas data): SUPRESIÓN por REDACCIÓN, nunca por borrado.
--
-- Qué hace: busca los datos personales de UNA persona (un texto: nombre, correo…), de UN teléfono, de UN número canario
-- (por su huella) o de UNA persona del personal (por su id de administrador) en las 14 tablas ultron_studio_* y los
-- sustituye por «[suprimido]». No borra filas: el libro, la cadena, el laboratorio y las instantáneas son de solo
-- inserción por diseño, y una fila menos rompería la cadena de publicaciones y los recuentos de auditoría.
--
-- EN SECO POR DEFECTO (:dry = 1): transacción READ ONLY que solo CUENTA, por tabla y columna, y termina en ROLLBACK.
-- Con :dry = 0: una sola transacción con el rol DUEÑO (SET LOCAL ROLE :owner) que, por cada tabla con coincidencias,
--   1. desactiva SOLO sus disparadores de UPDATE cuya función es ultron_studio_forbid() o ultron_studio_lab_forbid()
--      (los de solo inserción e inmutabilidad; uno de UPDATE con otra función ABORTA: no se sabe qué protege),
--   2. redacta, 3. vuelve a activar exactamente esos disparadores,
-- y al final comprueba que no queda ninguna coincidencia, que todos los disparadores del Studio están como estaban y
-- deja UNA fila en el libro (event system.personal_data_redacted, con recuentos y el radicado, nunca el dato). Si algo
-- falla, la transacción entera se deshace: nunca queda un disparador apagado ni una redacción a medias.
-- ALTER TABLE … DISABLE TRIGGER es transaccional y toma ACCESS EXCLUSIVE sobre esa tabla hasta el COMMIT: nadie más
-- ve la tabla sin su disparador; las lecturas del Studio esperan unos milisegundos (lock_timeout 5 s: si alguien la
-- tiene, aborta entero sin cambiar nada y se repite). No toca la definición de ningún disparador ni de ninguna función.
--
-- EL LABORATORIO: reply, checks y judge de cada caso entran en results_hash, que se recalcula al emitir el veredicto.
-- Con veredicto ya emitido, redactarlos no cambia nada (la puerta de publicar mira el veredicto y la huella de la
-- versión); SIN veredicto, esa ejecución ya no se puede juzgar (run_integrity_mismatch): el modo en seco lo cuenta.
--
-- LO QUE NO SE REDACTA (se informa, nunca se modifica): la configuración FIRMADA o con HUELLA, porque redactarla
-- rompería su verificación (el motor sellaría «fallback»):
--   ultron_studio_publications.effective_json (firmada), ultron_studio_versions.content (content_hash) y su copia
--   ultron_studio_lab_runs.target_snapshot (target_content_hash). Si aparece ahí, el remedio es una versión nueva sin
--   el dato (y, si se publicó, publicarla); la vieja queda como evidencia. Decisión del propietario, con su jurídico.
--
-- Variables (psql), por un fichero de parámetros de root (600) que se concatena DELANTE de este fichero, para que el
-- dato no pase por la línea de órdenes (ps), el historial de la shell ni un fichero legible por postgres:
--   \set radicado '1581-2026-001'    obligatorio: el número de la solicitud ([A-Za-z0-9._-], 3–40)
--   \set term 'Nombre Apellido'      un texto (≥ 4 caracteres; sin comillas dobles, barras invertidas ni controles).
--                                    Sin distinguir mayúsculas (en ASCII). Una variante de escritura por pasada
--                                    (con y sin tildes, apodos): el modo en seco enseña cuántas casan
--   \set phone '+573001234567'       un teléfono en E.164: casa sus dígitos nacionales con o sin el prefijo y con
--                                    espacios, puntos, guiones o paréntesis entre las cifras
--   \set cc 57                       prefijo del país del teléfono (57 por defecto)
--   \set phone_hash '<64 hex>'       la huella HMAC del número canario (la calcula la aplicación: runbook §10, paso 2). Solo
--                                    números REVOCADOS (uno vivo aborta: su huella la usa la coherencia con el motor):
--                                    alias → «suprimido», huella → una huella aleatoria (64 hex), 4 dígitos → 0000.
--                                    IRREVERSIBLE: la huella original no se puede «desredactar» ni recalcular
--   \set admin_id 42                 una persona del PERSONAL: su nombre en cada instantánea de autoría (actor_name,
--                                    created_by_name, reviewer_name…) y su IP y navegador en el libro. Quita la
--                                    trazabilidad nominal de lo que hizo (queda el id): decisión del propietario
--   \set owner iron                  el rol dueño de las tablas (en el banco, h5_owner)
--   \set dry 1                       1 = en seco (por defecto) · 0 = redacta
--
-- Uso en el servidor (como root; el runbook completo está en plans/informes-h7/informe-devops-h7.md §10, «Supresión»):
--   install -m 600 /dev/null /root/supresion/<radicado>.psql     # y escribir dentro las \set con un editor
--   cat /root/supresion/<radicado>.psql /root/supresion-1581.sql | sudo -u postgres psql -X -q -d ironbody 2>&1 | tee …
-- Salida: solo recuentos por tabla y columna, códigos y el radicado. Nunca el dato ni los valores encontrados.
-- Antes de :dry 0: respaldo fresco (las copias de seguridad SIGUEN llevando el dato hasta que caducan; una
-- restauración exige volver a aplicar cada supresión del registro) y log_statement en none o ddl (con mod o all, el
-- servidor guardaría la línea set_config con el dato en su log).

\set ON_ERROR_STOP 1
\set VERBOSITY terse
\pset pager off
\if :{?dry}
\else
  \set dry 1
\endif
\if :{?owner}
\else
  \set owner iron
\endif
\if :{?radicado}
\else
  \set radicado ''
\endif
\if :{?term}
\else
  \set term ''
\endif
\if :{?phone}
\else
  \set phone ''
\endif
\if :{?cc}
\else
  \set cc 57
\endif
\if :{?phone_hash}
\else
  \set phone_hash ''
\endif
\if :{?admin_id}
\else
  \set admin_id ''
\endif

-- Los valores viajan al bloque DO por variables de sesión (psql no sustituye variables dentro de $$…$$). Ni esta
-- consulta ni el bloque los imprimen.
SELECT set_config('supresion.dry', :'dry', false) IS NOT NULL AS _a,
       set_config('supresion.owner', :'owner', false) IS NOT NULL AS _b,
       set_config('supresion.radicado', :'radicado', false) IS NOT NULL AS _c,
       set_config('supresion.term', :'term', false) IS NOT NULL AS _d,
       set_config('supresion.phone', :'phone', false) IS NOT NULL AS _e,
       set_config('supresion.cc', :'cc', false) IS NOT NULL AS _f,
       set_config('supresion.phone_hash', :'phone_hash', false) IS NOT NULL AS _g,
       set_config('supresion.admin_id', :'admin_id', false) IS NOT NULL AS _h \gset

\if :dry
  \echo '== Supresión Ley 1581 · EN SECO (READ ONLY): solo recuentos; termina en ROLLBACK'
  BEGIN READ ONLY;
\else
  \echo '== Supresión Ley 1581 · REDACCIÓN con el rol dueño, en una sola transacción'
  BEGIN;
\endif
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '120s';
SET LOCAL ROLE :"owner";

DO $supresion$
DECLARE
    v_dry       boolean := current_setting('supresion.dry') IN ('1', 'on', 'true', 'yes');
    v_owner     text := current_setting('supresion.owner');
    v_radicado  text := current_setting('supresion.radicado');
    v_term      text := current_setting('supresion.term');
    v_phone     text := current_setting('supresion.phone');
    v_cc        text := current_setting('supresion.cc');
    v_hash      text := lower(current_setting('supresion.phone_hash'));
    v_admin_txt text := current_setting('supresion.admin_id');
    v_admin     bigint;
    v_marca     constant text := '[suprimido]';
    v_sep       constant text := '[[:space:].()-]*';
    -- La política: qué columna se redacta (r) y cuál solo se informa (i, firmada o con huella).
    v_politica  constant jsonb := '[
        ["ultron_studio_audit_events", "summary", "r"], ["ultron_studio_audit_events", "diff", "r"],
        ["ultron_studio_audit_events", "metadata", "r"], ["ultron_studio_audit_events", "actor_name", "r"],
        ["ultron_studio_audit_events", "user_agent", "r"],
        ["ultron_studio_publications", "reason", "r"], ["ultron_studio_publications", "actor_name", "r"],
        ["ultron_studio_publications", "acknowledged", "r"], ["ultron_studio_publications", "evidence", "r"],
        ["ultron_studio_publications", "lab_gate", "r"], ["ultron_studio_publications", "effective_json", "i"],
        ["ultron_studio_lab_scenarios", "title", "r"], ["ultron_studio_lab_scenarios", "description", "r"],
        ["ultron_studio_lab_scenarios", "content", "r"], ["ultron_studio_lab_scenarios", "archive_reason", "r"],
        ["ultron_studio_lab_scenarios", "created_by_name", "r"], ["ultron_studio_lab_scenarios", "updated_by_name", "r"],
        ["ultron_studio_lab_runs", "target_label", "r"], ["ultron_studio_lab_runs", "summary", "r"],
        ["ultron_studio_lab_runs", "created_by_name", "r"], ["ultron_studio_lab_runs", "cancel_requested_by_name", "r"],
        ["ultron_studio_lab_runs", "target_snapshot", "i"],
        ["ultron_studio_lab_run_cases", "scenario_snapshot", "r"], ["ultron_studio_lab_run_cases", "reply", "r"],
        ["ultron_studio_lab_run_cases", "checks", "r"], ["ultron_studio_lab_run_cases", "judge", "r"],
        ["ultron_studio_lab_run_cases", "calls", "r"],
        ["ultron_studio_lab_verdicts", "note", "r"], ["ultron_studio_lab_verdicts", "acknowledged_cases", "r"],
        ["ultron_studio_lab_verdicts", "reviewer_name", "r"],
        ["ultron_studio_lab_events", "summary", "r"], ["ultron_studio_lab_events", "diff", "r"],
        ["ultron_studio_lab_events", "actor_name", "r"],
        ["ultron_studio_canary_numbers", "alias", "r"], ["ultron_studio_canary_numbers", "revoke_reason", "r"],
        ["ultron_studio_canary_numbers", "registered_by_name", "r"], ["ultron_studio_canary_numbers", "authorized_by_name", "r"],
        ["ultron_studio_canary_numbers", "activated_by_name", "r"], ["ultron_studio_canary_numbers", "revoked_by_name", "r"],
        ["ultron_studio_integration_snapshots", "note", "r"], ["ultron_studio_integration_snapshots", "facts", "r"],
        ["ultron_studio_integration_snapshots", "actor_name", "r"],
        ["ultron_studio_campaign_links", "campaign_name_at_link", "r"], ["ultron_studio_campaign_links", "deactivation_reason", "r"],
        ["ultron_studio_campaign_links", "created_by_name", "r"], ["ultron_studio_campaign_links", "deactivated_by_name", "r"],
        ["ultron_studio_versions", "label", "r"], ["ultron_studio_versions", "note", "r"],
        ["ultron_studio_versions", "last_validation", "r"], ["ultron_studio_versions", "created_by_name", "r"],
        ["ultron_studio_versions", "content", "i"],
        ["ultron_studio_version_reviews", "note", "r"], ["ultron_studio_version_reviews", "acknowledged_warnings", "r"],
        ["ultron_studio_version_reviews", "validation", "r"], ["ultron_studio_version_reviews", "reviewer_name", "r"],
        ["ultron_studio_rules", "description", "r"], ["ultron_studio_rules", "conditions", "r"],
        ["ultron_studio_rules", "refs", "r"], ["ultron_studio_rules", "layers", "r"],
        ["ultron_studio_rules", "archive_reason", "r"], ["ultron_studio_rules", "created_by_name", "r"],
        ["ultron_studio_rules", "updated_by_name", "r"],
        ["ultron_studio_drafts", "content", "r"]
    ]';
    -- La autoría de una persona del personal: [tabla, columna del nombre, columna del id].
    v_autoria_def constant jsonb := '[
        ["ultron_studio_audit_events", "actor_name", "actor_admin_id"], ["ultron_studio_lab_events", "actor_name", "actor_admin_id"],
        ["ultron_studio_publications", "actor_name", "actor_admin_id"], ["ultron_studio_integration_snapshots", "actor_name", "actor_admin_id"],
        ["ultron_studio_campaign_links", "created_by_name", "created_by_admin_id"], ["ultron_studio_campaign_links", "deactivated_by_name", "deactivated_by_admin_id"],
        ["ultron_studio_canary_numbers", "registered_by_name", "registered_by_admin_id"], ["ultron_studio_canary_numbers", "authorized_by_name", "authorized_by_admin_id"],
        ["ultron_studio_canary_numbers", "activated_by_name", "activated_by_admin_id"], ["ultron_studio_canary_numbers", "revoked_by_name", "revoked_by_admin_id"],
        ["ultron_studio_lab_runs", "created_by_name", "created_by_admin_id"], ["ultron_studio_lab_scenarios", "created_by_name", "created_by_admin_id"],
        ["ultron_studio_lab_scenarios", "updated_by_name", "updated_by_admin_id"], ["ultron_studio_lab_verdicts", "reviewer_name", "reviewer_admin_id"],
        ["ultron_studio_versions", "created_by_name", "created_by_admin_id"], ["ultron_studio_version_reviews", "reviewer_name", "reviewer_admin_id"],
        ["ultron_studio_rules", "created_by_name", "created_by_admin_id"], ["ultron_studio_rules", "updated_by_name", "updated_by_admin_id"]
    ]';
    -- Columnas de texto o JSON que no llevan datos personales (enumeraciones, códigos, huellas, ids, roles).
    v_neutras   constant text[] := ARRAY['event', 'subject_type', 'actor_type', 'actor_role', 'from_status', 'to_status', 'status',
        'action', 'engine_contract', 'catalog_version', 'kind', 'provider', 'outcome', 'channel', 'country_code',
        'phone_hash', 'phone_last4', 'phone_encrypted', 'decision', 'mode', 'model', 'prompt_version', 'prices',
        'code', 'source', 'enforcement', 'type', 'priority', 'origin', 'category', 'target_kind', 'stop_reason',
        'ai_recommendation', 'error_code', 'scenario_code', 'ad_account_id', 'campaign_id', 'created_by_role',
        'deactivated_by_role', 'registered_by_role', 'authorized_by_role', 'activated_by_role', 'revoked_by_role',
        'reviewer_role', 'content_hash', 'effective_hash', 'signature', 'fingerprint', 'ip_address',
        'catalog_hash', 'target_content_hash', 'version_content_hash', 'results_hash'];
    v_res       text[] := '{}';
    v_tipos     text[] := '{}';
    v_digits    text;
    v_nacional  text;
    r           record;
    t           record;
    v_n         bigint;
    v_total     bigint := 0;
    v_firmadas  bigint := 0;
    v_hallazgos jsonb := '[]';
    v_autoria   jsonb := '[]';
    v_tablas    text[] := '{}';
    v_cuentas   jsonb := '{}';
    v_apagados  text[];
    v_antes     bigint;
    v_despues   bigint;
    v_resto     bigint;
    v_tabla     text;
    v_expr      text;
    v_set       text;
    v_trig      text;
BEGIN
    -- ── 0. Entradas y entorno ──────────────────────────────────────────────────────────────────────────────
    IF current_user <> v_owner THEN
        RAISE EXCEPTION 'supresión: se ejecuta con el rol dueño % (current_user es %)', v_owner, current_user USING ERRCODE = '42501';
    END IF;
    IF (SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = 'ultron_studio_audit_events') IS DISTINCT FROM v_owner THEN
        RAISE EXCEPTION 'supresión: el libro no es de % (o no existe): el rol dueño es otro', v_owner USING ERRCODE = '42501';
    END IF;
    IF v_radicado !~ '^[A-Za-z0-9._-]{3,40}$' THEN
        RAISE EXCEPTION 'supresión: falta :radicado o no tiene la forma [A-Za-z0-9._-]{3,40}' USING ERRCODE = '22023';
    END IF;
    IF v_term = '' AND v_phone = '' AND v_hash = '' AND v_admin_txt = '' THEN
        RAISE EXCEPTION 'supresión: nada que buscar (term, phone, phone_hash o admin_id)' USING ERRCODE = '22023';
    END IF;
    IF v_term <> '' THEN
        IF char_length(btrim(v_term)) < 4 THEN
            RAISE EXCEPTION 'supresión: el término tiene que tener al menos 4 caracteres (uno corto casaría con medio libro)' USING ERRCODE = '22023';
        END IF;
        IF v_term ~ '["\\]' OR v_term ~ '[[:cntrl:]]' THEN
            RAISE EXCEPTION 'supresión: el término no admite comillas dobles, barras invertidas ni controles' USING ERRCODE = '22023';
        END IF;
        -- Cada carácter que no es letra, cifra ni espacio va escapado: en una ARE, «\» + no alfanumérico es literal.
        v_res := v_res || regexp_replace(btrim(v_term), '([^[:alnum:][:space:]])', '\\\1', 'g');
        v_tipos := v_tipos || 'term'::text;
    END IF;
    IF v_phone <> '' THEN
        IF v_phone !~ '^\+[1-9][0-9]{7,14}$' THEN
            RAISE EXCEPTION 'supresión: el teléfono va en E.164 (+ y de 8 a 15 cifras)' USING ERRCODE = '22023';
        END IF;
        IF v_cc !~ '^[1-9][0-9]{0,2}$' THEN
            RAISE EXCEPTION 'supresión: :cc no es un prefijo de país' USING ERRCODE = '22023';
        END IF;
        v_digits := substr(v_phone, 2);
        v_nacional := CASE WHEN v_digits LIKE v_cc || '%' THEN substr(v_digits, char_length(v_cc) + 1) ELSE v_digits END;
        IF char_length(v_nacional) < 7 THEN
            RAISE EXCEPTION 'supresión: el número nacional tiene menos de 7 cifras' USING ERRCODE = '22023';
        END IF;
        -- Los dígitos nacionales, con o sin «+57»/«57» delante y con separadores de teléfono entre cifras.
        v_res := v_res || ('(?<![0-9])(\+?' || v_cc || v_sep || ')?' || array_to_string(regexp_split_to_array(v_nacional, ''), v_sep) || '(?![0-9])');
        v_tipos := v_tipos || 'phone'::text;
    END IF;
    IF v_hash <> '' THEN
        IF v_hash !~ '^[0-9a-f]{64}$' THEN
            RAISE EXCEPTION 'supresión: :phone_hash no son 64 hexadecimales' USING ERRCODE = '22023';
        END IF;
        v_tipos := v_tipos || 'phone_hash'::text;
    END IF;
    IF v_admin_txt <> '' THEN
        IF v_admin_txt !~ '^[1-9][0-9]{0,17}$' THEN
            RAISE EXCEPTION 'supresión: :admin_id no es un id' USING ERRCODE = '22023';
        END IF;
        v_admin := v_admin_txt::bigint;
        v_tipos := v_tipos || 'admin_id'::text;
    END IF;
    SELECT count(*) INTO v_antes FROM pg_trigger tg JOIN pg_class c ON c.oid = tg.tgrelid
     WHERE NOT tg.tgisinternal AND c.relname LIKE 'ultron\_studio\_%' AND tg.tgenabled = 'O';
    RAISE NOTICE 'radicado % · búsquedas: % · disparadores del Studio activos: % · modo: %',
        v_radicado, array_to_string(v_tipos, ', '), v_antes, CASE WHEN v_dry THEN 'en seco' ELSE 'redacción' END;

    -- ── 1. Columnas de texto o JSON que la política no conoce: se avisa (una columna nueva no se salta en silencio)
    FOR r IN
        SELECT c.table_name::text AS tbl, c.column_name::text AS col
          FROM information_schema.columns c
         WHERE c.table_schema = 'public' AND c.table_name LIKE 'ultron\_studio\_%'
           AND c.data_type IN ('text', 'character varying', 'character', 'json', 'jsonb')
           AND c.column_name <> ALL (v_neutras)
           AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(v_politica) e WHERE e->>0 = c.table_name AND e->>1 = c.column_name)
         ORDER BY 1, 2
    LOOP
        RAISE WARNING 'columna sin política (no se busca en ella; avisar a devops): %.%', r.tbl, r.col;
    END LOOP;

    -- ── 2. Inventario: cuántas filas casan, por columna (nunca qué) ────────────────────────────────────────
    FOR r IN
        SELECT e->>0 AS tbl, e->>1 AS col, e->>2 AS pol, c.data_type::text AS tipo,
               c.data_type IN ('json', 'jsonb') AS es_json, c.character_maximum_length AS maxlen
          FROM jsonb_array_elements(v_politica) e
          JOIN information_schema.columns c ON c.table_schema = 'public' AND c.table_name = e->>0 AND c.column_name = e->>1
         ORDER BY 1, 2
    LOOP
        v_n := 0;
        IF array_length(v_res, 1) IS NOT NULL THEN
            -- JSON: se busca en su forma jsonb, con los textos ya decodificados («Pérez» se lee «Pérez»).
            v_expr := CASE WHEN r.es_json THEN format('%I::jsonb::text', r.col) ELSE format('%I', r.col) END;
            EXECUTE format('SELECT count(*) FROM public.%I WHERE %I IS NOT NULL AND %s ~* ANY ($1)', r.tbl, r.col, v_expr)
               INTO v_n USING v_res;
        END IF;
        IF v_n > 0 THEN
            v_hallazgos := v_hallazgos || jsonb_build_array(jsonb_build_object('tbl', r.tbl, 'col', r.col, 'pol', r.pol,
                'tipo', r.tipo, 'es_json', r.es_json, 'maxlen', r.maxlen, 'filas', v_n));
            RAISE NOTICE '  %.% · % fila(s) · %', r.tbl, r.col, v_n,
                CASE r.pol WHEN 'r' THEN CASE WHEN v_dry THEN 'se redactaría' ELSE 'se redacta' END
                           ELSE 'NO SE REDACTA (firmada o con huella): versión nueva sin el dato; decide el propietario' END;
        END IF;
    END LOOP;
    -- reply, checks y judge entran en results_hash, que se recalcula al emitir el veredicto: una ejecución SIN veredicto
    -- con un caso redactado ya no se puede juzgar (run_integrity_mismatch) y hay que repetirla. Con veredicto, nada cambia
    -- (la puerta de publicar mira el veredicto y la huella de la versión, no recalcula los resultados).
    IF array_length(v_res, 1) IS NOT NULL AND to_regclass('public.ultron_studio_lab_run_cases') IS NOT NULL THEN
        EXECUTE 'SELECT count(DISTINCT c.run_id) FROM public.ultron_studio_lab_run_cases c
                  WHERE NOT EXISTS (SELECT 1 FROM public.ultron_studio_lab_verdicts v WHERE v.run_id = c.run_id)
                    AND (coalesce(c.reply, '''') ~* ANY ($1) OR coalesce(c.checks::jsonb::text, '''') ~* ANY ($1) OR coalesce(c.judge::jsonb::text, '''') ~* ANY ($1))'
           INTO v_n USING v_res;
        IF v_n > 0 THEN
            RAISE NOTICE '  AVISO: % ejecución(es) del laboratorio SIN veredicto quedarían sin poder juzgarse (sus resultados ya no darían su results_hash): repetirlas después', v_n;
        END IF;
    END IF;
    SELECT count(*) INTO v_n FROM jsonb_array_elements(v_politica) e
     WHERE NOT EXISTS (SELECT 1 FROM information_schema.columns c WHERE c.table_schema = 'public' AND c.table_name = e->>0 AND c.column_name = e->>1);
    IF v_n > 0 THEN
        RAISE NOTICE '  (% columnas de la política no existen en esta base: un esquema anterior al Hito 6 o al 4)', v_n;
    END IF;

    -- El número canario por su huella: solo revocados.
    IF v_hash <> '' THEN
        SELECT count(*), count(*) FILTER (WHERE status <> 'revoked') INTO v_n, v_resto
          FROM public.ultron_studio_canary_numbers WHERE phone_hash = v_hash;
        RAISE NOTICE '  ultron_studio_canary_numbers por huella · % fila(s), % sin revocar', v_n, v_resto;
        IF v_resto > 0 THEN
            RAISE EXCEPTION 'supresión: el número canario está vivo (% fila(s) sin revocar): revocarlo primero desde el CRM (su huella la usa la coherencia con el motor)', v_resto
                USING ERRCODE = '55000';
        END IF;
        IF v_n > 0 THEN
            v_tablas := array_append(v_tablas, 'ultron_studio_canary_numbers');
            v_total := v_total + v_n;
        END IF;
    END IF;

    -- Una persona del personal por su id: sus instantáneas de autoría y su IP y navegador en el libro.
    IF v_admin IS NOT NULL THEN
        FOR r IN
            SELECT e->>0 AS tbl, e->>1 AS nombre, e->>2 AS id_col FROM jsonb_array_elements(v_autoria_def) e
             WHERE EXISTS (SELECT 1 FROM information_schema.columns c WHERE c.table_schema = 'public' AND c.table_name = e->>0 AND c.column_name = e->>2)
        LOOP
            EXECUTE format('SELECT count(*) FROM public.%I WHERE %I = $1 AND %I IS DISTINCT FROM $2', r.tbl, r.id_col, r.nombre)
               INTO v_n USING v_admin, v_marca;
            IF v_n > 0 THEN
                v_autoria := v_autoria || jsonb_build_array(jsonb_build_object('tbl', r.tbl, 'nombre', r.nombre, 'id_col', r.id_col, 'filas', v_n));
                v_total := v_total + v_n;
                RAISE NOTICE '  %.% (por %) · % fila(s) · %', r.tbl, r.nombre, r.id_col, v_n, CASE WHEN v_dry THEN 'se redactaría' ELSE 'se redacta' END;
            END IF;
        END LOOP;
        SELECT count(*) INTO v_n FROM public.ultron_studio_audit_events WHERE actor_admin_id = v_admin AND (ip_address IS NOT NULL OR user_agent IS NOT NULL);
        IF v_n > 0 THEN
            v_autoria := v_autoria || jsonb_build_array(jsonb_build_object('tbl', 'ultron_studio_audit_events', 'nombre', 'ip_address+user_agent', 'id_col', 'actor_admin_id', 'filas', v_n));
            v_total := v_total + v_n;
            RAISE NOTICE '  ultron_studio_audit_events.ip_address y user_agent (por actor_admin_id) · % fila(s) · %', v_n, CASE WHEN v_dry THEN 'se vaciarían' ELSE 'se vacían' END;
        END IF;
    END IF;

    SELECT coalesce(sum((h->>'filas')::bigint) FILTER (WHERE h->>'pol' = 'r'), 0), coalesce(sum((h->>'filas')::bigint) FILTER (WHERE h->>'pol' = 'i'), 0)
      INTO v_n, v_firmadas FROM jsonb_array_elements(v_hallazgos) h;
    v_total := v_total + v_n;
    RAISE NOTICE 'total: % campo(s) que redactar · % en configuración firmada o con huella (no se tocan)', v_total, v_firmadas;

    IF v_dry THEN
        RAISE NOTICE 'EN SECO: nada ha cambiado. Para redactar: \set dry 0 en el fichero de parámetros, tras un respaldo fresco.';
        RETURN;
    END IF;

    -- ── 3. Redacción, tabla a tabla, con sus disparadores de UPDATE apagados SOLO en esta transacción ─────────
    SELECT coalesce(array_agg(DISTINCT x ORDER BY x), '{}') INTO v_tablas
      FROM (SELECT h->>'tbl' AS x FROM jsonb_array_elements(v_hallazgos) h WHERE h->>'pol' = 'r'
            UNION SELECT a->>'tbl' FROM jsonb_array_elements(v_autoria) a
            UNION SELECT u FROM unnest(v_tablas) AS u) q;
    FOREACH v_tabla IN ARRAY v_tablas LOOP
        v_apagados := '{}';
        FOR t IN
            SELECT tg.tgname::text AS tgname, p.proname::text AS proname
              FROM pg_trigger tg JOIN pg_proc p ON p.oid = tg.tgfoid
             WHERE tg.tgrelid = format('public.%I', v_tabla)::regclass AND NOT tg.tgisinternal
               AND (tg.tgtype & 16) = 16 AND tg.tgenabled <> 'D'
             ORDER BY tg.tgname
        LOOP
            IF t.proname NOT IN ('ultron_studio_forbid', 'ultron_studio_lab_forbid') THEN
                RAISE EXCEPTION 'supresión: %.% es un disparador de UPDATE con otra función (%): no se apaga lo que no se conoce; avisar a database-specialist', v_tabla, t.tgname, t.proname
                    USING ERRCODE = '55000';
            END IF;
            EXECUTE format('ALTER TABLE public.%I DISABLE TRIGGER %I', v_tabla, t.tgname);
            v_apagados := v_apagados || t.tgname;
        END LOOP;
        RAISE NOTICE '  % · disparadores de UPDATE apagados en esta transacción: %', v_tabla,
            coalesce(nullif(array_to_string(v_apagados, ', '), ''), 'ninguno (la tabla admite UPDATE)');

        -- 3a. Textos y JSON que casan con el término o con el teléfono. Cada búsqueda se aplica sobre el resultado de
        --     la anterior; un JSON vuelve a validarse al convertirlo (si no lo fuera, la transacción entera se deshace).
        FOR r IN
            SELECT h->>'col' AS col, h->>'tipo' AS tipo, (h->>'es_json')::boolean AS es_json, (h->>'maxlen')::int AS maxlen
              FROM jsonb_array_elements(v_hallazgos) h WHERE h->>'tbl' = v_tabla AND h->>'pol' = 'r' ORDER BY 1
        LOOP
            v_expr := CASE WHEN r.es_json THEN format('%I::jsonb::text', r.col) ELSE format('%I', r.col) END;
            v_set := v_expr;
            FOR k IN 1 .. array_length(v_res, 1) LOOP
                v_set := format('regexp_replace(%s, $1[%s], $2, ''gi'')', v_set, k);
            END LOOP;
            v_set := CASE WHEN r.es_json THEN format('(%s)::jsonb::%s', v_set, r.tipo)
                          WHEN r.maxlen IS NOT NULL THEN format('left(%s, %s)', v_set, r.maxlen)
                          ELSE v_set END;
            EXECUTE format('UPDATE public.%I SET %I = %s WHERE %I IS NOT NULL AND %s ~* ANY ($1)', v_tabla, r.col, v_set, r.col, v_expr)
               USING v_res, v_marca;
            GET DIAGNOSTICS v_n = ROW_COUNT;
            v_cuentas := v_cuentas || jsonb_build_object(v_tabla || '.' || r.col, v_n);
        END LOOP;

        -- 3b. El número canario por su huella (solo revocados, comprobado arriba): ni alias, ni huella, ni 4 dígitos.
        IF v_tabla = 'ultron_studio_canary_numbers' AND v_hash <> '' THEN
            UPDATE public.ultron_studio_canary_numbers
               SET alias = 'suprimido',
                   phone_hash = encode(sha256(convert_to('suprimido:' || uuid::text || ':' || gen_random_uuid()::text, 'UTF8')), 'hex'),
                   phone_last4 = '0000',
                   phone_encrypted = NULL
             WHERE phone_hash = v_hash AND status = 'revoked';
            GET DIAGNOSTICS v_n = ROW_COUNT;
            v_cuentas := v_cuentas || jsonb_build_object('ultron_studio_canary_numbers.phone_hash+phone_last4+alias', v_n);
        END IF;

        -- 3c. La autoría de una persona del personal (el id se queda: es la referencia a su cuenta).
        FOR r IN
            SELECT a->>'nombre' AS nombre, a->>'id_col' AS id_col FROM jsonb_array_elements(v_autoria) a WHERE a->>'tbl' = v_tabla ORDER BY 1
        LOOP
            IF r.nombre = 'ip_address+user_agent' THEN
                UPDATE public.ultron_studio_audit_events SET ip_address = NULL, user_agent = NULL
                 WHERE actor_admin_id = v_admin AND (ip_address IS NOT NULL OR user_agent IS NOT NULL);
            ELSE
                EXECUTE format('UPDATE public.%I SET %I = $2 WHERE %I = $1 AND %I IS DISTINCT FROM $2', v_tabla, r.nombre, r.id_col, r.nombre)
                   USING v_admin, v_marca;
            END IF;
            GET DIAGNOSTICS v_n = ROW_COUNT;
            v_cuentas := v_cuentas || jsonb_build_object(v_tabla || '.' || r.nombre || ' (admin_id)', v_n);
        END LOOP;

        -- 3d. Vuelven a activarse exactamente los que se apagaron.
        FOREACH v_trig IN ARRAY v_apagados LOOP
            EXECUTE format('ALTER TABLE public.%I ENABLE TRIGGER %I', v_tabla, v_trig);
        END LOOP;
    END LOOP;

    -- ── 4. Comprobaciones antes del COMMIT (si una falla, se deshace todo) ─────────────────────────────────
    SELECT count(*) INTO v_despues FROM pg_trigger tg JOIN pg_class c ON c.oid = tg.tgrelid
     WHERE NOT tg.tgisinternal AND c.relname LIKE 'ultron\_studio\_%' AND tg.tgenabled = 'O';
    IF v_despues <> v_antes THEN
        RAISE EXCEPTION 'supresión: % disparadores activos antes y % después: se deshace todo', v_antes, v_despues USING ERRCODE = '55000';
    END IF;
    v_resto := 0;
    FOR r IN
        SELECT h->>'tbl' AS tbl, h->>'col' AS col, (h->>'es_json')::boolean AS es_json
          FROM jsonb_array_elements(v_hallazgos) h WHERE h->>'pol' = 'r'
    LOOP
        v_expr := CASE WHEN r.es_json THEN format('%I::jsonb::text', r.col) ELSE format('%I', r.col) END;
        EXECUTE format('SELECT count(*) FROM public.%I WHERE %I IS NOT NULL AND %s ~* ANY ($1)', r.tbl, r.col, v_expr) INTO v_n USING v_res;
        v_resto := v_resto + v_n;
    END LOOP;
    IF v_hash <> '' THEN
        v_resto := v_resto + (SELECT count(*) FROM public.ultron_studio_canary_numbers WHERE phone_hash = v_hash);
    END IF;
    FOR r IN
        SELECT a->>'tbl' AS tbl, a->>'nombre' AS nombre, a->>'id_col' AS id_col FROM jsonb_array_elements(v_autoria) a
         WHERE a->>'nombre' <> 'ip_address+user_agent'
    LOOP
        EXECUTE format('SELECT count(*) FROM public.%I WHERE %I = $1 AND %I IS DISTINCT FROM $2', r.tbl, r.id_col, r.nombre) INTO v_n USING v_admin, v_marca;
        v_resto := v_resto + v_n;
    END LOOP;
    IF v_admin IS NOT NULL THEN
        v_resto := v_resto + (SELECT count(*) FROM public.ultron_studio_audit_events WHERE actor_admin_id = v_admin AND (ip_address IS NOT NULL OR user_agent IS NOT NULL));
    END IF;
    IF v_resto <> 0 THEN
        RAISE EXCEPTION 'supresión: tras redactar siguen % coincidencias: se deshace todo', v_resto USING ERRCODE = '55000';
    END IF;

    -- ── 5. El registro: una fila del libro con recuentos y el radicado, nunca el dato ni sus huellas ────────
    INSERT INTO public.ultron_studio_audit_events
           (event, subject_type, subject_uuid, actor_type, actor_admin_id, actor_name, actor_role, from_status, to_status,
            content_hash, summary, diff, metadata, ip_address, user_agent, created_at)
    VALUES ('system.personal_data_redacted', 'system', NULL, 'system', NULL, 'supresion-1581', NULL, NULL, NULL, NULL,
            left(format('Supresión de datos personales (Ley 1581), radicado %s: %s campo(s) redactado(s) en %s tabla(s)',
                        v_radicado, (SELECT coalesce(sum(value::bigint), 0) FROM jsonb_each_text(v_cuentas)),
                        coalesce(array_length(v_tablas, 1), 0)), 255),
            NULL,
            json_build_object('radicado', v_radicado, 'searches', to_json(v_tipos), 'fields', v_cuentas::json,
                              'not_redacted_signed', (SELECT coalesce(json_object_agg((h->>'tbl') || '.' || (h->>'col'), (h->>'filas')::bigint), '{}'::json)
                                                        FROM jsonb_array_elements(v_hallazgos) h WHERE h->>'pol' = 'i'),
                              'tool', 'docs/ultron-studio/ops/supresion-1581.sql'),
            NULL, NULL, (now() AT TIME ZONE 'UTC'));
    RAISE NOTICE 'redactado: % · disparadores del Studio activos: % (los mismos que antes) · quedan 0 coincidencias · 1 fila en el libro (system.personal_data_redacted)',
        v_cuentas::text, v_despues;
END
$supresion$;

\if :dry
  ROLLBACK;
  \echo '== EN SECO: ROLLBACK, nada ha cambiado.'
\else
  COMMIT;
  \echo '== SUPRESIÓN CONFIRMADA (COMMIT). Siguen: publication-check, el registro del radicado y las copias (runbook §10, pasos 7 y 8).'
\endif
