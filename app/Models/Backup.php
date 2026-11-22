<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A backup of one website, requested here, built and uploaded by the website's agent. */
class Backup extends Model
{
    public const STATUSES = [
        'requested' => 'Waiting for the website',
        'delivered' => 'Being created',
        'uploading' => 'Uploading',
        'completed' => 'Ready',
        'failed' => 'Failed',
        'expired' => 'No response',
    ];

    /** Statuses of a backup that is still on its way. */
    public const ACTIVE = ['requested', 'delivered', 'uploading'];

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $attributes = ['status' => 'requested', 'received_bytes' => 0];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return [
            'include_files' => 'boolean',
            'encrypted' => 'boolean',
            'size' => 'integer',
            'received_bytes' => 'integer',
            'contents' => 'array',
            'delivered_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(Installation::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereIn('status', self::ACTIVE);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    /** Where the upload is assembled before it is checked and encrypted. */
    public function partialPath(): string
    {
        return 'backups/incoming/'.$this->id.'.part';
    }
}
