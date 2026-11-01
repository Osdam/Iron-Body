<?php

namespace App\Services\UltronStudio;

use App\Models\MarketingCall;
use App\Models\MemberAuthChallenge;
use App\Models\TrainerAuthChallenge;
use App\Models\UltronStudio\StudioIntegrationSnapshot;
use App\Services\Otp\OtpCostGuard;
use App\Services\Sms\TwilioVerifyService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use stdClass;

/**
 * TWILIO, VISTO DESDE EL STUDIO (contrato H6 §I5.2): el OTP tal como está
 * (Verify por SMS y el remitente del vínculo socio↔personal), su gasto
 * estimado, las credenciales SIN sus valores, el webhook `usage-trigger`
 * descrito desde el código, Twilio Voice tras su bandera, las llamadas de
 * `marketing_calls` (solo recuentos) y la última sonda. SIN RED.
 *
 * LO EXISTENTE SE CONSERVA TAL CUAL: de `OtpCostGuard` solo se lee la foto del
 * día (`snapshot()`) y de `TwilioVerifyService`, si el modo Verify está activo
 * (`isActive()`); nada que envíe, cuente o gaste. Una prueba estática lo vigila.
 *
 * DATOS PERSONALES: de `marketing_calls` NUNCA se leen `to_phone`, `from_phone`,
 * `transcript`, `summary`, `metadata` ni ids de lead: las consultas nombran
 * solo las columnas que salen. De los retos del OTP, solo recuentos por estado
 * y fecha. De las credenciales, presencia, de qué variable salen, referencias
 * enmascaradas y huellas cortas ({@see StudioIntegrationConfig}).
 */
final class StudioTwilioPanel
{
    public const PER_PAGE_MAX = 50;

    /** Los estados de un reto que cuentan como verificado (§I5.2). */
    public const VERIFIED_STATUSES = [MemberAuthChallenge::STATUS_VERIFIED, MemberAuthChallenge::STATUS_COMPLETED];

    /** El webhook que ya existe, descrito desde el código (`TwilioUsageTriggerController`): fijo. */
    public const WEBHOOKS = [[
        'id' => 'usage_trigger',
        'path' => '/api/webhooks/twilio/usage-trigger',
        'signature' => 'HMAC-SHA1 (X-Twilio-Signature)',
        'idempotency' => 'IdempotencyToken, caché 24 h',
        'throttle' => '60/min',
        'persistence' => 'solo log',
    ]];

    /** Las únicas columnas de `marketing_calls` que lee el Studio. */
    private const CALL_COLUMNS = ['status', 'direction', 'reason', 'scheduled_at', 'started_at', 'ended_at', 'duration_seconds', 'created_at'];

    public function __construct(
        private readonly OtpCostGuard $cost,
        private readonly TwilioVerifyService $verify,
    ) {}

    // ── 43 · GET integrations/twilio ────────────────────────────────────────

    /** @return array<string,mixed> */
    public function panel(): array
    {
        $ultima = StudioIntegrationConfig::latest(StudioIntegrationSnapshot::PROVIDER_TWILIO, StudioIntegrationSnapshot::KIND_PROBE);

        return self::panelFrom(
            $this->summary(),
            StudioIntegrationConfig::twilioFacts(),
            StudioIntegrations::twilioVoice(),
            $this->callCounts(),
            StudioIntegrations::probeEnabled(),
            StudioIntegrationConfig::probeCredential(),
            $ultima === null ? null : StudioTwilioProbe::snapshotDto($ultima),
        );
    }

