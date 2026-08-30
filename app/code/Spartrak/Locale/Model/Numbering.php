<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Locale\Model;

/**
 * Forces every ICU locale this storefront formats numbers with onto the Latin
 * numbering system.
 *
 * ===========================================================================
 * WHY
 * ===========================================================================
 * The client rejected Arabic-Indic numerals (٠١٢٣) outright, in the Arabic
 * locale included — it is a stated non-negotiable of the brief, not a
 * preference. ICU does not know that: the default numbering system for `ar_EG`
 * is `arab`, so every price Magento formats came out as "٠٫٠٠ ج.م.‏" — digits
 * AND separators.
 *
 * Confirmed on the server before any of this was written:
 *
 *     ar_EG@currency=EGP                 -> Arabic-Indic digits
 *     ar_EG@currency=EGP;numbers=latn    -> Latin digits
 *
 * So the whole fix is one ICU keyword, and the only real work is applying it at
 * every point Magento builds a formatter — see the module README for the three.
 *
 * ===========================================================================
 * WHY THIS IS STATIC, WHEN THIS PROJECT OTHERWISE INJECTS EVERYTHING
 * ===========================================================================
 * Two of its three callers are classes Magento constructs through a generated
 * factory with positional-ish arguments, and one of them extends PHP's own
 * `\NumberFormatter`. Giving either a constructor dependency to reach a service
 * puts DI resolution in the path of every price on the site, for a function
 * that takes a string and returns a string, holds no state, reads no config and
 * has no collaborators. A pure transform is the one case where a static method
 * is the smaller risk, and it stays trivially unit-testable.
 */
final class Numbering
{
    /**
     * ICU's keyword for the numbering system, and the value for Western digits.
     */
    private const KEYWORD = 'numbers=latn';

    /**
     * Returns the locale with `numbers=latn` applied.
     *
     * ICU's legacy keyword syntax introduces the FIRST keyword with `@` and
     * separates any further ones with `;`. Magento already appends
     * `@currency=EGP` in Magento\Directory\Model\Currency, so which separator is
     * correct depends on what it was handed — hence the check rather than a
     * blind concatenation.
     *
     * A locale that already names a numbering system is left exactly as it is:
     * that is an explicit instruction from the caller, and silently overruling
     * it would make this class the thing that is hard to debug.
     *
     * @param string|null $locale an ICU locale, with or without keywords
     * @return string|null the same locale pinned to Latin digits
     */
    public static function latin(?string $locale): ?string
    {
        // Null means "use the process default", which Magento never relies on
        // for prices — every caller in the chain passes a resolved locale. It is
        // passed straight through rather than resolved here, because inventing a
        // locale is a bigger surprise than not changing one.
        if ($locale === null || $locale === '') {
            return $locale;
        }

        if (str_contains($locale, 'numbers=')) {
            return $locale;
        }

        return $locale . (str_contains($locale, '@') ? ';' : '@') . self::KEYWORD;
    }
}
