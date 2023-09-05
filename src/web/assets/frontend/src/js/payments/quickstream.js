import { t, eventKey, ensureVariable } from '../utils/utils';
import { FormiePaymentProvider } from './payment-provider';

export class FormieQuickStream extends FormiePaymentProvider {
    constructor(settings = {}) {
        super(settings);

        this.$form = settings.$form;
        this.form = this.$form.form;
        this.$field = settings.$field;
        this.$input = this.$field.querySelector('[data-fui-quickstream-button]');

        if (!this.$input) {
            console.error('Unable to find QuickStream placeholder for [data-fui-quickstream-button]');

            return;
        }

        this.publishableKey = settings.publishableKey;
        this.supplierCode = settings.supplierCode;
        this.currency = settings.currency;
        this.amountType = settings.amountType;
        this.amountFixed = settings.amountFixed;
        this.amountVariable = settings.amountVariable;
        this.quickstreamScriptId = 'FORMIE_QUICKSTREAM_SCRIPT';

        if (!this.publishableKey) {
            console.error('Missing publishableKey for QuickStream.');

            return;
        }

        if (!this.supplierCode) {
            console.error('Missing supplierCode for QuickStream.');

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
        this.form.removeEventListener(eventKey('onFormiePaymentValidate', 'quickstream'));
        this.form.removeEventListener(eventKey('onAfterFormieSubmit', 'quickstream'));
    }

    initField() {
        // Fetch and attach the script only once - this is in case there are multiple forms on the page.
        // They all go to a single callback which resolves its loaded state
        if (!document.getElementById(this.quickstreamScriptId)) {
            const $script = document.createElement('script');
            $script.id = this.quickstreamScriptId;
            $script.src = 'https://api.quickstream.support.qvalent.com/rest/v1/quickstream-api-1.0.min.js';
            // $script.src = 'https://api.quickstream.westpac.com.au/rest/v1/quickstream-api-1.0.min.js'; //prod

            $script.async = true;
            $script.defer = true;

            // Wait until quickstream-api.js has loaded, then initialize
            $script.onload = () => {
                this.mountCard()
            };

            document.body.appendChild($script);
        } else {
            // Ensure that QuickStream has been loaded and ready to use
            ensureVariable('QuickstreamAPI').then(() => {
                this.mountCard()
            });
        }

        // Attach custom event listeners on the form
        this.form.addEventListener(this.$form, eventKey('onFormiePaymentValidate', 'quickstream'), this.onValidate.bind(this));
        this.form.addEventListener(this.$form, eventKey('onAfterFormieSubmit', 'quickstream'), this.onAfterSubmit.bind(this));
    }

    mountCard() {
        // payway.createCreditCardFrame({
        //     layout: 'wide',
        //     publishableApiKey: this.publishableKey,
        //     tokenMode: 'callback',
        // }, (err, frame) => {
        //     if (err) {
        //         console.error(`Error creating frame: ${err.message}`);
        //     } else {
        //         // Save the created frame for when we get the token
        //         this.creditCardFrame = frame;
        //     }
        // });
        var trustedFrame;
        var options = {
            config: {
                supplierBusinessCode: this.supplierCode // This is a required config option
            }
        };

        QuickstreamAPI.init({
            publishableApiKey: this.publishableKey
        });

        QuickstreamAPI.creditCards.createTrustedFrame( options, function( errors, data ) {
            if ( errors ) {
                // Handle errors here
            }
            else {
                trustedFrame = data.trustedFrame
            }
        } );

    }

    onValidate(e) {
        // Don't validate if we're not submitting (going back, saving)
        // Check if the form has an invalid flag set, don't bother going further
        // options = https://quickstream.westpac.com.au/docs/quickstreamapi/v1/quickstream-api-js/#trustedframeconfigobject

        if (this.form.submitAction !== 'submit' || e.detail.invalid) {
            return;
        }

        e.preventDefault();

        // Save for later to trigger real submit
        this.submitHandler = e.detail.submitHandler;

        this.removeError();

        if (this.creditCardFrame) {
            this.creditCardFrame.getToken((err, data) => {
                if (err) {
                    console.error(`Error getting token: ${err.message}`);

                    this.addError(err.message);
                } else {
                    // Append an input so it's not namespaced with Twig
                    this.updateInputs('quickstreamTokenId', data.singleUseTokenId);

                    this.submitHandler.submitForm();
                }
            });
        } else {
            console.error('Credit Card Frame is invalid.');
        }
    }

    onAfterSubmit(e) {
        // Clear the form
        if (this.creditCardFrame) {
            this.creditCardFrame.destroy();
            this.creditCardFrame = null;
        }

        // Reset all hidden inputs
        this.updateInputs('quickstreamTokenId', '');
    }
}

window.FormiePayWay = FormiePayWay;
