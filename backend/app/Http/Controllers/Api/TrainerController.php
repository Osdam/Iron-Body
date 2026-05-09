<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Trainer;
use Illuminate\Http\Request;

class TrainerController extends Controller
{
    public function index(Request $request)
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

        $items = $query->orderByDesc('created_at')->get();

        return response()->json($items->map(fn ($t) => $this->serialize($t)));
    }

    public function show(Trainer $trainer)
    {
        return response()->json($this->serialize($trainer));
    }

    public function store(Request $request)
    {
        $validated = $this->validateInput($request, true);
        $trainer = Trainer::create($this->mapInput($validated));
        return response()->json($this->serialize($trainer), 201);
    }

    public function update(Request $request, Trainer $trainer)
    {
        $validated = $this->validateInput($request, false);
        $trainer->fill($this->mapInput($validated));
        $trainer->save();
        return response()->json($this->serialize($trainer));
    }

    public function destroy(Trainer $trainer)
    {
        $trainer->delete();
        return response()->json(null, 204);
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
            'certifications' => 'sometimes|nullable|string',
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
        return $out;
    }

    private function serialize(Trainer $t): array
    {
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
            'rating' => (float) $t->rating,
            'bio' => $t->bio ?? '',
            'certifications' => $t->certifications ?? '',
            'avatarUrl' => $t->avatar_url,
            'bannerUrl' => $t->banner_url,
            'availability' => $t->availability ?? [],
            'assignedClasses' => (int) $t->assigned_classes,
            'assignedMembers' => (int) $t->assigned_members,
            'createdAt' => optional($t->created_at)->toIso8601String(),
            'updatedAt' => optional($t->updated_at)->toIso8601String(),
        ];
    }
}
