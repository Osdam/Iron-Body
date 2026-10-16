<?php

namespace Tests\Feature\Workforce;

use App\Exceptions\OtpException;
use App\Models\AuditLog;
use App\Models\ClassEvent;
use App\Models\Identity;
use App\Models\Member;
use App\Models\MemberAuthChallenge;
use App\Models\MemberSecurityEvent;
use App\Models\MemberSupportTicket;
use App\Models\StaffProfile;
use App\Models\Trainer;
use App\Services\AccountRiskService;
use App\Services\Admin\AdminSessionService;
use App\Services\Identity\IdentityLinkService;
use App\Services\Otp\OtpPolicy;
use App\Services\OtpService;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Workforce\Concerns\MemberKit;
use Tests\Feature\Workforce\Concerns\WorkforceKit;
use Tests\TestCase;

/**
 * El vínculo VERIFICADO entre una persona del personal y su ficha de socio
 * (objetivo A, 2026-10-15). Casos 4, 5, 17 y 19 del plan, más el contrato de
 * errores de §7.2 (d) y (e).
 *
 * Todo corre contra las clases reales; la única frontera fingida es el
 * proveedor de SMS: el driver `dev` de OtpService, que no envía nada y deja el
 * destino en el log (de ahí se lee a qué número «salió» el código).
 */
class MemberOwnershipLinkTest extends TestCase
{
    use MemberKit;
    use RefreshDatabase;
    use WorkforceKit;

    private const YURI = '1075000777';

    private const TEL_SOCIO = '+57 300 555 1234';

    private const TEL_PERFIL = '+57 311 999 8877';

    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-15 09:00', 'America/Bogota')->utc());
        $this->admin = $this->adminWithPermissions(['workforce.view', 'workforce.manage', 'members.view', 'members.edit']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Yuri: socia sin identidad, dada de alta como administrativa por el
     * camino real. Su perfil laboral lleva OTRO teléfono.
     *
     * @return array{0: Identity, 1: Member}
     */
    private function yuriEnPersonal(): array
    {
        $yuri = $this->socio(self::YURI, ['phone' => self::TEL_SOCIO]);
        $this->conPagoYAccesoGratuito($yuri);
        $uuid = $this->postJson('/api/admin/workforce/staff', [
            'full_name' => 'Yuri Andrea', 'document' => self::YURI, 'phone' => self::TEL_PERFIL, 'positions' => ['administrative'],
        ], $this->admin)->assertCreated()->json('data.identity_id');

        return [Identity::query()->where('uuid', $uuid)->firstOrFail(), $yuri];
    }

    private function pedir(Identity $identity, Member|int $member, ?array $headers = null, array $extra = []): TestResponse
    {
        $id = $member instanceof Member ? $member->id : $member;

        return $this->postJson("/api/admin/workforce/staff/{$identity->uuid}/member-link", ['member_id' => $id] + $extra, $headers ?? $this->admin);
    }

    private function confirmar(Identity $identity, Member $member, string $challenge, string $code, ?array $headers = null): TestResponse
    {
        return $this->postJson("/api/admin/workforce/staff/{$identity->uuid}/member-link/confirm", [
            'member_id' => $member->id, 'challenge_id' => $challenge, 'code' => $code,
        ], $headers ?? $this->admin);
    }

    private function otroCodigo(string $code): string
    {
        return $code === '000000' ? '111111' : '000000';
    }

    // ── 4: el código va al teléfono del socio y prueba la titularidad ──────

    public function test_vincula_con_el_codigo_que_llega_al_telefono_registrado_del_socio(): void
    {
        [$identity, $yuri] = $this->yuriEnPersonal();
        $enviados = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$enviados): void {
            if (str_contains($e->message, 'driver dev')) {
                $enviados[] = $e->context['to'] ?? null;
            }
        });

        // Un teléfono en la petición se ignora: el destino es el de la ficha.
        $inicio = $this->pedir($identity, $yuri, extra: ['phone' => '+57 322 000 0000'])
            ->assertCreated()
            ->assertJsonPath('destination', '•••• 1234')
            ->assertJsonPath('expires_in', 300)
            ->assertJsonPath('reused', false);
        $code = (string) $inicio->json('dev_code');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $reto = MemberAuthChallenge::query()->where('uuid', $inicio->json('challenge_id'))->sole();
        $this->assertSame(MemberAuthChallenge::PURPOSE_IDENTITY_LINK, $reto->purpose);
        $this->assertSame($yuri->id, $reto->member_id);
        $this->assertSame(self::TEL_SOCIO, $reto->destination);
        $this->assertSame([self::TEL_SOCIO], $enviados, 'Un solo SMS, al teléfono de la ficha de socio.');

