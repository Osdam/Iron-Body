-- supresion-1581.sql · ULTRON STUDIO · Ley 1581 de 2012 (habeas data): SUPRESIÓN por REDACCIÓN, nunca por borrado.
--
-- Qué hace: busca los datos personales de UNA persona (un texto: nombre, correo…), de UN teléfono, de UN número canario
-- (por su huella), de UN número de la voz (por su huella `voice-phone`) o de UNA persona del personal (por su id de
-- administrador) en las 14 tablas ultron_studio_* del H3–H7 (y, desde el Hito 9, en las 7 del gobierno de estrategias y
-- en la autoría del personal de outreach_*: ver «HITO 9» abajo; y, desde el Hito 8, en las 11 tablas de la voz: ver
-- «HITO 8 · LA VOZ» abajo) y los sustituye por «[suprimido]». No borra filas: el libro, la cadena, el laboratorio y las instantáneas son de solo
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
-- ALTER TABLE … DISABLE TRIGGER es transaccional y toma SHARE ROW EXCLUSIVE sobre esa tabla hasta el COMMIT (PG 14,
-- comprobado en pg_locks el 10-11-2026; hasta entonces este párrafo decía ACCESS EXCLUSIVE): nadie más ve la tabla sin su
-- disparador; las ESCRITURAS de la aplicación en esa tabla esperan unos milisegundos y las lecturas no esperan
-- (lock_timeout 5 s: si una transacción abierta ya escribió en ella, aborta entero sin cambiar nada y se repite). No toca
-- la definición de ningún disparador ni de ninguna función.
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
--   \set voice_phone_hash '<64 hex>' la huella `voice-phone` de un número de la VOZ (Hito 8; NO es la del canario: la
--                                    calcula la aplicación con App\Services\Telephony\VoicePhone::hash, runbook paso 2b).
--                                    Contactos solo RETIRADOS y llamadas solo TERMINADAS (lo vivo aborta): huella → una
--                                    aleatoria (64 hex), 4 dígitos → 0000. IRREVERSIBLE. Ver «HITO 8 · LA VOZ» abajo
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
--
-- HITO 9 (OPS9-b; contrato H9 D9-15, L9-4, L9-5, L9-30; informe-db.md §13.2; m-5 de REV9 y R-3 de SEC9). Dos añadidos:
--   A) El bloque de TEXTO de arriba conoce también las 7 tablas del gobierno de estrategias (ultron_studio_strategies,
--      _message_templates, _person_bindings, _strategy_versions, _strategy_simulations, _strategy_reviews,
--      _strategy_publications: nombres del personal, notas, motivos, borradores) y la autoría del personal en
--      outreach_brakes, outreach_jobs y outreach_outcomes (opened_by/released_by/assisted_claimed_by/recorded_by). NO se
--      redactan (se informan): ultron_studio_message_templates.content_json y ultron_studio_strategy_versions.content_json
--      (content_hash), ultron_studio_strategy_simulations.result_json (result_hash) y
--      ultron_studio_strategy_publications.effective_json (firmado: la cadena). Se apagan SOLO los disparadores de UPDATE
--      que saltarían con las columnas que se redactan (los de toda la fila y los que las nombran), nunca los demás.
--   B) Un SEGUNDO bloque, POR PERSONA, para la automatización comercial: las filas de outreach_enrollments,
--      outreach_jobs, outreach_decisions y outreach_outcomes de UNA persona (su clave interna `subject_key`, m:<id> o
--      l:<id>) pasan a la FORMA SUPRIMIDA que admite el CHECK de DB9 (`subject_key = 'x:' || id`, `subject_ref` = 64
--      ceros, sin socio ni lead, `phone_ref` a NULL y `redacted_at`, L9-5) y se borran los contadores
--      outreach_budget_counters `phone:<seudónimo>` de sus teléfonos (los seudónimos se toman de SUS trabajos ANTES de
--      redactar y, si hace falta, de :phone_refs). Nunca se borra una fila de esas cuatro tablas. Es lo que la
--      aplicación no puede hacer (columnas inmutables por disparador y REVOKE): va con el rol dueño, en la misma
--      transacción, con los disparadores que saltarían apagados solo mientras dura. ANTES, la aplicación para lo vivo:
--      `php artisan outreach:suppress-subject <clave> --apply` (cancela trabajos y cierra inscripciones): con trabajos
--      vivos o inscripciones abiertas, la redacción ABORTA. No depende de la APP_KEY: busca por la clave en claro.
--   Variables nuevas (opcionales; sin :subject el bloque B no hace nada):
--   \set subject 'm:123'             la clave interna de la persona (m:<member_id> o l:<lead_id>); la da la aplicación
--                                    (`outreach:suppress-subject <clave>` la valida e imprime los recuentos). No se imprime
--   \set phone_refs ''               seudónimos de teléfono (64 hex, separados por comas) ADEMÁS de los de sus trabajos:
--                                    los calcula la aplicación (PhoneRef::fromRaw, por la entrada estándar), nunca a mano
--   Una solicitud de un socio o prospecto suele llevar los dos bloques en la misma pasada (:term/:phone para los textos
--   del Studio y :subject para la automatización comercial); el libro recibe una fila por bloque que redacte algo.
--
-- HITO 8 · LA VOZ (B-T3 del cierre integral, database-specialist, 10-11-2026; contrato H8 D8-17 y D8-18; H-24 de
-- ULTRON_STUDIO_H8_PREPARACION_PLAN_PAGO.md). Integra, con las correcciones de su informe, la propuesta de OPS8-d
-- (`supresion-1581-voz.propuesta.sql`). Probado en PostgreSQL 14 por tests/Feature/Telephony/Schema/
-- VoiceSuppression1581Test.php (`UNIT_DB=ultron_studio_1581_voz docs/ultron-studio/ops/pgunit.sh <esa prueba>`); la
-- política columna a columna está en plans/informes-h8/informe-db-1581-voz.md. El bloque de TEXTO de arriba conoce
-- también las 11 tablas de la voz: las 4 del gobierno (ultron_studio_voice_profiles, _agents, _publications,
-- _lab_sessions; su guarda es ultron_studio_forbid) y las 7 de la telefonía (voice_contacts, voice_consents, voice_calls,
-- voice_call_events, voice_exclusions, voice_brakes, voice_rne_checks; su guarda es voice_forbid).
--   · Por :term y :phone: los textos libres (notas, motivos, evidencias, la etiqueta y la nota de revisión de un perfil,
--     la nota de un veredicto, el motivo y la puerta de una publicación de voz) y los nombres del personal.
--     voice_calls.requested_by_name solo en las llamadas que pidió una PERSONA (requested_by_type = 'admin'): la de una
--     estrategia (V9) es «Estrategia <clave>», tiene su CHECK y no es un dato personal. Se INFORMAN y no se redactan:
--     ultron_studio_voice_publications.effective_json (firmado: la cadena de voz), ultron_studio_voice_profiles.content
--     (content_hash) y transfer_destinations_encrypted (cifrado: SQL no ve dentro) y
--     ultron_studio_voice_agents.transfer_destination_hashes (parte de la compilación del agente).
--   · Por :voice_phone_hash (la huella del número, no un texto): voice_contacts (solo RETIRADOS; su cifrado ya es NULL:
--     lo obliga vct_cipher_chk), voice_exclusions, voice_rne_checks y voice_calls.remote_* (solo TERMINADAS) pasan a
--     una huella aleatoria (64 hex: vct_phone_chk, vex_phone_chk, vrne_phone_chk, vc_remote_chk) y a 4 dígitos 0000 donde
--     los hay. Un contacto sin retirar o una llamada viva (requested, ambiguous, dialing, active) con esa huella: en seco
--     se avisa («VIVO»); con :dry 0 ABORTA sin tocar nada (retirar el contacto desde el CRM; colgar la llamada o esperar a
--     que termine o se concilie). Una exclusión redactada deja de aplicarse a ese número (D8-18: solo la quita la
--     supresión). Por la huella también SE INFORMA, sin redactar: los destinos de transferencia compilados con ese número
--     (perfil y agente: el remedio es una revisión nueva del perfil sin ese destino), los textos libres de esa persona
--     que NO casan con :term/:phone (motivo de retirada, evidencia de cada consentimiento —la prueba de la
--     autorización—, nota de cada exclusión y referencia de cada consulta al RNE: repetir con el texto, o decide el
--     propietario), los enlaces al motor que se conservan (voice_contacts.source_id: vct_source_chk no admite una forma
--     suprimida) y lo que guardan los proveedores (provider_conversation_id en ElevenLabs y provider_call_sid en Twilio:
--     se borra allí, paso del runbook por confirmar). Esos recuentos van a la fila del libro (`voice_informed`).
--   · :admin_id cubre también la autoría del personal en las 11 tablas (17 columnas *_name con su *_admin_id).
--   · voice_forbid entra en la lista blanca de funciones cuyos disparadores de UPDATE se apagan SOLO dentro de la
--     transacción y SOLO si saltarían (los de INSERT de la cadena de voz y del freno —usvpub_chain_next,
--     usvpub_agents_for_calls, vbr_release_targets_open— ni se miran), y los tres recorridos (el recuento de disparadores
--     activos antes y después, el aviso de «columna sin política» y la redacción) miran también voice\_%.
--   Un mismo número puede tener DOS huellas en esta base: la del registro de canarios (:phone_hash, PhoneFingerprint) y la
--   de la voz (:voice_phone_hash, VoicePhone). Suprimirlo del todo pide las dos; si las dos son iguales, una está mal
--   calculada y el guion aborta.
--   La huella de voz sin que el número pase por la pantalla, la línea de órdenes ni el historial (paso 2b; devops lo
--   lleva al runbook §10 de informe-devops-h7.md; la orden, probada en local contra la clase real el 10-11-2026):
--     install -m 600 /dev/null /root/supresion/<radicado>.tel        # y escribir dentro el E.164, una línea, con un editor
--     sudo -u www-data php /var/www/api/backend/artisan tinker --execute='echo App\Services\Telephony\VoicePhone::hash(trim((string) fgets(STDIN))), PHP_EOL;' \
--       < /root/supresion/<radicado>.tel 2>/dev/null | tail -1 > /root/supresion/<radicado>.vhash   # (umask 077)
--     printf "\\set voice_phone_hash '%s'\n" "$(cat /root/supresion/<radicado>.vhash)" >> /root/supresion/<radicado>.psql
--     shred -u /root/supresion/<radicado>.tel /root/supresion/<radicado>.vhash
--   Antes de :dry 0, la pasada en seco tiene que enseñar «voice_contacts por huella de voz · N fila(s)» con N > 0 si la
--   persona fue contacto: con 0 en todo, la huella está mal calculada (otra APP_KEY, otro formato del número).
--
-- LA AUTOMATIZACIÓN COMERCIAL · EL MOTOR Y LO QUE LA IA GUARDA (database-specialist, 12-11-2026; hallazgo I-2 de
-- plans/informes-motores/informe-security-quejas.md; política y pruebas en plans/informes-motores/informe-db-comercial.md
-- §3; probado en PostgreSQL 14 por tests/Feature/Commercial/CommercialSuppression1581Test.php). Un TERCER bloque, al final,
-- en la misma transacción y con la misma :subject (sin ella no hace nada; no hay variables nuevas). La persona: con m:<id>,
-- el socio y sus prospectos (marketing_leads.member_id); con l:<id>, ese prospecto y, si está enlazado a un socio, el
-- socio y los demás prospectos de ese socio.
--   · commercial_events de la persona (member_id, o marketing_lead_id de uno de sus prospectos): se BORRAN. No es de solo
--     inserción, no tiene disparadores ni CHECK, nada la referencia por clave foránea y borrar el prospecto ya la borra en
--     cascada; redactarla dejaría poco más que (event, occurred_at), porque la clave de deduplicación y el payload llevan
--     ids de la persona (socio, usuario, pago, conversación, factura). Sus lectores toleran la ausencia (el planificador del
--     H9 salta un hecho sin sujeto; el motor, la bandeja y Supervisión leen por persona).
--   · marketing_ai_actions de sus prospectos: la fila SE QUEDA (su idempotency_key impide que ULTRON repita una decisión;
--     lead_id es NOT NULL y cae en cascada con el prospecto). `reason` pasa a «[suprimido]» y `metadata` conserva SOLO los
--     sellos operativos que leen otros procesos (message_id: idempotencia del análisis; studio y studio_prompts: las
--     mediciones del canario; recovery, outbound y blocked_reason: el vigía de turnos de ULTRON). Lo demás —intent,
--     temperature, objection, lead_score, main_barrier, staff_review_reason, …: lo que la IA infirió de la persona— se quita.
--   · ORDEN (security, I-2): primero la BAJA GLOBAL de la persona y después esto. Si tiene complaint_created o
--     human_requested (los frenos N6 de la escalera, complaint_recent y human_requested_recently), borrarlos levanta el
--     freno, y la baja que dejó en outreach_outcomes ya no casa con ella tras el bloque del H9 (subject_key → x:<id>). La
--     baja que sobrevive es marketing_leads.do_not_contact («no contactar» en el CRM; N1 niega con ella). Con frenos y
--     algún prospecto sin «no contactar»: en seco se avisa («SIN BAJA GLOBAL») y con :dry 0 ABORTA sin tocar nada. Un
--     socio sin prospecto no la necesita: sin el hecho lead.do_not_contact la escalera ya falla cerrada en N1.
--   · SE INFORMA y no se toca (recuentos en la salida y en la fila del libro, `commercial_informed`): las filas de la
--     persona en commercial_opportunities, commercial_segments y commercial_tool_invocations (el motor NBA, apagado).
--   · Una fila en el libro (scope 'commercial') si borra o redacta algo; la comprobación final (0 hechos de la persona y 0
--     acciones con texto o claves fuera de la lista) lo deshace todo si no cuadra.

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
\if :{?subject}
\else
  \set subject ''
