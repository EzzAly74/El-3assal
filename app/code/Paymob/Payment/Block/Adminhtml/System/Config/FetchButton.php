<?php

namespace Paymob\Payment\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Model\UrlInterface;

class FetchButton extends Field
{
    protected $_template = 'Paymob_Payment::system/config/fetch_button.phtml';

    /**
     * @var UrlInterface
     */
    protected $backendUrl;

    public function __construct(
        Context $context,
        UrlInterface $backendUrl,
        array $data = []
    ) {
        $this->backendUrl = $backendUrl;
        parent::__construct($context, $data);
    }

    /**
     * Returns the rendered HTML of the custom button field.
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }

    /**
     * Get URL for AJAX fetch call to controller
     *
     * @return string
     */
    public function getAjaxFetchUrl()
    {
        return $this->backendUrl->getUrl('paymob_payment/integration/fetch');
    }
}
