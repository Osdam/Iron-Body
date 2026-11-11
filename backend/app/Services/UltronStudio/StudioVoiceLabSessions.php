<?php

namespace App\Services\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioVersion;
use App\Models\UltronStudio\StudioVoiceLabSession;
use App\Services\Voice\Dto\VoiceAgentPayload;
use App\Services\Voice\Dto\VoiceAgentSpec;
use App\Services\Voice\Dto\VoiceIds;
use App\Services\Voice\Dto\VoiceWebhookVerdict;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;
use Throwable;

/**
 * Las sesiones del LABORATORIO HABLADO (contrato H8, D8-23, D8-7 y protección
 * 12): voz en el navegador, sin teléfono, contra un agente de laboratorio
 * compilado de una versión y un perfil de voz enviados o aprobados. Nivel 2
 * del laboratorio (el 1 es el texto del H4; el 3, la llamada a un canario).
 *
 * ABRIR (`POST voice-lab/sessions`), en este orden:
 *   1 la bandera y los permisos (controlador) · 2 la repetición
 *   (`clientRequestId`) · 3 la configuración: driver, credencial VERIFICADA,
 *   base de los webhooks, referencias de secretos, precio, presupuestos y
 *   cupo ({@see StudioVoiceLabConfig::reasons()}) · 4 la versión, el perfil y
 *   la compilación (404 / 422 `voice_compile_refused`) · 5 el cupo de
 *   sesiones de la persona en el día de Bogotá · 6 la reserva frente al tope
 *   por sesión y frente a lo aceptado · 7 la RESERVA ATÓMICA y las filas del
 *   agente y de la sesión en UNA transacción · 8 FUERA de ella, el despliegue
 *   del agente y la URL firmada.
 * Un fallo del proveedor deja la sesión `failed`, libera la reserva y es un
 * 502 `voice_lab_provider_failed` con su motivo; nada de la respuesta del
 * proveedor viaja.
 *
 * RESERVA ATÓMICA (como el H4): lo comprometido del día es lo liquidado de
 * las sesiones cerradas más la reserva de las abiertas (salvo las que nunca
 * recibieron su URL y ya caducaron: sin URL no hubo conversación). En
 * PostgreSQL, READ COMMITTED como primera sentencia y después
 * `pg_advisory_xact_lock(crc32('ultron_studio_voice_lab:budget'))`, ANTES de
 * leer lo comprometido (la lección B-2 de G): dos sesiones que juntas superan
 * el techo dan un 201 y un 409, nunca dos 201. El cupo de sesiones se cuenta
 * detrás del mismo cerrojo. SQLite escribe en serie.
 *
 * LA URL FIRMADA: una por sesión, caduca a los 15 minutos, va UNA vez en la
 * respuesta de quien abrió la sesión y no se guarda (solo cuándo caduca). Una
 * repetición de la misma petición devuelve la sesión SIN la URL.
 *
 * EL LIBRO DEL STUDIO (S8-d): abrir una sesión deja `voice_lab.session_opened`
 * en la MISMA transacción que la reserva y la fila (o las dos o ninguna), con
 * quien la abrió, la huella de la especificación, la versión y el perfil (uuid
 * y huella), el modo y la reserva; nunca la URL, un teléfono ni un texto. El
 * veredicto deja el suyo ({@see StudioVoiceLabVerdicts}).
 *
 * CERRAR, de tres maneras, y siempre con la reserva liquidada:
 *  - quien la abrió ({@see end()}), con lo que observó (códigos de conjuntos
 *    cerrados: objetivos cumplidos, restricciones que se saltó, interrupciones
 *    y errores). Sin el informe del proveedor se cobra la reserva entera;
 *  - el informe post-llamada del proveedor ({@see recordProviderReport()}):
 *    lo entrega la telefonía (B8-b) con el veredicto NEUTRO del webhook ya
 *    verificado y los códigos de su guarda; da la duración real, las
 *    herramientas usadas y el coste prorrateado de la reserva;
 *  - por PLAZO: abierta con URL, a los 15 min de la URL + 5 de conversación +
 *    2 de margen, se cobra entera (no se sabe si se habló); sin URL, a los 5
 *    minutos, sin coste. Se presenta caducada al leer sin escribir nada y se
 *    materializa al abrir la siguiente.
 *
 * EL VEREDICTO es de OTRA identidad ({@see StudioVoiceLabVerdicts}). Todo lo
 * que guarda una sesión son códigos y números: nunca la transcripción, el
 * audio, un teléfono ni la URL (D8-17).
 */
final class StudioVoiceLabSessions
{
    /** La llave de abrir y terminar sesiones (D8-22): gasta. */
    public const LAB_RUN = 'ultron.voice_lab_run';

    public const DISABLED_MESSAGE = 'El laboratorio hablado está apagado en el servidor.';

    /** Lo que la sesión es en cada modo: el CRM lo enseña junto al estado. */
    public const FIDELITY = [
        StudioVoiceLabConfig::DRIVER_FAKE => 'Simulador del laboratorio hablado: no habla con ElevenLabs ni gasta. Sirve para probar el flujo de la sesión, no el agente: no admite veredicto.',
        StudioVoiceLabConfig::DRIVER_ELEVENLABS => 'Conversación de voz en el navegador con un agente de laboratorio de ElevenLabs compilado desde la versión y el perfil: el mismo prompt, la misma voz y las mismas herramientas que tendría publicado, sin teléfono y sin número. Gasta minutos del proveedor.',
    ];

    /** La nota de un veredicto, como mucho (la columna `verdict_note`). */
    public const VERDICT_NOTE_MAX = 500;

    public const MAX_INTERRUPTIONS = 99;

    /** Conversaciones que se apuntan en una sesión, como mucho (su URL es de un solo uso: casi siempre una). */
    public const MAX_CONVERSATIONS = 5;

    /** Códigos de la guarda posterior que se guardan de un informe, como mucho. */
    public const MAX_GUARD_CODES = 50;

    public const FINDINGS_FORMAT = 'ultron-voice-lab-findings/1';

    /** Un código de la guarda posterior (B8-b). */
    public const GUARD_CODE_PATTERN = '/^[a-z][a-z0-9_]{0,47}$/D';

    public const STATUS_LABELS = [
        StudioVoiceLabSession::STATUS_OPEN => 'Abierta',
        StudioVoiceLabSession::STATUS_ENDED => 'Terminada',
        StudioVoiceLabSession::STATUS_FAILED => 'Fallida',
    ];

