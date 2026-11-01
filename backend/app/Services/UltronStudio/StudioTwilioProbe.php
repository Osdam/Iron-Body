<?php

namespace App\Services\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioIntegrationSnapshot;
use App\Services\Twilio\TwilioProbeSanitizer;
use App\Services\Twilio\TwilioReadOnlyClient;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * LA SONDA DE SOLO LECTURA DE TWILIO (contrato H6 §I5.4, ruta 56): cinco GET
 * a la primera página de la cuenta, sus números, sus caller ID, sus llamadas de
 * 7 días y sus alertas de 24 horas, y una instantánea saneada con lo que dicen.
 * NO ENVÍA MENSAJES NI HACE LLAMADAS: el cliente solo sabe hacer GET, a los
 * hosts oficiales en `production` ({@see TwilioReadOnlyClient}).
 *
 * Es la ÚNICA petición saliente que puede hacer una ruta del Hito 6, y solo:
 *  - con `ULTRON_STUDIO_TWILIO_PROBE_ENABLED=true` (si no, 409 `probe_disabled`);
 *  - con `ultron.integrations_manage` (no llega por defecto al Administrador);
 *  - dentro del cupo propio `ultron-studio-probe` (6/h por persona, 20/h por red);
 *  - fuera del enfriamiento de 300 s: dentro, la última instantánea (200
 *    `reused`), sin red y sin evento.
 *
 * Dos sondas a la vez no salen las dos: un cerrojo de la caché compartida
 * (`database` en producción) las pone en fila, y la segunda vuelve a mirar el
 * reintento y el enfriamiento al entrar, así que reutiliza la de la primera.
 *
 * Un fallo del proveedor NUNCA es un 5xx: es un `outcome` (`auth_failed`,
 * `unreachable`, `degraded`, `error`) en la instantánea. Los cuerpos crudos
 * llevan números completos, destinos y textos de alertas: no se registran ni se
 * guardan; solo se conserva lo que deja el saneador ({@see TwilioProbeSanitizer}).
 * La credencial no sale de aquí.
 */
final class StudioTwilioProbe
{
    public const COOLDOWN_SECONDS = 300;

    /** El presupuesto total de los cinco pasos; los que no caben se anotan como `skipped`. */
    public const BUDGET_SECONDS = 30;

    /** El limitador propio (`ultron-studio-probe`, en AppServiceProvider). Aquí para la exportación de fixtures. */
    public const PER_HOUR_PER_PERSON = 6;

    public const PER_HOUR_PER_NETWORK = 20;

    private const LOCK = 'ultron-studio:twilio-probe';

    /** Lo que espera una sonda a la que tiene el cerrojo: el presupuesto y un margen. */
    private const LOCK_WAIT_SECONDS = 40;

    public function __construct(private readonly StudioAudit $audit) {}

    public static function cooldownSeconds(): int
    {
        $s = config('ultron_studio.integrations.twilio_probe.cooldown_seconds');

        return is_int($s) && $s > 0 ? $s : self::COOLDOWN_SECONDS;
    }

