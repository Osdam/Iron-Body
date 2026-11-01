<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingKnowledgeItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * AS-5 del H5 en la API interna de conocimiento (H7, SEC7). Lo que propone n8n
 * lo lee después una persona para aprobarlo, y un invisible es justo lo que no
 * se lee: las etiquetas U+E0000–U+E007F llevan texto que un modelo sí lee, y un
 * ZWSP en la `key` haría un gemelo visual de un hecho aprobado. Aquí no se
 * rechaza (rompería el flujo del motor): se LIMPIA con la clase de
 * `lab-invisibles`, se registra sin el texto y la respuesta lo dice en
 * `cleaned`. Nunca 500.
 *
 * Sin red: `Http::preventStrayRequests()`. Este fichero no lleva ningún
 * invisible literal.
 */
class KnowledgeInvisiblesTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    /** @var list<array{message:string,context:array<string,mixed>}> */
    private array $registros = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('automation.internal_secret', self::SECRET);
        config()->set('meta.enabled', false);
        Http::preventStrayRequests();
        Http::fake();

        Log::listen(function (MessageLogged $m): void {
            $this->registros[] = ['message' => $m->message, 'context' => $m->context];
        });
    }

    private function h(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }

    private function proponer(array $payload): TestResponse
    {
        return $this->postJson('/api/internal/marketing/knowledge', $payload, $this->h());
    }

    /** @return list<array<string,mixed>> */
    private function limpiezas(): array
    {
        return array_values(array_map(
            static fn (array $r): array => $r['context'],
            array_filter($this->registros, static fn (array $r): bool => $r['message'] === 'marketing.knowledge.invisibles_cleaned'),
        ));
    }

    public function test_el_contenido_con_invisibles_se_guarda_limpio_con_el_emoji_y_se_registra_sin_el_texto(): void
    {
        $levantadora = "\u{1F3CB}\u{FE0F}\u{200D}\u{2640}\u{FE0F}";
        $oculto = "\u{E0049}\u{E0047}\u{E004E}\u{E004F}\u{E0052}\u{E0045}";

        $res = $this->proponer([
            'category' => 'faq', 'key' => 'faq.precio',
            'content' => "Precio\u{200B} del plan\u{00AD} mensual:\u{2028}80.000{$oculto} {$levantadora}",
            'submitted_by' => 'n8n-flujo',
        ])->assertOk();

        $res->assertJsonPath('cleaned', ['content'])
            ->assertJsonPath('published', false)
            ->assertJsonPath('review_status', MarketingKnowledgeItem::REVIEW_PENDING);

        $item = MarketingKnowledgeItem::where('key', 'faq.precio')->firstOrFail();
        $this->assertSame("Precio del plan mensual:\n80.000 {$levantadora}", $item->content);
        $this->assertFalse($item->isPublishable());

        $limpiezas = $this->limpiezas();
        $this->assertCount(1, $limpiezas);
        $this->assertSame('faq.precio', $limpiezas[0]['key']);
        $this->assertSame(['content' => 9], $limpiezas[0]['fields']);
        $this->assertSame(9, $limpiezas[0]['removed']);
        $this->assertSame(['U+00AD', 'U+200B', 'U+2028', 'U+E0045', 'U+E0047', 'U+E0049', 'U+E004E', 'U+E004F', 'U+E0052'], $limpiezas[0]['codepoints']);
        $this->assertFalse($limpiezas[0]['invalid_utf8']);
        $this->assertSame('n8n-flujo', $limpiezas[0]['submitted_by']);

        // El texto no se escribe en ningún registro (solo su huella, como antes).
        $todo = (string) json_encode($this->registros, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Precio', $todo);
        $this->assertStringNotContainsString('80.000', $todo);
    }

    public function test_sin_invisibles_no_cambia_nada_ni_registra_la_limpieza(): void
    {
        $texto = "Plan familiar \u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467} con \u{2764}\u{FE0F}\nSegunda línea";

        $this->proponer(['category' => 'faq', 'key' => 'faq.familia', 'title' => 'Familia', 'content' => $texto])
            ->assertOk()->assertJsonPath('cleaned', []);

        $this->assertSame($texto, MarketingKnowledgeItem::where('key', 'faq.familia')->value('content'));
        $this->assertSame([], $this->limpiezas());
    }

    public function test_una_key_con_un_invisible_no_crea_un_gemelo_de_un_hecho_aprobado(): void
    {
        MarketingKnowledgeItem::create([
            'category' => 'payment_policy', 'key' => 'payment.wompi', 'title' => 'Pagos',
            'content' => 'Pagos por Wompi.', 'source' => 'seeder', 'origin' => MarketingKnowledgeItem::ORIGIN_SEEDER,
        ]);
        $this->assertTrue(MarketingKnowledgeItem::where('key', 'payment.wompi')->firstOrFail()->isPublishable());

        $this->proponer([
            'category' => 'payment_policy', 'key' => "payment.wo\u{200B}mpi", 'content' => 'Paga por transferencia a la cuenta 123.',
        ])->assertStatus(409)
            ->assertJsonPath('code', 'knowledge_item_approved')
            ->assertJsonPath('key', 'payment.wompi')
            ->assertJsonPath('cleaned', ['key']);

        $this->assertSame(1, MarketingKnowledgeItem::query()->count(), 'ni gemelo ni sobrescritura');
        $this->assertSame('Pagos por Wompi.', MarketingKnowledgeItem::where('key', 'payment.wompi')->value('content'));
    }

    public function test_el_titulo_y_las_cadenas_de_metadata_tambien_se_limpian(): void
    {
        $this->proponer([
            'category' => 'schedule', 'key' => "schedule.\u{2060}domingo",
            'title' => "Ho\u{2060}rario", 'content' => 'Domingos de 8 a 12.',
            // En medio: el ZWSP de un borde ya lo quita `TrimStrings` antes de llegar aquí.
            'metadata' => ['fuente' => "n8\u{200B}n", "a\u{00AD}b" => ['x' => "y\u{E0041}"], 'n' => 3],
        ])->assertOk()->assertJsonPath('cleaned', ['key', 'title', 'metadata']);

        $item = MarketingKnowledgeItem::where('key', 'schedule.domingo')->firstOrFail();
        $this->assertSame('Horario', $item->title);
        $this->assertSame(['fuente' => 'n8n', 'ab' => ['x' => 'y'], 'n' => 3], $item->metadata);
        $this->assertSame(['key' => 1, 'title' => 1, 'metadata' => 3], $this->limpiezas()[0]['fields']);
    }

    public function test_un_contenido_que_solo_eran_invisibles_es_el_422_de_siempre(): void
    {
        $this->proponer(['category' => 'faq', 'key' => 'faq.vacia', 'content' => "\u{200B}\u{E0041}\u{2060}"])
            ->assertStatus(422)->assertJsonValidationErrors(['content']);

        $this->assertSame(0, MarketingKnowledgeItem::query()->count());
        $this->assertCount(1, $this->limpiezas(), 'el intento queda registrado aunque no se guarde');
    }

    public function test_utf8_invalido_por_formulario_se_sustituye_y_no_es_500(): void
    {
        $res = $this->post('/api/internal/marketing/knowledge', [
            'category' => 'faq', 'key' => 'faq.utf8', 'content' => "Jos\xE9 P\xE9rez paga en efectivo",
        ], $this->h());

        $res->assertOk()->assertJsonPath('cleaned', ['content']);
        $guardado = (string) MarketingKnowledgeItem::where('key', 'faq.utf8')->value('content');
        $this->assertTrue(mb_check_encoding($guardado, 'UTF-8'));
        $this->assertSame('Jos? P?rez paga en efectivo', $guardado);
        $this->assertTrue($this->limpiezas()[0]['invalid_utf8']);
    }

    public function test_un_submitted_by_que_no_es_texto_es_422_y_no_500(): void
    {
        $this->proponer([
            'category' => 'faq', 'key' => 'faq.raro', 'content' => "Texto\u{200B} raro", 'submitted_by' => ['no', 'texto'],
        ])->assertStatus(422)->assertJsonValidationErrors(['submitted_by']);

        $this->assertNull($this->limpiezas()[0]['submitted_by']);
    }
}
