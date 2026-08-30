<?php

namespace Paymob\Payment\Model\System\Config\Admin;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Data\Collection\AbstractDb;

class PaymobData extends Value
{
    /**
     * @var ManagerInterface
     */
    protected $messageManager;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * Constructor
     *
     * @param ManagerInterface       $messageManager
     * @param Context                $context
     * @param Registry               $registry
     * @param ScopeConfigInterface   $config
     * @param TypeListInterface      $cacheTypeList
     * @param AbstractResource|null  $resource
     * @param AbstractDb|null        $resourceCollection
     * @param array                  $data
     */
    public function __construct(
        ManagerInterface $messageManager,
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
        $this->messageManager = $messageManager;
        $this->scopeConfig = $config;
    }

    /**
     * Disable the payment method and show an error
     *
     * @param string $error
     * @return $this
     */
    protected function disablePaymobWithError($error)
    {
        $this->messageManager->addErrorMessage(__($error));
        $this->setValue('0'); // Disable the method
        return parent::beforeSave();
    }

    /**
     * Get configuration value from form data or system config
     *
     * @param string $conf
     * @return string|null
     */
    protected function getPaymobConf($conf)
    {
        $value = $this->getFieldsetDataValue($conf);
        if ($value === null) {
            $value = $this->scopeConfig->getValue('payment/paymob_payment/' . $conf);
        }
        return $value;
    }
}