    /**
     * POST integrations/twilio/probe.
     *
     * @return array{snapshot:array<string,mixed>,replayed:bool,reused:bool}
     */
    public function probe(StudioAccess $access, Request $request, string $clientRequestId): array
    {
        $access->require(StudioAccess::INTEGRATIONS_MANAGE);
        if (! StudioIntegrations::probeEnabled()) {
            throw UltronStudioException::probeDisabled();
        }

        if ($previa = $this->replay($access, $clientRequestId)) {
            return ['snapshot' => $previa, 'replayed' => true, 'reused' => false];
        }

        $sid = config('otp.twilio.sid');
        $sid = is_string($sid) ? trim($sid) : '';
        if ($sid === '' || preg_match(TwilioReadOnlyClient::ACCOUNT_SID_PATTERN, $sid) !== 1) {
            throw UltronStudioException::twilioNotConfigured('missing_account_sid');
        }
        // La llave restringida solo si están sus dos piezas; si no, la del OTP.
        $credencial = StudioIntegrationConfig::probeCredential();
        [$usuario, $secreto] = $credencial === 'restricted_key'
            ? [(string) config('ultron_studio.integrations.twilio_probe.key_sid'), (string) config('ultron_studio.integrations.twilio_probe.key_secret')]
            : [$sid, is_string($t = config('otp.twilio.token')) ? $t : ''];
        if (trim($usuario) === '' || trim($secreto) === '') {
            throw UltronStudioException::twilioNotConfigured('missing_credential');
        }

        $cerrojo = Cache::lock(self::LOCK, self::LOCK_WAIT_SECONDS + self::BUDGET_SECONDS);
        try {
            $cerrojo->block(self::LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            // Una sonda anterior murió con el cerrojo puesto (caduca solo): se
            // sigue sin él. Lo peor es repetir cinco GET sin coste, y lo acota el cupo.
            $cerrojo = null;
        }

        try {
            return $this->probeLocked($access, $request, $clientRequestId, $sid, $usuario, $secreto, $credencial);
        } finally {
            $cerrojo?->release();
        }
    }

    /** @return array{snapshot:array<string,mixed>,replayed:bool,reused:bool} */
    private function probeLocked(StudioAccess $access, Request $request, string $clientRequestId, string $sid, string $usuario, #[\SensitiveParameter] string $secreto, string $credencial): array
    {
        // Con el cerrojo: la gemela pudo terminar mientras se esperaba.
        if ($previa = $this->replay($access, $clientRequestId)) {
            return ['snapshot' => $previa, 'replayed' => true, 'reused' => false];
        }
        $ultima = StudioIntegrationConfig::latest(StudioIntegrationSnapshot::PROVIDER_TWILIO, StudioIntegrationSnapshot::KIND_PROBE);
        if ($ultima !== null && $ultima->created_at !== null
            && $ultima->created_at->diffInSeconds(CarbonImmutable::now(), false) < self::cooldownSeconds()) {
            return ['snapshot' => self::snapshotDto($ultima), 'replayed' => false, 'reused' => true];
        }

        $saneado = TwilioProbeSanitizer::sanitize($this->steps($sid, $usuario, $secreto), $credencial);
        unset($secreto);

        try {
            $fila = DB::transaction(function () use ($access, $request, $clientRequestId, $saneado): StudioIntegrationSnapshot {
                $yo = $access->snapshot();
                $fila = StudioIntegrationSnapshot::query()->forceCreate([
                    'client_request_id' => $clientRequestId,
                    'provider' => StudioIntegrationSnapshot::PROVIDER_TWILIO,
                    'kind' => StudioIntegrationSnapshot::KIND_PROBE,
                    'fingerprint' => StudioIntegrationConfig::fingerprintOf($saneado['facts']),
                    'facts' => $saneado['facts'],
                    'outcome' => $saneado['outcome'],
                    'actor_type' => StudioIntegrationSnapshot::ACTOR_ADMIN,
                    'actor_admin_id' => $yo['id'],
                    'actor_name' => $yo['name'],
                    'actor_role' => $yo['role'],
                    'note' => null,
                    'created_at' => now(),
                ]);
                $this->audit->record($request, $access->actor, 'integration.probe_run', StudioAuditEvent::SUBJECT_INTEGRATION, $fila->uuid,
                    'Comprobación de solo lectura de Twilio', [
                        'diff' => ['provider' => StudioIntegrationSnapshot::PROVIDER_TWILIO, 'outcome' => $saneado['outcome']],
                    ]);

                return $fila;
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($previa = $this->replay($access, $clientRequestId)) {
                return ['snapshot' => $previa, 'replayed' => true, 'reused' => false];
            }

            throw $e;
        }

        return ['snapshot' => self::snapshotDto($fila), 'replayed' => false, 'reused' => false];
    }

    /**
     * Los cinco pasos, en su orden. Si la cuenta da `auth_failed` o
     * `unreachable`, el resto se salta; si se pasan los 30 s, también. Cada
     * paso lleva como tiempo de espera lo que le queda al presupuesto (de 1 a
     * 10 s): el total no pasa de 30 s por mucho que tarde Twilio.
     *
     * @return array<string, array<string,mixed>>
     */
    private function steps(string $sid, string $usuario, #[\SensitiveParameter] string $secreto): array
    {
        $inicio = CarbonImmutable::now();
        $base = config('ultron_studio.integrations.twilio_probe.base');
        $base = is_string($base) && trim($base) !== '' ? trim($base) : null;
        $respuestas = [];
        $parar = false;
        foreach (TwilioReadOnlyClient::STEPS as $paso) {
            $transcurrido = $inicio->diffInMilliseconds(CarbonImmutable::now(), true) / 1000;
            if ($parar || $transcurrido >= self::BUDGET_SECONDS) {
                $respuestas[$paso] = ['skipped' => true];

                continue;
            }
            $espera = max(1, min(TwilioReadOnlyClient::MAX_TIMEOUT_SECONDS, (int) floor(self::BUDGET_SECONDS - $transcurrido)));
            // En `production`, el cliente IGNORA la base: siempre los hosts oficiales.
            $cliente = new TwilioReadOnlyClient($sid, $usuario, $secreto, $base, $espera);
            $respuestas[$paso] = $cliente->get($paso, CarbonImmutable::now());
            unset($cliente);
            $parar = $paso === 'account' && in_array($respuestas[$paso]['error'] ?? null, ['auth_failed', 'unreachable'], true);
        }

        return $respuestas;
    }

    /**
     * La misma petición repetida devuelve la instantánea de la primera vez; la
     * misma llave de otra persona (o de otra cosa) es 409 `client_request_reused`.
     *
     * @return array<string,mixed>|null
     */
    private function replay(StudioAccess $access, string $clientRequestId): ?array
    {
        $previa = StudioIntegrationSnapshot::query()->where('client_request_id', strtolower($clientRequestId))->first();
        if ($previa === null) {
            return null;
        }
        if ((int) $previa->actor_admin_id !== $access->id()
            || $previa->kind !== StudioIntegrationSnapshot::KIND_PROBE
            || $previa->provider !== StudioIntegrationSnapshot::PROVIDER_TWILIO) {
            throw UltronStudioException::clientRequestReused();
        }

        return self::snapshotDto($previa);
    }

    /**
     * Una instantánea de sonda (§I5.4): `probe.last` de `GET integrations/twilio`
     * y la respuesta de la ruta 56. `facts` es solo lo que dejó el saneador.
     *
     * @return array{uuid:string,at:?string,outcome:?string,facts:array<string,mixed>|null,by:array{name:string,role:?string}}
     */
    public static function snapshotDto(StudioIntegrationSnapshot $s): array
    {
        return [
            'uuid' => (string) $s->uuid,
            'at' => $s->created_at?->toIso8601String(),
            'outcome' => $s->outcome,
            'facts' => is_array($s->facts) ? $s->facts : null,
            'by' => ['name' => (string) $s->actor_name, 'role' => $s->actor_role],
        ];
    }
}
