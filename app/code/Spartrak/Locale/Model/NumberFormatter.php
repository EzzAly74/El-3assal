<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Locale\Model;

/**
 * Seam 1 of 3: the formatter behind every server-rendered price.
 *
 * `Magento\Directory\Model\Currency::getNumberFormatter()` builds its ICU locale
 * as `$localeResolver->getLocale() . '@currency=' . $code` — e.g.
 * `ar_EG@currency=EGP` — and hands it to `NumberFormatterFactory`. That factory
 * is GENERATED, so there is no source file to plug; but a generated factory
 * still resolves its instance through the object manager, which means a
 * preference on the class it builds is picked up. This is that preference.
 *
 * Everything a shopper sees as a price on a server-rendered page arrives here:
 * the cart, the minicart's customer-data payload, checkout totals, order
 * confirmation emails, the admin's own order views.
 *
 * WHY NOT PLUG THE RESOLVER INSTEAD. The obvious-looking single fix is a plugin
 * on `Magento\Framework\Locale\ResolverInterface::getLocale()` returning
 * `ar_EG@numbers=latn`. It would break the store. `Magento\Framework\Translate`
 * uses that same return value to locate the translation files, so every lookup
 * would start hunting for `ar_EG@numbers=latn.csv`, find nothing, and the
 * storefront would fall back to untranslated English. The locale string has two
 * jobs and only one of them wants the keyword, which is why the keyword is
 * applied here — at the formatter — and nowhere upstream of it.
 */
class NumberFormatter extends \Magento\Framework\NumberFormatter
{
    /**
     * @param string|null $locale
     * @param int|null $style
     * @param string|null $pattern
     */
    public function __construct(
        $locale = null,
        $style = \NumberFormatter::CURRENCY,
        $pattern = null
    ) {
        parent::__construct(Numbering::latin($locale), $style, $pattern);
    }
}
