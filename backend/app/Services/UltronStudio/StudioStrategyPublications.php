<?php

namespace App\Services\UltronStudio;

use App\Models\UltronStudio\StudioStrategy;
use App\Models\UltronStudio\StudioStrategyPublication;
use App\Services\Outreach\Core\RelativeTime;
use App\Services\Outreach\Core\StrategyChainSignature;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * LA CADENA FIRMADA DE ESTRATEGIAS (contrato H9, D9-11.3, L9-23; vectores
 * `strategy-chain-vectors.json`): la escritura de cada entrada, su
 * verificación, lo vigente de cada estrategia y lo que se enseña. Solo la
 * escribe {@see StudioStrategyPublishing}, dentro de su transacción, por
 * {@see insertEntry()}.
 *
 * UNA SOLA IMPLEMENTACIÓN de la firma: `Outreach\Core\StrategyChainSignature`
 * (pura; subclave `ultron-studio-strategy-chain`, distinta de la del H5 y de
 * la voz). El Studio la recibe como `Closure` ({@see signer()}), como en el H5
 * y el H8; la verificación es la del núcleo, con el mismo orden (forma →
 * bytes canónicos → firma → `seq` → `prevHash`) y NUNCA LANZA. Además, la fila
 * tiene que decir lo mismo que su mensaje (`row_mismatch`): una entrada copiada
 * de otra estrategia o con la acción cambiada no verifica.
 *
 * EL MENSAJE: `{action, actedByPerson, approvedByPerson, contract, issuedAt,
 * prevHash, seq, state {canary, rest, status}, strategy {key, purpose, uuid}}`,
 * canónico ordenado por bytes (L9-23). `seq` es GLOBAL (cabeza + 1) y
 * `prevHash` es el sha256 del mensaje anterior de la cadena. Las personas son
 * uuids de `identities`; `approvedByPerson` solo en `publish` y `promote`.
 *
 * NO RETROACTIVA: la cadena solo crece. Pausar, revertir o retirar es INSERTAR
 * una entrada; lo que ya salió no se toca (D9-8 «Reversión»).
 */
final class StudioStrategyPublications
{
    /** Solo de una fila guardada: lo firmado no es lo que dicen sus columnas. */
    public const VERIFY_ROW_MISMATCH = 'row_mismatch';

    public const PER_PAGE_MAX = 50;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_OFF = 'off';

    /** El cerrojo consultivo de la cadena (PostgreSQL). */
    public const LOCK = 'ultron_studio_strategy:chain';

    /** Lo que se lee de una entrada (el texto firmado, acotado como lo lee la base). */
    private const COLUMNS = [
        'id', 'uuid', 'seq', 'previous_publication_id', 'prev_hash', 'client_request_id', 'action', 'strategy_id', 'version_id', 'effective_hash', 'signature',
        'simulation_gate', 'evidence', 'reason', 'actor_admin_id', 'acted_by_person_id', 'actor_name', 'actor_role', 'created_at',
    ];

    // ── La firma ────────────────────────────────────────────────────────────

    /**
     * La firma como `Closure`, de la ÚNICA implementación (el núcleo), con la
     * `APP_KEY` de la aplicación. Sin `APP_KEY` lanza al pedirla: no se firma
     * con una clave vacía (falla cerrado).
     *
     * @return Closure(string):string
     */
    public static function signer(): Closure
    {
        return StrategyChainSignature::signer(self::appKeyRaw());
    }

    private static function appKeyRaw(): string
    {
        return StrategyChainSignature::appKeyRaw((string) config('app.key'));
    }

    // ── Las filas ───────────────────────────────────────────────────────────

    /** La cabeza: la entrada de mayor `seq` (global). */
    public static function head(): ?StudioStrategyPublication
    {
        return self::withJson(StudioStrategyPublication::query())->orderByDesc('seq')->first();
    }

