<?php

namespace App\Services\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioCanaryNumber;
use App\Support\Privacy\PhoneFingerprint;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * EL REGISTRO DE NÚMEROS CANARIOS (contrato H6 §I4): números de prueba, con el
 * consentimiento de su titular, por canal (WhatsApp o voz). ES REGISTRO: el
 * motor no lo lee en este hito y ULTRON sigue atendiendo solo la conversación
 * canaria configurada en el servidor. Lo compara con ella
 * {@see StudioCanaryCoherence}.
 *
 * LA MÁQUINA DE ESTADOS (§I4.4): registrar → `pending`; autorizar (OTRA
 * persona, `ultron.canary_authorize`) → `authorized`; activar (una persona que
 * NO registró el número, `ultron.canary_manage`) → `active`; desactivar →
 * `authorized`; revocar → `revoked` (terminal, borra el cifrado en la MISMA
 * sentencia). Cada transición es un UPDATE condicionado al estado esperado en
 * la misma transacción que su evento del libro; si no toca ninguna fila, se
 * relee y es 409 `invalid_transition` con el estado de ahora.
 *
 * CUATRO OJOS POR IDENTIDAD ({@see StudioAccess::isCanaryRegistrant()}, que
 * compara ids, nunca permisos), y además en la base (`uscn_four_eyes_chk` y
 * `uscn_activation_four_eyes_chk`). El intento queda en el libro
 * (`canary_number.four_eyes_denied`, deduplicado 10 minutos).
 *
 * TOPES CON CERROJO (§I4.8): vivos por canal al registrar y activos por canal
 * al activar, contados tras `SET TRANSACTION ISOLATION LEVEL READ COMMITTED` y
 * un cerrojo consultivo por canal (PostgreSQL); SQLite escribe en serie.
 *
 * DATOS PERSONALES (§I13): el teléfono llega en el cuerpo del registro, se
 * valida ({@see StudioCanaryPhone}), se convierte en huella y, solo en voz, se
 * cifra; después se hace `unset`. Nunca sale: los DTO llevan el número
 * enmascarado, y el libro no lleva teléfonos, sus dígitos, su huella ni el alias.
 */
final class StudioCanaryNumbers
{
    public const PER_PAGE_MAX = 50;

    public const REASON_MIN = 5;

    public const REASON_MAX = 500;

    /** Las etiquetas de estado: las da el servidor y el CRM las pinta tal cual (§I4.9). */
    public const STATUS_LABELS = [
        StudioCanaryNumber::STATUS_PENDING => 'Pendiente de autorizar (registro)',
        StudioCanaryNumber::STATUS_AUTHORIZED => 'Autorizado (registro)',
        StudioCanaryNumber::STATUS_ACTIVE => 'Activo (registro)',
        StudioCanaryNumber::STATUS_REVOKED => 'Revocado',
    ];

    /** Las cinco acciones de un número, en su orden, y desde qué estados se admite cada una. */
    public const ALLOWED_FROM = [
        'authorize' => [StudioCanaryNumber::STATUS_PENDING],
        'activate' => [StudioCanaryNumber::STATUS_AUTHORIZED],
        'deactivate' => [StudioCanaryNumber::STATUS_ACTIVE],
        'revoke' => StudioCanaryNumber::LIVE,
        'expectation' => StudioCanaryNumber::LIVE,
    ];

    /** Qué llave pide cada acción (además de `ultron.view`). */
    public const ACTION_PERMISSIONS = [
        'authorize' => StudioAccess::CANARY_AUTHORIZE,
        'activate' => StudioAccess::CANARY_MANAGE,
        'deactivate' => StudioAccess::CANARY_MANAGE,
        'revoke' => StudioAccess::CANARY_MANAGE,
        'expectation' => StudioAccess::CANARY_MANAGE,
    ];

    /** Los motivos de `actions`, en su precedencia (§I4.9). */
    public const ACTION_REASONS = ['forbidden', 'writes_disabled', 'invalid_transition', 'four_eyes_required', 'active_limit'];

    private const ACTION_LABELS = [
        'authorize' => 'autorizar',
        'activate' => 'activar',
        'deactivate' => 'desactivar',
        'revoke' => 'revocar',
        'expectation' => 'fijar la versión esperada',
    ];

    private const CHANNEL_LABELS = ['whatsapp' => 'WhatsApp', 'voice' => 'voz'];

