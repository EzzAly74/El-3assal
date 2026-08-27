<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Model;

use Magento\Framework\App\ResourceConnection;
use Spartrak\Homepage\Model\ResourceModel\CategoryItem as CategoryItemResource;
use Spartrak\Homepage\Model\ResourceModel\CategoryItem\CollectionFactory as CategoryItemCollectionFactory;

/**
 * Owns the "what categories does this section show" set.
 *
 * The admin form edits the picks as a grid of rows, so the save is a
 * SYNCHRONISE, not a series of individual writes: whatever the form posted
 * becomes the section's complete set, and anything the admin removed from the
 * grid must actually disappear.
 *
 * Kept out of the controller because it is business logic, not request
 * handling (CLAUDE.md section 8), and because it is the only place that
 * understands the transaction below.
 */
class CategoryItemManager
{
    public function __construct(
        private readonly CategoryItemCollectionFactory $collectionFactory,
        private readonly CategoryItemFactory $itemFactory,
        private readonly CategoryItemResource $itemResource,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Replaces a section's category picks with exactly the rows given.
     *
     * ONE TRANSACTION on purpose. A delete-then-insert that failed halfway
     * would leave the section showing fewer categories than the admin saw
     * when they hit Save — and the dashboard would report success. Wrapping
     * it means the section either has the new set or keeps the old one.
     *
     * @param array<int, array{category_id: mixed, is_active?: mixed, sort_order?: mixed}> $rows
     */
    public function save(int $sectionId, array $rows): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->beginTransaction();

        try {
            $existing = $this->getExisting($sectionId);
            $seen = [];
            $position = 0;

            foreach ($rows as $row) {
                $categoryId = (int) ($row['category_id'] ?? 0);

                // A blank row is what an admin leaves behind when they click
                // "Add" and then change their mind. Skipped, not saved as a
                // pick for category 0.
                if ($categoryId <= 0 || isset($seen[$categoryId])) {
                    continue;
                }

                $seen[$categoryId] = true;

                $item = $existing[$categoryId] ?? $this->itemFactory->create();
                $item->setData('section_id', $sectionId);
                $item->setData('category_id', $categoryId);
                $item->setData('is_active', isset($row['is_active']) ? (int) $row['is_active'] : 1);

                // Sort order is taken from the ROW'S POSITION in the grid, not
                // from a number the admin types: the dynamic-rows control is
                // drag-to-reorder, so what they see is what the order is.
                $item->setData('sort_order', $position++);

                $this->itemResource->save($item);
            }

            foreach ($existing as $categoryId => $item) {
                if (!isset($seen[$categoryId])) {
                    $this->itemResource->delete($item);
                }
            }

            $connection->commit();
        } catch (\Exception $exception) {
            $connection->rollBack();

            throw $exception;
        }
    }

    /**
     * Current picks keyed by category id.
     *
     * Reused rather than deleted-and-recreated so a row keeps its item_id
     * across an edit — which keeps auto-increment from running away on a
     * section someone tweaks daily.
     *
     * @return array<int, CategoryItem>
     */
    private function getExisting(int $sectionId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('section_id', $sectionId);

        $byCategory = [];
        /** @var CategoryItem $item */
        foreach ($collection as $item) {
            $byCategory[$item->getCategoryId()] = $item;
        }

        return $byCategory;
    }
}
