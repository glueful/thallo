// Lemma canvas bridge (visual-canvas spec §3). SILENT until a canvas parent says
// hello; a plain preview tab never messages anyone. The nonce is a correlation
// token, not auth — it stops stale frames/same-window noise from impersonating
// the active canvas session. Token-free and static on purpose (cacheable).
(function () {
  'use strict'
  var session = null // { origin, nonce }

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

  function findBlock(id) {
    return document.querySelector('[data-lemma-block="' + CSS.escape(String(id)) + '"]')
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
    // (spec §3) — editing must not navigate the stage.
    document.addEventListener('click', function (e) {
      var w = wrapperFor(e.target)
      if (!w) return
      e.preventDefault()
      e.stopPropagation()
      clearClass('lemma-canvas-selected')
      w.classList.add('lemma-canvas-selected')
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
      clearClass('lemma-canvas-selected')
      var el = findBlock(data.id)
      if (el) el.classList.add('lemma-canvas-selected')
    }
    if (data.type === 'lemma:scroll-to') {
      var t = findBlock(data.id)
      if (t && t.firstElementChild) {
        t.firstElementChild.scrollIntoView({ block: 'center', behavior: 'smooth' })
      }
    }
  })
})()
