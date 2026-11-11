<?php

namespace Tests\Feature\UltronStudio;

use App\Http\Controllers\Crm\UltronStudio\StudioRouteCount;
use App\Services\Outreach\Facts\CanaryFacts;
use App\Services\Outreach\Facts\ContactHistoryFacts;
use App\Services\Outreach\Facts\SubjectTimeline;
use App\Services\UltronStudio\StudioPublishing;
use Illuminate\Support\Facades\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * EL MOTOR NO LEE EL STUDIO (contrato §A8), SALVO UNA TABLA (contrato H5 §A9).
 *
 * El Studio describe al agente; no lo gobierna. Esa frontera tiene que poder
 * comprobarse sin leer todo el código: ningún fichero fuera de los del Studio
 * nombra sus clases (`UltronStudio`), sus tablas (`ultron_studio_…`) ni su
 * configuración (`config('ultron_studio…')`). Se mide sobre los TOKENS de PHP,
 * como `PaymentMintAuthorityTest`: los comentarios no cuentan, el código sí
 * (nombres de clase, `use`, cadenas con nombres de tabla o claves de config).
 *
 * HITO 5, PASO 1c (mismo commit que el lector): el motor lee UNA tabla del
 * Studio (`ultron_studio_publications`) desde UNA clase
 * (`UltronPublishedConfig`). Esa clase no entra en la lista blanca: tiene su
 * propia aserción, y su referencia al Studio tiene que ser EXACTAMENTE esa
 * cadena, sin escrituras; solo la nombra, fuera del Studio, `UltronCommitService`
 * (que sella). La prueba de comportamiento —una sola SELECT por commit, y
 * ninguna con la llave en `off`— vive en
 * `Tests\Feature\Marketing\UltronCommitStudioStampTest` (§A9, prueba 2).
 *
 * HITO 5, PASO 3 (mismo commit que publicar): `StudioPublishing::
 * ENGINE_READS_VERSIONS` está girada (hay código que publica y código que lee)
 * y las rutas son 38. Lo que sigue apagado POR DEFECTO son las dos llaves de
 * entorno: la del motor (`MARKETING_ULTRON_STUDIO_CONFIG=off`: no consulta
 * nada) y la del Studio (`ULTRON_STUDIO_PUBLISHING_ENABLED=false`: publicar y
 * promover, 409). Se evalúan los dos ficheros de configuración sin las
 * variables en el entorno.
 *
 * HITO 6 (contrato H6 §I9.1): las dos migraciones de las integraciones entran
 * en la lista blanca y las rutas son 56. Las clases NEUTRAS del hito
 * (`App\Services\Voice`, `App\Services\Twilio` y `PhoneFingerprint`) no
 * nombran el Studio, y el motor no las nombra a ellas.
 *
 * HITO 7 (contrato H7): entra en la lista blanca la migración de
 * endurecimiento (DB7). `routes/console.php` (OPS7) programa las tareas del
 * monitor del Studio tras su bandera, pero programa también las del motor: no
 * entra entero (m-3 de la revisión final del H7). Como el lector, tiene su
 * excepción por cadena (la bandera, una vez) y su propia prueba: lo que la
 * bandera condiciona son solo órdenes del Studio.
 *
 * HITO 8 (contrato H8, D8-2 y D8-8; SEC8): la telefonía NO entra en la lista
 * blanca. Como el lector del motor, tiene excepciones por cadena: el lector de
 * la cadena de voz (`Telephony\VoicePublishedConfig`, el puente de E4-3) nombra
 * solo la tabla de SU cadena (`ultron_studio_voice_publications`, nunca la del
 * motor) y la de sus agentes (`ultron_studio_voice_agents`), y un
 * adaptador de la telefonía puede importar el modelo de los canarios
 * (`StudioCanaryNumber`) y su normalizador puro (`StudioCanaryPhone`), nada
 * más. Las migraciones del H8 que nombran la voz (DB8, `2026_11_1*`) entran
 * por patrón hasta que DB8 fije sus nombres. Los trabajos de la voz
 * (`app/Jobs/Voice/`) no son del motor. El resto de la frontera de la
 * telefonía está en `Tests\Feature\Telephony\TelephonyIsolationTest`.
 *
 * HITO 9 (contrato H9, §9.3, D9-2, L9-28, L9-40, L9-43; SEC9-a): la
 * automatización comercial (`App\Services\Outreach`) NO entra en la lista
 * blanca. Entran por ruta exacta las dos migraciones de DB9 (Anexo B de su
 * informe) y la configuración del canal SSE del Studio
 * (`config/ultron_studio_stream.php`, un fichero del Studio como
 * `config/ultron_studio.php`). Lo demás son excepciones por fichero, como las
 * del H8: los tres adaptadores de `Outreach\Facts` que leen tablas del Studio
 * (los canarios y el historial: solo las tablas `ultron_studio_*` de SU lista
 * `TABLES`, como cadena, sin escrituras; `FactsReadOnlyTest` lo prueba con un
 * escuchador), el puente `Outreach\Run\OutreachPublishedStrategies` («como
 * E4-3 del H8»: dos ayudas puras del Studio, una vez cada una) y los nombres
 * de las cinco tablas del gobierno que la ejecución lee
 * (`Outreach\Run\OutreachTables`, sin código). `Outreach\Core` no nombra el
 * Studio (es puro). El recuento de rutas (77 → 107 con las 30 de S9 y 108 con
 * el canal SSE) y la posición del controlador SSE se adaptan EN LA RAMA DEL H9
 * (L9-36, L9-38, L9-43), no aquí. La frontera de escritura y de red del H9
 * está en `Tests\Feature\UltronStudio\OutreachIsolationTest` y en
 * `tests/Feature/Outreach/Guards/`.
 *
 * L9-96 (plantillas de Meta desde el CRM, plan §5.12 y §6 paso 5): el
 * programador nombra una SEGUNDA bandera, la de la sincronización con Meta, en
 * un bloque propio, con su propia prueba (una vez, condición entera de un `if`
 * sin `else`, y exactamente su orden). El cliente de plantillas de Meta
 * (`App\Services\Meta\Templates`) es una zona neutra.
 */
