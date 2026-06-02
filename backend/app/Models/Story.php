<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $author_type 'member' | 'user'
 * @property int $author_id
 * @property string $author_name
 * @property string|null $author_avatar
 * @property string $type 'image' | 'video'
 * @property string $file_path
 * @property string|null $download_url
 * @property string $disk
 * @property int|null $duration_ms
 * @property string|null $caption
 * @property int|null $size_bytes
 * @property \Carbon\Carbon $expires_at
 */
class Story extends Model
{
    use HasFactory;

    protected $fillable = [
        'author_type', 'author_id', 'author_name', 'author_avatar',
        'type', 'file_path', 'download_url', 'disk', 'duration_ms', 'caption',
        'size_bytes', 'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'duration_ms' => 'integer',
        'size_bytes' => 'integer',
    ];

    /** Vistas registradas para este story. */
    public function views(): HasMany
    {
        return $this->hasMany(StoryView::class);
    }

    /** ¿El media vive en Firebase Storage (subido por la app) y no en disco Laravel? */
    public function isFirebaseStored(): bool
    {
        return $this->disk === 'firebase';
    }

    /**
     * URL pública del media.
     *
     * - Stories de Firebase: la app ya subió el archivo y nos mandó la
     *   `download_url` tokenizada → la devolvemos tal cual. NUNCA tocamos
     *   `Storage::disk('firebase')` (no existe tal driver en Laravel; el
     *   binario nunca pasó por el backend).
     * - Stories legacy en disco Laravel ('public'/'local'): se resuelve con el
     *   Storage facade como siempre.
     */
    public function getFileUrlAttribute(): string
    {
        if ($this->isFirebaseStored()) {
            return (string) ($this->download_url ?? '');
        }

        return Storage::disk($this->disk)->url($this->file_path);
    }

    /** Scope: solo stories no expiradas. Filtro principal del feed. */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('expires_at', '>', now());
    }

    /** Scope: orden cronológico estable para el carousel. */
    public function scopeForFeed(Builder $q): Builder
    {
        return $q->orderBy('created_at', 'desc');
    }

    /**
     * ¿Vio este story el viewer especificado?
     * Útil para marcar el anillo como "visto" en el carousel.
     */
    public function isViewedBy(string $viewerType, int $viewerId): bool
    {
        return $this->views()
            ->where('viewer_type', $viewerType)
            ->where('viewer_id', $viewerId)
            ->exists();
    }
}
