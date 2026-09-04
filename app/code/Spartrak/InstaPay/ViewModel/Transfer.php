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

    /**
     * The order this transfer belongs to, IF there is one.
     *
     * ===================================================================
     * ON THE NORMAL PATH THERE IS NOT, AND THAT IS THE CHANGE
     * ===================================================================
     * The order used to be created before this page rendered. It is now created
     * by Controller\Transfer\Save when the receipt is uploaded, so while this
     * page is on screen there is a live quote and nothing else.
     *
     * The method is kept, and kept honest by returning null, because a legacy
     * order from the previous behaviour can still be in the session and the
     * template's order-number line is written to print nothing when there is
     * no number yet.
     */
    public function getOrder(): ?OrderInterface
    {
        if (!$this->orderResolved) {
            $this->orderResolved = true;
            $order = $this->checkoutSession->getLastRealOrder();
            $this->order = $order->getId() ? $order : null;
        }

        return $this->order;
    }

    /**
     * The scope the merchant's banking details are read in.
     *
     * ===================================================================
     * THE QUOTE, NOT THE ORDER
     * ===================================================================
     * This asked the order, and on the normal path the order does not exist
     * yet - so it returned null and getMerchantNumber() fell back to the
     * DEFAULT scope. On a single-store install that is the same value by luck;
     * on a multi-store one it is the wrong merchant's number on the page a
     * shopper is about to send money to.
     *
     * The quote carries the store the shopper is checking out in and is present
     * for the whole life of this page, so it is the right thing to ask. The
     * order is still preferred when there is one - the legacy path - because an
     * order's store is a fact and a session's quote is a state.
     */
    private function getStoreId(): ?int
    {
        $order = $this->getOrder();

        if ($order !== null) {
            return (int) $order->getStoreId();
        }

        $quote = $this->checkoutSession->getQuote();

        return $quote->getId() ? (int) $quote->getStoreId() : null;
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
