<?php

namespace App\Services\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Models\UltronStudio\StudioStrategy;
use App\Models\UltronStudio\StudioStrategyVersion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

/**
 * LAS ESTRATEGIAS Y SU BORRADOR (contrato H9, D9-6, D9-11.0, §6.1, §7 #2–#5,
 * #20): crear la identidad (`key` y `purpose`, inmutables), guardar el
 * borrador compartido con `baseRevision` (UPDATE condicionado, 409
 * `draft_moved`, como el borrador del H5), archivar (solo sin versiones en
 * CANARY ni PUBLISHED) y leerlas.
 *
 * EL BORRADOR se guarda tal cual llega (un JSON ≤ 32 KB, sin exigir que
 * valide: se edita por partes); lo que valida es `validate` y lo que se
 * congela es una versión ({@see StudioStrategyVersions}). Es REGISTRO del
 * Studio: nada de aquí ejecuta; la ejecución lee solo la cadena firmada.
 */
final class StudioStrategies
{
    public const EDIT = StudioStrategyConfig::EDIT;

    public const NAME_MAX = 80;

    public const DRAFT_MAX_BYTES = 32768;

    public const PER_PAGE_MAX = 50;

    public const VERSIONS_IN_DETAIL = 20;

    public function __construct(private readonly StudioAudit $audit) {}

    // ── Crear (POST strategies) ─────────────────────────────────────────────

    /**
     * @param  array<string,mixed>|null  $draft
     * @return array{strategy:StudioStrategy,replayed:bool}
     */
    public function create(StudioAccess $access, Request $request, string $clientRequestId, string $key, string $name, string $purpose, ?array $draft): array
    {
        $access->require(self::EDIT);
        $llave = strtolower($clientRequestId);
        // El borrador nace en la revisión 1 (DB9, `usst_starts_clean`): el que llegue o un objeto vacío.
        $json = self::draftJson($draft ?? []);
        $previa = StudioStrategy::query()->where('client_request_id', $llave)->first();
        if ($previa !== null) {
            if ((int) $previa->created_by_admin_id !== $access->id() || $previa->key !== $key || $previa->name !== $name || $previa->purpose !== $purpose) {
                throw UltronStudioException::clientRequestReused();
            }

            return ['strategy' => $previa, 'replayed' => true];
        }
        if (StudioStrategy::query()->where('key', $key)->exists()) {
            throw new UltronStudioException('Ya hay una estrategia con esa clave: la clave no se reutiliza.', 'strategy_key_taken', 409);
        }

        try {
            $fila = DB::transaction(function () use ($access, $request, $llave, $key, $name, $purpose, $json): StudioStrategy {
                $yo = $access->snapshot();
                $s = StudioStrategy::query()->forceCreate([
                    'uuid' => strtolower((string) Str::uuid()),
                    'client_request_id' => $llave,
                    'key' => $key,
                    'name' => $name,
                    'purpose' => $purpose,
                    'draft_json' => $json,
                    'draft_revision' => 1,
                    'created_by_admin_id' => $yo['id'],
                    'created_by_name' => $yo['name'],
                    'created_by_role' => $yo['role'],
                ]);
                $this->audit->record($request, $access->actor, 'strategy.created', StudioStrategyConfig::SUBJECT_STRATEGY, $s->uuid,
                    "Creó la estrategia «{$name}» ({$key})", ['diff' => ['key' => $key, 'purpose' => $purpose, 'withDraft' => $json !== '{}']]);

                return $s;
            });
        } catch (UniqueConstraintViolationException) {
            throw new UltronStudioException('Ya hay una estrategia con esa clave: la clave no se reutiliza.', 'strategy_key_taken', 409);
        }

        return ['strategy' => $fila, 'replayed' => false];
    }

    // ── Borrador (PUT strategies/{uuid}/draft) ──────────────────────────────

