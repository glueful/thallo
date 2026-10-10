<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support\Palette;

use Thallo\Contracts\Style\Palette;
use Thallo\Core\Content\Blocks\Sources\BlockDocumentSource;
use Thallo\Core\Content\Layouts\LayoutRepository;
use Thallo\Core\Content\Layouts\LayoutWriteLock;
use Thallo\Core\Content\Palette\PaletteDocumentSources;
use Thallo\Core\Content\Palette\PaletteJobRepository;
use Thallo\Core\Content\Palette\PaletteMutations;
use Thallo\Core\Content\Palette\PaletteReplaceRunner;
use Thallo\Core\Content\Palette\PaletteReplaceService;
use Thallo\Core\Content\Patterns\SavedSectionRepository;
use Thallo\Core\Content\Regions\RegionRepository;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Services\PublishService;
use Thallo\Core\Content\Style\Classes\StyleClassRepository;
use Thallo\Core\Settings\PaletteSettings;

/**
 * The Replace job's world (custom palette plan Task 14): documents of every blocking kind naming a
 * brand colour, readers of where each colour landed, and the service, runner and repository — the
 * runner built by hand around a document source double where a proof needs one.
 *
 * The using class also uses PaletteFixtures and SyncsBlockStyleDeclarations, and calls
 * `$this->replaceWorld()` in setUp.
 */
trait PaletteReplaceFixtures
{
    private string $replaceType = '';

    protected function replaceWorld(): void
    {
        $this->syncBlockStyleDeclarations();
        $this->replaceType = $this->container()->get(ContentTypeRepository::class)->create([
            'slug' => 'pfrep', 'name' => 'Page', 'public_delivery' => true,
            'schema' => [['name' => 'title', 'type' => 'string'], ['name' => 'body', 'type' => 'blocks']],
        ]);
    }

    protected function service(): PaletteReplaceService
    {
        return $this->container()->get(PaletteReplaceService::class);
    }

    protected function runner(): PaletteReplaceRunner
    {
        return $this->container()->get(PaletteReplaceRunner::class);
    }

    /** The runner with one source replaced by a double (same id). */
    protected function runnerWith(BlockDocumentSource $double): PaletteReplaceRunner
    {
        return $this->runner()->withSources(
            $this->container()->get(PaletteDocumentSources::class)->replacing($double),
        );
    }

    protected function jobs(): PaletteJobRepository
    {
        return $this->container()->get(PaletteJobRepository::class);
    }

    protected function mutations(): PaletteMutations
    {
        return $this->container()->get(PaletteMutations::class);
    }

    /** The palette as stored now (another process may have written it). */
    protected function palette(): Palette
    {
        $this->container()->get(\Thallo\Core\Settings\GeneralSettings::class)->clearStoreCache();
        return $this->container()->get(PaletteSettings::class)->palette();
    }

    /**
     * The audit entry a replacement recorded (audit_logs outlives a test, so it is found by its job).
     *
     * @return array{action: string, label: ?string}|null
     */
    protected function replacedAudit(string $job): ?array
    {
        $rows = $this->connection()->table('audit_logs')->where('action', '=', 'palette.brand.replaced')->get();
        foreach ($rows as $row) {
            if (str_contains((string) $row['context'], $job)) {
                return ['action' => (string) $row['action'], 'label' => $row['target_label'] ?? null];
            }
        }
        return null;
    }

    /** @return array<string,mixed> the context of a replacement's audit entry */
    protected function replacedAuditContext(string $job): array
    {
        $rows = $this->connection()->table('audit_logs')->where('action', '=', 'palette.brand.replaced')->get();
        foreach ($rows as $row) {
            if (str_contains((string) $row['context'], $job)) {
                return json_decode((string) $row['context'], true) ?: [];
            }
        }
        return [];
    }

    protected function repo(): EntryRepository
    {
        return $this->container()->get(EntryRepository::class);
    }

    /** @return array{0: string, 1: int} */
    protected function entry(): array
    {
        $uuid = $this->repo()->createEntry($this->replaceType, 'en', 1, 'user00000001');
        return [$uuid, $this->lockOf($uuid)];
    }

    protected function lockOf(string $uuid): int
    {
        return (int) ($this->repo()->findDraft($uuid, 'en')['lock_version'] ?? 0);
    }

    /** @param list<array<string,mixed>> $body */
    protected function saveBody(string $uuid, array $body, string $title = 'Page'): void
    {
        $fields = ['title' => $title, 'body' => $body];
        $this->repo()->saveDraft($uuid, 'en', $fields, 1, $this->lockOf($uuid), 'user00000001');
    }

    protected function draftNaming(string $token): string
    {
        [$uuid] = $this->entry();
        $this->saveBody($uuid, [self::heading($token)]);
        return $uuid;
    }

