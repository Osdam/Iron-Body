<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Trainer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TrainerController extends Controller
{
    public function index(): View
    {
        $trainers = Trainer::withAvg('reviews', 'rating')
            ->withCount('reviews')
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
        Trainer::create($this->mapFields($data));

        return redirect()->route('crm.trainers.index')
            ->with('success', 'Entrenador creado exitosamente.');
    }

    public function edit(Trainer $trainer): View
    {
        return view('crm.trainers.edit', compact('trainer'));
    }

    public function update(Request $request, Trainer $trainer): RedirectResponse
    {
        $data = $this->validateTrainer($request, false);
        $trainer->fill($this->mapFields($data))->save();

        return redirect()->route('crm.trainers.index')
            ->with('success', 'Entrenador actualizado.');
    }

    public function destroy(Trainer $trainer): RedirectResponse
    {
        $trainer->update(['status' => 'inactive']);

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
            'name'             => "$req|string|max:120",
            'specialty'        => "$req|string|max:120",
            'bio'              => 'nullable|string',
            'experience_years' => 'nullable|integer|min:0|max:80',
            'student_count'    => 'nullable|integer|min:0',
            'photo_url'        => 'nullable|string|max:500',
            'is_active'        => 'nullable|boolean',
        ]);
    }

    private function mapFields(array $data): array
    {
        $mapped = [];

        if (isset($data['name']))             $mapped['full_name']        = $data['name'];
        if (isset($data['specialty']))        $mapped['main_specialty']   = $data['specialty'];
        if (array_key_exists('bio', $data))   $mapped['bio']              = $data['bio'];
        if (array_key_exists('experience_years', $data)) {
            $mapped['experience_years'] = (int) ($data['experience_years'] ?? 0);
        }
        if (array_key_exists('student_count', $data)) {
            $mapped['assigned_members'] = (int) ($data['student_count'] ?? 0);
        }
        if (array_key_exists('photo_url', $data)) $mapped['avatar_url'] = $data['photo_url'];

        $mapped['status'] = ($data['is_active'] ?? true) ? 'active' : 'inactive';

        return $mapped;
    }
}
