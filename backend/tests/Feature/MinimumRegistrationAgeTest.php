<?php

namespace Tests\Feature;

use App\Http\Requests\RegisterMemberRequest;
use App\Models\Member;
use App\Models\User;
use App\Rules\MinimumRegistrationAge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Edad mínima de registro: 13 años.
 *
 * Cubre el límite exacto por FECHA (no por año calendario), las fechas
 * corruptas y —lo más importante— que el cambio NO actúa retroactivamente:
 * ninguna cuenta histórica se bloquea, ni se le exige completar `birth_date`.
 */
class MinimumRegistrationAgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        config(['members.min_registration_age' => 13, 'members.legal_adult_age' => 18]);
    }

    /** Valida una fecha contra la regla real y devuelve los errores. */
    private function validateBirthDate(?string $value): array
    {
        return Validator::make(
            ['birth_date' => $value],
            ['birth_date' => ['nullable', 'date', new MinimumRegistrationAge]],
        )->errors()->get('birth_date');
    }

    // ── El límite exacto ──────────────────────────────────────────────────

    public function test_doce_anos_y_364_dias_es_rechazado(): void
    {
        // Cumple 13 MAÑANA: hoy todavía no.
        $birthDate = now()->subYears(13)->addDay()->toDateString();

        $errors = $this->validateBirthDate($birthDate);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('menores de 13', $errors[0]);
    }

    public function test_exactamente_13_anos_es_aceptado(): void
    {
        // Cumple 13 HOY.
        $this->assertEmpty($this->validateBirthDate(now()->subYears(13)->toDateString()));
    }

    public function test_mayor_de_13_es_aceptado(): void
    {
        $this->assertEmpty($this->validateBirthDate(now()->subYears(14)->toDateString()));
        $this->assertEmpty($this->validateBirthDate(now()->subYears(30)->toDateString()));
        $this->assertEmpty($this->validateBirthDate('1980-05-14'));
    }

    public function test_un_dia_antes_del_cumpleanos_13_sigue_rechazado(): void
    {
        $this->assertNotEmpty($this->validateBirthDate(
            now()->subYears(13)->addDays(1)->toDateString()
        ));
    }

    // ── Fechas corruptas ──────────────────────────────────────────────────

    public function test_fecha_futura_es_rechazada(): void
    {
        // Regresión: antes la regla devolvía sin fallar y la fecha entraba.
        $errors = $this->validateBirthDate(now()->addYear()->toDateString());

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('futuro', $errors[0]);
    }

    public function test_fecha_invalida_es_rechazada(): void
    {
        foreach (['no-es-una-fecha', '2026-13-45', '99/99/9999'] as $invalid) {
            $this->assertNotEmpty(
                $this->validateBirthDate($invalid),
                "Debería rechazar: {$invalid}",
            );
        }
    }

    public function test_fecha_ausente_no_falla_por_edad(): void
    {
        // Legítimo: la fecha viene del OCR y puede no leerse. El registro sigue
        // hacia revisión manual en vez de romperse.
        $this->assertEmpty($this->validateBirthDate(null));
        $this->assertEmpty($this->validateBirthDate(''));
    }

    // ── Cálculo de edad en el modelo ──────────────────────────────────────

    public function test_age_from_birth_date_distingue_desconocido_de_cero(): void
    {
        $this->assertNull(Member::ageFromBirthDate(null));
        $this->assertNull(Member::ageFromBirthDate(''));
        $this->assertNull(Member::ageFromBirthDate('no-es-fecha'));
        // Una fecha futura NO es "0 años": es un dato no fiable.
        $this->assertNull(Member::ageFromBirthDate(now()->addYears(2)->toDateString()));

        $this->assertSame(13, Member::ageFromBirthDate(now()->subYears(13)->toDateString()));
        $this->assertSame(30, Member::ageFromBirthDate(now()->subYears(30)->toDateString()));
    }

    public function test_el_minimo_sale_de_configuracion_con_respaldo_seguro(): void
    {
        config(['members.min_registration_age' => 16]);
        $this->assertSame(16, Member::minRegistrationAge());

        // Configuración corrupta → cae al valor seguro, nunca abre la puerta.
        config(['members.min_registration_age' => 0]);
        $this->assertSame(13, Member::minRegistrationAge());

        config(['members.min_registration_age' => -5]);
        $this->assertSame(13, Member::minRegistrationAge());
    }

    public function test_el_minimo_configurado_se_aplica_de_verdad(): void
    {
        config(['members.min_registration_age' => 16]);

        $errors = $this->validateBirthDate(now()->subYears(14)->toDateString());

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('menores de 16', $errors[0]);
    }

    // ── Registro desde la app (endpoint real) ─────────────────────────────

    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Nuevo Miembro',
            'document_number' => (string) random_int(1000000000, 1999999999),
            'phone' => '3001234567',
            'gender' => 'Masculino',
        ], $overrides);
    }

    public function test_registro_de_menor_de_13_es_rechazado_por_la_api(): void
    {
        $payload = $this->registrationPayload([
            'birth_date' => now()->subYears(12)->toDateString(),
        ]);

        $this->postJson('/api/members/register', $payload, [
            'X-Registration-Token' => (string) config('app.registration_token'),
        ])->assertStatus(422)->assertJsonValidationErrors('birth_date');

        $this->assertDatabaseMissing('members', [
            'document_number' => $payload['document_number'],
        ]);
    }

    public function test_la_app_no_puede_declarar_is_minor(): void
    {
        // Regresión: `is_minor` se aceptaba del cliente. Ahora lo deriva el
        // servidor de la fecha; un menor no puede auto-declararse adulto.
        $rules = (new RegisterMemberRequest)->rules();

        $this->assertArrayNotHasKey('is_minor', $rules);
    }

    // ── No retroactividad: lo más importante ──────────────────────────────

    public function test_las_cuentas_historicas_no_quedan_bloqueadas(): void
    {
        // Cuenta creada cuando el mínimo era 11: hoy tendría 12 años.
        $user = User::create([
            'name' => 'Miembro Histórico',
            'email' => 'historico@example.test',
            'password' => 'secret',
            'document' => '9876543210',
            'phone' => '3009876543',
            'status' => 'active',
            'plan' => 'PLAN TOTAL',
            'membership_end_date' => now()->addDays(30)->toDateString(),
        ]);

        $member = Member::create([
            'user_id' => $user->id,
            'full_name' => 'Miembro Histórico',
            'document_number' => '9876543210',
            'phone' => '3009876543',
            'access_hash' => 'tok-historico',
            'status' => Member::STATUS_ACTIVE,
            'birth_date' => now()->subYears(12)->toDateString(),
        ]);

        // Sigue entrando con normalidad: el cambio NO se aplica hacia atrás.
        $this->getJson('/api/member/app-state', [
            'Authorization' => 'Bearer '.$member->access_hash,
        ])->assertOk()->assertJsonPath('membership.is_active', true);

        $member->refresh();
        $this->assertSame(Member::STATUS_ACTIVE, $member->status);
    }

    public function test_una_cuenta_historica_sin_birth_date_sigue_funcionando(): void
    {
        // No se hace backfill ni se obliga a completar la fecha.
        $user = User::create([
            'name' => 'Sin Fecha',
            'email' => 'sinfecha@example.test',
            'password' => 'secret',
            'document' => '5555555555',
            'phone' => '3005555555',
            'status' => 'active',
            'plan' => 'PLAN TOTAL',
            'membership_end_date' => now()->addDays(30)->toDateString(),
        ]);

        $member = Member::create([
            'user_id' => $user->id,
            'full_name' => 'Sin Fecha',
            'document_number' => '5555555555',
            'phone' => '3005555555',
            'access_hash' => 'tok-sinfecha',
            'status' => Member::STATUS_ACTIVE,
            'birth_date' => null,
        ]);

        $this->getJson('/api/member/app-state', [
            'Authorization' => 'Bearer '.$member->access_hash,
        ])->assertOk();

        // La columna sigue siendo nullable y nadie la rellenó.
        $this->assertNull($member->fresh()->birth_date);
    }

    public function test_membresias_y_contratos_quedan_intactos(): void
    {
        $user = User::create([
            'name' => 'Con Membresía',
            'email' => 'membresia@example.test',
            'password' => 'secret',
            'document' => '4444444444',
            'phone' => '3004444444',
            'status' => 'active',
            'plan' => 'PLAN TOTAL',
            'membership_end_date' => now()->addDays(45)->toDateString(),
        ]);

        $member = Member::create([
            'user_id' => $user->id,
            'full_name' => 'Con Membresía',
            'document_number' => '4444444444',
            'phone' => '3004444444',
            'access_hash' => 'tok-membresia',
            'status' => Member::STATUS_ACTIVE,
            'birth_date' => now()->subYears(12)->toDateString(),
        ]);

        $planBefore = $user->plan;
        $endBefore = $user->membership_end_date;

        $this->getJson('/api/member/app-state', [
            'Authorization' => 'Bearer '.$member->access_hash,
        ])->assertOk();

        $user->refresh();
        $this->assertSame($planBefore, $user->plan);
        $this->assertEquals($endBefore, $user->membership_end_date);
        $this->assertSame('active', $user->status);
    }
}
