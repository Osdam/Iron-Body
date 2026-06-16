<?php

namespace App\Services\Trainer;

use App\Models\Trainer;
use App\Models\TrainerFaceReference;
use Illuminate\Support\Collection;

/**
 * Lógica del login facial de entrenadores (kiosko en tablet). El match ocurre
 * AQUÍ (backend): el dispositivo solo envía el embedding vivo; la referencia
 * nunca sale del servidor, así un dispositivo no puede autenticarse como alguien
 * cuya referencia no conoce. Distancia euclídea con el mismo umbral que on-device.
 */
class TrainerFaceService
{
    private function embeddingSize(): int
    {
        return (int) config('trainer.face.embedding_size', 192);
    }

    private function threshold(): float
    {
        return (float) config('trainer.face.match_threshold', 1.0);
    }

    /** Guarda/reemplaza la referencia facial del entrenador (enrolamiento). */
    public function enroll(Trainer $trainer, array $embedding): TrainerFaceReference
    {
        return TrainerFaceReference::updateOrCreate(
            ['trainer_id' => $trainer->getKey()],
            ['embedding' => array_map('floatval', $embedding), 'enrolled_at' => now()],
        );
    }

    public function hasReference(Trainer $trainer): bool
    {
        return TrainerFaceReference::where('trainer_id', $trainer->getKey())->exists();
    }

    /**
     * Compara el embedding vivo con la referencia del entrenador.
     *
     * @return array{matched: bool, distance: ?float}
     */
    public function match(Trainer $trainer, array $liveEmbedding): array
    {
        $ref = TrainerFaceReference::where('trainer_id', $trainer->getKey())->first();
        if ($ref === null || ! is_array($ref->embedding)) {
            return ['matched' => false, 'distance' => null];
        }

        $reference = $ref->embedding;
        if (count($reference) !== count($liveEmbedding)) {
            return ['matched' => false, 'distance' => null];
        }

        $distance = $this->euclidean($reference, $liveEmbedding);

        return [
            'matched'  => $distance !== null && $distance <= $this->threshold(),
            'distance' => $distance,
        ];
    }

    /**
     * Entrenadores ACTIVOS con rostro enrolado (para el selector de la tablet).
     *
     * @return Collection<int, array{id:int, full_name:string}>
     */
    public function roster(): Collection
    {
        $trainerIds = TrainerFaceReference::query()->pluck('trainer_id');

        return Trainer::query()
            ->whereIn('id', $trainerIds)
            ->whereIn('status', ['active', 'activo'])
            ->orderBy('full_name')
            ->get(['id', 'full_name'])
            ->map(fn (Trainer $t): array => [
                'id' => (int) $t->id,
                'full_name' => (string) $t->full_name,
            ])
            ->values();
    }

    /** Valida que el embedding tenga el tamaño correcto y números finitos. */
    public function isValidEmbedding(mixed $embedding): bool
    {
        if (! is_array($embedding) || count($embedding) !== $this->embeddingSize()) {
            return false;
        }
        foreach ($embedding as $v) {
            if (! is_numeric($v) || ! is_finite((float) $v)) {
                return false;
            }
        }

        return true;
    }

    private function euclidean(array $a, array $b): ?float
    {
        $sum = 0.0;
        foreach ($a as $i => $av) {
            $diff = (float) $av - (float) ($b[$i] ?? 0.0);
            $sum += $diff * $diff;
        }

        return sqrt($sum);
    }
}
