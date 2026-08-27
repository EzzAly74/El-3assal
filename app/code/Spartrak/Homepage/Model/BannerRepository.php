<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Model;

use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Spartrak\Homepage\Model\ResourceModel\Banner as BannerResource;

/**
 * Persistence service for banner items.
 *
 * Same shape and same rationale as SectionRepository — see that class for why
 * this is a lean load/save/delete service rather than a SearchCriteria @api
 * repository, and why the storefront reads through Model\SectionList instead.
 */
class BannerRepository
{
    public function __construct(
        private readonly BannerResource $resource,
        private readonly BannerFactory $bannerFactory
    ) {
    }

    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $bannerId): Banner
    {
        $banner = $this->bannerFactory->create();
        $this->resource->load($banner, $bannerId);

        if (!$banner->getId()) {
            throw new NoSuchEntityException(
                __('No banner exists with ID "%1".', $bannerId)
            );
        }

        return $banner;
    }

    /**
     * @throws CouldNotSaveException
     */
    public function save(Banner $banner): Banner
    {
        try {
            $this->resource->save($banner);
        } catch (\Exception $exception) {
            throw new CouldNotSaveException(
                __('The banner could not be saved: %1', $exception->getMessage()),
                $exception
            );
        }

        return $banner;
    }

    /**
     * @throws CouldNotDeleteException
     */
    public function delete(Banner $banner): void
    {
        try {
            $this->resource->delete($banner);
        } catch (\Exception $exception) {
            throw new CouldNotDeleteException(
                __('The banner could not be deleted: %1', $exception->getMessage()),
                $exception
            );
        }
    }

    /**
     * @throws NoSuchEntityException
     * @throws CouldNotDeleteException
     */
    public function deleteById(int $bannerId): void
    {
        $this->delete($this->getById($bannerId));
    }
}
