<?php

namespace App\Services\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Models\Admin;
use App\Models\UltronStudio\StudioPersonBinding;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * LOS VÍNCULOS CUENTA → PERSONA y los CUATRO OJOS POR PERSONA (contrato H9,
 * D9-11.2, D9-13, P9-4, L9-6, L9-7).
 *
 * LA REGLA: una cuenta solo aprueba, publica, promueve o reanuda una
 * estrategia, y aprueba una plantilla, si tiene un vínculo VERIFICADO y sin
 * revocar con una fila de `identities` ({@see requirePerson()}). Se comparan
 * PERSONAS, no cuentas: dos cuentas de la misma persona comparten la identidad
 * y no se aprueban entre sí; una cuenta sin vínculo no aprueba.
 *
 * EL VÍNCULO (`ultron_studio_person_bindings`):
 *  - lo CREA `ultron.person_bindings_manage` para OTRA cuenta y nunca para su
 *    propia identidad («nunca el propio»), siendo quien crea una persona
 *    verificada (sin eso no se puede comprobar después que quien verifica es
 *    otra persona);
 *  - lo VERIFICA una segunda persona ya vinculada, distinta de la vinculada y
 *    de quien lo creó (por cuenta Y por persona); un vínculo no se verifica a
 *    sí mismo;
 *  - se REVOCA una vez, con motivo, y no se borra. Revocar y volver a vincular
 *    la misma cuenta en menos de {@see REBIND_WARNING_HOURS} h deja un aviso en
 *    el libro.
 *  - LAS DOS PRIMERAS personas se vinculan solo por consola
 *    ({@see bootstrap()}, `ultron-studio:person-bindings --bootstrap`), con la
 *    autorización escrita del propietario y el evento en el libro.
 *
 * Tres capas: este servicio, los CHECK de la base (DB9) y el libro. Sin
 * documento ni teléfono: de la identidad solo salen su id interno, su uuid (en
 * el mensaje firmado de la cadena) y una HUELLA (`identityRef`) para el CRM.
 */
final class StudioPersonBindings
{
    public const MANAGE = StudioStrategyConfig::PERSON_BINDINGS;

    public const PER_PAGE_MAX = 50;

    public const REASON_MIN = 5;

    public const REASON_MAX = 500;

    /** Revocar y volver a vincular la misma cuenta antes de esto deja un aviso en el libro (D9-11.2). */
    public const REBIND_WARNING_HOURS = 24;

    /** La autorización escrita del arranque (`bootstrap_authorization`, DB9). */
    public const AUTHORIZATION_MAX = 160;

    /** El nombre del actor del arranque (no es una persona: es la consola). */
    public const BOOTSTRAP_ACTOR = 'ultron-studio:person-bindings';

    /** La etiqueta de la huella de la identidad que ve el CRM (no reversible a un documento). */
    public const IDENTITY_REF_LABEL = 'ultron-studio-person/1';

    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_REVOKED = 'revoked';

    public function __construct(private readonly StudioAudit $audit) {}

    // ── La persona de una cuenta ────────────────────────────────────────────

    /**
     * La persona VERIFICADA de una cuenta: su vínculo verificado y sin revocar,
     * o null. Una sola consulta (el índice único parcial por cuenta viva).
     *
     * @return array{bindingId:int,bindingUuid:string,identityId:int,identityUuid:string}|null
     */
    public static function personOf(int $adminId): ?array
    {
        $fila = DB::table('ultron_studio_person_bindings as b')
            ->join('identities as i', 'i.id', '=', 'b.identity_id')
            ->where('b.admin_id', $adminId)
            ->whereNotNull('b.verified_at')
            ->whereNull('b.revoked_at')
            ->orderByDesc('b.id')
            ->first(['b.id', 'b.uuid', 'b.identity_id', 'i.uuid as identity_uuid']);

        if ($fila === null || ! is_string($fila->identity_uuid) || $fila->identity_uuid === '') {
            return null;
        }

        return [
            'bindingId' => (int) $fila->id,
            'bindingUuid' => (string) $fila->uuid,
            'identityId' => (int) $fila->identity_id,
            'identityUuid' => strtolower((string) $fila->identity_uuid),
        ];
    }

