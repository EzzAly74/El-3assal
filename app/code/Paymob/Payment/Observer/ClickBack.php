<?php

namespace Paymob\Payment\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Checkout\Model\Session;
use Magento\Sales\Model\Order;

class ClickBack implements ObserverInterface
{

    /**
     * @var checkoutSession
     */
    private $checkoutSession;

    /**
     * @param Session $checkoutSession
     */
    public function __construct(Session $checkoutSession)
    {
        $this->checkoutSession = $checkoutSession;
    }

    /**
     * Restore quote when click on Go Back button
     *
     * @param  Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $lastRealOrder = $this->checkoutSession->getLastRealOrder();
        if (!$lastRealOrder->getPayment()) {
            return;
        }

        $state = $lastRealOrder->getData('state');
        $paymentMethod = $lastRealOrder->getPayment()->getMethod();

        // Restore the quote when the order is still awaiting payment
        if (
            $state === Order::STATE_PENDING_PAYMENT &&
            $lastRealOrder->getData('status') === Order::STATE_PENDING_PAYMENT
        ) {
            $this->checkoutSession->restoreQuote();
            return;
        }

        // Also restore the quote when the order was canceled while using a Paymob method.
        // This handles two additional scenarios:
        //   1. Magento's pending-payment cron canceled the order before the user returned.
        //   2. The Paymob server-to-server webhook canceled the order in a separate process
        //      (no browser session), leaving the quote inactive in the database.
        // Without this, the customer ends up with no active cart and sees
        // "Current customer does not have an active cart" when switching to COD.
        if ($state === Order::STATE_CANCELED && strpos($paymentMethod, 'paymob') === 0) {
            $this->checkoutSession->restoreQuote();
        }
    }
}
