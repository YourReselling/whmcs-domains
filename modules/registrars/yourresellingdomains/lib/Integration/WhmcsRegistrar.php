<?php

declare(strict_types=1);

namespace YourReselling\Whmcs\Domains\Integration;

use YourReselling\Whmcs\Domains\Transport;

final class WhmcsRegistrar
{
    /** @param array<string,mixed> $params */
    public static function transport(array $params): Transport
    {
        return new Transport((string) ($params['ApiKey'] ?? ''), (string) (($params['BaseUrl'] ?? '') ?: 'https://resellingprovider.de/api/v1'));
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public static function run(string $action, array $params): array
    {
        try {
            $domain = strtolower((string) ($params['domain_punycode'] ?? $params['domainname'] ?? $params['domain'] ?? (($params['sld'] ?? '').'.'.($params['tld'] ?? ''))));
            $gateway = new DomainGateway(self::transport($params), new WhmcsDomainStore((int) ($params['domainid'] ?? 0)), $domain);
            if ($action === 'RegisterDomain' || $action === 'TransferDomain') {
                $options = ['years' => (int) ($params['regperiod'] ?? 1)];
                if ($action === 'TransferDomain') {
                    $options['auth_code'] = (string) ($params['eppcode'] ?? '');
                    $options['nameserver_mode'] = 'external';
                    $options['dns_import'] = 'none';
                } else {
                    $nameservers = self::nameservers($params);
                    if ($nameservers !== []) {
                        $options['nameservers'] = $nameservers;
                    }
                }
                $gateway->order(self::contact($params), $options, $action === 'TransferDomain');

                return [];
            }
            if ($action === 'GetNameservers') {
                $result = [];
                foreach ($gateway->operation('GET', 'nameservers')['nameservers'] ?? [] as $index => $server) {
                    $result['ns'.($index + 1)] = $server;
                }

                return $result;
            }
            if ($action === 'SaveNameservers') {
                $gateway->operation('POST', 'nameservers/update', ['nameservers' => self::nameservers($params)]);

                return [];
            }
            if ($action === 'GetEPPCode') {
                return ['eppcode' => $gateway->operation('POST', 'auth-code', [])['auth_code']];
            }
            if ($action === 'GetDNS') {
                $records = [];
                foreach ($gateway->operation('GET', 'dns')['records'] ?? [] as $record) {
                    $records[] = ['hostname' => $record['name'], 'type' => $record['type'], 'address' => $record['content'], 'priority' => $record['priority'] ?? 0];
                }

                return $records;
            }
            if ($action === 'SaveDNS') {
                $existing = $gateway->operation('GET', 'dns')['records'] ?? [];
                foreach ($existing as $record) {
                    if (in_array($record['type'], ['SRV', 'CAA', 'TLSA', 'SSHFP', 'NAPTR', 'LOC', 'WR', 'ALIAS'], true)) {
                        throw new PanelException('Diese Zone enthält erweiterte DNS-Einträge. Bitte im Anbieterpanel bearbeiten, damit WHMCS keine nicht darstellbaren Einträge überschreibt.');
                    }
                }
                $records = [];
                foreach ($params['dnsrecords'] ?? [] as $record) {
                    if (! in_array($record['type'] ?? '', ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'PTR'], true)) {
                        throw new \InvalidArgumentException('Dieser DNS-Typ kann hier nicht sicher gespeichert werden.');
                    }
                    $entry = ['name' => (string) $record['hostname'], 'type' => $record['type'], 'content' => (string) $record['address'], 'ttl' => 3600];
                    if ($record['type'] === 'MX') {
                        $entry['priority'] = (int) ($record['priority'] ?? 0);
                    }
                    foreach ($existing as $old) {
                        if ($old['type'] === $entry['type'] && $old['name'] === $entry['name'] && $old['content'] === $entry['content']) {
                            $entry['ttl'] = $old['ttl'];
                        }
                    }
                    $records[] = $entry;
                }
                $gateway->operation('POST', 'dns/update', ['records' => $records]);

                return [];
            }
            $remote = $gateway->details();
            $expiry = ! empty($remote['expires_at']) ? ['expirydate' => (new \DateTimeImmutable($remote['expires_at']))->format('Y-m-d')] : [];
            if ($action === 'TransferSync') {
                return ['completed' => $remote['status'] === 'active', 'failed' => in_array($remote['status'], ['failed', 'cancelled'], true)] + $expiry;
            }
            if ($action === 'Sync') {
                return ['active' => $remote['status'] === 'active', 'cancelled' => in_array($remote['status'], ['cancelled', 'deleted', 'terminated'], true), 'transferredAway' => $remote['status'] === 'transferred_out'] + $expiry;
            }
            throw new \InvalidArgumentException('Registrar-Aktion nicht unterstützt.');
        } catch (\Throwable $error) {
            return ['error' => Panel::error($error, true)];
        }
    }

    /** @param array<string,mixed> $params @return list<string> */
    private static function nameservers(array $params): array
    {
        $result = [];
        for ($index = 1; $index <= 6; $index++) {
            if (! empty($params['ns'.$index])) {
                $result[] = (string) $params['ns'.$index];
            }
        }

        return $result;
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public static function contact(array $params): array
    {
        $contact = ['type' => empty($params['companyname']) ? 'individual' : 'organization', 'first_name' => (string) ($params['firstname'] ?? ''), 'last_name' => (string) ($params['lastname'] ?? ''), 'email' => (string) ($params['email'] ?? ''), 'phone' => preg_replace('/[^+0-9]/', '', (string) ($params['fullphonenumber'] ?? $params['phonenumberformatted'] ?? $params['phonenumber'] ?? '')), 'street' => trim((string) ($params['address1'] ?? '').' '.(string) ($params['address2'] ?? '')), 'postal_code' => (string) ($params['postcode'] ?? ''), 'city' => (string) ($params['city'] ?? ''), 'state' => (string) ($params['state'] ?? ''), 'country_code' => strtoupper((string) ($params['country'] ?? ''))];
        if (! empty($params['companyname'])) {
            $contact['organization'] = (string) $params['companyname'];
        }

        return $contact;
    }
}
