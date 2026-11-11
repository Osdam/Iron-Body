<?php

namespace Tests\Feature\UltronStudio\Strategies;

use App\Exceptions\UltronStudioException;
use App\Models\Admin;
use App\Models\RolePermission;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioStrategy;
use App\Models\UltronStudio\StudioStrategyPublication;
use App\Models\UltronStudio\StudioStrategyVersion;
use App\Services\Outreach\Core\CanonicalJson;
use App\Services\UltronStudio\StudioAccess;
use App\Services\UltronStudio\StudioAudit;
use App\Services\UltronStudio\StudioPersonBindings;
use App\Services\UltronStudio\StudioStrategies;
use App\Services\UltronStudio\StudioStrategyConfig;
use App\Services\UltronStudio\StudioStrategyPublications;
use App\Services\UltronStudio\StudioStrategyPublishing;
use App\Services\UltronStudio\StudioStrategyVersions;
use App\Support\Access\CrmPermission;
use App\Support\Access\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * SC-4 (revisión de seguridad del cierre, `informe-sec-cierre.md`): publicar,
 * promover o reanudar una estrategia con un paso `voice_call` exige, además de
 * `ultron.strategies_publish`, `telephony.call_place` de quien lo hace: la
 * misma llave que pedir una llamada a mano (D8-22). Sin permiso nuevo.
 *
 * EL ESCENARIO: el propietario reparte `ultron.strategies_publish` al
 * Administrador (por defecto solo lo tiene Super Admin) para que la jefa de
 * mercadeo publique las campañas de WhatsApp. Con la Enmienda 17 del H8 esa
 * persona podía publicar una estrategia que llama sola sin poder pedir ni una
 * llamada a mano (BFLA por configuración). Lo de WhatsApp y lo asistido
 * (incluido `assisted:voice`: llama una persona con su propia llave) sigue
 * igual. Las acciones devuelven 201 (200 `replayed` al repetir). Cero red.
 */
class UltronStudioStrategyVoiceAuthorityTest extends TestCase
{
    use RefreshDatabase;
    use StrategyFixtures;

    private Admin $ana;

    private Admin $beto;

    private Admin $marta;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->withoutRouteMap();
        $this->grantStrategyPermissions();
        $this->grantToAdministrator(StudioStrategyConfig::PUBLISH);
        // La simulación no es lo que se prueba aquí (la cubren Simulation y FourEyes): la puerta queda dispensada y escrita en la entrada.
        $this->strategyFlags(enabled: true, publishing: true, gate: StudioStrategyConfig::GATE_WAIVED);
        Carbon::setTestNow($this->weekdayMorning());
        [$this->ana, $this->beto] = $this->bootstrapPair();
        $this->marta = $this->account(Admin::ROLE_ADMINISTRADOR, 'Marta Mercadeo');
        $this->bindAccount($this->marta, $this->identity(), $this->ana, $this->beto);

        // El punto de partida de SC-4, comprobado y no supuesto.
        $this->assertTrue(CrmPermission::allows($this->marta, StudioStrategyConfig::PUBLISH));
        $this->assertFalse(CrmPermission::allows($this->marta, CrmPermission::TELEPHONY_CALL_PLACE), 'el Administrador no pide llamadas por defecto');
        $this->assertTrue(CrmPermission::allows($this->beto, CrmPermission::TELEPHONY_CALL_PLACE), 'Super Admin sí, por defecto');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function grantToAdministrator(string $permission): void
    {
        RolePermission::query()->updateOrCreate(['role' => Admin::ROLE_ADMINISTRADOR, 'permission' => $permission], ['granted' => true]);
        Cache::forget('crm.role_permissions.v1');
    }

    /**
     * Una versión APROBADA: la crea Ana y la aprueba Beto (cuatro ojos), con un
     * único paso por los canales de las plantillas dadas.
     *
     * @param  list<array{channel: string, content?: array<string,mixed>}>  $plantillas
     */
    private function approvedVersion(string $key, array $plantillas): StudioStrategyVersion
    {
        $canales = [];
        $referencias = [];
        $medio = 'whatsapp';
        foreach ($plantillas as $i => $p) {
            $t = $this->approvedTemplate($this->ana, $this->beto, $p['channel'], content: $p['content'] ?? null, key: "guion-{$key}-{$i}");
            $canal = $this->strategyContent($t)['sequence'][0]['channels'][0];
            $canales[] = $canal;
            $referencias[$canal] = ['uuid' => (string) $t->uuid, 'hash' => (string) $t->content_hash];
            $medio = str_ends_with($canal, 'voice') || $canal === 'voice_call' ? 'voice' : $medio;
        }
        $contenido = $this->strategyContent($t, [
            'sequence' => [[
                'id' => 'aviso',
                'delay' => ['n' => 0, 'unit' => 'hours', 'from' => 'enrollment'],
                'channels' => $canales,
                'templates' => $referencias,
                'on' => ['no_answer' => 'end_exit'],
            ]],
            'canary' => ['channel' => $medio],
        ]);
        $s = app(StudioStrategies::class)->create(new StudioAccess($this->ana), Request::create('/'), (string) Str::uuid(), $key, 'Estrategia '.$key, 'commercial', $contenido)['strategy'];
        $v = app(StudioStrategyVersions::class)->freeze(new StudioAccess($this->ana), Request::create('/'), (string) $s->uuid, (string) Str::uuid())['version'];

        return app(StudioStrategyVersions::class)->approve(new StudioAccess($this->beto), Request::create('/'), (string) $v->uuid, $v->validation_json['warningIds'], null);
    }

