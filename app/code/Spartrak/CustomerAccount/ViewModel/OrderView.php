<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAccount\ViewModel;

use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Directory\Model\CountryFactory;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\Order;
use Spartrak\CustomerAccount\Model\OrderProgress;
use Spartrak\PickupLocation\ViewModel\OrderPickup;
use Spartrak\Shipping\Model\EstimatedArrival;

/**
 * Every fact the account section's order surfaces print, answered once.
 *
 * ===========================================================================
 * WHY ONE VIEW MODEL FOR TWO PAGES
 * ===========================================================================
 * The order-history card (Figma 562:17887) and the order page (562:18903) are
 * two renderings of the same order. They share the status chip, the estimated
 * arrival, the payment-method title, the ship-to line, the item rows and the
 * money formatting — the card is, almost exactly, the page with fewer panels.
 *
 * Two view models would mean two places to fix "the ship-to line says the wrong
 * thing for a depot order", and CLAUDE.md section 9 rules out duplicating
 * business logic across components. One class, consumed by both templates.
 *
 * ===========================================================================
 * IMAGES ARE LOADED ONCE PER ORDER, NOT ONCE PER LINE
 * ===========================================================================
 * A template asking `$viewModel->getItemImage($item)` per row is the natural
 * shape and the natural way to write N+1 queries. getItemImage() therefore
 * primes a per-order map on first call — ONE product collection for the whole
 * order, selecting only the image attributes — and every later row is an array
 * lookup. The map is keyed by order id so a history page listing five orders
 * issues five collection loads, not one per line across all of them.
 *
 * ===========================================================================
 * MONEY IS FORMATTED IN THE ORDER'S OWN CURRENCY
 * ===========================================================================
 * Not the store's current one. An order placed in EGP must still read in EGP
 * after the merchant adds a second currency, which is why every amount goes
 * through formatPrice() with the order's currency code rather than through a
 * bare PriceCurrencyInterface::format().
 *
 * `$includeContainer = false` because these strings land inside Spartrak's own
 * typography nodes. Order::formatPrice() would wrap them in Magento's
 * `<span class="price">`, which Porto styles — a second, unasked-for opinion
 * about the price ramp inside a node Figma has already specified.
 */
class OrderView implements ArgumentInterface
{
    /**
     * Declared in the Spartrak theme's etc/view.xml — 216px, i.e. 2x the
     * larger of the two slots that use it. See that file for the sizing.
     */
    private const IMAGE_ID = 'spartrak_account_order_item_thumbnail';

    /**
     * @var array<int, array<int, Product>> order id => product id => product
     */
    private array $productsByOrder = [];

    public function __construct(
        private readonly OrderProgress $progress,
        private readonly EstimatedArrival $estimatedArrival,
        private readonly OrderPickup $pickup,
        private readonly TimezoneInterface $timezone,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly ImageHelper $imageHelper,
        private readonly CountryFactory $countryFactory,
        private readonly PaymentHelper $paymentHelper
    ) {
    }

    // ------------------------------------------------------------------
    // Progress
    // ------------------------------------------------------------------

    public function getProgress(): OrderProgress
    {
        return $this->progress;
    }

    // ------------------------------------------------------------------
    // Dates
    // ------------------------------------------------------------------

    /**
     * "٣٠ يوليو ٢٠٢٦" — the estimated arrival, or null when none was promised.
     *
     * LONG date with no time: the merchant's promise is a day, and printing a
     * clock time next to it would imply a precision the window does not have.
     */
    public function getEstimatedArrival(OrderInterface $order): ?string
    {
        $date = $this->estimatedArrival->forOrder($order);

        if ($date === null) {
            return null;
        }

        return $this->timezone->formatDateTime(
            $date,
            \IntlDateFormatter::LONG,
            \IntlDateFormatter::NONE
        );
    }

