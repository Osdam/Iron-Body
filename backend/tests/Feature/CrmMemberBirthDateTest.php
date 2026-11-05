<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LA FECHA DE NACIMIENTO VIAJA EN TODAS LAS RESPUESTAS, Y CON EL MISMO NOMBRE.
 *
 * El CRM la pide en camelCase, igual que `membershipStartDate` o `isMinor`, y
 * solo se la mandaban las respuestas de CREAR y ACTUALIZAR. El listado y la
 * ficha devolvían el modelo tal cual, con `birth_date`, así que para el CRM no
 * existía. Dos consecuencias:
 *
 *  · la pantalla «Ver detalles» no podía enseñarla;
 *  · el modal de editar la abría VACÍA —leía `birthDate`, que llegaba sin
 *    definir— y al guardar mandaba `birthDate: null`. Es decir: editar a un
 *    socio abierto desde el listado le BORRABA la fecha de nacimiento, que es
 *    lo que decide si hace falta acudiente.
 *
 * `birth_date` sigue viajando además: hay clientes que ya lo leen y quitarlo
 * sería romperlos sin necesidad.
 */
class CrmMemberBirthDateTest extends TestCase
{
    use RefreshDatabase;

    private function socio(?string $nacimiento = '1998-03-15'): User
    {
        $user = User::create([
            'name' => 'Ana Prueba',
            'email' => 'ana-'.uniqid().'@example.test',
            'password' => bcrypt('secret-password'),
            'document' => (string) random_int(10000000, 99999999),
            'phone' => '3001112233',
            'birth_date' => $nacimiento,
            'status' => 'active',
        ]);

        Member::create([
            'user_id' => $user->id,
            'full_name' => $user->name,
            'document_number' => $user->document,
            'phone' => '3001112233',
            'birth_date' => $nacimiento,
            'status' => Member::STATUS_ACTIVE,
        ]);

        return $user;
    }

    public function test_el_listado_la_manda_como_la_pide_el_crm(): void
    {
        $socio = $this->socio();

        $fila = collect($this->getJson('/api/users', $this->adminHeaders())->assertOk()->json('data'))
            ->firstWhere('id', $socio->id);

        $this->assertSame('1998-03-15', $fila['birthDate']);
        // Sin hora: es una fecha, y el CRM la pinta tal cual.
        $this->assertSame('1998-03-15', $fila['birth_date'] ?? null, 'la clave antigua sigue viajando');
    }

    public function test_la_ficha_suelta_tambien(): void
    {
        $socio = $this->socio();

        $this->getJson("/api/users/{$socio->id}", $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('birthDate', '1998-03-15');
    }

    public function test_sin_fecha_llega_nula_y_no_vacia(): void
    {
        // Un socio importado del sistema anterior puede no tenerla. `null` dice
        // «no se sabe»; una cadena vacía parecería un dato.
        $socio = $this->socio(nacimiento: null);

        $this->getJson("/api/users/{$socio->id}", $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('birthDate', null);
    }

    public function test_editar_al_socio_del_listado_ya_no_le_borra_la_fecha(): void
    {
        // El camino real: el CRM abre la ficha del listado y guarda. Antes
        // mandaba `birthDate: null` porque nunca la había recibido.
        $socio = $this->socio();

        $fila = collect($this->getJson('/api/users', $this->adminHeaders())->assertOk()->json('data'))
            ->firstWhere('id', $socio->id);

        $this->patchJson("/api/users/{$socio->id}", [
            'phone' => '3009998877',
            'birthDate' => $fila['birthDate'],
        ], $this->adminHeaders())->assertOk();

        // Las dos columnas tienen cast de fecha, así que el atributo es un
        // Carbon: se compara el día, no el objeto.
        $this->assertSame('1998-03-15', $socio->fresh()->birth_date?->toDateString());
        $this->assertSame('1998-03-15', Member::where('user_id', $socio->id)->sole()->birth_date?->toDateString());
    }

    public function test_crear_y_actualizar_siguen_devolviendola(): void
    {
        $socio = $this->socio();

        $this->patchJson("/api/users/{$socio->id}", ['birthDate' => '2001-07-09'], $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('birthDate', '2001-07-09');
    }
}
