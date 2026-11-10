<?php

namespace App\Services\UltronStudio;

use App\Exceptions\UltronStudioException;
use DateTimeInterface;

/**
 * LAS LLAVES DEL GOBIERNO DE ESTRATEGIAS (contrato H9, D9-11, D9-13, §9.1) y lo
 * que se sabe sin tocar la base: banderas, ventana, umbrales del canario, la
 * puerta de la simulación y los permisos. Las puertas que leen la base son de
 * {@see StudioStrategyPublishing} y {@see StudioStrategyVersions}.
 *
 * TODO NACE APAGADO (protección 1):
 *  - `ULTRON_STUDIO_STRATEGIES_ENABLED=false`: TODA ruta `strategies/**`
 *    responde 409 `strategies_disabled` ({@see assertEnabled()}), también las
 *    lecturas, después de la persona y antes de buscar nada (sin oráculo de
 *    existencia).
 *  - `ULTRON_STUDIO_STRATEGIES_PUBLISHING_ENABLED=false`: publicar, promover y
 *    reanudar, 409 `strategies_publishing_disabled`. Pausar y revertir no
 *    dependen de ella (el sentido seguro, como H5 §A5.3).
 *
 * Solo `true` (o 1/on/yes) enciende: la configuración ya lo normaliza con
 * `filter_var`, y aquí se compara con `=== true` (una cadena no enciende).
 */
final class StudioStrategyConfig
{
    // ── Permisos (D9-13) ────────────────────────────────────────────────────

    /** Crear estrategias y plantillas, editar el borrador, validar, simular con datos sintéticos y congelar versiones. */
    public const EDIT = 'ultron.strategies_edit';

    /** Publicar en canario, promover y reanudar (solo Super Admin por defecto). */
    public const PUBLISH = 'ultron.strategies_publish';

    /** Pausar y revertir (el sentido seguro). */
    public const ROLLBACK = 'ultron.strategies_rollback';

    /** Crear, verificar y revocar vínculos cuenta → persona, nunca el propio (solo Super Admin). */
    public const PERSON_BINDINGS = 'ultron.person_bindings_manage';

    /** Simular sobre una muestra REAL (seudonimizada). */
    public const SIMULATE_REAL = 'commercial_automation.simulate_real';

    /** Aprobar o rechazar versiones y plantillas de OTRA persona (existente). */
    public const APPROVE = StudioAccess::APPROVE;

    public const VIEW = StudioAccess::VIEW;

    // ── Los sujetos del libro (D9-11.5) ─────────────────────────────────────
    //
    // Los admite el CHECK `usae_subject_type_chk` desde la migración del
    // gobierno del H9 (DB9: 7 → 11 sujetos; `subject_type` es varchar(16) y
    // los cuatro caben). Viven aquí y no en `StudioAuditEvent::SUBJECT_TYPES`
    // (fichero del H6, que S9 no toca): ampliar esa lista (el filtro del libro
    // en `GET audit`) es una petición al lead.

    public const SUBJECT_STRATEGY = 'strategy';

    public const SUBJECT_STRATEGY_VERSION = 'strategy_version';

    public const SUBJECT_MESSAGE_TEMPLATE = 'message_template';

    public const SUBJECT_PERSON_BINDING = 'person_binding';

    public const SUBJECT_TYPES = [self::SUBJECT_STRATEGY, self::SUBJECT_STRATEGY_VERSION, self::SUBJECT_MESSAGE_TEMPLATE, self::SUBJECT_PERSON_BINDING];

    // ── Códigos de rechazo ──────────────────────────────────────────────────

    public const CODE_DISABLED = 'strategies_disabled';

    public const CODE_PUBLISHING_DISABLED = 'strategies_publishing_disabled';

    public const GATE_REQUIRED = 'required';

    public const GATE_WAIVED = 'waived';

    /** Los mínimos DUROS del canario (§9.1): 1 h y 1 contacto, aunque el entorno diga menos. */
    public const MIN_HOURS_FLOOR = 1;

    public const MIN_HOURS_CEILING = 168;

    public const MIN_TOUCHES_FLOOR = 1;

    public const MIN_TOUCHES_CEILING = 100;

    public const DEFAULT_MIN_HOURS = 24;

    public const DEFAULT_MIN_TOUCHES = 3;

    public static function enabled(): bool
    {
        return config('ultron_studio.strategies.enabled') === true;
    }

    public static function publishingEnabled(): bool
    {
        return config('ultron_studio.strategies.publishing_enabled') === true;
    }

