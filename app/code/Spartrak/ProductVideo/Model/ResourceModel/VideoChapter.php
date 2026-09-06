<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\ProductVideo\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * Reads and writes spartrak_product_video_chapter, in batches.
 *
 * The read is ONE query for every chapter of every video on the product,
 * grouped in PHP afterwards. The obvious alternative — asking per video — is
 * the N+1 the brief rules out, and it would have scaled with the number of
 * videos on exactly the page where that matters.
 *
 * See Model\ResourceModel\VideoSettings for why this is a plain class over
 * ResourceConnection rather than an AbstractDb resource model.
 */
class VideoChapter
{
    public const TABLE = 'spartrak_product_video_chapter';

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * ONE query for every chapter on the product, already ordered.
     *
     * Ordering in SQL rather than in PHP because the index
     * (value_id, sort_order) makes it free — the rows come back in the order
     * the storefront renders them and nothing sorts them again.
     *
     * @param int[] $valueIds
     * @return array<int, array<int, array<string, mixed>>> chapters keyed by value_id
     */
    public function loadByValueIds(array $valueIds): array
    {
        if ($valueIds === []) {
            return [];
        }

        $connection = $this->resource->getConnection();

        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE))
            ->where('value_id IN (?)', $valueIds)
            ->order(['value_id ASC', 'sort_order ASC']);

        $grouped = [];

        foreach ($connection->fetchAll($select) as $row) {
            $grouped[(int) $row['value_id']][] = $row;
        }

        return $grouped;
    }

    /**
     * Copies chapters onto a duplicated product's new gallery entries.
     *
     * ONE read and ONE write for the whole product. `chapter_id` is dropped so
     * the new rows get their own identities rather than colliding with the
     * originals'.
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
            $connection->select()
                ->from($table, ['value_id', 'start_seconds', 'title_en', 'title_ar', 'sort_order'])
                ->where('value_id IN (?)', array_keys($valueIdMap))
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
            $connection->insertMultiple($table, $payload);
        }
    }

    /**
     * Replaces one video's chapter list outright.
     *
     * DELETE then INSERT rather than a diff. A chapter has no identity a
     * merchant can see — they are re-typed as a block in one textarea — so
     * trying to match old rows to new ones would be inventing a stable key
     * that the input format does not have. The whole list for one video is a
     * handful of rows inside a product save that is already a transaction.
     *
     * @param array<int, array{start_seconds: int, title_en: ?string, title_ar: ?string, sort_order: int}> $chapters
     */
    public function replaceForValueId(int $valueId, array $chapters): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);

        $connection->delete($table, ['value_id = ?' => $valueId]);

        if ($chapters === []) {
            return;
        }

        $payload = [];

        foreach ($chapters as $chapter) {
            $payload[] = [
                'value_id' => $valueId,
                'start_seconds' => (int) $chapter['start_seconds'],
                'title_en' => $chapter['title_en'] ?? null,
                'title_ar' => $chapter['title_ar'] ?? null,
                'sort_order' => (int) $chapter['sort_order'],
            ];
        }

        $connection->insertMultiple($table, $payload);
    }
}
