<?php
namespace verbb\formie\integrations\captchas;

use verbb\formie\base\Captcha;
use verbb\formie\elements\Form;
use verbb\formie\elements\Submission;

use Craft;

class Honeypot extends Captcha
{
    // Constants
    // =========================================================================

    public const HONEYPOT_INPUT_NAME = 'beesknees';


    // Properties
    // =========================================================================

    public ?string $handle = 'honeypot';


    // Public Methods
    // =========================================================================

    public function getName(): string
    {
        return Craft::t('formie', 'Honeypot');
    }

    public function getDescription(): string
    {
        return Craft::t('formie', 'Check for bots that auto-fill forms, by providing an additional hidden field that should be left blank.');
    }

    /**
     * @inheritDoc
     */
    public function getFrontEndHtml(Form $form, $page = null): string
    {
        $sessionKey = $this->getSessionKey($form, $page);

        $label = Craft::t('formie', 'Leave this field blank');

        $output = '<div id="' . $sessionKey . '_wrapper" style="display:none;">';
        $output .= '<label for="' . $sessionKey . '">' . $label . '</label>';
        $output .= '<input type="text" id="' . $sessionKey . '" name="' . self::HONEYPOT_INPUT_NAME . '" style="display:none;" />';
        $output .= '</div>';

        return $output;
    }

    /**
     * @inheritDoc
     */
    public function getRefreshJsVariables(Form $form, $page = null): array
    {
        return [
            'formId' => $form->getFormId(),
            'sessionKey' => self::HONEYPOT_INPUT_NAME,
        ];
    }

    /**
     * @inheritDoc
     */
    public function validateSubmission(Submission $submission): bool
    {
        // The honeypot field must be left blank
        if ($this->getRequestParam(self::HONEYPOT_INPUT_NAME)) {
            $this->spamReason = Craft::t('formie', 'Honeypot input has value: {v}.', ['v' => $this->getRequestParam(self::HONEYPOT_INPUT_NAME)]);

            return false;
        }

        // If the honeypot param has been stripped out of the request altogether
        if ($this->getRequestParam(self::HONEYPOT_INPUT_NAME, true) === null) {
            $this->spamReason = Craft::t('formie', 'Honeypot param missing: {v}.', ['v' => self::HONEYPOT_INPUT_NAME]);

            return false;
        }

        return true;
    }


    // Private Methods
    // =========================================================================

    private function getSessionKey($form, $page = null): string
    {
        // Default the page to the last page, if not set.
        if (!$page) {
            $pages = $form->getPages();
            $page = $pages[count($pages) - 1] ?? $page;
        }

        $array = array_filter([
            self::HONEYPOT_INPUT_NAME . '_',
            $form->id,
            $page->id ?? null,
        ]);

        return implode('', $array);
    }

}
