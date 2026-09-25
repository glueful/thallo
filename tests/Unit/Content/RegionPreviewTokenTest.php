<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Content;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Content\Preview\PreviewToken;
use Thallo\Core\Content\Preview\PreviewTokenException;
use Thallo\Core\Content\Preview\RegionPreviewToken;

/**
 * A regions-stage session token (regions-stage spec §4.1): its own claims and its own parser, so
 * an entry token never passes for a region session and a region token never passes for an entry.
 */
final class RegionPreviewTokenTest extends TestCase
{
    private const KEY = 'test-key-0123456789';

    public function testItRoundTrips(): void
    {
        $token = RegionPreviewToken::mint('sess0000001', 'page0000001', 'en', 2_000, self::KEY);
        $claims = RegionPreviewToken::verify($token, self::KEY, 1_000);
        self::assertSame('sess0000001', $claims->session);
        self::assertSame('page0000001', $claims->page);
        self::assertSame('en', $claims->locale);
        self::assertSame(2_000, $claims->expiresAt);
    }

    public function testAPageIsOptional(): void
    {
        $token = RegionPreviewToken::mint('sess0000001', null, 'en', 2_000, self::KEY);
        self::assertNull(RegionPreviewToken::verify($token, self::KEY, 1_000)->page);
    }

    public function testATamperedTokenFails(): void
    {
        $token = RegionPreviewToken::mint('sess0000001', null, 'en', 2_000, self::KEY);
        [$payload, $sig] = explode('.', $token, 2);
        $claims = json_encode(['k' => 'regions', 's' => 'other', 'l' => 'en', 'exp' => 2_000]);
        $forged = rtrim(strtr(base64_encode($claims), '+/', '-_'), '=');
        $this->expectException(PreviewTokenException::class);
        RegionPreviewToken::verify($forged . '.' . $sig, self::KEY, 1_000);
    }

    public function testAnExpiredTokenFails(): void
    {
        $token = RegionPreviewToken::mint('sess0000001', null, 'en', 2_000, self::KEY);
        $this->expectException(PreviewTokenException::class);
        RegionPreviewToken::verify($token, self::KEY, 2_001);
    }

    public function testAnEntryTokenIsNotARegionToken(): void
    {
        $entry = PreviewToken::mint('entry000001', 'en', null, 2_000, self::KEY);
        $this->expectException(PreviewTokenException::class);
        RegionPreviewToken::verify($entry, self::KEY, 1_000);
    }

    public function testARegionTokenIsNotAnEntryToken(): void
    {
        $region = RegionPreviewToken::mint('sess0000001', 'page0000001', 'en', 2_000, self::KEY);
        $this->expectException(PreviewTokenException::class);
        PreviewToken::verify($region, self::KEY, 1_000);
    }
}
