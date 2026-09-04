<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Checkout\ViewModel;

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
 * This is the same rule applied to the cart PAGE. The number is declared in
 * Spartrak_Checkout/etc/frontend/di.xml rather than written here, so it sits
 * beside the rest of the cart-line wiring and can be changed without touching
 * a class.
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
 * STOCK IS NOT CONSULTED HERE, AND THAT IS A CORRECTION
 * ===========================================================================
 * This class used to cap the list by the legacy stock item's `qty` and
 * `max_sale_qty`, read through Magento\CatalogInventory\Api\
 * StockRegistryInterface. Three things were wrong with that, and together they
 * are why the cart rendered `1` as dead text instead of a working control:
 *
 *   IT PRODUCED A ONE-OPTION LIST. The cap came back as 1 for the store's own
 *   catalogue, getFor() returned a single value, and the template's
 *   "one quantity is not a choice" branch printed plain text. The shopper had
 *   no way to change a quantity at all. A quantity control that cannot be
 *   operated is worse than a list that is optimistic by one.
 *
 *   THE API IS DEPRECATED. StockRegistryInterface and StockStateInterface both
 *   carry `@deprecated 100.3.0 Replaced with Multi Source Inventory` in 2.4.8.
 *   CLAUDE.md section 1 rules out APIs deprecated in or before 2.4.8, and the
 *   legacy single-source tables they read are not authoritative once MSI is
 *   installed - which it is, by default, on every 2.4.8.
 *
 *   IT COST A QUERY PER CART LINE. One getStockItem() per renderer, on a page
 *   whose whole job is to render N lines.
 *
 * Stock is still enforced, in the place that can report it: core's
 * checkout/cart/updatePost validates the posted quantity against the salable
 * amount and answers with a message on the line. That is the same division the
 * cart drawer already uses - it offers 1..maxQtyOptions with no stock lookup
 * whatsoever - so the two surfaces now agree instead of one being quietly
 * stricter than the other.
 */
class CartQtyOptions implements ArgumentInterface
{
    /**
     * @param int $maxOptions How many quantities to offer. Set in
     *                        etc/frontend/di.xml; the default is the same 10
     *                        the cart drawer uses, so a mis-wired DI degrades
     *                        to the drawer's behaviour rather than to zero
     *                        options.
     */
    public function __construct(
        private readonly int $maxOptions = 10
    ) {
    }

    public function getMaxOptions(): int
    {
        return $this->maxOptions > 0 ? $this->maxOptions : 10;
    }

    /**
     * The quantities to offer for one cart line, ascending.
     *
     * @return int[]
     */
    public function getFor(Item $item): array
    {
        $current = max(1, (int) round((float) $item->getQty()));

        // The shopper's own value still has to be listed even when it is above
        // the offered length, or the control would silently reduce it.
        return range(1, max($this->getMaxOptions(), $current));
    }

    /**
     * True when the control should offer more than the single value it holds.
     *
     * A one-option dropdown is a control that looks interactive and is not;
     * the template renders plain text instead. With stock out of the picture
     * this can now only happen if a merchant configures maxOptions to 1, which
     * is a deliberate choice rather than an accident of the catalogue.
     */
    public function isSelectable(Item $item): bool
    {
        return count($this->getFor($item)) > 1;
    }
}
