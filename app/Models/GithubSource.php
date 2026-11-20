<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A GitHub repository whose releases are imported as update packages. */
class GithubSource extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'include_prereleases' => 'boolean',
            'auto_import' => 'boolean',
            'auto_publish' => 'boolean',
            'last_checked_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function releases(): HasMany
    {
        return $this->hasMany(Release::class);
    }

    /** Accepts "owner/repo" or a github.com URL. */
    public static function cleanRepository(?string $value): ?string
    {
        $value = trim((string) $value);
        $value = preg_replace('~^(https?://)?(www\.)?github\.com/~i', '', $value);
        $value = preg_replace('~(\.git)?/*$~', '', $value);

        return preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $value) ? $value : null;
    }
}
