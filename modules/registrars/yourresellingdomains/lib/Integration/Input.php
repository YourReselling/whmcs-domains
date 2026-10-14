<?php

declare(strict_types=1);

namespace YourReselling\Whmcs\Domains\Integration;

/** Convert billing panel form values without treating "false" as true or losing zero. */
final class Input
{
    /** @return array<string,mixed> */
    public static function object(string $json): array
    {
        $decoded = json_decode($json === '' ? '{}' : $json, false, 512, JSON_THROW_ON_ERROR);
        if (! $decoded instanceof \stdClass) {
            throw new \InvalidArgumentException('Die Konfiguration muss ein JSON-Objekt sein.');
        }

        return json_decode(json_encode($decoded, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $schema */
    public static function value(mixed $value, array $schema): mixed
    {
        if ($value === null && ! empty($schema['nullable'])) {
            return null;
        }
        $type = $schema['type'] ?? 'string';
        if (in_array($type, ['array', 'object'], true)) {
            if (is_string($value)) {
                $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            }
            if (! is_array($value)) {
                throw new \InvalidArgumentException('Array oder JSON-Objekt erwartet.');
            }
        } elseif ($type === 'boolean') {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($value === null) {
                throw new \InvalidArgumentException('Boolescher Wert erwartet.');
            }
        } elseif ($type === 'integer') {
            $value = filter_var($value, FILTER_VALIDATE_INT);
            if ($value === false) {
                throw new \InvalidArgumentException('Ganzzahl erwartet.');
            }
        } elseif ($type === 'number') {
            if (! is_numeric($value)) {
                throw new \InvalidArgumentException('Zahl erwartet.');
            }
            $value = (float) $value;
        } elseif (! is_scalar($value)) {
            throw new \InvalidArgumentException('Text erwartet.');
        } else {
            $value = (string) $value;
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            throw new \InvalidArgumentException('Wert außerhalb der erlaubten Auswahl.');
        }

        return $value;
    }

    /** Customer values can never override plan IDs, quotas, pricing, or ownership. @param array<string,mixed> $profile @param array<string,mixed> $defaults @param array<string,mixed> $customer @return array{body:array<string,mixed>,path:array<string,mixed>} */
    public static function provision(array $profile, array $defaults, array $customer): array
    {
        $path = [];
        $body = [];
        foreach ($profile['fields'] as $field) {
            $name = $field['name'];
            $value = $defaults[$name] ?? null;
            if (! $field['path'] && in_array($name, $profile['customerFields'], true) && array_key_exists($name, $customer)) {
                $value = $customer[$name];
            }
            if ($value === null || $value === '') {
                continue;
            }
            if ($field['path']) {
                $path[$name] = self::value($value, ['type' => 'string']);
            } else {
                $body[$name] = self::value($value, $field);
            }
        }

        return compact('path', 'body');
    }
}
