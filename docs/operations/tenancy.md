# Tenancy Operations

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