    /** Lo vigente de una estrategia: su entrada de mayor `seq`. */
    public static function latestFor(int $strategyId): ?StudioStrategyPublication
    {
        return self::withJson(StudioStrategyPublication::query())->where('strategy_id', $strategyId)->orderByDesc('seq')->first();
    }

    /** La entrada inmediatamente anterior a `$seq`, o null. */
    public static function predecessor(int $seq): ?StudioStrategyPublication
    {
        return StudioStrategyPublication::query()->where('seq', $seq - 1)->first(['id', 'seq', 'effective_hash']);
    }

    /**
     * Firma e inserta una entrada DENTRO de la transacción de quien publica
     * (que ya tomó el cerrojo y comprobó la cabeza). Una carrera perdida es el
     * UNIQUE de `seq`, que quien publica traduce a 409 `publication_moved`.
     *
     * @param  array{canary: array{contentHash:string,version:string}|null, rest: array{contentHash:string,version:string}|null, status: string}  $state
     * @param  array<string,mixed>|null  $evidence
     * @param  Closure(string):string  $signer
     */
    public static function insertEntry(
        string $action,
        StudioStrategy $strategy,
        ?int $versionId,
        ?StudioStrategyPublication $head,
        DateTimeInterface $at,
        StudioAccess $access,
        string $actorPersonUuid,
        int $actorPersonId,
        ?string $approvedPersonUuid,
        string $clientRequestId,
        array $state,
        ?string $simulationGate,
        ?array $evidence,
        ?string $reason,
        Closure $signer,
    ): StudioStrategyPublication {
        $seq = ($head !== null ? (int) $head->seq : 0) + 1;
        $prev = $head !== null ? (string) $head->effective_hash : StrategyChainSignature::GENESIS_PREV_HASH;
        $mensaje = StrategyChainSignature::message([
            'action' => $action,
            'actedByPerson' => strtolower($actorPersonUuid),
            'approvedByPerson' => $approvedPersonUuid === null ? null : strtolower($approvedPersonUuid),
            'contract' => StrategyChainSignature::CONTRACT,
            'issuedAt' => RelativeTime::format($at),
            'prevHash' => $prev,
            'seq' => $seq,
            'state' => $state,
            'strategy' => ['key' => (string) $strategy->key, 'purpose' => (string) $strategy->purpose, 'uuid' => (string) $strategy->uuid],
        ]);
        $firma = $signer($mensaje);
        if (! is_string($firma) || preg_match('/^[0-9a-f]{64}$/D', $firma) !== 1) {
            throw new InvalidArgumentException('La firma de la cadena de estrategias no tiene la forma esperada.');
        }
        $yo = $access->snapshot();

        return StudioStrategyPublication::query()->forceCreate([
            'uuid' => strtolower((string) Str::uuid()),
            'seq' => $seq,
            'previous_publication_id' => $head?->id,
            'prev_hash' => $prev,
            'client_request_id' => strtolower($clientRequestId),
            'action' => $action,
            'strategy_id' => (int) $strategy->id,
            'version_id' => $versionId,
            'effective_json' => $mensaje,
            'effective_hash' => StrategyChainSignature::entryHash($mensaje),
            'signature' => $firma,
            'simulation_gate' => $simulationGate,
            'evidence' => $evidence,
            'reason' => $reason,
            'actor_admin_id' => $yo['id'],
            'acted_by_person_id' => $actorPersonId,
            'actor_name' => $yo['name'],
            'actor_role' => $yo['role'],
            'created_at' => $at,
        ]);
    }

    // ── La verificación (nunca lanza) ───────────────────────────────────────

    /**
     * Verifica una fila: su mensaje con la del núcleo (con la entrada anterior
     * de la base) y que lo firmado es lo que dicen sus columnas.
     *
     * @return string uno de `StrategyChainSignature::RESULTS` o {@see VERIFY_ROW_MISMATCH}
     */
    public static function verifyRow(StudioStrategyPublication $fila): string
    {
        $anterior = (int) $fila->seq > 1 ? self::predecessor((int) $fila->seq) : null;

        return self::verifyRowWith($fila, $anterior === null ? null : (string) $anterior->effective_hash);
    }

