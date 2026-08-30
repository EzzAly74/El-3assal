<?php

namespace Paymob\Payment\Model;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\HTTP\Client\Curl;
use Paymob\Library\Paymob;
use Magento\Framework\Filesystem;


class IntegrationManager
{
    protected $configWriter;
    protected $scopeConfig;
    protected $cacheTypeList;
    protected $curl;
    protected $paymob;
    protected $filesystem;

    const PAYMOB_PREFIX = 'paymob_';
    const STATIC_METHOD = 'paymob_payment';

    public function __construct(
        WriterInterface $configWriter,
        ScopeConfigInterface $scopeConfig,
        TypeListInterface $cacheTypeList,
        Curl $curl,
        Paymob $paymob,
        Filesystem $filesystem
    ) {
        $this->configWriter = $configWriter;
        $this->scopeConfig = $scopeConfig;
        $this->cacheTypeList = $cacheTypeList;
        $this->curl = $curl;
        $this->paymob = $paymob;
        $this->filesystem = $filesystem;
    }

    public function fetchAndCreateMethods($apiKey, $secretKey, $publicKey)
    {
        // Step 1: Get integrations from Paymob
        $conf = [
            'apiKey' => $apiKey,
            'secKey' => $secretKey,
            'pubKey' => $publicKey,
            'mode'   => 'live'
        ];

        $data = $this->paymob->authToken($conf);
        $mediaWrite = $this->filesystem->getDirectoryWrite(\Magento\Framework\App\Filesystem\DirectoryList::MEDIA);
        $mediaDirRelative = 'paymentslogo';
        $mediaPath = $mediaWrite->getAbsolutePath($mediaDirRelative);

        // Ensure the folder exists
        if (!$mediaWrite->isExist($mediaDirRelative)) {
            try {
                $mediaWrite->create($mediaDirRelative);
            } catch (\Exception $e) {
                // fallback in case Magento's create fails (e.g. due to FS permission)
                if (!is_dir($mediaPath)) {
                    mkdir($mediaPath, 0755, true);
                }
            }
        }

        $gateway_data = $this->paymob->getPaymobGateways($conf['secKey'], $mediaPath);
      
 
        if (empty($data['integrationIDs'])) {
            throw new \Exception('No integrations found.');
        }

        // Step 2: Delete all Paymob dynamic methods except 'paymob_payment' and 'paymob_main'
        $allConfigs = $this->scopeConfig->getValue('payment', \Magento\Framework\App\Config\ScopeConfigInterface::SCOPE_TYPE_DEFAULT);

        foreach ($allConfigs as $methodCode => $methodConfig) {
            if (
                str_starts_with($methodCode, self::PAYMOB_PREFIX)
                && $methodCode !== self::STATIC_METHOD
                && $methodCode !== 'paymob_main'
            ) {
                foreach ($methodConfig as $field => $value) {
                    $this->configWriter->delete("payment/{$methodCode}/{$field}");
                }
            }
        }

        // Step 3: Create static 'paymob_main' method
        $paymobintegrationIds = []; // collect all integration IDs

        foreach ($data['integrationIDs'] as $integration) {
            $id = (int) $integration['id'];
            if ($id > 0) {
                $paymobintegrationIds[] = $id;
            }
        }
        // Save static method with all integration IDs
        $this->configWriter->save("payment/paymob_main/active", 0);
        $this->configWriter->save("payment/paymob_main/title", "Pay with Paymob");
        $this->configWriter->save("payment/paymob_main/hmac", "");
        $this->configWriter->save("payment/paymob_main/api_key", $apiKey);
        $this->configWriter->save("payment/paymob_main/secret_key", $secretKey);
        $this->configWriter->save("payment/paymob_main/public_key", $publicKey);
        $this->configWriter->save("payment/paymob_main/model", \Paymob\Payment\Model\Method\PaymobGeneric::class);
        $this->configWriter->save("payment/paymob_main/sort_order", 50);
        $this->configWriter->save("payment/paymob_main/integration_id", implode(',', $paymobintegrationIds));


        // Step 4: Save new integration-based methods
        foreach ($data['integrationIDs'] as $integration) {
            $id         = $integration['id'];
            $methodCode = self::PAYMOB_PREFIX . $id;
            $type       = $integration['type'];
            $valu_type  = strtolower($integration['gateway_type']);
            $name       = !empty($integration['name']) ? $integration['name'] : $type;
            $mode       = isset($integration['mode']) ? $integration['mode'] : 'test';

            // re sort payment methods 
            $type = $integration['gateway_type'];
            if ($type === 'VPC') {
                $type = 'Card';
            } elseif ($type === 'CAGG') {
                $type = 'Aman';
            } elseif ($type === 'UIG') {
                $type = 'Wallet';
            }

            $valu_type = strtolower($type);
            $name      = !empty($integration['name']) ? $integration['name'] : $type;
            switch ($type) {
                case 'Card':
                    $sortOrder = 10;
                    break;
                case 'Wallet':
                    $sortOrder = 20;
                    break;
                default:
                    $sortOrder = 30;
                    break;
            }

            // Title and Description from gateway data
            $checkoutTitle = isset($gateway_data[$valu_type]['title']) ? $gateway_data[$valu_type]['title'] : $name;
            $checkoutDesc  = isset($gateway_data[$valu_type]['desc'])  ? $gateway_data[$valu_type]['desc']  : $name;

            // Logo path
            $logoFile     = $valu_type . '.png';
            $mediaWrite   = $this->filesystem->getDirectoryWrite(\Magento\Framework\App\Filesystem\DirectoryList::MEDIA);
            $logoRelative = $mediaWrite->isExist('paymentslogo/' . $logoFile)
                ? 'paymentslogo/' . $logoFile
                : '';

            // Save configuration for each method
            $this->configWriter->save("payment/{$methodCode}/active", 0);
            $this->configWriter->save("payment/{$methodCode}/is_original_price", 1);
            $this->configWriter->save("payment/{$methodCode}/discount_amount_percentage", '0');
            $this->configWriter->save("payment/{$methodCode}/title", $checkoutTitle);
            $this->configWriter->save("payment/{$methodCode}/description", $checkoutDesc);
            $this->configWriter->save("payment/{$methodCode}/logo", $logoRelative);
            $this->configWriter->save("payment/{$methodCode}/mode", $mode);
            $this->configWriter->save("payment/{$methodCode}/integration_id", $id);
            $this->configWriter->save("payment/{$methodCode}/gateway_type", $valu_type);
            $this->configWriter->save("payment/{$methodCode}/api_key", $apiKey);
            $this->configWriter->save("payment/{$methodCode}/secret_key", $secretKey);
            $this->configWriter->save("payment/{$methodCode}/public_key", $publicKey);
            $this->configWriter->save("payment/{$methodCode}/sort_order", $sortOrder);
            $this->configWriter->save("payment/{$methodCode}/model", \Paymob\Payment\Model\Method\PaymobGeneric::class);
        }

        // Step 5: Clear config cache
        $this->cacheTypeList->cleanType('config');

        return __('%1 integrations created.', count($data['integrationIDs']));
    }

}
