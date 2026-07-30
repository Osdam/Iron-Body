<?php

namespace Tests\Feature\Moderation;

use App\Models\ContentReport;
use App\Support\Moderation\ReportReason;
use App\Support\Moderation\ReportStatus;

/**
 * Denunciar a una PERSONA (no a una publicación).
 *
 * Por qué existe esta superficie: la política de contenido generado por
 * usuarios de Google Play exige poder reportar usuarios además de contenido, y
 * había un hueco funcional real — a alguien sin estados activos no se le podía
 * denunciar por ninguna vía, porque el único acceso era el visor de estados.
 */
class MemberReportTest extends ModerationTestCase
{
    private const URL = '/api/app/members/%d/report';

    public function test_reportar_a_una_persona_crea_el_caso(): void
    {
        $reporter = $this->makeMember('Denunciante');
        $target = $this->makeMember('Denunciado');

        $this->postJson(sprintf(self::URL, $target->id), [
            'reason_code' => ReportReason::HARASSMENT,
            'reason_detail' => 'Me escribe mensajes ofensivos.',
        ], $this->asMember($reporter))
            ->assertCreated()
            ->assertJsonPath('data.created', true)
            ->assertJsonPath('data.status', ReportStatus::SUBMITTED);

        $this->assertDatabaseHas('content_reports', [
            'reporter_member_id' => $reporter->id,
            'reported_member_id' => $target->id,
            'content_type' => ContentReport::CONTENT_TYPE_MEMBER,
            'content_id' => $target->id,
            'reason_code' => ReportReason::HARASSMENT,
            'status' => ReportStatus::SUBMITTED,
        ]);
    }

    /** Reportar dos veces no multiplica los casos abiertos. */
    public function test_reportar_dos_veces_es_idempotente(): void
    {
        $reporter = $this->makeMember('Denunciante');
        $target = $this->makeMember('Denunciado');

        $payload = ['reason_code' => ReportReason::SPAM];

        $this->postJson(sprintf(self::URL, $target->id), $payload, $this->asMember($reporter))
            ->assertCreated()
            ->assertJsonPath('data.created', true);

        $this->postJson(sprintf(self::URL, $target->id), $payload, $this->asMember($reporter))
            ->assertOk()
            ->assertJsonPath('data.created', false);

        $this->assertSame(1, ContentReport::query()
            ->where('reporter_member_id', $reporter->id)
            ->where('content_type', ContentReport::CONTENT_TYPE_MEMBER)
            ->count());
    }

    public function test_no_puede_reportarse_a_si_mismo(): void
    {
        $member = $this->makeMember('Solitario');

        $this->postJson(sprintf(self::URL, $member->id), [
            'reason_code' => ReportReason::SPAM,
        ], $this->asMember($member))
            ->assertStatus(422)
            ->assertJsonPath('code', 'cannot_report_own_content');

        $this->assertDatabaseCount('content_reports', 0);
    }

    /** Un id inventado no puede crear un caso fantasma en la bandeja. */
    public function test_reportar_a_un_miembro_inexistente_devuelve_404(): void
    {
        $reporter = $this->makeMember('Denunciante');

        $this->postJson(sprintf(self::URL, 999999), [
            'reason_code' => ReportReason::SPAM,
        ], $this->asMember($reporter))
            ->assertStatus(404)
            ->assertJsonPath('code', 'member_not_found');

        $this->assertDatabaseCount('content_reports', 0);
    }

    public function test_motivo_fuera_del_catalogo_es_rechazado(): void
    {
        $reporter = $this->makeMember('Denunciante');
        $target = $this->makeMember('Denunciado');

        $this->postJson(sprintf(self::URL, $target->id), [
            'reason_code' => 'no_me_cae_bien',
        ], $this->asMember($reporter))
            ->assertStatus(422);

        $this->assertDatabaseCount('content_reports', 0);
    }

    /** El reportante nunca sale en la respuesta ni puede fijarse desde el body. */
    public function test_el_reportante_no_se_puede_suplantar_ni_se_expone(): void
    {
        $reporter = $this->makeMember('Denunciante');
        $target = $this->makeMember('Denunciado');
        $tercero = $this->makeMember('Ajeno');

        $response = $this->postJson(sprintf(self::URL, $target->id), [
            'reason_code' => ReportReason::SPAM,
            // Intento de suplantación: debe ignorarse por completo.
            'reporter_member_id' => $tercero->id,
            'severity' => 'critical',
            'status' => ReportStatus::ACTIONED,
        ], $this->asMember($reporter))->assertCreated();

        $report = ContentReport::query()->firstOrFail();

        $this->assertSame((int) $reporter->id, (int) $report->reporter_member_id);
        $this->assertSame(ReportStatus::SUBMITTED, $report->status);
        $this->assertSame(ReportReason::severityFor(ReportReason::SPAM), $report->severity);

        // La respuesta no expone al reportante por ninguna clave, y el
        // identificador que devuelve es el UUID público, no el id secuencial.
        $body = (string) $response->getContent();
        foreach (['reporter_member_id', 'reporter', 'reported_member_id'] as $prohibida) {
            $this->assertStringNotContainsString($prohibida, $body);
        }

        $this->assertSame($report->public_id, $response->json('data.report_id'));
        $this->assertNotSame((string) $report->id, (string) $response->json('data.report_id'));
    }

    public function test_requiere_autenticacion(): void
    {
        $target = $this->makeMember('Denunciado');

        $this->postJson(sprintf(self::URL, $target->id), [
            'reason_code' => ReportReason::SPAM,
        ])->assertStatus(401);
    }

    /** Con los reportes apagados por configuración, la vía queda cerrada. */
    public function test_respeta_el_interruptor_de_reportes(): void
    {
        config(['ugc.reports_enabled' => false]);

        $reporter = $this->makeMember('Denunciante');
        $target = $this->makeMember('Denunciado');

        $this->postJson(sprintf(self::URL, $target->id), [
            'reason_code' => ReportReason::SPAM,
        ], $this->asMember($reporter))
            ->assertStatus(503)
            ->assertJsonPath('code', 'reports_disabled');
    }

    /** El límite por hora del reportante también cubre esta vía. */
    public function test_aplica_el_limite_por_hora(): void
    {
        config(['ugc.report_rate_limit_per_hour' => 2]);

        $reporter = $this->makeMember('Denunciante');

        foreach (range(1, 2) as $i) {
            $target = $this->makeMember("Denunciado {$i}");
            $this->postJson(sprintf(self::URL, $target->id), [
                'reason_code' => ReportReason::SPAM,
            ], $this->asMember($reporter))->assertCreated();
        }

        $ultimo = $this->makeMember('Uno más');
        $this->postJson(sprintf(self::URL, $ultimo->id), [
            'reason_code' => ReportReason::SPAM,
        ], $this->asMember($reporter))
            ->assertStatus(429)
            ->assertJsonPath('code', 'rate_limited');
    }
}