\endif
\if :{?phone_refs}
\else
  \set phone_refs ''
\endif
\if :{?voice_phone_hash}
\else
  \set voice_phone_hash ''
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
       set_config('supresion.admin_id', :'admin_id', false) IS NOT NULL AS _h,
       set_config('supresion.subject', :'subject', false) IS NOT NULL AS _i,
       set_config('supresion.phone_refs', :'phone_refs', false) IS NOT NULL AS _j,
       set_config('supresion.voice_phone_hash', :'voice_phone_hash', false) IS NOT NULL AS _k \gset

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
    v_vhash     text := lower(current_setting('supresion.voice_phone_hash'));
    v_admin_txt text := current_setting('supresion.admin_id');
    v_subject   text := current_setting('supresion.subject');
    v_admin     bigint;
    v_marca     constant text := '[suprimido]';
    v_sep       constant text := '[[:space:].()-]*';
    -- Los estados vivos de una llamada (D8-13): con uno de ellos y esa huella, la redacción aborta.
    v_voz_viva  constant text[] := ARRAY['requested', 'ambiguous', 'dialing', 'active'];
    -- La política: qué columna se redacta (r) y cuál solo se informa (i, firmada o con huella). Un cuarto elemento,
    -- opcional, acota las FILAS en las que se busca (SQL constante de este fichero, nunca un parámetro).
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
        ["ultron_studio_drafts", "content", "r"],
        ["ultron_studio_person_bindings", "bootstrap_authorization", "r"], ["ultron_studio_person_bindings", "revoke_reason", "r"],
        ["ultron_studio_person_bindings", "created_by_name", "r"], ["ultron_studio_person_bindings", "verified_by_name", "r"],
        ["ultron_studio_person_bindings", "revoked_by_name", "r"],
        ["ultron_studio_strategies", "name", "r"], ["ultron_studio_strategies", "draft_json", "r"],
        ["ultron_studio_strategies", "created_by_name", "r"], ["ultron_studio_strategies", "draft_updated_by_name", "r"],
        ["ultron_studio_strategies", "archived_by_name", "r"],
        ["ultron_studio_message_templates", "review_note", "r"], ["ultron_studio_message_templates", "created_by_name", "r"],
        ["ultron_studio_message_templates", "reviewed_by_name", "r"], ["ultron_studio_message_templates", "content_json", "i"],
        ["ultron_studio_strategy_versions", "validation_json", "r"], ["ultron_studio_strategy_versions", "created_by_name", "r"],
        ["ultron_studio_strategy_versions", "approved_by_name", "r"], ["ultron_studio_strategy_versions", "content_json", "i"],
        ["ultron_studio_strategy_simulations", "created_by_name", "r"], ["ultron_studio_strategy_simulations", "result_json", "i"],
        ["ultron_studio_strategy_reviews", "note", "r"], ["ultron_studio_strategy_reviews", "acknowledged_warnings", "r"],
        ["ultron_studio_strategy_reviews", "reviewer_name", "r"],
        ["ultron_studio_strategy_publications", "reason", "r"], ["ultron_studio_strategy_publications", "evidence", "r"],
        ["ultron_studio_strategy_publications", "actor_name", "r"], ["ultron_studio_strategy_publications", "effective_json", "i"],
        ["ultron_studio_voice_profiles", "label", "r"], ["ultron_studio_voice_profiles", "review_note", "r"],
        ["ultron_studio_voice_profiles", "created_by_name", "r"], ["ultron_studio_voice_profiles", "submitted_by_name", "r"],
        ["ultron_studio_voice_profiles", "reviewed_by_name", "r"], ["ultron_studio_voice_profiles", "content", "i"],
        ["ultron_studio_voice_profiles", "transfer_destinations_encrypted", "i"],
        ["ultron_studio_voice_agents", "created_by_name", "r"], ["ultron_studio_voice_agents", "transfer_destination_hashes", "i"],
        ["ultron_studio_voice_publications", "reason", "r"], ["ultron_studio_voice_publications", "evidence", "r"],
        ["ultron_studio_voice_publications", "lab_gate", "r"], ["ultron_studio_voice_publications", "actor_name", "r"],
        ["ultron_studio_voice_publications", "effective_json", "i"],
        ["ultron_studio_voice_lab_sessions", "verdict_note", "r"], ["ultron_studio_voice_lab_sessions", "verdict_by_name", "r"],
        ["ultron_studio_voice_lab_sessions", "created_by_name", "r"],
        ["voice_contacts", "consent_request_evidence", "r"], ["voice_contacts", "retire_reason", "r"],
        ["voice_contacts", "registered_by_name", "r"], ["voice_contacts", "consent_requested_by_name", "r"],
        ["voice_contacts", "retired_by_name", "r"],
        ["voice_rne_checks", "evidence_ref", "r"], ["voice_rne_checks", "actor_name", "r"],
        ["voice_calls", "requested_by_name", "r", "requested_by_type = ''admin''"],
        ["voice_call_events", "codes", "r"], ["voice_call_events", "actor_name", "r"],
        ["voice_consents", "evidence", "r"], ["voice_consents", "recorded_by_name", "r"], ["voice_consents", "confirmed_by_name", "r"],
        ["voice_exclusions", "note", "r"], ["voice_exclusions", "actor_name", "r"],
        ["voice_brakes", "note", "r"], ["voice_brakes", "evidence", "r"], ["voice_brakes", "actor_name", "r"]
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
        ["ultron_studio_rules", "created_by_name", "created_by_admin_id"], ["ultron_studio_rules", "updated_by_name", "updated_by_admin_id"],
        ["ultron_studio_person_bindings", "created_by_name", "created_by_admin_id"], ["ultron_studio_person_bindings", "verified_by_name", "verified_by_admin_id"],
        ["ultron_studio_person_bindings", "revoked_by_name", "revoked_by_admin_id"],
        ["ultron_studio_strategies", "created_by_name", "created_by_admin_id"], ["ultron_studio_strategies", "draft_updated_by_name", "draft_updated_by_admin_id"],
        ["ultron_studio_strategies", "archived_by_name", "archived_by_admin_id"],
        ["ultron_studio_message_templates", "created_by_name", "created_by_admin_id"], ["ultron_studio_message_templates", "reviewed_by_name", "reviewed_by_admin_id"],
        ["ultron_studio_strategy_versions", "created_by_name", "created_by_admin_id"], ["ultron_studio_strategy_versions", "approved_by_name", "approved_by_admin_id"],
        ["ultron_studio_strategy_simulations", "created_by_name", "created_by_admin_id"],
        ["ultron_studio_strategy_reviews", "reviewer_name", "reviewer_admin_id"],
        ["ultron_studio_strategy_publications", "actor_name", "actor_admin_id"],
        ["outreach_brakes", "opened_by_name", "opened_by_admin_id"], ["outreach_brakes", "released_by_name", "released_by_admin_id"],
        ["outreach_jobs", "assisted_claimed_by_name", "assisted_claimed_by_admin_id"],
        ["outreach_outcomes", "recorded_by_name", "recorded_by_admin_id"],
        ["ultron_studio_voice_profiles", "created_by_name", "created_by_admin_id"], ["ultron_studio_voice_profiles", "submitted_by_name", "submitted_by_admin_id"],
        ["ultron_studio_voice_profiles", "reviewed_by_name", "reviewed_by_admin_id"], ["ultron_studio_voice_agents", "created_by_name", "created_by_admin_id"],
        ["ultron_studio_voice_publications", "actor_name", "actor_admin_id"], ["ultron_studio_voice_lab_sessions", "verdict_by_name", "verdict_by_admin_id"],
        ["ultron_studio_voice_lab_sessions", "created_by_name", "created_by_admin_id"],
        ["voice_contacts", "registered_by_name", "registered_by_admin_id"], ["voice_contacts", "consent_requested_by_name", "consent_requested_by_admin_id"],
        ["voice_contacts", "retired_by_name", "retired_by_admin_id"], ["voice_rne_checks", "actor_name", "actor_admin_id"],
        ["voice_calls", "requested_by_name", "requested_by_admin_id"], ["voice_call_events", "actor_name", "actor_admin_id"],
        ["voice_consents", "recorded_by_name", "recorded_by_admin_id"], ["voice_consents", "confirmed_by_name", "confirmed_by_admin_id"],
        ["voice_exclusions", "actor_name", "actor_admin_id"], ["voice_brakes", "actor_name", "actor_admin_id"]
    ]';
    -- Columnas de texto o JSON que no llevan datos personales (enumeraciones, códigos, huellas, ids, roles).
    v_neutras   constant text[] := ARRAY['event', 'subject_type', 'actor_type', 'actor_role', 'from_status', 'to_status', 'status',
        'action', 'engine_contract', 'catalog_version', 'kind', 'provider', 'outcome', 'channel', 'country_code',
        'phone_hash', 'phone_last4', 'phone_encrypted', 'decision', 'mode', 'model', 'prompt_version', 'prices',
        'code', 'source', 'enforcement', 'type', 'priority', 'origin', 'category', 'target_kind', 'stop_reason',
        'ai_recommendation', 'error_code', 'scenario_code', 'ad_account_id', 'campaign_id', 'created_by_role',
        'deactivated_by_role', 'registered_by_role', 'authorized_by_role', 'activated_by_role', 'revoked_by_role',
        'reviewer_role', 'content_hash', 'effective_hash', 'signature', 'fingerprint', 'ip_address',
        'catalog_hash', 'target_content_hash', 'version_content_hash', 'results_hash',
        -- Hito 9: claves, versiones, huellas y roles del gobierno de estrategias.
        'key', 'purpose', 'simulation_gate', 'studio_ref_kind', 'evaluator_version', 'prev_hash', 'input_hash', 'result_hash',
        'simulation_hash', 'studio_content_hash', 'reviewed_by_role', 'approved_by_role',
        -- Hito 8: códigos, estados, ids del proveedor, huellas de la compilación y roles de la voz. Las huellas y los 4
        -- dígitos del teléfono (remote_*, phone_*) no se buscan como texto: van por :voice_phone_hash.
        'direction', 'target_source', 'stamp_state', 'policy_reason', 'termination_reason', 'end_reason', 'failure_code',
        'guard_codes', 'verdict', 'language', 'compiler_version', 'provider_agent_id', 'provider_conversation_id',
        'provider_call_sid', 'observed_agent_id', 'observed_version_id', 'observed_branch_id', 'observed_hash', 'spec_hash',
        'payload_hash', 'projection_hash', 'voice_profile_hash', 'remote_hash', 'remote_last4', 'source_type',
        'consent_request_source', 'requested_by_type', 'requested_by_role', 'submitted_by_role', 'verdict_by_role',
        'recorded_by_role', 'confirmed_by_role', 'retired_by_role', 'consent_requested_by_role'];
    -- Lo mismo, pero solo en ESA tabla (el nombre de la columna es texto libre en otras: reason, result, scope…).
    v_neutras_tc constant text[] := ARRAY['voice_brakes.reason', 'voice_brakes.open_key', 'voice_rne_checks.result',
        'voice_rne_checks.method', 'voice_call_events.tool', 'voice_call_events.provider_event_key', 'voice_calls.part',
        'ultron_studio_voice_publications.scope'];
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
    v_cols      text[];
    v_antes     bigint;
    v_despues   bigint;
    v_resto     bigint;
    v_tabla     text;
    v_expr      text;
    v_set       text;
    v_trig      text;
    v_filtro    text;
    -- Hito 8: lo que se redacta por la huella de voz (tabla → filas), lo vivo que lo impide y lo que solo se informa.
    v_voz       jsonb := '{}';
    v_voz_vivos bigint := 0;
    v_voz_info  jsonb := '{}';
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
    IF v_term = '' AND v_phone = '' AND v_hash = '' AND v_vhash = '' AND v_admin_txt = '' THEN
        IF v_subject <> '' THEN
            -- Hito 9: solo la supresión por persona de la automatización comercial (el bloque siguiente).
            RAISE NOTICE 'radicado % · sin término, teléfono, huella ni personal: este bloque no busca nada; sigue el bloque del Hito 9 (subject)', v_radicado;
            RETURN;
        END IF;
        RAISE EXCEPTION 'supresión: nada que buscar (term, phone, phone_hash, voice_phone_hash, admin_id o, para la automatización comercial del Hito 9, subject)' USING ERRCODE = '22023';
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
    IF v_vhash <> '' THEN
        IF v_vhash !~ '^[0-9a-f]{64}$' THEN
            RAISE EXCEPTION 'supresión: :voice_phone_hash no son 64 hexadecimales' USING ERRCODE = '22023';
        END IF;
        IF v_vhash = v_hash THEN
            RAISE EXCEPTION 'supresión: :voice_phone_hash y :phone_hash son la misma huella: una de las dos está mal calculada (la de la voz es VoicePhone::hash, la del canario PhoneFingerprint::of; son etiquetas distintas)'
                USING ERRCODE = '22023';
        END IF;
        IF to_regclass('public.voice_contacts') IS NULL OR to_regclass('public.voice_calls') IS NULL
           OR to_regclass('public.voice_exclusions') IS NULL OR to_regclass('public.voice_rne_checks') IS NULL THEN
            RAISE EXCEPTION 'supresión: :voice_phone_hash sin las tablas de la voz en esta base (¿el Hito 8 sin migrar?)' USING ERRCODE = '42P01';
        END IF;
        v_tipos := v_tipos || 'voice_phone_hash'::text;
    END IF;
    IF v_admin_txt <> '' THEN
        IF v_admin_txt !~ '^[1-9][0-9]{0,17}$' THEN
            RAISE EXCEPTION 'supresión: :admin_id no es un id' USING ERRCODE = '22023';
        END IF;
        v_admin := v_admin_txt::bigint;
        v_tipos := v_tipos || 'admin_id'::text;
    END IF;
    SELECT count(*) INTO v_antes FROM pg_trigger tg JOIN pg_class c ON c.oid = tg.tgrelid
     WHERE NOT tg.tgisinternal AND (c.relname LIKE 'ultron\_studio\_%' OR c.relname LIKE 'outreach\_%' OR c.relname LIKE 'voice\_%') AND tg.tgenabled = 'O';
    RAISE NOTICE 'radicado % · búsquedas: % · disparadores del Studio, de outreach y de la voz activos: % · modo: %',
        v_radicado, array_to_string(v_tipos, ', '), v_antes, CASE WHEN v_dry THEN 'en seco' ELSE 'redacción' END;

    -- ── 1. Columnas de texto o JSON que la política no conoce: se avisa (una columna nueva no se salta en silencio)
    FOR r IN
        SELECT c.table_name::text AS tbl, c.column_name::text AS col
          FROM information_schema.columns c
         WHERE c.table_schema = 'public' AND (c.table_name LIKE 'ultron\_studio\_%' OR c.table_name LIKE 'voice\_%')
           AND c.data_type IN ('text', 'character varying', 'character', 'json', 'jsonb')
           AND c.column_name <> ALL (v_neutras)
           AND (c.table_name || '.' || c.column_name) <> ALL (v_neutras_tc)
           AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(v_politica) e WHERE e->>0 = c.table_name AND e->>1 = c.column_name)
         ORDER BY 1, 2
    LOOP
        RAISE WARNING 'columna sin política (no se busca en ella; avisar a devops): %.%', r.tbl, r.col;
    END LOOP;

    -- ── 2. Inventario: cuántas filas casan, por columna (nunca qué) ────────────────────────────────────────
    FOR r IN
        SELECT e->>0 AS tbl, e->>1 AS col, e->>2 AS pol, e->>3 AS filtro, c.data_type::text AS tipo,
               c.data_type IN ('json', 'jsonb') AS es_json, c.character_maximum_length AS maxlen
          FROM jsonb_array_elements(v_politica) e
          JOIN information_schema.columns c ON c.table_schema = 'public' AND c.table_name = e->>0 AND c.column_name = e->>1
         ORDER BY 1, 2
    LOOP
        v_n := 0;
        IF array_length(v_res, 1) IS NOT NULL THEN
            -- JSON: se busca en su forma jsonb, con los textos ya decodificados («Pérez» se lee «Pérez»).
            v_expr := CASE WHEN r.es_json THEN format('%I::jsonb::text', r.col) ELSE format('%I', r.col) END;
            -- Hito 8: el filtro de filas de la política (p. ej. solo las llamadas que pidió una persona).
            v_filtro := CASE WHEN r.filtro IS NULL THEN '' ELSE ' AND (' || r.filtro || ')' END;
            EXECUTE format('SELECT count(*) FROM public.%I WHERE %I IS NOT NULL AND %s ~* ANY ($1)%s', r.tbl, r.col, v_expr, v_filtro)
               INTO v_n USING v_res;
        END IF;
        IF v_n > 0 THEN
            v_hallazgos := v_hallazgos || jsonb_build_array(jsonb_build_object('tbl', r.tbl, 'col', r.col, 'pol', r.pol,
                'tipo', r.tipo, 'es_json', r.es_json, 'maxlen', r.maxlen, 'filas', v_n, 'filtro', r.filtro));
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
        RAISE NOTICE '  (% columnas de la política no existen en esta base: un esquema anterior al Hito 9, al 8, al 6 o al 4)', v_n;
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

    -- Hito 8 · un número de la VOZ por su huella `voice-phone`: contactos solo retirados y llamadas solo terminadas. Lo vivo
    -- se avisa aquí y, con :dry 0, aborta antes de tocar nada (más abajo).
    IF v_vhash <> '' THEN
        SELECT count(*), count(*) FILTER (WHERE status <> 'retired') INTO v_n, v_resto
          FROM public.voice_contacts WHERE phone_hash = v_vhash;
        RAISE NOTICE '  voice_contacts por huella de voz · % fila(s), % sin retirar', v_n, v_resto;
        IF v_resto > 0 THEN
            RAISE NOTICE '  VIVO: % contacto(s) de voz sin retirar con esa huella: retirarlo primero desde el CRM (su cifrado solo se vacía al retirarlo: vct_cipher_chk)', v_resto;
        END IF;
        v_voz_vivos := v_voz_vivos + v_resto;
        v_voz := v_voz || jsonb_build_object('voice_contacts', v_n - v_resto);
        SELECT count(*), count(*) FILTER (WHERE status = ANY (v_voz_viva)) INTO v_n, v_resto
          FROM public.voice_calls WHERE remote_hash = v_vhash;
        RAISE NOTICE '  voice_calls.remote_hash por huella de voz · % fila(s), % viva(s)', v_n, v_resto;
        IF v_resto > 0 THEN
            RAISE NOTICE '  VIVO: % llamada(s) en curso con esa huella (requested, ambiguous, dialing o active): colgarla, o esperar a que termine o se concilie', v_resto;
        END IF;
        v_voz_vivos := v_voz_vivos + v_resto;
        v_voz := v_voz || jsonb_build_object('voice_calls', v_n - v_resto);
        SELECT count(*) INTO v_n FROM public.voice_exclusions WHERE phone_hash = v_vhash;
        RAISE NOTICE '  voice_exclusions por huella de voz · % fila(s) · %', v_n,
            CASE WHEN v_n = 0 THEN 'nada' WHEN v_dry THEN 'se redactaría (la exclusión dejaría de aplicarse a ese número: D8-18)'
                 ELSE 'se redacta (la exclusión deja de aplicarse a ese número: D8-18)' END;
        v_voz := v_voz || jsonb_build_object('voice_exclusions', v_n);
        SELECT count(*) INTO v_n FROM public.voice_rne_checks WHERE phone_hash = v_vhash;
        RAISE NOTICE '  voice_rne_checks por huella de voz · % fila(s)', v_n;
        v_voz := v_voz || jsonb_build_object('voice_rne_checks', v_n);
        FOR r IN SELECT key AS tbl, value::bigint AS n FROM jsonb_each_text(v_voz) ORDER BY 1 LOOP
            IF r.n > 0 THEN
                v_tablas := array_append(v_tablas, r.tbl);
                v_total := v_total + r.n;
            END IF;
        END LOOP;

        -- Lo que se INFORMA por la huella y no se redacta (recuentos; van también a la fila del libro).
        --  a) la huella en la configuración compilada: un destino de transferencia (perfil y agente, con content_hash y
        --     projection_hash): el remedio es una revisión nueva del perfil sin ese destino; la vieja queda como evidencia.
        SELECT count(*) INTO v_n FROM public.ultron_studio_voice_profiles WHERE strpos(content::text, v_vhash) > 0;
        IF v_n > 0 THEN
            v_voz_info := v_voz_info || jsonb_build_object('ultron_studio_voice_profiles.content (destino de transferencia)', v_n);
        END IF;
        SELECT count(*) INTO v_n FROM public.ultron_studio_voice_agents WHERE strpos(coalesce(transfer_destination_hashes::text, ''), v_vhash) > 0;
        IF v_n > 0 THEN
            v_voz_info := v_voz_info || jsonb_build_object('ultron_studio_voice_agents.transfer_destination_hashes', v_n);
        END IF;
        --  b) los textos libres de esa persona que NO casan con :term/:phone (los que casan ya están en el inventario):
        --     ni se ven ni se adivinan desde aquí; repetir con el texto (el CRM los enseña) o decide el propietario
        --     (la evidencia de un consentimiento es la prueba de la autorización).
        SELECT count(*) INTO v_n FROM (
                SELECT retire_reason AS t FROM public.voice_contacts WHERE phone_hash = v_vhash AND retire_reason IS NOT NULL
                UNION ALL SELECT consent_request_evidence FROM public.voice_contacts WHERE phone_hash = v_vhash AND consent_request_evidence IS NOT NULL
                UNION ALL SELECT s.evidence FROM public.voice_consents s JOIN public.voice_contacts k ON k.id = s.contact_id WHERE k.phone_hash = v_vhash
                UNION ALL SELECT note FROM public.voice_exclusions WHERE phone_hash = v_vhash AND note IS NOT NULL
                UNION ALL SELECT evidence_ref FROM public.voice_rne_checks WHERE phone_hash = v_vhash
            ) q
         WHERE array_length(v_res, 1) IS NULL OR NOT (q.t ~* ANY (v_res));
        IF v_n > 0 THEN
            v_voz_info := v_voz_info || jsonb_build_object('textos de esa persona que no casan con term/phone', v_n);
        END IF;
        --  c) los enlaces al motor (lead o socio) y lo que guardan los proveedores (se borra allí, no aquí).
        SELECT count(*) INTO v_n FROM public.voice_contacts WHERE phone_hash = v_vhash AND source_id IS NOT NULL;
        IF v_n > 0 THEN
            v_voz_info := v_voz_info || jsonb_build_object('voice_contacts.source_id (enlace al motor; se conserva)', v_n);
        END IF;
        SELECT count(*) FILTER (WHERE provider_conversation_id IS NOT NULL), count(*) FILTER (WHERE provider_call_sid IS NOT NULL)
          INTO v_n, v_resto FROM public.voice_calls WHERE remote_hash = v_vhash;
        IF v_n > 0 THEN
            v_voz_info := v_voz_info || jsonb_build_object('voice_calls.provider_conversation_id (ElevenLabs)', v_n);
        END IF;
        IF v_resto > 0 THEN
            v_voz_info := v_voz_info || jsonb_build_object('voice_calls.provider_call_sid (Twilio)', v_resto);
        END IF;
        IF v_voz_info <> '{}' THEN
            RAISE NOTICE '  se INFORMA y no se redacta (huella en la configuración compilada, textos sin casar, enlaces y proveedores): %', v_voz_info::text;
        END IF;
        SELECT count(*) INTO v_n FROM public.ultron_studio_voice_profiles WHERE transfer_destinations_encrypted IS NOT NULL;
        IF v_n > 0 THEN
            RAISE NOTICE '  (% perfil(es) de voz con destinos cifrados: SQL no ve dentro; un destino se reconoce por su huella en el contenido del perfil y en el agente)', v_n;
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
    -- Hito 8: con algo VIVO de la voz con esa huella no se redacta nada (ni de la voz ni del resto: todo o nada).
    IF v_voz_vivos > 0 THEN
        RAISE EXCEPTION 'supresión: % fila(s) VIVAS de la voz con esa huella (contactos sin retirar o llamadas en curso): retirar el contacto desde el CRM y colgar la llamada o esperar a que termine; después, esta redacción', v_voz_vivos
            USING ERRCODE = '55000';
    END IF;

    -- ── 3. Redacción, tabla a tabla, con sus disparadores de UPDATE apagados SOLO en esta transacción ─────────
    SELECT coalesce(array_agg(DISTINCT x ORDER BY x), '{}') INTO v_tablas
      FROM (SELECT h->>'tbl' AS x FROM jsonb_array_elements(v_hallazgos) h WHERE h->>'pol' = 'r'
            UNION SELECT a->>'tbl' FROM jsonb_array_elements(v_autoria) a
            UNION SELECT u FROM unnest(v_tablas) AS u) q;
    -- Toda tabla que se va a tocar es del dueño: si no, ALTER TABLE fallaría a mitad de camino (y lo desharía todo);
    -- mejor decirlo antes de empezar.
    FOREACH v_tabla IN ARRAY v_tablas LOOP
        IF (SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = v_tabla) IS DISTINCT FROM v_owner THEN
            RAISE EXCEPTION 'supresión: % tiene coincidencias y no es de % (el rol dueño es otro): no se redacta nada', v_tabla, v_owner USING ERRCODE = '42501';
        END IF;
    END LOOP;
    FOREACH v_tabla IN ARRAY v_tablas LOOP
        v_apagados := '{}';
        -- Hito 9 (OPS9-b): las columnas que el UPDATE de esta tabla va a nombrar. Un disparador «BEFORE UPDATE OF col…»
        -- solo salta si el SET nombra alguna de las suyas: se apagan SOLO los que saltarían (los de toda la fila y los
        -- que nombran una columna que se redacta); los demás se quedan como están, entre ellos los de comprobación
        -- cruzada del H9 (ultron_studio_chk_*), que solo miran columnas de ids, huellas y fechas.
        SELECT coalesce(array_agg(DISTINCT q.c ORDER BY q.c), '{}') INTO v_cols FROM (
            SELECT h->>'col' AS c FROM jsonb_array_elements(v_hallazgos) h WHERE h->>'tbl' = v_tabla AND h->>'pol' = 'r'
            UNION SELECT a->>'nombre' FROM jsonb_array_elements(v_autoria) a WHERE a->>'tbl' = v_tabla AND a->>'nombre' <> 'ip_address+user_agent'
            UNION SELECT unnest(ARRAY['ip_address', 'user_agent']) WHERE EXISTS (SELECT 1 FROM jsonb_array_elements(v_autoria) a WHERE a->>'tbl' = v_tabla AND a->>'nombre' = 'ip_address+user_agent')
            UNION SELECT unnest(ARRAY['alias', 'phone_hash', 'phone_last4', 'phone_encrypted']) WHERE v_tabla = 'ultron_studio_canary_numbers' AND v_hash <> ''
            -- Hito 8: las huellas y los 4 dígitos de la voz, solo en las tablas con filas por esa huella. El cifrado de un
            -- contacto retirado ya es NULL (vct_cipher_chk): no se nombra, y vct_cipher_only_shrinks no se apaga.
            UNION SELECT unnest(ARRAY['phone_hash', 'phone_last4']) WHERE v_tabla IN ('voice_contacts', 'voice_exclusions') AND coalesce((v_voz->>v_tabla)::bigint, 0) > 0
            UNION SELECT 'phone_hash' WHERE v_tabla = 'voice_rne_checks' AND coalesce((v_voz->>v_tabla)::bigint, 0) > 0
            UNION SELECT unnest(ARRAY['remote_hash', 'remote_last4']) WHERE v_tabla = 'voice_calls' AND coalesce((v_voz->>v_tabla)::bigint, 0) > 0
        ) q;
        FOR t IN
            SELECT tg.tgname::text AS tgname, p.proname::text AS proname
              FROM pg_trigger tg JOIN pg_proc p ON p.oid = tg.tgfoid
             WHERE tg.tgrelid = format('public.%I', v_tabla)::regclass AND NOT tg.tgisinternal
               AND (tg.tgtype & 16) = 16 AND tg.tgenabled <> 'D'
               AND (cardinality(tg.tgattr::int2[]) = 0
                    OR EXISTS (SELECT 1 FROM unnest(tg.tgattr::int2[]) u(n) JOIN pg_attribute a ON a.attrelid = tg.tgrelid AND a.attnum = u.n WHERE a.attname = ANY (v_cols)))
             ORDER BY tg.tgname
        LOOP
            IF t.proname NOT IN ('ultron_studio_forbid', 'ultron_studio_lab_forbid', 'outreach_forbid', 'voice_forbid') THEN
                RAISE EXCEPTION 'supresión: %.% es un disparador de UPDATE con otra función (%) que saltaría con las columnas [%]: no se apaga lo que no se conoce; avisar a database-specialist', v_tabla, t.tgname, t.proname, array_to_string(v_cols, ', ')
                    USING ERRCODE = '55000';
            END IF;
            EXECUTE format('ALTER TABLE public.%I DISABLE TRIGGER %I', v_tabla, t.tgname);
            v_apagados := v_apagados || t.tgname;
        END LOOP;
        RAISE NOTICE '  % · columnas [%] · disparadores de UPDATE que saltarían, apagados en esta transacción: %', v_tabla, array_to_string(v_cols, ', '),
            coalesce(nullif(array_to_string(v_apagados, ', '), ''), 'ninguno (la tabla admite ese UPDATE)');

        -- 3a. Textos y JSON que casan con el término o con el teléfono. Cada búsqueda se aplica sobre el resultado de
        --     la anterior; un JSON vuelve a validarse al convertirlo (si no lo fuera, la transacción entera se deshace).
        FOR r IN
            SELECT h->>'col' AS col, h->>'tipo' AS tipo, (h->>'es_json')::boolean AS es_json, (h->>'maxlen')::int AS maxlen, h->>'filtro' AS filtro
              FROM jsonb_array_elements(v_hallazgos) h WHERE h->>'tbl' = v_tabla AND h->>'pol' = 'r' ORDER BY 1
        LOOP
            v_expr := CASE WHEN r.es_json THEN format('%I::jsonb::text', r.col) ELSE format('%I', r.col) END;
            v_filtro := CASE WHEN r.filtro IS NULL THEN '' ELSE ' AND (' || r.filtro || ')' END;
            v_set := v_expr;
            FOR k IN 1 .. array_length(v_res, 1) LOOP
                v_set := format('regexp_replace(%s, $1[%s], $2, ''gi'')', v_set, k);
            END LOOP;
            v_set := CASE WHEN r.es_json THEN format('(%s)::jsonb::%s', v_set, r.tipo)
                          WHEN r.maxlen IS NOT NULL THEN format('left(%s, %s)', v_set, r.maxlen)
                          ELSE v_set END;
            EXECUTE format('UPDATE public.%I SET %I = %s WHERE %I IS NOT NULL AND %s ~* ANY ($1)%s', v_tabla, r.col, v_set, r.col, v_expr, v_filtro)
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

        -- 3b-voz (Hito 8). Un número de la voz por su huella: una huella aleatoria por fila (64 hex, como exigen sus CHECK)
        -- y 4 dígitos 0000 donde los hay. Solo contactos retirados y llamadas terminadas (lo vivo ya abortó arriba; si algo
        -- se volvió vivo entre medias, la comprobación final lo ve y lo deshace todo). El cifrado de un contacto retirado ya
        -- es NULL (lo obliga vct_cipher_chk): no se escribe.
        IF v_vhash <> '' AND coalesce((v_voz->>v_tabla)::bigint, 0) > 0 THEN
            CASE v_tabla
                WHEN 'voice_contacts' THEN
                    UPDATE public.voice_contacts
                       SET phone_hash = encode(sha256(convert_to('suprimido:' || uuid::text || ':' || gen_random_uuid()::text, 'UTF8')), 'hex'),
                           phone_last4 = '0000'
                     WHERE phone_hash = v_vhash AND status = 'retired';
                    GET DIAGNOSTICS v_n = ROW_COUNT;
                    v_cuentas := v_cuentas || jsonb_build_object('voice_contacts.phone_hash+phone_last4', v_n);
                WHEN 'voice_exclusions' THEN
                    UPDATE public.voice_exclusions
                       SET phone_hash = encode(sha256(convert_to('suprimido:' || uuid::text || ':' || gen_random_uuid()::text, 'UTF8')), 'hex'),
                           phone_last4 = '0000'
                     WHERE phone_hash = v_vhash;
                    GET DIAGNOSTICS v_n = ROW_COUNT;
                    v_cuentas := v_cuentas || jsonb_build_object('voice_exclusions.phone_hash+phone_last4', v_n);
                WHEN 'voice_rne_checks' THEN
                    UPDATE public.voice_rne_checks
                       SET phone_hash = encode(sha256(convert_to('suprimido:' || uuid::text || ':' || gen_random_uuid()::text, 'UTF8')), 'hex')
                     WHERE phone_hash = v_vhash;
                    GET DIAGNOSTICS v_n = ROW_COUNT;
                    v_cuentas := v_cuentas || jsonb_build_object('voice_rne_checks.phone_hash', v_n);
                WHEN 'voice_calls' THEN
                    -- vc_remote_chk: huella y 4 dígitos van juntos; una saliente los exige; se quedan puestos.
                    UPDATE public.voice_calls
                       SET remote_hash = encode(sha256(convert_to('suprimido:' || uuid::text || ':' || gen_random_uuid()::text, 'UTF8')), 'hex'),
                           remote_last4 = '0000'
                     WHERE remote_hash = v_vhash AND status <> ALL (v_voz_viva);
                    GET DIAGNOSTICS v_n = ROW_COUNT;
                    v_cuentas := v_cuentas || jsonb_build_object('voice_calls.remote_hash+remote_last4', v_n);
                ELSE
                    NULL;
            END CASE;
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
     WHERE NOT tg.tgisinternal AND (c.relname LIKE 'ultron\_studio\_%' OR c.relname LIKE 'outreach\_%' OR c.relname LIKE 'voice\_%') AND tg.tgenabled = 'O';
    IF v_despues <> v_antes THEN
        RAISE EXCEPTION 'supresión: % disparadores activos antes y % después: se deshace todo', v_antes, v_despues USING ERRCODE = '55000';
    END IF;
    v_resto := 0;
    FOR r IN
        SELECT h->>'tbl' AS tbl, h->>'col' AS col, (h->>'es_json')::boolean AS es_json, h->>'filtro' AS filtro
          FROM jsonb_array_elements(v_hallazgos) h WHERE h->>'pol' = 'r'
    LOOP
        v_expr := CASE WHEN r.es_json THEN format('%I::jsonb::text', r.col) ELSE format('%I', r.col) END;
        v_filtro := CASE WHEN r.filtro IS NULL THEN '' ELSE ' AND (' || r.filtro || ')' END;
        EXECUTE format('SELECT count(*) FROM public.%I WHERE %I IS NOT NULL AND %s ~* ANY ($1)%s', r.tbl, r.col, v_expr, v_filtro) INTO v_n USING v_res;
        v_resto := v_resto + v_n;
    END LOOP;
    IF v_hash <> '' THEN
        v_resto := v_resto + (SELECT count(*) FROM public.ultron_studio_canary_numbers WHERE phone_hash = v_hash);
    END IF;
    -- Hito 8: ni una fila con la huella de voz en las cuatro tablas que la guardan (tampoco una que se volviera viva
    -- entre el inventario y aquí: entonces se deshace todo y se repite). El cifrado no hace falta mirarlo: solo se
    -- redactan contactos retirados y vct_cipher_chk obliga a que un retirado lo tenga a NULL.
    IF v_vhash <> '' THEN
        v_resto := v_resto + (SELECT count(*) FROM public.voice_contacts WHERE phone_hash = v_vhash)
                           + (SELECT count(*) FROM public.voice_exclusions WHERE phone_hash = v_vhash)
                           + (SELECT count(*) FROM public.voice_rne_checks WHERE phone_hash = v_vhash)
                           + (SELECT count(*) FROM public.voice_calls WHERE remote_hash = v_vhash);
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
                              'voice_informed', v_voz_info::json,
                              'tool', 'docs/ultron-studio/ops/supresion-1581.sql'),
            NULL, NULL, (now() AT TIME ZONE 'UTC'));
    RAISE NOTICE 'redactado: % · disparadores del Studio, de outreach y de la voz activos: % (los mismos que antes) · quedan 0 coincidencias · 1 fila en el libro (system.personal_data_redacted)',
        v_cuentas::text, v_despues;
