<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * A setting of the app itself, for everyone, set by its admins: kept by its
 * key, read through the cache.
 *
 * @property string $key
 * @property mixed $value
 */
class AppSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    /** What `$key` is set to, or `$default` while it is not. */
    public static function read(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever("app-setting:{$key}", fn () => self::find($key)?->value) ?? $default;
    }

    public static function write(string $key, mixed $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("app-setting:{$key}");
    }
}
