<?php

declare(strict_types=1);

namespace YourReselling\Whmcs\Domains\Integration;

use YourReselling\Whmcs\Domains\ApiException;
use YourReselling\Whmcs\Domains\Transport;

/** Registrar operations use provider UUIDs; domain names are used only for exact-match recovery. */
final class DomainGateway
{
    public function __construct(private readonly Transport $transport, private readonly StateStore $store, private readonly string $domain) {}

    /** @param array<string,mixed> $contact @param array<string,mixed> $options */
    public function order(array $contact, array $options, bool $transfer = false): void
    {
        $this->store->locked(function () use ($contact, $options, $transfer): void {
            $state = $this->store->read();
            if (isset($state['domain']) && $state['domain'] !== $this->domain) {
                throw new PanelException('Domainname stimmt nicht mit der gespeicherten Bestellung überein.');
            }
            $state['domain'] = $this->domain;
            if (empty($state['id'])) {
                if (! empty($state['creating'])) {
                    throw new PanelException('Bestellstatus unklar. Im Anbieterpanel abgleichen; die Domain-Synchronisierung kann eine bereits bestellte Domain zuordnen.');
                }
                if (empty($state['handle_id'])) {
                    $result = $this->request('POST', '/products/domains/handles/create', [], $contact);
                    $state['handle_id'] = $result['id'] ?? throw new PanelException('Kontaktantwort ohne Handle-ID.');
                    $this->store->write($state);
                }
                $state['creating'] = true;
                $this->store->write($state);
                try {
                    $result = $this->request('POST', '/products/domains/'.($transfer ? 'transfer' : 'create'), [], ['domain' => $this->domain, 'handle_id' => $state['handle_id']] + $options);
                } catch (ApiException $error) {
                    if (in_array($error->status, [400, 401, 403, 404, 422, 429], true)) {
                        $state['creating'] = false;
                        $this->store->write($state);
                    }
                    throw $error;
                }
                $state['id'] = $result['id'] ?? throw new PanelException('Domainantwort ohne Anbieter-ID; vor Wiederholung abgleichen.');
                $state['creating'] = false;
                $this->store->write($state);
            }
            $remote = $this->details();
            if (! $transfer && ($remote['status'] ?? '') !== 'active') {
                throw new PanelException('Registrierung beim Anbieter läuft. Produkt-ID gespeichert; erneutes Ausführen prüft diese Domain.');
            }
            if ($transfer && in_array($remote['status'] ?? '', ['failed', 'cancelled', 'expired'], true)) {
                throw new PanelException('Der Domaintransfer wurde beim Anbieter nicht erfolgreich abgeschlossen.');
            }
        });
    }

    /** @return array<string,mixed> */
    public function details(): array
    {
        return $this->request('GET', '/products/domains/{domain}', ['domain' => $this->id()]);
    }

    /** @param array<string,mixed>|null $body @return array<string,mixed> */
    public function operation(string $method, string $suffix, ?array $body = null): array
    {
        return $this->request($method, '/products/domains/{domain}/'.$suffix, ['domain' => $this->id()], $body);
    }

    private function id(): string
    {
        $state = $this->store->read();
        if (isset($state['domain']) && $state['domain'] !== $this->domain) {
            throw new PanelException('Domainzuordnung stimmt nicht überein.');
        }
        if (! empty($state['id'])) {
            return (string) $state['id'];
        }
        for ($page = 1; $page <= 1000; $page++) {
            $response = $this->transport->send('GET', '/products/domains', [], ['search' => $this->domain, 'page' => $page, 'per_page' => 100])->json();
            $data = $response['data'] ?? [];
            foreach ($data['domains'] ?? [] as $entry) {
                if (strtolower((string) ($entry['domain'] ?? '')) === $this->domain) {
                    $state['id'] = $entry['id'];
                    $state['domain'] = $this->domain;
                    $state['creating'] = false;
                    $this->store->write($state);

                    return (string) $state['id'];
                }
            }
            if ($page >= (int) ($data['meta']['last_page'] ?? 1)) {
                break;
            }
        }
        throw new PanelException('Domain beim Anbieter nicht gefunden.');
    }

    /** @param array<string,string> $path @param array<string,mixed>|null $body @return array<string,mixed> */
    private function request(string $method, string $route, array $path, ?array $body = null): array
    {
        $response = $this->transport->send($method, $route, $path, [], $body);
        $json = $response->json();
        if (! is_array($json) || ($json['success'] ?? false) !== true || ! is_array($json['data'] ?? null)) {
            throw new ApiException('Domainaktion wurde nicht bestätigt.', $response->status());
        }

        return $json['data'];
    }
}
