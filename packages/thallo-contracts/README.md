# glueful/lemma-contracts

Thin, stable contracts (interfaces, DTOs, events, VOs) that Lemma capability packs
compile against. **No engine logic, storage, or I/O.**

## Stability policy

- Strict semver. Additive change = minor; any interface / DTO / event / capability-id
  break = major.
- **0.x freeze trigger:** this package stays `0.x` while only first-party packs exist.
  It moves to `1.0` only **before** documenting third-party pack authoring or accepting
  external packs — so the seams are proven (by the Phase D reference extraction) first.

## Boundary rule

A pack may depend on `glueful/lemma-contracts`, `glueful/framework`, and pack-specific
deps — **never on `glueful/lemma`** (the engine app). Enforced by
`composer boundaries` (`scripts/check-pack-boundaries.php`).
