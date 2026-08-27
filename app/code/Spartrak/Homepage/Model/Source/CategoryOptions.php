<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Model\Source;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * The category picker used by every section that needs one.
 *
 * Renders the tree as an INDENTED FLAT LIST rather than a bare alphabetical
 * one: on a catalogue this size "Pistons" is meaningless without knowing which
 * branch it hangs from, and two branches can legitimately hold a category of
 * the same name.
 *
 * Built from ONE query with one sort. The collection is walked once to index
 * children by parent, then walked depth-first in PHP — which is O(n) and
 * avoids the load-children-per-node recursion that makes the naive version of
 * this class a hundred-query page.
 */
class CategoryOptions implements OptionSourceInterface
{
    /** @var array<int, array{value: int, label: string}>|null */
    private ?array $options = null;

    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @return array<int, array{value: int|string, label: string|\Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        if ($this->options === null) {
            $this->options = $this->build();
        }

        // An empty first option is what lets an admin CLEAR the field on a
        // banner or tile section, where a category is optional.
        return array_merge([['value' => '', 'label' => __('-- Please select --')]], $this->options);
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    private function build(): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addAttributeToSelect(['name', 'is_active']);
        $collection->addAttributeToSort('path', 'ASC');
        $collection->addAttributeToSort('position', 'ASC');

        $childrenByParent = [];
        $names = [];
        $active = [];

        foreach ($collection as $category) {
            $id = (int) $category->getId();
            $parentId = (int) $category->getParentId();

            // Level 0 is the invisible tree root and level 1 is a store's root
            // category — neither is a real destination, so neither is offered.
            if ((int) $category->getLevel() < 2) {
                continue;
            }

            $childrenByParent[$parentId][] = $id;
            $names[$id] = (string) $category->getName();
            $active[$id] = (bool) $category->getIsActive();
        }

        $options = [];

        foreach (array_keys($childrenByParent) as $parentId) {
            // Start from every node whose parent is not itself in the set —
            // i.e. the store root categories — so multi-store installs list
            // every tree rather than only the first.
            if (!isset($names[$parentId])) {
                $this->walk($parentId, 0, $childrenByParent, $names, $active, $options);
            }
        }

        return $options;
    }

    /**
     * @param array<int, int[]> $childrenByParent
     * @param array<int, string> $names
     * @param array<int, bool> $active
     * @param array<int, array{value: int, label: string}> $options
     */
    private function walk(
        int $parentId,
        int $depth,
        array $childrenByParent,
        array $names,
        array $active,
        array &$options
    ): void {
        foreach ($childrenByParent[$parentId] ?? [] as $id) {
            $label = str_repeat('— ', $depth) . $names[$id];

            // Disabled categories stay in the list, flagged. Removing them
            // would make an already-configured section's value vanish from
            // its own dropdown, which reads as data loss.
            if (!$active[$id]) {
                $label .= ' (' . __('disabled') . ')';
            }

            $options[] = ['value' => $id, 'label' => $label . ' [' . $id . ']'];

            $this->walk($id, $depth + 1, $childrenByParent, $names, $active, $options);
        }
    }
}
