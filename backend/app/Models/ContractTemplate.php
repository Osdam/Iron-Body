<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractTemplate extends Model
{
    protected $fillable = [
        'template_key',
        'name',
        'version',
        'applies_to',
        'source_file_path',
        'source_checksum',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(MemberContract::class);
    }
}
