<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\ProductVideo\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * Reads and writes spartrak_product_video, in batches, never one row at a time.
 *
 * ===========================================================================
 * WHY A PLAIN CLASS AND NOT AN AbstractDb RESOURCE MODEL
 * ===========================================================================
 * AbstractDb exists to load, save and delete ONE entity through a model
 * object. Nothing in this feature ever wants one row: the storefront wants
 * every setting for every video on a product, and the save path wants to write
 * all of them at once. An AbstractDb resource would have made the natural
 * spelling of both a loop — which is precisely the N+1 the brief rules out.
 *
 * Same reasoning, and the same shape, as
 * Spartrak\Review\Model\ResourceModel\RatingHistogram: a plain class over
 * ResourceConnection whose public methods are the two queries this module
 * actually issues.
 *
 * Every value that reaches SQL goes in as a bound parameter or through
 * Magento's own insert/delete builders. There is no string concatenation into
 * a query anywhere in this class.
 */
class VideoSettings
{
    public const TABLE = 'spartrak_product_video';

    /**
     * The columns the save path writes. Declared once so the writer and the
     * insertOnDuplicate update list cannot disagree about which columns are
     * ours to overwrite.
     */
    private const WRITABLE = [
        'source_type',
        'provider_id',
        'src_path',
        'mime_type',
        'autoplay',
        'is_loop',
        'muted',
        'controls',
        'allow_download',
        'is_featured',
    ];

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Copies settings onto a duplicated product's new gallery entries.
     *
     * ONE read and ONE write for the whole product, not one per video.
     *
     * Reading is unavoidable here — unlike every other path in this module,
     * which gets its settings joined into the gallery query for free (see
     * Plugin\Catalog\Gallery\JoinVideoSettings). A duplicate has no product
     * load to hang off: Magento hands over a map of old value_id to new and
     * nothing else.
     *
     * @param array<int, int> $valueIdMap old value_id => new value_id
     */
    public function duplicate(array $valueIdMap): void
    {
        if ($valueIdMap === []) {
            return;
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);

        $rows = $connection->fetchAll(
            $connection->select()->from($table)->where('value_id IN (?)', array_keys($valueIdMap))
        );

        if ($rows === []) {
            return;
        }

        $payload = [];

        foreach ($rows as $row) {
            $oldId = (int) $row['value_id'];

            if (!isset($valueIdMap[$oldId])) {
                continue;
            }

            $row['value_id'] = (int) $valueIdMap[$oldId];
            $payload[] = $row;
        }

        if ($payload !== []) {
            $connection->insertOnDuplicate($table, $payload, self::WRITABLE);
        }
    }

    /**
     * ONE statement for every video on the product.
     *
     * insertOnDuplicate rather than a read-then-decide: the primary key is the
     * media gallery's own value_id, so "is this an update or an insert?" is a
     * question the database can answer without a round trip, and asking it in
     * PHP would have meant a SELECT per video on every product save.
     *
     * @param array<int, array<string, mixed>> $rows keyed by value_id
     */
    public function saveMany(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $payload = [];

        foreach ($rows as $valueId => $row) {
            $record = ['value_id' => (int) $valueId];

            foreach (self::WRITABLE as $column) {
                $record[$column] = $row[$column] ?? null;
            }

            $payload[] = $record;
        }

        $connection = $this->resource->getConnection();
        $connection->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE),
            $payload,
            self::WRITABLE
        );
    }

    /**
     * Removes settings for gallery entries that have stopped being videos.
     *
     * The foreign key already cascades when the media gallery ROW is deleted,
     * so this covers only the other case: an entry that still exists but whose
     * media_type is no longer `external-video` (an admin replaced a video with
     * an image). The cascade never sees that, and the stale row would then be
     * resurrected by the next save.
     *
     * SCOPED BY THE CALLER'S OWN VALUE IDS, not by a product id looked up
     * through the media-gallery link table. That table is keyed on the EAV
     * LINK FIELD, which is `entity_id` on Open Source and `row_id` on Commerce
     * with staging — so a query written against it is a query that is right on
     * one edition and quietly wrong on the other. The save path already knows
     * every value_id on the product it is saving; passing them in removes the
     * question entirely.
     *
     * @param int[] $allValueIds every gallery entry on the product being saved
     * @param int[] $keepValueIds those that are still videos
     */
    public function deleteExcept(array $allValueIds, array $keepValueIds): void
    {
        $stale = array_values(array_diff($allValueIds, $keepValueIds));

        if ($stale === []) {
            return;
        }

        $this->resource->getConnection()->delete(
            $this->resource->getTableName(self::TABLE),
            ['value_id IN (?)' => $stale]
        );
    }
}
