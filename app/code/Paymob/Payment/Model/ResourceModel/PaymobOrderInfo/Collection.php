<?php 
namespace Paymob\Payment\Model\ResourceModel\PaymobOrderInfo;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
	public function _construct(){
		$this->_init("Paymob\Payment\Model\PaymobOrderInfo","Paymob\Payment\Model\ResourceModel\PaymobOrderInfo");
	}
}
