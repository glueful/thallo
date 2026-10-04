<?php

declare(strict_types=1);

namespace Thallo\Contracts\Search;

/**
 * The shapes a search document's parts may take (search block spec §3.2/§3.3). A kind names a
 * result kind (`entries`, `products`); a source id names one item within it; a locale is a BCP 47
 * tag or `*` for every locale. None may hold `_`, which the document id uses as its separator.
 */
final class SearchIdentity
{
    public const KIND = '/\A[a-z][a-z0-9]{0,15}\z/';
    public const SOURCE_ID = '/\A[A-Za-z0-9]{1,64}\z/';
    public const LOCALE = '/\A(?:[A-Za-z0-9-]{1,12}|\*)\z/';

    public static function assertKind(string $value): void
    {
        self::check(self::KIND, $value, 'kind');
    }

    public static function assertSourceId(string $value): void
    {
        self::check(self::SOURCE_ID, $value, 'sourceId');
    }

    public static function assertLocale(string $value): void
    {
        self::check(self::LOCALE, $value, 'locale');
    }

    private static function check(string $pattern, string $value, string $field): void
    {
        if (preg_match($pattern, $value) !== 1) {
            throw new \InvalidArgumentException("Invalid search {$field}: '{$value}'.");
        }
    }
}
