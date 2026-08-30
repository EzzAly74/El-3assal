<?php 

namespace Vendor\Paymob\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class Button extends Field
{
    protected function _getElementHtml(AbstractElement $element)
    {
        $url = $this->getUrl('paymob/config/fetch'); // Define controller below
        return '<button onclick="setLocation(\'' . $url . '\')" type="button">Confirm Integrations</button>';
    }
}
