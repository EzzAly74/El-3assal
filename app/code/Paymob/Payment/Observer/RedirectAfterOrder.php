<?php

namespace Paymob\Payment\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\App\Response\Http as ResponseHttp;

class RedirectAfterOrder implements ObserverInterface
{
   protected $redirect;
    protected $urlBuilder;
    protected $response;

    public function __construct(
        RedirectInterface $redirect,
        UrlInterface $urlBuilder,
        ResponseHttp $response
    ) {
        $this->redirect = $redirect;
        $this->urlBuilder = $urlBuilder;
        $this->response = $response;
    }

    public function execute(Observer $observer)
    {
        $orderIds = $observer->getEvent()->getOrderIds();
        if (!$orderIds || !is_array($orderIds)) {
            return;
        }

        /** @var \Magento\Sales\Model\Order $order */
        $order = $observer->getEvent()->getOrder();

        // Only redirect for Paymob payment methods
        if ($order && strpos($order->getPayment()->getMethod(), 'paymob') === 0) {
            $redirectUrl = $this->urlBuilder->getUrl('paymob_payment/checkout/process');
            $this->redirect->redirect($this->response, $redirectUrl);
        }
    }
}
