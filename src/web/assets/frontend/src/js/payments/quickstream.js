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
        this.isTestMode = (typeof settings.isTestMode == 'undefined' || settings.isTestMode == false) ? false : true;
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
        // Fetch and attach the script only once - this is in case there are multiple forms on the page.
        // They all go to a single callback which resolves its loaded state'
        if (!document.getElementById(this.quickstreamScriptId)) {
            const $script = document.createElement('script');
            $script.id = this.quickstreamScriptId;
            $script.src = (this.isTestMode == false) ? 'https://api.quickstream.westpac.com.au/rest/v1/quickstream-api-1.0.min.js' : 'https://api.quickstream.support.qvalent.com/rest/v1/quickstream-api-1.0.min.js';

            if (this.isTestMode == true) { console.info(`Quickstream Trusted Frame was loaded in Dev/test mode via ${$script.src}`); }

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
        // See more at: https://quickstream.westpac.com.au/docs/quickstreamapi/v1/quickstream-api-js/

        const inputStyle = {
            height: '34px',
            padding: '0px 12px',
            'font-size': '14px',
            border: '1px solid #ccc',
            'border-radius': '2px',
        };

        const options = {
            config: {
                supplierBusinessCode: this.supplierBusinessCode, // This is a required config option
            },
            iframe: {
                width: '100%',
                height: '100%',
                style: {
                    'font-size': '14px',
                    'line-height': '24px',
                    border: '1px solid #dedede',
                    'border-radius': '2px',
                    'margin-bottom': '0.75rem',
                    'min-height': '400px',
                    padding: '1.5rem',
                    width: '100%',
                    'background-color': 'white',
                },
            },
            showAcceptedCards: true,
            cardholderName: {
                style: inputStyle,
                label: 'Name on card',
            },
            cardNumber: {
                style: inputStyle,
                label: 'Card number',
            },
            expiryDateMonth: {
                style: inputStyle,
            },
            expiryDateYear: {
                style: inputStyle,
            },
            cvn: {
                hidden: false,
                label: 'Security code',
            },
            body: {
                style: {},
            },
        };

        QuickstreamAPI.init({
            publishableApiKey: this.publishableKey,
        });

        QuickstreamAPI.creditCards.createTrustedFrame(options, (errors, data) => {
            if (errors) {
                // Handle errors here
                console.error(`Error creating trusted frame: ${errors}`);
            } else {
                this.trustedFrame = data.trustedFrame;
            }
        });

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

        if (this.trustedFrame) {
            this.trustedFrame.submitForm((errors, data) => {
                if (errors) {
                    console.warn(`Error validating Trusted Frame: ${errors}`);
                    // this.addError('An error occured when processing your payment. Please try again.');
                } else {
                    // all good, append the single use token to the Formie form, and submit
                    // QuickstreamAPI.creditCards.appendTokenToForm(this.form, data.singleUseToken.singleUseTokenId);
                    this.updateInputs('quickstreamTokenId', data.singleUseToken.singleUseTokenId);
                    this.submitHandler.submitForm();
                }
            });

        } else {
            console.error('Credit Card Frame is invalid.');
        }
    }

    onAfterSubmit(e) {
        // Clear the form
        if (this.trustedFrame) {
            this.trustedFrame.teardown((errors, data) => {
                if (errors) {
                    // Handle errors here
                    console.error('Errors when destroying Trusted Frame:', errors);
                } else {
                    // console.log("Trusted Frame has been destroyed.");
                }
            });
            this.trustedFrame = null;
        }

        // Reset all hidden inputs
        this.updateInputs('quickstreamTokenId', '');
    }
}

window.FormieQuickStream = FormieQuickStream;
