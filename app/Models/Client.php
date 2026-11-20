<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A business using the ecommerce software. May own several installations. */
class Client extends Model
{
    protected $guarded = ['id', 'key', 'created_at', 'updated_at'];

    protected static function booted(): void
    {
        static::creating(fn (Client $c) => $c->key ??= (string) Str::uuid());
    }

    public function installations(): HasMany
    {
        return $this->hasMany(Installation::class);
    }

    public function releases(): HasMany
    {
        return $this->hasMany(Release::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
