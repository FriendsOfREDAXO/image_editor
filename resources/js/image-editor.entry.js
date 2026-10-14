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

const FIT_LABEL = { cover: 'Füllen (zuschneiden) – wie im Kit', contain: 'Einpassen (ganzes Bild im Rahmen)', original: 'Originalformat (Rahmen passt sich an)' };

/**
 * Medienpool (Seitenleiste eines Bildes), wie die Mediathek in KLXM Studio: Vorschau mit Fokuspunkt (ins Bild klicken),
 * Werkzeuge, „Zuschnitte je Format“ (Klick öffnet den Ausschnitt dieses Formats), Anpassen und Darstellung im Rahmen.
 */
function init(scope = document) {
  scope.querySelectorAll('[data-image-editor]').forEach(async (box) => {
    if (box.dataset.ieReady) return;
    box.dataset.ieReady = '1';
    const cfg = JSON.parse(box.dataset.imageEditor);
    const state = box.querySelector('[data-image-editor-state]');
    const fail = (err) => { if (state) { state.textContent = err.message; state.classList.add('text-danger'); } };
    let m;
    try { m = await call(cfg.api, { file: cfg.file, action: 'info' }); } catch (err) { fail(err); return; }

    const render = () => {
      const f = m.focus || { x: 50, y: 50 };
      box.innerHTML = `
        <div class="ie-pv" data-ie-focus title="Ins Bild klicken: Fokuspunkt setzen">
          <img src="${esc(m.url)}" alt="" draggable="false"><span class="ie-pv__dot" style="left:${f.x}%;top:${f.y}%"></span>
        </div>
        <p class="ie-orig"><a href="${esc(m.original)}" target="_blank" rel="noopener">Original ansehen</a>${m.original_size ? ` · ${m.original_size.w} × ${m.original_size.h} px` : ''}</p>
        <div class="ie-bar" role="toolbar" aria-label="Bild bearbeiten">${EDIT_TOOLS.map((k) => `<button type="button" class="ie-bar__btn" data-ie-tool="${k}"><span>${esc(LABELS[k])}</span></button>`).join('')}</div>
        <p class="help-block">Fokuspunkt: ins Bild klicken (z. B. aufs Gesicht). Dieser Bereich bleibt in jedem Format sichtbar.</p>
        <h4 class="ie-sec">Zuschnitte je Format <small>– klicken zum Zoomen und Zuschneiden</small></h4>
        <div class="ie-thumbs">${(m.ratios || []).map((r) => `<button type="button" class="ie-thumb${m.crops?.[r] ? ' is-set' : ''}" data-ie-ratio="${r}" title="${m.crops?.[r] ? 'Eigener Ausschnitt' : 'Automatisch (Fokuspunkt)'}">
          <span class="ie-thumb__img" style="aspect-ratio:${r.replace(':', '/')}"><img src="${esc(m.thumbs?.[r] || '')}" alt="" loading="lazy"></span><span class="ie-thumb__r">${r}</span></button>`).join('')}</div>
        <h4 class="ie-sec">Anpassen <small>– Effekte, Sättigung, Helligkeit, Kontrast, Schärfe</small></h4>
        <p class="ie-row"><span>${esc(m.fx_label || 'Original (ohne Anpassung)')}</span><button type="button" class="btn btn-default btn-xs" data-ie-adjust="adjust">Anpassen …</button></p>
        <h4 class="ie-sec">Darstellung im Rahmen <small>– füllen, einpassen oder Originalformat</small></h4>
        <p class="ie-row"><span>${esc(FIT_LABEL[m.fit] || FIT_LABEL.cover)}</span><button type="button" class="btn btn-default btn-xs" data-ie-adjust="focus">Rahmen …</button></p>
        <p class="help-block" data-image-editor-state>${m.edit || m.fx ? '<strong>Bearbeitet</strong> – das Original bleibt erhalten.' : 'Das Original bleibt immer erhalten.'}</p>`;
    };
    render();

    box.addEventListener('click', async (e) => {
      const pv = e.target.closest('[data-ie-focus]');
      const b = e.target.closest('[data-ie-tool],[data-ie-adjust],[data-ie-ratio]');
      try {
        if (pv) {
          // Fokuspunkt: Klick ins Bild, Anpassung und Rahmen bleiben
          const r = pv.querySelector('img').getBoundingClientRect();
          const focus = { x: Math.round(Math.min(100, Math.max(0, (e.clientX - r.left) / r.width * 100)) * 10) / 10, y: Math.round(Math.min(100, Math.max(0, (e.clientY - r.top) / r.height * 100)) * 10) / 10 };
          pv.querySelector('.ie-pv__dot').style.cssText = `left:${focus.x}%;top:${focus.y}%`;
          m = await call(cfg.api, { file: cfg.file, action: 'adjust', fx: m.fx || '', focus: JSON.stringify(focus), fit: m.fit || 'cover' });
          render();
          return;
        }
        if (!b) return;
        const item = b.dataset.ieAdjust
          ? await adjust({ file: cfg.file, api: cfg.api, tab: b.dataset.ieAdjust })
          : b.dataset.ieRatio ? await crop({ file: cfg.file, api: cfg.api, ratio: b.dataset.ieRatio })
          : await open({ file: cfg.file, api: cfg.api, tool: b.dataset.ieTool });
        if (!item) return;
        m = item.ratios ? item : await call(cfg.api, { file: cfg.file, action: 'info' });
        render();
      } catch (err) {
        fail(err);
      }
    });
  });
}

window.ImageEditor = { open, adjust, crop, init, setLayer };
if (document.readyState !== 'loading') init(document);
else document.addEventListener('DOMContentLoaded', () => init(document));
if (window.jQuery?.fn) window.jQuery(document).on('rex:ready', (e, c) => init(c?.[0] || document));
