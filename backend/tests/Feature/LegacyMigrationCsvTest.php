<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Plan;
use App\Models\User;
use App\Support\MemberPayload;
use Database\Seeders\LegacyPlansSeeder;
use Database\Seeders\PlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ensayo de la migración real: corre `iron:import-legacy-members` con el CSV
 * depurado de los dos export del sistema anterior y comprueba lo que importa —
 * que los socios vigentes entren con su plan resuelto, que los vencidos queden
 * bloqueados, y que repetir la importación no duplique nada.
 *
 * El CSV vive en storage/ y no se versiona; si no está, el test se omite.
 */
class LegacyMigrationCsvTest extends TestCase
{
    use RefreshDatabase;

    private function csv(): string
    {
        $path = storage_path('socios_migracion.csv');
        if (! is_file($path)) {
            $this->markTestSkipped('Falta storage/socios_migracion.csv (generado desde los export).');
        }

        return $path;
    }

    private function seedPlans(): void
    {
        $this->seed(PlansSeeder::class);
        $this->seed(LegacyPlansSeeder::class);
    }

    public function test_every_plan_in_the_csv_exists_in_the_catalog(): void
    {
        $path = $this->csv();
        $this->seedPlans();

        $catalog = Plan::query()->pluck('name')->all();
        $missing = [];
        $fh = fopen($path, 'r');
        $header = fgetcsv($fh, 0, ';');
        $planIdx = array_search('plan', $header, true);
        while (($cols = fgetcsv($fh, 0, ';')) !== false) {
            $plan = trim((string) ($cols[$planIdx] ?? ''));
            if ($plan !== '' && ! in_array($plan, $catalog, true)) {
                $missing[$plan] = true;
            }
        }
        fclose($fh);

        // Un plan que no exista deja al socio con TODOS los módulos bloqueados
        // aunque su membresía esté vigente.
        $this->assertSame([], array_keys($missing), 'planes del CSV que faltan en el catálogo');
    }

    public function test_import_creates_members_and_unlocks_only_the_current_ones(): void
    {
        $path = $this->csv();
        $this->seedPlans();

        $this->artisan('iron:import-legacy-members', ['file' => $path])
            ->assertSuccessful();

        $total = Member::count();
        $this->assertGreaterThan(3000, $total, 'se importaron muy pocos socios');
        $this->assertSame($total, User::whereNotNull('document')->count());

        // Cada member enlazado a su user (members.user_id es único).
        $this->assertSame(0, Member::whereNull('user_id')->count());

        // Un socio con membresía vigente entra con su plan resuelto.
        $vigente = User::query()
            ->whereNotNull('plan')
            ->whereDate('membership_end_date', '>=', '2026-08-31')
            ->first();
        $this->assertNotNull($vigente, 'ningún socio quedó vigente');
        $this->assertNotNull(
            Plan::where('name', $vigente->plan)->first(),
            "el plan '{$vigente->plan}' no existe en el catálogo"
        );
        $features = MemberPayload::featuresFor($vigente);
        $this->assertTrue((bool) $features['workouts']);

        // Un socio vencido queda bloqueado: es el comportamiento correcto.
        $vencido = User::query()
            ->whereNotNull('plan')
            ->whereDate('membership_end_date', '<', '2026-08-31')
            ->first();
        $this->assertNotNull($vencido);
        $this->assertFalse((bool) MemberPayload::featuresFor($vencido)['iron_ia']);
    }

    public function test_running_it_twice_updates_instead_of_duplicating(): void
    {
        $path = $this->csv();
        $this->seedPlans();

        $this->artisan('iron:import-legacy-members', ['file' => $path])->assertSuccessful();
        $members = Member::count();
        $users = User::count();

        $this->artisan('iron:import-legacy-members', ['file' => $path])->assertSuccessful();

        // Idempotente: es lo que permite re-correrla cuando ya hay gente dentro.
        $this->assertSame($members, Member::count());
        $this->assertSame($users, User::count());
    }

    public function test_an_existing_member_keeps_a_longer_membership(): void
    {
        $path = $this->csv();
        $this->seedPlans();

        // Alguien que ya se registró por la app y renovó en el sistema nuevo.
        $fh = fopen($path, 'r');
        $header = fgetcsv($fh, 0, ';');
        $row = array_combine($header, fgetcsv($fh, 0, ';'));
        fclose($fh);

        $user = User::create([
            'name' => $row['full_name'],
            'email' => 'ya-registrado@example.com',
            'password' => 'secret',
            'document' => $row['document_number'],
            'phone' => '3001112233',
            'status' => 'active',
            'plan' => 'Anualidad',
            'membership_end_date' => '2027-12-31',
        ]);

        $this->artisan('iron:import-legacy-members', ['file' => $path])->assertSuccessful();

        // La importación NO debe acortar una membresía más larga ya existente.
        $user->refresh();
        $this->assertSame('2027-12-31', $user->membershipEndDate);
        $this->assertSame('Anualidad', $user->plan);
    }
}
