<?php

namespace App\Services\Identity;

use App\Exceptions\IdentityLinkException;
use App\Models\Identity;
use App\Models\Member;
use App\Models\Trainer;
use App\Support\DocumentNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Resolución y enlace de identidades. Punto único para:
 *   - Asegurar que existe UNA identidad por documento (anti-duplicado), seguro
 *     ante condiciones de carrera mediante índice único + reintento.
 *   - Enlazar un perfil de miembro o de entrenador a una identidad SOLO cuando
 *     la propiedad ha sido verificada (OTP al teléfono registrado). Conocer el
 *     documento no basta: el enlace cross-perfil exige `$ownershipVerified`.
 *
 * Todas las operaciones de escritura corren en transacción y son idempotentes:
 * re-enlazar un perfil ya asociado a su identidad es un no-op sin verificación.
 */
class IdentityLinkService
{
    /**
     * Backfill idempotente de los datos existentes: cada miembro/entrenador sin
     * identidad recibe la suya (compartida por documento). Reutilizable desde la
     * migración de backfill y desde un comando de mantenimiento. Devuelve el
     * conteo de filas enlazadas.
     *
     * @return array{members:int, trainers:int}
     */
    public function backfillExisting(): array
    {
        $members = 0;
        $trainers = 0;

        Member::query()->whereNull('identity_id')->orderBy('id')
            ->chunkById(500, function ($rows) use (&$members): void {
                foreach ($rows as $member) {
                    $identity = $this->ensureIdentity($member->document_number, $member->phone);
                    $member->forceFill(['identity_id' => $identity->getKey()])->save();
                    $members++;
                }
            });

        Trainer::query()->whereNull('identity_id')->orderBy('id')
            ->chunkById(500, function ($rows) use (&$trainers): void {
                foreach ($rows as $trainer) {
                    $identity = $this->ensureIdentity($trainer->document, $trainer->phone);
                    $trainer->forceFill(['identity_id' => $identity->getKey()])->save();
                    $trainers++;
                }
            });

        return ['members' => $members, 'trainers' => $trainers];
    }

    /**
     * Identidad existente para un documento, o null. Útil para detectar a una
     * persona ya registrada antes de crear un perfil nuevo (anti-duplicado).
     */
    public function findByDocument(?string $document): ?Identity
    {
        $normalized = DocumentNormalizer::normalize($document);

        if ($normalized === null) {
            return null;
        }

        return Identity::query()
            ->where('document_normalized', $normalized)
            ->first();
    }

    /**
     * Devuelve la identidad del documento, creándola si no existe. Seguro ante
     * carreras: si dos peticiones intentan crearla a la vez, el índice único
     * deja pasar una y la otra reintenta leyendo la ganadora.
     *
     * Un documento no normalizable (vacío/sin alfanumérico) NO se deduplica: se
     * crea una identidad dedicada para no fusionar personas distintas.
     */
    public function ensureIdentity(?string $document, ?string $phone = null): Identity
    {
        $normalized = DocumentNormalizer::normalize($document);
        $phoneNormalized = DocumentNormalizer::normalizePhone($phone);

        if ($normalized === null) {
            return Identity::create([
                'document_normalized' => null,
                'phone_normalized' => $phoneNormalized,
            ]);
        }

        $existing = Identity::query()->where('document_normalized', $normalized)->first();
        if ($existing !== null) {
            return $existing;
        }

        try {
            return Identity::create([
                'document_normalized' => $normalized,
                'phone_normalized' => $phoneNormalized,
            ]);
        } catch (QueryException $e) {
            // Carrera: otra petición creó la identidad entre el SELECT y el
            // INSERT. Releemos la ganadora en lugar de duplicar.
            $winner = Identity::query()->where('document_normalized', $normalized)->first();
            if ($winner !== null) {
                return $winner;
            }
            throw $e;
        }
    }

    /**
     * Enlaza un miembro a una identidad. Idempotente si ya está enlazado a ella.
     * Cambiar de identidad o enlazar por primera vez a una identidad ajena exige
     * propiedad verificada por OTP.
     */
    public function attachMember(Member $member, Identity $identity, bool $ownershipVerified = false): Member
    {
        return $this->attachProfile($member, 'identity_id', $identity, $ownershipVerified);
    }

    /**
     * Enlaza un entrenador a una identidad con las mismas garantías que el
     * miembro. Permite que una persona sea miembro y entrenador con una sola
     * identidad sin duplicarse.
     */
    public function attachTrainer(Trainer $trainer, Identity $identity, bool $ownershipVerified = false): Trainer
    {
        return $this->attachProfile($trainer, 'identity_id', $identity, $ownershipVerified);
    }

    /**
     * @template T of Member|Trainer
     *
     * @param  T  $profile
     * @return T
     */
    private function attachProfile($profile, string $column, Identity $identity, bool $ownershipVerified)
    {
        $current = $profile->getAttribute($column);

        // Ya enlazado a esta misma identidad: no-op idempotente (sin OTP).
        if ($current !== null && (int) $current === (int) $identity->getKey()) {
            return $profile;
        }

        if (! $ownershipVerified) {
            throw IdentityLinkException::ownershipNotVerified();
        }

        return DB::transaction(function () use ($profile, $column, $identity) {
            $profile->forceFill([$column => $identity->getKey()])->save();

            return $profile;
        });
    }
}