    private function voiceVersion(string $key = 'renovacion-voz'): StudioStrategyVersion
    {
        return $this->approvedVersion($key, [['channel' => 'voice']]);
    }

    private function whatsappVersion(string $key = 'renovacion-wa'): StudioStrategyVersion
    {
        return $this->approvedVersion($key, [['channel' => 'whatsapp']]);
    }

    private function publish(StudioStrategyVersion $v, Admin $as): TestResponse
    {
        return $this->s9('POST', "strategies/versions/{$v->uuid}/publish", $as, ['clientRequestId' => (string) Str::uuid(), 'expectedContentHash' => $v->content_hash]);
    }

    private function strategyOf(StudioStrategyVersion $v): StudioStrategy
    {
        return StudioStrategy::query()->findOrFail($v->strategy_id);
    }

    private function assertVoiceAuthorityRefused(TestResponse $r): void
    {
        $r->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', StudioStrategyPublishing::CODE_VOICE_AUTHORITY_REQUIRED)
            ->assertJsonPath('required_permission', 'telephony.call_place');
        $this->assertStringContainsString('Pedir llamadas salientes y colgar', (string) $r->json('message'));
    }

    /** La publicación con una evidencia de canario que pasa (la costura de pruebas de RollbackTest). */
    private function publishingWithEvidence(): StudioStrategyPublishing
    {
        $evidencia = static function (StudioStrategyVersion $v): array {
            $registro = ['format' => 'ultron-strategy-canary-evidence/1', 'version' => (string) $v->uuid, 'touches' => 3, 'passes' => true];

            return ['dto' => $registro, 'record' => $registro + ['evidenceHash' => CanonicalJson::hash($registro)], 'passes' => true, 'evidenceHash' => CanonicalJson::hash($registro)];
        };

        return new StudioStrategyPublishing(app(StudioAudit::class), app(StudioPersonBindings::class), null, $evidencia);
    }

    private static function evidenceHashFor(StudioStrategyVersion $v): string
    {
        return CanonicalJson::hash(['format' => 'ultron-strategy-canary-evidence/1', 'version' => (string) $v->uuid, 'touches' => 3, 'passes' => true]);
    }

    // ── Publicar ────────────────────────────────────────────────────────────

    public function test_publicar_una_version_con_voz_sin_la_llave_de_llamar_es_403_y_no_escribe_nada(): void
    {
        $v = $this->voiceVersion();

        $this->assertVoiceAuthorityRefused($this->publish($v, $this->marta));

        $this->assertSame('APPROVED', $v->refresh()->status, 'la versión no se movió');
        $this->assertSame(0, StudioStrategyPublication::query()->count(), 'la cadena no creció');
        $this->assertContains('strategy_version.voice_authority_denied', $this->auditEventsOf((string) $v->uuid), 'el intento queda en el libro');
        $denegado = StudioAuditEvent::query()->where('event', 'strategy_version.voice_authority_denied')->sole();
        $this->assertSame(['action' => 'publish'], $denegado->diff);
        $this->assertSame((int) $this->marta->id, (int) $denegado->actor_admin_id);

        // Repetir el intento no inunda el libro (una fila por persona, sujeto y evento cada 10 min).
        $this->assertVoiceAuthorityRefused($this->publish($v, $this->marta));
        $this->assertSame(1, StudioAuditEvent::query()->where('event', 'strategy_version.voice_authority_denied')->count());
    }

