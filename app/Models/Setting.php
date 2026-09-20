<?php

namespace App\Models;

use App\Models\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'key',
        'value',
        'group',
        'type', // text, textarea, boolean, image, number
        'label',
        'is_public', // exposed to frontend
    ];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever("setting.{$key}", function () use ($key) {
            $setting = self::where('key', $key)->first();

            if (! $setting) {
                return null;
            }

            return match ($setting->type) {
                'boolean' => (bool) $setting->value,
                'number' => (float) $setting->value,
                default => $setting->value,
            };
        }) ?? $default;
    }

    public static function set(string $key, mixed $value, string $type = 'text'): self
    {
        Cache::forget("setting.{$key}");

        return self::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'type' => $type]
        );
    }
}
