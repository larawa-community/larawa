<?php

namespace App\Support;

class IpAllowList
{
    /**
     * @param  list<string>|string|null  $value
     * @return list<string>
     */
    public static function parse(array|string|null $value): array
    {
        $entries = is_array($value) ? $value : explode(',', $value ?? '');

        return collect($entries)
            ->map(fn (string $entry) => trim($entry))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $entries
     * @return list<string>
     */
    public static function invalidEntries(array $entries): array
    {
        return collect($entries)
            ->reject(fn (string $entry) => self::isValidEntry($entry))
            ->values()
            ->all();
    }

    public static function isValidEntry(string $entry): bool
    {
        if (! str_contains($entry, '/')) {
            return filter_var($entry, FILTER_VALIDATE_IP) !== false;
        }

        if (substr_count($entry, '/') !== 1) {
            return false;
        }

        [$ip, $prefix] = explode('/', $entry, 2);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false || ! preg_match('/^\d+$/', $prefix)) {
            return false;
        }

        $maxPrefix = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 128 : 32;

        return (int) $prefix >= 0 && (int) $prefix <= $maxPrefix;
    }
}
