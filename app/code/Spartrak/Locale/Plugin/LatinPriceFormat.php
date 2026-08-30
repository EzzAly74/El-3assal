<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Locale\Plugin;

use Magento\Framework\Locale\Format;
use Magento\Framework\Locale\ResolverInterface;
use Spartrak\Locale\Model\Numbering;

/**
 * Seam 3 of 3: the separators the BROWSER formats prices with.
 *
 * ===========================================================================
 * WHY THE OTHER TWO SEAMS ARE NOT ENOUGH
 * ===========================================================================
 * Not every price on this storefront is formatted in PHP. Anything Knockout
 * renders — the minicart line totals, checkout's running totals, a configurable
 * product's price as you pick options — is assembled in the browser by
 * `Magento_Catalog/js/price-utils`, from the `priceFormat` object this method
 * produces.
 *
 * JavaScript numbers only ever have Latin digits, so those prices were never
 * going to show ٠١٢٣. What they DID show is the separators, because
 * `priceFormat` carries `decimalSymbol` and `groupSymbol` read straight off an
 * ICU formatter for `ar_EG`, where they are ٫ (U+066B) and ٬ (U+066C). Fixing
 * only the PHP side would have produced the worst of both: `1٬234٫50` — Latin
 * digits punctuated with Arabic-Indic separators, on the same page as a
 * server-rendered `1,234.50`.
 *
 * ===========================================================================
 * WHY A PLUGIN AND NOT A PREFERENCE
 * ===========================================================================
 * `Format::getPriceFormat()` instantiates `new \NumberFormatter(...)` inline —
 * PHP's class, not Magento's — so neither the factory preference nor anything
 * else in this module intercepts it. Replacing the whole method would mean
 * copying its 60 lines of pattern arithmetic (precision, grouping, required
 * digits) purely to change which numbering system it read two symbols from.
 *
 * So core still computes the entire format, and this corrects only the two
 * values that carry a numbering system. Everything else it returns — `pattern`,
 * `precision`, `groupLength` — is numbering-system agnostic: the pattern is
 * stripped to `[^0#.,]` by core itself, and those are ASCII in every locale.
 */
class LatinPriceFormat
{
    /**
     * Memoised per ICU locale.
     *
     * `getPriceFormat()` is called on essentially every page render (it feeds
     * the `priceFormat` blob in x-magento-init), and core already builds one
     * formatter per call. Building a second one per call to read two symbols
     * that cannot change within a request would be a needless ICU
     * instantiation on a hot path, so the symbols are cached by locale.
     *
     * @var array<string, array{decimal: string, group: string}>
     */
    private array $symbols = [];

    public function __construct(
        private readonly ResolverInterface $localeResolver
    ) {
    }

    /**
     * @param Format $subject
     * @param array<string, mixed> $result
     * @param string|null $localeCode
     * @param string|null $currencyCode
     * @return array<string, mixed>
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetPriceFormat(
        Format $subject,
        array $result,
        $localeCode = null,
        $currencyCode = null
    ): array {
        // Mirrors core's own first line, so this reads the same locale core
        // did rather than assuming the resolver's.
        $locale = $localeCode ?: $this->localeResolver->getLocale();
        $symbols = $this->symbolsFor((string) $locale);

        $result['decimalSymbol'] = $symbols['decimal'];
        $result['groupSymbol'] = $symbols['group'];

        return $result;
    }

    /**
     * @return array{decimal: string, group: string}
     */
    private function symbolsFor(string $locale): array
    {
        $key = Numbering::latin($locale) ?? $locale;

        if (!isset($this->symbols[$key])) {
            $formatter = new \NumberFormatter($key, \NumberFormatter::CURRENCY);

            $this->symbols[$key] = [
                'decimal' => $formatter->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL),
                'group' => $formatter->getSymbol(\NumberFormatter::GROUPING_SEPARATOR_SYMBOL),
            ];
        }

        return $this->symbols[$key];
    }
}
