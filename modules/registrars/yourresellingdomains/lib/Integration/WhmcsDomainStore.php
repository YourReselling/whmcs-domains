<?php

declare(strict_types=1);

namespace YourReselling\Whmcs\Domains\Integration;

use WHMCS\Database\Capsule;

/** Domain-to-provider mapping and resumable order journal. Contains no credentials or contact data. */
final class WhmcsDomainStore implements StateStore
{
    public function __construct(private readonly int $domainId)
    {
        if ($domainId < 1) {
            throw new \InvalidArgumentException('WHMCS-Domain-ID fehlt.');
        }
        Capsule::connection()->statement('CREATE TABLE IF NOT EXISTS mod_yourreselling_domains (domain_id INT UNSIGNED NOT NULL PRIMARY KEY, state LONGTEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    public function read(): array
    {
        $raw = Capsule::table('mod_yourreselling_domains')->where('domain_id', $this->domainId)->value('state');

        return $raw ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : [];
    }

    public function write(array $state): void
    {
        Capsule::table('mod_yourreselling_domains')->updateOrInsert(['domain_id' => $this->domainId], ['state' => json_encode($state, JSON_THROW_ON_ERROR)]);
    }

    public function locked(\Closure $operation): mixed
    {
        $key = 'yourreselling:domain:'.$this->domainId;
        $result = Capsule::connection()->select('SELECT GET_LOCK(?, 0) AS acquired', [$key]);
        if ((int) ($result[0]->acquired ?? 0) !== 1) {
            throw new PanelException('Für diese Domain läuft bereits eine Aktion.');
        }
        try {
            return $operation();
        } finally {
            Capsule::connection()->select('SELECT RELEASE_LOCK(?)', [$key]);
        }
    }
}
