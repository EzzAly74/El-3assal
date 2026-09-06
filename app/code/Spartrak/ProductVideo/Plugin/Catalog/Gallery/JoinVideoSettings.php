<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\ProductVideo\Plugin\Catalog\Gallery;

use Magento\Catalog\Model\ResourceModel\Product\Gallery;
use Magento\Framework\DB\Select;
use Spartrak\ProductVideo\Model\ResourceModel\VideoChapter;
use Spartrak\ProductVideo\Model\ResourceModel\VideoSettings;

/**
 * Adds this module's columns to the query the media gallery was already going
 * to run.
 *
 * ===========================================================================
 * ZERO EXTRA QUERIES, ON EVERY PATH
 * ===========================================================================
 * `Gallery::createBatchBaseSelect()` is the one select the media gallery is
 * built from, and BOTH ways a product can be loaded go through it:
 *
 *   PDP           ReadHandler::execute() -> loadProductGalleryByAttributeId()
 *                 -> createBaseLoadSelect() -> createBatchBaseSelect()
 *   product rails Collection::addMediaGalleryData() -> createBatchBaseSelect()
 *
 * So one LEFT JOIN here means the settings arrive with the gallery, for a
 * single product on the PDP and for a whole rail of products on the homepage,
 * without adding a single round trip to either. Not one query per video, not
 * one per product — none.
 *
 * That is why this replaced an earlier version of this module that attached
 * the same data in a ReadHandler plugin. That version cost one extra query per
 * product page and, worse, did not fire at all for the collection path — so
 * the homepage's video showcase could never have seen it.
 *
 * It is also the seam Magento_ProductVideo itself uses, for its own columns,
 * in Magento\ProductVideo\Model\Plugin\ExternalVideoResourceBackend.
 *
 * ===========================================================================
 * WHY THE JOIN IS NOT STORE-SCOPED
 * ===========================================================================
 * Magento joins the gallery VALUE table twice — once for the current store and
 * once for the default — because a label or a position can be overridden per
 * store view. These columns cannot: how a video plays is a property of the
 * video, not of the language it is described in. The one genuinely per-store
 * thing, chapter titles, is stored as an `_ar`/`_en` pair on the chapter row
 * instead, which is the pattern every other bilingual Spartrak column follows.
 *
 * So this is one plain join on `main.value_id`, and there is no IFNULL pair to
 * get wrong.
 *
 * ===========================================================================
 * PREFIXED COLUMN ALIASES
 * ===========================================================================
 * Every column comes back as `spartrak_*`. The gallery row is a flat array
 * shared by Magento, by Magento_ProductVideo and by anything else that plugs
 * this select, and a bare `autoplay` or `controls` in that namespace is asking
 * for a collision with a module nobody has written yet.
 */
class JoinVideoSettings
{
    /**
     * Table column => the alias it is exposed under on the gallery row.
     *
     * Declared once, and read by Model\Video\DescriptorBuilder through the
     * same constant, so the query and its only consumer cannot drift apart.
     */
    public const COLUMNS = [
        'source_type' => 'spartrak_source_type',
        'provider_id' => 'spartrak_provider_id',
        'src_path' => 'spartrak_src_path',
        'mime_type' => 'spartrak_mime_type',
        'autoplay' => 'spartrak_autoplay',
        'is_loop' => 'spartrak_is_loop',
        'muted' => 'spartrak_muted',
        'controls' => 'spartrak_controls',
        'allow_download' => 'spartrak_allow_download',
        'is_featured' => 'spartrak_is_featured',
    ];

    private const ALIAS = 'spartrak_video';

    public function __construct(
        private readonly VideoSettings $settingsResource,
        private readonly VideoChapter $chapterResource
    ) {
    }

    /**
     * @return Select
     */
    public function afterCreateBatchBaseSelect(Gallery $subject, Select $select)
    {
        $columns = [];

        foreach (self::COLUMNS as $column => $alias) {
            $columns[$alias] = self::ALIAS . '.' . $column;
        }

        return $select->joinLeft(
            [self::ALIAS => $subject->getTable(VideoSettings::TABLE)],
            $subject->getMainTableAlias() . '.value_id = ' . self::ALIAS . '.value_id',
            $columns
        );
    }

    /**
     * Carries video settings across a product duplicate.
     *
     * Without this, "Save & Duplicate" produces a copy whose videos are in the
     * gallery and in the right order but have lost their source type, their
     * playback settings and their chapters — which reads as the feature being
     * broken rather than as a gap in a duplicate.
     *
     * Magento\ProductVideo\Model\Plugin\ExternalVideoResourceBackend does
     * exactly this for its own table, on the same method, and the two run
     * independently.
     *
     * @param array<int, int> $valueIdMap old value_id => new value_id
     * @return array<int, int>
     */
    public function afterDuplicate(Gallery $subject, array $valueIdMap)
    {
        $this->settingsResource->duplicate($valueIdMap);
        $this->chapterResource->duplicate($valueIdMap);

        return $valueIdMap;
    }
}