    public function test_la_misma_persona_publica_whatsapp_y_lo_asistido_como_siempre(): void
    {
        $whatsapp = $this->whatsappVersion();
        $asistidaPorVoz = $this->approvedVersion('asistida-voz', [['channel' => 'assisted', 'content' => ['medium' => 'voice', 'script' => 'Llama, recuerda que su plan vence pronto y ofrece ayuda para renovarlo. Si no quiere más contactos, anótalo.']]]);
        $asistidaPorWhatsapp = $this->approvedVersion('asistida-wa', [['channel' => 'assisted']]);
        $this->assertSame(['assisted:voice'], json_decode((string) $asistidaPorVoz->content_json, true)['sequence'][0]['channels']);

        foreach ([$whatsapp, $asistidaPorVoz, $asistidaPorWhatsapp] as $v) {
            $this->publish($v, $this->marta)->assertCreated()->assertJsonPath('data.verified', 'ok');
            $this->assertSame('CANARY', $v->refresh()->status);
        }
        $this->assertSame(3, StudioStrategyPublication::query()->where('action', 'publish')->count());
        $this->assertSame(0, DB::table('ultron_studio_audit_events')->where('event', 'like', '%voice_authority_denied')->count());
    }

    public function test_con_las_dos_llaves_publica_la_version_con_voz(): void
    {
        $deMarta = $this->voiceVersion('voz-marta');
        $deBeto = $this->voiceVersion('voz-beto');

        // Super Admin tiene las dos por defecto: el camino del dueño no cambia.
        $this->publish($deBeto, $this->beto)->assertCreated();
        $this->assertSame('CANARY', $deBeto->refresh()->status);

        // Concederle la de llamar a la jefa de mercadeo es una decisión de Roles, y entonces publica.
        $this->assertVoiceAuthorityRefused($this->publish($deMarta, $this->marta));
        $this->grantToAdministrator(CrmPermission::TELEPHONY_CALL_PLACE);
        $this->publish($deMarta, $this->marta)->assertCreated()->assertJsonPath('data.verified', 'ok');
        $this->assertSame('CANARY', $deMarta->refresh()->status);
    }

    public function test_un_paso_que_puede_ir_por_whatsapp_o_por_voz_tambien_exige_la_llave_de_llamar(): void
    {
        $v = $this->approvedVersion('mixta', [['channel' => 'whatsapp'], ['channel' => 'voice']]);
        $this->assertSame(['whatsapp_template', 'voice_call'], json_decode((string) $v->content_json, true)['sequence'][0]['channels']);

        $this->assertVoiceAuthorityRefused($this->publish($v, $this->marta));
        $this->assertSame('APPROVED', $v->refresh()->status);
    }

    public function test_la_autoridad_va_antes_que_el_reloj(): void
    {
        $v = $this->voiceVersion();
        // 20:00 de Bogotá: fuera de 07:00–19:00.
        $this->strategyFlags(enabled: true, publishing: true, window: '07:00-19:00', gate: StudioStrategyConfig::GATE_WAIVED);
        Carbon::setTestNow(Carbon::parse('2026-11-18 01:00:00', 'UTC'));

        // Quien no puede llamar se entera de que no puede, no de que vuelva mañana.
        $this->assertVoiceAuthorityRefused($this->publish($v, $this->marta));
        // Quien sí puede, choca con la ventana como siempre.
        $this->publish($v, $this->beto)->assertStatus(409)->assertJsonPath('code', 'outside_publication_window');
    }

    // ── Promover ────────────────────────────────────────────────────────────

    public function test_promover_una_version_con_voz_exige_la_llave_de_llamar(): void
    {
        $v = $this->voiceVersion();
        $this->publish($v, $this->beto)->assertCreated();

        // Antes que la evidencia: ni se mide.
        $this->assertVoiceAuthorityRefused($this->s9('POST', "strategies/versions/{$v->uuid}/promote", $this->marta, ['clientRequestId' => (string) Str::uuid(), 'evidenceHash' => self::evidenceHashFor($v)]));
        $this->assertSame('CANARY', $v->refresh()->status);
        $promover = $this->publishingWithEvidence();
        try {
            $promover->promote(new StudioAccess($this->marta), Request::create('/'), (string) $v->uuid, (string) Str::uuid(), self::evidenceHashFor($v), null);
            $this->fail('Promovió una versión con voz sin la llave de llamar.');
        } catch (UltronStudioException $e) {
            $this->assertSame([StudioStrategyPublishing::CODE_VOICE_AUTHORITY_REQUIRED, 403], [$e->errorCode, $e->status]);
        }

        $this->grantToAdministrator(CrmPermission::TELEPHONY_CALL_PLACE);
        $r = $promover->promote(new StudioAccess($this->marta), Request::create('/'), (string) $v->uuid, (string) Str::uuid(), self::evidenceHashFor($v), null);
        $this->assertSame('ok', $r['entry']['verified']);
        $this->assertSame('PUBLISHED', $v->refresh()->status);
    }

