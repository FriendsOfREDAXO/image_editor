/*
 * image_editor für REDAXO 5 – Zuschnitte je Bildformat (Dialog aus KLXM Studio, MIT).
 *
 * Boxen [data-image-editor] (klassischer Medienpool, MediaPlace-Detailansicht) zeigen je Format eine Vorschau;
 * ein Klick öffnet den Ausschnitt dieses Formats. MediaPlace lädt seine Detailansicht nachträglich – ein
 * MutationObserver verbindet neue Boxen.
 *
 * API: POST file, action=info|crop (ratio, rect) → { ok, item | error }
 */
import { cropImage } from './_crop.js';

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

async function call(api, data) {
  const fd = new FormData();
  for (const [k, v] of Object.entries(data)) fd.append(k, typeof v === 'string' ? v : JSON.stringify(v));
  let res;
  try {
    res = await fetch(String(api).replace(/&amp;/g, '&'), { method: 'POST', body: fd, credentials: 'same-origin' });
  } catch {
    throw new Error('Keine Verbindung zum Server.');
  }
  const json = await res.json().catch(() => ({ ok: false, error: 'Sitzung abgelaufen – bitte die Seite neu laden.' }));
  if (!json.ok) throw new Error(json.error || 'Unbekannter Fehler.');
  return json.item;
}

/** Ausschnitt-Dialog für ein Format → Promise: item oder undefined */
async function crop({ file, api, ratio = '' }) {
  const m = await call(api, { file, action: 'info' });
  if (m.editable) throw new Error(m.editable);
  return cropImage(m, { ratio, only: ratio, save: (r, rect) => call(api, { file, action: 'crop', ratio: r, rect: JSON.stringify(rect) }) });
}

function connect(box) {
  if (box.dataset.ieReady) return;
  box.dataset.ieReady = '1';
  const cfg = JSON.parse(box.dataset.imageEditor);
  let m;
  const render = () => {
    box.innerHTML = `
      <div class="ie-thumbs">${(m.ratios || []).map((r) => `<button type="button" class="ie-thumb${m.crops?.[r] ? ' is-set' : ''}" data-ie-ratio="${r}" title="${m.crops?.[r] ? 'Eigener Ausschnitt' : 'Automatisch (Fokuspunkt)'}">
        <span class="ie-thumb__img" style="aspect-ratio:${r.replace(':', '/')}"><img src="${esc(m.thumbs?.[r] || '')}" alt="" loading="lazy"></span><span class="ie-thumb__r">${r}</span></button>`).join('')}</div>
      <p class="help-block ie-hint">Klick auf ein Format: Ausschnitt wählen (ziehen, zoomen). Ohne eigenen Ausschnitt wird um den Fokuspunkt geschnitten${m.focuspoint ? ' (focuspoint)' : ''}; ein Punkt zeigt einen eigenen Ausschnitt.</p>
      <p class="help-block text-danger" data-image-editor-state hidden></p>`;
  };
  const fail = (err) => { const st = box.querySelector('[data-image-editor-state]'); if (st) { st.textContent = err.message; st.hidden = false; } };
  call(cfg.api, { file: cfg.file, action: 'info' }).then((item) => { m = item; render(); }).catch((err) => { box.textContent = err.message; });
  box.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-ie-ratio]');
    if (!b || !m) return;
    e.preventDefault();
    e.stopPropagation();   // MediaPlace soll den Klick nicht als Auswahl im Raster werten
    try {
      const item = await crop({ file: cfg.file, api: cfg.api, ratio: b.dataset.ieRatio });
      if (item) { m = item; render(); }
    } catch (err) { fail(err); }
  });
}

function init(scope = document) {
  scope.querySelectorAll?.('[data-image-editor]').forEach(connect);
}

window.ImageEditor = { crop, init };
const start = () => {
  init(document);
  new MutationObserver((list) => list.forEach((r) => r.addedNodes.forEach((n) => {
    if (n.nodeType !== 1) return;
    if (n.matches?.('[data-image-editor]')) connect(n);
    init(n);
  }))).observe(document.body, { childList: true, subtree: true });
};
if (document.readyState !== 'loading') start();
else document.addEventListener('DOMContentLoaded', start);
