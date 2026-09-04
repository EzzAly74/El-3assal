<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\InstaPay\Controller\Transfer;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Spartrak\InstaPay\Api\Data\TransferInterface;
use Spartrak\InstaPay\Api\TransferRepositoryInterface;
use Spartrak\InstaPay\Model\ProofStorage;
use Spartrak\InstaPay\Model\TransferFactory;
use Spartrak\InstaPay\Model\Ui\ConfigProvider;

/**
 * Records the transfer the shopper says they made, AND places the order.
 *
 * ===========================================================================
 * THE ORDER IS CREATED HERE. IT DID NOT USED TO BE.
 * ===========================================================================
 * The checkout used to create the order in `pending_payment` and redirect to
 * the transfer page, so an order existed the moment the shopper glanced at this
 * form - and pressing back cancelled it. The sales grid filled with orders
 * nobody placed and orders nobody cancelled on purpose.
 *
 * Now the quote survives all the way to this request and the order is created
 * from it once there is a receipt to attach. Opening the page costs nothing;
 * leaving it costs nothing; the basket is still the shopper's until they
 * actually submit.
 *
 * ===========================================================================
 * THE ORDER OF OPERATIONS IS THE SAFETY ARGUMENT
 * ===========================================================================
 *   1. read and validate the phone number
 *   2. validate and STORE the proof file
 *   3. place the order
 *   4. save the transfer row against it, and comment the order
 *
 * Steps 1-2 are the ones a shopper can get wrong, and they happen BEFORE any
 * order exists - so a bad file or a missing number sends them back to the form
 * with nothing created behind them.
 *
 * Step 3 before step 4 because the transfer row is keyed on an order id, so
 * there is nothing to key it to until the order exists. If 4 fails after 3
 * succeeded the shopper has a real order with no proof attached: that is logged
 * at error level with the increment id, and it is recoverable by a human, which
 * is the least bad of the three orderings.
 *
 * ===========================================================================
 * WHAT THIS STILL DOES NOT CLAIM
 * ===========================================================================
 * It does not verify anything. Nothing here talks to a bank, so the only honest
 * description of what is stored is "the customer says they sent this, and here
 * is their screenshot". The order is moved to `new` - received, awaiting review
 * - and NOT to processing or paid. A human decides that, from the admin, having
 * looked at the receipt. Getting this wrong in the other direction is how a
 * store ships goods against a screenshot of somebody else's transfer.
 *
 * ===========================================================================
 * CSRF
 * ===========================================================================
 * A plain POST action, so Magento's own form-key validation applies with no
 * opt-out. The form in transfer.phtml carries the key. Nothing here implements
 * CsrfAwareActionInterface, because there is no reason to weaken it.
 */
class Save implements HttpPostActionInterface
{
    /**
     * Egyptian mobile numbers are 11 digits and begin 01. Kept loose enough for
     * an international form (+20...) by validating digits rather than a strict
     * pattern - a shopper whose InstaPay is registered abroad still has to be
     * able to tell us the number they sent from.
     */
    private const PHONE_MIN_DIGITS = 8;
    private const PHONE_MAX_DIGITS = 20;

