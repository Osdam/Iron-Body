<?php

namespace App\Services\Telephony;

use App\Models\Incident;
use App\Models\Voice\VoiceBrakeEntry;
use App\Models\Voice\VoiceCallEvent;
use App\Services\IronGuard\IncidentRecorder;
use App\Services\Voice\Dto\VoiceAgentTarget;
use App\Services\Voice\Dto\VoiceIds;
use App\Services\Voice\Dto\VoiceSecretList;
use App\Services\Voice\Dto\VoiceWebhookList;
use App\Services\Voice\VoiceWebhookObserver;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * LA SALUD DEL WEBHOOK, automatizada (auditoría B-T5; contrato H8, D8-21,
 * decisión (f) de OPS8: «B8, antes de la fase 3»; riesgo R8-12: ElevenLabs
 * desactiva solo un webhook tras 10 fallos seguidos, y sin él la guarda
 * posterior no corre y una violación ya no echa el freno).
 *
 * SOLO con `VOICE_MONITOR_ENABLED`. Apagada, no lee nada, no sale a la red, no
 * guarda nada y no abre nada: dice `disabled`. Encendida, compara lo que hay
 * registrado en el proveedor (dos GET de solo lectura, por
 * {@see VoiceWebhookObserver}) con las REFERENCIAS de Laravel:
 *
 *  - el webhook `ELEVENLABS_POST_CALL_WEBHOOK_REF` existe, entrega en
 *    `VOICE_WEBHOOK_BASE_URL` + {@see POST_CALL_PATH}, no está desactivado (ni
 *    a mano ni solo) y firma con HMAC;
 *  - los secretos `ELEVENLABS_TOOL_SECRET_REF` y
 *    `ELEVENLABS_INITIATION_SECRET_REF` existen en el workspace.
 *
 * Si algo no coincide, abre o suma al incidente de IRON GUARD de su clase
 * (fuente `voice`, código estable {@see INCIDENT_KIND}; la huella son los
 * problemas, como el monitor del H7): la misma avería repetida es UN
 * incidente con más ocurrencias. Guarda el veredicto (sin URL, sin ids del
 * proveedor y sin secretos) para `voice:doctor --telefonia` y
 * `GET admin/voice/status`, con la última entrega buena que dejó la
 * post-llamada en `voice_call_events` (lo que D8-21 llama la salud del
 * webhook). `--dry-run` lee y compara, pero no guarda, no abre nada y no frena.
 *
 * EL FRENO (SC-3 del cierre, R8-12; decisión de seguridad «SÍ, frenar»): un
 * problema DEFINITIVO ({@see BRAKING}) echa además el FRENO de voz con el
 * motivo `webhook_unhealthy` ({@see VoiceBrake::openOrRearm()}; su clave es
 * `webhook:` + la huella de esos problemas): sin el webhook no corre la guarda
 * de la transcripción y una violación ya no frena. Lo INCIERTO
 * ({@see UNCHECKED}: no se pudo leer, lista truncada, proveedor sin preparar)
 * y lo que falta en Laravel (la base y las referencias) NO frenan: solo su
 * incidente, medio o alto como hasta ahora. Abrir es idempotente mientras siga
 * echado; lo suelta una persona por la vía de siempre ({@see VoiceBrake::release()},
 * con `ultron.voice_publish` y una nota) y, si después de soltarlo la
 * comprobación vuelve a ver el mismo problema, lo echa otra vez (la regla de
 * las derivas, {@see VoiceBrake::observedAfter()}). El freno lo leen la puerta
 * de salientes, el job, la entrada (`InboundPolicy`) y las herramientas.
 */
final class VoiceWebhookHealth
{
    /** La ruta pública de la post-llamada (routes/voice.php): el webhook tiene que entregar en la base + esta ruta. */
    public const POST_CALL_PATH = '/api/webhooks/voice/elevenlabs/post-call';

    /** El último veredicto (sin URL, sin ids del proveedor y sin secretos). */
    public const CACHE_KEY = 'voice:webhook:health';

    /** Una semana: el doctor lo enseña también viejo, con su fecha. */
    public const CACHE_SECONDS = 604800;

