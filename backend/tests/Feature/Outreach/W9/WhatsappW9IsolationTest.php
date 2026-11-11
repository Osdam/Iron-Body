<?php

namespace Tests\Feature\Outreach\W9;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Services\Marketing\MarketingMessageDispatcher;
use App\Services\Marketing\OutreachTemplateMessage;
use App\Services\Marketing\WhatsappOutboxService;
use App\Services\Meta\MetaAuthService;
use App\Services\Meta\MetaConversationService;
use App\Services\Outreach\Run\WhatsappTemplateOutcomes;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Outreach\W9\Support\W9World;
use Tests\TestCase;

/**
 * W9 · AISLAMIENTO (contrato H9, §3 protecciones 4, 5 y 10; §9.3; T3, T4):
 * mientras la automatización despacha por WhatsApp y normaliza sus
 * resultados, cada sentencia que escribe se clasifica por su tabla y por
 * QUIÉN la hace. La ejecución solo escribe `outreach_*`; Marketing solo se
 * toca DENTRO de la puerta (`dispatchWhatsappTemplate`: el mensaje como hoy,
 * con el apunte del Inbox en su conversación) y nunca `marketing_leads`,
 * `commercial_*`, pagos ni socios. La única red es el Graph falso de Meta, y
 * ningún dato personal llega a las tablas de la ejecución.
 */
class WhatsappW9IsolationTest extends TestCase
{
    use RefreshDatabase;
    use W9World;

    private const NOW = '2026-11-17 15:00:00';

    /** Lo que la ejecución puede escribir. */
    private const OUTREACH = ['outreach_runs', 'outreach_enrollments', 'outreach_jobs', 'outreach_decisions', 'outreach_outcomes', 'outreach_budget_counters'];

    /** Lo que solo la puerta de Marketing puede escribir (el mensaje y el apunte del Inbox de `recordOutbound()`, como hoy). */
    private const GATE = ['marketing_messages', 'marketing_conversations'];

