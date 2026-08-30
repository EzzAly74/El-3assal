<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Edit / Enable-Disable / Delete controls on each branch row.
 *
 * ===========================================================================
 * WHY EVERY WRITING ACTION CARRIES A confirm BLOCK
 * ===========================================================================
 * Not politeness - mechanics. Magento's actions column only attaches its click
 * handler when isHandlerRequired() is true, and that is
 * `callback || confirm || !href`. An action with an href and neither of the
 * other two never reaches defaultCallback(), which is the ONLY code that
 * honours `post: true`; the browser follows the plain anchor as a GET, and a
 * POST-only controller correctly 404s.
 *
 * This was found the hard way on the homepage banner grid - see
 * Spartrak\Homepage\Ui\Component\Listing\Column\BannerActions, where the same
 * note is recorded. Both writing actions here carry one from the start.
 */
class BranchActions extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @param array<string, mixed> $dataSource
     * @return array<string, mixed>
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        foreach ($dataSource['data']['items'] as &$item) {
            $entityId = (int) ($item['branch_id'] ?? 0);

            if ($entityId <= 0) {
                continue;
            }

            $isActive = (int) ($item['is_active'] ?? 0) === 1;

            $item[$this->getData('name')] = [
                'edit' => [
                    'href' => $this->urlBuilder->getUrl(
                        'spartrak_pickup/branch/edit',
                        ['branch_id' => $entityId]
                    ),
                    'label' => __('Edit'),
                ],
                'status' => [
                    'href' => $this->urlBuilder->getUrl(
                        'spartrak_pickup/branch/setStatus',
                        ['branch_id' => $entityId, 'is_active' => $isActive ? 0 : 1]
                    ),
                    'label' => $isActive ? __('Disable') : __('Enable'),
                    'post' => true,
                    'confirm' => [
                        'title' => $isActive ? __('Disable branch') : __('Enable branch'),
                        'message' => $isActive
                            ? __('Shoppers stop being offered this branch at checkout straight away. Continue?')
                            : __('This branch becomes available at checkout straight away. Continue?'),
                    ],
                ],
                'delete' => [
                    'href' => $this->urlBuilder->getUrl(
                        'spartrak_pickup/branch/delete',
                        ['branch_id' => $entityId]
                    ),
                    'label' => __('Delete'),
                    'post' => true,
                    'confirm' => [
                        'title' => __('Delete branch'),
                        'message' => __('Orders already placed against this branch keep the name and address they were placed with. Continue?'),
                    ],
                ],
            ];
        }

        return $dataSource;
    }
}
