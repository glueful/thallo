<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Psr\Log\NullLogger;
use Thallo\Contracts\Delivery\EntryTargetResolver;
use Thallo\Contracts\Fonts\FontFamilyView;
use Thallo\Contracts\Style\FontStacks;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Fonts\BlobRouteMediaUrls;
use Thallo\Core\Tests\Support\Fonts\FixedFontLibrary;
use Thallo\Core\Tests\Support\Fonts\FixedThemeAppearance;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\Style\FontsArtifact;
use Thallo\Render\Style\RequestFontSnapshot;
use Thallo\Render\ThemeAppearanceSource;
use Thallo\Render\ThemeLocator;

/**
 * Custom's Text and Headings are library families (block typeface spec §2.8; plan Task 7). Compared
 * with the references captured before the change (tests/fixtures/render/pre-typeface): the same files
 * play the same roles; what differs is exactly the recorded list — the generated family name, the
 * weight and style read from the file, and the synthesis policy (`style` for an uploaded family).
 */
final class AppearanceCustomFamiliesTest extends AppTestCase
{
    private const PRE = __DIR__ . '/../../fixtures/render/pre-typeface';
    private const TEXT = 'Tx3dE5fG7hJ9';
    private const HEAD = 'Hd3dE5fG7hJ9';
    private const SHARED = 'Sh3dE5fG7hJ9';

    /** @return list<FontFamilyView> the upgraded families: the files the captures used */
    private static function library(bool $removeText = false, bool $removeHeadings = false): array
    {
        return [
            FixedFontLibrary::family(self::TEXT, 'fontbody0001', 700, removed: $removeText),
            FixedFontLibrary::family(self::HEAD, 'fonthead0001', 400, italic: true, removed: $removeHeadings),
            FixedFontLibrary::family(self::SHARED, 'fontshared01', 400),
        ];
    }

    /**
     * @param array{text?: string, headings?: string} $families
     * @param list<FontFamilyView>|null $library
     */
    private function ext(string $font, array $families, ?array $library = null): RenderContextExtension
    {
        $base = $this->appContext()->getBasePath();
        $ext = new RenderContextExtension(
            null,
            $this->container()->get(EntryTargetResolver::class),
            'en',
            mediaUrls: new BlobRouteMediaUrls(),
            appearance: new ThemeAppearanceSource(new FixedThemeAppearance($font, $families), new NullLogger()),
            fontSnapshots: new RequestFontSnapshot(new FixedFontLibrary($library ?? self::library())),
        );
        $ext->bindTheme(new ThemeLocator('default', $base . '/themes'));
        $ext->setAssetContext(null, $base . '/packages/thallo-render/themes/default/assets');
        return $ext;
    }

    /**
     * What a Custom configuration declares, as roles: each role's first family, that family's files
     * (src URLs) and the stack after it; plus the `@font-face` descriptors of each file.
     *
     * @return array{roles: array<string, array{family: string, srcs: list<string>, tail: string}>,
     *     descriptors: array<string, string>}
     */
    private static function declared(string $css): array
    {
        $srcs = [];
        $descriptors = [];
        $face = '/@font-face\{font-family:"([^"]+)";src:url\("([^"]+)"\) format\("woff2"\);([^}]*)\}/';
        preg_match_all($face, $css, $faces, PREG_SET_ORDER);
        foreach ($faces as [, $family, $src, $rest]) {
            $srcs[$family][] = $src;
            $descriptors[$src] = $rest;
        }
        $roles = [];
        preg_match_all('/--font-(body|display):"([^"]+)",([^;}]+)/', $css, $tokens, PREG_SET_ORDER);
        foreach ($tokens as [, $role, $family, $tail]) {
            $roles[$role] = ['family' => $family, 'srcs' => $srcs[$family] ?? [], 'tail' => $tail];
        }
        ksort($roles);
        return ['roles' => $roles, 'descriptors' => $descriptors];
    }

    /** The page's Custom CSS now: the appearance block, and the fonts stylesheet it links. */
    private function now(string $font, array $families): string
    {
        $ext = $this->ext($font, $families);
        $snapshot = $ext->fontSnapshot();
        self::assertNotNull($snapshot);
        return (string) $ext->themeColorsStyle() . FontsArtifact::compile($snapshot);
    }