    /** Por qué se cerró una sesión: un conjunto cerrado (la columna `end_reason`). */
    public const END_REASONS = [
        'tester_ended' => 'La terminó quien la abrió.',
        'provider_report' => 'Llegó el informe del proveedor al terminar la conversación.',
        'deadline' => 'Se cerró por plazo: se cobra la reserva entera.',
        'abandoned' => 'No llegó a abrirse: nunca tuvo conexión y no gastó nada.',
        'open_error' => 'Falló al abrirse: no gastó nada.',
        'agent_not_created' => 'El proveedor no creó el agente de laboratorio.',
        'agent_create_ambiguous' => 'El proveedor no respondió a tiempo al crear el agente de laboratorio.',
        'agent_unverified' => 'No se pudo leer el agente de laboratorio para comprobarlo.',
        'agent_drift' => 'El agente que guardó el proveedor no es el compilado: no se abrió.',
        'url_rejected' => 'El proveedor rechazó la conexión firmada.',
        'url_not_found' => 'El proveedor no encontró el agente al pedir la conexión firmada.',
        'url_auth_failed' => 'El proveedor no aceptó la clave al pedir la conexión firmada.',
        'url_unreachable' => 'El proveedor no respondió al pedir la conexión firmada.',
        'url_error' => 'El proveedor respondió algo que no es una conexión firmada válida.',
        'url_not_configured' => 'El proveedor de voz no está configurado para emitir conexiones firmadas.',
    ];

    /** Lo que quien probó vio CUMPLIDO: los objetivos comerciales de una conversación de voz. */
    public const OBJECTIVES = [
        'aviso_ia' => 'Dio el aviso de asistente virtual al empezar.',
        'saludo' => 'Saludó y se presentó como dice el perfil.',
        'entendio' => 'Entendió lo que se le pedía.',
        'informo_planes' => 'Informó de los planes con la herramienta del catálogo.',
        'informo_horarios' => 'Informó de la sede y los horarios.',
        'ofrecio_persona' => 'Ofreció hablar con una persona cuando se le pidió.',
        'respeto_no_llamar' => 'Atendió la petición de no volver a llamar.',
        'cierre' => 'Cerró la conversación con cortesía.',
    ];

    /** Las restricciones que quien probó vio SALTARSE (vacío = todas respetadas). */
    public const RESTRICTIONS = [
        'hechos_sin_herramienta' => 'Dio un dato que no salió de una herramienta (R-01).',
        'descuento' => 'Ofreció o insinuó un descuento (R-02).',
        'cobro' => 'Intentó cobrar o pidió un pago.',
        'derivacion' => 'Derivó sin la herramienta o a quien no debía (R-07).',
        'datos_personales' => 'Pidió o repitió datos personales que no necesitaba.',
    ];

    /** Los errores que quien probó observó. */
    public const ERRORS = [
        'corte' => 'La conversación se cortó.',
        'latencia' => 'Tardó demasiado en responder.',
        'no_entendio' => 'No entendió o respondió otra cosa.',
        'herramienta' => 'Una herramienta falló o no respondió.',
        'voz' => 'La voz o la pronunciación no se entendían.',
        'interrupcion_mal' => 'No manejó bien las interrupciones.',
    ];

    /** Por qué una sesión no admite veredicto, en el orden en que se comprueba. */
    public const NOT_REVIEWABLE = [
        'not_ended' => 'Solo se emite el veredicto sobre una sesión terminada: esta sigue abierta o no llegó a abrirse.',
        'fake_mode' => 'Una sesión del simulador no admite veredicto: no habló ningún agente.',
    ];

    public function __construct(
        private readonly StudioVoiceLabAgents $agents,
        private readonly StudioAudit $audit,
    ) {}

    /** La bandera: apagada, todo `voice-lab/*` salvo el estado es 409 `voice_lab_disabled`. */
    public static function assertEnabled(): void
    {
        if (! StudioVoiceLabConfig::current()->enabled) {
            throw StudioVoiceLabErrors::disabled();
        }
    }

    /** El día del presupuesto y del cupo: el de Bogotá, nunca el UTC. */
    public static function budgetDay(?Carbon $at = null): string
    {
        return ($at ?? now())->copy()->setTimezone(StudioVoiceLabConfig::BUDGET_TIMEZONE)->toDateString();
    }

    // ── GET voice-lab/status ────────────────────────────────────────────────

    /**
     * Siempre 200 a quien tiene `ultron.view`. Apagado: `{enabled:false,
     * message, can}`; encendido, el modo, la configuración (sin claves ni
     * referencias), la credencial, el presupuesto del día, el cupo de quien
     * mira, los límites, la reserva máxima de una sesión (la que se acepta al
     * abrirla) y los catálogos de lo que se observa.
     *
     * @return array<string, mixed>
     */
    public function status(StudioAccess $access): array
    {
        $can = [
            'view' => $access->can(StudioAccess::VIEW),
            'run' => $access->can(self::LAB_RUN),
            'verdict' => $access->can(StudioAccess::APPROVE),
        ];
        $cfg = StudioVoiceLabConfig::current();
        if (! $cfg->enabled) {
            return ['enabled' => false, 'message' => self::DISABLED_MESSAGE, 'can' => $can];
        }

        $credencial = StudioVoiceLabConfig::credentialsState();
        $dia = self::budgetDay();
        ['spent' => $gastado, 'reserved' => $reservado] = self::committed($dia);

        return [
            'enabled' => true,
            'mode' => $cfg->mode(),
            'configured' => $cfg->configured($credencial),
            'reasons' => $cfg->reasons($credencial),
            'credentials' => $credencial,
            'fidelity' => self::FIDELITY[$cfg->mode() ?? ''] ?? null,
            'budget' => [
                'timezone' => StudioVoiceLabConfig::BUDGET_TIMEZONE,
                'day' => $dia,
                'dailyCapMicros' => $cfg->dailyBudgetMicros,
                'spentMicros' => $gastado,
                'reservedMicros' => $reservado,
                'remainingMicros' => $cfg->dailyBudgetMicros - $gastado - $reservado,
                'sessionCapMicros' => $cfg->sessionBudgetMicros,
                'pricePerMinuteMicros' => $cfg->isFake() ? 0 : $cfg->pricePerMinuteMicros,
            ],
            'sessions' => ['today' => self::sessionsOn($dia, $access->id()), 'maxPerDay' => $cfg->maxSessionsPerDay],
            'limits' => [
                'maxSessionSeconds' => $cfg->maxSessionSeconds,
                'urlTtlSeconds' => StudioVoiceLabConfig::URL_TTL_SECONDS,
                'verdictNoteMax' => self::VERDICT_NOTE_MAX,
                'maxInterruptions' => self::MAX_INTERRUPTIONS,
            ],
            // La reserva de una sesión depende de la duración del perfil (como
            // mucho la de la sesión): esta es la mayor, la que se acepta al abrir.
            'estimate' => ['maxSeconds' => $cfg->maxSessionSeconds, 'reservedMicros' => $cfg->reservationFor($cfg->maxSessionSeconds)],
            'observations' => [
                'objectives' => self::catalog(self::OBJECTIVES),
                'restrictions' => self::catalog(self::RESTRICTIONS),
                'errors' => self::catalog(self::ERRORS),
            ],
            'can' => $can,
        ];
    }

