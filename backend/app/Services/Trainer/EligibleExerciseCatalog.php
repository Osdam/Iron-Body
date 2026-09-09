<?php

namespace App\Services\Trainer;

use App\Models\Exercise;

/**
 * Los ejercicios que Iron IA puede elegir, y nada más.
 *
 * NO SE LE PIDE «INVENTA UNA RUTINA». Se le da la lista real del gimnasio y se
 * le exige que responda con `exercise_id`. Un modelo al que no se le acota el
 * repertorio escribe nombres plausibles —«press inclinado con mancuernas en
 * banco 30°»— que no existen en el catálogo, y el socio abriría su rutina y
 * encontraría un ejercicio sin vídeo ni instrucciones.
 *
 * ELEGIBILIDAD. La tabla `exercises` no tiene columna de activo: no hay
 * ejercicios apagados. Se exige lo mínimo para que el socio pueda ejecutarlo:
 * nombre y medio audiovisual. La fuente de vídeo real es `video_path` —los 206
 * ejercicios lo tienen y ninguno tiene `gif_url`—, así que filtrar por gif
 * dejaría el catálogo vacío.
 *
 * NO SE LE MANDA LA MEDIA. El modelo elige por nombre, músculo y equipamiento;
 * la ruta del vídeo no influye en la decisión y solo gastaría contexto.
 */
class EligibleExerciseCatalog
{
    /**
     * Cuántos caben en el prompt. Con 206 sobra, pero el límite existe para
     * que el día que el catálogo crezca no se envíe un contexto enorme sin que
     * nadie se dé cuenta.
     */
    private const MAX = 400;

    /**
     * La lista tal como la ve el modelo.
     *
     * @return list<array{id:int,name:string,muscle_group:?string,equipment:?string,difficulty:?string,target:?string}>
     */
    public function forPrompt(): array
    {
        return $this->query()
            ->limit(self::MAX)
            ->get(['id', 'name', 'local_name', 'muscle_group', 'body_part', 'equipment', 'difficulty', 'target'])
            ->map(fn (Exercise $e) => [
                'id' => (int) $e->id,
                // El nombre local es el que usa el gimnasio; el del proveedor
                // suele venir en inglés y confunde al modelo y al entrenador.
                'name' => $e->local_name ?: $e->name,
                'muscle_group' => $e->muscle_group ?: $e->body_part,
                'equipment' => $e->equipment,
                'difficulty' => $e->difficulty,
                'target' => $e->target,
            ])
            ->all();
    }

    public function count(): int
    {
        return $this->query()->count();
    }

    /**
     * Un ejercicio sirve si el socio puede identificarlo y verlo hacer.
     * Nada más: excluir por otros criterios reduciría el repertorio sin que
     * nadie lo hubiera pedido.
     */
    private function query()
    {
        return Exercise::query()
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->whereNotNull('video_path')
            ->orderBy('id');
    }
}
