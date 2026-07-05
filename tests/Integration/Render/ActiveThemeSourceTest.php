<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use Glueful\Lemma\Contracts\Delivery\PreviewThemeValidator;
use Glueful\Lemma\Contracts\Settings\ThemeSettingProvider;
use Glueful\Lemma\Render\ActiveThemeSource;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * The theme resolution ladder (theme-setting spec §2): stored override wins
 * when valid; a stale override logs + falls back (never throws); no provider
 * bound = env verbatim; memoized per instance.
 */
final class ActiveThemeSourceTest extends TestCase
{
    /** @param list<string> $valid */
    private function validator(array $valid): PreviewThemeValidator
    {
        return new class ($valid) implements PreviewThemeValidator {
            /** @param list<string> $valid */
            public function __construct(private readonly array $valid)
            {
            }

            public function isValidTheme(string $name): bool
            {
                return in_array($name, $this->valid, true);
            }
        };
    }

    private function provider(?string $override): ThemeSettingProvider
    {
        return new class ($override) implements ThemeSettingProvider {
            public function __construct(private readonly ?string $override)
            {
            }

            public function themeOverride(): ?string
            {
                return $this->override;
            }
        };
    }

    public function testValidOverrideWinsOverEnv(): void
    {
        $source = new ActiveThemeSource($this->provider('corporate'), $this->validator(['corporate']), 'default');
        self::assertSame('corporate', $source->name());
    }

    public function testStaleOverrideLogsAndFallsBack(): void
    {
        $logged = [];
        $logger = new class ($logged) extends AbstractLogger {
            /** @param list<string> $logged */
            public function __construct(private array &$logged)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged[] = (string) $message;
            }
        };

        $source = new ActiveThemeSource($this->provider('gone'), $this->validator([]), 'default', $logger);
        self::assertSame('default', $source->name()); // no throw — never a 500 from a stale row
        self::assertCount(1, $logged);
        self::assertStringContainsString("'gone'", $logged[0]);
    }

    public function testNoProviderMeansEnvVerbatim(): void
    {
        $source = new ActiveThemeSource(null, $this->validator([]), 'envtheme');
        self::assertSame('envtheme', $source->name());
    }

    public function testNullAndEmptyOverridesFallThrough(): void
    {
        self::assertSame(
            'default',
            (new ActiveThemeSource($this->provider(null), $this->validator(['x']), 'default'))->name(),
        );
        self::assertSame(
            'default',
            (new ActiveThemeSource($this->provider(''), $this->validator(['x']), 'default'))->name(),
        );
    }

    public function testMemoizesWithinAnInstance(): void
    {
        $calls = 0;
        $provider = new class ($calls) implements ThemeSettingProvider {
            public function __construct(private int &$calls)
            {
            }

            public function themeOverride(): ?string
            {
                $this->calls++;
                return 'corporate';
            }
        };
        $source = new ActiveThemeSource($provider, $this->validator(['corporate']), 'default');
        $source->name();
        $source->name();
        self::assertSame(1, $calls);
    }
}
