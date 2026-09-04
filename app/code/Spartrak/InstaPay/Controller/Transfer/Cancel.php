<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\InstaPay\Controller\Transfer;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Spartrak\InstaPay\Model\Ui\ConfigProvider;

/**
 * Leaving the transfer page without paying.
 *
 * ===========================================================================
 * THERE IS NORMALLY NOTHING TO CANCEL ANY MORE
 * ===========================================================================
 * The order is no longer created when the shopper arrives at the transfer page
 * - Controller\Transfer\Save creates it when the receipt is uploaded. So the
 * ordinary path through this action has no order, no cancellation, no
 * restoreQuote() and nothing to tell the minicart: the quote was never touched,
 * the basket is exactly as the shopper left it, and they go back to the payment
 * step to choose something else.
 *
 * That is the fix for three reported problems at once: orders appearing in the
 * grid for shoppers who only LOOKED at the transfer page, those same orders
 * then appearing as `canceled` when they pressed back, and a minicart that
 * disagreed with the cart page because the quote had been consumed and restored
 * behind it.
 *
 * ===========================================================================
 * THE LEGACY BRANCH, AND WHY IT IS KEPT
 * ===========================================================================
 * Orders placed by the PREVIOUS behaviour are real and are sitting in
 * `pending_payment` right now, and a shopper can be mid-flow across a deploy.
 * If this action finds such an order it does what it always did: cancels it,
 * releases the stock, hands the basket back and says so.
 *
 * It is not dead code waiting to rot - it is the only correct response to a
 * state the store can genuinely be in. It will stop being reached once the last
 * pending_payment InstaPay order from the old flow is resolved, and can be
 * deleted then.
 *
 * ===========================================================================
 * IT IS POST-ONLY, AND THAT IS STILL A CORRECTNESS REQUIREMENT
 * ===========================================================================
 * The legacy branch cancels an order, which is a state change, so it must not
 * sit behind a URL that anything can follow. A GET link here would be fired by
 * every link prefetcher, mail scanner, antivirus proxy and browser preloader
 * that ever met the page. HttpPostActionInterface also means Magento's
 * form-key validation applies with no opt-out.
 */
class Cancel implements HttpPostActionInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly RedirectFactory $redirectFactory,
        private readonly MessageManager $messageManager,
        private readonly OrderManagementInterface $orderManagement,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->redirectFactory->create();
        $order = $this->checkoutSession->getLastRealOrder();
        $payment = $order->getId() ? $order->getPayment() : null;

        $isLegacyPendingOrder = $payment !== null
            && $payment->getMethod() === ConfigProvider::CODE
            && $order->canCancel();

        if (!$isLegacyPendingOrder) {
            // The ordinary path. Nothing was created, so nothing is undone —
            // the shopper simply goes back to pick another way to pay, with
            // their basket untouched and the minicart already correct.
            return $redirect->setPath('checkout');
        }

        $orderId = (int) $order->getId();

        try {
            $this->orderManagement->cancel($orderId);

            // Re-read rather than reusing the instance held above: cancel()
            // saved its own copy, and commenting on the stale one would write
            // the pre-cancel state back over it.
            $cancelled = $this->orderRepository->get($orderId);
            $cancelled->addCommentToStatusHistory(
                __('InstaPay: the customer left the transfer page without paying. The order was cancelled and their basket restored.'),
                false,
                false
            );
            $this->orderRepository->save($cancelled);

            // Reactivates the quote behind the order and clears
            // last_real_order_id, which is also what stops the success page
            // rendering a confirmation for an order that no longer stands.
            $this->checkoutSession->restoreQuote();

            // The quote is live again, so the browser's cached minicart is now
            // wrong in the shopper's favour and has to be told. Only this
            // branch needs it — see Save.php, which owns the same call for the
            // request that actually empties the basket.
            $this->refreshCustomerSections($cancelled);

            $this->messageManager->addSuccessMessage(
                __('Your payment was cancelled and your items are back in your basket.')
            );
        } catch (\Exception $e) {
            $this->logger->error('Spartrak InstaPay: could not cancel an abandoned transfer.', [
                'order_id'  => $orderId,
                'exception' => $e,
            ]);
            $this->messageManager->addErrorMessage(
                __('We could not cancel your payment. Please contact us and quote order %1.', $order->getIncrementId())
            );

            return $redirect->setPath('spartrak_instapay/transfer/index');
        }

        return $redirect->setPath('checkout/cart');
    }

    /**
     * Tell the browser its cached customer-data sections are wrong.
     *
     * See the same method on Save.php for why the cookie rather than
     * sections.xml alone: `section-config` has to canonise the posted URL, and
     * on this store the base URL carries a store code, which is exactly where
     * that matching is thin. This is the server saying it outright.
     *
     * Save.php also records why the VALUE is the store code and not `'1'`: the
     * reader JSON-parses the cookie, so `'1'` arrives as the number 1, and
     * underscore's `isEmpty` calls a number empty - which made the whole
     * mechanism a no-op. Both writers use the same value for the same reason.
     */
    private function refreshCustomerSections(Order $order): void
    {
        try {
            $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
                ->setHttpOnly(false)
                ->setDuration(15)
                ->setPath('/');

            $this->cookieManager->setPublicCookie(
                'section_data_clean',
                (string) $order->getStore()->getCode(),
                $metadata
            );
        } catch (\Exception $e) {
            $this->logger->warning('Spartrak InstaPay: could not flag the customer sections for refresh.', [
                'exception' => $e,
            ]);
        }
    }
}
