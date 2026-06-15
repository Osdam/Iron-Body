<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Trainer;
use App\Models\TrainerRole;
use App\Services\RealtimeEvents;
use App\Services\Trainer\TrainerProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TrainerController extends Controller
{
    public function __construct(private readonly TrainerProfileService $profiles) {}

    public function index(): View
    {
        $trainers = Trainer::withAvg('reviews', 'rating')
            ->withCount('reviews')
            ->withCount(['professionalSessions as active_sessions_count' => fn ($q) => $q->whereNull('revoked_at')])
            ->with('roleAssignments')
            ->orderByDesc('reviews_avg_rating')
            ->orderByDesc('reviews_count')
            ->get();

        return view('crm.trainers.index', compact('trainers'));
    }

    public function create(): View
    {
        return view('crm.trainers.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateTrainer($request, true);

        // Crea el entrenador Y lo integra con el portal (identidad + roles) en una
        // sola transacción. Mismo registro/tabla que ya administra /crm/trainers.
        DB::transaction(function () use ($data) {
            $trainer = Trainer::create($this->mapFields($data));
            $this->profiles->linkIdentityAndRoles($trainer, $data['roles'] ?? [], 'crm_blade');
        });

        RealtimeEvents::rankingChanged();

        return redirect()->route('crm.trainers.index')
            ->with('success', 'Entrenador creado exitosamente.');
    }

    public function edit(Trainer $trainer): View
    {
        $trainer->load('roleAssignments');

        return view('crm.trainers.edit', compact('trainer'));
    }

    public function update(Request $request, Trainer $trainer): RedirectResponse
    {
        $data = $this->validateTrainer($request, false);
        $wasActive = $trainer->isActive();

        DB::transaction(function () use ($trainer, $data) {
            $trainer->fill($this->mapFields($data))->save();
            $this->profiles->linkIdentityAndRoles(
                $trainer,
                array_key_exists('roles', $data) ? ($data['roles'] ?? []) : null,
                'crm_blade',
            );
        });

        // Si quedó inactivo, corta el acceso profesional (revoca sesiones).
        $this->profiles->revokeOnDeactivation($trainer, $wasActive, 'crm_blade');

        RealtimeEvents::rankingChanged();

        return redirect()->route('crm.trainers.index')
            ->with('success', 'Entrenador actualizado.');
    }

    public function destroy(Trainer $trainer): RedirectResponse
    {
        $wasActive = $trainer->isActive();
        $trainer->update(['status' => 'inactive']);

        // Desactivar conserva miembro/membresía/historial pero corta el portal.
        $this->profiles->revokeOnDeactivation($trainer, $wasActive, 'crm_blade');

        RealtimeEvents::rankingChanged();

        return redirect()->route('crm.trainers.index')
            ->with('success', 'Entrenador desactivado.');
    }

    public function ratings(Trainer $trainer): View
    {
        $reviews = $trainer->reviews()
            ->with('member:id,full_name,document_number')
            ->latest()
            ->get();

        return view('crm.trainers.ratings', compact('trainer', 'reviews'));
    }

    private function validateTrainer(Request $request, bool $required): array
    {
        $req = $required ? 'required' : 'sometimes';

        return $request->validate([
            'name' => "$req|string|max:120",
            'specialty' => "$req|string|max:120",
            'bio' => 'nullable|string',
            'experience_years' => 'nullable|integer|min:0|max:80',
            'student_count' => 'nullable|integer|min:0',
            'photo_url' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
            // ── Portal profesional (aditivo) ─────────────────────────────────
            'document' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:30',
            'location' => 'nullable|string|max:120',
            'roles' => 'nullable|array',
            'roles.*' => ['string', Rule::in(TrainerRole::ALL)],
        ]);
    }

    private function mapFields(array $data): array
    {
        $mapped = [];

        if (isset($data['name'])) {
            $mapped['full_name'] = $data['name'];
        }
        if (isset($data['specialty'])) {
            $mapped['main_specialty'] = $data['specialty'];
        }
        if (array_key_exists('bio', $data)) {
            $mapped['bio'] = $data['bio'];
        }
        if (array_key_exists('experience_years', $data)) {
            $mapped['experience_years'] = (int) ($data['experience_years'] ?? 0);
        }
        if (array_key_exists('student_count', $data)) {
            $mapped['assigned_members'] = (int) ($data['student_count'] ?? 0);
        }
        if (array_key_exists('photo_url', $data)) {
            $mapped['avatar_url'] = $data['photo_url'];
        }
        // Campos del portal profesional (OTP/identidad/sede).
        if (array_key_exists('document', $data)) {
            $mapped['document'] = $data['document'];
        }
        if (array_key_exists('phone', $data)) {
            $mapped['phone'] = $data['phone'];
        }
        if (array_key_exists('location', $data)) {
            $mapped['location'] = $data['location'];
        }

        $mapped['status'] = ($data['is_active'] ?? true) ? 'active' : 'inactive';

        return $mapped;
    }
}
