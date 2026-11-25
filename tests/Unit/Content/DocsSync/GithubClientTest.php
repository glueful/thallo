<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Content\DocsSync;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Thallo\Core\Content\DocsSync\GithubClient;
use Thallo\Core\Content\DocsSync\GithubRefused;

/**
 * The docs sync's only outbound traffic (docs sync design §5): anonymous, to two GitHub hosts, and
 * every archive pinned to the commit its tag or branch resolved to.
 */
final class GithubClientTest extends TestCase
{
    /** @var list<array{method: string, url: string, headers: list<string>}> */
    private array $requests = [];

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/thallo-gh-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->tmp);
    }

    /** @param array<string, MockResponse|callable(): MockResponse> $routes URL (without host) => response */
    private function client(array $routes): GithubClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($routes): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $options['headers'] ?? []];
            $path = (string) preg_replace('~\Ahttps://[^/]+~', '', $url);
            $response = $routes[$path] ?? null;
            if ($response === null) {
                return new MockResponse('{"message":"Not Found"}', ['http_code' => 404]);
            }
            return is_callable($response) ? $response() : $response;
        });
        return new GithubClient($http);
    }

    private static function json(mixed $body, array $info = []): MockResponse
    {
        return new MockResponse((string) json_encode($body), $info + ['http_code' => 200]);
    }

    /** @param list<string> $tags */
    private static function refs(array $tags, string $type = 'commit'): array
    {
        return array_map(
            static fn (string $t): array => [
                'ref' => 'refs/tags/' . $t,
                'object' => ['sha' => sha1($t), 'type' => $type],
            ],
            $tags,
        );
    }

    private function zipBytes(): string
    {
        $path = $this->tmp . '/fixture.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('thallo-abc/docs/a.md', "# A\n");
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);
        return $bytes;
    }

    public function testHighestVersionTagWinsAcrossPages(): void
    {
        $client = $this->client([
            '/repos/glueful/thallo/git/matching-refs/tags/' => self::json(self::refs(['v1.0.0', 'v1.2.0']), [
                'response_headers' => [
                    'link' => '<https://api.github.com/repos/glueful/thallo/git/matching-refs/tags/?page=2>; rel="next"',
                ],
            ]),
            '/repos/glueful/thallo/git/matching-refs/tags/?page=2' => self::json(self::refs(['v1.10.0', 'v1.9.0'])),
        ]);

        $latest = $client->latestRelease('glueful/thallo', false);

        self::assertSame(['tag' => 'v1.10.0', 'sha' => sha1('v1.10.0')], $latest);
    }

    public function testPreReleasesCountOnlyWhenAsked(): void
    {
        $refs = self::json(self::refs(['v1.0.0-beta.108', 'v1.0.0-beta.109', 'v0.9.0']));
        $client = $this->client(['/repos/glueful/thallo/git/matching-refs/tags/' => $refs]);
        self::assertSame('v1.0.0-beta.109', $client->latestRelease('glueful/thallo', true)['tag']);

        $refs = self::json(self::refs(['v1.0.0-beta.108', 'v1.0.0-beta.109', 'v0.9.0']));
        $client = $this->client(['/repos/glueful/thallo/git/matching-refs/tags/' => $refs]);
        self::assertSame('v0.9.0', $client->latestRelease('glueful/thallo', false)['tag']);
    }

    public function testMixedTagNamesAndNonVersionsAreHandled(): void
    {
        $tags = ['1.2.0', 'v1.3.0', 'latest', 'v2.0.0-rc.1'];
        $client = $this->client(['/repos/a/b/git/matching-refs/tags/' => self::json(self::refs($tags))]);
        self::assertSame('v1.3.0', $client->latestRelease('a/b', false)['tag']);

        $client = $this->client(['/repos/a/b/git/matching-refs/tags/' => self::json(self::refs($tags))]);
        self::assertSame('v2.0.0-rc.1', $client->latestRelease('a/b', true)['tag']);
    }

    public function testAnnotatedTagResolvesToItsCommit(): void
    {
        $client = $this->client([
            '/repos/a/b/git/matching-refs/tags/' => self::json(self::refs(['v1.0.0'], 'tag')),
            '/repos/a/b/git/tags/' . sha1('v1.0.0') => self::json([
                'object' => ['sha' => 'c0ffee' . str_repeat('0', 34), 'type' => 'commit'],
            ]),
        ]);

        self::assertSame('c0ffee' . str_repeat('0', 34), $client->latestRelease('a/b', false)['sha']);
    }

    public function testLightweightTagIsItsCommit(): void
    {
        $client = $this->client(['/repos/a/b/git/matching-refs/tags/' => self::json(self::refs(['v1.0.0']))]);

        self::assertSame(sha1('v1.0.0'), $client->latestRelease('a/b', false)['sha']);
        self::assertCount(1, $this->requests, 'no tag object to follow');
    }

    public function testATagChainLongerThanFiveIsRefused(): void
    {
        $routes = ['/repos/a/b/git/matching-refs/tags/' => self::json(self::refs(['v1.0.0'], 'tag'))];
        $sha = sha1('v1.0.0');
        for ($i = 0; $i < 6; $i++) {
            $next = sha1($sha);
            $routes['/repos/a/b/git/tags/' . $sha] = self::json(['object' => ['sha' => $next, 'type' => 'tag']]);
            $sha = $next;
        }
        $this->expectException(GithubRefused::class);
        $this->client($routes)->latestRelease('a/b', false);
    }

    public function testBranchTipIsTheShaText(): void
    {
        $sha = str_repeat('a', 40);
        $client = $this->client(['/repos/a/b/commits/main' => new MockResponse($sha, ['http_code' => 200])]);

        self::assertSame($sha, $client->branchTip('a/b', 'main'));
        self::assertContains('Accept: application/vnd.github.sha', $this->requests[0]['headers']);
    }

    public function testPrivateOrMissingRepoIsRefused(): void
    {
        $client = $this->client(['/repos/a/secret' => self::json(['private' => true, 'default_branch' => 'main'])]);
        try {
            $client->repo('a/secret');
            self::fail('A private repo must be refused.');
        } catch (GithubRefused $e) {
            self::assertStringContainsString('public', $e->getMessage());
        }
        $this->expectException(GithubRefused::class);
        $this->client([])->repo('a/missing');
    }

    public function testRateLimitSaysWhenToTryAgain(): void
    {
        $reset = 1795612800; // a fixed instant
        $client = $this->client(['/repos/a/b' => new MockResponse('{}', [
            'http_code' => 403,
            'response_headers' => ['x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => (string) $reset],
        ])]);

        $this->expectException(GithubRefused::class);
        $this->expectExceptionMessage("GitHub's hourly limit is reached; try again after " . date('H:i', $reset));
        $client->repo('a/b');
    }

    public function testArchiveIsRequestedBySha(): void
    {
        $sha = str_repeat('b', 40);
        $bytes = $this->zipBytes();
        $client = $this->client(['/a/b/zip/' . $sha => new MockResponse($bytes, [
            'http_code' => 200, 'response_headers' => ['content-length' => (string) strlen($bytes)],
        ])]);
        $to = $this->tmp . '/out.zip';

        $client->download('a/b', $sha, $to, 1_000_000, static fn (): bool => true);

        self::assertSame('https://codeload.github.com/a/b/zip/' . $sha, $this->requests[0]['url']);
        self::assertSame($bytes, file_get_contents($to));
    }

    public function testArchiveOverTheCapIsRefusedMidStream(): void
    {
        $sha = str_repeat('b', 40);
        $client = $this->client(['/a/b/zip/' . $sha => new MockResponse([str_repeat('x', 600), str_repeat('x', 600)])]);

        $this->expectException(GithubRefused::class);
        $this->expectExceptionMessageMatches('/larger than/');
        $client->download('a/b', $sha, $this->tmp . '/big.zip', 1000, static fn (): bool => true);
    }

    public function testTruncatedArchiveIsRefused(): void
    {
        $sha = str_repeat('b', 40);
        $bytes = $this->zipBytes();
        $client = $this->client(['/a/b/zip/' . $sha => new MockResponse(substr($bytes, 0, 20), [
            'http_code' => 200, 'response_headers' => ['content-length' => (string) strlen($bytes)],
        ])]);

        $this->expectException(GithubRefused::class);
        $client->download('a/b', $sha, $this->tmp . '/short.zip', 1_000_000, static fn (): bool => true);
    }

    public function testRedirectOrNonZipBodyIsRefused(): void
    {
        $sha = str_repeat('b', 40);
        $client = $this->client(['/a/b/zip/' . $sha => new MockResponse('', [
            'http_code' => 302, 'response_headers' => ['location' => 'https://evil.example/x.zip'],
        ])]);
        try {
            $client->download('a/b', $sha, $this->tmp . '/r.zip', 1_000_000, static fn (): bool => true);
            self::fail('A redirect must be refused.');
        } catch (GithubRefused) {
        }

        $html = new MockResponse('<html>not a zip</html>', ['http_code' => 200]);
        $client = $this->client(['/a/b/zip/' . $sha => $html]);
        $this->expectException(GithubRefused::class);
        $client->download('a/b', $sha, $this->tmp . '/h.zip', 1_000_000, static fn (): bool => true);
    }

    public function testAHeartbeatThatFailsAbortsTheDownload(): void
    {
        $sha = str_repeat('b', 40);
        $client = $this->client(['/a/b/zip/' . $sha => new MockResponse([str_repeat('x', 10), str_repeat('x', 10)])]);

        $this->expectException(GithubRefused::class);
        $this->expectExceptionMessageMatches('/stopped/');
        $client->download(
            'a/b',
            $sha,
            $this->tmp . '/hb.zip',
            1_000_000,
            static fn (): bool => false,
            heartbeatEvery: 0,
        );
    }

    public function testNoRequestGoesToAnotherHost(): void
    {
        $sha = str_repeat('c', 40);
        $bytes = $this->zipBytes();
        $client = $this->client([
            '/repos/a/b' => self::json(['private' => false, 'default_branch' => 'main']),
            '/repos/a/b/git/matching-refs/tags/' => self::json(self::refs(['v1.0.0']), [
                'response_headers' => ['link' => '<https://evil.example/next>; rel="next"'],
            ]),
            '/repos/a/b/commits/main' => new MockResponse($sha),
            '/a/b/zip/' . $sha => new MockResponse($bytes),
        ]);

        $client->repo('a/b');
        $client->latestRelease('a/b', false);
        $client->branchTip('a/b', 'main');
        $client->download('a/b', $sha, $this->tmp . '/o.zip', 1_000_000, static fn (): bool => true);

        foreach ($this->requests as $request) {
            $host = (string) parse_url($request['url'], PHP_URL_HOST);
            self::assertContains($host, ['api.github.com', 'codeload.github.com']);
        }
    }
}
