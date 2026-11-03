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

  // Flyer, banner and default banner: a file from the media library; the hidden field carries its ID.
  document.querySelectorAll('[data-kn-media]').forEach(box => {
    const input = box.querySelector('.kn-media-input'), name = box.querySelector('.kn-media-name');
    const choose = box.querySelector('.kn-media-choose'), remove = box.querySelector('.kn-media-remove'), preview = box.querySelector('.kn-media-preview');
    const noun = box.dataset.knNoun || 'Datei', types = (box.dataset.knTypes || '').split(',').filter(Boolean);
    if (!input || !choose) return;
    if (!(window.wp && wp.media)) { choose.disabled = true; return; }
    let frame = null;
    choose.addEventListener('click', () => {
      if (!frame) {
        frame = wp.media({ title: noun + ' auswählen', button: { text: 'Als ' + noun + ' verwenden' }, library: { type: types }, multiple: false });
        frame.on('select', () => {
          const file = frame.state().get('selection').first().toJSON();
          input.value = String(file.id);
          name.textContent = file.filename || file.title || noun + ' ausgewählt';
          choose.textContent = 'Anderen ' + noun + ' wählen';
          if (remove) remove.hidden = false;
          if (preview) {
            const size = file.sizes && (file.sizes.medium || file.sizes.full);
            if (file.type === 'image' && size) { preview.src = size.url; preview.hidden = false; } else { preview.removeAttribute('src'); preview.hidden = true; }
          }
          input.dispatchEvent(new Event('change', { bubbles: true }));
        });
      }
      frame.open();
    });
    if (remove) remove.addEventListener('click', () => {
      input.value = '';
      name.textContent = 'Kein ' + noun + ' ausgewählt';
      choose.textContent = noun + ' auswählen';
      remove.hidden = true;
      if (preview) { preview.removeAttribute('src'); preview.hidden = true; }
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });
  });

  // Address: the region list shows only the regions of the chosen country, the label says Bundesland or Kanton.
  const country = document.querySelector('[data-kn-country-select]'), region = document.querySelector('[data-kn-region-select]');
  if (country && region) {
    const groups = Array.from(region.querySelectorAll('optgroup'));
    const label = document.querySelector('[data-kn-region-label]');
    const filter = () => {
      groups.forEach(group => { if (group.dataset.knFor === country.value || !country.value) { if (!group.parentNode) region.appendChild(group); } else if (group.parentNode) group.remove(); });
      if (region.selectedIndex < 0) region.value = '';
      if (label) label.textContent = { DE: 'Bundesland', AT: 'Bundesland', CH: 'Kanton' }[country.value] || 'Bundesland / Kanton';
    };
    country.addEventListener('change', filter);
    filter();
  }
  // Admission: the price field is only needed for "Preis".
  const priceMode = document.querySelector('[data-kn-price-mode]'), priceText = document.querySelector('[data-kn-price-text]');
  if (priceMode && priceText) {
    const toggle = () => { const chosen = priceMode.querySelector('input:checked'); priceText.hidden = !chosen || chosen.value !== 'paid'; };
    priceMode.addEventListener('change', toggle);
    toggle();
  }

  // Focus layout of the event editor (body.kn-focus from the server): the toolbar uses WordPress's own buttons,
  // the picture moves below the title and a preview follows the form. "Klassische Ansicht" is remembered per browser.
  const body = document.body, bar = document.querySelector('.kn-focus-bar');
  if (bar && (body.classList.contains('kn-focus') || body.classList.contains('kn-focus-off'))) {
    const store = { get() { try { return localStorage.getItem('kn-focus-off') === '1'; } catch (_) { return false; } }, set(v) { try { localStorage.setItem('kn-focus-off', v ? '1' : '0'); } catch (_) { /* without storage the choice lasts for this page */ } } };
    const toggle = bar.querySelector('[data-kn-focus-toggle]');
    const apply = classic => {
      body.classList.toggle('kn-focus', !classic); body.classList.toggle('kn-focus-off', classic);
      if (toggle) toggle.textContent = classic ? 'Fokus-Ansicht' : 'Klassische Ansicht';
    };
    if (toggle) { toggle.hidden = false; toggle.addEventListener('click', () => { const classic = body.classList.contains('kn-focus'); store.set(classic); apply(classic); }); }
    apply(store.get());
    bar.querySelectorAll('[data-kn-click]').forEach(button => {
      const target = document.querySelector(button.dataset.knClick);
      if (!target) return;
      button.hidden = false;
      if (button.classList.contains('kn-focus-primary') && target.value) button.textContent = target.value;
      button.addEventListener('click', () => target.click());
    });
    const image = document.getElementById('postimagediv'), title = document.getElementById('titlediv');
    if (image && title) title.insertAdjacentElement('afterend', image);
    const preview = document.querySelector('.kn-focus-preview');
    if (preview) {
      preview.hidden = false;
      const pv = name => preview.querySelector('[data-kn-pv="' + name + '"]');
      const value = selector => { const el = document.querySelector(selector); return el ? el.value : ''; };
      const day = new Intl.DateTimeFormat('de-DE', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
      const show = (img, src) => { if (src) { if (img.getAttribute('src') !== src) img.src = src; img.hidden = false; } else { img.removeAttribute('src'); img.hidden = true; } };
      const date = new Intl.DateTimeFormat('de-DE', { day: 'numeric', month: 'long', year: 'numeric' });
      const placeholder = { kn_institution: 'Name der Institution', kn_job: 'Titel der Stelle', kn_news: 'Überschrift der Nachricht' }[preview.dataset.knType] || 'Titel der Veranstaltung';
      const update = () => {
        const set = (name, text) => { const el = pv(name); if (el) el.textContent = text; };
        set('title', value('#title').trim() || placeholder);
        if (pv('when')) {
          const start = value('[name="kn_fields[start]"]'), end = value('[name="kn_fields[end]"]');
          let when = 'Datum und Uhrzeit wählen';
          if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(start)) when = day.format(new Date(start)) + ' | ' + start.slice(11, 16) + (end.slice(0, 10) === start.slice(0, 10) && end.length === 16 ? '–' + end.slice(11, 16) : '') + ' Uhr';
          set('when', when);
        }
        // Simple fields: the preview line shows the field value; a row without value disappears.
        preview.querySelectorAll('[data-kn-pv-field]').forEach(el => {
          let text = value('[name="kn_fields[' + el.dataset.knPvField + ']"]').trim();
          if (/^\d{4}-\d{2}-\d{2}$/.test(text)) text = date.format(new Date(text + 'T12:00'));
          el.textContent = text;
          const row = el.closest('[data-kn-pv-row]') || el; row.hidden = !text;
        });
        // Composed lines such as the address: "|" separates lines, "," joins with a comma, a space joins with a space.
        const field = name => { const el = document.querySelector('[name="kn_fields[' + name + ']"]'); if (!el) return ''; return el.tagName === 'SELECT' ? (el.value ? el.options[el.selectedIndex].text : '') : el.value.trim(); };
        preview.querySelectorAll('[data-kn-pv-join]').forEach(el => {
          const lines = el.dataset.knPvJoin.split('|').map(line => line.split(',').map(part => part.split(' ').map(field).filter(Boolean).join(' ')).filter(Boolean).join(', ')).filter(Boolean);
          el.textContent = lines.join('dataKnLines' in el.dataset ? '\n' : ', ');
          const row = el.closest('[data-kn-pv-row]') || el; row.hidden = !lines.length;
        });
        const price = preview.querySelector('[data-kn-pv-price]');
        if (price) {
          const mode = document.querySelector('[name="kn_fields[price_mode]"]:checked'), amount = field('price');
          const text = mode && mode.value === 'free' ? 'kostenlos' : (mode && mode.value === 'paid' ? amount : '');
          price.textContent = text; price.closest('[data-kn-pv-row]').hidden = !text;
        }
        const editor = window.tinymce && window.tinymce.get('content');
        const text = editor && !editor.isHidden() ? editor.getContent({ format: 'text' }) : value('#content').replace(/<[^>]+>/g, ' ');
        set('text', text.replace(/\s+/g, ' ').trim().slice(0, 280) || 'Hier erscheint deine Beschreibung.');
        const picture = document.querySelector('#postimagediv img');
        if (pv('image')) show(pv('image'), picture ? (picture.currentSrc || picture.src) : '');
        if (pv('banner')) {
          const own = document.getElementById('kn-banner'), ownPreview = own && own.closest('[data-kn-media]').querySelector('.kn-media-preview');
          const banner = own && own.value && ownPreview && ownPreview.getAttribute('src') ? ownPreview.getAttribute('src') : preview.dataset.knDefaultBanner;
          show(pv('banner'), banner); pv('banner').parentNode.hidden = !banner;
        }
        if (pv('flyer')) pv('flyer').hidden = !value('#kn-flyer');
        // Only a web address becomes the button; any other text appears as plain text on the job page.
        if (pv('apply')) pv('apply').hidden = !/^https?:\/\/\S+$/i.test(value('[name="kn_fields[application]"]').trim());
      };
      update();
      window.setInterval(update, 1000);
    }
  }
})();
