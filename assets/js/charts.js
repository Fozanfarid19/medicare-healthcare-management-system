/* =====================================================================
   MediCare - assets/js/charts.js
   Tiny dependency-free canvas chart library (bar / line / doughnut).
   Written locally so the dashboards keep working with NO internet
   connection and NO CDN.  Usage:

       MediCharts.bar('apptChart', {labels:[...], values:[...]});
       MediCharts.line('regChart', {labels:[...], values:[...]});
       MediCharts.doughnut('statusChart', {labels:[...], values:[...]});
   ===================================================================== */
(function () {
  'use strict';

  var PALETTE = ['#1266d4', '#1a9b62', '#e0a800', '#d64545', '#0dabc4',
                 '#7a5af8', '#f2711c', '#0f2745', '#8a94a6', '#00b894'];

  function setup(id) {
    var canvas = document.getElementById(id);
    if (!canvas || !canvas.getContext) { return null; }

    var ratio = window.devicePixelRatio || 1;
    var box   = canvas.parentNode.getBoundingClientRect();
    var w     = Math.max(240, Math.floor(box.width - 2));
    var h     = canvas.dataset.height ? parseInt(canvas.dataset.height, 10) : 240;

    canvas.width  = w * ratio;
    canvas.height = h * ratio;
    canvas.style.width  = w + 'px';
    canvas.style.height = h + 'px';

    var ctx = canvas.getContext('2d');
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    ctx.clearRect(0, 0, w, h);
    ctx.font = '11px "Segoe UI",Arial,sans-serif';
    return { canvas: canvas, ctx: ctx, w: w, h: h };
  }

  function niceMax(value) {
    if (value <= 5)  { return 5; }
    var pow  = Math.pow(10, Math.floor(Math.log10(value)));
    var step = pow / 2;
    return Math.ceil(value / step) * step;
  }

  function drawGrid(ctx, w, h, pad, max, steps) {
    ctx.strokeStyle = '#e9eff7';
    ctx.fillStyle   = '#8494a6';
    ctx.lineWidth   = 1;
    ctx.textAlign   = 'right';
    ctx.textBaseline = 'middle';

    for (var i = 0; i <= steps; i++) {
      var y = pad.top + (h - pad.top - pad.bottom) * (i / steps);
      var v = Math.round(max - (max * i / steps));
      ctx.beginPath();
      ctx.moveTo(pad.left, y + 0.5);
      ctx.lineTo(w - pad.right, y + 0.5);
      ctx.stroke();
      ctx.fillText(String(v), pad.left - 8, y);
    }
  }

  function xLabels(ctx, labels, pad, w, h, band) {
    ctx.fillStyle    = '#5a6d84';
    ctx.textAlign    = 'center';
    ctx.textBaseline = 'top';
    var skip = Math.ceil(labels.length / Math.max(1, Math.floor((w - pad.left - pad.right) / 55)));

    labels.forEach(function (label, i) {
      if (i % skip !== 0) { return; }
      ctx.fillText(String(label), pad.left + band * (i + 0.5), h - pad.bottom + 8);
    });
  }

  var MediCharts = {

    /* ------------------------------ BAR ----------------------------- */
    bar: function (id, cfg) {
      var s = setup(id);
      if (!s) { return; }
      var ctx = s.ctx, values = cfg.values || [], labels = cfg.labels || [];
      var pad = { top: 16, right: 14, bottom: 30, left: 40 };
      var max = niceMax(Math.max.apply(null, values.concat([1])));
      var plotH = s.h - pad.top - pad.bottom;
      var band  = (s.w - pad.left - pad.right) / Math.max(1, values.length);

      drawGrid(ctx, s.w, s.h, pad, max, 4);

      values.forEach(function (v, i) {
        var barW = Math.min(46, band * 0.6);
        var x    = pad.left + band * (i + 0.5) - barW / 2;
        var barH = (v / max) * plotH;
        var y    = pad.top + plotH - barH;
        var color = (cfg.colors && cfg.colors[i]) || cfg.color || PALETTE[0];

        var grad = ctx.createLinearGradient(0, y, 0, y + barH);
        grad.addColorStop(0, color);
        grad.addColorStop(1, color + 'bb');
        ctx.fillStyle = grad;

        var r = Math.min(5, barW / 2);
        ctx.beginPath();
        ctx.moveTo(x, y + barH);
        ctx.lineTo(x, y + r);
        ctx.quadraticCurveTo(x, y, x + r, y);
        ctx.lineTo(x + barW - r, y);
        ctx.quadraticCurveTo(x + barW, y, x + barW, y + r);
        ctx.lineTo(x + barW, y + barH);
        ctx.closePath();
        ctx.fill();

        if (v > 0) {
          ctx.fillStyle    = '#3d5166';
          ctx.textAlign    = 'center';
          ctx.textBaseline = 'bottom';
          ctx.fillText(String(v), x + barW / 2, y - 3);
        }
      });

      xLabels(ctx, labels, pad, s.w, s.h, band);
    },

    /* ----------------------------- LINE ----------------------------- */
    line: function (id, cfg) {
      var s = setup(id);
      if (!s) { return; }
      var ctx = s.ctx, values = cfg.values || [], labels = cfg.labels || [];
      var pad = { top: 16, right: 16, bottom: 30, left: 40 };
      var max = niceMax(Math.max.apply(null, values.concat([1])));
      var plotH = s.h - pad.top - pad.bottom;
      var band  = (s.w - pad.left - pad.right) / Math.max(1, values.length);
      var color = cfg.color || PALETTE[0];

      drawGrid(ctx, s.w, s.h, pad, max, 4);

      var points = values.map(function (v, i) {
        return { x: pad.left + band * (i + 0.5), y: pad.top + plotH - (v / max) * plotH, v: v };
      });

      if (points.length) {
        var area = ctx.createLinearGradient(0, pad.top, 0, pad.top + plotH);
        area.addColorStop(0, color + '44');
        area.addColorStop(1, color + '05');
        ctx.beginPath();
        ctx.moveTo(points[0].x, pad.top + plotH);
        points.forEach(function (p) { ctx.lineTo(p.x, p.y); });
        ctx.lineTo(points[points.length - 1].x, pad.top + plotH);
        ctx.closePath();
        ctx.fillStyle = area;
        ctx.fill();

        ctx.beginPath();
        points.forEach(function (p, i) { i ? ctx.lineTo(p.x, p.y) : ctx.moveTo(p.x, p.y); });
        ctx.strokeStyle = color;
        ctx.lineWidth = 2.5;
        ctx.lineJoin = 'round';
        ctx.stroke();

        points.forEach(function (p) {
          ctx.beginPath();
          ctx.arc(p.x, p.y, 3.5, 0, Math.PI * 2);
          ctx.fillStyle = '#fff';
          ctx.fill();
          ctx.lineWidth = 2;
          ctx.strokeStyle = color;
          ctx.stroke();
        });
      }

      xLabels(ctx, labels, pad, s.w, s.h, band);
    },

    /* --------------------------- DOUGHNUT --------------------------- */
    doughnut: function (id, cfg) {
      var s = setup(id);
      if (!s) { return; }
      var ctx = s.ctx, values = cfg.values || [], labels = cfg.labels || [];
      var total = values.reduce(function (a, b) { return a + Number(b); }, 0);
      var cx = s.w / 2, cy = s.h / 2, radius = Math.min(s.w, s.h) / 2 - 14;
      var inner = radius * 0.62;
      var start = -Math.PI / 2;

      if (total <= 0) {
        ctx.fillStyle = '#8494a6';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText('No data available yet', cx, cy);
        return;
      }

      values.forEach(function (v, i) {
        var angle = (Number(v) / total) * Math.PI * 2;
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.arc(cx, cy, radius, start, start + angle);
        ctx.closePath();
        ctx.fillStyle = (cfg.colors && cfg.colors[i]) || PALETTE[i % PALETTE.length];
        ctx.fill();
        start += angle;
      });

      ctx.globalCompositeOperation = 'destination-out';
      ctx.beginPath();
      ctx.arc(cx, cy, inner, 0, Math.PI * 2);
      ctx.fill();
      ctx.globalCompositeOperation = 'source-over';

      ctx.fillStyle    = '#12263f';
      ctx.textAlign    = 'center';
      ctx.textBaseline = 'middle';
      ctx.font         = 'bold 20px "Segoe UI",Arial,sans-serif';
      ctx.fillText(String(total), cx, cy - 6);
      ctx.font         = '11px "Segoe UI",Arial,sans-serif';
      ctx.fillStyle    = '#8494a6';
      ctx.fillText(cfg.centerLabel || 'Total', cx, cy + 12);

      // optional legend rendered into a sibling element
      if (cfg.legend) {
        var box = document.getElementById(cfg.legend);
        if (box) {
          box.innerHTML = labels.map(function (label, i) {
            var color = (cfg.colors && cfg.colors[i]) || PALETTE[i % PALETTE.length];
            return '<span><i style="background:' + color + '"></i>' + label + ' (' + values[i] + ')</span>';
          }).join('');
        }
      }
    }
  };

  window.MediCharts = MediCharts;

  // Redraw on resize so the dashboards stay sharp on tablets/phones.
  var redrawTimer = null;
  window.addEventListener('resize', function () {
    clearTimeout(redrawTimer);
    redrawTimer = setTimeout(function () {
      if (typeof window.renderMediCareCharts === 'function') { window.renderMediCareCharts(); }
    }, 250);
  });
})();
