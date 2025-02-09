<?php
namespace verbb\formie\integrations\crm;

use verbb\formie\Formie;
use verbb\formie\base\Crm;
use verbb\formie\base\Integration;
use verbb\formie\elements\Submission;
use verbb\formie\events\MicrosoftDynamics365RequiredLevelsEvent;
use verbb\formie\events\MicrosoftDynamics365TargetSchemasEvent;
use verbb\formie\models\IntegrationField;
use verbb\formie\models\IntegrationFormSettings;

use Craft;
use craft\helpers\App;
use craft\helpers\ArrayHelper;
use craft\helpers\Json;
use League\OAuth1\Client\Server\Server as Oauth1Provider;
use League\OAuth2\Client\Provider\AbstractProvider;
use TheNetworg\OAuth2\Client\Provider\Azure;

use Throwable;

use GuzzleHttp\Client;

class MicrosoftDynamics365 extends Crm
{
    // Constants
    // =========================================================================

    public const EVENT_MODIFY_REQUIRED_LEVELS = 'modifyRequiredLevels';
    public const EVENT_MODIFY_TARGET_SCHEMAS = 'modifyTargetSchemas';
    


    // Static Methods
    // =========================================================================

    public static function supportsOauthConnection(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public static function displayName(): string
    {
        return Craft::t('formie', 'Microsoft Dynamics 365');
    }
    

    // Properties
    // =========================================================================
    
    public ?string $clientId = null;
    public ?string $clientSecret = null;
    public ?string $apiDomain = null;
    public bool $impersonateUser = false;
    public string $impersonateHeader = 'CallerObjectId';
    public ?string $impersonateUserId = null;
    public ?string $apiVersion = 'v9.0';
    public bool $mapToContact = false;
    public bool $mapToLead = false;
    public bool $mapToOpportunity = false;
    public bool $mapToAccount = false;
    public bool $mapToIncident = false;
    public ?array $contactFieldMapping = null;
    public ?array $leadFieldMapping = null;
    public ?array $opportunityFieldMapping = null;
    public ?array $accountFieldMapping = null;
    public ?array $incidentFieldMapping = null;

    private array $_entityOptions = [];
    private array $_systemUsers = [];


    // Public Methods
    // =========================================================================

    public function getClassHandle()
    {
        return 'microsoft-dynamics-365';
    }

    public function getClientId(): string
    {
        return App::parseEnv($this->clientId);
    }

    public function getClientSecret(): string
    {
        return App::parseEnv($this->clientSecret);
    }

    public function getOauthScope(): array
    {
        return [
            'openid',
            'profile',
            'email',
            'offline_access',
            'user.read',
        ];
    }

    public function getOauthProvider(): AbstractProvider|Oauth1Provider
    {
        return new Azure($this->getOauthProviderConfig());
    }

    public function getOauthProviderConfig(): array
    {
        return array_merge(parent::getOauthProviderConfig(), [
            'defaultEndPointVersion' => '1.0',
            'resource' => App::parseEnv($this->apiDomain),
        ]);
    }

    public function getDescription(): string
    {
        return Craft::t('formie', 'Manage your {name} customers by providing important information on their conversion on your site.', ['name' => static::displayName()]);
    }

    /**
     * @inheritDoc
     */
    public function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['clientId', 'clientSecret'], 'required'];

        $contact = $this->getFormSettingValue('contact');
        $lead = $this->getFormSettingValue('lead');
        $opportunity = $this->getFormSettingValue('opportunity');
        $account = $this->getFormSettingValue('account');
        $incident = $this->getFormSettingValue('incident');

        // Validate the following when saving form settings
        $rules[] = [
            ['contactFieldMapping'], 'validateFieldMapping', 'params' => $contact, 'when' => function($model) {
                return $model->enabled && $model->mapToContact;
            }, 'on' => [Integration::SCENARIO_FORM],
        ];

        $rules[] = [
            ['leadFieldMapping'], 'validateFieldMapping', 'params' => $lead, 'when' => function($model) {
                return $model->enabled && $model->mapToLead;
            }, 'on' => [Integration::SCENARIO_FORM],
        ];

        $rules[] = [
            ['opportunityFieldMapping'], 'validateFieldMapping', 'params' => $opportunity, 'when' => function($model) {
                return $model->enabled && $model->mapToOpportunity;
            }, 'on' => [Integration::SCENARIO_FORM],
        ];

