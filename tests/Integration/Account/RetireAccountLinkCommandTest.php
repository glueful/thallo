<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Regions\RegionRepository;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Starter\Kinds\BlockTypeKind;
use App\Content\Starter\StarterProvenanceRepository;
use App\Content\Console\RetireAccountLinkCommand;
use App\Tests\Support\AppTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Task 1 (account-link retirement plan): physically retiring the deprecated `account-link` block
 * pre-launch — removed, not migrated. Covers the command's happy path (idempotent removal of a
 * placed instance, the `block_types` row, and its starter-provenance row, with proof that nothing
 * can resurrect the slug afterward) and its fail-closed behaviour when the block is still
 * referenced by entry content (BlockUsageScanner's scan scope: drafts + pinned publications only —
 * never regions, so a region-only instance never blocks the retirement on its own).
 */
final class RetireAccountLinkCommandTest extends AppTestCase
{
    private const SLUG = 'account-link';
    private const SOURCE_ID = 'thallo-account:account-link';

    private function blockTypes(): BlockTypeRepository
    {
        return $this->container()->get(BlockTypeRepository::class);
    }

    private function regions(): RegionRepository
    {
        return $this->container()->get(RegionRepository::class);
    }

    private function provenance(): StarterProvenanceRepository
    {
        return $this->container()->get(StarterProvenanceRepository::class);
    }

    private function seedAccountLinkBlockType(): void
    {
        $this->blockTypes()->create([
            'slug' => self::SLUG,
            'label' => 'Account link',
            'schema' => [['name' => 'label', 'type' => 'string']],
        ]);
    }

    private function seedProvenance(): void
    {
        $this->provenance()->recordApplied('block_type', self::SLUG, self::SOURCE_ID, 'fp');
    }

    private function seedHeaderRegionWithAccountLink(): void
    {
        $this->regions()->save('header', [
            ['id' => 'b1', 'type' => 'logo', 'data' => []],
            ['id' => 'b2', 'type' => self::SLUG, 'data' => []],
            ['id' => 'b3', 'type' => 'navigation', 'data' => []],
        ], [], null);
    }

    private function runCommand(): CommandTester
    {
        $tester = new CommandTester($this->container()->get(RetireAccountLinkCommand::class));
        $tester->execute([]);

        return $tester;
    }

    public function testHappyPathIsIdempotentAndCleansUpEverything(): void
    {
        $this->seedAccountLinkBlockType();
        $this->seedProvenance();
        $this->seedHeaderRegionWithAccountLink();

        $first = $this->runCommand();
        self::assertSame(0, $first->getStatusCode(), $first->getDisplay());

        $header = $this->regions()->find('header');
        self::assertSame(['logo', 'navigation'], array_column($header['blocks'], 'type'));
        self::assertNull($this->blockTypes()->findBySlug(self::SLUG));
        self::assertNull($this->provenance()->findBySource('block_type', self::SOURCE_ID));

        // Idempotent: rerunning over an already-clean tenant changes nothing and still succeeds.
        $second = $this->runCommand();
        self::assertSame(0, $second->getStatusCode(), $second->getDisplay());
        $headerAgain = $this->regions()->find('header');
        self::assertSame(['logo', 'navigation'], array_column($headerAgain['blocks'], 'type'));
        self::assertNull($this->blockTypes()->findBySlug(self::SLUG));
        self::assertNull($this->provenance()->findBySource('block_type', self::SOURCE_ID));

        // Nothing can resurrect it: AccountBlockTypesContributor contributes no definitions
        // anymore, so the slug is entirely absent from the starter source set that drives
        // block-type sync — no later sync run can recreate it or leave an orphaned_source row.
        $kind = $this->container()->get(BlockTypeKind::class);
        $sourceIds = array_map(static fn ($definition) => $definition->sourceId, $kind->definitions());
        self::assertNotContains(self::SOURCE_ID, $sourceIds);
        self::assertNull($this->blockTypes()->findBySlug(self::SLUG));
        self::assertNull($this->provenance()->findBySource('block_type', self::SOURCE_ID));
    }

    public function testFailsClosedAndRollsBackWhenTheBlockIsStillReferencedByAnEntry(): void
    {
        $this->seedAccountLinkBlockType();
        $this->seedProvenance();
        $this->seedHeaderRegionWithAccountLink();

        $typeUuid = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'usage_probe',
            'name' => 'Usage probe',
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        $entries = new EntryRepository(
            $this->connection(),
            $this->appContext(),
            new ContentTypeRepository($this->connection()),
        );
        $entryUuid = $entries->createEntry($typeUuid, 'en', 1, 'user00000001');
        $entries->saveDraft($entryUuid, 'en', [
            'title' => 'Uses it',
            'body' => [['id' => 'x', 'type' => self::SLUG, 'data' => []]],
        ], 1, 0, 'user00000001');

        $result = $this->runCommand();

        self::assertNotSame(0, $result->getStatusCode());

        // Fail-closed: nothing was deleted or mutated for this tenant.
        self::assertNotNull($this->blockTypes()->findBySlug(self::SLUG));
        self::assertNotNull($this->provenance()->findBySource('block_type', self::SOURCE_ID));
        $header = $this->regions()->find('header');
        self::assertSame(['logo', self::SLUG, 'navigation'], array_column($header['blocks'], 'type'));
    }
}
