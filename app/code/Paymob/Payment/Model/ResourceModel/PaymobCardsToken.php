<?php
namespace Paymob\Payment\Model\ResourceModel;
class PaymobCardsToken extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb 
{
    protected function _construct()
    {
        $this->_init('paymob_cards_token', 'id');
    }
}