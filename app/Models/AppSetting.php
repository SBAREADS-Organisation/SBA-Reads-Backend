<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AppSetting extends Model
{
    protected $primaryKey = 'key';
    protected $keyType    = 'string';
    public    $incrementing = false;

    protected $fillable = ['key', 'value'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::find($key);
        return $setting ? $setting->value : $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $val = static::get($key);
        if ($val === null) return $default;
        return in_array(strtolower((string) $val), ['true', '1', 'yes']);
    }

    public static function float(string $key, float $default = 0.0): float
    {
        $val = static::get($key);
        return $val !== null ? (float) $val : $default;
    }
}
