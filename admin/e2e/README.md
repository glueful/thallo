# Builder proofs

Real-browser proofs of the visual builder's structural editing (visual builder spec §5.6): the
real Design page, served by the admin's Vite dev server with `VITE_E2E=1`, edits the five-deep
composition entry against API responses captured from the real controllers. Every request the
page makes is answered from the fixtures (`helpers.ts` routes the world) and every apply and
save body is recorded, so a proof asserts what the editor sent and what its history holds —
through the page's test hooks (`window.__thalloBuilder`, present only in an E2E build) — not
only what the stage shows.

What is proven, in Chromium:

- `stage-cross-container` — a stage drag moves a depth-five heading into another section's
  empty column: one `MoveBlock` from the real source position to the zone the slot's geometry
  named, in the tree, in history and in the apply body;
- `outline-reparent` — Move to… from the outline reparents a button; the coordinator judges it
  as a drag would;
- `rejected-depth-drop` — a card dropped into a depth-four container is refused before it
  lands: the indicator turns red with the reason, release cancels, the tree is byte-identical,
  history and the accepted pair are unchanged, no apply is sent;
- `deep-child-does-not-fit` — the moved subtree's root would fit, its deepest child would not:
  refused all the same, since the drop is judged on the whole candidate tree;
- `siblings-down-in-place` — two selected siblings move down as one transaction of
  `MoveBlock`s whose indices count against the working tree;
- `siblings-across-index-shift` — two root siblings drag together into another slot; the
  second block's `from` index is read after the first has left;
- `cancel` — Escape mid-drag discards the session with nothing changed;
- `palette-drop` (Phase C.1) — a heading dragged from the Blocks tab into an empty column
  inserts one `InsertBlock` carrying its starter and selects it; a heading released over a
  button-only slot is refused with the tree byte-identical, history and the accepted pair
  unchanged and no apply sent; a drag that hovers a column and is released over the site header
  inserts nothing, with the same four assertions;
- `palette-click` (Phase C.1) — the stage `+` arms the Blocks tab after the block, the search
  takes focus, and Enter inserts the first match there.
- `slot-add` — the + inside an empty stage slot arms the Blocks tab into that slot; the next tile
  click inserts there, the target is consumed, the apply carries the one insert.
- `layout-mode-switch` (container-layout §5) — the Layout tab reads the mode a container is really
  in, a switch writes the settings the contract expects at the breakpoint being edited, and the
  tracks survive the switch while the tab discloses that they are unused.
- `structure-picker` (container-layout §6) — a container inserted from the Blocks tab is offered
  its presets; a choice commits every operation as one transaction and undo and redo treat the
  preset as one thing; a preset too deep for its destination is offered disabled with its reason;
  content arriving consumes the offer, and a later choice commits nothing.

Two of these drive the editor through the page's own test hooks rather than the stage. The stage
here is the page captured at fixture-build time and an apply is answered with no fragments, so it
never re-renders: a container inserted during a proof has no tiles there to click, and a mode
switch changes no geometry. What the stage itself does with an offer, and what a mode change does
to the rendering, are proven where those things really happen —
`admin/src/__tests__/preview-bridge-dom.spec.ts` for the tiles and
`tools/runtime-browser/tests/layout.spec.js` for the geometry.

```
DB_PGSQL_DATABASE=app_test APP_ENV=testing php scripts/build-builder-proof-fixtures   # writes fixtures/ (gitignored)
cd admin && pnpm install                     # the dev server the proofs drive
cd e2e && pnpm install --ignore-workspace && pnpm run install-browsers
pnpm test
```

The fixture build seeds the test database (the same boot the integration suite uses) with the
page content type, the composition entry and the shipped block types, renders the entry in
canvas mode through the real render pack, and captures the block types, content types, draft,
style schema, style classes, capabilities and preview mint as the controllers return them.
`.github/workflows/builder-proofs.yml` runs the same steps on every change to the editor, the
bridge, the render templates or the proofs.