    /**
     * La persona verificada de quien actúa, o 403 `person_binding_required`
     * (el intento queda en el libro). Lo usan aprobar, publicar, promover,
     * reanudar y aprobar plantillas, y congelar versiones (sin la persona de la
     * autora no se pueden comparar personas después).
     *
     * @return array{bindingId:int,bindingUuid:string,identityId:int,identityUuid:string}
     */
    public function requirePerson(StudioAccess $access, ?Request $request, string $action, string $subjectType, ?string $subjectUuid): array
    {
        $persona = self::personOf($access->id());
        if ($persona !== null) {
            return $persona;
        }
        $this->audit->recordDenied($request, $access->actor, 'person_binding.required_denied', $subjectType, $subjectUuid,
            'Intentó una acción de cuatro ojos sin un vínculo verificado con una persona', ['diff' => ['action' => $action]]);

        throw new UltronStudioException(
            'Esta acción exige que tu cuenta esté vinculada y verificada con una persona. Pide el vínculo a quien administra el sistema.',
            'person_binding_required', 403, ['action' => $action],
        );
    }

    /**
     * ¿Son la misma persona? Por cuenta O por identidad: cualquiera de las dos
     * basta para negar (P9-4, L9-7).
     */
    public static function samePerson(int $adminA, ?int $identityA, int $adminB, ?int $identityB): bool
    {
        return $adminA === $adminB || ($identityA !== null && $identityB !== null && $identityA === $identityB);
    }

    // ── Crear (POST strategies/person-bindings) ─────────────────────────────

    /**
     * @return array{binding:StudioPersonBinding,replayed:bool}
     */
    public function create(StudioAccess $access, Request $request, string $clientRequestId, int $adminId, ?string $identityUuid, ?string $staffProfileUuid): array
    {
        $access->require(self::MANAGE);
        $llave = strtolower($clientRequestId);
        $previo = StudioPersonBinding::query()->where('client_request_id', $llave)->first();
        if ($previo !== null) {
            return $this->replayCreate($access, $previo, $adminId, $identityUuid, $staffProfileUuid);
        }

        $creadora = $this->requirePerson($access, $request, 'person_binding.create', StudioStrategyConfig::SUBJECT_PERSON_BINDING, null);
        $identidad = self::resolveIdentity($identityUuid, $staffProfileUuid);
        if ($adminId === $access->id() || $identidad['id'] === $creadora['identityId']) {
            $this->audit->recordDenied($request, $access->actor, 'person_binding.own_denied', StudioStrategyConfig::SUBJECT_PERSON_BINDING, null,
                'Intentó crear un vínculo para su propia cuenta o su propia persona', ['diff' => ['ownAccount' => $adminId === $access->id(), 'ownPerson' => $identidad['id'] === $creadora['identityId']]]);

            throw new UltronStudioException('Nadie crea el vínculo de su propia cuenta o de su propia persona: lo hace otra persona.', 'own_binding_forbidden', 403);
        }
        $cuenta = Admin::query()->find($adminId);
        if (! $cuenta instanceof Admin || ! $cuenta->isActive()) {
            throw new UltronStudioException('La cuenta no existe o no está activa.', 'admin_not_found', 404);
        }
        $reciente = StudioPersonBinding::query()->where('admin_id', $adminId)->whereNotNull('revoked_at')
            ->where('revoked_at', '>=', now()->subHours(self::REBIND_WARNING_HOURS))->exists();

        try {
            $vinculo = DB::transaction(function () use ($access, $request, $llave, $adminId, $identidad, $reciente): StudioPersonBinding {
                if (StudioPersonBinding::query()->where('admin_id', $adminId)->whereNull('revoked_at')->lockForUpdate()->exists()) {
                    throw new UltronStudioException('Esa cuenta ya tiene un vínculo vivo: revócalo antes de crear otro.', 'binding_exists', 409);
                }
                $yo = $access->snapshot();
                $fila = StudioPersonBinding::query()->forceCreate([
                    'uuid' => strtolower((string) Str::uuid()),
                    'client_request_id' => $llave,
                    'origin' => StudioPersonBinding::ORIGIN_CRM,
                    'admin_id' => $adminId,
                    'identity_id' => $identidad['id'],
                    'created_by_admin_id' => $yo['id'],
                    'created_by_name' => $yo['name'],
                    'created_by_role' => $yo['role'],
                ]);
                $this->audit->record($request, $access->actor, 'person_binding.created', StudioStrategyConfig::SUBJECT_PERSON_BINDING, $fila->uuid,
                    'Creó un vínculo cuenta → persona (pendiente de verificar por otra persona)', [
                        'toStatus' => self::STATUS_PENDING,
                        'diff' => ['admin' => $adminId, 'identityRef' => self::identityRef($identidad['uuid'])] + ($reciente ? ['warning' => 'rebind_after_recent_revoke'] : []),
                    ]);

                return $fila;
            });
        } catch (UniqueConstraintViolationException) {
            // La carrera: otro vínculo vivo para la misma cuenta entró antes (índice único parcial).
            throw new UltronStudioException('Esa cuenta ya tiene un vínculo vivo: revócalo antes de crear otro.', 'binding_exists', 409);
        }

        return ['binding' => $vinculo, 'replayed' => false];
    }

