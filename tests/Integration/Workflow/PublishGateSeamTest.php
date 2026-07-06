<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Authoring\DraftSummaryReader;
use Thallo\Contracts\Authoring\PublishBlocked;
use Thallo\Contracts\Authoring\PublishGate;

final class PublishGateSeamTest extends AppTestCase
{
    use SeedsPublishedContent;

    public function testGateBlocksPublishBeforeAnyWrite(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $versionsBefore = (int) $this->connection()->getPDO()
            ->query("SELECT COUNT(*) FROM entry_versions WHERE entry_uuid = '{$entry}'")->fetchColumn();

        $blocking = new class implements PublishGate {
            public function assertCanPublish(string $entryUuid, string $locale, ?string $actorUuid): void
            {
                throw new PublishBlocked('blocked by test gate', 'in_review');
            }
        };

        try {
            $this->makePublishServiceWithGates([$blocking])->publish($entry, 'en', 'user00000001');
            self::fail('expected PublishBlocked');
        } catch (PublishBlocked $e) {
            self::assertSame('in_review', $e->state);
            self::assertSame('blocked by test gate', $e->reason);
        }
        $versionsAfter = (int) $this->connection()->getPDO()
            ->query("SELECT COUNT(*) FROM entry_versions WHERE entry_uuid = '{$entry}'")->fetchColumn();
        self::assertSame($versionsBefore, $versionsAfter, 'a blocked publish must write NOTHING');
    }

    public function testAllowingGatePublishes(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $allowing = new class implements PublishGate {
            public function assertCanPublish(string $entryUuid, string $locale, ?string $actorUuid): void
            {
            }
        };
        $version = $this->makePublishServiceWithGates([$allowing])->publish($entry, 'en', 'user00000001');
        self::assertNotSame('', $version);
    }

    public function testEmptyGateListPublishesAsToday(): void
    {
        // The seam must be INERT with no gates. Deliberately constructed with gates: [] (not
        // the container instance): once the workflow pack installs, the container service
        // carries a real gate — this test must keep proving the no-gates behaviour
        // regardless of what is installed.
        $entry = $this->seedBilingualPublishedEntry();
        $version = $this->makePublishServiceWithGates([])->publish($entry, 'en', 'user00000001');
        self::assertNotSame('', $version);
    }

    public function testDraftSummaryReaderReturnsTitleAndType(): void
    {
        $entry = $this->seedBilingualPublishedEntry(); // type 'blog', en title 'Hello'
        $reader = $this->container()->get(DraftSummaryReader::class);

        $summary = $reader->summary($entry, 'en');
        self::assertNotNull($summary);
        self::assertSame('Hello', $summary['title']);
        self::assertSame('blog', $summary['type_slug']);
        self::assertSame($entry, $summary['entry_uuid']);

        self::assertNull($reader->summary('nope00000000', 'en'));
    }

    /** @param list<PublishGate> $gates */
    private function makePublishServiceWithGates(array $gates): PublishService
    {
        $c = $this->container();
        return new PublishService(
            $this->appContext(),
            $c->get(EntryRepository::class),
            $c->get(VersionRepository::class),
            $c->get(ContentTypeRepository::class),
            $c->get(FieldValidator::class),
            $c->get(ReferenceProjectionRepository::class),
            null,
            null,
            $gates,
        );
    }
}
