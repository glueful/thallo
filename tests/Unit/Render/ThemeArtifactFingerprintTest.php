<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Thallo\Core\Tests\Support\ThemeFixture;
use Thallo\Render\Contribution\RenderContributionRegistry;
use Thallo\Render\Contribution\StylesheetContributor;
use Thallo\Render\Style\ThemeStylesheetArtifacts;
use Thallo\Render\ThemeAppearanceSource;
use Thallo\Render\ThemeLocator;

/**
 * Visual builder spec §2.4: editing a manifest stylesheet or a contributed package stylesheet
 * changes the theme artifact hash and the render cache's appearance fingerprint even when the
 * vocabulary is unchanged.
 */
final class ThemeArtifactFingerprintTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/thallo-artifact-fp-' . uniqid('', true);
        mkdir($this->tmp . '/themes', 0755, true);
        mkdir($this->tmp . '/cache', 0755, true);
        ThemeFixture::write($this->tmp . '/themes/mine', 'mine');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmp));
    }

    private function fingerprint(?StylesheetContributor $contributor = null): string
    {
        $registry = new RenderContributionRegistry();
        if ($contributor !== null) {
            $registry->registerStylesheets($contributor);
        }
        $artifacts = new ThemeStylesheetArtifacts($this->tmp . '/cache', $registry);
        $locator = new ThemeLocator('mine', $this->tmp . '/themes');
        $source = new ThemeAppearanceSource(
            null,
            new NullLogger(),
            static fn (): string => $artifacts->forTheme($locator)->hash,
        );
        return $source->fingerprint();
    }

    public function testAThemeStylesheetEditChangesTheFingerprintWithTheVocabularyUnchanged(): void
    {
        $before = $this->fingerprint();
        self::assertMatchesRegularExpression('/\Ablue-slate-round-sans-plain-t[0-9a-f]{8}\z/', $before);

        file_put_contents($this->tmp . '/themes/mine/assets/site.css', ':root { --fixture: edited; }');
        self::assertNotSame($before, $this->fingerprint());
    }

    public function testAContributedStylesheetChangesTheFingerprint(): void
    {
        file_put_contents($this->tmp . '/pack.css', '.pack { color: red; }');
        $contributor = new class ($this->tmp . '/pack.css') implements StylesheetContributor {
            public function __construct(private string $file)
            {
            }
            public function contributorId(): string
            {
                return 'test.pack';
            }
            public function priority(): int
            {
                return 0;
            }
            public function stylesheets(): array
            {
                return [$this->file];
            }
        };
        $without = $this->fingerprint();
        $with = $this->fingerprint($contributor);
        self::assertNotSame($without, $with);

        file_put_contents($this->tmp . '/pack.css', '.pack { color: blue; }');
        self::assertNotSame($with, $this->fingerprint($contributor), 'a package stylesheet edit re-keys');
    }
}
