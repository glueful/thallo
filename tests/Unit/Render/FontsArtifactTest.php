<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Fonts\FontFamilyView;
use Thallo\Contracts\Style\FontStacks;
use Thallo\Core\Content\Fonts\FontLibrarySnapshot;
use Thallo\Render\Style\FontsArtifact;
use Thallo\Render\Style\FontsArtifacts;

/**
 * The workspace's fonts stylesheet (block typeface spec §3.4; plan Task 6): an `@font-face` per face
 * of every current uploaded family and its utility in `@layer settings`, named by the family's ID —
 * never its display name — served immutably by the hash of its own bytes.
 */
final class FontsArtifactTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/thallo-fonts-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->dir . '/*') ?: [] as $sub) {
            rmdir($sub);
        }
        @rmdir($this->dir);
    }

    /**
     * @param list<array<string,mixed>> $faces
     */
    private static function family(
        string $id,
        array $faces,
        string $name = 'Brand',
        bool $removed = false,
        string $fallback = 'serif',
    ): FontFamilyView {
        $full = array_map(static fn (array $f): array => $f + [
            'blob_uuid' => 'blob00000001', 'url' => '/api/v1/blobs/blob00000001', 'weight_min' => 400,
            'weight_max' => 400, 'italic' => false, 'variable' => false, 'unknown' => false,
        ], $faces);
        return new FontFamilyView($id, $name, $fallback, $removed, $full);
    }

    /** @param list<FontFamilyView> $families */
    private static function snapshot(array $families, int $generation = 1): FontLibrarySnapshot
    {
        return new FontLibrarySnapshot($generation, $families);
    }

    public function testEachFaceIsDeclaredAndTheUtilityNamesTheIdAndTheFallbackStack(): void
    {
        $css = FontsArtifact::compile(self::snapshot([self::family('Ab3dE5fG7hJ9', [
            ['url' => '/api/v1/blobs/fontbold0001', 'weight_min' => 700, 'weight_max' => 700],
            ['url' => '/api/v1/blobs/fontital0001', 'weight_min' => 300, 'weight_max' => 900, 'italic' => true,
                'variable' => true],
        ])]));
        self::assertSame(
            '@font-face{font-family:"thallo-font-Ab3dE5fG7hJ9";src:url("/api/v1/blobs/fontbold0001") format("woff2");'
            . "font-weight:700;font-style:normal;font-display:swap}\n"
            . '@font-face{font-family:"thallo-font-Ab3dE5fG7hJ9";src:url("/api/v1/blobs/fontital0001") format("woff2");'
            . "font-weight:300 900;font-style:italic;font-display:swap}\n"
            . '@layer settings{.t-font-Ab3dE5fG7hJ9{font-family:"thallo-font-Ab3dE5fG7hJ9",' . FontStacks::SERIF
            . ";font-synthesis:style}}\n",
            $css,
        );
    }

    public function testAnUnknownFaceKeepsTheCompatibilityDeclaration(): void
    {
        $css = FontsArtifact::compile(self::snapshot([self::family('Ab3dE5fG7hJ9', [
            ['weight_min' => 100, 'weight_max' => 900, 'unknown' => true, 'italic' => true],
        ])]));
        self::assertStringContainsString('font-weight:100 900;font-style:normal;', $css);
    }

    public function testAFaceTheMediaLibraryDoesNotServeIsNotDeclared(): void
    {
        $css = FontsArtifact::compile(self::snapshot([self::family('Ab3dE5fG7hJ9', [['url' => '']])]));
        self::assertStringNotContainsString('@font-face', $css);
        self::assertStringContainsString('.t-font-Ab3dE5fG7hJ9{', $css, 'the utility still falls back');
    }

    public function testADisplayNameNeverReachesCss(): void
    {
        $css = FontsArtifact::compile(self::snapshot([self::family('Ab3dE5fG7hJ9', [[]], name: 'Brand"}</style><b>')]));
        self::assertStringNotContainsString('Brand', $css);
        self::assertStringNotContainsString('</style>', $css);
        self::assertStringNotContainsString('<b>', $css);
    }

    public function testAHostileUrlIsEscaped(): void
    {
        $css = FontsArtifact::compile(self::snapshot([self::family('Ab3dE5fG7hJ9', [['url' => '/x") }</style>']])]));
        self::assertStringNotContainsString('</style>', $css);
        self::assertSame(1, preg_match('/url\("([^"]*)"\) format/', $css, $m), 'the URL string is not broken out of');
        self::assertSame('/x\\22 ) }\\3c /style\\3e ', $m[1]);
    }

    public function testRemovedFamiliesAreNotDeclared(): void
    {
        $css = FontsArtifact::compile(self::snapshot([
            self::family('Ab3dE5fG7hJ9', [[]]),
            self::family('Rm3dE5fG7hJ9', [[]], removed: true),
        ]));
        self::assertStringContainsString('thallo-font-Ab3dE5fG7hJ9', $css);
        self::assertStringNotContainsString('Rm3dE5fG7hJ9', $css);
    }

    public function testAnEmptyLibraryIsNoStylesheet(): void
    {
        self::assertSame('', FontsArtifact::compile(self::snapshot([])));
        $removedOnly = self::snapshot([self::family('Rm3dE5fG7hJ9', [[]], removed: true)]);
        self::assertSame('', FontsArtifact::compile($removedOnly));
    }

    public function testTheHashIsOverTheBytes(): void
    {
        $a = self::snapshot([self::family('Ab3dE5fG7hJ9', [[]])], 1);
        $sameBytes = self::snapshot([self::family('Ab3dE5fG7hJ9', [[]], name: 'Renamed')], 2);
        $other = self::snapshot([self::family('Ab3dE5fG7hJ9', [[]], fallback: 'monospace')], 3);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{16}\z/', FontsArtifact::hash($a));
        self::assertSame(FontsArtifact::hash($a), FontsArtifact::hash($sameBytes), 'a rename changes no CSS');
        self::assertNotSame(FontsArtifact::hash($a), FontsArtifact::hash($other));
    }

    public function testPublishBeforeReturnAndAnOldHashStillReads(): void
    {
        $store = new FontsArtifacts($this->dir, static fn (): string => 'site');
        $first = $store->forSnapshot(self::snapshot([self::family('Ab3dE5fG7hJ9', [[]])]));
        self::assertFileExists($this->dir . '/site/' . FontsArtifacts::fileName($first['hash']));
        $second = $store->forSnapshot(self::snapshot([self::family('Ab3dE5fG7hJ9', [[]], fallback: 'cursive')], 2));
        self::assertNotSame($first['hash'], $second['hash']);
        $fresh = new FontsArtifacts($this->dir, static fn (): string => 'site');
        self::assertSame($first['css'], $fresh->read($first['hash']));
        self::assertSame($second['css'], $fresh->read($second['hash']));
        self::assertNull($fresh->read('0000000000000000'));
    }

    public function testAWorkspaceReadsOnlyItsOwnArtifacts(): void
    {
        $scope = 'tenant-a';
        $store = new FontsArtifacts($this->dir, static function () use (&$scope): string {
            return $scope;
        });
        $artifact = $store->forSnapshot(self::snapshot([self::family('Ab3dE5fG7hJ9', [[]])]));
        $scope = 'tenant-b';
        self::assertNull((new FontsArtifacts($this->dir, static fn (): string => 'tenant-b'))->read($artifact['hash']));
        $own = new FontsArtifacts($this->dir, static fn (): string => 'tenant-a');
        self::assertNotNull($own->read($artifact['hash']));
    }

    public function testRetentionKeepsTheNewestThreeAndAnythingYoungerThanADay(): void
    {
        $store = new FontsArtifacts($this->dir, static fn (): string => 'site');
        $store->forSnapshot(self::snapshot([self::family('Ab3dE5fG7hJ9', [[]])]));
        $old = time() - 3 * 86400;
        foreach (['aaaaaaaaaaaaaaa1', 'aaaaaaaaaaaaaaa2', 'aaaaaaaaaaaaaaa3', 'aaaaaaaaaaaaaaa4'] as $i => $hash) {
            $file = $this->dir . '/site/' . FontsArtifacts::fileName($hash);
            file_put_contents($file, '/* old */');
            touch($file, $old + $i);   // aaa4 is the newest of the old ones
        }
        $young = $this->dir . '/site/' . FontsArtifacts::fileName('bbbbbbbbbbbbbbb1');
        file_put_contents($young, '/* young */');
        touch($young, time() - 3600);
        $current = $store->forSnapshot(self::snapshot([self::family('Ab3dE5fG7hJ9', [[]], fallback: 'monospace')], 2));

        $left = array_map('basename', glob($this->dir . '/site/fonts-*.css') ?: []);
        sort($left);
        $expected = [
            FontsArtifacts::fileName($current['hash']),
            FontsArtifacts::fileName('bbbbbbbbbbbbbbb1'),
        ];
        // The newest three: the two just published (younger than a day anyway) and the young file;
        // every old file goes.
        foreach ($expected as $file) {
            self::assertContains($file, $left);
        }
        foreach (['aaaaaaaaaaaaaaa1', 'aaaaaaaaaaaaaaa2', 'aaaaaaaaaaaaaaa3', 'aaaaaaaaaaaaaaa4'] as $hash) {
            self::assertNotContains(FontsArtifacts::fileName($hash), $left);
        }
    }

    public function testFileNames(): void
    {
        self::assertSame('fonts-0123456789abcdef.css', FontsArtifacts::fileName('0123456789abcdef'));
        self::assertSame('0123456789abcdef', FontsArtifacts::hashFromFileName('fonts-0123456789abcdef.css'));
        self::assertNull(FontsArtifacts::hashFromFileName('settings-0123456789abcdef.css'));
        self::assertNull(FontsArtifacts::hashFromFileName('fonts-0123.css'));
    }
}
