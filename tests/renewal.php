<?php

declare(strict_types=1);

use WHMCS\Database\Capsule;
use WHMCS\Domain\Domain;
use YourReselling\Whmcs\Domains\FakeCurl;
use YourReselling\Whmcs\Domains\Integration\DomainGateway;
use YourReselling\Whmcs\Domains\Integration\DomainRenewal;

$store = new MemoryStore;
$store->state = ['id' => 'domain-one', 'domain' => 'example.de'];
$policy = new DomainRenewal(new DomainGateway($api, $store, 'example.de'), $store);
$remote = ['id' => 'domain-one', 'status' => 'active', 'expires_at' => '2027-06-20', 'auto_renew' => true];
$writes = [];
$failToggle = false;
$wrongConfirmation = false;
FakeCurl::$handler = static function (array $request) use (&$remote, &$writes, &$failToggle, &$wrongConfirmation): array|bool {
    $path = parse_url($request['url'], PHP_URL_PATH);
    if ($request['method'] === 'GET' && str_ends_with($path, '/domain-one')) {
        return responseAt('data', $remote);
    }
    expect($request['method'] === 'POST' && in_array($path, ['/api/v1/products/domains/domain-one/enableAutoRenew', '/api/v1/products/domains/domain-one/disableAutoRenew'], true), 'Renewal must only use documented cancellation toggles');
    expect($request['body'] === '{}', 'Toggle body must be an empty object');
    $writes[] = $path;
    $remote['auto_renew'] = str_ends_with($path, '/enableAutoRenew');
    if ($failToggle) {
        return false;
    }

    return responseAt('data', ['auto_renew' => $wrongConfirmation ? ! $remote['auto_renew'] : $remote['auto_renew']]);
};
$day = static fn (string $date): DateTimeImmutable => new DateTimeImmutable($date);
$policy->reconcile(null, 14, $day('2026-06-20'), true);
expect($store->state['renewal']['paid_until'] === '2027-06-20' && $writes === [], 'Initial paid term must remain active');
$policy->reconcile('2027-06-20', 14, $day('2027-06-05'));
expect($writes === [], 'Must not cancel before the configured payment cutoff');
$policy->reconcile('2027-06-20', 14, $day('2027-06-06'));
expect(! $remote['auto_renew'] && count($writes) === 1, 'Unpaid renewal must schedule cancellation before backend auto-renew');
$policy->reconcile('2027-06-20', 14, $day('2027-06-07'));
expect(count($writes) === 1, 'Repeated sync must not queue duplicate provider changes');
$policy->renew(1, '2027-06-20', 'invoice:41', 14, $day('2027-06-08'));
expect($remote['auto_renew'] && $store->state['renewal']['paid_until'] === '2028-06-20', 'Paid renewal must remove cancellation and save coverage');
$policy->renew(1, '2028-06-20', 'invoice:41', 14, $day('2027-06-08'));
expect($store->state['renewal']['paid_until'] === '2028-06-20', 'Duplicate payment/callback must not add a year');
rejects(fn () => $policy->renew(2, '2027-06-20', 'invoice:41', 14, $day('2027-06-08')), 'Verlängerungszeitraum');
$result = $policy->reconcile('2028-06-20', 14, $day('2027-06-09'), false, true);
expect($remote['auto_renew'] && $result['expires_at'] === '2028-06-20', 'Disabling future invoices must not remove an already paid term');
$remote['expires_at'] = '2028-06-20';
$policy->reconcile('2028-06-20', 14, $day('2028-06-06'));
expect(! $remote['auto_renew'], 'A paid renewal must not enable indefinite renewals');

$failToggle = true;
rejects(fn () => $policy->renew(2, '2028-06-20', 'invoice:42', 14, $day('2028-06-07')), 'API transport failed');
expect($store->state['renewal']['paid_until'] === '2030-06-20' && $store->state['renewal']['pending'] === 'invoice:42', 'Ambiguous response must preserve the intended purchased term');
rejects(fn () => $policy->renew(1, '2030-06-20', 'invoice:43', 14, $day('2028-06-07')), 'andere Verlängerung');
$failToggle = false;
$policy->reconcile('2028-06-20', 14, $day('2028-06-08'));
$policy->renew(2, '2030-06-20', 'manual:2030-06-20:2', 14, $day('2028-06-08'));
expect($store->state['renewal']['paid_until'] === '2030-06-20' && $store->state['renewal']['pending'] === null, 'Retry after sync must finish the pending intent without adding years');
$remote['expires_at'] = '2029-06-20';
$policy->reconcile('2030-06-20', 14, $day('2029-06-06'));
expect($remote['auto_renew'], 'Multi-year payment must cover intermediate yearly rollovers');
$remote['expires_at'] = '2030-06-20';
$policy->reconcile('2030-06-20', 14, $day('2029-06-21'), false, true);
expect(! $remote['auto_renew'], 'Explicit nonrenewal must cancel at the paid term end');
$remote['status'] = 'expired';
$before = count($writes);
rejects(fn () => $policy->renew(1, '2030-06-20', 'invoice:44', 14, $day('2030-06-21')), 'keine Wiederherstellung');
$policy->reconcile('2030-06-20', 14, $day('2030-06-21'));
expect(count($writes) === $before, 'Inactive domains must never be renewed or cancelled by a blind toggle');
$remote['status'] = 'active';
rejects(fn () => $policy->renew(1, '2030-06-20', 'invoice:44', 14, $day('2030-06-20')), 'keine Wiederherstellung');
$remote['expires_at'] = '2031-06-20';
$remote['auto_renew'] = true;
$policy->reconcile('2030-06-20', 14, $day('2030-06-21'));
expect(! $remote['auto_renew'] && $store->state['renewal']['paid_until'] === '2030-06-20', 'An unpaid remote rollover must not create an entitlement');

