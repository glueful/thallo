# Releasing Thallo

> The distribution charter is `docs/internal/DISTRIBUTION.md` — its decisions bind this
> runbook. Tags are IMMUTABLE (decision 8): anything found after tagging becomes the next
> `beta.N+1` (or patch), never a mutated tag.

## Preconditions

- Clean tree on `dev`, full gates green: PHP suite (tests+skips are the gate), admin vitest,
  type-check, build, phpcs, boundaries, `composer test:distribution`.
- All dependency releases published first (standing rule) and repinned.
- CHANGELOG's release section written (durable upgrade/security notes included).

## The release sequence

1. **Bake the admin bundle** (decision 9 — Packagist dists are `git archive` of the tag, so
   the release commit itself must contain the build):

       scripts/release-bake

2. **Create the release commit** (version identity per the repo's conventions + the baked
   `public/admin` the script staged):

       git commit -m "Release vX.Y.Z-beta.N — <name>"

3. **Verify the dist archive** — before tagging, always:

       scripts/verify-dist-archive

   Green means: `public/admin/index.html` ships; `admin/`, `docs/internal/`,
   `config/development/` do not.

4. **Tag (annotated) and push** — human step:

       git tag -a vX.Y.Z-beta.N -m "vX.Y.Z-beta.N — <name>"
       git push origin dev vX.Y.Z-beta.N

5. **Publish** — human step: Packagist submit/update (first release: the repository must be
   publicly readable first — an explicit decision, see the charter's public-Git curation item).

6. **Artifact clean-machine gate** — after publication, in an empty directory with no sibling
   repositories:

       composer create-project --prefer-dist --no-dev glueful/thallo t-gate vX.Y.Z-beta.N

   Confirm: no escaping path repositories or symlinks; the admin loads; the documented
   first-run sequence completes using only the public docs.

7. **Website-from-tag gate**: the Thallo website + docs deploy from this exact tag, never the
   dev checkout. Announce only after gates 6 AND 7 pass.

## After the release

- `git rm -r --cached public/admin` is NOT needed — the gitignore keeps daily builds
  untracked; the next release's bake re-stages fresh output. If a stale baked bundle ever
  shows as modified on dev, that means a release commit was merged back — rebuild and re-bake
  at the next release rather than hand-editing.
- Record the release in the charter's checklist if it closes an item.
