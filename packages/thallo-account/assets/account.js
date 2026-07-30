/*
 * account.js — the storefront account-chrome hydration layer (account-chrome plan).
 * Dependency-free, no build step. The `account-link` block renders a universal, signed-out shell
 * that the shared page cache stores byte-identically for every visitor; this script hydrates it
 * from GET /_account/session (private, no-store). It is a ThalloRuntime CONSUMER, not a
 * DOMContentLoaded script: on runtime pages the core drives enhancement, and a listener-based
 * script would never run there.
 *
 * Fail closed: any non-200, any envelope without `data`, or any fetch rejection leaves the
 * signed-out shell exactly as rendered — never a half-painted menu. Anonymous is the safe reading
 * of "we could not tell".
 */
(function () {
  'use strict';

  if (typeof document === 'undefined') {
    return;
  }

  // Every account-link block template emits its own <script> tag; run once.
  if (window.thalloAccount) {
    return;
  }

  function selectAll(root, selector) {
    if (!root || typeof root.querySelectorAll !== 'function') {
      return [];
    }
    return Array.prototype.slice.call(root.querySelectorAll(selector));
  }

  function paintAccountMenu(root, displayName, links) {
    var signin = root.querySelector('[data-account-signin]');
    var menu = root.querySelector('[data-account-menu]');
    var nameEl = root.querySelector('[data-account-name]');
    var linksEl = root.querySelector('[data-account-links]');

    if (nameEl) {
      nameEl.textContent = displayName == null ? '' : String(displayName);
    }
    if (linksEl && links && links.length) {
      for (var i = 0; i < links.length; i++) {
        var link = links[i] || {};
        var li = document.createElement('li');
        var a = document.createElement('a');
        a.setAttribute('href', String(link.path == null ? '#' : link.path));
        a.textContent = String(link.label == null ? '' : link.label);
        li.appendChild(a);
        linksEl.appendChild(li);
      }
    }
    // Reveal the menu and hide the sign-in link ONLY after a confirmed session.
    if (signin) {
      signin.setAttribute('hidden', 'hidden');
    }
    if (menu) {
      menu.removeAttribute('hidden');
    }
  }

  function enhanceAccountLink(root) {
    fetch('/_account/session', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (res) {
        return res.ok && res.status === 200 ? res.json() : null;
      })
      .then(function (payload) {
        var data = payload && payload.data;
        // Fail closed: on any error the shell stays as rendered (signed out), never a
        // half-populated menu. Anonymous is the safe reading of "we could not tell".
        if (!data || data.authenticated !== true) {
          return;
        }
        paintAccountMenu(root, data.display_name, data.links || []);
      })
      .catch(function () {
        /* leave the signed-out shell in place */
      });
  }

  function enhanceAll(root) {
    var nodes = selectAll(root || document, '[data-account-link]');
    for (var i = 0; i < nodes.length; i++) {
      enhanceAccountLink(nodes[i]);
    }
  }

  if (window.ThalloRuntime) {
    window.ThalloRuntime.register('account-link', {
      selector: '[data-account-link]',
      enhance: enhanceAccountLink,
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
    enhance: enhanceAccountLink,
    enhanceAll: enhanceAll,
  };
})();
