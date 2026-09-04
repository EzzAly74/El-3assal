<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Locale\Plugin;

use Magento\Directory\Model\Currency;

/**
 * SEAM 5 — NO TRAILING ".00".
 *
 * A whole price renders as `14,109 ج.م`, not `14,109.00 ج.م`. A price with real
 * fractions keeps them: `850.50`, `1,844.25`.
 *
 * ===========================================================================
 * WHY THIS IS A PRECISION DECISION AND NOT STRING SURGERY
 * ===========================================================================
 * The obvious implementation is to strip a trailing `.00` from the formatted
 * output. It is wrong in three ways at once: the decimal separator is
 * locale-dependent (this store pins Latin digits, but the module must not
 * assume its own other seams are in place), the currency symbol can sit on
 * either side of the number, and a right-to-left mark travels with it in
 * `ar_EG`. A regex over that is a bug waiting for a currency change.
 *
 * ICU already knows how to render a number with no fraction digits. So the
 * decision is made BEFORE formatting, by handing Magento the precision it
 * should use — which is what `$options['precision']` is for, and which
 * Currency::canUseNumberFormatter() explicitly allows through to the fast path.
 *
 * ===========================================================================
 * WHY formatTxt AND NOT formatPrecision
 * ===========================================================================
 * `formatPrecision()`, `format()` and `formatPrecision()`-via-PriceCurrency all
 * funnel into `formatTxt()`. Plugging the one convergence point covers the
 * pricing render layer (PDP, PLP, cart), `Order::formatPrice()`, order emails
 * and the admin, without a plugin per entry point that could later disagree.
 *
 * ===========================================================================
 * IT OVERRIDES AN EXPLICIT PRECISION, AND THAT IS DELIBERATE
 * ===========================================================================
 * Callers almost always arrive with `precision => 2`, because that is
 * PriceCurrency's default rather than an intention — so honouring an explicit
 * value would mean honouring the default and doing nothing. The store-wide rule
 * is "a whole price shows no decimals", and a rule with an opt-out that every
 * caller takes by accident is not a rule.
 *
 * The narrow cost: code that genuinely wants `14,109.0000` no longer gets it.
 * Nothing in this storefront asks for that, and a caller that ever does should
 * say so somewhere more visible than a default argument.
 */
class WholePricePrecision
{
    /**
     * @param Currency $subject
     * @param string|float|int|null $price
     * @param array<string, mixed> $options
     * @return array{0: mixed, 1: array<string, mixed>}
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeFormatTxt(Currency $subject, $price, $options = []): array
    {
        if (!is_array($options)) {
            $options = [];
        }

        if ($this->isWholeNumber($price)) {
            $options['precision'] = 0;
        }

        return [$price, $options];
    }

    /**
     * Has this amount no fractional part worth showing?
     *
     * Compared with a tolerance rather than `fmod(...) === 0.0`, because these
     * values arrive as floats from the database and 14109.000000000002 is a
     * whole price as far as a shopper is concerned. The tolerance is half of the
     * smallest unit two decimal places can express, so a real 0.01 is never
     * rounded away.
     *
     * @param string|float|int|null $price
     */
    private function isWholeNumber($price): bool
    {
        if ($price === null || $price === '' || !is_numeric($price)) {
            return false;
        }

        $amount = (float) $price;
        $fraction = abs($amount - round($amount));

        return $fraction < 0.005;
    }
}