    public const INCIDENT_KIND = 'voice_webhook_unhealthy';

    public const STATE_DISABLED = 'disabled';

    public const STATE_OK = 'ok';

    public const STATE_UNHEALTHY = 'unhealthy';

    // Los problemas (conjunto cerrado, en el orden en que se miran).

    /** ElevenLabs no está en `ELEVENLABS_UNVERIFIED` (bandera o piezas): no se puede comprobar, y sin red. */
    public const PROBLEM_PROVIDER_NOT_READY = 'provider_not_ready';

    /** `VOICE_WEBHOOK_BASE_URL` vacía o sin la forma de una base limpia. */
    public const PROBLEM_BASE_URL_INVALID = 'base_url_invalid';

    /** `ELEVENLABS_POST_CALL_WEBHOOK_REF` vacía o sin forma de id. */
    public const PROBLEM_WEBHOOK_REF_MISSING = 'webhook_ref_missing';

    /** `ELEVENLABS_TOOL_SECRET_REF` vacía o sin forma de id. */
    public const PROBLEM_TOOL_SECRET_REF_MISSING = 'tool_secret_ref_missing';

    /** `ELEVENLABS_INITIATION_SECRET_REF` vacía o sin forma de id. */
    public const PROBLEM_INITIATION_SECRET_REF_MISSING = 'initiation_secret_ref_missing';

    /** La lista de webhooks no se pudo leer (credencial, red o respuesta). */
    public const PROBLEM_WEBHOOKS_UNREADABLE = 'webhooks_unreadable';

    /** El webhook de la referencia no está en el proveedor. */
    public const PROBLEM_WEBHOOK_NOT_FOUND = 'webhook_not_found';

    /** El webhook entrega en otra URL que la base + la ruta de la post-llamada. */
    public const PROBLEM_WEBHOOK_URL_MISMATCH = 'webhook_url_mismatch';

    /** Alguien lo desactivó. */
    public const PROBLEM_WEBHOOK_DISABLED = 'webhook_disabled';

    /** El proveedor lo desactivó solo, tras fallos seguidos (R8-12). */
    public const PROBLEM_WEBHOOK_AUTO_DISABLED = 'webhook_auto_disabled';

    /** No firma con HMAC (la post-llamada solo acepta la firma). */
    public const PROBLEM_WEBHOOK_AUTH_NOT_HMAC = 'webhook_auth_not_hmac';

    /** La lista de secretos no se pudo leer. */
    public const PROBLEM_SECRETS_UNREADABLE = 'secrets_unreadable';

    /** La lista de secretos tiene más páginas y una referencia no estaba en la leída: no se puede afirmar. */
    public const PROBLEM_SECRETS_TRUNCATED = 'secrets_list_truncated';

    /** El secreto de las herramientas de la referencia no está. */
    public const PROBLEM_TOOL_SECRET_NOT_FOUND = 'tool_secret_not_found';

    /** El secreto de la iniciación de la referencia no está. */
    public const PROBLEM_INITIATION_SECRET_NOT_FOUND = 'initiation_secret_not_found';

    public const PROBLEMS = [
        self::PROBLEM_PROVIDER_NOT_READY, self::PROBLEM_BASE_URL_INVALID, self::PROBLEM_WEBHOOK_REF_MISSING,
        self::PROBLEM_TOOL_SECRET_REF_MISSING, self::PROBLEM_INITIATION_SECRET_REF_MISSING,
        self::PROBLEM_WEBHOOKS_UNREADABLE, self::PROBLEM_WEBHOOK_NOT_FOUND, self::PROBLEM_WEBHOOK_URL_MISMATCH,
        self::PROBLEM_WEBHOOK_DISABLED, self::PROBLEM_WEBHOOK_AUTO_DISABLED, self::PROBLEM_WEBHOOK_AUTH_NOT_HMAC,
        self::PROBLEM_SECRETS_UNREADABLE, self::PROBLEM_SECRETS_TRUNCATED,
        self::PROBLEM_TOOL_SECRET_NOT_FOUND, self::PROBLEM_INITIATION_SECRET_NOT_FOUND,
    ];

