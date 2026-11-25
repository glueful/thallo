<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\DocsSync;

use Glueful\Storage\StorageManager;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Thallo\Contracts\Authoring\ContentUpserter;
use Thallo\Contracts\Authoring\ContentWriter;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Schema\ContentTypeReader;
use Thallo\Core\Content\Authoring\EngineContentWriter;
use Thallo\Core\Content\DocsSync\DocsSyncRunner;
use Thallo\Core\Content\DocsSync\DocsSyncRunRepository;
use Thallo\Core\Content\DocsSync\DocsSyncStore;
use Thallo\Core\Content\DocsSync\GithubClient;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\ReferenceProjectionRepository;
use Thallo\Core\Content\Repositories\VersionRepository;
use Thallo\Core\Content\Services\PublishService;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Importers\Markdown\MarkdownFolderImport;

/**
 * The runner against a GitHub served from memory: `glueful/thallo` with one release tag whose
 * archive is built from the files a test gives.
 */
trait DocsSyncRunnerHelpers
{
    public const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @var array<string,string> path in docs/ => contents */
    protected array $repoFiles = [];

    /** @var (\Closure(): MockResponse)|null replaces the archive response once */
    protected ?\Closure $archiveOnce = null;

    protected int $archiveRequests = 0;

    protected function archiveBytes(): string
    {
        $path = sys_get_temp_dir() . '/thallo-repo-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('thallo-' . self::SHA . '/README.md', "# Repo readme\n");
        foreach ($this->repoFiles as $name => $contents) {
            $zip->addFromString('thallo-' . self::SHA . '/docs/' . $name, $contents);
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);
        return $bytes;
    }

    protected function github(): GithubClient
    {
        return new GithubClient(new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, '/git/matching-refs/tags/')) {
                return new MockResponse((string) json_encode([
                    ['ref' => 'refs/tags/v1.0.0', 'object' => ['sha' => self::SHA, 'type' => 'commit']],
                ]));
            }
            if (str_contains($url, '/zip/' . self::SHA)) {
                $this->archiveRequests++;
                if ($this->archiveOnce !== null) {
                    $once = $this->archiveOnce;
                    $this->archiveOnce = null;
                    return $once();
                }
                $bytes = $this->archiveBytes();
                return new MockResponse($bytes, ['response_headers' => ['content-length' => (string) strlen($bytes)]]);
            }
            return new MockResponse('{}', ['http_code' => 404]);
        }));
    }

    /** A writer that publishes as a site without review does (the test app has review on). */
    protected function writer(): ContentWriter
    {
        $types = new ContentTypeRepository($this->connection());
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        return new EngineContentWriter(
            $entries,
            new PublishService(
                $this->appContext(),
                $entries,
                new VersionRepository($this->connection()),
                $types,
                new FieldValidator($this->connection()),
                new ReferenceProjectionRepository($this->connection()),
            ),
            $types,
            new FieldValidator($this->connection()),
        );
    }

    protected function folders(?ContentWriter $writer = null): MarkdownFolderImport
    {
        $c = $this->container();
        return new MarkdownFolderImport(
            $writer ?? $this->writer(),
            $c->get(ContentUpserter::class),
            $c->get(ContentTypeReader::class),
            $c->get(CapabilityRegistry::class),
        );
    }

    protected function runner(?ContentWriter $writer = null, ?RecordingQueue $queue = null): DocsSyncRunner
    {
        $c = $this->container();
        return new DocsSyncRunner(
            $this->connection(),
            $c->get(DocsSyncStore::class),
            $c->get(DocsSyncRunRepository::class),
            $this->github(),
            $this->folders($writer),
            $c->get(StorageManager::class),
            $queue ?? new RecordingQueue(),
            $this->appContext(),
        );
    }

    /** Start a run the way Sync now / Preview does, returning its id. */
    protected function startRun(string $mode = 'commit'): string
    {
        return (string) $this->service()->start($mode, null)['id'];
    }

    protected function slugExists(string $slug): bool
    {
        $type = (string) $this->container()->get(ContentTypeReader::class)->findUuidBySlug('docs');
        return $this->container()->get(ContentUpserter::class)->findBySlug($type, 'en', $slug) !== null;
    }

    /** @return array<string,string> path => status */
    protected function pageStatuses(string $run): array
    {
        return array_column(
            $this->container()->get(DocsSyncRunRepository::class)->pages($run),
            'status',
            'path',
        );
    }
}
