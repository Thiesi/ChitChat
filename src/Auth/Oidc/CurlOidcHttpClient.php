<?php

declare(strict_types=1);

namespace ChitChat\Auth\Oidc;

use ChitChat\Http\ApiException;

/** Talks to real providers over HTTPS with short timeouts and no redirects. */
final class CurlOidcHttpClient implements OidcHttpClient
{
    public function postForm(string $url, array $fields): array
    {
        return $this->request($url, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
        ]);
    }

    public function getJson(string $url): array
    {
        return $this->request($url, [CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    }

    /**
     * @param array<int, mixed> $options
     * @return array<string, mixed>
     */
    private function request(string $url, array $options): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw $this->unavailable();
        }
        curl_setopt_array($handle, $options + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_USERAGENT => 'ChitChat sign-in',
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if (!is_string($body) || $status < 200 || $status >= 300) {
            error_log(sprintf('Sign-in provider request to %s failed with HTTP %d.', parse_url($url, PHP_URL_HOST) ?: 'provider', $status));
            throw $this->unavailable();
        }
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : throw $this->unavailable();
    }

    private function unavailable(): ApiException
    {
        return new ApiException(502, 'sign_in_provider_failed', 'The sign-in provider did not respond as expected. Please try again.');
    }
}