    // ── Verificar (POST strategies/person-bindings/{uuid}/verify) ───────────

    public function verify(StudioAccess $access, Request $request, string $uuid): StudioPersonBinding
    {
        $access->require(self::MANAGE);
        $vinculo = self::findByUuid($uuid);
        $verificadora = $this->requirePerson($access, $request, 'person_binding.verify', StudioStrategyConfig::SUBJECT_PERSON_BINDING, $vinculo->uuid);
        if ($vinculo->revoked_at !== null) {
            throw new UltronStudioException('El vínculo está revocado.', 'binding_revoked', 409);
        }
        if ($vinculo->verified_at !== null) {
            throw new UltronStudioException('El vínculo ya está verificado.', 'binding_already_verified', 409);
        }
        // Un vínculo del arranque nace verificado: aquí ya no llega (lo para la comprobación de arriba).
        $creadora = $vinculo->created_by_admin_id === null ? null : self::personOf((int) $vinculo->created_by_admin_id);
        $propio = self::samePerson($access->id(), $verificadora['identityId'], (int) $vinculo->admin_id, (int) $vinculo->identity_id);
        $deQuienLoCreo = $creadora === null || self::samePerson($access->id(), $verificadora['identityId'], (int) $vinculo->created_by_admin_id, $creadora['identityId']);
        if ($propio || $deQuienLoCreo) {
            $this->audit->recordDenied($request, $access->actor, 'person_binding.four_eyes_denied', StudioStrategyConfig::SUBJECT_PERSON_BINDING, $vinculo->uuid,
                'Intentó verificar un vínculo propio o que creó (o sin poder comprobar quién lo creó)', ['diff' => ['own' => $propio, 'creator' => $deQuienLoCreo]]);

            throw new UltronStudioException(
                $creadora === null
                    ? 'No se puede comprobar que quien verifica es otra persona que quien creó el vínculo (su cuenta ya no tiene vínculo verificado): crea el vínculo otra vez.'
                    : 'Un vínculo lo verifica otra persona: distinta de la vinculada y de quien lo creó.',
                'four_eyes_required', 403,
            );
        }

        return DB::transaction(function () use ($access, $request, $vinculo, $verificadora): StudioPersonBinding {
            $ahora = now();
            $hechas = StudioPersonBinding::query()->whereKey($vinculo->id)->whereNull('verified_at')->whereNull('revoked_at')->update([
                'verified_by_admin_id' => $access->id(),
                'verified_by_name' => $access->snapshot()['name'],
                'verified_by_identity_id' => $verificadora['identityId'],
                'verified_at' => $ahora,
                'updated_at' => $ahora,
            ]);
            if ($hechas !== 1) {
                throw new UltronStudioException('El vínculo cambió mientras lo verificabas: recárgalo.', 'binding_moved', 409);
            }
            $this->audit->record($request, $access->actor, 'person_binding.verified', StudioStrategyConfig::SUBJECT_PERSON_BINDING, $vinculo->uuid,
                'Verificó un vínculo cuenta → persona', ['fromStatus' => self::STATUS_PENDING, 'toStatus' => self::STATUS_VERIFIED, 'diff' => ['admin' => (int) $vinculo->admin_id]]);

            return $vinculo->refresh();
        });
    }

