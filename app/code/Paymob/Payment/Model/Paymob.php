<?php

namespace Paymob\Payment\Model;

class Paymob extends \Magento\Payment\Model\Method\AbstractMethod
{
    const CODE = 'paymob_payment';
    protected $_code = self::CODE;
}