<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Block\Adminhtml\Button;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

/**
 * The "Save" button on all three pickup forms.
 *
 * One class, three virtualTypes in etc/adminhtml/di.xml - the entities differ
 * only in the form they drive and the word on the button, which is exactly the
 * case etc/di.xml already argues virtualTypes exist for.
 *
 * $formNamespace must be the form's <namespace>. Magento_Ui/js/form/button-adapter
 * resolves `targetName` through uiRegistry.async(), and the form component
 * registers itself as "<namespace>.<namespace>" - NOT "<namespace>" and not
 * "<namespace>.areas". A targetName that does not match registers an async
 * callback that never fires, so the button would look fine and do nothing.
 *
 * No `back` parameter is sent: Controller\Adminhtml\AbstractSave redirects to
 * the grid when `back` is absent, which is what a plain Save should do.
 */
class SaveButton implements ButtonProviderInterface
{
    public function __construct(
        private readonly string $formNamespace,
        private readonly string $label
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getButtonData(): array
    {
        return [
            'label' => __($this->label),
            'class' => 'save primary',
            'data_attribute' => [
                'mage-init' => [
                    'buttonAdapter' => [
                        'actions' => [
                            [
                                'targetName' => $this->formNamespace . '.' . $this->formNamespace,
                                'actionName' => 'save',
                                'params' => [false],
                            ],
                        ],
                    ],
                ],
            ],
            'sort_order' => 90,
        ];
    }
}
