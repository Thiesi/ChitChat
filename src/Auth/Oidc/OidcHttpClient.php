<?php

declare(strict_types=1);

namespace ChitChat\Auth\Oidc;

/** The two requests a sign-in needs from a provider; a fake stands in during tests. */
interface OidcHttpClient
{
    /**
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    public function postForm(string $url, array $fields): array;

    /** @return array<string, mixed> */
    public function getJson(string $url): array;
}