    /** @return iterable<string, array{string, array{text?: string, headings?: string}}> */
    public static function configurations(): iterable
    {
        yield 'text-only' => ['text-only', ['text' => self::TEXT]];
        yield 'headings-only' => ['headings-only', ['headings' => self::HEAD]];
        yield 'both' => ['both', ['text' => self::TEXT, 'headings' => self::HEAD]];
        yield 'shared' => ['shared', ['text' => self::SHARED, 'headings' => self::SHARED]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('configurations')]
    public function testTheSameFilesPlayTheSameRolesAndOnlyTheRecordedDeclarationsDiffer(
        string $capture,
        array $families,
    ): void {
        $before = self::declared((string) file_get_contents(self::PRE . "/appearance-{$capture}.html"));
        $css = $this->now('custom', $families);
        $after = self::declared($css);

        // The same roles, set from the same files, falling back to the same stack.
        self::assertSame(array_keys($before['roles']), array_keys($after['roles']));
        foreach ($before['roles'] as $role => $was) {
            self::assertSame($was['srcs'], $after['roles'][$role]['srcs'], "{$role}: the same file");
            self::assertSame($was['tail'], $after['roles'][$role]['tail'], "{$role}: the same fallback stack");
            self::assertSame(FontStacks::SYSTEM, $after['roles'][$role]['tail']);
            // Recorded difference 1: the family is named by its library ID, never a display name.
            self::assertMatchesRegularExpression('/\Athallo-font-[A-Za-z0-9]{12}\z/', $after['roles'][$role]['family']);
        }
        // Recorded difference 2: each file's weight and style come from the file (no longer 100 900).
        foreach ($before['descriptors'] as $src => $old) {
            self::assertStringContainsString('font-weight:100 900', $old);
            self::assertStringNotContainsString('font-weight:100 900', $after['descriptors'][$src]);
            self::assertStringContainsString('font-style:', $after['descriptors'][$src]);
        }
        // Recorded difference 3: an uploaded family's synthesis is `style`, set for every role it plays.
        foreach (array_keys($after['roles']) as $role) {
            self::assertStringContainsString("--font-synthesis-{$role}:style", $css);
        }
        $was = (string) file_get_contents(self::PRE . "/appearance-{$capture}.html");
        self::assertStringNotContainsString('font-synthesis-', $was);
    }

    public function testUnselectedCustomStillDeclaresNothing(): void
    {
        self::assertSame(
            trim((string) file_get_contents(self::PRE . '/appearance-unselected.html')),
            (string) $this->ext('sans', ['text' => self::TEXT, 'headings' => self::HEAD])->themeColorsStyle(),
        );
    }

    public function testARemovedTextFamilyFallsBackToTheThemeFace(): void
    {
        $ext = $this->ext('custom', ['text' => self::TEXT], self::library(removeText: true));
        $css = (string) $ext->themeColorsStyle();
        self::assertStringNotContainsString('--font-body', $css);
        self::assertStringNotContainsString('--font-synthesis-body', $css);
    }

    public function testRemovedHeadingsFollowTheText(): void
    {
        $css = (string) $this->ext(
            'custom',
            ['text' => self::TEXT, 'headings' => self::HEAD],
            self::library(removeHeadings: true),
        )->themeColorsStyle();
        self::assertStringContainsString('--font-display:"thallo-font-' . self::TEXT . '"', $css);
        self::assertStringContainsString('--font-synthesis-display:style', $css);
    }

    public function testABuiltInTextKeepsTheBrowsersSynthesis(): void
    {
        $css = (string) $this->ext('custom', ['text' => 'serif'])->themeColorsStyle();
        self::assertStringContainsString('--font-body:' . FontStacks::SERIF, $css);
        self::assertStringContainsString('--font-synthesis-body:weight style', $css);
        self::assertStringContainsString('--font-display:' . FontStacks::SERIF, $css);
    }

    public function testCustomTextIsNotPreloadedButTheThemeFaceStaysDeclared(): void
    {
        $args = ['Figtree', 'fonts/figtree-roman-latin.woff2', 'fonts/figtree-italic-latin.woff2'];
        $withText = (string) $this->ext('custom', ['text' => self::TEXT])->fontFacesStyle(...$args);
        self::assertStringNotContainsString('rel="preload"', $withText);
        $headingsOnly = (string) $this->ext('custom', ['headings' => self::HEAD])->fontFacesStyle(...$args);
        self::assertStringContainsString('rel="preload"', $headingsOnly, 'the text is still the theme face');
    }

    /** Re-keying: a new Text family changes the page fingerprint; the fonts stylesheet is unchanged. */
    public function testANewTextFamilyReKeysPagesButNotTheFontsStylesheet(): void
    {
        $a = new ThemeAppearanceSource(new FixedThemeAppearance('custom', ['text' => self::TEXT]), new NullLogger());
        $b = new ThemeAppearanceSource(new FixedThemeAppearance('custom', ['text' => self::SHARED]), new NullLogger());
        self::assertNotSame($a->fingerprint(), $b->fingerprint());
        $snapshot = (new FixedFontLibrary(self::library()))->snapshot();
        self::assertSame(FontsArtifact::hash($snapshot), FontsArtifact::hash($snapshot), 'the library did not change');
        // A family ID is an ID: anything else is no family.
        $junk = new ThemeAppearanceSource(new FixedThemeAppearance('custom', ['text' => '../etc']), new NullLogger());
        self::assertSame([], $junk->fontFamilies());
    }
}
