<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Checkout\ViewModel;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;
use Spartrak\PickupLocation\ViewModel\OrderPickup;

/**
 * Everything the order-success card shows - Figma 554:12434.
 *
 * ===========================================================================
 * WHY A VIEW MODEL AND NOT THE SUCCESS BLOCK
 * ===========================================================================
 * Magento\Checkout\Block\Onepage\Success answers exactly two questions: what is
 * the order id, and can this visitor view the order. Figma's card needs the
 * line items with their images, the payment method's title, and either a
 * shipping address or a pickup location.
 *
 * Subclassing the block to add those would have meant a template bound to a
 * class it also has to instantiate. A view model keeps core's block exactly as
 * it is - still resolving the order id, still deciding what a guest may see -
 * and adds the rest alongside it (CLAUDE.md section 8).
 *
 * ===========================================================================
 * THE ORDER COMES FROM THE SESSION, ONCE
 * ===========================================================================
 * getLastRealOrder() is the only supported way to reach the just-placed order
 * on this page, and it is the same source core's own block uses. It is loaded
 * once and memoised: this class is asked six or seven questions while the card
 * renders, and each one hitting the database would be six or seven queries for
 * one row.
 *
 * A visitor who reloads the success page after the session has moved on gets an
 * order with no id. Every accessor below tolerates that and returns an empty
 * value, so the page degrades to Magento's own "your order has been received"
 * rather than a 500 - which is what a shopper hitting back and forward will
 * actually do.
 */
class OrderSuccess implements ArgumentInterface
{
    private ?OrderInterface $order = null;
    private bool $orderResolved = false;

    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ImageHelper $imageHelper,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly OrderPickup $orderPickup
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

    /**
     * The visible order number - `increment_id`, not the row id.
     *
     * Figma's card does not print it. It is rendered anyway, because a shopper
     * needs the reference they will quote on the phone, and because Magento's
     * own success page has always shown it. The divergence is deliberate and is
     * called out in the template.
     */
    public function getOrderNumber(): string
    {
        $order = $this->getOrder();

        return $order !== null ? (string) $order->getIncrementId() : '';
    }

    /**
     * Visible line items, in order.
     *
     * Child items of a configurable or bundle are skipped: the parent already
     * says what was bought, and listing both shows the same product twice.
     * That is core's own rule for the order view, applied here.
     *
     * @return array<int, array{name: string, qty: int, price: string, image: string}>
     */
    public function getItems(): array
    {
        $order = $this->getOrder();

        if ($order === null || !$order instanceof Order) {
            return [];
        }

        $items = [];

        /** @var Item $item */
        foreach ($order->getAllVisibleItems() as $item) {
            $items[] = [
                'name'  => (string) $item->getName(),
                // Ordered quantities are floats on the model (weighted goods
                // are legal); the badge shows a whole number.
                'qty'   => (int) round((float) $item->getQtyOrdered()),
                'price' => $this->priceCurrency->format(
                    (float) $item->getRowTotalInclTax() ?: (float) $item->getRowTotal(),
                    false,
                    PriceCurrencyInterface::DEFAULT_PRECISION,
                    $order->getStoreId(),
                    $order->getOrderCurrencyCode()
                ),
                'image' => $this->thumbnailFor($item),
            ];
        }

        return $items;
    }

    /**
     * The catalog thumbnail, at the 108px the card renders it at.
     *
     * Asking the image helper for the exact rendered size is what stops the
     * page downloading a 1000px original to paint a 108px tile (CLAUDE.md
     * section 11). 216 is that size at 2x, for a retina screen.
     *
     * A product deleted since the order was placed returns the placeholder
     * rather than throwing - an old order must still render.
     */
    private function thumbnailFor(Item $item): string
    {
        try {
            $product = $this->productRepository->getById(
                (int) $item->getProductId(),
                false,
                (int) $item->getStoreId()
            );
        } catch (NoSuchEntityException) {
            return (string) $this->imageHelper->getDefaultPlaceholderUrl('thumbnail');
        }

        return (string) $this->imageHelper
            ->init($product, 'product_thumbnail_image')
            ->resize(216, 216)
            ->getUrl();
    }

    /**
     * The payment method's own title, as configured.
     *
     * NEVER a hardcoded label. Figma's mobile success frame (687:18900) shows
     * `الدفع عند الاستلام` and its desktop frame shows `انستا باي` - two
     * different methods on two mocks of the same screen, which is precisely why
     * this has to come from the order.
     */
    public function getPaymentTitle(): string
    {
        $order = $this->getOrder();

        if ($order === null) {
            return '';
        }

        $payment = $order->getPayment();

        if ($payment === null) {
            return '';
        }

        $method = $payment->getMethodInstance();

        return (string) ($method->getTitle() ?: $payment->getMethod());
    }

    /**
     * True when the order is being collected rather than delivered.
     */
    public function isPickup(): bool
    {
        return $this->orderPickup->isPickup($this->getOrder());
    }

    /**
     * The row label - `الشحن إلى` for a delivery, or the pickup equivalent.
     */
    public function getDestinationLabel(): \Magento\Framework\Phrase
    {
        return $this->isPickup() ? __('Collect from') : __('Shipping to');
    }

    /**
     * Where the order is going, on one line.
     *
     * For a pickup that is the branch or depot the shopper chose, read from the
     * order's own snapshot of it - so an order still prints the branch it was
     * placed against even after that branch has been renamed.
     */
    public function getDestination(): string
    {
        $order = $this->getOrder();

        if ($order === null) {
            return '';
        }

        if ($this->isPickup()) {
            return (string) $this->orderPickup->getLabel($order);
        }

        $address = $order->getShippingAddress();

        if ($address === null) {
            // A virtual order has no shipping address at all, and that is not
            // an error - there is simply nothing to say.
            return '';
        }

        $parts = array_filter([
            implode(', ', array_filter((array) $address->getStreet())),
            $address->getCity(),
            $address->getRegion(),
            $address->getCountryId(),
        ]);

        return implode(', ', $parts);
    }
}
