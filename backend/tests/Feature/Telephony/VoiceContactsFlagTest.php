<?php

namespace Tests\Feature\Telephony;

use App\Models\Admin;
use App\Models\Voice\VoiceConsent;
use App\Models\Voice\VoiceContact;
use App\Models\Voice\VoiceExclusion;
use App\Models\Voice\VoiceRneCheck;
use App\Services\Telephony\TelephonyException;
use App\Services\Telephony\VoiceContacts;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * Auditoría B-T4 (fase 4b, D-12): los contactos de voz se podían registrar sin
 * bandera, antes del visto bueno legal y sin supresión 1581 de la voz.
 * `VOICE_CONTACTS_ENABLED` (apagada) cierra el ALTA, la CARGA y la
 * CONFIRMACIÓN del consentimiento: 409 `voice_contacts_disabled` sin escribir,
 * también desde el servicio. Las lecturas, revocar, excluir y el RNE siguen
 * (solo restringen). El estado y el doctor la enseñan. Sin red.
 */
class VoiceContactsFlagTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    private const CONTACTS = 'telephony.contacts_manage';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        $this->voiceWorld();
        Queue::fake();
    }

    /** @return array{contacts: int, consents: int, exclusions: int, rne: int, pending: int} lo que hay escrito */
    private static function written(): array
    {
        return [
            'contacts' => VoiceContact::query()->count(),
            'consents' => VoiceConsent::query()->count(),
            'exclusions' => VoiceExclusion::query()->count(),
            'rne' => VoiceRneCheck::query()->count(),
            'pending' => VoiceContact::query()->whereNotNull('consent_request_source')->count(),
        ];
    }

    /**
     * Dos contactos dados de alta con la bandera ENCENDIDA (A sin nada pendiente,
     * B con un consentimiento cargado por Ana), y la bandera apagada después.
     *
     * @return array{0: string, 1: string, 2: array<string, string>, 3: array<string, string>}
     */
    private function contactsThenOff(): array
    {
        $comoAna = $this->actingAsAdmin($this->person(Admin::ROLE_SUPER_ADMIN, 'Ana Contactos'));
        $comoBeto = $this->actingAsAdmin($this->person(Admin::ROLE_SUPER_ADMIN, 'Beto Confirma'));
        config(['voice.contacts.enabled' => true]);
        $a = (string) $this->postJson('/api/admin/voice/contacts', ['clientRequestId' => (string) Str::uuid(), 'number' => '+57 300 000 0061', 'source' => ['type' => 'manual', 'id' => null]], $comoAna)
            ->assertCreated()->json('contact.uuid');
        $b = (string) $this->postJson('/api/admin/voice/contacts', ['clientRequestId' => (string) Str::uuid(), 'number' => '+57 300 000 0062', 'source' => ['type' => 'manual', 'id' => null]], $comoAna)
            ->assertCreated()->json('contact.uuid');
        $this->postJson("/api/admin/voice/contacts/{$b}/consents", ['source' => 'written_form', 'evidence' => 'Formulario firmado en recepción'], $comoAna)->assertStatus(202);
        config(['voice.contacts.enabled' => false]);

        return [$a, $b, $comoAna, $comoBeto];
    }

    public function test_por_defecto_esta_apagada(): void
    {
        $this->assertFalse(config('voice.contacts.enabled'), 'VOICE_CONTACTS_ENABLED nace apagada (protección 1)');
        $this->assertFalse(VoiceContacts::enabled());
    }

    public function test_apagada_el_alta_la_carga_y_la_confirmacion_dan_409_sin_escribir(): void
    {
        [$a, $b, $comoAna, $comoBeto] = $this->contactsThenOff();
        $antes = self::written();
        $this->assertSame(['contacts' => 2, 'consents' => 0, 'exclusions' => 0, 'rne' => 0, 'pending' => 1], $antes);

        $alta = ['clientRequestId' => (string) Str::uuid(), 'number' => '+57 300 000 0063', 'source' => ['type' => 'manual', 'id' => null]];
        $casos = [
            'alta' => ['contacts', $alta, $comoAna],
            // Con la bandera apagada el cuerpo no se llega a mirar: 409, nunca 422.
            'alta con un cuerpo inválido' => ['contacts', ['number' => '12'], $comoAna],
            'alta repetida (mismo id de petición que una real)' => ['contacts', $alta, $comoAna],
            'carga' => ["contacts/{$a}/consents", ['source' => 'written_form', 'evidence' => 'Formulario firmado'], $comoAna],
            'carga con un cuerpo inválido' => ["contacts/{$a}/consents", ['source' => 'otra'], $comoAna],
            'confirmación por otra persona' => ["contacts/{$b}/consents/confirm", ['clientRequestId' => (string) Str::uuid()], $comoBeto],
            'confirmación de un contacto que no existe' => ['contacts/'.Str::uuid().'/consents/confirm', ['clientRequestId' => (string) Str::uuid()], $comoBeto],
        ];
        foreach ($casos as $caso => [$ruta, $cuerpo, $quien]) {
            $r = $this->postJson('/api/admin/voice/'.$ruta, $cuerpo, $quien)->assertStatus(409);
            $this->assertSame(['ok' => false, 'code' => 'voice_contacts_disabled'], ['ok' => $r->json('ok'), 'code' => $r->json('code')], $caso);
            $this->assertStringNotContainsString('573000000063', (string) $r->getContent(), "{$caso}: el número no vuelve");
        }
        $this->assertSame($antes, self::written(), 'ni un contacto, ni un consentimiento, ni una carga pendiente');
        $this->assertNull(VoiceContact::query()->where('uuid', $a)->value('consent_request_source'), 'A sigue sin carga');
        $this->assertFalse(VoiceContacts::hasConsent((int) VoiceContact::query()->where('uuid', $b)->value('id')), 'B sigue sin consentimiento');
        Http::assertNothingSent();
    }

    public function test_el_permiso_va_antes_que_la_bandera(): void
    {
        $sinLlave = $this->adminAs(Admin::ROLE_RECEPCION);
        $alta = ['clientRequestId' => (string) Str::uuid(), 'number' => '+57 300 000 0064', 'source' => ['type' => 'manual', 'id' => null]];
        $this->postJson('/api/admin/voice/contacts', $alta)->assertStatus(401);
        $this->postJson('/api/admin/voice/contacts', $alta, $this->sharedTokenHeaders())->assertStatus(403);
        $this->postJson('/api/admin/voice/contacts', $alta, $sinLlave)->assertStatus(403);
        $this->postJson('/api/admin/voice/contacts', $alta, $this->adminWithPermissions(['telephony.view']))->assertStatus(403);
        $this->postJson('/api/admin/voice/contacts', $alta, $this->adminWithPermissions([self::CONTACTS]))->assertStatus(409)->assertJsonPath('code', 'voice_contacts_disabled');
        $this->assertSame(0, VoiceContact::query()->count());
    }

    public function test_apagada_las_lecturas_y_lo_que_solo_restringe_siguen(): void
    {
        [$a, $b, $comoAna] = $this->contactsThenOff();

        $lista = $this->getJson('/api/admin/voice/contacts', $comoAna)->assertOk();
        $this->assertSame(2, $lista->json('meta.total'));
        // Revocar, excluir (por contacto y por número) y registrar una consulta al RNE solo restringen las salientes: siguen.
        $this->postJson("/api/admin/voice/contacts/{$b}/revoke", ['clientRequestId' => (string) Str::uuid(), 'source' => 'written_form', 'evidence' => 'Pidió no recibir llamadas'], $comoAna)->assertCreated();
        $this->postJson('/api/admin/voice/exclusions', ['clientRequestId' => (string) Str::uuid(), 'contactUuid' => $a], $comoAna)->assertCreated();
        $this->postJson('/api/admin/voice/exclusions', ['clientRequestId' => (string) Str::uuid(), 'number' => '+57 300 000 0065'], $comoAna)->assertCreated();
        $this->postJson('/api/admin/voice/rne-checks', ['clientRequestId' => (string) Str::uuid(), 'contactUuid' => $a, 'checkedAt' => now()->subDay()->toIso8601String(),
            'result' => 'listed', 'evidenceRef' => 'CRC consulta 2001'], $comoAna)->assertCreated();
        $this->assertSame(['contacts' => 2, 'consents' => 1, 'exclusions' => 3, 'rne' => 1, 'pending' => 1], self::written());
        $this->assertSame('revoked', VoiceConsent::query()->sole()->kind);
    }

    public function test_el_servicio_tambien_lo_exige_para_cualquier_llamador(): void
    {
        [$a, $b] = $this->contactsThenOff();
        $antes = self::written();
        $ana = Admin::query()->where('name', 'Ana Contactos')->sole();
        $beto = Admin::query()->where('name', 'Beto Confirma')->sole();
        $contactos = app(VoiceContacts::class);
        $llamadas = [
            'register' => fn () => $contactos->register($ana, ['clientRequestId' => (string) Str::uuid(), 'number' => '+573000000066', 'sourceType' => 'manual', 'sourceId' => null]),
            'requestConsent' => fn () => $contactos->requestConsent($ana, $a, ['source' => 'written_form', 'evidence' => 'Formulario firmado']),
            'confirmConsent' => fn () => $contactos->confirmConsent($beto, $b, (string) Str::uuid()),
        ];
        foreach ($llamadas as $metodo => $llamada) {
            $this->assertSame([409, 'voice_contacts_disabled'], self::rejection($llamada), $metodo);
        }
        $this->assertSame($antes, self::written());

        // Encendida, el mismo servicio escribe (el camino entero lo prueba VoiceCrmRoutesTest).
        config(['voice.contacts.enabled' => true]);
        $this->assertSame(201, $llamadas['register']()['http']);
    }

    public function test_el_estado_y_el_doctor_la_ensenan(): void
    {
        $vista = $this->adminWithPermissions(['telephony.view']);
        foreach ([false => 'no', true => 'sí'] as $encendida => $texto) {
            config(['voice.contacts.enabled' => (bool) $encendida]);
            $this->getJson('/api/admin/voice/status', $vista)->assertOk()->assertJsonPath('flags.contacts', (bool) $encendida);

            $this->assertSame(0, Artisan::call('voice:doctor', ['--telefonia' => true]));
            $this->assertMatchesRegularExpression('/^Banderas   : .* · contactos '.$texto.'$/mu', Artisan::output());
            $this->assertSame(0, Artisan::call('voice:doctor', ['--telefonia' => true, '--json' => true]));
            $this->assertSame((bool) $encendida, json_decode(Artisan::output(), true, 64, JSON_THROW_ON_ERROR)['telefonia']['flags']['contacts']);
        }
        Http::assertNothingSent();
    }

    /** @return array{0: int, 1: string} el estado y el código del rechazo */
    private static function rejection(Closure $llamada): array
    {
        try {
            $llamada();
        } catch (TelephonyException $e) {
            return [$e->status, $e->errorCode];
        }
        self::fail('no rechazó');
    }
}
