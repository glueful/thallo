# Releasing Thallo

> The distribution charter is `docs/internal/DISTRIBUTION.md` — its decisions bind this
> runbook. Tags are IMMUTABLE (decision 8): anything found after tagging becomes the next
> `beta.N+1` (or patch), never a mutated tag.

Since the package split (decisions 7 and 10) a release is FIFTEEN published artifacts, all at
the same version: `glueful/thallo` (the skeleton, what `create-project` installs),
`glueful/thallo-core` (the application) and the 13 `glueful/thallo-<pack>` packages. Each is a
read-only mirror repository fed by a subtree split of one directory of this repo; Packagist reads
the mirrors. Nobody commits to a mirror.

## Preconditions

- Clean tree on `dev`, full gates green: PHP suite (tests+skips are the gate), admin vitest,
  type-check, build, phpcs, boundaries, `composer test:distribution`, `composer test:skeleton`.
- All dependency releases published first (standing rule) and repinned.
- CHANGELOG's release section written (durable upgrade/security notes included).
- Mirror remotes exist locally as `split/<name>` (see "One-time setup").

## The release sequence

1. **Bake the admin bundle** (decision 9 — the core artifact must carry the built bundle):

       scripts/release-bake

2. **Create the release commit** (the changelog cut + the baked `core/resources/admin` the
   script staged):

       git commit -m "Release vX.Y.Z-beta.N — <name>"

3. **Verify every artifact** from the release commit — before splitting, always:

       scripts/verify-dist-archive

   Green means: the core archive ships `resources/admin/index.html` with the whole lucide set
   embedded and no `tests/`; the skeleton ships only the operator's tree and requires
   `glueful/thallo-core` at a `^` version with no path repositories; every pack ships
   `composer.json` + `src/` with no `version` field.

4. **Split and tag** — pins `skeleton/composer.json` to this core version (one commit),
   subtree-splits the 15 directories onto `split/<name>` branches and tags each:

       scripts/release-split vX.Y.Z-beta.N

5. **Push** — human step. The script printed the 15 pushes (mirror `main` + the tag); run them,
   or re-run with `--push`. Also tag and push the dev repo itself for history:

       scripts/release-split --push vX.Y.Z-beta.N
       git tag -a vX.Y.Z-beta.N -m "vX.Y.Z-beta.N — <name>" && git push origin dev vX.Y.Z-beta.N

6. **Publish** — Packagist updates each package from its mirror's webhook (first release of a
   mirror: submit it on Packagist once). `glueful/thallo` on Packagist points at the SKELETON
   mirror, never at this repo.

7. **Clean-machine gate** — after publication, in an empty directory with no sibling
   repositories, BOTH:

       composer create-project --prefer-dist --no-dev glueful/thallo t-gate vX.Y.Z-beta.N
       # and, in an install of the PREVIOUS tag:
       composer update && php glueful thallo:provision

   Confirm: the admin loads; the documented first-run sequence completes using only the public
   docs; the upgraded install reports zero pending migrations and serves the new admin.

8. **Website-from-tag gate**: the Thallo website + docs deploy from this exact tag
   (`scripts/deploy-site`), never the dev checkout. Announce only after gates 7 AND 8 pass.

## One-time setup (mirror remotes)

    for n in thallo-skeleton thallo-core thallo-contracts thallo-account thallo-analytics \
             thallo-collections thallo-commerce thallo-importers thallo-navigation thallo-render \
             thallo-search thallo-seo thallo-subscriptions thallo-tenancy thallo-workflow; do
      git remote add "split/$n" "git@github.com:glueful/$n.git"
    done

## After the release

- `git rm -r --cached core/resources/admin` is NOT needed — the gitignore keeps daily builds
  untracked; the next release's bake re-stages fresh output. If a stale baked bundle ever
  shows as modified on dev, that means a release commit was merged back — rebuild and re-bake
  at the next release rather than hand-editing.
- The `split/<name>` branches and `split/<name>/vX` tags are local bookkeeping; they may be
  deleted after the pushes.
- Record the release in the charter's checklist if it closes an item.
