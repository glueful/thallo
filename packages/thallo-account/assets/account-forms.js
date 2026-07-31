/*
 * account-forms.js — the login-form block's enhancement layer (account-form-blocks plan Task 3).
 * Dependency-free, no build step, LOGIN-ONLY: register/forgot-password blocks are plain server
 * forms (their neutral flows leave the page) and receive no enhancement.
 *
 * Three jobs, all cache-safe (the block's static markup stays byte-identical):
 *  1. Inject a hidden `return_to` (the current pathname) into the login form — ONLY enhanced
 *     submits carry it, so a no-JS submit flows through the themed /account/login page where
 *     errors render normally. The server validates it (path-only) and falls back to the themed
 *     422 when absent or unsafe.
 *  2. On an error return (?account_error=<code>): reveal the matching hidden message node
 *     (role="alert" ships in the markup; focus moves to it), refill the email from a consume-once
 *     sessionStorage stash, then strip the param with history.replaceState() so refresh/back
 *     never replays it. Unknown codes reveal nothing — and still strip.
 *  3. On submit: stash ONLY the email (never passwords/OTPs/tokens) under a host+pathname+form
 *     key with a timestamp; entries older than 5 minutes are ignored. The pathname in the key
 *     keeps two custom login pages on one host from reading each other's stash.
 */
(function () {
  'use strict';

  if (typeof document === 'undefined') {
    return;
  }

  // Every login-form block emits its own <script> tag; run once.
  if (window.thalloAccountForms) {
    return;
  }

  var ERROR_CODES = ['credentials'];
  var TTL_MS = 5 * 60 * 1000;

  function storageKey() {
    return 'thallo:account:refill:' + window.location.host + ':' + window.location.pathname + ':login';
  }

  function storage() {
    // Storage access can throw under hardened privacy settings; enhancement degrades silently.
    try {
      return window.sessionStorage || null;
    } catch (e) {
      return null;
    }
  }

  function stashEmail(form) {
    var email = form.querySelector('input[name="email"]');
    var store = storage();
    if (!email || !store) {
      return;
    }
    try {
      store.setItem(storageKey(), JSON.stringify({ email: String(email.value || ''), t: Date.now() }));
    } catch (e) {
      /* quota/privacy failures never break the submit */
    }
  }

  // Consume-once: the entry is DELETED on read; expired entries are purged and yield nothing.
  function consumeStashedEmail() {
    var store = storage();
    if (!store) {
      return null;
    }
    var raw = null;
    try {
      raw = store.getItem(storageKey());
      store.removeItem(storageKey());
    } catch (e) {
      return null;
    }
    if (!raw) {
      return null;
    }
    try {
      var parsed = JSON.parse(raw);
      if (!parsed || typeof parsed.email !== 'string' || typeof parsed.t !== 'number') {
        return null;
      }
      return Date.now() - parsed.t > TTL_MS ? null : parsed.email;
    } catch (e) {
      return null;
    }
  }

  function errorCode() {
    var match = /[?&]account_error=([^&]*)/.exec(String(window.location.search || ''));
    return match ? decodeURIComponent(match[1]) : null;
  }

  // Strip ONLY the consumed parameter, keeping any other query/hash — refresh/back never replays.
  function stripErrorParam() {
    if (!window.history || typeof window.history.replaceState !== 'function') {
      return;
    }
    var search = String(window.location.search || '')
      .replace(/([?&])account_error=[^&]*&?/, '$1')
      .replace(/[?&]$/, '');
    window.history.replaceState(
      null,
      '',
      String(window.location.pathname || '/') + search + String(window.location.hash || '')
    );
  }

  function enhanceLoginForm(root) {
    var form = root.querySelector('form');
    if (!form || form._thalloAccountFormEnhanced) {
      return;
    }
    form._thalloAccountFormEnhanced = true;

    // (1) return_to: exists ONLY on enhanced submits (see the file header).
    var returnTo = document.createElement('input');
    returnTo.setAttribute('type', 'hidden');
    returnTo.setAttribute('name', 'return_to');
    returnTo.setAttribute('value', String(window.location.pathname || '/'));
    form.appendChild(returnTo);

    // (3) stash the email at submit time so an error return can refill it.
    if (typeof form.addEventListener === 'function') {
      form.addEventListener('submit', function () {
        stashEmail(form);
      });
    }

    // (2) error return: reveal + focus + refill for a KNOWN code with a matching node; anything
    // else reveals nothing. The param is stripped either way.
    var code = errorCode();
    if (code === null) {
      return;
    }
    if (ERROR_CODES.indexOf(code) !== -1) {
      var node = root.querySelector('[data-account-error="' + code + '"]');
      if (node) {
        node.removeAttribute('hidden');
        var email = consumeStashedEmail();
        var emailInput = form.querySelector('input[name="email"]');
        if (email !== null && emailInput) {
          emailInput.value = email;
        }
        if (typeof node.focus === 'function') {
          node.focus();
        }
      }
    }
    stripErrorParam();
  }

  function enhanceAll(root) {
    var scope = root && typeof root.querySelectorAll === 'function' ? root : document;
    var nodes = scope.querySelectorAll('[data-account-form="login"]');
    for (var i = 0; i < nodes.length; i++) {
      enhanceLoginForm(nodes[i]);
    }
  }

  if (window.ThalloRuntime) {
    window.ThalloRuntime.register('account-forms', {
      selector: '[data-account-form="login"]',
      enhance: enhanceLoginForm,
    });
    if (document.readyState !== 'loading') {
      // Catch-up pass: the runtime core's deferred boot may already have fired before this
      // registration existed. The core's data-thallo-enhanced markers make repeats no-ops.
      Promise.resolve().then(function () {
        window.ThalloRuntime.enhance(document.documentElement);
      });
    }
  } else if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      enhanceAll(document);
    });
  } else {
    enhanceAll(document);
  }

  // Exposed for the executable test harness and post-injection re-enhancement.
  window.thalloAccountForms = {
    enhance: enhanceLoginForm,
    enhanceAll: enhanceAll,
  };
})();
