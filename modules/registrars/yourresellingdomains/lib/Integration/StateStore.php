<?php

declare(strict_types=1);

namespace YourReselling\Whmcs\Domains\Integration;

/** Durable per-service journal. Writes must commit before the external order request. */
interface StateStore
{
    /** @return array<string,mixed> */
    public function read(): array;

    /** @param array<string,mixed> $state */
    public function write(array $state): void;

    /** Serialize concurrent lifecycle calls for this service. */
    public function locked(\Closure $operation): mixed;
}
