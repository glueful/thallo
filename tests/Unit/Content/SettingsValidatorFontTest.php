<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Content;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Style\StyleCapabilities;
use Thallo\Core\Content\Style\SettingsValidator;

/**
 * `typography.family` holds a typeface ID, validated by shape only: whether the family exists is the
 * renderer's question (a missing one inherits), so content survives a removed or imported family
 * (block typeface spec §1, §3.3; plan Task 3).
 */
final class SettingsValidatorFontTest extends TestCase
{
    /** @return array{0: array<string,mixed>, 1: array<string,string>} */
    private static function validate(mixed $family, ?StyleCapabilities $caps = null): array
    {
        return (new SettingsValidator())->validate(
            ['style' => ['typography' => ['family' => $family]]],
            $caps ?? StyleCapabilities::fromDeclaration(['typography']),
        );
    }

    public function testABuiltInIsValid(): void
    {
        [$clean, $errors] = self::validate(['type' => 'font', 'value' => 'serif']);
        self::assertSame([], $errors);
        self::assertSame(['style' => ['typography' => ['family' => ['type' => 'font', 'value' => 'serif']]]], $clean);
    }

    public function testALibraryIdIsValidWithoutExisting(): void
    {
        [$clean, $errors] = self::validate(['type' => 'font', 'value' => 'Ab3dE5fG7hJ9']);
        self::assertSame([], $errors);
        self::assertSame('Ab3dE5fG7hJ9', $clean['style']['typography']['family']['value']);
    }

    public function testResetIsValid(): void
    {
        [$clean, $errors] = self::validate(['type' => 'reset']);
        self::assertSame([], $errors);
        self::assertSame(['type' => 'reset'], $clean['style']['typography']['family']);
    }

    public function testWhatIsNotATypefaceIdIsRefused(): void
    {
        foreach (['inherit', 'reset', 'My Font', 'Ab3', 'Ab3dE5fG7hJ9x', '<b>'] as $value) {
            [, $errors] = self::validate(['type' => 'font', 'value' => $value]);
            self::assertSame(['settings.style.typography.family' => 'is not a typeface ID'], $errors, $value);
        }
    }

    public function testItIsNotResponsive(): void
    {
        [, $errors] = self::validate(['base' => ['type' => 'font', 'value' => 'serif']]);
        self::assertSame(['settings.style.typography.family' => 'is not responsive'], $errors);
    }

    public function testAnotherKindIsRefused(): void
    {
        [, $errors] = self::validate(['type' => 'token', 'value' => 'serif']);
        self::assertSame(['settings.style.typography.family' => 'expects a font'], $errors);
    }

    public function testABlockWithoutTypographyIsRefusedAsForOtherPaths(): void
    {
        $spacingOnly = StyleCapabilities::fromDeclaration(['spacing']);
        [, $errors] = self::validate(['type' => 'font', 'value' => 'serif'], $spacingOnly);
        self::assertSame(['settings.style.typography.family' => 'not styleable on this block'], $errors);
    }

    public function testAStyleClassMaySetATypeface(): void
    {
        // The style class editor validates a class's style against every capability.
        [$clean, $errors] = self::validate(['type' => 'font', 'value' => 'mono'], StyleCapabilities::all());
        self::assertSame([], $errors);
        self::assertSame('mono', $clean['style']['typography']['family']['value']);
    }
}
