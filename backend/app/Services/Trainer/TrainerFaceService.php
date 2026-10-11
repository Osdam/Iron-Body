<?php

namespace App\Services\Trainer;

use App\Models\Trainer;
use App\Models\TrainerFaceReference;
use App\Services\Workforce\FaceReferenceService;
use App\Support\Workforce\FaceVector;
use Illuminate\Support\Collection;

/**
 * Vista del portal del entrenador sobre la plantilla facial.
 *
 * Desde 2026-10 la plantilla es de la PERSONA y la gestiona
 * {@see FaceReferenceService} (cifrada, con estados, sin sobrescrituras, con
 * control de norma y de intentos). Esta clase conserva las preguntas que ya
 * hacían el recurso del entrenador y el selector de la tablet.
 */
class TrainerFaceService
{
    public function __construct(private readonly FaceReferenceService $faces) {}

    /** ¿Tiene una plantilla ACTIVA? Una revocada no cuenta. */
    public function hasReference(Trainer $trainer): bool
    {
        return (bool) $this->faces->referenceForTrainer($trainer)?->isActive();
    }

    /**
     * Entrenadores ACTIVOS con plantilla ACTIVA (selector de la tablet).
     *
     * @return Collection<int, array{id:int, full_name:string}>
     */
    public function roster(): Collection
    {
        // Solo plantillas validadas: una pendiente de renovación no entra.
        $active = TrainerFaceReference::query()->active()->whereNotNull('consent_version')->get(['trainer_id', 'identity_id']);
        $byIdentity = $active->pluck('identity_id')->filter()->values();
        $byTrainer = $active->pluck('trainer_id')->filter()->values();

        return Trainer::query()
            ->whereIn('status', ['active', 'activo'])
            ->where(fn ($q) => $q->whereIn('id', $byTrainer)->orWhereIn('identity_id', $byIdentity))
            ->orderBy('full_name')
            ->get(['id', 'full_name'])
            ->map(fn (Trainer $t): array => [
                'id' => (int) $t->id,
                'full_name' => (string) $t->full_name,
            ])
            ->values();
    }

    /** Un rostro que la app pudo calcular: tamaño, finitos y norma 1. */
    public function isValidEmbedding(mixed $embedding): bool
    {
        return FaceVector::isValid($embedding, (float) config('workforce.face.norm_min'), (float) config('workforce.face.norm_max'));
    }
}
