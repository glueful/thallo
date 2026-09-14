# Upgrade fixtures

`beta28.sql` is a full dump (schema and data) of a `glueful/thallo` 1.0.0-beta.28 install from
Packagist, seeded by `scripts/upgrade-fixture-seed.php` through that release's own repositories
with the content the visual-builder conversion must handle: two drafts, two published entries
with two retained versions each, header and footer regions, and every legacy presentation
value the spec's §7.2 conversion table names, mappable (heading `align`, button `shape`, padding
presets) and unmappable (a hex heading colour, three hex animated-text colours, a `17px` padding
box, a hex overlay, `min_height_px`, `gap`, the style block's hex shadow colour and opacity, a
carousel `transition_duration`). `beta28.manifest.json` lists the published routes.

`beta28.decisions.json` holds the decisions for every unmappable value the converter reports
on the fixture, keyed by the diagnostic identity and pinned to each document's hash (recorded by
`scripts/upgrade-fixture-decide.php`: hex colours become the accent token, a pixel width the
container width token, a pixel height is discarded). `after-group-1.sql` is the fixture after
conversion group one, the starting point of the next slice's sequential scenario.

Rebuild the fixture with `scripts/build-upgrade-fixture` (network, composer, `createdb`,
`pg_dump`); re-record the decisions and the snapshot with
`REHEARSAL_RECORD_DECISIONS=1 REHEARSAL_SNAPSHOT=1 composer test:upgrade`.
Rehearse with `composer test:upgrade`, never concurrently with the PHP suite (same database):
four scenarios, each from a fresh restore and this checkout's migrations — dry run, decisions,
live, stamps and rendering; an interrupted live run and its rerun landing on the identical
state; a document edited after review making its decision stale; restore from backup returning
the pre-state (spec §7.4).
