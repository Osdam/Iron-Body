<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioMessageTemplate;
use App\Models\UltronStudio\StudioStrategyVersion;
use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Facts\BatchSnapshotBuilder;
use App\Services\Outreach\Facts\CanaryFacts;
use App\Services\Outreach\Facts\CatalogDomains;
use App\Services\Outreach\Facts\ContactHistoryFacts;
use App\Services\Outreach\Facts\OperationalBrakes;
use App\Services\Outreach\Facts\SubjectKey;
use App\Services\Outreach\Facts\SubjectTimeline;
use App\Services\Outreach\Run\OutreachException;
use App\Services\Outreach\Run\OutreachMonitor;
use App\Services\Outreach\Run\OutreachPublishedStrategies;
use App\Services\Outreach\Run\OutreachSendGate;
use App\Services\Outreach\Run\OutreachStop;
use App\Services\Telephony\VoiceEvents;
use App\Services\UltronStudio\Stream\StreamPacer;
use App\Services\UltronStudio\Stream\StreamTopics;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Feature\Outreach\Facts\Support\FactsWorld;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\Feature\UltronStudio\Strategies\StrategyFixtures;
use Tests\Feature\UltronStudio\Stream\FakeStreamClock;
use Tests\TestCase;

/**
 * AISLAMIENTO DEL HITO 9 (contrato H9 §3 protecciones 4–9, §9.3, T3 y T4;
 * SEC9-a). Lo que la lista blanca de `UltronEngineIsolationTest` fija por
 * tokens, aquí se mira EN EJECUCIÓN, con un escuchador de consultas y con
 * `Http::preventStrayRequests()`:
 *
 *  1. Ninguna escritura del H9 llega a `commercial_*`, `marketing_*`, `users`,
 *     `members`, `payments`, `voice_*`, `identities`… Se recorre todo lo que
 *     hoy existe del H9 (las 30 rutas de S9 con el ciclo entero del gobierno,
 *     la simulación sobre muestra real, la foto completa de hechos y el
 *     historial, el puente de la ejecución, las órdenes `outreach:stop` y
 *     `outreach:monitor`, el freno y su suelta, y el canal SSE con sus once
 *     temas) y cada sentencia que escribe se clasifica por su tabla: solo las
 *     del H9 (gobierno y `outreach_*`), el libro, los incidentes y la sesión.
 *     Los hechos, el puente y el canal SSE son de SOLO LECTURA: ni una
 *     sentencia que no sea `select`, ni un `FOR UPDATE`.
 *  2. Cero red con las banderas por defecto y con la configuración REAL de
 *     producción (ElevenLabs, OTP y Meta «configurados», `app.env` =
 *     production, la ejecución en `live` con WhatsApp y voz encendidos y con
 *     cupo): la constante `SENDS_ENABLED_IN_CODE` es lo que cierra.
 *  3. La puerta de diff de ULTRON Legacy, n8n, el OTP y el motor comercial
 *     está vacía frente a `origin/main` (como en el H8), y el H9 no añade
 *     ficheros a la voz del H8 (su contenido lo compara el despliegue, porque
 *     el árbol compartido lleva el cierre del H8 sin confirmar, L9-34).
 *  4. Dentro de la ejecución, los lectores de las tablas del Studio son una
 *     LISTA CERRADA (L9-51, que enmienda D9-2 «el puente es el único fichero»):
 *     versiones, publicaciones, plantillas y vínculos SOLO por el puente;
 *     `ultron_studio_strategies` la pueden leer, solo para joins de lectura
 *     (uuid/estado), los ficheros de {@see STUDIO_TABLE_READERS}. La prueba cae
 *     si aparece uno más, si alguno escribe sobre una tabla del Studio o si
 *     alguien nombra una tabla del Studio por su cuenta.
 *
 * Lo que B9-run añada después (barrido, despachador, bandeja, historial por
 * la API) tiene que entrar en {@see exercise()} para que esta prueba lo vea.
 */
class OutreachIsolationTest extends TestCase
{
    use FactsWorld;
    use RefreshDatabase;
    use StrategyFixtures;
    use VoiceFixtures;

    private const NOW = '2026-11-17 15:00:00';

    /** Las tablas en las que el H9 NUNCA escribe (protecciones 5, 6, 8, 9 y 10; §9.3). */
    private const FORBIDDEN_WRITES = '/^(commercial_|marketing_|automation_|otp_|voice_|ultron_studio_voice_|ultron_studio_publications$|ultron_studio_versions$|ultron_studio_drafts$|ultron_studio_rules$|ultron_aborts$|users$|members$|membership_|payments$|payment_transactions$|receivables?$|receivable_payments$|identities$|staff_profiles$|admins$|classes$|class_|valuation_|physical_evaluations$|attendances$|plans$)/';

