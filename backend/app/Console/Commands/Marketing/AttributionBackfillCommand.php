<?php

namespace App\Console\Commands\Marketing;

use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Services\Marketing\Attribution\LeadSourceClassifier;
use App\Services\Marketing\Attribution\LeadUniverse;
use App\Services\Marketing\LeadAttributionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;

/**
 * Recupera la atribución de los leads que se quedaron sin fila, a partir del
 * referral que sí quedó guardado en sus mensajes.
 *
 * SIMULA por defecto: lista lo que haría y no escribe nada. Con `--apply`
 * reproduce, en orden, lo que el webhook habría hecho en su día, llamando al
 * mismo LeadAttributionService: el primer entrante crea la fila (sin referral,
 * `unknown`; el primer contacto no se reescribe después) y cada entrante con
 * referral posterior cuenta como un contacto nuevo. Las fechas son las de los
 * mensajes, no las de hoy.
 *
 * Solo toca leads reales SIN fila de atribución: los que ya la tienen no se
 * reescriben. Repetirlo no duplica nada (la fila es única por lead).
 *
 * No está pensado para producción: allí pide confirmación, salvo `--force`.
 */
class AttributionBackfillCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'marketing:attribution-backfill
        {--apply : Escribe las atribuciones; sin esta opción solo simula}
        {--force : En producción, no pide confirmación}';

    protected $description = 'Crea la atribución de los leads sin fila que tienen el referral de Meta guardado en sus mensajes (simula por defecto).';

    public function handle(LeadAttributionService $attribution): int
    {
        $plan = $this->plan();

        if ($plan === []) {
            $this->info('No hay leads reales sin atribución con un referral guardado. Nada que hacer.');

            return self::SUCCESS;
        }

        $this->table(
            ['lead', 'entrantes a reproducir', 'con referral', 'primer contacto', 'plataforma'],
            array_map(fn (array $p) => [
                $p['lead_id'],
                count($p['touches']),
                $p['with_referral'],
                $p['first_type'],
                $p['first_platform'] ?? '—',
            ], $plan),
        );

        if (! $this->option('apply')) {
            $this->comment('Simulación: no se escribió nada. Usa --apply para crear estas '.count($plan).' atribuciones.');

            return self::SUCCESS;
        }

        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $created = 0;
        $failed = 0;
        foreach ($plan as $p) {
            $row = null;
            foreach ($p['touches'] as $touch) {
                $row = $attribution->record(
                    leadId: $p['lead_id'],
                    referral: $touch['referral'],
                    conversationId: $touch['conversation_id'],
                    contactId: $touch['contact_id'],
                    receivedAt: $touch['at'],
                ) ?? $row;
            }

            $row !== null ? $created++ : $failed++;
        }

        $this->info("Atribuciones creadas: {$created}. Fallidas: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Los leads a recuperar y los entrantes que hay que reproducir de cada uno.
     *
     * @return list<array{lead_id: int, touches: list<array{referral: ?array, conversation_id: int, contact_id: ?string, at: CarbonImmutable}>,
     *     with_referral: int, first_type: string, first_platform: ?string}>
     */
    private function plan(): array
    {
        $candidates = LeadUniverse::constrain(DB::table('marketing_leads as l'), 'l.source')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('marketing_lead_attributions as a')
                ->whereColumn('a.marketing_lead_id', 'l.id'))
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('marketing_messages as m')
                ->join('marketing_conversations as c', 'c.id', '=', 'm.conversation_id')
                ->whereColumn('c.lead_id', 'l.id')
                ->where('m.direction', MarketingMessage::DIRECTION_INBOUND)
                ->whereNotNull('m.metadata->referral'))
            ->orderBy('l.id')
            ->pluck('l.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($candidates === []) {
            return [];
        }

        $plan = [];
        foreach (array_chunk($candidates, 200) as $ids) {
            $metaUser = MarketingLead::query()->whereIn('id', $ids)->pluck('meta_user_id', 'id');

            $messages = DB::table('marketing_messages as m')
                ->join('marketing_conversations as c', 'c.id', '=', 'm.conversation_id')
                ->whereIn('c.lead_id', $ids)
                ->where('m.direction', MarketingMessage::DIRECTION_INBOUND)
                ->orderBy('m.created_at')
                ->orderBy('m.id')
                ->get(['c.lead_id', 'm.conversation_id', 'm.metadata', 'm.created_at']);

            foreach ($messages as $m) {
                $leadId = (int) $m->lead_id;
                $metadata = is_string($m->metadata) ? json_decode($m->metadata, true) : (array) $m->metadata;
                $referral = is_array($metadata['referral'] ?? null) ? $metadata['referral'] : null;
                $isFirst = ! isset($plan[$leadId]);

                // Después del primero, un entrante sin referral no cambia nada.
                if (! $isFirst && $referral === null) {
                    continue;
                }

                $plan[$leadId] ??= [
                    'lead_id' => $leadId,
                    'touches' => [],
                    'with_referral' => 0,
                    'first_type' => $referral !== null ? (string) ($referral['source_type'] ?? 'unknown') : 'unknown',
                    'first_platform' => $referral !== null ? LeadSourceClassifier::platformFromUrl($referral['source_url'] ?? null) : null,
                ];

                $plan[$leadId]['touches'][] = [
                    'referral' => $referral,
                    'conversation_id' => (int) $m->conversation_id,
                    'contact_id' => is_string($metadata['wa_id'] ?? null) ? $metadata['wa_id'] : ($metaUser[$leadId] ?? null),
                    'at' => CarbonImmutable::parse((string) $m->created_at, 'UTC'),
                ];
                $plan[$leadId]['with_referral'] += $referral !== null ? 1 : 0;
            }
        }

        return array_values($plan);
    }
}
