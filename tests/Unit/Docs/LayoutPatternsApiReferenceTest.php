<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

/**
 * The published API reference says what the layout editor's endpoints take (sections and templates
 * design §4, §5): the library for one layout, the section saved from a layout, the session's binding
 * rules. docs/openapi.json is generated (`composer docs:openapi`) and committed; this catches a
 * change to those endpoints that was not regenerated.
 */
final class LayoutPatternsApiReferenceTest extends TestCase
{
    private static function reference(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3) . '/docs/openapi.json');
    }

    public function testThePatternsEndpointDocumentsALayoutsLibrary(): void
    {
        self::assertStringContainsString('the layout editor\'s library for that layout', self::reference());
    }

    public function testSavingASectionDocumentsTheLayoutScope(): void
    {
        $reference = self::reference();
        self::assertStringContainsString('`layout` with its `surface`', $reference);
        self::assertStringContainsString('Requires `templates.manage` for a layout', $reference);
    }

    public function testTheLayoutSessionDocumentsItsBindingRules(): void
    {
        self::assertStringContainsString('target\'s binding rules', self::reference());
    }
}