    /** `required` (defecto) o `waived`, que solo decide el propietario. */
    public static function simulationGate(): string
    {
        return config('ultron_studio.strategies.simulation_gate') === self::GATE_WAIVED ? self::GATE_WAIVED : self::GATE_REQUIRED;
    }

    /**
     * La ventana de publicar, promover y reanudar (hora de Bogotá, [inicio, fin)),
     * con el MISMO lector que la del Hito 5; null = sin ventana.
     *
     * @return array{start:int,end:int,label:string}|null
     */
    public static function window(): ?array
    {
        return StudioPublishing::windowFor(config('ultron_studio.strategies.window', StudioPublishing::DEFAULT_WINDOW));
    }

    public static function windowOpen(DateTimeInterface $now): bool
    {
        return StudioPublishing::windowOpenFor(self::window(), $now);
    }

    /**
     * Los umbrales de la evidencia del canario, acotados otra vez a sus topes
     * duros (la configuración ya los recorta; esto es defensa en profundidad).
     *
     * @return array{minHours:int,minTouches:int}
     */
    public static function canaryThresholds(): array
    {
        $acotar = static fn (mixed $v, int $defecto, int $min, int $max): int => is_int($v) ? min($max, max($min, $v)) : $defecto;

        return [
            'minHours' => $acotar(config('ultron_studio.strategies.canary_min_hours'), self::DEFAULT_MIN_HOURS, self::MIN_HOURS_FLOOR, self::MIN_HOURS_CEILING),
            'minTouches' => $acotar(config('ultron_studio.strategies.canary_min_touches'), self::DEFAULT_MIN_TOUCHES, self::MIN_TOUCHES_FLOOR, self::MIN_TOUCHES_CEILING),
        ];
    }

    /**
     * La bandera maestra del gobierno de estrategias.
     *
     * @throws UltronStudioException 409 `strategies_disabled`
     */
    public static function assertEnabled(): void
    {
        if (! self::enabled()) {
            throw new UltronStudioException('Las estrategias del Studio están desactivadas en el servidor: las enciende quien administra el sistema.', self::CODE_DISABLED, 409);
        }
    }

    /**
     * La bandera de publicar, promover y reanudar.
     *
     * @throws UltronStudioException 409 `strategies_publishing_disabled`
     */
    public static function assertPublishingEnabled(): void
    {
        if (! self::publishingEnabled()) {
            throw new UltronStudioException('Publicar estrategias está desactivado en el servidor: lo enciende quien administra el sistema.', self::CODE_PUBLISHING_DISABLED, 409);
        }
    }

    /**
     * La ventana de publicar, promover y reanudar.
     *
     * @throws UltronStudioException 409 `outside_publication_window`
     */
    public static function assertWindow(DateTimeInterface $now): void
    {
        if (! self::windowOpen($now)) {
            throw UltronStudioException::outsidePublicationWindow(self::window()['label'] ?? null);
        }
    }

    /**
     * El bloque `governance` de las lecturas: banderas, ventana, puerta y umbrales.
     *
     * @return array{enabled:bool,publishing:array{enabled:bool,code:string|null,window:array{label:string|null,open:bool}},simulationGate:string,canary:array{minHours:int,minTouches:int}}
     */
    public static function status(DateTimeInterface $now): array
    {
        return [
            'enabled' => self::enabled(),
            'publishing' => [
                'enabled' => self::publishingEnabled(),
                'code' => self::publishingEnabled() ? null : self::CODE_PUBLISHING_DISABLED,
                'window' => ['label' => self::window()['label'] ?? null, 'open' => self::windowOpen($now)],
            ],
            'simulationGate' => self::simulationGate(),
            'canary' => self::canaryThresholds(),
        ];
    }

    /**
     * El bloque `can` de las lecturas: lo que puede hacer quien mira (cada
     * llave presupone ver el Studio, como {@see StudioAccess::require()}).
     *
     * @return array{edit:bool,approve:bool,publish:bool,rollback:bool,simulateReal:bool,personBindings:bool}
     */
    public static function capabilities(StudioAccess $access): array
    {
        $ve = $access->can(self::VIEW);

        return [
            'edit' => $ve && $access->can(self::EDIT),
            'approve' => $ve && $access->can(self::APPROVE),
            'publish' => $ve && $access->can(self::PUBLISH),
            'rollback' => $ve && $access->can(self::ROLLBACK),
            'simulateReal' => $ve && $access->can(self::SIMULATE_REAL),
            'personBindings' => $ve && $access->can(self::PERSON_BINDINGS),
        ];
    }
}
