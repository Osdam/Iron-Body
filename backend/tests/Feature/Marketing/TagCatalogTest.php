<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingConversationTag;
use App\Models\MarketingLead;
use App\Models\MarketingTag;
use App\Services\Marketing\MarketingConversationTagService;
use App\Services\Marketing\TagCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Etiquetas con catálogo, y la regla que las hace fiables.
 *
 * La afirmación central: **una persona no puede tocar una etiqueta de origen**.
 * «Meta Ads» no es una opinión del equipo, es la lectura de lo que informó el
 * canal. Si alguien pudiera retirarla de una conversación que vino de un
 * anuncio, los números de esa campaña dejarían de cuadrar y nadie sabría por
 * qué. Todo lo demás de este módulo es comodidad; esto es integridad.
 */
class TagCatalogTest extends TestCase
{
    use RefreshDatabase;

    private MarketingConversation $conversation;

    private MarketingConversationTagService $tags;

    protected function setUp(): void
    {
        parent::setUp();

        TagCatalog::sync();
        $this->tags = app(MarketingConversationTagService::class);

        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'meta_user_id' => '573150536026',
            'phone' => '3150536026', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
    }

    // ── Catálogo ────────────────────────────────────────────────────────────

    public function test_the_catalog_covers_the_three_families(): void
    {
        foreach ([
            MarketingTag::CATEGORY_COMMERCIAL,
            MarketingTag::CATEGORY_OPERATIONAL,
            MarketingTag::CATEGORY_ATTRIBUTION,
        ] as $category) {
            $this->assertTrue(
                MarketingTag::query()->where('category', $category)->exists(),
                "Falta la familia {$category}.",
            );
        }
    }

    /** Sincronizar dos veces no duplica ni multiplica el catálogo. */
    public function test_syncing_twice_is_harmless(): void
    {
        $before = MarketingTag::query()->count();
        TagCatalog::sync();

        $this->assertSame($before, MarketingTag::query()->count());
    }

    /** Todas las de atribución nacen bloqueadas, sin excepción. */
    public function test_every_attribution_tag_is_locked(): void
    {
        $unlocked = MarketingTag::query()
            ->where('category', MarketingTag::CATEGORY_ATTRIBUTION)
            ->where('locked', false)
            ->pluck('slug');

        $this->assertEmpty($unlocked, 'Etiquetas de origen editables: '.$unlocked->implode(', '));
    }

    // ── La regla que importa ────────────────────────────────────────────────

    /**
     * El caso central: nadie retira a mano el origen de una conversación.
     */
    public function test_a_person_cannot_remove_the_origin_tag(): void
    {
        $this->tags->attachSystem(
            $this->conversation, 'meta-ads', MarketingTag::KIND_SOURCE,
            ['fact' => 'meta_referral_received', 'ad_id' => '120210000000123456'],
        );

        $after = $this->tags->apply($this->conversation, [], ['meta-ads'], actorAdminId: 7);

        $this->assertContains('meta-ads', $after, 'Se pudo borrar a mano una etiqueta de origen.');
    }

    /** Tampoco se puede poner a mano un origen que el canal no informó. */
    public function test_a_person_cannot_invent_an_origin_tag(): void
    {
        $after = $this->tags->apply($this->conversation, ['meta-ads'], [], actorAdminId: 7);

        $this->assertNotContains('meta-ads', $after);
    }

    /** El sistema sí puede: él las deriva de la atribución. */
    public function test_the_system_can_set_and_clear_origin_tags(): void
    {
        $this->tags->attachSystem($this->conversation, 'organico', MarketingTag::KIND_SOURCE);
        $this->assertContains('organico', $this->tags->apply($this->conversation, [], [], null));

        $this->tags->detachSystem($this->conversation, 'organico');
        $this->assertNotContains('organico', $this->tags->apply($this->conversation, [], [], null));
    }

    // ── Etiquetas manuales ──────────────────────────────────────────────────

