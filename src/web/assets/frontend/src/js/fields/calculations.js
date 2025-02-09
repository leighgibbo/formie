import { t, eventKey } from '../utils/utils';

import ExpressionLanguage from 'expression-language';

export class FormieCalculations {
    constructor(settings = {}) {
        this.$form = settings.$form;
        this.form = this.$form.form;
        this.$field = settings.$field;
        this.$input = this.$field.querySelector('input');
        this.formula = settings.formula.formula;
        this.variables = settings.formula.variables;
        this.formatting = settings.formatting;
        this.prefix = settings.prefix;
        this.suffix = settings.suffix;
        this.decimals = settings.decimals;

        this.fieldsStore = {};
        this.expressionLanguage = new ExpressionLanguage();

        this.initCalculations();
    }

    initCalculations() {
        // For every dynamic field defined in the formula, listen to changes and re-calculate
        Object.keys(this.variables).forEach((variableKey) => {
            const variable = this.variables[variableKey];

            let $targets = this.$form.querySelectorAll(`[name="${variable.name}"]`);

            // Special handling for Repeater/Groups that have `new1` in their name but for page reload forms
            // this will be replaced by the blockId, and will fail to match the conditions settings.
            if ((!$targets || !$targets.length) && variable.name.includes('[new1]')) {
                // Get tricky with Regex. Find the element that matches everything except `[new1]` for `[1234]`.
                // Escape special characters `[]` in the string, and swap `[new1]` with `[\d+]`.
                const regexString = variable.name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/new1/g, '\\d+');

                // Find all targets via Regex.
                $targets = this.querySelectorAllRegex(new RegExp(regexString), 'name');
            }

            if (!$targets || !$targets.length) {
                return;
            }

            // Save the resolved target for later
            this.fieldsStore[variableKey] = {
                $targets,
                ...variable,
            };

            $targets.forEach(($target) => {
                // Get the right event for the field
                const eventType = this.getEventType($target);

                // Watch for changes on the target field. When one occurs, fire off a custom event on the source field
                this.form.addEventListener($target, eventKey(eventType), () => {
                    return this.$field.dispatchEvent(new CustomEvent('onFormieEvaluateCalculations', { bubbles: true, detail: { calculations: this } }));
                });
            });
        });

        // Add a custom event listener to fire when the field event listener fires
        this.form.addEventListener(this.$field, eventKey('onFormieEvaluateCalculations'), this.evaluateCalculations.bind(this));

        // Also - trigger the event right now to evaluate immediately. Namely if we need to hide
        // field that are set to show if conditions are met.
        this.$field.dispatchEvent(new CustomEvent('onFormieEvaluateCalculations', { bubbles: true, detail: { calculations: this, init: true } }));

