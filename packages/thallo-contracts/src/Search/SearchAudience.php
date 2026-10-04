<?php

declare(strict_types=1);

namespace Thallo\Contracts\Search;

/**
 * Who a search answers (search block spec §3.4): the public, or an API key with its scopes. The
 * site's own surfaces always search as the public, whoever is signed in.
 */
final class SearchAudience
{
    /** @param list<string>|null $apiKeyScopes null = the public */
    private function __construct(public readonly ?array $apiKeyScopes)
    {
    }

    public static function public(): self
    {
        return new self(null);
    }

    /** @param list<string> $scopes */
    public static function apiKey(array $scopes): self
    {
        return new self(array_values($scopes));
    }

    public function isPublic(): bool
    {
        return $this->apiKeyScopes === null;
    }

    /** A stable string a cursor binds to, so a cursor never crosses audiences. */
    public function fingerprint(): string
    {
        if ($this->apiKeyScopes === null) {
            return 'public';
        }
        $scopes = $this->apiKeyScopes;
        sort($scopes);
        return 'key:' . hash('sha256', implode("\n", $scopes));
    }
}