    // ── Revocar (POST strategies/person-bindings/{uuid}/revoke) ─────────────

    public function revoke(StudioAccess $access, Request $request, string $uuid, string $reason): StudioPersonBinding
    {
        $access->require(self::MANAGE);
        $vinculo = self::findByUuid($uuid);
        if ((int) $vinculo->admin_id === $access->id()) {
            throw new UltronStudioException('Nadie revoca el vínculo de su propia cuenta: lo hace otra persona.', 'own_binding_forbidden', 403);
        }
        if ($vinculo->revoked_at !== null) {
            throw new UltronStudioException('El vínculo ya está revocado.', 'binding_revoked', 409);
        }

        return DB::transaction(function () use ($access, $request, $vinculo, $reason): StudioPersonBinding {
            $ahora = now();
            $antes = $vinculo->verified_at !== null ? self::STATUS_VERIFIED : self::STATUS_PENDING;
            $hechas = StudioPersonBinding::query()->whereKey($vinculo->id)->whereNull('revoked_at')->update([
                'revoked_at' => $ahora,
                'revoked_by_admin_id' => $access->id(),
                'revoked_by_name' => $access->snapshot()['name'],
                'revoke_reason' => $reason,
                'updated_at' => $ahora,
            ]);
            if ($hechas !== 1) {
                throw new UltronStudioException('El vínculo cambió mientras lo revocabas: recárgalo.', 'binding_moved', 409);
            }
            $this->audit->record($request, $access->actor, 'person_binding.revoked', StudioStrategyConfig::SUBJECT_PERSON_BINDING, $vinculo->uuid,
                'Revocó un vínculo cuenta → persona', ['fromStatus' => $antes, 'toStatus' => self::STATUS_REVOKED, 'diff' => ['admin' => (int) $vinculo->admin_id, 'reason' => $reason]]);

            return $vinculo->refresh();
        });
    }

    // ── El arranque (solo consola) ──────────────────────────────────────────

    /**
     * LAS DOS PRIMERAS PERSONAS (D9-11.2): cada cuenta queda vinculada a su
     * identidad, de origen `bootstrap`: sin cuenta que la cree ni la
     * verifique (la consola, con la autorización escrita del propietario en
     * `bootstrap_authorization`), verificada al nacer (el CHECK
     * `uspb_origin_shape_chk` de DB9). Solo si todavía no hay NINGÚN vínculo
     * vivo (verificado o pendiente; la base admite hasta dos): después, los
     * vínculos los crea y verifica el CRM. El evento queda en el libro.
     *
     * @return list<StudioPersonBinding>
     */
    public function bootstrap(int $adminA, string $identityA, int $adminB, string $identityB, string $authorization): array
    {
        if ($adminA === $adminB) {
            throw new UltronStudioException('El arranque vincula dos cuentas DISTINTAS.', 'bootstrap_invalid', 422);
        }
        if (mb_strlen($authorization) < 10 || mb_strlen($authorization) > self::AUTHORIZATION_MAX) {
            throw new UltronStudioException('La autorización escrita del propietario ocupa de 10 a '.self::AUTHORIZATION_MAX.' caracteres.', 'bootstrap_invalid', 422);
        }
        $a = Admin::query()->find($adminA);
        $b = Admin::query()->find($adminB);
        if (! $a instanceof Admin || ! $b instanceof Admin || ! $a->isActive() || ! $b->isActive()) {
            throw new UltronStudioException('Las dos cuentas tienen que existir y estar activas.', 'admin_not_found', 404);
        }
        $ia = self::resolveIdentity($identityA, null);
        $ib = self::resolveIdentity($identityB, null);
        if ($ia['id'] === $ib['id']) {
            throw new UltronStudioException('El arranque vincula dos PERSONAS distintas.', 'bootstrap_invalid', 422);
        }

        return DB::transaction(function () use ($a, $b, $ia, $ib, $authorization): array {
            if (StudioPersonBinding::query()->whereNull('revoked_at')->lockForUpdate()->exists()) {
                throw new UltronStudioException('Ya hay vínculos vivos: el arranque solo vale para las dos primeras personas. Usa el CRM.', 'bootstrap_not_allowed', 409);
            }
            $ahora = now();
            $filas = [];
            foreach ([[$a, $ia], [$b, $ib]] as [$cuenta, $identidad]) {
                $filas[] = StudioPersonBinding::query()->forceCreate([
                    'uuid' => strtolower((string) Str::uuid()),
                    'client_request_id' => strtolower((string) Str::uuid()),
                    'origin' => StudioPersonBinding::ORIGIN_BOOTSTRAP,
                    'admin_id' => (int) $cuenta->id,
                    'identity_id' => $identidad['id'],
                    'bootstrap_authorization' => $authorization,
                    'created_by_admin_id' => null,
                    'created_by_name' => self::BOOTSTRAP_ACTOR,
                    'verified_at' => $ahora,
                ]);
            }
            foreach ($filas as $fila) {
                $this->audit->record(null, null, 'person_binding.bootstrapped', StudioStrategyConfig::SUBJECT_PERSON_BINDING, $fila->uuid,
                    'Arranque por consola: vínculo verificado de una de las dos primeras personas', [
                        'toStatus' => self::STATUS_VERIFIED,
                        'diff' => ['admin' => (int) $fila->admin_id, 'identityRef' => self::identityRef($fila->identity_id === $ia['id'] ? $ia['uuid'] : $ib['uuid']), 'authorization' => $authorization],
                    ], self::BOOTSTRAP_ACTOR);
            }

            return $filas;
        });
    }

