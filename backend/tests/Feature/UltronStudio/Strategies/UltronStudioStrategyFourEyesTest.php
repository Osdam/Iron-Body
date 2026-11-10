<?php

namespace Tests\Feature\UltronStudio\Strategies;

use App\Models\Admin;
use App\Models\UltronStudio\StudioStrategyPublication;
use App\Models\UltronStudio\StudioStrategyVersion;
use App\Services\Outreach\Core\CanonicalJson;
use App\Services\Outreach\Core\StrategyChainSignature;
use App\Services\UltronStudio\StudioAccess;
use App\Services\UltronStudio\StudioPersonBindings;
use App\Services\UltronStudio\StudioStrategies;
use App\Services\UltronStudio\StudioStrategyPublications;
use App\Services\UltronStudio\StudioStrategySimulations;
use App\Services\UltronStudio\StudioStrategyVersions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

/**
 * T1 · GOBIERNO Y CUATRO OJOS REALES (contrato H9, D9-11.2, P9-4, L9-7): la
 * autora no aprueba, publica ni promueve; DOS CUENTAS DE LA MISMA PERSONA
 * TAMPOCO; una cuenta sin vínculo verificado no aprueba; las ventanas se
 * respetan; la cadena verifica y una entrada manipulada (un UPDATE crudo,
 * rechazado; o, sin la guarda, bytes o firma cambiados) no verifica y deja la
 * estrategia en `chain_unreadable`: no se publica nada sobre ella. Cero red.
 */
class UltronStudioStrategyFourEyesTest extends TestCase
{
    use RefreshDatabase;
    use StrategyFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->withoutRouteMap();
        $this->grantStrategyPermissions();
        $this->strategyFlags(enabled: true, publishing: true);
        Carbon::setTestNow($this->weekdayMorning());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Una versión de `$author`, simulada y lista para revisar.
     */
    private function versionBy(Admin $author, Admin $templateReviewer, string $key = 'renovacion'): StudioStrategyVersion
    {
        $t = $this->approvedTemplate($author, $templateReviewer, key: 'guion-'.$key);
        $s = app(StudioStrategies::class)->create(new StudioAccess($author), Request::create('/'), (string) Str::uuid(), $key, 'Estrategia '.$key, 'commercial', $this->strategyContent($t))['strategy'];
        $v = app(StudioStrategyVersions::class)->freeze(new StudioAccess($author), Request::create('/'), (string) $s->uuid, (string) Str::uuid())['version'];
        app(StudioStrategySimulations::class)->simulate(new StudioAccess($author), Request::create('/'), $s, $v, 'synthetic', ['people' => $this->syntheticPeople(), 'at' => '2026-11-17T15:00:00Z']);

        return $v->refresh();
    }

    public function test_t1_la_autora_no_aprueba_ni_publica_y_dos_cuentas_de_la_misma_persona_tampoco(): void
    {
        [$ana, $beto, $identidadAna] = $this->bootstrapPair();
        $carla = $this->account(Admin::ROLE_SUPER_ADMIN, 'Carla Tercera');
        $this->bindAccount($carla, $this->identity(), $ana, $beto);
        // La segunda cuenta de Ana: la crea Beto y la verifica Carla (ni la vinculada ni quien lo creó).
        $ana2 = $this->account(Admin::ROLE_SUPER_ADMIN, 'Ana (segunda cuenta)');
        $this->bindAccount($ana2, $identidadAna, $beto, $carla);

        $v = $this->versionBy($ana, $beto);
        $avisos = $v->validation_json['warningIds'];
        foreach ([$ana, $ana2] as $quien) {
            $r = $this->s9('POST', "strategies/versions/{$v->uuid}/approve", $quien, ['acknowledgedWarnings' => $avisos])->assertStatus(403);
            $this->assertSame('four_eyes_required', $r->json('code'), $quien->name);
        }
        // El intento queda en el libro (una fila por persona y evento cada 10 min).
        $this->assertContains('strategy_version.four_eyes_denied', $this->auditEventsOf((string) $v->uuid));

        $this->s9('POST', "strategies/versions/{$v->uuid}/approve", $carla, ['acknowledgedWarnings' => $avisos])->assertOk()->assertJsonPath('data.status', 'APPROVED');
        // Publicar: ni la autora ni su otra cuenta; Beto sí.
        foreach ([$ana, $ana2] as $quien) {
            $this->s9('POST', "strategies/versions/{$v->uuid}/publish", $quien, ['clientRequestId' => (string) Str::uuid(), 'expectedContentHash' => $v->content_hash])
                ->assertStatus(403)->assertJsonPath('code', 'four_eyes_required');
        }
        // Lo que se vio es lo que se publica: otra huella es 409 con las dos.
        $otra = str_repeat('e', 64);
        $this->s9('POST', "strategies/versions/{$v->uuid}/publish", $beto, ['clientRequestId' => (string) Str::uuid(), 'expectedContentHash' => $otra])
            ->assertStatus(409)->assertJsonPath('code', 'content_changed')->assertJsonPath('expected', $otra)->assertJsonPath('actual', $v->content_hash);
        $this->s9('POST', "strategies/versions/{$v->uuid}/publish", $beto, ['clientRequestId' => (string) Str::uuid(), 'expectedContentHash' => $v->content_hash])->assertCreated();
        // Promover: tampoco la autora (la evidencia ni se mide).
        $this->s9('POST', "strategies/versions/{$v->uuid}/promote", $ana2, ['clientRequestId' => (string) Str::uuid(), 'evidenceHash' => str_repeat('a', 64)])
            ->assertStatus(403)->assertJsonPath('code', 'four_eyes_required');
        // La aprobación guarda las dos personas, distintas.
        $fila = StudioStrategyVersion::query()->whereKey($v->id)->first();
        $this->assertNotSame($fila->created_by_person_id, $fila->approved_by_person_id);
    }

