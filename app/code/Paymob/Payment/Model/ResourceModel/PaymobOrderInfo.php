<?php 
namespace Paymob\Payment\Model\ResourceModel;
class PaymobOrderInfo extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb 
{
	public function _construct(){
		$this->_init("paymob_order_info","id");
	}
}