    /** Lo que es «no se pudo comprobar» (incidente medio), distinto de «comprobado y no coincide» (alto). */
    public const UNCHECKED = [self::PROBLEM_PROVIDER_NOT_READY, self::PROBLEM_WEBHOOKS_UNREADABLE, self::PROBLEM_SECRETS_UNREADABLE, self::PROBLEM_SECRETS_TRUNCATED];

    /**
     * SC-3 (R8-12; `informe-sec-cierre.md` §6.3): lo DEFINITIVO, comprobado en
     * el proveedor y mal sin duda. Sin el webhook (no está, desactivado a mano o
     * por ElevenLabs, otra URL, sin HMAC) la post-llamada no llega o no pasa la
     * firma, así que no corre la guarda de la transcripción; sin el secreto de
     * las herramientas `no_volver_a_llamar` no puede registrar la baja hablada,
     * y sin el de la iniciación no se autoriza la entrante. Echa el FRENO. Un
     * `auth_type` que el proveedor no informa cuenta como «sin HMAC» (solo
     * `hmac` es HMAC): falla cerrado, como lo demás que el H8 no puede comprobar.
     */
    public const BRAKING = [
        self::PROBLEM_WEBHOOK_NOT_FOUND, self::PROBLEM_WEBHOOK_URL_MISMATCH, self::PROBLEM_WEBHOOK_DISABLED,
        self::PROBLEM_WEBHOOK_AUTO_DISABLED, self::PROBLEM_WEBHOOK_AUTH_NOT_HMAC,
        self::PROBLEM_TOOL_SECRET_NOT_FOUND, self::PROBLEM_INITIATION_SECRET_NOT_FOUND,
    ];

    /** El motivo cerrado del freno (`vbr_reason_chk`, migración `2026_11_14_100001`). */
    public const BRAKE_REASON = VoiceBrakeEntry::REASON_WEBHOOK_UNHEALTHY;

    /** La clave de la apertura: este prefijo + la huella de los problemas que frenan, ordenados. */
    public const BRAKE_KEY_PREFIX = 'webhook:';

    /** La autenticación que exige la post-llamada (`ElevenLabsWebhookSignature`). */
    public const AUTH_HMAC = 'hmac';

    /** Lo que deja en `voice_call_events` una post-llamada FIRMADA y aceptada ({@see VoicePostCall}): la entrega buena. */
    private const DELIVERY_KINDS = ['post_call_transcription', 'post_call_audio_discarded', 'call_initiation_failure'];

    /** ¿Se vigila? (`VOICE_MONITOR_ENABLED`, el bloque de voz del programador). */
    public static function enabled(): bool
    {
        return config('voice.monitor.enabled') === true;
    }

