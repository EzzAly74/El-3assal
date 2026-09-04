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
 * Where a shopper lands after choosing InstaPay: it shows them the number to
 * send to, and takes their phone number and a screenshot.
 *
 * ===========================================================================
 * THERE IS NO ORDER YET, AND THE GUARD CHANGED WITH IT
 * ===========================================================================
 * This used to admit whoever held `last_real_order_id` - because the checkout
 * created the order first and redirected here second. That is reversed: the
 * order is now created by Save.php when the receipt is uploaded, so at this
 * point there is nothing but a live quote.
 *
 * The guard is therefore the QUOTE, and it asks the three questions that
 * actually matter:
 *
 *   is there a basket to pay for      an empty or missing quote means someone
 *                                     bookmarked this page, or came back to it
 *                                     after checking out. -> the cart.
 *   did they choose InstaPay          the renderer writes the method onto the
 *                                     quote through set-payment-information
 *                                     before redirecting here, so a quote whose
 *                                     method is anything else did not come from
 *                                     that button. -> the checkout.
 *   can the method still be used      the merchant may have switched InstaPay
 *                                     off, or cleared their number, since the
 *                                     page was opened. A transfer form with no
 *                                     destination invites a payment into thin
 *                                     air. -> the checkout.
 *
 * Nothing here is guessable from a URL: there is no id in it, and the quote is
 * the session's own.
 *
 * ===========================================================================
 * ALREADY PAID
 * ===========================================================================
 * A shopper who submits the receipt and then presses back arrives with an EMPTY
 * quote - Save placed the order and Magento deactivated it. They fall through
 * the first guard to the cart, which is the honest answer: their order exists
 * and this form is finished with. The success page is reachable from their
 * confirmation email and from My Orders.
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
        $quote = $this->checkoutSession->getQuote();

        if (!$quote->getId() || (int) $quote->getItemsCount() === 0) {
            return $this->redirectFactory->create()->setPath('checkout/cart');
        }

        $payment = $quote->getPayment();

        if ($payment === null || $payment->getMethod() !== ConfigProvider::CODE) {
            return $this->redirectFactory->create()->setPath('checkout');
        }

        if (!$this->config->isUsable((int) $quote->getStoreId())) {
            return $this->redirectFactory->create()->setPath('checkout');
        }

        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set(__('InstaPay payment'));

        return $page;
    }
}
