(function () {
  'use strict';
  function updateTopics(changed) {
    const boxes = Array.from(document.querySelectorAll('[name="kn_topics[]"]'));
    if (boxes.filter(b => b.checked).length > 3 && changed) changed.checked = false;
    const count = boxes.filter(b => b.checked).length;
    const feedback = document.getElementById('kn-category-feedback');
    if (feedback) feedback.textContent = count + ' von maximal 3 Kategorien gewählt.';
    boxes.forEach(b => { b.disabled = count === 3 && !b.checked; });
  }
  document.addEventListener('change', e => {
    if (e.target.name === 'kn_topics[]') updateTopics(e.target);
    if (e.target.id === 'kn-send-mail') {
      const select = document.getElementById('kn-mail-mode');
      if (select) select.disabled = !e.target.checked;
    }
  });
  document.addEventListener('click', async e => {
    const b = e.target.closest('[data-kn-copy]'); if (!b) return;
    try {
      await navigator.clipboard.writeText(b.dataset.knCopy);
      b.textContent = 'Kopiert ✓';
      document.getElementById('kn-copy-feedback').textContent = b.dataset.knCopy + ' kopiert.';
    } catch (_) {
      const code = b.parentElement.querySelector('code');
      const range = document.createRange(); range.selectNodeContents(code);
      const selection = window.getSelection(); selection.removeAllRanges(); selection.addRange(range);
      document.getElementById('kn-copy-feedback').textContent = 'Shortcode markiert. Bitte kopieren.';
    }
  });
  updateTopics();
  const toggle = document.getElementById('kn-send-mail'), mode = document.getElementById('kn-mail-mode');
  if (toggle && mode) mode.disabled = !toggle.checked;
})();