    // ── Lecturas ────────────────────────────────────────────────────────────

    public static function findByUuid(string $uuid): StudioPersonBinding
    {
        $fila = StudioPersonBinding::query()->where('uuid', strtolower($uuid))->first();
        if ($fila === null) {
            throw new UltronStudioException('El vínculo no existe.', 'binding_not_found', 404);
        }

        return $fila;
    }

    /** `GET strategies/person-bindings` (≤ 50): sin documento ni teléfono. */
    public static function page(StudioAccess $access, ?string $status, int $perPage): LengthAwarePaginator
    {
        $access->require(self::MANAGE);
        $consulta = StudioPersonBinding::query()->orderByDesc('id');
        match ($status) {
            self::STATUS_PENDING => $consulta->whereNull('verified_at')->whereNull('revoked_at'),
            self::STATUS_VERIFIED => $consulta->whereNotNull('verified_at')->whereNull('revoked_at'),
            self::STATUS_REVOKED => $consulta->whereNotNull('revoked_at'),
            default => null,
        };
        $pagina = $consulta->paginate(min($perPage, self::PER_PAGE_MAX));
        // Los nombres de las cuentas, las huellas de las identidades y los perfiles, en tres consultas (sin N+1).
        $filas = $pagina->items();
        $cuentas = Admin::query()->whereIn('id', array_unique(array_merge(
            array_map(static fn (StudioPersonBinding $b): int => (int) $b->admin_id, $filas),
            array_filter(array_map(static fn (StudioPersonBinding $b): ?int => $b->created_by_admin_id, $filas)),
            array_filter(array_map(static fn (StudioPersonBinding $b): ?int => $b->verified_by_admin_id, $filas)),
        )))->get(['id', 'name', 'role'])->keyBy('id');
        $identidades = DB::table('identities')->whereIn('id', array_unique(array_map(static fn (StudioPersonBinding $b): int => (int) $b->identity_id, $filas)))->pluck('uuid', 'id');
        $perfiles = DB::table('staff_profiles')->whereIn('identity_id', array_unique(array_map(static fn (StudioPersonBinding $b): int => (int) $b->identity_id, $filas)))->pluck('full_name', 'identity_id');

        return $pagina->through(static fn (StudioPersonBinding $b): array => self::dto($b, $cuentas->all(), $identidades->all(), $perfiles->all()));
    }

