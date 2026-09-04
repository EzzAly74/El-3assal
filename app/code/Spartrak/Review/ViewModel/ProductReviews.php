<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Review\ViewModel;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Customer\Model\Context as CustomerContext;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\Locale\LocaleFormatter;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Review\Helper\Data as ReviewHelper;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Spartrak\Review\Model\ResourceModel\RatingHistogram;

/**
 * Everything the `جميع التقييمات` panel prints — Figma 535:10083 (desktop),
 * 1204:27098 (mobile).
 *
 * ===========================================================================
 * ONE READ, AND EVERY NUMBER DERIVED FROM IT
 * ===========================================================================
 * The panel shows an average, a ratings count, a review count and five bars.
 * Three of those four come from the SAME histogram read, so they cannot
 * disagree with each other or with the bars:
 *
 *     bars           the buckets themselves
 *     تقييمات        their sum
 *     average        sum(value x votes) / sum(votes)
 *     مراجعات        a separate count of approved reviews (a different fact —
 *                    see RatingHistogram::getReviewCount)
 *
 * The average is computed here rather than read from
 * `review_entity_summary.rating_summary` deliberately. That column is a
 * percentage maintained on a different schedule, so it can and does drift from
 * the votes by a tenth — and a panel that prints "4.5" above bars that average
 * 4.4 is a panel nobody trusts twice.
 *
 * ===========================================================================
 * THE BAR WIDTH IS A SHARE OF THE TOTAL, NOT OF THE LARGEST BUCKET
 * ===========================================================================
 * Figma's own bar widths do not match its own counts on either reading, and
 * they contradict each other between breakpoints — the desktop frame draws
 * 310/255/108/43/28 of an 886px track for 843/351/112/56/42 votes, while the
 * mobile frame (1204:27113 / 1204:27118) draws the 5-star and the 4-star bar
 * at an identical 240px for those same two different counts. They are sample
 * art. So the rule is taken from what the control MEANS: each bar is that
 * value's share of all votes, which is what makes the five bars readable
 * against each other and sums to a full track.
 *
 * Scaling to the largest bucket instead would always paint one bar full width,
 * which reads as "everyone gave five stars" on a product where 40% did.
 *
 * ===========================================================================
 * WHY THERE IS NO PARTIAL STAR FILL
 * ===========================================================================
 * An earlier plan had the 48px summary star carrying the average as a partial
 * fill. It does not, and the asset settles it: the exported node (535:10088)
 * is TWO half-star paths BOTH filled #EEBD1D — a whole gold star — and the
 * frame that draws it sits above a mock average of 4.5, not 5.0. The star is
 * decorative; the numeral underneath it is what states the average. Adding a
 * fill would be inventing a visual effect Figma does not specify (CLAUDE.md
 * section 3), so the drafted `getStarFill()` was removed rather than left
 * unused (section 18).
 *
 * ===========================================================================
 * THERE IS NO "HAS ANY DATA" QUESTION, AND THAT IS DELIBERATE
 * ===========================================================================
 * This class briefly carried `hasRatings()` and `hasFeedback()`, so the panel
 * could hide itself on a product nobody had reviewed. Both are gone: the panel
 * renders the Figma frame in every data state, with `0.0 of 5.0`, both counts
 * at zero and five bars at zero width.
 *
 * That is a better answer than a bespoke empty state for two reasons. Figma
 * draws no empty state, so one would be invented shape (CLAUDE.md section 3);
 * and swapping one layout for another when a product's first review lands
 * moves the page under the shopper for no gain. Every getter below is
 * therefore total — it answers with zero rather than with "nothing to say" —
 * and the template has no branch in it.
 *
 * ===========================================================================
 * NUMBERS ARE FORMATTED HERE, NOT IN THE TEMPLATE
 * ===========================================================================
 * Two different formats, for two different reasons:
 *
 *   counts    through Framework\Locale\LocaleFormatter, which Spartrak_Locale
 *             replaces so an ar_EG store view renders `1,405` in LATIN digits
 *             with locale grouping rather than ICU's default Arabic-Indic
 *             numerals. That is a project-wide decision with its own module;
 *             this class must not re-litigate it with a private formatter.
 *
 *   the scale one fixed decimal place, always — `4.5 of 5.0`, and `4.0` rather
 *             than `4` on a bar row. A rating scale reads as a scale only when
 *             its steps are written to the same width, and ICU's default drops
 *             a trailing zero. The separator is the ASCII point, which is what
 *             the Latin-digit numbering this store is pinned to uses.
 */
class ProductReviews implements ArgumentInterface
{
    /**
     * How many decimal places a rating value is written to — Figma's own
     * `4.5 of 5.0` and `5.0 / 4.0 / 3.0 / 2.0 / 1.0`.
     */
    private const RATING_DECIMALS = 1;

    /** @var array<int, int>|null */
    private ?array $votes = null;

    private ?int $reviewCount = null;

    public function __construct(
        private readonly Registry $registry,
        private readonly StoreManagerInterface $storeManager,
        private readonly RatingHistogram $histogram,
        private readonly LocaleFormatter $localeFormatter,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly HttpContext $httpContext,
        private readonly ReviewHelper $reviewHelper
    ) {
    }

