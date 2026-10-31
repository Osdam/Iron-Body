<?php

namespace App\Services\Workforce;

use App\Exceptions\WorkforceException;
use App\Models\FaceLookalike;
use App\Models\FaceLookalikeException;
use App\Models\Identity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pares de personas cuyo rostro el modelo no separa, y las excepciones
 * presenciales para registrar el rostro pese al parecido (contrato §10.5).
 *
 * Un par nace solo, cuando un enrolamiento se rechaza porque el rostro coincide
 * con la plantilla de otra persona. Mientras exista, las dos personas necesitan
 * rostro + PIN: el rostro solo no dice cuál de las dos está delante, tampoco
 * antes de que la segunda tenga rostro registrado.
 *
 * La excepción solo deja registrar el rostro; nunca interviene al verificar.
 */
class LookalikeRegistry
{
    /** Registra el par (o renueva su fecha). Devuelve si es nuevo. */
    public function record(int $a, int $b, string $source = FaceLookalike::SOURCE_DUPLICATE_REJECTED): bool
    {
        if ($a === $b) {
            return false;
        }
        [$low, $high] = [min($a, $b), max($a, $b)];
        // Dos rechazos a la vez no pueden duplicar el par (índice único).
        $inserted = DB::table('face_lookalikes')->insertOrIgnore([
            'identity_low_id' => $low,
            'identity_high_id' => $high,
            'source' => $source,
            'first_detected_at' => now(),
            'last_detected_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        FaceLookalike::query()->where('identity_low_id', $low)->where('identity_high_id', $high)
            ->update(['last_detected_at' => now(), 'updated_at' => now()]);

        return $inserted > 0;
    }

    public function isPaired(int $identityId): bool
    {
        return FaceLookalike::query()
            ->where(fn ($q) => $q->where('identity_low_id', $identityId)->orWhere('identity_high_id', $identityId))
            ->exists();
    }

    public function arePaired(int $a, int $b): bool
    {
        return FaceLookalike::query()
            ->where('identity_low_id', min($a, $b))
            ->where('identity_high_id', max($a, $b))
            ->exists();
    }

    /** @return Collection<int, FaceLookalike> */
    public function pairsOf(int $identityId): Collection
    {
        return FaceLookalike::query()
            ->where(fn ($q) => $q->where('identity_low_id', $identityId)->orWhere('identity_high_id', $identityId))
            ->orderBy('id')
            ->get();
    }

    /**
     * Concede la excepción: las dos personas comprobadas en persona por un
     * administrador, solo para un par registrado. Anula la anterior del par.
     */
    public function grantException(Identity $subject, Identity $other, string $reason, ?int $adminId, string $adminName): FaceLookalikeException
    {
        if (! $this->arePaired((int) $subject->getKey(), (int) $other->getKey())) {
            throw WorkforceException::lookalikeNotRegistered();
        }

        return DB::transaction(function () use ($subject, $other, $reason, $adminId, $adminName): FaceLookalikeException {
            FaceLookalikeException::query()
                ->where('identity_id', $subject->getKey())
                ->where('other_identity_id', $other->getKey())
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            return FaceLookalikeException::create([
                'identity_id' => $subject->getKey(),
                'other_identity_id' => $other->getKey(),
                'subject_verified_in_person' => true,
                'other_verified_in_person' => true,
                'reason' => mb_substr($reason, 0, 500),
                'authorized_by_admin_id' => $adminId,
                'authorized_by_name' => mb_substr($adminName, 0, 120),
                'expires_at' => now()->addMinutes((int) config('workforce.codes.ttl_minutes', 30)),
            ]);
        });
    }

    /**
     * Excepciones vivas de quien se registra: otra persona del par => id de
     * la excepción. Solo cuentan si el par sigue registrado.
     *
     * @return array<int, int>
     */
    public function liveExceptions(int $subjectId): array
    {
        $out = [];
        $rows = FaceLookalikeException::query()
            ->where('identity_id', $subjectId)
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->get();
        foreach ($rows as $row) {
            if (! isset($out[(int) $row->other_identity_id]) && $this->arePaired($subjectId, (int) $row->other_identity_id)) {
                $out[(int) $row->other_identity_id] = (int) $row->getKey();
            }
        }

        return $out;
    }

    /** La excepción viva de un par visto desde quien se registra (para el CRM). */
    public function liveException(int $subjectId, int $otherId): ?FaceLookalikeException
    {
        return FaceLookalikeException::query()
            ->where('identity_id', $subjectId)
            ->where('other_identity_id', $otherId)
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Consume las excepciones usadas en un enrolamiento, dentro de su
     * transacción. Si alguna ya no está viva (se usó o caducó entre la
     * comprobación y aquí), el rostro se rechaza como siempre.
     *
     * @param  list<int>  $ids
     */
    public function consumeExceptions(array $ids, int $referenceId): void
    {
        if ($ids === []) {
            return;
        }
        $updated = FaceLookalikeException::query()
            ->whereIn('id', $ids)
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->update(['consumed_at' => now(), 'consumed_reference_id' => $referenceId, 'updated_at' => now()]);

        if ($updated !== count($ids)) {
            throw WorkforceException::faceAlreadyLinked();
        }
    }
}