    /**
     * `GET integrations/twilio` a partir de sus piezas. Pura: la usa también la
     * exportación de fixtures. Las credenciales salen de los `facts` de
     * configuración (presencia, origen, referencias y huellas; nunca valores).
     *
     * @param  array<string,mixed>  $summary  {@see summary()}
     * @param  array<string,mixed>  $facts  {@see StudioIntegrationConfig::twilioFacts()}
     * @param  array{enabled:bool,fromConfigured:bool,dailyCallCap:int}  $voice
     * @param  array<string,mixed>  $calls
     * @param  array<string,mixed>|null  $lastProbe  {@see StudioTwilioProbe::snapshotDto()}
     * @return array<string,mixed>
     */
    public static function panelFrom(array $summary, array $facts, array $voice, array $calls, bool $probeEnabled, string $credential, ?array $lastProbe): array
    {
        return [
            'classification' => $summary['classification'],
            'state' => $summary['state'],
            'reason' => $summary['reason'],
            'otp' => $summary['otp'],
            'cost' => $summary['cost'],
            'credentials' => [
                'accountSid' => $facts['accountSid'],
                'authToken' => $facts['authToken'],
                'aliasesConsistent' => $facts['aliasesConsistent'],
                'verifyServiceSid' => $facts['verifyServiceSid'],
                'apiBase' => $facts['apiBase'],
                'verifyBase' => $facts['verifyBase'],
            ],
            'webhooks' => self::WEBHOOKS,
            'voice' => [
                'enabled' => $voice['enabled'],
                'fromConfigured' => $voice['fromConfigured'],
                'state' => StudioIntegrations::classifyTwilioVoice($voice['fromConfigured'], $voice['enabled']),
            ],
            'calls' => $calls,
            'probe' => [
                'enabled' => $probeEnabled,
                'credential' => $credential,
                'cooldownSeconds' => StudioTwilioProbe::cooldownSeconds(),
                'last' => $lastProbe,
            ],
        ];
    }

    /**
     * Lo que comparten el panel y el bloque `twilio` de `GET integrations/status`:
     * la clasificación (§I7.2), el OTP y el coste.
     *
     * @return array{classification:string,state:string,reason:?string,otp:array<string,mixed>,cost:array<string,mixed>}
     */
    public function summary(): array
    {
        return self::summaryFrom(
            is_string($d = config('otp.driver')) ? $d : '',
            $this->verify->isActive(),
            (bool) config('otp.send_enabled', true),
            $this->cost->snapshot(),
            trim((string) config('otp.twilio.rate_limit_unique_name', '')) !== '',
            (bool) config('otp.legacy_sms_sender_enabled', false),
            filled(config('otp.twilio.from')),
            $this->verifiedCounts(),
        );
    }

    /**
     * @param  array<string,mixed>  $costSnapshot  `OtpCostGuard::snapshot()` (en snake_case; sale en camelCase)
     * @param  array{last24h:int,last7d:int,lastAt:?string}  $verified
     * @return array{classification:string,state:string,reason:?string,otp:array<string,mixed>,cost:array<string,mixed>}
     */
    public static function summaryFrom(string $driver, bool $verifyActive, bool $sendEnabled, array $costSnapshot, bool $rateLimitConfigured, bool $legacySenderEnabled, bool $memberLinkSenderConfigured, array $verified): array
    {
        $coste = [
            'date' => (string) $costSnapshot['date'],
            'estimatedSpentUsd' => (float) $costSnapshot['estimated_spent_usd'],
            'dailySoftLimitUsd' => (float) $costSnapshot['daily_soft_limit_usd'],
            'dailyHardLimitUsd' => (float) $costSnapshot['daily_hard_limit_usd'],
        ];

        return StudioIntegrations::classifyTwilio($driver, $verifyActive, $sendEnabled, $coste['estimatedSpentUsd'], $coste['dailyHardLimitUsd']) + [
            'otp' => [
                'driver' => $driver,
                'verifyActive' => $verifyActive,
                'sendEnabled' => $sendEnabled,
                'rateLimitConfigured' => $rateLimitConfigured,
                'legacySenderEnabled' => $legacySenderEnabled,
                'memberLinkSenderConfigured' => $memberLinkSenderConfigured,
                'verified' => $verified,
            ],
            'cost' => $coste,
        ];
    }

    // ── 44 · GET integrations/twilio/calls ──────────────────────────────────