    /** Donde SÍ puede escribir lo que se recorre: las 15 tablas del H9, el libro, los incidentes de IRON GUARD y la sesión del CRM. */
    private const ALLOWED_WRITES = [
        'ultron_studio_strategies', 'ultron_studio_strategy_versions', 'ultron_studio_strategy_simulations', 'ultron_studio_strategy_reviews',
        'ultron_studio_strategy_publications', 'ultron_studio_message_templates', 'ultron_studio_person_bindings', 'ultron_studio_audit_events',
        'outreach_runs', 'outreach_cursors', 'outreach_enrollments', 'outreach_jobs', 'outreach_decisions', 'outreach_outcomes', 'outreach_brakes', 'outreach_budget_counters',
        'incidents', 'incident_events', 'admin_sessions',
    ];

    /**
     * Dónde puede escribir CADA tramo (D9-2, D9-11 «copiado sin compartir
     * tablas»): el gobierno (S9) solo en sus siete tablas, el libro y la sesión;
     * la ejecución (B9-run) solo en `outreach_*` y en los incidentes; los
     * hechos, el puente y el canal SSE, en ninguna.
     */
    private const ALLOWED_WRITES_BY_SEGMENT = [
        's9' => ['ultron_studio_strategies', 'ultron_studio_strategy_versions', 'ultron_studio_strategy_simulations', 'ultron_studio_strategy_reviews',
            'ultron_studio_strategy_publications', 'ultron_studio_message_templates', 'ultron_studio_person_bindings', 'ultron_studio_audit_events', 'admin_sessions'],
        'run' => ['outreach_runs', 'outreach_cursors', 'outreach_enrollments', 'outreach_jobs', 'outreach_decisions', 'outreach_outcomes', 'outreach_brakes', 'outreach_budget_counters',
            'incidents', 'incident_events'],
        'facts' => [],
        'bridge' => [],
        'sse' => [],
    ];

    /** Las tablas ajenas cuyo número de filas no puede cambiar mientras el H9 trabaja. */
    private const COUNTED = ['users', 'members', 'plans', 'payments', 'payment_transactions', 'membership_subscriptions', 'attendances', 'receivables',
        'receivable_payments', 'marketing_leads', 'marketing_conversations', 'marketing_messages', 'marketing_followups', 'marketing_ai_actions',
        'commercial_events', 'commercial_opportunities', 'commercial_alerts', 'commercial_segments', 'classes', 'class_reservations', 'class_attendances',
        'valuation_appointments', 'physical_evaluations', 'voice_contacts', 'voice_consents', 'voice_exclusions', 'voice_rne_checks', 'voice_calls',
        'voice_call_events', 'voice_brakes', 'ultron_studio_canary_numbers', 'ultron_studio_publications', 'ultron_studio_versions', 'ultron_aborts',
        'marketing_agent_settings', 'automation_events', 'identities', 'admins'];

    /**
     * La puerta de diff (protecciones 5, 6, 7 y 8): ni un byte distinto de
     * `origin/main` ni un fichero nuevo. Las zonas de la voz del H8 van
     * aparte ({@see VOICE_NO_NEW_FILES}): su contenido lleva el cierre del H8
     * sin confirmar en este mismo árbol (L9-34) y lo compara el despliegue.
     */
    private const DIFF_GATE = [
        'app/Services/Marketing/Ultron', 'app/Jobs/SendUltronEventToN8n.php', 'app/Http/Controllers/Api/Internal', 'routes/marketing.php', 'config/marketing.php',
        'app/Services/Automation', 'config/automation.php',
        'app/Services/Commercial', 'app/Jobs/Commercial', 'app/Observers/Commercial', 'config/commercial.php',
        'app/Services/Otp', 'app/Services/Sms', 'config/otp.php',
    ];

    private const VOICE_NO_NEW_FILES = ['app/Services/Voice', 'app/Services/Telephony', 'app/Models/Voice', 'app/Jobs/Voice', 'config/voice.php'];

    /**
     * Hito 9 (L9-51, que enmienda D9-2 y §9.3): la LISTA CERRADA de quién lee
     * las tablas del Studio desde la ejecución, por constante de
     * `OutreachTables`. Versiones, publicaciones, plantillas y vínculos: SOLO
     * el puente. `ultron_studio_strategies`: además, solo para joins de lectura
     * (uuid, clave, estado), los ficheros de B9-run que siguen. Un lector nuevo
     * se añade aquí a sabiendas (y lo revisa SEC9), nunca aparece solo; ninguno
     * escribe sobre una tabla del Studio ({@see assertNoStudioWritesInStatements()}
     * y el escuchador por tramos de {@see assertWritesStayHome()}).
     */
    private const STUDIO_TABLE_READERS = [
        'VERSIONS' => ['app/Services/Outreach/Run/OutreachPublishedStrategies.php'],
        'PUBLICATIONS' => ['app/Services/Outreach/Run/OutreachPublishedStrategies.php'],
        'TEMPLATES' => ['app/Services/Outreach/Run/OutreachPublishedStrategies.php'],
        'PERSON_BINDINGS' => ['app/Services/Outreach/Run/OutreachPublishedStrategies.php'],
        // W9: la correspondencia con Meta, SOLO el puente.
        'TEMPLATE_META_CHECKS' => ['app/Services/Outreach/Run/OutreachPublishedStrategies.php'],
        'STRATEGIES' => [
            'app/Console/Commands/Outreach/OutreachSuppressSubjectCommand.php',
            'app/Services/Outreach/Run/AssistedQueue.php',
            'app/Services/Outreach/Run/OutcomeRecorder.php',
            'app/Services/Outreach/Run/OutreachDispatcher.php',
            'app/Services/Outreach/Run/OutreachMonitor.php',
            'app/Services/Outreach/Run/OutreachPublishedStrategies.php',
            'app/Services/Outreach/Run/OutreachReadModels.php',
            'app/Services/Outreach/Run/OutreachReconciler.php',
            'app/Services/Outreach/Run/OutreachStop.php',
        ],
    ];

