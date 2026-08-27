<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Model\Section;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Filesystem;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Spartrak\Homepage\Model\CategoryItem;
use Spartrak\Homepage\Model\Image\Storage;
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
        private readonly Storage $storage,
        private readonly Filesystem $filesystem,
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

            // The same round trip the banner form performs: the column stores
            // a bare filename, the imageUploader element reads a list of file
            // descriptors. Without this the promo image renders empty on every
            // edit and an admin would have to re-upload it just to change a
            // headline. Save does the mirror-image conversion — the two are a
            // matched pair, change one and you must change the other.
            foreach (['promo_image_en', 'promo_image_ar'] as $field) {
                $data[$field] = $this->toUploaderValue((string) ($data[$field] ?? ''));
            }

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
     * Stored filename -> the descriptor list the uploader element expects.
     *
     * `size` is read from disk because the uploader's preview prints it; a
     * file removed from the media directory behind Magento's back degrades to
     * a missing preview rather than a fatal error.
     *
     * @return array<int, array<string, mixed>>
     */
    private function toUploaderValue(string $file): array
    {
        if (trim($file) === '') {
            return [];
        }

        $size = 0;

        try {
            $media = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
            $relative = $this->storage->getRelativePath($file);
            $size = $media->isExist($relative) ? (int) $media->stat($relative)['size'] : 0;
        } catch (\Exception $exception) {
            $size = 0;
        }

        return [[
            'name' => $file,
            'url' => $this->storage->getUrl($file),
            'size' => $size,
        ]];
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