class UltronEngineIsolationTest extends TestCase
{
    /** Los únicos ficheros (o directorios, con barra final) que pueden nombrar el Studio. */
    private const ALLOWED = [
        'app/Services/UltronStudio/',
        'app/Models/UltronStudio/',
        'app/Http/Controllers/Crm/UltronStudio/',
        'app/Console/Commands/UltronStudio/',
        'app/Exceptions/UltronStudioException.php',
        'routes/ultron-studio.php',
        'config/ultron_studio.php',
        'database/migrations/2026_10_18_100001_create_ultron_studio_tables.php',
        'database/migrations/2026_10_18_100002_add_ultron_studio_guards.php',
        // Hito G (contrato G §A7): la columna `conditions` de las reglas.
        'database/migrations/2026_10_19_100001_add_conditions_to_ultron_studio_rules.php',
        // Hito 4 (contrato H4 §A8): el laboratorio.
        'database/migrations/2026_10_19_100002_create_ultron_studio_lab_tables.php',
        'database/migrations/2026_10_19_100003_add_ultron_studio_lab_guards.php',
        // Hito 5 (contrato H5 §A3): la cadena de publicaciones y sus guardas.
        'database/migrations/2026_10_25_100001_create_ultron_studio_publications_table.php',
        'database/migrations/2026_10_25_100002_add_ultron_studio_publication_guards.php',
        // Hito 6 (contrato H6 §I9.1): las integraciones comerciales y sus guardas.
        'database/migrations/2026_11_01_100001_create_ultron_studio_integration_tables.php',
        'database/migrations/2026_11_01_100002_add_ultron_studio_integration_guards.php',
        // Hito 7 (contrato H7, DB7): el endurecimiento de las tablas de solo inserción.
        'database/migrations/2026_11_02_100001_add_ultron_studio_hardening.php',
        // Enmienda M-1 del H5 (plans/STUDIO_MOTORES_PLAN.md §B.4): `uspub_engine_contract_chk` admite /1 y /2.
        'database/migrations/2026_11_16_100001_allow_engine_config_v2_in_ultron_studio_publications.php',
    ];

    /**
     * Hito 8 (contrato H8, DB8): las migraciones de la voz y de su gobierno
     * (`2026_11_1*_create_voice_*`, `…_create_ultron_studio_voice_*` y sus
     * guardas): las dos rutas exactas (REV8 m-2; DB8 fijó los nombres).
     */
    private const ALLOWED_H8_PATTERNS = [
        // REV8 m-2: las dos rutas exactas de DB8 (sin comodín: una migración futura con «voice» en el nombre no entra sola).
        'database/migrations/2026_11_10_100001_create_ultron_studio_voice_tables.php',
        'database/migrations/2026_11_10_100002_create_voice_tables.php',
    ];

    /**
     * Hito 9 (contrato H9, §9.3; DB9, Anexo B): la migración del gobierno de
     * las estrategias (sus tablas `ultron_studio_*` y la ampliación del libro)
     * y la de la ejecución `outreach_*` (sus FKs al gobierno). Rutas exactas,
     * sin comodín, como REV8 m-2. La 100003 (índices de lectura) no nombra el
     * Studio y no hace falta.
     */
    private const ALLOWED_H9_MIGRATIONS = [
        'database/migrations/2026_11_12_100001_create_ultron_studio_strategy_tables.php',
        'database/migrations/2026_11_12_100002_create_outreach_tables.php',
        // W9: la correspondencia de las plantillas del Studio con Meta (`ultron_studio_template_meta_checks`).
        'database/migrations/2026_11_13_200001_create_ultron_studio_template_meta_checks_table.php',
        // L9-96 (plantillas de Meta desde el CRM): los envíos a Meta y la ampliación de las comprobaciones.
        'database/migrations/2026_11_15_100001_create_ultron_studio_meta_template_submissions_table.php',
        'database/migrations/2026_11_15_100002_add_meta_api_source_to_ultron_studio_template_meta_checks.php',
    ];

    /**
     * Hito 9 (paquete SSE9, L9-43): la configuración del canal SSE del Studio
     * es un fichero del Studio, como `config/ultron_studio.php`. Ruta exacta.
     */
    private const ALLOWED_H9_STUDIO_FILES = ['config/ultron_studio_stream.php'];

    /**
     * Hito 9 (L9-28, L9-40; SEC9-a): los tres adaptadores de `Outreach\Facts`
     * que LEEN tablas del Studio: los canarios y los dos del historial
     * (estrategias y versiones). No entran en la lista blanca: cada uno solo
     * puede nombrar, como cadena (con o sin alias `as x`), las tablas
     * `ultron_studio_*` de SU lista `TABLES` —la misma que `FactsReadOnlyTest`
     * vigila con un escuchador de consultas—, ninguna clase del Studio ni su
     * configuración, nunca la cadena del motor, y sin llamadas que escriban o
     * bloqueen ({@see test_hito_9_los_lectores_de_facts_y_el_puente_solo_leen_sus_tablas()}).
     */
    private const FACTS_READERS = [
        'app/Services/Outreach/Facts/CanaryFacts.php' => CanaryFacts::class,
        'app/Services/Outreach/Facts/ContactHistoryFacts.php' => ContactHistoryFacts::class,
        'app/Services/Outreach/Facts/SubjectTimeline.php' => SubjectTimeline::class,
    ];

    /**
     * Hito 9 (contrato H9, D9-2 y §9.3: «el puente `OutreachPublishedStrategies`
     * (SEC9, como E4-3 del H8)»): el lector de la cadena firmada de estrategias
     * desde la ejecución. Lo único del Studio que puede nombrar son las dos
     * ayudas PURAS cuya única implementación vive en el Studio (la referencia
     * comercial de N3 y las restricciones de las plantillas), una vez cada
     * una; las tablas las toma de {@see H9_TABLE_NAMES}, nunca por su cuenta.
     * Sin escrituras ni bloqueos.
     */
    private const H9_BRIDGE = 'app/Services/Outreach/Run/OutreachPublishedStrategies.php';

    private const H9_BRIDGE_REFERENCES = [
        'T_NAME_QUALIFIED App\\Services\\UltronStudio\\StudioMessageTemplates',
        'T_NAME_QUALIFIED App\\Services\\UltronStudio\\StudioStrategyValidator',
    ];

    /**
     * Hito 9: los nombres de las cinco tablas del gobierno que la ejecución
     * LEE, una sola vez en `Outreach`, como constantes y sin código. Exactamente
     * estas, como cadena, y ninguna otra del Studio (nunca
     * `ultron_studio_publications`, la cadena del motor, ni las de la voz).
     */
    private const H9_TABLE_NAMES = 'app/Services/Outreach/Run/OutreachTables.php';

    private const H9_TABLE_NAMES_REFERENCES = [
        "T_CONSTANT_ENCAPSED_STRING 'ultron_studio_strategies'",
        "T_CONSTANT_ENCAPSED_STRING 'ultron_studio_strategy_versions'",
        "T_CONSTANT_ENCAPSED_STRING 'ultron_studio_strategy_publications'",
        "T_CONSTANT_ENCAPSED_STRING 'ultron_studio_message_templates'",
        "T_CONSTANT_ENCAPSED_STRING 'ultron_studio_person_bindings'",
        // W9: la lee solo el puente (`metaApproved()`).
        "T_CONSTANT_ENCAPSED_STRING 'ultron_studio_template_meta_checks'",
    ];

