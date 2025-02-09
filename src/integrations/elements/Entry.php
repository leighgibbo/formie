<?php
namespace verbb\formie\integrations\elements;

use verbb\formie\Formie;
use verbb\formie\base\Integration;
use verbb\formie\base\Element;
use verbb\formie\elements\Submission;
use verbb\formie\helpers\ArrayHelper;
use verbb\formie\models\IntegrationCollection;
use verbb\formie\models\IntegrationField;
use verbb\formie\models\IntegrationFormSettings;
use verbb\formie\models\IntegrationResponse;

use Craft;
use craft\base\Element as CraftElement;
use craft\elements\Entry as EntryElement;
use craft\elements\User;
use craft\helpers\Json;

use Throwable;

class Entry extends Element
{
    // Static Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    public static function displayName(): string
    {
        return Craft::t('formie', 'Entry');
    }


    // Properties
    // =========================================================================

    public ?int $entryTypeId = null;
    public ?string $entryTypeUid = null;
    public int|array|null $defaultAuthorId = null;
    public ?bool $createDraft = null;


    // Public Methods
    // =========================================================================

    public function getDescription(): string
    {
        return Craft::t('formie', 'Map content provided by form submissions to create {name} elements.', ['name' => static::displayName()]);
    }

    /**
     * @inheritDoc
     */
    public function defineRules(): array
    {
        $rules = parent::defineRules();

        // Validate the following when saving form settings
        $rules[] = [['entryTypeUid', 'defaultAuthorId'], 'required', 'on' => [Integration::SCENARIO_FORM]];

        // Find the field for the entry type - a little trickier due to nested in sections
        $fields = $this->_getEntryTypeSettings()->fields ?? [];

        $rules[] = [
            ['fieldMapping'], 'validateFieldMapping', 'params' => $fields, 'when' => function($model) {
                return $model->enabled;
            }, 'on' => [Integration::SCENARIO_FORM],
        ];

        return $rules;
    }

    public function fetchFormSettings(): IntegrationFormSettings
    {
        $customFields = [];

        $sections = Craft::$app->getSections()->getAllSections();

        foreach ($sections as $section) {
            if ($section->type === 'single') {
                continue;
            }

            foreach ($section->getEntryTypes() as $entryType) {
                $fields = $this->getFieldLayoutFields($entryType->getFieldLayout());

                $customFields[$section->name][] = new IntegrationCollection([
                    'id' => $entryType->uid,
                    'name' => $entryType->name,
                    'fields' => $fields,
                ]);
            }
        }

        return new IntegrationFormSettings([
            'elements' => $customFields,
            'attributes' => $this->getElementAttributes(),
        ]);
    }

    public function getElementAttributes(): array
    {
        return [
            new IntegrationField([
                'name' => Craft::t('app', 'Title'),
                'handle' => 'title',
            ]),
            new IntegrationField([
                'name' => Craft::t('app', 'Site ID'),
                'handle' => 'siteId',
            ]),
            new IntegrationField([
                'name' => Craft::t('app', 'Slug'),
                'handle' => 'slug',
            ]),
            new IntegrationField([
                'name' => Craft::t('app', 'Author'),
                'handle' => 'author',
                'type' => IntegrationField::TYPE_ARRAY,
            ]),
            new IntegrationField([
                'name' => Craft::t('app', 'Post Date'),
                'handle' => 'postDate',
                'type' => IntegrationField::TYPE_DATECLASS,
            ]),
            new IntegrationField([
                'name' => Craft::t('app', 'Expiry Date'),
                'handle' => 'expiryDate',
                'type' => IntegrationField::TYPE_DATECLASS,
            ]),
            new IntegrationField([
                'name' => Craft::t('app', 'Enabled'),
                'handle' => 'enabled',
                'type' => IntegrationField::TYPE_BOOLEAN,
            ]),
            new IntegrationField([
                'name' => Craft::t('app', 'Date Created'),
                'handle' => 'dateCreated',
                'type' => IntegrationField::TYPE_DATECLASS,
            ]),
            new IntegrationField([
                'name' => Craft::t('app', 'Date Updated'),
                'handle' => 'dateUpdated',
                'type' => IntegrationField::TYPE_DATECLASS,
            ]),
        ];
    }

    public function getUpdateAttributes(): array
    {
        $attributes = [];

        $sections = Craft::$app->getSections()->getAllSections();

        foreach ($sections as $section) {
            if ($section->type === 'single') {
                continue;
            }

            foreach ($section->getEntryTypes() as $entryType) {
                $key = $entryType->uid;

                $attributes[$key] = [
                    new IntegrationField([
                        'name' => Craft::t('app', 'ID'),
                        'handle' => 'id',
                    ]),
                    new IntegrationField([
                        'name' => Craft::t('app', 'Title'),
                        'handle' => 'title',
                    ]),
                    new IntegrationField([
                        'name' => Craft::t('app', 'Slug'),
                        'handle' => 'slug',
                    ]),
                    new IntegrationField([
                        'name' => Craft::t('app', 'Site'),
                        'handle' => 'site',
                    ]),
                ];

                foreach ($entryType->getFieldLayout()->getCustomFields() as $field) {
                    if (!$this->fieldCanBeUniqueId($field)) {
                        continue;
                    }

                    $attributes[$key][] = new IntegrationField([
                        'handle' => $field->handle,
                        'name' => $field->name,
                        'type' => $this->getFieldTypeForField(get_class($field)),
                        'sourceType' => get_class($field),
                    ]);
                }
            }
        }

        return $attributes;
    }