    public static bool $enLaPuerta = false;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        config(['commercial.events_enabled' => true]);
        $this->threePeople();
        // La puerta REAL, marcada: así se sabe qué escritura de Marketing hizo ella.
        $this->app->bind(MarketingMessageDispatcher::class, function ($app) {
            return new class($app->make(MetaAuthService::class), $app->make(WhatsappOutboxService::class)) extends MarketingMessageDispatcher
            {
                public function dispatchWhatsappTemplate(MarketingLead $lead, OutreachTemplateMessage $template, string $outreachJobUuid): array
                {
                    WhatsappW9IsolationTest::$enLaPuerta = true;
                    try {
                        return parent::dispatchWhatsappTemplate($lead, $template, $outreachJobUuid);
                    } finally {
                        WhatsappW9IsolationTest::$enLaPuerta = false;
                    }
                }
            };
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        self::$enLaPuerta = false;
        parent::tearDown();
    }

    public function test_la_ejecucion_solo_escribe_outreach_y_marketing_solo_por_la_puerta(): void
    {
        $p = $this->plannedWhatsappJob(self::utcAt(self::NOW));
        $antes = $this->counts();
        $escrituras = [];
        $capturar = false;
        DB::listen(function (QueryExecuted $q) use (&$escrituras, &$capturar): void {
            if ($capturar && preg_match('/^\s*(insert\s+(?:or\s+\w+\s+)?into|update|delete\s+from)\s+["`]?([a-z_]+)/i', $q->sql, $m) === 1) {
                $escrituras[] = [strtolower($m[2]), self::$enLaPuerta];
            }
        });

        $capturar = true;
        $this->assertSame(['started' => 1], $this->openDispatcher()->dispatch(now(), null, true)['results']);
        $capturar = false;
        // Meta y la persona, por el camino de siempre (escrituras de Marketing que NO son del H9).
        $wamid = (string) DB::table('marketing_messages')->value('meta_message_id');
        $this->metaStatusWebhook($wamid, 'delivered');
        Carbon::setTestNow(now()->addMinutes(3));
        $conversacion = MarketingConversation::query()->where('lead_id', $p['member']['lead'])->firstOrFail();
        app(MetaConversationService::class)->recordInbound($conversacion, 'wamid.IN.iso', 'baja', []);
        $capturar = true;
        $r = (new WhatsappTemplateOutcomes)->run(now());
        $capturar = false;

        $this->assertSame(['delivered' => 1, 'opted_out' => 1], $r['recorded']);
        $this->assertNotEmpty($escrituras);
        $fuera = [];
        foreach ($escrituras as [$tabla, $enLaPuerta]) {
            if (in_array($tabla, self::OUTREACH, true)) {
                continue;
            }
            if ($enLaPuerta && in_array($tabla, self::GATE, true)) {
                continue;
            }
            $fuera[] = $tabla.($enLaPuerta ? ' (en la puerta)' : ' (fuera de la puerta)');
        }
        $this->assertSame([], array_values(array_unique($fuera)), 'escrituras del H9 fuera de outreach_* o de Marketing fuera de la puerta');
        $tablas = array_values(array_unique(array_column($escrituras, 0)));
        $this->assertContains('marketing_messages', $tablas, 'el mensaje se creó (por la puerta)');
        $this->assertGreaterThanOrEqual(1, count(array_filter($escrituras, static fn (array $w): bool => $w[0] === 'marketing_messages' && $w[1])), 'y lo creó la puerta');
        $resumen = [];
        foreach ($escrituras as [$tabla, $enLaPuerta]) {
            $clave = $tabla.($enLaPuerta ? '@puerta' : '');
            $resumen[$clave] = ($resumen[$clave] ?? 0) + 1;
        }
        ksort($resumen);
        fwrite(STDERR, "\n[W9] escrituras del despacho y la normalización: ".json_encode($resumen)."\n");

        // Las tablas ajenas que el H9 nunca escribe no cambian de filas (marketing_messages crece en 2: el saliente y el entrante de la persona).
        $despues = $this->counts();
        foreach ($antes as $tabla => $n) {
            $esperado = $tabla === 'marketing_messages' ? $n + 2 : $n;
            $this->assertSame($esperado, $despues[$tabla], $tabla);
        }

        // Ningún dato de la persona en las tablas de la ejecución.
        $ejecucion = json_encode([DB::table('outreach_jobs')->get(), DB::table('outreach_outcomes')->get(), DB::table('outreach_decisions')->get(), DB::table('outreach_runs')->get()]);
        foreach (['3001234567', '573001234567', 'Socio', 'noviembre', 'baja'] as $dato) {
            $this->assertStringNotContainsStringIgnoringCase($dato, (string) $ejecucion, "«{$dato}» llegó a las tablas de la ejecución");
        }

        // La única red: el Graph falso, una vez, una plantilla.
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $req): bool => str_contains($req->url(), 'graph.facebook.com') && $req['type'] === 'template');
    }

    /**
     * D9-9.3 («sin ruta nueva: la puerta interna firmada no la alcanza»): el
     * método de plantillas lo DEFINE el despachador y lo LLAMA solo el canal;
     * ningún controlador (tampoco los internos de `automation.internal`), ruta,
     * orden ni trabajo lo nombra, ni el DTO de la plantilla.
     */
    public function test_solo_el_canal_llama_a_la_puerta_de_plantillas_y_ninguna_ruta_la_alcanza(): void
    {
        $quien = [];
        foreach (['app', 'routes'] as $raiz) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($raiz), \RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (! $f->isFile() || $f->getExtension() !== 'php') {
                    continue;
                }
                $codigo = '';
                foreach (token_get_all((string) file_get_contents($f->getPathname())) as $t) {
                    if (! is_array($t) || ! in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                        $codigo .= is_array($t) ? $t[1] : $t;
                    }
                }
                $relativa = substr($f->getPathname(), strlen(base_path()) + 1);
                if (str_contains($codigo, 'dispatchWhatsappTemplate') || str_contains($codigo, 'OutreachTemplateMessage')) {
                    $quien[] = $relativa;
                }
            }
        }
        sort($quien);
        $this->assertSame([
            'app/Services/Marketing/MarketingMessageDispatcher.php',
            'app/Services/Marketing/OutreachTemplateMessage.php',
            'app/Services/Marketing/WhatsappOutboxService.php',
            'app/Services/Outreach/Run/Channels/WhatsappTemplateChannel.php',
        ], $quien, 'la puerta de plantillas solo la define Marketing y solo la llama el canal: ni rutas, ni controladores internos, ni órdenes');
        $canal = (string) file_get_contents(app_path('Services/Outreach/Run/Channels/WhatsappTemplateChannel.php'));
        $this->assertSame(1, preg_match_all('/->dispatchWhatsappTemplate\(/', $canal), 'una llamada, desde el canal');
        $this->assertSame(0, preg_match_all('/->dispatchWhatsapp\(/', $canal), 'nunca el texto libre');
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $out = [];
        foreach (['users', 'members', 'plans', 'payments', 'payment_transactions', 'marketing_leads', 'marketing_conversations', 'marketing_messages',
            'marketing_followups', 'marketing_ai_actions', 'marketing_consent_events', 'commercial_events', 'commercial_opportunities', 'automation_events',
            'ultron_studio_message_templates', 'ultron_studio_template_meta_checks', 'ultron_studio_strategy_publications', 'voice_calls'] as $tabla) {
            $out[$tabla] = DB::table($tabla)->count();
        }

        return $out;
    }
}
