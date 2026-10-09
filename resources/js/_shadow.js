/* Ersatz für Studio _shadow.js: Ebene für den Dialog – auf der Website die Shadow-DOM-Ebene des Editors (setLayer), sonst body */
let box = null;
export const setLayer = (el) => { box = el; };
export const layerBox = () => box || document.body;