    /**
     * La comprobación entera. Con la bandera apagada, `disabled` sin leer
     * nada; si no, dos GET por el observador, el veredicto guardado y, si no
     * coincide, el incidente y, si algo es DEFINITIVO, el freno (salvo
     * `$dryRun`, que solo dice si lo echaría). `brake` es null si nada frena.
     *
     * @return array<string, mixed>
     */
    public function run(VoiceWebhookObserver $observer, bool $dryRun = false): array
    {
        if (! self::enabled()) {
            return ['state' => self::STATE_DISABLED, 'problems' => [], 'checkedAt' => null, 'webhook' => null, 'secrets' => null,
                'lastGoodDeliveryAt' => null, 'dryRun' => $dryRun, 'incident' => null, 'brake' => null];
        }

        // Antes de leer: una persona que suelta el freno DESPUÉS de este instante no ha visto esta observación (E10-6).
        $observadaEn = now();
        $problemas = [];
        // 1. Las referencias de Laravel (sin red).
        $base = VoiceAgentTarget::cleanBase((string) config('voice.webhook_base_url'));
        if ($base === null) {
            $problemas[] = self::PROBLEM_BASE_URL_INVALID;
        }
        $refWebhook = VoiceIds::providerIdOrNull(config('voice.elevenlabs.post_call_webhook_ref'));
        $refHerramientas = VoiceIds::providerIdOrNull(config('voice.elevenlabs.tool_secret_ref'));
        $refIniciacion = VoiceIds::providerIdOrNull(config('voice.elevenlabs.initiation_secret_ref'));
        foreach ([self::PROBLEM_WEBHOOK_REF_MISSING => $refWebhook, self::PROBLEM_TOOL_SECRET_REF_MISSING => $refHerramientas,
            self::PROBLEM_INITIATION_SECRET_REF_MISSING => $refIniciacion] as $problema => $ref) {
            if ($ref === null) {
                $problemas[] = $problema;
            }
        }

        // 2. Lo registrado en el proveedor (dos GET; el nulo y un proveedor sin preparar, sin red).
        $webhooks = $observer->describeWebhooks();
        $secretos = $observer->describeSecrets();
        if ($webhooks->outcome === VoiceWebhookList::OUTCOME_NOT_CONFIGURED || $secretos->outcome === VoiceSecretList::OUTCOME_NOT_CONFIGURED) {
            array_unshift($problemas, self::PROBLEM_PROVIDER_NOT_READY);
        }
        [$webhook, $deWebhook] = self::webhookVerdict($webhooks, $refWebhook, $base);
        [$secretosVistos, $deSecretos] = self::secretsVerdict($secretos, $refHerramientas, $refIniciacion);
        $problemas = array_values(array_intersect(self::PROBLEMS, array_unique([...$problemas, ...$deWebhook, ...$deSecretos])));

        $veredicto = [
            'state' => $problemas === [] ? self::STATE_OK : self::STATE_UNHEALTHY,
            'problems' => $problemas,
            'checkedAt' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'webhook' => $webhook,
            'secrets' => $secretosVistos,
            'lastGoodDeliveryAt' => self::lastGoodDelivery(),
        ];
        $frenan = self::brakingProblems($problemas);
        if ($dryRun) {
            // Sin escribir: si lo echaría ahora (la misma regla que `voice:reconcile --dry-run`).
            $freno = $frenan === [] ? null : ['reason' => self::BRAKE_REASON, 'problems' => $frenan, 'opened' => null, 'uuid' => null,
                'wouldOpen' => VoiceBrake::nextOpening(self::brakeKey($frenan), VoiceBrake::observedAfter($observadaEn)) !== null, 'engaged' => VoiceBrake::isOpen()];

            return $veredicto + ['dryRun' => true, 'incident' => null, 'brake' => $freno];
        }

        // El aviso primero (no lanza nunca); el freno después: si no se puede echar, la orden falla a la vista.
        $incidente = $problemas === [] ? null : self::incident($veredicto);
        $freno = $frenan === [] ? null : self::brake($frenan, $incidente, $observadaEn);
        Cache::put(self::CACHE_KEY, $veredicto, self::CACHE_SECONDS);
        if ($problemas !== []) {
            Log::channel('voice')->warning('voice.webhook_health.unhealthy', ['problems' => $problemas, 'incident_id' => $incidente, 'brake_uuid' => $freno['uuid'] ?? null]);
        }

        return $veredicto + ['dryRun' => false, 'incident' => $incidente, 'brake' => $freno];
    }

    /**
     * Los problemas del veredicto que echan el freno ({@see BRAKING}), ordenados
     * (la huella de la clave no depende del orden en que se miraron).
     *
     * @param  list<string>  $problems
     * @return list<string>
     */
    public static function brakingProblems(array $problems): array
    {
        $frenan = array_values(array_intersect($problems, self::BRAKING));
        sort($frenan, SORT_STRING);

        return $frenan;
    }

    /**
     * La clave de la apertura para esa combinación de problemas DEFINITIVOS:
     * la misma avería, la misma clave (idempotente mientras siga echado); otra
     * combinación, otra apertura. Lo incierto no entra en la huella: que una
     * lectura falle a ratos junto a un problema definitivo no abre otra.
     *
     * @param  list<string>  $braking  ya ordenados ({@see brakingProblems()})
     */
    public static function brakeKey(array $braking): string
    {
        return self::BRAKE_KEY_PREFIX.substr(hash('sha256', implode(',', $braking)), 0, 32);
    }

