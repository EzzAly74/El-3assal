<?php

namespace Paymob\Payment\Model\Method;

use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Payment\Helper\Data as PaymentData;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Payment\Model\Method\Logger;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\DataObject;
use Magento\Framework\UrlInterface;

class PaymobGeneric extends AbstractMethod
{
    protected $_code = 'paymob_generic';
    
    // CRITICAL: These settings control payment flow
    protected $_isOffline = false;
    protected $_canUseInternal = true;
    protected $_canUseCheckout = true;
    protected $_canOrder = true;
    protected $_isInitializeNeeded = true;

    protected $scopeConfig;
    protected $urlBuilder;

    public function __construct(
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        PaymentData $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        UrlInterface $urlBuilder,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );

        $this->scopeConfig = $scopeConfig;
        $this->urlBuilder = $urlBuilder;

        if (!empty($data['code'])) {
            $this->_code = $data['code'];
        }
    }

    public function initialize($paymentAction, $stateObject)
    {
        $stateObject->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
        $stateObject->setIsNotified(false);
    }

    public function getConfigValue(string $field)
    {
        $path = 'payment/' . $this->_code . '/' . $field;
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE);
    }

    /**
     * CRITICAL: This method is called by Magento to get the redirect URL
     * It's checked in AbstractMethod::getConfigData()
     */
    public function getOrderPlaceRedirectUrl()
    {
        return $this->urlBuilder->getUrl('paymob_payment/checkout/process', ['_secure' => true]);
    }

    /**
     * Get title for payment method (displayed in order details)
     */
    public function getTitle()
    {
        try {
            $info = $this->getInfoInstance();
            if ($info && $info->getAdditionalInformation('method_title')) {
                return $info->getAdditionalInformation('method_title');
            }
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // Fallback
        }

        return (string)($this->getConfigValue('title') ?: __('Paymob'));
    }
    
    /**
     * Assign payment method title during checkout
     */
    public function assignData(DataObject $data)
    {
        // Get the correct dynamic payment method code
        $code = $data->getData('method') ?: $this->getCode();

        // Load the title from configuration
        $title = $this->scopeConfig->getValue(
            'payment/' . $code . '/title',
            ScopeInterface::SCOPE_STORE
        ) ?: 'Paymob';

        /** @var \Magento\Payment\Model\InfoInterface $paymentInfo */
        $paymentInfo = $this->getInfoInstance();

        if ($paymentInfo) {
            $paymentInfo->setAdditionalInformation('method_title', $title);
        }

        return parent::assignData($data);
    }

    public function getCode()
    {
        return $this->getData('code') ?: $this->_code;
    }

    /**
     * Return correct info block (for order/invoice display)
     */
    public function getInfoBlockType()
    {
        return \Magento\Payment\Block\Info\Instructions::class;
    }

    /**
     * Check if method is available
     */
    public function isAvailable($quote = null)
    {
        // Check if method is active in config
        $isActive = $this->getConfigValue('active');
        
       if (strpos($this->getCode(), 'paymob_') === 0) {
            return true;
        }
        
        return parent::isAvailable($quote);
    }

    public function requiresBillingAddress()
    {
        return true;
    }
}