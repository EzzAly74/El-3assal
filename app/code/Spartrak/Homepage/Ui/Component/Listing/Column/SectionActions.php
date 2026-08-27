<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Edit / Delete controls on each section row.
 */
class SectionActions extends Column
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
            $sectionId = (int) ($item['section_id'] ?? 0);

            if ($sectionId <= 0) {
                continue;
            }

            $item[$this->getData('name')] = [
                'edit' => [
                    'href' => $this->urlBuilder->getUrl('spartrak_homepage/section/edit', ['section_id' => $sectionId]),
                    'label' => __('Edit'),
                ],
                'delete' => [
                    'href' => $this->urlBuilder->getUrl('spartrak_homepage/section/delete', ['section_id' => $sectionId]),
                    'label' => __('Delete'),
                    // post=true is what makes the grid submit this as a POST
                    // rather than following a link — the delete controller is
                    // HttpPostActionInterface and would reject a GET.
                    'post' => true,
                    'confirm' => [
                        'title' => __('Delete section'),
                        'message' => __(
                            'This deletes the section and every banner and category pick inside it. Continue?'
                        ),
                    ],
                ],
            ];
        }

        return $dataSource;
    }
}
