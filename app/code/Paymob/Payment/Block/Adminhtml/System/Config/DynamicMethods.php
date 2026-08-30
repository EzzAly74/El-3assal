<?php

namespace Paymob\Payment\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;

class DynamicMethods extends Field
{
    protected $scopeConfig;

    public function __construct(
        Context $context,
        ScopeConfigInterface $scopeConfig,
        array $data = []
    ) {
        $this->scopeConfig = $scopeConfig;
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        $html = '<div><strong>Paymob Dynamic Methods</strong></div>';
        $configData = $this->scopeConfig->getValue('payment', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);

        foreach ($configData as $code => $values) {
            if (strpos($code, 'paymob_') === 0) {
                $title = $values['title'] ?? '';
                $hmac = $values['hmac'] ?? '';
                $sortOrder = $values['sort_order'] ?? '';

                $html .= "<div style='margin:15px 0; padding:10px; border:1px solid #ccc; background:#f8f8f8'>";
                $html .= "<strong>{$code}</strong><br/>";
                $html .= "Title: <input name='groups[{$code}][fields][title][value]' value='{$title}' style='width:300px'/><br/>";
                $html .= "HMAC: <input name='groups[{$code}][fields][hmac][value]' value='{$hmac}' style='width:300px'/><br/>";
                $html .= "Sort Order: <input name='groups[{$code}][fields][sort_order][value]' value='{$sortOrder}' style='width:100px'/><br/>";
                $html .= "</div>";
            }
        }

        return $html;
    }
}