    public function __construct(
        private readonly StudioAudit $audit,
        private readonly StudioCanaryCoherence $coherence,
    ) {}

    // ── 42 · GET integrations/canaries ──────────────────────────────────────

    /**
     * La coherencia con el canario del motor, los topes y una página de
     * números (por defecto, todos menos los revocados), de los más recientes a
     * los más antiguos, con lo que puede hacer quien mira con cada uno.
     *
     * @param  array{status?:?string,channel?:?string}  $filters
     * @return array{coherence:array<string,mixed>,limits:array<string,mixed>,numbers:LengthAwarePaginator}
     */
    public function page(StudioAccess $access, array $filters, int $perPage): array
    {
        $estado = $filters['status'] ?? null;
        $consulta = StudioCanaryNumber::query()
            ->with('expectedVersion:id,uuid,label,status,content,content_hash')
            ->when($filters['channel'] ?? null, fn ($q, string $canal) => $q->where('channel', $canal))
            ->orderByDesc('id');
        if ($estado === null) {
            $consulta->where('status', '<>', StudioCanaryNumber::STATUS_REVOKED);
        } elseif ($estado !== 'all') {
            $consulta->where('status', $estado);
        }

        $coherencia = $this->coherence->read();
        $pagina = $consulta->paginate($perPage);

        return [
            'coherence' => StudioCanaryCoherence::publicBlock($coherencia),
            'limits' => [
                'maxActivePerChannel' => StudioIntegrations::canaryMaxActive(),
                'maxLivePerChannel' => StudioIntegrations::canaryMaxLive(),
                'countryCodes' => StudioIntegrations::countryCodes(),
                'writesEnabled' => StudioIntegrations::writesEnabled(),
            ],
            'numbers' => $pagina->setCollection(collect($this->dtos($access, $pagina->getCollection(), $coherencia))),
        ];
    }

    // ── 49 · POST integrations/canaries ─────────────────────────────────────

