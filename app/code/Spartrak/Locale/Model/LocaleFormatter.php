<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Locale\Model;

use Magento\Framework\Locale\LocaleFormatter as BaseLocaleFormatter;
use Magento\Framework\Locale\ResolverInterface;

/**
 * SEAM 6 — PLAIN NUMBERS, AND THE LOCALE HANDED TO JAVASCRIPT.
 *
 * ===========================================================================
 * THIS IS THE ONE THAT MADE PAGINATION ARABIC
 * ===========================================================================
 * Seams 1-3 pin the CURRENCY formatter that Directory\Model\Currency builds.
 * Magento\Framework\Locale\LocaleFormatter builds its own, and it does so with
 * a bare
 *
 *     numfmt_create($this->localeResolver->getLocale(), \NumberFormatter::TYPE_DEFAULT)
 *
 * — no factory, no object manager, nothing a preference or a plugin can reach.
 * So every number that goes through it renders in `ar_EG`'s default numbering
 * system, which is `arab`.
 *
 * That is not a corner: core's own 2.4.8 templates route a lot through it. The
 * PAGER alone (Magento_Theme::html/pager.phtml) formats the page numbers, the
 * "N Item(s)" count and the "showing X-Y of Z" range through
 * formatNumber() — which is exactly the "pagination numbers in the entire
 * project" report this class answers.
 *
 * ===========================================================================
 * A PREFERENCE, NOT A PLUGIN — BECAUSE THE FORMATTER IS CACHED PRIVATELY
 * ===========================================================================
 * `$numberFormatter` is a private property built lazily on first use, so a
 * `before` plugin has nothing to alter and an `after` plugin could only
 * transliterate the finished string. Transliteration is what this module has
 * refused from the start (see Plugin\LatinDateFormatter for why): it treats the
 * symptom, runs on every number twice, and would rewrite Arabic-Indic digits
 * that legitimately belong to CONTENT rather than to formatting.
 *
 * The base class is concrete, not final, its one dependency is the locale
 * resolver, and both methods are public — so overriding them applies the same
 * one ICU keyword at the same kind of place every other seam does: where the
 * formatter is constructed. That is also precisely how seam 2 works.
 *
 * ===========================================================================
 * getLocaleJs() IS THE SAME DEFECT, CLIENT-SIDE
 * ===========================================================================
 * The base implementation returns `ar-EG`, and anything that hands that to
 * `Intl.NumberFormat` or `toLocaleString` gets Arabic-Indic digits in the
 * browser — the server-side fix would then be visibly contradicted on the same
 * page. It is pinned too, in the BCP-47 form JavaScript actually accepts;
 * Numbering::latinBcp47() explains why that is a different string from the ICU
 * keyword.
 */
class LocaleFormatter extends BaseLocaleFormatter
{
    /**
     * Re-declared rather than inherited: the base class's own cache is private.
     */
    private ?\NumberFormatter $latinNumberFormatter = null;

    public function __construct(
        private readonly ResolverInterface $spartrakLocaleResolver
    ) {
        parent::__construct($spartrakLocaleResolver);
    }

    /**
     * @return string
     */
    public function getLocaleJs(): string
    {
        return (string) Numbering::latinBcp47((string) $this->spartrakLocaleResolver->getLocale());
    }

    /**
     * @param string|float|int|null $number
     * @return false|string
     */
    public function formatNumber($number)
    {
        // Kept verbatim from the base class: a non-numeric value is coerced to
        // int rather than rejected, and changing that here would be a second,
        // unrelated behaviour change riding along with a digit fix.
        if (!is_float($number) && !is_int($number)) {
            $number = (int) $number;
        }

        if ($this->latinNumberFormatter === null) {
            $formatter = numfmt_create(
                (string) Numbering::latin((string) $this->spartrakLocaleResolver->getLocale()),
                \NumberFormatter::TYPE_DEFAULT
            );

            if ($formatter === null || $formatter === false) {
                // An unusable locale string is the platform's problem, not
                // something to swallow — fall back to the base behaviour so the
                // number still renders rather than disappearing.
                return parent::formatNumber($number);
            }

            $this->latinNumberFormatter = $formatter;
        }

        return $this->latinNumberFormatter->format($number);
    }
}
