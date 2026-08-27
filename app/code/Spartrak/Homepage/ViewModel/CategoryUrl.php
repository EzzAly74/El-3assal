<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\ViewModel;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Psr\Log\LoggerInterface;

/**
 * The storefront URL of a section's source category.
 *
 * Split out of the carousel block so the "view all" link is resolved by one
 * class for every product section rather than three copies of the same
 * try/catch. Memoised because two sections may legitimately point at the same
 * category while content is being set up.
 */
class CategoryUrl implements ArgumentInterface
{
    /** @var array<int, string> */
    private array $urls = [];

    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Returns '' for a missing or disabled category, and the template then
     * omits the link rather than rendering one that 404s.
     */
    public function get(int $categoryId): string
    {
        if ($categoryId <= 0) {
            return '';
        }

        if (isset($this->urls[$categoryId])) {
            return $this->urls[$categoryId];
        }

        try {
            $category = $this->categoryRepository->get($categoryId);

            return $this->urls[$categoryId] = (string) $category->getUrl();
        } catch (\Exception $exception) {
            $this->logger->warning(
                'Spartrak_Homepage: no URL for category ' . $categoryId . ': ' . $exception->getMessage()
            );

            return $this->urls[$categoryId] = '';
        }
    }
}
