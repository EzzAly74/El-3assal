<?php
namespace Paymob\Payment\Model;

class PaymobOrderInfo extends \Magento\Framework\Model\AbstractModel
{
    public function _construct(){
        $this->_init("Paymob\Payment\Model\ResourceModel\PaymobOrderInfo");
    }
}
