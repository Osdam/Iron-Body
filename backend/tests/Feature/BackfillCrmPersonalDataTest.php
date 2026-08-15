<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El backfill repara las fichas del CRM a las que nunca llegó el sexo ni la
 * fecha de nacimiento del registro desde la app. Tiene que ser no destructivo:
 * jamás pisa un valor que el CRM ya tuviera.
 */
class BackfillCrmPersonalDataTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithUser(string $doc, array $memberAttrs, array $userAttrs = []): Member
    {
        $user = User::create(array_merge([
            'name' => 'Socio '.$doc,
            'email' => $doc.'@ironbody.test',
            'password' => bcrypt('secret-password'),
            'document' => $doc,
            'status' => 'active',
        ], $userAttrs));

        return Member::create(array_merge([
            'user_id' => $user->id,
            'full_name' => 'Socio '.$doc,
            'document_number' => $doc,
            'phone' => '3001112233',
            'status' => Member::STATUS_ACTIVE,
        ], $memberAttrs));
    }

    public function test_copia_sexo_y_fecha_donde_el_crm_los_tiene_vacios(): void
    {
        $member = $this->memberWithUser('111111', [
            'gender' => 'Femenino',
            'birth_date' => '1995-04-12',
        ]);

        $this->artisan('ironbody:backfill-crm-personal-data')->assertSuccessful();

        $user = $member->fresh()->user;
        $this->assertSame('Femenino', $user->gender);
        $this->assertSame('1995-04-12', substr((string) $user->birth_date, 0, 10));
    }

    public function test_no_sobrescribe_lo_que_el_crm_ya_tenia(): void
    {
        $member = $this->memberWithUser(
            '222222',
            ['gender' => 'Femenino', 'birth_date' => '1995-04-12'],
            ['gender' => 'Masculino', 'birth_date' => '1990-01-01'],
        );

        $this->artisan('ironbody:backfill-crm-personal-data')->assertSuccessful();

        $user = $member->fresh()->user;
        $this->assertSame('Masculino', $user->gender);
        $this->assertSame('1990-01-01', substr((string) $user->birth_date, 0, 10));
    }

    public function test_dry_run_no_escribe_nada(): void
    {
        $member = $this->memberWithUser('333333', ['gender' => 'Otro']);

        $this->artisan('ironbody:backfill-crm-personal-data --dry-run')->assertSuccessful();

        $this->assertNull($member->fresh()->user->gender);
    }

    public function test_es_idempotente(): void
    {
        $member = $this->memberWithUser('444444', ['gender' => 'Femenino']);

        $this->artisan('ironbody:backfill-crm-personal-data')->assertSuccessful();
        $this->artisan('ironbody:backfill-crm-personal-data')
            ->expectsOutputToContain('No hay fichas por reparar')
            ->assertSuccessful();

        $this->assertSame('Femenino', $member->fresh()->user->gender);
    }

    public function test_rellena_solo_el_campo_que_falta(): void
    {
        $member = $this->memberWithUser(
            '555555',
            ['gender' => 'Femenino', 'birth_date' => '1995-04-12'],
            ['gender' => 'Masculino'], // el sexo ya estaba; la fecha no
        );

        $this->artisan('ironbody:backfill-crm-personal-data')->assertSuccessful();

        $user = $member->fresh()->user;
        $this->assertSame('Masculino', $user->gender, 'no debe tocar el sexo existente');
        $this->assertSame('1995-04-12', substr((string) $user->birth_date, 0, 10));
    }
}
