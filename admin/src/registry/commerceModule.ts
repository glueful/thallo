import type { AdminModule } from './adminModules'

// Commerce admin module — Task 9 (admin-commerce-area plan, slice 3): scaffold-only
// registration, gated on the `thallo.commerce` capability. Deliberately has NO `nav` key:
// registering it here (rather than skipping registration entirely) lets Task 10/11 land
// query/page work under a stable, already-gated module id, but the sidebar must not show a
// dead-end entry before there is anything behind it. Task 12 atomically adds
// `nav: { main: [...] }` (Commerce → Products) once Products AND Linking both land —
// design spec §6/§9 calls this out as the first user-visible activation boundary.
export const commerceModule: AdminModule = { id: 'commerce', requires: ['thallo.commerce'] }
