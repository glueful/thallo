<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Setup;

use Glueful\Helpers\Utils;
use Thallo\Core\Setup\DefaultLanguage;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * The default language is a real row in Settings › Languages: provision makes the configured
 * default (`i18n.default_locale`, `en`) one where no language is the default. Before, `en` was
 * only a fallback, so the page listed nothing, the first language added became the default, and
 * English could not be chosen again.
 */
final class DefaultLanguageTest extends AppTestCase
{
    /** @var list<array<string,mixed>> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->saved = $this->connection()->table('i18n_locales')->get();
        $this->connection()->getPDO()->exec('DELETE FROM i18n_locales');
    }

    protected function tearDown(): void
    {
        $this->connection()->getPDO()->exec('DELETE FROM i18n_locales');
        foreach ($this->saved as $row) {
            $this->connection()->table('i18n_locales')->insert($row);
        }
        parent::tearDown();
    }

    private function ensure(): ?string
    {
        return $this->container()->get(DefaultLanguage::class)->ensure();
    }

    private function add(string $code, bool $default, bool $enabled = true): void
    {
        $this->connection()->table('i18n_locales')->insert([
            'uuid' => Utils::generateNanoID(12), 'code' => $code, 'name' => strtoupper($code),
            'enabled' => $enabled, 'is_default' => $default, 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string,mixed> */
    private function row(string $code): array
    {
        $row = $this->connection()->table('i18n_locales')->where('code', '=', $code)->first();
        self::assertIsArray($row, "{$code} is a language");
        return $row;
    }

    public function testASiteWithNoLanguagesGetsEnglishAsItsDefault(): void
    {
        self::assertSame('en', $this->ensure());

        $en = $this->row('en');
        self::assertSame('English', $en['name']);
        self::assertSame('English', $en['native_name']);
        self::assertTrue((bool) $en['enabled']);
        self::assertTrue((bool) $en['is_default']);
    }

    public function testALanguageAddedWithoutADefaultKeepsEnglishAsTheDefault(): void
    {
        $this->add('fr', false);

        self::assertSame('en', $this->ensure());
        self::assertTrue((bool) $this->row('en')['is_default']);
        self::assertFalse((bool) $this->row('fr')['is_default']);
    }

    public function testADisabledEnglishThatIsTheDefaultInEffectIsEnabled(): void
    {
        $this->add('en', false, false);

        self::assertSame('en', $this->ensure());
        self::assertTrue((bool) $this->row('en')['enabled']);
        self::assertTrue((bool) $this->row('en')['is_default']);
    }

    public function testASiteThatChoseItsDefaultIsLeftAlone(): void
    {
        $this->add('fr', true);

        self::assertNull($this->ensure());
        self::assertSame(1, $this->connection()->table('i18n_locales')->count());
    }

    public function testItIsSafeToRunOnEveryProvision(): void
    {
        self::assertSame('en', $this->ensure());
        self::assertNull($this->ensure());
        self::assertSame(1, $this->connection()->table('i18n_locales')->count());
    }
}
