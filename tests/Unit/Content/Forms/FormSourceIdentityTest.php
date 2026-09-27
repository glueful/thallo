<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Content\Forms;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Content\Forms\FormSourceIdentity;

/**
 * Which form a submission belongs to (form-block spec §5, type layouts spec §5.6): a layout's form
 * is one form across the type, a header or footer form one form across the site, a body form its
 * page's — so each level beats the ones after it.
 */
final class FormSourceIdentityTest extends TestCase
{
    public function testPrecedenceIsLayoutRegionEntryRouteFallback(): void
    {
        $entry = ['uuid' => 'entry0000001'];
        self::assertSame('layout:entry:post', FormSourceIdentity::resolve($entry, 'footer', '/post/a', 'entry:post'));
        self::assertSame('region:footer', FormSourceIdentity::resolve($entry, 'footer', '/post/a'));
        self::assertSame('entry:entry0000001', FormSourceIdentity::resolve($entry, null, '/post/a'));
        self::assertSame('route:/post/a', FormSourceIdentity::resolve(null, null, '/post/a'));
        self::assertSame('theme:path:/', FormSourceIdentity::resolve(null, null, null));
        // Empty strings are absent, never an identity.
        self::assertSame('entry:entry0000001', FormSourceIdentity::resolve($entry, '', '/post/a', ''));
    }
}
