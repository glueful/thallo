<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Thallo\Contracts\Delivery\PreviewSession;
use Thallo\Contracts\Delivery\PreviewSessionVerifier;
use Thallo\Contracts\Delivery\PublicRouteResolver;
use Thallo\Core\Content\Preview\PreviewReader;
use Thallo\Core\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\MintsRegionTokens;

/**
 * A regions-stage session is a verified PreviewSession of its own kind (regions-stage spec §4.1),
 * and every consumer that reads drafts acts on entry sessions only (§4.7).
 */
final class RegionPreviewSessionTest extends AppTestCase
{
    use MintsRegionTokens;
    use SeedsPublishedContent;

    private function verifier(): PreviewSessionVerifier
    {
        return $this->container()->get(PreviewSessionVerifier::class);
    }

    public function testTheVerifierMapsARegionTokenToARegionsSession(): void
    {
        $session = $this->verifier()->verify($this->regionToken('sessABC', 'page123'));
        self::assertNotNull($session);
        self::assertSame(PreviewSession::KIND_REGIONS, $session->kind);
        self::assertSame('sessABC', $session->session);
        self::assertSame('page123', $session->page);
        self::assertSame('', $session->entry);
        self::assertFalse($session->isEntry());
    }

    public function testAnEntryTokenStaysAnEntrySession(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $token = $this->container()->get(\Thallo\Core\Content\Preview\PreviewMinter::class)->mint($entry, 'en', null);
        $session = $this->verifier()->verify($token);
        self::assertNotNull($session);
        self::assertTrue($session->isEntry());
        self::assertSame($entry, $session->entry);
    }

    public function testTheReaderRefusesARegionsSession(): void
    {
        $session = $this->verifier()->verify($this->regionToken());
        $this->expectException(\InvalidArgumentException::class);
        $this->container()->get(PreviewReader::class)->readVerified($session);
    }

    public function testTheResolverServesPublishedContentToARegionsSession(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $resolver = $this->container()->get(PublicRouteResolver::class);
        $session = $this->verifier()->verify($this->regionToken());

        $byPath = $resolver->resolvePath('/blog/hello', $session);
        self::assertSame('content', $byPath['kind']);
        self::assertSame('Hello', $byPath['content']['fields']['title'] ?? null);
        self::assertArrayNotHasKey('preview_revision', $byPath['content']);

        $byEntry = $resolver->resolveEntry($entry, 'en', $session);
        self::assertSame('Hello', $byEntry['content']['fields']['title'] ?? null);
    }

    public function testATokenForTheEntryDoorsIsRefusedWhenItIsARegionToken(): void
    {
        // EntryController::applyPreview and PreviewFragments parse the raw token with
        // PreviewToken::verify (a region token fails it — RegionPreviewTokenTest), and resolve
        // through resolvePreview, which answers not_found.
        $resolver = $this->container()->get(PublicRouteResolver::class);
        self::assertSame('not_found', $resolver->resolvePreview($this->regionToken())['kind']);
    }
}
