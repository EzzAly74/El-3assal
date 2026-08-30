<?php

namespace Paymob\Payment\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;

class SaveDynamicPaymobMethods implements ObserverInterface
{
    protected $configWriter;

    public function __construct(WriterInterface $configWriter)
    {
        $this->configWriter = $configWriter;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $request = $observer->getEvent()->getRequest();
        $postData = $request->getPostValue();

        if (!isset($postData['groups']['paymob_dynamic_methods']['fields'])) {
            return;
        }

        $methods = $postData['groups']['paymob_dynamic_methods']['fields'];

        foreach ($methods as $code => $fields) {
            foreach ($fields as $field => $value) {
                $path = 'payment/' . $code . '/' . $field;
                $this->configWriter->save($path, $value);
            }
        }
    }
}