    /**
     * May THIS shopper write a review, or must they sign in first?
     *
     * ===========================================================================
     * WHY THIS IS ASKED THE SAME WAY MAGENTO ASKS IT
     * ===========================================================================
     * Signed in, or the store allows guest reviews — byte for byte the test in
     * `Magento\Review\Block\Form::_construct()`. Duplicated here rather than
     * reached for through that block because the CTA lives in the PANEL and the
     * dialog lives at page level (the two are separate blocks; see the theme's
     * catalog_product_view.xml for why), and a template that fishes a sibling
     * block out of the layout to ask it a question is a template with business
     * logic in it.
     *
     * ===========================================================================
     * CUSTOMER SESSION IS NOT USED, AND THAT IS THE POINT
     * ===========================================================================
     * `Framework\App\Http\Context` — not `Customer\Model\Session` — because the
     * PDP is full-page cached. The http context's `customer_logged_in` value is
     * part of the cache VARY key, so a signed-out and a signed-in shopper get
     * two different cached pages and each is correct. Reading the session
     * instead would decide the CTA once, on whoever warmed the cache, and then
     * serve that answer to everyone: signed-out shoppers offered a dialog they
     * cannot submit, or signed-in shoppers sent to a sign-in modal they do not
     * need.
     */
    public function canWriteReview(): bool
    {
        return (bool) $this->httpContext->getValue(CustomerContext::CONTEXT_AUTH)
            || (bool) $this->reviewHelper->getIsGuestAllowToWrite();
    }

    /**
     * Are reviews switched on for this store view?
     *
     * Magento's layout already gates the whole block on `catalog/review/active`
     * via `ifconfig`, so in the shipped layout this is always true. It is asked
     * anyway because the templates read it, and a template that assumes its own
     * block cannot be reached from another layout is a template that breaks
     * silently the first time somebody references it from one.
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            'catalog/review/active',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * The average, to one decimal place — `4.5` in Figma's `4.5 of 5.0`.
     */
    public function getAverage(): float
    {
        $total = $this->getTotalVotes();

        if ($total === 0) {
            return 0.0;
        }

        $sum = 0;

        foreach ($this->getVotes() as $value => $votes) {
            $sum += $value * $votes;
        }

        return round($sum / $total, self::RATING_DECIMALS);
    }

    /**
     * The top of the scale — `5.0`. Read off the histogram's own value set
     * rather than written as a literal, so the two cannot diverge.
     */
    public function getScale(): float
    {
        return (float) max(RatingHistogram::VALUES);
    }

    public function getAverageLabel(): string
    {
        return $this->rating($this->getAverage());
    }

    public function getScaleLabel(): string
    {
        return $this->rating($this->getScale());
    }

    /**
     * `تقييمات` — how many people awarded stars.
     */
    public function getRatingsCount(): int
    {
        return $this->getTotalVotes();
    }

    /**
     * `مراجعات` — how many people wrote a review.
     */
    public function getReviewsCount(): int
    {
        if ($this->reviewCount === null) {
            $this->reviewCount = $this->histogram->getReviewCount(
                $this->getProductId(),
                $this->getStoreId()
            );
        }

        return $this->reviewCount;
    }

    public function getRatingsCountLabel(): string
    {
        return $this->count($this->getRatingsCount());
    }

    public function getReviewsCountLabel(): string
    {
        return $this->count($this->getReviewsCount());
    }

    /**
     * The five bars, high to low.
     *
     * `percent` is the ONLY geometry that crosses into the template — the
     * track, the fill height and the edge it grows from all stay in CSS, where
     * the RTL mirroring lives.
     *
     * `valueLabel` and `votesLabel` are the printed strings, so the template
     * has no formatting to do and the two number formats stay in one place.
     *
     * @return array<int, array{value: int, votes: int, percent: float, valueLabel: string, votesLabel: string}>
     */
    public function getBars(): array
    {
        $total = $this->getTotalVotes();
        $bars = [];

        foreach ($this->getVotes() as $value => $votes) {
            $bars[] = [
                'value' => $value,
                'votes' => $votes,
                'percent' => $total > 0 ? round($votes / $total * 100, 2) : 0.0,
                'valueLabel' => $this->rating((float) $value),
                'votesLabel' => $this->count($votes),
            ];
        }

        return $bars;
    }

    /**
     * A rating value as the design writes one: fixed decimals, ASCII point.
     */
    private function rating(float $value): string
    {
        return number_format($value, self::RATING_DECIMALS, '.', '');
    }

    /**
     * A cardinal count, through the store's own number formatter.
     */
    private function count(int $value): string
    {
        $formatted = $this->localeFormatter->formatNumber($value);

        // formatNumber() returns false on an ICU failure. The count is still a
        // true fact at that point, so it is printed unformatted rather than
        // dropped — a missing number reads as "no reviews", which would be a
        // lie about the product.
        return $formatted === false ? (string) $value : (string) $formatted;
    }

    /**
     * @return array<int, int>
     */
    private function getVotes(): array
    {
        if ($this->votes === null) {
            $this->votes = $this->histogram->getVotes(
                $this->getProductId(),
                $this->getStoreId()
            );
        }

        return $this->votes;
    }

    private function getTotalVotes(): int
    {
        return array_sum($this->getVotes());
    }

    /**
     * `current_product` is what Magento's own product blocks read, set by
     * Catalog\Controller\Product\View. Taking it here means the template needs
     * no product argument and the view model works from any block on the page.
     */
    private function getProductId(): int
    {
        $product = $this->registry->registry('current_product');

        return $product instanceof ProductInterface ? (int) $product->getId() : 0;
    }

    private function getStoreId(): int
    {
        try {
            return (int) $this->storeManager->getStore()->getId();
        } catch (\Exception) {
            return 0;
        }
    }
}
