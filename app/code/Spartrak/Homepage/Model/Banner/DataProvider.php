<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Model\Banner;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Filesystem;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Spartrak\Homepage\Model\Banner;
use Spartrak\Homepage\Model\Image\Storage;
use Spartrak\Homepage\Model\ResourceModel\Banner\CollectionFactory;

/**
 * Feeds the banner edit form.
 *
 * ===========================================================================
 * WHY THE IMAGE FIELDS ARE RESHAPED ON THE WAY OUT
 * ===========================================================================
 * The database stores a bare filename. The `imageUploader` form element does
 * not read a filename — it reads a LIST of file descriptors:
 *
 *     [['name' => 'hero.webp', 'url' => 'https://…/hero.webp', 'size' => 1234]]
 *
 * Without that shape the field renders empty on every edit, and the admin
 * would have to re-upload all four images to change a title. The Save
 * controller performs the mirror-image conversion, so the two sides of this
 * round trip are a matched pair — change one and you must change the other.
 *
 * `size` is included because the uploader's own preview template prints it;
 * it is read from disk, so a file removed from the media directory behind
 * Magento's back degrades to a missing preview rather than a fatal error.
 */
class DataProvider extends AbstractDataProvider
{
    private const IMAGE_FIELDS = [
        'image_desktop_en',
        'image_desktop_ar',
        'image_mobile_en',
        'image_mobile_ar',
    ];

    /** @var array<int, array<string, mixed>>|null */
    private ?array $loadedData = null;

    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        CollectionFactory $collectionFactory,
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

        /** @var Banner $banner */
        foreach ($this->collection->getItems() as $banner) {
            $row = $banner->getData();

            foreach (self::IMAGE_FIELDS as $field) {
                $row[$field] = $this->toUploaderValue((string) ($row[$field] ?? ''));
            }

            $this->loadedData[(int) $banner->getId()] = $row;
        }

        $persisted = $this->dataPersistor->get('spartrak_homepage_banner');

        if ($persisted) {
            $bannerId = (int) ($persisted['banner_id'] ?? 0);
            $this->loadedData[$bannerId] = $persisted;
            $this->dataPersistor->clear('spartrak_homepage_banner');
        }

        return $this->loadedData;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function toUploaderValue(string $file): array
    {
        if (trim($file) === '') {
            return [];
        }

        return [[
            'name' => $file,
            'url' => $this->storage->getUrl($file),
            'size' => $this->getFileSize($file),
        ]];
    }

    private function getFileSize(string $file): int
    {
        try {
            $media = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
            $relative = Storage::BASE_PATH . '/' . ltrim($file, '/');

            return $media->isExist($relative) ? (int) $media->stat($relative)['size'] : 0;
        } catch (\Exception $exception) {
            return 0;
        }
    }
}
