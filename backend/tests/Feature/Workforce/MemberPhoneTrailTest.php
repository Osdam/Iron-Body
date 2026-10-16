<?php

namespace Tests\Feature\Workforce;

use App\Models\AuditLog;
use App\Models\Identity;
use App\Models\Member;
use App\Models\MemberAuthChallenge;
use App\Models\MemberDeviceBinding;
use App\Models\MemberSecurityEvent;
use App\Models\MemberSupportTicket;
use App\Services\Identity\MemberOwnershipLink;
use Database\Seeders\LegacyPlansSeeder;
use Database\Seeders\PlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Feature\Workforce\Concerns\MemberKit;
use Tests\Feature\Workforce\Concerns\WorkforceKit;
use Tests\TestCase;

/**
 * Precondición 2 del vínculo (hueco M2): todo cambio REAL de `members.phone`
 * deja rastro, venga del camino que venga, y la cuarentena de 7 días lo ve.
 *
 * El caso M2: el PATCH de la ficha del CRM registra los campos del USUARIO; si
 * `users.phone` ya tenía el número nuevo, `members.phone` cambiaba sin que
 * `phone` apareciera en la traza, y quien tiene `members.edit` podía poner su
 * número en la ficha de socio y recibir el código del vínculo ese mismo día.
 *
 * Cada camino se ejercita por su puerta real (ruta o comando), contra las
 * clases de producción; la única frontera fingida es el SMS (driver `dev`).
 */
class MemberPhoneTrailTest extends TestCase
{
    use MemberKit;
    use RefreshDatabase;
    use WorkforceKit;

    private const YURI = '1075000777';

    private const TEL_SOCIO = '+57 300 555 1234';