    public function test_promover_una_version_de_whatsapp_no_pide_la_llave_de_llamar(): void
    {
        $v = $this->whatsappVersion();
        $this->publish($v, $this->marta)->assertCreated();

        $r = $this->publishingWithEvidence()->promote(new StudioAccess($this->marta), Request::create('/'), (string) $v->uuid, (string) Str::uuid(), self::evidenceHashFor($v), null);
        $this->assertSame('PUBLISHED', $v->refresh()->status);
        $this->assertSame('ok', $r['entry']['verified']);
    }

    // ── Reanudar ────────────────────────────────────────────────────────────

    public function test_reanudar_una_estrategia_con_voz_exige_la_llave_de_llamar(): void
    {
        $voz = $this->voiceVersion();
        $whatsapp = $this->whatsappVersion();
        foreach ([$voz, $whatsapp] as $v) {
            $this->publish($v, $this->beto)->assertCreated();
            $this->s9('POST', 'strategies/'.$this->strategyOf($v)->uuid.'/pause', $this->ana, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Pausa para la prueba de SC-4'])->assertCreated();
        }
        $conVoz = $this->strategyOf($voz);

        $this->assertVoiceAuthorityRefused($this->s9('POST', "strategies/{$conVoz->uuid}/resume", $this->marta, ['clientRequestId' => (string) Str::uuid()]));
        $this->assertSame('paused', StudioStrategyPublications::stateOf($conVoz)['status'], 'sigue en pausa');
        $this->assertContains('strategy.voice_authority_denied', $this->auditEventsOf((string) $conVoz->uuid));

        // La de WhatsApp la reanuda igual que antes.
        $this->s9('POST', 'strategies/'.$this->strategyOf($whatsapp)->uuid.'/resume', $this->marta, ['clientRequestId' => (string) Str::uuid()])->assertCreated();

        // Con la llave de llamar, también la de voz.
        $this->grantToAdministrator(CrmPermission::TELEPHONY_CALL_PLACE);
        $this->s9('POST', "strategies/{$conVoz->uuid}/resume", $this->marta, ['clientRequestId' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.state.status', 'active');
    }

    // ── El detector y el texto de la llave ──────────────────────────────────

    public function test_el_detector_de_voz_lee_los_pasos_y_falla_cerrado(): void
    {
        $con = static function (array|string $contenido, ?string $huella = null): StudioStrategyVersion {
            $json = is_string($contenido) ? $contenido : (string) json_encode($contenido);

            return (new StudioStrategyVersion)->forceFill(['content_json' => $json, 'content_hash' => $huella ?? hash('sha256', $json)]);
        };
        $pasos = static fn (array ...$canales): array => ['sequence' => array_map(static fn (array $c): array => ['id' => 'p', 'channels' => $c], $canales)];

        $this->assertFalse(StudioStrategyPublishing::hasVoiceStep($con($pasos(['whatsapp_template']))));
        $this->assertFalse(StudioStrategyPublishing::hasVoiceStep($con($pasos(['assisted:voice'], ['assisted:whatsapp']))));
        $this->assertTrue(StudioStrategyPublishing::hasVoiceStep($con($pasos(['whatsapp_template'], ['voice_call']))));
        $this->assertTrue(StudioStrategyPublishing::hasVoiceStep($con($pasos(['assisted:voice', 'voice_call']))));
        // Falla cerrado: lo que no se puede leer cuenta como si llamara.
        $this->assertTrue(StudioStrategyPublishing::hasVoiceStep(null), 'una versión que no se encuentra');
        $this->assertTrue(StudioStrategyPublishing::hasVoiceStep($con($pasos(['whatsapp_template']), str_repeat('0', 64))), 'bytes que no casan con su huella');
        $this->assertTrue(StudioStrategyPublishing::hasVoiceStep($con('{no es json')), 'bytes que no son JSON');
        $this->assertTrue(StudioStrategyPublishing::hasVoiceStep($con(['goal' => 'renew'])), 'sin secuencia');
        $this->assertTrue(StudioStrategyPublishing::hasVoiceStep($con(['sequence' => [['id' => 'p']]])), 'un paso sin canales');
    }

    public function test_la_llave_de_publicar_dice_lo_que_exige(): void
    {
        $filas = array_column(PermissionCatalog::rows(), null, 'key');
        $ayuda = (string) $filas[StudioStrategyConfig::PUBLISH]['help'];

        $this->assertStringContainsString('«'.$filas[CrmPermission::TELEPHONY_CALL_PLACE]['label'].'»', $ayuda, 'nombra la llave de llamar como la ve Roles');
        // Los cuatro ojos como los aplica el código y como los manda el contrato (D9-11.2): la autora no publica ni promueve.
        $this->assertStringContainsString('nadie publica ni promueve una versión que creó', $ayuda);
        $this->assertStringNotContainsString('quien aprueba no publica', $ayuda);
    }
}
