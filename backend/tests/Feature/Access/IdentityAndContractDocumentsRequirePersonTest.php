<?php

namespace Tests\Feature\Access;

use App\Models\ContractTemplate;
use App\Models\Member;
use App\Models\MemberContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * LA CÉDULA Y EL CONTRATO LOS LEE UNA PERSONA.
 *
 * Son lecturas, así que la puerta las resolvía a un permiso de lectura que el
 * token compartido de automatizaciones obtiene: con él se descargaban las
 * dos caras del documento de identidad de cualquier socio y el PDF de su
 * contrato (con los datos médicos que declaró al firmar). Ningún workflow de
 * n8n del repositorio llama a estas rutas, y el CRM entra siempre con la
 * sesión de una persona.
 */
class IdentityAndContractDocumentsRequirePersonTest extends TestCase
{
    use RefreshDatabase;

    private Member $member;

    private MemberContract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['contracts.disk' => 'local']);

        $this->member = Member::create([
            'full_name' => 'Socia Documentos', 'document_number' => '550550551',
            'phone' => '3005505505', 'status' => Member::STATUS_ACTIVE,
        ]);
        $this->member->identityDocument()->create([
            'document_number' => '550550551', 'identity_status' => 'verified',
            'front_path' => UploadedFile::fake()->create('front.jpg', 12, 'image/jpeg')
                ->store("members/{$this->member->member_uuid}/identity/front", 'local'),
            'front_mime' => 'image/jpeg',
            'back_path' => UploadedFile::fake()->create('back.jpg', 12, 'image/jpeg')
                ->store("members/{$this->member->member_uuid}/identity/back", 'local'),
            'back_mime' => 'image/jpeg',
        ]);

        Storage::disk('local')->put('contracts/signed/contrato.pdf', '%PDF-1.4 contenido-del-contrato');
        $plantilla = ContractTemplate::firstOrCreate(
            ['template_key' => 'workout_registration'],
            ['name' => 'Registro', 'version' => '1', 'source_file_path' => 'contracts/workout.pdf'],
        );
        $this->contract = MemberContract::create([
            'contract_uuid' => (string) Str::uuid(), 'member_id' => $this->member->id,
            'contract_template_id' => $plantilla->id, 'contract_type' => 'workout_registration',
            'status' => MemberContract::STATUS_SIGNED, 'signed_at' => now(),
            'signed_pdf_path' => 'contracts/signed/contrato.pdf',
            'medical_snapshot' => ['conditions' => 'asma-declarada'],
        ]);
    }

    /** @return array<string, string> */
    private function lecturas(): array
    {
        return [
            'estado de la cédula' => "/api/admin/members/{$this->member->id}/identity",
            'cara frontal' => "/api/admin/members/{$this->member->id}/identity/front",
            'cara posterior' => "/api/admin/members/{$this->member->id}/identity/back",
            'detalle del contrato' => "/api/admin/contracts/{$this->contract->contract_uuid}",
            'pdf del contrato' => "/api/admin/contracts/{$this->contract->contract_uuid}/download",
        ];
    }

    public function test_el_token_compartido_no_lee_cedulas_ni_contratos(): void
    {
        $vistos = [];
        foreach ($this->lecturas() as $que => $uri) {
            $res = $this->getJson($uri, $this->sharedTokenHeaders());
            $flujo = $res->baseResponse instanceof StreamedResponse;
            $contenido = $flujo ? $res->streamedContent() : (string) $res->getContent();
            $vistos[$que] = [
                $res->getStatusCode(),
                $flujo ? null : $res->json('code'),
                str_contains($contenido, 'contenido-del-contrato') || str_contains($contenido, 'asma-declarada'),
            ];
        }

        $this->assertSame(
            array_fill_keys(array_keys($this->lecturas()), [403, 'person_required', false]),
            $vistos,
        );
    }

    public function test_una_persona_del_crm_los_sigue_leyendo(): void
    {
        foreach ($this->lecturas() as $que => $uri) {
            $this->get($uri, $this->adminHeaders(['Accept' => 'application/json']))->assertOk();
        }
    }

    public function test_el_listado_de_contratos_no_cambia_para_el_token_compartido(): void
    {
        // Solo metadatos (folio, estado, fechas): queda como estaba.
        $this->getJson("/api/admin/members/{$this->member->id}/contracts", $this->sharedTokenHeaders())
            ->assertOk()
            ->assertJsonPath('data.0.uuid', $this->contract->contract_uuid);
    }
}
