<?php

namespace Tests\Feature\Moderation;

use App\Models\Member;
use App\Models\MemberUgcConsent;
use App\Models\ModerationAuditLog;

/**
 * Lineamientos de Comunidad: la puerta que hay que cruzar para publicar.
 *
 * Durante semanas el requisito estuvo activo sin que existiera forma de
 * aceptarlo desde la app, y 3.738 de 3.739 socios quedaron bloqueados. Estas
 * pruebas fijan las dos mitades del contrato: que la puerta sigue cerrada para
 * quien no ha aceptado, y que aceptar es posible, idempotente y sólo en nombre
 * propio.
 */
class CommunityGuidelinesTest extends ModerationTestCase
{
    /**
     * Publicar un estado por la vía que usa la app: el medio va directo a
     * Firebase Storage y aquí sólo se registra.
     */
    private function publish(Member $member): \Illuminate\Testing\TestResponse
    {
        $path = 'IRONBODYSTORIES/'.$member->id.'/'.uniqid().'/story_media.jpg';

        return $this->postJson('/api/app/stories/firebase', [
            'type' => 'image',
            'firebase_path' => $path,
            'download_url' => $this->storageUrl($path),
            'caption' => 'Entrenando',
        ], $this->asMember($member));
    }

    // ── La puerta ─────────────────────────────────────────────────────────

    public function test_sin_aceptar_no_se_puede_publicar(): void
    {
        $member = $this->makeMember('Sin Consentimiento');

        $this->publish($member)
            ->assertStatus(403)
            ->assertJsonPath('code', 'guidelines_acceptance_required')
            ->assertJsonPath('data.version', (string) config('ugc.guidelines_version'));
    }

    public function test_tras_aceptar_se_puede_publicar(): void
    {
        $member = $this->makeMember('Con Consentimiento');
        $this->acceptGuidelines($member);

        $this->publish($member)->assertSuccessful();
    }

    public function test_la_guarda_tambien_cubre_la_subida_multipart(): void
    {
        // Son dos puertas al mismo sitio; cerrar sólo una no cierra nada. Y la
        // guarda tiene que actuar ANTES de validar el fichero: si no, un 422
        // taparía el 403 y no sabríamos que el socio está bloqueado.
        $member = $this->makeMember('Por multipart');

        $this->postJson('/api/app/stories', [
            'caption' => 'Entrenando',
        ], $this->asMember($member))
            ->assertStatus(403)
            ->assertJsonPath('code', 'guidelines_acceptance_required');
    }

    // ── Aceptar ───────────────────────────────────────────────────────────

    public function test_aceptar_crea_el_consentimiento_y_deja_auditoria(): void
    {
        $member = $this->makeMember();

        $this->postJson('/api/app/moderation/guidelines/accept', [
            'platform' => 'ios',
            'app_version' => '1.0.2',
        ], $this->asMember($member))
            ->assertOk()
            ->assertJsonPath('data.version', (string) config('ugc.guidelines_version'));

        $this->assertDatabaseHas('member_ugc_consents', [
            'member_id' => $member->id,
            'community_guidelines_version' => (string) config('ugc.guidelines_version'),
            'platform' => 'ios',
        ]);

        // Un consentimiento sin rastro de quién y cuándo no sirve de prueba.
        $this->assertDatabaseHas('moderation_audit_logs', [
            'action' => ModerationAuditLog::ACTION_GUIDELINES_ACCEPTED,
            'entity_type' => 'member_ugc_consent',
        ]);
    }

    public function test_aceptar_dos_veces_no_duplica(): void
    {
        // Doble toque en el botón, o un reintento tras una red lenta.
        $member = $this->makeMember();
        $headers = $this->asMember($member);

        $this->postJson('/api/app/moderation/guidelines/accept', [], $headers)->assertOk();
        $this->postJson('/api/app/moderation/guidelines/accept', [], $headers)->assertOk();

        $this->assertSame(1, MemberUgcConsent::where('member_id', $member->id)->count());
        $this->assertSame(1, ModerationAuditLog::where('action', ModerationAuditLog::ACTION_GUIDELINES_ACCEPTED)->count());
    }

