<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Review\ViewModel;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use Spartrak\Review\Model\ResourceModel\RatingHistogram;

/**
 * Everything the `جميع التقييمات` panel prints — Figma 534:9527.
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
 * percentage maintained by an observer on a different schedule, so it can and
 * does drift from the votes by a tenth — and a panel that prints "4.5" above
 * bars that average 4.4 is a panel nobody trusts twice.
 *
 * ===========================================================================
 * THE BAR WIDTH IS A SHARE OF THE TOTAL, NOT OF THE LARGEST BUCKET
 * ===========================================================================
 * Figma's own bar widths (310, 255, 108, 43, 28 of an 886px track) do not
 * match its own counts (843, 351, 112, 56, 42) on either reading — they are
 * sample art, and the same file has sample-data contradictions recorded
 * elsewhere. So the rule is taken from what the control MEANS: each bar is
 * that value's share of all votes, which is what makes the five bars readable
 * against each other and sums to a full track.
 *
 * Scaling to the largest bucket instead would always paint one bar full width,
 * which reads as "everyone gave five stars" on a product where 40% did.
 *
 * ===========================================================================
 * THE PRODUCT COMES FROM THE REGISTRY, LIKE EVERY OTHER PDP BLOCK
 * ===========================================================================
 * `current_product` is what Magento's own product blocks read, set by
 * Catalog\Controller\Product\View. Taking it here means the template needs no
 * product argument and the view model works from any block on the page.
 */
class ProductReviews implements ArgumentInterface
{
    /** @var array<int, int>|null */
    private ?array $votes = null;

    private ?int $reviewCount = null;

    public function __construct(
        private readonly Registry $registry,
        private readonly StoreManagerInterface $storeManager,
        private readonly RatingHistogram $histogram
    ) {
    }

    /**
     * Is there anything to show?
     *
     * The panel's heading, the CTA and the empty line are all rendered either
     * way — what this gates is the SUMMARY and the BARS, which describe data
     * that may not exist. A product nobody has rated gets the invitation to be
     * the first, not five empty bars and "0.0 of 5.0".
     */
    public function hasRatings(): bool
    {
        return $this->getTotalVotes() > 0;
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

        return round($sum / $total, 1);
    }

    /**
     * The top of the scale — `5.0`. Read off the histogram's own value set
     * rather than written as a literal, so the two cannot diverge.
     */
    public function getScale(): float
    {
        return (float) max(RatingHistogram::VALUES);
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

    /**
     * The five bars, high to low.
     *
     * `percent` is already rounded for the stylesheet, and it is the ONLY
     * geometry that crosses into the template — the track, the fill height and
     * the direction it grows from all stay in CSS where the RTL mirroring
     * lives.
     *
     * @return array<int, array{value: int, votes: int, percent: float}>
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
            ];
        }

        return $bars;
    }

    /**
     * How much of the big summary star to fill, 0..1 — Figma 535:10087.
     *
     * The star is a single glyph, not five, so it carries the average as a
     * PARTIAL FILL. Returned as a ratio for the same reason the bars return a
     * percentage: the stylesheet owns how that becomes a width, and therefore
     * which edge it grows from in each direction.
     */
    public function getStarFill(): float
    {
        $scale = $this->getScale();

        return $scale > 0 ? round($this->getAverage() / $scale, 4) : 0.0;
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
