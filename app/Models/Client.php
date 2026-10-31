<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Matizze client. It owns the conversations through which WARM talks to it
 * (its own group) and the internal conversations where the team registers
 * progress about it.
 *
 * @mixin IdeHelperClient
 */
class Client extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'slug',
        'name',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function channels(): HasMany
    {
        return $this->hasMany(ClientChannel::class);
    }

    public function progressEntries(): HasMany
    {
        return $this->hasMany(ProgressEntry::class);
    }
}
