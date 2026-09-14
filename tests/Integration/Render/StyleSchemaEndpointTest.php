<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Style\StyleSchema;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\Http\Controllers\StyleSchemaController;
use Thallo\Render\ThemeLocator;

/** Visual builder spec §3.4: the inspector's one runtime source of the property table. */
final class StyleSchemaEndpointTest extends AppTestCase
{
    public function testTheEndpointIsBoundUnderTheAdminRenderPrefix(): void
    {
        $match = $this->router()->match(Request::create('/v1/admin/render/style-schema', 'GET'));
        self::assertNotNull($match, 'GET /v1/admin/render/style-schema must resolve');
    }

    public function testTheSchemaCarriesTheTableTheBreakpointsAndTheActiveVocabulary(): void
    {
        $response = $this->container()->get(StyleSchemaController::class)->show();
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true)['data'];

        self::assertSame(StyleSchema::VERSION, $data['version']);
        self::assertSame(['base' => 0, 'md' => 768, 'lg' => 1024], $data['breakpoints']);
        self::assertCount(count(StyleSchema::properties()), $data['properties']);
        $byPath = array_column($data['properties'], null, 'path');
        self::assertSame(
            ['path' => 'spacing.padding.top', 'group' => 'spacing', 'kinds' => ['token', 'reset'],
                'responsive' => true, 'token_domain' => 'spacing', 'choices' => null],
            $byPath['spacing.padding.top'],
        );
        self::assertSame(['start', 'center', 'end'], $byPath['alignment.text']['choices']);
        self::assertFalse($byPath['radius']['responsive']);
        $advanced = ['anchor', 'css_classes', 'attributes', 'accessibility.label'];
        self::assertSame($advanced, $data['advanced']);
        $spacing = ['none', 'xs', 'sm', 'md', 'lg', 'xl', '2xl', '3xl'];
        self::assertSame($spacing, $data['vocabulary']['domains']['spacing']);
        $values = $this->container()->get(ThemeLocator::class)->vocabulary()->values();
        self::assertSame($values, $data['vocabulary']['values']);
        self::assertSame('var(--space-4)', $data['vocabulary']['values']['spacing.lg']);
    }
}
