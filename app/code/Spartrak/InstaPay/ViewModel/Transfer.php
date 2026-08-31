<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\InstaPay\ViewModel;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Spartrak\InstaPay\Model\Config;
use Spartrak\InstaPay\Model\ProofStorage;

/**
 * What the InstaPay transfer page needs to render - Figma 586:7420.
 *
 * The merchant's number and masked name come from configuration, not from this
 * class and certainly not from the template: they are the merchant's own
 * banking details and a hardcoded one would be a number some shopper eventually
 * sends real money to.
 */
class Transfer implements ArgumentInterface
{
    private ?OrderInterface $order = null;
    private bool $orderResolved = false;

    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly Config $config,
        private readonly ProofStorage $proofStorage
    ) {
    }

    public function getOrder(): ?OrderInterface
    {
        if (!$this->orderResolved) {
            $this->orderResolved = true;
            $order = $this->checkoutSession->getLastRealOrder();
            $this->order = $order->getId() ? $order : null;
        }

        return $this->order;
    }

    private function getStoreId(): ?int
    {
        $order = $this->getOrder();

        return $order !== null ? (int) $order->getStoreId() : null;
    }

    /**
     * The number a shopper transfers TO.
     */
    public function getMerchantNumber(): string
    {
        return $this->config->getMerchantNumber($this->getStoreId());
    }

    /**
     * The masked account name InstaPay shows against that number.
     *
     * A merchant is asked to enter it already masked (see the field's comment in
     * system.xml), because its only job is to let a shopper confirm the account
     * matches before they send - and the full name is not needed for that.
     */
    public function getMerchantName(): string
    {
        return $this->config->getMerchantName($this->getStoreId());
    }

    public function getOrderNumber(): string
    {
        $order = $this->getOrder();

        return $order !== null ? (string) $order->getIncrementId() : '';
    }

    /**
     * The `accept` attribute for the file input, e.g. `.jpg,.jpeg,.heic`.
     *
     * Built from the same list the server enforces, so the picker cannot offer
     * a type the upload will then refuse. It is a convenience, not a control:
     * ProofStorage re-checks the actual bytes regardless.
     */
    public function getAcceptAttribute(): string
    {
        return implode(',', array_map(
            static fn (string $extension): string => '.' . $extension,
            $this->proofStorage->getAllowedExtensions()
        ));
    }

    /**
     * The same list in the words Figma prints under the dropzone
     * (586:13015): `JPG,JPEG,HIECH`.
     */
    public function getAllowedFormatsLabel(): string
    {
        return implode(', ', array_map('strtoupper', $this->proofStorage->getAllowedExtensions()));
    }

    public function getMaxUploadMegabytes(): int
    {
        return (int) (ProofStorage::MAX_BYTES / 1024 / 1024);
    }
}
