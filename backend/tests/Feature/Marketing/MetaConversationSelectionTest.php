<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Services\Meta\MetaLeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A qué conversación pertenece un mensaje entrante.
 *
 * Esto existe por un fallo que se vio en producción: `lead_id + channel` no es
 * único, y la selección se hacía con un `first()` sin `ORDER BY`. El motor
 * devolvía la fila que encontrase primero —en PostgreSQL, la que estuviera
 * antes en el heap— y ese orden CAMBIA al actualizar una fila. Dos mensajes
 * seguidos de la misma persona acabaron en hilos distintos.
 *
 * Lo que se fija aquí no es la implementación sino el contrato: abierta antes
 * que cerrada, la que habló más recientemente, y el id como desempate. Las
 * pruebas que tocan `updated_at` son las que de verdad importan: son las que
 * fallaban antes.
 */
class MetaConversationSelectionTest extends TestCase
{
    use RefreshDatabase;

    private MetaLeadService $service;

    private MarketingLead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new MetaLeadService;
        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'meta_user_id' => '573000000000',
            'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);
    }

    private function conversation(string $status, ?string $lastMessageAt, string $channel = 'whatsapp'): MarketingConversation
    {
        return MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => $channel, 'status' => $status,
            'ai_enabled' => true, 'human_takeover' => false, 'last_message_at' => $lastMessageAt,
        ]);
    }

    /** 1. Una abierta y una cerrada: gana la abierta, aunque se creara después. */
    public function test_an_open_conversation_always_beats_a_closed_one(): void
    {
        $cerrada = $this->conversation('closed', '2026-09-01 10:00:00');
        $abierta = $this->conversation('open', '2026-08-01 10:00:00');

        $elegida = $this->service->ensureConversation($this->lead, 'whatsapp');

        $this->assertSame($abierta->id, $elegida->id);
        $this->assertNotSame($cerrada->id, $elegida->id, 'Una cerrada nunca gana a una abierta.');
    }

    /**
     * 2. El caso REAL que rompió el canario.
     *
     * Actualizar la fila abierta movía su posición física y el siguiente
     * entrante se iba a la cerrada. Aquí se actualiza a propósito.
     */
    public function test_updating_the_open_row_does_not_change_the_choice(): void
    {
        $this->conversation('closed', '2026-09-01 10:00:00');
        $abierta = $this->conversation('open', '2026-08-01 10:00:00');

        $primera = $this->service->ensureConversation($this->lead, 'whatsapp');

        // Exactamente lo que hace release(): un UPDATE sobre la conversación.
        $abierta->forceFill(['human_takeover' => false, 'ai_enabled' => true, 'summary' => 'traspaso'])->save();

        $segunda = $this->service->ensureConversation($this->lead, 'whatsapp');

        $this->assertSame($abierta->id, $primera->id);
        $this->assertSame($abierta->id, $segunda->id, 'Un UPDATE no puede mover los mensajes de hilo.');
    }

    /** 3. Tocar la cerrada tampoco la asciende. */
    public function test_touching_the_closed_row_does_not_promote_it(): void
    {
        $cerrada = $this->conversation('closed', '2026-09-01 10:00:00');
        $abierta = $this->conversation('open', '2026-08-01 10:00:00');

        $cerrada->forceFill(['summary' => 'la toco para mover su updated_at'])->save();

        $this->assertSame($abierta->id, $this->service->ensureConversation($this->lead, 'whatsapp')->id);
    }

    /** 4. Varias abiertas: la que habló más recientemente. */
    public function test_among_several_open_ones_the_most_recent_wins(): void
    {
        $this->conversation('open', '2026-07-01 10:00:00');
        $reciente = $this->conversation('open', '2026-09-10 10:00:00');
        $this->conversation('open', '2026-08-01 10:00:00');

        $this->assertSame($reciente->id, $this->service->ensureConversation($this->lead, 'whatsapp')->id);
    }

    /** 4b. Y si empatan en fecha, el id mayor: un criterio que no empata nunca. */
    public function test_a_tie_is_broken_by_id_and_is_stable(): void
    {
        $this->conversation('open', '2026-09-10 10:00:00');
        $ultima = $this->conversation('open', '2026-09-10 10:00:00');

        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->service->ensureConversation($this->lead, 'whatsapp')->id;
        }

        $this->assertSame([$ultima->id], array_unique($ids), 'La elección debe ser siempre la misma.');
    }

    /** 4c. Una abierta sin actividad no gana a una abierta que sí habló. */
    public function test_an_open_one_without_activity_does_not_beat_one_that_spoke(): void
    {
        $sinActividad = $this->conversation('open', null);
        $conActividad = $this->conversation('open', '2026-07-01 10:00:00');

        $this->assertSame($conActividad->id, $this->service->ensureConversation($this->lead, 'whatsapp')->id);
        $this->assertNotSame($sinActividad->id, $conActividad->id);
    }

    /** 5. Solo cerradas: se reutiliza, que es el comportamiento de hoy. */
    public function test_with_only_closed_ones_the_most_recent_is_reused(): void
    {
        $this->conversation('closed', '2026-07-01 10:00:00');
        $reciente = $this->conversation('closed', '2026-09-01 10:00:00');

        $elegida = $this->service->ensureConversation($this->lead, 'whatsapp');

        $this->assertSame($reciente->id, $elegida->id);
        $this->assertSame(2, MarketingConversation::count(), 'No se crea una nueva si hay dónde continuar.');
    }

    /** 6. Sin ninguna: se crea, abierta y con la IA activa. */
    public function test_with_no_conversation_it_creates_one(): void
    {
        $creada = $this->service->ensureConversation($this->lead, 'whatsapp');

        $this->assertSame(1, MarketingConversation::count());
        $this->assertSame('open', $creada->status);
        $this->assertTrue((bool) $creada->ai_enabled);
        $this->assertFalse((bool) $creada->human_takeover);
        $this->assertSame($this->lead->id, $creada->lead_id);
    }

    /** 7. Con una sola conversación, nada cambia respecto a antes. */
    public function test_a_single_conversation_behaves_exactly_as_before(): void
    {
        $unica = $this->conversation('open', '2026-09-01 10:00:00');

        $this->assertSame($unica->id, $this->service->ensureConversation($this->lead, 'whatsapp')->id);
        $this->assertSame(1, MarketingConversation::count());
    }

    /** 8. Los canales no se mezclan: cada uno tiene su hilo. */
    public function test_channels_do_not_bleed_into_each_other(): void
    {
        $whatsapp = $this->conversation('open', '2026-09-01 10:00:00', 'whatsapp');
        $instagram = $this->conversation('open', '2026-09-05 10:00:00', 'instagram');

        $this->assertSame($whatsapp->id, $this->service->ensureConversation($this->lead, 'whatsapp')->id);
        $this->assertSame($instagram->id, $this->service->ensureConversation($this->lead, 'instagram')->id);
    }

    /** Y un lead distinto nunca se lleva la conversación de otro. */
    public function test_another_lead_never_gets_this_conversation(): void
    {
        $this->conversation('open', '2026-09-01 10:00:00');

        $otro = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'meta_user_id' => '573111111111',
            'name' => 'Otro', 'status' => MarketingLead::STATUS_NEW,
        ]);

        $suya = $this->service->ensureConversation($otro, 'whatsapp');

        $this->assertSame($otro->id, $suya->lead_id);
        $this->assertSame(2, MarketingConversation::count());
    }
}