    /**
     * Hito 9 (Paquete SSE9: «los `outreach.*` los implementa B9-run sobre el
     * registro de adaptadores de SSE9 (interfaz `StreamTopic`)»): los temas de
     * la ejecución pueden importar del Studio SOLO la interfaz del canal y su
     * evento (con su cursor y su contador), nada más: ni tablas, ni
     * configuración, ni otras clases.
     */
    private const OUTREACH_STREAM_DIR = 'app/Services/Outreach/Run/Stream/';

    private const OUTREACH_STREAM_REFERENCES = [
        'T_NAME_QUALIFIED App\\Services\\UltronStudio\\Stream\\StreamTopic',
        'T_NAME_QUALIFIED App\\Services\\UltronStudio\\Stream\\StreamEvent',
        'T_NAME_QUALIFIED App\\Services\\UltronStudio\\Stream\\StreamCursors',
        'T_NAME_QUALIFIED App\\Services\\UltronStudio\\Stream\\StreamCountable',
    ];

    /** Hito 9: todo el PHP de la automatización comercial, para la prueba de que nada más de ella nombra el Studio. */
    private const OUTREACH_ZONES = ['app/Services/Outreach', 'app/Console/Commands/Outreach', 'app/Jobs/Outreach'];

    /**
     * Hito 8 (Enmienda 8, E8-1; Enmienda 9, E9-1): el arranque de la aplicación
     * registra el oyente del evento `voice.lab.post_call` de la telefonía hacia
     * el laboratorio hablado del Studio. Es la única clase del Studio que puede
     * nombrar, SOLO en este fichero y EXACTAMENTE así (SEC8-b): el `use` sin
     * alias, una vez, y el nombre corto una sola vez, dentro de
     * {@see LAB_LISTENER_CALL}. Nada más del laboratorio desde el arranque.
     */
    private const LAB_LISTENER = 'app/Providers/AppServiceProvider.php';

    private const LAB_LISTENER_REFERENCES = ['T_NAME_QUALIFIED App\\Services\\UltronStudio\\StudioVoiceLabSessions'];

    /** El único uso del laboratorio en el arranque, token a token (sin espacios ni comentarios). */
    private const LAB_LISTENER_CALL = ['Event', '::', 'listen', '(', 'VoicePostCall', '::', 'LAB_EVENT', ',', '[', 'StudioVoiceLabSessions', '::', 'class', ',', "'recordProviderReport'", ']', ')', ';'];

    /**
     * Hito 8 (contrato H8, D8-8): el lector de la cadena de voz. No está en la
     * lista blanca: lo único que puede nombrar del Studio es la tabla de SU
     * cadena (nunca `ultron_studio_publications`) y, por E4-3 (Enmienda 4;
     * SEC8-b), la de sus agentes, por el JOIN de los tres campos del sello que el
     * mensaje firmado no lleva. Una vez cada una, como cadena (en cualquier
     * orden), y SOLO en este fichero.
     */
    private const VOICE_READER = 'app/Services/Telephony/VoicePublishedConfig.php';

    private const VOICE_READER_REFERENCES = [
        "T_CONSTANT_ENCAPSED_STRING 'ultron_studio_voice_publications'",
        // E4-3: la tabla de los agentes (studio_content_hash, voice_profile_hash, compiler_version).
        "T_CONSTANT_ENCAPSED_STRING 'ultron_studio_voice_agents'",
    ];

    /**
     * Hito 8 (contrato H8, D8-2; auditoría OTP-4): lo único del Studio que un
     * fichero de la telefonía puede importar.
     */
    private const TELEPHONY_DIR = 'app/Services/Telephony/';

    private const TELEPHONY_STUDIO_REFERENCES = [
        'T_NAME_QUALIFIED App\\Models\\UltronStudio\\StudioCanaryNumber',
        'T_NAME_FULLY_QUALIFIED \\App\\Models\\UltronStudio\\StudioCanaryNumber',
        'T_NAME_QUALIFIED App\\Services\\UltronStudio\\StudioCanaryPhone',
        'T_NAME_FULLY_QUALIFIED \\App\\Services\\UltronStudio\\StudioCanaryPhone',
    ];

    /** Hito 8: los trabajos de la voz no son del motor (la frontera motor ↔ voz está en `TelephonyIsolationTest`). */
    private const NOT_ENGINE = ['app/Jobs/Voice/'];

    /**
     * Hito 7 (contrato H7, OPS7; m-3 de la revisión final del H7): el
     * programador. No está en la lista blanca porque programa también el motor:
     * lo único que puede nombrar del Studio es la bandera del monitor, una vez,
     * como cadena, y {@see test_la_bandera_del_monitor_solo_programa_ordenes_del_studio()}
     * mira lo que esa bandera condiciona.
     */
    private const SCHEDULER = 'routes/console.php';

    private const SCHEDULER_FLAG = "'ultron_studio.monitor.enabled'";

    /** La condición exacta del `if` del monitor, token a token y sin espacios: la bandera sola, sin `||` ni nada más (decisión del lead, 11-11). */
    private const SCHEDULER_CONDITION = "if((bool)config('ultron_studio.monitor.enabled',false))";

    /**
     * L9-96 (plantillas de Meta desde el CRM; plan §5.12, enmienda nombrada de
     * la guarda): la bandera de la sincronización por sondeo, en un bloque
     * PROPIO. Como la del monitor, una vez y como cadena; lo que condiciona lo
     * mira {@see test_la_bandera_de_las_plantillas_de_meta_solo_programa_su_sincronizacion()}.
     */
    private const SCHEDULER_META_FLAG = "'ultron_studio.meta_templates.sync_enabled'";

    /** En el orden en que aparecen en el fichero: el monitor y, después, las plantillas de Meta. */
    private const SCHEDULER_REFERENCES = ['T_CONSTANT_ENCAPSED_STRING '.self::SCHEDULER_FLAG, 'T_CONSTANT_ENCAPSED_STRING '.self::SCHEDULER_META_FLAG];

    /** La condición exacta del `if` de las plantillas de Meta, token a token y sin espacios: la bandera sola, sin `||` ni nada más. */
    private const SCHEDULER_META_CONDITION = "if((bool)config('ultron_studio.meta_templates.sync_enabled',false))";

    /** Lo ÚNICO que la bandera de las plantillas de Meta programa, token a token y sin espacios: su orden, sin argumentos. */
    private const SCHEDULER_META_STATEMENT = "Schedule::command('ultron-studio:meta-templates-sync')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer()";

