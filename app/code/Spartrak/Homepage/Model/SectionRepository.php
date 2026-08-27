<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Model;

use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Spartrak\Homepage\Model\ResourceModel\Section as SectionResource;

/**
 * Persistence service for homepage sections.
 *
 * WHY THIS IS NOT A SearchCriteria-STYLE @api REPOSITORY
 * ---------------------------------------------------------------------------
 * A full SearchCriteria repository exists to serve REST/GraphQL and
 * third-party integrations. This entity has neither: it is admin-authored
 * content read by exactly one page. Adding the SearchResults plumbing would
 * be several hundred lines nothing calls.
 *
 * What the repository IS here for is the part that genuinely matters — one
 * place that owns load/save/delete, converts resource-model failures into
 * Magento's own typed exceptions, and keeps the admin controllers free of
 * persistence detail (CLAUDE.md section 8).
 *
 * The storefront deliberately does NOT come through here. It reads through
 * Model\SectionList, which loads a section and all of its children in a fixed
 * number of queries — going entity-by-entity through a repository on a page
 * whose first requirement is LCP would be exactly the N+1 the brief rules
 * out. If this entity ever needs a web API, that is the point to add the
 * SearchCriteria layer, and it can be added without touching either caller.
 */
class SectionRepository
{
    public function __construct(
        private readonly SectionResource $resource,
        private readonly SectionFactory $sectionFactory
    ) {
    }

    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $sectionId): Section
    {
        $section = $this->sectionFactory->create();
        $this->resource->load($section, $sectionId);

        if (!$section->getId()) {
            throw new NoSuchEntityException(
                __('No homepage section exists with ID "%1".', $sectionId)
            );
        }

        return $section;
    }

    /**
     * @throws CouldNotSaveException
     */
    public function save(Section $section): Section
    {
        try {
            $this->resource->save($section);
        } catch (\Exception $exception) {
            throw new CouldNotSaveException(
                __('The homepage section could not be saved: %1', $exception->getMessage()),
                $exception
            );
        }

        return $section;
    }

    /**
     * @throws CouldNotDeleteException
     */
    public function delete(Section $section): void
    {
        try {
            $this->resource->delete($section);
        } catch (\Exception $exception) {
            throw new CouldNotDeleteException(
                __('The homepage section could not be deleted: %1', $exception->getMessage()),
                $exception
            );
        }
    }

    /**
     * @throws NoSuchEntityException
     * @throws CouldNotDeleteException
     */
    public function deleteById(int $sectionId): void
    {
        $this->delete($this->getById($sectionId));
    }
}
