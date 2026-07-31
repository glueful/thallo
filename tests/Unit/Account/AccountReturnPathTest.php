<?php

declare(strict_types=1);

namespace App\Tests\Unit\Account;

use PHPUnit\Framework\TestCase;
use Thallo\Account\AccountReturnPath;

/**
 * The single return-path authority (public-account-surface plan Task 3). It guards BOTH the
 * `?next=` a visitor can supply and the operator-configured redirect settings: only a normalized
 * application-relative path with exactly one leading `/` is safe; every open-redirect shape —
 * protocol-relative, absolute, scheme, backslash, control char, or percent-encoded bypass — is
 * rejected. `resolve()` layers precedence: a valid `next` wins, else the configured default, else
 * the fixed fallback.
 */
final class AccountReturnPathTest extends TestCase
{
    private AccountReturnPath $paths;

    protected function setUp(): void
    {
        $this->paths = new AccountReturnPath();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileCandidates(): iterable
    {
        yield 'empty' => [''];
        yield 'protocol-relative' => ['//evil.example'];
        yield 'absolute https' => ['https://evil.example/x'];
        yield 'absolute http' => ['http://evil.example'];
        yield 'scheme javascript' => ['javascript:alert(1)'];
        yield 'scheme data' => ['data:text/html,x'];
        yield 'backslash escape' => ['/\\evil.example'];
        yield 'double backslash' => ['\\\\evil.example'];
        yield 'no leading slash' => ['account/orders'];
        yield 'encoded double slash' => ['%2f%2fevil.example'];
        yield 'encoded slash mid' => ['/foo%2f%2fevil'];
        yield 'encoded backslash' => ['/%5cevil'];
        yield 'encoded null' => ['/foo%00bar'];
        yield 'leading space' => [' /account/orders'];
        yield 'leading tab' => ["\t/account/orders"];
        yield 'embedded newline' => ["/account\n/orders"];
        yield 'embedded null' => ["/account\0/orders"];
    }

    /** @dataProvider hostileCandidates */
    public function testRejectsHostileCandidate(string $candidate): void
    {
        self::assertNull($this->paths->validate($candidate));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function safeCandidates(): iterable
    {
        yield 'root' => ['/'];
        yield 'account' => ['/account'];
        yield 'nested' => ['/account/orders'];
        yield 'deep' => ['/account/orders/123'];
        yield 'query' => ['/account/orders?tab=recent'];
        yield 'fragment' => ['/account#section'];
    }

    /** @dataProvider safeCandidates */
    public function testAcceptsAndReturnsSafeCandidate(string $candidate): void
    {
        self::assertSame($candidate, $this->paths->validate($candidate));
    }

    public function testResolvePrefersAValidNext(): void
    {
        self::assertSame('/account/orders', $this->paths->resolve('/account/orders', '/dashboard', '/account'));
    }

    public function testResolveFallsToConfiguredWhenNextIsMissing(): void
    {
        self::assertSame('/dashboard', $this->paths->resolve(null, '/dashboard', '/account'));
    }

    public function testResolveFallsToConfiguredWhenNextIsHostile(): void
    {
        self::assertSame('/dashboard', $this->paths->resolve('//evil.example', '/dashboard', '/account'));
    }

    public function testResolveFallsToFallbackWhenBothInvalid(): void
    {
        self::assertSame('/account', $this->paths->resolve('//evil.example', 'https://evil.example', '/account'));
    }

    public function testResolveFallsToFallbackWhenBothMissing(): void
    {
        self::assertSame('/account', $this->paths->resolve(null, null, '/account'));
    }

    // --- validatePagePath: the PATH-ONLY posted-return authority (form-blocks plan Task 3) ----

    /**
     * @return iterable<string, array{string}>
     */
    public static function pageOnlyCandidates(): iterable
    {
        yield 'root' => ['/'];
        yield 'custom page' => ['/signin'];
        yield 'nested custom page' => ['/members/welcome'];
    }

    /** @dataProvider pageOnlyCandidates */
    public function testValidatePagePathAcceptsAPlainPath(string $candidate): void
    {
        self::assertSame($candidate, $this->paths->validatePagePath($candidate));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonPageCandidates(): iterable
    {
        // Every hostile shape validate() rejects stays rejected...
        yield 'protocol-relative' => ['//evil.example'];
        yield 'absolute' => ['https://evil.example/x'];
        yield 'scheme' => ['javascript:alert(1)'];
        yield 'encoded' => ['%2f%2fevil.example'];
        // ...and ADDITIONALLY anything carrying a query or fragment — the controller appends its
        // one allowlisted parameter, so a path-only contract removes merge/duplicate-key ambiguity.
        yield 'query' => ['/signin?tab=recent'];
        yield 'fragment' => ['/signin#form'];
        yield 'query and fragment' => ['/signin?a=1#b'];
    }

    /** @dataProvider nonPageCandidates */
    public function testValidatePagePathRejectsNonPageCandidates(string $candidate): void
    {
        self::assertNull($this->paths->validatePagePath($candidate));
    }

    public function testValidateStillAcceptsQueryAndFragmentForNextDestinations(): void
    {
        // The richer contract is untouched: post-login `next` may legitimately carry either.
        self::assertSame('/account/orders?tab=recent', $this->paths->validate('/account/orders?tab=recent'));
        self::assertSame('/account#section', $this->paths->validate('/account#section'));
    }
}
