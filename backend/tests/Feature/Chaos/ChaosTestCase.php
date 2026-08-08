<?php

namespace Tests\Feature\Chaos;

use App\Models\Admin;
use App\Models\Incident;
use App\Models\Member;
use App\Models\Plan;
use App\Models\User;
use App\Services\Wompi\WompiSignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Base de la INYECCIÓN DE FALLOS de la fase F.6.
 *
 * La diferencia con el resto de la suite —incluidos los recorridos E2E de F.3—
 * es la pregunta que se hace. F.3 pregunta «¿funciona cuando todo va bien?».
 * Aquí se pregunta «¿qué queda escrito cuando algo se rompe a media operación?»,
 * que es la pregunta cuya respuesta se descubre normalmente en producción y de
 * la peor forma.
 *
 * Un fallo externo puede terminar de muchas maneras aceptables —reintento,
 * incidente, escalamiento a un humano— pero hay una lista corta de finales
 * inaceptables, y son los que estas pruebas persiguen: perder un mensaje sin
 * dejar rastro, cobrar dos veces, activar dos membresías, emitir dos facturas,
 * o —el más traicionero— dar por bueno algo que nunca ocurrió.
 *
 * **Aislamiento**: base en memoria por prueba, disco falso, y
 * `preventStrayRequests` reventando cualquier petición que se escape. Ningún
 * escenario toca Meta, Wompi, Factus ni OpenAI de verdad.
 */
abstract class ChaosTestCase extends TestCase
{
    use RefreshDatabase;

    /** Secreto del webhook de Meta para esta prueba. No es el de producción. */
    protected const META_SECRET = 'chaos-meta-secret';

    /** Secreto de eventos de Wompi para esta prueba. No es el de producción. */
    protected const WOMPI_EVENTS_SECRET = 'chaos-events-secret';

    protected Admin $admin;

    protected array $saHeaders = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Las banderas arrancan como las de producción HOY. Un escenario que
        // necesite otra cosa tiene que encenderla explícitamente, para que en la
        // prueba se lea qué hizo falta para llegar a ese estado.
        config()->set('meta.enabled', false);
        config()->set('meta.webhook_secret', self::META_SECRET);
        config()->set('meta.whatsapp_phone_number_id', 'PNID-CHAOS');
        config()->set('marketing.agent_enabled', false);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.media.disk', 'whatsapp');
        config()->set('commercial.autonomy_enabled', false);
        config()->set('observability.remediation.enabled', false);

        config()->set('wompi', array_merge((array) config('wompi'), [
            'env' => 'sandbox',
            'api_url' => 'https://sandbox.wompi.co/v1',
            'public_key' => 'pub_test_chaos',
            'private_key' => 'prv_test_chaos',
            'integrity_secret' => 'chaos_integrity',
            'events_secret' => self::WOMPI_EVENTS_SECRET,
            'methods' => ['card' => true, 'pse' => true, 'nequi' => true, 'daviplata' => true],
        ]));

        Storage::fake('whatsapp');

        /*
         * `fake([])` enciende el registro de peticiones SIN dejar ningún stub
         * puesto. Es deliberado: un stub comodín aquí ganaría a los que declara
         * cada escenario —el primero que empareja manda— y las pruebas pasarían
         * hablando con una respuesta vacía en vez de con el fallo inyectado.
         *
         * Sin stub y con `preventStrayRequests`, cualquier llamada que ningún
         * escenario haya previsto revienta la prueba en vez de colarse.
         */
        Http::preventStrayRequests();
        Http::fake([]);

        $this->admin = Admin::create([
            'name' => 'Chaos', 'email' => 'chaos-'.uniqid().'@ironbody.test',
            'password' => 'x', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
        $this->saHeaders = $this->actingAsAdmin($this->admin);
    }

    // ── Inyección de fallos ─────────────────────────────────────────────

    /**
     * Un timeout de verdad, no un 500 disfrazado.
     *
     * La distinción es el corazón de media fase F.6: un 500 dice «no pasó
     * nada», un timeout NO DICE NADA. Guzzle levanta `ConnectionException` en
     * los dos casos que importan (agotado el tiempo, conexión rechazada), y es
     * lo que el código de producción ve.
     */
    protected function timeout(string $message = 'cURL error 28: Operation timed out'): \Closure
    {
        return fn () => throw new ConnectionException($message);
    }

    /** Respuesta HTTP con cabeceras (para `Retry-After` y compañía). */
    protected function httpStatus(int $code, array $body = [], array $headers = []): \Closure
    {
        return fn () => Http::response($body, $code, $headers);
    }

    /**
     * Una secuencia: primero falla, después responde.
     *
     * Es la forma de probar la RECUPERACIÓN, que es la mitad que suele faltar.
     * Comprobar que un fallo se registra bien está a medio camino; lo que
     * importa de verdad es que cuando el proveedor vuelve, la operación termina
     * —y termina UNA vez—.
     */
    protected function sequence(array $responses): \Illuminate\Http\Client\ResponseSequence
    {
        $seq = Http::fakeSequence();
        foreach ($responses as $r) {
            $seq = $r instanceof \Closure ? $seq->pushResponse($r()) : $seq->pushResponse($r);
        }

        return $seq;
    }

    // ── Entradas reales ─────────────────────────────────────────────────

    /** Webhook de Meta FIRMADO, como lo manda Meta. */
    protected function metaWebhook(array $payload)
    {
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->call('POST', '/api/webhooks/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, self::META_SECRET),
            'HTTP_ACCEPT' => 'application/json',
        ], $raw);
    }

