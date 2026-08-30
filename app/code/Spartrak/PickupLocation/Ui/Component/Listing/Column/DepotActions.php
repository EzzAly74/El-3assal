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
 * Edit / Enable-Disable / Delete controls on each depot row.
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
class DepotActions extends Column
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
            $entityId = (int) ($item['depot_id'] ?? 0);

            if ($entityId <= 0) {
                continue;
            }

            $isActive = (int) ($item['is_active'] ?? 0) === 1;

            $item[$this->getData('name')] = [
                'edit' => [
                    'href' => $this->urlBuilder->getUrl(
                        'spartrak_pickup/depot/edit',
                        ['depot_id' => $entityId]
                    ),
                    'label' => __('Edit'),
                ],
                'status' => [
                    'href' => $this->urlBuilder->getUrl(
                        'spartrak_pickup/depot/setStatus',
                        ['depot_id' => $entityId, 'is_active' => $isActive ? 0 : 1]
                    ),
                    'label' => $isActive ? __('Disable') : __('Enable'),
                    'post' => true,
                    'confirm' => [
                        'title' => $isActive ? __('Disable depot') : __('Enable depot'),
                        'message' => $isActive
                            ? __('Shoppers stop being offered this depot at checkout straight away. Continue?')
                            : __('This depot becomes available at checkout straight away. Continue?'),
                    ],
                ],
                'delete' => [
                    'href' => $this->urlBuilder->getUrl(
                        'spartrak_pickup/depot/delete',
                        ['depot_id' => $entityId]
                    ),
                    'label' => __('Delete'),
                    'post' => true,
                    'confirm' => [
                        'title' => __('Delete depot'),
                        'message' => __('Orders already placed against this depot keep the name and address they were placed with. Continue?'),
                    ],
                ],
            ];
        }

        return $dataSource;
    }
}
