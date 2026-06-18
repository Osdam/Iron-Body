<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Exercise extends Model
{
    protected $fillable = [
        'external_id',
        'name',
        'local_name',
        'matched_query',
        'body_part',
        'muscle_group',
        'target',
        'difficulty',
        'equipment',
        'description',
        'steps',
        'tips',
        'common_mistakes',
        'secondary_muscles',
        'muscles_worked',
        'suggested_sets',
        'suggested_reps',
        'gif_url',
        'gif_path',
        'video_path',
        'media_type',
        'playback_speed',
        'thumbnail_url',
        'instructions',
        'provider',
        'source',
        'last_synced_at',
    ];

    protected $casts = [
        'instructions'      => 'array',
        'steps'             => 'array',
        'tips'              => 'array',
        'common_mistakes'   => 'array',
        'secondary_muscles' => 'array',
        'muscles_worked'    => 'array',
        'suggested_sets'    => 'integer',
        'last_synced_at'    => 'datetime',
        'playback_speed'    => 'float',
    ];

    public function toReference(): array
    {
        return [
            'external_id'    => $this->external_id,
            'name'           => $this->name,
            'local_name'     => $this->local_name,
            'body_part'      => $this->body_part,
            'muscle_group'   => $this->muscle_group,
            'target'         => $this->target,
            'difficulty'     => $this->difficulty,
            'equipment'      => $this->equipment,
            'description'    => $this->description,
            'steps'          => $this->steps ?? [],
            'tips'           => $this->tips ?? [],
            'muscles_worked' => $this->muscles_worked ?? [],
            'gif_url'        => $this->gif_url,
            'thumbnail_url'  => $this->thumbnail_url,
            // Para ejercicios manuales (provider local) la media es una URL pública
            // ya almacenada; finalizeOne la usa como video_url.
            'video_path'     => $this->video_path,
            'instructions'   => $this->instructions ?? [],
            'provider'       => $this->provider,
            'source'         => $this->source,
            'media_type'     => $this->video_path ? 'video' : ($this->media_type ?? 'gif'),
            'playback_speed' => $this->playback_speed,
            '_has_video'     => $this->video_path ? 1 : 0,
        ];
    }
}
