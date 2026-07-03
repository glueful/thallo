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
  var editing = null // { id, field, kind, region, debounce }
  var lastPointer = null // { x, y } of the granting double-click (caret placement)
  var drag = null // { wrapper, originalNext, lastY }
  var suppressClick = false // one-shot: the click after a completed drag

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
    { action: 'drag', label: 'Drag to reorder', path: 'M9 5h.01M9 12h.01M9 19h.01M15 5h.01M15 12h.01M15 19h.01' },
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
    toolbar.querySelector('[data-action="drag"]').addEventListener('pointerdown', onGripDown)
    return toolbar
  }

  // Elements whose children never RENDER (void/replaced/foreign roots): the
  // toolbar cannot live inside them — a bridge-owned shim sibling hosts it.
  var NO_CHILD_HOSTS = {
    HR: 1, IMG: 1, BR: 1, INPUT: 1, EMBED: 1, IFRAME: 1, OBJECT: 1,
    CANVAS: 1, VIDEO: 1, AUDIO: 1, svg: 1, SVG: 1
  }

  function detachToolbar() {
    if (toolbar && toolbar.parentNode) toolbar.parentNode.removeChild(toolbar)
    if (anchorEl) {
      if (anchorEl.classList.contains('lemma-canvas-shim') && anchorEl.parentNode) {
        anchorEl.parentNode.removeChild(anchorEl) // bridge-owned — remove entirely
      } else {
        anchorEl.classList.remove('lemma-canvas-anchor')
      }
      anchorEl = null
    }
  }

  function selectWrapper(w) {
    clearClass('lemma-canvas-selected')
    detachToolbar()
    w.classList.add('lemma-canvas-selected')
    selectedId = w.getAttribute('data-lemma-block')
    var host = w.firstElementChild
    if (host && NO_CHILD_HOSTS[host.tagName]) {
      // hr/img/… render no children: anchor a shim sibling instead.
      var shim = document.createElement('span')
      shim.className = 'lemma-canvas-anchor lemma-canvas-shim'
      host.insertAdjacentElement('afterend', shim)
      anchorEl = shim
      shim.appendChild(ensureToolbar())
    } else if (host) {
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

  // ── Edit-in-place session (edit-in-place spec §3) ───────────────────────────
  function regionFor(id, field) {
    var w = findBlock(id)
    if (!w) return null
    var regions = w.querySelectorAll(
      '.lemma-edit-region[data-lemma-edit-block="' + cssEscape(id) + '"]'
        + '[data-lemma-edit-field="' + cssEscape(field) + '"]'
    )
    return regions.length === 1 ? regions[0] : null // one region per (block, field)
  }

  function commitEditing() {
    if (!editing) return
    if (editing.debounce) clearTimeout(editing.debounce)
    if (editing.kind === 'rich') {
      post('text-changed', { id: editing.id, field: editing.field, html: editing.region.innerHTML })
    } else {
      var text = editing.region.innerText
      if (typeof text !== 'string') text = editing.region.textContent || ''
      post('text-changed', { id: editing.id, field: editing.field, text: text })
    }
  }

  function endEditing() {
    if (!editing) return
    if (editing.debounce) clearTimeout(editing.debounce)
    editing.region.removeAttribute('contenteditable')
    editing.region.classList.remove('lemma-canvas-editing')
    editing.region.removeEventListener('input', onEditInput)
    editing.region.removeEventListener('blur', onEditBlur)
    editing.region.removeEventListener('keydown', onEditKeydown)
    var id = editing.id
    editing = null
    post('edit-end', { id: id })
  }

  function onEditInput() {
    if (!editing) return
    if (editing.debounce) clearTimeout(editing.debounce)
    editing.debounce = setTimeout(commitEditing, 400)
  }

  function onEditBlur() {
    commitEditing()
    endEditing()
  }

  function onEditKeydown(e) {
    if (e.key === 'Escape') {
      e.preventDefault()
      commitEditing()
      endEditing()
    }
    if (e.key === 'Enter' && editing && editing.kind === 'string') {
      e.preventDefault() // single-line: Enter commits-and-exits
      commitEditing()
      endEditing()
    }
  }

  function startEditing(id, field, kind) {
    if (editing) return
    var region = regionFor(id, field)
    if (!region) return
    detachToolbar()
    editing = { id: id, field: field, kind: kind, region: region, debounce: null }
    region.setAttribute('contenteditable', kind === 'rich' ? 'true' : 'plaintext-only')
    // Best-effort (spec pin): engines without plaintext-only support reflect a
    // different IDL value — fall back to 'true'. Commits read innerText for
    // plain kinds, so markup can never persist either way.
    if (kind !== 'rich' && String(region.contentEditable).toLowerCase() !== 'plaintext-only') {
      region.setAttribute('contenteditable', 'true')
    }
    region.classList.add('lemma-canvas-editing')
    region.addEventListener('input', onEditInput)
    region.addEventListener('blur', onEditBlur)
    region.addEventListener('keydown', onEditKeydown)
    region.focus()
    // Caret at the double-click point (spec pin) — best-effort: jsdom and old
    // engines lack caretRangeFromPoint; focus() alone is the fallback.
    if (lastPointer && document.caretRangeFromPoint) {
      var range = document.caretRangeFromPoint(lastPointer.x, lastPointer.y)
      if (range && region.contains(range.startContainer)) {
        var sel = window.getSelection()
        if (sel) {
          sel.removeAllRanges()
          sel.addRange(range)
        }
      }
    }
    // Session lifecycle (auto-apply spec §1): posted ONLY when a session
    // actually started — a failed grant must never wedge parent suppression.
    post('edit-start', { id: id })
  }

  // ── Free drag (free-drag spec §1): live reorder, ONE intent on pointerup ────
  function siblingWrapperFrom(el, dir) {
    // Nearest sibling WRAPPER scanning outward — skips non-wrapper nodes and,
    // by construction, the dragged wrapper itself (review caution).
    var cur = dir > 0 ? el.nextElementSibling : el.previousElementSibling
    while (cur && !(cur.hasAttribute && cur.hasAttribute('data-lemma-block'))) {
      cur = dir > 0 ? cur.nextElementSibling : cur.previousElementSibling
    }
    return cur
  }

  function onGripDown(e) {
    if (editing || drag || selectedId === null) return
    var w = findBlock(selectedId)
    if (!w || !w.parentNode) return
    e.preventDefault()
    drag = { wrapper: w, originalNext: w.nextElementSibling, lastY: e.clientY }
    w.classList.add('lemma-canvas-dragging')
    // currentTarget (review P3): the listener sits on the grip BUTTON, but
    // e.target is often the nested svg/path — capture must attach to the
    // element that owns the listener.
    var captureEl = e.currentTarget
    if (captureEl && captureEl.setPointerCapture && typeof e.pointerId === 'number') {
      try { captureEl.setPointerCapture(e.pointerId) } catch (err) { /* jsdom / old engines */ }
    }
    document.addEventListener('pointermove', onDragMove)
    document.addEventListener('pointerup', onDragUp)
    document.addEventListener('pointercancel', onDragCancel)
    document.addEventListener('keydown', onDragKeydown, true)
  }

  /**
   * FLIP the given wrappers' first children through a reorder (drag-feel
   * amendment): measure, mutate, animate the delta to zero via the Web
   * Animations API — script-driven, so the CSP no-inline-styles pin holds.
   * Engines without element.animate (jsdom) just get the instant move.
   */
  function flipReorder(kids, w, mutate) {
    var before = []
    for (var i = 0; i < kids.length; i++) {
      var el = kids[i]
      if (!(el.hasAttribute && el.hasAttribute('data-lemma-block'))) continue
      var h = el.firstElementChild
      if (h && h.animate) before.push({ host: h, top: h.getBoundingClientRect().top })
    }
    mutate()
    for (var k = 0; k < before.length; k++) {
      var entry = before[k]
      var dy = entry.top - entry.host.getBoundingClientRect().top
      if (dy !== 0) {
        entry.host.animate(
          [{ transform: 'translateY(' + dy + 'px)' }, { transform: 'none' }],
          { duration: 150, easing: 'ease' }
        )
      }
    }
  }

  function onDragMove(e) {
    if (!drag) return
    var w = drag.wrapper
    if (!w.parentNode) return
    // Direction gating (drag-feel amendment): live moves re-shift sibling
    // midpoints under the pointer, so undirected swaps can oscillate near a
    // boundary with unequal block heights. Only swap in the direction the
    // pointer is actually travelling.
    var dirDown = e.clientY > drag.lastY
    var dirUp = e.clientY < drag.lastY
    drag.lastY = e.clientY
    if (!dirDown && !dirUp) return
    var kids = w.parentNode.children
    var target = null
    for (var i = 0; i < kids.length; i++) {
      var el = kids[i]
      if (el === w) continue
      if (!(el.hasAttribute && el.hasAttribute('data-lemma-block'))) continue
      // Same-parent guard (review caution): mirror-move's rule on the live path.
      if (el.parentNode !== w.parentNode) continue
      var host = el.firstElementChild
      if (!host) continue
      var r = host.getBoundingClientRect()
      if (e.clientY < r.top + r.height / 2) {
        target = el
        break
      }
    }
    if (target) {
      if (w.nextElementSibling === target) return
      // The computed slot is above w's current position -> an UP move; only
      // take it when the pointer travels up (and vice versa for down).
      var movingUp = isBefore(target, w)
      if ((movingUp && !dirUp) || (!movingUp && !dirDown)) return
      flipReorder(kids, w, function () { w.parentNode.insertBefore(w, target) })
    } else {
      // Below every midpoint: move to the end of the sibling wrappers.
      if (!dirDown) return
      var lastWrap = null
      for (var j = kids.length - 1; j >= 0; j--) {
        var cand = kids[j]
        if (cand !== w && cand.hasAttribute && cand.hasAttribute('data-lemma-block')) {
          lastWrap = cand
          break
        }
      }
      if (lastWrap && lastWrap.nextSibling !== w) {
        flipReorder(kids, w, function () { w.parentNode.insertBefore(w, lastWrap.nextSibling) })
      }
    }
  }

  /** True when a precedes b in document order (same parent assumed). */
  function isBefore(a, b) {
    return !!(a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING)
  }

  function onDragUp() {
    if (!drag) return
    var w = drag.wrapper
    if (w.nextElementSibling !== drag.originalNext) {
      var next = siblingWrapperFrom(w, 1)
      var prev = siblingWrapperFrom(w, -1)
      if (next) {
        post('block-move-to', {
          id: w.getAttribute('data-lemma-block'),
          beforeId: next.getAttribute('data-lemma-block')
        })
      } else if (prev) {
        post('block-move-to', {
          id: w.getAttribute('data-lemma-block'),
          afterId: prev.getAttribute('data-lemma-block')
        })
      }
      suppressClick = true // the click that follows a completed drag
    }
    endDrag()
  }

  function onDragCancel() {
    rollbackDrag()
  }

  function onDragKeydown(e) {
    if (e.key === 'Escape' && drag) {
      e.preventDefault()
      rollbackDrag()
    }
  }

  function rollbackDrag() {
    // Full rollback (review caution): restore order, clear state, no suppressor.
    if (!drag) return
    var w = drag.wrapper
    if (w.parentNode) w.parentNode.insertBefore(w, drag.originalNext) // null -> append
    endDrag()
  }

  function endDrag() {
    if (!drag) return
    drag.wrapper.classList.remove('lemma-canvas-dragging')
    document.removeEventListener('pointermove', onDragMove)
    document.removeEventListener('pointerup', onDragUp)
    document.removeEventListener('pointercancel', onDragCancel)
    document.removeEventListener('keydown', onDragKeydown, true)
    drag = null
  }

  // ── Mirrors (stage-toolbar spec §1): DOM-only, parent-commanded ─────────────
  function stripCanvasState(root) {
    Array.prototype.forEach.call(root.querySelectorAll('.lemma-canvas-toolbar'), function (el) {
      el.parentNode.removeChild(el)
    })
    Array.prototype.forEach.call(root.querySelectorAll('.lemma-canvas-shim'), function (el) {
      el.parentNode.removeChild(el) // bridge-owned anchor shims never survive cloning
    })
    var classes = [
      'lemma-canvas-anchor', 'lemma-canvas-selected', 'lemma-canvas-hover',
      'lemma-canvas-selected-target', 'lemma-canvas-hover-target', 'lemma-canvas-dragging'
    ]
    classes.forEach(function (cls) {
      root.classList.remove(cls)
      Array.prototype.forEach.call(root.querySelectorAll('.' + cls), function (el) {
        el.classList.remove(cls)
      })
    })
    Array.prototype.forEach.call(root.querySelectorAll('[contenteditable]'), function (el) {
      el.removeAttribute('contenteditable')
    })
    Array.prototype.forEach.call(root.querySelectorAll('.lemma-canvas-editing'), function (el) {
      el.classList.remove('lemma-canvas-editing')
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
    // Edit regions carry their block id too (review P1): without the rewrite a
    // duplicated prose block's region keeps the SOURCE id and edit-grant for
    // the new id can never find it until the next Apply re-renders truth.
    Array.prototype.forEach.call(clone.querySelectorAll('[data-lemma-edit-block]'), function (el) {
      var mappedEdit = idMap[el.getAttribute('data-lemma-edit-block')]
      if (mappedEdit) el.setAttribute('data-lemma-edit-block', mappedEdit)
    })
    src.parentNode.insertBefore(clone, src.nextSibling)
  }

  function activate() {
    document.addEventListener('mouseover', function (e) {
      if (drag) return
      var w = wrapperFor(e.target)
      clearClass('lemma-canvas-hover')
      if (w) {
        w.classList.add('lemma-canvas-hover')
        post('block-hover', { id: w.getAttribute('data-lemma-block') })
      }
    })
    // Double-click on a prose block asks the parent for an edit grant
    // (edit-in-place spec §3) — the bridge never decides editability itself.
    document.addEventListener('dblclick', function (e) {
      if (editing) return
      var w = wrapperFor(e.target)
      if (!w) return
      // The REGION under the double-click names the field (spec §2); a
      // wrapper-level double-click falls back to the block's single region.
      var region = e.target && e.target.closest ? e.target.closest('.lemma-edit-region') : null
      if (!region || !w.contains(region)) {
        var regions = w.querySelectorAll('.lemma-edit-region')
        region = regions.length === 1 ? regions[0] : null
      }
      if (!region) return
      e.preventDefault()
      lastPointer = { x: e.clientX, y: e.clientY }
      post('edit-request', {
        id: region.getAttribute('data-lemma-edit-block'),
        field: region.getAttribute('data-lemma-edit-field')
      })
    }, true)
    // Capture phase: block-internal links/buttons are INERT while active
    // (spec §3) — editing must not navigate the stage. Toolbar clicks are the
    // ONE branch that dispatches an intent instead of (re)selecting.
    document.addEventListener('click', function (e) {
      if (suppressClick) {
        // One-shot: the click that follows a completed drag (free-drag spec §1).
        suppressClick = false
        e.preventDefault()
        e.stopPropagation()
        return
      }
      if (editing) {
        // Caret placement inside the active region passes through untouched;
        // any click outside commits-and-exits, then v2 semantics resume.
        if (editing.region.contains(e.target)) return
        commitEditing()
        endEditing()
      }
      var btn = e.target && e.target.closest
        ? e.target.closest('.lemma-canvas-toolbar [data-action]')
        : null
      if (btn && selectedId !== null) {
        e.preventDefault()
        e.stopPropagation()
        var action = btn.getAttribute('data-action')
        if (action === 'drag') return // drags are pointer-driven, never a click intent
        if (action === 'move-up') post('block-move', { id: selectedId, delta: -1 })
        if (action === 'move-down') post('block-move', { id: selectedId, delta: 1 })
        if (action === 'duplicate') post('block-duplicate', { id: selectedId })
        if (action === 'delete') {
          // Anchor for the parent's confirm (same mechanism as add-after).
          var dr = btn.getBoundingClientRect()
          post('block-delete-request', { id: selectedId, rect: { x: dr.left, y: dr.bottom } })
        }
        if (action === 'add-after') {
          // Anchor for the parent's picker (iframe-viewport coordinates): the
          // parent translates through the iframe's own offset so the panel
          // opens AT the + button instead of floating top-center.
          var r = btn.getBoundingClientRect()
          post('block-add-after', { id: selectedId, rect: { x: r.left, y: r.bottom } })
        }
        return
      }
      var w = wrapperFor(e.target)
      if (!w) return
      e.preventDefault()
      e.stopPropagation()
      selectWrapper(w)
      post('block-select', { id: w.getAttribute('data-lemma-block') })
    }, true)
    // Scroll preservation (auto-apply spec §3): trailing-throttled reports;
    // the parent restores after every stage reload.
    var scrollTimer = null
    window.addEventListener('scroll', function () {
      if (scrollTimer) return
      scrollTimer = setTimeout(function () {
        scrollTimer = null
        post('scroll', { y: window.scrollY || 0 })
      }, 250)
    })
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
    if (
      data.type === 'lemma:edit-grant' && typeof data.id === 'string'
      && typeof data.field === 'string' && typeof data.kind === 'string'
    ) {
      startEditing(data.id, data.field, data.kind)
    }
    if (data.type === 'lemma:edit-flush') {
      if (editing) {
        commitEditing()
        endEditing()
      }
      post('edit-flushed') // ALWAYS ack (spec §3) — the parent awaits this
    }
    if (data.type === 'lemma:restore-scroll' && typeof data.y === 'number') {
      window.scrollTo(0, data.y) // instant — a reload restore must not visibly travel
    }
    if (data.type === 'lemma:mirror-move') mirrorMove(data.id, data.beforeId, data.afterId)
    if (data.type === 'lemma:mirror-remove') mirrorRemove(data.id)
    if (data.type === 'lemma:mirror-duplicate') mirrorDuplicate(data.sourceId, data.idMap)
  })
})()
