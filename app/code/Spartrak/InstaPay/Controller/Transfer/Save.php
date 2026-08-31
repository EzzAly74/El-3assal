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
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Spartrak\InstaPay\Api\Data\TransferInterface;
use Spartrak\InstaPay\Api\TransferRepositoryInterface;
use Spartrak\InstaPay\Model\ProofStorage;
use Spartrak\InstaPay\Model\TransferFactory;
use Spartrak\InstaPay\Model\Ui\ConfigProvider;

/**
 * Records the transfer the shopper says they made, then finishes the order.
 *
 * ===========================================================================
 * WHAT THIS DOES AND DOES NOT CLAIM
 * ===========================================================================
 * It does not verify anything. Nothing here talks to a bank, so the only honest
 * description of what is stored is "the customer says they sent this, and here
 * is their screenshot". The order therefore moves from `pending_payment` to
 * `new` - received, awaiting review - and NOT to processing or paid. A human
 * decides that, from the admin, having looked at the receipt.
 *
 * Getting this wrong in the other direction is how a store ships goods against
 * a screenshot of somebody else's transfer.
 *
 * ===========================================================================
 * THE ORDER IS ALREADY PLACED BY THE TIME WE GET HERE
 * ===========================================================================
 * The checkout creates it in `pending_payment` and redirects here. That order
 * of events is deliberate: the cart is converted and stock is reserved while
 * the shopper is making the transfer, so two people cannot pay for the last
 * one. An abandoned transfer leaves a pending_payment order, which is exactly
 * the state a merchant cancels or chases.
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
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->redirectFactory->create();
        $order = $this->checkoutSession->getLastRealOrder();

        if (!$order->getId()) {
            return $redirect->setPath('checkout/cart');
        }

        $payment = $order->getPayment();

        if ($payment === null || $payment->getMethod() !== ConfigProvider::CODE) {
            return $redirect->setPath('checkout/onepage/success');
        }

        try {
            $phone = $this->readPhone();
            $file = $this->request->getFiles('proof');
            $file = is_array($file) ? $file : [];

            $relativePath = $this->proofStorage->store($file);

            /** @var TransferInterface $transfer */
            $transfer = $this->transferFactory->create();
            $transfer->setOrderId((int) $order->getId())
                ->setQuoteId($order->getQuoteId() ? (int) $order->getQuoteId() : null)
                ->setCustomerPhone($phone)
                ->setProofPath($relativePath)
                ->setOriginalName(isset($file['name']) ? (string) $file['name'] : null)
                ->setFileSize(isset($file['size']) ? (int) $file['size'] : null)
                ->setStatus(TransferInterface::STATUS_PENDING);

            $this->transferRepository->save($transfer);
            $this->markOrderAwaitingReview($order, $phone);
        } catch (LocalizedException $e) {
            // The shopper can fix this - a missing file, a wrong format, a
            // number they did not type. Send them back to the form with the
            // reason rather than to a dead end.
            $this->messageManager->addErrorMessage($e->getMessage());

            return $redirect->setPath('spartrak_instapay/transfer/index');
        } catch (\Exception $e) {
            $this->logger->error('Spartrak InstaPay: could not record a transfer.', [
                'order_id'  => $order->getId(),
                'exception' => $e,
            ]);
            $this->messageManager->addErrorMessage(
                __('We could not record your transfer. Please try again, or contact us and quote order %1.', $order->getIncrementId())
            );

            return $redirect->setPath('spartrak_instapay/transfer/index');
        }

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
}