    /**
     * Lo único que la bandera puede programar: una orden del Studio con su
     * frecuencia y sus cerrojos, con argumentos literales. Ni trabajos, ni
     * clausuras, ni variables, ni órdenes de otros.
     */
    private const SCHEDULER_STATEMENT = "/^Schedule::command\\('ultron-studio:[a-z0-9-]+'\\)(->[A-Za-z]+\\(('[^']*'|\\d+)?(,('[^']*'|\\d+))*\\))*$/";

    /**
     * Hito 6: las clases neutras del hito. Ni el motor las nombra ni ellas nombran el Studio.
     * L9-96 (plan de plantillas §6, paso 5): el cliente neutro de plantillas de Meta (`App\Services\Meta\Templates`).
     */
    private const NEUTRAL_ZONES = ['app/Services/Voice', 'app/Services/Twilio', 'app/Support/Privacy/PhoneFingerprint.php', 'app/Services/Meta/Templates'];

    /**
     * El lector del motor (contrato H5 §A4). No está en la lista blanca: lo único
     * que puede nombrar del Studio es la tabla de la cadena, una vez, como cadena.
     * Un `use` de una clase del Studio, otra tabla o su configuración lo delatan.
     */
    private const ENGINE_READER = 'app/Services/Marketing/Ultron/UltronPublishedConfig.php';

    private const ENGINE_READER_REFERENCES = ["T_CONSTANT_ENCAPSED_STRING 'ultron_studio_publications'"];

    /**
     * Fuera del Studio, los únicos que nombran al lector: él mismo, el commit, que
     * sella (§A9), y la oferta de prompts de `decide` (Enmienda M-1, M1-6).
     */
    private const ENGINE_READER_NAMERS = [
        'app/Services/Marketing/Ultron/Prompts/UltronPromptOffer.php',
        'app/Services/Marketing/Ultron/UltronCommitService.php',
        self::ENGINE_READER,
    ];

    /** Lo que sería escribir o bloquear: ninguna de estas llamadas aparece en el lector. */
    private const WRITE_CALLS = ['insert', 'insertorignore', 'insertgetid', 'insertusing', 'update', 'updateorinsert', 'upsert',
        'delete', 'truncate', 'statement', 'unprepared', 'affectingstatement', 'increment', 'decrement', 'create', 'forcecreate',
        'save', 'fill', 'forcefill', 'push', 'touch', 'destroy', 'lockforupdate', 'sharedlock'];

    /** Lo que se rastrea: todo el PHP de la aplicación que corre en producción. */
    private const SCANNED = ['app', 'routes', 'config', 'database', 'bootstrap'];

    /** Las zonas del motor: limpias del Studio salvo el lector. */
    private const ENGINE_ZONES = ['app/Services/Marketing', 'app/Jobs', 'app/Http/Controllers/Api/Internal', 'routes/marketing.php', 'routes/api.php', 'config/marketing.php'];