$store = new MemoryStore;
$store->state = ['id' => 'domain-one', 'domain' => 'example.de'];
$policy = new DomainRenewal(new DomainGateway($api, $store, 'example.de'), $store);
$remote = ['id' => 'domain-one', 'status' => 'active', 'expires_at' => '2028-02-29', 'auto_renew' => false];
rejects(fn () => $policy->reconcile(null, 14, $day('2028-02-01')), 'WHMCS-Ablaufdatum fehlt');
expect(! isset($store->state['renewal']), 'Migration must not guess a purchased term');
rejects(fn () => $policy->reconcile('2028-02-30', 14, $day('2028-02-01')), 'Ungültiges Ablaufdatum');
$policy->renew(1, '2028-02-29', 'manual:2028-02-29:1', 14, $day('2028-02-01'));
expect($store->state['renewal']['paid_until'] === '2029-02-28', 'Leap-day renewal must clamp to February, not March');
rejects(fn () => $policy->renew(0, '2029-02-28', 'invoice:50', 14, $day('2028-02-01')), 'Ungültiger Verlängerungszeitraum');
rejects(fn () => $policy->reconcile('2029-02-28', 5, $day('2028-02-01')), 'Kündigungsvorlauf');
$remote['auto_renew'] = false;
$wrongConfirmation = true;
rejects(fn () => $policy->reconcile('2029-02-28', 14, $day('2028-02-01')), 'nicht bestätigt');
$wrongConfirmation = false;
unset($remote['auto_renew']);
rejects(fn () => $policy->reconcile('2029-02-28', 14, $day('2028-02-01')), 'Auto-Renew-Status');

/** Verify that the standalone archive actually installs the scheduled/payment hooks. */
$hooks = [];
function add_hook(string $name, int $priority, Closure $callback): void
{
    $GLOBALS['hooks'][$name] = $callback;
}
require $root.'/'.$manifest['modulePath'].'/hooks.php';
expect(array_keys($hooks) === ['InvoicePaidPreEmail', 'InvoicePaid', 'DailyCronJob', 'DomainEdit'], 'Missing renewal lifecycle hooks');

