<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrainerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $avgRating = round($this->ratings->avg('rating') ?? 0, 1);
        $ratingCount = $this->ratings->count();

        $reviews = $this->ratings()
            ->with('member:id,full_name')
            ->latest()
            ->take(5)
            ->get()
            ->map(fn ($r) => [
                'user_name'  => $r->member->full_name ?? 'Miembro',
                'rating'     => (float) $r->rating,
                'comment'    => $r->comment ?? '',
                'created_at' => $r->created_at->toIso8601String(),
            ]);

        return [
            'id'               => (string) $this->id,
            'name'             => $this->full_name,
            'specialty'        => $this->main_specialty ?? '',
            'bio'              => $this->bio ?? '',
            'experience_years' => (int) $this->experience_years,
            'student_count'    => (int) ($this->assigned_members ?? 0),
            'average_rating'   => (float) $avgRating,
            'rating_count'     => $ratingCount,
            'photo_url'        => $this->publicPhotoUrl(),
            'reviews'          => $reviews,
        ];
    }
}