    /**
     * When the order was placed, weekday included — Figma 562:19523 leads with
     * the day name because "الجمعة" is how a shopper remembers ordering.
     */
    public function getPlacedAt(OrderInterface $order): ?string
    {
        $createdAt = $order->getCreatedAt();

        if (!$createdAt) {
            return null;
        }

        return $this->timezone->formatDateTime(
            new \DateTime($createdAt, new \DateTimeZone('UTC')),
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::SHORT
        );
    }

    // ------------------------------------------------------------------
    // Status, payment, destination
    // ------------------------------------------------------------------

    public function getStatusLabel(OrderInterface $order): string
    {
        return $order instanceof Order
            ? (string) $order->getStatusLabel()
            : (string) $order->getStatus();
    }

    /**
     * The payment method's OWN detail block, rendered.
     *
     * ===================================================================
     * WHY THE VIEW MODEL RETURNS HTML HERE
     * ===================================================================
     * Every payment method in Magento ships a block that knows what to say
     * about a payment made with it — Spartrak_InstaPay's says the transfer is
     * being checked, a card method prints the masked number, cash on delivery
     * prints its instructions. `Payment\Helper\Data::getInfoBlockHtml()` is
     * the platform's own way to ask for it, and using it is what keeps this
     * panel working for a payment method nobody has written yet.
     *
     * The alternative was `$this->helper(...)` inside the template, which is
     * what core's own order templates do — and they suppress the
     * Magento2.Templates.ThisInTemplate sniff to do it. Returning the rendered
     * block from an injected dependency keeps the template free of both the
     * service locator and the suppression, the same trade this project already
     * made for the cart line's view models.
     */
    public function getPaymentInfoHtml(OrderInterface $order): string
    {
        $payment = $order->getPayment();

        if ($payment === null) {
            return '';
        }

        return (string) $this->paymentHelper->getInfoBlockHtml($payment, (int) $order->getStoreId());
    }

    /**
     * "الدفع عند الاستلام" — the payment method's own title, as the merchant
     * configured it. Never a hardcoded map from method code to label: the
     * titles are merchant content and are already translated per store view.
     */
    public function getPaymentTitle(OrderInterface $order): ?string
    {
        $payment = $order->getPayment();

        if ($payment === null) {
            return null;
        }

        $method = $payment->getMethodInstance();
        $title = (string) $method->getTitle();

        return $title !== '' ? $title : null;
    }

    /**
     * The one-line destination on an order card — "Nasr City, Cairo, Egypt"
     * (562:18003), or the collection point's name for a pickup order.
     *
     * A pickup order HAS a shipping address, and it is the customer's own home
     * address, so printing the address here would tell a shopper their parcel
     * is being delivered to them when they have agreed to collect it. The
     * pickup view model answers that question authoritatively.
     */
    public function getShipToSummary(OrderInterface $order): ?string
    {
        if ($this->pickup->isPickup($order)) {
            return $this->pickup->getName($order);
        }

        $address = $order->getShippingAddress();

        if ($address === null) {
            return null;
        }

        $parts = array_filter([
            $address->getCity(),
            $address->getRegion(),
            $this->countryName((string) $address->getCountryId()),
        ], static fn ($part): bool => is_string($part) && trim($part) !== '');

        return $parts !== [] ? implode(', ', $parts) : null;
    }

    /**
     * The heading above the shipping panel — "المنزل" in Figma (562:19555).
     *
     * Magento has no address nickname (the checkout modal Figma draws,
     * 557:5173, collects six fields and none of them is a label), so the
     * recipient's own name is the honest answer: it is what the shopper typed,
     * it is what appears on the parcel, and it distinguishes one saved address
     * from another when a household has several.
     */
    public function getShipToName(OrderInterface $order): ?string
    {
        if ($this->pickup->isPickup($order)) {
            return $this->pickup->getName($order);
        }

        $address = $order->getShippingAddress();

        return $address !== null ? trim((string) $address->getName()) ?: null : null;
    }

