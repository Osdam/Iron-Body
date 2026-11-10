<?php

namespace Tests\Feature\UltronStudio\Strategies;

use App\Models\Admin;
use App\Models\UltronStudio\StudioPersonBinding;
use App\Services\UltronStudio\StudioPersonBindings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LOS VÍNCULOS CUENTA → PERSONA (contrato H9, D9-11.2, P9-4, §7 #26–#29): las
 * dos primeras personas solo por consola, con la autorización escrita y el
 * evento en el libro; después, los crea `ultron.person_bindings_manage` para
 * OTRA cuenta y los verifica una SEGUNDA persona ya vinculada, distinta de la
 * vinculada y de quien lo creó; un vínculo no se verifica a sí mismo; se
 * revoca una vez y no se borra. Sin documento ni teléfono en las respuestas.
 */
class UltronStudioStrategyPersonBindingsTest extends TestCase
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
        $this->strategyFlags(enabled: true);
    }

    public function test_el_arranque_solo_por_consola_con_autorizacion_y_una_sola_vez(): void
    {
        $a = $this->account(Admin::ROLE_SUPER_ADMIN, 'Primera');
        $b = $this->account(Admin::ROLE_SUPER_ADMIN, 'Segunda');
        $ia = $this->identity();
        $ib = $this->identity();

        // Sin autorización o con la misma cuenta, nada.
        $this->artisan('ultron-studio:person-bindings', ['--bootstrap' => true, '--admin-a' => (string) $a->id, '--identity-a' => (string) $ia->uuid,
            '--admin-b' => (string) $b->id, '--identity-b' => (string) $ib->uuid])->assertExitCode(2);
        $this->artisan('ultron-studio:person-bindings', ['--bootstrap' => true, '--admin-a' => (string) $a->id, '--identity-a' => (string) $ia->uuid,
            '--admin-b' => (string) $a->id, '--identity-b' => (string) $ib->uuid, '--authorization' => 'Orden escrita del propietario'])->assertExitCode(1);
        $this->assertSame(0, StudioPersonBinding::query()->count());

        $this->artisan('ultron-studio:person-bindings', ['--bootstrap' => true, '--admin-a' => (string) $a->id, '--identity-a' => (string) $ia->uuid,
            '--admin-b' => (string) $b->id, '--identity-b' => (string) $ib->uuid, '--authorization' => 'Orden escrita del propietario del 14-11-2026'])->assertExitCode(0);
        $this->assertSame(['bootstrap', 'bootstrap'], StudioPersonBinding::query()->pluck('origin')->all());
        $this->assertNotNull(StudioPersonBindings::personOf((int) $a->id));
        $this->assertNotNull(StudioPersonBindings::personOf((int) $b->id));
        $libro = DB::table('ultron_studio_audit_events')->where('event', 'person_binding.bootstrapped')->get();
        $this->assertCount(2, $libro);
        $this->assertSame('system', $libro[0]->actor_type);
        $this->assertStringNotContainsString((string) $ia->document_normalized, (string) $libro[0]->diff);

        // Una segunda vez, no: ya hay vínculos vivos.
        $c = $this->account();
        $d = $this->account();
        $this->artisan('ultron-studio:person-bindings', ['--bootstrap' => true, '--admin-a' => (string) $c->id, '--identity-a' => (string) $this->identity()->uuid,
            '--admin-b' => (string) $d->id, '--identity-b' => (string) $this->identity()->uuid, '--authorization' => 'Otra orden escrita'])->assertExitCode(1);
        $this->assertSame(2, StudioPersonBinding::query()->count());
    }

    public function test_crear_para_otra_cuenta_verificar_por_otra_persona_y_revocar_una_vez(): void
    {
        [$ana, $beto] = $this->bootstrapPair();
        $nueva = $this->account(Admin::ROLE_ADMINISTRADOR, 'Nueva');
        $identidad = $this->identity();

        // Nunca la propia cuenta.
        $this->s9('POST', 'strategies/person-bindings', $ana, ['clientRequestId' => (string) Str::uuid(), 'adminId' => $ana->id, 'identityUuid' => (string) $this->identity()->uuid])
            ->assertStatus(403)->assertJsonPath('code', 'own_binding_forbidden');
        $llave = (string) Str::uuid();
        $v = $this->s9('POST', 'strategies/person-bindings', $ana, ['clientRequestId' => $llave, 'adminId' => $nueva->id, 'identityUuid' => (string) $identidad->uuid])
            ->assertCreated()->json('data');
        $this->assertSame('pending', $v['status']);
        $this->assertArrayNotHasKey('document', $v);
        $this->assertSame(16, strlen((string) $v['identityRef']));
        $this->assertStringNotContainsString((string) $identidad->document_normalized, json_encode($v));
        // El reintento devuelve el mismo; otra cuenta con la misma llave, no.
        $this->s9('POST', 'strategies/person-bindings', $ana, ['clientRequestId' => $llave, 'adminId' => $nueva->id, 'identityUuid' => (string) $identidad->uuid])
            ->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.uuid', $v['uuid']);
        // Una cuenta con un vínculo vivo no recibe otro.
        $this->s9('POST', 'strategies/person-bindings', $beto, ['clientRequestId' => (string) Str::uuid(), 'adminId' => $nueva->id, 'identityUuid' => (string) $this->identity()->uuid])
            ->assertStatus(409)->assertJsonPath('code', 'binding_exists');

        // Quien lo creó no lo verifica; la vinculada tampoco (no tiene vínculo verificado); Beto sí.
        $this->s9('POST', "strategies/person-bindings/{$v['uuid']}/verify", $ana)->assertStatus(403)->assertJsonPath('code', 'four_eyes_required');
        $this->s9('POST', "strategies/person-bindings/{$v['uuid']}/verify", $nueva)->assertStatus(403);
        $this->s9('POST', "strategies/person-bindings/{$v['uuid']}/verify", $beto)->assertOk()->assertJsonPath('data.status', 'verified');
        $this->s9('POST', "strategies/person-bindings/{$v['uuid']}/verify", $beto)->assertStatus(409)->assertJsonPath('code', 'binding_already_verified');
        $this->assertNotNull(StudioPersonBindings::personOf((int) $nueva->id));

        // Revocar: con motivo, nunca el propio, una vez; no se borra.
        $this->s9('POST', "strategies/person-bindings/{$v['uuid']}/revoke", $ana, [])->assertStatus(422);
        $this->s9('POST', "strategies/person-bindings/{$v['uuid']}/revoke", $ana, ['reason' => 'Deja de trabajar en la sede'])->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->s9('POST', "strategies/person-bindings/{$v['uuid']}/revoke", $ana, ['reason' => 'Otra vez'])->assertStatus(409)->assertJsonPath('code', 'binding_revoked');
        $this->assertNull(StudioPersonBindings::personOf((int) $nueva->id));
        $this->assertSame(1, StudioPersonBinding::query()->where('uuid', $v['uuid'])->count());

        // Volver a vincular enseguida deja un aviso en el libro.
        $this->s9('POST', 'strategies/person-bindings', $beto, ['clientRequestId' => (string) Str::uuid(), 'adminId' => $nueva->id, 'identityUuid' => (string) $identidad->uuid])->assertCreated();
        $ultimo = DB::table('ultron_studio_audit_events')->where('event', 'person_binding.created')->orderByDesc('id')->first();
        $this->assertStringContainsString('rebind_after_recent_revoke', (string) $ultimo->diff);

        // La lista: solo con la llave, sin documento.
        $lista = $this->s9('GET', 'strategies/person-bindings', $ana)->assertOk()->json('data.data');
        $this->assertCount(4, $lista);
        $this->assertStringNotContainsString('document', json_encode($lista));
        $admin = $this->account(Admin::ROLE_ADMINISTRADOR);
        $this->s9('GET', 'strategies/person-bindings', $admin)->assertStatus(403);
    }

    public function test_un_vinculo_no_se_verifica_a_si_mismo_ni_lo_verifica_su_misma_persona_con_otra_cuenta(): void
    {
        [$ana, $beto, $identidadAna] = $this->bootstrapPair();
        // Beto crea un vínculo de una cuenta nueva a la persona de Ana: Ana no puede verificarlo (es la vinculada).
        $otraDeAna = $this->account(Admin::ROLE_SUPER_ADMIN, 'Ana bis');
        $v = $this->s9('POST', 'strategies/person-bindings', $beto, ['clientRequestId' => (string) Str::uuid(), 'adminId' => $otraDeAna->id, 'identityUuid' => (string) $identidadAna->uuid])
            ->assertCreated()->json('data');
        $this->s9('POST', "strategies/person-bindings/{$v['uuid']}/verify", $ana)->assertStatus(403)->assertJsonPath('code', 'four_eyes_required');
        // Nadie crea el vínculo de su propia persona, aunque sea para otra cuenta.
        $this->s9('POST', 'strategies/person-bindings', $ana, ['clientRequestId' => (string) Str::uuid(), 'adminId' => $this->account()->id, 'identityUuid' => (string) $identidadAna->uuid])
            ->assertStatus(403)->assertJsonPath('code', 'own_binding_forbidden');
    }
}
