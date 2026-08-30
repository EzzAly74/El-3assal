<?php

namespace Paymob\Payment\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class DynamicIntegrations extends Field
{
    protected $_template = 'Paymob_Payment::system/config/dynamic_integrations.phtml';

    protected $scopeConfig;

    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        array $data = []
    ) {
        $this->scopeConfig = $scopeConfig;
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }

    public function getPaymobMethods()
    {
        $allConfig = $this->scopeConfig->getValue('payment', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);

        $methods = [];
        foreach ($allConfig as $code => $values) {
            if (strpos($code, 'paymob_') === 0) {
                $methods[$code] = $values;
            }
        }

        return $methods;
    }
}
