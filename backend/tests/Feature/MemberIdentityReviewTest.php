<?php

namespace Tests\Feature;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * El estado "Revisión manual" solo tiene sentido si el gimnasio puede MIRAR las
 * capturas. Las imágenes ya llegaban al disco privado y había estado, pero no
 * existía ninguna forma de consultarlas: la promesa no tenía workflow detrás.
 */
class MemberIdentityReviewTest extends TestCase
{
    use RefreshDatabase;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->member = Member::create([
            'full_name' => 'Socio Revisión',
            'document_number' => '550550550',
            'phone' => '+573005505505',
            'status' => Member::STATUS_INCOMPLETE,
        ]);
    }

    private function withDocument(): void
    {
        $this->member->identityDocument()->create([
            'document_number' => '550550550',
            'identity_status' => 'needs_manual_review',
            'ocr_confidence' => 0.3,
            'front_path' => UploadedFile::fake()->create('front.jpg', 12, 'image/jpeg')
                ->store("members/{$this->member->member_uuid}/identity/front", 'local'),
            'front_mime' => 'image/jpeg',
            'back_path' => UploadedFile::fake()->create('back.jpg', 12, 'image/jpeg')
                ->store("members/{$this->member->member_uuid}/identity/back", 'local'),
            'back_mime' => 'image/jpeg',
        ]);
    }

    public function test_el_crm_ve_el_estado_de_la_documentacion(): void
    {
        $this->withDocument();

        $this->getJson("/api/admin/members/{$this->member->id}/identity", $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('data.has_document', true)
            ->assertJsonPath('data.identity_status', 'needs_manual_review')
            ->assertJsonPath('data.front_available', true)
            ->assertJsonPath('data.back_available', true);
    }

    public function test_no_expone_la_ruta_interna_del_archivo(): void
    {
        $this->withDocument();

        $body = $this->getJson("/api/admin/members/{$this->member->id}/identity", $this->adminHeaders())
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('front_path', $body);
        $this->assertStringNotContainsString('members/', $body);
    }

    public function test_el_crm_puede_ver_las_imagenes(): void
    {
        $this->withDocument();

        $this->get("/api/admin/members/{$this->member->id}/identity/front", $this->adminHeaders())
            ->assertOk()->assertHeader('content-type', 'image/jpeg');

        $this->get("/api/admin/members/{$this->member->id}/identity/back", $this->adminHeaders())
            ->assertOk();
    }

    public function test_las_imagenes_no_son_publicas(): void
    {
        $this->withDocument();

        // Sin sesión admin no se sirve documentación de identidad.
        $this->getJson("/api/admin/members/{$this->member->id}/identity/front")
            ->assertStatus(401);
        $this->getJson("/api/admin/members/{$this->member->id}/identity")
            ->assertStatus(401);
    }

    public function test_una_cara_invalida_se_rechaza(): void
    {
        $this->withDocument();

        $this->getJson("/api/admin/members/{$this->member->id}/identity/selfie", $this->adminHeaders())
            ->assertStatus(422);
    }

    public function test_sin_documento_lo_dice_en_vez_de_fallar(): void
    {
        $this->getJson("/api/admin/members/{$this->member->id}/identity", $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('data.has_document', false);

        $this->getJson("/api/admin/members/{$this->member->id}/identity/front", $this->adminHeaders())
            ->assertStatus(404);
    }
}