    /** Las llamadas, de la más reciente a la más antigua, sin ningún dato del prospecto. */
    public function calls(int $perPage): LengthAwarePaginator
    {
        return MarketingCall::query()
            ->select(['id', ...self::CALL_COLUMNS])
            ->selectRaw('CASE WHEN provider_call_sid IS NULL THEN 0 ELSE 1 END AS has_provider_sid')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(static fn (MarketingCall $c): array => [
                'status' => (string) $c->status,
                'direction' => (string) $c->direction,
                'reason' => $c->reason,
                'scheduledAt' => self::iso($c->scheduled_at),
                'startedAt' => self::iso($c->started_at),
                'endedAt' => self::iso($c->ended_at),
                'durationSeconds' => $c->duration_seconds === null ? null : (int) $c->duration_seconds,
                'hasProviderSid' => (int) $c->getAttribute('has_provider_sid') === 1,
                'createdAt' => self::iso($c->created_at),
            ]);
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /**
     * Retos del OTP (socios y personal) verificados en 24 h y en 7 días, y el
     * último. Solo recuentos: ni destinos ni dispositivos.
     *
     * @return array{last24h:int,last7d:int,lastAt:?string}
     */
    private function verifiedCounts(): array
    {
        $ahora = CarbonImmutable::now();
        $dia = $ahora->subDay();
        $semana = $ahora->subDays(7);
        $total24 = 0;
        $total7 = 0;
        $ultimo = null;
        foreach ([MemberAuthChallenge::query(), TrainerAuthChallenge::query()] as $consulta) {
            $fila = $consulta->toBase()
                ->whereIn('status', self::VERIFIED_STATUSES)
                ->selectRaw('sum(case when updated_at >= ? then 1 else 0 end) as d', [$dia])
                ->selectRaw('sum(case when updated_at >= ? then 1 else 0 end) as s', [$semana])
                ->selectRaw('max(updated_at) as ultimo')
                ->first();
            $total24 += (int) ($fila->d ?? 0);
            $total7 += (int) ($fila->s ?? 0);
            if ($fila !== null && $fila->ultimo !== null) {
                $en = CarbonImmutable::parse((string) $fila->ultimo);
                $ultimo = $ultimo === null || $en->greaterThan($ultimo) ? $en : $ultimo;
            }
        }

        return ['last24h' => $total24, 'last7d' => $total7, 'lastAt' => $ultimo?->toIso8601String()];
    }

    /**
     * Recuentos de `marketing_calls`: total, con SID del proveedor, por estado y
     * por dirección, y la última creada. Tres consultas sin columnas personales.
     *
     * @return array{total:int,withProviderSid:int,byStatus:array<string,int>|stdClass,byDirection:array<string,int>|stdClass,lastCreatedAt:?string}
     */
    public function callCounts(): array
    {
        $resumen = MarketingCall::query()->toBase()
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when provider_call_sid is null then 0 else 1 end) as con_sid')
            ->selectRaw('max(created_at) as ultima')
            ->first();
        $porEstado = MarketingCall::query()->toBase()->selectRaw('status as k, count(*) as n')->groupBy('status')->orderBy('status')->pluck('n', 'k')->map(static fn (mixed $n): int => (int) $n)->all();
        $porDireccion = MarketingCall::query()->toBase()->selectRaw('direction as k, count(*) as n')->groupBy('direction')->orderBy('direction')->pluck('n', 'k')->map(static fn (mixed $n): int => (int) $n)->all();

        return [
            'total' => (int) ($resumen->total ?? 0),
            'withProviderSid' => (int) ($resumen->con_sid ?? 0),
            // Un mapa vacío sale como `{}`, no `[]` (aquí no hay huella canónica).
            'byStatus' => $porEstado === [] ? new stdClass : $porEstado,
            'byDirection' => $porDireccion === [] ? new stdClass : $porDireccion,
            'lastCreatedAt' => ($resumen->ultima ?? null) !== null ? CarbonImmutable::parse((string) $resumen->ultima)->toIso8601String() : null,
        ];
    }

    private static function iso(mixed $valor): ?string
    {
        return $valor instanceof \DateTimeInterface ? CarbonImmutable::instance($valor)->toIso8601String() : null;
    }
}
