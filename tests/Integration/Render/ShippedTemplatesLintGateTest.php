<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\Templates\TemplateLinter;

/**
 * Release gate (admin-contributed-templates spec §Testing): EVERY shipped template —
 * render default theme + every contributed pack dir — round-trips through the SAME
 * policy the admin save enforces. Exception-free by pinned decision: a template that
 * needs denied vocabulary is a bug in the template (or a reviewed policy addition),
 * never a lint-gate exception.
 */
final class ShippedTemplatesLintGateTest extends AppTestCase
{
    /** @return iterable<string, array{string}> */
    public static function shippedTemplates(): iterable
    {
        $repoRoot = \dirname(__DIR__, 3);
        $roots = [
            $repoRoot . '/packages/thallo-render/themes/default/templates',
            $repoRoot . '/packages/thallo-account/templates',
            $repoRoot . '/packages/thallo-commerce/templates',
        ];
        foreach ($roots as $root) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($it as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.twig')) {
                    // Repo-relative key — unique across roots (basename($root) would
                    // collide: all three roots end in "templates").
                    $key = substr($file->getPathname(), strlen($repoRoot) + 1);
                    yield $key => [$file->getPathname()];
                }
            }
        }
    }

    /** @dataProvider shippedTemplates */
    public function testShippedTemplateLintsClean(string $path): void
    {
        /** @var TemplateLinter $linter */
        $linter = $this->container()->get(TemplateLinter::class);
        $violations = $linter->lint((string) file_get_contents($path));
        self::assertSame([], $violations, "Shipped template fails the save policy: {$path}");
    }
}
