<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El documento es la llave de acceso del miembro y es único en `members`.
 * `POST /api/users` ya lo comprobaba; `PATCH /api/users/{user}` no, así que un
 * documento repetido se escribía en `users.document` (sin índice único) y
 * reventaba al replicarlo en `members.document_number`: 500 para el CRM y las
 * dos tablas desincronizadas.
 */
class CrmMemberDocumentUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private function makeMember(string $name, string $document, string $email): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('secret-password'),
            'document' => $document,
            'phone' => '3001112233',
            'status' => 'active',
        ]);

        Member::create([
            'user_id' => $user->id,
            'full_name' => $name,
            'document_number' => $document,
            'phone' => '3001112233',
            'status' => Member::STATUS_ACTIVE,
        ]);

        return $user;
    }

    public function test_editar_un_miembro_con_el_documento_de_otro_devuelve_422(): void
    {
        $this->makeMember('Ana', '111111', 'ana@example.test');
        $bruno = $this->makeMember('Bruno', '222222', 'bruno@example.test');

        $this->patchJson("/api/users/{$bruno->id}", ['document' => '111111'], $this->adminHeaders())
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ya existe un miembro registrado con ese documento.');

        // Ni el User ni el Member de Bruno cambiaron: nada quedó a medias.
        $this->assertSame('222222', $bruno->fresh()->document);
        $this->assertSame('222222', Member::where('user_id', $bruno->id)->value('document_number'));
    }

    public function test_guardar_el_mismo_miembro_sin_cambiar_documento_sigue_funcionando(): void
    {
        $ana = $this->makeMember('Ana', '111111', 'ana@example.test');

        $this->patchJson("/api/users/{$ana->id}", [
            'name' => 'Ana María',
            'document' => '111111',
        ], $this->adminHeaders())->assertOk();

        $this->assertSame('Ana María', $ana->fresh()->name);
        $this->assertSame('111111', Member::where('user_id', $ana->id)->value('document_number'));
    }

    public function test_el_documento_se_guarda_normalizado_en_las_dos_tablas(): void
    {
        $ana = $this->makeMember('Ana', '111111', 'ana@example.test');

        // El CRM permite escribir el documento con puntos: el login por documento
        // lo normaliza, así que ambas tablas deben guardarlo ya normalizado.
        $this->patchJson("/api/users/{$ana->id}", ['document' => '1.234.567'], $this->adminHeaders())
            ->assertOk();

        $this->assertSame('1234567', $ana->fresh()->document);
        $this->assertSame('1234567', Member::where('user_id', $ana->id)->value('document_number'));
    }

    public function test_un_documento_vacio_tras_normalizar_se_rechaza(): void
    {
        $ana = $this->makeMember('Ana', '111111', 'ana@example.test');

        $this->patchJson("/api/users/{$ana->id}", ['document' => '...'], $this->adminHeaders())
            ->assertStatus(422)
            ->assertJsonPath('message', 'El documento no es válido.');

        $this->assertSame('111111', $ana->fresh()->document);
    }
}
