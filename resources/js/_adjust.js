/*
 * Bild anpassen (Effekte, Sättigung, Helligkeit, Kontrast, Schärfe) und Fokuspunkt/Einpassen – Dialog für image_editor.
 * Werte wie in KLXM Studio (Core\ImageFx, Core\ImageFit); die Vorschau nutzt CSS-Filter auf der Fassung ohne Anpassung
 * (image_editor_base), der Server rechnet die Anpassung später beim Ausliefern (Adjust::apply).
 */
import { layerBox } from './_shadow.js';
import { ask } from './_bar.js';

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const unamp = (u) => String(u || '').replace(/&amp;/g, '&');
const PRESET_CSS = {
  gray: 'grayscale(1)', sepia: 'sepia(.8)', warm: 'sepia(.3) saturate(1.25)', cool: 'hue-rotate(180deg) sepia(.35) hue-rotate(180deg) brightness(1.03)',
  muted: 'saturate(.45) brightness(1.03)', vivid: 'saturate(1.6) contrast(1.08)', contrast: 'contrast(1.35)',
};
const RANGES = { s: [0, 200, 'Sättigung'], b: [50, 150, 'Helligkeit'], c: [50, 150, 'Kontrast'] };

export function parseFx(v) {
  const out = { p: '', s: 100, b: 100, c: 100, k: 0 };
  String(v || '').trim().toLowerCase().split(/[\s,;]+/).filter(Boolean).forEach((t) => {
    let m;
    if (PRESET_CSS[t]) out.p = t;
    else if ((m = /^([sbc])(\d{1,3})$/.exec(t))) out[m[1]] = +m[2];
    else if ((m = /^sharp([+-]?\d{1,3})$/.exec(t))) out.k = +m[1];
  });
  return out;
}
export function fxString(a) {
  return [a.p, ...['s', 'b', 'c'].filter((k) => a[k] !== 100).map((k) => k + a[k]), a.k ? 'sharp' + a.k : ''].filter(Boolean).join(' ');
}
export function cssFilter(a) {
  return [PRESET_CSS[a.p] || '', a.s !== 100 ? `saturate(${a.s / 100})` : '', a.b !== 100 ? `brightness(${a.b / 100})` : '',
    a.c !== 100 ? `contrast(${a.c / 100})` : '', a.k < 0 ? `blur(${(-a.k * 0.04).toFixed(2)}px)` : ''].filter(Boolean).join(' ') || 'none';
}