    public function sendPayload(Submission $submission): IntegrationResponse|bool
    {
        if (!$this->entryTypeUid) {
            Integration::error($this, Craft::t('formie', 'Unable to save element integration. No `entryTypeUid`.'), true);

            return false;
        }

        try {
            $entryType = Craft::$app->getSections()->getEntryTypeByUid($this->entryTypeUid);

            $entry = $this->getElementForPayload(EntryElement::class, $entryType->id, $submission, [
                'typeId' => $entryType->id,
                'sectionId' => $entryType->sectionId,
            ]);

            $entry->siteId = $submission->siteId;
            $entry->typeId = $entryType->id;
            $entry->sectionId = $entryType->sectionId;

            if ($this->defaultAuthorId) {
                $entry->authorId = $this->defaultAuthorId;
            }

            $attributeValues = $this->getFieldMappingValues($submission, $this->attributeMapping, $this->getElementAttributes());

            // Filter null values
            if (!$this->overwriteValues) {
                $attributeValues = ArrayHelper::filterNullValues($attributeValues);
            }

            foreach ($attributeValues as $entryFieldHandle => $fieldValue) {
                if ($entryFieldHandle === 'author') {
                    if (isset($fieldValue[0]) && $fieldValue[0]) {
                        $entry->authorId = (int)$fieldValue[0];
                    }
                } else {
                    $entry->{$entryFieldHandle} = $fieldValue;
                }
            }

            $fields = $this->_getEntryTypeSettings()->fields ?? [];
            $fieldValues = $this->getFieldMappingValues($submission, $this->fieldMapping, $fields);

            // Filter null values
            if (!$this->overwriteValues) {
                $fieldValues = ArrayHelper::filterNullValues($fieldValues);
            }

            $entry->setFieldValues($fieldValues);
            $entry->updateTitle();

            // If we're not mapping to the status, ensure it's inherited from the section's default
            $statusAttributeMapping = $this->attributeMapping['enabled'] ?? '';

            if ($statusAttributeMapping === '') {
                $siteSettings = ArrayHelper::firstWhere($entryType->section->getSiteSettings(), 'siteId', $entry->siteId);
                $enabled = $siteSettings->enabledByDefault;

                if (Craft::$app->getIsMultiSite() && count($entry->getSupportedSites()) > 1) {
                    $entry->enabled = true;
                    $entry->setEnabledForSite($enabled);
                } else {
                    $entry->enabled = $enabled;
                    $entry->setEnabledForSite(true);
                }
            }

            // Although empty, because we pass via reference, we need variables
            $endpoint = '';
            $method = '';

            // Allow events to cancel sending - return as success            
            if (!$this->beforeSendPayload($submission, $endpoint, $entry, $method)) {
                return true;
            }

            // Check if we need to create a new draft
            if ($this->createDraft) {
                $authorId = $entry->authorId ?? Craft::$app->getUser()->getId();

                // Is this a brand-new entry?
                if (!$entry->id) {
                    $entry->setScenario(CraftElement::SCENARIO_ESSENTIALS);

                    if (!Craft::$app->getDrafts()->saveElementAsDraft($entry, $authorId)) {
                        Integration::error($this, Craft::t('formie', 'Unable to save “{type}” draft element integration. Error: {error}.', [
                            'type' => $this->handle,
                            'error' => Json::encode($entry->getErrors()),
                        ]), true);

                        return false;
                    }

                    $this->afterSendPayload($submission, '', $entry, '', []);
                } else {
                    // Otherwise, create a new draft on the entry
                    $draft = Craft::$app->getDrafts()->createDraft($entry, $authorId);

                    $this->afterSendPayload($submission, '', $entry, '', ['draft' => $draft]);
                }

                return true;
            }

            if (!$entry->validate()) {
                Integration::error($this, Craft::t('formie', 'Unable to validate “{type}” element integration. Error: {error}.', [
                    'type' => $this->handle,
                    'error' => Json::encode($entry->getErrors()),
                ]), true);

                return false;
            }

            if (!Craft::$app->getElements()->saveElement($entry, true, true, $this->updateSearchIndexes)) {
                Integration::error($this, Craft::t('formie', 'Unable to save “{type}” element integration. Error: {error}.', [
                    'type' => $this->handle,
                    'error' => Json::encode($entry->getErrors()),
                ]), true);

                return false;
            }

            // Allow events to say the response is invalid
            if (!$this->afterSendPayload($submission, '', $entry, '', [])) {
                return true;
            }
        } catch (Throwable $e) {
            $error = Craft::t('formie', 'Element integration failed for submission “{submission}”. Error: {error} {file}:{line}', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'submission' => $submission->id,
            ]);

            Formie::error($error);

            return new IntegrationResponse(false, [$error]);
        }

        return true;
    }

    public function getAuthor($form): array
    {
        $defaultAuthorId = $form->settings->integrations[$this->handle]['defaultAuthorId'] ?? '';

        if (!$defaultAuthorId) {
            $defaultAuthorId = $this->defaultAuthorId;
        }

        if ($defaultAuthorId) {
            return User::find()->id($defaultAuthorId)->all();
        }

        return [Craft::$app->getUser()->getIdentity()];
    }


    // Private Methods
    // =========================================================================

    private function _getEntryTypeSettings()
    {
        $entryTypes = $this->getFormSettingValue('elements');

        foreach ($entryTypes as $key => $entryType) {
            if ($collection = ArrayHelper::firstWhere($entryType, 'id', $this->entryTypeUid)) {
                return $collection;
            }
        }

        return [];
    }
}