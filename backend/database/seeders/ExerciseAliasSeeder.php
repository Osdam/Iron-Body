<?php

namespace Database\Seeders;

use App\Models\ExerciseAlias;
use App\Services\Exercises\ExerciseCatalogResolver;
use Illuminate\Database\Seeder;

/**
 * Aliases verificados de nombres de rutina → ejercicio real del catálogo.
 *
 * Origen: salida de `ironbody:exercise-media-audit` en producción (68 nombres
 * únicos). Aquí solo se vinculan los que tienen un equivalente CLARO y no
 * ambiguo. Los dudosos (ver PENDIENTES abajo) NO se siembran: quedan en
 * needs_review para revisión humana, para no asignar un video equivocado.
 *
 * Cada alias se resuelve por el NOMBRE EXACTO del catálogo en tiempo de
 * ejecución (no se hardcodean ids): así funciona en cualquier BD con el catálogo
 * y se OMITE de forma segura si ese nombre exacto no existe.
 *
 * Idempotente: re-ejecutarlo no duplica (updateOrCreate por alias+exercise_id).
 */
class ExerciseAliasSeeder extends Seeder
{
    /**
     * [alias usado en rutinas, nombre EXACTO del ejercicio en el catálogo, nota].
     *
     * @var list<array{0:string,1:string,2:string}>
     */
    private const SAFE_ALIASES = [
        // ── Pecho ──
        ['Press banca plano', 'Press banca plano con barra', 'Mismo patrón: press banca plano con barra.'],
        ['Press plano en máquina Hammer', 'Press de pecho en máquina', 'Press horizontal de pecho en máquina (equivalente).'],
        ['Press inclinado en máquina isolateral', 'Press banca inclinado en máquina Smith', 'Press inclinado guiado en máquina (equivalente).'],
        ['Fondos en banco', 'Fondos en banco', 'Coincidencia directa.'],

        // ── Hombro ──
        ['Press militar con barra', 'Press militar con barra de pie', 'Press militar con barra de pie (mismo ejercicio).'],
        ['Press militar con mancuernas', 'Press militar sentado con mancuernas', 'Press de hombro con mancuernas (equivalente).'],

        // ── Espalda ──
        ['Remo con barra T', 'Remo T-bar en máquina cargada con discos', 'Remo T-bar equivalente (confirmado).'],
        ['Remo polea sentado', 'Remo sentado en polea', 'Remo sentado en polea (mismo ejercicio).'],
        ['Remo polea sentado agarre abierto', 'Remo sentado en polea', 'Remo sentado en polea; el agarre no cambia el ejercicio del catálogo.'],
        ['Jalón polea agarre cerrado', 'Jalón cerrado en polea', 'Jalón cerrado en polea (mismo ejercicio).'],

        // ── Bíceps ──
        ['Curl martillo', 'Curl martillo con mancuernas', 'Curl martillo con mancuernas (mismo ejercicio).'],
        ['Curl de bíceps con mancuernas', 'Curl de bíceps con mancuernas', 'Coincidencia directa.'],
        ['Curl de bíceps en polea baja', 'Curl de bíceps en polea con barra', 'Curl de bíceps en polea (equivalente).'],
        ['Curl concentrado con mancuerna', 'Curl de concentración con mancuerna', 'Mismo ejercicio, redacción distinta.'],

        // ── Pierna ──
        ['Sentadilla goblet', 'Sentadilla goblet con mancuerna', 'Sentadilla goblet con mancuerna (mismo ejercicio).'],
        ['Sentadilla búlgara', 'Sentadilla búlgara con mancuernas', 'Sentadilla búlgara con mancuernas (equivalente).'],
        ['Extensión de rodilla en máquina sentado', 'Extensión de piernas en máquina', 'Extensión de cuádriceps en máquina (equivalente).'],
        ['Peso muerto con barra', 'Peso muerto con barra', 'Coincidencia directa.'],
    ];

    /**
     * PENDIENTES — requieren confirmar el nombre EXACTO del catálogo antes de
     * sembrar. NO se asignan automáticamente para evitar matches incorrectos:
     *   - Curl predicador            (falta candidato seguro confirmado)
     *   - Press banca inclinado      (¿"Press banca inclinado con barra"?)
     *   - Peso muerto con mancuerna  (falta candidato seguro confirmado)
     *   - Hip Thrust                 (falta candidato seguro confirmado)
     *   - Press francés              (biomecánicamente distinto: no asumir)
     *   - Curl antebrazo             (grupo muscular distinto)
     *   - Hacka                      (solo si existe hack squat real)
     *   - Jalón polea agarre abierto (solo si existe jalón abierto real)
     * Cuando se confirme su catalogName, basta moverlos a SAFE_ALIASES.
     */
    public function run(): void
    {
        $resolver = app(ExerciseCatalogResolver::class);
        $resolver->refresh();

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach (self::SAFE_ALIASES as [$alias, $catalogName, $notes]) {
            // El candidato debe existir EXACTO en el catálogo.
            $exercise = $resolver->resolveSafe(null, $catalogName);
            if ($exercise === null) {
                $skipped++;
                $this->command?->warn("Alias omitido (catálogo sin '{$catalogName}'): {$alias}");
                continue;
            }

            $row = ExerciseAlias::query()->updateOrCreate(
                ['normalized_alias' => $resolver->normalize($alias), 'exercise_id' => (int) $exercise->id],
                [
                    'alias_name'  => $alias,
                    'source'      => 'manual_verified',
                    'confidence'  => 1.000,
                    'is_verified' => true,
                    'notes'       => $notes,
                ],
            );

            $row->wasRecentlyCreated ? $created++ : $updated++;
        }

        $resolver->refresh();

        $verified = ExerciseAlias::query()->where('is_verified', true)->count();
        $this->command?->info(
            "ExerciseAliasSeeder: {$created} creados, {$updated} actualizados, {$skipped} omitidos · "
            . "aliases verificados totales: {$verified}."
        );
    }
}