    /**
     * Registrar un número (→ `pending`). `$phone` llega ya validado por el
     * controlador; aquí se vuelve a interpretar, se convierte en huella (y, en
     * voz, en cifrado) y se olvida.
     *
     * @return array{number:array<string,mixed>,replayed:bool}
     */
    public function register(StudioAccess $access, Request $request, string $clientRequestId, string $channel, #[\SensitiveParameter] string $phone, #[\SensitiveParameter] string $alias, ?string $expectedVersionUuid): array
    {
        $access->require(StudioAccess::CANARY_MANAGE);
        if (! StudioIntegrations::writesEnabled()) {
            throw UltronStudioException::integrationsWritesDisabled();
        }

        $leido = StudioCanaryPhone::parse($phone, $channel, StudioIntegrations::countryCodes());
        unset($phone);
        if ($leido['ok'] !== true) {
            // El controlador ya lo validó: llegar aquí es un error de código.
            throw new LogicException('ultron_studio: un teléfono sin validar llegó al registro de canarios.');
        }
        $huella = PhoneFingerprint::of($leido['e164']);
        $cifrar = $channel === StudioCanaryPhone::CHANNEL_VOICE ? $leido['e164'] : null;
        $ultimos = $leido['last4'];
        $pais = $leido['countryCode'];
        unset($leido);

        $esperada = $expectedVersionUuid === null ? null : strtolower($expectedVersionUuid);
        $cuerpo = ['channel' => $channel, 'phoneHash' => $huella, 'alias' => $alias, 'expectedVersionUuid' => $esperada];
        if ($previa = $this->registerReplay($access, $clientRequestId, $cuerpo)) {
            return ['number' => $this->dtoOf($access, $previa), 'replayed' => true];
        }

        try {
            return $this->registerGated($access, $request, $clientRequestId, $channel, $huella, $ultimos, $pais, $cifrar, $alias, $esperada);
        } catch (UltronStudioException $e) {
            // H-1 del Hito 5: la gemela pudo confirmarse mientras tanto.
            if ($previa = $this->registerReplay($access, $clientRequestId, $cuerpo)) {
                return ['number' => $this->dtoOf($access, $previa), 'replayed' => true];
            }

            throw $e;
        }
    }

    /** @return array{number:array<string,mixed>,replayed:bool} */
    private function registerGated(StudioAccess $access, Request $request, string $clientRequestId, string $channel, #[\SensitiveParameter] string $huella, #[\SensitiveParameter] string $ultimos, string $pais, #[\SensitiveParameter] ?string $cifrar, #[\SensitiveParameter] string $alias, ?string $esperada): array
    {
        $version = $esperada === null ? null : StudioCampaignLinks::linkableVersion($esperada);
        $cuerpo = ['channel' => $channel, 'phoneHash' => $huella, 'alias' => $alias, 'expectedVersionUuid' => $esperada];

        try {
            $numero = DB::transaction(function () use ($access, $request, $clientRequestId, $channel, $huella, $ultimos, $pais, $cifrar, $alias, $version): StudioCanaryNumber {
                StudioIntegrations::lock('ultron_studio_canary_live:'.$channel);
                $max = StudioIntegrations::canaryMaxLive();
                $vivos = StudioCanaryNumber::query()->where('channel', $channel)->whereIn('status', StudioCanaryNumber::LIVE)->count();
                if ($vivos >= $max) {
                    throw UltronStudioException::canaryLiveLimit($max, $channel);
                }
                // H-2 del H6: el mismo teléfono ya vivo en el canal se contesta
                // aquí, bajo el cerrojo de vivos, y no con el 23505 de
                // `uscn_one_live`, cuyo DETAIL lleva la huella al log del
                // servidor. El 23505 queda para la carrera real: una fila sin
                // confirmar que no pasó por este cerrojo. El mismo predicado que
                // el índice parcial; detrás del tope, para que el 409 que gana
                // sea el de siempre.
                if (StudioCanaryNumber::query()->where('channel', $channel)->where('phone_hash', $huella)->where('status', '<>', StudioCanaryNumber::STATUS_REVOKED)->exists()) {
                    throw UltronStudioException::canaryNumberExists();
                }

                $ahora = now();
                $yo = $access->snapshot();
                $numero = StudioCanaryNumber::query()->forceCreate([
                    'client_request_id' => $clientRequestId,
                    'channel' => $channel,
                    'alias' => $alias,
                    'phone_hash' => $huella,
                    'phone_last4' => $ultimos,
                    'country_code' => $pais,
                    // Solo en voz, y con el cast `encrypted` (APP_KEY); se borra al revocar.
                    'phone_encrypted' => $cifrar,
                    'status' => StudioCanaryNumber::STATUS_PENDING,
                    'expected_version_id' => $version?->id,
                    'consent_attested' => true,
                    'registered_by_admin_id' => $yo['id'],
                    'registered_by_name' => $yo['name'],
                    'registered_by_role' => $yo['role'],
                    'status_changed_at' => $ahora,
                ]);

                $this->audit->record($request, $access->actor, 'canary_number.registered', StudioAuditEvent::SUBJECT_CANARY_NUMBER, $numero->uuid,
                    'Número canario registrado ('.self::CHANNEL_LABELS[$channel].')', [
                        'toStatus' => StudioCanaryNumber::STATUS_PENDING,
                        'diff' => ['channel' => $channel, 'expectedVersionUuid' => $version?->uuid],
                    ]);

                return $numero;
            });
        } catch (UniqueConstraintViolationException $e) {
            // La misma llave dos veces a la vez, o el mismo teléfono ya vivo en
            // el canal (`uscn_one_live`): FUERA de la transacción abortada.
            if ($previa = $this->registerReplay($access, $clientRequestId, $cuerpo)) {
                return ['number' => $this->dtoOf($access, $previa), 'replayed' => true];
            }

            throw UltronStudioException::canaryNumberExists();
        } catch (PDOException $e) {
            // N-1 (Enmienda 3): otro fallo de la base en el alta (40P01, 57014,
            // conexión caída…) llevaría a `laravel.log` el SQL con sus valores:
            // huella, 4 dígitos, alias y cifrado. Se relanza SIN el original y
            // con solo la clase y el SQLSTATE. `PDOException` cubre también la
            // `DeadlockException` que Laravel lanza en una transacción anidada.
            $origen = $e instanceof QueryException ? $e : ($e->getPrevious() instanceof QueryException ? $e->getPrevious() : $e);

            throw new RuntimeException('ultron_studio: el registro de un número canario falló en la base ('.class_basename($e).', SQLSTATE '.$origen->getCode().').');
        }
        unset($cifrar);

        return ['number' => $this->dtoOf($access, $this->findOrFail($numero->id)), 'replayed' => false];
    }

    // ── 50–54 · las transiciones ────────────────────────────────────────────

    /** @return array{number:array<string,mixed>} */
    public function authorize(StudioAccess $access, Request $request, string $uuid): array
    {
        $numero = $this->gate($access, 'authorize', $uuid);
        $this->assertFourEyes($access, $request, $numero, 'authorize');
        $this->assertStatus($numero, 'authorize');

        DB::transaction(function () use ($access, $request, $numero): void {
            $yo = $access->snapshot();
            $ahora = now();
            $this->move($numero, 'authorize', [
                'status' => StudioCanaryNumber::STATUS_AUTHORIZED,
                'authorized_by_admin_id' => $yo['id'],
                'authorized_by_name' => $yo['name'],
                'authorized_by_role' => $yo['role'],
                'authorized_at' => $ahora,
                'status_changed_at' => $ahora,
            ]);
            $this->audit->record($request, $access->actor, 'canary_number.authorized', StudioAuditEvent::SUBJECT_CANARY_NUMBER, $numero->uuid,
                'Número canario autorizado', ['fromStatus' => StudioCanaryNumber::STATUS_PENDING, 'toStatus' => StudioCanaryNumber::STATUS_AUTHORIZED, 'diff' => []]);
        });

        return ['number' => $this->dtoOf($access, $this->findOrFail($numero->id))];
    }

    /** @return array{number:array<string,mixed>} */
    public function activate(StudioAccess $access, Request $request, string $uuid): array
    {
        $numero = $this->gate($access, 'activate', $uuid);
        $this->assertFourEyes($access, $request, $numero, 'activate');
        $this->assertStatus($numero, 'activate');

        DB::transaction(function () use ($access, $request, $numero): void {
            StudioIntegrations::lock('ultron_studio_canary_active:'.$numero->channel);
            $max = StudioIntegrations::canaryMaxActive();
            $activos = StudioCanaryNumber::query()->where('channel', $numero->channel)->where('status', StudioCanaryNumber::STATUS_ACTIVE)->count();
            if ($activos >= $max) {
                throw UltronStudioException::canaryActiveLimit($max, (string) $numero->channel);
            }

            $yo = $access->snapshot();
            $ahora = now();
            $this->move($numero, 'activate', [
                'status' => StudioCanaryNumber::STATUS_ACTIVE,
                'activated_by_admin_id' => $yo['id'],
                'activated_by_name' => $yo['name'],
                'activated_by_role' => $yo['role'],
                'activated_at' => $ahora,
                'status_changed_at' => $ahora,
            ]);
            $this->audit->record($request, $access->actor, 'canary_number.activated', StudioAuditEvent::SUBJECT_CANARY_NUMBER, $numero->uuid,
                'Número canario activado (registro)', ['fromStatus' => StudioCanaryNumber::STATUS_AUTHORIZED, 'toStatus' => StudioCanaryNumber::STATUS_ACTIVE, 'diff' => []]);
        });

        return ['number' => $this->dtoOf($access, $this->findOrFail($numero->id))];
    }

    /** @return array{number:array<string,mixed>} */
    public function deactivate(StudioAccess $access, Request $request, string $uuid, string $reason): array
    {
        $numero = $this->gate($access, 'deactivate', $uuid);
        $this->assertStatus($numero, 'deactivate');

        DB::transaction(function () use ($access, $request, $numero, $reason): void {
            $this->move($numero, 'deactivate', ['status' => StudioCanaryNumber::STATUS_AUTHORIZED, 'status_changed_at' => now()]);
            // El motivo de desactivar no tiene columna: va al libro (§I4.1).
            $this->audit->record($request, $access->actor, 'canary_number.deactivated', StudioAuditEvent::SUBJECT_CANARY_NUMBER, $numero->uuid,
                'Número canario desactivado (registro)', [
                    'fromStatus' => StudioCanaryNumber::STATUS_ACTIVE,
                    'toStatus' => StudioCanaryNumber::STATUS_AUTHORIZED,
                    'diff' => ['reason' => $reason],
                ], maxValueLength: StudioAudit::MAX_RULE_VALUE_LENGTH);
        });

        return ['number' => $this->dtoOf($access, $this->findOrFail($numero->id))];
    }

    /** @return array{number:array<string,mixed>} */
    public function revoke(StudioAccess $access, Request $request, string $uuid, string $reason): array
    {
        $numero = $this->gate($access, 'revoke', $uuid);
        $this->assertStatus($numero, 'revoke');

        DB::transaction(function () use ($access, $request, $numero, $reason): void {
            // Revocar admite tres estados de partida: el de verdad se lee con la fila
            // BLOQUEADA, porque una autorización o activación que se confirme mientras
            // se espera la fila lo cambia, y el libro cuenta la transición que hubo.
            $desde = (string) StudioCanaryNumber::query()->whereKey($numero->id)->lockForUpdate()->value('status');
            // El cifrado solo lo borra revocar, y dos revocaciones no pasan las dos: la lectura previa vale.
            $habiaCifrado = $numero->getRawOriginal('phone_encrypted') !== null;
            $yo = $access->snapshot();
            $ahora = now();
            // El cifrado se borra en la MISMA sentencia (lo exige `uscn_revoke_chk`).
            $this->move($numero, 'revoke', [
                'status' => StudioCanaryNumber::STATUS_REVOKED,
                'phone_encrypted' => null,
                'revoked_by_admin_id' => $yo['id'],
                'revoked_by_name' => $yo['name'],
                'revoked_by_role' => $yo['role'],
                'revoked_at' => $ahora,
                'revoke_reason' => $reason,
                'status_changed_at' => $ahora,
            ]);
            $this->audit->record($request, $access->actor, 'canary_number.revoked', StudioAuditEvent::SUBJECT_CANARY_NUMBER, $numero->uuid,
                'Número canario revocado', [
                    'fromStatus' => $desde,
                    'toStatus' => StudioCanaryNumber::STATUS_REVOKED,
                    'diff' => ['reason' => $reason, 'cipherErased' => $habiaCifrado],
                ], maxValueLength: StudioAudit::MAX_RULE_VALUE_LENGTH);
        });

        return ['number' => $this->dtoOf($access, $this->findOrFail($numero->id))];
    }

    /**
     * La versión que se ESPERA probar con el número (`null` la quita). Es
     * expectativa, nunca autoridad: nadie la usa para decidir (§I4.6).
     *
     * @return array{number:array<string,mixed>}
     */
    public function setExpectation(StudioAccess $access, Request $request, string $uuid, ?string $versionUuid): array
    {
        $numero = $this->gate($access, 'expectation', $uuid);
        $this->assertStatus($numero, 'expectation');
        $version = $versionUuid === null ? null : StudioCampaignLinks::linkableVersion($versionUuid);

        DB::transaction(function () use ($access, $request, $numero, $version): void {
            $this->move($numero, 'expectation', ['expected_version_id' => $version?->id]);
            $this->audit->record($request, $access->actor, 'canary_number.expectation_set', StudioAuditEvent::SUBJECT_CANARY_NUMBER, $numero->uuid,
                'Versión esperada de un número canario', ['diff' => ['versionUuid' => $version?->uuid]]);
        });

        return ['number' => $this->dtoOf($access, $this->findOrFail($numero->id))];
    }

    // ── Presentación ────────────────────────────────────────────────────────

    /**
     * `CanaryNumberDto` de varios números, con lo que comparten calculado una
     * vez: la cadena de publicaciones (solo si alguno es el canario del motor),
     * los activos por canal y la coherencia.
     *
     * @param  iterable<StudioCanaryNumber>  $numbers
     * @param  array{verdict:string,engineCanaryConfigured:bool,engineHash:?string,activeWhatsapp:?int}|null  $coherence
     * @return list<array<string,mixed>>
     */
    public function dtos(StudioAccess $access, iterable $numbers, ?array $coherence = null): array
    {
        $lista = (new EloquentCollection(is_array($numbers) ? $numbers : iterator_to_array($numbers, false)))->values();
        if ($lista->isEmpty()) {
            return [];
        }
        $lista->loadMissing('expectedVersion:id,uuid,label,status,content,content_hash');
        $coherencia = $coherence ?? $this->coherence->read();
        $activos = StudioCanaryNumber::query()->toBase()
            ->where('status', StudioCanaryNumber::STATUS_ACTIVE)
            ->groupBy('channel')
            ->selectRaw('channel, count(*) as n')
            ->pluck('n', 'channel')
            ->map(static fn (mixed $n): int => (int) $n)
            ->all();
        $capacidades = $access->integrationCapabilities();
        $escrituras = StudioIntegrations::writesEnabled();
        $maxActivos = StudioIntegrations::canaryMaxActive();
        $cadena = self::memoizedChain();

        return $lista->map(function (StudioCanaryNumber $n) use ($access, $coherencia, $activos, $capacidades, $escrituras, $maxActivos, $cadena): array {
            $esperada = $n->expectedVersion === null ? null : [
                'uuid' => (string) $n->expectedVersion->uuid,
                'label' => (string) $n->expectedVersion->label,
                'status' => (string) $n->expectedVersion->status,
                'integrityOk' => StudioVersions::integrityOk($n->expectedVersion),
            ];
            $esCanario = StudioCanaryCoherence::isEngineCanary($coherencia, (string) $n->channel, (string) $n->status, (string) $n->phone_hash);
            $acciones = self::actionsFor($capacidades, $escrituras, (string) $n->status, $access->isCanaryRegistrant($n), $activos[$n->channel] ?? 0, $maxActivos);

            return self::dto($n, $esperada, self::resolveExpectation((string) $n->channel, $esCanario, $cadena, $esperada), $esCanario, $acciones);
        })->all();
    }

    /** @return array<string,mixed> */
    public function dtoOf(StudioAccess $access, StudioCanaryNumber $number): array
    {
        return $this->dtos($access, [$number])[0];
    }

    /**
     * `CanaryNumberDto` (§I4.9). Pura: la usa también la exportación de
     * fixtures. El número completo nunca sale: solo `masked`.
     *
     * @param  array{uuid:string,label:string,status:string,integrityOk:bool}|null  $expected
     * @param  array{effectiveVersion:array<string,mixed>,expectation:string,expectedVersionStale:?bool}  $resolved
     * @param  array<string, array{allowed:bool,reason:?string}>  $actions
     * @return array<string,mixed>
     */
    public static function dto(StudioCanaryNumber $n, ?array $expected, array $resolved, ?bool $isEngineCanary, array $actions): array
    {
        $quien = static fn (?string $nombre, ?string $rol): ?array => $nombre === null ? null : ['name' => $nombre, 'role' => $rol];

        return [
            'uuid' => (string) $n->uuid,
            'channel' => (string) $n->channel,
            'alias' => (string) $n->alias,
            'masked' => StudioCanaryPhone::masked((string) $n->country_code, (string) $n->phone_last4),
            'status' => (string) $n->status,
            'statusLabel' => self::STATUS_LABELS[$n->status] ?? (string) $n->status,
            'registeredBy' => ['name' => (string) $n->registered_by_name, 'role' => $n->registered_by_role],
            'registeredAt' => $n->created_at?->toIso8601String(),
            'authorizedBy' => $quien($n->authorized_by_name, $n->authorized_by_role),
            'authorizedAt' => $n->authorized_at?->toIso8601String(),
            'activatedBy' => $quien($n->activated_by_name, $n->activated_by_role),
            'activatedAt' => $n->activated_at?->toIso8601String(),
            'revoked' => $n->status !== StudioCanaryNumber::STATUS_REVOKED ? null : [
                'at' => $n->revoked_at?->toIso8601String(),
                'by' => ['name' => (string) $n->revoked_by_name, 'role' => $n->revoked_by_role],
                'reason' => (string) $n->revoke_reason,
            ],
            'expectedVersion' => $expected === null ? null : [
                'uuid' => $expected['uuid'],
                'label' => $expected['label'],
                'status' => $expected['status'],
                'statusLabel' => StudioVersionStatus::tryFrom($expected['status'])?->label() ?? $expected['status'],
                'stale' => (bool) $resolved['expectedVersionStale'],
            ],
            'effectiveVersion' => $resolved['effectiveVersion'],
            'expectation' => $resolved['expectation'],
            'isEngineCanary' => $isEngineCanary,
            'actions' => $actions,
            'statusChangedAt' => $n->status_changed_at?->toIso8601String(),
        ];
    }

    /**
     * `actions` de un número para quien mira (§I4.9), con la precedencia
     * `forbidden` → `writes_disabled` → `invalid_transition` →
     * `four_eyes_required` → `active_limit`. Pura.
     *
     * @param  array{campaignsManage?:bool,canaryManage:bool,canaryAuthorize:bool,integrationsManage?:bool}  $can
     * @return array<string, array{allowed:bool,reason:?string}>
     */
    public static function actionsFor(array $can, bool $writesEnabled, string $status, bool $isRegistrant, int $activeInChannel, int $maxActive): array
    {
        $tiene = [
            StudioAccess::CANARY_AUTHORIZE => ($can['canaryAuthorize'] ?? false) === true,
            StudioAccess::CANARY_MANAGE => ($can['canaryManage'] ?? false) === true,
        ];
        $out = [];
        foreach (self::ALLOWED_FROM as $accion => $desde) {
            $motivo = match (true) {
                ! $tiene[self::ACTION_PERMISSIONS[$accion]] => 'forbidden',
                ! $writesEnabled => 'writes_disabled',
                ! in_array($status, $desde, true) => 'invalid_transition',
                in_array($accion, ['authorize', 'activate'], true) && $isRegistrant => 'four_eyes_required',
                $accion === 'activate' && $activeInChannel >= $maxActive => 'active_limit',
                default => null,
            };
            $out[$accion] = ['allowed' => $motivo === null, 'reason' => $motivo];
        }

        return $out;
    }

    /**
     * La versión que RIGE para el número, la expectativa y si la esperada es un
     * dato viejo (§I4.6). Una sola autoridad: la cadena de publicaciones, y solo
     * para el canario del motor. Pura salvo la cadena, que se pide SOLO si hace
     * falta (con `isEngineCanary = true`); si lanza, `chain_unreadable`.
     *
     * @param  Closure():array{state:string,canary:?array{versionUuid:string,label:string},rest:?array{versionUuid:string,label:string}}  $chain
     * @param  array{uuid:string,status:string,integrityOk:bool}|null  $expected
     * @return array{effectiveVersion:array<string,mixed>,expectation:string,expectedVersionStale:?bool}
     */
    public static function resolveExpectation(string $channel, ?bool $isEngineCanary, Closure $chain, ?array $expected): array
    {
        $ninguna = static fn (string $motivo): array => ['source' => 'none', 'reason' => $motivo];
        if ($channel === StudioCanaryPhone::CHANNEL_VOICE) {
            $rige = $ninguna('no_voice_engine');
        } elseif ($isEngineCanary === null) {
            $rige = $ninguna('coherence_unreadable');
        } elseif ($isEngineCanary === false) {
            $rige = $ninguna('not_engine_canary');
        } else {
            try {
                $c = $chain();
            } catch (Throwable) {
                $c = null;
            }
            if ($c === null) {
                $rige = $ninguna('chain_unreadable');
            } elseif ($c['state'] === StudioPublications::STATE_APPLIED) {
                // Como resuelve el lector del motor (H5 §A4.3, paso 5): el
                // canario y, si no hay, el resto; sin ninguno, Legacy.
                $parte = $c['canary'] !== null ? 'canary' : ($c['rest'] !== null ? 'rest' : null);
                $rige = $parte === null
                    ? ['source' => 'publication_chain', 'state' => StudioPublications::STATE_LEGACY]
                    : ['source' => 'publication_chain', 'state' => StudioPublications::STATE_APPLIED, 'part' => $parte,
                        'versionUuid' => (string) $c[$parte]['versionUuid'], 'label' => (string) $c[$parte]['label']];
            } else {
                $rige = ['source' => 'publication_chain', 'state' => (string) $c['state']];
            }
        }

        $expectativa = match (true) {
            $expected === null => 'none',
            $rige['source'] === 'none' => 'not_applicable',
            ($rige['state'] ?? null) === StudioPublications::STATE_APPLIED && strtolower((string) $rige['versionUuid']) === strtolower($expected['uuid']) => 'match',
            default => 'mismatch',
        };

        return [
            'effectiveVersion' => $rige,
            'expectation' => $expectativa,
            'expectedVersionStale' => $expected === null ? null
                : ! in_array($expected['status'], StudioCampaignLinks::linkableStatuses(), true) || ! $expected['integrityOk'],
        ];
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /**
     * La cadena de publicaciones, leída como mucho UNA vez por petición y solo
     * si algún número es el canario del motor. Si no se puede leer, lanza
     * dentro de {@see resolveExpectation()} (→ `chain_unreadable`) y queda una
     * línea en el log con la clase.
     *
     * @return Closure():array{state:string,canary:?array,rest:?array}
     */
    private static function memoizedChain(): Closure
    {
        $leida = null;
        $fallo = null;

        return static function () use (&$leida, &$fallo): array {
            if ($fallo !== null) {
                throw $fallo;
            }
            if ($leida === null) {
                try {
                    $estado = app(StudioPublications::class)->state();
                    $leida = ['state' => (string) $estado['state'], 'canary' => $estado['canary'], 'rest' => $estado['rest']];
                } catch (Throwable $e) {
                    Log::warning('ultron_studio.canary_chain_unreadable', ['error_class' => class_basename($e)]);
                    $fallo = $e;

                    throw $e;
                }
            }

            return $leida;
        };
    }

    /**
     * Las puertas comunes de 50–54, en su orden: la llave (403), la bandera
     * (409) y el número (404). Los cuatro ojos y el estado los mira cada acción.
     */
    private function gate(StudioAccess $access, string $action, string $uuid): StudioCanaryNumber
    {
        $access->require(self::ACTION_PERMISSIONS[$action]);
        if (! StudioIntegrations::writesEnabled()) {
            throw UltronStudioException::integrationsWritesDisabled();
        }

        $numero = StudioCanaryNumber::query()->where('uuid', strtolower($uuid))->first();
        if ($numero === null) {
            throw UltronStudioException::canaryNotFound();
        }

        return $numero;
    }

    /**
     * Cuatro ojos por IDENTIDAD al autorizar y al activar. El intento queda en
     * el libro aparte (la operación falla y no hay transacción), deduplicado.
     */
    private function assertFourEyes(StudioAccess $access, Request $request, StudioCanaryNumber $numero, string $action): void
    {
        if (! $access->isCanaryRegistrant($numero)) {
            return;
        }

        $this->audit->recordDenied($request, $access->actor, 'canary_number.four_eyes_denied', StudioAuditEvent::SUBJECT_CANARY_NUMBER, $numero->uuid,
            $action === 'activate' ? 'Intentó activar un número canario que registró' : 'Intentó autorizar un número canario que registró', [
                'diff' => ['action' => $action],
            ]);

        throw UltronStudioException::canaryFourEyesRequired($action);
    }

    private function assertStatus(StudioCanaryNumber $numero, string $action): void
    {
        if (! in_array($numero->status, self::ALLOWED_FROM[$action], true)) {
            throw self::invalidTransition((string) $numero->status, $action);
        }
    }

    /**
     * El UPDATE condicionado de una acción. Si no toca ninguna fila, otra
     * petición movió el número: 409 con el estado de AHORA.
     *
     * @param  array<string, mixed>  $values
     */
    private function move(StudioCanaryNumber $numero, string $action, array $values): void
    {
        if (StudioCanaryNumber::transition((int) $numero->id, self::ALLOWED_FROM[$action], $values)) {
            return;
        }

        $ahora = (string) StudioCanaryNumber::query()->whereKey($numero->id)->value('status');

        throw self::invalidTransition($ahora, $action);
    }

    private static function invalidTransition(string $current, string $action): UltronStudioException
    {
        return UltronStudioException::canaryInvalidTransition($current, self::STATUS_LABELS[$current] ?? $current, self::ACTION_LABELS[$action], self::ALLOWED_FROM[$action]);
    }

    private function findOrFail(int $id): StudioCanaryNumber
    {
        return StudioCanaryNumber::query()->whereKey($id)->firstOrFail();
    }

    /**
     * La misma petición repetida devuelve el número de la primera vez. La misma
     * llave de otra persona, o con otro canal, teléfono, alias o versión
     * esperada, es 409 `client_request_reused`.
     *
     * @param  array{channel:string,phoneHash:string,alias:string,expectedVersionUuid:?string}  $cuerpo
     */
    private function registerReplay(StudioAccess $access, string $clientRequestId, array $cuerpo): ?StudioCanaryNumber
    {
        $previa = StudioCanaryNumber::query()->with('expectedVersion:id,uuid,label,status,content,content_hash')
            ->where('client_request_id', strtolower($clientRequestId))
            ->first();
        if ($previa === null) {
            return null;
        }

        $esperada = $previa->expectedVersion === null ? null : strtolower((string) $previa->expectedVersion->uuid);
        if (! $access->isCanaryRegistrant($previa)
            || $previa->channel !== $cuerpo['channel']
            || ! hash_equals((string) $previa->phone_hash, $cuerpo['phoneHash'])
            || $previa->alias !== $cuerpo['alias']
            || $esperada !== $cuerpo['expectedVersionUuid']) {
            throw UltronStudioException::clientRequestReused();
        }

        return $previa;
    }
}
