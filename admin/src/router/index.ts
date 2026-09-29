import { setupLayouts } from 'virtual:generated-layouts'
import { createRouter, createWebHistory } from 'vue-router'
import { routes, handleHotUpdate } from 'vue-router/auto-routes'
import { installAndAuthGuard } from './guard'
import { recoverFromModuleLoadFailure } from './moduleRecovery'

const router = createRouter({
  // Match Vite's `base` (/admin/) so routes resolve under /admin/ in both dev and production (the
  // SPA is served at /admin via serveFrontend). Without this, navigation drops the prefix and a
  // hard refresh lands outside the dev server's base ("did you mean /admin/setup?").
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: setupLayouts(routes),
})

router.beforeEach(installAndAuthGuard)

// A page whose module failed to load is reloaded once rather than left blank (see moduleRecovery).
router.onError((error, to) => {
  recoverFromModuleLoadFailure(error, router.resolve(to).href)
})

export default router

if (import.meta.hot) {
  handleHotUpdate(router)
}
