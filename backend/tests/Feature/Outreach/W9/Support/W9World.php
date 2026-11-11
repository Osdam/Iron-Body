<?php

namespace Tests\Feature\Outreach\W9\Support;

use App\Services\Outreach\Run\Channels\AssistedChannel;
use App\Services\Outreach\Run\Channels\SimulatedChannel;
use App\Services\Outreach\Run\Channels\VoiceStrategyChannel;
use App\Services\Outreach\Run\Channels\WhatsappTemplateChannel;
use App\Services\Outreach\Run\LadderInputBuilder;
use App\Services\Outreach\Run\OutreachDispatcher;
use App\Services\Outreach\Run\OutreachPlanner;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Outreach\Run\Support\RunWorld;

/**
 * El mundo de las pruebas de W9: el de B9-run ({@see RunWorld}: gobierno con
 * cadena firmada de verdad, socios y prospectos sin datos reales) más una
 * plantilla de WhatsApp APROBADA en el Studio, su comprobación de Meta, las
 * banderas de WhatsApp y un Graph FALSO (solo Meta; todo lo demás, con
 * `Http::preventStrayRequests()`, rompe la prueba).
 *
 * LA CONSTANTE `OutreachSendGate::SENDS_ENABLED_IN_CODE` NO SE GIRA NUNCA: la
 * «puerta abierta» de estas pruebas es un DOBLE inyectado en el canal y en la
 * entrada de la escalera ({@see openPlanner()}, {@see openDispatcher()}); el
 * resto del código pregunta a la constante, que sigue en `false`.
 */
trait W9World
{
    use RunWorld;

    private const PHONE_ID = '1098765';

    private const WEBHOOK_SECRET = 'w9-webhook-secret';

    private const META_NAME = 'renovacion_w9';

    private const BODY = 'Hola {{1}}, tu plan vence el {{2}}. Responde BAJA para no recibir más mensajes.';

    /** Graph falso: cada envío, un wamid distinto (Meta nunca repite y la columna es única). */
    protected function metaOn(): void
    {
        config([
            'meta.enabled' => true,
            'meta.access_token' => 'token-de-prueba-w9',
            'meta.app_secret' => 'secreto-de-prueba-w9',
            'meta.webhook_secret' => self::WEBHOOK_SECRET,
            'meta.whatsapp_phone_number_id' => self::PHONE_ID,
        ]);
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.W9.'.Str::random(12)]]], 200)]);
    }

    /** La ejecución en vivo con WhatsApp encendido, con cupo, presupuesto y precio. @param  array<string, mixed>  $extra */
    protected function whatsappLive(array $extra = []): void
    {
        $this->runFlags('live', array_merge([
            'outreach.whatsapp.enabled' => true,
            'outreach.whatsapp.daily_cap' => 10,
            'outreach.whatsapp.daily_budget_usd' => '5',
            'outreach.whatsapp.price_marketing_usd' => '0.05',
            'outreach.whatsapp.price_utility_usd' => '0.01',
        ], $extra));
    }

    /** La plantilla de WhatsApp del Studio, APROBADA a cuatro ojos (Ana la crea, Beto la aprueba). @param  array<string, mixed>  $content */
    protected function whatsappTemplate(array $content = [], string $purpose = 'commercial'): int
    {
        $this->threePeople();

        return $this->approvedTemplate([
            'channel' => 'whatsapp',
            'purpose' => $purpose,
            'content' => array_merge([
                'bodyPreview' => self::BODY,
                'category' => $purpose === 'commercial' ? 'marketing' : 'utility',
                'language' => 'es',
                'metaName' => self::META_NAME,
                'optOutLine' => true,
                'variables' => ['first_name', 'membership_end_date_text'],
            ], $content),
        ]);
    }

