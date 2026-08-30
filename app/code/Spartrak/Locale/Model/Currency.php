<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Locale\Model;

use Magento\Framework\App\CacheInterface;

/**
 * Seam 2 of 3: the fallback formatter.
 *
 * `Magento\Directory\Model\Currency::formatTxt()` has two paths. The fast one
 * goes through the number formatter covered by Model\NumberFormatter. The other
 * — taken whenever `canUseNumberFormatter()` says no, i.e. when the caller
 * passes any option outside {precision, display, symbol}, or a `display` mode
 * other than USE_SYMBOL / NO_SYMBOL — goes to
 * `Magento\Framework\Currency\Data\Currency::toCurrency()`, which builds its own
 * `new NumberFormatter($options['locale'], ...)` directly.
 *
 * That second formatter never touches the factory, so the preference on
 * NumberFormatter cannot reach it. Rather than plug `toCurrency()` — a long
 * method whose `$options` are merged from a protected property a plugin cannot
 * read — the locale is fixed at the point the currency object is CONSTRUCTED.
 * `Magento\Framework\Locale\Currency::getCurrency()` creates these through
 * `CurrencyFactory` with `['locale' => $localeResolver->getLocale()]`, so
 * transforming the constructor argument here means `$this->options['locale']`
 * is already Latin by the time `toCurrency()` merges it.
 *
 * This is a rarely-taken path — most price rendering passes precision and
 * nothing else — but "rarely" is exactly how a stray ٠٫٠٠ survives a fix and
 * turns up on one email template six months later.
 */
class Currency extends \Magento\Framework\Currency
{
    /**
     * @param CacheInterface $appCache
     * @param array|string|null $options
     * @param string|null $locale
     */
    public function __construct(
        CacheInterface $appCache,
        $options = null,
        $locale = null
    ) {
        parent::__construct($appCache, $options, Numbering::latin($locale));
    }
}
