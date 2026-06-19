<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use App\Support\SseStream;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Gestión MANUAL del catálogo de ejercicios desde el CRM.
 *
 * Sustituye al sync de proveedores externos: el gimnasio crea sus propios
 * ejercicios y sube su MP4 de referencia. La app los consume vía
 * /app/exercises (catálogo) y /exercises/search (provider=local) sin cambios.
 */
class ExerciseController extends Controller
{
    private const ARRAY_FIELDS = ['steps', 'tips', 'common_mistakes', 'secondary_muscles', 'muscles_worked'];

    /** GET /api/admin/exercises?search=&muscle_group=&page= */
    public function index(Request $request): JsonResponse
    {
        $query = Exercise::query()->orderBy('muscle_group')->orderBy('name');

        if ($request->filled('muscle_group')) {
            $query->where('muscle_group', $request->query('muscle_group'));
        }
        if ($request->filled('search')) {
            $like = '%' . trim((string) $request->query('search')) . '%';
            $query->where(function ($q) use ($like): void {
                $q->where('name', 'like', $like)
                    ->orWhere('local_name', 'like', $like)
                    ->orWhere('muscle_group', 'like', $like)
                    ->orWhere('target', 'like', $like);
            });
        }

        $page = $query->paginate((int) min(max($request->integer('per_page', 30), 1), 100));

        return response()->json([
            'ok'    => true,
            'data'  => collect($page->items())->map(fn (Exercise $e) => $this->serialize($e))->values(),
            'meta'  => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
            ],
        ]);
    }

    /**
     * GET /api/admin/exercises/stream — tiempo real (SSE). Cuando CUALQUIER
     * usuario crea, edita o borra un ejercicio, los demás CRM abiertos refrescan
     * el catálogo al instante (firma = total + última modificación).
     */
    public function stream(Request $request): StreamedResponse
    {
        $signature = static fn (): string => Exercise::count() . ':' . (string) Exercise::max('updated_at');

        $last = null;

        return SseStream::response(function () use (&$last, $signature): void {
            $now = $signature();
            if ($last === null) {
                $last = $now; // primer tick: línea base, no dispara
                return;
            }
            if ($now !== $last) {
                $last = $now;
                SseStream::emit('exercises', ['sig' => $now]);
            }
        }, 25, 2000); // sondeo cada 2s durante ~25s; el cliente reconecta solo
    }

    /** POST /api/admin/exercises */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateExercise($request);
        $data['external_id'] = 'local-' . Str::uuid()->toString();
        $data['provider'] = 'local';
        $data['source'] = 'manual';
        $data['media_type'] = ! empty($data['video_path']) ? 'video' : 'gif';

        $exercise = Exercise::create($data);

        return response()->json(['ok' => true, 'data' => $this->serialize($exercise)], 201);
    }

    /** PUT/PATCH /api/admin/exercises/{exercise} */
    public function update(Request $request, Exercise $exercise): JsonResponse
    {
        $data = $this->validateExercise($request, partial: true);
        if (array_key_exists('video_path', $data)) {
            $data['media_type'] = ! empty($data['video_path']) ? 'video' : 'gif';
        }
        // Mantener el catálogo como manual aunque venga de un sync previo.
        $data['provider'] = 'local';
        $data['source'] = 'manual';

        $exercise->update($data);

        return response()->json(['ok' => true, 'data' => $this->serialize($exercise->fresh())]);
    }

    /** DELETE /api/admin/exercises/{exercise} */
    public function destroy(Exercise $exercise): JsonResponse
    {
        $exercise->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/admin/exercises/upload — sube un MP4 o imagen y devuelve su URL
     * pública. El CRM la guarda en video_path / thumbnail_url / gif_url.
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:mp4,webm,gif,jpg,jpeg,png,webp', 'max:10240'], // 10 MB
        ], [
            'file.max' => 'El archivo supera el máximo de 10 MB. Comprímelo e inténtalo de nuevo.',
        ]);

        $file = $request->file('file');
        $isVideo = in_array(strtolower($file->getClientOriginalExtension()), ['mp4', 'webm'], true);
        $folder = $isVideo ? 'exercises/videos' : 'exercises/images';
        $name = Str::uuid()->toString() . '.' . strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $path = $file->storeAs($folder, $name, 'public');

        return response()->json([
            'ok'   => true,
            'data' => [
                'path' => $path,
                'url'  => Storage::disk('public')->url($path),
                'kind' => $isVideo ? 'video' : 'image',
            ],
        ]);
    }

    // ── Internos ────────────────────────────────────────────────────────────

    private function validateExercise(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        $validated = $request->validate([
            'name'              => "$req|string|max:160",
            'local_name'        => 'nullable|string|max:160',
            'muscle_group'      => 'nullable|string|max:80',
            'body_part'         => 'nullable|string|max:80',
            'target'            => 'nullable|string|max:80',
            'equipment'         => 'nullable|string|max:80',
            'difficulty'        => 'nullable|string|max:40',
            'description'       => 'nullable|string',
            'steps'             => 'nullable|array',
            'steps.*'           => 'string|max:500',
            'tips'              => 'nullable|array',
            'tips.*'            => 'string|max:500',
            'common_mistakes'   => 'nullable|array',
            'common_mistakes.*' => 'string|max:500',
            'secondary_muscles' => 'nullable|array',
            'secondary_muscles.*' => 'string|max:80',
            'muscles_worked'    => 'nullable|array',
            'muscles_worked.*'  => 'string|max:80',
            'suggested_sets'    => 'nullable|integer|min:1|max:20',
            'suggested_reps'    => 'nullable|string|max:40',
            'playback_speed'    => 'nullable|numeric|min:0.25|max:3',
            'video_path'        => 'nullable|string|max:1000', // URL pública del MP4
            'thumbnail_url'     => 'nullable|string|max:1000',
            'gif_url'           => 'nullable|string|max:1000',
        ]);

        // Las instructions del provider/app espejan steps.
        if (array_key_exists('steps', $validated)) {
            $validated['instructions'] = $validated['steps'];
        }

        return $validated;
    }

    private function serialize(Exercise $e): array
    {
        return [
            'id'                => $e->id,
            'external_id'       => $e->external_id,
            'name'              => $e->name,
            'local_name'        => $e->local_name,
            'muscle_group'      => $e->muscle_group,
            'body_part'         => $e->body_part,
            'target'            => $e->target,
            'equipment'         => $e->equipment,
            'difficulty'        => $e->difficulty,
            'description'       => $e->description,
            'steps'             => $e->steps ?? [],
            'tips'              => $e->tips ?? [],
            'common_mistakes'   => $e->common_mistakes ?? [],
            'secondary_muscles' => $e->secondary_muscles ?? [],
            'muscles_worked'    => $e->muscles_worked ?? [],
            'suggested_sets'    => (int) ($e->suggested_sets ?? 3),
            'suggested_reps'    => $e->suggested_reps ?? '8-12',
            'playback_speed'    => $e->playback_speed,
            'video_url'         => $e->video_path,       // URL pública del MP4
            'thumbnail_url'     => $e->thumbnail_url,
            'gif_url'           => $e->gif_url,
            'media_type'        => $e->video_path ? 'video' : 'gif',
            'provider'          => $e->provider,
        ];
    }
}
