<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Altas duplicadas desde el CRM: correo y documento.
 *
 * LO QUE ESTO CIERRA
 * ------------------
 * Recepción intentó dar de alta a un socio con un correo que ya pertenecía a
 * otro, y la pantalla dijo «Server Error». No era un fallo del servidor: era el
 * índice `users_email_unique` haciendo su trabajo, y un QueryException que
 * nadie capturaba saliendo como 500. Quien estaba al mostrador no tenía forma
 * de saber qué corregir.
 *
 * El documento sí se comprobaba antes de insertar; el correo no. Y la
 * comprobación previa, por sí sola, nunca basta: entre el `exists()` y el
 * INSERT cabe otra petición.
 *
 * EL INVARIANTE: un duplicado se responde con 422 y el campo culpable. Un 500
 * es un fallo nuestro, no una forma de decirle a alguien que repita el correo.
 */
class CrmMemberDuplicateContactTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): array
    {
        return $this->actingAsAdmin();
    }

    /** @return array<string, mixed> */
    private function alta(array $over = []): array
    {
        return array_merge([
            'fullName' => 'Jose Eduardo Cedeño',
            'document' => '1003812287',
            'phone' => '3226822445',
            'email' => 'nuevo-'.uniqid().'@correo.test',
            'birthDate' => '1999-10-09',
            'gender' => 'Masculino',
            'address' => 'Iv Centenario',
        ], $over);
    }

    private function existente(string $documento, string $correo): User
    {
        $user = User::create([
            'name' => 'Eduard Tovar',
            'email' => $correo,
            'password' => bcrypt('secret-password'),
            'document' => $documento,
            'phone' => '3103020048',
            'status' => 'active',
        ]);

        Member::create([
            'user_id' => $user->id,
            'full_name' => 'Eduard Tovar',
            'document_number' => $documento,
            'phone' => '3103020048',
            'status' => Member::STATUS_ACTIVE,
        ]);

        return $user;
    }

    // ── Correo ──────────────────────────────────────────────────────────────

    public function test_un_correo_nuevo_crea_el_miembro(): void
    {
        $r = $this->postJson('/api/users', $this->alta(), $this->admin());

        $r->assertStatus(201);
        $this->assertSame(1, User::where('document', '1003812287')->count());
        $this->assertSame(1, Member::where('document_number', '1003812287')->count());
    }

    /** EL CASO DEL INCIDENTE, tal cual ocurrió en producción. */
    public function test_un_correo_ya_registrado_devuelve_422_y_no_500(): void
    {
        $this->existente('1077852899', 'eduardguzman166@gmail.com');

        $r = $this->postJson('/api/users', $this->alta(['email' => 'eduardguzman166@gmail.com']), $this->admin());

        $r->assertStatus(422);
        $this->assertNotSame(500, $r->getStatusCode());
    }

    public function test_el_correo_duplicado_dice_cual_es_el_campo(): void
    {
        $this->existente('1077852899', 'eduardguzman166@gmail.com');

        $this->postJson('/api/users', $this->alta(['email' => 'eduardguzman166@gmail.com']), $this->admin())
            ->assertStatus(422)
            ->assertJsonPath('message', 'Este correo ya está registrado.')
            ->assertJsonPath('errors.email.0', 'Este correo ya está registrado.');
    }

    public function test_el_correo_duplicado_no_delata_a_su_dueno(): void
    {
        $this->existente('1077852899', 'eduardguzman166@gmail.com');

        $cuerpo = $this->postJson('/api/users', $this->alta(['email' => 'eduardguzman166@gmail.com']), $this->admin())
            ->getContent();

        // Ni el nombre ni el documento del otro socio: quien se equivoca de
        // correo no tiene por qué enterarse de quién lo tiene.
        $this->assertStringNotContainsString('Eduard Tovar', (string) $cuerpo);
        $this->assertStringNotContainsString('1077852899', (string) $cuerpo);
    }

    public function test_sin_correo_se_sigue_pudiendo_crear(): void
    {
        $this->postJson('/api/users', array_diff_key($this->alta(), ['email' => null]), $this->admin())
            ->assertStatus(201);

        $user = User::where('document', '1003812287')->firstOrFail();
        $this->assertStringEndsWith('@ironbody.local', $user->email, 'se genera uno que no puede chocar');
    }

    public function test_dos_altas_sin_correo_no_chocan_entre_si(): void
    {
        $this->postJson('/api/users', array_diff_key($this->alta(), ['email' => null]), $this->admin())
            ->assertStatus(201);
        $this->postJson('/api/users', array_diff_key($this->alta(['document' => '1020304050']), ['email' => null]), $this->admin())
            ->assertStatus(201);

        $this->assertSame(2, User::where('email', 'like', '%@ironbody.local')->count());
    }

    // ── Documento ───────────────────────────────────────────────────────────

    public function test_un_documento_ya_registrado_devuelve_422_con_su_campo(): void
    {
        $this->existente('1003812287', 'otro@correo.test');

        $this->postJson('/api/users', $this->alta(), $this->admin())
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ya existe un miembro registrado con ese documento.')
            ->assertJsonPath('errors.document.0', 'Ya existe un miembro registrado con ese documento.');
    }

    /**
     * Se comprueba por COMPORTAMIENTO y no leyendo el catálogo de índices: el
     * catálogo cambia con el motor —aquí los tests corren en SQLite y producción
     * en PostgreSQL— y lo que importa no es cómo se llama la restricción, sino
     * que la base rechace el segundo documento igual.
     */
    public function test_la_base_rechaza_un_documento_repetido_aunque_se_salte_el_controlador(): void
    {
        $this->existente('1003812287', 'ocupado@correo.test');

        $this->expectException(QueryException::class);

        DB::table('users')->insert([
            'name' => 'Otro',
            'email' => 'otro-'.uniqid().'@correo.test',
            'password' => bcrypt('secret-password'),
            'document' => '1003812287',
            'phone' => '3000000000',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ── La carrera: lo que la comprobación previa no puede evitar ────────────

    /**
     * LA CARRERA DE VERDAD, no la que atrapa la comprobación previa.
     *
     * Se escribe el correo conflictivo JUSTO ANTES del insert real, ya dentro de
     * la transacción: es exactamente lo que hace otra petición que se coló entre
     * el `exists()` y el `INSERT`. Así el choque llega al índice único y se
     * comprueba que el traductor lo convierte en 422 y no en un 500.
     */
    public function test_una_carrera_real_contra_el_indice_unico_responde_422(): void
    {
        $correo = 'carrera@correo.test';

        User::creating(function (User $u) use ($correo): void {
            if ($u->email !== $correo) return;
            // El otro cajero se adelanta por un milisegundo.
            DB::table('users')->insert([
                'name' => 'El que llegó primero',
                'email' => $correo,
                'password' => bcrypt('secret-password'),
                'document' => '9999999999',
                'phone' => '3000000000',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $r = $this->postJson('/api/users', $this->alta(['email' => $correo]), $this->admin());

        $r->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Este correo ya está registrado.');
        $this->assertSame(0, User::where('document', '1003812287')->count(), 'la transacción revirtió');
    }

    /**
     * Una violación de unicidad de OTRA restricción no se disfraza de correo
     * repetido.
     *
     * Se construye con el mismo SQLSTATE que usa PostgreSQL para un duplicado
     * —23505— pero nombrando una restricción que no está en el mapa. Si el
     * traductor tradujera por el código y no por el nombre, este caso saldría
     * como «Este correo ya está registrado» y escondería el fallo de verdad.
     */
    public function test_una_restriccion_desconocida_no_se_disfraza_de_correo_duplicado(): void
    {
        User::creating(function (User $u): void {
            $pdo = new \PDOException('SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "otra_cosa_unique"');
            $pdo->errorInfo = ['23505', 7, 'duplicate key value violates unique constraint "otra_cosa_unique"'];

            throw new QueryException('pgsql', 'insert into "algo"', [], $pdo);
        });

        $r = $this->postJson('/api/users', $this->alta(), $this->admin());

        $this->assertNotSame(422, $r->getStatusCode(), 'un fallo real no puede salir como dato duplicado');
        $this->assertStringNotContainsString('correo ya está registrado', (string) $r->getContent());
    }

    /** Y un error que ni siquiera es de unicidad tampoco. */
    public function test_un_error_cualquiera_de_base_de_datos_sigue_siendo_un_error(): void
    {
        User::creating(function (User $u): void {
            $pdo = new \PDOException('SQLSTATE[42703]: Undefined column');
            $pdo->errorInfo = ['42703', 7, 'column "no_existe" does not exist'];

            throw new QueryException('pgsql', 'insert into "algo"', [], $pdo);
        });

        $r = $this->postJson('/api/users', $this->alta(), $this->admin());

        $this->assertNotSame(422, $r->getStatusCode());
    }

    public function test_un_duplicado_no_deja_nada_a_medias(): void
    {
        $this->existente('1077852899', 'eduardguzman166@gmail.com');
        $usuariosAntes = User::count();
        $sociosAntes = Member::count();

        $this->postJson('/api/users', $this->alta(['email' => 'eduardguzman166@gmail.com']), $this->admin())
            ->assertStatus(422);

        $this->assertSame($usuariosAntes, User::count(), 'ningún usuario a medias');
        $this->assertSame($sociosAntes, Member::count(), 'ninguna ficha de socio huérfana');
        $this->assertSame(0, User::where('document', '1003812287')->count());
    }

    public function test_el_socio_creado_sigue_pudiendo_entrar_por_su_documento(): void
    {
        $this->postJson('/api/users', $this->alta(), $this->admin())->assertStatus(201);

        // El documento es la llave de acceso: tiene que quedar en las dos tablas.
        $this->assertSame(1, Member::where('document_number', '1003812287')->count());
        $this->assertSame(1, User::where('document', '1003812287')->count());
    }
}
