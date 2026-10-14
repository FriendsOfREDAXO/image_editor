/*
 * Ausschnitt je Bildformat (16:9, 4:3 …) – aus KLXM Studio (_media.js → crop(), MIT).
 *
 * Zoomen und Verschieben im festen Rahmen des Formats; je Format ein eigener Ausschnitt oder „Automatisch (Fokuspunkt)“.
 * Grundlage ist die bearbeitete Fassung (Zuschneiden, Drehen … in editImage), die Anteile beziehen sich darauf.
 *
 *   cropImage(m, { ratio, only, save })   m = info() des Servers, save(ratio, rect|null) → Promise(item)
 */
import { layerBox } from './_shadow.js';

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const $ = (sel, el) => el.querySelector(sel);
const $$ = (sel, el) => [...el.querySelectorAll(sel)];

export function cropImage(m, { ratio = '', only = '', save }) {
  return new Promise((resolve) => {
    const ratios = m.ratios || [];
    if (!ratios.includes(ratio)) ratio = ratios[0];
    const box = layerBox();
    box.querySelector('#ie-crop')?.remove();
    box.insertAdjacentHTML('beforeend', '<dialog id="ie-crop" class="cr" aria-labelledby="cr-title"></dialog>');
    const dlg = box.querySelector('#ie-crop');
    const pending = {};                          // Format → Ausschnitt|null (noch nicht gespeichert)
    const current = (r) => (r in pending ? pending[r] : m.crops?.[r]) || null;
    let result;
    dlg.innerHTML = `
      <div class="cr-head">
        <h2 id="cr-title">Ausschnitt je Format <span>${esc(m.display)}</span></h2>
        <div class="cr-ratios" role="tablist" aria-label="Bildformat">${ratios.map((r) => `<button type="button" role="tab" data-ratio="${r}"><span class="cr-shape" style="aspect-ratio:${r.replace(':', '/')}"></span>${r}${only === r ? ' <em>hier</em>' : ''}</button>`).join('')}</div>
      </div>
      <div class="cr-view" tabindex="0" aria-label="Bildausschnitt: ziehen zum Verschieben, Mausrad oder Plus/Minus zum Zoomen, Pfeiltasten zum Verschieben">
        <img alt="" draggable="false" src="${esc(m.url)}">
        <div class="cr-frame"><i></i><i></i><i></i><i></i></div>
      </div>
      <div class="cr-bar">
        <button type="button" class="cr-z" data-z="-1" aria-label="Verkleinern">−</button>
        <input type="range" min="0" max="1000" value="0" aria-label="Zoom" data-zoom>
        <button type="button" class="cr-z" data-z="1" aria-label="Vergrößern">+</button>
        <span class="cr-info" aria-live="polite"></span>
        <span class="cr-spacer"></span>
        <button type="button" class="adm-btn adm-btn--small adm-btn--ghost" data-reset>Automatisch (Fokuspunkt)</button>
      </div>
      <p class="cr-err" role="alert" hidden></p>
      <div class="cr-foot">
        <p class="cr-help">Ziehen = verschieben · Mausrad/Trackpad = zoomen · Doppelklick = automatisch</p>
        <button type="button" class="adm-btn adm-btn--ghost" data-cancel>Abbrechen</button>
        <button type="button" class="adm-btn adm-btn--primary" data-save>Übernehmen</button>
      </div>`;
    const view = $('.cr-view', dlg), img = $('img', view), frame = $('.cr-frame', dlg), slider = $('[data-zoom]', dlg), info = $('.cr-info', dlg);
    const W = m.width, H = m.height;
    const focus = m.focus || { x: 50, y: 50 };
    let st = null;                                // { ar, Fw, Fh, fx, fy, k, kMin, kMax, ix, iy }

    const layout = () => {
      const [a, b] = ratio.split(':').map(Number), ar = a / b;
      const Vw = view.clientWidth, Vh = view.clientHeight, pad = 36;
      let Fw = Vw - pad * 2, Fh = Fw / ar;
      if (Fh > Vh - pad * 2) { Fh = Vh - pad * 2; Fw = Fh * ar; }
      const fx = (Vw - Fw) / 2, fy = (Vh - Fh) / 2;
      const kMin = Math.max(Fw / W, Fh / H), kMax = Math.max(kMin * 1.001, Fw / Math.min(W, 240));
      st = { ar, Fw, Fh, fx, fy, kMin, kMax, k: kMin, ix: 0, iy: 0 };
      frame.style.cssText = `left:${fx}px;top:${fy}px;width:${Fw}px;height:${Fh}px`;
      const c = current(ratio);
      if (c) { st.k = Math.min(kMax, Math.max(kMin, Fw / (c.w * W))); st.ix = fx - c.x * W * st.k; st.iy = fy - c.y * H * st.k; }
      else center();
      apply();
    };
    const center = () => {
      st.k = st.kMin;
      st.ix = st.fx + st.Fw / 2 - (focus.x / 100) * W * st.k;
      st.iy = st.fy + st.Fh / 2 - (focus.y / 100) * H * st.k;
    };
    const clamp = () => {
      st.k = Math.min(st.kMax, Math.max(st.kMin, st.k));
      st.ix = Math.min(st.fx, Math.max(st.fx + st.Fw - W * st.k, st.ix));
      st.iy = Math.min(st.fy, Math.max(st.fy + st.Fh - H * st.k, st.iy));
    };
    const rect = () => ({ x: (st.fx - st.ix) / (W * st.k), y: (st.fy - st.iy) / (H * st.k), w: st.Fw / (W * st.k), h: st.Fh / (H * st.k) });
    const apply = () => {
      clamp();
      img.style.cssText = `width:${W * st.k}px;height:${H * st.k}px;transform:translate(${st.ix}px,${st.iy}px)`;
      slider.value = Math.round(Math.log(st.k / st.kMin) / Math.log(st.kMax / st.kMin || 2) * 1000) || 0;
      const r = rect(), pw = Math.round(r.w * W), ph = Math.round(r.h * H);
      info.textContent = `${pw} × ${ph} px` + (current(ratio) ? '' : ' · automatisch') + (pw < 1200 ? ' · Auflösung knapp für große Bildschirme' : '');
      info.classList.toggle('is-warn', pw < 1200);
      $$('[data-ratio]', dlg).forEach((b) => {
        b.setAttribute('aria-selected', String(b.dataset.ratio === ratio));
        b.classList.toggle('is-set', !!current(b.dataset.ratio));
      });
    };
    const touched = () => { pending[ratio] = rect(); apply(); };
    const zoomAt = (k, px = st.fx + st.Fw / 2, py = st.fy + st.Fh / 2) => {
      k = Math.min(st.kMax, Math.max(st.kMin, k));
      st.ix = px - (px - st.ix) * k / st.k; st.iy = py - (py - st.iy) * k / st.k; st.k = k;
      clamp(); touched();
    };
    const auto = () => { pending[ratio] = null; center(); apply(); };

    // Ziehen (Maus, Stift, Touch) und Pinch-Zoom
    const pts = new Map(); let pinch = null;
    view.addEventListener('pointerdown', (e) => { view.setPointerCapture(e.pointerId); pts.set(e.pointerId, [e.clientX, e.clientY]); view.classList.add('is-drag'); });
    view.addEventListener('pointermove', (e) => {
      if (!pts.has(e.pointerId)) return;
      const [ox, oy] = pts.get(e.pointerId); pts.set(e.pointerId, [e.clientX, e.clientY]);
      if (pts.size === 2) {
        const [a, b] = [...pts.values()], dist = Math.hypot(a[0] - b[0], a[1] - b[1]);
        const r = view.getBoundingClientRect();
        if (pinch) zoomAt(st.k * dist / pinch, (a[0] + b[0]) / 2 - r.left, (a[1] + b[1]) / 2 - r.top);
        pinch = dist; return;
      }
      st.ix += e.clientX - ox; st.iy += e.clientY - oy; clamp(); touched();
    });
    const up = (e) => { pts.delete(e.pointerId); pinch = null; if (!pts.size) view.classList.remove('is-drag'); };
    view.addEventListener('pointerup', up); view.addEventListener('pointercancel', up);
    view.addEventListener('wheel', (e) => {
      e.preventDefault();
      const r = view.getBoundingClientRect();
      zoomAt(st.k * Math.exp(-e.deltaY * (e.ctrlKey ? 0.01 : 0.0025)), e.clientX - r.left, e.clientY - r.top);
    }, { passive: false });
    view.addEventListener('dblclick', auto);
    view.addEventListener('keydown', (e) => {
      const step = e.shiftKey ? 50 : 10;
      const map = { ArrowLeft: [step, 0], ArrowRight: [-step, 0], ArrowUp: [0, step], ArrowDown: [0, -step] };
      if (map[e.key]) { e.preventDefault(); st.ix += map[e.key][0]; st.iy += map[e.key][1]; clamp(); touched(); }
      else if (e.key === '+' || e.key === '=') { e.preventDefault(); zoomAt(st.k * 1.15); }
      else if (e.key === '-') { e.preventDefault(); zoomAt(st.k / 1.15); }
      else if (e.key === '0') auto();
    });
    slider.addEventListener('input', () => zoomAt(st.kMin * Math.pow(st.kMax / st.kMin, slider.value / 1000)));
    $$('[data-z]', dlg).forEach((b) => b.addEventListener('click', () => zoomAt(st.k * (b.dataset.z > 0 ? 1.25 : 0.8))));
    $('[data-reset]', dlg).addEventListener('click', auto);
    $$('[data-ratio]', dlg).forEach((b) => b.addEventListener('click', () => { ratio = b.dataset.ratio; layout(); }));
    $('[data-cancel]', dlg).addEventListener('click', () => dlg.close());
    $('[data-save]', dlg).addEventListener('click', async (e) => {
      const btn = e.currentTarget; btn.disabled = true;
      const err = $('.cr-err', dlg);
      err.hidden = true;
      try {
        for (const [r, rc] of Object.entries(pending)) {
          m = await save(r, rc);
          result = m;
        }
        dlg.close();
      } catch (ex) { err.textContent = ex.message; err.hidden = false; btn.disabled = false; }
    });
    const ro = new ResizeObserver(() => st && layout());
    dlg.addEventListener('close', () => { ro.disconnect(); dlg.remove(); resolve(result); }, { once: true });
    dlg.showModal();
    ro.observe(view);
    (img.complete ? Promise.resolve() : new Promise((r) => { img.onload = r; img.onerror = r; })).then(() => { layout(); view.focus(); });
  });
}
