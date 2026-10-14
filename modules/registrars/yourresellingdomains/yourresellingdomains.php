<?php

declare(strict_types=1);

if (! defined('WHMCS')) {
    exit('Direct access is not permitted.');
}

require_once __DIR__.'/bootstrap.php';

use WHMCS\Domains\DomainLookup\ResultsList;
use WHMCS\Domains\DomainLookup\SearchResult;
use YourReselling\Whmcs\Domains\Integration\Panel;
use YourReselling\Whmcs\Domains\Integration\WhmcsRegistrar;

/** Bulk availability; premium domains require separate pricing support and remain unavailable here. */
function yourresellingdomains_CheckAvailability(array $params): ResultsList|array
{
    try {
        $sld = (string) (($params['punyCodeSearchTerm'] ?? '') ?: ($params['searchTerm'] ?? ''));
        $domains = [];
        foreach ($params['tldsToInclude'] ?? [] as $tld) {
            $domains[] = $sld.'.'.ltrim((string) $tld, '.');
        }
        $results = new ResultsList;
        foreach (array_chunk($domains, 50) as $chunk) {
            $response = WhmcsRegistrar::transport($params)->send('POST', '/products/domains/check-bulk', [], [], ['domains' => $chunk])->json();
            $entries = $response['data']['results'] ?? null;
            if (! is_array($entries) || count($entries) !== count($chunk)) {
                throw new RuntimeException('Unvollständige Antwort auf die Verfügbarkeitsprüfung.');
            }
            foreach ($entries as $entry) {
                $result = new SearchResult($entry['name'], $entry['tld']);
                $result->setStatus(! empty($entry['premium']) ? SearchResult::STATUS_RESERVED : (($entry['available'] ?? false) === true ? SearchResult::STATUS_NOT_REGISTERED : SearchResult::STATUS_REGISTERED));
                $results->append($result);
            }
        }

        return $results;
    } catch (Throwable $error) {
        return ['error' => Panel::error($error)];
    }
}

function yourresellingdomains_MetaData(): array
{
    return ['DisplayName' => 'Your-Reselling Domains', 'APIVersion' => '1.1'];
}

function yourresellingdomains_getConfigArray(): array
{
    return [
        'FriendlyName' => ['Type' => 'System', 'Value' => 'Your-Reselling Domains'],
        'Description' => ['Type' => 'System', 'Value' => 'Registrierung, Transfer und Verlängerung über Kündigung zum Laufzeitende. WHMCS-Cron und Domain-Sync müssen aktiv sein.'],
        'BaseUrl' => ['FriendlyName' => 'API-Basis-URL', 'Type' => 'text', 'Size' => '60', 'Default' => 'https://resellingprovider.de/api/v1'],
        'ApiKey' => ['FriendlyName' => 'API-Key', 'Type' => 'password', 'Size' => '60'],
        'CancellationNoticeDays' => ['FriendlyName' => 'Kündigungsvorlauf (Tage)', 'Type' => 'text', 'Size' => '4', 'Default' => '14', 'Description' => '7–90 Tage. Unbezahlte Folgeperioden werden vor Ablauf gekündigt. Rechnungen entsprechend früher fällig stellen.'],
    ];
}

/** RegisterDomain using the registered reseller API. */
function yourresellingdomains_RegisterDomain(array $params): array
{
    return WhmcsRegistrar::run('RegisterDomain', $params);
}

/** TransferDomain using the registered reseller API. */
function yourresellingdomains_TransferDomain(array $params): array
{
    return WhmcsRegistrar::run('TransferDomain', $params);
}

/** Approve the purchased term by removing cancellation; the API extends the term later. */
function yourresellingdomains_RenewDomain(array $params): array
{
    return WhmcsRegistrar::run('RenewDomain', $params);
}

/** GetNameservers using the registered reseller API. */
function yourresellingdomains_GetNameservers(array $params): array
{
    return WhmcsRegistrar::run('GetNameservers', $params);
}

/** SaveNameservers using the registered reseller API. */
function yourresellingdomains_SaveNameservers(array $params): array
{
    return WhmcsRegistrar::run('SaveNameservers', $params);
}

/** GetDNS using the registered reseller API. */
function yourresellingdomains_GetDNS(array $params): array
{
    return WhmcsRegistrar::run('GetDNS', $params);
}

/** SaveDNS using the registered reseller API. */
function yourresellingdomains_SaveDNS(array $params): array
{
    return WhmcsRegistrar::run('SaveDNS', $params);
}

/** GetEPPCode using the registered reseller API. */
function yourresellingdomains_GetEPPCode(array $params): array
{
    return WhmcsRegistrar::run('GetEPPCode', $params);
}

/** Sync using the registered reseller API. */
function yourresellingdomains_Sync(array $params): array
{
    return WhmcsRegistrar::run('Sync', $params);
}

/** TransferSync using the registered reseller API. */
function yourresellingdomains_TransferSync(array $params): array
{
    return WhmcsRegistrar::run('TransferSync', $params);
}
