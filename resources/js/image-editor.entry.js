/*
 * friendsofredaxo/image_editor – Bildeditor aus KLXM Studio (_imageedit.js, MIT) für REDAXO: window.ImageEditor.
 *
 *   ImageEditor.open({ file, api, tool })   Dialog öffnen → Promise: gespeicherte Angaben (item) oder undefined
 *   ImageEditor.adjust({ file, api, tab })  Dialog „Bild anpassen“ (tab: adjust | focus)
 *   ImageEditor.crop({ file, api, ratio })   Ausschnitt je Bildformat (16:9, 4:3 …) – ratio = Format, das vorgewählt ist
 *   ImageEditor.setLayer(el)                Ebene für den Dialog (z. B. Shadow DOM eines Editors); CSS dort einbinden
 *   ImageEditor.init(scope)                 Werkzeugleisten [data-image-editor] im Backend (Medienpool) verbinden
 *
 * API (assets: ImageEditorAddon::config()['api']): POST file, action=info|save, ops → { ok, item | error }.
 */
import { editImage, EDIT_TOOLS } from './_imageedit.js';
import { setLayer } from './_shadow.js';
import { adjustImage } from './_adjust.js';
import { cropImage } from './_crop.js';

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const LABELS = { crop: 'Zuschneiden', rotate: 'Drehen', flip: 'Spiegeln', straighten: 'Ausrichten', perspective: 'Entzerren' };

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

async function open({ file, api, tool = 'crop' }) {
  const m = await call(api, { file, action: 'info' });
  if (m.editable) throw new Error(m.editable);
  return editImage(m, {
    tool,
    save: async (ops) => ({ item: await call(api, { file, action: 'save', ops: JSON.stringify(ops || {}) }) }),
  });
}

/** Dialog „Bild anpassen“ (Effekte, Regler, Fokuspunkt, Einpassen) → Promise: item oder undefined */
async function adjust({ file, api, tab = 'adjust' }) {
  const m = await call(api, { file, action: 'info' });
  if (m.editable) throw new Error(m.editable);
  return adjustImage(m, {
    tab,
    save: (v) => call(api, { file, action: 'adjust', fx: v.fx, focus: JSON.stringify(v.focus), fit: v.fit }),
  });
}

/** Ausschnitt je Bildformat → Promise: item (nach dem letzten gespeicherten Format) oder undefined */
async function crop({ file, api, ratio = '' }) {
  const m = await call(api, { file, action: 'info' });
  if (m.editable) throw new Error(m.editable);
  return cropImage(m, {
    ratio,
    only: ratio,
    save: (r, rect) => call(api, { file, action: 'crop', ratio: r, rect: JSON.stringify(rect) }),
  });
}

function init(scope = document) {
  scope.querySelectorAll('[data-image-editor]').forEach((box) => {
    if (box.dataset.ieReady) return;
    box.dataset.ieReady = '1';
    const cfg = JSON.parse(box.dataset.imageEditor);
    const bar = box.querySelector('[data-image-editor-tools]');
    bar.innerHTML = EDIT_TOOLS.map((k) => `<button type="button" class="ie-bar__btn" data-ie-tool="${k}"><span>${esc(LABELS[k])}</span></button>`).join('')
      + '<button type="button" class="ie-bar__btn" data-ie-adjust="adjust"><span>Anpassen</span></button>'
      + '<button type="button" class="ie-bar__btn" data-ie-adjust="focus"><span>Fokus &amp; Einpassen</span></button>'
      + '<button type="button" class="ie-bar__btn" data-ie-crop><span>Ausschnitt je Format</span></button>';
    bar.addEventListener('click', async (e) => {
      const b = e.target.closest('[data-ie-tool],[data-ie-adjust],[data-ie-crop]');
      if (!b) return;
      try {
        const item = b.dataset.ieAdjust
          ? await adjust({ file: cfg.file, api: cfg.api, tab: b.dataset.ieAdjust })
          : ('ieCrop' in b.dataset ? await crop({ file: cfg.file, api: cfg.api }) : await open({ file: cfg.file, api: cfg.api, tool: b.dataset.ieTool }));
        if (!item) return;
        const img = box.querySelector('.image-editor-preview img');
        if (img) img.src = item.url;
        const st = box.querySelector('[data-image-editor-state]');
        if (st) st.innerHTML = (item.edit || item.fx) ? '<strong>Bearbeitet</strong>' + (item.fx_label ? ' · ' + esc(item.fx_label) : '') + ' – das Original bleibt erhalten.' : 'Original – keine Bearbeitung.';
      } catch (err) {
        const st = box.querySelector('[data-image-editor-state]');
        if (st) { st.textContent = err.message; st.classList.add('text-danger'); }
      }
    });
  });
}

window.ImageEditor = { open, adjust, crop, init, setLayer };
if (document.readyState !== 'loading') init(document);
else document.addEventListener('DOMContentLoaded', () => init(document));
if (window.jQuery?.fn) window.jQuery(document).on('rex:ready', (e, c) => init(c?.[0] || document));
