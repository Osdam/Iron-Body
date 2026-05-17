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
        'target',
        'equipment',
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
        'instructions'   => 'array',
        'last_synced_at'  => 'datetime',
        'playback_speed'  => 'float',
    ];

    /**
     * Forma normalizada que consume Flutter (vía Laravel). Estable e
     * independiente del proveedor externo.
     */
    public function toReference(): array
    {
        return [
            'external_id'  => $this->external_id,
            'name'         => $this->name,
            'local_name'   => $this->local_name,
            'body_part'    => $this->body_part,
            'target'       => $this->target,
            'equipment'    => $this->equipment,
            'gif_url'        => $this->gif_url,
            'thumbnail_url'  => $this->thumbnail_url,
            'instructions'   => $this->instructions ?? [],
            'provider'       => $this->provider,
            'source'         => $this->source,
            // Marca si hay MP4; la URL final la arma la capa provider.
            'media_type'     => $this->video_path ? 'video' : ($this->media_type ?? 'gif'),
            'playback_speed' => $this->playback_speed,
            '_has_video'     => $this->video_path ? 1 : 0,
        ];
    }
}