    public function test_a_person_can_use_their_own_tags(): void
    {
        // No estar en el catálogo no impide crearla: el equipo tiene que poder
        // inventarse las suyas sin pedir permiso.
        $after = $this->tags->apply($this->conversation, ['llamar-el-lunes'], [], 7);

        $this->assertContains('llamar-el-lunes', $after);
    }

    public function test_manual_tags_can_be_removed(): void
    {
        $this->tags->apply($this->conversation, ['pendiente'], [], 7);
        $after = $this->tags->apply($this->conversation, [], ['pendiente'], 7);

        $this->assertNotContains('pendiente', $after);
    }

    public function test_adding_the_same_tag_twice_is_harmless(): void
    {
        $this->tags->apply($this->conversation, ['soporte'], [], 7);
        $this->tags->apply($this->conversation, ['soporte'], [], 7);

        $this->assertSame(1, MarketingConversationTag::query()
            ->where('conversation_id', $this->conversation->id)->where('tag', 'soporte')->count());
    }

    // ── Evidencia y origen ──────────────────────────────────────────────────

    /**
     * Una etiqueta que el sistema pone sin poder explicar por qué es
     * indistinguible de un error.
     */
    public function test_system_tags_carry_their_evidence(): void
    {
        $this->tags->attachSystem(
            $this->conversation, 'alta-intencion', MarketingTag::KIND_AUTOMATIC,
            ['fact' => 'asked_for_price_twice'],
        );

        $detailed = collect($this->tags->detailed($this->conversation))->firstWhere('slug', 'alta-intencion');

        $this->assertSame('asked_for_price_twice', $detailed['evidence']['fact']);
        $this->assertSame(MarketingTag::KIND_AUTOMATIC, $detailed['kind']);
        $this->assertFalse($detailed['editable'] === null);
    }

    public function test_detailed_tags_carry_colour_and_category(): void
    {
        $this->tags->apply($this->conversation, ['facturacion'], [], 7);

        $detailed = collect($this->tags->detailed($this->conversation))->firstWhere('slug', 'facturacion');

        $this->assertSame('Facturación', $detailed['name']);
        $this->assertSame(MarketingTag::CATEGORY_OPERATIONAL, $detailed['category']);
        $this->assertNotSame('neutral', $detailed['color']);
    }

    // ── Lista: como mucho dos ───────────────────────────────────────────────

    /**
     * Con cinco etiquetas por fila la lista se vuelve una pared de colores y
     * deja de servir para decidir a quién atender primero.
     */
    public function test_the_list_shows_at_most_two_tags(): void
    {
        $this->tags->apply($this->conversation, ['pendiente', 'soporte', 'facturacion'], [], 7);
        $this->tags->attachSystem($this->conversation, 'meta-ads', MarketingTag::KIND_SOURCE);
        $this->tags->attachSystem($this->conversation, 'requiere-revision', MarketingTag::KIND_SYSTEM);

        $this->assertCount(2, $this->tags->forList($this->conversation));
    }

    /** Y las dos que se ven son las que cambian una decisión. */
    public function test_the_list_prioritises_what_needs_attention(): void
    {
        $this->tags->apply($this->conversation, ['soporte'], [], 7);
        $this->tags->attachSystem($this->conversation, 'meta-ads', MarketingTag::KIND_SOURCE);
        $this->tags->attachSystem($this->conversation, 'requiere-revision', MarketingTag::KIND_SYSTEM);

        $slugs = array_column($this->tags->forList($this->conversation), 'slug');

        // Primero lo operativo (exige actuar), después el origen.
        $this->assertSame('requiere-revision', $slugs[0]);
        $this->assertContains('meta-ads', $slugs);
    }

    // ── Compatibilidad ──────────────────────────────────────────────────────

    /**
     * El inbox actual y sus pruebas dependen de que `apply()` devuelva babosas.
     * Estrenar el catálogo no puede romper lo que ya está entregado.
     */
    public function test_the_previous_contract_still_holds(): void
    {
        $result = $this->tags->apply($this->conversation, ['pendiente'], [], 7);

        $this->assertIsArray($result);
        $this->assertContainsOnly('string', $result);
    }
}
