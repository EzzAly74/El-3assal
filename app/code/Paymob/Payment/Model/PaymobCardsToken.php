<?php
namespace Paymob\Payment\Model;

class PaymobCardsToken extends \Magento\Framework\Model\AbstractModel
{
    protected function _construct()
    {
        $this->_init('Paymob\Payment\Model\ResourceModel\PaymobCardsToken');
    }
}