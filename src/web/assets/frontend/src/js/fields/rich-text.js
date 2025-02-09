import { exec, init } from 'pell';

export class FormieRichText {
    constructor(settings = {}) {
        this.$form = settings.$form;
        this.form = this.$form.form;
        this.$field = settings.$field.querySelector('textarea');
        this.$container = settings.$field.querySelector('[data-rich-text]');
        this.scriptId = 'FORMIE_FONT_AWESOME_SCRIPT';
        this.defaultParagraphSeparator = 'p';

        this.buttons = settings.buttons;

        if (this.$field && this.$container) {
            this.initEditor();
        } else {
            console.error('Unable to find rich text field “[data-rich-text]”');
        }
    }

    getButtons() {
        const buttonDefinitions = [
            {
                name: 'bold',
                icon: '<i class="far fa-bold"></i>',
            },
            {
                name: 'italic',
                icon: '<i class="far fa-italic"></i>',
            },
            {
                name: 'underline',
                icon: '<i class="far fa-underline"></i>',
            },
            {
                name: 'strikethrough',
                icon: '<i class="far fa-strikethrough"></i>',
            },
            {
                name: 'heading1',
                icon: '<i class="far fa-h1"></i>',
            },
            {
                name: 'heading2',
                icon: '<i class="far fa-h2"></i>',
            },
            {
                name: 'paragraph',
                icon: '<i class="far fa-paragraph"></i>',
            },
            {
                name: 'quote',
                icon: '<i class="far fa-quote-right"></i>',
            },
            {
                name: 'olist',
                icon: '<i class="far fa-list-ol"></i>',
            },
            {
                name: 'ulist',
                icon: '<i class="far fa-list-ul"></i>',
            },
            {
                name: 'code',
                icon: '<i class="far fa-code"></i>',
            },
            {
                name: 'line',
                icon: '<i class="far fa-horizontal-rule"></i>',
            },
            {
                name: 'link',
                icon: '<i class="far fa-link"></i>',
            },
            {
                name: 'image',
                icon: '<i class="far fa-image"></i>',
            },
            {
                name: 'alignleft',
                icon: '<i class="far fa-align-left"></i>',
                title: 'Align Left',
                result: () => { return exec('justifyLeft', ''); },
            },
            {
                name: 'aligncenter',
                icon: '<i class="far fa-align-center"></i>',
                title: 'Align Center',
                result: () => { return exec('justifyCenter', ''); },
            },
            {
                name: 'alignright',
                icon: '<i class="far fa-align-right"></i>',
                title: 'Align Right',
                result: () => { return exec('justifyRight', ''); },
            },
            {
                name: 'clear',
                icon: '<i class="far fa-eraser"></i>',
                title: 'Clear',
                result: () => {
                    if (window.getSelection().toString()) {
                        const linesToDelete = window.getSelection().toString().split('\n').join('<br>');
                        exec('formatBlock', '<p>');
                        document.execCommand('insertHTML', false, linesToDelete);
                    } else {
                        exec('formatBlock', '<p>');
                    }
                },
            },
        ];

        if (!this.buttons) {
            this.buttons = ['bold', 'italic'];
        }

        const buttons = [];

        this.buttons.forEach((button) => {
            const found = buttonDefinitions.find((element) => { return element.name === button; });

            if (found) {
                buttons.push(found);
            }
        });

        return buttons;
    }

    initEditor() {
        // Assign this instance to the field's DOM, so it can be accessed by third parties
        this.$field.richText = this;

        // Load in FontAwesome, for better icons. Only load once though
        if (!document.getElementById(this.scriptId)) {
            const $script = document.createElement('script');
            $script.src = 'https://kit.fontawesome.com/bfee7f35b7.js';
            $script.id = this.scriptId;
            $script.defer = true;
            $script.async = true;
            $script.setAttribute('crossorigin', 'anonymous');
            document.body.appendChild($script);
        }

        const options = {
            element: this.$container,
            defaultParagraphSeparator: this.defaultParagraphSeparator,
            styleWithCSS: true,
            actions: this.getButtons(),
            onChange: (html) => {
                // catch "empty" HTML if we're using a placeholder
                if (this.$field.placeholder && html === `<${this.defaultParagraphSeparator}><br></${this.defaultParagraphSeparator}>`) {
                    this.$field.textContent = '';
                    this.editor.content.innerHTML = '';
                } else {
                    this.$field.textContent = html;
                }

                // Fire a custom event on the input
                this.$field.dispatchEvent(new CustomEvent('populate', { bubbles: true }));
            },
            classes: {
                actionbar: 'fui-rich-text-toolbar',
                button: 'fui-rich-text-button',
                content: 'fui-input fui-rich-text-content',
                selected: 'fui-rich-text-selected',
            },
        };

        // Emit an "beforeInit" event. This can directly modify the `options` param
        const beforeInitEvent = new CustomEvent('beforeInit', {
            bubbles: true,
            detail: {
                richText: this,
                options,
            },
        });

        this.$field.dispatchEvent(beforeInitEvent);

        // save the defaultParagraphSeparator again, if it changed
        this.defaultParagraphSeparator = options.defaultParagraphSeparator || this.defaultParagraphSeparator;

        this.editor = init(options);

        // Populate any values initially set
        this.editor.content.innerHTML = this.$field.textContent;

        // Populate placeholder if set
        if (this.$field.placeholder) {
            this.editor.content.setAttribute('data-placeholder', this.$field.placeholder);
        }

        // Emit an "afterInit" event
        this.$field.dispatchEvent(new CustomEvent('afterInit', {
            bubbles: true,
            detail: {
                richText: this,
            },
        }));
    }
}

window.FormieRichText = FormieRichText;