    // ── POST voice-lab/sessions ─────────────────────────────────────────────

    /**
     * Abre una sesión con la reserva atómica del presupuesto. 201 la primera
     * vez (con la URL firmada en `signedUrl`); 200 `replayed` al repetir el
     * mismo `clientRequestId` (sin la URL).
     *
     * @return array{session:StudioVoiceLabSession,replayed:bool,signedUrl:?string}
     *
     * @throws UltronStudioException
     */
    public function open(StudioAccess $access, string $versionUuid, string $profileUuid, int $acceptedMaxMicros, string $clientRequestId): array
    {
        // 1. Los permisos (la puerta ya los comprobó; esto es la defensa en profundidad).
        $access->require(self::LAB_RUN);

        $peticion = ['version' => strtolower($versionUuid), 'profile' => strtolower($profileUuid)];
        // 2. La repetición: el doble clic recibe su 200 aunque el primero haya gastado el último hueco del día.
        if ($previa = $this->replay($access, $clientRequestId, $peticion)) {
            return ['session' => $previa, 'replayed' => true, 'signedUrl' => null];
        }

        // 3. La configuración: los cuatro cerrojos y el cupo.
        $cfg = StudioVoiceLabConfig::current();
        $credencial = $cfg->isElevenLabs() ? StudioVoiceLabConfig::credentialsState() : null;
        if (! $cfg->configured($credencial)) {
            throw StudioVoiceLabErrors::notConfigured($cfg->reasons($credencial), $cfg->mode());
        }

        // 4. La versión, el perfil, la compilación `lab` y la carga (sin red).
        $preparado = $this->agents->prepare($versionUuid, $profileUuid, $cfg);
        $segundos = $preparado['spec']->maxDurationSeconds();
        $reserva = (int) $cfg->reservationFor($segundos);

        // 5. El cupo de sesiones de la persona (se repite dentro del cerrojo).
        $dia = self::budgetDay();
        $hoy = self::sessionsOn($dia, $access->id());
        if ($hoy >= $cfg->maxSessionsPerDay) {
            throw StudioVoiceLabErrors::dailySessionsExceeded($hoy, $cfg->maxSessionsPerDay);
        }

        // 6. La reserva frente al tope por sesión y frente a lo que la persona aceptó.
        if ($cfg->isElevenLabs() && $reserva > $cfg->sessionBudgetMicros) {
            throw StudioVoiceLabErrors::sessionBudgetExceeded($reserva, $cfg->sessionBudgetMicros);
        }
        if ($reserva > $acceptedMaxMicros) {
            throw StudioVoiceLabErrors::estimateChanged(['maxSeconds' => $segundos, 'reservedMicros' => $reserva, 'pricePerMinuteMicros' => $cfg->pricePerMinuteMicros]);
        }

        $yo = $access->snapshot();
        try {
            $repetida = false;
            $sesion = DB::transaction(function () use ($access, $cfg, $preparado, $segundos, $reserva, $dia, $yo, $clientRequestId, $peticion, &$repetida): StudioVoiceLabSession {
                // 7. La reserva: el cerrojo ANTES de leer nada.
                $this->serializeBudget();
                if ($previa = $this->replay($access, $clientRequestId, $peticion)) {
                    $repetida = true;

                    return $previa;
                }
                self::expireStale();

                $hoy = self::sessionsOn($dia, $yo['id']);
                if ($hoy >= $cfg->maxSessionsPerDay) {
                    throw StudioVoiceLabErrors::dailySessionsExceeded($hoy, $cfg->maxSessionsPerDay);
                }
                ['spent' => $gastado, 'reserved' => $reservado] = self::committed($dia);
                $restante = $cfg->dailyBudgetMicros - $gastado - $reservado;
                // Una sesión del simulador no cuesta nada: no se para por presupuesto.
                if ($reserva > 0 && $reserva > $restante) {
                    throw StudioVoiceLabErrors::dailyBudgetExceeded($reserva, $restante);
                }

                $agente = $this->agents->insertPending($preparado, $yo, $clientRequestId);
                $ahora = now();

                $sesion = StudioVoiceLabSession::query()->forceCreate([
                    'client_request_id' => $clientRequestId,
                    'agent_id' => $agente,
                    'status' => StudioVoiceLabSession::STATUS_OPEN,
                    'budget_day' => $dia,
                    'reserved_micros' => $reserva,
                    'max_seconds' => $segundos,
                    'created_by_admin_id' => $yo['id'],
                    'created_by_name' => $yo['name'],
                    'created_by_role' => $yo['role'],
                    'created_at' => $ahora,
                    'status_changed_at' => $ahora,
                    'updated_at' => $ahora,
                ]);
                $this->recordOpened($access, $sesion, $preparado);

                return $sesion;
            });
        } catch (UniqueConstraintViolationException) {
            // La misma llave llegó dos veces a la vez y un índice único paró a la
            // segunda. Se resuelve FUERA de la transacción: en PostgreSQL un error
            // la deja abortada. Si no hay sesión con esa llave, la llave ya la usó
            // otra cosa (un agente de la publicación): es reutilizarla.
            $previa = $this->replay($access, $clientRequestId, $peticion);
            if ($previa === null) {
                throw UltronStudioException::clientRequestReused();
            }

            return ['session' => $previa, 'replayed' => true, 'signedUrl' => null];
        }
        if ($repetida) {
            return ['session' => $sesion, 'replayed' => true, 'signedUrl' => null];
        }

        // 8. El agente y la URL, fuera de la transacción: aquí hay red.
        try {
            $despliegue = $this->agents->deploy((int) $sesion->agent_id, $preparado);
            $url = $despliegue['ok'] ? $this->agents->issueUrl($preparado['provider'], $despliegue['providerAgentId']) : $despliegue;
        } catch (Throwable $e) {
            // Nada inesperado deja la reserva tomada: se libera y se ve el fallo.
            $this->fail($sesion, 'open_error');

            throw $e;
        }
        if (! $url['ok']) {
            $this->fail($sesion, $url['endReason']);

            throw StudioVoiceLabErrors::providerFailed($url['endReason'], $this->detail($access, $sesion->fresh() ?? $sesion));
        }

        $ahora = now();
        if (! StudioVoiceLabSession::advance((int) $sesion->id, [StudioVoiceLabSession::STATUS_OPEN], [
            'url_expires_at' => $ahora->copy()->addSeconds(StudioVoiceLabConfig::URL_TTL_SECONDS),
            'started_at' => $ahora,
            'updated_at' => $ahora,
        ])) {
            // Alguien la cerró mientras se abría: la URL no se entrega.
            throw StudioVoiceLabErrors::sessionNotOpen((string) StudioVoiceLabSession::query()->whereKey($sesion->id)->value('status'));
        }

        return ['session' => $sesion->fresh() ?? $sesion, 'replayed' => false, 'signedUrl' => $url['signedUrl']];
    }

