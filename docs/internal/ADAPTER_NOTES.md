# Thallo Adapter Notes

Thallo should keep content-domain adapters outside `glueful/import-export`. That package is the engine; Thallo owns how external content maps to entries, fields, assets, taxonomies, locales, and publishing states.

## WordPress

A WordPress importer should translate posts, pages, media, authors, categories, tags, custom post types, and metadata into Thallo content models.

Recommended behavior:

- Plan batches by stable WordPress object IDs.
- Store source IDs in adapter metadata so repeated imports can upsert.
- Map WordPress slugs to Thallo routes only inside Thallo.
- Import media through Thallo's asset/storage path, then reference assets from entries.
- Treat comments as out of scope unless Thallo adds a comment model.

## Markdown And MDX

A Markdown/MDX importer should treat files as source documents and front matter as structured fields.

Recommended behavior:

- Plan batches by file path.
- Parse front matter into fields before validation.
- Preserve body content as a rich text, markdown, or block field depending on the target model.
- Resolve local image references through Thallo media handling.
- Keep MDX component validation in Thallo, not the generic engine.

## CSV To Entries

A CSV importer should map columns to a selected content model.

Recommended behavior:

- Require an explicit field mapping.
- Validate every row against Thallo field rules.
- Support dry-run mode for validation previews.
- Use stable key columns for upsert behavior.
- Report row-level errors through the engine's error repository.

## Boundary

`glueful/import-export` should not know about Thallo entries, content models, locales, slugs, revisions, workflows, or preview URLs. Thallo adapters can use the engine's source, options, batching, retry, reporting, and CLI/API surfaces while keeping content semantics in Thallo.
