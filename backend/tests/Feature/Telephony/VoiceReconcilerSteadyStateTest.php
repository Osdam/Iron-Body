<?php

namespace Tests\Feature\Telephony;

use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Services\Telephony\VoiceReconciler;
use App\Services\Voice\Dto\CarrierCall;
use App\Services\Voice\Dto\CarrierCallsPage;
use App\Services\Voice\Dto\CarrierResult;
use App\Services\Voice\NullVoiceAgentDeployer;
use App\Services\Voice\TelephonyCarrier;
use DateTimeInterface;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * LA CONCILIACIÓN EN RÉGIMEN (PERF8 P8-7; B8-c): cuando Twilio vuelve a
 * enseñar llamadas ya conciliadas, la pasada no cambia nada, y tampoco INTENTA
 * escribir lo que ya está. Antes, cada llamada conocida intentaba otra vez su
 * `reconciled` y su `cost_recorded` (y cada tramo su `cost_recorded`), dos
 * INSERT que el UNIQUE rechazaba con su punto de guardado, su excepción y su
 * vuelta atrás: perfilado, el 80 % del tiempo de la pasada. Ahora la fila se
 * lee con dos marcas de esos eventos en la MISMA sentencia que ya la bloquea.
 *
 * El resultado es el de antes: el resumen, los eventos y los costes, pasada a
 * pasada; y lo que cambia entre pasadas (un estado final nuevo, un precio que
 * llega tarde) se sigue escribiendo una vez. Sin red: el transportista es un
 * doble en memoria (el real está en `VoiceReconcilerTest`).
 */
class VoiceReconcilerSteadyStateTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    /** @var list<CarrierCall> */
    private array $page = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->voiceWorld(['voice.limits.daily_budget_usd' => '1000']);
    }

    public function test_en_regimen_no_cambia_nada_y_no_intenta_ningun_insert_que_vaya_a_fallar(): void
    {
        foreach ([1, 2, 3] as $n) {
            $this->voiceCall('outbound', 'completed', ['provider_call_sid' => self::sid($n), 'remote' => '+57300001000'.$n]);
        }
        $this->page = [
            self::carrierCall(1, 'completed', '-0.07540'),
            self::carrierCall(2, 'busy', '-0.01000'),
            self::carrierCall(3, 'completed', '-0.03770'),
            // Un tramo de transferencia de la 1: su coste va a la madre.
            self::carrierCall(9, 'completed', '-0.14000', parent: 1),
        ];

        $primera = $this->pass();
        $this->assertSame([3, 1, 0], [$primera['known'], $primera['legs'], $primera['unknown']]);
        $eventos = $this->events();
        $costes = $this->costs();
        $this->assertCount(7, $eventos, '3 reconciled + 3 cost_recorded + el del tramo');
        $this->assertSame(75400 + 140000, $costes[self::sid(1)], 'la madre suma su precio y el del tramo');

        // Régimen: el mismo resumen, nada nuevo, y ninguna vuelta atrás (ningún INSERT condenado).
        foreach ([1, 2] as $vuelta) {
            [$resumen, $transacciones, $vueltasAtras] = $this->countingTransactions(fn (): array => $this->pass());
            $this->assertSame($primera, $resumen, "pasada en régimen {$vuelta}: el mismo resumen");
            $this->assertSame($eventos, $this->events(), 'ningún evento nuevo');
            $this->assertSame($costes, $this->costs(), 'los mismos costes');
            $this->assertSame(0, $vueltasAtras, 'ningún INSERT que el UNIQUE rechace: ni punto de guardado deshecho ni excepción');
            $this->assertSame(count($this->page), $transacciones, 'una transacción por llamada y ningún punto de guardado dentro');
        }
    }

    public function test_lo_que_cambia_entre_pasadas_se_escribe_una_vez(): void
    {
        $this->voiceCall('outbound', 'dialing', ['provider_call_sid' => self::sid(4), 'remote' => '+573000010004']);
        $this->voiceCall('outbound', 'completed', ['provider_call_sid' => self::sid(5), 'remote' => '+573000010005']);

        // 1.ª: la 4 sigue en curso (sin reconciled) y la 5 aún sin tarificar (sin coste).
        $this->page = [self::carrierCall(4, 'in-progress', null), self::carrierCall(5, 'completed', null)];
        $this->pass();
        $this->assertSame(['reconciled|'.self::sid(5).':completed'], $this->eventKeys());

        // 2.ª: la 4 termina y a la 5 le llega su precio: una vez cada cosa.
        $this->page = [self::carrierCall(4, 'completed', '-0.02000'), self::carrierCall(5, 'completed', '-0.05000')];
        $this->pass();
        $this->pass();
        $this->assertSame([
            'cost_recorded|cost:'.self::sid(4),
            'cost_recorded|cost:'.self::sid(5),
            'reconciled|'.self::sid(4).':completed',
            'reconciled|'.self::sid(5).':completed',
        ], $this->eventKeys());
        $this->assertSame('completed', VoiceCall::query()->where('provider_call_sid', self::sid(4))->value('status'));
        $this->assertSame([self::sid(4) => 20000, self::sid(5) => 50000], $this->costs());
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function pass(): array
    {
        $pagina = $this->page;
        $transportista = new class($pagina) implements TelephonyCarrier
        {
            /** @param  list<CarrierCall>  $calls */
            public function __construct(private readonly array $calls) {}

            public function hangup(string $callSid): CarrierResult
            {
                throw new LogicException('la conciliación no cuelga');
            }

            public function listCalls(DateTimeInterface $since, ?string $pageToken): CarrierCallsPage
            {
                return new CarrierCallsPage(CarrierCallsPage::OUTCOME_LISTED, $this->calls, null, false, 200);
            }
        };

        return app(VoiceReconciler::class)->run($transportista, new NullVoiceAgentDeployer, now()->subDay());
    }

    /**
     * @param  \Closure(): array<string, mixed>  $fn
     * @return array{0: array<string, mixed>, 1: int, 2: int} lo que devuelve, transacciones (y puntos de guardado) abiertas y deshechas
     */
    private function countingTransactions(\Closure $fn): array
    {
        $abiertas = 0;
        $deshechas = 0;
        $contar = true;
        Event::listen(TransactionBeginning::class, function () use (&$abiertas, &$contar): void {
            $abiertas += $contar ? 1 : 0;
        });
        Event::listen(TransactionRolledBack::class, function () use (&$deshechas, &$contar): void {
            $deshechas += $contar ? 1 : 0;
        });
        try {
            $resultado = $fn();
        } finally {
            $contar = false;
        }

        return [$resultado, $abiertas, $deshechas];
    }

    /** @return list<array{kind: string, key: ?string, call: ?int, codes: mixed}> */
    private function events(): array
    {
        return VoiceCallEvent::query()->orderBy('id')->get(['kind', 'provider_event_key', 'call_id', 'codes'])
            ->map(static fn (VoiceCallEvent $e): array => ['kind' => (string) $e->kind, 'key' => $e->provider_event_key, 'call' => $e->call_id, 'codes' => $e->codes])->all();
    }

    /** @return list<string> */
    private function eventKeys(): array
    {
        $claves = VoiceCallEvent::query()->whereIn('kind', ['reconciled', 'cost_recorded'])->get(['kind', 'provider_event_key'])
            ->map(static fn (VoiceCallEvent $e): string => $e->kind.'|'.$e->provider_event_key)->all();
        sort($claves);

        return $claves;
    }

    /** @return array<string, int|null> SID → carrier_cost_micros */
    private function costs(): array
    {
        return VoiceCall::query()->whereNotNull('provider_call_sid')->orderBy('provider_call_sid')->pluck('carrier_cost_micros', 'provider_call_sid')
            ->map(static fn ($v): ?int => $v === null ? null : (int) $v)->all();
    }

    private static function sid(int $n): string
    {
        return 'CA'.str_pad(dechex(0xC8C0 + $n), 32, '0', STR_PAD_LEFT);
    }

    private static function carrierCall(int $n, string $status, ?string $price, ?int $parent = null): CarrierCall
    {
        return new CarrierCall(self::sid($n), $parent === null ? null : self::sid($parent), $status, $parent === null ? 'outbound-api' : 'outbound-dial',
            '2026-11-08T10:00:00Z', in_array($status, ['completed', 'busy'], true) ? '2026-11-08T10:02:00Z' : null, $status === 'completed' ? 120 : null,
            $price, $price === null ? null : 'USD', '0001', '0090');
    }
}
