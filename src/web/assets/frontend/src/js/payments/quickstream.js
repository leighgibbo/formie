import { t, eventKey, ensureVariable } from '../utils/utils';
import { FormiePaymentProvider } from './payment-provider';

export class FormieQuickStream extends FormiePaymentProvider {
    constructor(settings = {}) {
        super(settings);

        this.trustedFrame = false;
        this.$form = settings.$form;
        this.form = this.$form.form;
        this.$field = settings.$field;
        this.$input = this.$field.querySelector('[data-fui-quickstream-frame]');

        if (!this.$input) {
            console.error('Unable to find QuickStream form placeholder for [data-fui-quickstream-form]');

            return;
        }

        this.publishableKey = settings.publishableKey;
        this.supplierBusinessCode = settings.supplierBusinessCode;
        this.currency = settings.currency;
        this.amountType = settings.amountType;
        this.amountFixed = settings.amountFixed;
        this.amountVariable = settings.amountVariable;
        this.quickstreamScriptId = 'FORMIE_QUICKSTREAM_SCRIPT';

        if (!this.publishableKey) {
            console.error('Missing publishableKey for QuickStream.');

            return;
        }

        if (!this.supplierBusinessCode) {
            console.error('Missing supplierBusinessCode for QuickStream.');

            return;
        }

        // We can start listening for the field to become visible to initialize it
        this.initialized = true;
    }

    onShow() {
        // Initialize the field only when it's visible
        this.initField();
    }

    onHide() {
        // Field is hidden, so reset everything
        this.onAfterSubmit();

        // Remove unique event listeners
        // TODO: review this works
        this.form.removeEventListener(eventKey('onFormiePaymentValidate', 'quickstream'));
        this.form.removeEventListener(eventKey('onAfterFormieSubmit', 'quickstream'));
    }

    initField() {
        console.log('initField fired');
        // Fetch and attach the script only once - this is in case there are multiple forms on the page.
        // They all go to a single callback which resolves its loaded state
        if (!document.getElementById(this.quickstreamScriptId)) {
            const $script = document.createElement('script');
            $script.id = this.quickstreamScriptId;
            $script.src = 'https://api.quickstream.support.qvalent.com/rest/v1/quickstream-api-1.0.min.js'; // staging
            // $script.src = 'https://api.quickstream.westpac.com.au/rest/v1/quickstream-api-1.0.min.js'; //prod

            $script.async = true;
            $script.defer = true;

            // Wait until quickstream-api.js has loaded, then initialize
            $script.onload = () => {
                this.mountTrustedFrame();
            };

            document.body.appendChild($script);
        } else {
            // Ensure that QuickStream has been loaded and ready to use
            ensureVariable('QuickstreamAPI').then(() => {
                this.mountTrustedFrame();
            });
        }

        // Attach custom event listeners on the form
        // TODO: Review this works
        this.form.addEventListener(this.$form, eventKey('onFormiePaymentValidate', 'quickstream'), this.onValidate.bind(this));
        this.form.addEventListener(this.$form, eventKey('onAfterFormieSubmit', 'quickstream'), this.onAfterSubmit.bind(this));
    }

    mountTrustedFrame() {
        console.log('mountTrustedFrame fired');
        const options = {
            config: {
                supplierBusinessCode: this.supplierBusinessCode, // This is a required config option
            },
        };

        console.log('firing QuickstreamAPI.init with key', this.publishableKey);

        QuickstreamAPI.init({
            publishableApiKey: this.publishableKey,
        });

        QuickstreamAPI.creditCards.createTrustedFrame(options, (errors, data) => {
            if (errors) {
                // Handle errors here
                console.error(`Error creating trusted frame: ${errors}`);
            } else {
                this.trustedFrame = data.trustedFrame;
                console.log('trustedFrame created');
            }
        });

        // Quickstream's example method of handling (by controlling the parent form iteself): TODO: delete me
        // this.$input.addEventListener('submit', (event) => {
        //     event.preventDefault();
        //     trustedFrame.submitForm((errors, data) => {
        //         if (!errors) {
        //             QuickstreamAPI.creditCards.appendTokenToForm(form, data.singleUseToken.singleUseTokenId);
        //             form.submit();
        //         }
        //     });
        // });

    }

    onValidate(e) {
        // Don't validate if we're not submitting (going back, saving)
        // Check if the form has an invalid flag set, don't bother going further
        // options = https://quickstream.westpac.com.au/docs/quickstreamapi/v1/quickstream-api-js/#trustedframeconfigobject

        if (this.form.submitAction !== 'submit' || e.detail.invalid) {
            return;
        }

        e.preventDefault();

        console.log('onValidate fired');

        // Save for later to trigger real submit
        this.submitHandler = e.detail.submitHandler;

        this.removeError();

        if (this.trustedFrame) {
            console.log('trustedFrame exists, submitting frame to get token');
            this.trustedFrame.submitForm((errors, data) => {
                if (errors) {
                    console.error(`Error getting token: ${errors}`);
                    this.addError('An error occured when processing your payment. Please try again.');
                } else {
                    console.log('token received', data);

                    // all good, append the single use token to the Formie form, and submit
                    // QuickstreamAPI.creditCards.appendTokenToForm(this.form, data.singleUseToken.singleUseTokenId);
                    this.updateInputs('quickstreamTokenId', data.singleUseToken.singleUseTokenId);
                    this.submitHandler.submitForm();
                }
            });

            // The method used in the PayWay gateway (for reference) - TODO: delete me
            // this.trustedFrame.getToken((err, data) => {
            //     if (err) {
            //         console.error(`Error getting token: ${err.message}`);
            //         this.addError(err.message);
            //     } else {
            //         // Append an input so it's not namespaced with Twig
            //         this.updateInputs('quickstreamTokenId', data.singleUseTokenId);

            //         this.submitHandler.submitForm();
            //     }
            // });
        } else {
            console.error('Credit Card Frame is invalid.');
        }
    }

    onAfterSubmit(e) {
        console.log('onAfterSubmit fired');
        // Clear the form
        if (this.trustedFrame) {
            this.trustedFrame.destroy();
            this.trustedFrame = null;
        }

        // Reset all hidden inputs
        this.updateInputs('quickstreamTokenId', '');
    }
}

window.FormieQuickStream = FormieQuickStream;
