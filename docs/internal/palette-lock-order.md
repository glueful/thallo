# Palette lock order and writer inventory

Custom palette spec §4.3–§4.6. Every path that takes the palette row takes it FIRST.

## Lock order

1. `palette_state` row: `UPDATE palette_state SET generation = generation` (PaletteState::lock()), or
   `generation = generation + 1` for a palette mutation (PaletteState::bump()).
2. Advisory document locks, each path's existing order unchanged:
   - entry versions: `pg_advisory_xact_lock(thallo:entry_versions:{entry}:{locale})` (VersionRepository::reserveNextVersionNumber)
   - regions: `pg_advisory_xact_lock(crc32('thallo:regions'))` (RegionWriteLock)
   - layouts: type `thallo:layouts:type:{slug}` then layout `thallo:layouts:{surface}:{target}` (LayoutWriteLock)
3. `style_classes` rows (StyleClassReferenceGuard; StyleClassRepository::bump()).
4. Document rows: `entry_drafts` / `entry_versions` / `entry_publications` / `regions` / `layouts` /
   `saved_sections` conditional updates.
5. `style_generations` row (SiteStyleGeneration::incrementWithin).

`PaletteFence` refuses to take (1) inside a transaction that has not already taken it
(`LogicException`), so a path that would take (1) after (2)–(5) fails in tests, not in production.
Settings writes (`settings` table, `AppearanceLock`) are never taken while (1) is held, except the
palette mutations' own settings write, which happens after (1) and takes no other lock.

## Writer inventory

| Writer | Path | Fenced | Basis on mismatch | Notes |
|---|---|---|---|---|
| Editor draft save | EntryController::saveDraft → EntryRepository::saveDraft | yes | current draft ∪ the draft's persisted restore_basis | Task 10, 12; returns palette_rewrites, palette_replacements and palette_generation |
| Authoring engine | EngineContentWriter::createDraft / EngineContentUpserter::updateDraft → saveDraft | yes (via saveDraft) | current draft | importers: CSV, Markdown, WordPress, Markdown folder |
| Locale copy | EntryRepository::createLocaleDraft | yes (+ CAS on overwrite) | source draft, re-read | Task 10 |
| Publish / publishStarter | PublishService::publishInternal | yes | the draft, re-read | Task 10 |
| Scheduled publish | ScheduleRunner::fire → PublishService::publish | yes (via publish) | the draft, re-read | claim transaction commits first |
| Rollback | PublishService::rollback | yes | the version, server-loaded | changed fields force append-a-version |
| Restore to draft | POST /entries/{uuid}/draft/{locale}/restore (new) | yes | current draft ∪ the version, server-loaded; persists the server-derived restore_basis on the draft | Task 12 |
| Region save | RegionSaver::save / RegionRepository::saveExpected | yes | current regions | Task 11 |
| Region save (unconditional) | RegionRepository::save | no | — | RegionKind / RetireAccountLinkCommand: reads and writes under the region lock; starter payloads have no brand token |
| Layout save | LayoutSaver::save | yes | current layout | Task 11 |
| Layout rebinding | LayoutBindings (content-type migrations) | no | — | renames/deletes bindings only; under layout locks |
| Saved section create | SavedSectionController::store | yes | none (new) | Task 11 |
| Saved section block | SavedSectionRepository::replaceBlock | yes | current section | Task 11 |
| Style class save | StyleClassController::store / update | yes | current class | Task 11 |
| Style class detach job | StyleClassJobRunner::process (detach) | yes | current document | copies class values into blocks — Task 11 |
| Style class remove job | StyleClassJobRunner::process (remove) | no | — | removes a class reference, adds no value; CAS |
| Content bundle import: entry_draft | ContentImporter::upsert | yes | stored draft | per-record refusal — Task 11 |
| Content bundle import: entry_version | ContentImporter::upsert | yes when it is the current publication; history stored as given | the stored version | Task 11 |
| Content bundle import: entry_publication | ContentImporter::upsert | yes (a pointer change is a rollback) | the referenced version, server-loaded; a changed one is appended and pinned | Task 11 |
| Block-type migration | BlockBackfillRunner::process | no | — | Delete/Rename on data fields; CAS persist |
| Content-type migration | BackfillRunner::processDraft / processPublished | no | — | DeleteField/RenameField; CAS / pin re-check |
| Settings converter | SettingsConversion::apply | no | — | no shipped stage; conversion tables predate brand tokens; CAS persist |
| Tenant seed / starter sync | TenantSeeder / StarterSync kinds | no | — | starter payloads carry no brand token (pinned) |
| Entry create / discard / delete / unpublish | EntryRepository / PublishService | no | — | write no style values |
| Replace job | PaletteReplaceRunner (queued as RunPaletteReplaceJob, inside its workspace) | yes (forced, job id asserted; every status change a guarded transition) | current document | Task 14 |
| Palette mutations | PaletteMutations (configure, rename, re-colour, clear, reset) | takes and bumps the row | — | Task 13 |

## Storage classes

The classes that issue the writes for the rows above. A storage class takes no palette lock of its
own: the writer row that calls it decides whether the write is fenced.

| Class | Writes | Called by |
|---|---|---|
| EntryRepository | entry_drafts | editor draft save, authoring engine, locale copy, restore to draft |
| VersionRepository | entry_versions, entry_publications | publish, rollback, content-type migrations, PublishedEntriesSource |
| EntryDraftsSource | entry_drafts (CAS) | block-type migrations, style class jobs, the settings converter, the replace job |
| EntryVersionsSource | entry_versions (CAS, in place) | style class jobs, the settings converter (never the replace job: history is not rewritten) |
| PublishedEntriesSource | entry_versions + entry_publications (append-and-repin) | block-type migrations, style class jobs, the replace job |
| RegionsSource | regions.blocks (CAS) | block-type migrations, style class jobs, the replace job |
| RegionRepository | regions | region save, RegionKind, RetireAccountLinkCommand |
| LayoutRepository | layouts | layout save, LayoutBindings, LayoutsSource |
| LayoutsSource | layouts.blocks (CAS) | block-type migrations, style class jobs, the replace job |
| SavedSectionRepository | saved_sections | saved section create, SavedSectionsSource |
| StyleClassRepository | style_classes | style class save, style class jobs, the replace job |
| ContentImporter | entry_drafts, entry_versions, entry_publications | content bundle import |
| BackfillRunner | entry_drafts, entry_versions, entry_publications | content-type migrations |
