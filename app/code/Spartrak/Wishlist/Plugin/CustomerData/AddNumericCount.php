<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Wishlist\Plugin\CustomerData;

use Magento\Wishlist\CustomerData\Wishlist as WishlistSection;
use Spartrak\Wishlist\Model\SavedProductIds;

/**
 * Puts a plain NUMBER in the wishlist customer-data section.
 *
 * ===========================================================================
 * WHY
 * ===========================================================================
 * Figma draws the header's wishlist badge as a numeral in a 12x12 dot
 * (595:14506), exactly like the cart badge beside it. The only count the
 * section carries is `counter`, and that is a translated PHRASE, not a number:
 * Magento\Wishlist\CustomerData\Wishlist::createCounter() returns __('1 item')
 * or __('%1 items', $n). So the badge rendered "1 item" inside a dot sized for
 * one digit.
 *
 * The phrase is useful — it is the accessible wording — so it is left exactly
 * as it is and a numeric sibling is added beside it. Nothing that already reads
 * `counter` changes behaviour.
 *
 * ===========================================================================
 * WHY NOT PARSE THE PHRASE IN THE TEMPLATE
 * ===========================================================================
 * Digging the digits back out of a translated string in Knockout would work
 * today and break the first time a locale writes its numbers differently or a
 * translator reorders the placeholder. The count exists as an integer one
 * method call away; taking it from there is both shorter and correct in every
 * locale.
 *
 * ===========================================================================
 * WHY NOT Helper\Data::getItemCount(), WHICH IS WHAT THIS USED TO READ
 * ===========================================================================
 * Because it stops at 3. That was reported as "the counter does not exceed 3
 * however many items I add", and it is a state leak in core rather than a
 * stale cache: getItems() puts setPageSize(3) on the wish-list collection that
 * Helper\Data MEMOISES, calculate() then counts that same collection with
 * count() - the Countable method, which loads rows and so respects the limit -
 * and writes the 3 into the customer session, where getItemCount() serves it
 * from then on. Model\SavedProductIds carries the full walk-through.
 *
 * So the count comes from there now: one indexed read of wishlist_item, which
 * no page size can reach. It is also the SAME source AddProductIds paints the
 * hearts from, so the numeral and the filled hearts are one fact counted two
 * ways and cannot drift apart.
 */
class AddNumericCount
{
    public function __construct(
        private readonly SavedProductIds $savedProductIds
    ) {
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function afterGetSectionData(WishlistSection $subject, array $result): array
    {
        $result['count'] = $this->savedProductIds->getCount();

        return $result;
    }
}
