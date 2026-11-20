<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Audit and deployment log entry. */
class Event extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['context' => 'array', 'created_at' => 'datetime'];
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(Installation::class);
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Record an event. $refs keys: installation, client, deployment, target, user, level, context. */
    public static function log(string $type, string $message, array $refs = []): self
    {
        $target = $refs['target'] ?? null;
        $installation = $refs['installation'] ?? $target?->installation;
        $deployment = $refs['deployment'] ?? $target?->deployment;

        return static::create([
            'type' => $type,
            'message' => mb_substr($message, 0, 1000),
            'level' => $refs['level'] ?? 'info',
            'installation_id' => $installation?->id,
            'client_id' => ($refs['client'] ?? $installation?->client)?->id,
            'deployment_id' => $deployment?->id,
            'deployment_target_id' => $target?->id,
            'user_id' => array_key_exists('user', $refs) ? $refs['user']?->id : auth()->id(),
            'context' => $refs['context'] ?? null,
        ]);
    }
}
