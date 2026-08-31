<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\InstaPay\Block\Adminhtml\Order\View;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Registry;
use Magento\Sales\Model\Order;
use Spartrak\InstaPay\Api\Data\TransferInterface;
use Spartrak\InstaPay\Api\TransferRepositoryInterface;
use Spartrak\InstaPay\Model\Ui\ConfigProvider;

/**
 * The "InstaPay transfer" panel on an admin order view.
 *
 * Where a member of staff sees the receipt, compares it with the bank
 * statement, and approves or rejects it. It is the only place in the system
 * that turns a screenshot into a paid order.
 *
 * ===========================================================================
 * IT RENDERS NOTHING WITHOUT THE ACL
 * ===========================================================================
 * The check is here as well as on the controller, and both are needed for
 * different reasons: the controller stops the file being fetched, and this
 * stops the panel being drawn for a role that may not act on it. Showing a
 * disabled panel with an image nobody can load would be worse than not showing
 * it at all.
 */
class Transfer extends Template
{
    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly TransferRepositoryInterface $transferRepository,
        private readonly AuthorizationInterface $authorization,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getOrder(): ?Order
    {
        $order = $this->registry->registry('current_order');

        return $order instanceof Order ? $order : null;
    }

    /**
     * Whether this panel applies at all.
     *
     * Three conditions, and every one of them has to hold: the viewer is
     * allowed, there is an order, and it was actually paid with this method.
     * An order paid by card must not grow an InstaPay panel.
     */
    public function shouldRender(): bool
    {
        if (!$this->authorization->isAllowed('Spartrak_InstaPay::proof')) {
            return false;
        }

        $order = $this->getOrder();

        if ($order === null) {
            return false;
        }

        $payment = $order->getPayment();

        return $payment !== null && $payment->getMethod() === ConfigProvider::CODE;
    }

    /**
     * Every transfer submitted for this order, newest first.
     *
     * Plural on purpose: a rejected receipt is followed by another attempt, and
     * the rejected one stays visible because it is the reason the second one
     * exists.
     *
     * @return TransferInterface[]
     */
    public function getTransfers(): array
    {
        $order = $this->getOrder();

        return $order === null ? [] : $this->transferRepository->getByOrderId((int) $order->getId());
    }

    public function getProofUrl(TransferInterface $transfer): string
    {
        return $this->getUrl('spartrak_instapay/proof/view', [
            'transfer_id' => $transfer->getTransferId(),
        ]);
    }

    public function getReviewUrl(): string
    {
        return $this->getUrl('spartrak_instapay/proof/review');
    }

    public function isPending(TransferInterface $transfer): bool
    {
        return $transfer->getStatus() === TransferInterface::STATUS_PENDING;
    }

    /**
     * A translated, human label for a status - never the raw stored token.
     */
    public function getStatusLabel(TransferInterface $transfer): \Magento\Framework\Phrase
    {
        return match ($transfer->getStatus()) {
            TransferInterface::STATUS_APPROVED => __('Approved'),
            TransferInterface::STATUS_REJECTED => __('Rejected'),
            default => __('Awaiting review'),
        };
    }
}
