<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Patterns;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Patterns\PatternBlocks;
use Thallo\Contracts\Patterns\LayoutPatternContributor;
use Thallo\Contracts\Patterns\LayoutSection;
use Thallo\Contracts\Patterns\LayoutTemplate;
use Thallo\Contracts\Patterns\PatternContributor;
use Thallo\Contracts\Patterns\PatternSection;
use Thallo\Contracts\Patterns\PatternTemplate;
use Thallo\Core\Content\Patterns\DefaultPatternContributorRegistry;

/**
 * Packs contribute sections and templates, but never silently: a pattern's slug is unique across
 * core and every pack, a template names only sections that exist and belong on a page, and a
 * contributor that breaks either rule is refused whole, naming who already holds the slug.
 */
final class PatternContributorRegistryTest extends TestCase
{
    /**
     * @param list<PatternSection> $sections
     * @param list<PatternTemplate> $templates
     */
    private static function contributor(string $id, array $sections = [], array $templates = []): PatternContributor
    {
        return new class ($id, $sections, $templates) implements PatternContributor {
            /**
             * @param list<PatternSection> $sections
             * @param list<PatternTemplate> $templates
             */
            public function __construct(
                private readonly string $id,
                private readonly array $sections,
                private readonly array $templates,
            ) {
            }

            public function id(): string
            {
                return $this->id;
            }

            public function sections(): array
            {
                return $this->sections;
            }

            public function templates(): array
            {
                return $this->templates;
            }
        };
    }

    private static function section(string $slug): PatternSection
    {
        $block = PatternBlocks::heading('Hi', 'h2', 'center');

        return new PatternSection($slug, 'Label', 'Shop', 'A description.', $block);
    }

    /** @param list<string> $sections */
    private static function template(string $slug, array $sections): PatternTemplate
    {
        return new PatternTemplate($slug, 'Label', 'A description.', $sections);
    }

    public function testAContributorIsListedOnce(): void
    {
        $registry = new DefaultPatternContributorRegistry();
        $a = self::contributor('a');
        $registry->register($a);
        self::assertSame([$a], $registry->all());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("'a'");
        $registry->register(self::contributor('a'));
    }

