<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Search\KindFilter;
use Thallo\Contracts\Search\SearchAudience;
use Thallo\Contracts\Search\SearchDocumentPage;
use Thallo\Contracts\Search\SearchSourceContributor;
use Thallo\Search\Sources\DefaultSearchSourceRegistry;

/** One contributor per result kind (spec §3.2): a second one for a kind is a programming error. */
final class SearchSourceRegistryTest extends TestCase
{
    public function testASecondContributorForAKindIsRefused(): void
    {
        $r = new DefaultSearchSourceRegistry();
        $r->register($this->contributor('entries'));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("'entries'");
        $r->register($this->contributor('entries'));
    }

    public function testAnInvalidKindIsRefusedAndOrderIsKept(): void
    {
        $r = new DefaultSearchSourceRegistry();
        $r->register($this->contributor('entries'));
        $r->register($this->contributor('products'));
        self::assertSame(['entries', 'products'], array_keys($r->all()));
        $this->expectException(\LogicException::class);
        $r->register($this->contributor('Bad_Kind'));
    }

    private function contributor(string $kind): SearchSourceContributor
    {
        return new class ($kind) implements SearchSourceContributor {
            public function __construct(private readonly string $kind)
            {
            }

            public function kind(): string
            {
                return $this->kind;
            }

            public function label(): string
            {
                return $this->kind;
            }

            public function requiredCapabilities(): array
            {
                return [];
            }

            public function schemaVersion(): int
            {
                return 1;
            }

            public function documents(string $sourceId): array
            {
                return [];
            }

            public function enumerate(?string $after, int $size): SearchDocumentPage
            {
                return new SearchDocumentPage([], null);
            }

            public function visibilityFilter(SearchAudience $audience): KindFilter
            {
                return KindFilter::none();
            }

            public function present(SearchAudience $audience, string $locale, array $sourceIds): array
            {
                return [];
            }
        };
    }
}
