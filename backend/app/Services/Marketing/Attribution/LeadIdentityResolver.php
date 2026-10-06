<?php

namespace App\Services\Marketing\Attribution;

use App\Models\MarketingLead;
use App\Models\MarketingLeadIdentity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Qué persona del CRM hay detrás de un lead (regla D7).
 *
 *  1. Si el lead ya trae su socio (`marketing_leads.member_id`, que solo fija
 *     una persona al aceptar un reclamo de pago), ese manda.
 *  2. Si no, los 10 últimos dígitos de su teléfono contra `members.phone` y
 *     `users.phone`. Cuenta solo si señalan a UNA persona; con dos o más, el
 *     lead queda `ambiguous` y sin vínculo, porque una familia que comparte
 *     número no es un cliente.
 *
 * DESVIACIÓN DECLARADA DE D7. El contrato dice mirar `users.phone` solo «si no
 * hay socio». Aquí se miran siempre los dos: si casa UNA ficha de socio y,
 * además, OTRO usuario sin ficha tiene el mismo número, son dos personas y el
 * lead queda `ambiguous`. Es más conservador (vincula menos, nunca atribuye la
 * compra de otro): un familiar con cuenta de la app y el mismo celular que el
 * socio deja ese lead sin enlazar. La ficha y SU propio usuario sí son una
 * sola persona.
 *
 * Lo que se niega a hacer: escribir `marketing_leads.member_id`. Ese campo lo
 * leen el Inbox, ULTRON y la Supervisión como un hecho confirmado, y una
 * coincidencia de teléfono es solo una pista. El resultado va a una tabla
 * derivada, `marketing_lead_identities`, que se puede rehacer entera y que
 * escribe SOLO el comando `marketing:resolve-lead-identities` ({@see syncAll()}).
 * El panel resuelve en memoria ({@see resolveMany()}) y no escribe nada.
 *
 * TODO EN LOTE. Los teléfonos de socios y usuarios se guardan con cualquier
 * formato («300 123 4567», «+57…»), así que no se pueden comparar en SQL de
 * forma portable entre PostgreSQL y SQLite. Se recorren en PHP y se guarda un
 * índice «10 últimos dígitos → socios y usuarios» en la caché durante
 * `marketing.attribution.phone_index_cache_seconds` (300 s por defecto; 0 lo
 * apaga): así cada petición del panel no barre miles de fichas. Un socio dado
 * de alta hace un momento puede tardar ese tiempo en enlazarse. Nunca una
 * consulta por lead.
 */
class LeadIdentityResolver
{
    /** Filas por sentencia: lejos del límite de parámetros de los dos motores. */
    private const CHUNK = 500;

    /** Clave del índice de teléfonos en la caché. Con versión: si cambia la forma, cambia la clave. */
    public const PHONE_INDEX_CACHE_KEY = 'marketing:attribution:phone-index:v1';

    /**
     * El índice leído de la base para UNA operación del comando
     * ({@see syncAll()}, {@see summarize()}), que lo usa en todas sus tandas.
     * Fuera de ella vale null: nada se queda en la instancia entre llamadas,
     * porque una instancia puede vivir más que una petición.
     *
     * @var array{members: array<string, list<array{member_id: int, user_id: ?int}>>, users: array<string, list<int>>}|null
     */
    private ?array $operationIndex = null;

    /**
     * D7 para un lead. Puro: lee, no escribe.
     *
     * @return array{marketing_lead_id: int, user_id: ?int, member_id: ?int, method: string, candidates: int}
     */
    public function resolve(MarketingLead $lead): array
    {
        return $this->resolveMany(collect([$lead]))[(int) $lead->id];
    }

