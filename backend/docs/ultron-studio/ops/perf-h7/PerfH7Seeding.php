<?php

namespace Tests\PerfH7;

use App\Models\Admin;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\UltronStudio\StudioCampaignLink;
use App\Models\UltronStudio\StudioLabRun;
use App\Models\UltronStudio\StudioLabRunCase;
use App\Models\UltronStudio\StudioVersion;
use App\Services\Marketing\Ultron\UltronPublishedConfig;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PERF7: la siembra de datos de tamaño realista, común al arnés de consultas
 * (PerfH7QueriesTest) y a la base del banco HTTP (PerfH7SeedHttpTest). Se apoya
 * en los fixtures del Studio (StudioFixtures, PublicationFixtures,
 * IntegrationFixtures, LabFixtures), que la clase que lo usa compone.
 *
 * Referencias de producción (informes del H6): `meta_ad_entities` 114 filas en 3
 * cuentas; `marketing_ai_actions` ~700 filas en 7 días (~100/día). El fondo por
 * defecto (20 000 acciones, 40 000 mensajes) equivale a unos 6 meses.
 */
trait PerfH7Seeding
{
    private int $mundoPerf = 0;

    /** Hasta $total versiones (las de antes se conservan), en estados de un historial real. */
    private function crecerVersiones(Admin $ana, Admin $luis, int $total): void
    {
        $estados = ['RETIRED', 'REJECTED', 'DRAFT', 'APPROVED', 'REVIEW_REQUIRED'];
        $hay = StudioVersion::query()->count();
        for ($i = $hay; $i < $total; $i++) {
            $estado = $estados[$i % count($estados)];
            in_array($estado, ['DRAFT', 'REVIEW_REQUIRED', 'REJECTED'], true)
                ? $this->seedVersion($i % 2 === 0 ? $ana : $luis, $this->baseline(), $estado)
                : $this->seededVersion($ana, $luis, $this->baseline(), $estado);
        }
    }

