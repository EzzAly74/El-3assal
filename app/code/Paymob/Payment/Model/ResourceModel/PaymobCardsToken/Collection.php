<?php 
namespace Paymob\Payment\Model\ResourceModel\PaymobCardsToken;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
	public function _construct(){
		$this->_init("Paymob\Payment\Model\PaymobCardsToken","Paymob\Payment\Model\ResourceModel\PaymobCardsToken");
	}
}