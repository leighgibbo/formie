<?php
namespace verbb\formie\migrations;

use verbb\formie\elements\Form;
use verbb\formie\fields\formfields\Date;
use verbb\formie\integrations\elements\Entry as EntryIntegration;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\ArrayHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;

class m241128_100000_entry_integrations extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $entryIntegrations = (new Query())
            ->select(['*'])
            ->from(['{{%formie_integrations}}'])
            ->where(['type' => EntryIntegration::class])
            ->all();

        foreach ($entryIntegrations as $entryIntegration) {
            $forms = (new Query())
                ->select(['*'])
                ->from(['{{%formie_forms}}'])
                ->all();

            foreach ($forms as $form) {
                $updatedSettings = false;
                $entryTypeUid = null;

                $settings = Json::decode($form['settings']);
                $entryIntegrationSettings = $settings['integrations'][$entryIntegration['handle']] ?? [];
                $entryTypeId = $entryIntegrationSettings['entryTypeId'] ?? null;

                if ($entryTypeId) {
                    $entryTypeUid = Db::uidById(Table::ENTRYTYPES, $entryTypeId);
                }

                if ($entryTypeUid) {
                    $settings['integrations'][$entryIntegration['handle']]['entryTypeUid'] = $entryTypeUid;
                    $updatedSettings = true;
                }

                if ($updatedSettings) {
                    $this->update('{{%formie_forms}}', ['settings' => Json::encode($settings)], ['id' => $form['id']]);
                }
            }
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m241128_100000_entry_integrations cannot be reverted.\n";
        return false;
    }
}
