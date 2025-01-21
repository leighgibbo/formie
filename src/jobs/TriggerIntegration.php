<?php
namespace verbb\formie\jobs;

use verbb\formie\Formie;
use verbb\formie\base\Integration;
use verbb\formie\elements\Submission;
use verbb\formie\models\IntegrationResponse;

use Craft;
use craft\db\Query;
use craft\helpers\Json;

use Exception;

class TriggerIntegration extends BaseJob
{
    // Properties
    // =========================================================================

    public ?int $submissionId = null;
    public mixed $payload = null;
    public ?Integration $integration = null;


    // Public Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    public function execute($queue): void
    {
        $this->setProgress($queue, 0.25);

        // Allow incomplete submissions
        $submission = Submission::find()
            ->id($this->submissionId)
            ->isIncomplete(null)
            ->status(null)
            ->one();

        $this->setProgress($queue, 0.5);

        if ($submission) {
            // Pass a reference of this class to the integration, to assist with debugging.
            // Set with a private variable, so it doesn't appear in the queue job data which would be mayhem.
            $this->integration->setQueueJob($this);

            // Re-fetch the token in case it's been changed (as we store a reference to the entire integration in the job)
            $this->integration->tokenId = (new Query())
                ->select('tokenId')
                ->from(['{{%formie_integrations}}'])
                ->where(['uid' => $this->integration->uid])
                ->scalar();

            // Ensure we set the correct language for a potential CLI request
            Craft::$app->language = $submission->getSite()->language;
            Craft::$app->set('locale', Craft::$app->getI18n()->getLocaleById($submission->getSite()->language));
            Craft::$app->getSites()->setCurrentSite($submission->getSite());

            $this->setProgress($queue, 0.75);

            $response = Formie::$plugin->getSubmissions()->sendIntegrationPayload($this->integration, $submission);

            // Check if some integrations return a response object for more detail
            if (($response instanceof IntegrationResponse) && !$response->success) {
                throw new Exception('Failed to trigger integration: ' . Json::encode($response->message) . '.');
            }

            if (!$response) {
                throw new Exception('Failed to trigger integration. Check the Formie log files.');
            }
        }

        $this->setProgress($queue, 1);
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    protected function defaultDescription(): string
    {
        return Craft::t('formie', 'Triggering form “{handle}” integration.', ['handle' => $this->integration->handle]);
    }
}
