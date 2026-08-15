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

    /**
     * El CRM lee sexo y fecha de nacimiento del User (UserController::serialize),
     * no del Member. Al registrarse desde la app esos datos se guardaban solo en
     * `members` y la ficha administrativa aparecía vacía.
     */
    public function test_register_propaga_genero_y_fecha_al_usuario_del_crm(): void
    {
        $adult = now()->subYears(30)->format('Y-m-d');

        $this->postJson('/api/members/register', $this->registerPayload([
            'document_number' => '1004301551',
            'gender' => 'Femenino',
            'birth_date' => $adult,
        ]))->assertCreated();

        $member = Member::where('document_number', '1004301551')->firstOrFail();

        $this->assertSame('Femenino', $member->gender);
        $this->assertNotNull($member->user, 'El registro debe dejar el User del CRM enlazado.');
        $this->assertSame('Femenino', $member->user->gender);
        $this->assertSame($adult, substr((string) $member->user->birth_date, 0, 10));
    }

    /** Reanudar un registro no puede borrar datos que el CRM ya tuviera. */
    public function test_reanudar_registro_no_borra_el_genero_previo_del_crm(): void
    {
        $this->postJson('/api/members/register', $this->registerPayload([
            'document_number' => '1004301552',
            'gender' => 'Femenino',
        ]))->assertCreated();

        // Segundo intento sin fecha de nacimiento: el género sigue llegando y la
        // fecha ausente no debe pisar la que ya hubiera.
        $this->postJson('/api/members/register', $this->registerPayload([
            'document_number' => '1004301552',
            'gender' => 'Femenino',
        ]))->assertOk()->assertJsonPath('status', 'resumed');

        $member = Member::where('document_number', '1004301552')->firstOrFail();
        $this->assertSame('Femenino', $member->user->gender);
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
        // Edad por debajo del mínimo (cumple 12 hoy) → 422 por edad mínima.
        // El mínimo subió de 11 a 13 al retirar el tramo 9-12 de Google Play.
        $twelveYearsOld = now()->subYears(12)->format('Y-m-d');

        $this->postJson('/api/members/register', $this->registerPayload([
            'birth_date' => $twelveYearsOld,
        ]))->assertStatus(422)->assertJsonValidationErrors(['birth_date']);
    }

    public function test_register_rejects_member_one_day_before_turning_thirteen(): void
    {
        // Cumple 13 mañana: hoy aún tiene 12 → bloqueado (fecha exacta, no año).
        $almostThirteen = now()->subYears(13)->addDay()->format('Y-m-d');

        $this->postJson('/api/members/register', $this->registerPayload([
            'birth_date' => $almostThirteen,
        ]))->assertStatus(422)->assertJsonValidationErrors(['birth_date']);
    }

    public function test_register_allows_member_exactly_thirteen_years_old(): void
    {
        // Cumple 13 justo hoy → permitido (menor, con flujo de acudiente después).
        $exactlyThirteen = now()->subYears(13)->format('Y-m-d');

        $this->postJson('/api/members/register', $this->registerPayload([
            'document_number' => '111111111',
            'birth_date' => $exactlyThirteen,
        ]))->assertCreated();
    }

    public function test_register_allows_minor_between_thirteen_and_seventeen(): void
    {
        $fifteenYearsOld = now()->subYears(15)->format('Y-m-d');

        $this->postJson('/api/members/register', $this->registerPayload([
            'document_number' => '151515151',
            'birth_date' => $fifteenYearsOld,
        ]))->assertCreated();
    }

    public function test_register_derives_is_minor_from_birth_date_not_from_client(): void
    {
        // La app ya no puede declarar `is_minor`: lo deriva el servidor.
        $fifteenYearsOld = now()->subYears(15)->format('Y-m-d');

        $this->postJson('/api/members/register', $this->registerPayload([
            'document_number' => '161616161',
            'birth_date' => $fifteenYearsOld,
            'is_minor' => false,   // mentira del cliente: se ignora
        ]))->assertCreated();

        $this->assertTrue(
            (bool) Member::where('document_number', '161616161')->value('is_minor'),
            'El servidor debe marcar menor a un usuario de 15 años aunque el cliente diga lo contrario.',
        );
    }

    public function test_register_allows_adult(): void
    {
        $adult = now()->subYears(25)->format('Y-m-d');

        $this->postJson('/api/members/register', $this->registerPayload([
            'document_number' => '252525252',
            'birth_date' => $adult,
        ]))->assertCreated();

        $this->assertFalse(
            (bool) Member::where('document_number', '252525252')->value('is_minor'),
        );
    }

    public function test_register_allows_missing_birth_date(): void
    {
        // Sin fecha de nacimiento NO se bloquea por edad mínima (se confirma luego).
        $this->postJson('/api/members/register', $this->registerPayload([
            'document_number' => '262626262',
        ]))->assertCreated();
    }
}
