<?php

namespace Tests\Feature\Outreach\AI9\Support;

use App\Models\MarketingConversation;
use App\Models\MarketingMessage;
use App\Services\Meta\MetaConversationService;
use App\Services\Outreach\Run\Classifier\ReplyClassifierTransport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Outreach\W9\Support\W9World;

/**
 * El mundo de las pruebas de AI9: el de W9 ({@see W9World}: la plantilla de
 * WhatsApp que SALIÓ por la puerta real con Meta falso, y la respuesta de la
 * persona por el camino de siempre del webhook) más el clasificador
 * configurado y su transporte enlazado en el contenedor como lo enlazaría el
 * proveedor de servicios.
 */
trait AI9World
{
    use W9World;

    /** El modelo y los precios de las pruebas (US$ por millón de tokens de entrada y de salida). */
    private const MODEL = 'gpt-4.1-mini';

    private const PRICE_INPUT_MICROS = 400_000;

    private const PRICE_OUTPUT_MICROS = 1_600_000;

    /** La plantilla de WhatsApp en vivo que ya salió. @return array{job: object, message: MarketingMessage, member: array<string, mixed>, live: array<string, mixed>} */
    private function sentTemplate(): array
    {
        $p = $this->plannedWhatsappJob(self::utcAt(self::NOW));
        $this->assertSame(['started' => 1], $this->openDispatcher()->dispatch(now(), null, true)['results']);

        return ['job' => DB::table('outreach_jobs')->first(), 'message' => MarketingMessage::query()->where('direction', 'outbound')->sole(), 'member' => $p['member'], 'live' => $p['live']];
    }

    /** Un entrante de la persona por el camino de siempre (lo que guarda el webhook), minutos después. */
    private function inbound(int $leadId, string $body, int $minutesLater = 5): MarketingMessage
    {
        Carbon::setTestNow(now()->addMinutes($minutesLater));
        $conversacion = MarketingConversation::query()->where('lead_id', $leadId)->where('channel', 'whatsapp')->firstOrFail();

        return app(MetaConversationService::class)->recordInbound($conversacion, 'wamid.IN.'.uniqid('', true), $body, ['message_type' => 'text']);
    }

    /** El clasificador encendido, con cupo, techo, modelo y precios; el transporte, el que se dé (o ninguno). @param  array<string, mixed>  $extra */
    private function classifierOn(?ReplyClassifierTransport $transport, array $extra = []): void
    {
        config(array_merge([
            'outreach.classifier.enabled' => true,
            'outreach.classifier.daily_cap' => 10,
            'outreach.classifier.daily_budget_usd' => '1',
            'outreach.classifier.model' => self::MODEL,
            'outreach.classifier.price_input_usd_per_mtok' => '0.40',
            'outreach.classifier.price_output_usd_per_mtok' => '1.60',
        ], $extra));
        if ($transport !== null) {
            $this->app->instance(ReplyClassifierTransport::class, $transport);
        }
    }

    /** Las filas del clasificador en el libro. */
    private function classifierOutcomes(): Collection
    {
        return DB::table('outreach_outcomes')->where('source', 'classifier')->orderBy('id')->get();
    }

    /** El contador del día del clasificador (o null). */
    private function classifierCounter(): ?object
    {
        return DB::table('outreach_budget_counters')->where('scope', 'classifier:reply')->first();
    }
}
