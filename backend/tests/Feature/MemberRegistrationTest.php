<?php

namespace Tests\Feature;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_reuses_pending_member_with_same_normalized_document(): void
    {
        $member = Member::create([
            'full_name' => 'Oscar Mancipe',
            'email' => 'old@example.com',
            'document_number' => '1004301550',
            'status' => Member::STATUS_PENDING_REGISTRATION,
        ]);

        $response = $this->postJson('/api/members/register', [
            'full_name' => 'Oscar Daniel Mancipe Molina',
            'email' => 'new@example.com',
            'document_number' => '1.004 301-550',
            'phone' => '3215542105',
            'gender' => 'Masculino',
        ]);

        // Un registro pendiente con el mismo documento normalizado se REANUDA
        // (idempotente), no se duplica ni se rechaza.
        $response
            ->assertOk()
            ->assertJsonPath('status', 'resumed')
            ->assertJsonPath('member_id', $member->id)
            ->assertJsonPath('registration_status', Member::STATUS_PENDING_REGISTRATION);

        $this->assertDatabaseCount('members', 1);
        $this->assertDatabaseHas('members', [
            'id' => $member->id,
            'document_number' => '1004301550',
            'email' => 'new@example.com',
        ]);
    }

    public function test_register_rejects_active_member_with_clear_duplicate_error(): void
    {
        $member = Member::create([
            'full_name' => 'Active Member',
            'document_number' => '1004301550',
            'status' => Member::STATUS_ACTIVE,
        ]);

        $response = $this->postJson('/api/members/register', [
            'full_name' => 'Someone Else',
            'document_number' => '1004301550',
            'phone' => '3215542105',
            'gender' => 'Masculino',
        ]);

        $response
            ->assertStatus(409)
            ->assertJsonPath('status', 'duplicate_document')
            ->assertJsonPath('member_id', $member->id)
            ->assertJsonPath('message', 'Ya existe una cuenta registrada con este documento o correo.');
    }

    /** @return array<string, array{0: array<string,mixed>, 1: string}> */
    public static function invalidPhoneProvider(): array
    {
        return [
            'menos de 10' => [['phone' => '321554210'], 'phone'],
            'mas de 10 (se normaliza pero no cumple regex)' => [['phone' => '32155421050'], 'phone'],
            'no empieza por 3' => [['phone' => '6015542105'], 'phone'],
            'con letras' => [['phone' => '32155abcd1'], 'phone'],
            'vacio' => [['phone' => ''], 'phone'],
        ];
    }

    /** @dataProvider invalidPhoneProvider */
    public function test_register_rejects_invalid_colombian_phone(array $override, string $field): void
    {
        $response = $this->postJson('/api/members/register', array_merge([
            'full_name' => 'New Member',
            'document_number' => '900900900',
            'phone' => '3001234567',
            'gender' => 'Masculino',
        ], $override));

        $response->assertStatus(422)->assertJsonValidationErrors([$field]);
        $this->assertDatabaseMissing('members', ['document_number' => '900900900']);
    }

    public function test_register_accepts_phone_with_country_prefix_normalized(): void
    {
        $this->postJson('/api/members/register', [
            'full_name' => 'New Member',
            'document_number' => '900900901',
            'phone' => '+57 300 123 4567',
            'gender' => 'Femenino',
        ])->assertCreated();

        // Se normaliza a 10 dígitos nacionales antes de guardar.
        $this->assertDatabaseHas('members', [
            'document_number' => '900900901',
            'phone' => '3001234567',
        ]);
    }

    public function test_register_requires_a_valid_gender(): void
    {
        // Falta el género (la app no debe enviar "Seleccionar").
        $this->postJson('/api/members/register', [
            'full_name' => 'New Member',
            'document_number' => '900900902',
            'phone' => '3001234567',
        ])->assertStatus(422)->assertJsonValidationErrors(['gender']);

        // Valor fuera del conjunto válido.
        $this->postJson('/api/members/register', [
            'full_name' => 'New Member',
            'document_number' => '900900903',
            'phone' => '3001234567',
            'gender' => 'Seleccionar',
        ])->assertStatus(422)->assertJsonValidationErrors(['gender']);
    }

    /** Payload base de registro válido (datos ficticios). */
    private function registerPayload(array $override = []): array
    {
        return array_merge([
            'full_name' => 'Menor Prueba',
            'document_number' => (string) random_int(100000000, 999999999),
            'phone' => '3000000000',
            'gender' => 'Masculino',
            'email' => 'responsable@example.com',
        ], $override);
    }

    public function test_register_rejects_member_below_minimum_age(): void
    {
        // Edad por debajo del mínimo (cumple 10 hoy) → 422 por edad mínima.
        $tenYearsOld = now()->subYears(10)->format('Y-m-d');

        $this->postJson('/api/members/register', $this->registerPayload([
            'birth_date' => $tenYearsOld,
        ]))->assertStatus(422)->assertJsonValidationErrors(['birth_date']);
    }

    public function test_register_rejects_member_one_day_before_turning_eleven(): void
    {
        // Cumple 11 mañana: hoy aún tiene 10 → bloqueado (validación por fecha exacta).
        $almostEleven = now()->subYears(11)->addDay()->format('Y-m-d');

        $this->postJson('/api/members/register', $this->registerPayload([
            'birth_date' => $almostEleven,
        ]))->assertStatus(422)->assertJsonValidationErrors(['birth_date']);
    }

    public function test_register_allows_member_exactly_eleven_years_old(): void
    {
        // Cumple 11 justo hoy → permitido (menor con flujo de acudiente posterior).
        $exactlyEleven = now()->subYears(11)->format('Y-m-d');

        $this->postJson('/api/members/register', $this->registerPayload([
            'document_number' => '111111111',
            'birth_date' => $exactlyEleven,
            'is_minor' => true,
        ]))->assertCreated();
    }

    public function test_register_allows_minor_between_eleven_and_seventeen(): void
    {
        $fifteenYearsOld = now()->subYears(15)->format('Y-m-d');

        $this->postJson('/api/members/register', $this->registerPayload([
            'document_number' => '151515151',
            'birth_date' => $fifteenYearsOld,
            'is_minor' => true,
        ]))->assertCreated();
    }

    public function test_register_allows_adult(): void
    {
        $adult = now()->subYears(25)->format('Y-m-d');

        $this->postJson('/api/members/register', $this->registerPayload([
            'document_number' => '252525252',
            'birth_date' => $adult,
            'is_minor' => false,
        ]))->assertCreated();
    }

    public function test_register_allows_missing_birth_date(): void
    {
        // Sin fecha de nacimiento NO se bloquea por edad mínima (se confirma luego).
        $this->postJson('/api/members/register', $this->registerPayload([
            'document_number' => '262626262',
        ]))->assertCreated();
    }
}
