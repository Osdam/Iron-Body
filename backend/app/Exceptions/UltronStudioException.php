<?php

namespace App\Exceptions;

use App\Services\UltronStudio\StudioLabConfig;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Lo que ULTRON STUDIO no permite, dicho para el CRM: un borrador que cambió en
 * otra pestaña, una versión que no admite esa transición, aprobar lo propio,
 * publicar mientras el motor no lee versiones…
 *
 * Mismo patrón que {@see WorkforceException}: un código ESTABLE que el CRM usa
 * para decidir (contrato docs/ultron-studio/ULTRON_STUDIO_F_CONTRACT.md §A3,
 * «Errores»), el estado HTTP y, cuando hace falta, datos que viajan junto al
 * error (`current`, `findings`, `warnings`, `currentStatus`…). Se lanza dentro
 * de la transacción cuando hay que deshacer: al salir no queda nada escrito.
 *
 * Los mensajes no llevan internals (consultas, rutas, clases): solo lo que la
 * persona necesita para seguir.
 */
class UltronStudioException extends RuntimeException
{
    /** @param  array<string, mixed>  $payload */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 409,
        public readonly array $payload = [],
    ) {
        parent::__construct($message);
    }

    /**
     * Un rechazo del dominio es una respuesta prevista, no un fallo del
     * servidor: no va al log de errores, donde taparía los de verdad. Lo que
     * importa para la traza ya queda en `ultron_studio_audit_events`.
     *
     * Laravel no registra la excepción si esto devuelve algo distinto de false.
     */
    public function report(): bool
    {
        return $this->status < 500;
    }

    public function render(): JsonResponse
    {
        return response()->json(array_merge([
            'ok' => false,
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
        ], $this->payload), $this->status);
    }

    // ── 403 ─────────────────────────────────────────────────────────────────

    public static function personRequired(): self
    {
        return new self('El Studio de ULTRON lo usa una persona: entra con tu cuenta del CRM.', 'person_required', 403);
    }

    public static function forbidden(string $permission): self
    {
        return new self('No tienes permiso para esta acción.', 'forbidden', 403, ['required_permission' => $permission]);
    }

    public static function notVersionOwner(): self
    {
        return new self('Solo quien creó la versión puede enviarla a revisión.', 'not_version_owner', 403);
    }

    public static function fourEyesRequired(): self
    {
        return new self('Nadie aprueba ni rechaza lo que creó: pide a otra persona con permiso que revise esta versión.', 'four_eyes_required', 403);
    }

    // ── 404 ─────────────────────────────────────────────────────────────────

    public static function versionNotFound(): self
    {
        return new self('La versión no existe.', 'version_not_found', 404);
    }

    public static function ruleNotFound(): self
    {
        return new self('La regla no existe.', 'rule_not_found', 404);
    }

    public static function draftNotFound(): self
    {
        return new self('No tienes un borrador guardado. Guárdalo antes de crear una versión: la versión es una copia del borrador guardado.', 'draft_not_found', 404);
    }

    // ── 409 ─────────────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $current  el borrador tal como está en el servidor (forma de GET draft) */
    public static function draftConflict(array $current): self
    {
        return new self(
            'El borrador cambió en otra pestaña o en otro equipo. Recarga para ver lo guardado, o sobrescríbelo.',
            'draft_conflict',
            409,
            ['current' => $current],
        );
    }

    /** @param  array<string, mixed>  $current  la regla tal como está en el servidor */
    public static function ruleConflict(array $current): self
    {
        return new self('La regla cambió mientras la editabas. Revisa cómo quedó antes de seguir.', 'rule_conflict', 409, ['current' => $current]);
    }

    /** @param  list<string>  $allowedFrom */
    public static function invalidTransition(string $currentStatus, string $currentLabel, string $action, array $allowedFrom): self
    {
        return new self(
            "Una versión en estado «{$currentLabel}» no admite «{$action}».",
            'invalid_transition',
            409,
            ['currentStatus' => $currentStatus, 'allowedFrom' => $allowedFrom],
        );
    }

    /** @param  list<string>  $warnings  los avisos actuales: lo que hay que reconocer */
    public static function warningsNotAcknowledged(array $warnings): self
    {
        return new self(
            'Para aprobar hay que reconocer exactamente los avisos actuales de la versión, ni uno más ni uno menos.',
            'warnings_not_acknowledged',
            409,
            ['warnings' => $warnings],
        );
    }

    /** @param  list<array<string, mixed>>  $findings */
    public static function revalidationBlocked(array $findings): self
    {
        return new self(
            'La versión ya no pasa la validación con el catálogo actual: tiene bloqueos y no se puede aprobar.',
            'revalidation_blocked',
            409,
            ['findings' => $findings],
        );
    }

    public static function integrityMismatch(): self
    {
        return new self(
            'La huella de la versión no coincide con su contenido. No se puede enviar ni aprobar: avisa a quien administra el sistema.',
            'integrity_mismatch',
        );
    }

    public static function publishingRefused(string $code, string $message): self
    {
        return new self($message, $code);
    }

    public static function legacyRuleReadOnly(): self
    {
        return new self(
            'Las reglas del código son de solo lectura: las aplica el motor y solo cambian cuando cambia el código.',
            'legacy_rule_read_only',
        );
    }

    public static function ruleArchived(): self
    {
        return new self('La regla está archivada: ya no se edita ni se vuelve a archivar.', 'rule_archived');
    }

    public static function ruleLimitReached(int $max): self
    {
        return new self(
            "El Studio admite como mucho {$max} reglas propias sin archivar. Archiva alguna antes de crear otra.",
            'rule_limit_reached',
        );
    }

    /**
     * Hito G (contrato §A3): las condiciones contradicen otra regla del Studio
     * VIVA. Lo que choca con un control del motor no llega aquí: es un 422.
     * Los códigos y uuids de las reglas ya los ve cualquiera con `ultron.view`.
     *
     * @param  list<array{code:string,uuid:string,revision:int,target:string,label:string}>  $conflicts
     */
    public static function ruleContradiction(array $conflicts): self
    {
        return new self(
            'Estas condiciones contradicen otra regla del Studio. Cámbialas, o edita o archiva la otra regla antes de guardar.',
            'rule_contradiction',
            409,
            ['conflicts' => $conflicts],
        );
    }

    public static function clientRequestReused(): self
    {
        return new self(
            'Ese identificador de petición ya se usó para otra operación. Vuelve a intentarlo desde el Studio.',
            'client_request_reused',
        );
    }

    // ── 422 ─────────────────────────────────────────────────────────────────

    /** @param  list<array<string, mixed>>  $findings */
    public static function validationBlocked(string $message, array $findings): self
    {
        return new self($message, 'validation_blocked', 422, ['findings' => $findings]);
    }

    // ── Laboratorio de IA (Hito 4, contrato H4 §A7) ─────────────────────────
    //
    // Los códigos que el CRM usa para decidir. Los importes van en micro-US$
    // enteros; ninguno lleva nada del proveedor ni de la configuración secreta.

    public static function labDisabled(): self
    {
        return new self('El laboratorio está apagado en el servidor.', 'lab_disabled');
    }

    /**
     * @param  list<string>  $reasons  los de {@see StudioLabConfig::REASONS}
     * @param  ?string  $mode  `fake`, `openai` o null con un driver no válido (Enmienda 3)
     */
    public static function labNotConfigured(array $reasons, ?string $mode): self
    {
        return new self(
            'El laboratorio no está configurado para ejecutar en este modo. Lo arregla quien administra el servidor.',
            'lab_not_configured',
            409,
            ['reasons' => array_values($reasons), 'mode' => $mode],
        );
    }

    public static function labDailyRunsExceeded(int $runsToday, int $maxRunsPerDay): self
    {
        return new self(
            "El laboratorio ya llegó a su máximo de ejecuciones de hoy ({$maxRunsPerDay}). Vuelve a intentarlo mañana.",
            'lab_daily_runs_exceeded',
            409,
            ['runsToday' => $runsToday, 'maxRunsPerDay' => $maxRunsPerDay],
        );
    }

    public static function labRunBudgetExceeded(int $requiredMicros, int $capMicros): self
    {
        return new self(
            'Esta ejecución podría costar más que el tope por ejecución. Quita escenarios o pide que suban el tope.',
            'lab_run_budget_exceeded',
            409,
            ['requiredMicros' => $requiredMicros, 'capMicros' => $capMicros],
        );
    }

    /** @param  array<string, mixed>  $estimate  la estimación recalculada (EstimateDto completo) */
    public static function estimateChanged(array $estimate): self
    {
        return new self(
            'La estimación cambió desde que la viste: revisa el nuevo máximo y vuelve a confirmar.',
            'estimate_changed',
            409,
            ['estimate' => $estimate],
        );
    }

    public static function labDailyBudgetExceeded(int $requiredMicros, int $remainingMicros): self
    {
        return new self(
            'No queda presupuesto del día para esta ejecución. Quita escenarios o espera a mañana.',
            'lab_daily_budget_exceeded',
            409,
            ['requiredMicros' => $requiredMicros, 'remainingMicros' => $remainingMicros],
        );
    }

    /**
     * Enmienda 3: algo de lo que escribió el personal en el objetivo (una
     * etiqueta de parámetro, la descripción o la frase de una regla) no pasa
     * el filtro de datos personales. La entrada al modelo no se construye.
     *
     * @param  list<string>  $fields  `configs.<capa>.<clave>` o `rules.<código>`
     */
    public static function targetContainsPersonalData(array $fields): self
    {
        return new self(
            'La configuración o las reglas del objetivo llevan un texto que parece un dato personal o un carácter invisible, y el laboratorio no lo envía a un proveedor externo. Corrígelo en el Studio.',
            'target_contains_personal_data',
            409,
            ['fields' => array_values($fields)],
        );
    }

    public static function scenarioNotFound(): self
    {
        return new self('El escenario no existe.', 'scenario_not_found', 404);
    }

    /** @param  array<string, mixed>  $current  el escenario tal como está en el servidor (ScenarioDto) */
    public static function scenarioConflict(array $current): self
    {
        return new self('El escenario cambió mientras lo editabas. Revisa cómo quedó antes de seguir.', 'scenario_conflict', 409, ['current' => $current]);
    }

    public static function scenarioLimitReached(int $max): self
    {
        return new self(
            "El laboratorio admite como mucho {$max} escenarios propios sin archivar. Archiva alguno antes de crear otro.",
            'scenario_limit_reached',
        );
    }

    public static function catalogScenarioReadOnly(): self
    {
        return new self(
            'Los escenarios del catálogo son de solo lectura: solo cambian cuando cambia el código.',
            'catalog_scenario_read_only',
        );
    }

    public static function scenarioArchived(): self
    {
        return new self('El escenario está archivado: ya no se edita ni se vuelve a archivar.', 'scenario_archived');
    }

    /** Idéntico para un uuid que no existe y para una ejecución sobre el borrador de otra persona (§A3). */
    public static function runNotFound(): self
    {
        return new self('La ejecución no existe.', 'run_not_found', 404);
    }

    public static function runNotCancellable(string $currentStatus): self
    {
        return new self('La ejecución ya terminó: no hay nada que cancelar.', 'run_not_cancellable', 409, ['currentStatus' => $currentStatus]);
    }

    /** @param  string  $reason  `not_completed`, `fake_mode` o `target_not_version` (§A6) */
    public static function runNotReviewable(string $reason, string $message): self
    {
        return new self($message, 'run_not_reviewable', 409, ['reason' => $reason]);
    }

    /** Los cuatro ojos del laboratorio: mismo código que F, con el texto del veredicto. */
    public static function labFourEyesRequired(): self
    {
        return new self(
            'Nadie emite el veredicto sobre su propia versión: pide a otra persona con permiso que lo emita.',
            'four_eyes_required',
            403,
        );
    }

    public static function verdictExists(): self
    {
        return new self('Esta ejecución ya tiene veredicto: se emite uno solo.', 'verdict_exists');
    }

    /** @param  list<string>  $cases  los códigos de escenario que hay que reconocer */
    public static function casesNotAcknowledged(array $cases): self
    {
        return new self(
            'Para aprobar hay que reconocer exactamente los casos que no pasaron (fallo, revisión, error u omitido), ni uno más ni uno menos.',
            'cases_not_acknowledged',
            409,
            ['cases' => array_values($cases)],
        );
    }

    public static function runIntegrityMismatch(): self
    {
        return new self(
            'La huella de los resultados no coincide con la que viste o con lo guardado. Vuelve a abrir la ejecución antes de emitir el veredicto.',
            'run_integrity_mismatch',
        );
    }
}
