<?php

namespace Paymob\Payment\Block\Info;

use Magento\Payment\Block\Info;
use Magento\Framework\Phrase;

class Paymob extends Info
{
    protected $_template = 'Paymob_Payment::info/paymob_info.phtml';

    /**
     * Get payment method title
     *
     * @return string|Phrase
     */
    public function getMethodTitle()
    {
        try {
            $info = $this->getInfo();
            if ($info && $info->getAdditionalInformation('method_title')) {
                return $info->getAdditionalInformation('method_title');
            }
            
            if ($info && $info->getMethod()) {
                return $info->getMethod()->getTitle();
            }
        } catch (\Exception $e) {
            // Fallback to default title
        }
        
        return __('Paymob');
    }

    /**
     * Get payment method instructions
     *
     * @return string
     */
    public function getInstructions()
    {
        try {
            $info = $this->getInfo();
            if ($info && $info->getAdditionalInformation('instructions')) {
                return $info->getAdditionalInformation('instructions');
            }
        } catch (\Exception $e) {
            // Return empty string if no instructions
        }
        
        return '';
    }
}