END
$supresion$;

-- ══ HITO 9 · la automatización comercial: supresión POR PERSONA (subject_key), no por texto (OPS9-b; D9-15, L9-5, L9-30) ══
-- Corre en la MISMA transacción que el bloque anterior (mismo rol dueño, mismo lock_timeout, mismo COMMIT o ROLLBACK).
-- Sin :subject no hace nada. En seco solo cuenta. Con :dry 0 exige que la aplicación haya parado antes lo vivo
-- (`php artisan outreach:suppress-subject <clave> --apply`). Los seudónimos de teléfono se leen de SUS trabajos antes
-- de redactar; los contadores `phone:<seudónimo>` del día se borran (un número compartido pierde ese recuento: la otra
-- persona podría recibir hoy un contacto más; es el diseño de DB9 §13.2). Nunca borra una fila de las 4 tablas.
DO $supresion_h9$
DECLARE
    v_dry        boolean := current_setting('supresion.dry') IN ('1', 'on', 'true', 'yes');
    v_owner      text := current_setting('supresion.owner');
    v_radicado   text := current_setting('supresion.radicado');
    v_subject    text := current_setting('supresion.subject');
    v_refs_txt   text := current_setting('supresion.phone_refs');
    v_refs       text[] := '{}';
    v_scopes     text[] := '{}';
    v_ref        text;
    -- Las columnas que el UPDATE nombra (la forma suprimida de L9-5): se apaga el disparador «UPDATE OF» que las incluya.
    v_cols       constant text[] := ARRAY['subject_key', 'subject_ref', 'member_id', 'lead_id', 'phone_ref', 'redacted_at'];
    v_tablas     constant text[] := ARRAY['outreach_enrollments', 'outreach_jobs', 'outreach_decisions', 'outreach_outcomes'];
    v_tabla      text;
    t            record;
    v_trig       text;
    v_apagados   text[];
    v_n          bigint;
    v_total      bigint := 0;
    v_contadores bigint := 0;
    v_vivos      bigint := 0;
    v_abiertas   bigint := 0;
    v_cuentas    jsonb := '{}';
    v_antes      bigint;
    v_despues    bigint;
    v_resto      bigint;