    /**
     * La comprobación de Meta de una plantilla (lo que registraría el
     * propietario): por defecto, la que casa con el contenido del Studio.
     *
     * @param  array<string, mixed>  $o
     */
    protected function metaCheck(int $templateId, array $o = []): int
    {
        $t = DB::table('ultron_studio_message_templates')->where('id', $templateId)->first(['content_json', 'content_hash']);
        $c = json_decode((string) $t->content_json, true);

        return (int) DB::table('ultron_studio_template_meta_checks')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => (string) Str::uuid(),
            'template_id' => $templateId,
            'template_content_hash' => (string) $t->content_hash,
            'meta_name' => (string) $c['metaName'],
            'language' => (string) $c['language'],
            'category' => (string) $c['category'],
            'meta_body_hash' => hash('sha256', (string) $c['bodyPreview']),
            'meta_template_id' => '7700112233',
            'status' => 'APPROVED',
            'checked_at' => now()->subHour(),
            'recorded_by_admin_id' => 1,
            'recorded_by_name' => 'Ana',
            'created_at' => now(),
        ], $o));
    }

    /** La estrategia viva (canario) con un paso de WhatsApp por plantilla. @param  array<string, mixed>  $override @return array<string, mixed> */
    protected function whatsappStrategy(int $templateId, array $override = [], array $strategy = []): array
    {
        return $this->liveStrategy($this->validContent($templateId, $override), 'CANARY', $strategy);
    }

    /** El doble de la puerta: la constante «abierta» SOLO en esta prueba. */
    protected static function gateOpen(): \Closure
    {
        return static fn (): bool => true;
    }

    protected function openPlanner(): OutreachPlanner
    {
        return new OutreachPlanner(inputs: new LadderInputBuilder(sendsEnabledInCode: self::gateOpen()));
    }

    protected function openDispatcher(): OutreachDispatcher
    {
        return new OutreachDispatcher(
            inputs: new LadderInputBuilder(sendsEnabledInCode: self::gateOpen()),
            channels: [new SimulatedChannel, new AssistedChannel, new WhatsappTemplateChannel(self::gateOpen()), new VoiceStrategyChannel],
        );
    }

    /**
     * Todo encendido con la puerta abierta solo aquí: plantilla aprobada (y
     * por Meta), estrategia viva, un socio que califica con su prospecto de
     * WhatsApp autorizado y su trabajo de WhatsApp PLANIFICADO en vivo (con su
     * reserva). El reloj queda 15 min después (el desfase).
     *
     * @return array{template: int, live: array<string, mixed>, member: array<string, mixed>, job: object}
     */
    protected function plannedWhatsappJob(DateTimeImmutable $nowUtc, bool $metaCheck = true): array
    {
        $this->whatsappLive();
        $this->metaOn();
        $plantilla = $this->whatsappTemplate();
        if ($metaCheck) {
            $this->metaCheck($plantilla);
        }
        $vivo = $this->whatsappStrategy($plantilla);
        $socio = $this->qualifyingMember($nowUtc, 5, '3001234567');
        $planner = $this->openPlanner();
        $planner->sweep($nowUtc, true);
        $r = $planner->planDue($nowUtc);
        if (($r['planned'] ?? 0) !== 1) {
            throw new \RuntimeException('No se planificó el trabajo de WhatsApp de la prueba: '.json_encode($r));
        }
        Carbon::setTestNow(Carbon::instance($nowUtc)->addMinutes(15));

        return ['template' => $plantilla, 'live' => $vivo, 'member' => $socio, 'job' => DB::table('outreach_jobs')->first()];
    }

    /** Lo que Meta dice de un saliente, por el webhook de SIEMPRE (firma, evento, job): solo estados. */
    protected function metaStatusWebhook(string $wamid, string $status, ?int $errorCode = null): void
    {
        $estado = ['id' => $wamid, 'status' => $status, 'timestamp' => (string) now()->timestamp, 'recipient_id' => '573001234567'];
        if ($errorCode !== null) {
            $estado['errors'] = [['code' => $errorCode, 'title' => 'Error de prueba']];
        }
        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['phone_number_id' => self::PHONE_ID, 'display_phone_number' => '570000000'],
            'statuses' => [$estado],
        ]]]]]];
        $raw = (string) json_encode($payload);
        $this->call('POST', '/api/webhooks/meta', [], [], [], [
            'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $raw, self::WEBHOOK_SECRET),
            'CONTENT_TYPE' => 'application/json',
        ], $raw)->assertOk();
    }
}
