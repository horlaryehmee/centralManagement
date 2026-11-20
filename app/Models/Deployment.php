<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Sending one release to a set of installations. */
class Deployment extends Model
{
    public const FINAL = ['completed', 'completed_with_failures', 'failed', 'cancelled'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'selection' => 'array',
            'allow_modified_files' => 'boolean',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canary(): BelongsTo
    {
        return $this->belongsTo(Installation::class, 'canary_installation_id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(DeploymentTarget::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function isDue(): bool
    {
        return ! $this->scheduled_at || $this->scheduled_at->lte(now());
    }
}