    public function test_no_se_acepta_una_version_que_no_es_la_vigente(): void
    {
        // Aceptar "2.0" por adelantado saltaría el siguiente cambio de normas.
        $member = $this->makeMember();

        $this->postJson('/api/app/moderation/guidelines/accept',
            ['version' => '99.0'], $this->asMember($member))
            ->assertStatus(409)
            ->assertJsonPath('code', 'version_mismatch');

        $this->assertSame(0, MemberUgcConsent::count());
    }

    public function test_sin_autenticar_no_se_acepta_nada(): void
    {
        $this->postJson('/api/app/moderation/guidelines/accept', [])->assertStatus(401);
        $this->getJson('/api/app/moderation/guidelines')->assertStatus(401);
    }

    // ── Nadie acepta en nombre de otro ────────────────────────────────────

    public function test_un_socio_no_puede_aceptar_por_otro(): void
    {
        // El actor sale SIEMPRE del bearer. Se intenta colar el id ajeno por
        // las tres vías por las que un cliente podría mandarlo.
        $atacante = $this->makeMember('Atacante');
        $victima = $this->makeMember('Victima');

        foreach ([
            ['member_id' => $victima->id],
            ['memberId' => $victima->id],
            ['member' => $victima->id],
        ] as $payload) {
            $this->postJson('/api/app/moderation/guidelines/accept',
                $payload, $this->asMember($atacante))->assertOk();
        }

        $this->postJson('/api/app/moderation/guidelines/accept?member_id='.$victima->id,
            [], $this->asMember($atacante))->assertOk();

        $this->assertSame(0, MemberUgcConsent::where('member_id', $victima->id)->count(),
            'la víctima NO puede terminar con un consentimiento que nunca dio');
        $this->assertSame(1, MemberUgcConsent::where('member_id', $atacante->id)->count());

        // Y la puerta de la víctima sigue cerrada.
        $this->publish($victima)->assertStatus(403);
    }

    // ── Versionado ────────────────────────────────────────────────────────

    public function test_al_subir_la_version_se_vuelve_a_exigir(): void
    {
        $member = $this->makeMember();
        $this->acceptGuidelines($member);
        $this->publish($member)->assertSuccessful();

        config(['ugc.guidelines_version' => '1.1']);

        $this->publish($member)
            ->assertStatus(403)
            ->assertJsonPath('code', 'guidelines_acceptance_required')
            ->assertJsonPath('data.version', '1.1');

        // Y la aceptación anterior se conserva: es prueba de lo que aceptó
        // entonces, no se pisa.
        $this->postJson('/api/app/moderation/guidelines/accept', [], $this->asMember($member))->assertOk();
        $this->assertSame(2, MemberUgcConsent::where('member_id', $member->id)->count());
    }

    // ── El documento ──────────────────────────────────────────────────────

    public function test_el_documento_se_sirve_completo(): void
    {
        $member = $this->makeMember();

        $res = $this->getJson('/api/app/moderation/guidelines', $this->asMember($member))
            ->assertOk()
            ->assertJsonPath('data.version', (string) config('ugc.guidelines_version'))
            ->assertJsonPath('data.accepted', false)
            ->assertJsonStructure(['data' => [
                'version', 'effective_date', 'title', 'subtitle', 'intro',
                'sections' => [['icon', 'title', 'summary', 'body']],
                'full_text', 'required_to_post', 'accepted',
            ]]);

        // El texto tiene que venir de verdad, no un placeholder.
        $this->assertGreaterThan(1500, strlen($res->json('data.full_text')));
        $this->assertCount(5, $res->json('data.sections'));
    }

    public function test_el_documento_refleja_si_ya_se_acepto(): void
    {
        $member = $this->makeMember();
        $this->acceptGuidelines($member);

        $this->getJson('/api/app/moderation/guidelines', $this->asMember($member))
            ->assertOk()
            ->assertJsonPath('data.accepted', true);
    }

    public function test_el_documento_en_disco_coincide_con_la_version_configurada(): void
    {
        // Si alguien sube UGC_GUIDELINES_VERSION sin tocar el texto, el socio
        // aceptaría una versión distinta de la que está leyendo.
        $doc = require resource_path('legal/community_guidelines.php');

        $this->assertSame((string) config('ugc.guidelines_version'), $doc['version']);
    }

    // ── El requisito se puede desactivar, pero no por accidente ───────────

    public function test_si_el_requisito_esta_apagado_no_bloquea(): void
    {
        config(['ugc.guidelines_required_to_post' => false]);

        $this->publish($this->makeMember('Sin requisito'))->assertSuccessful();
    }
}
