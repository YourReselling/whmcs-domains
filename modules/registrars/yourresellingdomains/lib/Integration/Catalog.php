<?php

declare(strict_types=1);

namespace YourReselling\Whmcs\Domains\Integration;

/** Verified product capabilities shared by billing-panel integrations. */
final class Catalog
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        static $profiles;

        return $profiles ??= json_decode(file_get_contents(__DIR__.'/../profiles.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    public static function product(string $slug): array
    {
        return self::all()[$slug] ?? throw new \InvalidArgumentException('Unbekanntes Produkt.');
    }

    /** @param array<string,mixed> $value */
    public static function value(array $value, string $path): mixed
    {
        foreach (explode('.', $path) as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public static function redact(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (preg_match('/password|secret|token|private.?key|authorization|auth.?code|api.?key|credential|login.?url|config|connection.?string|dsn|environment|variables/i', (string) $key)) {
                $result[$key] = '[geschützt]';
            } else {
                $result[$key] = is_array($value) ? self::redact($value) : $value;
            }
        }

        return $result;
    }
}
