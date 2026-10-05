<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Http;

use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Delivery\PreviewSessionVerifier;
use Thallo\Core\Content\Http\Controllers\PreviewController;
use Thallo\Core\Content\Http\DTOs\MintPreviewData;
use Thallo\Core\Http\Controllers\GeneralSettingsController;
use Thallo\Core\Http\DTOs\UpdateGeneralSettingsData;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\Style\RequestFontSnapshot;

/**
 * Appearance's Text and Headings families from the request to the page (block typeface spec §2.8;
 * plan Task 7): a save takes a built-in or a current library family and refuses anything else; a
 * preview carries pending families (or `none`, a saved one taken off) and renders their tokens.
 */
final class AppearanceFamiliesPreviewTest extends AppTestCase
{
    private const UPLOADED = 'Ab3dE5fG7hJ9';

    protected function setUp(): void
    {
        parent::setUp();
        $now = gmdate('Y-m-d H:i:s');
        $this->connection()->table('font_families')->insert([
            'id' => self::UPLOADED, 'name' => 'Brand', 'fallback' => 'serif',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->connection()->table('font_families')->insert([
            'id' => 'Rm3dE5fG7hJ9', 'name' => 'Gone', 'fallback' => 'serif', 'removed_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->container()->get(RequestFontSnapshot::class)->refresh();
    }

    /** @param array<string, mixed> $body */
    private function save(array $body): \Glueful\Http\Response
    {
        /** @var UpdateGeneralSettingsData $dto */
        $dto = (new RequestDataHydrator())->hydrate(UpdateGeneralSettingsData::class, $body);
        return $this->container()->get(GeneralSettingsController::class)->update($dto);
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        $res = $this->container()->get(GeneralSettingsController::class)->show();
        return json_decode((string) $res->getContent(), true)['data']['settings'];
    }

    /** @param array<string, mixed> $body */
    private function mint(array $body): \Glueful\Http\Response
    {
        /** @var MintPreviewData $dto */
        $dto = (new RequestDataHydrator())->hydrate(MintPreviewData::class, $body);
        $controller = $this->container()->get(PreviewController::class);
        return $controller->mint($dto, Request::create('/'), 'entry0000001', 'en');
    }

    public function testASavedFamilyReadsBack(): void
    {
        $res = $this->save([
            'theme_font' => 'custom',
            'theme_font_text_family' => self::UPLOADED,
            'theme_font_headings_family' => 'serif',
        ]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $settings = $this->settings();
        $saved = [$settings['theme_font_text_family'], $settings['theme_font_headings_family']];
        self::assertSame([self::UPLOADED, 'serif'], $saved);
        self::assertArrayNotHasKey('theme_font_body', $settings);
    }

    public function testAnExplicitClearIsStored(): void
    {
        $this->save(['theme_font_text_family' => self::UPLOADED]);
        $this->save(['theme_font_text_family' => '']);
        self::assertSame('', $this->settings()['theme_font_text_family']);
    }

    public function testAnythingButABuiltInOrACurrentFamilyIsRefused(): void
    {
        foreach (['Rm3dE5fG7hJ9', 'Zz9yX8wV7uT6', 'not an id', 'inherit'] as $id) {
            $res = $this->save(['theme_font_text_family' => $id]);
            self::assertSame(422, $res->getStatusCode(), $id);
            self::assertStringContainsString('unknown typeface', (string) $res->getContent(), $id);
        }
    }

    public function testAPreviewCarriesPendingFamiliesAndRendersTheirTokens(): void
    {
        $res = $this->mint([
            'font' => 'custom',
            'font_text_family' => self::UPLOADED,
            'font_headings_family' => 'none',
        ]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $token = (string) json_decode((string) $res->getContent(), true)['data']['token'];
        $session = $this->container()->get(PreviewSessionVerifier::class)->verify($token);
        self::assertNotNull($session);
        self::assertSame(
            ['font' => 'custom', 'font_text_family' => self::UPLOADED, 'font_headings_family' => 'none'],
            array_intersect_key(
                $session->design ?? [],
                array_flip(['font', 'font_text_family', 'font_headings_family']),
            ),
        );

        $ext = $this->container()->get(RenderContextExtension::class);
        $ext->resetPerRenderState();
        $ext->setThemeAppearanceOverride(null, null, $session->design);
        $css = (string) $ext->themeColorsStyle();
        self::assertStringContainsString('--font-body:"thallo-font-' . self::UPLOADED . '"', $css);
        self::assertStringContainsString('--font-synthesis-body:style', $css);
        $display = '--font-display:"thallo-font-' . self::UPLOADED . '"';
        self::assertStringContainsString($display, $css, 'none follows the text');
        $ext->setThemeAppearanceOverride(null, null, null);
    }

    public function testNonePreviewsTheFallbackForASavedText(): void
    {
        $this->save(['theme_font' => 'custom', 'theme_font_text_family' => self::UPLOADED]);
        $ext = $this->container()->get(RenderContextExtension::class);
        $ext->resetPerRenderState();
        $ext->setThemeAppearanceOverride(null, null, ['font' => 'custom', 'font_text_family' => 'none']);
        self::assertStringNotContainsString('--font-body', (string) $ext->themeColorsStyle());
        $ext->setThemeAppearanceOverride(null, null, null);
    }

    public function testAnInvalidPreviewFamilyIsRefused(): void
    {
        foreach (['Rm3dE5fG7hJ9', 'nope'] as $id) {
            self::assertSame(422, $this->mint(['font_text_family' => $id])->getStatusCode(), $id);
        }
    }
}