    public function test_t1_una_cuenta_sin_vinculo_verificado_no_aprueba_no_congela_ni_publica(): void
    {
        [$ana, $beto] = $this->bootstrapPair();
        $v = $this->versionBy($ana, $beto);
        $sinVinculo = $this->account(Admin::ROLE_SUPER_ADMIN, 'Sin vínculo');
        $this->s9('POST', "strategies/versions/{$v->uuid}/approve", $sinVinculo, ['acknowledgedWarnings' => $v->validation_json['warningIds']])
            ->assertStatus(403)->assertJsonPath('code', 'person_binding_required');
        // Pendiente (creado y sin verificar) tampoco vale.
        app(StudioPersonBindings::class)->create(new StudioAccess($ana), Request::create('/'), (string) Str::uuid(), (int) $sinVinculo->id, (string) $this->identity()->uuid, null);
        $this->s9('POST', "strategies/versions/{$v->uuid}/approve", $sinVinculo, ['acknowledgedWarnings' => $v->validation_json['warningIds']])
            ->assertStatus(403)->assertJsonPath('code', 'person_binding_required');
        $s = StudioStrategyVersion::query()->with('strategy')->whereKey($v->id)->first()->strategy;
        $this->s9('POST', "strategies/{$s->uuid}/versions", $sinVinculo, ['clientRequestId' => (string) Str::uuid()])->assertStatus(403)->assertJsonPath('code', 'person_binding_required');
    }

