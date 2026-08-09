import './assets/css/main.css'
import './assets/print.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { PiniaColada } from '@pinia/colada'
import piniaPluginPersist from './plugins/pinia-persist-plugin'
import ui from '@nuxt/ui/vue-plugin'
import App from './App.vue'
import router from './router'
import { loadRuntimeConfig } from './runtime/config'

async function bootstrap() {
  // Runtime config MUST resolve before mount: the API client's baseUrl and the router guard's
  // `installed` check both read it synchronously after boot. A failure here is fatal — the SPA
  // cannot find its API without it (it is served by the PHP app at /admin, which exposes
  // /admin/config).
  await loadRuntimeConfig()

  const app = createApp(App)

  const pinia = createPinia()
  pinia.use(piniaPluginPersist)
  app.use(pinia)
  app.use(PiniaColada, {
    // Sensible server-state defaults; per-query overrides where needed.
    queryOptions: {
      staleTime: 30_000,
      gcTime: 5 * 60_000,
    },
  })
  // Hydrate the persisted (encrypted) session BEFORE installing the router. app.use(router) kicks
  // off the router's initial navigation — and its auth guard — synchronously during install. The
  // restore is async (Web Crypto) and Pinia doesn't await plugins, so unless the session is
  // hydrated first, that guard sees an empty session on a hard refresh and bounces a logged-in user
  // to /login. Imported dynamically (after loadRuntimeConfig) so the API client captures apiBase.
  const { useSessionStore } = await import('./stores/session')
  const restored = (useSessionStore() as unknown as { $persistRestored?: Promise<void> })
    .$persistRestored
  if (restored) await restored

  app.use(router)
  app.use(ui)
  app.mount('#app')
}

void bootstrap()
