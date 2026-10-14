<?php

declare(strict_types=1);

use YourReselling\Whmcs\Domains\ApiException;
use YourReselling\Whmcs\Domains\FakeCurl;
use YourReselling\Whmcs\Domains\Integration\Catalog;
use YourReselling\Whmcs\Domains\Integration\DomainGateway;
use YourReselling\Whmcs\Domains\Integration\Provisioner;
use YourReselling\Whmcs\Domains\Integration\ServiceGateway;
use YourReselling\Whmcs\Domains\Integration\StateStore;
use YourReselling\Whmcs\Domains\Transport;

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$environmentIncludes = get_included_files();
$root = dirname(__DIR__);
$manifest = json_decode(file_get_contents($root.'/module.json'), true, 512, JSON_THROW_ON_ERROR);
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$manifest['modulePath'], FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() === 'php') {
        PhpToken::tokenize(file_get_contents($file->getPathname()), TOKEN_PARSE);
    }
}
require __DIR__.'/FakeCurl.php';
define('WHMCS', true);
require $root.'/'.$manifest['modulePath'].'/'.$manifest['module'].'.php';

function expect(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function rejects(Closure $action, string $message): Throwable
{
    try {
        $action();
    } catch (Throwable $error) {
        expect(str_contains($error->getMessage(), $message), 'Unexpected error: '.$error->getMessage());

        return $error;
    }
    throw new RuntimeException('Expected rejection: '.$message);
}

function responseAt(string $path, mixed $value): array
{
    foreach (array_reverse(explode('.', $path)) as $part) {
        $value = [$part => $value];
    }

    return ['json' => ['success' => true] + $value];
}

final class MemoryStore implements StateStore
{
    public array $state = [];

    public function read(): array
    {
        return $this->state;
    }

    public function write(array $state): void
    {
        $this->state = $state;
    }

    public function locked(Closure $operation): mixed
    {
        return $operation();
    }
}

$api = new Transport('secret-test-token', 'https://api.example.test/api/v1');
FakeCurl::$handler = static fn (): array => ['json' => ['data' => ['id' => 'one']], 'headers' => ['X-Request-ID' => 'trace-one']];
$response = $api->send('POST', '/things/{id}', ['id' => 'a/b c'], ['filter' => ['active' => false], 'limit' => 0, 'skip' => null], []);
$request = FakeCurl::$requests[0];
expect($request['url'] === 'https://api.example.test/api/v1/things/a%2Fb%20c?filter%5Bactive%5D=0&limit=0', 'Path/query encoding');
expect($request['body'] === '{}' && in_array('Authorization: Bearer secret-test-token', $request['headers'], true), 'JSON/auth encoding');
expect($request['options'][CURLOPT_FOLLOWLOCATION] === false && $request['options'][CURLOPT_SSL_VERIFYPEER] === true && $request['options'][CURLOPT_SSL_VERIFYHOST] === 2 && $request['options'][CURLOPT_PROTOCOLS] === CURLPROTO_HTTPS, 'TLS/redirect policy');
expect($response->json()['data']['id'] === 'one' && $response->header('x-request-id') === 'trace-one' && $response->header('x-interim') === '', 'Response parsing/header reset');
expect(! str_contains(print_r($api, true), 'secret-test-token'), 'Debug token leak');
rejects(fn () => new Transport('token', 'http://insecure.test'), 'HTTPS');
rejects(fn () => new Transport("token\r\nheader"), 'newline');
foreach (['/things/{missing}', '../account', 'https://other.test', '/things?secret=1'] as $path) {
    rejects(fn () => $api->send('GET', $path), 'Invalid API path');
}
FakeCurl::$handler = static fn (): array => ['status' => 429, 'json' => ['error' => ['message' => 'secret-test-token denied', 'code' => 'RATE_LIMIT', 'errors' => ['token' => ['secret-test-token']]]], 'headers' => ['X-Request-ID' => 'trace-two', 'Retry-After' => '30']];
$error = rejects(fn () => $api->send('POST', '/order'), '[redacted] denied');
expect($error instanceof ApiException && $error->status === 429 && $error->errorCode === 'RATE_LIMIT' && $error->requestId === 'trace-two' && $error->retryAfter === '30', 'Structured API error');
expect(! str_contains(json_encode($error->validationErrors), 'secret-test-token'), 'Validation token leak');
$escapedToken = 'token/with"quotes';
FakeCurl::$handler = static fn (): array => ['status' => 422, 'json' => ['errors' => ['token' => [$escapedToken]]]];
$escapedError = rejects(fn () => (new Transport($escapedToken))->send('POST', '/order'), 'API request failed');
expect($escapedError->validationErrors === ['token' => ['[redacted]']], 'Escaped validation token leak');
FakeCurl::$handler = static fn (): array => ['status' => 302, 'headers' => ['Location' => 'https://other.test'], 'raw' => '<html>redirect</html>'];
$before = count(FakeCurl::$requests);
rejects(fn () => $api->send('GET', '/account'), 'API request failed');
expect(count(FakeCurl::$requests) === $before + 1, 'Redirect was followed');
FakeCurl::$handler = static fn (): bool => false;
$before = count(FakeCurl::$requests);
rejects(fn () => $api->send('POST', '/order'), 'API transport failed');
expect(count(FakeCurl::$requests) === $before + 1, 'Transport retried a write');
FakeCurl::$handler = static fn (): array => ['raw' => str_repeat('x', 16 * 1024 * 1024 + 1)];
rejects(fn () => $api->send('GET', '/account'), 'API transport failed');
FakeCurl::$handler = static fn (): array => ['raw' => 'invalid-json'];
rejects(fn () => $api->send('GET', '/account')->json(), 'invalid JSON');

$module = $manifest['module'];
expect(is_array(($module.'_MetaData')()), 'WHMCS metadata callback');
$store = new MemoryStore;
$creates = 0;
if ($manifest['type'] === 'servers') {
    $slug = $manifest['product'];
    expect(array_keys(Catalog::all()) === [$slug], 'Package contains a foreign product profile');
    $profile = Catalog::product($slug);
    expect(count(($module.'_ConfigOptions')()) <= 24, 'WHMCS configuration limit');
    foreach (['suspend' => 'SuspendAccount', 'unsuspend' => 'UnsuspendAccount', 'termination' => 'TerminateAccount'] as $capability => $callback) {
        expect(function_exists($module.'_'.$callback) === ($profile[$capability] !== null), 'Unsupported/missing callback');
    }
    $input = ['body' => [], 'path' => []];
    foreach ($profile['fields'] as $field) {
        if ($field['required']) {
            $input[$field['path'] ? 'path' : 'body'][$field['name']] = $field['enum'][0] ?? match ($field['type'] ?? 'string') {
                'integer', 'number' => 1, 'boolean' => true, 'array', 'object' => [], default => 'fixture-id',
            };
        }
    }
    $shows = 0;
    FakeCurl::$handler = static function (array $request) use ($profile, $slug, $input, $store, &$creates, &$shows): array {
        if ($request['method'] === $profile['create']['method']) {
            $creates++;
            expect(($store->state['creating'] ?? false) === true, 'Purchase journal not written first');
            $expected = $profile['create']['path'];
            foreach ($input['path'] as $key => $value) {
                $expected = str_replace('{'.$key.'}', rawurlencode((string) $value), $expected);
            }
            expect(parse_url($request['url'], PHP_URL_PATH) === '/api/v1'.$expected, 'Wrong create route');

            return responseAt($profile['create']['response_id_path'], 'owned-resource');
        }
        $shows++;
        if ($slug === 'mailbox') {
            expect(str_contains($request['url'], '/domains/fixture-id/mailboxes'), 'Mailbox parent lost');

            return responseAt('data.mailboxes', [['id' => 'other-resource', 'enabled' => true], ['id' => 'owned-resource', 'enabled' => $shows > 1]]);
        }
        expect(str_ends_with($request['url'], '/owned-resource'), 'Wrong service ID');

        return responseAt($profile['showDataPath'], ['id' => 'owned-resource', 'status' => $shows === 1 ? 'pending' : $profile['activeStatuses'][0]]);
    };
    $provisioner = new Provisioner(new ServiceGateway($api, $profile), $store);
    rejects(fn () => $provisioner->create($input['body'], $input['path']), 'noch nicht bestätigt');
    expect($store->state['id'] === 'owned-resource', 'Lost asynchronous resource ID');
    expect($provisioner->create($input['body'], $input['path']) === 'owned-resource' && $creates === 1, 'Duplicate purchase on resume');
    expect($provisioner->create($input['body'], $input['path']) === 'owned-resource' && $creates === 1, 'Duplicate purchase on completed retry');
    $store = new MemoryStore;
    FakeCurl::$handler = static fn (): bool => false;
    $provisioner = new Provisioner(new ServiceGateway($api, $profile), $store);
    $before = count(FakeCurl::$requests);
    rejects(fn () => $provisioner->create($input['body'], $input['path']), 'API transport failed');
    rejects(fn () => $provisioner->create($input['body'], $input['path']), 'Bestellstatus unklar');
    expect(count(FakeCurl::$requests) === $before + 1, 'Ambiguous purchase was retried');
} else {
    expect(function_exists($module.'_RenewDomain'), 'Missing cancellation-based renewal');
    expect(is_array(($module.'_getConfigArray')()), 'Registrar configuration');
    FakeCurl::$handler = static function (array $request) use ($store, &$creates): array {
        $path = parse_url($request['url'], PHP_URL_PATH);
        if (str_ends_with($path, '/handles/create')) {
            return responseAt('data', ['id' => 'handle-one']);
        }
        if (str_ends_with($path, '/domains/create')) {
            $creates++;
            expect($store->state['creating'] === true, 'Registrar journal not written first');
            $body = json_decode($request['body'], true);
            expect($body['domain'] === 'example.de' && $body['handle_id'] === 'handle-one', 'Registrar contact/domain binding');

            return responseAt('data', ['id' => 'domain-one']);
        }
        expect(str_ends_with($path, '/domain-one'), 'Domain name used instead of UUID');

        return responseAt('data', ['id' => 'domain-one', 'status' => 'active']);
    };
    $gateway = new DomainGateway($api, $store, 'example.de');
    $gateway->order(['type' => 'individual'], ['years' => 1]);
    $gateway->order(['type' => 'individual'], ['years' => 1]);
    expect($creates === 1, 'Duplicate domain purchase');
    require __DIR__.'/renewal.php';
}

foreach (array_diff(get_included_files(), $environmentIncludes) as $file) {
    expect(str_starts_with(realpath($file), realpath($root).DIRECTORY_SEPARATOR), 'Dependency outside standalone repository: '.$file);
}
echo $manifest['repository'].": standalone transport, callbacks, provisioning and retry tests passed.\n";
