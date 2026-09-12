<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updates;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Thallo\Core\Updates\PackagistReleaseFeed;

/**
 * Packagist's p2 metadata is "minified": every entry after the first inherits the keys it omits
 * from the entry before it. The feed carries the previous entry forward and collects `version`.
 */
final class PackagistReleaseFeedTest extends TestCase
{
    public function testReadsEveryVersionFromMinifiedMetadata(): void
    {
        $body = json_encode([
            'minified' => 'composer/2.0',
            'packages' => ['glueful/thallo-core' => [
                [
                    'name' => 'glueful/thallo-core',
                    'version' => 'v1.0.0-beta.22',
                    'version_normalized' => '1.0.0.0-beta22',
                ],
                ['version' => 'v1.0.0-beta.21', 'version_normalized' => '1.0.0.0-beta21'],
                ['version' => 'dev-main'],
            ]],
        ]);
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$requests, $body): MockResponse {
            $requests[] = "{$method} {$url}";
            return new MockResponse((string) $body, ['http_code' => 200]);
        });

        $versions = (new PackagistReleaseFeed($http))->versions('glueful/thallo-core');

        self::assertSame(['v1.0.0-beta.22', 'v1.0.0-beta.21', 'dev-main'], $versions);
        self::assertSame(['GET https://repo.packagist.org/p2/glueful/thallo-core.json'], $requests);
    }

    public function testAFailedResponseThrows(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['http_code' => 500]));

        $this->expectException(\RuntimeException::class);
        (new PackagistReleaseFeed($http))->versions('glueful/thallo-core');
    }

    public function testAnUnexpectedShapeThrows(): void
    {
        $http = new MockHttpClient(new MockResponse('{"packages":{}}', ['http_code' => 200]));

        $this->expectException(\RuntimeException::class);
        (new PackagistReleaseFeed($http))->versions('glueful/thallo-core');
    }
}