    /**
     * Guarda el borrador si nadie lo cambió desde `baseRevision` (UPDATE
     * condicionado: dos pestañas no se pisan). Si no, 409 `draft_moved` con el
     * borrador como está.
     *
     * @param  array<string,mixed>  $draft
     */
    public function saveDraft(StudioAccess $access, Request $request, string $uuid, int $baseRevision, array $draft): StudioStrategy
    {
        $access->require(self::EDIT);
        $s = self::findByUuid($uuid);
        self::assertNotArchived($s);
        $json = self::draftJson($draft);

        return DB::transaction(function () use ($access, $request, $s, $baseRevision, $json): StudioStrategy {
            $ahora = now();
            $hechas = StudioStrategy::query()->whereKey($s->id)->whereNull('archived_at')->where('draft_revision', $baseRevision)->update([
                'draft_json' => $json,
                'draft_revision' => $baseRevision + 1,
                'draft_updated_by_admin_id' => $access->id(),
                'draft_updated_by_name' => $access->snapshot()['name'],
                'draft_updated_at' => $ahora,
                'updated_at' => $ahora,
            ]);
            if ($hechas !== 1) {
                $actual = $s->refresh();

                throw new UltronStudioException('El borrador cambió en otra pestaña o lo cambió otra persona. Recarga para ver lo guardado.', 'draft_moved', 409, [
                    'current' => self::draftDto($actual),
                ]);
            }
            $this->audit->record($request, $access->actor, 'strategy.draft_saved', StudioStrategyConfig::SUBJECT_STRATEGY, $s->uuid,
                'Guardó el borrador de la estrategia «'.$s->name.'» (revisión '.($baseRevision + 1).')', ['diff' => ['revision' => $baseRevision + 1, 'bytes' => strlen($json)]]);

            return $s->refresh();
        });
    }

    // ── Archivar (POST strategies/{uuid}/archive) ───────────────────────────

    public function archive(StudioAccess $access, Request $request, string $uuid): StudioStrategy
    {
        $access->require(self::EDIT);
        $s = self::findByUuid($uuid);
        self::assertNotArchived($s);

        return DB::transaction(function () use ($access, $request, $s): StudioStrategy {
            // Con la fila de la estrategia bloqueada: ninguna publicación entra a la vez.
            StudioStrategy::query()->whereKey($s->id)->lockForUpdate()->first(['id']);
            $vivas = StudioStrategyVersion::query()->where('strategy_id', $s->id)->whereIn('status', ['CANARY', 'PUBLISHED'])->count();
            if ($vivas > 0) {
                throw new UltronStudioException('Una estrategia con una versión en canario o publicada no se archiva: revierte antes.', 'strategy_live', 409, ['live' => $vivas]);
            }
            $ahora = now();
            $hechas = StudioStrategy::query()->whereKey($s->id)->whereNull('archived_at')->update([
                'archived_at' => $ahora, 'archived_by_admin_id' => $access->id(), 'archived_by_name' => $access->snapshot()['name'], 'updated_at' => $ahora,
            ]);
            if ($hechas !== 1) {
                throw new UltronStudioException('La estrategia ya está archivada.', 'strategy_archived', 409);
            }
            $this->audit->record($request, $access->actor, 'strategy.archived', StudioStrategyConfig::SUBJECT_STRATEGY, $s->uuid, "Archivó la estrategia «{$s->name}»", ['toStatus' => 'archived']);

            return $s->refresh();
        });
    }

    // ── Lecturas ────────────────────────────────────────────────────────────

    public static function findByUuid(string $uuid): StudioStrategy
    {
        $s = StudioStrategy::query()->where('uuid', strtolower($uuid))->first();
        if ($s === null) {
            throw new UltronStudioException('La estrategia no existe.', 'strategy_not_found', 404);
        }

        return $s;
    }

    public static function assertNotArchived(StudioStrategy $s): void
    {
        if ($s->archived_at !== null) {
            throw new UltronStudioException('La estrategia está archivada: ya no se edita ni se publica.', 'strategy_archived', 409);
        }
    }

    /**
     * `GET strategies` (≤ 50): sin el borrador; con el estado de gobierno
     * (las versiones por estado) y el de la cadena.
     */
    public static function page(?string $purpose, bool $withArchived, int $perPage): LengthAwarePaginator
    {
        $pagina = StudioStrategy::query()
            ->select(['id', 'uuid', 'key', 'name', 'purpose', 'draft_revision', 'draft_updated_at', 'archived_at', 'created_by_name', 'created_by_role', 'created_at'])
            ->when($purpose, fn ($q, string $p) => $q->where('purpose', $p))
            ->when(! $withArchived, fn ($q) => $q->whereNull('archived_at'))
            ->orderByDesc('id')
            ->paginate(min($perPage, self::PER_PAGE_MAX));
        // Los recuentos por estado de todas las de la página, en UNA consulta (sin N+1).
        $ids = array_map(static fn (StudioStrategy $s): int => (int) $s->id, $pagina->items());
        $porEstado = [];
        if ($ids !== []) {
            foreach (StudioStrategyVersion::query()->whereIn('strategy_id', $ids)->selectRaw('strategy_id, status, count(*) as n')->groupBy('strategy_id', 'status')->get() as $f) {
                $porEstado[(int) $f->strategy_id][(string) $f->status] = (int) $f->n;
            }
        }

        $cadenas = StudioStrategyPublications::statesFor($pagina->items());

        return $pagina->through(static fn (StudioStrategy $s): array => self::summaryDto($s) + [
            'versions' => $porEstado[(int) $s->id] ?? (object) [],
            'chain' => self::chainSummary($cadenas[(int) $s->id]),
        ]);
    }

