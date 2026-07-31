/*
 * account.js — the auth-state hydration layer (public-account-surface plan Task 2).
 * Dependency-free, no build step. The `auth-state` block renders BOTH branches server-side —
 * `signed_out` visible, `signed_in` starting `hidden inert` — so the shared page cache stores it
 * byte-identically for every visitor (Global Constraints: presentation only, fail closed). This
 * script hydrates the branches from GET /_account/session (private, no-store). It is a
 * ThalloRuntime CONSUMER, not a DOMContentLoaded script: on runtime pages the core drives
 * enhancement, and a listener-based script would never run there.
 *
 * One session request per document (Global Constraints): every `auth-state` instance shares ONE
 * fetch — a module-level promise, lazily created on first enhancement — and its result is applied
 * to every instance. Fail closed: any non-200, any envelope without `data.authenticated === true`,
 * or any fetch rejection leaves the server-rendered attributes exactly as they were rendered —
 * never a half-swapped branch. Anonymous is the safe reading of "we could not tell".
 */
(function () {
  'use strict';

  if (typeof document === 'undefined') {
    return;
  }

  // Every auth-state block template emits its own <script> tag; run once.
  if (window.thalloAccount) {
    return;
  }

  function selectAll(root, selector) {
    if (!root || typeof root.querySelectorAll !== 'function') {
      return [];
    }
    return Array.prototype.slice.call(root.querySelectorAll(selector));
  }

  // ONE GET /_account/session per document, shared by every auth-state instance. Lazily
  // created on the first enhancement, so a page with zero auth-state blocks never fetches
  // at all. Any failure (non-200, non-JSON, network reject) resolves to null rather than
  // rejecting, so every waiting instance falls through to the fail-closed branch below.
  var sessionPromise = null;
  function session() {
    if (!sessionPromise) {
      sessionPromise = fetch('/_account/session', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      })
        .then(function (res) {
          return res.ok && res.status === 200 ? res.json() : null;
        })
        .catch(function () {
          return null;
        });
    }
    return sessionPromise;
  }

  // Reveal the authenticated branch and hide the anonymous one. Both attributes on both
  // elements are swapped here, in one synchronous pass: `hidden`+`inert` land on the
  // anonymous branch BEFORE either is removed from the authenticated branch, so there is
  // no tick where both branches — or neither — are simultaneously interactive.
  function swap(root) {
    var anonymous = root.querySelector('[data-auth-when="anonymous"]');
    var authenticated = root.querySelector('[data-auth-when="authenticated"]');
    if (anonymous) {
      anonymous.setAttribute('hidden', '');
      anonymous.setAttribute('inert', '');
    }
    if (authenticated) {
      authenticated.removeAttribute('hidden');
      authenticated.removeAttribute('inert');
    }
  }

  function enhanceAuthState(root) {
    session().then(function (payload) {
      var data = payload && payload.data;
      // Fail closed: on any error the branches stay exactly as rendered (signed-out
      // visible, signed-in hidden+inert) — never a half-swapped instance. Anonymous is
      // the safe reading of "we could not tell".
      if (!data || data.authenticated !== true) {
        return;
      }
      swap(root);
    });
  }

  function enhanceAll(root) {
    var nodes = selectAll(root || document, '[data-auth-state]');
    for (var i = 0; i < nodes.length; i++) {
      enhanceAuthState(nodes[i]);
    }
  }

  if (window.ThalloRuntime) {
    window.ThalloRuntime.register('auth-state', {
      selector: '[data-auth-state]',
      enhance: enhanceAuthState,
    });
    if (document.readyState !== 'loading') {
      // Catch-up pass: the runtime core's deferred boot may already have fired before this
      // registration existed (separate defer <script> tasks). Delegate to the core, whose
      // data-thallo-enhanced markers make an already-covered node a no-op.
      Promise.resolve().then(function () {
        window.ThalloRuntime.enhance(document.documentElement);
      });
    }
  } else if (document.readyState === 'loading') {
    // Fallback: a copied pre-runtime layout has no ThalloRuntime — self-drive.
    document.addEventListener('DOMContentLoaded', function () {
      enhanceAll(document);
    });
  } else {
    enhanceAll(document);
  }

  // Exposed for the executable test harness and for callers re-running enhancement after
  // injecting a block (e.g. a builder preview).
  window.thalloAccount = {
    enhance: enhanceAuthState,
    enhanceAll: enhanceAll,
  };
})();