    /** $acciones filas de `marketing_ai_actions` (y el doble de mensajes) en conversaciones de 50 turnos. */
    private function sembrarFondo(int $acciones): void
    {
        $porConversacion = 50;
        $ahora = CarbonImmutable::now();
        for ($c = 0; $c < intdiv($acciones, $porConversacion); $c++) {
            $lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '320'.str_pad((string) $c, 7, '0', STR_PAD_LEFT), 'name' => 'Fondo '.$c]);
            $conv = MarketingConversation::create(['lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open']);
            $mensajes = [];
            $filas = [];
            for ($i = 0; $i < $porConversacion; $i++) {
                $cuando = $ahora->subMinutes(($c * $porConversacion + $i) * 7)->utc();
                $mensajes[] = ['conversation_id' => $conv->id, 'direction' => 'inbound', 'sender_type' => 'lead', 'body' => 'Hola, quiero información de los planes '.$i, 'status' => 'received', 'created_at' => $cuando, 'updated_at' => $cuando];
                $mensajes[] = ['conversation_id' => $conv->id, 'direction' => 'outbound', 'sender_type' => 'ai', 'body' => 'Claro, el plan mensual cuesta $80.000 e incluye acceso completo. ¿Quieres agendar una visita?', 'status' => 'sent', 'created_at' => $cuando->addSeconds(20), 'updated_at' => $cuando->addSeconds(20)];
            }
            DB::table('marketing_messages')->insert($mensajes);
            $entrantes = DB::table('marketing_messages')->where('conversation_id', $conv->id)->where('direction', 'inbound')->orderBy('id')->pluck('id')->all();
            foreach ($entrantes as $i => $mid) {
                $cuando = $ahora->subMinutes(($c * $porConversacion + $i) * 7)->utc();
                $filas[] = [
                    'lead_id' => $lead->id, 'conversation_id' => $conv->id, 'action_type' => 'reply', 'status' => 'executed',
                    'source_type' => 'inbound_message', 'source_event_id' => $mid, 'idempotency_key' => 'perf-fondo-'.$conv->id.'-'.$mid,
                    'metadata' => json_encode(['origin' => 'ultron', 'intent' => 'general_info', 'strategy_goal' => 'collect_data', 'critic' => ['verdict' => 'pass', 'score' => 0.9]]),
                    'created_at' => $cuando, 'updated_at' => $cuando,
                ];
            }
            DB::table('marketing_ai_actions')->insert($filas);
        }
    }

    /** Turnos del canario de $de a $hasta (entrante + acción sellada + saliente), cada 5 min desde $desde. */
    private function sembrarTurnosDelCanario(string $version, CarbonImmutable $desde, int $de, int $hasta): void
    {
        $conv = $this->canary();
        for ($i = $de; $i < $hasta; $i++) {
            $cuando = $desde->addMinutes(5 * ($i + 1))->utc();
            $entrante = DB::table('marketing_messages')->insertGetId([
                'conversation_id' => $conv->id, 'direction' => 'inbound', 'sender_type' => 'lead',
                'body' => 'Buenas, ¿qué horarios tienen y cuánto cuesta el plan trimestral? Mensaje '.$i,
                'status' => 'received', 'created_at' => $cuando, 'updated_at' => $cuando,
            ]);
            DB::table('marketing_ai_actions')->insert([
                'lead_id' => $conv->lead_id, 'conversation_id' => $conv->id, 'action_type' => 'reply', 'status' => 'executed',
                'source_type' => 'inbound_message', 'source_event_id' => $entrante, 'idempotency_key' => 'perf-canario-'.$entrante,
                'metadata' => json_encode([
                    'origin' => 'ultron', 'intent' => 'general_info', 'strategy_goal' => 'collect_data', 'next_best_action' => 'ask_discovery',
                    'previous_phase' => 'discovery', 'next_phase' => 'discovery', 'critic' => ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9],
                    'studio' => ['v' => 1, 'env' => 'canary', 'state' => 'applied', 'entry' => null, 'seq' => 1, 'part' => 'canary', 'version' => $version, 'contentHash' => str_repeat('a', 64), 'reason' => null],
                ]),
                'created_at' => $cuando->addSeconds(3), 'updated_at' => $cuando->addSeconds(3),
            ]);
            DB::table('marketing_messages')->insert([
                'conversation_id' => $conv->id, 'direction' => 'outbound', 'sender_type' => 'ai',
                'body' => 'Abrimos de lunes a viernes de 5:00 a 22:00 y sábados de 7:00 a 14:00. El plan mensual cuesta $80.000; si quieres, te agendo una visita para que conozcas la sede.',
                'status' => 'sent', 'created_at' => $cuando->addSeconds(25), 'updated_at' => $cuando->addSeconds(25),
            ]);
        }
    }

    /** Campañas del espejo de $de a $hasta, repartidas entre las tres cuentas. */
    private function crecerEspejo(int $de, int $hasta): void
    {
        $ahora = CarbonImmutable::now();
        for ($i = $de; $i < $hasta; $i++) {
            $cuenta = self::ACCOUNTS[$i % 3];
            $estado = ['ACTIVE', 'PAUSED', 'ARCHIVED', 'ACTIVE'][$i % 4];
            $this->metaCampaign($cuenta, (string) (120200000000000000 + $i), 'Campaña Iron Body '.$i.' — Promo plan trimestral', $estado, $ahora->subMinutes(10 + $i % 30));
        }
    }

    /**
     * Asociaciones activas de $de a $hasta (una por campaña), cada una con dos
     * periodos anteriores ya cerrados (el historial de una campaña real); 3 de
     * cada 4 a una versión y 1 a la línea base.
     *
     * @param  list<StudioVersion>  $versiones
     */
    private function crecerEnlaces(Admin $quien, array $versiones, int $de, int $hasta): void
    {
        $campanas = DB::table('meta_ad_entities')->where('level', 'campaign')->orderBy('entity_id')->get(['ad_account_id', 'entity_id']);
        for ($i = $de; $i < $hasta; $i++) {
            $c = $campanas[$i];
            $base = [
                'ad_account_id' => $c->ad_account_id, 'campaign_id' => $c->entity_id, 'campaign_name_at_link' => 'Campaña Iron Body *** ',
                'created_by_admin_id' => $quien->id, 'created_by_name' => $quien->name, 'created_by_role' => $quien->role,
            ];
            $previas = [];
            for ($k = 0; $k < 2; $k++) {
                $previas[] = StudioCampaignLink::query()->forceCreate($base + [
                    'uuid' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(), 'target_kind' => 'baseline',
                    'version_id' => null, 'version_content_hash' => null, 'status' => 'inactive', 'created_at' => now()->subDays(10 - $k),
                    'deactivated_at' => now()->subDays(9 - $k), 'deactivated_by_admin_id' => $quien->id, 'deactivated_by_name' => $quien->name,
                    'deactivated_by_role' => $quien->role, 'deactivation_reason' => 'Cambio de destino de la campaña',
                ]);
            }
            $v = $i % 4 === 0 ? null : $versiones[$i % count($versiones)];
            $activa = StudioCampaignLink::query()->forceCreate($base + [
                'uuid' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(),
                'target_kind' => $v === null ? 'baseline' : 'version', 'version_id' => $v?->id, 'version_content_hash' => $v?->content_hash,
                'status' => 'active', 'created_at' => now()->subDays(7),
            ]);
            $previas[1]->forceFill(['replaced_by_link_id' => $activa->id])->save();
        }
    }

    /** Una copia de una ejecución terminada, con sus casos (uuid y llave nuevos). */
    private function clonarEjecucion(StudioLabRun $modelo): void
    {
        $copia = $modelo->replicate();
        $copia->forceFill(['uuid' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid()]);
        $copia->save();
        foreach (StudioLabRunCase::query()->where('run_id', $modelo->id)->get() as $caso) {
            $c = $caso->replicate();
            $c->forceFill(['run_id' => $copia->id] + (array_key_exists('uuid', $caso->getAttributes()) ? ['uuid' => (string) Str::uuid()] : []));
            $c->save();
        }
    }

    /** Una entrada de la cadena firmada como la escribe el Studio, sin versión (el lector del motor no la necesita). */
    private function publicaCruda(): void
    {
        $cabeza = DB::table('ultron_studio_publications')->orderByDesc('seq')->first(['id', 'seq']);
        $seq = ($cabeza->seq ?? 0) + 1;
        $uuid = strtolower((string) Str::uuid());
        $parte = static fn (string $v): array => [
            'approvedBy' => 12, 'catalogVersion' => '2026-10-19.1', 'contentHash' => hash('sha256', $v),
            'declared' => ['governance.canaryOnly' => true, 'governance.enabled' => true, 'intent.driver' => 'entorno', 'rules.paymentLinksEnabled' => false],
            'publishedBy' => 7, 'version' => $v,
        ];
        $doc = ['canary' => $parte('b0000000-0000-4000-8000-00000000000b'), 'contract' => UltronPublishedConfig::CONTRACT, 'entry' => $uuid, 'issuedAt' => '2026-11-03T15:04:05Z', 'rest' => $parte('a0000000-0000-4000-8000-00000000000a'), 'seq' => $seq];
        $ordena = function (mixed $v) use (&$ordena): mixed {
            if (! is_array($v)) {
                return $v;
            }
            if (! array_is_list($v)) {
                ksort($v, SORT_STRING);
            }

            return array_map($ordena, $v);
        };
        $json = json_encode($ordena($doc), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        DB::table('ultron_studio_publications')->insert([
            'uuid' => $uuid, 'seq' => $seq, 'client_request_id' => (string) Str::uuid(), 'action' => 'publish', 'version_id' => null,
            'restores_publication_id' => null, 'previous_publication_id' => $cabeza->id ?? null, 'engine_contract' => UltronPublishedConfig::CONTRACT,
            'effective_json' => $json, 'effective_hash' => hash('sha256', $json), 'signature' => UltronPublishedConfig::signatureFor($json),
            'catalog_version' => '2026-10-19.1', 'lab_gate' => json_encode(['mode' => 'waived']), 'acknowledged' => null, 'evidence' => null,
            'reason' => null, 'actor_admin_id' => 7, 'actor_name' => 'Persona de pruebas', 'actor_role' => 'Super Admin', 'created_at' => now(),
        ]);
    }

    /** Una conversación nueva, abierta y con la IA activa; pasa a ser la canaria del motor. */
    private function conversacionNueva(): MarketingConversation
    {
        $n = ++$this->mundoPerf;
        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'meta_user_id' => '573150'.str_pad((string) $n, 6, '0', STR_PAD_LEFT), 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $conv = MarketingConversation::create(['lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false]);
        config()->set('marketing.ultron.canary_conversation_id', $conv->id);

        return $conv;
    }
}
