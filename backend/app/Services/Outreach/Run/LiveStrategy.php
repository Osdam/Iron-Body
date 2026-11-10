<?php

namespace App\Services\Outreach\Run;

use App\Services\Outreach\Core\CanonicalCondition;
use App\Services\Outreach\Core\OutreachCatalog;

/**
 * Una VERSIÓN VIVA de una estrategia tal como la lee el puente de la cadena
 * ({@see OutreachPublishedStrategies}): lo que la ejecución necesita de ella
 * y nada más. Inmutable. Una estrategia puede tener DOS (la `canary` y la
 * `rest`): la persona del alcance canario va a la canaria y las demás a la
 * publicada (D9-11.6).
 *
 * `content` es el JSON canónico ya leído por `StrategyContent::fromStored()`,
 * o null si la versión guardada no se pudo releer (`contentReason`:
 * `content_hash_mismatch`, `malformed`, `not_canonical` o
 * `catalog_incompatible`): entonces N3 la niega (`catalog_incompatible`) y no
 * se reinterpreta (D9-3).
 */
final class LiveStrategy
{
    /** @var array<string, CanonicalCondition|null> */
    private array $parsed = [];

    /**
     * @param  array<string, mixed>|null  $content
     * @param  array{referenceValid: bool, paymentLinksEnabled: bool, canaryOnly: bool, reason: string|null}  $studio
     * @param  array<string, list<int|string>>  $dynamicDomains
     */
    public function __construct(
        public readonly int $strategyId,
        public readonly string $strategyUuid,
        public readonly string $strategyKey,
        public readonly string $purpose,
        public readonly int $versionId,
        public readonly string $versionUuid,
        public readonly int $versionNumber,
        public readonly string $versionStatus,
        public readonly string $contentHash,
        public readonly ?array $content,
        public readonly ?string $contentReason,
        public readonly string $catalogVersion,
        public readonly string $evaluatorVersion,
        public readonly string $studioRefKind,
        public readonly ?string $studioVersionUuid,
        public readonly string $studioContentHash,
        public readonly string $part,
        public readonly int $publicationSeq,
        public readonly string $chainStatus,
        public readonly string $chainVerified,
        public readonly array $studio,
        private readonly array $dynamicDomains = [],
    ) {}

    /** ¿Verifica la entrada de la cadena que la sostiene? (si no, `chain_unreadable`). */
    public function chainReadable(): bool
    {
        return $this->chainVerified === 'ok';
    }

    public function paused(): bool
    {
        return $this->chainStatus === 'paused';
    }

    /** ¿Puede decidir? Cadena legible, versión en su estado vivo y contenido releído. */
    public function runnable(): bool
    {
        return $this->chainReadable() && $this->content !== null
            && (($this->part === 'canary' && $this->versionStatus === 'CANARY') || ($this->part === 'rest' && $this->versionStatus === 'PUBLISHED'));
    }

    /**
     * Por qué una inscripción de esta versión/parte es HUÉRFANA (D9-8, D9-3, D9-11.3; REV9 M-5, L9-69):
     * `strategy_moved` si la versión ya no está en el estado vivo de esta parte (reversión, retirada,
     * promoción de la parte canario); `catalog_incompatible` si la cadena verifica pero el contenido no
     * se puede releer; null si puede decidir O si la cadena NO verifica: entonces N5 niega y el monitor
     * frena, y el estado se conserva hasta que la cadena vuelva a leerse (cancelar por una firma rota
     * sería irreversible: la ocurrencia del ciclo se consume).
     */
    public function orphanReason(): ?string
    {
        if (! $this->chainReadable()) {
            return null;
        }
        $viva = ($this->part === 'canary' && $this->versionStatus === 'CANARY') || ($this->part === 'rest' && $this->versionStatus === 'PUBLISHED');
        if (! $viva) {
            return 'strategy_moved';
        }

        return $this->content === null ? 'catalog_incompatible' : null;
    }

    public function isCanary(): bool
    {
        return $this->part === 'canary';
    }

    /** @return array{event: string, params: array<string, int>} */
    public function trigger(): array
    {
        $t = $this->content['trigger'] ?? ['event' => ''];

        return ['event' => (string) ($t['event'] ?? ''), 'params' => is_array($t['params'] ?? null) ? $t['params'] : []];
    }

    public function triggerEvent(): string
    {
        return $this->trigger()['event'];
    }

    public function goal(): string
    {
        return (string) ($this->content['goal'] ?? '');
    }

    public function priority(): int
    {
        return (int) ($this->content['priority'] ?? 1);
    }

    /** @return array<string, int> */
    public function limits(): array
    {
        return is_array($this->content['limits'] ?? null) ? $this->content['limits'] : [];
    }

    public function limit(string $key, int $default): int
    {
        $v = $this->limits()[$key] ?? null;

        return is_int($v) ? $v : $default;
    }

