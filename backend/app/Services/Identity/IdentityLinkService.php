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
     * La ficha de socio que lleva el documento de esta identidad, o null. SOLO
     * LECTURA: no enlaza nada. Coincidir por documento IDENTIFICA a quién
     * habría que verificar; no prueba que la ficha sea de esa persona (eso es
     * el OTP al teléfono registrado del socio, ver {@see attachMember()}).
     */
    public function memberCandidate(Identity $identity): ?Member
    {
        return $this->memberForDocument($identity->document_normalized);
    }

    /**
     * El socio de un documento, o null. Solo lectura, como {@see memberCandidate()},
     * para quien todavía no tiene identidad (la comprobación previa del alta).
     *
     * Los dos normalizadores no son el mismo: `members.document_number` se guarda
     * con {@see Member::normalizeDocumentNumber()} (quita espacios, puntos y
     * guiones; respeta mayúsculas y minúsculas) y la identidad con
     * {@see DocumentNormalizer} (solo alfanumérico, en mayúsculas). En una cédula
     * coinciden; un documento de extranjero puede estar en minúsculas en la ficha
     * de socio. Se busca primero la forma exacta y después sin distinguir
     * mayúsculas, y el resultado se confirma con el normalizador de identidades:
     * lo que este no reconozca como el mismo documento no es candidato.
     */
    public function memberForDocument(?string $document): ?Member
    {
        $normalized = DocumentNormalizer::normalize($document);
        if ($normalized === null) {
            return null;
        }

        $candidate = Member::query()->where('document_number', $normalized)->first();
        if ($candidate === null) {
            // `document_number` es único tal cual, no sin mayúsculas: dos fichas
            // podrían diferir solo en eso. Con más de una no se elige ninguna,
            // porque adivinar aquí es proponer la ficha de otra persona.
            $fallback = Member::query()->whereRaw('upper(document_number) = ?', [$normalized])->limit(2)->get();
            $candidate = $fallback->count() === 1 ? $fallback->first() : null;
        }

        return $candidate !== null && DocumentNormalizer::normalize($candidate->document_number) === $normalized
            ? $candidate
            : null;
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
