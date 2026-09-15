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
- `cancel` — Escape mid-drag discards the session with nothing changed.

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