    public static function verifyRowWith(StudioStrategyPublication $fila, ?string $previousHash): string
    {
        $r = self::verifyMessageOnly($fila, $previousHash);
        if ($r !== StrategyChainSignature::RESULT_OK) {
            return $r;
        }

        return self::rowMatches($fila, (array) StrategyChainSignature::decode((string) $fila->effective_json)) ? StrategyChainSignature::RESULT_OK : self::VERIFY_ROW_MISMATCH;
    }

    /** La huella de la fila y el mensaje con la del núcleo (sin cruzar con las columnas). Nunca lanza. */
    private static function verifyMessageOnly(StudioStrategyPublication $fila, ?string $previousHash): string
    {
        try {
            $clave = self::appKeyRaw();
        } catch (Throwable $e) {
            // Tragado a propósito: la verificación nunca lanza; sin clave no verifica (falla cerrado). Solo la clase.
            Log::warning('ultron_studio.strategy_chain_key_unavailable', ['error_class' => class_basename($e)]);

            return 'signature_mismatch';
        }
        $json = $fila->effective_json;
        if (! is_string($json) || ! hash_equals(StrategyChainSignature::entryHash($json), (string) $fila->effective_hash)) {
            return self::VERIFY_ROW_MISMATCH;
        }
        $seq = (int) $fila->seq;
        if ($seq > 1 && $previousHash === null) {
            return 'seq_gap';
        }

        return StrategyChainSignature::verifyEntry($json, (string) $fila->signature, $seq > 1 ? ['seq' => $seq - 1, 'entryHash' => (string) $previousHash] : null, $clave);
    }

    // ── Lo vigente ──────────────────────────────────────────────────────────

    /**
     * El estado de la cadena de UNA estrategia: lo que dice su última entrada
     * (verificada) o `off` si nunca se publicó. Una entrada que no verifica no
     * cuenta: `verified` lo dice, y quien decide (publicar, la ejecución) la
     * trata como ilegible (`chain_unreadable`).
     *
     * @return array{status:string,canary:array{contentHash:string,version:string}|null,rest:array{contentHash:string,version:string}|null,seq:int|null,entry:string|null,verified:string|null}
     */
    public static function stateOf(StudioStrategy $strategy): array
    {
        $ultima = self::latestFor((int) $strategy->id);
        if ($ultima === null) {
            return ['status' => self::STATUS_OFF, 'canary' => null, 'rest' => null, 'seq' => null, 'entry' => null, 'verified' => null];
        }
        $verificada = self::verifyRow($ultima);
        $m = StrategyChainSignature::decode((string) $ultima->effective_json);
        $estado = is_array($m['state'] ?? null) ? $m['state'] : ['status' => self::STATUS_OFF, 'canary' => null, 'rest' => null];

        return [
            'status' => (string) $estado['status'],
            'canary' => $estado['canary'],
            'rest' => $estado['rest'],
            'seq' => (int) $ultima->seq,
            'entry' => (string) $ultima->uuid,
            'verified' => $verificada,
        ];
    }

