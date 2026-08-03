import type { AdminModule } from './adminModules'
import { coreModule } from './coreModule'
import { collectionsModule } from './collectionsModule'
import { analyticsModule } from './analyticsModule'
import { workflowModule } from './workflowModule'
import { navigationModule } from './navigationModule'
import { regionsModule } from './regionsModule'
import { templatesModule } from './templatesModule'
import { commerceModule } from './commerceModule'
import { submissionsModule } from './submissionsModule'
import { tenancyModule } from './tenancyModule'
import { accountModule } from './accountModule'
import { subscriptionsModule } from './subscriptionsModule'

/**
 * The STATIC admin menu manifest: every module the sidebar can ever show, declared in
 * render order. Menus are never registered at runtime — visibility is the only dynamic
 * axis (per-module `requires` capability ids, evaluated against the capability store's
 * presentation hint). Because structure lives here in code, a deployment can never leave
 * stale labels/routes behind, and there is no registration-timing constraint: the list is
 * complete before any component evaluates it.
 *
 * Adding a module = add its declaration file and slot it into this list.
 */
export const adminManifest: readonly AdminModule[] = [
  coreModule,
  collectionsModule,
  analyticsModule,
  workflowModule,
  navigationModule,
  regionsModule,
  templatesModule,
  commerceModule,
  submissionsModule,
  tenancyModule,
  accountModule,
  subscriptionsModule,
]
