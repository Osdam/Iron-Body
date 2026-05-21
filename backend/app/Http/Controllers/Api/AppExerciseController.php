<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppExerciseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Exercise::orderBy('muscle_group')->orderBy('name');

        if ($request->filled('muscle_group')) {
            $query->where('muscle_group', $request->input('muscle_group'));
        }
        if ($request->filled('difficulty')) {
            $query->where('difficulty', $request->input('difficulty'));
        }
        if ($request->filled('search')) {
            $term = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                  ->orWhere('muscle_group', 'like', $term)
                  ->orWhere('description', 'like', $term);
            });
        }

        $exercises = $query->get();

        return response()->json([
            'data' => $exercises->map(fn (Exercise $e): array => [
                'id'                => (string) $e->id,
                'name'              => $e->name,
                'muscle_group'      => $e->muscle_group ?? $e->body_part ?? '',
                'equipment'         => $e->equipment ?? '',
                'difficulty'        => $e->difficulty ?? 'Principiante',
                'description'       => $e->description ?? '',
                'steps'             => $e->steps ?? [],
                'tips'              => $e->tips ?? [],
                'common_mistakes'   => $e->common_mistakes ?? [],
                'secondary_muscles' => $e->secondary_muscles ?? [],
                'muscles_worked'    => $e->muscles_worked ?? [],
                'suggested_sets'    => (int) ($e->suggested_sets ?? 3),
                'suggested_reps'    => $e->suggested_reps ?? '8-12',
                'gif_url'           => $e->gif_url,
                'thumbnail_url'     => $e->thumbnail_url,
                'video_url'         => $e->video_path,
            ]),
        ]);
    }
}
