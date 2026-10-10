<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Palette;

use Thallo\Core\Content\Blocks\Sources\DocumentRef;
use Thallo\Core\Content\Blocks\Sources\EntryDraftsSource;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Style\Conversion\ConversionTables;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Palette\PaletteFixtures;

/**
 * The writers docs/internal/palette-lock-order.md leaves unfenced, pinned to the reason they need no
 * fence (custom palette spec §4.6): starter payloads carry no brand colour; the migration walkers
 * write through revision-conditional writes, so a write based on a read from before a replace rewrote
 * the document fails its condition; and no settings conversion ships.
 */
final class UnfencedWritersTest extends AppTestCase
{
    use PaletteFixtures;

    public function testStarterPayloadsCarryNoBrandColour(): void
    {
        $root = dirname(__DIR__, 4);
        $hits = [];
        foreach (['core/src/Content/Starter', 'skeleton'] as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($files as $file) {
                if (
                    $file->isFile() && in_array($file->getExtension(), ['php', 'json'], true)
                    && str_contains((string) file_get_contents($file->getPathname()), 'color.brand-')
                ) {
                    $hits[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }
        self::assertSame([], $hits, 'a starter that names a brand colour must seed through the palette fence');
    }

    public function testACasWriterCannotCarryAStaleReferencePastAReplaceRewrite(): void
    {
        $type = $this->container()->get(ContentTypeRepository::class)->create([
            'slug' => 'pfcas', 'name' => 'Cas', 'schema' => [['name' => 'body', 'type' => 'blocks']],
        ]);
        $entries = $this->container()->get(EntryRepository::class);
        $uuid = $entries->createEntry($type, 'en', 1, 'user00000001');
        $this->configure(1, 'Gold', '#8a6a2a');
        $entries->saveDraft($uuid, 'en', ['body' => [self::heading('color.brand-1')]], 1, 0, 'user00000001');
        $schema = ContentTypeSchema::fromArray([['name' => 'body', 'type' => 'blocks']]);
        $draft = $entries->findDraft($uuid, 'en');
        $revision = (string) $draft['lock_version'];
        $stale = new DocumentRef(EntryDraftsSource::ID, $uuid, 'en', $revision, $schema, (array) $draft['fields']);
        // the replace job rewrites the draft (its own conditional write bumps the revision)
        $source = $this->container()->get(EntryDraftsSource::class);
        self::assertTrue($source->persist($stale, ['body' => [self::heading('color.accent')]]));
        // a migration walker's write based on the earlier read fails its condition
        self::assertFalse($source->persist($stale, (array) $draft['fields']));
        self::assertSame(
            'color.accent',
            $entries->findDraft($uuid, 'en')['fields']['body'][0]['settings']['style']['colors']['text']['value'],
        );
    }

    public function testNoSettingsConversionShips(): void
    {
        self::assertSame([], ConversionTables::shipped()->all(), 'a shipped conversion must be fenced');
    }
}
