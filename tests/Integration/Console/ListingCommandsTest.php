<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Console;

use Symfony\Component\Console\Tester\CommandTester;
use Thallo\Core\Capabilities\CapabilityStateStore;
use Thallo\Core\Capabilities\Console\CapabilitiesCommand;
use Thallo\Core\Content\Console\ListBlockTypesCommand;
use Thallo\Core\Tests\Support\AppTestCase;

/** thallo:blocks:list and thallo:capabilities, the two listings a shell had no way to read. */
final class ListingCommandsTest extends AppTestCase
{
    private ?bool $accountsBefore = null;

    protected function tearDown(): void
    {
        if ($this->accountsBefore !== null) {
            $store = $this->container()->get(CapabilityStateStore::class);
            $store->put('thallo.accounts', $this->accountsBefore);
        }
        parent::tearDown();
    }

    public function testBlockTypesAreListed(): void
    {
        $repo = $this->container()->get(\Thallo\Core\Content\Blocks\BlockTypeRepository::class);
        if ($repo->findBySlug('listing-probe') === null) {
            $repo->create([
                'slug' => 'listing-probe',
                'label' => 'Listing probe',
                'category' => 'content',
                'schema' => [['name' => 'heading', 'type' => 'string']],
            ]);
        }
        $tester = new CommandTester($this->container()->get(ListBlockTypesCommand::class));

        self::assertSame(0, $tester->execute(['--json' => true]));
        $rows = json_decode($tester->getDisplay(), true);
        self::assertIsArray($rows);
        $probe = array_column($rows, null, 'slug')['listing-probe'] ?? null;
        self::assertSame(['heading (string)'], $probe['fields'] ?? null);

        $tester->execute([]);
        self::assertStringContainsString('Listing probe', $tester->getDisplay());
        $repo->deleteBySlug('listing-probe');
    }

    public function testCapabilitiesAreListedAndFlipped(): void
    {
        $tester = new CommandTester($this->container()->get(CapabilitiesCommand::class));

        self::assertSame(0, $tester->execute(['--json' => true]));
        $rows = array_column((array) json_decode($tester->getDisplay(), true), null, 'id');
        self::assertArrayHasKey('thallo.accounts', $rows);

        $store = $this->container()->get(CapabilityStateStore::class);
        $this->accountsBefore = $store->requested('thallo.accounts');
        self::assertSame(0, $tester->execute(['--disable' => 'thallo.accounts']));
        self::assertFalse($store->requested('thallo.accounts'));
        self::assertStringContainsString('next request', $tester->getDisplay());

        self::assertSame(1, $tester->execute(['--enable' => 'no.such.capability']));
        self::assertSame(1, $tester->execute(['--enable' => 'a', '--disable' => 'b']));
    }
}
