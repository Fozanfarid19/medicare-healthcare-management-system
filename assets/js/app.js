/* =====================================================================
   MediCare - assets/js/app.js
   Small shared behaviours: sidebar, confirmations, modal pre-filling,
   auto submitting filters and the print button.
   ===================================================================== */
(function () {
  'use strict';

  var MC = window.MEDICARE || {};

  /* ------------------------- helper exports ------------------------- */
  window.MediCare = {
    /**
     * Runs a callback when a modal is opened, giving it the modal element
     * and the button that triggered it.  Works with the official
     * Bootstrap bundle AND with bootstrap-lite.
     */
    onModalShow: function (selector, callback) {
      var modal = document.querySelector(selector);
      if (!modal) { return; }
      modal.addEventListener('show.bs.modal', function (event) {
        var trigger = event.relatedTarget || (event.detail && event.detail.relatedTarget) || null;
        callback(modal, trigger);
      });
    },

    /** Fills form fields inside a modal from the trigger's data-* values. */
    fillFromDataset: function (modal, trigger) {
      if (!trigger) { return; }
      Object.keys(trigger.dataset).forEach(function (key) {
        var field = modal.querySelector('[data-fill="' + key + '"]');
        if (!field) { return; }
        var value = trigger.dataset[key];
        if (field.tagName === 'INPUT' || field.tagName === 'SELECT' || field.tagName === 'TEXTAREA') {
          field.value = value;
        } else {
          field.textContent = value;
        }
      });
    },

    openModal: function (selector) {
      var el = document.querySelector(selector);
      if (el && window.bootstrap && window.bootstrap.Modal) {
        window.bootstrap.Modal.getOrCreateInstance(el).show();
      }
    },

    closeModal: function (selector) {
      var el = document.querySelector(selector);
      if (el && window.bootstrap && window.bootstrap.Modal) {
        window.bootstrap.Modal.getOrCreateInstance(el).hide();
      }
    },

    /** POST helper that always sends the CSRF token. */
    post: function (endpoint, data) {
      var body = new FormData();
      body.append('csrf_token', MC.csrf || '');
      Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });

      return fetch((MC.baseUrl || '/') + endpoint, {
        method: 'POST',
        body: body,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (r) { return r.json(); });
    },

    get: function (endpoint) {
      return fetch((MC.baseUrl || '/') + endpoint, {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (r) { return r.json(); });
    },

    /** Small floating message. */
    toast: function (message, type) {
      var box = document.createElement('div');
      box.className = 'alert alert-' + (type || 'success') + ' shadow';
      box.style.cssText = 'position:fixed;right:1rem;bottom:1rem;z-index:1300;max-width:320px';
      box.textContent = message;
      document.body.appendChild(box);
      setTimeout(function () { box.remove(); }, 4000);
    }
  };

  document.addEventListener('DOMContentLoaded', function () {

    /* --------------------------- sidebar ---------------------------- */
    var toggle   = document.getElementById('sidebarToggle');
    var sidebar  = document.getElementById('appSidebar');
    var backdrop = document.getElementById('sidebarBackdrop');

    function closeSidebar() {
      if (sidebar)  { sidebar.classList.remove('open'); }
      if (backdrop) { backdrop.classList.remove('show'); }
    }
    if (toggle && sidebar) {
      toggle.addEventListener('click', function () {
        sidebar.classList.toggle('open');
        if (backdrop) { backdrop.classList.toggle('show'); }
      });
    }
    if (backdrop) { backdrop.addEventListener('click', closeSidebar); }

    /* ---------------------- confirm dangerous actions --------------- */
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
      el.addEventListener('click', function (event) {
        if (!window.confirm(el.getAttribute('data-confirm'))) {
          event.preventDefault();
          event.stopPropagation();
        }
      });
    });
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        if (!window.confirm(form.getAttribute('data-confirm'))) { event.preventDefault(); }
      });
    });

    /* ------------------- auto dismiss flash messages ---------------- */
    document.querySelectorAll('.alert-dismissible').forEach(function (alert) {
      setTimeout(function () {
        alert.classList.remove('show');
        setTimeout(function () { if (alert.parentNode) { alert.remove(); } }, 250);
      }, 7000);
    });

    /* ------------------ filters that submit on change --------------- */
    document.querySelectorAll('.auto-submit').forEach(function (field) {
      field.addEventListener('change', function () {
        if (field.form) { field.form.submit(); }
      });
    });

    /* --------------------------- print ------------------------------ */
    document.querySelectorAll('[data-print]').forEach(function (btn) {
      btn.addEventListener('click', function (e) { e.preventDefault(); window.print(); });
    });

    /* ---------------- show/hide password on auth forms -------------- */
    document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var input = document.querySelector(btn.getAttribute('data-toggle-password'));
        if (!input) { return; }
        input.type = (input.type === 'password') ? 'text' : 'password';
        btn.textContent = (input.type === 'password') ? 'Show' : 'Hide';
      });
    });

    /* --------- generic modals that only need data-* pre-filling ----- */
    document.querySelectorAll('.modal[data-autofill]').forEach(function (modal) {
      modal.addEventListener('show.bs.modal', function (event) {
        var trigger = event.relatedTarget || (event.detail && event.detail.relatedTarget) || null;
        window.MediCare.fillFromDataset(modal, trigger);
      });
    });

    /* ------- live clock (used on the patient dashboard header) ------- */
    var clock = document.getElementById('liveClock');
    if (clock) {
      var tick = function () {
        var now = new Date();
        clock.textContent = now.toLocaleDateString(undefined, {
          weekday: 'long', year: 'numeric', month: 'short', day: 'numeric'
        }) + '  -  ' + now.toLocaleTimeString();
      };
      tick();
      setInterval(tick, 1000);
    }
  });
})();