    /**
     * {@see stateOf()} de varias estrategias a la vez (el listado): las
     * últimas entradas, sus anteriores, las estrategias y las personas en
     * CUATRO consultas, sin N+1.
     *
     * @param  list<StudioStrategy>  $strategies
     * @return array<int, array{status:string,canary:array{contentHash:string,version:string}|null,rest:array{contentHash:string,version:string}|null,seq:int|null,entry:string|null,verified:string|null}> por id de estrategia
     */
    public static function statesFor(array $strategies): array
    {
        $ids = array_map(static fn (StudioStrategy $s): int => (int) $s->id, $strategies);
        $salida = array_fill_keys($ids, ['status' => self::STATUS_OFF, 'canary' => null, 'rest' => null, 'seq' => null, 'entry' => null, 'verified' => null]);
        if ($ids === []) {
            return $salida;
        }
        $maximos = StudioStrategyPublication::query()->whereIn('strategy_id', $ids)->groupBy('strategy_id')->selectRaw('max(seq) as seq')->pluck('seq')->map(fn ($s): int => (int) $s)->all();
        if ($maximos === []) {
            return $salida;
        }
        $ultimas = self::withJson(StudioStrategyPublication::query())->whereIn('seq', $maximos)->get();
        $anteriores = StudioStrategyPublication::query()->whereIn('seq', array_map(static fn (int $s): int => $s - 1, $maximos))->pluck('effective_hash', 'seq')->all();
        $personas = DB::table('identities')->whereIn('id', $ultimas->pluck('acted_by_person_id')->filter()->unique()->all())->pluck('uuid', 'id')->all();
        $porId = [];
        foreach ($strategies as $s) {
            $porId[(int) $s->id] = $s;
        }
        foreach ($ultimas as $fila) {
            $estrategia = $porId[(int) $fila->strategy_id] ?? null;
            $previo = isset($anteriores[(int) $fila->seq - 1]) ? (string) $anteriores[(int) $fila->seq - 1] : null;
            $m = StrategyChainSignature::decode((string) $fila->effective_json);
            $estado = is_array($m['state'] ?? null) ? $m['state'] : ['status' => self::STATUS_OFF, 'canary' => null, 'rest' => null];
            $salida[(int) $fila->strategy_id] = [
                'status' => (string) $estado['status'],
                'canary' => $estado['canary'],
                'rest' => $estado['rest'],
                'seq' => (int) $fila->seq,
                'entry' => (string) $fila->uuid,
                'verified' => $estrategia === null ? self::VERIFY_ROW_MISMATCH : self::verifyRowKnown($fila, $previo, $estrategia, $personas[(int) $fila->acted_by_person_id] ?? null),
            ];
        }

        return $salida;
    }

    /** {@see verifyRowWith()} con la estrategia y la persona ya leídas (sin consultas). */
    private static function verifyRowKnown(StudioStrategyPublication $fila, ?string $previousHash, StudioStrategy $estrategia, ?string $personaUuid): string
    {
        $r = self::verifyMessageOnly($fila, $previousHash);
        if ($r !== StrategyChainSignature::RESULT_OK) {
            return $r;
        }
        $m = (array) StrategyChainSignature::decode((string) $fila->effective_json);

        return self::matchesWith($fila, $m, $estrategia, $personaUuid) ? StrategyChainSignature::RESULT_OK : self::VERIFY_ROW_MISMATCH;
    }

    /** `GET strategies/{uuid}/publications` (≤ 50), de la más reciente a la más antigua, cada una con su `verified`. */
    public static function page(StudioStrategy $strategy, int $perPage): LengthAwarePaginator
    {
        $pagina = self::withJson(StudioStrategyPublication::query())
            ->with(['version:id,uuid,number,content_hash'])
            ->where('strategy_id', $strategy->id)
            ->orderByDesc('seq')
            ->paginate(min($perPage, self::PER_PAGE_MAX));
        // Las huellas de las anteriores (de cualquier estrategia: la cadena es global), en UNA consulta.
        $seqs = array_map(static fn (StudioStrategyPublication $p): int => (int) $p->seq - 1, $pagina->items());
        $anteriores = $seqs === [] ? [] : StudioStrategyPublication::query()->whereIn('seq', $seqs)->pluck('effective_hash', 'seq')->all();

        return $pagina->through(static fn (StudioStrategyPublication $p): array => self::entryDto($p) + [
            'verified' => self::verifyRowWith($p, isset($anteriores[(int) $p->seq - 1]) ? (string) $anteriores[(int) $p->seq - 1] : null),
        ]);
    }

