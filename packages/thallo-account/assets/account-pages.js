/*
 * account-pages.js — small progressive enhancements for the storefront account pages, loaded by
 * account/layout.twig. Currently: a show/hide toggle injected into every password field. No-JS
 * degrades gracefully to a plain password input, so this is purely additive.
 */
(function () {
  'use strict';

  if (typeof document === 'undefined' || window.thalloAccountPages) {
    return;
  }
  window.thalloAccountPages = true;

  var EYE = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" '
    + 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
    + '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>';
  var EYE_OFF = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" '
    + 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
    + '<path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/>'
    + '<path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/>'
    + '<path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/>'
    + '<line x1="2" x2="22" y1="2" y2="22"/></svg>';

  function addToggle(input) {
    if (!input.parentNode) {
      return;
    }
    var parent = input.parentNode;
    if (parent.classList && parent.classList.contains('account__password')) {
      return; // already enhanced
    }

    var wrap = document.createElement('div');
    wrap.className = 'account__password';
    parent.insertBefore(wrap, input);
    wrap.appendChild(input);

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'account__password-toggle';
    btn.setAttribute('aria-label', 'Show password');
    btn.setAttribute('aria-pressed', 'false');
    btn.innerHTML = EYE;

    btn.addEventListener('click', function () {
      var show = input.getAttribute('type') === 'password';
      input.setAttribute('type', show ? 'text' : 'password');
      btn.innerHTML = show ? EYE_OFF : EYE;
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
    });

    wrap.appendChild(btn);
  }

  function enhance() {
    var inputs = document.querySelectorAll('.account__form input[type="password"]');
    for (var i = 0; i < inputs.length; i++) {
      addToggle(inputs[i]);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', enhance);
  } else {
    enhance();
  }
})();
