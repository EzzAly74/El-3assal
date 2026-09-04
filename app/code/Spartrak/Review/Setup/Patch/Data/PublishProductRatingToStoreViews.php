<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Review\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Spartrak\Review\Model\RatingVisibility;

/**
 * Publishes a product rating to every store view, so the rating dialog has
 * stars in it.
 *
 * Magento seeds three product ratings and no `rating_store` rows, which means
 * no rating is visible on any storefront until somebody ticks "Visible In" by
 * hand — and until they do, the review dialog renders no stars, every review
 * is submitted with no vote, and the reviews panel reports "no ratings yet" on
 * a product that has reviews. Model\RatingVisibility carries the full trace.
 *
 * A DATA PATCH because that is what it is: a rating dimension the storefront
 * cannot function without is setup, not configuration (CLAUDE.md section 5). If
 * it only exists because somebody clicked it on one environment, then on every
 * other environment the feature silently does not work — the same argument
 * Spartrak_PickupLocation's AddDeliveryStatuses makes about order statuses.
 *
 * NON-DESTRUCTIVE AND IDEMPOTENT. It skips any store view that already shows a
 * rating, whichever one, so a merchant's own configuration is never reversed;
 * and the underlying write is a diff against the rating's existing store set,
 * so re-running it changes nothing.
 *
 * A store view created LATER is covered by Observer\PublishRatingToNewStoreView
 * rather than by this patch, which by definition runs once.
 */
class PublishProductRatingToStoreViews implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly RatingVisibility $ratingVisibility
    ) {
    }

    /**
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [
            // Magento's own seed patch is what creates the ratings this one
            // publishes. Declared so the two cannot run in the wrong order on a
            // fresh install.
            \Magento\Review\Setup\Patch\Data\InitReviewStatusesAndData::class,
        ];
    }

    /**
     * @return string[]
     */
    public function getAliases(): array
    {
        return [];
    }

    public function apply(): self
    {
        $this->moduleDataSetup->startSetup();

        $this->ratingVisibility->ensureVisibleEverywhere();

        $this->moduleDataSetup->endSetup();

        return $this;
    }
}
