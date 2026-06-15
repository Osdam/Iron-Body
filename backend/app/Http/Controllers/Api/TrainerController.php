<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrainerResource;
use App\Models\Trainer;
use App\Models\TrainerAuditLog;
use App\Models\TrainerReview;
use App\Models\TrainerRole;
use App\Services\Identity\IdentityLinkService;
use App\Services\NotificationService;
use App\Services\RealtimeEvents;
use App\Services\Trainer\TrainerAuditService;
use App\Services\Trainer\TrainerSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
            $term = '%'.$request->input('search').'%';
            $query->where(function ($q) use ($term) {
                $q->where('full_name', 'like', $term)
                    ->orWhere('main_specialty', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }

        $items = $query
            ->withAvg('reviews', 'rating')
            ->withCount('reviews')
            ->withCount(['professionalSessions as active_sessions_count' => fn ($q) => $q->whereNull('revoked_at')])
            ->with(['reviews.member:id,full_name', 'roleAssignments'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json($items->map(fn ($t) => $this->serialize($t)));
    }

    public function show(Request $request, Trainer $trainer)
    {
        if ($request->boolean('admin')) {
            return response()->json($this->serialize($this->loadProfessional($trainer)));
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

        // Crear el entrenador Y vincular su identidad/rol en una sola transacción:
        // el mismo módulo /trainers queda integrado con el portal profesional, sin
        // CRUD ni tabla paralela. Idempotente y anti-duplicado por documento.
        $trainer = DB::transaction(function () use ($validated) {
            $trainer = Trainer::create($this->mapInput($validated));
            $this->syncProfessional($trainer, $validated);

            return $trainer;
        });

        // Notificación de entrenador creado (ADITIVO; no afecta la creación).
        app(NotificationService::class)->notifyTrainerCreated($trainer);

        return response()->json($this->serialize($this->loadProfessional($trainer)), 201);
    }

    public function update(Request $request, Trainer $trainer)
    {
        $validated = $this->validateInput($request, false);
        $wasActive = $trainer->isActive();

        DB::transaction(function () use ($trainer, $validated) {
            $trainer->fill($this->mapInput($validated))->save();
            $this->syncProfessional($trainer, $validated);
        });

        // Si la edición dejó al entrenador inactivo, se corta el acceso
        // profesional al instante (revoca sesiones). Conserva miembro/historial.
        if ($wasActive && ! $trainer->fresh()->isActive()) {
            $revoked = app(TrainerSessionService::class)->revokeAll($trainer, 'trainer_deactivated');
            app(TrainerAuditService::class)->record(
                TrainerAuditLog::EVENT_DEACTIVATED,
                $trainer,
                actorType: TrainerAuditLog::ACTOR_ADMIN,
                metadata: ['revoked_sessions' => $revoked, 'source' => 'crm_trainers'],
                request: $request,
            );
        }

        // Notificación de entrenador actualizado (ADITIVO; idempotente por hash).
        app(NotificationService::class)->notifyTrainerUpdated($trainer);

        return response()->json($this->serialize($this->loadProfessional($trainer)));
    }

    /**
     * Vincula el entrenador a su identidad (creándola si no existe, REUSANDO la
     * del miembro si comparten documento) y sincroniza roles si vienen en el
     * formulario. Es el puente del módulo /trainers existente con el portal.
     * Idempotente; nunca crea dos identidades para el mismo documento.
     */
    private function syncProfessional(Trainer $trainer, array $validated): void
    {
        $identities = app(IdentityLinkService::class);
        $identity = $identities->ensureIdentity($trainer->document, $trainer->phone);
        $identities->attachTrainer($trainer, $identity, ownershipVerified: true);

        if (array_key_exists('roles', $validated)) {
            $trainer->syncRoles($validated['roles'] ?? []);
        }

        app(TrainerAuditService::class)->record(
            TrainerAuditLog::EVENT_IDENTITY_LINKED,
            $trainer,
            actorType: TrainerAuditLog::ACTOR_ADMIN,
            metadata: ['identity_id' => $identity->getKey(), 'source' => 'crm_trainers'],
        );
    }

    private function loadProfessional(Trainer $trainer): Trainer
    {
        return $trainer->loadAvg('reviews', 'rating')
            ->loadCount('reviews')
            ->loadCount(['professionalSessions as active_sessions_count' => fn ($q) => $q->whereNull('revoked_at')])
            ->load('roleAssignments');
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
            'rating' => 'required|numeric|min:1|max:5',
            'comment' => 'nullable|string|max:500',
        ], [
            'rating.min' => 'La calificación debe estar entre 1 y 5',
            'rating.max' => 'La calificación debe estar entre 1 y 5',
        ]);

        $member = $request->attributes->get('auth_member');

        $trainer->ratings()->updateOrCreate(
            ['member_id' => $member->id],
            [
                'rating' => round($data['rating'], 1),
                'comment' => $data['comment'] ?? null,
            ]
        );

        $trainer->load('ratings');

        // El ranking cambió → refresca el módulo para todos en vivo.
        RealtimeEvents::rankingChanged();

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

        // El ranking cambió → refresca el módulo para todos en vivo.
        RealtimeEvents::rankingChanged();

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
            // Sede y roles profesionales (aditivos: el portal usa estos campos).
            'location' => 'sometimes|nullable|string|max:120',
            'roles' => 'sometimes|array',
            'roles.*' => ['string', Rule::in(TrainerRole::ALL)],
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
            'location' => 'location',
            'status' => 'status',
            'rating' => 'rating',
            'bio' => 'bio',
            'certifications' => 'certifications',
            'avatarUrl' => 'avatar_url',
            'bannerUrl' => 'banner_url',
            'availability' => 'availability',
        ];
        foreach ($directMap as $camel => $snake) {
            if (array_key_exists($camel, $data)) {
                $out[$snake] = $data[$camel];
            }
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
                    'rating' => (float) $r->rating,
                    'comment' => $r->comment ?? '',
                    'createdAt' => optional($r->created_at)->toIso8601String(),
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
            'location' => $t->location ?? '',
            'status' => $t->status ?? 'active',
            // ── Portal profesional (aditivo) ─────────────────────────────────
            'identityId' => $t->identity_id,
            'roles' => $t->relationLoaded('roleAssignments') ? $t->roleNames() : [],
            'permissions' => $t->relationLoaded('roleAssignments') ? $t->permissions() : [],
            'portalAccess' => $t->relationLoaded('roleAssignments')
                && $t->isActive()
                && $t->roleNames() !== []
                && trim((string) $t->phone) !== '',
            'activeSessions' => (int) ($t->active_sessions_count ?? 0),
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
            $term = '%'.$specialty.'%';
            $query->where(function ($q) use ($specialty, $term) {
                $q->where('main_specialty', $specialty)
                    ->orWhere('main_specialty', 'like', $term)
                    ->orWhere('specialties', 'like', $term);
            });
        }

        if ($request->filled('search')) {
            $term = '%'.$request->input('search').'%';
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
        $ids = $this->rankedActiveQuery(new Request)
            ->pluck('id')
            ->values();

        $index = $ids->search($trainer->id);

        return $index === false ? 0 : $index + 1;
    }
}