    /**
     * Echa el freno por `webhook_unhealthy` (o lo vuelve a echar si una persona
     * lo soltó antes de esta observación y el problema sigue). La evidencia son
     * códigos y el id del incidente, nunca URL, ids del proveedor ni secretos.
     * No atrapa nada: un freno que no se puede echar no se calla.
     *
     * `opened`: si esta pasada abrió una apertura (sin ella, la de esta avería
     * seguía echada, o una persona la soltó mientras se miraba); `engaged`: si
     * el freno queda echado, por esta u otra causa.
     *
     * @param  list<string>  $braking
     * @return array{reason: string, problems: list<string>, opened: bool, uuid: ?string, engaged: bool}
     */
    private static function brake(array $braking, ?int $incident, DateTimeInterface $observedAt): array
    {
        $apertura = VoiceBrake::openOrRearm(self::BRAKE_REASON, self::brakeKey($braking),
            ['where' => 'webhook_health', 'codes' => $braking, 'incidentId' => $incident], VoiceBrake::observedAfter($observedAt));

        return ['reason' => self::BRAKE_REASON, 'problems' => $braking, 'opened' => $apertura !== null, 'uuid' => $apertura?->uuid, 'engaged' => VoiceBrake::isOpen()];
    }

    /**
     * El último veredicto guardado (para el estado y el doctor), o null si no
     * hay ninguno legible. Sin red.
     *
     * @return array{state: string, problems: list<string>, checkedAt: string, webhook: array<string, mixed>, secrets: array<string, mixed>, lastGoodDeliveryAt: ?string}|null
     */
    public static function lastVerdict(): ?array
    {
        $v = Cache::get(self::CACHE_KEY);
        if (! is_array($v) || ! in_array($v['state'] ?? null, [self::STATE_OK, self::STATE_UNHEALTHY], true) || ! is_string($v['checkedAt'] ?? null)
            || ! is_array($v['problems'] ?? null) || array_diff($v['problems'], self::PROBLEMS) !== []) {
            return null;
        }

        return [
            'state' => $v['state'], 'problems' => array_values($v['problems']), 'checkedAt' => $v['checkedAt'],
            'webhook' => is_array($v['webhook'] ?? null) ? $v['webhook'] : [], 'secrets' => is_array($v['secrets'] ?? null) ? $v['secrets'] : [],
            'lastGoodDeliveryAt' => is_string($v['lastGoodDeliveryAt'] ?? null) ? $v['lastGoodDeliveryAt'] : null,
        ];
    }