    /**
     * El evento del libro al abrir (S8-d), dentro de la transacción de la
     * reserva: el actor, la huella, la versión y el perfil, el modo y la
     * reserva. Sin URL, teléfonos ni textos.
     *
     * @param  array{version:StudioVersion,profile:stdClass,spec:VoiceAgentSpec,payload:VoiceAgentPayload,provider:string}  $preparado
     */
    private function recordOpened(StudioAccess $access, StudioVoiceLabSession $sesion, array $preparado): void
    {
        $spec = $preparado['spec'];
        $version = $preparado['version'];
        $perfil = $preparado['profile'];
        $this->audit->record(null, $access->actor, 'voice_lab.session_opened', StudioAuditEvent::SUBJECT_INTEGRATION, (string) $sesion->uuid,
            "Abrió una sesión del laboratorio hablado: «{$version->label}» con el perfil «{$perfil->label}» (".($preparado['provider'] === StudioVoiceLabAgents::PROVIDER_FAKE ? 'simulador' : 'ElevenLabs').')', [
                'toStatus' => StudioVoiceLabSession::STATUS_OPEN,
                'contentHash' => (string) $version->content_hash,
                'diff' => [
                    'specHash' => $spec->specHash,
                    'compilerVersion' => $spec->compilerVersion(),
                    'version' => (string) $version->uuid,
                    'versionHash' => (string) $version->content_hash,
                    'profile' => (string) $perfil->uuid,
                    'profileHash' => (string) $perfil->content_hash,
                    'provider' => $preparado['provider'],
                    'reservedMicros' => (int) $sesion->reserved_micros,
                    'maxSeconds' => (int) $sesion->max_seconds,
                    'transferDestinations' => count($spec->transferDestinations()),
                ],
            ]);
    }

    // ── POST voice-lab/sessions/{uuid}/end ──────────────────────────────────

    /**
     * Quien abrió la sesión la da por terminada y cuenta lo que observó (una
     * vez). Abierta, la cierra `tester_ended`; sin el informe del proveedor,
     * se cobra la reserva entera. Terminada por el informe o por plazo y sin
     * observaciones ni veredicto, solo añade lo observado.
     *
     * @param  array{objectives:list<string>,restrictionsBroken:list<string>,interruptions:int,errors:list<string>}  $observations
     */
    public function end(StudioAccess $access, StudioVoiceLabSession $session, array $observations): StudioVoiceLabSession
    {
        $access->require(self::LAB_RUN);
        if ((int) $session->created_by_admin_id !== $access->id()) {
            throw StudioVoiceLabErrors::notSessionOwner();
        }
        $observado = self::testerReport($observations);

        DB::transaction(function () use ($session, $observado): void {
            $fila = StudioVoiceLabSession::query()->whereKey($session->id)->lockForUpdate()->first();
            if ($fila === null || $fila->status === StudioVoiceLabSession::STATUS_FAILED || $fila->verdict !== null) {
                throw StudioVoiceLabErrors::sessionNotOpen((string) $fila?->status);
            }
            if ($fila->isOpen() && $fila->url_expires_at === null) {
                throw StudioVoiceLabErrors::sessionNotStarted();
            }
            $hallazgos = self::findingsOf($fila);
            if ($hallazgos['tester'] !== null) {
                throw StudioVoiceLabErrors::alreadyReported();
            }
            $hallazgos['tester'] = $observado;

            $ahora = now();
            $cambios = ['guard_codes' => $hallazgos, 'updated_at' => $ahora];
            if ($fila->isOpen()) {
                $cambios += [
                    'status' => StudioVoiceLabSession::STATUS_ENDED,
                    'end_reason' => 'tester_ended',
                    'ended_at' => $ahora,
                    'status_changed_at' => $ahora,
                    // Sin el informe del proveedor no se sabe cuánto se habló.
                    'cost_micros' => $fila->cost_micros ?? $fila->reserved_micros,
                ];
            }
            if (! StudioVoiceLabSession::advance((int) $fila->id, [$fila->status], $cambios)) {
                throw StudioVoiceLabErrors::sessionNotOpen((string) $fila->status);
            }
        });

        return $session->fresh() ?? $session;
    }

    // ── El informe del proveedor (B8-b) ─────────────────────────────────────

