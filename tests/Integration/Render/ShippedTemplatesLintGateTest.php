<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\Templates\TemplateLinter;

/**
 * Ratchet gate (admin-contributed-templates spec §Testing): EVERY shipped template —
 * render default theme + every contributed pack dir — must lint clean under the SAME
 * policy the admin save enforces, EXCEPT two named disk-only pins.
 *
 * Two-way ratchet (fails if):
 * - Either pinned template becomes clean (pin assertions verify non-empty violations)
 * - A pinned template gains a different violation class (ALL-violations assertions)
 * - A pinned template's path changes (file-exists assertions)
 * - Any un-pinned template fails the sweep
 *
 * Closed two-template policy: entries are never added without a spec amendment.
 *
 * Disk-only pins (exceptions by reviewed design decision):
 * - blocks/html.twig: raw filter by design (trusted-editor escape hatch, spec §2)
 * - blocks/shortcode.twig: dynamic include dispatch by design (defense-in-depth pattern)
 */
final class ShippedTemplatesLintGateTest extends AppTestCase
{
    /** Repo-relative paths of templates pinned to fail (disk-only pins) */
    private const PINNED_FAILURES = [
        'packages/thallo-render/themes/default/templates/blocks/html.twig',
        'packages/thallo-render/themes/default/templates/blocks/shortcode.twig',
    ];

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
                    // Skip pinned failures in the main sweep
                    if (!in_array($key, self::PINNED_FAILURES, true)) {
                        yield $key => [$file->getPathname()];
                    }
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

    /**
     * Pin: blocks/html.twig
     * Rationale: raw filter by design (trusted-editor escape hatch, spec §2)
     * Ratchet: fails if the file is removed, violations become empty, or a violation
     * class other than raw-filter denial appears.
     */
    public function testPinnedHtmlBlockHasRawFilterViolation(): void
    {
        $repoRoot = \dirname(__DIR__, 3);
        $path = $repoRoot . '/packages/thallo-render/themes/default/templates/blocks/html.twig';

        // Ratchet: file must exist at exact path
        self::assertFileExists($path);

        /** @var TemplateLinter $linter */
        $linter = $this->container()->get(TemplateLinter::class);
        $violations = $linter->lint((string) file_get_contents($path));

        // Ratchet: violations must remain non-empty (if clean, the pin has been removed)
        self::assertNotEmpty($violations, 'Pinned template blocks/html.twig must have violations');

        // Ratchet: EVERY violation must be the raw filter denial (RawFilter node class)
        foreach ($violations as $violation) {
            self::assertStringContainsString(
                'Twig\Node\Expression\Filter\RawFilter',
                $violation['message'],
                "Pinned template blocks/html.twig must fail ONLY for raw-filter denial, got: {$violation['message']}"
            );
        }
    }

    /**
     * Pin: blocks/shortcode.twig
     * Rationale: dynamic include dispatch by design (defense-in-depth pattern)
     * Ratchet: fails if the file is removed, violations become empty, or a violation
     * class other than non-constant include-target denial appears.
     */
    public function testPinnedShortcodeBlockHasNonConstantIncludeViolation(): void
    {
        $repoRoot = \dirname(__DIR__, 3);
        $path = $repoRoot . '/packages/thallo-render/themes/default/templates/blocks/shortcode.twig';

        // Ratchet: file must exist at exact path
        self::assertFileExists($path);

        /** @var TemplateLinter $linter */
        $linter = $this->container()->get(TemplateLinter::class);
        $violations = $linter->lint((string) file_get_contents($path));

        // Ratchet: violations must remain non-empty (if clean, the pin has been removed)
        self::assertNotEmpty($violations, 'Pinned template blocks/shortcode.twig must have violations');

        // Ratchet: EVERY violation must be the non-constant include-target denial
        foreach ($violations as $violation) {
            self::assertStringContainsString(
                'include target must be a constant string.',
                $violation['message'],
                "blocks/shortcode.twig must fail ONLY for non-constant include denial, got: {$violation['message']}"
            );
        }
    }
}
