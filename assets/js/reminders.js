/* =====================================================================
   MediCare - assets/js/reminders.js
   The medicine reminder engine that runs while a PATIENT is logged in.

        Browser  ->  JavaScript (this file, every 30 seconds)
                 ->  api/reminder_check.php
                 ->  MySQL  (medicines + medicine_reminders + medicine_logs)
                 ->  JSON with the doses that are due right now
                 ->  in-page alert with  Taken / Snooze / Skip
                 ->  POST back to api/reminder_check.php
                 ->  row written into medicine_logs

   The popup is a real in-application dialog, so it works even when the
   browser blocks desktop notifications.
   ===================================================================== */
(function () {
  'use strict';

  if (!window.MEDICARE || window.MEDICARE.role !== 'patient') { return; }

  var CHECK_INTERVAL = 30000;      // 30 seconds
  var shown = {};                  // reminders already displayed in this page view
  var queue = [];
  var busy  = false;

  /* --------------------------- popup markup ------------------------- */
  function buildModal() {
    if (document.getElementById('reminderModal')) { return; }

    var html =
      '<div class="modal reminder-modal" id="reminderModal" aria-hidden="true" role="dialog">' +
        '<div class="modal-dialog modal-dialog-centered">' +
          '<div class="modal-content">' +
            '<div class="modal-header">' +
              '<h5 class="modal-title">&#9200; Medicine Reminder</h5>' +
              '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
            '</div>' +
            '<div class="modal-body" id="reminderBody"></div>' +
            '<div class="modal-footer">' +
              '<button type="button" class="btn btn-success"  id="btnTaken">Taken</button>' +
              '<button type="button" class="btn btn-warning"  id="btnSnooze">Snooze</button>' +
              '<button type="button" class="btn btn-outline-secondary" id="btnSkip">Skip</button>' +
            '</div>' +
          '</div>' +
        '</div>' +
      '</div>';

    var wrap = document.createElement('div');
    wrap.innerHTML = html;
    document.body.appendChild(wrap.firstChild);

    document.getElementById('btnTaken').addEventListener('click',  function () { act('taken'); });
    document.getElementById('btnSnooze').addEventListener('click', function () { act('snoozed'); });
    document.getElementById('btnSkip').addEventListener('click',   function () { act('skipped'); });
  }

  /* ------------------------- render one dose ------------------------ */
  function render(dose) {
    buildModal();

    var doctor = dose.doctor_name ? '<div class="small text-muted">Prescribed by ' + esc(dose.doctor_name) + '</div>' : '';
    var extra  = dose.instructions ? '<div class="alert alert-info mb-0">' + esc(dose.instructions) + '</div>' : '';

    document.getElementById('reminderBody').innerHTML =
      '<p class="mb-3">It is time to take your medicine.</p>' +
      '<div class="reminder-pill">' +
        '<span class="rp-ic"><svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor">' +
        '<path d="M4.2 12.9 12 5.1a4.5 4.5 0 0 1 6.4 6.4l-7.8 7.8a4.5 4.5 0 0 1-6.4-6.4z"/></svg></span>' +
        '<div>' +
          '<div class="fw-bold fs-5">' + esc(dose.medicine_name) + '</div>' +
          '<div class="text-muted small">Dose: ' + esc(dose.dose) +
             (dose.quantity ? '  &middot;  ' + esc(dose.quantity) : '') +
             '  &middot;  ' + esc(dose.route) + '</div>' +
          doctor +
        '</div>' +
        '<div class="ms-auto text-end">' +
          '<div class="reminder-time">' + esc(dose.time_label) + '</div>' +
          '<div class="small text-muted">' + esc(dose.frequency) + '</div>' +
        '</div>' +
      '</div>' + extra;

    window.MediCare.openModal('#reminderModal');
  }

  function esc(text) {
    var d = document.createElement('div');
    d.textContent = (text === null || text === undefined) ? '' : text;
    return d.innerHTML;
  }

  /* --------------------------- user action -------------------------- */
  function act(action) {
    if (!queue.length || busy) { return; }
    busy = true;
    var dose = queue[0];

    window.MediCare.post('api/reminder_check.php', {
      action: action,
      reminder_id: dose.reminder_id,
      scheduled_at: dose.scheduled_at
    }).then(function (res) {
      busy = false;
      queue.shift();
      window.MediCare.closeModal('#reminderModal');

      // A snoozed dose must be able to pop up again later.
      if (action === 'snoozed') {
        delete shown[dose.reminder_id + '@' + dose.scheduled_at];
      }

      if (res && res.success) {
        window.MediCare.toast(res.message || 'Saved.', 'success');
        updateRow(dose, action);
        refreshCounters(res);
      } else {
        window.MediCare.toast((res && res.message) || 'Could not save that action.', 'danger');
      }

      if (queue.length) { setTimeout(function () { render(queue[0]); }, 600); }
    }).catch(function () {
      busy = false;
      window.MediCare.toast('Connection problem - the action was not saved.', 'danger');
    });
  }

  /* ---- update the "Today's Medicine Schedule" table without reload -- */
  function updateRow(dose, action) {
    var row = document.querySelector('tr[data-reminder="' + dose.reminder_id + '"]');
    if (!row) { return; }

    var cell = row.querySelector('[data-status]');
    if (cell) {
      var map = { taken: 'success', skipped: 'secondary', snoozed: 'warning', missed: 'danger' };
      var label = action.charAt(0).toUpperCase() + action.slice(1);
      cell.innerHTML = '<span class="badge bg-' + (map[action] || 'secondary') + '">' + label + '</span>';
    }
    row.classList.remove('taken', 'missed');
    if (action === 'taken') { row.classList.add('taken'); }

    var buttons = row.querySelector('[data-dose-actions]');
    if (buttons && action !== 'snoozed') { buttons.innerHTML = '<span class="text-muted small">Recorded</span>'; }
  }

  function refreshCounters(res) {
    if (!res.counts) { return; }
    Object.keys(res.counts).forEach(function (key) {
      var el = document.querySelector('[data-count="' + key + '"]');
      if (el) { el.textContent = res.counts[key]; }
    });
  }

  /* ----------------------------- polling ---------------------------- */
  function check() {
    window.MediCare.get('api/reminder_check.php?action=due')
      .then(function (res) {
        if (!res || !res.success || !res.due || !res.due.length) { return; }

        res.due.forEach(function (dose) {
          var key = dose.reminder_id + '@' + dose.scheduled_at;
          if (shown[key]) { return; }
          shown[key] = true;
          queue.push(dose);
        });

        if (queue.length && !document.querySelector('#reminderModal.show')) {
          render(queue[0]);
        }
      })
      .catch(function () { /* offline / navigating away - ignore */ });
  }

  document.addEventListener('DOMContentLoaded', function () {
    buildModal();
    check();                              // immediately on page load
    setInterval(check, CHECK_INTERVAL);   // then every 30 seconds
  });
})();
