<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Model\Section;

use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Spartrak\Homepage\Model\CategoryItem;
use Spartrak\Homepage\Model\ResourceModel\CategoryItem\CollectionFactory as CategoryItemCollectionFactory;
use Spartrak\Homepage\Model\ResourceModel\Section\Collection;
use Spartrak\Homepage\Model\ResourceModel\Section\CollectionFactory;
use Spartrak\Homepage\Model\Section;

/**
 * Feeds the section edit form.
 *
 * Loads the section row and, for a tile section, its category picks — which
 * the form renders as drag-to-reorder dynamic rows. The picks are loaded here
 * rather than by the frontend's SectionList because this is the admin path and
 * has different needs: it must show DISABLED picks too, which the storefront
 * loader deliberately filters out.
 */
class DataProvider extends AbstractDataProvider
{
    /** @var array<int, array<string, mixed>>|null */
    private ?array $loadedData = null;

    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        CollectionFactory $collectionFactory,
        private readonly CategoryItemCollectionFactory $categoryItemCollectionFactory,
        private readonly DataPersistorInterface $dataPersistor,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collectionFactory->create();

        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getData(): array
    {
        if ($this->loadedData !== null) {
            return $this->loadedData;
        }

        $this->loadedData = [];

        /** @var Collection $collection */
        $collection = $this->collection;

        /** @var Section $section */
        foreach ($collection->getItems() as $section) {
            $data = $section->getData();
            $data['category_items'] = $this->getCategoryItems((int) $section->getId());

            $this->loadedData[(int) $section->getId()] = $data;
        }

        // Input the Save controller rejected, handed back so a validation
        // error does not cost the admin everything they typed.
        $persisted = $this->dataPersistor->get('spartrak_homepage_section');

        if ($persisted) {
            $sectionId = (int) ($persisted['section_id'] ?? 0);
            $this->loadedData[$sectionId] = $persisted;
            $this->dataPersistor->clear('spartrak_homepage_section');
        }

        return $this->loadedData;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getCategoryItems(int $sectionId): array
    {
        $collection = $this->categoryItemCollectionFactory->create();
        $collection->addFieldToFilter('section_id', $sectionId);
        $collection->setOrder('sort_order', 'ASC');
        $collection->setOrder('item_id', 'ASC');

        $rows = [];

        /** @var CategoryItem $item */
        foreach ($collection as $item) {
            $rows[] = [
                'item_id' => (int) $item->getId(),
                'category_id' => $item->getCategoryId(),
                'is_active' => (int) $item->getData('is_active'),
                'sort_order' => (int) $item->getData('sort_order'),
            ];
        }

        return $rows;
    }
}
