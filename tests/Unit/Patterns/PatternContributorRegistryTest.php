<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Patterns;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Patterns\PatternBlocks;
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
}
