# Style capability fixtures, v1

What a block type offers, expanded once (hover state spec §2.2.1). Both runtimes read this folder:

- `expansion.json` — a declaration (`style_capabilities`, `style_targets`) and the `style_paths` it
  publishes (`block` and each part's, in schema order), or the `error` its declaration raises.
  Run by `tests/Unit/Contracts/StyleCapabilityFixturesTest.php` (through `StyleTargets::stylePaths`)
  and `tests/Integration/Http/BlockTypeStylePathsTest.php` (through the block-type payload); the
  admin's `src/__tests__/style-capabilities.spec.ts` runs each expected `style_paths` through the
  Style tab.
- `multi-select.json` — sibling blocks selected together and the hover rows they share.
- `starters.json` — the shipped starters' real `style_paths`, recorded by `BlockTypeStylePathsTest`
  (`THALLO_RECORD_STYLE_PATHS=1`), so the admin's tests read real data without a server.
