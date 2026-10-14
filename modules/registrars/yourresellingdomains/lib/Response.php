<?php

declare(strict_types=1);

namespace YourReselling\Whmcs\Domains;

/** JSON API response; deliberately independent of PSR/Guzzle and the other product modules. */
final class Response
{
    /** @param array<string,string> $headers Lowercase header names. */
    public function __construct(private readonly int $status, private readonly array $headers, private readonly string $body) {}

    public function status(): int
    {
        return $this->status;
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    public function json(): mixed
    {
        if ($this->body === '') {
            return null;
        }
        try {
            return json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \UnexpectedValueException('The API returned invalid JSON.');
        }
    }
}
