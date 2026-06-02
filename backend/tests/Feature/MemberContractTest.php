<?php

namespace Tests\Feature;

use App\Models\ContractAuditLog;
use App\Models\Member;
use App\Models\MemberContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MemberContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Disco privado falso (temporal, auto-limpiado) sembrado con las
        // plantillas oficiales reales → NO toca el storage real ni Postgres.
        Storage::fake('local');
        $real = base_path('storage/app/private/contract_templates/source');
        foreach (['basic_registration', 'workout_registration', 'minor_release'] as $key) {
            Storage::disk('local')->put(
                "contract_templates/source/{$key}.pdf",
                file_get_contents("{$real}/{$key}.pdf")
            );
        }
        Artisan::call('contracts:install-templates');
    }

    private function makeMember(array $attrs = []): Member
    {
        return Member::create(array_merge([
            'full_name'       => 'Juan Pérez',
            'email'           => 'juan@example.com',
            'document_number' => (string) random_int(10000000, 99999999),
            'phone'           => '3001234567',
            'birth_date'      => '1995-05-10',
            'is_minor'        => false,
            'status'          => Member::STATUS_ACTIVE,
        ], $attrs));
    }

    private function signaturePngBase64(): string
    {
        // PNG RGB (sin alpha) real → TCPDF lo incrusta sin depender de GD.
        $bytes = file_get_contents(base_path('tests/fixtures/signature.png'));

        return 'data:image/png;base64,'.base64_encode($bytes);
    }

    private function auth(Member $m): array
    {
        return ['Authorization' => 'Bearer '.$m->access_hash];
    }

    public function test_adult_flow_draft_sign_download(): void
    {
        $member = $this->makeMember();

        // status: requiere contrato
        $status = $this->getJson('/api/member/contracts/status', $this->auth($member));
        $status->assertOk()
            ->assertJsonPath('requires_contract', true)
            ->assertJsonPath('recommended_contract_type', 'workout_registration');

        // draft
        $draft = $this->postJson('/api/member/contracts/draft', [], $this->auth($member));
        $draft->assertCreated();
        $uuid = $draft->json('data.uuid');
        $this->assertNotEmpty($draft->json('data.folio'));

        // sign
        $payload = [
            'signature_image' => $this->signaturePngBase64(),
            'rh'              => 'O+',
            'address'         => 'Calle 1 # 2-3',
            'medical_notes'   => 'Ninguna relevante.',
            'injuries'        => 'Esguince de tobillo en 2024.',
            'acceptance'      => [
                'truthfulness' => true, 'terms_and_conditions' => true, 'data_processing' => true,
                'health_data' => true, 'inform_injuries' => true, 'commercial_policies' => true,
                'image_use' => false, // opcional → false permitido
            ],
            'app_platform' => 'android', 'app_version' => '1.0.0', 'device_id' => 'dev-123',
        ];
        $res = $this->postJson("/api/member/contracts/{$uuid}/sign", $payload, $this->auth($member));
        $res->assertOk()->assertJsonPath('data.status', 'signed');

        $contract = MemberContract::where('contract_uuid', $uuid)->first();
        $this->assertSame('signed', $contract->status);
        $this->assertNotNull($contract->signed_pdf_path);
        $this->assertTrue(Storage::disk('local')->exists($contract->signed_pdf_path));
        $this->assertTrue(Storage::disk('local')->exists($contract->signature_path));

        // checksum coincide con el archivo
        $bytes = Storage::disk('local')->get($contract->signed_pdf_path);
        $this->assertSame(hash('sha256', $bytes), $contract->signed_pdf_checksum);
        $this->assertSame('%PDF', substr($bytes, 0, 4));

        // snapshots e image_use=false reflejado
        $imageCb = collect($contract->acceptance_snapshot)->firstWhere('key', 'image_use');
        $this->assertFalse($imageCb['value']);

        // auditoría
        foreach (['created', 'accepted', 'signed', 'pdf_generated'] as $action) {
            $this->assertDatabaseHas('contract_audit_logs', [
                'member_contract_id' => $contract->id, 'action' => $action,
            ]);
        }

        // consent legacy sincronizado
        $this->assertDatabaseHas('member_legal_consents', [
            'member_id' => $member->id, 'terms_and_conditions' => true, 'data_processing' => true,
        ]);

        // download
        $dl = $this->get("/api/member/contracts/{$uuid}/download", $this->auth($member));
        $dl->assertOk();
        $this->assertSame('application/pdf', $dl->headers->get('content-type'));
        $this->assertDatabaseHas('contract_audit_logs', [
            'member_contract_id' => $contract->id, 'action' => 'downloaded',
        ]);

        // status ahora no requiere contrato
        $this->getJson('/api/member/contracts/status', $this->auth($member))
            ->assertJsonPath('requires_contract', false);
    }

    public function test_empty_signature_rejected(): void
    {
        $member = $this->makeMember();
        $uuid = $this->postJson('/api/member/contracts/draft', [], $this->auth($member))->json('data.uuid');

        $this->postJson("/api/member/contracts/{$uuid}/sign", [
            'acceptance' => ['truthfulness' => true, 'terms_and_conditions' => true, 'data_processing' => true,
                'health_data' => true, 'inform_injuries' => true, 'commercial_policies' => true],
        ], $this->auth($member))->assertStatus(422);
    }

    public function test_required_checkbox_missing_rejected(): void
    {
        $member = $this->makeMember();
        $uuid = $this->postJson('/api/member/contracts/draft', [], $this->auth($member))->json('data.uuid');

        $this->postJson("/api/member/contracts/{$uuid}/sign", [
            'signature_image' => $this->signaturePngBase64(),
            'acceptance' => ['truthfulness' => true], // faltan obligatorios
        ], $this->auth($member))->assertStatus(422);
    }

    public function test_member_cannot_access_other_contract(): void
    {
        $a = $this->makeMember();
        $b = $this->makeMember(['document_number' => '88887777']);
        $uuid = $this->postJson('/api/member/contracts/draft', [], $this->auth($a))->json('data.uuid');

        $this->getJson("/api/member/contracts/{$uuid}/preview", $this->auth($b))->assertNotFound();
        $this->get("/api/member/contracts/{$uuid}/download", $this->auth($b))->assertNotFound();
    }

    public function test_signed_contract_cannot_be_resigned(): void
    {
        $member = $this->makeMember();
        $uuid = $this->postJson('/api/member/contracts/draft', [], $this->auth($member))->json('data.uuid');
        $payload = [
            'signature_image' => $this->signaturePngBase64(),
            'acceptance' => ['truthfulness' => true, 'terms_and_conditions' => true, 'data_processing' => true,
                'health_data' => true, 'inform_injuries' => true, 'commercial_policies' => true],
        ];
        $this->postJson("/api/member/contracts/{$uuid}/sign", $payload, $this->auth($member))->assertOk();
        // segundo intento → bloqueado
        $this->postJson("/api/member/contracts/{$uuid}/sign", $payload, $this->auth($member))->assertStatus(422);
    }

    public function test_minor_release_flow(): void
    {
        $minor = $this->makeMember(['full_name' => 'Niño Pérez', 'is_minor' => true, 'birth_date' => '2014-01-01']);

        $this->getJson('/api/member/contracts/status', $this->auth($minor))
            ->assertJsonPath('recommended_contract_type', 'minor_release')
            ->assertJsonPath('requires_contract', true);

        $uuid = $this->postJson('/api/member/contracts/draft', [], $this->auth($minor))->json('data.uuid');

        $res = $this->postJson("/api/member/contracts/{$uuid}/sign", [
            'signature_image'          => $this->signaturePngBase64(),
            'guardian_full_name'       => 'María Acudiente',
            'guardian_document_number' => '52000111',
            'guardian_document_city'   => 'Neiva',
            'guardian_phone'           => '3009998877',
            'guardian_address'         => 'Cra 10 # 5-20',
            'guardian_city'            => 'Neiva',
            'minor_full_name'          => 'Niño Pérez',
            'minor_document_number'    => '1075999888',
            'acceptance' => [
                'guardian_authorized' => true, 'minor_admission' => true, 'accompaniment' => true,
                'risk_waiver' => true, 'minor_data_processing' => true,
            ],
        ], $this->auth($minor));

        $res->assertOk()->assertJsonPath('data.status', 'signed')
            ->assertJsonPath('data.contract_type', 'minor_release');

        $contract = MemberContract::where('contract_uuid', $uuid)->first();
        $this->assertNotNull($contract->guardian_snapshot['guardian_full_name'] ?? null);
        $this->assertTrue(Storage::disk('local')->exists($contract->signed_pdf_path));
    }

    public function test_admin_can_list_show_download_and_void(): void
    {
        $member = $this->makeMember();
        $uuid = $this->postJson('/api/member/contracts/draft', [], $this->auth($member))->json('data.uuid');
        $this->postJson("/api/member/contracts/{$uuid}/sign", [
            'signature_image' => $this->signaturePngBase64(),
            'acceptance' => ['truthfulness' => true, 'terms_and_conditions' => true, 'data_processing' => true,
                'health_data' => true, 'inform_injuries' => true, 'commercial_policies' => true],
        ], $this->auth($member))->assertOk();

        $this->getJson("/api/admin/members/{$member->id}/contracts")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/admin/contracts/{$uuid}")->assertOk()->assertJsonPath('data.status', 'signed');
        $this->get("/api/admin/contracts/{$uuid}/download")->assertOk();
        $this->postJson("/api/admin/contracts/{$uuid}/void", ['reason' => 'Solicitud del titular'])
            ->assertOk()->assertJsonPath('data.status', 'void');
    }

    public function test_public_consent_template(): void
    {
        // Sin auth: solo textos de checkboxes + URLs (sin datos personales).
        $adult = $this->getJson('/api/contracts/consent-template');
        $adult->assertOk()
            ->assertJsonPath('contract_type', 'workout_registration')
            ->assertJsonPath('is_minor', false);
        $this->assertNotEmpty($adult->json('checkboxes'));

        $this->getJson('/api/contracts/consent-template?is_minor=1')
            ->assertOk()
            ->assertJsonPath('contract_type', 'minor_release')
            ->assertJsonPath('is_minor', true);
    }

    public function test_image_authorized_reflected_in_present(): void
    {
        $member = $this->makeMember();
        $uuid = $this->postJson('/api/member/contracts/draft', [], $this->auth($member))->json('data.uuid');
        $this->postJson("/api/member/contracts/{$uuid}/sign", [
            'signature_image' => $this->signaturePngBase64(),
            'acceptance' => ['truthfulness' => true, 'terms_and_conditions' => true, 'data_processing' => true,
                'health_data' => true, 'inform_injuries' => true, 'commercial_policies' => true, 'image_use' => false],
        ], $this->auth($member))->assertOk()->assertJsonPath('data.image_authorized', false);
    }

    public function test_admin_user_contracts_endpoint(): void
    {
        // Sin miembro vinculado a ese user_id → lista vacía, no error.
        $this->getJson('/api/admin/users/9999/contracts')
            ->assertOk()->assertJsonPath('data', []);
    }

    public function test_missing_template_fails_clearly(): void
    {
        Storage::disk('local')->delete('contract_templates/source/workout_registration.pdf');
        $member = $this->makeMember();

        $this->postJson('/api/member/contracts/draft', [], $this->auth($member))
            ->assertStatus(503);
    }
}
