# Tenancy Operations

## Provider Model

Tenancy has two providers with different lifecycles:

- `Glueful\Extensions\Tenancy\TenancyControlPlaneProvider` is always loaded from
  `config/serviceproviders.php`. It provides the tenant registry migrations, provisioning,
  administration, domain lifecycle, and tenant-context runner even when row-level enforcement is
  off.
- `Glueful\Extensions\Tenancy\TenancyServiceProvider` is the optional enforcement provider in
  `config/extensions.php`. The enablement machine adds it only after retrofit succeeds and removes
  it during disable. It owns tenant resolution, middleware, table scoping, the query guard, and
  insert stamping.

For upgrades to `glueful/tenancy` 2.0.0 or later, ensure the control-plane provider appears before
`App\Providers\ThalloServiceProvider`:

```php
return [
    'enabled' => [
        'Glueful\\Extensions\\Tenancy\\TenancyControlPlaneProvider',
        'App\\Providers\\ThalloServiceProvider',
    ],
];
```

`php glueful extensions:enable tenancy` manages only the enforcement provider; it cannot add the
always-on control-plane provider. A deployment upgrading an existing installation must merge the
`config/serviceproviders.php` entry explicitly.

Normal workers use the persisted `SystemFlags::enforcementActive()` state, not service-binding
presence, to decide whether tenant-aware work is permitted. `RELOADING`, `FINALIZING`, and
`ENABLING_ENFORCEMENT` remain barrier-protected transition states rather than normal operation.

## Workspace Roles

Built-in workspace roles inherit the live global `tenancy.role_matrix`. Workspace-specific grants
and revocations are stored as deltas, so removing an override restores inheritance and future global
capabilities continue to flow into customized workspaces. The `owner` role always retains
`tenant.roles.manage` and `tenant.members.manage`; attempts to revoke that governance floor are
rejected.

Custom workspace roles use explicit grants only. Disabling one immediately removes all effective
capabilities while retaining memberships. Deleting an assigned role requires an atomic reassignment.
Role slugs are immutable; display names may change.

Export or validate the deployed data-only policy manifest:

```bash
php glueful thallo:policy:manifest --export > policy.json
php glueful thallo:policy:manifest --validate policy.json
php glueful thallo:policy:manifest --compare old.json --compare new.json
```

Use the Roles page for normal workspace governance. The operator reset endpoint is emergency tooling:
it requires both `tenancy.manage` and `tenancy.access_any`, explicit operator mode, and emits a
target-naming audit record.

## Purge Recovery

Workspace purge runs are durable and lease-owned. Run this command periodically and after queue
outages to redispatch requested, failed, or lease-expired runs:

```bash
php glueful thallo:tenancy:purge:recover
```

The purge worker is idempotent. It persists prepare artifacts before deletion and resumes from the
durable run ledger. A workspace record is removed only after every product-data handler verifies.

## Host Cooldown Sweep

Queue the cooldown tombstone sweep daily:

```bash
php glueful thallo:tenancy:hosts:sweep
```

Expired tombstones do not block claims even before this sweep runs. The job is housekeeping only;
it rechecks each candidate while holding the same per-host advisory lock used by claims/releases.

Automatic workspace purge remains disabled by default. Operators explicitly purge trashed
workspaces after reviewing the irreversible action.
