/**
 * Carries the original photo File objects from the OCR import view
 * (recipe-import-photo.js) across a Router.navigate() into the create form
 * (recipe-form.js), so they can be attached as the new recipe's images
 * after it's saved. Kept as a plain in-memory variable rather than
 * sessionStorage - the bytes of a phone photo (easily several MB each,
 * possibly several pages) would risk sessionStorage's ~5-10MB per-origin
 * quota and there's no need to serialize them at all: hash-navigation in
 * this SPA never reloads the document, so an in-memory value survives the
 * navigation just fine. Only the small parsed-draft JSON goes into
 * sessionStorage (see recipe-import-photo.js/recipe-form.js).
 */
const OcrDraftStore = (() => {
    let files = [];

    return {
        setFiles: (fs) => { files = fs; },
        takeFiles: () => { const taken = files; files = []; return taken; },
    };
})();
