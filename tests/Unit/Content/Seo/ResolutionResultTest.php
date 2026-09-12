<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Content\Seo;

use Thallo\Core\Content\Seo\ResolutionResult;
use PHPUnit\Framework\TestCase;

final class ResolutionResultTest extends TestCase
{
    public function testContentResultCarriesPublishedRow(): void
    {
        $result = ResolutionResult::found(['entry_uuid' => 'entry0000001']);

        self::assertSame('content', $result->kind());
        self::assertSame('entry0000001', $result->content()['entry_uuid']);
        self::assertFalse($result->isRedirect());
    }

    public function testRedirectResultCarriesDescriptor(): void
    {
        $result = ResolutionResult::moved([
            'uuid' => 'redir0000001',
            'status' => 301,
            'to' => '/en/blog/new',
            'external' => false,
            'target_state' => 'live',
            'target' => [
                'content_type' => 'blog',
                'locale' => 'en',
                'slug' => 'new',
            ],
        ]);

        self::assertSame('redirect', $result->kind());
        self::assertTrue($result->isRedirect());
        self::assertSame('/en/blog/new', $result->redirect()['to']);
        self::assertSame(301, $result->redirect()['status']);
    }

    public function testGoneResultCarriesBrokenRedirectDescriptor(): void
    {
        $result = ResolutionResult::gone([
            'uuid' => 'redir0000001',
            'status' => 301,
            'to' => null,
            'external' => false,
            'target_state' => 'broken',
            'target' => [
                'content_type' => 'blog',
                'locale' => 'en',
                'slug' => null,
            ],
        ]);

        self::assertSame('gone', $result->kind());
        self::assertTrue($result->isGone());
        self::assertSame('broken', $result->redirect()['target_state']);
    }
}