    public function __construct(
        private readonly RequestInterface $request,
        private readonly CheckoutSession $checkoutSession,
        private readonly RedirectFactory $redirectFactory,
        private readonly MessageManager $messageManager,
        private readonly ProofStorage $proofStorage,
        private readonly TransferFactory $transferFactory,
        private readonly TransferRepositoryInterface $transferRepository,
        private readonly CartManagementInterface $cartManagement,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->redirectFactory->create();
        $quote = $this->checkoutSession->getQuote();

        // The same three questions Index.php asks, asked again. A guard on the
        // page that renders a form is not a guard on the endpoint it posts to.
        if (!$quote->getId() || (int) $quote->getItemsCount() === 0) {
            return $redirect->setPath('checkout/cart');
        }

        $payment = $quote->getPayment();

        if ($payment === null || $payment->getMethod() !== ConfigProvider::CODE) {
            return $redirect->setPath('checkout');
        }

        try {
            // 1 + 2 — everything the shopper can get wrong, before anything is
            // created.
            $phone = $this->readPhone();
            $file = $this->request->getFiles('proof');
            $file = is_array($file) ? $file : [];

            $relativePath = $this->proofStorage->store($file);
        } catch (LocalizedException $e) {
            // A missing file, a wrong format, a number they did not type. Back
            // to the form with the reason, and no order behind them.
            $this->messageManager->addErrorMessage($e->getMessage());

            return $redirect->setPath('spartrak_instapay/transfer/index');
        }

        try {
            // 3 — placeOrder() also writes last_real_order_id and friends onto
            // the checkout session (Magento\Quote\Model\QuoteManagement), which
            // is what lets the success page render.
            $orderId = (int) $this->cartManagement->placeOrder((int) $quote->getId());
            $order = $this->orderRepository->get($orderId);
        } catch (LocalizedException $e) {
            /**
             * The basket changed under them while they were in their banking
             * app: something went out of stock, a price rule expired, the
             * address stopped being deliverable.
             *
             * THIS IS THE COST OF PLACING LATE, AND IT IS SAID OUT LOUD. The
             * shopper may already have transferred the money. The message names
             * that possibility rather than pretending the click simply failed,
             * because someone who has paid needs to contact the merchant rather
             * than try again and pay twice. The proof file is already stored, so
             * support has something to match against.
             */
            $this->logger->error('Spartrak InstaPay: could not place an order for an uploaded transfer.', [
                'quote_id'   => $quote->getId(),
                'proof_path' => $relativePath,
                'exception'  => $e,
            ]);
            $this->messageManager->addErrorMessage(
                __(
                    'We could not complete your order: %1. If you have already sent the transfer, '
                    . 'please contact us before trying again so we do not take the payment twice.',
                    $e->getMessage()
                )
            );

            return $redirect->setPath('spartrak_instapay/transfer/index');
        } catch (\Exception $e) {
            $this->logger->error('Spartrak InstaPay: could not place an order for an uploaded transfer.', [
                'quote_id'   => $quote->getId(),
                'proof_path' => $relativePath,
                'exception'  => $e,
            ]);
            $this->messageManager->addErrorMessage(
                __(
                    'We could not complete your order. If you have already sent the transfer, '
                    . 'please contact us before trying again so we do not take the payment twice.'
                )
            );

            return $redirect->setPath('spartrak_instapay/transfer/index');
        }

        try {
            // 4 — the order exists, so the transfer row finally has something
            // to belong to.
            /** @var TransferInterface $transfer */
            $transfer = $this->transferFactory->create();
            $transfer->setOrderId($orderId)
                ->setQuoteId((int) $quote->getId())
                ->setCustomerPhone($phone)
                ->setProofPath($relativePath)
                ->setOriginalName(isset($file['name']) ? (string) $file['name'] : null)
                ->setFileSize(isset($file['size']) ? (int) $file['size'] : null)
                ->setStatus(TransferInterface::STATUS_PENDING);

            $this->transferRepository->save($transfer);
            $this->markOrderAwaitingReview($order, $phone);
        } catch (\Exception $e) {
            /**
             * The order is real and paid for as far as the shopper is
             * concerned, so this does NOT send them back to the form - doing so
             * would invite a second order. It is logged loudly with the
             * increment id and the file that is sitting on disk, which is
             * everything a human needs to attach it by hand.
             */
            $this->logger->critical('Spartrak InstaPay: order placed but its transfer could not be recorded.', [
                'order_id'     => $orderId,
                'increment_id' => $order->getIncrementId(),
                'proof_path'   => $relativePath,
                'phone'        => $phone,
                'exception'    => $e,
            ]);
        }

        $this->refreshCustomerSections($order);

        return $redirect->setPath('checkout/onepage/success');
    }

