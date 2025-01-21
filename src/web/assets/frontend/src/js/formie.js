import './utils/polyfills';
import { Formie } from './formie-lib';

// This should only be used when initializing Formie from the browser. When initializing with JS directly
// import `formie-lib.js` directly into your JS modules.
window.Formie = new Formie();

// Whether we want to initialize the forms automatically.
let initForms = true;

if (document.currentScript && document.currentScript.hasAttribute('data-manual-init')) {
    initForms = false;
}

// Don't init forms until the document is ready, or the document already loaded
// https://developer.mozilla.org/en-US/docs/Web/API/Document/DOMContentLoaded_event#checking_whether_loading_is_already_complete
if (initForms) {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', (event) => {
            window.Formie.initForms();
        });
    } else {
        window.Formie.initForms();
    }
}
