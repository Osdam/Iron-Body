<?php

namespace App\Exceptions;

use App\Services\UltronStudio\StudioLabConfig;
use App\Services\UltronStudio\StudioPromptBundle;
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
    public static function revalidationBlocked(array $findings, string $message = 'La versión ya no pasa la validación con el catálogo actual: tiene bloqueos y no se puede aprobar.'): self
    {
        return new self($message, 'revalidation_blocked', 409, ['findings' => $findings]);
    }

    public static function integrityMismatch(string $message = 'La huella de la versión no coincide con su contenido. No se puede enviar ni aprobar: avisa a quien administra el sistema.'): self
    {
        return new self($message, 'integrity_mismatch');
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

    // ── Publicación (Hito 5, contrato H5 §A5 y §A7) ─────────────────────────
    //
    // Las cargas son las que lee el CRM (informe-front-datos §4.2): la vista
    // previa nueva en `preview`, la evidencia en `evidence`, y las listas de
    // §A1 por su nombre. Ninguna lleva ids de conversación, textos ni secretos.

    /** Los cuatro ojos al publicar y al promover: mismo código que F, con su texto. */
    public static function publicationFourEyesRequired(string $action): self
    {
        $verbo = $action === 'promote' ? 'promueve' : 'publica';

        return new self(
            "Nadie {$verbo} lo que creó: pide a otra persona con permiso que lo haga.",
            'four_eyes_required',
            403,
        );
    }

    /** @param  list<string>  $warnings  los avisos actuales, que la reseña no reconoció */
    public static function revalidationChanged(array $warnings): self
    {
        return new self(
            'El catálogo cambió después de aprobar: los avisos de la versión ya no son los que se reconocieron. Crea una versión nueva y vuelve a aprobarla.',
            'revalidation_changed',
            409,
            ['warnings' => array_values($warnings)],
        );
    }

    /** @param  string|null  $latest  null | rejected | needs_changes | stale_prompts */
    public static function labVerdictRequired(?string $latest): self
    {
        $porque = match ($latest) {
            null => 'no tiene ningún veredicto del laboratorio',
            'rejected' => 'su último veredicto del laboratorio la rechazó',
            'needs_changes' => 'su último veredicto del laboratorio pide cambios',
            default => 'su veredicto del laboratorio es de una versión anterior de los prompts',
        };

        return new self(
            "No se puede publicar: esta versión {$porque}. Hace falta un veredicto «aprobada» sobre esta misma huella.",
            'lab_verdict_required',
            409,
            ['latest' => $latest],
        );
    }

    /** @param  list<array{param:string,versionValue:mixed,engineValue:mixed}>  $mismatches */
    public static function engineValueMismatch(array $mismatches): self
    {
        return new self(
            'La versión declara valores distintos de los que lee hoy el motor. Una declaración que contradice al motor sería falsa: crea una versión que refleje el motor.',
            'engine_value_mismatch',
            409,
            ['mismatches' => array_values($mismatches)],
        );
    }

    /** @param  list<string>  $params  `capa.clave` */
    public static function engineReadingUnavailable(array $params): self
    {
        return new self(
            'No se pudo leer lo que tiene hoy el motor en algún interruptor: sin esa lectura no se publica. Vuelve a intentarlo en un momento.',
            'engine_reading_unavailable',
            409,
            ['engineReadingUnavailable' => array_values($params)],
        );
    }

    /** @param  list<string>  $noEffect  el conjunto que hay que reconocer, en su orden */
    public static function noEffectNotAcknowledged(array $noEffect): self
    {
        return new self(
            'Para publicar hay que reconocer exactamente lo que no tendrá efecto en el motor, ni uno más ni uno menos.',
            'no_effect_not_acknowledged',
            409,
            ['noEffect' => array_values($noEffect)],
        );
    }

    /** @param  array<string, mixed>  $preview  la vista previa nueva (PublicationPreviewDto) */
    public static function previewStale(array $preview): self
    {
        return new self(
            'La vista previa cambió desde que la viste: revísala y vuelve a confirmar.',
            'preview_stale',
            409,
            ['preview' => $preview],
        );
    }

    public static function engineHasNoCanary(): self
    {
        return new self(
            'El motor no tiene configurada la conversación canaria: sin ella no se publica (toda publicación empieza en canario).',
            'engine_has_no_canary',
        );
    }

    public static function engineBraked(?string $message = null): self
    {
        return new self(
            $message ?? 'El freno de ULTRON está echado: no se publica ni se promueve hasta que alguien lo suelte.',
            'engine_braked',
        );
    }

    public static function canaryInProgress(): self
    {
        return new self(
            'Ya hay otra versión en canario. Promuévela o revértela antes de publicar otra.',
            'canary_in_progress',
        );
    }

    public static function outsidePublicationWindow(?string $window): self
    {
        return new self(
            "Fuera de la ventana de publicación ({$window}, hora de Bogotá). Revertir y volver a Legacy se pueden hacer siempre.",
            'outside_publication_window',
            409,
            ['window' => $window],
        );
    }

    public static function scopeNotAllowed(): self
    {
        return new self(
            'Promover exige que el motor lea la cadena en todas las conversaciones (su llave en «all»). Hoy solo la lee en la canaria.',
            'scope_not_allowed',
        );
    }

    /** @param  array<string, mixed>  $evidence  la evidencia de ahora (CanaryEvidenceDto) */
    public static function canaryEvidenceInsufficient(array $evidence): self
    {
        return new self(
            'La evidencia del canario todavía no basta para promover. Revisa qué falta.',
            'canary_evidence_insufficient',
            409,
            ['evidence' => $evidence],
        );
    }

    /** @param  array<string, mixed>  $evidence  la evidencia de ahora (CanaryEvidenceDto) */
    public static function evidenceChanged(array $evidence): self
    {
        return new self(
            'La evidencia del canario cambió desde que la viste: revísala y vuelve a confirmar.',
            'evidence_changed',
            409,
            ['evidence' => $evidence],
        );
    }

    public static function notActivePublication(string $currentStatus): self
    {
        return new self(
            'Solo se revierte la versión en canario o la publicada vigente.',
            'not_active_publication',
            409,
            ['currentStatus' => $currentStatus],
        );
    }

    public static function restoreIntegrityMismatch(): self
    {
        return new self(
            'La versión anterior que volvería no pasa la verificación (huella o firma alterada): no se restaura. La salida segura es volver a Legacy, y avisa a quien administra el sistema.',
            'restore_integrity_mismatch',
        );
    }

    public static function alreadyLegacy(): self
    {
        return new self('La cadena ya está en Legacy: no declara ninguna versión.', 'already_legacy');
    }

    public static function publicationMoved(): self
    {
        return new self(
            'La cadena de publicaciones cambió mientras tanto (otra persona publicó, promovió o revirtió). Vuelve a cargar y repite si sigue teniendo sentido.',
            'publication_moved',
        );
    }

    /**
     * La cabeza de la cadena no pasa su verificación (huella, firma o forma):
     * nada se compone encima, porque se copiaría a una entrada firmada nueva.
     * Volver a Legacy no copia nada y sigue disponible. (Desviación del informe
     * del paquete B-S: el contrato no lo fijaba.)
     */
    public static function publicationHeadInvalid(): self
    {
        return new self(
            'La última entrada de la cadena de publicaciones no pasa la verificación (huella o firma): no se compone nada encima. Vuelve a Legacy para empezar una cadena limpia y avisa a quien administra el sistema.',
            'publication_head_invalid',
        );
    }

    /**
     * Enmienda M-1 (plan de motores §B.4): con la llave de prompts del motor
     * encendida, el paquete de prompts de la versión (o el de la parte que la
     * entrada arrastra) no compila con el código de hoy. Nunca se firma una
     * parte que no compila. Sin el motivo del compilador: va al log.
     */
    public static function promptsCompileFailed(): self
    {
        return new self(
            'Los prompts de ULTRON no se pueden compilar con los valores de esta publicación: no se publica nada. Avisa a quien administra el sistema.',
            'prompts_compile_failed',
        );
    }

    /**
     * Compilador de prompts 2 (reglas S-NNN en WhatsApp): las frases que
     * prohíben las reglas de la versión no caben en los prompts de WhatsApp.
     * Nunca se recortan ni se descartan: no se publica nada. `problems` es la
     * lista de `StudioPromptBundle::phrases()` (`{rule, reason, phrase}`; la
     * frase es texto de la propia versión, ya validado como texto plano al
     * crear la regla).
     *
     * `part` dice de quién son esas frases (vuelta 2, B-1 de seguridad y
     * hallazgo 1 del reviewer): `version`, la que se publica; `carried`, la
     * versión que hoy rige el resto de las conversaciones, cuya parte `/1` esta
     * publicación convertiría a `/2` con sus frases. Entonces la causa no está
     * en lo que se publica y el mensaje da la salida.
     *
     * @param  list<array<string, mixed>>  $problems
     * @param  string  $part  `StudioPromptBundle::PHRASES_PART_VERSION` o `StudioPromptBundle::PHRASES_PART_CARRIED`
     */
    public static function promptsRulePhrasesInvalid(array $problems, string $part = StudioPromptBundle::PHRASES_PART_VERSION): self
    {
        $reglas = 'como mucho 12 frases, de 3 a 60 caracteres, con al menos una letra o una cifra, solo con letras, cifras, espacios y , . ¿ ? ¡ ! -';

        return new self(
            $part === StudioPromptBundle::PHRASES_PART_CARRIED
                ? 'No se publica nada, y la causa no está en esta versión: está en la versión que hoy rige el resto de las conversaciones. Esta publicación llevaría su parte a los prompts de WhatsApp con las frases que prohíben sus reglas del Studio, y esas frases no caben: '.$reglas.'. La salida: «Volver a Legacy» antes de publicar; si quieres conservar esas reglas, corrígelas y publica otra versión.'
                : 'Las frases que prohíben las reglas del Studio de esta versión no caben en los prompts de WhatsApp: '.$reglas.'. No se publica nada: corrige las reglas y crea una versión nueva.',
            'prompts_rule_phrases_invalid',
            409,
            ['problems' => $problems, 'part' => $part === StudioPromptBundle::PHRASES_PART_CARRIED ? StudioPromptBundle::PHRASES_PART_CARRIED : StudioPromptBundle::PHRASES_PART_VERSION],
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

    // ── Integraciones comerciales (Hito 6, contrato H6 §I7.3) ───────────────
    //
    // Asociaciones de campaña, números canarios y configuración autorizada son
    // REGISTRO del Studio: ningún texto promete un efecto en el motor. Ninguna
    // carga lleva teléfonos, alias, ids de Meta, secretos ni sus huellas: solo
    // los DTO públicos (ids opacos, «***» y cuatro dígitos, número enmascarado).

    public static function integrationsWritesDisabled(): self
    {
        return new self(
            'Las escrituras de las integraciones están apagadas en el servidor. Leer sigue disponible.',
            'integrations_writes_disabled',
        );
    }

    /** @param  string  $reason  el problema de `MetaAdsApiClient::configurationProblem()` */
    public static function metaNotConfigured(string $reason): self
    {
        return new self(
            'Meta Ads no está configurado en el servidor: no hay campañas que asociar.',
            'meta_not_configured',
            409,
            ['reason' => $reason],
        );
    }

    public static function campaignNotFound(): self
    {
        return new self('La campaña no está entre las de las cuentas de Meta conectadas.', 'campaign_not_found', 404);
    }

    public static function linkNotFound(): self
    {
        return new self('La asociación no existe.', 'link_not_found', 404);
    }

    public static function versionNotLinkable(string $currentStatus): self
    {
        return new self(
            'Solo se asocia una versión aprobada, en canario o publicada.',
            'version_not_linkable',
            409,
            ['currentStatus' => $currentStatus],
        );
    }

    /** @param  array<string, mixed>  $current  la asociación activa (CampaignLinkDto) */
    public static function campaignAlreadyLinked(array $current): self
    {
        return new self(
            'La campaña ya tiene una asociación activa. Para cambiarla, sustitúyela.',
            'campaign_already_linked',
            409,
            ['current' => $current],
        );
    }

    /** @param  array<string, mixed>|null  $current  la asociación activa de ahora (CampaignLinkDto) o null */
    public static function campaignLinkMoved(?array $current): self
    {
        return new self(
            'La asociación que querías sustituir ya no es la activa de esta campaña. Vuelve a cargar y repite si sigue teniendo sentido.',
            'campaign_link_moved',
            409,
            ['current' => $current],
        );
    }

    public static function campaignLinksLimit(int $max): self
    {
        return new self(
            "El Studio admite como mucho {$max} asociaciones de campaña activas. Desactiva alguna antes de crear otra.",
            'campaign_links_limit',
            409,
            ['max' => $max],
        );
    }

    /**
     * Una transición que la asociación no admite (solo se desactiva una activa).
     *
     * @param  list<string>  $allowedFrom
     */
    public static function campaignLinkInvalidTransition(string $currentStatus, array $allowedFrom): self
    {
        return new self(
            'La asociación ya no está activa: no se puede desactivar.',
            'invalid_transition',
            409,
            ['currentStatus' => $currentStatus, 'allowedFrom' => $allowedFrom],
        );
    }

    public static function canaryNotFound(): self
    {
        return new self('El número canario no existe.', 'canary_not_found', 404);
    }

    public static function canaryNumberExists(): self
    {
        return new self(
            'Ese número ya está registrado en este canal (pendiente, autorizado o activo). Revócalo antes de registrarlo otra vez.',
            'canary_number_exists',
        );
    }

    public static function canaryLiveLimit(int $max, string $channel): self
    {
        return new self(
            "El registro admite como mucho {$max} números vivos por canal. Revoca alguno antes de registrar otro.",
            'canary_live_limit',
            409,
            ['max' => $max, 'channel' => $channel],
        );
    }

    public static function canaryActiveLimit(int $max, string $channel): self
    {
        return new self(
            "El registro admite como mucho {$max} números activos por canal. Desactiva alguno antes de activar otro.",
            'canary_active_limit',
            409,
            ['max' => $max, 'channel' => $channel],
        );
    }

    /** Los cuatro ojos de los números canarios: mismo código que F, con su texto. */
    public static function canaryFourEyesRequired(string $action): self
    {
        $verbo = $action === 'activate' ? 'activa' : 'autoriza';

        return new self(
            "Nadie {$verbo} un número que registró: tiene que hacerlo otra persona con permiso.",
            'four_eyes_required',
            403,
            ['action' => $action],
        );
    }

    /**
     * Una transición que el número no admite desde su estado de ahora. Si
     * `currentStatus` ya es el destino, el CRM lo lee como «ya estaba hecho».
     *
     * @param  list<string>  $allowedFrom
     */
    public static function canaryInvalidTransition(string $currentStatus, string $currentLabel, string $action, array $allowedFrom): self
    {
        return new self(
            "Un número canario en estado «{$currentLabel}» no admite «{$action}».",
            'invalid_transition',
            409,
            ['currentStatus' => $currentStatus, 'allowedFrom' => $allowedFrom],
        );
    }

    /** @param  list<array{key:string,authorized:mixed,effective:mixed}>  $drift */
    public static function configChanged(string $provider, string $effectiveFingerprint, array $drift): self
    {
        return new self(
            'La configuración cambió desde que la viste: revisa lo que cambió y vuelve a reconocerla.',
            'config_changed',
            409,
            ['provider' => $provider, 'effectiveFingerprint' => $effectiveFingerprint, 'drift' => array_values($drift)],
        );
    }

    public static function probeDisabled(): self
    {
        return new self('La comprobación de Twilio está apagada en el servidor.', 'probe_disabled');
    }

    /** @param  string  $reason  `missing_account_sid` | `missing_credential` */
    public static function twilioNotConfigured(string $reason): self
    {
        return new self(
            'Twilio no tiene la cuenta o la credencial que necesita la comprobación. Se arregla en el servidor.',
            'twilio_not_configured',
            409,
            ['reason' => $reason],
        );
    }
}