/** Dialog öffnen. m = Angaben aus der API (info), save({fx, focus, fit}) → Promise<item>. Ergebnis: item oder undefined */
export function adjustImage(m, { save, tab = 'adjust' } = {}) {
  return new Promise((resolve) => {
    const box = layerBox();
    box.querySelector('#ia-dlg')?.remove();
    box.insertAdjacentHTML('beforeend', '<dialog id="ia-dlg" class="ie ia" aria-labelledby="ia-title"></dialog>');
    const dlg = box.querySelector('#ia-dlg');
    let st = { fx: parseFx(m.fx), focus: { ...(m.focus || { x: 50, y: 50 }) }, fit: m.fit || 'cover' };
    const start = JSON.stringify([fxString(st.fx), st.focus, st.fit]);
    let result;
    let busy = false;
    const presets = m.presets || {};
    dlg.innerHTML = `
      <div class="ie-head">
        <h2 id="ia-title">Bild anpassen <span>${esc(m.display || '')}</span></h2>
        <div class="ie-tabs" role="tablist" aria-label="Werkzeuge">
          <button type="button" role="tab" data-tab="adjust">Anpassen</button>
          <button type="button" role="tab" data-tab="focus">Fokus &amp; Einpassen</button>
        </div>
      </div>
      <div class="ie-main">
        <div class="ie-stage ia-stage" data-stage>
          <div class="ia-pic" data-pic><img data-img src="${esc(unamp(m.base || m.url))}" alt="" draggable="false"><span class="ia-dot" data-dot hidden></span></div>
        </div>
        <div class="ie-panel" data-panel></div>
      </div>
      <p class="ie-err f-error" role="alert" hidden></p>
      <div class="ie-foot">
        <button type="button" class="adm-btn adm-btn--small adm-btn--ghost" data-before aria-pressed="false">Vorher/Nachher</button>
        <button type="button" class="adm-btn adm-btn--small adm-btn--ghost" data-resetall>Alles zurücksetzen</button>
        <span class="ie-info" aria-live="polite"></span>
        <span class="ie-spacer"></span>
        <button type="button" class="adm-btn adm-btn--ghost" data-cancel>Abbrechen</button>
        <button type="button" class="adm-btn adm-btn--primary" data-save>Speichern</button>
      </div>`;
    const $ = (s) => dlg.querySelector(s);
    const img = $('[data-img]');
    const dot = $('[data-dot]');
    const panel = $('[data-panel]');
    let tabNow = tab;
    let before = false;

    const preview = () => {
      img.style.filter = before ? 'none' : cssFilter(st.fx);
      dot.hidden = tabNow !== 'focus';
      dot.style.left = st.focus.x + '%';
      dot.style.top = st.focus.y + '%';
      dlg.querySelectorAll('[data-frame] img').forEach((i) => {
        i.style.filter = cssFilter(st.fx);
        i.style.objectFit = st.fit === 'contain' ? 'contain' : 'cover';
        i.style.objectPosition = `${st.focus.x}% ${st.focus.y}%`;
      });
      $('.ie-info').textContent = tabNow === 'adjust' && st.fx.k > 0 ? 'Schärfen zeigt sich erst im gespeicherten Bild.' : '';
    };

    const drawAdjust = () => {
      panel.innerHTML = `<h3 class="ia-h">Effekt</h3><div class="ia-presets">`
        + [['', 'Original'], ...Object.entries(presets)].map(([k, l]) => `<button type="button" class="ia-preset${st.fx.p === k ? ' is-on' : ''}" data-preset="${esc(k)}">
            <span class="ia-preset__pic"><img src="${esc(unamp(m.thumb || m.url))}" alt="" style="filter:${cssFilter({ p: k, s: 100, b: 100, c: 100, k: 0 })}"></span><span>${esc(l)}</span></button>`).join('')
        + `</div>` + Object.entries(RANGES).map(([k, [min, max, label]]) => `<label class="ia-range"><span>${label}</span>
            <input type="range" min="${min}" max="${max}" step="10" value="${st.fx[k]}" data-range="${k}"><output>${st.fx[k]} %</output></label>`).join('')
        + `<label class="ia-range"><span>Schärfe</span><input type="range" min="-100" max="100" step="10" value="${st.fx.k}" data-range="k"><output>${st.fx.k > 0 ? '+' : ''}${st.fx.k}</output></label>
           <p class="f-help">Links weichzeichnen, rechts schärfen.</p>
           <button type="button" class="adm-btn adm-btn--small adm-btn--ghost" data-reset="adjust">Anpassung zurücksetzen</button>`;
    };
    const drawFocus = () => {
      const fits = [['cover', 'Füllen', 'Rahmen ganz füllen, Ränder werden abgeschnitten – der Fokuspunkt bleibt sichtbar.'],
        ['contain', 'Einpassen', 'Ganzes Bild im Rahmen zeigen (z. B. für Logos, Grafiken).'], ['original', 'Originalformat', 'Rahmen übernimmt das Seitenverhältnis des Bildes, wo das Kit es zulässt.']];
      panel.innerHTML = `<h3 class="ia-h">Fokuspunkt</h3><p class="f-help">Ins Bild klicken: Dieser Punkt bleibt beim Zuschneiden in Rahmen sichtbar.</p>
        <h3 class="ia-h">Im Rahmen</h3>` + fits.map(([v, l, h]) => `<label class="ia-fit"><input type="radio" name="ia-fit" value="${v}"${st.fit === v ? ' checked' : ''}> <span><strong>${l}</strong><small>${h}</small></span></label>`).join('')
        + `<h3 class="ia-h">Vorschau in Rahmen</h3><div class="ia-frames">${['16-9', '1-1', '4-5'].map((r) => `<span class="ia-frame ia-frame--${r}" data-frame><img src="${esc(unamp(m.url))}" alt=""><small>${r.replace('-', ':')}</small></span>`).join('')}</div>
        <button type="button" class="adm-btn adm-btn--small adm-btn--ghost" data-reset="focus">Fokus zurücksetzen</button>`;
    };
    const show = (t) => {
      tabNow = t;
      dlg.querySelectorAll('[data-tab]').forEach((b) => b.setAttribute('aria-selected', String(b.dataset.tab === t)));
      t === 'adjust' ? drawAdjust() : drawFocus();
      preview();
    };

    dlg.addEventListener('click', async (e) => {
      const b = e.target.closest('button');
      if (e.target.closest('[data-pic]') && tabNow === 'focus') {
        const r = img.getBoundingClientRect();
        st.focus = { x: Math.round(Math.max(0, Math.min(100, ((e.clientX - r.left) / r.width) * 100)) * 10) / 10, y: Math.round(Math.max(0, Math.min(100, ((e.clientY - r.top) / r.height) * 100)) * 10) / 10 };
        preview();
        return;
      }
      if (!b) return;
      if (b.dataset.tab) return show(b.dataset.tab);
      if (b.dataset.preset !== undefined) { st.fx.p = b.dataset.preset; drawAdjust(); preview(); return; }
      if (b.dataset.reset === 'adjust') { st.fx = parseFx(''); drawAdjust(); preview(); return; }
      if (b.dataset.reset === 'focus') { st.focus = { x: 50, y: 50 }; st.fit = 'cover'; drawFocus(); preview(); return; }
      if (b.hasAttribute('data-resetall')) { st = { fx: parseFx(''), focus: { x: 50, y: 50 }, fit: 'cover' }; show(tabNow); return; }
      if (b.hasAttribute('data-before')) { before = !before; b.setAttribute('aria-pressed', String(before)); preview(); return; }
      if (b.hasAttribute('data-cancel')) return close();
      if (b.hasAttribute('data-save')) return doSave(b);
    });
    panel.addEventListener('input', (e) => {
      const r = e.target.closest('[data-range]');
      if (!r) return;
      st.fx[r.dataset.range] = +r.value;
      r.nextElementSibling.textContent = r.dataset.range === 'k' ? (r.value > 0 ? '+' : '') + r.value : r.value + ' %';
      preview();
    });
    panel.addEventListener('change', (e) => { if (e.target.name === 'ia-fit') { st.fit = e.target.value; preview(); } });

    async function doSave(btn) {
      if (busy) return;
      busy = true; btn.disabled = true;
      const err = $('.ie-err');
      err.hidden = true;
      try {
        result = await save({ fx: fxString(st.fx), focus: st.focus, fit: st.fit });
        dlg.close();
      } catch (ex) {
        err.textContent = ex.message; err.hidden = false;
      } finally { busy = false; btn.disabled = false; }
    }
    async function close() {
      if (busy) return;
      if (JSON.stringify([fxString(st.fx), st.focus, st.fit]) !== start && !(await ask({ title: 'Ungespeicherte Änderungen verwerfen?', ok: 'Verwerfen' }))) return;
      dlg.close();
    }
    dlg.oncancel = (e) => { e.preventDefault(); close(); };
    dlg.onclose = () => { dlg.remove(); resolve(result); };
    dlg.showModal();
    show(tab);
  });
}
