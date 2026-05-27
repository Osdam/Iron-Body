<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrainerResource;
use App\Models\Member;
use App\Models\Trainer;
use App\Models\TrainerReview;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrainerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($request->boolean('admin')) {
            return $this->adminIndex($request);
        }

        $trainers = Trainer::whereIn('status', ['active', 'Activo', 'activo'])
            ->with(['ratings'])
            ->get()
            ->sortByDesc(fn (Trainer $t) => $t->ratings->avg('rating'))
            ->values();

        return response()->json([
            'data' => TrainerResource::collection($trainers),
        ]);
    }

    private function adminIndex(Request $request)
    {
        $query = Trainer::query();

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('search')) {
            $term = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($term) {
                $q->where('full_name', 'like', $term)
                  ->orWhere('main_specialty', 'like', $term)
                  ->orWhere('email', 'like', $term);
            });
        }

        $items = $query
            ->withAvg('reviews', 'rating')
            ->withCount('reviews')
            ->with('reviews.member:id,full_name')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($items->map(fn ($t) => $this->serialize($t)));
    }

    public function show(Request $request, Trainer $trainer)
    {
        if ($request->boolean('admin')) {
            return response()->json($this->serialize($trainer));
        }

        if (! $trainer->isActive()) {
            abort(404);
        }

        $trainer->load(['reviews.member.user'])
            ->loadAvg('reviews', 'rating')
            ->loadCount('reviews');

        return response()->json([
            'ok' => true,
            'data' => array_merge(
                $this->serializeMobile($trainer, $this->rankPosition($trainer)),
                [
                    'reviews' => $trainer->reviews
                        ->sortByDesc('created_at')
                        ->values()
                        ->map(fn (TrainerReview $review): array => [
                            'id' => $review->id,
                            'member_id' => $review->member_id,
                            'member_name' => $review->member?->full_name ?: $review->member?->user?->name,
                            'rating' => (int) $review->rating,
                            'comment' => $review->comment,
                            'created_at' => optional($review->created_at)->toIso8601String(),
                        ])
                        ->all(),
                ]
            ),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateInput($request, true);
        $trainer = Trainer::create($this->mapInput($validated));

        // Notificación de entrenador creado (ADITIVO; no afecta la creación).
        app(NotificationService::class)->notifyTrainerCreated($trainer);

        $trainer->loadAvg('reviews', 'rating')->loadCount('reviews');
        return response()->json($this->serialize($trainer), 201);
    }

    public function update(Request $request, Trainer $trainer)
    {
        $validated = $this->validateInput($request, false);
        $trainer->fill($this->mapInput($validated));
        $trainer->save();

        // Notificación de entrenador actualizado (ADITIVO; idempotente por hash).
        app(NotificationService::class)->notifyTrainerUpdated($trainer);

        $trainer->loadAvg('reviews', 'rating')->loadCount('reviews');
        return response()->json($this->serialize($trainer));
    }

    public function destroy(Trainer $trainer)
    {
        // Notifica ANTES de borrar para conservar nombre/id (ADITIVO).
        app(NotificationService::class)->notifyTrainerDeleted($trainer);

        $trainer->delete();
        return response()->json(null, 204);
    }

    public function rate(Request $request, Trainer $trainer): JsonResponse
    {
        if (! $trainer->is_active) {
            abort(404);
        }

        $data = $request->validate([
            'rating'  => 'required|numeric|min:1|max:5',
            'comment' => 'nullable|string|max:500',
        ], [
            'rating.min' => 'La calificación debe estar entre 1 y 5',
            'rating.max' => 'La calificación debe estar entre 1 y 5',
        ]);

        $member = $request->attributes->get('auth_member');

        $trainer->ratings()->updateOrCreate(
            ['member_id' => $member->id],
            [
                'rating'  => round($data['rating'], 1),
                'comment' => $data['comment'] ?? null,
            ]
        );

        $trainer->load('ratings');

        return response()->json([
            'data' => new TrainerResource($trainer),
        ]);
    }

    public function review(Request $request, Trainer $trainer)
    {
        if (! $trainer->isActive()) {
            return response()->json([
                'message' => 'Solo puedes calificar entrenadores activos.',
            ], 422);
        }

        $validated = $request->validate([
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $review = $trainer->reviews()->updateOrCreate(
            ['member_id' => $validated['member_id']],
            [
                'rating' => $validated['rating'],
                'comment' => $validated['comment'] ?? null,
            ]
        );

        $trainer->loadAvg('reviews', 'rating')->loadCount('reviews');

        return response()->json([
            'ok' => true,
            'message' => 'Calificación registrada.',
            'data' => [
                'trainer_id' => $trainer->id,
                'member_id' => (int) $review->member_id,
                'rating' => (int) $review->rating,
                'comment' => $review->comment,
                'trainer_rating' => $this->ratingValue($trainer),
                'reviews_count' => (int) $trainer->reviews_count,
            ],
        ]);
    }

    private function validateInput(Request $request, bool $required): array
    {
        return $request->validate([
            'fullName' => $required ? 'required|string|max:255' : 'sometimes|string|max:255',
            'document' => 'sometimes|nullable|string|max:50',
            'phone' => 'sometimes|nullable|string|max:30',
            'email' => 'sometimes|nullable|email|max:255',
            'birthDate' => 'sometimes|nullable|date',
            'mainSpecialty' => 'sometimes|nullable|string|max:255',
            'specialties' => 'sometimes|nullable|array',
            'experienceYears' => 'sometimes|nullable|integer|min:0|max:80',
            'contractType' => 'sometimes|nullable|string|max:100',
            'status' => 'sometimes|nullable|string|max:50',
            'rating' => 'sometimes|nullable|numeric|min:0|max:5',
            'bio' => 'sometimes|nullable|string',
            'certifications' => 'sometimes|nullable',
            'avatarUrl' => 'sometimes|nullable|string',
            'bannerUrl' => 'sometimes|nullable|string',
            'availability' => 'sometimes|nullable|array',
        ]);
    }

    private function mapInput(array $data): array
    {
        $out = [];
        $directMap = [
            'fullName' => 'full_name',
            'document' => 'document',
            'phone' => 'phone',
            'email' => 'email',
            'birthDate' => 'birth_date',
            'mainSpecialty' => 'main_specialty',
            'specialties' => 'specialties',
            'experienceYears' => 'experience_years',
            'contractType' => 'contract_type',
            'status' => 'status',
            'rating' => 'rating',
            'bio' => 'bio',
            'certifications' => 'certifications',
            'avatarUrl' => 'avatar_url',
            'bannerUrl' => 'banner_url',
            'availability' => 'availability',
        ];
        foreach ($directMap as $camel => $snake) {
            if (array_key_exists($camel, $data)) $out[$snake] = $data[$camel];
        }
        if (array_key_exists('certifications', $out) && is_array($out['certifications'])) {
            $out['certifications'] = json_encode(array_values(array_filter(array_map(
                fn (mixed $item): string => trim((string) $item),
                $out['certifications']
            ))));
        }
        return $out;
    }

    private function serialize(Trainer $t): array
    {
        $recentReviews = $t->relationLoaded('reviews')
            ? $t->reviews
                ->sortByDesc('created_at')
                ->take(3)
                ->values()
                ->map(fn ($r) => [
                    'memberName' => $r->member?->full_name ?? 'Miembro',
                    'rating'     => (float) $r->rating,
                    'comment'    => $r->comment ?? '',
                    'createdAt'  => optional($r->created_at)->toIso8601String(),
                ])
            : [];

        return [
            'id' => (string) $t->id,
            'fullName' => $t->full_name,
            'document' => $t->document ?? '',
            'phone' => $t->phone ?? '',
            'email' => $t->email ?? '',
            'birthDate' => optional($t->birth_date)->toDateString(),
            'mainSpecialty' => $t->main_specialty ?? '',
            'specialties' => $t->specialties ?? [],
            'experienceYears' => (int) $t->experience_years,
            'contractType' => $t->contract_type ?? '',
            'status' => $t->status ?? 'active',
            'bio' => $t->bio ?? '',
            'certifications' => $t->certifications ?? '',
            'avatarUrl' => $t->avatar_url,
            'bannerUrl' => $t->banner_url,
            'availability' => $t->availability ?? [],
            'assignedClasses' => (int) $t->assigned_classes,
            'assignedMembers' => (int) $t->assigned_members,
            'reviewsAvgRating' => round((float) ($t->reviews_avg_rating ?? 0), 1),
            'reviewsCount' => (int) ($t->reviews_count ?? 0),
            'recentReviews' => $recentReviews,
            'createdAt' => optional($t->created_at)->toIso8601String(),
            'updatedAt' => optional($t->updated_at)->toIso8601String(),
        ];
    }

    private function rankedActiveQuery(Request $request)
    {
        $query = Trainer::query()
            ->whereIn('status', ['active', 'Activo', 'activo'])
            ->withAvg('reviews', 'rating')
            ->withCount('reviews');

        if ($request->filled('specialty')) {
            $specialty = $request->input('specialty');
            $term = '%' . $specialty . '%';
            $query->where(function ($q) use ($specialty, $term) {
                $q->where('main_specialty', $specialty)
                    ->orWhere('main_specialty', 'like', $term)
                    ->orWhere('specialties', 'like', $term);
            });
        }

        if ($request->filled('search')) {
            $term = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($term) {
                $q->where('full_name', 'like', $term)
                    ->orWhere('main_specialty', 'like', $term)
                    ->orWhere('bio', 'like', $term);
            });
        }

        return $query
            ->orderByDesc('reviews_avg_rating')
            ->orderByDesc('reviews_count')
            ->orderBy('full_name');
    }

    private function serializeMobile(Trainer $trainer, int $rankPosition): array
    {
        return [
            'id' => $trainer->id,
            'full_name' => $trainer->full_name,
            'specialty' => $trainer->main_specialty ?? '',
            'bio' => $trainer->bio ?? '',
            'photo_url' => $trainer->publicPhotoUrl(),
            'rating' => $this->ratingValue($trainer),
            'reviews_count' => (int) ($trainer->reviews_count ?? 0),
            'years_experience' => (int) $trainer->experience_years,
            'certifications' => $trainer->certificationsArray(),
            'is_active' => $trainer->isActive(),
            'rank_position' => $rankPosition,
        ];
    }

    private function ratingValue(Trainer $trainer): float
    {
        return round((float) ($trainer->reviews_avg_rating ?? 0), 1);
    }

    private function rankPosition(Trainer $trainer): int
    {
        $ids = $this->rankedActiveQuery(new Request())
            ->pluck('id')
            ->values();

        $index = $ids->search($trainer->id);

        return $index === false ? 0 : $index + 1;
    }
}