    /** @return array{days: list<int>, windows: list<array{from: int, to: int}>}|null */
    public function schedule(): ?array
    {
        return is_array($this->content['schedule'] ?? null) ? $this->content['schedule'] : null;
    }

    /** El canal del registro de canarios que define el alcance canario (`whatsapp` | `voice`). */
    public function canaryChannel(): string
    {
        return (string) ($this->content['canary']['channel'] ?? OutreachCatalog::MEDIUM_WHATSAPP);
    }

    /** @return list<array<string, mixed>> */
    public function steps(): array
    {
        return is_array($this->content['sequence'] ?? null) ? $this->content['sequence'] : [];
    }

    /** @return array<string, mixed>|null */
    public function step(int $index): ?array
    {
        return $this->steps()[$index] ?? null;
    }

    /** Los pasos con la forma que pide `FollowUpMachine` (`id` y `delay`). @return list<array{id: string, delay: array{n: int, unit: string, from: string}}> */
    public function machineSteps(): array
    {
        return array_map(static fn (array $s): array => ['id' => (string) $s['id'], 'delay' => $s['delay']], $this->steps());
    }

    public function audience(): ?CanonicalCondition
    {
        return $this->condition('audience');
    }

    public function exclusions(): ?CanonicalCondition
    {
        return $this->condition('exclusions');
    }

    public function success(): ?CanonicalCondition
    {
        return $this->condition('success');
    }

    public function guard(int $stepIndex): ?CanonicalCondition
    {
        $paso = $this->step($stepIndex);
        if ($paso === null || ! isset($paso['guard'])) {
            return null;
        }
        $clave = "guard:{$stepIndex}";
        if (! array_key_exists($clave, $this->parsed)) {
            $this->parsed[$clave] = CanonicalCondition::parse($paso['guard'], $this->dynamicDomains, 'guard');
        }

        return $this->parsed[$clave];
    }

    /**
     * Lo que USAN todas las condiciones de la versión (para leer solo esos hechos).
     *
     * @return array{facts: list<string>, events: list<string>, contacts: bool, transitions: bool}
     */
    public function uses(): array
    {
        $hechos = [];
        $eventos = [];
        $contactos = false;
        $transiciones = false;
        $condiciones = [$this->audience(), $this->exclusions(), $this->success()];
        foreach (array_keys($this->steps()) as $i) {
            $condiciones[] = $this->guard($i);
        }
        foreach ($condiciones as $c) {
            if ($c === null) {
                continue;
            }
            $u = $c->uses();
            $hechos = [...$hechos, ...($u['facts'] ?? [])];
            $eventos = [...$eventos, ...($u['events'] ?? [])];
            $contactos = $contactos || ($u['contacts'] ?? false) === true;
            $transiciones = $transiciones || ($u['transitions'] ?? false) === true;
        }
        $hechos = array_values(array_unique($hechos));
        sort($hechos, SORT_STRING);
        $eventos = array_values(array_unique([...$eventos, 'human_requested']));
        sort($eventos, SORT_STRING);

        return ['facts' => $hechos, 'events' => $eventos, 'contacts' => $contactos, 'transitions' => $transiciones];
    }

    /**
     * El sello (§5) de las filas que nacen de esta versión.
     *
     * @return array<string, int|string|null>
     */
    public function seal(string $mode): array
    {
        return [
            'strategy_id' => $this->strategyId,
            'strategy_version_id' => $this->versionId,
            'content_hash' => $this->contentHash,
            'publication_seq' => $this->publicationSeq,
            'part' => $this->part,
            'studio_ref_kind' => $this->studioRefKind,
            'studio_version_uuid' => $this->studioVersionUuid,
            'studio_content_hash' => $this->studioContentHash,
            'catalog_version' => $this->catalogVersion,
            'evaluator_version' => $this->evaluatorVersion,
            'mode' => $mode,
        ];
    }

    /** Lo que se enseña de ella (sin contenido). @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'strategy' => $this->strategyUuid, 'key' => $this->strategyKey, 'purpose' => $this->purpose,
            'version' => $this->versionUuid, 'number' => $this->versionNumber, 'part' => $this->part, 'seq' => $this->publicationSeq,
            'chainStatus' => $this->chainStatus, 'verified' => $this->chainVerified, 'runnable' => $this->runnable(),
            'contentReason' => $this->contentReason, 'trigger' => $this->triggerEvent(),
        ];
    }

    private function condition(string $key): ?CanonicalCondition
    {
        if (! isset($this->content[$key])) {
            return null;
        }
        if (! array_key_exists($key, $this->parsed)) {
            $this->parsed[$key] = CanonicalCondition::parse($this->content[$key], $this->dynamicDomains, $key);
        }

        return $this->parsed[$key];
    }
}