    /**
     * El webhook de la referencia frente a lo leído: lo que se puede enseñar
     * (sin la URL ni el id) y sus problemas.
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private static function webhookVerdict(VoiceWebhookList $lista, ?string $ref, ?string $base): array
    {
        $visto = ['outcome' => $lista->outcome, 'httpStatus' => $lista->httpStatus, 'found' => null, 'urlMatches' => null,
            'disabled' => null, 'autoDisabled' => null, 'authType' => null];
        if ($lista->outcome === VoiceWebhookList::OUTCOME_NOT_CONFIGURED) {
            return [$visto, []];
        }
        if ($lista->outcome !== VoiceWebhookList::OUTCOME_OBSERVED) {
            return [$visto, [self::PROBLEM_WEBHOOKS_UNREADABLE]];
        }
        if ($ref === null) {
            return [$visto, []];
        }
        $w = $lista->find($ref);
        $visto['found'] = $w !== null;
        if ($w === null) {
            return [$visto, [self::PROBLEM_WEBHOOK_NOT_FOUND]];
        }

        $problemas = [];
        if ($base !== null) {
            $visto['urlMatches'] = is_string($w['url']) && hash_equals($base.self::POST_CALL_PATH, $w['url']);
            if (! $visto['urlMatches']) {
                $problemas[] = self::PROBLEM_WEBHOOK_URL_MISMATCH;
            }
        }
        // Sin el dato no se afirma nada: solo un `true` del proveedor es «desactivado», y solo `hmac` es HMAC.
        $visto['disabled'] = $w['disabled'];
        $visto['autoDisabled'] = $w['autoDisabled'];
        $visto['authType'] = $w['authType'];
        if ($w['disabled'] === true) {
            $problemas[] = self::PROBLEM_WEBHOOK_DISABLED;
        }
        if ($w['autoDisabled'] === true) {
            $problemas[] = self::PROBLEM_WEBHOOK_AUTO_DISABLED;
        }
        if ($w['authType'] !== self::AUTH_HMAC) {
            $problemas[] = self::PROBLEM_WEBHOOK_AUTH_NOT_HMAC;
        }

        return [$visto, $problemas];
    }

    /**
     * Los dos secretos de las referencias frente a lo leído.
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private static function secretsVerdict(VoiceSecretList $lista, ?string $refHerramientas, ?string $refIniciacion): array
    {
        $visto = ['outcome' => $lista->outcome, 'httpStatus' => $lista->httpStatus, 'truncated' => $lista->truncated, 'toolFound' => null, 'initiationFound' => null];
        if ($lista->outcome === VoiceSecretList::OUTCOME_NOT_CONFIGURED) {
            return [$visto, []];
        }
        if ($lista->outcome !== VoiceSecretList::OUTCOME_OBSERVED) {
            return [$visto, [self::PROBLEM_SECRETS_UNREADABLE]];
        }

        $problemas = [];
        foreach ([['toolFound', $refHerramientas, self::PROBLEM_TOOL_SECRET_NOT_FOUND], ['initiationFound', $refIniciacion, self::PROBLEM_INITIATION_SECRET_NOT_FOUND]] as [$campo, $ref, $problema]) {
            if ($ref === null) {
                continue;
            }
            $visto[$campo] = $lista->has($ref);
            if (! $visto[$campo]) {
                // Con más páginas sin leer, que no esté en la primera no prueba que falte.
                $problemas[] = $lista->truncated ? self::PROBLEM_SECRETS_TRUNCATED : $problema;
            }
        }

        return [$visto, $problemas];
    }

    /** D8-21: la última post-llamada firmada y aceptada (UTC), o null. Una lectura de `voice_call_events`. */
    private static function lastGoodDelivery(): ?string
    {
        $ultima = VoiceCallEvent::query()->where('provider', VoiceCallEvent::PROVIDER_ELEVENLABS)->whereIn('kind', self::DELIVERY_KINDS)->max('created_at');

        return $ultima === null ? null : Carbon::parse((string) $ultima, 'UTC')->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * El incidente de IRON GUARD (fuente `voice`, como el freno). Medio si
     * solo falta la comprobación; alto si algo no coincide. Si no se puede
     * abrir, se registra y el veredicto sigue (el aviso no tumba la orden).
     *
     * @param  array<string, mixed>  $veredicto
     */
    private static function incident(array $veredicto): ?int
    {
        $problemas = $veredicto['problems'];
        sort($problemas, SORT_STRING);
        $soloSinComprobar = array_diff($problemas, self::UNCHECKED) === [];
        try {
            return (int) app(IncidentRecorder::class)->record([
                'source' => VoiceBrake::INCIDENT_SOURCE,
                'kind' => self::INCIDENT_KIND,
                'fingerprint_keys' => $problemas,
                'title' => ($soloSinComprobar
                    ? 'No se pudo comprobar el webhook post-llamada de la voz: '
                    : 'El webhook post-llamada o los secretos de la voz registrados en ElevenLabs no coinciden con Laravel: ').implode(', ', $problemas),
                'severity' => $soloSinComprobar ? Incident::SEVERITY_MEDIUM : Incident::SEVERITY_HIGH,
                'evidence' => [
                    'problems' => $problemas,
                    'webhook' => $veredicto['webhook'],
                    'secrets' => $veredicto['secrets'],
                    'last_good_delivery_at' => $veredicto['lastGoodDeliveryAt'],
                    'check_command' => 'php artisan voice:webhook-health --dry-run',
                    'runbook' => 'backend/docs/ultron-studio/ops/h8-secrets-register.sh --solo-comprobar',
                ],
            ])->id;
        } catch (Throwable $e) {
            Log::channel('voice')->error('voice.webhook_health.incident_failed', ['problems' => $problemas, 'error_class' => $e::class]);

            return null;
        }
    }
}
