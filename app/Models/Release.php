<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A signed, versioned update package: the core app, a module, or one client's extension. */
class Release extends Model
{
    public const TYPES = ['core' => 'Core application', 'module' => 'Module', 'extension' => 'Client extension'];

    protected $guarded = ['id', 'uuid', 'created_at', 'updated_at'];

    // The manifest lists every file's checksum (megabytes for a core release): only the API sends it.
    protected $hidden = ['package_path', 'manifest'];

    protected function casts(): array
    {
        return [
            'requires' => 'array',
            'has_migrations' => 'boolean',
            'database_rollback' => 'boolean',
            'published_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (Release $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function label(): string
    {
        return ($this->type === 'core' ? 'Core' : $this->slug).' '.$this->version;
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function manifestData(): array
    {
        return json_decode((string) $this->manifest, true) ?: [];
    }
}
