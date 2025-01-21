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

class Turnstile extends Captcha
{
    // Properties
    // =========================================================================

    public ?string $handle = 'turnstile';
    public ?string $secretKey = null;
    public ?string $siteKey = null;
    public string $scriptLoadingMethod = 'asyncDefer';
    public string $theme = 'auto';
    public string $size = 'normal';
    public string $appearance = 'always';


    // Public Methods
    // =========================================================================

    public function getName(): string
    {
        return Craft::t('formie', 'Cloudflare Turnstile');
    }

    public function getDescription(): string
    {
        return Craft::t('formie', 'Friendly Captcha employs a fundamentally new approach to securely defend your websites and online services from spam and bots. Find out more via [Cloudflare Turnstile](https://blog.cloudflare.com/turnstile-private-captcha-alternative/).');
    }

    /**
     * @inheritDoc
     */
    public function getSettingsHtml(): ?string
    {
        $variables = $this->getSettingsHtmlVariables();

        return Craft::$app->getView()->renderTemplate('formie/integrations/captchas/turnstile/_plugin-settings', $variables);
    }

    public function getFormSettingsHtml($form): string
    {
        $variables = $this->getFormSettingsHtmlVariables($form);
        
        return Craft::$app->getView()->renderTemplate('formie/integrations/captchas/turnstile/_form-settings', $variables);
    }

    /**
     * @inheritDoc
     */
    public function getFrontEndHtml(Form $form, $page = null): string
    {
        return Html::tag('div', null, [
            'class' => 'formie-turnstile-placeholder',
            'data-turnstile-placeholder' => true,
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
            'loadingMethod' => $this->scriptLoadingMethod,
            'theme' => $this->theme,
            'size' => $this->size,
            'appearance' => $this->appearance,
        ];

        $src = Craft::$app->getAssetManager()->getPublishedUrl('@verbb/formie/web/assets/frontend/dist/', true, 'js/captchas/turnstile.js');

        return [
            'src' => $src,
            'module' => 'FormieTurnstile',
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
        $response = $this->getRequestParam('cf-turnstile-response');

        if (!$response) {
            return false;
        }

        $client = Craft::createGuzzleClient();

        $response = $client->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'json' => [
                'secret' => App::parseEnv($this->secretKey),
                'response' => $response,
                'remoteip' => Craft::$app->getRequest()->getRemoteIP(),
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
            'scriptLoadingMethod' => $this->scriptLoadingMethod,
            'theme' => $this->theme,
            'size' => $this->size,
            'appearance' => $this->appearance,
        ];
    }    
}