    /**
     * `PersonBindingDto`: la cuenta (id, nombre y rol), la HUELLA de la
     * identidad y el nombre de su perfil laboral si lo tiene. Nunca documento
     * ni teléfono.
     *
     * @param  array<int, Admin>  $cuentas
     * @param  array<int, string>  $identidades
     * @param  array<int, string>  $perfiles
     * @return array<string, mixed>
     */
    public static function dto(StudioPersonBinding $b, array $cuentas = [], array $identidades = [], array $perfiles = []): array
    {
        $cuenta = static function (?int $id) use ($cuentas): ?array {
            if ($id === null) {
                return null;
            }
            $a = $cuentas[$id] ?? Admin::query()->find($id, ['id', 'name', 'role']);

            return $a === null ? ['id' => $id, 'name' => null, 'role' => null] : ['id' => (int) $a->id, 'name' => (string) $a->name, 'role' => $a->role];
        };
        $uuid = $identidades[(int) $b->identity_id] ?? DB::table('identities')->where('id', $b->identity_id)->value('uuid');
        $perfil = $perfiles[(int) $b->identity_id] ?? DB::table('staff_profiles')->where('identity_id', $b->identity_id)->value('full_name');

        return [
            'uuid' => (string) $b->uuid,
            'status' => $b->revoked_at !== null ? self::STATUS_REVOKED : ($b->verified_at !== null ? self::STATUS_VERIFIED : self::STATUS_PENDING),
            'origin' => (string) $b->origin,
            'account' => $cuenta((int) $b->admin_id),
            'identityRef' => is_string($uuid) ? self::identityRef($uuid) : null,
            'profileName' => is_string($perfil) ? $perfil : null,
            'createdBy' => $b->created_by_admin_id === null ? null : $cuenta((int) $b->created_by_admin_id),
            'verifiedBy' => $cuenta($b->verified_by_admin_id),
            'verifiedAt' => $b->verified_at?->toIso8601String(),
            'revokedAt' => $b->revoked_at?->toIso8601String(),
            'revokeReason' => $b->revoke_reason,
            'createdAt' => $b->created_at?->toIso8601String(),
        ];
    }

    /** La huella de una identidad para el CRM: 16 hex de sha256, con etiqueta propia. No identifica un documento. */
    public static function identityRef(string $identityUuid): string
    {
        return substr(hash('sha256', self::IDENTITY_REF_LABEL.'|'.strtolower($identityUuid)), 0, 16);
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /**
     * La identidad por su uuid o por el uuid del perfil laboral (exactamente uno).
     *
     * @return array{id:int,uuid:string}
     */
    private static function resolveIdentity(?string $identityUuid, ?string $staffProfileUuid): array
    {
        if (($identityUuid === null) === ($staffProfileUuid === null)) {
            throw new UltronStudioException('Indica la persona por su identidad o por su perfil laboral (uno de los dos).', 'identity_required', 422);
        }
        $fila = $identityUuid !== null
            ? DB::table('identities')->where('uuid', strtolower($identityUuid))->first(['id', 'uuid'])
            : DB::table('staff_profiles as s')->join('identities as i', 'i.id', '=', 's.identity_id')->where('s.uuid', strtolower((string) $staffProfileUuid))->first(['i.id', 'i.uuid']);
        if ($fila === null) {
            throw new UltronStudioException('La persona no existe.', 'identity_not_found', 404);
        }

        return ['id' => (int) $fila->id, 'uuid' => strtolower((string) $fila->uuid)];
    }

    /** @return array{binding:StudioPersonBinding,replayed:bool} */
    private function replayCreate(StudioAccess $access, StudioPersonBinding $previo, int $adminId, ?string $identityUuid, ?string $staffProfileUuid): array
    {
        $identidad = self::resolveIdentity($identityUuid, $staffProfileUuid);
        if ((int) $previo->created_by_admin_id !== $access->id() || (int) $previo->admin_id !== $adminId || (int) $previo->identity_id !== $identidad['id']) {
            throw UltronStudioException::clientRequestReused();
        }

        return ['binding' => $previo, 'replayed' => true];
    }
}
