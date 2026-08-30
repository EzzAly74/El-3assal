<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Checkout\ViewModel;

use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Quote\Model\Quote\Item;

/**
 * The option list behind the cart page's quantity dropdown.
 *
 * ===========================================================================
 * WHY A DROPDOWN AT ALL, AND WHY TEN
 * ===========================================================================
 * Figma draws the desktop cart quantity as a dropdown (820:16439, "Tab -
 * Option" 83x48). This project ALREADY made that decision once, for the cart
 * drawer: see the theme's Magento_Checkout/layout/default.xml, which sets
 * `maxQtyOptions: 10` on the minicart item renderer and explains that the
 * length of a control is presentation, not merchant content.
 *
 * This is the same rule applied to the cart PAGE, and the number is read from
 * layout XML for the same reason rather than written here - so the two
 * surfaces cannot drift and neither carries a bare literal.
 *
 * ===========================================================================
 * A SHOPPER'S OWN QUANTITY IS NEVER DROPPED FROM THE LIST
 * ===========================================================================
 * The rule that matters. If a line already holds 24 and the dropdown offers
 * 1-10, then rendering the control at all would silently show "1" - and the
 * next save would rewrite a shopper's cart to a quantity they never chose.
 * getFor() therefore always includes the current quantity, extending the list
 * when it has to. The drawer's own mixin
 * (web/js/spartrak-minicart-qty-options-mixin.js) exists for exactly this, and
 * this is the server-side half of the same guarantee.
 *
 * ===========================================================================
 * STOCK IS A CEILING, NOT A SUGGESTION
 * ===========================================================================
 * The list is also capped by what can actually be bought - the stock item's
 * max_sale_qty and, when backorders are off, the salable quantity. Offering a
 * quantity that will be rejected on the next page is a worse experience than a
 * short list.
 */
class CartQtyOptions implements ArgumentInterface
{
    /**
     * Fallback only. The real value comes from layout XML - see the class
     * header - and this exists so a mis-wired layout degrades to the same
     * length the cart drawer uses rather than to zero options.
     */
    private const DEFAULT_MAX_OPTIONS = 10;

    private int $maxOptions = self::DEFAULT_MAX_OPTIONS;

    public function __construct(
        private readonly StockRegistryInterface $stockRegistry
    ) {
    }

    /**
     * Set from layout XML via the block's `qty_options_view_model` argument.
     */
    public function setMaxOptions(int $maxOptions): void
    {
        if ($maxOptions > 0) {
            $this->maxOptions = $maxOptions;
        }
    }

    public function getMaxOptions(): int
    {
        return $this->maxOptions;
    }

    /**
     * The quantities to offer for one cart line, ascending.
     *
     * @return int[]
     */
    public function getFor(Item $item): array
    {
        $current = (int) round((float) $item->getQty());
        $current = max($current, 1);

        $limit = min($this->maxOptions, $this->purchasableCeiling($item));

        // The ceiling can legitimately come back BELOW the current quantity -
        // stock may have fallen since the item was added. The shopper's own
        // value still has to be listed, or the control would silently reduce
        // it; the checkout is where a genuine stock problem gets reported, with
        // a message, rather than here by quietly editing the cart.
        $limit = max($limit, $current, 1);

        return range(1, $limit);
    }

    /**
     * True when the control should offer more than the single value it holds.
     *
     * A one-option dropdown is a control that looks interactive and is not;
     * the template renders plain text instead.
     */
    public function isSelectable(Item $item): bool
    {
        return count($this->getFor($item)) > 1;
    }

    /**
     * How many of this product may be in one order.
     */
    private function purchasableCeiling(Item $item): int
    {
        $product = $item->getProduct();

        if ($product === null) {
            return $this->maxOptions;
        }

        try {
            $stockItem = $this->stockRegistry->getStockItem(
                (int) $product->getId(),
                (int) $product->getStore()->getWebsiteId()
            );
        } catch (\Exception) {
            // No stock record (a virtual/downloadable product, or a custom
            // stock implementation). The merchant cap is then the only limit
            // there is, which is the right answer rather than an error.
            return $this->maxOptions;
        }

        $ceiling = (int) $stockItem->getMaxSaleQty();

        if ($ceiling <= 0) {
            $ceiling = $this->maxOptions;
        }

        // Backorders on means stock is not a ceiling at all.
        if ((int) $stockItem->getBackorders() === 0 && $stockItem->getManageStock()) {
            $available = (int) $stockItem->getQty();

            if ($available > 0) {
                $ceiling = min($ceiling, $available);
            }
        }

        return $ceiling;
    }
}
