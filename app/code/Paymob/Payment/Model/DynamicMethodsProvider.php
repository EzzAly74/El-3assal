<?php
namespace Paymob\Payment\Model;

class DynamicMethodsProvider
{
    public function getMethods(): array
    {
        // These integration IDs should ideally come from config
        $integrationIds = ['4951550'];

        $methods = [];
        foreach ($integrationIds as $id) {
            $code = 'paymob_' . $id;
            $methods[$code] = \Paymob\Payment\Model\Method\PaymobGeneric::class;
        }

        return $methods;
    }
}
