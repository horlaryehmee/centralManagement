<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** One independently hosted website. Talks to the central API with its own credentials. */
class Installation extends Model
{
    public const STATUSES = ['pending' => 'Waiting to connect', 'active' => 'Active', 'suspended' => 'Suspended', 'revoked' => 'Revoked'];

    protected $guarded = ['id', 'uuid', 'secret', 'enrollment_code_hash', 'created_at', 'updated_at'];

    protected $hidden = ['secret', 'enrollment_code_hash'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'server' => 'array',
            'is_test' => 'boolean',
            'enrollment_expires_at' => 'datetime',
            'secret_issued_at' => 'datetime',
            'registered_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (Installation $i) => $i->uuid ??= (string) Str::uuid());
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function modules(): HasMany
    {
        return $this->hasMany(InstallationModule::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(DeploymentTarget::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * New one-time enrollment code (shown once, stored hashed). Existing
     * credentials stop working until the site connects again with the code.
     */
    public function issueEnrollmentCode(): string
    {
        // 25 random characters (~145 bits): a fast SHA-256 lookup hash is safe for codes this strong.
        $code = strtoupper(implode('-', str_split(Str::random(25), 5)));
        $this->forceFill([
            'enrollment_code_hash' => self::hashCode($code),
            'enrollment_expires_at' => now()->addDays((int) config('central.enrollment_days')),
            'secret' => null,
            'secret_issued_at' => null,
            'status' => 'pending',
        ])->save();

        return $code;
    }

    public static function hashCode(string $code): string
    {
        return hash('sha256', strtoupper(trim($code)));
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subMinutes(max((int) config('central.offline_after_minutes'), $this->check_interval * 3)));
    }

    /** May receive updates: installation and its client both active. */
    public function canReceiveUpdates(): bool
    {
        return $this->status === 'active' && (bool) $this->client?->isActive();
    }

    public function scopeOffline(Builder $q): Builder
    {
        return $q->where('status', 'active')->where(fn ($w) => $w->whereNull('last_seen_at')
            ->orWhere('last_seen_at', '<', now()->subMinutes((int) config('central.offline_after_minutes'))));
    }

    /** Reported module/extension versions keyed by slug. */
    public function moduleVersions(): array
    {
        return $this->modules->mapWithKeys(fn ($m) => [$m->slug => $m->version])->all();
    }
}