    public function test_t1_la_ventana_de_publicar_y_reanudar_se_respeta_y_pausar_no_depende_de_ella(): void
    {
        [$ana, $beto] = $this->bootstrapPair();
        $v = $this->versionBy($ana, $beto);
        $this->s9('POST', "strategies/versions/{$v->uuid}/approve", $beto, ['acknowledgedWarnings' => $v->validation_json['warningIds']])->assertOk();
        // 20:00 de Bogotá: fuera de 07:00–19:00.
        $this->strategyFlags(enabled: true, publishing: true, window: '07:00-19:00');
        Carbon::setTestNow(Carbon::parse('2026-11-18 01:00:00', 'UTC'));
        $this->s9('POST', "strategies/versions/{$v->uuid}/publish", $beto, ['clientRequestId' => (string) Str::uuid(), 'expectedContentHash' => $v->content_hash])
            ->assertStatus(409)->assertJsonPath('code', 'outside_publication_window');
        Carbon::setTestNow($this->weekdayMorning());
        $this->s9('POST', "strategies/versions/{$v->uuid}/publish", $beto, ['clientRequestId' => (string) Str::uuid(), 'expectedContentHash' => $v->content_hash])->assertCreated();
        $s = StudioStrategyVersion::query()->with('strategy')->whereKey($v->id)->first()->strategy;
        Carbon::setTestNow(Carbon::parse('2026-11-18 01:00:00', 'UTC'));
        $this->s9('POST', "strategies/{$s->uuid}/pause", $ana, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Fuera de horario, pausa de seguridad'])->assertCreated();
        $this->s9('POST', "strategies/{$s->uuid}/resume", $beto, ['clientRequestId' => (string) Str::uuid()])->assertStatus(409)->assertJsonPath('code', 'outside_publication_window');
        // Con la bandera de publicar apagada, publicar es 409 y revertir sigue (el sentido seguro).
        $this->strategyFlags(enabled: true, publishing: false, window: '');
        $this->s9('POST', "strategies/{$s->uuid}/resume", $beto, ['clientRequestId' => (string) Str::uuid()])->assertStatus(409)->assertJsonPath('code', 'strategies_publishing_disabled');
        $this->s9('POST', "strategies/{$s->uuid}/rollback", $ana, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Revertir con la bandera apagada'])->assertCreated();
    }

    public function test_t1_la_cadena_verifica_y_una_entrada_manipulada_la_deja_ilegible_y_no_se_publica_sobre_ella(): void
    {
        [$ana, $beto] = $this->bootstrapPair();
        $v = $this->versionBy($ana, $beto);
        $this->s9('POST', "strategies/versions/{$v->uuid}/approve", $beto, ['acknowledgedWarnings' => $v->validation_json['warningIds']])->assertOk();
        $this->s9('POST', "strategies/versions/{$v->uuid}/publish", $beto, ['clientRequestId' => (string) Str::uuid(), 'expectedContentHash' => $v->content_hash])->assertCreated();
        $s = StudioStrategyVersion::query()->with('strategy')->whereKey($v->id)->first()->strategy;
        $this->s9('POST', "strategies/{$s->uuid}/pause", $ana, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Pausa para la prueba de la cadena'])->assertCreated();
        $this->assertSame('ok', StudioStrategyPublications::verifyChain()['result']);

        // El mensaje firmado es el canónico del núcleo y su firma, la de la subclave propia.
        $fila = StudioStrategyPublication::query()->orderBy('seq')->first();
        $clave = StrategyChainSignature::appKeyRaw((string) config('app.key'));
        $this->assertSame(StrategyChainSignature::sign((string) $fila->effective_json, $clave), $fila->signature);
        $this->assertNotSame(hash_hmac('sha256', (string) $fila->effective_json, hash_hmac('sha256', 'ultron-studio-voice-chain', $clave, true)), $fila->signature, 'una firma no vale en otro dominio');

        // Un UPDATE crudo lo rechaza la base.
        try {
            DB::table('ultron_studio_strategy_publications')->where('id', $fila->id)->update(['reason' => 'manipulado']);
            $this->fail('La cadena aceptó un UPDATE.');
        } catch (Throwable $e) {
            $this->assertStringContainsString('ultron_studio:', $e->getMessage());
        }

        // Sin la guarda (la quita quien manda en la base), la manipulación se detecta igual.
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER usspub_forbid_update');
            // La última entrada (la pausa) dice ahora «activa»: sus bytes ya no son los firmados.
            $ultima = StudioStrategyPublication::query()->orderByDesc('seq')->first();
            $mensaje = json_decode((string) $ultima->effective_json, true);
            $mensaje['state']['status'] = 'active';
            DB::table('ultron_studio_strategy_publications')->where('id', $ultima->id)->update(['effective_json' => CanonicalJson::encode($mensaje)]);
            $this->assertSame(['result' => 'signature_mismatch', 'seq' => 2, 'verified' => 1], StudioStrategyPublications::verifyChain());
            $this->assertNotSame('ok', StudioStrategyPublications::verifyRow(StudioStrategyPublication::query()->find($ultima->id)));
            // La primera, con su mensaje y su firma intactos pero la ACCIÓN de la fila cambiada: no es lo que se firmó.
            DB::table('ultron_studio_strategy_publications')->where('id', $fila->id)->update(['action' => 'promote']);
            $this->assertSame(StudioStrategyPublications::VERIFY_ROW_MISMATCH, StudioStrategyPublications::verifyRow(StudioStrategyPublication::query()->find($fila->id)));
            // Ilegible: no se reanuda sobre ella (N5 `chain_unreadable` para la ejecución).
            $this->assertNotSame('ok', StudioStrategyPublications::stateOf($s)['verified']);
            $this->s9('POST', "strategies/{$s->uuid}/resume", $beto, ['clientRequestId' => (string) Str::uuid()])->assertStatus(409)->assertJsonPath('code', 'chain_unreadable');
        }
    }
}
