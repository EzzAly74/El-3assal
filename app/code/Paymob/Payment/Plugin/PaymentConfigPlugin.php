<?php 

namespace Paymob\Payment\Plugin;

use Magento\Payment\Model\Config;
use Paymob\Payment\Model\DynamicMethodsProvider;
use Magento\Framework\ObjectManagerInterface;

class PaymentConfigPlugin
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

    public function afterGetActiveMethods(Config $subject, array $result)
    {
        $methods = $this->dynamicMethodsProvider->getMethods();
        foreach ($methods as $code => $class) {
            if (!isset($result[$code])) {
                $result[$code] = $this->objectManager->create(
                    $class,
                    ['data' => ['code' => $code]]
                );
            }
        }
        return $result;
    }
}
