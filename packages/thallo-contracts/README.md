# glueful/thallo-contracts

Thin, stable contracts (interfaces, DTOs, events, VOs) that Thallo capability packs
compile against. **No engine logic, storage, or I/O.**

## Stability policy

- Strict semver. Additive change = minor; any interface / DTO / event / capability-id
  break = major.
- **Released in lockstep:** the package carries the monorepo's version, and every first-party
  pack and `glueful/thallo-core` require it at `self.version`.

## Boundary rule

A pack may depend on `glueful/thallo-contracts`, `glueful/framework`, and pack-specific
deps — **never on `glueful/thallo`** (the engine app). Enforced by
`composer boundaries` (`scripts/check-pack-boundaries.php`).

## Contributing

This repository is a read-only mirror, published from
[glueful/thallo](https://github.com/glueful/thallo) on every release; its `main` is overwritten
by the next split, so nothing can land here. Issues and pull requests belong in glueful/thallo,
where this code lives at `packages/thallo-contracts/`.
