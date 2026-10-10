<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Content\Palette;

use Thallo\Contracts\Style\BrandSlot;
use Thallo\Contracts\Style\Palette;
use Thallo\Core\Content\Palette\ColorTokenWalker;
use Thallo\Core\Content\Palette\PaletteJob;
use Thallo\Core\Content\Palette\PaletteNormalizer;
use Thallo\Core\Content\Palette\PaletteRefusal;
use Thallo\Core\Content\Palette\PaletteSnapshot;
use Thallo\Core\Tests\Support\AppTestCase;

/** Custom palette spec §4.5. */
final class PaletteNormalizerTest extends AppTestCase
{
    private const K = ColorTokenWalker::KIND_CLASS;

    private static function cls(string $text, ?string $surface = null): array
    {
        $style = ['colors' => ['text' => ['type' => 'token', 'value' => $text]]];
        if ($surface !== null) {
            $style['colors']['surface'] = ['type' => 'token', 'value' => $surface];
        }
        return ['style' => $style];
    }

    private static function snap(array $brands, array $jobs = []): PaletteSnapshot
    {
        return new PaletteSnapshot(7, new Palette(brands: $brands), $jobs);
    }

    private static function job(int $slot, string $to, ?string $contrastTo): PaletteJob
    {
        return new PaletteJob('job000000001', $slot, $to, $contrastTo, 'running', 0, 0, 0, 0, []);
    }

    private function n(): PaletteNormalizer
    {
        return $this->container()->get(PaletteNormalizer::class);
    }

    public function testAnActiveJobsSourceIsMappedIncludingItsContrastToken(): void
    {
        $gold = [1 => new BrandSlot('Gold', '#8a6a2a')];
        $snap = self::snap($gold, [self::job(1, 'color.accent', 'color.accent-contrast')]);
        $out = $this->n()->normalize(self::K, self::cls('color.brand-1', 'color.brand-1-contrast'), $snap, []);
        self::assertSame('color.accent', $out->doc['style']['colors']['text']['value']);
        self::assertSame('color.accent-contrast', $out->doc['style']['colors']['surface']['value']);
        self::assertTrue($out->originalHadBrand);
        self::assertFalse($out->normalizedHasBrand);
        self::assertTrue($out->fenced(), 'fenced because the ORIGINAL named a brand token');
    }

    public function testAFreshReferenceToAnUnconfiguredSlotIsRefusedNamingIt(): void
    {
        try {
            $this->n()->normalize(self::K, self::cls('color.brand-2'), self::snap([]), []);
            self::fail('expected a refusal');
        } catch (PaletteRefusal $e) {
            self::assertSame(['style.colors.text' => "Brand 2 isn't in the palette"], $e->errors);
        }
    }

    public function testAReferenceTheBasisAlreadyHoldsAtThatLocationIsKept(): void
    {
        $basis = $this->n()->basisOf(self::K, null, self::cls('color.brand-2'));
        $out = $this->n()->normalize(self::K, self::cls('color.brand-2'), self::snap([]), $basis);
        self::assertSame('color.brand-2', $out->doc['style']['colors']['text']['value']);
    }

    public function testTheBasisIsPerLocationNotPerDocument(): void
    {
        $basis = $this->n()->basisOf(self::K, null, self::cls('color.brand-2')); // text holds it
        $this->expectException(PaletteRefusal::class);
        // moved to surface
        $this->n()->normalize(self::K, self::cls('color.accent', 'color.brand-2'), self::snap([]), $basis);
    }

    public function testAConfiguredSlotAndOrdinaryTokensPassUnchanged(): void
    {
        $snap = self::snap([3 => new BrandSlot('Ink', '#111111')]);
        $doc = self::cls('color.brand-3', 'color.surface');
        $out = $this->n()->normalize(self::K, $doc, $snap, []);
        self::assertSame($doc, $out->doc);
        $plain = $this->n()->normalize(self::K, self::cls('color.text'), $snap, []);
        self::assertFalse($plain->fenced());
    }

    public function testAJobsSourceWinsOverTheBasisDuringAReplacement(): void
    {
        $gold = [1 => new BrandSlot('Gold', '#8a6a2a')];
        $snap = self::snap($gold, [self::job(1, 'color.brand-2', 'color.brand-2-contrast')]);
        $basis = $this->n()->basisOf(self::K, null, self::cls('color.brand-1'));
        $out = $this->n()->normalize(self::K, self::cls('color.brand-1'), $snap, $basis);
        self::assertSame('color.brand-2', $out->doc['style']['colors']['text']['value']);
        self::assertSame(
            [['location' => 'style.colors.text', 'from' => 'color.brand-1', 'to' => 'color.brand-2']],
            $out->rewrites,
        );
    }

    public function testACurrentAccentDoesNotHideAHistoricalBrandAtTheSameLocation(): void
    {
        // the current draft holds Accent where the restored version held the now-cleared Brand 1
        $basis = $this->n()->basisOf(self::K, null, self::cls('color.accent'), self::cls('color.brand-1'));
        self::assertSame(['style.colors.text' => ['color.accent', 'color.brand-1']], $basis);
        $out = $this->n()->normalize(self::K, self::cls('color.brand-1'), self::snap([]), $basis);
        self::assertSame('color.brand-1', $out->doc['style']['colors']['text']['value']);
    }

    public function testAJobWithoutAContrastMappingRefusesAContrastReference(): void
    {
        $snap = self::snap([1 => new BrandSlot('Gold', '#8a6a2a')], [self::job(1, 'color.surface', null)]);
        try {
            $this->n()->normalize(self::K, self::cls('color.brand-1', 'color.brand-1-contrast'), $snap, []);
            self::fail('a contrast reference with no mapping is refused');
        } catch (PaletteRefusal $e) {
            self::assertSame(
                ['style.colors.surface' => 'Text on Gold has no replacement in the running replacement'],
                $e->errors,
            );
        }
    }

    public function testARemovedColourIsRefusedByTheNameItHadAndANeverIssuedOneByItsNumber(): void
    {
        $snap = new PaletteSnapshot(7, new Palette(removed: [3 => 'Teal']), []);
        $expected = [
            'color.brand-3' => "Teal isn't in the palette",
            'color.brand-12' => "Brand 12 isn't in the palette",
        ];
        foreach ($expected as $token => $message) {
            try {
                $this->n()->normalize(self::K, self::cls($token), $snap, []);
                self::fail("expected a refusal for {$token}");
            } catch (PaletteRefusal $e) {
                self::assertSame(['style.colors.text' => $message], $e->errors);
            }
        }
        $basis = $this->n()->basisOf(self::K, null, self::cls('color.brand-12'));
        $out = $this->n()->normalize(self::K, self::cls('color.brand-12'), $snap, $basis);
        self::assertSame('color.brand-12', $out->doc['style']['colors']['text']['value']);
    }
}
