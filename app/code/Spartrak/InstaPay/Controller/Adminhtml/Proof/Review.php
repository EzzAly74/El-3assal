<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\InstaPay\Controller\Adminhtml\Proof;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Service\InvoiceService;
use Psr\Log\LoggerInterface;
use Spartrak\InstaPay\Api\Data\TransferInterface;
use Spartrak\InstaPay\Api\TransferRepositoryInterface;

/**
 * A member of staff decides whether the money arrived.
 *
 * ===========================================================================
 * THIS IS THE POINT WHERE THE ORDER BECOMES PAID
 * ===========================================================================
 * Nothing earlier in this flow may claim payment: the storefront never talks to
 * a bank, so until a person has matched the receipt against a statement, all
 * the store has is a screenshot. Approving here is that person recording their
 * judgement, and it is what raises the invoice.
 *
 * The invoice is created with CAPTURE_OFFLINE. The money moved outside Magento
 * - between two banking apps - so there is nothing online to capture, and
 * asking for an online capture on a method with no gateway would throw.
 *
 * ===========================================================================
 * REJECTION DOES NOT CANCEL THE ORDER
 * ===========================================================================
 * A rejected receipt usually means a shopper uploaded the wrong screenshot, not
 * that they are not paying. The order is left alone with a comment on it, so
 * they can be contacted and try again - and the rejected record stays, because
 * it is the reason the next one exists.
 *
 * Cancelling is a separate, deliberate act, and Magento already has a button
 * for it a few inches away.
 */
class Review extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Spartrak_InstaPay::proof';

    public function __construct(
        Context $context,
        private readonly RedirectFactory $redirectFactory,
        private readonly TransferRepositoryInterface $transferRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly InvoiceService $invoiceService,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->redirectFactory->create();
        $transferId = (int) $this->getRequest()->getParam('transfer_id');
        $decision = (string) $this->getRequest()->getParam('decision');

        if (!in_array($decision, [TransferInterface::STATUS_APPROVED, TransferInterface::STATUS_REJECTED], true)) {
            $this->messageManager->addErrorMessage(__('That is not a decision this receipt can be given.'));

            return $redirect->setPath('sales/order/index');
        }

        try {
            $transfer = $this->transferRepository->getById($transferId);
            $order = $this->orderRepository->get($transfer->getOrderId());

            if ($transfer->getStatus() !== TransferInterface::STATUS_PENDING) {
                // Two people opened the same order. Say so rather than silently
                // applying the second decision over the first.
                $this->messageManager->addNoticeMessage(
                    __('This receipt was already reviewed by %1.', $transfer->getReviewedBy() ?: __('someone else'))
                );

                return $this->backToOrder($redirect, $order);
            }

            $username = (string) ($this->_auth->getUser()?->getUserName() ?? '');

            $transfer->setStatus($decision)
                ->setReviewedBy($username)
                ->setReviewedAt($this->dateTime->gmtDate());
            $this->transferRepository->save($transfer);

            $decision === TransferInterface::STATUS_APPROVED
                ? $this->approve($order, $transfer, $username)
                : $this->reject($order, $transfer, $username);

            $this->messageManager->addSuccessMessage(
                $decision === TransferInterface::STATUS_APPROVED
                    ? __('The transfer was approved and the order has been invoiced.')
                    : __('The transfer was rejected. The order has not been changed.')
            );

            return $this->backToOrder($redirect, $order);
        } catch (\Exception $e) {
            $this->logger->error('Spartrak InstaPay: a receipt review failed.', [
                'transfer_id' => $transferId,
                'decision'    => $decision,
                'exception'   => $e,
            ]);
            $this->messageManager->addErrorMessage(
                __('We could not record that decision: %1', $e->getMessage())
            );

            return $redirect->setPath('sales/order/index');
        }
    }

    /**
     * @throws \Exception
     */
    private function approve(Order $order, TransferInterface $transfer, string $username): void
    {
        if ($order->canInvoice()) {
            $invoice = $this->invoiceService->prepareInvoice($order);
            // The money moved between two banking apps. There is no gateway to
            // ask, so the capture is recorded rather than performed.
            $invoice->setRequestedCaptureCase(Order\Invoice::CAPTURE_OFFLINE);
            $invoice->register();
            $order->addRelatedObject($invoice);
        }

        /**
         * =================================================================
         * THE ORDER HAS TO LEAVE `new`, AND ONLY THIS FLAG MOVES IT
         * =================================================================
         * Registering an invoice does NOT change the order's state. It records
         * the money and the items; the transition is a separate, deliberate
         * step, and Magento's own invoice controller
         * (Sales\Controller\Adminhtml\Order\Invoice\Save) takes it with exactly
         * this line:
         *
         *     $invoice->getOrder()->setIsInProcess(true);
         *
         * `Sales\Model\ResourceModel\Order::save()` calls
         * `Handler\State::check()`, which reads the flag and - for an order
         * whose state is still `new` - sets state `processing` and stamps that
         * state's DEFAULT status.
         *
         * Without it, approving a transfer invoiced the order and left it
         * sitting in `new` / `pending`: the admin's own status field still read
         * "Pending" beside fully invoiced items, and on the storefront
         * Spartrak\CustomerAccount\Model\OrderProgress read state `new` and
         * parked the shopper's tracker at `بانتظار الموافقة` forever - for an
         * order somebody had just approved. That is the bug this line fixes,
         * and it is why the moment of approval is also the moment the tracker
         * advances.
         *
         * SET UNCONDITIONALLY, not inside the `canInvoice()` branch above. An
         * order that cannot be invoiced (a zero total, an invoice already
         * raised by hand) has still just had its payment accepted by a person,
         * and that is what takes it out of `new`.
         *
         * THE STATUS IS THE MERCHANT'S TO NAME, not this module's. `check()`
         * assigns whatever is configured as the default status for
         * `processing`, so a merchant who wants `تم التعبئة` on approval sets it
         * in Stores > Order Status. Hardcoding one of Spartrak_PickupLocation's
         * four fulfilment statuses here would have this controller claim the
         * goods were packed, which approving a bank transfer says nothing
         * about - see Spartrak\PickupLocation\Model\DeliveryStatus for why none
         * of them is a state default in the first place.
         */
        $order->setIsInProcess(true);

        $order->addCommentToStatusHistory(
            __(
                'InstaPay: the transfer from %1 was approved by %2.',
                $transfer->getCustomerPhone(),
                $username ?: __('an administrator')
            ),
            false,
            false
        );

        $this->orderRepository->save($order);
    }

    private function reject(Order $order, TransferInterface $transfer, string $username): void
    {
        $order->addCommentToStatusHistory(
            __(
                'InstaPay: the transfer from %1 could not be matched to a payment and was rejected by %2. The order has been left open so the customer can be contacted.',
                $transfer->getCustomerPhone(),
                $username ?: __('an administrator')
            ),
            false,
            false
        );

        $this->orderRepository->save($order);
    }

    private function backToOrder(
        \Magento\Framework\Controller\Result\Redirect $redirect,
        Order $order
    ): ResultInterface {
        return $redirect->setPath('sales/order/view', ['order_id' => $order->getId()]);
    }
}