BEGIN
    IF v_subject = '' THEN
        RETURN;
    END IF;
    IF current_user <> v_owner THEN
        RAISE EXCEPTION 'supresión H9: se ejecuta con el rol dueño % (current_user es %)', v_owner, current_user USING ERRCODE = '42501';
    END IF;
    IF v_radicado !~ '^[A-Za-z0-9._-]{3,40}$' THEN
        RAISE EXCEPTION 'supresión H9: falta :radicado o no tiene la forma [A-Za-z0-9._-]{3,40}' USING ERRCODE = '22023';
    END IF;
    IF v_subject !~ '^(m|l):[1-9][0-9]{0,18}$' THEN
        RAISE EXCEPTION 'supresión H9: :subject tiene que ser m:<id> o l:<id> (la clave interna de la persona, la que valida outreach:suppress-subject)' USING ERRCODE = '22023';
    END IF;
    IF to_regclass('public.outreach_jobs') IS NULL OR to_regclass('public.outreach_budget_counters') IS NULL THEN
        RAISE EXCEPTION 'supresión H9: las tablas de la automatización comercial no existen en esta base (¿la fase 1 del Hito 9 sin desplegar?)' USING ERRCODE = '42P01';
    END IF;
    FOREACH v_tabla IN ARRAY v_tablas || ARRAY['outreach_budget_counters'] LOOP
        IF (SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = v_tabla) IS DISTINCT FROM v_owner THEN
            RAISE EXCEPTION 'supresión H9: % no es de % (el rol dueño es otro)', v_tabla, v_owner USING ERRCODE = '42501';
        END IF;
    END LOOP;
    IF v_refs_txt <> '' THEN
        v_refs := regexp_split_to_array(lower(regexp_replace(v_refs_txt, '[[:space:]]', '', 'g')), ',');
        FOREACH v_ref IN ARRAY v_refs LOOP
            IF v_ref !~ '^[0-9a-f]{64}$' THEN
                RAISE EXCEPTION 'supresión H9: :phone_refs lleva un valor que no son 64 hexadecimales (los seudónimos los calcula la aplicación)' USING ERRCODE = '22023';
            END IF;
        END LOOP;
    END IF;
    -- Los seudónimos de teléfono de SUS trabajos, leídos ANTES de redactar (después ya no se pueden saber).
    SELECT coalesce(array_agg(DISTINCT phone_ref::text), '{}') INTO v_scopes FROM public.outreach_jobs WHERE subject_key = v_subject AND phone_ref IS NOT NULL;
    v_refs := ARRAY(SELECT DISTINCT x FROM unnest(v_refs || v_scopes) AS x ORDER BY x);
    v_scopes := ARRAY(SELECT 'phone:' || x FROM unnest(v_refs) AS x);
    SELECT count(*) INTO v_antes FROM pg_trigger tg JOIN pg_class c ON c.oid = tg.tgrelid
     WHERE NOT tg.tgisinternal AND (c.relname LIKE 'ultron\_studio\_%' OR c.relname LIKE 'outreach\_%' OR c.relname LIKE 'voice\_%') AND tg.tgenabled = 'O';
    RAISE NOTICE 'Hito 9 · radicado % · supresión por persona (su clave no se imprime) · seudónimos de teléfono: % · disparadores del Studio, de outreach y de la voz activos: % · modo: %',
        v_radicado, coalesce(array_length(v_refs, 1), 0), v_antes, CASE WHEN v_dry THEN 'en seco' ELSE 'redacción' END;

    -- ── 1. Inventario (cuántas, nunca cuáles) ──────────────────────────────────────────────────────────────
    FOREACH v_tabla IN ARRAY v_tablas LOOP
        EXECUTE format('SELECT count(*) FROM public.%I WHERE subject_key = $1 AND redacted_at IS NULL', v_tabla) INTO v_n USING v_subject;
        v_cuentas := v_cuentas || jsonb_build_object(v_tabla, v_n);
        v_total := v_total + v_n;
        RAISE NOTICE '  % · % fila(s) · %', v_tabla, v_n, CASE WHEN v_dry THEN 'pasarían a la forma suprimida' ELSE 'pasan a la forma suprimida' END;
    END LOOP;
    SELECT count(*) INTO v_contadores FROM public.outreach_budget_counters WHERE scope = ANY (v_scopes);
    RAISE NOTICE '  outreach_budget_counters · % contador(es) phone: de sus seudónimos · %', v_contadores, CASE WHEN v_dry THEN 'se borrarían' ELSE 'se borran' END;
    SELECT count(*) INTO v_vivos FROM public.outreach_jobs WHERE subject_key = v_subject AND status IN ('planned', 'claimed', 'dispatching');
    SELECT count(*) INTO v_abiertas FROM public.outreach_enrollments WHERE subject_key = v_subject AND status IN ('active', 'waiting');
    IF v_vivos > 0 OR v_abiertas > 0 THEN
        RAISE NOTICE '  VIVO: % trabajo(s) y % inscripción(es) abierta(s): antes de redactar los para la aplicación: php artisan outreach:suppress-subject <clave> --apply (como www-data)', v_vivos, v_abiertas;
    END IF;
    RAISE NOTICE 'total H9: % fila(s) en 4 tablas · % contador(es)', v_total, v_contadores;
    IF v_dry THEN
        RAISE NOTICE 'EN SECO (H9): nada ha cambiado.';
        RETURN;
    END IF;
    IF v_vivos > 0 OR v_abiertas > 0 THEN
        RAISE EXCEPTION 'supresión H9: la persona tiene % trabajo(s) vivo(s) y % inscripción(es) abierta(s): primero `php artisan outreach:suppress-subject <clave> --apply`, después esta redacción', v_vivos, v_abiertas USING ERRCODE = '55000';
    END IF;
    IF v_total = 0 AND v_contadores = 0 THEN
        RAISE NOTICE 'H9: nada que redactar en la automatización comercial para esa clave (ninguna fila sin redactar ni contador): este bloque no deja fila en el libro.';
        RETURN;
    END IF;

    -- ── 2. Redacción, tabla a tabla, con los disparadores que saltarían apagados SOLO en esta transacción ────
    FOREACH v_tabla IN ARRAY v_tablas || ARRAY['outreach_budget_counters'] LOOP
        v_apagados := '{}';
        FOR t IN
            SELECT tg.tgname::text AS tgname, p.proname::text AS proname
              FROM pg_trigger tg JOIN pg_proc p ON p.oid = tg.tgfoid
             WHERE tg.tgrelid = format('public.%I', v_tabla)::regclass AND NOT tg.tgisinternal AND tg.tgenabled <> 'D'
               AND ((v_tabla <> 'outreach_budget_counters' AND (tg.tgtype & 16) = 16
                     AND (cardinality(tg.tgattr::int2[]) = 0
                          OR EXISTS (SELECT 1 FROM unnest(tg.tgattr::int2[]) u(n) JOIN pg_attribute a ON a.attrelid = tg.tgrelid AND a.attnum = u.n WHERE a.attname = ANY (v_cols))))
                    OR (v_tabla = 'outreach_budget_counters' AND (tg.tgtype & 8) = 8))
             ORDER BY tg.tgname
        LOOP
            IF t.proname <> 'outreach_forbid' THEN
                RAISE EXCEPTION 'supresión H9: %.% es un disparador con otra función (%) que saltaría: no se apaga lo que no se conoce; avisar a database-specialist', v_tabla, t.tgname, t.proname USING ERRCODE = '55000';
            END IF;
            EXECUTE format('ALTER TABLE public.%I DISABLE TRIGGER %I', v_tabla, t.tgname);
            v_apagados := v_apagados || t.tgname;
        END LOOP;
        RAISE NOTICE '  % · disparadores apagados en esta transacción: %', v_tabla, coalesce(nullif(array_to_string(v_apagados, ', '), ''), 'ninguno');
        CASE v_tabla
            WHEN 'outreach_enrollments' THEN
                UPDATE public.outreach_enrollments SET subject_key = 'x:' || id::text, subject_ref = repeat('0', 64), member_id = NULL, lead_id = NULL, redacted_at = now()
                 WHERE subject_key = v_subject AND redacted_at IS NULL;
            WHEN 'outreach_jobs' THEN
                UPDATE public.outreach_jobs SET subject_key = 'x:' || id::text, subject_ref = repeat('0', 64), phone_ref = NULL, redacted_at = now()
                 WHERE subject_key = v_subject AND redacted_at IS NULL;
            WHEN 'outreach_decisions' THEN
                UPDATE public.outreach_decisions SET subject_key = 'x:' || id::text, subject_ref = repeat('0', 64), redacted_at = now()
                 WHERE subject_key = v_subject AND redacted_at IS NULL;
            WHEN 'outreach_outcomes' THEN
                UPDATE public.outreach_outcomes SET subject_key = 'x:' || id::text, subject_ref = repeat('0', 64), redacted_at = now()
                 WHERE subject_key = v_subject AND redacted_at IS NULL;
            WHEN 'outreach_budget_counters' THEN
                DELETE FROM public.outreach_budget_counters WHERE scope = ANY (v_scopes);
        END CASE;
        GET DIAGNOSTICS v_n = ROW_COUNT;
        v_cuentas := v_cuentas || jsonb_build_object(v_tabla, v_n);
        FOREACH v_trig IN ARRAY v_apagados LOOP
            EXECUTE format('ALTER TABLE public.%I ENABLE TRIGGER %I', v_tabla, v_trig);
        END LOOP;
    END LOOP;

    -- ── 3. Comprobaciones antes del COMMIT (si una falla, se deshace todo) ─────────────────────────────────
    SELECT count(*) INTO v_despues FROM pg_trigger tg JOIN pg_class c ON c.oid = tg.tgrelid
     WHERE NOT tg.tgisinternal AND (c.relname LIKE 'ultron\_studio\_%' OR c.relname LIKE 'outreach\_%' OR c.relname LIKE 'voice\_%') AND tg.tgenabled = 'O';
    IF v_despues <> v_antes THEN
        RAISE EXCEPTION 'supresión H9: % disparadores activos antes y % después: se deshace todo', v_antes, v_despues USING ERRCODE = '55000';
    END IF;
    v_resto := 0;
    FOREACH v_tabla IN ARRAY v_tablas LOOP
        EXECUTE format('SELECT count(*) FROM public.%I WHERE subject_key = $1', v_tabla) INTO v_n USING v_subject;
        v_resto := v_resto + v_n;
    END LOOP;
    v_resto := v_resto + (SELECT count(*) FROM public.outreach_budget_counters WHERE scope = ANY (v_scopes));
    IF v_resto <> 0 THEN
        RAISE EXCEPTION 'supresión H9: tras redactar siguen % fila(s) con la clave o contador(es) de sus teléfonos: se deshace todo', v_resto USING ERRCODE = '55000';
    END IF;

    -- ── 4. El registro: una fila del libro con recuentos y el radicado, nunca la clave ni los seudónimos ────
    SELECT coalesce(sum(value::bigint), 0) INTO v_total FROM jsonb_each_text(v_cuentas - 'outreach_budget_counters');
    INSERT INTO public.ultron_studio_audit_events
           (event, subject_type, subject_uuid, actor_type, actor_admin_id, actor_name, actor_role, from_status, to_status,
            content_hash, summary, diff, metadata, ip_address, user_agent, created_at)
    VALUES ('system.personal_data_redacted', 'system', NULL, 'system', NULL, 'supresion-1581', NULL, NULL, NULL, NULL,
            left(format('Supresión de datos personales (Ley 1581), radicado %s: automatización comercial, %s fila(s) en forma suprimida en 4 tabla(s) y %s contador(es) de teléfono borrado(s)',
                        v_radicado, v_total, (v_cuentas->>'outreach_budget_counters')::bigint), 255),
            NULL,
            json_build_object('radicado', v_radicado, 'scope', 'outreach', 'fields', v_cuentas::json,
                              'phone_refs', coalesce(array_length(v_refs, 1), 0), 'tool', 'docs/ultron-studio/ops/supresion-1581.sql'),
            NULL, NULL, (now() AT TIME ZONE 'UTC'));
    RAISE NOTICE 'Hito 9 · redactado: % · disparadores activos: % (los mismos que antes) · quedan 0 filas con la clave y 0 contadores · 1 fila en el libro (system.personal_data_redacted)',
        v_cuentas::text, v_despues;
