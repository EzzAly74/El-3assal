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
 * Edit / Enable-Disable / Delete controls on each section row.
 *
 * The status control is built per row from the row's OWN current state, so it
 * always reads as the action it performs - "Disable" on something enabled and
 * "Enable" on something disabled - and posts the state it intends rather than
 * asking the server to invert whatever it finds. See Controller SetStatus.
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

            $isActive = (int) ($item['is_active'] ?? 0) === 1;

            $item[$this->getData('name')] = [
                'edit' => [
                    'href' => $this->urlBuilder->getUrl('spartrak_homepage/section/edit', ['section_id' => $sectionId]),
                    'label' => __('Edit'),
                ],
                'status' => [
                    'href' => $this->urlBuilder->getUrl(
                        'spartrak_homepage/section/setStatus',
                        ['section_id' => $sectionId, 'is_active' => $isActive ? 0 : 1]
                    ),
                    'label' => $isActive ? __('Disable') : __('Enable'),
                    // POST, like delete: this writes, so it must not be a GET
                    // link. The grid adds the admin form key for us.
                    'post' => true,
                    // ===========================================================
                    // THE CONFIRM IS LOAD-BEARING, NOT DECORATION
                    // ===========================================================
                    // `post` alone does NOTHING here. Magento's actions column
                    // only attaches a click handler when isHandlerRequired() is
                    // true, and that is `callback || confirm || !href`. With an
                    // href and neither of the other two, the template's
                    // click="$col.getActionHandler(...)" binds `undefined`, the
                    // browser just follows the <a href> as an ordinary GET, and
                    // defaultCallback() - the only code that honours `post` -
                    // never runs. The POST-only controller then correctly 404s.
                    //
                    // Delete has always worked precisely because it carries a
                    // confirm. Verified the hard way: clicking this action
                    // navigated to a 404 until this block was added.
                    //
                    // It earns its place on its own terms too - this takes a
                    // section on or off the live homepage the instant it is
                    // clicked, with no save step to reconsider at.
                    'confirm' => [
                        'title' => $isActive ? __('Disable section') : __('Enable section'),
                        'message' => $isActive
                            ? __('This section stops appearing on the homepage straight away. Continue?')
                            : __('This section starts appearing on the homepage straight away. Continue?'),
                    ],
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
