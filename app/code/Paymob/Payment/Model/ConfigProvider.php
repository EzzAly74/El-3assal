<?php

namespace Paymob\Payment\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class ConfigProvider implements ConfigProviderInterface
{
    protected $scopeConfig;
    protected $storeManager;
    protected $urlBuilder;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        UrlInterface $urlBuilder
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->urlBuilder = $urlBuilder;
    }

    public function getConfig()
    {
        $config = ['payment' => []];

        // Get all payment configs
        $allPaymentConfig = $this->scopeConfig->getValue('payment', ScopeInterface::SCOPE_STORE);

        foreach ($allPaymentConfig as $code => $settings) {
            if (strpos($code, 'paymob_') === 0 && !empty($settings['active'])) {
                $config['payment'][$code] = [
                    'logo' => $this->getLogo($code),
                    'title' => $settings['title'] ?? '',
                    'instructions' => $settings['description'] ?? '',
                    'isBillingAddressRequired' => true,
                    'redirectUrl' => $this->urlBuilder->getUrl('paymob_payment/checkout/process', ['_secure' => true])
                ];
            }
        }

        return $config;
    }

    private function getLogo($paymentCode)
    {
        $logo = $this->getConfigData($paymentCode, 'logo');
        if (!$logo || $logo == "Array") {
            return '';
        }

        $mediaPath = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
        return $mediaPath . '/' . $logo;
    }

    private function getConfigData($paymentCode, $field)
    {
        return $this->scopeConfig->getValue("payment/{$paymentCode}/{$field}", ScopeInterface::SCOPE_STORE);
    }
}