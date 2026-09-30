<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Layouts;

use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Http\Controllers\PatternController;
use Thallo\Core\Content\Http\DTOs\Responses\Patterns\PatternData;
use Thallo\Core\Content\Layouts\LayoutValidator;
use Thallo\Core\Content\Patterns\LayoutPatterns;
use Thallo\Core\Content\Patterns\PatternLibrary;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\LayoutTypeShapes;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * A layout's sections and templates, served for its target (sections and templates design §3,
 * §3.2): built for the target's type, validated against it, returned exactly as validated; never in
 * the page library; nothing for a target that cannot have a layout.
 */
final class LayoutLibraryTest extends AppTestCase
{
    use LayoutTypeShapes;
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        $this->createLayoutTypeShapes();
    }

    private function library(): PatternLibrary
    {
        return $this->container()->get(PatternLibrary::class);
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @return list<array<string,mixed>>
     */
    private static function stripIds(array $blocks): array
    {
        foreach ($blocks as $i => $block) {
            unset($blocks[$i]['id']);
            foreach ($block['data'] ?? [] as $key => $value) {
                if (is_array($value) && array_is_list($value) && isset($value[0]['type'])) {
                    $blocks[$i]['data'][$key] = self::stripIds($value);
                }
            }
        }
        return $blocks;
    }

    public function testAPostLayoutGetsItsSectionsAndTemplatesBuiltForIt(): void
    {
        $entries = array_column($this->library()->forLayout('entry', 'lp_body'), null, 'slug');
        self::assertSame(
            [
                'entry-article-header', 'entry-cover-band', 'entry-related', 'entry-neighbours',
                'entry-classic', 'entry-magazine', 'entry-minimal',
            ],
            array_keys($entries),
        );
        $band = $entries['entry-cover-band'];
        self::assertSame(['layout', 'entry', 'section'], [$band['scope'], $band['surface'], $band['kind']]);
        self::assertNull($band['settings']);
        $magazine = $entries['entry-magazine'];
        self::assertSame(['page', 'Layouts'], [$magazine['kind'], $magazine['category']]);
        self::assertSame(['width' => 'full'], $entries['entry-magazine']['settings']);
    }

    public function testWhatDoesNotFitTheTargetIsNotOffered(): void
    {
        $slugs = array_column($this->library()->forLayout('entry', 'lp_rich'), 'slug');
        self::assertNotContains('entry-cover-band', $slugs);
    }

    public function testWhatIsServedIsWhatWasValidated(): void
    {
        $validator = $this->container()->get(LayoutValidator::class);
        foreach ($this->library()->forLayout('entry', 'lp_body') as $entry) {
            if ($entry['kind'] === 'page') {
                $blocks = self::withIds($entry['blocks']);
                $clean = $validator->validate('entry', 'lp_body', $blocks, $entry['settings'], [], false);
                self::assertSame($entry['blocks'], self::stripIds($clean['blocks']), "{$entry['slug']}: normalised");
            } else {
                $again = $validator->fragment('entry', 'lp_body', $entry['blocks']);
                self::assertSame([], $again['errors'], $entry['slug']);
                self::assertSame($entry['blocks'], $again['blocks'], "{$entry['slug']}: served normalised");
            }
            self::assertStringNotContainsString('"id"', (string) json_encode($entry['blocks']), $entry['slug']);
        }
    }

    public function testATemplateTheValidatorRefusesIsNotOffered(): void
    {
        // A block type switched off hides every pattern using it, as page patterns are hidden.
        $repo = new BlockTypeRepository($this->connection());
        $row = $repo->findBySlug('entry_related');
        self::assertNotNull($row);
        $repo->setActive((string) $row['uuid'], false);
        try {
            $slugs = array_column($this->library()->forLayout('entry', 'lp_body'), 'slug');
        } finally {
            $repo->setActive((string) $row['uuid'], true);
        }
        self::assertNotContains('entry-magazine', $slugs);
        self::assertNotContains('entry-related', $slugs);
        self::assertContains('entry-minimal', $slugs);
    }

    public function testAClosedTargetOffersNothing(): void
    {
        self::assertNotSame([], $this->library()->forLayout('listing', 'lp_body'));
        $this->closeListing('lp_body'); // listing pages off for the type: the target is closed
        self::assertSame([], $this->library()->forLayout('listing', 'lp_body'));
    }

    public function testAnUnknownSurfaceIsRefusedAndAnUnknownTargetOffersNothing(): void
    {
        try {
            $this->library()->forLayout('basket', 'x');
            self::fail('unknown surface');
        } catch (\InvalidArgumentException $e) {
            self::assertSame("unknown layout surface 'basket'", $e->getMessage());
        }
        self::assertSame([], $this->library()->forLayout('entry', 'no_such_type'));
    }

    public function testThePageLibraryHoldsNoLayoutPattern(): void
    {
        foreach ($this->library()->all() as $entry) {
            self::assertNotSame('layout', $entry['scope'], $entry['slug']);
            self::assertNull($entry['surface']);
            self::assertNull($entry['settings']);
        }
    }

    public function testTheEndpointServesTheTarget(): void
    {
        $controller = $this->container()->get(PatternController::class);
        $response = $controller->index(Request::create('/x', 'GET', ['surface' => 'entry', 'target' => 'lp_body']));
        $data = json_decode((string) $response->getContent(), true)['data'];
        self::assertContains('entry-classic', array_column($data['patterns'], 'slug'));
        self::assertDataMatchesDtoShape($data['patterns'][0], PatternData::class);
        $unknown = $controller->index(Request::create('/x', 'GET', ['surface' => 'basket', 'target' => 'x']));
        self::assertSame(422, $unknown->getStatusCode());
    }

    public function testLayoutSlugsCoverEveryRegisteredSurface(): void
    {
        $core = array_values(array_filter(
            $this->library()->layoutSlugs(),
            static fn (string $s): bool => !str_starts_with($s, 'product-') && !str_starts_with($s, 'shop-'),
        ));
        self::assertEqualsCanonicalizing(array_keys(LayoutPatterns::slugs()), $core);
    }
}
