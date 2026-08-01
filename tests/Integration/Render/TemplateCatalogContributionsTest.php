<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\Templates\TemplateCatalog;
use Thallo\Render\Templates\TemplateRepository;

final class TemplateCatalogContributionsTest extends AppTestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/thallo-catalog-contrib-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/contribA/shop', 0755, true);
        mkdir($this->tmp . '/contribB', 0755, true);
        mkdir($this->tmp . '/appthemes/mytheme/templates', 0755, true);
        file_put_contents($this->tmp . '/contribA/shop/checkout.twig', 'CONTRIB-A-CHECKOUT');
        file_put_contents($this->tmp . '/contribA/probe.twig', 'CONTRIB-A-PROBE');
        file_put_contents($this->tmp . '/contribB/probe.twig', 'CONTRIB-B-PROBE');
        // Also present in the pack default: entry.twig — contribution must NOT shadow it
        // in the catalog when only the default ships it… but a contributed copy MUST win:
        file_put_contents($this->tmp . '/contribA/entry.twig', 'CONTRIB-A-ENTRY');
        file_put_contents($this->tmp . '/appthemes/mytheme/templates/probe.twig', 'APP-THEME-PROBE');
        file_put_contents(
            $this->tmp . '/appthemes/mytheme/theme.json',
            (string) json_encode(['name' => 'mytheme', 'version' => '1.0.0']),
        );
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->tmp);
        parent::tearDown();
    }

    /** @param list<array{contributor_id: string, dir: string}> $contributions */
    private function catalog(array $contributions): TemplateCatalog
    {
        return new TemplateCatalog(
            $this->container()->get(TemplateRepository::class),
            $this->tmp . '/appthemes',
            \dirname(__DIR__, 3) . '/packages/thallo-render/themes',
            $contributions,
        );
    }

    /** @return list<array{contributor_id: string, dir: string}> */
    private function contributions(): array
    {
        return [
            ['contributor_id' => 'a-pack', 'dir' => $this->tmp . '/contribA'],
            ['contributor_id' => 'b-pack', 'dir' => $this->tmp . '/contribB'],
        ];
    }

    public function testContributedTemplatesListWithPackageOrigin(): void
    {
        $byPath = array_column($this->catalog($this->contributions())->list('default'), null, 'path');

        self::assertSame('package', $byPath['shop/checkout.twig']['origin']);
        self::assertFalse($byPath['shop/checkout.twig']['overridden']);
        // Contributed copy of a pack-default name wins the listing (contribution > default).
        self::assertSame('package', $byPath['entry.twig']['origin']);
        // Pack-default-only names keep origin 'default'.
        self::assertSame('default', $byPath['layout.twig']['origin']);
    }

    public function testPrecedenceThemeOverContributionOverDefaultAndFirstContributorWins(): void
    {
        $byPath = array_column($this->catalog($this->contributions())->list('mytheme'), null, 'path');
        // App theme beats both contributions.
        self::assertSame('theme', $byPath['probe.twig']['origin']);

        // Without the app theme, first-registered contribution wins (a-pack over b-pack).
        $read = $this->catalog($this->contributions())->readFile('default', 'probe.twig');
        self::assertSame(['source' => 'CONTRIB-A-PROBE', 'origin' => 'package'], $read);
    }

    public function testReadFileLadderSeedsPackageSource(): void
    {
        $catalog = $this->catalog($this->contributions());
        self::assertSame(
            ['source' => 'CONTRIB-A-CHECKOUT', 'origin' => 'package'],
            $catalog->readFile('default', 'shop/checkout.twig'),
        );
        // App theme still wins the ladder.
        self::assertSame(
            ['source' => 'APP-THEME-PROBE', 'origin' => 'theme'],
            $catalog->readFile('mytheme', 'probe.twig'),
        );
        // Contribution beats pack default in the ladder.
        self::assertSame(
            ['source' => 'CONTRIB-A-ENTRY', 'origin' => 'package'],
            $catalog->readFile('default', 'entry.twig'),
        );
    }

    public function testZeroContributionsIsByteIdenticalToPreFeature(): void
    {
        $with = $this->catalog([])->list('default');
        $without = new TemplateCatalog(
            $this->container()->get(TemplateRepository::class),
            $this->tmp . '/appthemes',
            \dirname(__DIR__, 3) . '/packages/thallo-render/themes',
        );
        self::assertSame($without->list('default'), $with);
        self::assertNull($this->catalog([])->readFile('default', 'shop/checkout.twig'));
    }

    public function testCapabilityOffHonestBehaviorOverrideSurvivesBaselineRemoval(): void
    {
        /** @var TemplateRepository $repo */
        $repo = $this->container()->get(TemplateRepository::class);
        $repo->save('default', 'shop/checkout.twig', 'DB-OVERRIDE', 'user00000001');

        // Capability on: DB override wins the row, origin db.
        $on = array_column($this->catalog($this->contributions())->list('default'), null, 'path');
        self::assertSame('db', $on['shop/checkout.twig']['origin']);
        self::assertTrue($on['shop/checkout.twig']['overridden']);

        // Capability off (no contributions): the override REMAINS listed as db —
        // the honest behavior pinned in spec §Pinned rules 5 — but the filesystem
        // baseline is gone.
        $off = array_column($this->catalog([])->list('default'), null, 'path');
        self::assertSame('db', $off['shop/checkout.twig']['origin']);
        self::assertNull($this->catalog([])->readFile('default', 'shop/checkout.twig'));
    }
}