END
$supresion_h9$;

-- ══ LA AUTOMATIZACIÓN COMERCIAL · el motor (commercial_events) y lo que la IA guarda (marketing_ai_actions), POR PERSONA (:subject) ══
-- Corre en la MISMA transacción, después del bloque del H9 (mismo rol dueño, lock_timeout, COMMIT o ROLLBACK). Sin :subject
-- no hace nada. En seco solo cuenta. Ver la cabecera («LA AUTOMATIZACIÓN COMERCIAL · EL MOTOR…») para la política y el ORDEN.
DO $supresion_comercial$
DECLARE
    v_dry          boolean := current_setting('supresion.dry') IN ('1', 'on', 'true', 'yes');
    v_owner        text := current_setting('supresion.owner');
    v_radicado     text := current_setting('supresion.radicado');
    v_subject      text := current_setting('supresion.subject');
    -- Los sellos operativos de marketing_ai_actions.metadata que leen otros procesos: lo único que se conserva.
    v_keep         constant text[] := ARRAY['message_id', 'studio', 'studio_prompts', 'recovery', 'outbound', 'blocked_reason'];
    -- Los hechos que la escalera lee como freno en N6 (complaint_recent, human_requested_recently).
    v_frenos       constant text[] := ARRAY['complaint_created', 'human_requested'];
    v_tabla        text;
    v_member       bigint;
    v_lead0        bigint;
    v_leads        bigint[];
    v_eventos      bigint := 0;
    v_con_freno    bigint := 0;
    v_acciones     bigint := 0;
    v_sin_baja     bigint := 0;
    v_n            bigint;
    v_informed     jsonb := '{}';
    v_cuentas      jsonb := '{}';
    v_resto        bigint;
