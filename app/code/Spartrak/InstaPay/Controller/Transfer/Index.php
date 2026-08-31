<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\InstaPay\Controller\Transfer;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;
use Spartrak\InstaPay\Model\Ui\ConfigProvider;

/**
 * The transfer page - Figma 586:7352.
 *
 * Where a shopper lands immediately after placing an InstaPay order: it shows
 * them the number to send to, and takes their phone number and a screenshot.
 *
 * ===========================================================================
 * WHO IS ALLOWED TO SEE IT
 * ===========================================================================
 * Only whoever just placed the order, and only for that order. The check is the
 * session's own `last_real_order_id`, which is the same thing Magento's success
 * page trusts - there is no order id in the URL to tamper with, so there is
 * nothing to enumerate.
 *
 * Three states are rejected, each to a place that makes sense:
 *
 *   no order in the session   -> the cart. Someone bookmarked this page, or
 *                                came back to it days later.
 *   order is not InstaPay     -> the success page. It is their order; it just
 *                                does not need a transfer.
 *   method not configured     -> the success page. The merchant switched
 *                                InstaPay off, or cleared the number, between
 *                                the order being placed and this request.
 *                                Showing a transfer form with no destination
 *                                would invite a payment into thin air.
 */
class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly PageFactory $pageFactory,
        private readonly RedirectFactory $redirectFactory,
        private readonly \Spartrak\InstaPay\Model\Config $config
    ) {
    }

    public function execute(): ResultInterface
    {
        $order = $this->checkoutSession->getLastRealOrder();

        if (!$order->getId()) {
            return $this->redirectFactory->create()->setPath('checkout/cart');
        }

        $payment = $order->getPayment();
        $isInstaPay = $payment !== null && $payment->getMethod() === ConfigProvider::CODE;

        if (!$isInstaPay || !$this->config->isUsable((int) $order->getStoreId())) {
            return $this->redirectFactory->create()->setPath('checkout/onepage/success');
        }

        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set(__('InstaPay payment'));

        return $page;
    }
}