        $rules[] = [
            ['accountFieldMapping'], 'validateFieldMapping', 'params' => $account, 'when' => function($model) {
                return $model->enabled && $model->mapToAccount;
            }, 'on' => [Integration::SCENARIO_FORM],
        ];

        $rules[] = [
            ['incidentFieldMapping'], 'validateFieldMapping', 'params' => $incident, 'when' => function($model) {
                return $model->enabled && $model->mapToIncident;
            }, 'on' => [Integration::SCENARIO_FORM]
        ];

        $rules[] = ['impersonateUserId', 'required', 'when' => function($model) {
            return $model->impersonateUser;
        }];

        return $rules;
    }

    public function fetchFormSettings(): IntegrationFormSettings
    {
        $settings = [];

        try {
            if ($this->mapToContact) {
                $settings['contact'] = $this->_getEntityFields('contact');
            }

            if ($this->mapToLead) {
                $settings['lead'] = $this->_getEntityFields('lead');
            }

            if ($this->mapToOpportunity) {
                $settings['opportunity'] = $this->_getEntityFields('opportunity');
            }

            if ($this->mapToAccount) {
                $settings['account'] = $this->_getEntityFields('account');
            }

            if ($this->mapToIncident) {
                $settings['incident'] = $this->_getEntityFields('incident');
            }
        } catch (Throwable $e) {
            Integration::apiError($this, $e);
        }

        return new IntegrationFormSettings($settings);
    }

    public function sendPayload(Submission $submission): bool
    {
        try {
            $contactValues = $this->getFieldMappingValues($submission, $this->contactFieldMapping, 'contact');
            $leadValues = $this->getFieldMappingValues($submission, $this->leadFieldMapping, 'lead');
            $opportunityValues = $this->getFieldMappingValues($submission, $this->opportunityFieldMapping, 'opportunity');
            $accountValues = $this->getFieldMappingValues($submission, $this->accountFieldMapping, 'account');
            $incidentValues = $this->getFieldMappingValues($submission, $this->incidentFieldMapping, 'incident');

            $contactId = null;
            $leadId = null;
            $opportunityId = null;
            $accountId = null;
            $incidentId = null;

            if ($this->mapToContact) {
                $contactPayload = $contactValues;

                $response = $this->deliverPayload($submission, 'contacts?$select=contactid', $contactPayload);

                if ($response === false) {
                    return true;
                }

                $contactId = $response['contactid'] ?? '';

                if (!$contactId) {
                    Integration::error($this, Craft::t('formie', 'Missing return “contactId” {response}. Sent payload {payload}', [
                        'response' => Json::encode($response),
                        'payload' => Json::encode($contactPayload),
                    ]), true);

                    return false;
                }
            }

            if ($this->mapToAccount) {
                $accountPayload = $accountValues;

                if ($contactId) {
                    $accountPayload['primarycontactid@odata.bind'] = $this->_formatLookupValue('contacts', $contactId);
                }

                $response = $this->deliverPayload($submission, 'accounts?$select=accountid', $accountPayload);

                if ($response === false) {
                    return true;
                }

                $accountId = $response['accountid'] ?? '';

                if (!$accountId) {
                    Integration::error($this, Craft::t('formie', 'Missing return accountid {response}. Sent payload {payload}', [
                        'response' => Json::encode($response),
                        'payload' => Json::encode($accountPayload),
                    ]), true);

                    return false;
                }
            }

            if ($this->mapToLead) {
                $leadPayload = $leadValues;

                if ($contactId) {
                    $contactLookupValue = $this->_formatLookupValue('contacts', $contactId);

                    $leadPayload['parentcontactid@odata.bind'] = $contactLookupValue;
                    $leadPayload['customerid_contact@odata.bind'] = $contactLookupValue;
                }

                if ($accountId) {
                    $accountLookupValue = $this->_formatLookupValue('accounts', $accountId);

                    $leadPayload['parentaccountid@odata.bind'] = $accountLookupValue;
                    $leadPayload['customerid_account@odata.bind'] = $accountLookupValue;
                }

                $response = $this->deliverPayload($submission, 'leads?$select=leadid', $leadPayload);

                if ($response === false) {
                    return true;
                }

                $leadId = $response['leadid'] ?? '';

                if (!$leadId) {
                    Integration::error($this, Craft::t('formie', 'Missing return leadid {response}. Sent payload {payload}', [
                        'response' => Json::encode($response),
                        'payload' => Json::encode($leadPayload),
                    ]), true);

                    return false;
                }
            }

            if ($this->mapToOpportunity) {
                $opportunityPayload = $opportunityValues;

                if ($contactId) {
                    $accountPayload['parentcontactid@odata.bind'] = $this->_formatLookupValue('contacts', $contactId);
                }

                if ($accountId) {
                    $accountPayload['parentaccountid@odata.bind'] = $this->_formatLookupValue('accounts', $accountId);
                }

                $response = $this->deliverPayload($submission, 'opportunities?$select=opportunityid', $opportunityPayload);

                if ($response === false) {
                    return true;
                }

                $opportunityId = $response['opportunityid'] ?? '';

                if (!$opportunityId) {
                    Integration::error($this, Craft::t('formie', 'Missing return opportunityid {response}. Sent payload {payload}', [
                        'response' => Json::encode($response),
                        'payload' => Json::encode($opportunityPayload),
                    ]), true);

                    return false;
                }
            }

            if ($this->mapToIncident) {
                $incidentPayload = $incidentValues;

                if ($contactId) {
                    $incidentPayload['customerid_contact@odata.bind'] = $this->_formatLookupValue('contacts', $contactId);
                }

                $response = $this->deliverPayload($submission, 'incidents?$select=incidentid', $incidentPayload);

                if ($response === false) {
                    return true;
                }

                $incidentId = $response['incidentid'] ?? '';

                if (!$incidentId) {
                    Integration::error($this, Craft::t('formie', 'Missing return incidentid {response}. Sent payload {payload}', [
                        'response' => Json::encode($response),
                        'payload' => Json::encode($incidentPayload),
                    ]), true);

                    return false;
                }
            }
        } catch (Throwable $e) {
            Integration::apiError($this, $e);

            return false;
        }

        return true;
    }

    public function request(string $method, string $uri, array $options = [])
    {
        // Recommended headers to pass for all web API requests
        // https://learn.microsoft.com/en-us/power-apps/developer/data-platform/webapi/compose-http-requests-handle-errors#http-headers
        $defaultOptions = [
            'headers' => [
                'Accept' => 'application/json',
                'OData-MaxVersion' => '4.0',
                'OData-Version' => '4.0',
                'If-None-Match' => null
            ]
        ];

        $options = ArrayHelper::merge($defaultOptions, $options);

        // Ensure a proper response is returned on POST/PATCH operations
        // https://learn.microsoft.com/en-us/power-apps/developer/data-platform/webapi/compose-http-requests-handle-errors#prefer-headers
        if ($method === 'POST' || $method === 'PATCH') {
            $options['headers']['Prefer'] = 'return=representation';
        }

        // Impersonate user when creating records if enabled
        if ($this->impersonateUser && $method === 'POST') {
            $options['headers'][$this->impersonateHeader] = $this->impersonateUserId;
        }

        // Prevent create when using upsert
        // https://learn.microsoft.com/en-us/power-apps/developer/data-platform/webapi/perform-conditional-operations-using-web-api#prevent-create-in-upsert
        if ($method === 'PATCH') {
            $options['headers']['If-Match'] = '*';
        }

        return parent::request($method, $uri, $options);
    }

    public function getClient(): Client
    {
        if ($this->_client) {
            return $this->_client;
        }

        $token = $this->getToken();

        if (!$token) {
            Integration::error($this, 'Token not found for integration. Attempting to refresh token.');

            $token = $this->getToken(true);
        }

        $url = rtrim(App::parseEnv($this->apiDomain), '/');
        $apiVersion = $this->apiVersion;

        $this->_client = Craft::createGuzzleClient([
            'base_uri' => "$url/api/data/$apiVersion/",
            'headers' => [
                'Authorization' => 'Bearer ' . ($token->accessToken ?? 'empty'),
                'Content-Type' => 'application/json',
            ],
        ]);

        // Always provide an authenticated client - so check first.
        // We can't always rely on the EOL of the token.
        try {
            $this->request('GET', 'WhoAmI');
        } catch (Throwable $e) {
            if ($e->getCode() === 401) {
                // Force-refresh the token
                Formie::$plugin->getTokens()->refreshToken($token, true);

                // Then try again, with the new access token
                $this->_client = Craft::createGuzzleClient([
                    'base_uri' => "$url/api/data/$apiVersion/",
                    'headers' => [
                        'Authorization' => 'Bearer ' . ($token->accessToken ?? 'empty'),
                        'Content-Type' => 'application/json',
                    ],
                ]);
            }
        }

        return $this->_client;
    }


    // Protected Methods
    // =========================================================================

    protected function convertFieldType($fieldType)
    {
        $fieldTypes = [
            'Decimal' => IntegrationField::TYPE_FLOAT,
            'Double' => IntegrationField::TYPE_FLOAT,
            'BigInt' => IntegrationField::TYPE_NUMBER,
            'Integer' => IntegrationField::TYPE_NUMBER,
            'Boolean' => IntegrationField::TYPE_BOOLEAN,
            'Money' => IntegrationField::TYPE_FLOAT,
            'Date' => IntegrationField::TYPE_DATE,
            'DateTime' => IntegrationField::TYPE_DATETIME,
        ];

        return $fieldTypes[$fieldType] ?? IntegrationField::TYPE_STRING;
    }

    private function _getEntityFields($entity): array
    {
        $metadataAttributesForSelect = [
            'AttributeType',
            'IsCustomAttribute',
            'IsValidForCreate',
            'IsValidForUpdate',
            'CanBeSecuredForCreate',
            'CanBeSecuredForUpdate',
            'LogicalName',
            'DisplayName',
            'RequiredLevel'
        ];

        // Fetch all defined fields on the entity
        // https://docs.microsoft.com/en-us/dynamics365/customer-engagement/web-api/contact?view=dynamics-ce-odata-9
        // https://docs.microsoft.com/en-us/dynamics365/customerengagement/on-premises/developer/entities/contact?view=op-9-1#BKMK_Address1_Telephone1
        $metadata = $this->request('GET', $this->_getEntityDefinitionsUri($entity), [
            'query' => [
                '$select' => 'Attributes',
                '$expand' => 'Attributes($select='. implode(',', $metadataAttributesForSelect) . ')'
            ]
        ]);

        // We also need to query DateTime attribute data to check if any are DateOnly
        $dateTimeAttributes = $this->request('GET', $this->_getEntityDefinitionsUri($entity, 'DateTime'), [
            'query' => [
                '$select' => 'SchemaName,LogicalName,DateTimeBehavior'
            ]
        ]);

        $dateTimeBehaviourValues = ArrayHelper::map($dateTimeAttributes, 'MetadataId','DateTimeBehavior.Value');

        $fields = [];
        $attributes = $metadata['Attributes'] ?? [];

        // Default to SystemRequired and ApplicationRequired
        $requiredLevels = [
            'SystemRequired',
            'ApplicationRequired'
        ];

        $event = new MicrosoftDynamics365RequiredLevelsEvent([
            'requiredLevels' => $requiredLevels,
        ]);

        $this->trigger(self::EVENT_MODIFY_REQUIRED_LEVELS, $event);

        foreach ($attributes as $field) {
            $label = $field['DisplayName']['UserLocalizedLabel']['Label'] ?? '';
            $customField = $field['IsCustomAttribute'] ?? false;
            $canCreate = $field['IsValidForCreate'] ?? false;
            $requiredLevel = $field['RequiredLevel']['Value'] ?? 'None';
            $type = $field['AttributeType'] ?? '';
            $odataType = $field['@odata.type'] ?? '';
            $metadataId = $field['MetadataId'] ?? '';

            // Pick the correct field handle, depending on custom fields
            if ($customField) {
                $handle = $field['SchemaName'] ?? '';
            } else {
                $handle = $field['LogicalName'] ?? '';
            }

            $key = $handle;

            $excludedTypes = [
                'Customer',
                'EntityName',
                'State',
                'Uniqueidentifier',
                'Virtual',
            ];

            if (!$label || !$handle || !$canCreate || in_array($type, $excludedTypes, true)) {
                continue;
            }

            // Relational fields need a special handle
            if ($odataType === '#Microsoft.Dynamics.CRM.LookupAttributeMetadata') {
                $handle .= '@odata.bind';
            }

            // DateTime attributes, just because the AttributeType is DateTime doesn't mean it actually accepts one!
            // If a field DateTimeBehaviour is set to DateOnly, it will not accept DateTime values ever!
            // https://learn.microsoft.com/en-us/dynamics365/customerengagement/on-premises/developer/behavior-format-date-time-attribute
            if ($type === 'DateTime') {
                $dateTimeBehavior = $dateTimeBehaviourValues[$metadataId] ?? null;

                if ($dateTimeBehavior === 'DateOnly') {
                    $type = 'Date';
                }
            }

            // Index by handle for easy lookup with PickLists
            $fields[$key] = new IntegrationField([
                'handle' => $handle,
                'name' => $label,
                'type' => $this->convertFieldType($type),
                'required' => in_array($requiredLevel, $event->requiredLevels, true),
            ]);
        }

        // Add default true/false values for boolean fields
        foreach ($fields as $field) {
            if ($field->type === IntegrationField::TYPE_BOOLEAN) {
                $field->options = [
                    'label' => Craft::t('formie', 'Default options'),
                    'options' => [
                        ['label' => Craft::t('formie', 'True'), 'value' => 'true'],
                        ['label' => Craft::t('formie', 'False'), 'value' => 'false']
                    ]
                ];
            }
        }

        // Do another call for PickList fields, to populate any set options to pick from
        $response = $this->request('GET', $this->_getEntityDefinitionsUri($entity, 'Picklist'), [
            'query' => [
                '$select' => 'IsCustomAttribute,LogicalName,SchemaName',
                '$expand' => 'GlobalOptionSet($select=Options)'
            ]
        ]);
        $pickListFields = $response['value'] ?? [];

        foreach ($pickListFields as $pickListField) {
            $customField = $pickListField['IsCustomAttribute'] ?? false;
            $pickList = $pickListField['GlobalOptionSet']['Options'] ?? [];
            $options = [];

            // Pick the correct field handle, depending on custom fields
            if ($customField) {
                $handle = $pickListField['SchemaName'] ?? '';
            } else {
                $handle = $pickListField['LogicalName'] ?? '';
            }

            // Get the field to add options to
            $field = $fields[$handle] ?? null;

            if (!$handle || !$pickList || !$field) {
                continue;
            }

            foreach ($pickList as $pickListOption) {
                $options[] = [
                    'label' => $pickListOption['Label']['UserLocalizedLabel']['Label'] ?? '',
                    'value' => $pickListOption['Value'],
                ];
            }

            if ($options) {
                $field->options = [
                    'label' => $field->name,
                    'options' => $options,
                ];
            }
        }

        // Do the same thing for any fields with an Owner, we have to do multiple queries.
        // This can be for multiple entities, so have some cache.
        $this->_getEntityOwnerOptions($entity, $fields);

        // Add a list of system users for "Created By"
        $fields['createdby'] = new IntegrationField([
            'handle' => 'createdby',
            'name' => Craft::t('formie', 'Created By'),
            'options' => [
                'label' => Craft::t('formie', 'Created By'),
                'options' => $this->_getSystemUsersOptions(),
            ],
        ]);

        // Reset array keys
        $fields = array_values($fields);

        // Sort by required field and then name
        ArrayHelper::multisort($fields, ['required', 'name'], [SORT_DESC, SORT_ASC]);

        return $fields;
    }

    private function _getEntityOwnerOptions($entity, $fields): void
    {
        // Get all the fields that are relational
        $response = $this->request('GET', $this->_getEntityDefinitionsUri($entity, 'Lookup'), [
            'query' => [
                '$select' => 'IsCustomAttribute,LogicalName,SchemaName,Targets'
            ]
        ]);
        $relationFields = $response['value'] ?? [];

        // Define a schema so that we can query each entity according to the target (index)
        // the endpoint to query (entity) and what attributes to use for the label/value to pick from
        $targetSchemas = [
            'businessunit' => [
                'entity' => 'businessunits',
                'label' => 'name',
                'value' => 'businessunitid',
            ],
            'systemuser' => [
                'entity' => 'systemusers',
                'label' => 'fullname',
                'value' => 'systemuserid',
            ],
            'account' => [
                'entity' => 'accounts',
                'label' => 'name',
                'value' => 'accountid',
            ],
            'contact' => [
                'entity' => 'contacts',
                'label' => 'fullname',
                'value' => 'contactid',
            ],
            'lead' => [
                'entity' => 'leads',
                'label' => 'fullname',
                'value' => 'leadid',
            ],
            'incident' => [
                'entity' => 'incidents',
                'label' => 'title',
                'value' => 'incidentid',
            ],
            'transactioncurrency' => [
                'entity' => 'transactioncurrencies',
                'label' => 'currencyname',
                'value' => 'transactioncurrencyid',
            ],
            'team' => [
                'entity' => 'teams',
                'label' => 'name',
                'value' => 'teamid',
            ],
            'campaign' => [
                'entity' => 'campaigns',
                'label' => 'name',
                'value' => 'campaignid',
            ],
            'pricelevel' => [
                'entity' => 'pricelevels',
                'label' => 'name',
                'value' => 'pricelevelid',
            ],
        ];

        $event = new MicrosoftDynamics365TargetSchemasEvent([
            'targetSchemas' => $targetSchemas,
        ]);

        $this->trigger(self::EVENT_MODIFY_TARGET_SCHEMAS, $event);

        $targetSchemas = ArrayHelper::merge($targetSchemas, $event->targetSchemas);

        // Populate our cached entity options, cached across multiple calls because we only need to
        // fetch the collection once, for each entity type. Subsequent fields can re-use the options.
        foreach ($relationFields as $relationField) {
            $targets = $relationField['Targets'] ?? [];

            foreach ($targets as $target) {
                // Get the schema definition to do stuff
                $targetSchema = $targetSchemas[$target] ?? '';

                if (!$targetSchema) {
                    continue;
                }

                // Provide a little cache, if we've already fetched items, no need to do again
                if (isset($this->_entityOptions[$target])) {
                    continue;
                }

                // We don't really need that much from the entities
                $select = [$targetSchema['label'], $targetSchema['value']];

                if ($target === 'systemuser') {
                    $select[] = 'applicationid';
                }

                // Fetch the entities and use the schema options to store. Be sure to limit and be performant.
                $response = $this->request('GET', $targetSchema['entity'], [
                    'query' => [
                        '$expand' => $targetSchema['expand'] ?? null,
                        '$filter' => $targetSchema['filter'] ?? null,
                        '$orderby' => $targetSchema['orderby'] ?? null,
                        '$select' => implode(',', $select),
                        '$top' => $targetSchema['limit'] ?? '100'
                    ],
                ]);

                $entities = $response['value'] ?? [];

                foreach ($entities as $entity) {
                    // Special-case for systemusers
                    if ($target === 'systemuser' && isset($entity['applicationid'])) {
                        continue;
                    }

                    $label = $entity[$targetSchema['label']] ?? '';
                    $value = $entity[$targetSchema['value']] ?? '';

                    $this->_entityOptions[$target][] = [
                        'label' => $label,
                        'value' => $this->_formatLookupValue($targetSchema['entity'], $value),
                    ];
                }
            }
        }

        // With all possible options populated, add the options into the fields
        foreach ($relationFields as $relationField) {
            $customField = $relationField['IsCustomAttribute'] ?? false;
            $targets = $relationField['Targets'] ?? [];
            $options = [];

            // Pick the correct field handle, depending on custom fields
            if ($customField) {
                $handle = $relationField['SchemaName'] ?? '';
            } else {
                $handle = $relationField['LogicalName'] ?? '';
            }

            foreach ($targets as $target) {
                // Get the options for this field
                if (isset($this->_entityOptions[$target])) {
                    $options = ArrayHelper::merge($options, $this->_entityOptions[$target]);
                }
            }

            // Get the field to add options to
            $field = $fields[$handle] ?? null;

            if (!$handle || !$field || !$options) {
                continue;
            }

            // Add the options to the field
            $field->options = [
                'label' => $field->name,
                'options' => $options,
            ];
        }
    }

    /**
     * Formats lookup values as entityname(GUID)
     *
     * @param $entity
     * @param $value
     * @return string
     */
    private function _formatLookupValue($entity, $value): string
    {
        return $entity . '(' . $value . ')';
    }

    /**
     * Format EntityDefintions uri request path
     *
     * @param $entity
     * @param $type
     * @return string
     */
    private function _getEntityDefinitionsUri($entity, $type = null): string
    {
        $path = "EntityDefinitions(LogicalName='$entity')";

        if ($type) {
            $path .= "/Attributes/Microsoft.Dynamics.CRM.{$type}AttributeMetadata";
        }

        return $path;
    }

    private function _getSystemUsersOptions(): array
    {
        if ($this->_systemUsers) {
            return $this->_systemUsers;
        }

        $response = $this->request('GET', 'systemusers', [
            'query' => [
                '$top' => '100',
                '$select' => 'fullname,systemuserid,applicationid',
                '$orderby' => 'fullname',
                '$filter' => 'applicationid eq null and invitestatuscode eq 4 and isdisabled eq false',
            ]
        ]);

        foreach (($response['value'] ?? []) as $user) {
            $this->_systemUsers[] = ['label' => $user['fullname'], 'value' => 'systemusers(' . $user['systemuserid'] . ')'];
        }

        return $this->_systemUsers;
    }
}