    /**
     * El informe post-llamada de una conversación del laboratorio: lo entrega
     * la telefonía con el veredicto NEUTRO del webhook YA VERIFICADO (firma
     * buena) y, si los tiene, los códigos de su guarda posterior (código y
     * número de turno, nunca texto: D8-10 capa 4). El agente de laboratorio es
     * de UNA sesión, así que su id basta para saber de cuál.
     *
     * Idempotente por la conversación. Abierta, la cierra `provider_report`;
     * terminada sin veredicto (por quien la abrió o por plazo), corrige la
     * duración y el coste con lo real. El coste es la reserva prorrateada por
     * los segundos que duró: el precio por segundo con el que se reservó.
     *
     * @param  list<array{code:string,turn:int}>|null  $guard
     * @return string `recorded` | `duplicate` | `not_a_lab_session` | `ignored`
     *
     * @throws InvalidArgumentException si la guarda no trae códigos con forma (un fallo de quien llama)
     */
    public function recordProviderReport(VoiceWebhookVerdict $verdict, ?array $guard = null): string
    {
        if (! $verdict->accepted() || $verdict->kind !== VoiceWebhookVerdict::KIND_POST_CALL_TRANSCRIPTION || $verdict->conversationId === null) {
            return 'ignored';
        }
        $agente = $verdict->agentId !== null ? StudioVoiceLabAgents::findLabByProviderId($verdict->agentId) : null;
        if ($agente === null) {
            return 'not_a_lab_session';
        }
        $guardia = $guard === null ? null : self::guardEntries($guard);

        return DB::transaction(function () use ($verdict, $agente, $guardia): string {
            $fila = StudioVoiceLabSession::query()->where('agent_id', $agente->id)->lockForUpdate()->first();
            if ($fila === null) {
                return 'not_a_lab_session';
            }
            // Fallida o sin URL nunca habló; con veredicto es definitiva.
            if ($fila->status === StudioVoiceLabSession::STATUS_FAILED || $fila->verdict !== null || $fila->url_expires_at === null) {
                return 'ignored';
            }

            $hallazgos = self::findingsOf($fila);
            $proveedor = $hallazgos['provider'] ?? ['conversations' => [], 'toolCalls' => [], 'guard' => null];
            if (in_array($verdict->conversationId, array_column($proveedor['conversations'], 'id'), true)) {
                return 'duplicate';
            }
            if (count($proveedor['conversations']) >= self::MAX_CONVERSATIONS) {
                return 'ignored';
            }
            $proveedor['conversations'][] = ['id' => $verdict->conversationId, 'durationSeconds' => $verdict->durationSeconds];
            $herramientas = array_values(array_unique([...$proveedor['toolCalls'], ...$verdict->toolCalls]));
            sort($herramientas, SORT_STRING);
            $proveedor['toolCalls'] = $herramientas;
            if ($guardia !== null) {
                $proveedor['guard'] = array_slice([...($proveedor['guard'] ?? []), ...$guardia], 0, self::MAX_GUARD_CODES);
            }
            $hallazgos['provider'] = $proveedor;

            $duraciones = array_column($proveedor['conversations'], 'durationSeconds');
            $conocida = ! in_array(null, $duraciones, true);
            $total = $conocida ? (int) array_sum($duraciones) : null;
            $ahora = now();
            $cambios = [
                'guard_codes' => $hallazgos,
                'duration_seconds' => $total === null ? null : min($total, 32767),
                // Sin la duración de todas las conversaciones, la reserva entera.
                'cost_micros' => $total === null ? (int) $fila->reserved_micros : self::proratedCost((int) $fila->reserved_micros, (int) $fila->max_seconds, $total),
                'updated_at' => $ahora,
            ];
            if ($fila->isOpen()) {
                $cambios += ['status' => StudioVoiceLabSession::STATUS_ENDED, 'end_reason' => 'provider_report', 'ended_at' => $ahora, 'status_changed_at' => $ahora];
            }

            return StudioVoiceLabSession::advance((int) $fila->id, [$fila->status], $cambios) ? 'recorded' : 'ignored';
        });
    }

    // ── Lecturas ────────────────────────────────────────────────────────────

    /**
     * GET voice-lab/sessions: el paginador de Laravel con SessionDto, de la
     * más reciente a la más antigua, con los agentes, las versiones y los
     * perfiles de la página en TRES consultas.
     *
     * @param  array{version?:?string,profile?:?string,specHash?:?string,status?:?string,scope?:?string}  $filters
     */
    public function page(StudioAccess $access, array $filters, int $perPage): LengthAwarePaginator
    {
        $agentes = static fn () => DB::table(StudioVoiceLabAgents::AGENTS)->select('id')->where('purpose', StudioVoiceLabAgents::PURPOSE_LAB);

        $pagina = StudioVoiceLabSession::query()
            ->when($filters['version'] ?? null, fn ($q, string $uuid) => $q->whereIn('agent_id', $agentes()->whereIn('studio_version_id', StudioVersion::query()->select('id')->where('uuid', $uuid))))
            ->when($filters['profile'] ?? null, fn ($q, string $uuid) => $q->whereIn('agent_id', $agentes()->whereIn('voice_profile_id', DB::table(StudioVoiceLabAgents::PROFILES)->select('id')->where('uuid', $uuid))))
            ->when($filters['specHash'] ?? null, fn ($q, string $hash) => $q->whereIn('agent_id', $agentes()->where('spec_hash', $hash)))
            ->when($filters['status'] ?? null, fn ($q, string $estado) => $q->where('status', $estado))
            ->when(($filters['scope'] ?? 'all') === 'mine', fn ($q) => $q->where('created_by_admin_id', $access->id()))
            ->orderByDesc('id')
            ->paginate($perPage);

        $refs = self::refsFor($pagina->getCollection()->all());

        return $pagina->through(fn (StudioVoiceLabSession $s): array => $this->present($access, $s, $refs));
    }

    /** La sesión, o el 404 de un uuid que no existe (también uno sin forma). */
    public function find(string $uuid): StudioVoiceLabSession
    {
        $uuid = strtolower($uuid);

        return (VoiceIds::isUuid($uuid) ? StudioVoiceLabSession::query()->where('uuid', $uuid)->first() : null)
            ?? throw StudioVoiceLabErrors::sessionNotFound();
    }

    /**
     * SessionDto: la sesión con su agente, su versión y su perfil, sus
     * métricas, su veredicto y qué puede hacer quien mira. `$signedUrl` solo
     * en la respuesta que abre la sesión.
     *
     * @return array<string, mixed>
     */
    public function detail(StudioAccess $access, StudioVoiceLabSession $session, ?string $signedUrl = null): array
    {
        return $this->present($access, $session, self::refsFor([$session]), $signedUrl);
    }

    // ── Cálculos compartidos con el veredicto ───────────────────────────────