        // Update the form hash, so we don't get change warnings
        if (this.form.formTheme) {
            this.form.formTheme.updateFormHash();
        }
    }

    evaluateCalculations(e) {
        const $field = e.target;
        const isInit = e.detail ? e.detail.init : false;
        let { formula } = this;
        let variables = {};

        // For each variable, grab the value
        Object.keys(this.fieldsStore).forEach((variableKey) => {
            const { $targets, type } = this.fieldsStore[variableKey];

            // Set a sane default
            variables[variableKey] = '';

            // We pass target DOM elements as a NodeList, but in almost all cases,
            // they're a list of a single element. Radio fields are special though.
            $targets.forEach(($target) => {
                // Handle some fields differently and check for type-casting
                if (type === 'verbb\\formie\\fields\\formfields\\Number') {
                    variables[variableKey] = Number($target.value);
                } else if (type === 'verbb\\formie\\fields\\formfields\\Radio') {
                    if ($target.checked) {
                        variables[variableKey] = $target.value;
                    }
                } else if (type === 'verbb\\formie\\fields\\formfields\\Checkboxes') {
                    if ($target.checked) {
                        if (!Array.isArray(variables[variableKey])) {
                            variables[variableKey] = [];
                        }

                        variables[variableKey].push($target.value);
                    }
                } else {
                    variables[variableKey] = $target.value;
                }
            });
        });

        // See if we need to format some variables depending on formatting
        variables = this.formatVariables(variables);

        // Allow events to modify the data before evaluation
        const beforeEvaluateEvent = new CustomEvent('beforeEvaluate', {
            bubbles: true,
            detail: {
                calculations: this,
                init: isInit,
                formula,
                variables,
            },
        });

        $field.dispatchEvent(beforeEvaluateEvent);

        // Events can modify the formula and variables
        // eslint-disable-next-line
        formula = beforeEvaluateEvent.detail.formula;
        // eslint-disable-next-line
        variables = beforeEvaluateEvent.detail.variables;

        // Prevent evaluating empty data
        if (!formula || !variables) {
            return;
        }

        try {
            let result = this.expressionLanguage.evaluate(formula, variables);

            // Format the result, if required
            result = this.formatValue(result);

            // Allow events to modify the data after evaluation
            const afterEvaluateEvent = new CustomEvent('afterEvaluate', {
                bubbles: true,
                detail: {
                    calculations: this,
                    init: isInit,
                    formula,
                    variables,
                    result,
                },
            });

            $field.dispatchEvent(afterEvaluateEvent);

            // Events can modify the result
            // eslint-disable-next-line
            result = afterEvaluateEvent.detail.result;

            // Handle null-like results. If they're `NaN`, `false` set as empty, but `0` is valid
            if (typeof result === 'undefined' || Number.isNaN(result)) {
                result = '';
            }

            this.$input.value = result;

            // Trigger a `input` event for the input as well
            this.$input.dispatchEvent(new Event('input'));
        } catch (ex) {
            console.error(ex);

            // Always reset in the event of an error
            this.$input.value = '';
        }
    }

    getEventType($input) {
        const $field = $input.closest('[data-field-type]');
        const fieldType = $field?.getAttribute('data-field-type');
        const tagName = $input.tagName.toLowerCase();
        const inputType = $input.getAttribute('type') ? $input.getAttribute('type').toLowerCase() : '';

        if (tagName === 'select' || inputType === 'date') {
            return 'change';
        }

        if (inputType === 'number') {
            return 'input';
        }

        if (inputType === 'checkbox' || inputType === 'radio') {
            return 'click';
        }

        // If sourcing a value from another calculations, this'll be a `input` event
        if (fieldType && fieldType === 'calculations') {
            return 'input';
        }

        return 'keyup';
    }

    formatVariables(variables) {
        if (this.formatting === 'number') {
            Object.keys(variables).forEach((index) => {
                if (Array.isArray(variables[index])) {
                    variables[index].forEach((i, j) => {
                        variables[index][j] = Number(variables[index][j]);
                    });
                } else {
                    variables[index] = Number(variables[index]);
                }
            });
        }

        return variables;
    }

    formatValue(value) {
        if (this.formatting === 'number') {
            // TODO: allow handling of array values more thatn just sum (e.g. 1,2,3)
            if (Array.isArray(value)) {
                value = value.reduce((partialSum, a) => { return partialSum + a; }, 0);
            }

            // Assume no rounding if not providing decimals, but formatting as number
            if (this.decimals) {
                value = Number(value).toFixed(this.decimals);
            } else {
                value = Number(value).toFixed(0);
            }

            if (this.prefix) {
                value = this.prefix + value;
            }

            if (this.suffix) {
                value = value + this.suffix;
            }
        }

        return value;
    }

    querySelectorAllRegex(regex, attributeToSearch) {
        const output = [];

        for (const element of this.$form.querySelectorAll(`[${attributeToSearch}]`)) {
            if (regex.test(element.getAttribute(attributeToSearch))) {
                output.push(element);
            }
        }

        return output;
    }
}

window.FormieCalculations = FormieCalculations;