BEGIN
    IF v_subject = '' THEN
        RETURN;
    END IF;
    IF current_user <> v_owner THEN
        RAISE EXCEPTION 'supresión (comercial): se ejecuta con el rol dueño % (current_user es %)', v_owner, current_user USING ERRCODE = '42501';
    END IF;
    IF v_subject !~ '^(m|l):[1-9][0-9]{0,18}$' THEN
        RAISE EXCEPTION 'supresión (comercial): :subject tiene que ser m:<id> o l:<id>' USING ERRCODE = '22023';
    END IF;
    IF to_regclass('public.commercial_events') IS NULL OR to_regclass('public.marketing_ai_actions') IS NULL OR to_regclass('public.marketing_leads') IS NULL THEN
        RAISE EXCEPTION 'supresión (comercial): faltan commercial_events, marketing_ai_actions o marketing_leads en esta base' USING ERRCODE = '42P01';
    END IF;
    FOREACH v_tabla IN ARRAY ARRAY['commercial_events', 'marketing_ai_actions'] LOOP
        IF (SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = v_tabla) IS DISTINCT FROM v_owner THEN
            RAISE EXCEPTION 'supresión (comercial): % no es de % (el rol dueño es otro)', v_tabla, v_owner USING ERRCODE = '42501';
        END IF;
    END LOOP;

    -- La persona: el socio y sus prospectos (nunca se imprimen).
    IF v_subject LIKE 'm:%' THEN
        v_member := substr(v_subject, 3)::bigint;
    ELSE
        v_lead0 := substr(v_subject, 3)::bigint;
        SELECT member_id INTO v_member FROM public.marketing_leads WHERE id = v_lead0;
    END IF;
    v_leads := ARRAY(SELECT id FROM public.marketing_leads WHERE id = v_lead0 OR (v_member IS NOT NULL AND member_id = v_member) ORDER BY id);

    -- ── 1. Inventario (cuántas, nunca cuáles) ──────────────────────────────────────────────────────────────
    SELECT count(*), count(*) FILTER (WHERE event = ANY (v_frenos)) INTO v_eventos, v_con_freno
      FROM public.commercial_events WHERE (v_member IS NOT NULL AND member_id = v_member) OR marketing_lead_id = ANY (v_leads);
    SELECT count(*) INTO v_acciones FROM public.marketing_ai_actions
     WHERE lead_id = ANY (v_leads)
       AND ((reason IS NOT NULL AND reason <> '[suprimido]')
            OR (metadata IS NOT NULL AND (jsonb_typeof(metadata) <> 'object'
                                          OR EXISTS (SELECT 1 FROM jsonb_object_keys(metadata) AS k WHERE k <> ALL (v_keep)))));
    SELECT count(*) INTO v_sin_baja FROM public.marketing_leads WHERE id = ANY (v_leads) AND do_not_contact IS NOT TRUE;
    FOREACH v_tabla IN ARRAY ARRAY['commercial_opportunities', 'commercial_segments', 'commercial_tool_invocations'] LOOP
        v_n := 0;
        IF to_regclass('public.' || v_tabla) IS NOT NULL THEN
            EXECUTE format('SELECT count(*) FROM public.%I WHERE ($1 IS NOT NULL AND member_id = $1) OR marketing_lead_id = ANY ($2)', v_tabla) INTO v_n USING v_member, v_leads;
        END IF;
        v_informed := v_informed || jsonb_build_object(v_tabla, v_n);
    END LOOP;
    RAISE NOTICE 'Automatización comercial (motor e IA) · radicado % · la persona: % prospecto(s)% · modo: %',
        v_radicado, coalesce(array_length(v_leads, 1), 0), CASE WHEN v_member IS NULL THEN ', sin socio' ELSE ' y su socio' END,
        CASE WHEN v_dry THEN 'en seco' ELSE 'redacción' END;
    RAISE NOTICE '  commercial_events · % hecho(s), % de ellos freno N6 (queja o petición de persona) · %', v_eventos, v_con_freno,
        CASE WHEN v_dry THEN 'se borrarían' ELSE 'se borran' END;
    RAISE NOTICE '  marketing_ai_actions · % fila(s) con motivo o metadata de la persona · % (la fila se queda; la metadata conserva solo sus sellos operativos)', v_acciones,
        CASE WHEN v_dry THEN 'se redactarían' ELSE 'se redactan' END;
    RAISE NOTICE '  se informa, no se toca: commercial_opportunities % · commercial_segments % · commercial_tool_invocations %',
        v_informed->>'commercial_opportunities', v_informed->>'commercial_segments', v_informed->>'commercial_tool_invocations';
    IF v_con_freno > 0 AND v_sin_baja > 0 THEN
        RAISE NOTICE '  SIN BAJA GLOBAL: % prospecto(s) sin «no contactar»: borrar sus frenos N6 los levantaría. Primero marcar «no contactar» en el CRM, después esta redacción', v_sin_baja;
    END IF;
    IF v_dry THEN
        RAISE NOTICE 'EN SECO (comercial): nada ha cambiado.';
        RETURN;
    END IF;
    IF v_con_freno > 0 AND v_sin_baja > 0 THEN
        RAISE EXCEPTION 'supresión (comercial): la persona tiene % freno(s) N6 y % prospecto(s) sin «no contactar»: primero la baja global (marcar «no contactar» en el CRM), después esta redacción', v_con_freno, v_sin_baja USING ERRCODE = '55000';
    END IF;
    IF v_eventos = 0 AND v_acciones = 0 THEN
        RAISE NOTICE 'Comercial: nada que borrar ni redactar para esa clave: este bloque no deja fila en el libro.';
        RETURN;
    END IF;

    -- ── 2. Borrado y redacción ────────────────────────────────────────────────────────────────────────────
    DELETE FROM public.commercial_events WHERE (v_member IS NOT NULL AND member_id = v_member) OR marketing_lead_id = ANY (v_leads);
    GET DIAGNOSTICS v_n = ROW_COUNT;
    v_cuentas := v_cuentas || jsonb_build_object('commercial_events', v_n);
    UPDATE public.marketing_ai_actions
       SET reason = CASE WHEN reason IS NULL THEN NULL ELSE '[suprimido]' END,
           metadata = CASE
               WHEN metadata IS NULL THEN NULL
               WHEN jsonb_typeof(metadata) <> 'object' THEN '{}'::jsonb
               ELSE (SELECT coalesce(jsonb_object_agg(e.k, e.v), '{}'::jsonb) FROM jsonb_each(metadata) AS e(k, v) WHERE e.k = ANY (v_keep))
           END
     WHERE lead_id = ANY (v_leads)
       AND ((reason IS NOT NULL AND reason <> '[suprimido]')
            OR (metadata IS NOT NULL AND (jsonb_typeof(metadata) <> 'object'
                                          OR EXISTS (SELECT 1 FROM jsonb_object_keys(metadata) AS k WHERE k <> ALL (v_keep)))));
    GET DIAGNOSTICS v_n = ROW_COUNT;
    v_cuentas := v_cuentas || jsonb_build_object('marketing_ai_actions', v_n);

    -- ── 3. Comprobaciones antes del COMMIT (si una falla, se deshace todo) ─────────────────────────────────
    SELECT count(*) INTO v_resto FROM public.commercial_events WHERE (v_member IS NOT NULL AND member_id = v_member) OR marketing_lead_id = ANY (v_leads);
    v_resto := v_resto + (SELECT count(*) FROM public.marketing_ai_actions
     WHERE lead_id = ANY (v_leads)
       AND ((reason IS NOT NULL AND reason <> '[suprimido]')
            OR (metadata IS NOT NULL AND (jsonb_typeof(metadata) <> 'object'
                                          OR EXISTS (SELECT 1 FROM jsonb_object_keys(metadata) AS k WHERE k <> ALL (v_keep))))));
    IF v_resto <> 0 THEN
        RAISE EXCEPTION 'supresión (comercial): tras borrar y redactar quedan % fila(s) de la persona: se deshace todo', v_resto USING ERRCODE = '55000';
    END IF;

    -- ── 4. El registro: una fila del libro con recuentos y el radicado, nunca la clave ni los ids ──────────
    INSERT INTO public.ultron_studio_audit_events
           (event, subject_type, subject_uuid, actor_type, actor_admin_id, actor_name, actor_role, from_status, to_status,
            content_hash, summary, diff, metadata, ip_address, user_agent, created_at)
    VALUES ('system.personal_data_redacted', 'system', NULL, 'system', NULL, 'supresion-1581', NULL, NULL, NULL, NULL,
            left(format('Supresión de datos personales (Ley 1581), radicado %s: automatización comercial (motor e IA), %s hecho(s) borrado(s) y %s acción(es) de la IA redactada(s)',
                        v_radicado, (v_cuentas->>'commercial_events')::bigint, (v_cuentas->>'marketing_ai_actions')::bigint), 255),
            NULL,
            json_build_object('radicado', v_radicado, 'scope', 'commercial', 'fields', v_cuentas::json,
                              'commercial_informed', v_informed::json, 'kept_metadata_keys', to_json(v_keep),
                              'tool', 'docs/ultron-studio/ops/supresion-1581.sql'),
            NULL, NULL, (now() AT TIME ZONE 'UTC'));
    RAISE NOTICE 'Comercial · borrado y redactado: % · quedan 0 hechos de la persona y 0 acciones con su texto · 1 fila en el libro (system.personal_data_redacted)', v_cuentas::text;
END
$supresion_comercial$;

\if :dry
  ROLLBACK;
  \echo '== EN SECO: ROLLBACK, nada ha cambiado.'
\else
  COMMIT;
  \echo '== SUPRESIÓN CONFIRMADA (COMMIT). Siguen: publication-check, el registro del radicado y las copias (runbook §10, pasos 7 y 8); con :subject, además `php artisan outreach:suppress-subject <clave>` en seco → 0 filas (OPS9-b); con :voice_phone_hash, además `php artisan voice:doctor --telefonia` (la cadena de voz por partes, ok), la misma pasada en seco → 0 campos y lo que la fila del libro deja en voice_informed (proveedores, textos sin casar, enlaces al motor).'
\endif