    public function testASlugTakenByCoreIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Pattern 'faq' of 'a' is already taken by 'core'.");
        (new DefaultPatternContributorRegistry())->register(self::contributor('a', [self::section('faq')]));
    }

    public function testASlugTakenByAnotherContributorIsRefused(): void
    {
        $registry = new DefaultPatternContributorRegistry();
        $a = self::contributor('a', [self::section('x-hero')]);
        $registry->register($a);

        try {
            $registry->register(self::contributor('b', [self::section('x-hero')]));
            self::fail('a second x-hero must be refused');
        } catch (\LogicException $e) {
            self::assertSame("Pattern 'x-hero' of 'b' is already taken by 'a'.", $e->getMessage());
        }
        self::assertSame([$a], $registry->all());
        self::assertSame('a', $registry->ownerOf('x-hero'));
    }

    public function testATemplateMustNameKnownPageSections(): void
    {
        $registry = new DefaultPatternContributorRegistry();
        foreach (
            [
                'x-missing' => "Template 'x-page' of 'a' names 'x-missing', which is not a page section.",
                'header-announcement' =>
                    "Template 'x-page' of 'a' names 'header-announcement', which is not a page section.",
            ] as $slug => $message
        ) {
            try {
                $registry->register(self::contributor('a', [self::section('x-hero')], [
                    self::template('x-page', ['x-hero', $slug]),
                ]));
                self::fail("{$slug} must be refused");
            } catch (\LogicException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }

        $registry->register(self::contributor('a', [self::section('x-hero')], [
            self::template('x-page', ['x-hero', 'faq']),
        ]));
        self::assertSame('a', $registry->ownerOf('x-page'));
    }

    public function testATemplateWithNoSectionsIsRefused(): void
    {
        $registry = new DefaultPatternContributorRegistry();
        try {
            $registry->register(self::contributor('a', [self::section('x-hero')], [self::template('x-empty', [])]));
            self::fail('a template that inserts nothing must be refused');
        } catch (\LogicException $e) {
            self::assertSame("Template 'x-empty' of 'a' names no sections.", $e->getMessage());
        }
        self::assertSame([], $registry->all());
        self::assertNull($registry->ownerOf('x-hero'));
    }

    public function testRegistrationIsAllOrNothing(): void
    {
        $registry = new DefaultPatternContributorRegistry();
        try {
            $registry->register(self::contributor('a', [self::section('x-hero')], [
                self::template('x-good', ['x-hero']),
                self::template('x-bad', ['x-nope']),
            ]));
            self::fail('a bad template must refuse the contributor');
        } catch (\LogicException) {
        }

        self::assertSame([], $registry->all());
        self::assertNull($registry->ownerOf('x-hero'));
        self::assertNull($registry->ownerOf('x-good'));
    }

    /**
     * @param list<LayoutSection> $sections
     * @param list<LayoutTemplate> $templates
     */
    private static function layoutContributor(
        string $id,
        array $sections = [],
        array $templates = [],
    ): LayoutPatternContributor {
        return new class ($id, $sections, $templates) implements LayoutPatternContributor {
            /**
             * @param list<LayoutSection> $sections
             * @param list<LayoutTemplate> $templates
             */
            public function __construct(
                private readonly string $id,
                private readonly array $sections,
                private readonly array $templates,
            ) {
            }

            public function id(): string
            {
                return $this->id;
            }

            public function layoutSections(): array
            {
                return $this->sections;
            }

            public function layoutTemplates(): array
            {
                return $this->templates;
            }
        };
    }

    private static function layoutSection(string $slug, string $surface = 'product'): LayoutSection
    {
        return new LayoutSection(
            $slug,
            $surface,
            'Label',
            'Product',
            'A description.',
            static fn (): array => PatternBlocks::heading('Hi', 'h2', 'start'),
        );
    }

    private static function layoutTemplate(string $slug, string $surface = 'product'): LayoutTemplate
    {
        return new LayoutTemplate($slug, $surface, 'Label', 'A description.', static fn (): array => [], [
            'width' => 'full',
        ]);
    }

    public function testALayoutContributorIsListedAndOwnsItsSlugs(): void
    {
        $registry = new DefaultPatternContributorRegistry();
        $registry->registerLayout(self::layoutContributor(
            'a',
            [self::layoutSection('product-x')],
            [self::layoutTemplate('product-y')],
        ));
        self::assertCount(1, $registry->layoutContributors());
        self::assertSame('a', $registry->ownerOf('product-x'));
        self::assertSame('a', $registry->ownerOf('product-y'));
    }

    public function testALayoutSlugMayNotTakeAPageSlugOrAnotherContributors(): void
    {
        $registry = new DefaultPatternContributorRegistry();
        $registry->register(self::contributor('pages', [self::section('x-hero')]));
        foreach (['faq' => 'core', 'x-hero' => 'pages'] as $slug => $owner) {
            try {
                $registry->registerLayout(self::layoutContributor('b', [self::layoutSection($slug)]));
                self::fail("{$slug} must be refused");
            } catch (\LogicException $e) {
                self::assertSame("Pattern '{$slug}' of 'b' is already taken by '{$owner}'.", $e->getMessage());
            }
        }
        $registry->registerLayout(self::layoutContributor('c', [self::layoutSection('product-z')]));
        $this->expectExceptionMessage("Pattern 'product-z' of 'd' is already taken by 'c'.");
        $registry->registerLayout(self::layoutContributor('d', [], [self::layoutTemplate('product-z')]));
    }

    public function testALayoutPatternNamesAKnownSurface(): void
    {
        $registry = new DefaultPatternContributorRegistry();
        $this->expectExceptionMessage("Pattern 'x-thing' of 'a' names the surface 'basket', which no layout has.");
        $registry->registerLayout(self::layoutContributor('a', [self::layoutSection('x-thing', 'basket')]));
    }

    public function testALayoutContributorIdIsUniqueAndRegistrationIsWhole(): void
    {
        $registry = new DefaultPatternContributorRegistry();
        $registry->registerLayout(self::layoutContributor('a'));
        try {
            $registry->registerLayout(self::layoutContributor('a'));
            self::fail('a second contributor with the id must be refused');
        } catch (\LogicException $e) {
            self::assertSame("Layout pattern contributor 'a' is already registered.", $e->getMessage());
        }
        try {
            $registry->registerLayout(self::layoutContributor(
                'b',
                [self::layoutSection('product-ok'), self::layoutSection('shop-index-bad', 'basket')],
            ));
            self::fail('an unknown surface must refuse the contributor');
        } catch (\LogicException) {
        }
        self::assertNull($registry->ownerOf('product-ok'), 'nothing of a refused contributor is kept');
        self::assertCount(1, $registry->layoutContributors());
    }

    public function testAPageContributorAndALayoutContributorMayShareAnId(): void
    {
        $registry = new DefaultPatternContributorRegistry();
        $registry->register(self::contributor('thallo.commerce', [self::section('shop-a')]));
        $registry->registerLayout(self::layoutContributor('thallo.commerce', [self::layoutSection('product-a')]));
        self::assertSame('thallo.commerce', $registry->ownerOf('product-a'));
    }
}