    /**
     * @throws LocalizedException
     */
    private function readPhone(): string
    {
        $phone = trim((string) $this->request->getParam('customer_phone', ''));
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) < self::PHONE_MIN_DIGITS || strlen($digits) > self::PHONE_MAX_DIGITS) {
            throw new LocalizedException(
                __('Please enter the phone number registered with your InstaPay account.')
            );
        }

        return $phone;
    }

    /**
     * Move the order out of `pending_payment` and say why, on the record.
     *
     * The comment is part of the order's own history, so the next person to
     * open it - support, accounts, a different shift - can see what happened
     * without knowing this module exists. The phone number is included because
     * it is the single field a reviewer matches against the bank statement.
     *
     * `false` for both notify and visible-on-front: the customer has just been
     * told on screen, and an email saying "we have your screenshot" before
     * anyone has looked at it invites them to read it as confirmation of
     * payment.
     */
    private function markOrderAwaitingReview(Order $order, string $phone): void
    {
        $order->setState(Order::STATE_NEW)
            ->setStatus($order->getConfig()->getStateDefaultStatus(Order::STATE_NEW));

        $order->addCommentToStatusHistory(
            __('InstaPay: the customer submitted a transfer receipt from %1. Awaiting review.', $phone),
            false,
            false
        );

        $this->orderRepository->save($order);
    }

    /**
     * Tell the browser its cached customer-data sections are wrong.
     *
     * ===================================================================
     * THIS MOVED HERE FROM Cancel.php, AND THAT IS THE POINT
     * ===================================================================
     * The minicart, its counter and the drawer are served from `customer-data`,
     * a localStorage cache. It has to be invalidated whenever the basket
     * changes underneath it - and with the order now placed HERE, this is the
     * only request in the InstaPay flow that empties it. Cancel no longer
     * touches the quote at all, so it no longer needs to say anything.
     *
     * `section_data_clean` is the server's own signal and cannot miss:
     * `customer-data.init()` reads the cookie, reloads every section, clears
     * it. Magento sets the same cookie when a shopper switches store view -
     * the same situation, a browser holding data for a state that no longer
     * exists. It is belt-and-braces over etc/frontend/sections.xml, which
     * declares the same thing but depends on `section-config` canonising a URL
     * that on this store carries a store code.
     *
     * Without it the shopper reaches the success page with the old basket still
     * in the header, and only an add-to-cart shakes it loose - which is exactly
     * the symptom this flow was reported with.
     *
     * ===================================================================
     * THE VALUE IS THE STORE CODE, AND IT HAS TO BE SOMETHING LIKE IT
     * ===================================================================
     * This cookie used to be set to the string `'1'`, and that silently did
     * nothing at all - which is why the drawer kept the items after an InstaPay
     * order and only emptied when the shopper opened the cart page (the cart
     * page is rendered server-side from the real quote; the drawer is served
     * from the localStorage cache this cookie exists to invalidate).
     *
     * The reader is `customer-data.js`:
     *
     *     if (!_.isEmpty($.cookieStorage.get('section_data_clean'))) { ... }
     *
     * `$.cookieStorage.get()` JSON-parses the cookie and falls back to the raw
     * string only when the parse throws (js-storage's `js.storage.js`). `"1"`
     * parses cleanly - to the NUMBER 1. And underscore's `isEmpty` on a number
     * returns TRUE: it has no `length`, so `isEmpty` falls through to
     * `keys(1).length === 0`. So the guard read "empty", the reload never ran,
     * and the only symptom was a stale cart.
     *
     * Magento's own writer of this cookie - Magento\Store\Controller\Store\
     * SwitchAction\CookieManager - stores the target store's CODE, e.g. `ar`,
     * which does not parse as JSON and therefore survives as a non-empty
     * string. This does the same thing, with the code of the store the order
     * was placed in. Any non-numeric marker would work; matching the platform's
     * own value means the next person to read this file finds one convention,
     * not two.
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
            // A cookie that could not be set is not a reason to fail an order
            // that has already been placed. The worst case is a stale counter
            // until the shopper's next navigation.
            $this->logger->warning('Spartrak InstaPay: could not flag the customer sections for refresh.', [
                'exception' => $e,
            ]);
        }
    }
}
