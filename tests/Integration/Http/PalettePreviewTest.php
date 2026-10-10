<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Http;

use Thallo\Contracts\Style\BrandSlot;
use Thallo\Core\Settings\BrandColors;
use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Delivery\PreviewSessionVerifier;
use Thallo\Contracts\Style\PaletteProvider;
use Thallo\Core\Content\Http\Controllers\PreviewController;
use Thallo\Core\Content\Http\DTOs\MintPreviewData;
use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\Style\RequestPalette;

/**
 * Custom palette spec §5.1: the Appearance preview carries the whole unsaved palette in its signed
 * token; the preview render paints it and applies the brand colours it configures; nothing else
 * sees it.
 */
final class PalettePreviewTest extends AppTestCase
{
    /** @param array<string, mixed> $body */
    private function mint(array $body): \Glueful\Http\Response
    {
        /** @var MintPreviewData $dto */
        $dto = (new RequestDataHydrator())->hydrate(MintPreviewData::class, $body);
        $controller = $this->container()->get(PreviewController::class);
        return $controller->mint($dto, Request::create('/'), 'entry0000001', 'en');
    }

    private function extension(): RenderContextExtension
    {
        return $this->container()->get(RenderContextExtension::class);
    }

    public function testAPreviewClaimCarriesTheWholePendingListInOrderAndReachesNothingElse(): void
    {
        $this->container()->get(GeneralSettings::class)->save([
            'theme_brand_colors' => BrandColors::encode([2 => new BrandSlot('Rose', '#c98a8a')], [], 1),
        ]);
        $this->container()->get(RequestPalette::class)->refresh();
        $res = $this->mint(['palette' => ['brands' => [
            ['id' => 5, 'name' => 'Sky', 'hex' => '#38BDF8'],
            ['id' => 2, 'name' => 'Rose', 'hex' => '#c98a8a'],
        ]]]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $token = (string) json_decode((string) $res->getContent(), true)['data']['token'];
        $session = $this->container()->get(PreviewSessionVerifier::class)->verify($token);
        self::assertNotNull($session);
        self::assertSame(['brands' => [
            ['id' => 5, 'name' => 'Sky', 'hex' => '#38bdf8'],
            ['id' => 2, 'name' => 'Rose', 'hex' => '#c98a8a'],
        ]], $session->palette);

        $preview = $this->container()->get(PaletteProvider::class)->preview((array) $session->palette);
        self::assertSame([5, 2], $preview->ids());

        $ext = $this->extension();
        $ext->resetPerRenderState();
        $ext->setThemeAppearanceOverride(null, null, null, $preview);
        $css = (string) $ext->themeColorsStyle();
        self::assertLessThan(strpos($css, '--brand-2:'), strpos($css, '--brand-5:'), 'in the pending order');
        self::assertFalse($ext->palette()->isUnavailable('color.brand-5'), 'the previewed colour applies');

        $ext->setThemeAppearanceOverride(null, null);   // the next, ordinary render
        self::assertStringNotContainsString('--brand-5', (string) $ext->themeColorsStyle());
        self::assertTrue($ext->palette()->isUnavailable('color.brand-5'), 'unsaved colours reach no other render');
    }

    public function testAClaimThatIsNotAListOfDistinctIdsIs422(): void
    {
        $cases = [
            ['1' => ['name' => 'Gold', 'hex' => '#8a6a2a']],
            [['id' => 0, 'name' => 'Gold', 'hex' => '#8a6a2a']],
            [['id' => 3, 'name' => 'A', 'hex' => '#111111'], ['id' => 3, 'name' => 'B', 'hex' => '#222222']],
        ];
        foreach ($cases as $brands) {
            $res = $this->mint(['palette' => ['brands' => $brands]]);
            self::assertSame(422, $res->getStatusCode(), (string) json_encode($brands));
        }
    }

    public function testAMalformedPaletteIs422NamingIt(): void
    {
        foreach (
            [
                ['brands' => ['1' => ['name' => 'Gold', 'hex' => 'red']]],
                ['dark_base' => 'purple'],
                ['neutral_custom' => ['bg' => '#fff']],
                ['brands' => ['4' => ['name' => 'X', 'hex' => '#000000']]],
            ] as $palette
        ) {
            $res = $this->mint(['palette' => $palette]);
            self::assertSame(422, $res->getStatusCode(), json_encode($palette));
            self::assertStringContainsString('palette', (string) $res->getContent());
        }
    }
}