    /** Un verbo de escritura o de bloqueo pegado a una tabla del Studio en una misma sentencia de la ejecución. */
    private const STUDIO_WRITE_IN_STATEMENT = '/OutreachTables::(STRATEGIES|VERSIONS|PUBLICATIONS|TEMPLATES|PERSON_BINDINGS|TEMPLATE_META_CHECKS)\b[^;]*->(insert|insertGetId|insertOrIgnore|insertUsing|update|updateOrInsert|upsert|delete|truncate|increment|decrement|lockForUpdate|sharedLock)\(/';

    /** Dónde vive el PHP de la ejecución que puede leer el Studio. */
    private const OUTREACH_ROOTS = ['app/Services/Outreach', 'app/Console/Commands/Outreach', 'app/Jobs/Outreach'];

    /** Lo que se siembra con datos personales de verdad (para comprobar que no viajan): el teléfono del prospecto y el del contacto de voz. */
    private const LEAD_PHONE = '3006660099';

    private const VOICE_PHONE = '+573006660099';

    private ?string $envAntes = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake([]);
        $this->withoutMiddleware(ThrottleRequests::class);
        // Las 30 rutas de S9 están sin mapa hasta la rama del H9 (L9-38): el mapa deniega
        // (falla cerrado) y aquí lo que se mira es qué escribe y a dónde sale el código.
        $this->withoutRouteMap();
        $this->grantStrategyPermissions();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
    }

    protected function tearDown(): void
    {
        if ($this->envAntes !== null) {
            $this->app['env'] = $this->envAntes;
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── 1. Escrituras ───────────────────────────────────────────────────────

    public function test_ninguna_escritura_del_h9_llega_a_tablas_ajenas_y_los_lectores_solo_leen(): void
    {
        $this->strategyFlags(enabled: true, publishing: true);
        $this->runFlags('shadow');
        $this->streamFlags();
        $mundo = $this->world();
        $antes = $this->counts();

        $tramos = $this->exercise($mundo);

        $this->assertWritesStayHome($tramos);
        foreach (['facts', 'bridge', 'sse'] as $tramo) {
            $this->assertReadOnly($tramos[$tramo], $tramo);
        }
        $this->assertSame($antes, $this->counts(), 'Alguna tabla ajena cambió de filas mientras el H9 trabajaba.');
        Http::assertNothingSent();
    }

    // ── 2. Cero red ─────────────────────────────────────────────────────────

    /**
     * Con la configuración real de producción (E1-12 del H8: ElevenLabs
     * configurado y encendido; el OTP de Twilio; Meta), `app.env` en
     * `production` y la ejecución en `live` con WhatsApp y voz encendidos y
     * con cupo: el ciclo entero no saca una petición. Lo que cierra es la
     * constante (`automaticSendAllowed` = false en los dos canales automáticos).
     */
    public function test_cero_red_con_la_configuracion_de_produccion_y_la_ejecucion_en_live(): void
    {
        $this->productionConfig();
        $this->strategyFlags(enabled: true, publishing: true);
        $this->runFlags('live', [
            'outreach.whatsapp.enabled' => true, 'outreach.whatsapp.daily_cap' => 100, 'outreach.whatsapp.daily_budget_usd' => '25',
            'outreach.voice.enabled' => true, 'outreach.monitor.enabled' => true, 'outreach.classifier.enabled' => true,
        ]);
        $this->streamFlags();
        $mundo = $this->world();

        $this->assertFalse(OutreachSendGate::sendsEnabledInCode());
        foreach (OutreachCatalog::AUTOMATIC_CHANNELS as $canal) {
            $this->assertFalse(OutreachSendGate::automaticSendAllowed($canal), "{$canal}: con todo encendido, la constante cierra");
        }

        $tramos = $this->exercise($mundo);

        $this->assertWritesStayHome($tramos);
        Http::assertNothingSent();
    }

    public function test_cero_red_con_las_banderas_por_defecto(): void
    {
        // Sin tocar ninguna bandera: las rutas del gobierno responden 409, el canal 409, las órdenes no hacen nada.
        $this->assertFalse(config('outreach.enabled'));
        $this->assertFalse(config('ultron_studio.strategies.enabled'));
        $this->assertFalse(config('ultron_studio_stream.enabled'));
        [$ana] = $this->bootstrapPair();
        $cabeceras = $this->headersOf($ana);

        $sql = $this->capture(function () use ($ana, $cabeceras): void {
            $this->s9('GET', 'strategies', $ana)->assertStatus(409)->assertJsonPath('code', 'strategies_disabled');
            $this->s9('POST', 'strategies', $ana, ['clientRequestId' => (string) Str::uuid(), 'key' => 'apagada', 'name' => 'Apagada', 'purpose' => 'commercial'])->assertStatus(409);
            $this->get('/api/admin/ultron-studio/stream?topics=ledger', $cabeceras)->assertStatus(409)->assertJsonPath('code', 'stream_disabled');
            $this->assertSame(0, Artisan::call('outreach:monitor', ['--json' => true]));
            (new OutreachPublishedStrategies)->liveStrategies();
            (new OperationalBrakes)->read();
        });

        $this->assertReadOnly($sql, 'banderas por defecto');
        Http::assertNothingSent();
    }

    // ── 3. Puerta de diff ───────────────────────────────────────────────────

    public function test_la_puerta_de_diff_de_legacy_n8n_otp_y_motor_comercial_esta_vacia_y_la_voz_no_gana_ficheros(): void
    {
        foreach (['app/Services/Marketing/Ultron', 'app/Http/Controllers/Api/Internal', 'routes/marketing.php', 'config/marketing.php', 'config/automation.php',
            'app/Services/Commercial', 'config/commercial.php', 'config/otp.php', 'app/Services/Voice', 'app/Services/Telephony'] as $zona) {
            $this->assertFileExists(base_path($zona), "La puerta nombra algo que no existe: {$zona}");
        }

        $base = $this->gitBase();
        $cambiados = $this->git(['diff', '--name-only', $base, '--', ...self::DIFF_GATE]);
        $this->assertSame('', $cambiados, "La puerta de diff del H9 no está vacía frente a {$base} (ULTRON Legacy, n8n, OTP o motor comercial tocados):\n{$cambiados}");
        $nuevos = $this->git(['ls-files', '--others', '--exclude-standard', '--', ...self::DIFF_GATE]);
        $this->assertSame('', $nuevos, "Ficheros nuevos sin seguimiento en las zonas protegidas:\n{$nuevos}");

        // La voz del H8: ningún fichero nuevo de las zonas de la voz es del H9 (el cierre del H8 sí
        // añade los suyos en este árbol, L9-34; el contenido lo compara el despliegue). Un fichero
        // del H9 se reconoce por nombrar la automatización comercial, las estrategias o el canal.
        $nuevosVoz = $this->git(['ls-files', '--others', '--exclude-standard', '--', ...self::VOICE_NO_NEW_FILES]);
        foreach (array_filter(explode("\n", $nuevosVoz)) as $nuevo) {
            $codigo = $this->codeOnly(base_path($nuevo));
            $this->assertDoesNotMatchRegularExpression('/Outreach|outreach_|Strateg|UltronStudio\\\\Stream|commercial_automation/', $codigo, "{$nuevo} es un fichero nuevo de la voz del H8 con código del H9 (protección 9).");
            fwrite(STDERR, "\n[SEC9-a] fichero nuevo en la voz del H8 (no es del H9): {$nuevo}\n");
        }
    }

    // ── 4. Lectores del Studio dentro de la ejecución ───────────────────────

    public function test_los_lectores_de_las_tablas_del_studio_dentro_de_outreach_son_una_lista_cerrada(): void
    {
        $vistos = array_fill_keys(array_keys(self::STUDIO_TABLE_READERS), []);
        $ficheros = [];
        foreach (self::OUTREACH_ROOTS as $raiz) {
            if (is_dir(base_path($raiz))) {
                $ficheros = [...$ficheros, ...$this->phpFiles($raiz)];
            }
        }
        foreach ($ficheros as $relativa) {
            $codigo = $this->codeOnly(base_path($relativa));
            foreach (array_keys(self::STUDIO_TABLE_READERS) as $constante) {
                if (preg_match('/OutreachTables::'.$constante.'\b/', $codigo) === 1) {
                    $vistos[$constante][] = $relativa;
                }
            }
            // L9-51: leer, nunca escribir ni bloquear una tabla del Studio desde la ejecución.
            $this->assertNoStudioWritesInStatements($codigo, $relativa);
            // Y nadie nombra una tabla del Studio por su cuenta fuera de OutreachTables y de los tres lectores de Facts.
            if (! in_array($relativa, ['app/Services/Outreach/Run/OutreachTables.php', 'app/Services/Outreach/Facts/CanaryFacts.php',
                'app/Services/Outreach/Facts/ContactHistoryFacts.php', 'app/Services/Outreach/Facts/SubjectTimeline.php'], true)) {
                $this->assertStringNotContainsString("'ultron_studio_", $codigo, "{$relativa} nombra una tabla del Studio por su cuenta.");
            }
        }
        foreach (self::STUDIO_TABLE_READERS as $constante => $permitidos) {
            sort($vistos[$constante]);
            if ($constante === 'STRATEGIES') {
                // L9-51: la lista cerrada de quienes leen el uuid/estado de la estrategia; un lector nuevo
                // se añade a sabiendas (SEC9 lo revisa), nunca aparece solo. Se imprime para el informe.
                $this->assertContains('app/Services/Outreach/Run/OutreachPublishedStrategies.php', $vistos[$constante]);
                fwrite(STDERR, "\n[SEC9-a] lectores de ultron_studio_strategies en la ejecución (L9-51, lista cerrada de ".count($permitidos).'): '.implode(', ', array_map('basename', $vistos[$constante]))."\n");
            }
            $this->assertSame($permitidos, $vistos[$constante], "Los lectores de OutreachTables::{$constante} no son los de la lista cerrada (L9-51: el puente; para STRATEGIES, además los ficheros enumerados). Un lector nuevo se añade a sabiendas y lo revisa SEC9.");
        }
    }

    // ── El recorrido ────────────────────────────────────────────────────────

    /**
     * Todo lo que hoy existe del H9, tramo a tramo, con el escuchador puesto.
     *
     * @param  array{ana: Admin, beto: Admin, claves: list<string>}  $mundo
     * @return array<string, list<string>>
     */
    private function exercise(array $mundo): array
    {
        $ana = $mundo['ana'];
        $beto = $mundo['beto'];
        $claves = $mundo['claves'];
        $tramos = [];
        $estado = [];

        // S9: el ciclo entero del gobierno por la API, también la simulación sobre muestra real (L9-40).
        $tramos['s9'] = $this->capture(function () use ($ana, $beto, &$estado): void {
            $plantilla = $this->s9('POST', 'strategies/templates', $ana, [
                'clientRequestId' => (string) Str::uuid(), 'key' => 'guion-renovacion', 'channel' => 'assisted', 'purpose' => 'commercial',
                'content' => ['medium' => 'whatsapp', 'script' => 'Saluda, recuerda que su plan vence pronto y ofrece ayuda para renovarlo.'],
            ])->assertCreated()->json('data');
            $this->s9('POST', "strategies/templates/{$plantilla['uuid']}/approve", $beto, [])->assertOk();
            $t = StudioMessageTemplate::query()->where('uuid', $plantilla['uuid'])->firstOrFail();

            $s = $this->s9('POST', 'strategies', $ana, ['clientRequestId' => (string) Str::uuid(), 'key' => 'renovacion-7d', 'name' => 'Renovación a 7 días', 'purpose' => 'commercial'])
                ->assertCreated()->json('data');
            $this->s9('PUT', "strategies/{$s['uuid']}/draft", $ana, ['baseRevision' => 1, 'draft' => $this->strategyContent($t)])->assertOk();
            $this->s9('POST', "strategies/{$s['uuid']}/validate", $ana, [])->assertOk();
            $this->s9('POST', "strategies/{$s['uuid']}/simulations", $ana, ['kind' => 'synthetic', 'at' => '2026-11-17T15:00:00Z', 'people' => $this->syntheticPeople()])->assertCreated();
            $v = $this->s9('POST', "strategies/{$s['uuid']}/versions", $ana, ['clientRequestId' => (string) Str::uuid()])->assertCreated()->json('data');
            $this->s9('POST', "strategies/versions/{$v['uuid']}/simulations", $ana, ['kind' => 'synthetic', 'at' => '2026-11-17T15:00:00Z', 'people' => $this->syntheticPeople()])->assertCreated();
            // La muestra REAL: `Outreach\Facts` desde el Studio, en solo lectura (L9-40).
            $muestra = $this->s9('POST', "strategies/versions/{$v['uuid']}/simulations", $ana, ['kind' => 'sample'])->assertCreated()->json('data');
            $this->assertSame('sample', $muestra['kind'] ?? 'sample');
            $this->s9('POST', "strategies/versions/{$v['uuid']}/approve", $beto, ['acknowledgedWarnings' => $v['validation']['warningIds']])->assertOk();
            $this->s9('POST', "strategies/versions/{$v['uuid']}/publish", $beto, ['clientRequestId' => (string) Str::uuid(), 'expectedContentHash' => $v['contentHash']])->assertCreated();

            foreach (['strategies/catalog', 'strategies', "strategies/{$s['uuid']}", "strategies/{$s['uuid']}/publications", "strategies/versions/{$v['uuid']}",
                "strategies/versions/{$v['uuid']}/simulations", "strategies/versions/{$v['uuid']}/canary-evidence", 'strategies/templates',
                "strategies/templates/{$plantilla['uuid']}", 'strategies/person-bindings'] as $ruta) {
                $cuerpo = $this->s9('GET', $ruta, $ana)->assertOk()->getContent();
                $this->assertNoSeededPersonalData($cuerpo, "GET {$ruta}");
            }
            $this->s9('POST', "strategies/{$s['uuid']}/pause", $ana, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Pausa de la prueba de aislamiento'])->assertCreated();
            $this->s9('POST', "strategies/{$s['uuid']}/resume", $beto, ['clientRequestId' => (string) Str::uuid()])->assertCreated();

            $estado = ['template' => $t, 'strategy' => $s, 'version' => $v];
        });

        // B9-facts: la foto completa, el historial, los contactos, los canarios, los frenos y los dominios.
        $tramos['facts'] = $this->capture(function () use ($claves): void {
            $foto = (new BatchSnapshotBuilder)->build($claves, self::at(self::NOW));
            $this->assertNotNull($foto);
            (new SubjectTimeline)->forSubject($claves[0]);
            (new ContactHistoryFacts)->contacts($this->pass($claves, self::NOW));
            (new CanaryFacts)->facts($this->pass($claves, self::NOW));
            (new OperationalBrakes)->read();
            (new CatalogDomains)->read();
        });

        // El puente de la ejecución hacia el gobierno (D9-2).
        $tramos['bridge'] = $this->capture(function () use ($ana, $estado): void {
            $puente = new OutreachPublishedStrategies;
            $vivas = $puente->liveStrategies();
            $this->assertNotSame([], $vivas, 'el puente ve la canaria publicada');
            $this->assertTrue($puente->headVerified()['verified']);
            $this->assertSame('ok', $puente->verifyChain()['result']);
            $this->assertNotNull($puente->personOf((int) $ana->id));
            $t = $estado['template'];
            $this->assertTrue($puente->templateState(['uuid' => (string) $t->uuid, 'hash' => (string) $t->content_hash], 'assisted:whatsapp', 'commercial')['approved']);
            $this->assertNotNull($puente->assistedScript((int) $t->id));
            $versionId = (int) StudioStrategyVersion::query()->where('uuid', $estado['version']['uuid'])->value('id');
            $this->assertNotNull($puente->liveVersion($versionId, 'canary'));
        });

        // B9-run: el monitor, la PARADA GLOBAL por consola, la suelta a cuatro ojos y otra vez el monitor.
        $tramos['run'] = $this->capture(function () use ($ana, $beto): void {
            $this->assertSame(0, Artisan::call('outreach:monitor', ['--json' => true]));
            $this->assertSame(0, Artisan::call('outreach:stop', ['--all' => true, '--reason' => 'Parada de la prueba de aislamiento', '--by' => (string) $ana->id, '--json' => true]), Artisan::output());
            $stop = app(OutreachStop::class);
            $this->assertTrue($stop->isGlobalOpen());
            $freno = DB::table('outreach_brakes')->where('scope', OutreachStop::SCOPE_GLOBAL)->whereNull('released_at')->first();
            $this->assertNotNull($freno);
            try {
                $stop->release($ana, (string) $freno->uuid, 'Intento de la misma persona que lo abrió', (string) Str::uuid());
                $this->fail('Quien abre un freno no lo suelta (L9-7).');
            } catch (OutreachException $e) {
                $this->assertSame(403, $e->status, $e->errorCode);
            }
            $stop->release($beto, (string) $freno->uuid, 'Suelta la prueba de aislamiento', (string) Str::uuid());
            $this->assertFalse($stop->isGlobalOpen());
            app(OutreachMonitor::class)->run(Carbon::now());
        });

        // SSE9: el canal con sus once temas, con el tique en la URL y con la sesión; nada personal en el cuerpo.
        $tramos['sse'] = $this->capture(function () use ($ana): void {
            $reloj = new FakeStreamClock;
            $this->app->instance(StreamPacer::class, StreamPacer::fromConfig($reloj));
            $cabeceras = $this->headersOf($ana);
            $temas = implode(',', StreamTopics::NAMES);
            $desde = implode(',', array_map(static fn (string $t): string => "{$t}:0", StreamTopics::NAMES));

            $cuerpo = $this->get("/api/admin/ultron-studio/stream?topics={$temas}&after={$desde}", $cabeceras)->assertOk()->streamedContent();
            $this->assertStringContainsString('event: ready', $cuerpo);
            $this->assertStringContainsString('event: ledger', $cuerpo, 'el libro del gobierno viaja por el canal');
            $this->assertStringContainsString('event: voice.calls', $cuerpo, 'las llamadas sembradas viajan por el canal');
            $this->assertNoSeededPersonalData($cuerpo, 'el canal SSE');

            $tique = $this->get('/api/admin/stream-ticket', $cabeceras)->assertOk()->json('data.ticket');
            $this->app->instance(StreamPacer::class, StreamPacer::fromConfig(new FakeStreamClock));
            $conTique = $this->get("/api/admin/ultron-studio/stream?ticket={$tique}&topics=ledger,voice.calls&after=ledger:0,voice.calls:0")->assertOk()->streamedContent();
            $this->assertStringContainsString('event: ready', $conTique);
            $this->assertNoSeededPersonalData($conTique, 'el canal SSE con tique');
        });

        return $tramos;
    }

    // ── Aserciones ──────────────────────────────────────────────────────────

    /** @param  array<string, list<string>>  $tramos */
    private function assertWritesStayHome(array $tramos): void
    {
        $prohibidas = [];
        $desconocidas = [];
        $escrituras = 0;
        $total = 0;
        foreach ($tramos as $tramo => $sentencias) {
            foreach ($sentencias as $sql) {
                $total++;
                $tabla = $this->writeTarget($sql);
                if ($tabla === null) {
                    continue;
                }
                $escrituras++;
                if (str_starts_with($tabla, '?')) {
                    $desconocidas[] = "{$tramo}: {$sql}";

                    continue;
                }
                if (preg_match(self::FORBIDDEN_WRITES, $tabla) === 1) {
                    $prohibidas[] = "{$tramo}: {$sql}";
                }
                if (! in_array($tabla, self::ALLOWED_WRITES_BY_SEGMENT[$tramo] ?? self::ALLOWED_WRITES, true)) {
                    $desconocidas[] = "{$tramo} → {$tabla}: {$sql}";
                }
            }
        }
        $this->assertSame([], $prohibidas, "El H9 escribe en tablas ajenas:\n".implode("\n", $prohibidas));
        $this->assertSame([], $desconocidas, "Un tramo del H9 escribe fuera de sus tablas (el gobierno en outreach_*, la ejecución en el gobierno, o una sentencia que no se sabe clasificar):\n".implode("\n", $desconocidas));
        $this->assertGreaterThan(0, $escrituras, 'El recorrido no escribió nada: la prueba no probaría nada.');
        fwrite(STDERR, "\n[SEC9-a] aislamiento: {$total} sentencias, {$escrituras} escrituras, todas en tablas del H9\n");
    }

    /** @param  list<string>  $sql */
    private function assertReadOnly(array $sql, string $tramo): void
    {
        $this->assertNotSame([], $sql, "{$tramo}: el escuchador no vio ninguna consulta (la prueba no probaría nada).");
        foreach ($sql as $s) {
            $this->assertMatchesRegularExpression('/^\s*(select|pragma)\b/i', $s, "{$tramo} ejecutó algo que no es una lectura: {$s}");
            $this->assertDoesNotMatchRegularExpression('/\bfor\s+(update|share|no\s+key\s+update|key\s+share)\b/i', $s, "{$tramo} bloquea filas: {$s}");
        }
    }

    /** Los datos personales sembrados (teléfonos del prospecto y del contacto de voz, en sus formas) no aparecen en lo que sale. */
    private function assertNoSeededPersonalData(string $cuerpo, string $donde): void
    {
        foreach ([self::LEAD_PHONE, '57'.self::LEAD_PHONE, '+57'.self::LEAD_PHONE, self::VOICE_PHONE, ltrim(self::VOICE_PHONE, '+'), substr(self::VOICE_PHONE, 3), self::CANARY_PHONE, ltrim(self::CANARY_PHONE, '+')] as $privado) {
            $this->assertStringNotContainsString($privado, $cuerpo, "{$donde} lleva un teléfono sembrado ({$privado}).");
        }
    }

    // ── El mundo ────────────────────────────────────────────────────────────

    /**
     * Personas con datos reales (teléfonos, deuda, pagos, clases, voz), las dos
     * cuentas del gobierno con su vínculo verificado y una llamada de voz, todo
     * ANTES de poner el escuchador.
     *
     * @return array{ana: Admin, beto: Admin, claves: list<string>}
     */
    private function world(): array
    {
        [$ana, $beto] = $this->bootstrapPair();
        $this->headersOf($ana);
        $this->headersOf($beto);

        $entrenador = $this->trainer();
        $clase = $this->klass();
        $claves = [];
        foreach ([0, 1] as $i) {
            $s = $this->socio(['membership_end_date' => '2026-11-2'.$i]);
            $lead = $this->lead(['member_id' => $i === 1 ? null : $s['member'], 'phone' => substr(self::LEAD_PHONE, 0, 9).$i, 'do_not_contact' => false]);
            $conv = $this->conversation($lead, ['last_inbound_at' => self::utc('2026-11-16 15:00:00')]);
            $this->aiMessage($conv, '2026-11-15 15:00:00');
            $this->entry($s['user'], '2026-11-0'.(1 + $i).' 15:00:00');
            $this->receivable($s['member'], '2026-10-0'.(1 + $i));
            $this->tx(['member_id' => $s['member'], 'created_at' => self::utc('2026-11-16 10:00:00')]);
            $this->reservation($clase, $s['member'], '2026-11-19');
            $this->valuation($s['member'], $entrenador, 'completed', '2026-10-01 15:00:00');
            $this->commercialEvent('payment_approved', '2026-11-10 15:00:00', $s['member']);
            $v = $this->voiceContact('member', $s['member'], substr(self::VOICE_PHONE, 0, 12).$i);
            $this->voiceConsent($v['id'], 'granted', 2, '2026-11-01 12:00:00');
            $claves[] = SubjectKey::member($s['member']);
            if ($i === 1) {
                $claves[] = SubjectKey::lead($lead);
            }
        }
        $this->canary(self::CANARY_PHONE);
        // Una llamada saliente del H8 con su evento (los temas `voice.*` emiten por `voice_call_events`).
        $llamada = $this->voiceCall('outbound', 'dialing');
        VoiceEvents::record('outbound_started', (int) $llamada->id, 'elevenlabs', 'sec9-started-1', ['codes' => ['sid' => 'CA123SEC9']]);

        return ['ana' => $ana, 'beto' => $beto, 'claves' => $claves];
    }

    /** @param  array<string, mixed>  $extra */
    private function runFlags(string $mode, array $extra = []): void
    {
        config(array_merge([
            'outreach.enabled' => true,
            'outreach.mode' => $mode,
            'outreach.monitor.enabled' => true,
            'outreach.assisted.enabled' => true,
            'outreach.assisted.daily_cap' => 20,
        ], $extra));
    }

    private function streamFlags(): void
    {
        config([
            'ultron_studio_stream.enabled' => true,
            'ultron_studio_stream.max_seconds' => 3,
            'ultron_studio_stream.poll_ms' => 1000,
            'ultron_studio_stream.heartbeat_seconds' => 15,
        ]);
    }

    /** La configuración de producción de los proveedores (como `VoiceProductionNoNetworkTest` y `UltronStudioIntegrationsNoNetworkTest`). */
    private function productionConfig(): void
    {
        config([
            'voice.elevenlabs.enabled' => true,
            'voice.elevenlabs.api_key' => 'clave-de-prueba-sec9-'.str_repeat('k', 24),
            'voice.elevenlabs.agent_id' => 'agent_sec9',
            'voice.elevenlabs.webhook_secret' => 'webhook-de-prueba-sec9-'.str_repeat('w', 24),
            'voice.elevenlabs.tool_secret' => 'herramientas-de-prueba-sec9-'.str_repeat('t', 24),
            'voice.elevenlabs.region' => 'default',
            'voice.elevenlabs.base_url' => null,
            'otp.driver' => 'twilio',
            'otp.twilio.sid' => 'AC0123456789abcdef0123456789abcdef',
            'otp.twilio.token' => 'token-de-la-prueba-sin-red',
            'otp.twilio.verify_service_sid' => 'VA'.str_repeat('0', 28).'9f0e',
            'meta.access_token' => 'meta-de-la-prueba-sin-red',
            'meta.phone_number_id' => '100000000000001',
        ]);
        $this->envAntes = $this->app['env'];
        $this->app['env'] = 'production';
    }

    // ── Ayudantes ───────────────────────────────────────────────────────────

    /** @return list<string> */
    private function capture(Closure $correr): array
    {
        $sql = [];
        DB::listen(function (QueryExecuted $q) use (&$sql): void {
            $sql[] = $q->sql;
        });
        try {
            $correr();
        } finally {
            DB::getEventDispatcher()->forget(QueryExecuted::class);
        }

        return $sql;
    }

    /**
     * La tabla que escribe una sentencia, null si es una lectura (o una
     * sentencia de transacción) y `?…` si no se sabe clasificar.
     */
    private function writeTarget(string $sql): ?string
    {
        $s = strtolower(trim($sql));
        if (preg_match('/^(select|pragma|savepoint|release\s+savepoint|rollback|begin|commit|explain)\b/', $s) === 1) {
            return null;
        }
        if (preg_match('/^insert\s+(?:or\s+\w+\s+)?into\s+["`]?([a-z_][a-z0-9_]*)/', $s, $m) === 1
            || preg_match('/^update\s+(?:or\s+\w+\s+)?["`]?([a-z_][a-z0-9_]*)/', $s, $m) === 1
            || preg_match('/^delete\s+from\s+["`]?([a-z_][a-z0-9_]*)/', $s, $m) === 1
            || preg_match('/^(?:replace\s+into|truncate\s+(?:table\s+)?|alter\s+table\s+|create\s+(?:temporary\s+)?table\s+(?:if\s+not\s+exists\s+)?|drop\s+table\s+(?:if\s+exists\s+)?)["`]?([a-z_][a-z0-9_]*)/', $s, $m) === 1) {
            return $m[1];
        }

        return '?'.$sql;
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $c = [];
        foreach (self::COUNTED as $t) {
            $c[$t] = Schema::hasTable($t) ? DB::table($t)->count() : -1;
        }

        return $c;
    }

    private function gitBase(): string
    {
        $p = new Process(['git', 'rev-parse', '--verify', '--quiet', 'origin/main'], base_path());
        $p->run();
        if ($p->isSuccessful() && trim($p->getOutput()) !== '') {
            return 'origin/main';
        }
        fwrite(STDERR, "\n[SEC9-a] sin origin/main en este clon: la puerta de diff se compara con HEAD\n");

        return 'HEAD';
    }

    /** @param  list<string>  $args */
    private function git(array $args): string
    {
        $p = new Process(['git', ...$args], base_path());
        $p->run();
        $this->assertTrue($p->isSuccessful(), 'git '.implode(' ', $args).' falló: '.$p->getErrorOutput());

        return trim($p->getOutput());
    }

    /**
     * L9-51: en una misma sentencia de la ejecución, una tabla del Studio
     * (por constante de `OutreachTables`) nunca va seguida de un verbo que
     * escriba o bloquee. Es el complemento estático del escuchador por tramos.
     */
    private function assertNoStudioWritesInStatements(string $codigo, string $relativa): void
    {
        foreach (explode(';', $codigo) as $sentencia) {
            if (preg_match(self::STUDIO_WRITE_IN_STATEMENT, $sentencia, $m) === 1) {
                $this->fail("{$relativa} escribe o bloquea una tabla del Studio desde la ejecución (OutreachTables::{$m[1]} → {$m[2]}): L9-51 solo admite lecturas.");
            }
        }
        $this->assertTrue(true);
    }

    /** @return list<string> rutas relativas a la base */
    private function phpFiles(string $raiz): array
    {
        $base = rtrim(base_path(), '/').'/';
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base.$raiz, \RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $out[] = substr($f->getPathname(), strlen($base));
            }
        }
        sort($out);

        return $out;
    }

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
}
