# Collections Tenancy: Local Development Adoption

This repository is pre-release. Existing local `coll_*` tables use the retired global naming model
and are not converted automatically. Reset local collection data before testing tenant-scoped
collections.

1. Back up any local collection data that must be retained.
2. Drop physical tables whose names match `coll_*` only. Do not wildcard-drop `tc_*` tables.
3. Clear `collection_schema_changes` and `collection_definitions`.
4. Reset and migrate the development database from zero.
5. Run setup, or run `thallo:tenancy:single-store:repair --owner=<user-uuid>` on an existing install.
6. Recreate collections through the admin/API surface. New physical names match
   `tc_<tenant-token>_<collection-token>`.

Example PostgreSQL inspection before the destructive step:

```sql
SELECT tablename
FROM pg_tables
WHERE schemaname = current_schema()
  AND tablename LIKE 'coll\_%' ESCAPE '\';
```

This is a local-only adoption procedure, not a production data migration.