    /**
     * D7 para varios leads, con consultas en lote. Puro.
     *
     * @param  Collection<int, MarketingLead>  $leads
     * @return array<int, array{marketing_lead_id: int, user_id: ?int, member_id: ?int, method: string, candidates: int}>
     */
    public function resolveMany(Collection $leads): array
    {
        $leads = $leads->filter(fn ($lead) => $lead instanceof MarketingLead)->values();
        if ($leads->isEmpty()) {
            return [];
        }

        // 1) Socios explícitos: de ellos solo falta su usuario.
        $explicitIds = $leads->pluck('member_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $userOfMember = $this->usersOfMembers($explicitIds);

        // 2) Teléfonos que hay que buscar: los de los leads sin socio explícito.
        $wanted = [];
        foreach ($leads as $lead) {
            $digits = $lead->member_id === null ? LeadUniverse::last10($lead->phone) : null;
            if ($digits !== null) {
                $wanted[$digits] = true;
            }
        }
        [$memberMatches, $userMatches] = $this->phoneMatches($wanted);

        $out = [];
        $pendingMemberOfUser = [];

        foreach ($leads as $lead) {
            $id = (int) $lead->id;

            if ($lead->member_id !== null) {
                $memberId = (int) $lead->member_id;
                $out[$id] = $this->row($id, MarketingLeadIdentity::METHOD_EXPLICIT, $userOfMember[$memberId] ?? null, $memberId, 1);

                continue;
            }

            $digits = LeadUniverse::last10($lead->phone);
            $persons = $digits === null ? [] : $this->personsFor($digits, $memberMatches, $userMatches);

            if (count($persons) === 0) {
                $out[$id] = $this->row($id, MarketingLeadIdentity::METHOD_NONE, null, null, 0);

                continue;
            }

            if (count($persons) > 1) {
                $out[$id] = $this->row($id, MarketingLeadIdentity::METHOD_AMBIGUOUS, null, null, count($persons));

                continue;
            }

            $person = reset($persons);
            if ($person['via'] === 'member') {
                $out[$id] = $this->row($id, MarketingLeadIdentity::METHOD_PHONE_MEMBER, $person['user_id'], $person['member_id'], 1);

                continue;
            }

            // Casó un usuario sin ficha que coincida por teléfono: su ficha, si
            // la tiene, se busca después y en lote (por ella van los abonos).
            $out[$id] = $this->row($id, MarketingLeadIdentity::METHOD_PHONE_USER, $person['user_id'], null, 1);
            $pendingMemberOfUser[$person['user_id']] = true;
        }

        if ($pendingMemberOfUser !== []) {
            $memberOfUser = $this->membersOfUsers(array_keys($pendingMemberOfUser));
            foreach ($out as $id => $row) {
                if ($row['method'] === MarketingLeadIdentity::METHOD_PHONE_USER) {
                    $out[$id]['member_id'] = $memberOfUser[$row['user_id']] ?? null;
                }
            }
        }

        return $out;
    }

    /**
     * Resuelve y guarda: crea las filas que faltan y actualiza las que
     * cambiaron. Idempotente; una segunda pasada sin cambios no escribe nada.
     * Es la ÚNICA escritura de la tabla derivada, y la usa solo el comando.
     *
     * Sin leads, recorre todos los leads reales por tandas. Con `$dryRun`
     * calcula lo mismo y no escribe. Lee socios y usuarios de nuevo, no de la
     * caché: lo que se guarda tiene que ser lo de ahora.
     *
     * @param  Collection<int, MarketingLead>|null  $leads
     * @return int filas escritas (o que se escribirían)
     */
    public function syncAll(?Collection $leads = null, bool $dryRun = false): int
    {
        return $this->withFreshPhoneIndex(function () use ($leads, $dryRun): int {
            if ($leads !== null) {
                return $this->persist($this->resolveMany($leads), $dryRun);
            }

            $written = 0;
            $this->eachRealLeadChunk(function (Collection $chunk) use (&$written, $dryRun): void {
                $written += $this->persist($this->resolveMany($chunk), $dryRun);
            });

            return $written;
        });
    }

    /** Segundos de vida del índice de teléfonos en la caché; 0 lo apaga. */
    public static function phoneIndexCacheSeconds(): int
    {
        return max(0, (int) config('marketing.attribution.phone_index_cache_seconds', 300));
    }

    /**
     * Cuántos leads reales caen en cada método, sin escribir nada. Como
     * {@see syncAll()}, con socios y usuarios leídos de nuevo.
     *
     * @return array<string, int>
     */
    public function summarize(): array
    {
        return $this->withFreshPhoneIndex(function (): array {
            $counts = array_fill_keys(MarketingLeadIdentity::METHODS, 0);

            $this->eachRealLeadChunk(function (Collection $chunk) use (&$counts): void {
                foreach ($this->resolveMany($chunk) as $row) {
                    $counts[$row['method']]++;
                }
            });

            return $counts;
        });
    }

    // ── Escritura ───────────────────────────────────────────────────────────

    /**
     * @param  array<int, array<string, mixed>>  $resolved
     */
    private function persist(array $resolved, bool $dryRun = false): int
    {
        if ($resolved === []) {
            return 0;
        }

        $stored = [];
        foreach (array_chunk(array_keys($resolved), self::CHUNK) as $ids) {
            MarketingLeadIdentity::query()
                ->whereIn('marketing_lead_id', $ids)
                ->get(['marketing_lead_id', 'user_id', 'member_id', 'method', 'candidates'])
                ->each(function (MarketingLeadIdentity $row) use (&$stored): void {
                    $stored[(int) $row->marketing_lead_id] = $row;
                });
        }

        $now = now();
        $rows = [];
        foreach ($resolved as $leadId => $row) {
            $current = $stored[$leadId] ?? null;
            if ($current !== null
                && $current->method === $row['method']
                && $current->user_id === $row['user_id']
                && $current->member_id === $row['member_id']
                && $current->candidates === $row['candidates']) {
                continue;
            }

            $rows[] = $row + ['resolved_at' => $now];
        }

        // Siempre en el mismo orden (por lead): dos escritores que toman las
        // filas en orden distinto pueden bloquearse entre sí en PostgreSQL.
        usort($rows, fn (array $a, array $b): int => $a['marketing_lead_id'] <=> $b['marketing_lead_id']);

        if (! $dryRun) {
            foreach (array_chunk($rows, 200) as $chunk) {
                // La clave única hace que dos peticiones a la vez escriban lo
                // mismo sobre la misma fila en vez de duplicarla.
                MarketingLeadIdentity::query()->upsert(
                    $chunk,
                    ['marketing_lead_id'],
                    ['user_id', 'member_id', 'method', 'candidates', 'resolved_at'],
                );
            }
        }

        return count($rows);
    }

    // ── Lectura en lote ─────────────────────────────────────────────────────

    /** @param  callable(Collection<int, MarketingLead>): void  $callback */
    private function eachRealLeadChunk(callable $callback): void
    {
        LeadUniverse::constrain(MarketingLead::query())
            ->select(['id', 'member_id', 'phone'])
            ->chunkById(self::CHUNK, fn (Collection $chunk) => $callback($chunk));
    }

    /**
     * Socios y usuarios cuyo teléfono termina en alguno de los números
     * buscados, agrupados por esos 10 dígitos.
     *
     * @param  array<string, true>  $wanted
     * @return array{0: array<string, list<array{member_id: int, user_id: ?int}>>, 1: array<string, list<int>>}
     */
    private function phoneMatches(array $wanted): array
    {
        if ($wanted === []) {
            return [[], []];
        }

        $index = $this->phoneIndex();

        return [
            array_intersect_key($index['members'], $wanted),
            array_intersect_key($index['users'], $wanted),
        ];
    }

    /**
     * Corre una operación del comando con socios y usuarios leídos de la base
     * (no de la caché), una sola vez para todas sus tandas, y de paso renueva el
     * índice de la caché para el panel.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function withFreshPhoneIndex(callable $operation): mixed
    {
        $previous = $this->operationIndex;
        $this->operationIndex = $this->buildPhoneIndex();

        $ttl = self::phoneIndexCacheSeconds();
        if ($ttl > 0) {
            Cache::put(self::PHONE_INDEX_CACHE_KEY, $this->operationIndex, $ttl);
        }

        try {
            return $operation();
        } finally {
            $this->operationIndex = $previous;
        }
    }

    /**
     * El índice «10 últimos dígitos → socios y usuarios» de todo el CRM: el de
     * la operación del comando en curso; si no, el de la caché, y si no está (o
     * la caché está apagada), leído de la base.
     *
     * @return array{members: array<string, list<array{member_id: int, user_id: ?int}>>, users: array<string, list<int>>}
     */
    private function phoneIndex(): array
    {
        if ($this->operationIndex !== null) {
            return $this->operationIndex;
        }

        $ttl = self::phoneIndexCacheSeconds();

        return $ttl > 0
            ? Cache::remember(self::PHONE_INDEX_CACHE_KEY, $ttl, fn (): array => $this->buildPhoneIndex())
            : $this->buildPhoneIndex();
    }

    /**
     * Recorre socios y usuarios con teléfono, solo con las columnas necesarias.
     *
     * @return array{members: array<string, list<array{member_id: int, user_id: ?int}>>, users: array<string, list<int>>}
     */
    private function buildPhoneIndex(): array
    {
        $members = [];
        DB::table('members')
            ->select(['id', 'user_id', 'phone'])
            ->whereNotNull('phone')
            ->where('phone', '<>', '')
            ->chunkById(1000, function (Collection $rows) use (&$members): void {
                foreach ($rows as $r) {
                    $digits = LeadUniverse::last10($r->phone);
                    if ($digits !== null) {
                        $members[$digits][] = [
                            'member_id' => (int) $r->id,
                            'user_id' => $r->user_id !== null ? (int) $r->user_id : null,
                        ];
                    }
                }
            });

        $users = [];
        DB::table('users')
            ->select(['id', 'phone'])
            ->whereNotNull('phone')
            ->where('phone', '<>', '')
            ->chunkById(1000, function (Collection $rows) use (&$users): void {
                foreach ($rows as $r) {
                    $digits = LeadUniverse::last10($r->phone);
                    if ($digits !== null) {
                        $users[$digits][] = (int) $r->id;
                    }
                }
            });

        return ['members' => $members, 'users' => $users];
    }

    /**
     * Las personas distintas a las que apunta un número. Una ficha de socio y
     * su propio usuario son la misma persona; una ficha sin usuario cuenta por
     * sí sola.
     *
     * @param  array<string, list<array{member_id: int, user_id: ?int}>>  $memberMatches
     * @param  array<string, list<int>>  $userMatches
     * @return array<string, array{via: string, member_id: ?int, user_id: ?int}>
     */
    private function personsFor(string $digits, array $memberMatches, array $userMatches): array
    {
        $persons = [];

        foreach ($memberMatches[$digits] ?? [] as $m) {
            $key = $m['user_id'] !== null ? 'u'.$m['user_id'] : 'm'.$m['member_id'];
            $persons[$key] = ['via' => 'member', 'member_id' => $m['member_id'], 'user_id' => $m['user_id']];
        }

        foreach ($userMatches[$digits] ?? [] as $userId) {
            $persons['u'.$userId] ??= ['via' => 'user', 'member_id' => null, 'user_id' => $userId];
        }

        return $persons;
    }

    /**
     * @param  list<int>  $memberIds
     * @return array<int, ?int> member_id => user_id
     */
    private function usersOfMembers(array $memberIds): array
    {
        $out = [];
        foreach (array_chunk($memberIds, self::CHUNK) as $ids) {
            foreach (DB::table('members')->whereIn('id', $ids)->get(['id', 'user_id']) as $r) {
                $out[(int) $r->id] = $r->user_id !== null ? (int) $r->user_id : null;
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, int> user_id => member_id
     */
    private function membersOfUsers(array $userIds): array
    {
        $out = [];
        foreach (array_chunk($userIds, self::CHUNK) as $ids) {
            foreach (DB::table('members')->whereIn('user_id', $ids)->orderBy('id')->get(['id', 'user_id']) as $r) {
                $out[(int) $r->user_id] ??= (int) $r->id;
            }
        }

        return $out;
    }

    /** @return array{marketing_lead_id: int, user_id: ?int, member_id: ?int, method: string, candidates: int} */
    private function row(int $leadId, string $method, ?int $userId, ?int $memberId, int $candidates): array
    {
        return [
            'marketing_lead_id' => $leadId,
            'user_id' => $userId,
            'member_id' => $memberId,
            'method' => $method,
            'candidates' => $candidates,
        ];
    }
}
