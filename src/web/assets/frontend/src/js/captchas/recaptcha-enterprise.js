import { FormieCaptchaProvider } from './captcha-provider';
import { recaptchaEnterprise as recaptcha } from './inc/recaptcha';
import { t, eventKey } from '../utils/utils';

export class FormieRecaptchaEnterprise extends FormieCaptchaProvider {
    constructor(settings = {}) {
        super(settings);

        this.$form = settings.$form;
        this.form = this.$form.form;
        this.siteKey = settings.siteKey;
        this.badge = settings.badge;
        this.language = settings.language;
        this.loadingMethod = settings.loadingMethod;
        this.type = settings.enterpriseType;
        this.recaptchaScriptId = 'FORMIE_RECAPTCHA_SCRIPT';

        // Fetch and attach the script only once - this is in case there are multiple forms on the page.
        // They all go to a single callback which resolves its loaded state
        if (!document.getElementById(this.recaptchaScriptId)) {
            const $script = document.createElement('script');
            $script.id = this.recaptchaScriptId;
            $script.src = `https://www.google.com/recaptcha/enterprise.js?onload=formieRecaptchaOnLoadCallback&render=explicit&hl=${this.language}`;

            if (this.loadingMethod === 'async' || this.loadingMethod === 'asyncDefer') {
                $script.async = true;
            }

            if (this.loadingMethod === 'defer' || this.loadingMethod === 'asyncDefer') {
                $script.defer = true;
            }

            document.body.appendChild($script);
        }

        // Wait for/ensure recaptcha script has been loaded
        recaptcha.checkRecaptchaLoad();

        // We can have multiple captchas per form, so store them and render only when we need
        this.$placeholders = this.$form.querySelectorAll('[data-recaptcha-placeholder]');

        if (!this.$placeholders) {
            console.error('Unable to find any ReCAPTCHA placeholders for [data-recaptcha-placeholder]');

            return;
        }

        // Render the captcha for just this page
        this.renderCaptcha();

        // Attach a custom event listener on the form
        this.$form.addEventListener('onBeforeFormieSubmit', this.onBeforeSubmit.bind(this));
        this.form.addEventListener(this.$form, eventKey('onFormieCaptchaValidate', 'RecaptchaEnterprise'), this.onValidate.bind(this));
        this.form.addEventListener(this.$form, eventKey('onAfterFormieSubmit', 'RecaptchaEnterprise'), this.onAfterSubmit.bind(this));
    }

    renderCaptcha() {
        // Default to the first placeholder available.
        if (this.type === 'checkbox') {
            this.$placeholder = null;
        } else {
            // eslint-disable-next-line
            this.$placeholder = this.$placeholders[0];
        }

        // Get the active page
        let $currentPage = null;

        if (this.$form.form.formTheme) {
            // eslint-disable-next-line
            $currentPage = this.$form.form.formTheme.$currentPage;
        }

        const { hasMultiplePages } = this.$form.form.settings;

        // Get the current page's captcha - find the first placeholder that's non-invisible
        this.$placeholders.forEach(($placeholder) => {
            if ($currentPage && $currentPage.contains($placeholder)) {
                this.$placeholder = $placeholder;
            }
        });

        // If a single-page form, get the first placeholder
        if (!hasMultiplePages && this.$placeholder === null) {
            // eslint-disable-next-line
            this.$placeholder = this.$placeholders[0];
        }

        if (this.$placeholder === null) {
            // This is okay in some instances - notably for multi-page forms where the captcha
            // should only be shown on the last step. But its nice to log this anyway
            if ($currentPage === null) {
                console.log('Unable to find ReCAPTCHA placeholder for [data-recaptcha-placeholder]');
            }

            return;
        }

        // Remove any existing token input
        const $token = this.$form.querySelector('[name="g-recaptcha-response"]');

        if ($token) {
            $token.remove();
        }

        // Check if we actually need to re-render this, or just refresh it...
        const currentRecaptchaId = this.$placeholder.getAttribute('data-recaptcha-id');

        if (currentRecaptchaId !== null) {
            this.recaptchaId = currentRecaptchaId;

            recaptcha.reset(this.recaptchaId);

            return;
        }

        // Use "invisible" for score and invisible, but not checkbox
        const size = this.type === 'checkbox' ? '' : 'invisible';

        // Render the recaptcha
        recaptcha.render(this.createInput(), {
            sitekey: this.siteKey,
            badge: this.badge,
            size,
            callback: this.onVerify.bind(this),
            'expired-callback': this.onExpired.bind(this),
            'error-callback': this.onError.bind(this),
        }, (id) => {
            this.recaptchaId = id;
        });
    }

    onBeforeSubmit(e) {
        // Save for later to trigger real submit
        this.submitHandler = e.detail.submitHandler;

        this.removeError();
    }

    onValidate(e) {
        // When not using Formie's theme JS, there's nothing preventing the form from submitting (the theme does).
        // And when the form is submitting, we can't query DOM elements, so stop early so the normal checks work.
        if (!this.$form.form.formTheme) {
            e.preventDefault();

            // Get the submit action from the form hidden input. This is normally taken care of by the theme
            this.form.submitAction = this.$form.querySelector('[name="submitAction"]').value || 'submit';
        }

        // Don't validate if we're not submitting (going back, saving)
        if (this.form.submitAction !== 'submit' || this.$placeholder === null) {
            return;
        }

        // Check if the form has an invalid flag set, don't bother going further
        if (e.detail.invalid) {
            return;
        }

        if (this.type === 'checkbox') {
            const $token = this.$form.querySelector('[name="g-recaptcha-response"]');

            // Check to see if there's a valid token, otherwise, keep preventing the form.
            if (!$token || !$token.value.length) {
                this.addError();

                e.preventDefault();
            }

            return;
        }

        e.preventDefault();

        // Save for later to trigger real submit
        this.submitHandler = e.detail.submitHandler;

        // Trigger recaptcha
        recaptcha.execute(this.recaptchaId);
    }

    onVerify(token) {
        if (this.type === 'checkbox') {
            return;
        }

        // Submit the form - we've hijacked it up until now
        if (this.submitHandler) {
            // Run the next submit action for the form. TODO: make this better!
            if (this.submitHandler.validatePayment()) {
                this.submitHandler.submitForm();
            }
        }
    }

    onAfterSubmit(e) {
        // For a multi-page form, we need to remove the current captcha, then render the next pages.
        // For a single-page form, reset the recaptcha, in case we want to fill out the form again
        // `renderCaptcha` will deal with both cases
        setTimeout(() => {
            this.renderCaptcha();
        }, 300);
    }

    addError() {
        // Is there even a captcha field on this page?
        if (this.$placeholder === null) {
            return;
        }

        if (this.submitHandler) {
            this.submitHandler.formSubmitError();
        }

        const $error = document.createElement('div');
        $error.className = 'fui-error-message';
        $error.textContent = t('This field is required.');

        this.$placeholder.appendChild($error);
    }

    removeError() {
        // Is there even a captcha field on this page?
        if (this.$placeholder === null) {
            return;
        }

        const $error = this.$placeholder.querySelector('.fui-error-message');

        if ($error) {
            $error.remove();
        }
    }

    onExpired() {
        console.log('ReCAPTCHA has expired - reloading.');

        recaptcha.reset(this.recaptchaId);
    }

    onError(error) {
        console.error('ReCAPTCHA was unable to load');
        console.error(error);
    }
}

window.FormieRecaptchaEnterprise = FormieRecaptchaEnterprise;
