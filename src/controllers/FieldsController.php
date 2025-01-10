<?php
namespace verbb\formie\controllers;

use verbb\formie\Formie;
use verbb\formie\base\NestedFieldInterface;
use verbb\formie\elements\Submission;
use verbb\formie\fields\formfields\Signature;

use Craft;
use craft\web\Controller;

use yii\web\Response;

use Throwable;

class FieldsController extends Controller
{
    // Properties
    // =========================================================================

    protected array|bool|int $allowAnonymous = ['get-summary-html', 'get-signature-image'];


    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if ($action->id === 'get-summary-html') {
            $this->enableCsrfValidation = false;
        }

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('formie/settings/fields', []);
    }

    public function actionGetElementSelectOptions(): Response
    {
        $elements = [];

        try {
            $fieldData = $this->request->getParam('field');
            $type = $fieldData['type'];
            $fieldSettings = $fieldData['settings'];

            // Create a new fieldtype, and populate the settings
            $field = new $type();
            $field->sources = $fieldSettings['sources'] ?? [];
            $field->source = $fieldSettings['source'] ?? null;

            // Fetch the element query for the field, so we can fetch the content (limited)
            $elements = $field->getPreviewElements();
        } catch (Throwable $e) {
            Formie::error(Craft::t('formie', 'Unable to fetch element select options: “{message}” {file}:{line}', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]));
        }

        return $this->asJson($elements);
    }

    public function actionGetPredefinedOptions(): Response
    {
        $type = $this->request->getParam('option');

        $options = Formie::$plugin->getPredefinedOptions()->getPredefinedOptionsForType($type);

        return $this->asJson($options);
    }

    public function actionGetSummaryHtml(): string
    {
        $fieldId = (int)$this->request->getParam('fieldId');
        $submissionId = (int)$this->request->getParam('submissionId');

        if ($submissionId && $fieldId) {
            $submission = Submission::find()->id($submissionId)->isIncomplete(null)->one();

            if ($submission && $form = $submission->getForm()) {
                if ($field = $form->getFieldById($fieldId)) {
                    $value = $field->getFieldValue($submission);

                    return $field->getFrontEndInputHtml($form, $value);
                }
            }
        }

        return '';
    }

    public function actionGetSignatureImage(): ?Response
    {
        $fieldId = (int)$this->request->getParam('fieldId');
        $submissionUid = $this->request->getParam('submissionUid');

        // Use UID to prevent easy-guessing of submission to scrape data
        if ($submissionUid && $fieldId) {
            $submission = Submission::find()->uid($submissionUid)->one();

            if ($submission && $form = $submission->getForm()) {
                $signatureValue = null;

                foreach ($form->getCustomFields() as $field) {
                    if ((int)$field->id === $fieldId) {
                        $signatureValue = $submission->getFieldValue($field->handle);
                    }

                    if ($field instanceof NestedFieldInterface) {
                        foreach ($field->getCustomFields() as $nestedField) {
                            if ((int)$nestedField->id === $fieldId) {
                                $row = $submission->getFieldValue($field->handle)->one();

                                if ($row) {
                                    $signatureValue = $row->getFieldValue($nestedField->handle);
                                }
                            }
                        }
                    }
                }

                if ($signatureValue) {
                    $base64 = explode('base64,', $signatureValue);
                    $image = base64_decode(end($base64));

                    $response = Craft::$app->getResponse();
                    $response->setCacheHeaders();
                    $response->getHeaders()->set('Content-Type', 'image/png');
                    
                    return $this->asRaw($image);
                }
            }
        }

        return null;
    }

}