    /**
     * The full destination, one line per element, ready to print.
     *
     * @return string[]
     */
    public function getShipToLines(OrderInterface $order): array
    {
        /**
         * ===============================================================
         * A PICKUP SHOWS THE SNAPSHOT, AND FALLS BACK RATHER THAN VANISHING
         * ===============================================================
         * This used to return the snapshotted address ALONE, which meant an
         * empty array whenever the snapshot had not landed — and the template
         * skips the whole panel on an empty array. So an order the shopper is
         * collecting could render with no destination section at all: not
         * wrong-looking, just absent, which is the hardest kind of missing
         * thing to notice.
         *
         * The fallback is sound because of what the pickup address IS: the
         * shipping address on a pickup order was synthesised FROM the chosen
         * location by Plugin\Checkout\ApplyPickupLocation, so its street, city
         * and governorate already describe the branch or depot. Printing it is
         * printing the same place from the other copy.
         */
        if ($this->pickup->isPickup($order)) {
            $snapshot = array_values(array_filter([$this->pickup->getAddress($order)]));

            if ($snapshot !== []) {
                return $snapshot;
            }
        }

        $address = $order->getShippingAddress();

        if ($address === null) {
            return [];
        }

        $lines = array_map('trim', (array) $address->getStreet());
        $tail = array_filter([
            $address->getCity(),
            $address->getRegion(),
            $address->getPostcode(),
            $this->countryName((string) $address->getCountryId()),
        ], static fn ($part): bool => is_string($part) && trim($part) !== '');

        if ($tail !== []) {
            $lines[] = implode('، ', $tail);
        }

        return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
    }

    public function getShipToPhone(OrderInterface $order): ?string
    {
        $address = $order->getShippingAddress();

        if ($address === null) {
            return null;
        }

        $phone = trim((string) $address->getTelephone());

        return $phone !== '' ? $phone : null;
    }

    public function isPickup(OrderInterface $order): bool
    {
        return $this->pickup->isPickup($order);
    }

    /**
     * The destination panel's heading, in the words of this order's channel:
     * `عنوان الشحن` for a delivery, the branch for a branch collection, the
     * station for a depot one.
     *
     * THREE ANSWERS, WHERE THE TEMPLATE USED TO PICK BETWEEN TWO with
     * `$isPickup ? 'Collection point' : 'Shipping address'`. "Collection point"
     * covering both pickup kinds read as a euphemism on the depot channel,
     * where the place is a public transport station the shopper travels to and
     * not a counter of ours — and it meant the page never told them which of
     * the three they had chosen.
     */
    public function getDestinationHeading(OrderInterface $order): string
    {
        return (string) $this->pickup->getDestinationHeading($order);
    }

    /**
     * The chosen branch or depot BY NAME, for a pickup order.
     *
     * Null for a delivery (there is no named place) and null when the snapshot
     * is missing. It is shown above the address lines because on a pickup the
     * name is the fact the shopper navigates by — "موقف السلام" is what they
     * will say to a taxi driver, not the street.
     */
    public function getDestinationName(OrderInterface $order): ?string
    {
        return $this->pickup->isPickup($order) ? $this->pickup->getName($order) : null;
    }

    // ------------------------------------------------------------------
    // Items
    // ------------------------------------------------------------------

