<?php

namespace Paymob\Payment\Plugin;

use Magento\Payment\Model\Config as PaymentConfig;
use Paymob\Payment\Model\DynamicMethodsProvider;
use Magento\Framework\ObjectManagerInterface;

class PaymentMethodFactoryPlugin
{
    protected $dynamicMethodsProvider;
    protected $objectManager;

    public function __construct(
        DynamicMethodsProvider $dynamicMethodsProvider,
        ObjectManagerInterface $objectManager
    ) {
        $this->dynamicMethodsProvider = $dynamicMethodsProvider;
        $this->objectManager = $objectManager;
    }

    public function afterGetActiveMethods(PaymentConfig $subject, array $result)
    {
        $dynamicMethods = $this->dynamicMethodsProvider->getMethods();

        foreach ($dynamicMethods as $code => $class) {
            if (!isset($result[$code])) {
                $instance = $this->objectManager->create($class, ['data' => ['code' => $code]]);
                $result[$code] = $instance;
            }
        }
        return $result;
    }

    /**
     * Ensure factory can create dynamic paymob_* methods by code
     */
    public function aroundCreate(\Magento\Payment\Model\Method\Factory $subject, callable $proceed, $method)
    {
        try {
            return $proceed($method);
        } catch (\Throwable $e) {
            // If the core factory cannot resolve but the code is one of our dynamic paymob_* methods,
            // create an instance of PaymobGeneric with the given code.
            if (is_string($method) && strpos($method, 'paymob_') === 0) {
                return $this->objectManager->create(
                    \Paymob\Payment\Model\Method\PaymobGeneric::class,
                    ['data' => ['code' => $method]]
                );
            }
            throw $e;
        }
    }
}
