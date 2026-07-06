# Removing glueful/thallo-importers

`composer remove glueful/thallo-importers` (and drop its path-repo entry) removes the
CSV/Markdown/WordPress + user import adapters. After removal:

- The headless CMS core boots; content delivery and the admin work.
- **Snapshot export/import still works** — `ContentExporter` / `ContentImporter`,
  the `/v1/admin/import-export/upload|download` endpoints, and the snapshot UI are core-owned.
- The `thallo.importers` capability is absent from `GET /v1/admin/capabilities`, so the
  format-import admin section and the users bulk-CSV import hide automatically (Phase C gating).
- `composer boundaries` stays green.

Disabling without removing: set `'thallo.importers' => false` in `config/thallo.php`'s
`capabilities` switchboard — the adapters' admin surface hides, code stays installed.
