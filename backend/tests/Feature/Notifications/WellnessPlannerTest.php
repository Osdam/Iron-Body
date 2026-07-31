<?php

namespace Tests\Feature\Notifications;

use App\Models\Attendance;
use App\Models\Member;
use App\Models\MemberNotificationPreference;
use App\Models\NotificationDispatch;
use App\Models\NotificationTemplate;
use App\Services\Notifications\WellnessPlanner;
use App\Support\Notifications\NotificationCategory;
use Carbon\CarbonImmutable;

class WellnessPlannerTest extends NotificationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('notifications:seed-templates')->assertSuccessful();
    }

    private function planner(): WellnessPlanner
    {
        return app(WellnessPlanner::class);
    }

    private function midday(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-07-30 17:00:00', 'UTC');
    }

    public function test_siembra_el_catalogo_completo(): void
    {
        $this->assertGreaterThanOrEqual(30, NotificationTemplate::count());
        $this->assertTrue(NotificationTemplate::query()->where('is_seeded', true)->exists());
    }

    public function test_sembrar_dos_veces_no_duplica(): void
    {
        $antes = NotificationTemplate::count();
        $this->artisan('notifications:seed-templates')->assertSuccessful();

        $this->assertSame($antes, NotificationTemplate::count());
    }

    public function test_sembrar_no_pisa_un_texto_editado_en_el_crm(): void
    {
        $t = NotificationTemplate::query()->firstWhere('key', 'mot_constancia');
        $t->update(['title' => 'Texto corregido a mano']);

        $this->artisan('notifications:seed-templates')->assertSuccessful();

        $this->assertSame('Texto corregido a mano', $t->fresh()->title);
    }

    public function test_envia_una_sola_al_dia_aunque_corra_dos_veces(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);

        $this->planner()->planDaily($this->midday());
        $this->planner()->planDaily($this->midday()->addHours(3));

        $this->assertSame(
            1,
            NotificationDispatch::query()->where('member_id', $member->id)->count(),
            'Dos pasadas el mismo día deben producir un único intento.',
        );
    }

    public function test_no_repite_la_misma_plantilla_al_dia_siguiente(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);

        $this->planner()->planDaily($this->midday());
        $this->planner()->planDaily($this->midday()->addDay());

        $claves = NotificationDispatch::query()
            ->where('member_id', $member->id)
            ->sent()
            ->pluck('template_key')
            ->all();

        $this->assertCount(count(array_unique($claves)), $claves, 'Repitió la misma plantilla.');
    }

    public function test_a_quien_lleva_dias_sin_venir_le_llega_motivacion(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);

        Attendance::create([
            'user_id' => $member->user_id,
            'member_id' => $member->id,
            'action' => 'entry',
            'source' => 'manual',
            'captured_at' => $this->midday()->subDays(9),
        ]);

        $plan = $this->planner()->planFor($member->fresh(), $this->midday());

        $this->assertNotNull($plan);
        $this->assertSame(NotificationCategory::MOTIVATION, $plan['category']);
    }

    public function test_a_quien_entreno_ayer_le_llega_recuperacion(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);

        Attendance::create([
            'user_id' => $member->user_id,
            'member_id' => $member->id,
            'action' => 'entry',
            'source' => 'manual',
            'captured_at' => $this->midday()->subHours(20),
        ]);

        $plan = $this->planner()->planFor($member->fresh(), $this->midday());

        $this->assertNotNull($plan);
        $this->assertSame(NotificationCategory::RECOVERY, $plan['category']);
    }

    public function test_nunca_propone_suplementos_a_un_menor(): void
    {
        $member = $this->makeMember('Menor', ['birth_date' => now()->subYears(15)->toDateString()]);
        $this->giveDevice($member);
        MemberNotificationPreference::create([
            'member_id' => $member->id,
            'categories' => [
                NotificationCategory::SUPPLEMENTS => true,
                NotificationCategory::MOTIVATION => false,
                NotificationCategory::HYDRATION => false,
                NotificationCategory::RECOVERY => false,
            ],
        ]);

        $plan = $this->planner()->planFor($member->fresh(), $this->midday());

        $this->assertNull($plan, 'A un menor no se le propone contenido de suplementos.');
    }

    public function test_sin_fecha_de_nacimiento_tampoco_hay_suplementos(): void
    {
        $member = $this->makeMember('Sin fecha', ['birth_date' => null]);
        $this->giveDevice($member);
        MemberNotificationPreference::create([
            'member_id' => $member->id,
            'categories' => [
                NotificationCategory::SUPPLEMENTS => true,
                NotificationCategory::MOTIVATION => false,
                NotificationCategory::HYDRATION => false,
                NotificationCategory::RECOVERY => false,
            ],
        ]);

        $this->assertNull(
            $this->planner()->planFor($member->fresh(), $this->midday()),
            'Sin saber la edad hay que callar, no suponer.',
        );
    }

    public function test_un_adulto_que_lo_pidio_si_recibe_suplementos(): void
    {
        $member = $this->makeMember('Adulto', ['birth_date' => now()->subYears(30)->toDateString()]);
        $this->giveDevice($member);
        MemberNotificationPreference::create([
            'member_id' => $member->id,
            'categories' => [
                NotificationCategory::SUPPLEMENTS => true,
                NotificationCategory::MOTIVATION => false,
                NotificationCategory::HYDRATION => false,
                NotificationCategory::RECOVERY => false,
            ],
        ]);

        $plan = $this->planner()->planFor($member->fresh(), $this->midday());

        $this->assertNotNull($plan);
        $this->assertSame(NotificationCategory::SUPPLEMENTS, $plan['category']);
        $this->assertNotNull($plan['supplement_kind']);
        $this->assertStringContainsString('no consejo médico', $plan['body']);
    }

    public function test_no_repite_la_misma_familia_de_suplemento_seguida(): void
    {
        $member = $this->makeMember('Adulto', ['birth_date' => now()->subYears(30)->toDateString()]);
        $this->giveDevice($member);
        MemberNotificationPreference::create([
            'member_id' => $member->id,
            'categories' => [
                NotificationCategory::SUPPLEMENTS => true,
                NotificationCategory::MOTIVATION => false,
                NotificationCategory::HYDRATION => false,
                NotificationCategory::RECOVERY => false,
            ],
        ]);

        $primero = $this->planner()->planFor($member->fresh(), $this->midday());
        NotificationDispatch::create([
            'member_id' => $member->id,
            'category' => NotificationCategory::SUPPLEMENTS,
            'supplement_kind' => $primero['supplement_kind'],
            'template_key' => $primero['key'],
            'title' => $primero['title'],
            'body' => $primero['body'],
            'idempotency_key' => 'manual-'.uniqid(),
            'status' => NotificationDispatch::STATUS_SENT,
            'sent_at' => $this->midday(),
        ]);

        $segundo = $this->planner()->planFor($member->fresh(), $this->midday()->addWeek()->subDay());

        if ($segundo !== null && $segundo['category'] === NotificationCategory::SUPPLEMENTS) {
            $this->assertNotSame($primero['supplement_kind'], $segundo['supplement_kind']);
        } else {
            $this->assertTrue(true, 'Cambió de categoría, que también evita insistir.');
        }
    }

    /**
     * El día que cuenta es el del gimnasio, no el del servidor.
     *
     * Las 10:00 y las 21:00 de Neiva son el mismo día para el socio, pero en
     * UTC caen en fechas distintas (15:00 del día N y 02:00 del día N+1). Con
     * una llave construida en UTC, la segunda tanda le mandaría un segundo
     * aviso el mismo día.
     */
    public function test_dos_tandas_del_mismo_dia_local_no_duplican_aunque_cambie_el_dia_utc(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);

        $manana = CarbonImmutable::parse('2026-07-30 10:00:00', 'America/Bogota')->setTimezone('UTC');
        $noche = CarbonImmutable::parse('2026-07-30 21:00:00', 'America/Bogota')->setTimezone('UTC');

        $this->assertNotSame(
            $manana->format('Y-m-d'),
            $noche->format('Y-m-d'),
            'El escenario pierde sentido si ambos instantes caen en el mismo día UTC.',
        );

        $this->planner()->planDaily($manana);
        $this->planner()->planDaily($noche);

        $this->assertSame(
            1,
            NotificationDispatch::query()->where('member_id', $member->id)->count(),
            'El socio recibió dos avisos en un mismo día suyo.',
        );
    }

    // ── Membresía vencida ───────────────────────────────────────────────

    /** Deja la membresía del socio vencida ayer. */
    private function vencerMembresia(Member $member): void
    {
        $member->user->update([
            'membership_end_date' => CarbonImmutable::parse('2026-07-29')->toDateString(),
        ]);
    }

    public function test_a_quien_tiene_la_membresia_vencida_no_se_le_habla_de_entrenar(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);
        $this->vencerMembresia($member);

        $plan = $this->planner()->planFor($member->fresh(), $this->midday());

        $this->assertNotNull($plan, 'Debe recibir algo: el tono cambia, no se le silencia.');

        $plantilla = NotificationTemplate::firstWhere('key', $plan['key']);
        $this->assertFalse(
            $plantilla->requires_active_membership,
            "La plantilla «{$plan['title']}» da por hecho que puede entrenar, y hoy no puede.",
        );
    }

    public function test_el_socio_al_dia_si_recibe_el_contenido_de_entrenamiento(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);
        // La fecha de fin la pone `makeMember` 30 días por delante.

        $plan = $this->planner()->planFor($member->fresh(), $this->midday());

        $this->assertNotNull($plan);
    }

    public function test_el_vencido_sigue_teniendo_variedad_suficiente(): void
    {
        $disponibles = NotificationTemplate::query()
            ->active()->forLapsedMembership()->count();

        $this->assertGreaterThanOrEqual(
            10,
            $disponibles,
            'Con pocas plantillas para socios vencidos, el mensaje se repetiría enseguida.',
        );
    }

    public function test_ninguna_plantilla_para_vencidos_menciona_entrenar_hoy(): void
    {
        // Frases que dan por hecho que la persona va a ir hoy al gimnasio.
        // «Lleva tu botella» entró en esta lista después de encontrarla
        // ofreciéndose a un socio con la membresía vencida.
        $prohibido = ['durante el entrenamiento', 'antes de entrenar', 'en el gimnasio',
            'revisa tu rutina', 'al terminar', 'tu entrenador', 'lleva tu botella',
            'la sesión', 'próxima sesión'];

        foreach (NotificationTemplate::query()->forLapsedMembership()->get() as $t) {
            $texto = mb_strtolower($t->title.' '.$t->body);
            foreach ($prohibido as $frase) {
                $this->assertStringNotContainsString(
                    $frase,
                    $texto,
                    "La plantilla {$t->key} se ofrece a socios vencidos pero les habla de entrenar.",
                );
            }
        }
    }

    public function test_no_planifica_para_quien_no_tiene_dispositivo(): void
    {
        $member = $this->makeMember();
        $this->giveDevice($member, active: false);

        $stats = $this->planner()->planDaily($this->midday());

        $this->assertSame(0, $stats['considered']);
        $this->assertSame(0, NotificationDispatch::query()->where('member_id', $member->id)->count());
    }
}
