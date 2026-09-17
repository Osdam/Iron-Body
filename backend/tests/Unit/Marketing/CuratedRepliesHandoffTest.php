<?php

namespace Tests\Unit\Marketing;

use App\Models\MarketingMessage;
use App\Services\Marketing\OutboundContentGuard;
use App\Services\Marketing\SalesAgentDecisionSchema;
use App\Services\Marketing\SalesConversationReplyService;
use PHPUnit\Framework\TestCase;

/**
 * Las respuestas curadas salen por el camino del critic fallido, que NO pasa
 * por el guard de salida: son texto de Laravel y se confía en ellas. Por eso
 * ninguna puede ofrecer un traspaso a una persona. Así se cierra la última
 * vía por la que «te paso con alguien» podía llegar a un turno normal.
 */
class CuratedRepliesHandoffTest extends TestCase
{
    public function test_no_curated_reply_offers_a_transfer(): void
    {
        $replies = new SalesConversationReplyService;
        $guard = new OutboundContentGuard;
        $fugas = [];

        foreach (SalesAgentDecisionSchema::INTENTS as $intent) {
            $texto = $replies->replyFor($intent, ['lead' => null, 'channel' => 'whatsapp']);
            if ($texto === null) {
                continue;
            }
            $r = $guard->inspect($texto, MarketingMessage::SENDER_AI, false);
            if (! $r['safe']) {
                $fugas[] = "{$intent}: «{$texto}» ({$r['code']})";
            }
        }

        foreach ([$replies->paymentPendingReply(), $replies->staffReviewReply()] as $texto) {
            $r = $guard->inspect($texto, MarketingMessage::SENDER_AI, false);
            if (! $r['safe']) {
                $fugas[] = "fallback: «{$texto}» ({$r['code']})";
            }
        }

        $this->assertSame([], $fugas, "Respuestas curadas que ofrecen traspaso:\n".implode("\n", $fugas));
    }
}
