# Per-Locale RBAC

Thallo authorizes locale-specific admin actions against the locale they target. The
`content_permission` middleware derives the Aegis resource from the matched route: a route carrying
`{locale}` is checked against `locale:<code>`; every other route keeps the coarse `thallo`
resource. Permission names do not change.

## Backward Compatibility

The seeded roles (`admin`, `editor`, `viewer`) grant permissions with no
resource filter, so they match every resource string. A user holding one of those roles can act on
every locale exactly as before; only the authorization audit resource changes on locale routes.

## Global Grants Win

Aegis authorization is permissive OR over all matching grants. A single unscoped grant overrides
any locale-scoped grant. To restrict a user to one locale, assign only locale-filtered grants and
do not assign the coarse seeded role.

## French-Only Editor Recipe

1. Do not assign the global `editor` role.
2. Create a locale role such as `editor_fr`.
3. Grant that role the needed Thallo permissions with `resource_filter = {"resource":"locale:fr"}`:
   - `thallo.entries.read` for locale read routes such as `GET .../draft/fr`,
     `GET .../versions/fr`, and `POST .../preview/fr`.
   - `thallo.entries.write` for saving/discarding drafts, creating locale drafts, and managing
     routes for `fr`.
   - `thallo.entries.publish` for publish, unpublish, and rollback for `fr`.
4. Assign the user to `editor_fr`.

Use one role per locale. Aegis dedupes role-permission rows by role and permission, not by
resource filter, so do not stack `locale:fr` and `locale:de` filters for the same permission on
one role. A French+German editor should receive both `editor_fr` and `editor_de`.

## Discovery Boundary

Routes without a target locale still authorize against `thallo`: entry show, locale inventory,
route inventory, entry create/delete, and content-type management. A user with only `locale:fr`
grants can edit a known `/draft/fr` URL but cannot discover all locales/routes or open the
entry-show view.

Granting a coarse `thallo.entries.read` restores that admin discovery UX, but also allows reading
all locales. Write and publish permissions can remain locale-scoped.

## Out of Scope

- A Thallo UI/API for assigning per-locale grants.
- Per-content-type scoping such as `content-type:<slug>`.
