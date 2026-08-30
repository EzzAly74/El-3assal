<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Checkout\ViewModel;

use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\Tax\Helper\Data as TaxHelper;

/**
 * The struck-through "was" price on a discounted cart line.
 *
 * ===========================================================================
 * WHY THIS IS NOT IN THE TEMPLATE
 * ===========================================================================
 * It is a price calculation - which tax basis to use, and whether there is a
 * discount at all. CLAUDE.md section 8 keeps that out of .phtml, and a view
 * model is where it belongs: the cart page and the checkout's order summary
 * both draw the same card and must not each grow their own copy.
 *
 * ===========================================================================
 * UNIT PRICE, NOT ROW TOTAL - AND THAT IS EVIDENCE, NOT PREFERENCE
 * ===========================================================================
 * Which of the two Figma's big navy price represents is not stated anywhere,
 * and the cart frame cannot settle it: every line there has quantity 1, where
 * the unit price and the row total are the same number.
 *
 * The CHECKOUT order-summary card settles it. In 554:13119 the second
 * "Card - Cart Product" carries a quantity badge of 2 (node 554:13245) and a
 * price of 850.50 - the same figure as the quantity-1 line above it. A row
 * total would read 1,701.00. So the price on this card is per unit, and the
 * "was" price below is per unit too.
 *
 * ===========================================================================
 * THE TAX BASIS FOLLOWS CORE'S, IT IS NOT CHOSEN HERE
 * ===========================================================================
 * The live price beside this one is rendered by Magento's own unit-price
 * renderer, which honours the store's cart price display setting. If this
 * class picked a different basis, a store showing prices including tax would
 * strike through an ex-tax figure next to an inc-tax one - two numbers on the
 * same line measured differently, which reads as a pricing bug.
 *
 * So the same helper core's renderer consults is consulted here, and the tax is
 * applied from the ITEM'S OWN tax percent rather than recalculated.
 */
class CartItemPricing implements ArgumentInterface
{
    public function __construct(
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly TaxHelper $taxHelper
    ) {
    }

    /**
     * True when this line is cheaper than its catalogue price.
     */
    public function hasDiscount(AbstractItem $item): bool
    {
        return $this->originalUnitPrice($item) !== null;
    }

    /**
     * The formatted catalogue price, or null when the line is not discounted.
     *
     * Returns a FORMATTED string because the only consumer is a template, and
     * formatting is where Spartrak_Locale's Latin-digit rule applies - going
     * through PriceCurrencyInterface is what keeps this price rendering in the
     * same numerals as every other price on the page.
     */
    public function getOriginalUnitPriceHtml(AbstractItem $item): ?string
    {
        $price = $this->originalUnitPrice($item);

        if ($price === null) {
            return null;
        }

        return $this->priceCurrency->format($price, false);
    }

    /**
     * The catalogue unit price, on the same tax basis core is displaying.
     */
    private function originalUnitPrice(AbstractItem $item): ?float
    {
        $original = (float) $item->getOriginalPrice();
        $actual = (float) $item->getPrice();

        // A hundredth of a currency unit. Comparing floats with > alone would
        // strike through a price identical to the one beside it whenever a
        // rounding artefact put them a fraction apart.
        if ($original - $actual < 0.01) {
            return null;
        }

        if (!$this->taxHelper->displayCartPriceInclTax() && !$this->taxHelper->displayCartBothPrices()) {
            return $original;
        }

        // The item's own rate, not a recalculation: the tax engine has already
        // decided what applies to this product for this address, and second
        // guessing it here is how a template ends up disagreeing with the
        // totals block.
        $taxPercent = (float) $item->getTaxPercent();

        return $taxPercent > 0
            ? $original * (1 + $taxPercent / 100)
            : $original;
    }
}