require __DIR__.'/FakeWhmcs.php';
function logActivity(string $message): void
{
    $GLOBALS['activity'][] = $message;
}
$expiry = (new DateTimeImmutable('today'))->modify('+10 days')->format('Y-m-d');
$remote = ['id' => 'domain-one', 'status' => 'active', 'expires_at' => $expiry, 'auto_renew' => true];
$provider = &$remote;
$writes = [];
FakeCurl::$handler = static function (array $request) use (&$provider, &$writes): array {
    $path = parse_url($request['url'], PHP_URL_PATH);
    if ($request['method'] === 'GET') {
        if (str_ends_with($path, '/products/domains')) {
            return responseAt('data', ['domains' => [['id' => 'domain-one', 'domain' => 'example.de']], 'meta' => ['last_page' => 1]]);
        }
        expect(str_ends_with($path, '/domain-one'), 'Callback must use the provider UUID');

        return responseAt('data', $provider);
    }
    expect(str_ends_with($path, '/enableAutoRenew') || str_ends_with($path, '/disableAutoRenew'), 'Unexpected callback write');
    $writes[] = $path;
    $provider['auto_renew'] = str_ends_with($path, '/enableAutoRenew');

    return responseAt('data', ['auto_renew' => $provider['auto_renew']]);
};
Capsule::$rows = [
    'tbldomains' => [
        ['id' => 1, 'domain' => 'example.de', 'registrar' => 'yourresellingdomains', 'expirydate' => $expiry, 'donotrenew' => 0, 'status' => 'Active'],
        ['id' => 2, 'domain' => 'foreign.de', 'registrar' => 'other', 'expirydate' => $expiry, 'donotrenew' => 1, 'status' => 'Active'],
    ],
    'tblinvoices' => [91 => ['id' => 91, 'status' => 'Paid'], 92 => ['id' => 92, 'status' => 'Unpaid']],
    'tblinvoiceitems' => [['invoiceid' => 91, 'relid' => 1, 'type' => 'Domain'], ['invoiceid' => 92, 'relid' => 1, 'type' => 'Domain']],
];
$params = ['domainid' => 1, 'sld' => 'example', 'tld' => '.de', 'regperiod' => 1, 'ApiKey' => 'secret-test-token', 'BaseUrl' => 'https://api.example.test/api/v1'];
Domain::$params = $params;
$hooks['DailyCronJob']([]);
expect(! $provider['auto_renew'] && count($writes) === 1 && empty($GLOBALS['activity']), 'Daily cron must cancel unpaid domains and ignore other registrars');
$hooks['InvoicePaidPreEmail'](['invoiceid' => 92]);
$result = yourresellingdomains_RenewDomain($params);
expect(isset($result['error']) && ! $provider['auto_renew'], 'Unpaid invoice must not authorize renewal');
$hooks['InvoicePaidPreEmail'](['invoiceid' => 91]);
expect(yourresellingdomains_RenewDomain($params) === [] && $provider['auto_renew'], 'Paid invoice must reach the actual RenewDomain callback');
$expectedExpiry = (new DateTimeImmutable($expiry))->modify('+1 year')->format('Y-m-d');
$result = yourresellingdomains_Sync($params);
expect($result['expirydate'] === $expectedExpiry && $result['active'], 'Sync must preserve prepaid coverage while backend rollover is pending');
expect(yourresellingdomains_RenewDomain($params) === [], 'Repeated paid callback must succeed idempotently');
expect(yourresellingdomains_Sync($params)['expirydate'] === $expectedExpiry, 'Repeated paid callback must not extend twice');
$hooks['InvoicePaid'](['invoiceid' => 91]);
expect(yourresellingdomains_RenewDomain($params) === [] && yourresellingdomains_Sync($params)['expirydate'] === $expectedExpiry, 'Administrative retry at unchanged WHMCS expiry must not duplicate a completed invoice callback');
$provider['expires_at'] = $expectedExpiry;
Capsule::$rows['tbldomains'][0]['donotrenew'] = 1;
$hooks['DomainEdit'](['domainid' => 1]);
expect(! $provider['auto_renew'], 'Admin nonrenewal flag must reach cancellation automation');
Capsule::$locked = true;
$result = yourresellingdomains_RenewDomain($params);
expect(isset($result['error']) && str_contains($result['error'], 'bereits eine Aktion'), 'Concurrent callback must fail without racing the renewal journal');
Capsule::$locked = false;
$provider['expires_at'] = 'invalid';
$hooks['DailyCronJob']([]);
expect(count($GLOBALS['activity']) === 1 && ! str_contains($GLOBALS['activity'][0], 'secret-test-token'), 'Cron errors must be visible without exposing credentials');

Capsule::$rows['mod_yourreselling_domains'] = [];
Capsule::$rows['tbldomains'][0]['expirydate'] = '0000-00-00';
Capsule::$rows['tbldomains'][0]['donotrenew'] = 0;
Capsule::$rows['tbldomains'][0]['status'] = 'Pending Transfer';
$provider = ['id' => 'domain-one', 'status' => 'pending', 'expires_at' => null, 'auto_renew' => true];
$result = yourresellingdomains_TransferSync($params);
expect(! $result['completed'] && ! $result['failed'] && ! isset($result['expirydate']), 'Pending transfer must not invent a paid term');
$provider['status'] = 'active';
$provider['expires_at'] = (new DateTimeImmutable('today'))->modify('+1 year')->format('Y-m-d');
$result = yourresellingdomains_TransferSync($params);
expect($result['completed'] && $result['expirydate'] === $provider['expires_at'], 'Completed transfer must adopt the purchased provider term');

Capsule::$rows['mod_yourreselling_domains'] = [];
$registrations = 0;
FakeCurl::$handler = static function (array $request) use (&$provider, &$registrations): array {
    if (str_ends_with($request['url'], '/handles/create')) {
        return responseAt('data', ['id' => 'handle-one']);
    }
    if (str_ends_with($request['url'], '/domains/create')) {
        $registrations++;
        expect(json_decode($request['body'], true)['domain'] === 'example.de', 'Leading-dot TLD must not produce a doubled dot');

        return responseAt('data', ['id' => 'domain-one']);
    }
    expect($request['method'] === 'GET' && str_ends_with($request['url'], '/domain-one'), 'Registration must not cancel the newly purchased year');

    return responseAt('data', $provider);
};
expect(yourresellingdomains_RegisterDomain($params) === [] && yourresellingdomains_RegisterDomain($params) === [] && $registrations === 1, 'Registration callback must initialize renewal without duplicate purchase');

echo "Domain renewal: paid/unpaid terms, cancellation, rollover, multi-year coverage, retries and invalid state tests passed.\n";
