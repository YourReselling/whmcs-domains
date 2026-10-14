<?php

declare(strict_types=1);

namespace YourReselling\Whmcs\Domains;

/** Local cURL boundary double: exercises request encoding, callbacks and error handling without network I/O. */
final class FakeCurl
{
    public static ?\Closure $handler = null;

    public static array $requests = [];

    public static array $options = [];

    public static int $status = 0;
}

function curl_init(): \CurlHandle|false
{
    return \curl_init();
}

function curl_setopt_array(\CurlHandle $handle, array $options): bool
{
    FakeCurl::$options = $options;

    return true;
}

function curl_exec(\CurlHandle $handle): bool
{
    $options = FakeCurl::$options;
    $request = ['method' => $options[CURLOPT_CUSTOMREQUEST], 'url' => $options[CURLOPT_URL], 'body' => $options[CURLOPT_POSTFIELDS] ?? null, 'headers' => $options[CURLOPT_HTTPHEADER], 'options' => $options];
    FakeCurl::$requests[] = $request;
    $result = (FakeCurl::$handler ?? throw new \RuntimeException('Unexpected network request.'))($request);
    if ($result === false) {
        return false;
    }
    FakeCurl::$status = $result['status'] ?? 200;
    $headers = $options[CURLOPT_HEADERFUNCTION];
    $headers($handle, "HTTP/1.1 100 Continue\r\n");
    $headers($handle, "X-Interim: ignore\r\n");
    $headers($handle, 'HTTP/2 '.FakeCurl::$status."\r\n");
    foreach ($result['headers'] ?? [] as $name => $value) {
        $headers($handle, $name.': '.$value."\r\n");
    }
    $body = $result['raw'] ?? json_encode($result['json'] ?? [], JSON_THROW_ON_ERROR);

    return strlen($body) === $options[CURLOPT_WRITEFUNCTION]($handle, $body);
}

function curl_getinfo(\CurlHandle $handle, int $option): int
{
    return FakeCurl::$status;
}
