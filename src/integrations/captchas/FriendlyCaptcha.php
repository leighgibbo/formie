<?php
namespace verbb\formie\integrations\captchas;

use verbb\formie\base\Captcha;
use verbb\formie\elements\Form;
use verbb\formie\elements\Submission;

use Craft;
use craft\helpers\App;
use craft\helpers\ArrayHelper;
use craft\helpers\Html;
use craft\helpers\Json;

class FriendlyCaptcha extends Captcha
{
    // Properties
    // =========================================================================

    public ?string $handle = 'friendlyCaptcha';
    public ?string $secretKey = null;
    public ?string $siteKey = null;
    public string $language = 'en';
    public string $startMode = 'none';


    // Public Methods
    // =========================================================================

    public function getName(): string
    {
        return Craft::t('formie', 'Friendly Captcha');
    }

    public function getDescription(): string
    {
        return Craft::t('formie', 'Friendly Captcha employs a fundamentally new approach to securely defend your websites and online services from spam and bots. Find out more via [Friendly Captcha](https://friendlycaptcha.com/).');
    }

    /**
     * @inheritDoc
     */
    public function getSettingsHtml(): ?string
    {
        $variables = $this->getSettingsHtmlVariables();
        $variables['languageOptions'] = $this->_getLanguageOptions();

        return Craft::$app->getView()->renderTemplate('formie/integrations/captchas/friendly-captcha/_plugin-settings', $variables);
    }

    public function getFormSettingsHtml($form): string
    {
        $variables = $this->getFormSettingsHtmlVariables($form);

        return Craft::$app->getView()->renderTemplate('formie/integrations/captchas/friendly-captcha/_form-settings', $variables);
    }

    /**
     * @inheritDoc
     */
    public function getFrontEndHtml(Form $form, $page = null): string
    {
        return Html::tag('div', null, [
            'class' => 'formie-friendly-captcha-placeholder',
            'data-friendly-captcha-placeholder' => true,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getFrontEndJsVariables(Form $form, $page = null): ?array
    {
        $settings = [
            'siteKey' => App::parseEnv($this->siteKey),
            'formId' => $form->getFormId(),
            'language' => $this->_getMatchedLanguageId() ?? 'en',
            'startMode' => $this->startMode ?? 'none',
        ];

        $src = Craft::$app->getAssetManager()->getPublishedUrl('@verbb/formie/web/assets/frontend/dist/', true, 'js/captchas/friendly-captcha.js');

        return [
            'src' => $src,
            'module' => 'FormieFriendlyCaptcha',
            'settings' => $settings,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getGqlVariables(Form $form, $page = null): array
    {
        return [
            'formId' => $form->getFormId(),
            'sessionKey' => 'siteKey',
            'value' => App::parseEnv($this->siteKey),
        ];
    }

    /**
     * @inheritDoc
     */
    public function validateSubmission(Submission $submission): bool
    {
        $response = $this->getRequestParam('frc-captcha-solution');

        if (!$response) {
            return false;
        }

        $client = Craft::createGuzzleClient();

        $response = $client->post('https://api.friendlycaptcha.com/api/v1/siteverify', [
            'json' => [
                'secret' => App::parseEnv($this->secretKey),
                'sitekey' => App::parseEnv($this->siteKey),
                'solution' => $response,
            ],
        ]);

        $result = Json::decode((string)$response->getBody(), true);
        $success = $result['success'] ?? false;

        if (!$success) {
            $this->spamReason = Json::encode($result);
        }

        return $success;
    }

    public function hasValidSettings(): bool
    {
        return $this->siteKey && $this->secretKey;
    }

    public function allowedGqlSettings(): array
    {
        return [
            'siteKey' => $this->siteKey,
            'language' => $this->language,
        ];
    }


    // Private Methods
    // =========================================================================

    public function _getMatchedLanguageId()
    {
        if ($this->language && $this->language != 'auto') {
            return $this->language;
        }

        $currentLanguageId = Craft::$app->getLocale()->getLanguageID();

        // 700+ languages supported
        $allCraftLocales = Craft::$app->getI18n()->getAllLocales();
        $allCraftLanguageIds = ArrayHelper::getColumn($allCraftLocales, 'id');

        // ~70 languages supported
        $allRecaptchaLanguageIds = ArrayHelper::getColumn($this->_getLanguageOptions(), 'value');

        // 65 matched language IDs
        $matchedLanguageIds = array_intersect($allRecaptchaLanguageIds, $allCraftLanguageIds);

        // If our current request Language ID matches a reCAPTCHA language ID, use it
        if (in_array($currentLanguageId, $matchedLanguageIds, true)) {
            return $currentLanguageId;
        }

        // If our current language ID has a more generic match, use it
        if (str_contains($currentLanguageId, '-')) {
            $parts = explode('-', $currentLanguageId);
            $baseLanguageId = $parts['0'] ?? null;

            if (in_array($baseLanguageId, $matchedLanguageIds, true)) {
                return $baseLanguageId;
            }
        }

        return null;
    }

    private function _getLanguageOptions(): array
    {
        $languages = [
            'Auto' => 'auto',
            'English' => 'en',
            'French' => 'fr',
            'German' => 'de',
            'Italian' => 'it',
            'Dutch' => 'nl',
            'Portuguese' => 'pt',
            'Spanish' => 'es',
            'Catalan' => 'ca',
            'Danish' => 'da',
            'Japanese' => 'ja',
            'Russian' => 'ru',
            'Swedish' => 'sv',
            'Greek' => 'el',
            'Ukrainian' => 'uk',
            'Bulgarian' => 'bg',
            'Czech' => 'cs',
            'Slovak' => 'sk',
            'Norwegian' => 'no',
            'Finnish' => 'fi',
            'Latvian' => 'lv',
            'Lithuanian' => 'lt',
            'Polish' => 'pl',
            'Estonian' => 'et',
            'Croatian' => 'hr',
            'Serbian' => 'sr',
            'Slovenian' => 'sl',
            'Hungarian' => 'hu',
            'Romanian' => 'ro',
            'Chinese (Simplified)' => 'zh',
            'Chinese (Traditional)' => 'zh_TW',
            'Vietnamese' => 'vi',
            'Hebrew' => 'he',
            'Thai' => 'th',
        ];

        $languageOptions = [];

        foreach ($languages as $languageName => $languageCode) {
            $languageOptions[] = [
                'label' => Craft::t('formie', $languageName),
                'value' => $languageCode,
            ];
        }

        return $languageOptions;
    }
}
