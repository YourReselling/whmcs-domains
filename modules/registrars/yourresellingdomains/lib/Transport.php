<?php

declare(strict_types=1);

namespace YourReselling\Whmcs\Domains;

/** Private JSON transport for this module. No external SDK, Composer autoloader or retries. */
final class Transport
{
    public function __construct(
        private readonly string $token,
        private readonly string $baseUrl = 'https://resellingprovider.de/api/v1',
        private readonly int $timeout = 120,
    ) {
        $url = parse_url($baseUrl);
        if (! is_array($url) || ($url['scheme'] ?? null) !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
            throw new \InvalidArgumentException('The API base URL must be an absolute HTTPS URL without credentials, query or fragment.');
        }
        if ($token === '' || preg_match('/[\r\n]/', $token)) {
            throw new \InvalidArgumentException('Provide a non-empty API token without newline characters.');
        }
        if ($timeout < 1) {
            throw new \InvalidArgumentException('Timeout must be positive.');
        }
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'token' => '[redacted]'];
    }

    /**
     * @param  array<string,string|int>  $pathParameters  Identifiers encoded as individual path segments.
     * @param  array<string,mixed>  $query  Laravel bracket notation; null omitted, false and zero retained.
     * @param  array<string,mixed>|null  $body  JSON object; null means no request body.
     */
    public function send(string $method, string $path, array $pathParameters = [], array $query = [], ?array $body = null): Response
    {
        if (! in_array($method, ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'], true)) {
            throw new \InvalidArgumentException('Unsupported HTTP method.');
        }
        foreach ($pathParameters as $key => $value) {
            if ((string) $value === '' || in_array((string) $value, ['.', '..'], true)) {
                throw new \InvalidArgumentException('Path parameters must be non-empty identifiers.');
            }
            $path = str_replace('{'.$key.'}', rawurlencode((string) $value), $path);
        }
        $path = ltrim($path, '/');
        if ($path === '' || preg_match('~[{}?#\\\\\x00-\x20]|(^|/)\.\.?(?:/|$)|^[a-z]+:~i', $path)) {
            throw new \InvalidArgumentException('Invalid API path or missing route parameter.');
        }
        $url = rtrim($this->baseUrl, '/').'/'.$path;
        $encodedQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        if ($encodedQuery !== '') {
            $url .= '?'.$encodedQuery;
        }
        $headers = ['Accept: application/json', 'Authorization: Bearer '.$this->token, 'User-Agent: YourReselling-WHMCS/3.0'];
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeout,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = json_encode($body === [] ? new \stdClass : $body, JSON_THROW_ON_ERROR);
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        $responseHeaders = [];
        $responseBody = '';
        $options[CURLOPT_HEADERFUNCTION] = static function (\CurlHandle $handle, string $line) use (&$responseHeaders): int {
            if (str_starts_with($line, 'HTTP/')) {
                $responseHeaders = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }

            return strlen($line);
        };
        $options[CURLOPT_WRITEFUNCTION] = static function (\CurlHandle $handle, string $chunk) use (&$responseBody): int {
            if (strlen($responseBody) + strlen($chunk) > 16 * 1024 * 1024) {
                return 0;
            }
            $responseBody .= $chunk;

            return strlen($chunk);
        };
        $handle = curl_init();
        if ($handle === false) {
            throw new \RuntimeException('Could not initialize the API connection.');
        }
        if (! curl_setopt_array($handle, $options) || curl_exec($handle) === false) {
            throw new \RuntimeException('API transport failed. Check connectivity, TLS, response size and timeouts; do not automatically retry an order.');
        }
        $response = new Response((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $responseHeaders, $responseBody);
        if ($response->status() < 200 || $response->status() >= 300) {
            $json = json_decode(substr($responseBody, 0, 65536), true);
            $error = is_array($json['error'] ?? null) ? $json['error'] : [];
            $validation = $error['errors'] ?? $json['errors'] ?? [];
            $message = $error['message'] ?? $json['message'] ?? 'API request failed';
            $message = is_string($message) ? strip_tags(str_replace($this->token, '[redacted]', $message)) : 'API request failed';
            $validation = is_array($validation) ? $this->redact($validation) : [];
            throw new ApiException($message, $response->status(), isset($error['code']) ? (string) $error['code'] : null, $validation, $response->header('X-Request-ID') ?: null, $response->header('Retry-After') ?: null);
        }

        return $response;
    }

    private function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->redact($item), $value);
        }

        return is_string($value) ? str_replace($this->token, '[redacted]', $value) : $value;
    }
}
