<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Services\UltronStudio\StudioCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `GET status` y `GET catalog` (contrato §A3 y §A8): sus formas exactas, y que
 * ninguna respuesta del Studio lleva teléfonos, credenciales ni datos del
 * motor (bearer, claves `sk-`, `wamid`, HMAC).
 */
class UltronStudioStatusCatalogTest extends TestCase
{
    use RefreshDatabase;
    use StudioFixtures;

    public function test_status_tiene_la_forma_del_contrato(): void
    {
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');

        $r = $this->studio('GET', 'status', $root)->assertOk();

        // Hito 5 (contrato H5 §A7): el motor lee versiones (la constante está
        // girada) y `engine.publication` dice qué declara la cadena; con las
        // llaves por defecto, el motor no la lee y publicar está desactivado.
        $r->assertJsonPath('ok', true)
            ->assertJsonPath('data.engine', [
                'readsVersions' => true,
                'activeVersion' => null,
                'message' => 'El motor de ULTRON no lee la cadena de publicaciones (su llave está apagada): publicar aquí no cambia nada en el agente.',
                'publication' => ['envScope' => 'off', 'head' => null, 'canary' => null, 'rest' => null, 'drift' => [], 'state' => 'off'],
                // Enmienda M-1 (plan de motores §D): la traza de los prompts, con la llave en `off`.
                'prompts' => [
                    'key' => 'off',
                    'base' => 'n8n-29de5119',
                    'compiler' => 2,
                    'legacyBundleHash' => '3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902',
                    'current' => ['mode' => 'legacy', 'version' => null, 'bundleHash' => null, 'reason' => 'prompts_off'],
                    'lastTurns' => [],
                ],
            ])
            ->assertJsonPath('data.publishing', [
                'available' => false,
                'code' => 'publishing_disabled',
                'message' => 'Publicar está desactivado en el servidor: lo enciende quien administra el sistema.',
            ])
            ->assertJsonPath('data.can', ['view' => true, 'editDraft' => true, 'manageRules' => true, 'approve' => true, 'publish' => true, 'rollback' => true])
            ->assertJsonPath('data.catalog', ['version' => StudioCatalog::VERSION, 'hash' => StudioCatalog::hash()])
            ->assertJsonPath('data.legacyBaseline', ['label' => 'LEGACY_BASELINE_V1', 'rulesImported' => 0]);

        $pasos = $r->json('data.lifecycle');
        $this->assertSame(
            ['DRAFT', 'VALIDATING', 'VALIDATED', 'REVIEW_REQUIRED', 'APPROVED', 'REJECTED', 'CANARY', 'PUBLISHED', 'RETIRED', 'ROLLED_BACK'],
            array_column($pasos, 'status'),
        );
        $this->assertSame(['status' => 'DRAFT', 'label' => 'Creada, sin enviar', 'reachable' => true], $pasos[0]);
        $this->assertSame(['status' => 'VALIDATING', 'label' => 'Validando', 'reachable' => false, 'reason' => 'La validación es inmediata: ninguna versión queda en este estado.'], $pasos[1]);
        foreach ($pasos as $p) {
            $this->assertSame(! $p['reachable'], array_key_exists('reason', $p), $p['status']);
        }
        // Hito 5: los cuatro de la publicación son alcanzables; RETIRED es «Sustituida».
        $this->assertSame(['VALIDATING'], array_column(array_filter($pasos, static fn (array $p): bool => ! $p['reachable']), 'status'));
        $this->assertSame([
            ['status' => 'CANARY', 'label' => 'Canario', 'reachable' => true],
            ['status' => 'PUBLISHED', 'label' => 'Publicada', 'reachable' => true],
            ['status' => 'RETIRED', 'label' => 'Sustituida', 'reachable' => true],
            ['status' => 'ROLLED_BACK', 'label' => 'Revertida', 'reachable' => true],
        ], array_slice($pasos, 6));

        $this->importBaseline();
        $this->studio('GET', 'status', $root)->assertOk()->assertJsonPath('data.legacyBaseline.rulesImported', 12);
    }

