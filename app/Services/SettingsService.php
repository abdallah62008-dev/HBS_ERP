<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Centralised access to the `settings` table.
 *
 * Reads are memoised in the cache for 1 hour. Any write through this
 * service flushes the cache so the next read reflects the new value.
 *
 * Defaults documented in 02_DATABASE_SCHEMA.md are seeded by
 * Database\Seeders\SettingsSeeder. Code that needs a setting should
 * always go through SettingsService::get() so the rest of the app
 * does not have to know whether the value is a string, number,
 * boolean, or JSON.
 */
class SettingsService
{
    private const CACHE_KEY = 'hbs_settings_all';
    private const CACHE_TTL_SECONDS = 3600;

    /**
     * Fetch the typed value for a setting by key, or the supplied default
     * if the key is not present.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();

        if (! array_key_exists($key, $all)) {
            return $default;
        }

        return $all[$key];
    }

    /**
     * Persist (insert or update) a setting and invalidate the cache.
     */
    public static function set(string $key, mixed $value, string $group = 'general', ?string $valueType = null): Setting
    {
        $valueType ??= self::detectValueType($value);

        $setting = Setting::updateOrCreate(
            ['setting_key' => $key],
            [
                'setting_group' => $group,
                'setting_value' => self::encodeValue($value, $valueType),
                'value_type' => $valueType,
            ],
        );

        self::flush();

        return $setting;
    }

    /**
     * Return every setting as a key => typed-value map.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, static function () {
            return Setting::query()
                ->get()
                ->mapWithKeys(static fn (Setting $s) => [
                    $s->setting_key => self::decodeValue($s->setting_value, $s->value_type),
                ])
                ->all();
        });
    }

    /**
     * Forget cached settings; call after any direct DB write.
     */
    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private static function detectValueType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value), is_float($value) => 'number',
            is_array($value), is_object($value) => 'json',
            default => 'string',
        };
    }

    private static function encodeValue(mixed $value, string $valueType): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($valueType) {
            'boolean' => $value ? '1' : '0',
            'number' => (string) $value,
            'json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => (string) $value,
        };
    }

    private static function decodeValue(?string $raw, string $valueType): mixed
    {
        if ($raw === null) {
            return null;
        }

        return match ($valueType) {
            'boolean' => in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true),
            'number' => str_contains($raw, '.') ? (float) $raw : (int) $raw,
            'json' => json_decode($raw, true),
            default => $raw,
        };
    }
}