    public function test_fuera_del_studio_nadie_nombra_sus_clases_sus_tablas_ni_su_configuracion(): void
    {
        $fuera = [];
        $dentro = 0;
        $lectorVisto = false;
        $programadorVisto = false;
        $lectoresDeFacts = 0;

        foreach ($this->phpFiles() as $relativa) {
            $hallazgos = $this->references(base_path($relativa));
            if ($hallazgos === []) {
                continue;
            }
            if ($this->allowed($relativa)) {
                $dentro++;

                continue;
            }
            if ($relativa === self::ENGINE_READER && $this->sinLinea($hallazgos) === self::ENGINE_READER_REFERENCES) {
                $lectorVisto = true;

                continue;
            }
            if ($relativa === self::SCHEDULER && $this->sinLinea($hallazgos) === self::SCHEDULER_REFERENCES) {
                $programadorVisto = true;

                continue;
            }
            // Hito 8 (E8-1, E9-1): el oyente del laboratorio hablado en el arranque, exactamente esa referencia, una vez,
            // y usada solo en su `Event::listen` (SEC8-b).
            if ($relativa === self::LAB_LISTENER && $this->sinLinea($hallazgos) === self::LAB_LISTENER_REFERENCES && $this->onlyTheLabListener(base_path($relativa))) {
                continue;
            }
            // Hito 8: el lector de la cadena de voz y los adaptadores de los canarios de la telefonía.
            // E4-3: exactamente esas dos referencias, una vez cada una (el orden de las constantes no cuenta).
            if ($relativa === self::VOICE_READER && $this->ordenadas($this->sinLinea($hallazgos)) === $this->ordenadas(self::VOICE_READER_REFERENCES)) {
                continue;
            }
            if (str_starts_with($relativa, self::TELEPHONY_DIR) && array_diff($this->sinLinea($hallazgos), self::TELEPHONY_STUDIO_REFERENCES) === []) {
                continue;
            }
            // Hito 9 (SEC9-a): los tres lectores de Facts (solo las tablas de su lista, como cadena),
            // el puente (sus dos ayudas puras, una vez cada una) y los nombres de las cinco tablas.
            if (isset(self::FACTS_READERS[$relativa]) && $this->onlyOwnStudioTables($hallazgos, self::FACTS_READERS[$relativa])) {
                $lectoresDeFacts++;

                continue;
            }
            if ($relativa === self::H9_BRIDGE && $this->ordenadas($this->sinLinea($hallazgos)) === $this->ordenadas(self::H9_BRIDGE_REFERENCES)) {
                continue;
            }
            if ($relativa === self::H9_TABLE_NAMES && $this->ordenadas($this->sinLinea($hallazgos)) === $this->ordenadas(self::H9_TABLE_NAMES_REFERENCES)) {
                continue;
            }
            if (str_starts_with($relativa, self::OUTREACH_STREAM_DIR) && array_diff($this->sinLinea($hallazgos), self::OUTREACH_STREAM_REFERENCES) === []) {
                continue;
            }
            $fuera[$relativa] = $hallazgos;
        }

        $this->assertSame([], $fuera, "Código fuera del Studio que lo nombra (el motor solo puede leer ultron_studio_publications, desde UltronPublishedConfig; el programador, solo la bandera del monitor y la de las plantillas de Meta, una vez cada una; la automatización comercial, solo por sus tres lectores de Facts, el puente y OutreachTables):\n".json_encode($fuera, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        // La prueba ve lo que tiene que ver: si el rastreo dejara de encontrar
        // las referencias legítimas, no estaría comprobando nada.
        $this->assertGreaterThanOrEqual(17, $dentro);
        $this->assertTrue($lectorVisto, 'El rastreo no vio la única lectura del motor sobre el Studio.');
        $this->assertTrue($programadorVisto, 'El rastreo no vio las banderas del monitor y de las plantillas de Meta en el programador.');
        $this->assertSame(count(self::FACTS_READERS), $lectoresDeFacts, 'El rastreo no vio a los tres lectores de Facts (si alguno dejó de leer el Studio, sobra en FACTS_READERS).');
    }

    /**
     * Hito 7 (OPS7; m-3 de la revisión final del H7): la bandera del monitor
     * es la condición de UN `if` con su bloque, sin `else`, y lo que ese bloque
     * programa son solo órdenes del Studio. Un trabajo del motor condicionado
     * al Studio —dentro del bloque, en un `else`, con otra clave o con la misma
     * bandera repetida (esto último ya lo caza el rastreo global)— la hace caer.
     * Desde L9-96 (11-11), como la de Meta: la bandera es la condición ENTERA
     * del `if` (un `|| true` o una negación programarían con ella apagada).
     */
    public function test_la_bandera_del_monitor_solo_programa_ordenes_del_studio(): void
    {
        ['condicion' => $condicion, 'sentencias' => $sentencias] = $this->schedulerFlagBlock(self::SCHEDULER_FLAG, 'del monitor');

        // La bandera es la condición ENTERA: un `|| true` o una negación lo programarían con ella apagada.
        $this->assertSame(self::SCHEDULER_CONDITION, $condicion, 'La condición del if del monitor es la bandera y nada más.');
        $ajenas = array_values(array_filter($sentencias, static fn (string $s): bool => preg_match(self::SCHEDULER_STATEMENT, $s) !== 1));
        $this->assertSame([], $ajenas, 'La bandera del monitor programa algo que no es una orden del Studio.');
        // El monitor y la instantánea de las integraciones: si el rastreo no
        // los viera, no estaría comprobando nada.
        $this->assertGreaterThanOrEqual(2, count($sentencias));
    }

    /**
     * L9-96 (plan de plantillas §5.12; enmienda nombrada de la guarda del
     * programador): la bandera de la sincronización con Meta aparece UNA vez,
     * es la condición ENTERA de un `if` con su bloque y sin `else`, y ese
     * bloque programa EXACTAMENTE una sentencia: la orden de la sincronización
     * con su frecuencia y sus cerrojos, sin argumentos. Una segunda orden, un
     * trabajo del motor, un `else`, un argumento o una condición con algo más
     * que la bandera (que la programaría con ella apagada) la hacen caer.
     */
    public function test_la_bandera_de_las_plantillas_de_meta_solo_programa_su_sincronizacion(): void
    {
        $this->assertFileExists(base_path(self::SCHEDULER));
        $veces = 0;
        foreach ($this->significantTokens(base_path(self::SCHEDULER)) as $t) {
            if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING && $t[1] === self::SCHEDULER_META_FLAG) {
                $veces++;
            }
        }
        $this->assertSame(1, $veces, 'La bandera de las plantillas de Meta aparece una sola vez en el programador.');

        ['condicion' => $condicion, 'sentencias' => $sentencias] = $this->schedulerFlagBlock(self::SCHEDULER_META_FLAG, 'de las plantillas de Meta');

        $this->assertSame(self::SCHEDULER_META_CONDITION, $condicion, 'La condición del if de las plantillas de Meta es la bandera y nada más.');
        $this->assertSame([self::SCHEDULER_META_STATEMENT], $sentencias, 'La bandera de las plantillas de Meta programa algo más (o algo distinto) que su sincronización.');
    }

    /**
     * El bloque que condiciona una bandera del programador (H7, OPS7; L9-96):
     * la bandera es la condición de UN `if` con su bloque entre llaves y sin
     * `else`. Devuelve la condición (desde el `if` hasta la llave, sin
     * espacios) y las sentencias del bloque (sin espacios ni comentarios).
     *
     * @return array{condicion: string, sentencias: list<string>}
     */
    private function schedulerFlagBlock(string $flag, string $cual): array
    {
        $this->assertFileExists(base_path(self::SCHEDULER));
        $tokens = $this->significantTokens(base_path(self::SCHEDULER));

        $bandera = null;
        foreach ($tokens as $i => $t) {
            if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING && $t[1] === $flag) {
                $bandera = $i;
                break;
            }
        }
        $this->assertNotNull($bandera, "El programador no nombra la bandera {$cual}.");

        // Es la condición de un `if`: hacia atrás, el `if` antes que el final de otra sentencia.
        for ($i = $bandera - 1; $i >= 0 && ! (is_array($tokens[$i]) && $tokens[$i][0] === T_IF); $i--) {
            $this->assertNotContains($tokens[$i], [';', '{', '}'], "La bandera {$cual} no es la condición de un if.");
        }
        $this->assertGreaterThanOrEqual(0, $i, "La bandera {$cual} no es la condición de un if.");
        $si = $i;

        // Su bloque, entre llaves.
        for ($abre = $bandera + 1; isset($tokens[$abre]) && $tokens[$abre] !== '{'; $abre++) {
            $this->assertNotSame(';', $tokens[$abre], 'El if de la bandera no lleva un bloque entre llaves.');
        }
        $cierra = null;
        for ($j = $abre, $nivel = 0; isset($tokens[$j]); $j++) {
            $abreOtra = $tokens[$j] === '{' || (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true));
            $nivel += $abreOtra ? 1 : ($tokens[$j] === '}' ? -1 : 0);
            if ($nivel === 0) {
                $cierra = $j;
                break;
            }
        }
        $this->assertNotNull($cierra, 'El bloque de la bandera no se cierra.');
        $siguiente = $tokens[$cierra + 1] ?? null;
        $this->assertFalse(is_array($siguiente) && in_array($siguiente[0], [T_ELSE, T_ELSEIF], true), "La bandera {$cual} lleva un else: con ella apagada se programaría otra cosa.");

        $condicion = '';
        for ($j = $si; $j < $abre; $j++) {
            $condicion .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
        }

        // Lo que programa, sentencia a sentencia (sin espacios ni comentarios).
        $sentencias = [''];
        for ($j = $abre + 1; $j < $cierra; $j++) {
            if ($tokens[$j] === ';') {
                $sentencias[] = '';

                continue;
            }
            $sentencias[array_key_last($sentencias)] .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
        }

