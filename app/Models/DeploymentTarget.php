<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One installation's part in a deployment. Its status changes only on the installation's own reports. */
class DeploymentTarget extends Model
{
    /** Not handed to the installation yet, so safe to cancel. */
    public const CANCELLABLE = ['waiting_canary', 'queued', 'retry_wait'];

    /** Handed over, result not reported yet. */
    public const IN_FLIGHT = ['delivered', 'running', 'rollback_requested', 'rolling_back'];

    public const FINAL = ['succeeded', 'failed', 'cancelled', 'no_response', 'rolled_back', 'rollback_failed'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_canary' => 'boolean',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
            'last_report_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(Installation::class);
    }
}