    /**
     * The lines a shopper should see.
     *
     * Child items of a configurable/bundle parent are excluded the way core's
     * own order templates exclude them — they are an implementation detail of
     * one purchase, not two purchases.
     *
     * @return OrderItemInterface[]
     */
    public function getVisibleItems(OrderInterface $order): array
    {
        $items = [];

        foreach ($order->getItems() ?? [] as $item) {
            if ($item->getParentItem() === null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    public function getItemCount(OrderInterface $order): int
    {
        return count($this->getVisibleItems($order));
    }

    /**
     * A ready-to-render image block for one line, or null when the product no
     * longer exists.
     *
     * Returning the helper rather than a URL string is what gives the template
     * the placeholder, the resized derivative and the intrinsic width/height
     * attributes — the last of which is what keeps these rows from shifting
     * layout as images arrive (CLAUDE.md section 11).
     */
    public function getItemImage(OrderItemInterface $item): ?ImageHelper
    {
        $product = $this->productFor($item);

        return $product !== null ? $this->imageHelper->init($product, self::IMAGE_ID) : null;
    }

    /**
     * Where "عرض المنتج" goes. Null for a product that has since been deleted
     * or disabled, so the template can drop the button rather than offer a 404.
     */
    public function getItemProductUrl(OrderItemInterface $item): ?string
    {
        $product = $this->productFor($item);

        return $product !== null ? $product->getProductUrl() : null;
    }

    /**
     * The line's own price, already multiplied by quantity.
     *
     * row_total_incl_tax rather than price: the card shows what this line cost,
     * and for a quantity of three that is not the unit price.
     */
    public function getItemRowTotal(OrderInterface $order, OrderItemInterface $item): string
    {
        return $this->formatPrice($order, (float) $item->getRowTotalInclTax());
    }

    /**
     * The struck-through original, when the line was discounted — Figma
     * 562:19483. Null when it was not, so the template prints one price.
     */
    public function getItemOriginalRowTotal(OrderInterface $order, OrderItemInterface $item): ?string
    {
        $discount = (float) $item->getDiscountAmount();

        if ($discount <= 0.0) {
            return null;
        }

        return $this->formatPrice($order, (float) $item->getRowTotalInclTax() + $discount);
    }

    // ------------------------------------------------------------------
    // Money
    // ------------------------------------------------------------------

    public function formatPrice(OrderInterface $order, float $amount): string
    {
        return (string) $this->priceCurrency->format(
            $amount,
            false,
            PriceCurrencyInterface::DEFAULT_PRECISION,
            $order->getStoreId(),
            $order->getOrderCurrencyCode()
        );
    }

    /**
     * "وفرت" — how much the order saved, as a positive number.
     *
     * Magento stores discount_amount negative. The template prints it with its
     * own minus sign in the design's green, so the sign is presentation and the
     * magnitude is the datum.
     */
    public function getSavings(OrderInterface $order): float
    {
        return abs((float) $order->getDiscountAmount());
    }

    public function hasSavings(OrderInterface $order): bool
    {
        return $this->getSavings($order) > 0.0;
    }

    public function isFreeShipping(OrderInterface $order): bool
    {
        return (float) $order->getShippingInclTax() <= 0.0;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function countryName(string $countryId): ?string
    {
        if ($countryId === '') {
            return null;
        }

        $name = $this->countryFactory->create()->loadByCode($countryId)->getName();

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * The catalogue product behind one order line, from the per-order map.
     */
    private function productFor(OrderItemInterface $item): ?Product
    {
        $orderId = (int) $item->getOrderId();
        $productId = (int) $item->getProductId();

        if ($productId === 0) {
            return null;
        }

        if (!isset($this->productsByOrder[$orderId])) {
            $this->productsByOrder[$orderId] = $this->loadProducts($item);
        }

        return $this->productsByOrder[$orderId][$productId] ?? null;
    }

    /**
     * One collection for the whole order.
     *
     * Reached through the item's own order so the map can be built from any
     * line without the template having to hand the order back in — the
     * templates iterate items, and asking them to pass both would be a worse
     * API for the sake of one property lookup.
     *
     * @return array<int, Product>
     */
    private function loadProducts(OrderItemInterface $item): array
    {
        $order = $item instanceof \Magento\Sales\Model\Order\Item ? $item->getOrder() : null;
        $productIds = [];

        foreach (($order?->getItems() ?? [$item]) as $orderItem) {
            $productId = (int) $orderItem->getProductId();

            if ($productId !== 0) {
                $productIds[$productId] = $productId;
            }
        }

        if ($productIds === []) {
            return [];
        }

        $collection = $this->productCollectionFactory->create();
        $collection->addIdFilter(array_values($productIds));
        // Only what the image helper reads. Selecting '*' here would pull every
        // attribute of every purchased product to render a thumbnail.
        $collection->addAttributeToSelect(['small_image', 'thumbnail', 'image', 'name', 'url_key']);
        $collection->setFlag('has_stock_status_filter', true);

        $products = [];

        foreach ($collection as $product) {
            $products[(int) $product->getId()] = $product;
        }

        return $products;
    }
}
