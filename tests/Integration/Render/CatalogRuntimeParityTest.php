<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\Contribution\RenderContributionRegistry;
use Thallo\Render\Contribution\TemplatePathContributor;
use Thallo\Render\Templates\RenderTemplateLoader;
use Thallo\Render\Templates\TemplateCatalog;
use Thallo\Render\Templates\TemplateRepository;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Twig\Loader\FilesystemLoader;

/** The editor must seed the exact bytes the runtime precedence chain resolves. */
final class CatalogRuntimeParityTest extends AppTestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/thallo-catalog-parity-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/a', 0755, true);
        mkdir($this->tmp . '/b', 0755, true);
        mkdir($this->tmp . '/themes/parity/templates', 0755, true);
        file_put_contents($this->tmp . '/a/__package_collision.twig', 'PACKAGE-A');
        file_put_contents($this->tmp . '/b/__package_collision.twig', 'PACKAGE-B');
        file_put_contents($this->tmp . '/themes/parity/theme.json', '{"name":"parity"}');
        file_put_contents($this->tmp . '/themes/parity/templates/__theme_only.twig', 'THEME');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($this->tmp);
        parent::tearDown();
    }

    /** @param list<string> $dirs */
    private function contributor(string $id, array $dirs, int $priority): TemplatePathContributor
    {
        return new class ($id, $dirs, $priority) implements TemplatePathContributor {
            /** @param list<string> $dirs */
            public function __construct(
                private readonly string $id,
                private readonly array $dirs,
                private readonly int $priority,
            ) {
            }

            public function contributorId(): string
            {
                return $this->id;
            }

            public function priority(): int
            {
                return $this->priority;
            }

            public function templatePaths(): array
            {
                return $this->dirs;
            }
        };
    }

    public function testEveryFilesystemRowIncludingPackagesMatchesTheRuntimeChain(): void
    {
        $root = dirname(__DIR__, 3);
        $registry = new RenderContributionRegistry();
        $registry->registerTemplatePaths($this->contributor('probe-a', [$this->tmp . '/a'], 0));
        $registry->registerTemplatePaths($this->contributor('probe-b', [$this->tmp . '/b'], 1));
        $registry->registerTemplatePaths($this->contributor(
            'thallo-account',
            [$root . '/packages/thallo-account/templates'],
            10,
        ));
        $registry->registerTemplatePaths($this->contributor(
            'thallo-commerce',
            [$root . '/packages/thallo-commerce/templates'],
            20,
        ));

        $contributions = $registry->frozenTemplateContributions();
        $catalog = new TemplateCatalog(
            $this->container()->get(TemplateRepository::class),
            $this->tmp . '/themes',
            $root . '/packages/thallo-render/themes',
            $contributions,
        );
        $locator = new ThemeLocator(
            'parity',
            $this->tmp . '/themes',
            $root . '/packages/thallo-render/themes',
            $registry->frozenTemplatePaths(),
        );
        $runtime = new FilesystemLoader($locator->activePaths()['templates']);

        $rows = $catalog->list('parity');
        self::assertSame('package', array_column($rows, null, 'path')['__package_collision.twig']['origin']);
        self::assertSame('PACKAGE-A', $runtime->getSourceContext('__package_collision.twig')->getCode());

        foreach ($rows as $row) {
            if (!str_ends_with($row['path'], '.twig') || $row['origin'] === 'db') {
                continue;
            }
            $admin = $catalog->readFile('parity', $row['path']);
            self::assertNotNull($admin, "Catalog row has no readable baseline: {$row['path']}");
            self::assertSame(
                $runtime->getSourceContext($row['path'])->getCode(),
                $admin['source'],
                "Catalog/runtime divergence for {$row['path']}",
            );
        }
    }

    public function testDbRowMatchesTheCompositeRuntimeLoader(): void
    {
        /** @var TemplateRepository $repo */
        $repo = $this->container()->get(TemplateRepository::class);
        $repo->save('default', 'entry.twig', 'PARITY-DB {{ entry.fields.title }}', 'user00000001');

        $loader = $this->container()->get(TwigFactory::class)->environment()->getLoader();
        self::assertInstanceOf(RenderTemplateLoader::class, $loader);
        $loader->resetForRender();
        $row = $repo->findCurrentSource('default', 'entry.twig');
        self::assertNotNull($row);
        self::assertSame($loader->getSourceContext('entry.twig')->getCode(), $row['source']);
    }
}
