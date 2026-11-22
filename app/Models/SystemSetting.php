<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The central app's own settings (a single row). */
class SystemSetting extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $hidden = ['update_token'];

    protected function casts(): array
    {
        return ['update_token' => 'encrypted'];
    }

    public static function current(): self
    {
        return static::query()->first() ?? static::query()->create([]);
    }
}
