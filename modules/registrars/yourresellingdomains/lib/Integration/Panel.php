<?php

declare(strict_types=1);

namespace YourReselling\Whmcs\Domains\Integration;

use YourReselling\Whmcs\Domains\ApiException;

/** Shared presentation and error boundaries; raw provider payloads never enter logs. */
final class Panel
{
    public static function error(\Throwable $error, bool $admin = false): string
    {
        if ($error instanceof ApiException) {
            return 'Anbieter-Anfrage fehlgeschlagen (HTTP '.$error->status.').'.($error->requestId ? ' Referenz: '.preg_replace('/[^a-zA-Z0-9_.:-]/', '', $error->requestId) : '').($error->status === 429 ? ' Bitte später erneut versuchen.' : '');
        }
        if ($admin && ($error instanceof PanelException || $error instanceof \InvalidArgumentException) && ! $error instanceof \JsonException) {
            return $error->getMessage();
        }

        return 'Die Aktion konnte nicht abgeschlossen werden. Bitte den Support kontaktieren.';
    }

    /** @param array<string,mixed> $data @return list<array{label:string,value:string}> */
    public static function rows(array $data, string $prefix = ''): array
    {
        $rows = [];
        foreach (Catalog::redact($data) as $key => $value) {
            if (preg_match('/cost|provider|reseller|team_id|internal|wholesale/i', (string) $key)) {
                continue;
            }
            $label = $prefix.(string) $key;
            if (is_array($value)) {
                $rows = array_merge($rows, self::rows($value, $label.' · '));
            } else {
                $rows[] = ['label' => str_replace('_', ' ', $label), 'value' => is_bool($value) ? ($value ? 'Ja' : 'Nein') : (string) ($value ?? '—')];
            }
            if (count($rows) >= 250) {
                break;
            }
        }

        return array_slice($rows, 0, 250);
    }

    /** @param array<string,mixed> $operation @param array<string,mixed> $input @return array<string,mixed> */
    public static function controlInput(array $operation, array $input): array
    {
        $body = [];
        foreach ($operation['body']['properties'] as $key => $schema) {
            if (array_key_exists($key, $input) && $input[$key] !== '') {
                $body[$key] = Input::value($input[$key], $schema);
            }
        }

        return $body;
    }
}