    /** @return list<string> */
    protected function draftsNaming(string $token, int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $this->draftNaming($token);
        }
        return $out;
    }

    protected function countDraftsNaming(string $token): int
    {
        $n = 0;
        foreach ($this->connection()->table('entry_drafts')->get() as $row) {
            if (str_contains((string) $row['fields'], '"' . $token . '"')) {
                $n++;
            }
        }
        return $n;
    }

    /** A draft row written without the fence, as a pre-palette writer would. */
    protected function storeDraftRawNaming(string $token): string
    {
        $uuid = $this->draftNaming('color.accent');
        $this->connection()->table('entry_drafts')->where('entry_uuid', '=', $uuid)->where('locale', '=', 'en')
            ->update(['fields' => json_encode(['title' => 'Page', 'body' => [self::heading($token)]])]);
        return $uuid;
    }

    /** @return array{0: string} an entry whose draft and current publication name the token */
    protected function publishedEntryNaming(string $token): array
    {
        $uuid = $this->draftNaming($token);
        $this->container()->get(PublishService::class)->publish($uuid, 'en', 'user00000001');
        return [$uuid];
    }

    /** A new publication naming the token; returns the version it displaced (history from now on). */
    protected function retainedVersionNaming(string $uuid, string $token): string
    {
        $old = $this->publishedVersionUuid($uuid);
        $this->saveBody($uuid, [self::heading($token)], 'Page again');
        $this->container()->get(PublishService::class)->publish($uuid, 'en', 'user00000001');
        return $old;
    }

    protected function publishedVersionUuid(string $uuid): string
    {
        return (string) ($this->connection()->table('entry_publications')->where('entry_uuid', '=', $uuid)
            ->where('locale', '=', 'en')->first()['version_uuid'] ?? '');
    }

    protected function versionCount(string $uuid): int
    {
        return $this->connection()->table('entry_versions')->where('entry_uuid', '=', $uuid)->count();
    }

    /** @return array<string,mixed> */
    protected function draftFields(string $uuid): array
    {
        return (array) ($this->repo()->findDraft($uuid, 'en')['fields'] ?? []);
    }

    protected function draftToken(?string $uuid = null): ?string
    {
        $newest = $this->connection()->table('entry_drafts')->orderBy('updated_at', 'DESC')->first();
        $uuid ??= (string) ($newest['entry_uuid'] ?? '');
        return $this->draftFields($uuid)['body'][0]['settings']['style']['colors']['text']['value'] ?? null;
    }

    protected function versionToken(string $versionUuid): ?string
    {
        $row = $this->connection()->table('entry_versions')->where('uuid', '=', $versionUuid)->first();
        $fields = json_decode((string) ($row['fields'] ?? '{}'), true) ?: [];
        return $fields['body'][0]['settings']['style']['colors']['text']['value'] ?? null;
    }

    protected function publishedToken(string $uuid): ?string
    {
        return $this->versionToken($this->publishedVersionUuid($uuid));
    }

    protected function regionStyleNaming(string $slug, string $token): void
    {
        $this->container()->get(RegionRepository::class)->save(
            $slug,
            [],
            ['style' => ['colors' => ['text' => self::tok($token)]]],
            'user00000001',
        );
    }

    protected function regionStyleToken(string $slug): ?string
    {
        $row = $this->container()->get(RegionRepository::class)->find($slug);
        return $row['settings']['style']['colors']['text']['value'] ?? null;
    }

    protected function layoutFrameNaming(string $surface, string $target, string $token): void
    {
        $layouts = $this->container()->get(LayoutRepository::class);
        $this->container()->get(LayoutWriteLock::class)->within($surface, $target, fn () => $layouts->saveExpected(
            $surface,
            $target,
            [['id' => 'laybody00001', 'type' => 'entry_content', 'data' => ['field' => 'body'], 'settings' => []]],
            ['style' => ['colors' => ['surface' => self::tok($token)]]],
            0,
            'user00000001',
        ));
    }

    protected function layoutFrameToken(string $surface, string $target): ?string
    {
        $row = $this->container()->get(LayoutRepository::class)->find($surface, $target);
        return $row['settings']['style']['colors']['surface']['value'] ?? null;
    }

    protected function savedSectionNaming(string $name, string $token): string
    {
        return $this->container()->get(SavedSectionRepository::class)
            ->create($name, 'Saved', null, self::heading($token), null, 'user00000001');
    }

    protected function sectionToken(string $name): ?string
    {
        $row = $this->connection()->table('saved_sections')->where('name', '=', $name)->first();
        $block = json_decode((string) ($row['block'] ?? '{}'), true) ?: [];
        return $block['settings']['style']['colors']['text']['value'] ?? null;
    }

    /** @return string the class id */
    protected function styleClassNaming(string $name, string $token): string
    {
        $class = $this->container()->get(StyleClassRepository::class)
            ->create(['name' => $name, 'style' => ['colors' => ['text' => self::tok($token)]]]);
        return (string) $class['id'];
    }

    protected function classToken(string $id, string $path): ?string
    {
        $row = $this->connection()->table('style_classes')->where('id', '=', $id)->first();
        $node = json_decode((string) ($row['style'] ?? '{}'), true) ?: [];
        foreach (explode('.', $path) as $segment) {
            $node = is_array($node) ? ($node[$segment] ?? null) : null;
        }
        return is_array($node) ? ($node['value'] ?? null) : null;
    }

    protected function animatedTextDraftNaming(string $token): string
    {
        [$uuid] = $this->entry();
        $this->saveBody($uuid, [[
            'id' => 'anim00000001', 'type' => 'animated_text',
            'data' => ['prefix' => 'We make', 'words' => ['things'], 'prefix_color' => self::tok($token)],
            'settings' => [],
        ]]);
        return $uuid;
    }

    protected function animatedTextToken(): ?string
    {
        foreach ($this->connection()->table('entry_drafts')->get() as $row) {
            $fields = json_decode((string) $row['fields'], true) ?: [];
            foreach ((array) ($fields['body'] ?? []) as $block) {
                if (($block['type'] ?? null) === 'animated_text') {
                    return $block['data']['prefix_color']['value'] ?? null;
                }
            }
        }
        return null;
    }
}