        $antes = $this->intocable($yuri);
        $this->confirmar($identity, $yuri, $reto->uuid, $code)
            ->assertOk()
            ->assertJsonPath('result', 'linked')
            ->assertJsonPath('data.member.link', 'linked')
            ->assertJsonPath('data.member.member_id', $yuri->id)
            ->assertJsonPath('data.member.employee_access.source', 'manual')
            ->assertJsonPath('data.member.membership.plan', 'INGRESO EMPLEADOS')
            ->assertJsonPath('data.member.story_live', false);

        $this->assertSame($identity->id, $yuri->refresh()->identity_id);
        // 19: solo cambió `members.identity_id`.
        $this->assertSame($antes, $this->intocable($yuri));
        $this->assertSame(MemberAuthChallenge::STATUS_VERIFIED, $reto->refresh()->status);

        // Traza del envío y del vínculo, sin el código.
        $trazas = AuditLog::query()->where('module', 'workforce')->where('entity', 'member_link')->orderBy('id')->get();
        $this->assertSame(['create', 'update'], $trazas->pluck('action')->all());
        foreach ($trazas as $traza) {
            $this->assertArrayNotHasKey('code', (array) $traza->metadata);
            $this->assertNotContains($code, array_map('strval', array_values((array) $traza->metadata)));
            $this->assertStringNotContainsString($code, (string) $traza->summary);
        }
        $this->assertSame($reto->uuid, $trazas->last()->metadata['challenge']);
        // Y dicen a qué número fue: enmascarado y con la huella de la política de OTP.
        foreach ($trazas as $traza) {
            $this->assertSame('•••• 1234', $traza->metadata['destination_masked']);
            $this->assertSame(app(OtpPolicy::class)->phoneHash(self::TEL_SOCIO), $traza->metadata['destination_hash']);
        }
        $this->assertSame(1, ClassEvent::query()->where('type', 'staff.updated')->where('reason', 'updated')->where('subject', $identity->uuid)->count());
    }

    public function test_sin_editar_miembros_no_se_vincula(): void
    {
        [$identity, $yuri] = $this->yuriEnPersonal();
        $sinMiembros = $this->adminWithPermissions(['workforce.view', 'workforce.manage', 'members.view']);

        $this->pedir($identity, $yuri, $sinMiembros)
            ->assertForbidden()->assertJsonPath('code', 'forbidden')->assertJsonPath('required_permission', 'members.edit');
        $this->confirmar($identity, $yuri, (string) Str::uuid(), '123456', $sinMiembros)
            ->assertForbidden()->assertJsonPath('required_permission', 'members.edit');
        // Y con `members.edit` pero sin gestionar personal, tampoco.
        $this->pedir($identity, $yuri, $this->adminWithPermissions(['members.edit', 'members.view', 'workforce.view']))->assertForbidden();

        $this->assertSame(0, MemberAuthChallenge::count(), 'Ningún SMS salió.');
        $this->assertNull($yuri->refresh()->identity_id);
    }

    public function test_un_codigo_equivocado_no_vincula_y_el_intento_queda_contado(): void
    {
        [$identity, $yuri] = $this->yuriEnPersonal();
        $inicio = $this->pedir($identity, $yuri)->assertCreated();
        $code = (string) $inicio->json('dev_code');

        $this->confirmar($identity, $yuri, $inicio->json('challenge_id'), $this->otroCodigo($code))
            ->assertStatus(422)->assertJsonPath('code', 'otp_invalid')->assertJsonPath('remaining', 4);

        // El intento sobrevive al rechazo: el código se comprueba FUERA de la
        // transacción del vínculo, que lo desharía.
        $this->assertSame(1, MemberAuthChallenge::query()->where('uuid', $inicio->json('challenge_id'))->value('attempts'));
        $this->assertNull($yuri->refresh()->identity_id);

        $this->confirmar($identity, $yuri, $inicio->json('challenge_id'), $code)->assertOk()->assertJsonPath('result', 'linked');
    }

    public function test_cinco_codigos_equivocados_bloquean_el_reto(): void
    {
        [$identity, $yuri] = $this->yuriEnPersonal();
        $inicio = $this->pedir($identity, $yuri)->assertCreated();
        $code = (string) $inicio->json('dev_code');
        $malo = $this->otroCodigo($code);

        foreach ([4, 3, 2, 1] as $quedan) {
            $this->confirmar($identity, $yuri, $inicio->json('challenge_id'), $malo)->assertStatus(422)->assertJsonPath('remaining', $quedan);
        }
        $this->confirmar($identity, $yuri, $inicio->json('challenge_id'), $malo)->assertStatus(423)->assertJsonPath('code', 'otp_blocked');
        // Bloqueado: ni el bueno sirve ya.
        $this->confirmar($identity, $yuri, $inicio->json('challenge_id'), $code)->assertStatus(423)->assertJsonPath('code', 'otp_blocked');
        $this->assertNull($yuri->refresh()->identity_id);
    }

    public function test_un_reto_de_login_o_de_otro_socio_no_sirve(): void
    {
        [$identity, $yuri] = $this->yuriEnPersonal();
        $otp = app(OtpService::class);

        // El código del login de la propia Yuri: otro propósito.
        $login = $otp->startChallenge($yuri, [], MemberAuthChallenge::PURPOSE_LOGIN);
        $this->confirmar($identity, $yuri, $login['challenge']->uuid, $login['code'])
            ->assertNotFound()->assertJsonPath('code', 'otp_not_found');
        $this->assertSame(MemberAuthChallenge::STATUS_PENDING, $login['challenge']->refresh()->status, 'El reto de login no se gastó.');

        // Un reto de vínculo, pero de otro socio.
        $otro = $this->socio('1075000999', ['full_name' => 'Otro Socio', 'phone' => '+57 300 777 4321'], ['name' => 'Otro Socio']);
        $ajeno = $otp->startChallenge($otro, [], MemberAuthChallenge::PURPOSE_IDENTITY_LINK);
        $this->confirmar($identity, $yuri, $ajeno['challenge']->uuid, $ajeno['code'])
            ->assertNotFound()->assertJsonPath('code', 'otp_not_found');

        $this->assertNull($yuri->refresh()->identity_id);
        $this->assertNull($otro->refresh()->identity_id);
    }

    public function test_un_codigo_caducado_no_vincula(): void
    {
        [$identity, $yuri] = $this->yuriEnPersonal();
        $inicio = $this->pedir($identity, $yuri)->assertCreated();

        Carbon::setTestNow(now()->addMinutes(6));
        $this->confirmar($identity, $yuri, $inicio->json('challenge_id'), (string) $inicio->json('dev_code'))
            ->assertStatus(410)->assertJsonPath('code', 'otp_expired');
        $this->assertNull($yuri->refresh()->identity_id);
    }

    // ── El orden que sostiene la garantía ───────────────────────────────────

    public function test_el_codigo_se_comprueba_fuera_y_el_vinculo_decide_sobre_filas_bloqueadas(): void
    {
        [$identity, $yuri] = $this->yuriEnPersonal();
        $inicio = $this->pedir($identity, $yuri)->assertCreated();

        $conexion = DB::connection();
        $original = $conexion->getQueryGrammar();
        if ($conexion->getDriverName() === 'sqlite') {
            // SQLite no tiene FOR UPDATE y su gramática compila `lockForUpdate()`
            // a nada. Esta solo deja a la vista, como comentario inerte, la
            // marca de bloqueo que lleva la consulta; PostgreSQL (donde también
            // corre esta prueba) escribe el `for update` de verdad.
            $conexion->setQueryGrammar(new class($conexion) extends SQLiteGrammar
            {
                protected function compileLock(Builder $query, $value)
                {
                    return $value === true ? '/* for update */' : parent::compileLock($query, $value);
                }
            });
        }

        $base = DB::transactionLevel();
        $sql = [];
        DB::listen(function ($q) use (&$sql): void {
            $sql[] = ['sql' => strtolower($q->sql), 'level' => DB::transactionLevel()];
        });

        try {
            $this->confirmar($identity, $yuri, $inicio->json('challenge_id'), (string) $inicio->json('dev_code'))
                ->assertOk()->assertJsonPath('result', 'linked');
        } finally {
            $conexion->setQueryGrammar($original);
        }

        $donde = fn (callable $es) => collect($sql)->search(fn (array $q) => $es($q['sql']));
        $verificado = $donde(fn (string $s) => str_starts_with($s, 'update "member_auth_challenges"') && str_contains($s, '"consumed_at"'));
        $identidad = $donde(fn (string $s) => str_starts_with($s, 'select') && str_contains($s, 'from "identities"') && str_contains($s, 'for update'));
        $socio = $donde(fn (string $s) => str_starts_with($s, 'select') && str_contains($s, 'from "members"') && str_contains($s, 'for update'));
        $escritura = $donde(fn (string $s) => str_starts_with($s, 'update "members"') && str_contains($s, '"identity_id"'));

        foreach (compact('verificado', 'identidad', 'socio', 'escritura') as $que => $i) {
            $this->assertNotFalse($i, "No se encontró la consulta «{$que}».");
        }
        $this->assertSame($base, $sql[$verificado]['level'], 'El código se marca usado FUERA de la transacción del vínculo.');
        $this->assertGreaterThan($base, $sql[$identidad]['level'], 'La identidad se bloquea dentro de la transacción.');
        $this->assertGreaterThan($base, $sql[$socio]['level'], 'El socio se bloquea dentro de la transacción.');
        $this->assertGreaterThan($base, $sql[$escritura]['level']);
        $this->assertTrue($verificado < $identidad && $identidad < $socio && $socio < $escritura, 'Código, identidad, socio y después la escritura.');
    }

    public function test_si_el_socio_se_vincula_con_otra_persona_mientras_se_dicta_el_codigo_no_se_pisa(): void
    {
        [$identity, $yuri] = $this->yuriEnPersonal();
        $inicio = $this->pedir($identity, $yuri)->assertCreated();
        $otra = Identity::create(['document_normalized' => 'OTRA-PERSONA']);

        // Entre el código correcto y la transacción del vínculo, otro proceso
        // cuelga la ficha de socio de otra identidad.
        $hecho = false;
        MemberAuthChallenge::updated(function (MemberAuthChallenge $c) use (&$hecho, $yuri, $otra): void {
            if (! $hecho && $c->status === MemberAuthChallenge::STATUS_VERIFIED) {
                $hecho = true;
                DB::table('members')->where('id', $yuri->id)->update(['identity_id' => $otra->id]);
            }
        });

        $this->confirmar($identity, $yuri, $inicio->json('challenge_id'), (string) $inicio->json('dev_code'))
            ->assertStatus(409)->assertJsonPath('code', 'member_linked_elsewhere');
        $this->assertTrue($hecho);
        $this->assertSame($otra->id, $yuri->refresh()->identity_id, 'El vínculo ganador no se sobrescribe.');
    }

    public function test_si_aparece_un_perfil_de_entrenador_mientras_se_dicta_el_codigo_no_se_vincula(): void
    {
        [$identity, $yuri] = $this->yuriEnPersonal();
        $inicio = $this->pedir($identity, $yuri)->assertCreated();
        $code = (string) $inicio->json('dev_code');

        $hecho = false;
        MemberAuthChallenge::updated(function (MemberAuthChallenge $c) use (&$hecho, $identity): void {
            if (! $hecho && $c->status === MemberAuthChallenge::STATUS_VERIFIED) {
                $hecho = true;
                Trainer::create(['full_name' => 'Yuri Andrea', 'document' => self::YURI, 'status' => 'active', 'identity_id' => $identity->id]);
            }
        });

        $this->confirmar($identity, $yuri, $inicio->json('challenge_id'), $code)
            ->assertStatus(409)->assertJsonPath('code', 'member_link_trainer_unsupported');
        $this->assertNull($yuri->refresh()->identity_id);

        // Ya no es entrenadora, pero el código se gastó: hace falta otro.
        Trainer::query()->where('identity_id', $identity->id)->delete();
        $this->confirmar($identity, $yuri, $inicio->json('challenge_id'), $code)
            ->assertStatus(409)->assertJsonPath('code', 'otp_used');
        $this->assertNull($yuri->refresh()->identity_id);
    }

    // ── 5: duplicados e idempotencia ────────────────────────────────────────

    public function test_un_socio_ya_vinculado_con_otra_persona_no_se_toca(): void
    {
        [$identity, $yuri] = $this->yuriEnPersonal();
        $otra = Identity::create(['document_normalized' => 'OTRA-PERSONA']);
        $yuri->forceFill(['identity_id' => $otra->id])->save();

        $this->getJson("/api/admin/workforce/staff/{$identity->uuid}", $this->admin)
            ->assertJsonPath('data.member.link', 'conflict')
            ->assertJsonPath('data.member.link_blocked_reason', 'linked_elsewhere')
            ->assertJsonPath('data.member.can_verify', false);
        $this->pedir($identity, $yuri)->assertStatus(409)->assertJsonPath('code', 'member_linked_elsewhere');

        $this->assertSame(0, MemberAuthChallenge::count());
        $this->assertSame($otra->id, $yuri->refresh()->identity_id);
    }

    public function test_una_persona_con_otra_ficha_de_socio_no_recibe_una_segunda(): void
    {
        [$identity, $yuri] = $this->yuriEnPersonal();
        $primera = $this->socio('1075000778', ['full_name' => 'Ficha Anterior'], ['name' => 'Ficha Anterior']);
        $primera->forceFill(['identity_id' => $identity->id])->save();

        $this->pedir($identity, $yuri)->assertStatus(409)->assertJsonPath('code', 'identity_has_member');
        $this->assertSame(0, MemberAuthChallenge::count());
        $this->assertNull($yuri->refresh()->identity_id);
    }

    public function test_repetir_el_vinculo_no_hace_nada(): void
    {
        [$identity, $yuri] = $this->yuriEnPersonal();
        $inicio = $this->pedir($identity, $yuri)->assertCreated();
        $cuerpo = [$identity, $yuri, $inicio->json('challenge_id'), (string) $inicio->json('dev_code')];
        $this->confirmar(...$cuerpo)->assertOk()->assertJsonPath('result', 'linked');

        // Doble clic en confirmar y volver a pedir: ni otro SMS ni otra escritura.
        $this->confirmar(...$cuerpo)->assertOk()->assertJsonPath('result', 'already_linked')->assertJsonPath('data.member.link', 'linked');
        $this->pedir($identity, $yuri)->assertOk()->assertJsonPath('result', 'already_linked')->assertJsonPath('data.identity_id', $identity->uuid);

        $this->assertSame(1, MemberAuthChallenge::count());
        $this->assertSame(1, AuditLog::query()->where('entity', 'member_link')->where('action', 'update')->count());
        $this->assertSame($identity->id, $yuri->refresh()->identity_id);
    }

    public function test_pedir_otra_vez_reutiliza_el_codigo_vivo_sin_otro_sms(): void
    {
        [$identity, $yuri] = $this->yuriEnPersonal();
        $primero = $this->pedir($identity, $yuri)->assertCreated();

        Carbon::setTestNow(now()->addSeconds(40));
        $this->pedir($identity, $yuri)
            ->assertCreated()
            ->assertJsonPath('reused', true)
            ->assertJsonPath('challenge_id', $primero->json('challenge_id'))
            ->assertJsonPath('expires_in', 260)
            ->assertJsonMissingPath('dev_code');
        $this->assertSame(1, MemberAuthChallenge::count());
    }

    // ── 17: entrenadores fuera de esta fase ─────────────────────────────────

    public function test_quien_tiene_perfil_de_entrenador_no_se_vincula_en_esta_fase(): void
    {
        $coach = $this->floorTrainer('1075000020');
        StaffProfile::create(['identity_id' => $coach->identity_id, 'full_name' => 'Coach Carlos Pérez', 'positions' => ['receptionist'], 'status' => 'active']);
        $suya = $this->socio('1075000020', ['full_name' => 'Coach Carlos Pérez']);

        $this->pedir($coach->identity, $suya)->assertStatus(409)->assertJsonPath('code', 'member_link_trainer_unsupported');
        $this->confirmar($coach->identity, $suya, (string) Str::uuid(), '123456')
            ->assertStatus(409)->assertJsonPath('code', 'member_link_trainer_unsupported');

        $this->assertSame(0, MemberAuthChallenge::count());
        $this->assertNull($suya->refresh()->identity_id);
    }

    // ── Resto del contrato de errores ───────────────────────────────────────

    public function test_un_socio_inexistente_o_de_otro_documento_responde_igual(): void
    {
        [$identity] = $this->yuriEnPersonal();
        $ajeno = $this->socio('1075000999', ['full_name' => 'Otro Socio'], ['name' => 'Otro Socio']);

        $inexistente = $this->pedir($identity, 999999)
            ->assertNotFound()->assertJsonPath('code', 'member_not_found');
        $otroDocumento = $this->pedir($identity, $ajeno)->assertNotFound()->assertJsonPath('code', 'member_not_found');

        $this->assertSame($inexistente->json(), $otroDocumento->json());
        $this->assertSame(0, MemberAuthChallenge::count());
    }

    public function test_una_identidad_sin_perfil_laboral_no_tiene_ficha_que_vincular(): void
    {
        $soloSocio = app(IdentityLinkService::class)->ensureIdentity('1075000555');
        $socio = $this->socio('1075000555');

        $this->pedir($soloSocio, $socio)->assertNotFound()->assertJsonPath('code', 'employee_not_found');
        $this->assertSame(0, MemberAuthChallenge::count());
    }

    public function test_sin_telefono_en_la_ficha_de_socio_no_hay_verificacion_posible(): void
    {
        $yuri = $this->socio(self::YURI, ['phone' => null]);
        $uuid = $this->postJson('/api/admin/workforce/staff', [
            'full_name' => 'Yuri Andrea', 'document' => self::YURI, 'phone' => self::TEL_PERFIL, 'positions' => ['administrative'],
        ], $this->admin)->assertCreated()->json('data.identity_id');
        $identity = Identity::query()->where('uuid', $uuid)->firstOrFail();

        // El teléfono del perfil laboral NO sirve de respaldo.
        $this->getJson("/api/admin/workforce/staff/{$uuid}", $this->admin)
            ->assertJsonPath('data.member.link', 'unlinked')
            ->assertJsonPath('data.member.link_blocked_reason', 'no_phone')
            ->assertJsonPath('data.member.can_verify', false)
            ->assertJsonPath('data.member.phone_masked', null);
        $this->pedir($identity, $yuri)->assertStatus(422)->assertJsonPath('code', 'member_without_phone');
        $this->assertSame(0, MemberAuthChallenge::count());
    }

    public function test_con_el_envio_apagado_responde_503_y_no_vincula(): void
    {
        // R1: el techo de gasto o el interruptor cortan el envío; la ficha se
        // queda en «coincide por documento, sin verificar».
        [$identity, $yuri] = $this->yuriEnPersonal();
        config(['otp.send_enabled' => false]);

        $this->pedir($identity, $yuri)->assertStatus(503)->assertJsonPath('code', 'otp_unavailable')->assertJsonStructure(['retry_after']);
        $this->getJson("/api/admin/workforce/staff/{$identity->uuid}", $this->admin)->assertJsonPath('data.member.link', 'unlinked');
        $this->assertNull($yuri->refresh()->identity_id);
    }

    // ── M1: un teléfono recién cambiado no prueba titularidad ──────────────

    /** El SMS «enviado» por el driver dev: a qué números salió. */
    private function escucharSms(): \Closure
    {
        $enviados = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$enviados): void {
            if (str_contains($e->message, 'driver dev')) {
                $enviados[] = $e->context['to'] ?? null;
            }
        });

        return function () use (&$enviados): array {
            return $enviados;
        };
    }

    public function test_tras_cambiar_el_telefono_en_la_ficha_del_crm_no_se_verifica_durante_siete_dias(): void
    {
        [$identity, $yuri] = $this->yuriEnPersonal();
        $sms = $this->escucharSms();

        // Quien tiene `members.edit` pone SU número en la ficha del socio…
        $this->patchJson("/api/users/{$yuri->user_id}", ['phone' => '+57 320 999 8877'], $this->admin)->assertOk();
        $this->assertSame('+57 320 999 8877', $yuri->refresh()->phone);
        // (la traza que deja ese PATCH es la que se consulta)
        $traza = AuditLog::query()->where('module', 'Miembros')->where('entity', 'cliente')->where('entity_id', (string) $yuri->user_id)->sole();
        $this->assertContains(['field' => 'phone'], $traza->changes);

        // …y pide el código: no sale ninguno.
        $this->getJson("/api/admin/workforce/staff/{$identity->uuid}", $this->admin)
            ->assertJsonPath('data.member.link', 'unlinked')
            ->assertJsonPath('data.member.link_blocked_reason', 'phone_recently_changed')
            ->assertJsonPath('data.member.can_verify', false);
        $this->pedir($identity, $yuri)->assertStatus(409)->assertJsonPath('code', 'member_phone_recently_changed');
        $this->assertSame(0, MemberAuthChallenge::count(), 'Ningún reto creado.');
        $this->assertSame([], $sms(), 'Ningún SMS salió.');
        $this->assertNull($yuri->refresh()->identity_id);

        // Siete días después el número ya es el de la ficha y se puede verificar.
        Carbon::setTestNow(now()->addDays(7)->addMinute());
        $admin = $this->adminWithPermissions(['workforce.view', 'workforce.manage', 'members.view', 'members.edit']);
        $this->getJson("/api/admin/workforce/staff/{$identity->uuid}", $admin)->assertJsonPath('data.member.can_verify', true);
        $this->pedir($identity, $yuri, $admin)->assertCreated()->assertJsonPath('destination', '•••• 8877');
        $this->assertSame(['+57 320 999 8877'], $sms());
    }

    public function test_un_cambio_de_telefono_desde_soporte_tambien_bloquea(): void
    {
        [$identity, $yuri] = $this->yuriEnPersonal();
        $ticket = MemberSupportTicket::create([
            'member_id' => $yuri->id, 'user_id' => $yuri->user_id, 'document' => self::YURI, 'type' => 'access',
            'message' => 'Perdí acceso a mi número.', 'status' => MemberSupportTicket::STATUS_NEW, 'platform' => 'login',
        ]);
        $this->postJson("/api/admin/support/{$ticket->id}/phone", ['phone' => '3209998877'], $this->adminWithPermissions(['support.view', 'support.manage']))
            ->assertOk();

        $this->pedir($identity, $yuri)->assertStatus(409)->assertJsonPath('code', 'member_phone_recently_changed');
        $this->assertSame(0, MemberAuthChallenge::count());
    }

    public function test_si_el_telefono_cambia_entre_pedir_y_confirmar_no_se_vincula_ni_se_gasta_el_codigo(): void
    {
        [$identity, $yuri] = $this->yuriEnPersonal();
        $inicio = $this->pedir($identity, $yuri)->assertCreated();

        $this->patchJson("/api/users/{$yuri->user_id}", ['phone' => '+57 320 999 8877'], $this->admin)->assertOk();

        $this->confirmar($identity, $yuri, $inicio->json('challenge_id'), (string) $inicio->json('dev_code'))
            ->assertStatus(409)->assertJsonPath('code', 'member_phone_recently_changed');
        $reto = MemberAuthChallenge::query()->where('uuid', $inicio->json('challenge_id'))->sole();
        $this->assertSame(MemberAuthChallenge::STATUS_PENDING, $reto->status);
        $this->assertSame(0, $reto->attempts);
        $this->assertNull($yuri->refresh()->identity_id);
    }

    public function test_si_el_telefono_cambia_mientras_se_comprueba_el_codigo_no_se_vincula(): void
    {
        [$identity, $yuri] = $this->yuriEnPersonal();
        $inicio = $this->pedir($identity, $yuri)->assertCreated();

        // Entre el código correcto y la transacción del vínculo, otra petición
        // termina un PATCH del teléfono: su traza es la del CRM.
        $hecho = false;
        MemberAuthChallenge::updated(function (MemberAuthChallenge $c) use (&$hecho, $yuri): void {
            if (! $hecho && $c->status === MemberAuthChallenge::STATUS_VERIFIED) {
                $hecho = true;
                AuditLog::create([
                    'action' => 'update', 'module' => 'Miembros', 'entity' => 'cliente', 'entity_id' => (string) $yuri->user_id,
                    'changes' => [['field' => 'phone']], 'actor_id' => '1', 'actor_name' => 'Otra sesión', 'created_at' => now(),
                ]);
            }
        });

        $this->confirmar($identity, $yuri, $inicio->json('challenge_id'), (string) $inicio->json('dev_code'))
            ->assertStatus(409)->assertJsonPath('code', 'member_phone_recently_changed');
        $this->assertTrue($hecho);
        $this->assertNull($yuri->refresh()->identity_id);
    }

    // ── B2: el cupo es del administrador, no de la sesión ──────────────────

    /** @return array{0: array, 1: array} dos sesiones del MISMO administrador */
    private function dosSesionesDelMismoAdmin(): array
    {
        $una = $this->adminWithPermissions(['workforce.view', 'workforce.manage', 'members.view', 'members.edit']);
        $admin = app(AdminSessionService::class)->resolveByToken(substr($una['Authorization'], 7))->admin;

        return [$una, $this->actingAsAdmin($admin)];
    }

    /** Una petición barata al inicio del vínculo: 404, pero cuenta para el cupo. */
    private function pedirEnBlanco(array $headers): TestResponse
    {
        return $this->postJson('/api/admin/workforce/staff/'.Str::uuid().'/member-link', ['member_id' => 1], $headers);
    }

    public function test_dos_sesiones_del_mismo_admin_comparten_el_cupo_por_minuto_y_no_tocan_el_del_login(): void
    {
        [$una, $otra] = $this->dosSesionesDelMismoAdmin();

        for ($i = 0; $i < 10; $i++) {
            $this->pedirEnBlanco($i % 2 === 0 ? $una : $otra)->assertNotFound();
        }
        $this->pedirEnBlanco($otra)->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
        $this->pedirEnBlanco($una)->assertStatus(429);
        // Confirmar comparte el cupo por minuto.
        $this->postJson('/api/admin/workforce/staff/'.Str::uuid().'/member-link/confirm', ['member_id' => 1, 'challenge_id' => (string) Str::uuid(), 'code' => '123456'], $otra)
            ->assertStatus(429);

        // Otro administrador, desde la misma IP, tiene su propio cupo; y el
        // login del CRM (cubo anónimo por IP) no se entera.
        $this->pedirEnBlanco($this->adminWithPermissions(['workforce.manage', 'members.edit']))->assertNotFound();
        $login = $this->postJson('/api/admin/auth/login', ['email' => 'nadie@ironbody.test', 'password' => 'incorrecta']);
        $this->assertNotSame(429, $login->status());
    }

    public function test_el_inicio_del_vinculo_tiene_un_tope_diario_por_administrador(): void
    {
        [$una, $otra] = $this->dosSesionesDelMismoAdmin();

        for ($minuto = 0; $minuto < 3; $minuto++) {
            for ($i = 0; $i < 10; $i++) {
                $this->pedirEnBlanco($i % 2 === 0 ? $una : $otra)->assertNotFound();
            }
            Carbon::setTestNow(now()->addSeconds(61));
        }

        // El 31 del día, con el cupo por minuto ya libre: no.
        $this->pedirEnBlanco($otra)->assertStatus(429)->assertJsonPath('code', 'too_many_requests')
            ->assertJsonPath('message', 'Llegaste al máximo de códigos de verificación por hoy.');
        // Confirmar no gasta ni sufre el tope diario.
        $this->postJson('/api/admin/workforce/staff/'.Str::uuid().'/member-link/confirm', ['member_id' => 1, 'challenge_id' => (string) Str::uuid(), 'code' => '123456'], $una)
            ->assertNotFound();
    }

    // ── B3: los errores del mostrador no son riesgo del socio ──────────────

    public function test_los_codigos_fallidos_del_vinculo_no_suman_riesgo_al_socio(): void
    {
        [$identity, $yuri] = $this->yuriEnPersonal();

        for ($reto = 0; $reto < 2; $reto++) {
            $inicio = $this->pedir($identity, $yuri)->assertCreated();
            $malo = $this->otroCodigo((string) $inicio->json('dev_code'));
            for ($i = 0; $i < 5; $i++) {
                $this->confirmar($identity, $yuri, $inicio->json('challenge_id'), $malo)->assertStatus($i < 4 ? 422 : 423);
            }
            // Enfriamiento del envío y cupo por minuto.
            Carbon::setTestNow(now()->addSeconds(61));
        }

        $this->assertSame(2, MemberAuthChallenge::query()->where('status', MemberAuthChallenge::STATUS_BLOCKED)->count());
        $this->assertSame(10, (int) MemberAuthChallenge::query()->sum('attempts'), 'Los diez intentos cuentan en sus retos.');
        $this->assertSame(0, MemberSecurityEvent::query()->where('member_id', $yuri->id)
            ->whereIn('type', [MemberSecurityEvent::TYPE_LOGIN_FAILED, MemberSecurityEvent::TYPE_OTP_BLOCKED])->count());
        $this->assertSame(0, app(AccountRiskService::class)->score($yuri));
        $this->assertNull($yuri->refresh()->identity_id);
    }

    public function test_un_codigo_fallido_del_login_del_socio_sigue_sumando_riesgo(): void
    {
        $socio = $this->socio('1075000999', ['full_name' => 'Otro Socio'], ['name' => 'Otro Socio']);
        $otp = app(OtpService::class);
        $login = $otp->startChallenge($socio, [], MemberAuthChallenge::PURPOSE_LOGIN);

        try {
            $otp->verify($login['challenge']->uuid, $this->otroCodigo($login['code']), []);
            $this->fail('Un código equivocado tenía que rechazarse.');
        } catch (OtpException $e) {
            $this->assertSame(422, $e->status);
        }

        $this->assertSame(1, MemberSecurityEvent::query()->where('member_id', $socio->id)->where('type', MemberSecurityEvent::TYPE_LOGIN_FAILED)->count());
        $this->assertSame((int) config('security.weights.login_failed'), app(AccountRiskService::class)->score($socio));
        $this->assertGreaterThan(0, app(AccountRiskService::class)->score($socio));
    }

    public function test_el_cupo_de_envios_del_socio_responde_429(): void
    {
        [$identity, $yuri] = $this->yuriEnPersonal();
        config(['otp.policy.account.window_limit' => 1]);
        // El socio ya gastó su cupo de la ventana (un login).
        app(OtpService::class)->startChallenge($yuri, [], MemberAuthChallenge::PURPOSE_LOGIN);

        $this->pedir($identity, $yuri)->assertStatus(429)->assertJsonPath('code', 'otp_rate_limited')->assertJsonStructure(['retry_after']);
        $this->assertSame(0, MemberAuthChallenge::query()->where('purpose', MemberAuthChallenge::PURPOSE_IDENTITY_LINK)->count());
    }
}
