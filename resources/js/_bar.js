/* Ersatz für Studio _bar.js: Rückfrage als gestalteter <dialog> in der Ebene (keine window.confirm) */
import { layerBox } from './_shadow.js';

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

export function ask({ title, body = '', ok = 'OK', cancel = 'Abbrechen', danger = false }) {
  return new Promise((resolve) => {
    const d = document.createElement('dialog');
    d.className = 'adm-dialog adm-dialog--small ie-ask';
    d.innerHTML = `<h2>${esc(title)}</h2>${body ? `<p>${esc(body)}</p>` : ''}<div class="ie-ask__actions">`
      + `<button type="button" class="adm-btn adm-btn--ghost" data-no>${esc(cancel)}</button>`
      + `<button type="button" class="adm-btn ${danger ? 'adm-btn--danger' : 'adm-btn--primary'}" data-yes>${esc(ok)}</button></div>`;
    layerBox().append(d);
    const done = (v) => { d.close(); d.remove(); resolve(v); };
    d.addEventListener('click', (e) => { if (e.target.closest('[data-yes]')) done(true); else if (e.target.closest('[data-no]')) done(false); });
    d.addEventListener('cancel', (e) => { e.preventDefault(); done(false); });
    d.showModal();
    d.querySelector('[data-no]').focus();
  });
}