    private const TEL_NUEVO = '3209998877';

    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-15 09:00', 'America/Bogota')->utc());
        $this->admin = $this->adminWithPermissions(['workforce.view', 'workforce.manage', 'members.view', 'members.edit']);
        config(['workforce.member_link_enabled' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Utilidades ──────────────────────────────────────────────────────────

    /** Alta como administrativa (camino real); no toca la ficha de socio. */
    private function enPersonal(Member $member): Identity
    {
        $uuid = $this->postJson('/api/admin/workforce/staff', [
            'full_name' => $member->full_name, 'document' => $member->document_number, 'phone' => '+57 311 999 8877', 'positions' => ['administrative'],
        ], $this->admin)->assertCreated()->json('data.identity_id');

        return Identity::query()->where('uuid', $uuid)->firstOrFail();
    }

    private function pedir(Identity $identity, Member $member): TestResponse
    {
        return $this->postJson("/api/admin/workforce/staff/{$identity->uuid}/member-link", ['member_id' => $member->id], $this->admin);
    }

    /** La cuarentena bloquea: ni reto ni SMS, y la ficha lo dice. */
    private function assertCuarentena(Identity $identity, Member $member): void
    {
        $this->getJson("/api/admin/workforce/staff/{$identity->uuid}", $this->admin)
            ->assertJsonPath('data.member.link_blocked_reason', 'phone_recently_changed')
            ->assertJsonPath('data.member.can_verify', false);
        $this->pedir($identity, $member)->assertStatus(409)->assertJsonPath('code', 'member_phone_recently_changed');
        $this->assertSame(0, MemberAuthChallenge::query()->where('purpose', MemberAuthChallenge::PURPOSE_IDENTITY_LINK)->count());
        $this->assertNull($member->refresh()->identity_id);
    }

    /** @return Collection<int, MemberSecurityEvent> */
    private function rastros(Member $member)
    {
        return MemberSecurityEvent::query()
            ->where('member_id', $member->id)
            ->where('type', MemberSecurityEvent::TYPE_PHONE_FIELD_CHANGED)
            ->orderBy('id')
            ->get();
    }

    // ── M2: el PATCH del CRM con `users.phone` ya igual ─────────────────────

    public function test_m2_si_users_phone_ya_tenia_el_numero_el_patch_activa_la_cuarentena(): void
    {
        // El usuario del CRM ya tiene el número nuevo; la ficha de socio, no.
        $yuri = $this->socio(self::YURI, ['phone' => self::TEL_SOCIO], ['phone' => self::TEL_NUEVO]);
        $identity = $this->enPersonal($yuri);

        $this->patchJson("/api/users/{$yuri->user_id}", ['phone' => self::TEL_NUEVO], $this->admin)->assertOk();
        $this->assertSame(self::TEL_NUEVO, $yuri->refresh()->phone);

        // La traza del PATCH sigue sin `phone` (lista los campos del usuario)…
        $traza = AuditLog::query()->where('module', 'Miembros')->where('entity', 'cliente')->where('entity_id', (string) $yuri->user_id)->sole();
        $this->assertNotContains(['field' => 'phone'], (array) $traza->changes);
        // …pero la cuarentena lo ve, por el rastro del cambio de la ficha de socio.
        $this->assertCuarentena($identity, $yuri);
        $this->assertCount(1, $this->rastros($yuri));
        $this->assertSame(['field' => 'phone', 'via' => 'PATCH api/users/{user}'], $this->rastros($yuri)->first()->metadata);

        // Siete días después el número es el de la ficha y se puede verificar.
        Carbon::setTestNow(now()->addDays(7)->addMinute());
        $admin = $this->adminWithPermissions(['workforce.view', 'workforce.manage', 'members.view', 'members.edit']);
        $this->postJson("/api/admin/workforce/staff/{$identity->uuid}/member-link", ['member_id' => $yuri->id], $admin)
            ->assertCreated()->assertJsonPath('destination', '•••• 8877');
    }

    public function test_el_patch_que_cambia_los_dos_telefonos_deja_un_solo_rastro_de_datos(): void
    {
        $yuri = $this->socio(self::YURI, ['phone' => self::TEL_SOCIO], ['phone' => self::TEL_SOCIO]);
        $identity = $this->enPersonal($yuri);

        $this->patchJson("/api/users/{$yuri->user_id}", ['phone' => self::TEL_NUEVO], $this->admin)->assertOk();

        $this->assertCount(1, $this->rastros($yuri), 'Un cambio, un rastro.');
        $this->assertCuarentena($identity, $yuri);
    }

    // ── El resto de caminos que escriben `members.phone` ────────────────────

    public function test_el_registro_reanudado_que_reescribe_el_telefono_activa_la_cuarentena(): void
    {
        // Un registro reanudable lo reescribe con el token público de la app.
        $yuri = $this->socio(self::YURI, ['phone' => self::TEL_SOCIO, 'status' => Member::STATUS_INCOMPLETE]);
        $identity = $this->enPersonal($yuri);
        config(['services.member_registration.token' => 'token-de-la-apk']);

        $this->postJson('/api/members/register', [
            'full_name' => 'Yuri Andrea Socia', 'document_number' => self::YURI, 'phone' => self::TEL_NUEVO, 'gender' => 'Femenino',
        ], ['Authorization' => 'Bearer token-de-la-apk'])->assertOk()->assertJsonPath('status', 'resumed');
        $this->assertSame(self::TEL_NUEVO, $yuri->refresh()->phone);
        $this->assertSame('POST api/members/register', $this->rastros($yuri)->sole()->metadata['via']);

        // El registro termina después (la activación no toca el teléfono): el
        // número que puso quien registró sigue en cuarentena.
        $yuri->forceFill(['status' => Member::STATUS_ACTIVE])->save();
        $this->assertCount(1, $this->rastros($yuri));
        $this->assertCuarentena($identity, $yuri);
    }

    public function test_la_importacion_que_completa_el_telefono_activa_la_cuarentena(): void
    {
        $this->seed(PlansSeeder::class);
        $this->seed(LegacyPlansSeeder::class);
        $yuri = $this->socio(self::YURI, ['phone' => null]);
        $dir = storage_path('framework/testing/member-phone-trail');
        File::ensureDirectoryExists($dir);

        try {
            File::put($dir.'/clientes.csv', "\xEF\xBB\xBF"
                .'ID del cliente;Identificación del cliente;Nombre completo del cliente;Celular del cliente;Correo electrónico del cliente;'
                .'Dirección del cliente;Fecha de nacimiento del cliente;Observaciones del cliente;Nombre completo del contacto de respaldo;Celular del contacto de respaldo'
                ."\n1;".self::YURI.';Yuri Andrea Socia;+57 '.self::TEL_NUEVO.";;;;;;\n");
            File::put($dir.'/membresias.csv', "\xEF\xBB\xBF"
                .'ID del cliente;Identificación del cliente;Nombre del plan;ID de la membrecía;Fecha y hora de compra de la membrecía;'
                .'Fecha de inicio de la membrecía;Fecha de finalización de la membrecía;Fecha de finalización de la membrecía + Prorroga;'
                .'La membrecía está anulada?;La membrecía está totalmente paga?;Total pagado;Valor con descuento de la membrecía;Pagos realizados'
                ."\n1;".self::YURI.";MENSUALIDAD;9927;2026-08-31 20:54:00;2026-08-31;2026-09-29;2026-09-29;No;Si;80000;80000;$ 80.000 (Efectivo)\n");

            $this->artisan('iron:import-legacy-crm', ['--clientes' => $dir.'/clientes.csv', '--membresias' => $dir.'/membresias.csv'])->assertSuccessful();
        } finally {
            File::deleteDirectory($dir);
        }

        $this->assertNotNull($yuri->refresh()->phone, 'La importación completó el teléfono vacío.');
        // (El alta en personal va DESPUÉS: en PHPUnit, la última petición HTTP
        // sigue enlazada mientras corre el comando; en producción un comando es
        // un proceso sin ruta.)
        $this->assertSame('console', $this->rastros($yuri)->sole()->metadata['via']);
        $this->assertCuarentena($this->enPersonal($yuri), $yuri);
    }

    public function test_el_cambio_de_numero_desde_la_app_deja_el_rastro(): void
    {
        $yuri = $this->socio(self::YURI, ['phone' => '3005551234']);
        $identity = $this->enPersonal($yuri);
        $app = ['Authorization' => 'Bearer '.$yuri->access_hash];

        $req = $this->postJson('/api/member/security/phone-change/request', ['new_phone' => self::TEL_NUEVO], $app)->assertOk();
        $this->postJson('/api/member/security/phone-change/verify', [
            'challenge_id' => $req->json('challenge_id'), 'code' => $req->json('dev_code'),
        ], $app)->assertOk();

        $this->assertSame(self::TEL_NUEVO, $yuri->refresh()->phone);
        $this->assertSame('POST api/member/security/phone-change/verify', $this->rastros($yuri)->sole()->metadata['via']);
        // El evento del flujo sigue ahí, una vez; el rastro de datos no lo duplica.
        $this->assertSame(1, MemberSecurityEvent::query()->where('member_id', $yuri->id)->where('type', MemberSecurityEvent::TYPE_PHONE_CHANGED)->count());
        $this->assertCuarentena($identity, $yuri);
    }

    public function test_la_recuperacion_del_numero_deja_el_rastro(): void
    {
        $yuri = $this->socio(self::YURI, ['phone' => '3005551234']);
        $identity = $this->enPersonal($yuri);
        MemberDeviceBinding::create(['device_id' => 'equipo-de-yuri', 'member_id' => $yuri->id, 'device_name' => 'iPhone', 'platform' => 'ios', 'bound_at' => now()]);

        $ticket = $this->postJson('/api/member/phone-recovery/start', ['document' => self::YURI, 'device_id' => 'equipo-de-yuri'])->json('recovery_ticket');
        $req = $this->postJson('/api/member/phone-recovery/request', ['recovery_ticket' => $ticket, 'new_phone' => self::TEL_NUEVO, 'device_id' => 'equipo-de-yuri'])->assertOk();
        $this->postJson('/api/member/phone-recovery/verify', [
            'challenge_id' => $req->json('challenge_id'), 'code' => $req->json('dev_code'), 'device_id' => 'equipo-de-yuri',
        ])->assertOk();

        $this->assertSame(self::TEL_NUEVO, $yuri->refresh()->phone);
        $this->assertCount(1, $this->rastros($yuri));
        $this->assertCuarentena($identity, $yuri);
    }

    public function test_el_cambio_desde_soporte_deja_el_rastro(): void
    {
        $yuri = $this->socio(self::YURI, ['phone' => '3005551234']);
        $identity = $this->enPersonal($yuri);
        $ticket = MemberSupportTicket::create([
            'member_id' => $yuri->id, 'user_id' => $yuri->user_id, 'document' => self::YURI, 'type' => 'access',
            'message' => 'Perdí acceso a mi número.', 'status' => MemberSupportTicket::STATUS_NEW, 'platform' => 'login',
        ]);

        $this->postJson("/api/admin/support/{$ticket->id}/phone", ['phone' => self::TEL_NUEVO], $this->adminWithPermissions(['support.view', 'support.manage']))
            ->assertOk();

        $this->assertSame('POST api/admin/support/{ticket}/phone', $this->rastros($yuri)->sole()->metadata['via']);
        $this->assertCuarentena($identity, $yuri);
    }

    public function test_cualquier_otro_guardado_del_modelo_deja_el_rastro(): void
    {
        // Comandos, herramientas internas y lo que se escriba mañana: si pasa
        // por el modelo, pasa por el observer.
        $yuri = $this->socio(self::YURI, ['phone' => self::TEL_SOCIO]);
        $identity = $this->enPersonal($yuri);

        Member::query()->findOrFail($yuri->id)->update(['phone' => self::TEL_NUEVO]);

        $this->assertCount(1, $this->rastros($yuri));
        $this->assertCuarentena($identity, $yuri);
    }

    // ── Lo que NO es un cambio de teléfono ──────────────────────────────────

    public function test_un_guardado_sin_cambio_de_telefono_no_activa_la_cuarentena(): void
    {
        $yuri = $this->socio(self::YURI, ['phone' => self::TEL_SOCIO], ['phone' => self::TEL_SOCIO]);
        $identity = $this->enPersonal($yuri);

        // La ficha entera reenviada desde el CRM, con el mismo teléfono…
        $this->patchJson("/api/users/{$yuri->user_id}", ['name' => 'Yuri Andrea Socia', 'phone' => self::TEL_SOCIO, 'gender' => 'Femenino'], $this->admin)->assertOk();
        // …el mismo número con otro formato (mismo destino del SMS)…
        $yuri->refresh()->forceFill(['goal' => 'Fuerza', 'phone' => '+573005551234'])->save();
        $this->assertSame('+573005551234', $yuri->refresh()->phone);
        // …y un guardado que no toca el teléfono.
        $yuri->forceFill(['training_level' => 'Intermedio'])->save();

        $this->assertCount(0, $this->rastros($yuri));
        $this->getJson("/api/admin/workforce/staff/{$identity->uuid}", $this->admin)
            ->assertJsonPath('data.member.link_blocked_reason', null)
            ->assertJsonPath('data.member.can_verify', true);
        $this->pedir($identity, $yuri)->assertCreated();
    }

    public function test_dar_de_alta_una_ficha_no_es_un_cambio_de_telefono(): void
    {
        // El alta no reemplaza el número de nadie: quien la crea no puede
        // apuntar así el código de una ficha ajena (el documento es único).
        $yuri = $this->socio(self::YURI, ['phone' => self::TEL_SOCIO]);

        $this->assertCount(0, $this->rastros($yuri));
        $this->assertFalse(app(MemberOwnershipLink::class)->phoneRecentlyChanged($yuri));
    }

    // ── Cómo se guarda el rastro ────────────────────────────────────────────

    public function test_el_rastro_no_guarda_el_numero(): void
    {
        $yuri = $this->socio(self::YURI, ['phone' => self::TEL_SOCIO], ['phone' => self::TEL_NUEVO]);

        $this->patchJson("/api/users/{$yuri->user_id}", ['phone' => self::TEL_NUEVO], $this->admin)->assertOk();

        $rastro = $this->rastros($yuri)->sole();
        // Ni el número viejo ni el nuevo, en ningún formato, en ninguna columna.
        $fila = (string) preg_replace('/\D+/', '', (string) json_encode($rastro->getAttributes()));
        foreach (['3005551234', '5551234', self::TEL_NUEVO, '9998877'] as $digitos) {
            $this->assertStringNotContainsString($digitos, $fila);
        }
        $this->assertSame(['field', 'via'], array_keys($rastro->metadata));
    }

    public function test_si_el_rastro_no_se_puede_escribir_el_telefono_no_cambia(): void
    {
        $yuri = $this->socio(self::YURI, ['phone' => self::TEL_SOCIO]);
        MemberSecurityEvent::creating(function (MemberSecurityEvent $e): void {
            if ($e->type === MemberSecurityEvent::TYPE_PHONE_FIELD_CHANGED) {
                throw new RuntimeException('la bitácora no acepta escrituras');
            }
        });

        try {
            $yuri->forceFill(['phone' => self::TEL_NUEVO])->save();
            $this->fail('El cambio tenía que fallar con el rastro.');
        } catch (RuntimeException $e) {
            $this->assertSame('la bitácora no acepta escrituras', $e->getMessage());
        }

        // Un cambio sin rastro abriría la cuarentena: el teléfono sigue igual.
        $this->assertSame(self::TEL_SOCIO, Member::query()->whereKey($yuri->id)->value('phone'));
    }
}
