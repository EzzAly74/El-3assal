<?php

namespace Paymob\Payment\Model\System\Config\Admin;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Data\Collection\AbstractDb;
use Paymob\Library\Paymob;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\App\Filesystem\DirectoryList;

class ValidatePaymobData extends PaymobData
{
    protected $config;
    protected $configWriter;
    protected $storeManager;
    protected $base_url;
    protected $productMetadata;
    protected $moduleList;
    protected $directoryList;

    public function __construct(
        WriterInterface $configWriter,
        ManagerInterface $msg,
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        StoreManagerInterface $storeManager,
        ProductMetadataInterface $productMetadata,
        ModuleListInterface $moduleList,
        DirectoryList $directoryList,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($msg, $context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
        $this->config = $config;
        $this->configWriter = $configWriter;
        $this->base_url = $storeManager->getStore()->getBaseUrl();
        $this->productMetadata = $productMetadata;
        $this->moduleList = $moduleList;
        $this->directoryList = $directoryList;
    }

    public function beforeSave()
    {
        $pubKey = $this->getPaymobConf('public_key');
        $secKey = $this->getPaymobConf('secret_key');
        $apiKey = $this->getPaymobConf('api_key');
        $hmac =   $this->getPaymobConf('hmac');
        $debug =  $this->getPaymobConf("debug");
        $integration_id = $this->getPaymobConf('integration_id');

        if (empty($pubKey) || empty($secKey) || empty($apiKey) || empty($hmac) || empty($integration_id)) {
            return $this->disablePaymob('Paymob Error : Please, ensure filling the secret, public, API keys, HMAC, and integration ID(s) that exist in Paymob merchant account.');
        }

        $conf = [
            'apiKey' => $apiKey,
            'pubKey' => $pubKey,
            'secKey' => $secKey
        ];

        try {
            $paymobReq = new Paymob($debug, $this->getDebugFilePath());
            $result = $paymobReq->authToken($conf);
            $note = 'Merchant configuration: ';
            Paymob::addLogs($debug, $this->getDebugFilePath(), $note, $result);

            if ($hmac !== $result['hmac']) {
                return $this->disablePaymob('Paymob Error : Please provide correct HMAC key that exists in your Paymob merchant account.');
            }

            $integrations = explode(',', $integration_id);
            $confIntegration = [];

            foreach ($integrations as $id) {
                $id = (int) trim($id);
                if ($id > 0) {
                    $confIntegration[] = $id;
                }
            }

            $availIds = array_column($result['integrationIDs'], 'id');
            foreach ($confIntegration as $val) {
                if (!in_array($val, $availIds)) {
                    return $this->disablePaymob('Paymob Error : One or more integration IDs do not match Paymob flash/unified checkout. Please check or contact your account manager.');
                }
            }

            $this->registerFramework($confIntegration, $secKey, $debug);
        } catch (\Exception $exc) {
            return $this->disablePaymob('Paymob Error : ' . $exc->getMessage());
        }

        return parent::beforeSave();
    }

    private function disablePaymob($error)
    {
        return $this->disablePaymobWithError($error);
    }

    public function registerFramework($ids, $sec_key, $debug)
    {
        $moduleInfo = $this->moduleList->getOne('Paymob_Payment');
        $unique_ids = array_unique($ids);

        $data = [
            "url" => $this->base_url,
            "is_ssl" => $this->isSsl(),
            "platform" => "MAGENTO",
            "platform_version" => $this->productMetadata->getVersion(),
            "plugin_version" => $moduleInfo['setup_version'],
            "selected_integrations" => $unique_ids,
            "info" => []
        ];

        $register = new Paymob($debug, $this->getDebugFilePath());
        Paymob::addLogs($debug, $this->getDebugFilePath(), 'Register Framework Request Data', $data);
        $register->registerFramework($sec_key, $data);
    }

    public function isSsl()
    {
        $isFrontendSecure = $this->scopeConfig->isSetFlag(
            'web/secure/use_in_frontend',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        $isAdminSecure = $this->scopeConfig->isSetFlag(
            'web/secure/use_in_adminhtml',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        return $isFrontendSecure && $isAdminSecure;
    }

    private function getDebugFilePath(): string 
    {
        $logDir = $this->directoryList->getPath(DirectoryList::LOG);
        return $logDir . '/paymob.log';
    }
}
