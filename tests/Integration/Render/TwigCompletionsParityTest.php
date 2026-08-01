<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use PHPUnit\Framework\TestCase;
use Thallo\Render\Templates\TemplatePolicy;

/**
 * admin/src/pages/templates/components/twigCompletions.ts declares itself a MIRROR of
 * TemplatePolicy's FUNCTIONS/FILTERS/TESTS allowlists (see its header comment) so the
 * editor never suggests vocabulary the save-time linter would reject. That mirror has
 * drifted before (the TESTS array was missing 'true' and 'same as'). This is a plain
 * text-parsing test (no app bootstrap needed) — it fails LOUD, with the exact set
 * difference, the moment TemplatePolicy gains/loses an entry the TS file doesn't mirror.
 */
final class TwigCompletionsParityTest extends TestCase
{
    private function tsSource(): string
    {
        $path = dirname(__DIR__, 3) . '/admin/src/pages/templates/components/twigCompletions.ts';
        $src = file_get_contents($path);
        self::assertIsString($src, "Could not read {$path}");
        return $src;
    }

    /** @return list<string> */
    private function extractConstArray(string $src, string $constName): array
    {
        $pattern = '/const ' . preg_quote($constName, '/') . '\s*=\s*\[(.*?)\n\]/s';
        self::assertMatchesRegularExpression(
            $pattern,
            $src,
            "twigCompletions.ts has no `const {$constName} = [...]` array to parse.",
        );
        preg_match($pattern, $src, $m);
        preg_match_all("/'([^']*)'/", $m[1], $strings);
        return $strings[1];
    }

    private function assertMirrorsPolicy(string $constName, array $policyValues): void
    {
        $tsValues = $this->extractConstArray($this->tsSource(), $constName);
        self::assertEqualsCanonicalizing(
            $policyValues,
            $tsValues,
            "admin/src/pages/templates/components/twigCompletions.ts's {$constName} array has "
            . "drifted from Thallo\\Render\\Templates\\TemplatePolicy::{$constName}. "
            . 'Sync twigCompletions.ts (it declares itself a mirror of TemplatePolicy) so the '
            . 'editor never suggests — or fails to suggest — vocabulary the save-time linter '
            . 'actually allows.',
        );
    }

    public function testFunctionsMirrorsTemplatePolicy(): void
    {
        $this->assertMirrorsPolicy('FUNCTIONS', TemplatePolicy::FUNCTIONS);
    }

    public function testFiltersMirrorsTemplatePolicy(): void
    {
        $this->assertMirrorsPolicy('FILTERS', TemplatePolicy::FILTERS);
    }

    public function testTestsMirrorsTemplatePolicy(): void
    {
        $this->assertMirrorsPolicy('TESTS', TemplatePolicy::TESTS);
    }
}
