<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Wishlist\Plugin\CustomerData;

use Magento\Wishlist\CustomerData\Wishlist as WishlistSection;
use Magento\Wishlist\Helper\Data as WishlistHelper;

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
 * WHY THE SAME HELPER CORE USES
 * ===========================================================================
 * getItemCount() is what core's own createCounter() is counting, so this cannot
 * disagree with the phrase beside it. The helper memoises per request, so the
 * second call costs no query.
 */
class AddNumericCount
{
    public function __construct(
        private readonly WishlistHelper $wishlistHelper
    ) {
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function afterGetSectionData(WishlistSection $subject, array $result): array
    {
        $result['count'] = (int) $this->wishlistHelper->getItemCount();

        return $result;
    }
}
