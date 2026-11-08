<?php

namespace Tests\Feature\Telephony;

use App\Models\Admin;
use App\Models\Incident;
use App\Models\Voice\VoiceBrakeEntry;
use App\Services\Telephony\TelephonyException;
use App\Services\Telephony\VoiceBrake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * El FRENO de voz (contrato H8, D8-19 [P-17]; fixture `policy-tables.json`
 * §brake, DF-P14). Lo abren solos sus causas (idempotente por su clave, con su
 * incidente de IRON GUARD) y SOLO lo suelta una persona con
 * `ultron.voice_publish`, con una nota y una vez. El Studio (S8) lo suelta a
 * través de {@see VoiceBrake::release()} (E2-6).
 */
class VoiceBrakeTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->voiceWorld();
    }

    public function test_abrir_es_idempotente_por_su_clave_y_abre_un_incidente(): void
    {
        $this->assertFalse(VoiceBrake::isOpen());
        $primera = VoiceBrake::open('guard_violation', 'guard:conv_1', ['codes' => ['voice_notice_missing']]);
        $this->assertNotNull($primera);
        $this->assertNull(VoiceBrake::open('guard_violation', 'guard:conv_1', []), 'la misma causa no abre otra vez');
        $this->assertTrue(VoiceBrake::isOpen());
        $this->assertSame(1, VoiceBrakeEntry::query()->count());
        $incidente = Incident::query()->where('source', 'voice')->sole();
        $this->assertSame(['voice_brake', Incident::SEVERITY_HIGH], [$incidente->kind, $incidente->severity]);
        $this->assertSame('guard_violation', $incidente->evidence['reason']);
    }

    public function test_solo_lo_suelta_una_persona_con_voice_publish_con_nota_y_una_vez(): void
    {
        $apertura = VoiceBrake::open('seal_mismatch', 'seal:CA1', []);
        $publica = $this->personWith(['ultron.voice_publish', 'ultron.view'], 'Publica');
        $sinLlave = $this->personWith(['ultron.voice_manage', 'telephony.view'], 'Sin llave');

        foreach ([[null, 'Revisado.', 'person_required', 403], [$sinLlave, 'Revisado.', 'forbidden', 403], [$publica, '', 'validation_failed', 422],
            [$publica, 'Llamé al 300 000 0031 y todo bien', 'validation_failed', 422], [$publica, str_repeat('a', 301), 'validation_failed', 422]] as [$quien, $nota, $codigo, $http]) {
            try {
                VoiceBrake::release($quien, $apertura->uuid, $nota, (string) Str::uuid());
                $this->fail("tenía que dar {$codigo}");
            } catch (TelephonyException $e) {
                $this->assertSame([$codigo, $http], [$e->errorCode, $e->status]);
            }
        }
        $this->assertTrue(VoiceBrake::isOpen());

        $crid = (string) Str::uuid();
        $suelta = VoiceBrake::release($publica, $apertura->uuid, 'Revisada la guarda: falso positivo del aviso.', $crid);
        $this->assertSame(['release', $apertura->id, $publica->id, 'admin'], [$suelta->kind, $suelta->brake_id, $suelta->actor_admin_id, $suelta->actor_type]);
        $this->assertFalse(VoiceBrake::isOpen());
        $this->assertSame($suelta->id, VoiceBrake::release($publica, $apertura->uuid, 'Otra vez.', $crid)->id, 'la misma petición es idempotente');
        try {
            VoiceBrake::release($publica, $apertura->uuid, 'Otra vez.', (string) Str::uuid());
            $this->fail('se suelta una vez');
        } catch (TelephonyException $e) {
            $this->assertSame([409, 'brake_already_released'], [$e->status, $e->errorCode]);
        }
        try {
            VoiceBrake::release($publica, (string) Str::uuid(), 'Revisado.', (string) Str::uuid());
            $this->fail('no existe');
        } catch (TelephonyException $e) {
            $this->assertSame(404, $e->status);
        }
        $lista = VoiceBrake::list();
        $this->assertSame([true, 'Publica', 'Revisada la guarda: falso positivo del aviso.'], [$lista[0]['released'], $lista[0]['releasedBy'], $lista[0]['note']]);
    }

    public function test_un_super_admin_lo_suelta_y_el_administrador_por_defecto_no(): void
    {
        $apertura = VoiceBrake::open('cost_cap', 'cost_cap:2026-11-07', []);
        $admin = $this->person(Admin::ROLE_ADMINISTRADOR, 'Administrador');
        $this->expectException(TelephonyException::class);
        try {
            VoiceBrake::release($admin, $apertura->uuid, 'Revisado.', (string) Str::uuid());
        } finally {
            $this->assertTrue(VoiceBrake::isOpen());
            VoiceBrake::release($this->person(Admin::ROLE_SUPER_ADMIN, 'Root'), $apertura->uuid, 'Revisado el gasto del día.', (string) Str::uuid());
            $this->assertFalse(VoiceBrake::isOpen());
        }
    }
}
