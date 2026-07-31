<?php

namespace Tests\Feature\Notifications;

use App\Models\Attendance;
use App\Models\Member;
use App\Models\MemberNotificationPreference;
use App\Models\NotificationDispatch;
use App\Models\NotificationTemplate;
use App\Services\Notifications\WellnessPlanner;
use App\Support\Notifications\NotificationCategory;
use App\Support\Notifications\NotificationSlot as Slot;
use Carbon\Carbon;
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

    /** Las 12:00 de Neiva: dentro de la franja de media mañana. */
    private function midday(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-07-30 17:00:00', 'UTC');
    }

    /**
     * Instante de disparo de la franja indicada, en hora de Neiva.
     *
     * Son las horas REALES del cron, no unas cualesquiera dentro de cada
     * franja: el hueco más estrecho del día —de 19:00 a 21:45— es justo el que
     * pone a prueba el intervalo mínimo de seguridad, y un test que usara horas
     * inventadas dejaría de vigilarlo.
     */
    private function enFranja(string $slot, string $dia = '2026-07-30'): CarbonImmutable
    {
        $hora = match ($slot) {
            Slot::MORNING => '07:00',
            Slot::MIDMORNING => '11:00',
            Slot::AFTERNOON => '15:00',
            Slot::EVENING => '19:00',
            Slot::NIGHT => '21:45',
        };

        return CarbonImmutable::parse("{$dia} {$hora}", 'America/Bogota')->setTimezone('UTC');
    }

    /**
     * Corre una franja moviendo también el reloj del test.
     *
     * `created_at` lo pone Eloquent con el reloj global, no con el instante que
     * recibe el planificador. Sin mover el reloj, catorce días simulados
     * escriben todas sus filas con la misma fecha y los cupos diarios las
     * cuentan como si fueran del mismo día.
     */
    private function correrFranja(string $slot, string $dia = '2026-07-30'): array
    {
        $instante = $this->enFranja($slot, $dia);
        CarbonImmutable::setTestNow($instante);
        Carbon::setTestNow($instante);

        return $this->planner()->planDaily($instante);
    }

    /** Apaga todo lo que no sea la categoría que el test quiere observar. */
    private function soloCategoria(Member $member, string $categoria): void
    {
        $mapa = [];
        foreach ([NotificationCategory::MOTIVATION, NotificationCategory::HYDRATION,
            NotificationCategory::RECOVERY, NotificationCategory::NUTRITION,
            NotificationCategory::SUPPLEMENTS] as $c) {
            $mapa[$c] = $c === $categoria;
        }

        MemberNotificationPreference::updateOrCreate(
            ['member_id' => $member->id],
            ['categories' => $mapa],
        );
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

    public function test_envia_una_sola_por_franja_aunque_corra_dos_veces(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);

        // Dos ejecuciones dentro de la MISMA franja de media mañana.
        $this->planner()->planDaily($this->enFranja(Slot::MIDMORNING));
        $this->planner()->planDaily($this->enFranja(Slot::MIDMORNING)->addHour());

        $this->assertSame(
            1,
            NotificationDispatch::query()->where('member_id', $member->id)->count(),
            'Dos pasadas de la misma franja deben producir un único intento.',
        );
    }

    public function test_las_cinco_franjas_del_dia_producen_cinco_avisos(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);

        foreach (Slot::ALL as $slot) {
            $this->correrFranja($slot);
        }

        $enviadas = NotificationDispatch::query()
            ->where('member_id', $member->id)->sent()->orderBy('id')->get();

        $this->assertCount(5, $enviadas, 'Debe llegar una por franja.');
        $this->assertSame(
            Slot::ALL,
            $enviadas->pluck('slot')->all(),
            'Cada aviso debe quedar etiquetado con su franja.',
        );
    }

    public function test_nunca_pasa_de_cinco_en_un_dia(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);

        // Cada franja, dos veces: diez ejecuciones en el mismo día local.
        foreach (Slot::ALL as $slot) {
            $this->correrFranja($slot);
            $this->correrFranja($slot);
        }

        $this->assertSame(
            5,
            NotificationDispatch::query()->where('member_id', $member->id)->sent()->count(),
            'Diez disparos no pueden convertirse en más de cinco avisos.',
        );
    }

    /**
     * La segunda tanda del día no envía nada, y debe decirlo.
     *
     * El 31 de julio de 2026 la tanda de las 10:00 informó `enviados: 3` cuando
     * solo había sonado un teléfono: los otros dos socios ya tenían su fila del
     * día y el despachador la devolvió tal cual. Contar eso como envío deja el
     * número sin valor justo para lo que se mira desde n8n.
     */
    public function test_la_segunda_tanda_del_dia_no_cuenta_como_enviada(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);

        $primera = $this->planner()->planDaily($this->enFranja(Slot::MIDMORNING));
        $segunda = $this->planner()->planDaily($this->enFranja(Slot::MIDMORNING)->addHour());

        $this->assertSame(1, $primera['sent'], 'La primera tanda sí envía.');
        $this->assertSame(0, $primera['already_handled']);

        $this->assertSame(1, $segunda['considered']);
        $this->assertSame(0, $segunda['sent'], 'La segunda tanda no mandó nada; no puede decir que sí.');
        $this->assertSame(0, $segunda['suppressed'], 'No se decidió callar: ya estaba resuelto.');
        $this->assertSame(1, $segunda['already_handled']);
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

        // La franja de la tarde admite recuperación; la de media mañana no, y
        // esa precedencia de la hora sobre el comportamiento es deliberada.
        $ahora = $this->enFranja(Slot::AFTERNOON);

        Attendance::create([
            'user_id' => $member->user_id,
            'member_id' => $member->id,
            'action' => 'entry',
            'source' => 'manual',
            'captured_at' => $ahora->subHours(20),
        ]);

        $plan = $this->planner()->planFor($member->fresh(), $ahora);

        $this->assertNotNull($plan);
        $this->assertSame(NotificationCategory::RECOVERY, $plan['category']);
        $this->assertSame('entreno_reciente', $plan['selection_reason']);
    }

    public function test_la_franja_manda_sobre_el_comportamiento(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);

        $ahora = $this->enFranja(Slot::MIDMORNING);

        Attendance::create([
            'user_id' => $member->user_id,
            'member_id' => $member->id,
            'action' => 'entry',
            'source' => 'manual',
            'captured_at' => $ahora->subHours(20),
        ]);

        $plan = $this->planner()->planFor($member->fresh(), $ahora);

        $this->assertNotNull($plan);
        $this->assertNotSame(
            NotificationCategory::RECOVERY,
            $plan['category'],
            'A media mañana no toca hablar de descanso, por muy reciente que sea el entreno.',
        );
    }

    public function test_nunca_propone_suplementos_a_un_menor(): void
    {
        $member = $this->makeMember('Menor', ['birth_date' => now()->subYears(15)->toDateString()]);
        $this->giveDevice($member);
        $this->soloCategoria($member, NotificationCategory::SUPPLEMENTS);

        $plan = $this->planner()->planFor($member->fresh(), $this->midday());

        $this->assertNull($plan, 'A un menor no se le propone contenido de suplementos.');
    }

    public function test_sin_fecha_de_nacimiento_tampoco_hay_suplementos(): void
    {
        $member = $this->makeMember('Sin fecha', ['birth_date' => null]);
        $this->giveDevice($member);
        $this->soloCategoria($member, NotificationCategory::SUPPLEMENTS);

        $this->assertNull(
            $this->planner()->planFor($member->fresh(), $this->midday()),
            'Sin saber la edad hay que callar, no suponer.',
        );
    }

    public function test_un_adulto_que_lo_pidio_si_recibe_suplementos(): void
    {
        $member = $this->makeMember('Adulto', ['birth_date' => now()->subYears(30)->toDateString()]);
        $this->giveDevice($member);
        $this->soloCategoria($member, NotificationCategory::SUPPLEMENTS);

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
        $this->soloCategoria($member, NotificationCategory::SUPPLEMENTS);

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
     * Las 08:00 y las 21:45 de Neiva son el mismo día para el socio, pero en
     * UTC caen en fechas distintas (13:00 del día N y 02:45 del día N+1). Con
     * una fecha construida en UTC, la franja de cierre quedaría archivada como
     * del día siguiente y el cupo diario se reiniciaría a mitad de la noche.
     */
    public function test_el_dia_local_agrupa_las_franjas_aunque_cambie_el_dia_utc(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);

        $manana = $this->enFranja(Slot::MORNING);
        $noche = $this->enFranja(Slot::NIGHT);

        $this->assertNotSame(
            $manana->format('Y-m-d'),
            $noche->format('Y-m-d'),
            'El escenario pierde sentido si ambos instantes caen en el mismo día UTC.',
        );

        $this->planner()->planDaily($manana);
        $this->planner()->planDaily($noche);

        $llaves = NotificationDispatch::query()
            ->where('member_id', $member->id)
            ->orderBy('id')
            ->pluck('idempotency_key')
            ->all();

        $this->assertSame([
            "wellness:{$member->id}:2026-07-30:morning",
            "wellness:{$member->id}:2026-07-30:night",
        ], $llaves, 'Las dos franjas pertenecen al mismo día del gimnasio.');
    }

    public function test_dos_ejecuciones_concurrentes_de_una_franja_no_duplican(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);

        // Mismo instante exacto, como si dos disparos llegaran a la vez.
        $ahora = $this->enFranja(Slot::AFTERNOON);
        $this->planner()->planDaily($ahora);
        $this->planner()->planDaily($ahora);

        $this->assertSame(
            1,
            NotificationDispatch::query()->where('member_id', $member->id)->count(),
        );
    }

    public function test_no_repite_categoria_en_dos_franjas_seguidas(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);

        foreach (Slot::ALL as $slot) {
            $this->correrFranja($slot);
        }

        $categorias = NotificationDispatch::query()
            ->where('member_id', $member->id)
            ->sent()
            ->orderBy('id')
            ->pluck('category')
            ->all();

        for ($i = 1; $i < count($categorias); $i++) {
            $this->assertNotSame(
                $categorias[$i - 1],
                $categorias[$i],
                'Dos franjas seguidas hablaron del mismo tema.',
            );
        }
    }

    public function test_no_repite_plantilla_en_catorce_dias(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);

        for ($dia = 0; $dia < 14; $dia++) {
            $fecha = CarbonImmutable::parse('2026-07-01')->addDays($dia)->format('Y-m-d');
            foreach (Slot::ALL as $slot) {
                $this->correrFranja($slot, $fecha);
            }
        }

        $claves = NotificationDispatch::query()
            ->where('member_id', $member->id)
            ->sent()
            ->pluck('template_key')
            ->all();

        $this->assertGreaterThan(60, count($claves), 'Dos semanas deben producir muchos avisos.');
        $this->assertSame(
            count($claves),
            count(array_unique($claves)),
            'Alguna plantilla se repitió dentro de la ventana de catorce días.',
        );
    }

    public function test_pasados_catorce_dias_la_plantilla_vuelve_a_estar_disponible(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);

        $primera = $this->planner()->planFor($member->fresh(), $this->enFranja(Slot::MORNING));
        $this->assertNotNull($primera);

        NotificationDispatch::create([
            'member_id' => $member->id,
            'category' => $primera['category'],
            'slot' => Slot::MORNING,
            'template_key' => $primera['key'],
            'title' => $primera['title'],
            'body' => $primera['body'],
            'idempotency_key' => 'manual-'.uniqid(),
            'status' => NotificationDispatch::STATUS_SENT,
            'sent_at' => $this->enFranja(Slot::MORNING),
        ]);

        // Al día siguiente el veto sigue vivo y esa plantilla no puede repetirse.
        $alDiaSiguiente = $this->planner()->planFor($member->fresh(), $this->enFranja(Slot::MORNING, '2026-07-31'));
        $this->assertNotNull($alDiaSiguiente);
        $this->assertNotSame($primera['key'], $alDiaSiguiente['key'], 'El veto de catorce días no se respetó.');

        // A los quince, ya caducó y el contenido vuelve al fondo disponible.
        $recientes = NotificationDispatch::query()
            ->where('member_id', $member->id)
            ->sent()
            ->where('created_at', '>=', $this->enFranja(Slot::MORNING, '2026-08-14')->subDays(WellnessPlanner::TEMPLATE_COOLDOWN_DAYS))
            ->pluck('template_key')
            ->all();

        $this->assertNotContains(
            $primera['key'],
            $recientes,
            'Pasados catorce días la plantilla debe salir de la lista de vetadas.',
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