    /** @return array<string, mixed> */
    public static function summaryDto(StudioStrategy $s): array
    {
        return [
            'uuid' => (string) $s->uuid,
            'key' => (string) $s->key,
            'name' => (string) $s->name,
            'purpose' => (string) $s->purpose,
            'draftRevision' => (int) $s->draft_revision,
            'draftUpdatedAt' => $s->draft_updated_at?->toIso8601String(),
            'archived' => $s->archived_at !== null,
            'archivedAt' => $s->archived_at?->toIso8601String(),
            'createdBy' => ['name' => (string) $s->created_by_name, 'role' => $s->created_by_role],
            'createdAt' => $s->created_at?->toIso8601String(),
        ];
    }

    /**
     * `GET strategies/{uuid}`: el borrador, las últimas versiones, el estado de
     * la cadena y lo que puede hacer quien mira.
     *
     * @return array<string, mixed>
     */
    public static function detailDto(StudioStrategy $s, StudioAccess $access): array
    {
        $versiones = StudioStrategyVersion::query()->where('strategy_id', $s->id)->orderByDesc('number')->limit(self::VERSIONS_IN_DETAIL)
            ->get(['id', 'uuid', 'number', 'status', 'content_hash', 'studio_ref_kind', 'created_by_name', 'created_by_role', 'created_at', 'status_changed_at']);
        $cadena = StudioStrategyPublications::stateOf($s);
        $can = StudioStrategyConfig::capabilities($access);
        $vivo = $cadena['status'] !== StudioStrategyPublications::STATUS_OFF;

        return self::summaryDto($s) + [
            'draft' => self::draftDto($s),
            'versions' => $versiones->map(static fn (StudioStrategyVersion $v): array => StudioStrategyVersions::summaryDto($v))->all(),
            'chain' => self::chainSummary($cadena),
            'can' => $can,
            'actions' => [
                'pause' => ['allowed' => $can['rollback'] && $cadena['status'] === StudioStrategyPublications::STATUS_ACTIVE],
                'resume' => ['allowed' => $can['publish'] && $cadena['status'] === StudioStrategyPublications::STATUS_PAUSED],
                'rollback' => ['allowed' => $can['rollback'] && $vivo],
                'archive' => ['allowed' => $can['edit'] && $s->archived_at === null && ! $vivo],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $cadena  {@see StudioStrategyPublications::stateOf()}
     * @return array<string,mixed>
     */
    public static function chainSummary(array $cadena): array
    {
        return [
            'status' => $cadena['status'],
            'canary' => $cadena['canary'],
            'rest' => $cadena['rest'],
            'seq' => $cadena['seq'],
            'verified' => $cadena['verified'],
        ];
    }

    /** @return array{revision:int,content:mixed,updatedAt:string|null} */
    public static function draftDto(StudioStrategy $s): array
    {
        $contenido = null;
        if (is_string($s->draft_json)) {
            try {
                $contenido = json_decode($s->draft_json, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                // Tragado a propósito: un borrador ilegible se enseña vacío; guardarlo otra vez lo reemplaza.
                $contenido = null;
            }
        }

        return ['revision' => (int) $s->draft_revision, 'content' => $contenido, 'updatedAt' => $s->draft_updated_at?->toIso8601String()];
    }

    /**
     * El borrador como JSON (≤ 32 KB), tal cual (sin canonicalizar: puede no
     * validar todavía). Siempre un OBJETO (DB9, `usst_draft_chk`): una lista
     * es 422 y el vacío es `{}`.
     *
     * @param  array<mixed>  $draft
     */
    private static function draftJson(array $draft): string
    {
        if ($draft === []) {
            return '{}';
        }
        if (array_is_list($draft)) {
            throw new UltronStudioException('El borrador es un objeto JSON, no una lista.', 'draft_invalid', 422, ['errors' => ['draft' => ['El borrador es un objeto JSON, no una lista.']]]);
        }
        try {
            $json = json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException) {
            throw new UltronStudioException('El borrador no se puede guardar como JSON.', 'draft_invalid', 422, ['errors' => ['draft' => ['El borrador no es un JSON válido.']]]);
        }
        if (strlen($json) > self::DRAFT_MAX_BYTES) {
            throw new UltronStudioException('El borrador pasa de 32 KB.', 'draft_too_large', 422, ['errors' => ['draft' => ['El borrador pasa de 32 KB.']]]);
        }

        return $json;
    }
}
