<?php

declare(strict_types=1);

namespace ChitChat\Auth\Oidc;

/** The requests a sign-in (and a picture import) needs from a provider; a fake stands in during tests. */
interface OidcHttpClient
{
    /**
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    public function postForm(string $url, array $fields): array;

    /** @return array<string, mixed> */
    public function getJson(string $url): array;

    /** Downloads at most `maxBytes`, failing if the body is larger. */
    public function getBytes(string $url, int $maxBytes): string;
}
