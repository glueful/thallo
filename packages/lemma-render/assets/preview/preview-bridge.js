// Lemma canvas bridge (visual-canvas spec §3 + stage-toolbar spec §1–§3).
// SILENT until a canvas parent says hello; a plain preview tab never messages
// anyone. The nonce is a correlation token, not auth — it stops stale frames/
// same-window noise from impersonating the active canvas session. Token-free
// and static on purpose (cacheable). CSP pin: NO inline styles anywhere — all
// appearance lives in preview.css classes; the toolbar is positioned by DOM
// placement inside the selected block's anchor element. Mirrors are DOM-only
// and applied ONLY on parent command, after the tree mutation committed.
(function () {
  'use strict'
  var session = null // { origin, nonce }
  var selectedId = null
  var toolbar = null
  var anchorEl = null

  function post(type, payload) {
    if (!session) return
    var msg = { type: 'lemma:' + type, nonce: session.nonce }
    if (payload) {
      for (var key in payload) {
        if (Object.prototype.hasOwnProperty.call(payload, key)) msg[key] = payload[key]
      }
    }
    window.parent.postMessage(msg, session.origin)
  }

  function idsIndex() {
    return Array.prototype.map.call(
      document.querySelectorAll('[data-lemma-block]'),
      function (el) { return el.getAttribute('data-lemma-block') }
    )
  }

  function wrapperFor(target) {
    return target && target.closest ? target.closest('[data-lemma-block]') : null
  }

  function clearClass(cls) {
    Array.prototype.forEach.call(document.querySelectorAll('.' + cls), function (el) {
      el.classList.remove(cls)
    })
  }

  function cssEscape(value) {
    // window.CSS.escape everywhere modern; quote/backslash fallback keeps the
    // selector safe for the nanoid-alphabet ids we actually emit.
    return window.CSS && window.CSS.escape
      ? window.CSS.escape(String(value))
      : String(value).replace(/["\\]/g, '\\$&')
  }

  function findBlock(id) {
    return document.querySelector('[data-lemma-block="' + cssEscape(id) + '"]')
  }

  // ── Toolbar (stage-toolbar spec §3) ─────────────────────────────────────────
  var ACTIONS = [
    { action: 'move-up', label: 'Move up', path: 'M18 15l-6-6-6 6' },
    { action: 'move-down', label: 'Move down', path: 'M6 9l6 6 6-6' },
    { action: 'duplicate', label: 'Duplicate', path: 'M8 8h12v12H8zM16 8V4H4v12h4' },
    { action: 'delete', label: 'Delete', path: 'M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14M10 10v6M14 10v6' },
    { action: 'add-after', label: 'Add block after', path: 'M12 5v14M5 12h14' }
  ]

  function ensureToolbar() {
    if (toolbar) return toolbar
    toolbar = document.createElement('div')
    toolbar.className = 'lemma-canvas-toolbar'
    ACTIONS.forEach(function (a) {
      var btn = document.createElement('button')
      btn.type = 'button'
      btn.setAttribute('data-action', a.action)
      btn.setAttribute('aria-label', a.label)
      btn.innerHTML =
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
        'stroke-linecap="round" stroke-linejoin="round"><path d="' + a.path + '"/></svg>'
      toolbar.appendChild(btn)
    })
    return toolbar
  }

  function detachToolbar() {
    if (toolbar && toolbar.parentNode) toolbar.parentNode.removeChild(toolbar)
    if (anchorEl) {
      anchorEl.classList.remove('lemma-canvas-anchor')
      anchorEl = null
    }
  }

  function selectWrapper(w) {
    clearClass('lemma-canvas-selected')
    detachToolbar()
    w.classList.add('lemma-canvas-selected')
    selectedId = w.getAttribute('data-lemma-block')
    var host = w.firstElementChild
    if (host) {
      // DOM placement, static CSS (spec §3): anchor gets position:relative from
      // its class; the toolbar is absolute against it with constant offsets.
      // Text-only renders (no element child) get selection but no toolbar.
      anchorEl = host
      host.classList.add('lemma-canvas-anchor')
      host.insertBefore(ensureToolbar(), host.firstChild)
    }
  }

  function clearSelection() {
    clearClass('lemma-canvas-selected')
    detachToolbar()
    selectedId = null
  }

  // ── Mirrors (stage-toolbar spec §1): DOM-only, parent-commanded ─────────────
  function stripCanvasState(root) {
    Array.prototype.forEach.call(root.querySelectorAll('.lemma-canvas-toolbar'), function (el) {
      el.parentNode.removeChild(el)
    })
    var classes = [
      'lemma-canvas-anchor', 'lemma-canvas-selected', 'lemma-canvas-hover',
      'lemma-canvas-selected-target', 'lemma-canvas-hover-target'
    ]
    classes.forEach(function (cls) {
      root.classList.remove(cls)
      Array.prototype.forEach.call(root.querySelectorAll('.' + cls), function (el) {
        el.classList.remove(cls)
      })
    })
  }

  function mirrorMove(id, beforeId, afterId) {
    var w = findBlock(id)
    if (!w || !w.parentNode) return
    // Same-list-only pin: the reference wrapper must be a DOM SIBLING. A stale
    // or mismatched reference in another container must never move the block
    // across parents — ignore the mirror instead.
    if (typeof beforeId === 'string') {
      var ref = findBlock(beforeId)
      if (ref && ref.parentNode === w.parentNode) ref.parentNode.insertBefore(w, ref)
    } else if (typeof afterId === 'string') {
      var prev = findBlock(afterId)
      if (prev && prev.parentNode === w.parentNode) prev.parentNode.insertBefore(w, prev.nextSibling)
    }
  }

  function mirrorRemove(id) {
    var w = findBlock(id)
    if (!w) return
    if (selectedId === id) clearSelection() // detach the toolbar BEFORE the wrapper goes
    if (w.parentNode) w.parentNode.removeChild(w)
  }

  function mirrorDuplicate(sourceId, idMap) {
    var src = findBlock(sourceId)
    if (!src || !src.parentNode || !idMap) return
    var clone = src.cloneNode(true)
    stripCanvasState(clone) // the source is usually SELECTED — never clone live UI state
    var ownId = clone.getAttribute('data-lemma-block')
    if (idMap[ownId]) clone.setAttribute('data-lemma-block', idMap[ownId])
    Array.prototype.forEach.call(clone.querySelectorAll('[data-lemma-block]'), function (el) {
      var next = idMap[el.getAttribute('data-lemma-block')]
      if (next) el.setAttribute('data-lemma-block', next)
    })
    src.parentNode.insertBefore(clone, src.nextSibling)
  }

  function activate() {
    document.addEventListener('mouseover', function (e) {
      var w = wrapperFor(e.target)
      clearClass('lemma-canvas-hover')
      if (w) {
        w.classList.add('lemma-canvas-hover')
        post('block-hover', { id: w.getAttribute('data-lemma-block') })
      }
    })
    // Capture phase: block-internal links/buttons are INERT while active
    // (spec §3) — editing must not navigate the stage. Toolbar clicks are the
    // ONE branch that dispatches an intent instead of (re)selecting.
    document.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest
        ? e.target.closest('.lemma-canvas-toolbar [data-action]')
        : null
      if (btn && selectedId !== null) {
        e.preventDefault()
        e.stopPropagation()
        var action = btn.getAttribute('data-action')
        if (action === 'move-up') post('block-move', { id: selectedId, delta: -1 })
        if (action === 'move-down') post('block-move', { id: selectedId, delta: 1 })
        if (action === 'duplicate') post('block-duplicate', { id: selectedId })
        if (action === 'delete') post('block-delete-request', { id: selectedId })
        if (action === 'add-after') post('block-add-after', { id: selectedId })
        return
      }
      var w = wrapperFor(e.target)
      if (!w) return
      e.preventDefault()
      e.stopPropagation()
      selectWrapper(w)
      post('block-select', { id: w.getAttribute('data-lemma-block') })
    }, true)
    post('blocks-index', { ids: idsIndex() })
  }

  window.addEventListener('message', function (event) {
    var data = event.data || {}
    if (!session) {
      if (data.type === 'lemma:canvas-hello' && typeof data.nonce === 'string') {
        session = { origin: event.origin, nonce: data.nonce }
        activate()
      }
      return
    }
    if (event.origin !== session.origin || data.nonce !== session.nonce) return
    if (data.type === 'lemma:highlight') {
      // Outline-driven selection behaves like a stage click: ring + toolbar.
      var el = findBlock(data.id)
      if (el) selectWrapper(el)
      else clearSelection()
    }
    if (data.type === 'lemma:scroll-to') {
      var t = findBlock(data.id)
      if (t && t.firstElementChild) {
        t.firstElementChild.scrollIntoView({ block: 'center', behavior: 'smooth' })
      }
    }
    if (data.type === 'lemma:mirror-move') mirrorMove(data.id, data.beforeId, data.afterId)
    if (data.type === 'lemma:mirror-remove') mirrorRemove(data.id)
    if (data.type === 'lemma:mirror-duplicate') mirrorDuplicate(data.sourceId, data.idMap)
  })
})()
