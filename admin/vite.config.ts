import { fileURLToPath, URL } from 'node:url'

import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueDevTools from 'vite-plugin-vue-devtools'
import VueRouter from 'vue-router/vite'
import Layouts from 'vite-plugin-vue-layouts-next'
import ui, { type NuxtUIOptions } from '@nuxt/ui/vite'

import fs from 'fs'
import path from 'path'

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  const isDevelopment = mode === 'development'

  // Vite does NOT populate process.env from .env files — load them explicitly. '' = no prefix
  // filter, so VITE_SSL_KEY_PATH etc. are included alongside VITE_HOST/VITE_PORT.
  const { VITE_HOST, VITE_PORT, VITE_SSL_KEY_PATH, VITE_SSL_CERT_PATH } = loadEnv(
    mode,
    process.cwd(),
    '',
  )

  // @nuxt/ui@4.9.0 mistypes the Vite plugin's `icon` option as Pick<RuntimeOptions,'customize'|'size'|
  // 'mode'> with those keys REQUIRED — it both omits the documented `clientBundle.scan` build-time option
  // (ui.nuxt.com — icons/vue) and demands three props the plugin doesn't need. The runtime honours
  // `clientBundle`, so cast our real option through the plugin's accepted option type.
  const iconOptions = { clientBundle: { scan: true } } as unknown as NuxtUIOptions['icon']

  return {
    // The admin SPA is served by the PHP app at /admin (framework serveFrontend() seam), so assets
    // must resolve under /admin/ and deep-link routing uses the HTML5 history fallback there.
    base: '/admin/',
    build: {
      // Compiled bundle ships as public/admin/ (baked into release tags; gitignored in dev).
      outDir: fileURLToPath(new URL('../public/admin', import.meta.url)),
      emptyOutDir: true,
    },
    server: isDevelopment
      ? {
          // Bind ALL interfaces (not VITE_HOST): the Apache proxy connects over IPv4 127.0.0.1, but
          // a hostname like thallo.dev would bind IPv6 ::1 only (per /etc/hosts) and be unreachable.
          host: true,
          port: VITE_PORT ? parseInt(VITE_PORT, 10) : undefined,
          // The Apache proxy forwards Host: VITE_HOST (ProxyPreserveHost), so Vite 8 must allow it
          // or it rejects the proxied request as a disallowed host.
          allowedHosts: VITE_HOST ? [VITE_HOST] : undefined,
          https:
            VITE_SSL_KEY_PATH && VITE_SSL_CERT_PATH
              ? {
                  key: fs.readFileSync(path.resolve(VITE_SSL_KEY_PATH)),
                  cert: fs.readFileSync(path.resolve(VITE_SSL_CERT_PATH)),
                }
              : undefined,
          // When the SPA is fronted by a proxy (e.g. Apache at thallo.dev), the page origin isn't
          // the Vite origin, so point the HMR client straight at the Vite dev server. wss when Vite
          // serves https. host must match the TLS cert's domain for the wss handshake to validate.
          hmr: {
            host: VITE_HOST,
            protocol: VITE_SSL_KEY_PATH && VITE_SSL_CERT_PATH ? 'wss' : 'ws',
            clientPort: VITE_PORT ? parseInt(VITE_PORT, 10) : undefined,
          },
        }
      : undefined,

    plugins: [
      VueRouter({
        exclude: ['src/pages/**/components/**'],
      }),
      vue(),
      vueDevTools(),
      Layouts(),
      ui({
        colorMode: false, // Disable color mode support
        icon: iconOptions,

        ui: {
          colors: {
            // Thallo's own brand scale — the mirage tokens defined in assets/css/main.css.
            primary: 'mirage',
            // Success = the design mock's emerald green (Nuxt UI's default success is a limier green).
            success: 'emerald',
            neutral: 'slate'
          },
          modal: {
            slots: {
              content: 'divide-y-0',
            },
            variants: {
              fullscreen: {
                false: {
                  content: 'rounded-3xl',
                },
              },
            },
          },
        },
      }),
    ],
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url)),
      },
      // tiptap / ProseMirror must resolve to a SINGLE instance shared with @nuxt/ui's editor.
      // Importing @tiptap extensions directly (tables, task lists) otherwise pulls a second copy of
      // @tiptap/core + @tiptap/pm, and registering a keyed ProseMirror plugin from two instances
      // throws "Adding different instances of a keyed plugin".
      dedupe: [
        '@tiptap/core',
        '@tiptap/pm',
        '@tiptap/vue-3',
        'prosemirror-state',
        'prosemirror-view',
        'prosemirror-model',
        'prosemirror-transform',
        'prosemirror-keymap',
      ],
    },

    // Pre-bundle ProseMirror from @nuxt/ui's own copy so the editor and our extra @tiptap extensions
    // share one optimized instance (the @nuxt/ui > dep syntax is Vite's). This is @nuxt/ui's
    // documented fix for the "Adding different instances of a keyed plugin" ProseMirror error.
    optimizeDeps: {
      include: [
        '@nuxt/ui > prosemirror-state',
        '@nuxt/ui > prosemirror-transform',
        '@nuxt/ui > prosemirror-model',
        '@nuxt/ui > prosemirror-view',
        '@nuxt/ui > prosemirror-gapcursor',
        '@nuxt/ui > prosemirror-keymap',
      ],
    },

    // Other configurations can go here
  }
})
