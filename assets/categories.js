/* konstanth – Kategorien & Farben: Farbwähler, Live-Vorschau mit Kontrastwert, Rückfrage vor dem Löschen. */
(function ($) {
  'use strict';
  function rgb(hex) { hex = hex.replace('#', ''); if (hex.length === 3) { hex = hex.replace(/(.)/g, '$1$1'); } return [0, 2, 4].map(function (i) { return parseInt(hex.substr(i, 2), 16); }); }
  function lum(hex) { var c = rgb(hex).map(function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }); return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]; }
  function contrast(a, b) { var x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); }
  function hsl(l, h, s) {
    l = Math.max(0, Math.min(1, l)); var c = (1 - Math.abs(2 * l - 1)) * s, x = c * (1 - Math.abs((h / 60) % 2 - 1)), m = l - c / 2;
    var p = h < 60 ? [c, x, 0] : h < 120 ? [x, c, 0] : h < 180 ? [0, c, x] : h < 240 ? [0, x, c] : h < 300 ? [x, 0, c] : [c, 0, x];
    return '#' + p.map(function (v) { var n = Math.round((v + m) * 255).toString(16); return n.length < 2 ? '0' + n : n; }).join('');
  }
  // Mirrors KN_Categories::ink_for() so the preview shows exactly what the website will use.
  function ink(bg) {
    var c = rgb(bg).map(function (v) { return v / 255; }), max = Math.max.apply(null, c), min = Math.min.apply(null, c), l = (max + min) / 2, d = max - min, h = 0, s = 0, step, cand, fallback = '';
    if (d > 0) { s = d / (1 - Math.abs(2 * l - 1)); h = max === c[0] ? ((c[1] - c[2]) / d) % 6 : max === c[1] ? (c[2] - c[0]) / d + 2 : (c[0] - c[1]) / d + 4; h = (h * 60 + 360) % 360; }
    s = s < 0.05 ? 0 : Math.max(0.25, Math.min(s, 0.8));
    for (step = 0; step <= 45; step++) { cand = hsl(Math.min(l, 0.45) - step * 0.01, h, s); if (contrast(cand, bg) >= 6.5) { return cand; } if (!fallback && contrast(cand, bg) >= 4.5) { fallback = cand; } }
    if (fallback) { return fallback; }
    for (step = 0; step <= 45; step++) { cand = hsl(Math.max(l, 0.55) + step * 0.01, h, s); if (contrast(cand, bg) >= 4.5) { return cand; } }
    return contrast('#000000', bg) >= contrast('#ffffff', bg) ? '#000000' : '#ffffff';
  }
  function preview(input, color) {
    var target = document.getElementById(input.getAttribute('data-preview'));
    if (!target || !/^#([0-9a-f]{3}){1,2}$/i.test(color || '')) { return; }
    var text = ink(color);
    target.style.background = color; target.style.color = text;
    var note = target.nextElementSibling;
    if (note) { note.textContent = ' Kontrast ' + contrast(text, color).toFixed(1).replace('.', ',') + ':1' + (lum(color) > 0.55 ? '' : ' · dunkle Farbe – für Pastell heller wählen'); }
  }
  $(function () {
    $('.kn-color').each(function () {
      var input = this;
      $(input).wpColorPicker({ change: function (event, ui) { preview(input, ui.color.toString()); }, clear: function () { preview(input, ''); } });
      preview(input, input.value);
    });
    var form = document.getElementById('kn-categories-form');
    if (form) {
      form.addEventListener('submit', function (event) {
        var marked = Array.prototype.filter.call(form.querySelectorAll('.kn-delete'), function (box) { return box.checked; });
        if (!marked.length) { return; }
        var lines = marked.map(function (box) { var row = box.closest('tr'); var name = row.querySelector('input[type="text"]').value; return '• ' + name + ' (' + box.getAttribute('data-usage') + ')'; });
        if (!window.confirm('Diese Kategorien werden endgültig gelöscht und Themen-Abos beendet:\n\n' + lines.join('\n') + '\n\nFortfahren?')) { event.preventDefault(); }
      });
    }
  });
})(jQuery);
