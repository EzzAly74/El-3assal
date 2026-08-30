<?php
namespace Paymob\Payment\Block\Adminhtml;
/**
 * @used-by etc\system.xml
 */

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Data\Form\Element\AbstractElement;

class PaymobCallback extends Field
{    
    public function __construct(StoreManagerInterface $storeManager)
    {
        $this->base_url = $storeManager->getStore()->getBaseUrl();
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        $callback_url = $this->base_url . 'paymob_payment/checkout/callback';
        $html = "<div> 
                    <p><b>Transaction Processed/Response Callback URL:</b><br/>$callback_url</p>
                </div>";
        return $html;
    }

    protected function _renderScopeLabel(AbstractElement $element)
    {
        $html = ' data-config-scope="';
        if ($element->getScope()) {
            $html .= $element->getScopeLabel();
        }
        $html .= '"';
        return $html;
    }
    protected function _renderInheritCheckbox(AbstractElement $element){

    }
}
