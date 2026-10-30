/* Ersatz für Studio _shadow.js: Ebene für den Dialog – auf der Website die Shadow-DOM-Ebene des Editors (setLayer), sonst body */
let box = null;
export const setLayer = (el) => { box = el; };
export const layerBox = () => box || document.body;

/**
 * Inline-Styles aus Vorlagen setzen: `data-css="…"` statt `style="…"`. Die Content-Security-Policy des Backends blockt
 * style-Attribute im Markup; über element.style gesetzte Werte sind erlaubt.
 */
export const applyCss = (root) => {
  root.querySelectorAll('[data-css]').forEach((el) => { el.style.cssText = el.dataset.css; el.removeAttribute('data-css'); });
  return root;
};
