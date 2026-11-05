/* Progressive enhancements: every query and calendar link also works without JavaScript. */
(() => {
  'use strict';
  const initialize = () => {
    document.querySelectorAll('.kn-portal .kn-filter-form').forEach((form) => {
      if (form.dataset.knReady) return;
      form.dataset.knReady = 'true';
      const feedback = form.querySelector('.kn-filter-feedback');
      const groups = [...form.querySelectorAll('[data-kn-filter-group]')];
      const update = (changed) => {
        let empty = false;
        groups.forEach((group) => {
          const boxes = [...group.querySelectorAll('input[type="checkbox"]')];
          const count = boxes.filter((box) => box.checked).length;
          if (!count) empty = true;
          group.querySelector('[data-kn-check="all"]')?.setAttribute('aria-pressed', String(boxes.length > 0 && count === boxes.length));
          group.querySelector('[data-kn-check="none"]')?.setAttribute('aria-pressed', String(count === 0));
        });
        if (changed && feedback) {
          feedback.textContent = empty
            ? 'Eine Auswahl ist leer. „Ergebnisse anzeigen“ zeigt dann keine Veranstaltungen.'
            : 'Auswahl geändert. Mit „Ergebnisse anzeigen“ auf alle Veranstaltungen anwenden.';
        }
      };
      groups.forEach((group) => {
        const actions = group.querySelector('.kn-check-actions');
        if (actions) actions.hidden = false;
        group.addEventListener('click', (event) => {
          const button = event.target.closest('[data-kn-check]');
          if (!button || !group.contains(button)) return;
          group.querySelectorAll('input[type="checkbox"]').forEach((box) => { box.checked = button.dataset.knCheck === 'all'; });
          update(true);
        });
      });
      form.addEventListener('change', () => update(true));
      form.addEventListener('submit', () => {
        if (feedback) feedback.textContent = 'Passende Veranstaltungen werden geladen …';
        form.setAttribute('aria-busy', 'true');
      });
      update(false);
    });
  };
  // Chips mirror their checkbox state as classes for browsers without :has() support.
  const syncChips = () => {
    document.querySelectorAll('.kn-portal .kn-check-list .kn-check input').forEach((box) => {
      const chip = box.closest('.kn-check');
      chip.classList.toggle('is-checked', box.checked);
      if (box.dataset.knChip) return;
      box.dataset.knChip = 'true';
      box.addEventListener('change', () => chip.classList.toggle('is-checked', box.checked));
      box.addEventListener('focus', () => { if (box.matches(':focus-visible')) chip.classList.add('has-focus'); });
      box.addEventListener('blur', () => chip.classList.remove('has-focus'));
    });
  };
  // Narrow blocks start with the choices collapsed; the summary names the current selection.
  const summarize = () => {
    document.querySelectorAll('.kn-portal .kn-filter-details').forEach((details) => {
      const summary = details.querySelector('.kn-filter-summary');
      const parts = [...details.querySelectorAll('[data-kn-filter-group]')].map((group) => {
        const boxes = [...group.querySelectorAll('input[type="checkbox"]')];
        return `${boxes.filter((b) => b.checked).length} von ${boxes.length} ${group.querySelector('legend')?.textContent || ''}`;
      });
      if (summary && parts.length) summary.textContent = `Auswahl: ${parts.join(', ')}`;
      if (!details.dataset.knSized) {
        details.dataset.knSized = 'true';
        if ((details.closest('.kn-portal')?.clientWidth || 9999) < 576) details.open = false;
      }
    });
  };
  // The summary is hidden on wide blocks, so a collapsed selection must reopen when the block grows.
  let resizeTimer;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => document.querySelectorAll('.kn-portal .kn-filter-details:not([open])').forEach((details) => {
      if ((details.closest('.kn-portal')?.clientWidth || 0) >= 576) details.open = true;
    }), 150);
  });
  // PDF flyer: the bundled pdf.js draws the first page at the file's own proportions, without a viewer frame.
  // The box stays hidden until the page is drawn; if anything fails, only the download below remains.
  const drawFlyer = async (pdfjs, box) => {
    // Image decoders and the CMYK profile lie next to pdf.js in the plugin and are loaded only when a PDF needs them.
    const folder = new URL('./', box.dataset.knPdfLib).href;
    const page = await (await pdfjs.getDocument({ url: box.dataset.knPdf, wasmUrl: `${folder}wasm/`, iccUrl: `${folder}iccs/` }).promise).getPage(1);
    const base = page.getViewport({ scale: 1 });
    const holder = box.parentElement;
    let drawn = 0;
    let task = null;
    const draw = () => {
      // Sharp on high-density screens, but never larger than needed or than a phone can hold.
      const pixels = Math.min(Math.round(holder.clientWidth * Math.min(window.devicePixelRatio || 1, 2)), 2400);
      if (!pixels || Math.abs(pixels - drawn) < 8) return Promise.resolve();
      task?.cancel();
      const viewport = page.getViewport({ scale: pixels / base.width });
      const canvas = document.createElement('canvas');
      canvas.width = Math.round(viewport.width);
      canvas.height = Math.round(viewport.height);
      task = page.render({ canvas, viewport });
      return task.promise.then(() => { box.replaceChildren(canvas); box.hidden = false; drawn = pixels; });
    };
    await draw();
    // Redrawn when the column changes width, e.g. when a phone is turned.
    let timer;
    if (window.ResizeObserver) new ResizeObserver(() => { clearTimeout(timer); timer = setTimeout(() => draw().catch(() => {}), 150); }).observe(holder);
  };
  const drawPdfFlyers = () => {
    const boxes = [...document.querySelectorAll('.kn-portal .kn-event-flyer-pdf[data-kn-pdf]')].filter((box) => !box.dataset.knPdfStarted);
    if (!boxes.length) return;
    boxes.forEach((box) => { box.dataset.knPdfStarted = 'true'; });
    import(boxes[0].dataset.knPdfLib).then((pdfjs) => {
      pdfjs.GlobalWorkerOptions.workerSrc = boxes[0].dataset.knPdfWorker;
      boxes.forEach((box) => drawFlyer(pdfjs, box).catch(() => { box.hidden = true; }));
    }).catch(() => {});
  };
  const start = () => { initialize(); syncChips(); summarize(); drawPdfFlyers(); };
  document.addEventListener('change', (event) => { if (event.target.closest('.kn-portal .kn-filter-details')) summarize(); });
  document.addEventListener('click', (event) => { if (event.target.closest('.kn-portal [data-kn-check]')) { syncChips(); summarize(); } });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
