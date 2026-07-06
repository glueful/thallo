<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Schema\ContentTypeSchema;
use App\Tests\Support\AppTestCase;

/**
 * Regression: the reference projection is keyed per (source entry, locale). Rebuilding or clearing
 * one locale must leave other locales' rows intact — otherwise publishing/unpublishing a single
 * locale of a multi-locale entry wipes the others' "what links here" rows and wrongly frees assets
 * still referenced by another locale's published version.
 */
final class ReferenceProjectionLocaleTest extends AppTestCase
{
    private const SCHEMA = [['name' => 'author', 'type' => 'reference']];

    private function projection(): ReferenceProjectionRepository
    {
        return new ReferenceProjectionRepository($this->connection());
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sourceUuid): array
    {
        return $this->connection()->table('entry_references')
            ->where('source_entry_uuid', '=', $sourceUuid)
            ->orderBy('id', 'ASC')
            ->get();
    }

    public function testRebuildingOneLocaleLeavesOtherLocaleRowsIntact(): void
    {
        $schema = ContentTypeSchema::fromArray(self::SCHEMA);
        $repo = $this->projection();

        $repo->rebuildForEntry('source000001', $schema, ['author' => 'targetenaaaa'], 'en');
        $repo->rebuildForEntry('source000001', $schema, ['author' => 'targetfraaaa'], 'fr');

        // Re-publishing 'en' with a new target must NOT touch the 'fr' row.
        $repo->rebuildForEntry('source000001', $schema, ['author' => 'targetenbbbb'], 'en');

        $byLocale = [];
        foreach ($this->rows('source000001') as $r) {
            $byLocale[(string) $r['locale']] = (string) $r['target_entry_uuid'];
        }
        ksort($byLocale);
        self::assertSame(['en' => 'targetenbbbb', 'fr' => 'targetfraaaa'], $byLocale);
    }

    public function testClearingOneLocaleKeepsReferenceProtectionFromAnotherLocale(): void
    {
        $schema = ContentTypeSchema::fromArray(self::SCHEMA);
        $repo = $this->projection();

        // Both the en and fr published versions of the source link the SAME target (asset/entry).
        $repo->rebuildForEntry('source000001', $schema, ['author' => 'sharedtgtaa1'], 'en');
        $repo->rebuildForEntry('source000001', $schema, ['author' => 'sharedtgtaa1'], 'fr');
        self::assertSame(['source000001'], $repo->referencesTo('sharedtgtaa1'));

        // Unpublishing just 'en' clears only its rows; 'fr' still links the target, so it stays
        // protected ("what links here" still reports the source).
        $repo->clearForEntryLocale('source000001', 'en');

        self::assertSame(['source000001'], $repo->referencesTo('sharedtgtaa1'));
        $remaining = array_map(static fn(array $r): string => (string) $r['locale'], $this->rows('source000001'));
        self::assertSame(['fr'], $remaining);
    }

    public function testClearForEntryRemovesAllLocales(): void
    {
        $schema = ContentTypeSchema::fromArray(self::SCHEMA);
        $repo = $this->projection();

        $repo->rebuildForEntry('source000001', $schema, ['author' => 'targetenaaaa'], 'en');
        $repo->rebuildForEntry('source000001', $schema, ['author' => 'targetfraaaa'], 'fr');

        // Whole-entry delete drops every locale's rows.
        $repo->clearForEntry('source000001');

        self::assertSame([], $this->rows('source000001'));
    }
}