    /** Webhook de Wompi con checksum VÁLIDO calculado como lo calcula Wompi. */
    protected function wompiWebhook(array $payload, bool $validSignature = true)
    {
        $payload['signature'] ??= [
            'properties' => ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'],
            'checksum' => '',
        ];
        $payload['timestamp'] ??= 1700000000;
        $payload['environment'] ??= 'test';

        $payload['signature']['checksum'] = $validSignature
            ? strtoupper((string) (new WompiSignatureService(['events_secret' => self::WOMPI_EVENTS_SECRET]))
                ->computeWebhookChecksum($payload, self::WOMPI_EVENTS_SECRET))
            : 'FIRMA-QUE-NO-ES';

        return $this->postJson('/api/webhooks/wompi', $payload);
    }

    /** Un evento `transaction.updated` con la forma exacta de Wompi. */
    protected function wompiTransactionEvent(string $reference, string $status, int $cents, array $over = []): array
    {
        return [
            'event' => 'transaction.updated',
            'data' => ['transaction' => array_merge([
                'id' => 'wompi-chaos-'.substr(md5($reference), 0, 8),
                'status' => $status,
                'reference' => $reference,
                'amount_in_cents' => $cents,
                'currency' => 'COP',
            ], $over)],
        ];
    }

    /** Mensaje entrante de WhatsApp con la forma de Cloud API. */
    protected function inboundMessage(string $from, string $text, array $over = [], ?string $waid = null): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA-CHAOS',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '573143455483', 'phone_number_id' => 'PNID-CHAOS'],
                        'contacts' => [['profile' => ['name' => 'Prospecto Chaos'], 'wa_id' => $from]],
                        'messages' => [array_merge([
                            'from' => $from,
                            'id' => $waid ?? 'wamid.'.uniqid(),
                            'timestamp' => (string) now()->timestamp,
                            'type' => 'text',
                            'text' => ['body' => $text],
                        ], $over)],
                    ],
                ]],
            ]],
        ];
    }

    /** Callback de estado de un saliente (sent/delivered/read/failed). */
    protected function statusCallback(string $metaMessageId, string $status, array $over = []): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA-CHAOS',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '573143455483', 'phone_number_id' => 'PNID-CHAOS'],
                        'statuses' => [array_merge([
                            'id' => $metaMessageId,
                            'status' => $status,
                            'timestamp' => (string) now()->timestamp,
                            'recipient_id' => '573001112233',
                        ], $over)],
                    ],
                ]],
            ]],
        ];
    }

    // ── Dominio ─────────────────────────────────────────────────────────

    protected function plan(string $name = 'Mensual', float $price = 90000, int $days = 30): Plan
    {
        return Plan::create([
            'name' => $name, 'price' => $price, 'duration_days' => $days,
            'active' => true, 'sort_order' => 1,
        ]);
    }

    protected function member(string $doc = '30000001'): Member
    {
        $user = User::create([
            'name' => 'Cliente Chaos', 'email' => 'chaos'.$doc.'-'.uniqid().'@ironbody.test',
            'password' => 'x',
        ]);

        return Member::create([
            'user_id' => $user->id,
            'full_name' => 'Cliente Chaos',
            'document_number' => $doc,
            'phone' => '3001112233',
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    // ── Aserciones transversales de la fase ─────────────────────────────

    /**
     * Un fallo repetido es UN incidente con N ocurrencias, no N incidentes.
     *
     * Es la diferencia entre un panel que se mira y uno que se ignora.
     */
    protected function assertSingleIncident(string $kind, int $occurrences): Incident
    {
        $incidents = Incident::where('kind', $kind)->get();

        $this->assertCount(1, $incidents, sprintf(
            'Se esperaba UN incidente de tipo %s, hay %d. La deduplicación por fingerprint no está agrupando.',
            $kind, $incidents->count(),
        ));

        $this->assertSame($occurrences, (int) $incidents->first()->occurrences, sprintf(
            'El incidente %s debería llevar %d ocurrencias.', $kind, $occurrences,
        ));

        return $incidents->first();
    }

    /** Ninguna evidencia de incidente puede llevar un secreto dentro. */
    protected function assertNoSecretsLeaked(Incident $incident): void
    {
        $blob = json_encode([$incident->evidence, $incident->title, $incident->correlation_ids]);

        foreach ([
            'prv_test_chaos', 'chaos_integrity', self::WOMPI_EVENTS_SECRET,
            self::META_SECRET, 'sk-', 'Bearer ',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $blob, sprintf(
                'El incidente %d filtró un secreto en la evidencia.', $incident->id,
            ));
        }
    }

    /** Con el canal apagado nada quedó realmente entregado por Meta. */
    protected function assertNothingDelivered(): void
    {
        $this->assertSame(0, \App\Models\MarketingMessage::query()
            ->where('direction', 'outbound')
            ->whereNotNull('meta_message_id')
            ->count(), 'Un mensaje quedó marcado como entregado por Meta.');
    }
}
