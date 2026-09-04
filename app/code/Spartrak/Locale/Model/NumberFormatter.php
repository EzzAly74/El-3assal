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

        if ($pattern === null) {
            $this->restoreLocalePattern((string) $locale, (int) $style);
        }
    }

    /**
     * Put the currency symbol back where the locale actually wants it.
     *
     * ===================================================================
     * THE NUMBERING KEYWORD SILENTLY MOVES THE CURRENCY SYMBOL
     * ===================================================================
     * MEASURED on this server (ICU 71.1), reading the patterns ICU itself
     * chooses:
     *
     *     ar_EG@currency=EGP                 ->  #,##0.00 ¤     (symbol LAST)
     *     ar_EG@currency=EGP;numbers=latn    ->  ¤ #,##0.00     (symbol FIRST)
     *     en_US@currency=USD                 ->  ¤#,##0.00
     *     en_US@currency=USD;numbers=latn    ->  ¤#,##0.00      (unchanged)
     *
     * So asking ICU for Latin digits in `ar_EG` also, as a side effect, flips
     * the symbol to the front — which is how a price that should read
     * "14,109.00 ج.م" started rendering as "ج.م 14,109.00". Figma puts the
     * symbol after the number on every price node in the file, and so does
     * ICU's own Arabic convention; the keyword was the only thing that changed
     * it.
     *
     * This is therefore a REPAIR, not a design decision: the pattern is read
     * from the locale as it was BEFORE the keyword was applied, and reinstated.
     * `en_US` is unaffected because its two patterns are identical, so nothing
     * here needs to know which languages put the symbol where — ICU is still
     * the authority, it is just being asked the question in the right order.
     *
     * Skipped entirely when the caller supplied an explicit pattern: that is an
     * instruction, and this class does not overrule instructions (the same rule
     * Numbering::latin() follows for an explicit numbering system).
     */
    private function restoreLocalePattern(string $locale, int $style): void
    {
        if ($locale === '' || str_contains($locale, 'numbers=')) {
            return;
        }

        // One extra formatter per distinct locale+style. Magento's own
        // Directory\Model\Currency caches its formatters per locale and option
        // set, so this runs a handful of times per request, not per price.
        $native = new \NumberFormatter($locale, $style);
        $nativePattern = $native->getPattern();

        if ($nativePattern === false || $nativePattern === $this->getPattern()) {
            return;
        }

        $this->setPattern($nativePattern);
    }
}