    public function test_status_dice_lo_que_puede_cada_uno(): void
    {
        $this->studio('GET', 'status', $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana'))->assertOk()
            ->assertJsonPath('data.can', ['view' => true, 'editDraft' => true, 'manageRules' => true, 'approve' => true, 'publish' => false, 'rollback' => false]);

        $soloVer = $this->adminWithPermissions(['ultron.view']);
        $this->getJson('/api/admin/ultron-studio/status', $soloVer)->assertOk()
            ->assertJsonPath('data.can', ['view' => true, 'editDraft' => false, 'manageRules' => false, 'approve' => false, 'publish' => false, 'rollback' => false]);
    }

    public function test_catalog_es_exactamente_el_del_servidor(): void
    {
        $r = $this->studio('GET', 'catalog', $this->person())->assertOk();

        $this->assertSame(
            json_decode((string) json_encode(StudioCatalog::toArray()), true),
            $r->json('data'),
        );
        // Hito G (contrato G §A2 y §A3): los mandos reales, las lecturas y las condiciones de las reglas del código.
        $this->assertSame(['version', 'hash', 'layers', 'ports', 'edges', 'requiredPaths', 'constraints', 'parameters', 'baseline', 'bindings', 'readings', 'legacyConditions'], array_keys($r->json('data')));
        // Claves opcionales omitidas, nunca `false`.
        foreach ($r->json('data.edges') as $arista) {
            foreach (['feedback', 'opensTurn'] as $opcional) {
                $this->assertNotSame(false, $arista[$opcional] ?? null, "{$arista['id']}.{$opcional}");
            }
        }
    }

    public function test_ninguna_respuesta_lleva_telefonos_credenciales_ni_datos_del_motor(): void
    {
        // Datos del motor en la base: un prospecto con su teléfono. El Studio
        // no tiene por qué tocarlos, y ninguna respuesta puede traerlos.
        $lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026', 'meta_user_id' => '573150536026', 'name' => 'Prospecto Secreto']);
        MarketingConversation::create(['lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open']);

        $this->importBaseline();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Pérez');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $uuid = $this->submittedVersion($ana);
        $this->studio('POST', 'rules', $ana, ['description' => 'Una regla.', 'type' => 'style', 'priority' => 'normal', 'layers' => ['persona'], 'clientRequestId' => (string) Str::uuid()])->assertCreated();

        $cuerpos = '';
        foreach (['status', 'catalog', 'draft', 'versions', "versions/{$uuid}", 'rules', 'audit'] as $ruta) {
            $cuerpos .= $this->studio('GET', $ruta, $root)->assertOk()->getContent();
        }
        $cuerpos .= $this->studio('POST', "versions/{$uuid}/validate", $root)->assertOk()->getContent();
        $cuerpos .= $this->studio('POST', "versions/{$uuid}/publish", $root, ['clientRequestId' => (string) Str::uuid(), 'previewHash' => str_repeat('0', 64), 'acknowledgedNoEffect' => []])->assertStatus(409)->getContent();
        // Hito 5: la vista previa y el historial de la cadena.
        $cuerpos .= $this->studio('GET', "versions/{$uuid}/publication-preview", $root)->assertOk()->getContent();
        $cuerpos .= $this->studio('GET', 'publications', $root)->assertOk()->getContent();

        foreach (['3150536026', '573150536026', 'Prospecto Secreto', 'Bearer', 'sk-', 'wamid', 'HMAC', 'secret-password'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $cuerpos, "Una respuesta del Studio contiene «{$prohibido}».");
        }
        // Ninguna ristra suelta de 10+ dígitos (teléfonos, documentos). Las de
        // dentro de una huella o de un uuid no cuentan: el último bloque de un
        // uuid puede ser todo dígitos (va precedido de un guion).
        $this->assertDoesNotMatchRegularExpression('/(?<![0-9a-f\-])\d{10,}(?![0-9a-f])/', $cuerpos);
        // Y el token de la sesión no vuelve en ninguna respuesta.
        $token = substr($this->as($root)['Authorization'], strlen('Bearer '));
        $this->assertStringNotContainsString($token, $cuerpos);
    }
}
