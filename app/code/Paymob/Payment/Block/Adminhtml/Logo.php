<?php
namespace Paymob\Payment\Block\Adminhtml;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class Logo extends Field
{
    protected function _getElementHtml(AbstractElement $element)
    {
        $logoUrl = $this->getViewFileUrl('Paymob_Payment::images/paymob.png');
        return '<div style="margin-bottom:15px;">
                    <img src="' . $logoUrl . '" alt="Paymob" style="height: 40px;" />
                </div>';
    }
}
