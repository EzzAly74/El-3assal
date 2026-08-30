<?php
namespace Paymob\Payment\Plugin;

use Magento\Sales\Model\ResourceModel\Order\Grid\Collection as OrderGridCollection;

class NormalizePaymentMethodGridPlugin
{
    /**
     * Normalize Paymob dynamic codes only for grid display
     *
     * @param OrderGridCollection $subject
     * @param array $result
     * @return array
     */
    public function afterGetData(OrderGridCollection $subject, $result)
    {
        if (is_array($result)) {
            foreach ($result as &$row) {
                if (isset($row['payment_method']) && strpos($row['payment_method'], 'paymob_') === 0) {
                    $row['payment_method'] = 'paymob_payment';
                }
            }
        }
        return $result;
    }
}
