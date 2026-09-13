<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Search\Plugin\Layer;

use Magento\Catalog\Model\Layer\Filter\Item;
use Magento\Catalog\Model\Layer\State;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Phrase;
use Magento\Search\Model\QueryFactory;

/**
 * A layered-nav filter that only RESTATES the search term is page context, not a filter.
 *
 * ===========================================================================
 * THE SYMPTOM
 * ===========================================================================
 * Pick a brand in the cascade finder — say فورد — and the results page offers
 * "التصفية الحالية: ماركة فورد" with an "إزالة" beside it, plus "مسح الكل".
 * Clicking either does nothing you can see. That is the merchant's report, and
 * it is accurate.
 *
 * ===========================================================================
 * WHY, AND WHY IT IS NOT A BROKEN LINK
 * ===========================================================================
 * The brand destination names the brand TWICE. Spartrak_Catalog's
 * ViewModel\BrandNavigation::buildFilterUrl() builds, in one statement:
 *
 *     q      => the brand LABEL      ("فورد")
 *     brand  => the brand OPTION ID  (18)
 *
 * because catalogsearch/result cannot render without a term (see this module's
 * Plugin\Controller\Result\ResetEmptySearch for that branch), while only the
 * attribute gives an exact brand match. Both halves are needed and both are
 * correct. The chip, though, offers to remove one of the two — and removing it
 * leaves the other, so the shopper stays on a Ford page.
 *
 * Measured live, removing that chip:
 *
 *     فورد           442 -> 457   +3%    looks identical — the report
 *     دويتس          391 -> 424   +8%    looks identical
 *     زيتور          142 -> 142   +0%    literally nothing
 *     نيوهولند        14 ->  46   +229%  misleading: mostly other brands
 *     ماسي فيرجسون     1 -> 600            actively harmful
 *
 * So the control is never useful and is sometimes damaging: it swaps an exact
 * brand listing for a fuzzy keyword one that is still about the same brand.
 * There is no state it can reach that the shopper wants. A control with no
 * good destination should not be drawn — the way out of a brand is to navigate
 * away from it, which the header, the finder and the brand grid all do.
 *
 * ===========================================================================
 * THE RULE, AND WHY IT IS EXACT RATHER THAN A GUESS
 * ===========================================================================
 * Hide a filter item whose label IS the search term. That is not a heuristic
 * that happens to catch brand pages: on these URLs the two strings come from
 * the same line of buildFilterUrl(), so the equality is structural. And where
 * it fires on any other route into the same URL — a shopper who typed "فورد"
 * and then ticked فورد in the sidebar — the page is byte-identical, so it had
 * better behave identically; treating one differently from the other would be
 * the inconsistency.
 *
 * It is deliberately narrow. On a part search such as "بستم", a brand chip is
 * a real filter the shopper applied over a different query, the labels do not
 * match, and everything behaves exactly as Magento intends. Category pages are
 * untouched — the route check below sees to that, and there the brand facet
 * already works properly (993 -> 105 -> 993, measured).
 *
 * The FILTER ITSELF IS UNAFFECTED. Layer\Filter\AbstractFilter::apply() narrows
 * the product collection and records a display item through State::addFilter()
 * as two separate actions, so dropping the item changes what is drawn and not
 * what is found: the page still shows the exact 442. The brand swatch also
 * stays in the sidebar, still marked, still able to toggle — so nothing is
 * hidden that the shopper cannot still see and act on.
 *
 * ===========================================================================
 * WHY THIS SEAM
 * ===========================================================================
 * Three controls read this one list, and all three have to agree:
 *
 *     Block\Navigation\State::getActiveFilters()   the chips
 *     layer/view.phtml `if (...getFilters())`      the "مسح الكل" button
 *     layer/view.phtml `$filtered = count(...)`    the mobile filter badge
 *
 * The last two read the MODEL directly, so plugging the block would have left
 * a "clear all" with nothing to clear. Plugging the model is one small class
 * and no copied templates — Porto and Mageplaza own those files, and copying
 * seventy lines of third-party markup to change one guard is what CLAUDE.md
 * section 9 warns against.
 *
 * The model's one non-display consumer is Layer::apply(), which appends the
 * items to $_stateKey. Nothing in 2.4.8 reads that key back — checked across
 * module-catalog, module-catalog-search, module-layered-navigation,
 * module-swatches, module-elasticsearch, Mageplaza and Porto — so there is no
 * collection or cache keyed on it to go stale.
 *
 * ===========================================================================
 * THE REAL FIX, WHEN IT COMES
 * ===========================================================================
 * All of this descends from brand browsing living on the search route.
 * BrandNavigation::buildFilterUrl() already says so: "Uses catalogsearch/result
 * because no dedicated brand landing route exists yet". A brand landing page
 * would carry the brand as its identity — its own h1, its own canonical, the
 * exact attribute filter and no term at all — and this plugin would then have
 * nothing to match and could be deleted.
 */
class HideFiltersRestatingQuery
{
    /**
     * The only route this applies to. A category page's chips are all genuine.
     */
    private const SEARCH_ACTION = 'catalogsearch_result_index';

    /**
     * @param Http $request
     * @param QueryFactory $queryFactory
     */
    public function __construct(
        private readonly Http $request,
        private readonly QueryFactory $queryFactory
    ) {
    }

    /**
     * Drop the display items that only repeat the search term.
     *
     * @param State $subject
     * @param Item[]|mixed $result
     * @return Item[]|mixed
     */
    public function afterGetFilters(State $subject, $result)
    {
        if (!is_array($result) || $result === []) {
            return $result;
        }

        if ($this->request->getFullActionName() !== self::SEARCH_ACTION) {
            return $result;
        }

        // The same memoised Query the controller and the heading read, so the
        // term compared here is the one the page is actually showing.
        $term = $this->normalise($this->queryFactory->get()->getQueryText());

        if ($term === '') {
            return $result;
        }

        $kept = [];

        foreach ($result as $item) {
            if (!$this->restatesTerm($item, $term)) {
                $kept[] = $item;
            }
        }

        // Re-indexed: the templates foreach and count this list.
        return $kept;
    }

    /**
     * Whether this display item says nothing the search term has not already said.
     *
     * @param mixed $item
     * @param string $term already normalised
     * @return bool
     */
    private function restatesTerm($item, string $term): bool
    {
        if (!$item instanceof Item) {
            return false;
        }

        $label = $item->getLabel();

        // Price and other range filters label themselves with a Phrase built
        // from two numbers; anything that is not plain text cannot be a repeat
        // of the term and is left alone rather than coerced.
        if (!is_string($label) && !$label instanceof Phrase) {
            return false;
        }

        return $this->normalise((string) $label) === $term;
    }

    /**
     * Fold the incidental differences — case, padding, doubled spaces — that a
     * label and a query string can pick up on their way into the URL.
     *
     * @param mixed $value
     * @return string
     */
    private function normalise($value): string
    {
        if (!is_string($value) && !$value instanceof Phrase) {
            return '';
        }

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $value)));
    }
}
