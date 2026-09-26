<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;
use Thallo\Importers\Markdown\FrontMatter;

/**
 * The licence as a page of the docs (`docs/reference/07-license.md`, served at /docs/license) is
 * the project's LICENSE word for word: its first line is the page's title, the rest its body.
 * Change one without the other and this fails, so the site never shows terms the project no
 * longer has.
 */
final class LicensePageTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    public function testTheLicencePageIsTheLicenceFile(): void
    {
        $license = (string) file_get_contents(self::ROOT . '/LICENSE');
        [$name, $terms] = array_pad(preg_split('~\R~', trim($license), 2) ?: [], 2, '');

        ['front' => $front, 'body' => $body] = FrontMatter::split(
            (string) file_get_contents(self::ROOT . '/docs/reference/07-license.md'),
        );

        self::assertSame(trim($name), $front['title'] ?? '', 'the page is titled with the licence\'s name');
        self::assertSame('license', $front['slug'] ?? '');
        self::assertSame(trim($terms), trim($body), 'the page\'s text is LICENSE\'s, word for word');
    }
}