    /**
     * `StrategyPublicationEntryDto`: sin el texto firmado (lo identifica su
     * huella), sin ids internos, con el estado que declara.
     *
     * @return array<string, mixed>
     */
    public static function entryDto(StudioStrategyPublication $p): array
    {
        $m = StrategyChainSignature::decode((string) $p->effective_json);
        $p->loadMissing('version:id,uuid,number,content_hash');

        return [
            'uuid' => (string) $p->uuid,
            'seq' => (int) $p->seq,
            'action' => (string) $p->action,
            'state' => is_array($m['state'] ?? null) ? $m['state'] : null,
            'version' => $p->version === null ? null : ['uuid' => (string) $p->version->uuid, 'number' => (int) $p->version->number, 'contentHash' => (string) $p->version->content_hash],
            'effectiveHash' => (string) $p->effective_hash,
            'shortHash' => substr((string) $p->effective_hash, 0, 8),
            'simulationGate' => $p->simulation_gate,
            'evidence' => is_array($p->evidence) ? $p->evidence : null,
            'reason' => $p->reason,
            'actor' => ['name' => (string) $p->actor_name, 'role' => $p->actor_role],
            'at' => $p->created_at?->toIso8601String(),
        ];
    }

    /**
     * La cadena ENTERA desde la génesis, por el núcleo (`verifyChain`): el primer
     * eslabón que falla. Lo que mira el monitor; aquí, para la prueba T1.
     *
     * @return array{result:string,seq:int|null,verified:int}
     */
    public static function verifyChain(): array
    {
        $filas = DB::table('ultron_studio_strategy_publications')->orderBy('seq')->get(['effective_json', 'signature']);
        try {
            $clave = self::appKeyRaw();
        } catch (Throwable $e) {
            // Tragado a propósito: sin clave la cadena no verifica (falla cerrado). Solo la clase.
            Log::warning('ultron_studio.strategy_chain_key_unavailable', ['error_class' => class_basename($e)]);

            return ['result' => 'signature_mismatch', 'seq' => $filas->isEmpty() ? null : 1, 'verified' => 0];
        }

        return StrategyChainSignature::verifyChain(
            $filas->map(static fn (object $f): array => ['message' => (string) $f->effective_json, 'signature' => (string) $f->signature])->all(),
            $clave,
        );
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /**
     * @param  Builder<StudioStrategyPublication>  $consulta
     * @return Builder<StudioStrategyPublication>
     */
    private static function withJson(Builder $consulta): Builder
    {
        return $consulta->select(self::COLUMNS)->selectRaw('substr(effective_json, 1, '.(StrategyChainSignature::MAX_MESSAGE + 1).') as effective_json');
    }

    /**
     * ¿Lo firmado es lo que dicen la fila, su estrategia y la persona que actuó?
     *
     * @param  array<string, mixed>  $m
     */
    private static function rowMatches(StudioStrategyPublication $fila, array $m): bool
    {
        $estrategia = StudioStrategy::query()->find($fila->strategy_id, ['id', 'uuid', 'key', 'purpose']);
        $persona = $fila->acted_by_person_id === null ? null : DB::table('identities')->where('id', $fila->acted_by_person_id)->value('uuid');

        return $estrategia !== null && self::matchesWith($fila, $m, $estrategia, is_string($persona) ? $persona : null);
    }

    /** @param  array<string, mixed>  $m */
    private static function matchesWith(StudioStrategyPublication $fila, array $m, StudioStrategy $estrategia, ?string $personaUuid): bool
    {
        return ($m['seq'] ?? null) === (int) $fila->seq
            && hash_equals((string) $fila->prev_hash, (string) ($m['prevHash'] ?? ''))
            && ($m['action'] ?? null) === $fila->action
            && (int) $fila->strategy_id === (int) $estrategia->id
            && ($m['strategy']['uuid'] ?? null) === (string) $estrategia->uuid
            && ($m['strategy']['key'] ?? null) === (string) $estrategia->key
            && ($m['strategy']['purpose'] ?? null) === (string) $estrategia->purpose
            && $personaUuid !== null && strtolower($personaUuid) === ($m['actedByPerson'] ?? null);
    }
}
