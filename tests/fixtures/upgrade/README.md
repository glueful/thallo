# Upgrade fixtures

`beta28.sql` is a full dump (schema and data) of a `glueful/thallo` 1.0.0-beta.28 install from
Packagist, seeded by `scripts/upgrade-fixture-seed.php` through that release's own repositories
with the content the visual-builder conversion must handle: two drafts, two published entries
with two retained versions each, header and footer regions, and every legacy presentation
value the spec's §7.2 conversion table names, mappable (heading `align`, button `shape`, padding
presets) and unmappable (a hex heading colour, three hex animated-text colours, a `17px` padding
box, a hex overlay, `min_height_px`, `gap`, the style block's hex shadow colour and opacity, a
carousel `transition_duration`). `beta28.manifest.json` lists the published routes.

Rebuild with `scripts/build-upgrade-fixture` (network, composer, `createdb`, `pg_dump`).
Rehearse with `composer test:upgrade`, never concurrently with the PHP suite (same database).
The rehearsal restores the fixture into the test database, runs this checkout's migrations,
runs `thallo:doctor`, and proves every manifest route still renders. Later slices add the
converter's dry run, durable decisions, live run, interrupted run and restore scenarios
(spec §7.4, plan A4.7 and A5.4).