        return ['condicion' => $condicion, 'sentencias' => array_values(array_filter($sentencias, static fn (string $s): bool => $s !== ''))];
    }

    public function test_las_zonas_del_motor_estan_limpias_y_la_lista_blanca_no_esta_vieja(): void
    {
        foreach (self::ENGINE_ZONES as $zona) {
            $this->assertFileExists(base_path($zona));
            foreach ($this->phpFiles([$zona]) as $relativa) {
                if ($this->notEngine($relativa)) {
                    continue;
                }
                $esperado = $relativa === self::ENGINE_READER ? self::ENGINE_READER_REFERENCES : [];
                $this->assertSame($esperado, $this->sinLinea($this->references(base_path($relativa))), "{$relativa} nombra el Studio.");
            }
        }

        foreach (self::ALLOWED as $permitido) {
            $this->assertFileExists(base_path(rtrim($permitido, '/')), "La lista blanca nombra algo que no existe: {$permitido}");
        }
        // Hito 9: las rutas exactas de DB9 y del canal SSE tampoco pueden envejecer (un nombre cambiado dejaría la excepción muerta y la migración fuera).
        foreach ([...self::ALLOWED_H9_MIGRATIONS, ...self::ALLOWED_H9_STUDIO_FILES, ...array_keys(self::FACTS_READERS), self::H9_BRIDGE, self::H9_TABLE_NAMES] as $permitido) {
            $this->assertFileExists(base_path($permitido), "La lista blanca del Hito 9 nombra algo que no existe: {$permitido}");
        }
    }

    /**
     * Hito 9 (L9-28, L9-40, §9.3; SEC9-a): por dentro, cada lector de Facts
     * nombra del Studio SOLO tablas de su lista `TABLES` (la que
     * `FactsReadOnlyTest` prueba con un escuchador: ni otras tablas ni una
     * escritura), ninguna clase del Studio ni su configuración, nunca la cadena
     * del motor, y ninguna llamada que escriba o bloquee. El puente, lo mismo
     * con sus dos ayudas puras y sin nombrar tablas por su cuenta;
     * `OutreachTables` no tiene código. Y fuera de esos cinco ficheros, nada de
     * la automatización comercial nombra el Studio (`Core` es puro; `Run` lee
     * por el puente). Lo que cada uno ejecuta de verdad lo mira
     * `OutreachIsolationTest` con un escuchador de consultas.
     */
    public function test_hito_9_los_lectores_de_facts_y_el_puente_solo_leen_sus_tablas(): void
    {
        foreach (self::FACTS_READERS as $relativa => $clase) {
            $ruta = base_path($relativa);
            $this->assertFileExists($ruta);
            $referencias = $this->references($ruta);
            $this->assertNotSame([], $referencias, "{$relativa} ya no nombra el Studio: sobra en FACTS_READERS.");
            $this->assertTrue($this->onlyOwnStudioTables($referencias, $clase), "{$relativa} nombra del Studio algo que no es una tabla de su lista TABLES:\n".implode("\n", $referencias));
            $codigo = $this->codeOnly($ruta);
            $this->assertStringNotContainsString('UltronStudio', $codigo, "{$relativa} importa una clase del Studio.");
            $this->assertStringNotContainsString('ultron_studio.', $codigo, "{$relativa} lee la configuración del Studio.");
            $this->assertStringNotContainsString('ultron_studio_publications', $codigo, "{$relativa} lee la cadena del motor.");
            $this->assertStringNotContainsString('ultron_studio_voice_', $codigo, "{$relativa} lee las tablas de la voz.");
            $this->assertSame([], array_values(array_intersect($this->calledMethods($ruta), self::WRITE_CALLS)), "{$relativa} escribe o bloquea.");
        }

        $puente = base_path(self::H9_BRIDGE);
        $this->assertFileExists($puente);
        $this->assertSame($this->ordenadas(self::H9_BRIDGE_REFERENCES), $this->ordenadas($this->sinLinea($this->references($puente))), 'El puente nombra del Studio algo más que sus dos ayudas puras.');
        $this->assertSame([], array_values(array_intersect($this->calledMethods($puente), self::WRITE_CALLS)), 'El puente escribe o bloquea.');
        $this->assertStringNotContainsString('ultron_studio_', $this->codeOnly($puente), 'El puente nombra una tabla por su cuenta: los nombres viven en OutreachTables.');

        $nombres = base_path(self::H9_TABLE_NAMES);
        $this->assertFileExists($nombres);
        $this->assertSame($this->ordenadas(self::H9_TABLE_NAMES_REFERENCES), $this->ordenadas($this->sinLinea($this->references($nombres))), 'OutreachTables nombra otras tablas del Studio.');
        $this->assertSame([], $this->calledMethods($nombres), 'OutreachTables tiene código: solo son nombres.');

        $otros = [];
        foreach (self::OUTREACH_ZONES as $zona) {
            if (! is_dir(base_path($zona))) {
                continue;
            }
            foreach ($this->phpFiles([$zona]) as $relativa) {
                if (isset(self::FACTS_READERS[$relativa]) || in_array($relativa, [self::H9_BRIDGE, self::H9_TABLE_NAMES], true)) {
                    continue;
                }
                $referencias = $this->references(base_path($relativa));
                // Los temas `outreach.*` del canal SSE: solo la interfaz del canal (Paquete SSE9).
                if (str_starts_with($relativa, self::OUTREACH_STREAM_DIR) && array_diff($this->sinLinea($referencias), self::OUTREACH_STREAM_REFERENCES) === []) {
                    continue;
                }
                if ($referencias !== []) {
                    $otros[$relativa] = $referencias;
                }
            }
        }
        $this->assertSame([], $otros, "Más ficheros de la automatización comercial nombran el Studio:\n".json_encode($otros, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * El lector, por dentro (contrato H5 §A9): la tabla una sola vez, nada del
     * Studio importado, ninguna llamada que escriba o bloquee, y solo lo nombra
     * el commit.
     */
    public function test_la_unica_lectura_del_motor_es_de_solo_lectura_y_solo_la_usa_el_commit(): void
    {
        $lector = base_path(self::ENGINE_READER);
        $this->assertFileExists($lector);

        $codigo = $this->codeOnly($lector);
        $this->assertSame(1, substr_count($codigo, 'ultron_studio_'), 'La tabla del Studio se nombra una sola vez en el lector.');
        $this->assertStringNotContainsString('UltronStudio', $codigo);
        $this->assertSame([], array_values(array_intersect($this->calledMethods($lector), self::WRITE_CALLS)), 'El lector escribe o bloquea.');

        $nombran = [];
        foreach ($this->phpFiles() as $relativa) {
            if ($this->allowed($relativa)) {
                continue;
            }
            if (str_contains($this->codeOnly(base_path($relativa)), 'UltronPublishedConfig')) {
                $nombran[] = $relativa;
            }
        }
        $this->assertSame(self::ENGINE_READER_NAMERS, $nombran, 'Fuera del Studio, solo el commit y la oferta de prompts invocan al lector.');
    }

    /**
     * Enmienda M-1 (plan de motores §B.5): la llave de los prompts nace apagada;
     * solo `canary` la enciende (`all` incluido, todo lo demás es `off`). Se
     * evalúa config/marketing.php SIN la variable en el entorno.
     */
    public function test_la_llave_de_los_prompts_vale_off_por_defecto(): void
    {
        $this->assertSame('off', $this->configWithout('config/marketing.php', 'MARKETING_ULTRON_STUDIO_PROMPTS')['ultron']['studio_prompts']);
    }

    /**
     * La llave del motor (contrato H5 §A13) nace apagada: se evalúa
     * config/marketing.php SIN la variable en el entorno, sea cual sea el
     * entorno con el que corra la suite.
     */
    public function test_la_llave_del_motor_vale_off_por_defecto(): void
    {
        $nombre = 'MARKETING_ULTRON_STUDIO_CONFIG';
        $antes = [$_SERVER[$nombre] ?? null, $_ENV[$nombre] ?? null, getenv($nombre)];

        try {
            unset($_SERVER[$nombre], $_ENV[$nombre]);
            putenv($nombre);
            $config = require base_path('config/marketing.php');
        } finally {
            [$servidor, $entorno, $proceso] = $antes;
            if ($servidor !== null) {
                $_SERVER[$nombre] = $servidor;
            }
            if ($entorno !== null) {
                $_ENV[$nombre] = $entorno;
            }
            if ($proceso !== false) {
                putenv("{$nombre}={$proceso}");
            }
        }

        $this->assertSame('off', $config['ultron']['studio_config']);
    }

    public function test_ninguna_ruta_del_studio_cuelga_de_internal_ni_lleva_la_firma_de_n8n(): void
    {
        $delStudio = 0;
        foreach (Route::getRoutes() as $route) {
            $accion = $route->getActionName();
            $esDelStudio = str_contains($accion, '\\UltronStudio\\');

            if (str_contains($route->uri(), 'ultron-studio')) {
                $this->assertTrue($esDelStudio, "{$route->uri()} no la atiende un controlador del Studio.");
            }
            if (! $esDelStudio) {
                continue;
            }

            $delStudio++;
            $this->assertStringStartsWith('api/admin/ultron-studio/', $route->uri());
            $this->assertStringNotContainsString('internal', $route->uri());
            $this->assertNotContains('automation.internal', $route->gatherMiddleware(), $route->uri());
        }

        // Las 19 de F, las dos de lectura del Hito G (engine/readings y compare),
        // las 12 del laboratorio del Hito 4 (`lab/*`, contrato H4 §A7), las 5
        // de la publicación del Hito 5 (contrato H5 §A7) y las 18 de las
        // integraciones del Hito 6 (contrato H6 §I7.1).
        // Y las 21 del Hito 8 (contrato H8, «API»): la lista, en la constante (E2-6).
        $this->assertSame(StudioRouteCount::TOTAL, $delStudio);
    }

    /**
     * Hito 6 (contrato H6 §I9.1): ningún fichero de las zonas del motor nombra
     * la voz, el cliente de Twilio del hito ni la huella de teléfonos; y esas
     * clases neutras no nombran el Studio (ya lo cubre el rastreo global; se
     * escribe aparte para que el motivo se lea).
     */
    public function test_el_motor_no_nombra_las_clases_neutras_y_ellas_no_nombran_el_studio(): void
    {
        $vistos = 0;
        foreach (self::ENGINE_ZONES as $zona) {
            foreach ($this->phpFiles([$zona]) as $relativa) {
                if ($this->notEngine($relativa)) {
                    continue;
                }
                $codigo = $this->codeOnly(base_path($relativa));
                foreach (['App\\Services\\Voice', 'App\\Services\\Twilio', 'PhoneFingerprint', 'Services\\Voice\\', 'Services\\Twilio\\'] as $nombre) {
                    $this->assertStringNotContainsString($nombre, $codigo, "{$relativa} nombra {$nombre}.");
                }
                $vistos++;
            }
        }
        $this->assertGreaterThan(50, $vistos, 'el rastreo ve las zonas del motor');

        $neutros = 0;
        foreach (self::NEUTRAL_ZONES as $zona) {
            $this->assertFileExists(base_path($zona));
            foreach ($this->phpFiles([$zona]) as $relativa) {
                $this->assertSame([], $this->references(base_path($relativa)), "{$relativa} nombra el Studio.");
                $this->assertStringNotContainsString('ultron-studio', $this->codeOnly(base_path($relativa)), "{$relativa} nombra las rutas del Studio.");
                $neutros++;
            }
        }
        $this->assertGreaterThanOrEqual(15, $neutros, 'el rastreo ve las clases neutras');
    }

    /**
     * Hito 5 (contrato H5 §A9, prueba 1, paso 3): hay código que publica y
     * código que lee, pero el motor solo lee con SU llave y publicar nace
     * apagado. Los dos ficheros de configuración se evalúan SIN las variables
     * en el entorno, sea cual sea el entorno con el que corra la suite.
     */
    public function test_el_motor_lee_solo_con_su_llave_y_publicar_esta_apagado_por_defecto(): void
    {
        $this->assertTrue(StudioPublishing::ENGINE_READS_VERSIONS);

        $marketing = $this->configWithout('config/marketing.php', 'MARKETING_ULTRON_STUDIO_CONFIG');
        $studio = $this->configWithout('config/ultron_studio.php', 'ULTRON_STUDIO_PUBLISHING_ENABLED');
        $this->assertSame('off', $marketing['ultron']['studio_config']);
        $this->assertFalse($studio['publishing_enabled']);

        // Con esos valores, ninguna llave abre publicar ni promover.
        config(['marketing.ultron.studio_config' => $marketing['ultron']['studio_config'], 'ultron_studio.publishing_enabled' => $studio['publishing_enabled']]);
        $this->assertFalse(StudioPublishing::available());
        $this->assertSame('publishing_disabled', StudioPublishing::keyRefusal('publish')['code']);
        $this->assertSame('publishing_disabled', StudioPublishing::keyRefusal('promote')['code']);
        $this->assertSame(StudioPublishing::SCOPE_OFF, StudioPublishing::engineScope());
    }

    /**
     * Un fichero de configuración evaluado sin una variable en el entorno.
     *
     * @return array<string, mixed>
     */
    private function configWithout(string $fichero, string $nombre): array
    {
        $antes = [$_SERVER[$nombre] ?? null, $_ENV[$nombre] ?? null, getenv($nombre)];

        try {
            unset($_SERVER[$nombre], $_ENV[$nombre]);
            putenv($nombre);

            return require base_path($fichero);
        } finally {
            [$servidor, $entorno, $proceso] = $antes;
            if ($servidor !== null) {
                $_SERVER[$nombre] = $servidor;
            }
            if ($entorno !== null) {
                $_ENV[$nombre] = $entorno;
            }
            if ($proceso !== false) {
                putenv("{$nombre}={$proceso}");
            }
        }
    }

    // ────────────────────────────────────────────────────────────────────────

    /**
     * Qué nombra del Studio un fichero, mirando solo el código.
     *
     * @return list<string>
     */
    private function references(string $ruta): array
    {
        $out = [];
        foreach (token_get_all((string) file_get_contents($ruta)) as $t) {
            if (! is_array($t) || in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_INLINE_HTML], true)) {
                continue;
            }
            $texto = $t[1];
            $cadena = $t[0] === T_CONSTANT_ENCAPSED_STRING || $t[0] === T_ENCAPSED_AND_WHITESPACE;
            if (str_contains($texto, 'UltronStudio')
                || str_contains($texto, 'ultron_studio_')
                || ($cadena && preg_match('/^[\'"]?ultron_studio(\.|[\'"]?$)/', $texto) === 1)) {
                $out[] = token_name($t[0]).' '.$texto.' (línea '.$t[2].')';
            }
        }

        return $out;
    }

    /**
     * Las referencias sin su número de línea, para comparar QUÉ se nombra.
     *
     * @param  list<string>  $referencias
     * @return list<string>
     */
    private function sinLinea(array $referencias): array
    {
        return array_map(fn (string $r) => (string) preg_replace('/ \(línea \d+\)$/u', '', $r), $referencias);
    }

    /**
     * Las mismas referencias, ordenadas: compara QUÉ se nombra y cuántas veces, no en qué orden.
     *
     * @param  list<string>  $referencias
     * @return list<string>
     */
    private function ordenadas(array $referencias): array
    {
        sort($referencias);

        return $referencias;
    }

    /**
     * Hito 9 (SEC9-a): ¿todo lo que este lector de Facts nombra del Studio son
     * tablas `ultron_studio_*` de su lista `TABLES`, como cadena (con o sin
     * alias `as x`)? Una clase del Studio, su configuración u otra tabla lo
     * delatan. Una lista sin tablas del Studio no admite nada.
     *
     * @param  list<string>  $referencias
     * @param  class-string  $clase
     */
    private function onlyOwnStudioTables(array $referencias, string $clase): bool
    {
        $propias = array_values(array_filter((array) constant($clase.'::TABLES'), static fn ($t): bool => is_string($t) && str_starts_with($t, 'ultron_studio_')));
        if ($propias === [] || $referencias === []) {
            return false;
        }
        foreach ($this->sinLinea($referencias) as $referencia) {
            if (preg_match("/^T_CONSTANT_ENCAPSED_STRING '(ultron_studio_[a-z_]+)( as [a-z_]+)?'$/", $referencia, $m) !== 1 || ! in_array($m[1], $propias, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Hito 8 (E8-1, E9-1; SEC8-b): en el arranque, el laboratorio hablado solo
     * se nombra para registrar su oyente. El `use` sin alias (justo `use …;`),
     * el nombre corto una sola vez y esa vez dentro de {@see LAB_LISTENER_CALL}.
     */
    private function onlyTheLabListener(string $ruta): bool
    {
        $textos = array_map(fn ($t) => is_array($t) ? $t[1] : $t, $this->significantTokens($ruta));
        $usos = array_keys($textos, 'App\\Services\\UltronStudio\\StudioVoiceLabSessions', true);
        $cortos = array_keys($textos, 'StudioVoiceLabSessions', true);
        if (count($usos) !== 1 || ($textos[$usos[0] - 1] ?? null) !== 'use' || ($textos[$usos[0] + 1] ?? null) !== ';' || count($cortos) !== 1) {
            return false;
        }
        $inicio = $cortos[0] - (int) array_search('StudioVoiceLabSessions', self::LAB_LISTENER_CALL, true);

        return $inicio >= 0 && array_slice($textos, $inicio, count(self::LAB_LISTENER_CALL)) === self::LAB_LISTENER_CALL;
    }

    /**
     * Los tokens de un fichero sin espacios, comentarios ni docblocks.
     *
     * @return list<array{0:int,1:string,2:int}|string>
     */
    private function significantTokens(string $ruta): array
    {
        return array_values(array_filter(
            token_get_all((string) file_get_contents($ruta)),
            fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
    }

    /** El código de un fichero sin comentarios ni docblocks. */
    private function codeOnly(string $ruta): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($ruta)) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }

        return $out;
    }

    /**
     * Los nombres de método que se llaman en un fichero (`->x(`, `?->x(` o `::x(`), en minúscula.
     *
     * @return list<string>
     */
    private function calledMethods(string $ruta): array
    {
        $tokens = array_values(array_filter(
            token_get_all((string) file_get_contents($ruta)),
            fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $out = [];
        foreach ($tokens as $i => $t) {
            $esFlecha = is_array($t) && in_array($t[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
            $nombre = $tokens[$i + 1] ?? null;
            $parentesis = $tokens[$i + 2] ?? null;
            if ($esFlecha && is_array($nombre) && $nombre[0] === T_STRING && $parentesis === '(') {
                $out[] = strtolower($nombre[1]);
            }
        }

        return array_values(array_unique($out));
    }

    private function allowed(string $relativa): bool
    {
        foreach (self::ALLOWED as $permitido) {
            if (str_ends_with($permitido, '/') ? str_starts_with($relativa, $permitido) : $relativa === $permitido) {
                return true;
            }
        }
        // Hito 8: las migraciones de DB8, por patrón (sin `/` en el comodín: no cruza directorios).
        foreach (self::ALLOWED_H8_PATTERNS as $patron) {
            if (fnmatch($patron, $relativa, FNM_PATHNAME)) {
                return true;
            }
        }

        // Hito 9: las dos migraciones de DB9 y la configuración del canal SSE, por ruta exacta.
        return in_array($relativa, self::ALLOWED_H9_MIGRATIONS, true) || in_array($relativa, self::ALLOWED_H9_STUDIO_FILES, true);
    }

    /** Hito 8: lo que vive en una zona del motor sin ser del motor (los trabajos de la voz). */
    private function notEngine(string $relativa): bool
    {
        foreach (self::NOT_ENGINE as $prefijo) {
            if (str_starts_with($relativa, $prefijo)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>|null  $raices
     * @return list<string> rutas relativas a la base
     */
    private function phpFiles(?array $raices = null): array
    {
        $base = rtrim(base_path(), '/').'/';
        $out = [];
        foreach ($raices ?? self::SCANNED as $raiz) {
            $absoluta = $base.$raiz;
            if (is_file($absoluta)) {
                $out[] = $raiz;

                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absoluta, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php' && ! str_contains($f->getPathname(), '/bootstrap/cache/')) {
                    $out[] = substr($f->getPathname(), strlen($base));
                }
            }
        }
        sort($out);

        return $out;
    }
}
