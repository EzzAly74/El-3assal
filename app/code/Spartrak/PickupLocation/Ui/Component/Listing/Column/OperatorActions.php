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
 * Edit / Enable-Disable / Delete controls on each operator row.
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
class OperatorActions extends Column
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
            $entityId = (int) ($item['operator_id'] ?? 0);

            if ($entityId <= 0) {
                continue;
            }

            $isActive = (int) ($item['is_active'] ?? 0) === 1;

            $item[$this->getData('name')] = [
                'edit' => [
                    'href' => $this->urlBuilder->getUrl(
                        'spartrak_pickup/operator/edit',
                        ['operator_id' => $entityId]
                    ),
                    'label' => __('Edit'),
                ],
                'status' => [
                    'href' => $this->urlBuilder->getUrl(
                        'spartrak_pickup/operator/setStatus',
                        ['operator_id' => $entityId, 'is_active' => $isActive ? 0 : 1]
                    ),
                    'label' => $isActive ? __('Disable') : __('Enable'),
                    'post' => true,
                    'confirm' => [
                        'title' => $isActive ? __('Disable operator') : __('Enable operator'),
                        'message' => $isActive
                            ? __('The filter chip for this operator disappears from checkout and its depots stop showing one. The depots themselves stay available. Continue?')
                            : __('This operator reappears as a filter chip at checkout. Continue?'),
                    ],
                ],
                'delete' => [
                    'href' => $this->urlBuilder->getUrl(
                        'spartrak_pickup/operator/delete',
                        ['operator_id' => $entityId]
                    ),
                    'label' => __('Delete'),
                    'post' => true,
                    'confirm' => [
                        'title' => __('Delete operator'),
                        'message' => __('Depots assigned to this operator keep working but lose their chip. Continue?'),
                    ],
                ],
            ];
        }

        return $dataSource;
    }
}