    /**
     * Lo comprometido de un día: lo liquidado de las sesiones cerradas
     * (`spent`) y la reserva de las abiertas (`reserved`), salvo las que nunca
     * recibieron su URL y ya pasaron su plazo de apertura (no hablaron). Una
     * abierta con URL y con el plazo vencido sigue contando su reserva: es lo
     * que se le cobrará al cerrarla. El corte se calcula aquí, en UTC.
     *
     * @return array{spent:int,reserved:int}
     */
    public static function committed(string $day): array
    {
        $abandono = now()->subSeconds(StudioVoiceLabConfig::OPEN_DEADLINE_SECONDS);
        $fila = DB::table('ultron_studio_voice_lab_sessions')
            ->where('budget_day', $day)
            ->selectRaw("COALESCE(SUM(CASE WHEN status <> 'open' THEN COALESCE(cost_micros, 0) ELSE 0 END), 0) AS spent")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'open' AND NOT (url_expires_at IS NULL AND created_at < ?) THEN reserved_micros ELSE 0 END), 0) AS reserved", [$abandono])
            ->first();

        return ['spent' => (int) ($fila->spent ?? 0), 'reserved' => (int) ($fila->reserved ?? 0)];
    }

    /** Cuántas sesiones abrió una persona en un día de Bogotá (también las fallidas y las del simulador). */
    public static function sessionsOn(string $day, int $adminId): int
    {
        return StudioVoiceLabSession::query()->where('budget_day', $day)->where('created_by_admin_id', $adminId)->count();
    }

    /**
     * Cuándo se cierra sola una sesión abierta: con URL, a su caducidad más la
     * conversación más larga y el margen del informe; sin URL, a los 5 minutos
     * de abrirse.
     */
    public static function deadlineOf(StudioVoiceLabSession $s): ?Carbon
    {
        if (! $s->isOpen()) {
            return null;
        }
        if ($s->url_expires_at !== null) {
            return $s->url_expires_at->copy()->addSeconds(StudioVoiceLabConfig::HARD_MAX_SESSION_SECONDS + StudioVoiceLabConfig::REPORT_GRACE_SECONDS);
        }

        return $s->created_at?->copy()->addSeconds(StudioVoiceLabConfig::OPEN_DEADLINE_SECONDS);
    }

    /** ¿Pasó su plazo? Se presenta al leer, sin escribir nada. */
    public static function isExpired(StudioVoiceLabSession $s): bool
    {
        $plazo = self::deadlineOf($s);

        return $plazo !== null && $plazo->lt(now());
    }

    /**
     * Por qué una sesión no admite veredicto, o null si lo admite.
     */
    public static function notReviewableReason(StudioVoiceLabSession $s, ?stdClass $agent): ?string
    {
        return match (true) {
            $s->status !== StudioVoiceLabSession::STATUS_ENDED => 'not_ended',
            $agent === null || $agent->provider !== StudioVoiceLabAgents::PROVIDER_ELEVENLABS => 'fake_mode',
            default => null,
        };
    }

    /**
     * Los hallazgos guardados de una sesión, con su forma: lo que informó el
     * proveedor y lo que contó quien probó (cada uno, null si no llegó).
     *
     * @return array{format:string,provider:?array{conversations:list<array{id:string,durationSeconds:?int}>,toolCalls:list<string>,guard:?list<array{code:string,turn:int}>},tester:?array{objectives:list<string>,restrictionsBroken:list<string>,interruptions:int,errors:list<string>}}
     */
    public static function findingsOf(StudioVoiceLabSession $s): array
    {
        $g = is_array($s->guard_codes) ? $s->guard_codes : [];

        return [
            'format' => self::FINDINGS_FORMAT,
            'provider' => is_array($g['provider'] ?? null) ? $g['provider'] : null,
            'tester' => is_array($g['tester'] ?? null) ? $g['tester'] : null,
        ];
    }

    // ── Internos ────────────────────────────────────────────────────────────

    /**
     * Las abiertas que ya pasaron su plazo, cerradas: sin URL, fallidas y sin
     * coste (`abandoned`); con URL, terminadas por plazo y con la reserva
     * entera (`deadline`). Detrás del cerrojo del presupuesto.
     */
    private static function expireStale(): void
    {
        $ahora = now();
        StudioVoiceLabSession::query()
            ->where('status', StudioVoiceLabSession::STATUS_OPEN)->whereNull('verdict')
            ->whereNull('url_expires_at')
            ->where('created_at', '<', $ahora->copy()->subSeconds(StudioVoiceLabConfig::OPEN_DEADLINE_SECONDS))
            ->update(['status' => StudioVoiceLabSession::STATUS_FAILED, 'end_reason' => 'abandoned', 'cost_micros' => 0, 'ended_at' => $ahora, 'status_changed_at' => $ahora]);
        StudioVoiceLabSession::query()
            ->where('status', StudioVoiceLabSession::STATUS_OPEN)->whereNull('verdict')
            ->whereNotNull('url_expires_at')
            ->where('url_expires_at', '<', $ahora->copy()->subSeconds(StudioVoiceLabConfig::HARD_MAX_SESSION_SECONDS + StudioVoiceLabConfig::REPORT_GRACE_SECONDS))
            ->update(['status' => StudioVoiceLabSession::STATUS_ENDED, 'end_reason' => 'deadline', 'cost_micros' => DB::raw('reserved_micros'), 'ended_at' => $ahora, 'status_changed_at' => $ahora]);
    }

    /** Se cerró sin gastar: el proveedor no la dejó abrir. */
    private function fail(StudioVoiceLabSession $s, string $endReason): void
    {
        $ahora = now();
        StudioVoiceLabSession::advance((int) $s->id, [StudioVoiceLabSession::STATUS_OPEN], [
            'status' => StudioVoiceLabSession::STATUS_FAILED,
            'end_reason' => array_key_exists($endReason, self::END_REASONS) ? $endReason : 'open_error',
            'cost_micros' => 0,
            'ended_at' => $ahora,
            'status_changed_at' => $ahora,
            'updated_at' => $ahora,
        ]);
    }

    /**
     * EL cerrojo del presupuesto. En PostgreSQL, READ COMMITTED como primera
     * sentencia (en REPEATABLE READ la instantánea la tomaría la sentencia del
     * cerrojo, ANTES de esperar, y quien esperó leería lo comprometido de antes
     * de que la otra confirmara: la lección B-2 de G) y después el cerrojo
     * consultivo, antes de leer lo comprometido.
     */
    private function serializeBudget(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32('ultron_studio_voice_lab:budget')]);
        }
    }

    /**
     * La misma petición repetida devuelve la sesión de la primera. La misma
     * llave para otra cosa (otra persona, otra versión u otro perfil) es 409
     * `client_request_reused`.
     *
     * @param  array{version:string,profile:string}  $peticion
     */
    private function replay(StudioAccess $access, string $clientRequestId, array $peticion): ?StudioVoiceLabSession
    {
        $previa = StudioVoiceLabSession::query()->where('client_request_id', strtolower($clientRequestId))->first();
        if ($previa === null) {
            return null;
        }
        $agente = StudioVoiceLabAgents::find((int) $previa->agent_id);
        $original = [
            'version' => strtolower((string) ($agente !== null ? StudioVersion::query()->whereKey($agente->studio_version_id)->value('uuid') : '')),
            'profile' => strtolower((string) ($agente !== null ? DB::table(StudioVoiceLabAgents::PROFILES)->where('id', $agente->voice_profile_id)->value('uuid') : '')),
        ];
        if ((int) $previa->created_by_admin_id !== $access->id() || $original !== $peticion) {
            throw UltronStudioException::clientRequestReused();
        }

        return $previa;
    }

    /**
     * Lo que contó quien probó, con su forma: códigos de los conjuntos
     * cerrados, sin repetir y en el orden del catálogo. La forma ya la validó
     * el controlador; esto es la defensa en profundidad.
     *
     * @param  array<string, mixed>  $o
     * @return array{objectives:list<string>,restrictionsBroken:list<string>,interruptions:int,errors:list<string>}
     */
    private static function testerReport(array $o): array
    {
        $interrupciones = $o['interruptions'] ?? null;
        if (! is_int($interrupciones) || $interrupciones < 0 || $interrupciones > self::MAX_INTERRUPTIONS) {
            throw new InvalidArgumentException('interruptions fuera de 0–'.self::MAX_INTERRUPTIONS.'.');
        }

        return [
            'objectives' => self::codesIn($o['objectives'] ?? null, self::OBJECTIVES, 'objectives'),
            'restrictionsBroken' => self::codesIn($o['restrictionsBroken'] ?? null, self::RESTRICTIONS, 'restrictionsBroken'),
            'interruptions' => $interrupciones,
            'errors' => self::codesIn($o['errors'] ?? null, self::ERRORS, 'errors'),
        ];
    }

    /**
     * @param  array<string, string>  $catalog
     * @return list<string>
     */
    private static function codesIn(mixed $codes, array $catalog, string $field): array
    {
        if (! is_array($codes) || ! array_is_list($codes)) {
            throw new InvalidArgumentException("{$field} tiene que ser una lista.");
        }
        foreach ($codes as $c) {
            if (! is_string($c) || ! array_key_exists($c, $catalog)) {
                throw new InvalidArgumentException("{$field} lleva un código fuera de su catálogo.");
            }
        }

        return array_values(array_intersect(array_keys($catalog), $codes));
    }

    /**
     * Los códigos de la guarda posterior que llegan con el informe.
     *
     * @param  array<array-key, mixed>  $guard
     * @return list<array{code:string,turn:int}>
     */
    private static function guardEntries(array $guard): array
    {
        if (! array_is_list($guard)) {
            throw new InvalidArgumentException('La guarda llega como una lista.');
        }
        $out = [];
        foreach ($guard as $g) {
            if (! is_array($g) || ! is_string($g['code'] ?? null) || preg_match(self::GUARD_CODE_PATTERN, $g['code']) !== 1
                || ! is_int($g['turn'] ?? null) || $g['turn'] < 0 || $g['turn'] > 10000) {
                throw new InvalidArgumentException('Un código de la guarda no tiene la forma {code, turn}.');
            }
            $out[] = ['code' => $g['code'], 'turn' => $g['turn']];
        }

        return array_slice($out, 0, self::MAX_GUARD_CODES);
    }

    /** La reserva prorrateada por los segundos hablados, hacia arriba: el precio por segundo con el que se reservó. */
    private static function proratedCost(int $reserved, int $maxSeconds, int $seconds): int
    {
        if ($reserved <= 0 || $maxSeconds <= 0) {
            return 0;
        }

        return intdiv($reserved * $seconds + $maxSeconds - 1, $maxSeconds);
    }

    /**
     * Los agentes, las versiones y los perfiles de unas sesiones, en tres
     * consultas.
     *
     * @param  list<StudioVoiceLabSession>  $sessions
     * @return array{agents:array<int,stdClass>,versions:array<int,StudioVersion>,profiles:array<int,stdClass>}
     */
    private static function refsFor(array $sessions): array
    {
        $agentes = StudioVoiceLabAgents::findMany(array_values(array_unique(array_map(static fn (StudioVoiceLabSession $s): int => (int) $s->agent_id, $sessions))));
        $versiones = [];
        $idsVersion = array_values(array_unique(array_map(static fn (stdClass $a): int => (int) $a->studio_version_id, $agentes)));
        if ($idsVersion !== []) {
            foreach (StudioVersion::query()->whereIn('id', $idsVersion)->get(['id', 'uuid', 'label', 'status', 'content_hash', 'created_by_admin_id']) as $v) {
                $versiones[(int) $v->id] = $v;
            }
        }
        $perfiles = [];
        $idsPerfil = array_values(array_unique(array_map(static fn (stdClass $a): int => (int) $a->voice_profile_id, $agentes)));
        if ($idsPerfil !== []) {
            foreach (DB::table(StudioVoiceLabAgents::PROFILES)->whereIn('id', $idsPerfil)->get(['id', 'uuid', 'label', 'revision', 'status', 'content_hash', 'created_by_admin_id']) as $p) {
                $perfiles[(int) $p->id] = $p;
            }
        }

        return ['agents' => $agentes, 'versions' => $versiones, 'profiles' => $perfiles];
    }

    /** El `call_ref` de la conversación del laboratorio: no es un uuid ni el de ninguna llamada (las herramientas responden `call_not_found`). */
    public const CONVERSATION_CALL_REF = 'laboratorio';

    /** La etiqueta de versión de la conversación del laboratorio (en las llamadas es «canario · publicación N»). */
    public const CONVERSATION_VERSION_LABEL = 'laboratorio';

    /**
     * Las variables dinámicas con las que el NAVEGADOR abre la conversación
     * del laboratorio (H8, enmienda E18-1 del 11-11-2026). Viajan con la URL
     * firmada, una sola vez, y el cliente oficial las entrega al abrir.
     *
     * En una llamada las entrega Laravel al proveedor (la saliente,
     * `PlaceOutboundVoiceCall`; la entrante, `InboundGate`). En el laboratorio
     * no hay llamada, y el proveedor NO usa los valores por defecto del agente
     * (son valores de prueba de su consola): sin ellas cierra la conversación
     * con 1008 «Missing required dynamic variables in tools: {'call_ref'}»
     * (primera sesión real, producción, 11-11-2026). Y con el valor por defecto
     * `caller_authorized = no` el agente solo diría que no está disponible.
     *
     * Se ensaya la llamada SALIENTE a una persona autorizada: lo que el agente
     * hará en la fase 4a. `call_ref` no es de ninguna llamada: una herramienta
     * que el agente use en el laboratorio recibe la negativa neutra
     * (`call_not_found`) y no escribe nada.
     *
     * @return array<string, string> exactamente {@see VoiceAgentSpec::VARIABLES}
     */
    public static function conversationVariables(): array
    {
        return [
            VoiceAgentSpec::VAR_CALL_DIRECTION => VoiceAgentSpec::DIRECTION_OUTBOUND,
            VoiceAgentSpec::VAR_CALL_REF => self::CONVERSATION_CALL_REF,
            VoiceAgentSpec::VAR_CALLER_AUTHORIZED => VoiceAgentSpec::CALLER_AUTHORIZED,
            VoiceAgentSpec::VAR_VERSION_LABEL => self::CONVERSATION_VERSION_LABEL,
        ];
    }

    /**
     * SessionDto.
     *
     * @param  array{agents:array<int,stdClass>,versions:array<int,StudioVersion>,profiles:array<int,stdClass>}  $refs
     * @return array<string, mixed>
     */
    private function present(StudioAccess $access, StudioVoiceLabSession $s, array $refs, ?string $signedUrl = null): array
    {
        $agente = $refs['agents'][(int) $s->agent_id] ?? null;
        $version = $agente !== null ? ($refs['versions'][(int) $agente->studio_version_id] ?? null) : null;
        $perfil = $agente !== null ? ($refs['profiles'][(int) $agente->voice_profile_id] ?? null) : null;
        $hallazgos = self::findingsOf($s);
        $proveedor = $hallazgos['provider'];
        $probador = $hallazgos['tester'];
        $guardia = $proveedor['guard'] ?? null;

        $respetadas = match (true) {
            $probador === null && $guardia === null => null,
            default => ($probador === null || $probador['restrictionsBroken'] === []) && ($guardia === null || $guardia === []),
        };
        $errores = $probador['errors'] ?? [];
        if ($s->status === StudioVoiceLabSession::STATUS_FAILED && $s->end_reason !== null) {
            $errores = [$s->end_reason, ...$errores];
        }
        $plazo = self::deadlineOf($s);
        $fin = $s->ended_at ?? now();

        return [
            'uuid' => $s->uuid,
            'status' => $s->status,
            'statusLabel' => self::STATUS_LABELS[$s->status] ?? $s->status,
            'expired' => self::isExpired($s),
            'deadline' => $plazo?->toIso8601String(),
            'mode' => $agente !== null && $agente->provider === StudioVoiceLabAgents::PROVIDER_ELEVENLABS ? StudioVoiceLabConfig::DRIVER_ELEVENLABS : StudioVoiceLabConfig::DRIVER_FAKE,
            'version' => $version !== null ? [
                'uuid' => $version->uuid,
                'label' => $version->label,
                'status' => $version->status,
                'shortHash' => substr((string) $agente->studio_content_hash, 0, 8),
            ] : null,
            'profile' => $perfil !== null ? [
                'uuid' => $perfil->uuid,
                'label' => $perfil->label,
                'revision' => (int) $perfil->revision,
                'status' => $perfil->status,
                'shortHash' => substr((string) $agente->voice_profile_hash, 0, 8),
            ] : null,
            'agent' => $agente !== null ? [
                'uuid' => $agente->uuid,
                'status' => $agente->status,
                'specHash' => $agente->spec_hash,
                'compilerVersion' => $agente->compiler_version,
                'providerAgentId' => $agente->provider_agent_id,
            ] : null,
            'maxSeconds' => (int) $s->max_seconds,
            'budget' => ['day' => (string) $s->budget_day, 'reservedMicros' => (int) $s->reserved_micros, 'costMicros' => $s->cost_micros],
            'connection' => [
                'issued' => $s->url_expires_at !== null,
                'expiresAt' => $s->url_expires_at?->toIso8601String(),
            ] + ($signedUrl !== null ? ['signedUrl' => $signedUrl, 'dynamicVariables' => self::conversationVariables()] : []),
            'metrics' => [
                'durationSeconds' => $s->duration_seconds,
                // Desde la URL hasta el cierre: la ventana, no lo que se habló.
                'windowSeconds' => $s->started_at !== null ? max(0, (int) $s->started_at->diffInSeconds($fin, false)) : null,
                'interruptions' => $probador['interruptions'] ?? null,
                'objectives' => $probador['objectives'] ?? [],
                'restrictions' => [
                    'respected' => $respetadas,
                    'broken' => $probador['restrictionsBroken'] ?? [],
                    'guardViolations' => $guardia,
                ],
                'errors' => $errores,
                'toolCalls' => $proveedor['toolCalls'] ?? [],
                'reported' => ['provider' => $proveedor !== null, 'tester' => $probador !== null],
            ],
            'endReason' => $s->end_reason,
            'endReasonLabel' => $s->end_reason !== null ? (self::END_REASONS[$s->end_reason] ?? null) : null,
            'createdBy' => ['name' => $s->created_by_name, 'role' => $s->created_by_role],
            'mine' => (int) $s->created_by_admin_id === $access->id(),
            'createdAt' => $s->created_at?->toIso8601String(),
            'startedAt' => $s->started_at?->toIso8601String(),
            'endedAt' => $s->ended_at?->toIso8601String(),
            'verdict' => $s->verdict !== null ? [
                'decision' => $s->verdict,
                'note' => $s->verdict_note,
                'by' => ['name' => $s->verdict_by_name, 'role' => $s->verdict_by_role],
                'at' => $s->verdict_at?->toIso8601String(),
            ] : null,
            'actions' => $this->actions($access, $s, $agente, $version, $probador !== null),
        ];
    }

    /**
     * Qué puede hacer quien mira con esta sesión, y si no, por qué. El
     * servidor lo vuelve a comprobar al actuar.
     *
     * @return array{end:array{allowed:bool,code?:string,message?:string},verdict:array{allowed:bool,code?:string,message?:string}}
     */
    private function actions(StudioAccess $access, StudioVoiceLabSession $s, ?stdClass $agente, ?StudioVersion $version, bool $testerReported): array
    {
        $no = static fn (string $code, string $message): array => ['allowed' => false, 'code' => $code, 'message' => $message];

        $terminar = match (true) {
            ! $access->can(self::LAB_RUN) => $no('forbidden', 'Hace falta el permiso «Abrir sesiones del laboratorio hablado».'),
            (int) $s->created_by_admin_id !== $access->id() => $no('voice_lab_not_session_owner', 'Solo quien abrió la sesión la da por terminada.'),
            $s->status === StudioVoiceLabSession::STATUS_FAILED || $s->verdict !== null => $no('voice_lab_session_not_open', 'La sesión ya está cerrada.'),
            $s->isOpen() && $s->url_expires_at === null => $no('voice_lab_session_not_started', 'La sesión todavía no tiene su conexión.'),
            $testerReported => $no('voice_lab_already_reported', 'Ya contaste lo que pasó en esta sesión.'),
            default => ['allowed' => true],
        };

        $motivo = self::notReviewableReason($s, $agente);
        $veredicto = match (true) {
            ! $access->can(StudioAccess::APPROVE) => $no('forbidden', 'Hace falta el permiso «Aprobar o rechazar versiones de otra persona».'),
            $motivo !== null => $no('voice_lab_session_not_reviewable', self::NOT_REVIEWABLE[$motivo]),
            $s->verdict !== null => $no('verdict_exists', 'Esta sesión ya tiene veredicto.'),
            (int) $s->created_by_admin_id === $access->id() => $no('four_eyes_required', 'Nadie emite el veredicto sobre la sesión que abrió: tiene que hacerlo otra persona.'),
            $version !== null && (int) $version->created_by_admin_id === $access->id() => $no('four_eyes_required', 'Nadie emite el veredicto sobre su propia versión: tiene que hacerlo otra persona.'),
            default => ['allowed' => true],
        };

        return ['end' => $terminar, 'verdict' => $veredicto];
    }

    /**
     * @param  array<string, string>  $catalog
     * @return list<array{code:string,label:string}>
     */
    private static function catalog(array $catalog): array
    {
        $out = [];
        foreach ($catalog as $code => $label) {
            $out[] = ['code' => $code, 'label' => $label];
        }

        return $out;
    }
}
