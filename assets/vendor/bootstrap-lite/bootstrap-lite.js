/* =====================================================================
   bootstrap-lite.js  -  MediCare offline UI behaviours
   ---------------------------------------------------------------------
   Provides the small part of the Bootstrap 5 JavaScript API this project
   uses:  modals, dropdowns, alert dismissal, tabs and collapse.
   Exposes window.bootstrap.Modal so the application code works
   unchanged when the OFFICIAL bootstrap.bundle.min.js is dropped into
   assets/vendor/bootstrap/ instead of this file.
   ===================================================================== */
(function (window, document) {
  'use strict';

  /* ----------------------------- Modal ------------------------------ */
  function Modal(element) {
    this._element = element;
  }

  Modal.prototype.show = function () {
    if (!this._element) { return; }
    this._element.classList.add('show');
    this._element.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
  };

  Modal.prototype.hide = function () {
    if (!this._element) { return; }
    this._element.classList.remove('show');
    this._element.setAttribute('aria-hidden', 'true');
    if (!document.querySelector('.modal.show')) {
      document.body.classList.remove('modal-open');
    }
  };

  Modal.prototype.toggle = function () {
    if (!this._element) { return; }
    this._element.classList.contains('show') ? this.hide() : this.show();
  };

  Modal.getOrCreateInstance = function (element) {
    if (typeof element === 'string') { element = document.querySelector(element); }
    if (!element) { return new Modal(null); }
    if (!element._modalInstance) { element._modalInstance = new Modal(element); }
    return element._modalInstance;
  };
  Modal.getInstance = Modal.getOrCreateInstance;

  /* ---------------------------- Dropdown ---------------------------- */
  function closeAllDropdowns(except) {
    document.querySelectorAll('.dropdown-menu.show').forEach(function (menu) {
      if (menu !== except) { menu.classList.remove('show'); }
    });
  }

  function Dropdown(element) { this._element = element; }
  Dropdown.prototype.toggle = function () {
    var menu = this._element.parentNode.querySelector('.dropdown-menu');
    if (!menu) { return; }
    var isOpen = menu.classList.contains('show');
    closeAllDropdowns();
    if (!isOpen) { menu.classList.add('show'); }
  };
  Dropdown.getOrCreateInstance = function (el) { return new Dropdown(el); };

  /* --------------------------- Delegation --------------------------- */
  document.addEventListener('click', function (event) {
    var target = event.target;

    // --- open a modal -----------------------------------------------
    var opener = target.closest('[data-bs-toggle="modal"]');
    if (opener) {
      event.preventDefault();
      var sel = opener.getAttribute('data-bs-target') || opener.getAttribute('href');
      var modalEl = sel ? document.querySelector(sel) : null;
      if (modalEl) {
        // let the page pre-fill the modal from the button's data-* values
        modalEl.dispatchEvent(new CustomEvent('show.bs.modal', { detail: { relatedTarget: opener } }));
        Modal.getOrCreateInstance(modalEl).show();
      }
      return;
    }

    // --- dismiss modal / alert --------------------------------------
    var dismisser = target.closest('[data-bs-dismiss]');
    if (dismisser) {
      var kind = dismisser.getAttribute('data-bs-dismiss');
      if (kind === 'modal') {
        event.preventDefault();
        var m = dismisser.closest('.modal');
        if (m) { Modal.getOrCreateInstance(m).hide(); }
        return;
      }
      if (kind === 'alert') {
        event.preventDefault();
        var a = dismisser.closest('.alert');
        if (a) { a.parentNode.removeChild(a); }
        return;
      }
    }

    // --- dropdown ----------------------------------------------------
    var dd = target.closest('[data-bs-toggle="dropdown"]');
    if (dd) {
      event.preventDefault();
      new Dropdown(dd).toggle();
      return;
    }

    // --- tabs --------------------------------------------------------
    var tab = target.closest('[data-bs-toggle="tab"]');
    if (tab) {
      event.preventDefault();
      var pane = document.querySelector(tab.getAttribute('data-bs-target') || tab.getAttribute('href'));
      var group = tab.closest('.nav-tabs');
      if (group) {
        group.querySelectorAll('.nav-link').forEach(function (l) { l.classList.remove('active'); });
      }
      tab.classList.add('active');
      if (pane && pane.parentNode) {
        pane.parentNode.querySelectorAll('.tab-pane').forEach(function (p) { p.classList.remove('active'); });
        pane.classList.add('active');
      }
      return;
    }

    // --- collapse (sidebar / filter panels) --------------------------
    var col = target.closest('[data-bs-toggle="collapse"]');
    if (col) {
      event.preventDefault();
      var box = document.querySelector(col.getAttribute('data-bs-target') || col.getAttribute('href'));
      if (box) { box.classList.toggle('show'); }
      return;
    }

    // click outside closes dropdowns
    if (!target.closest('.dropdown-menu')) { closeAllDropdowns(); }
  });

  // click on the dark backdrop closes the modal
  document.addEventListener('mousedown', function (event) {
    if (event.target.classList && event.target.classList.contains('modal')) {
      Modal.getOrCreateInstance(event.target).hide();
    }
  });

  // ESC closes the top-most modal
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      var open = document.querySelector('.modal.show');
      if (open) { Modal.getOrCreateInstance(open).hide(); }
      closeAllDropdowns();
    }
  });

  window.bootstrap = window.bootstrap || {};
  window.bootstrap.Modal = Modal;
  window.bootstrap.Dropdown = Dropdown;
})(window, document);
