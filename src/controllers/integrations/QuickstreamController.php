<?php
namespace verbb\formie\controllers\integrations;

use verbb\formie\Formie;
use verbb\formie\helpers\ImportExportHelper;
use verbb\formie\models\Settings;
use verbb\formie\models\Support;

use Craft;
use craft\helpers\App;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\web\Controller;

use yii\base\ErrorException;
use yii\base\Exception;

use craft\web\Request;
use craft\web\Response;

use ZipArchive;

use GuzzleHttp\Exception\RequestException;

use Throwable;

use verbb\formie\integrations\payments\QuickStream;

class QuickstreamController extends Controller
{
    // Properties
    // =========================================================================

    protected array|bool|int $allowAnonymous = ['request-3d-secure-auth'];

    // Public Methods
    // =========================================================================

    public function actionRequest3dSecureAuth(Request $request): Response
    {
        $tokenId = $request->getParam('singleUseTokenId');
        $params = $request->getParam('params');
        $liveMode = $request->getParam('liveMode') ?? false;

        $quickstreamIntegration = new QuickStream();
        return $quickstreamIntegration->request3DSecureAuth($tokenId, $params, $liveMode);
    }
}
