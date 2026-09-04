<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Block\Adminhtml\Button;

use Magento\Backend\Model\UrlInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

/**
 * The "Back" button on all three pickup forms.
 *
 * Shared by branch, depot and operator: '*\/*\/' resolves against the CURRENT
 * route, so the same instance sends the admin back to whichever grid they came
 * from without needing to know which entity it is rendering for.
 *
 * A ButtonProviderInterface class rather than inline XML on purpose. The
 * ui_component XSD only permits <label>, <class>, <url>, <aclResource> and
 * <param> inside <button>, and Ui\Config\Converter\Buttons DISCARDS the PHP
 * `class` attribute the moment a <button> has any child element - so inline
 * button config silently loses its behaviour. Declaring the class on a
 * self-closing <button/> is the only form that keeps it.
 */
class BackButton implements ButtonProviderInterface
{
    public function __construct(
        private readonly UrlInterface $url
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getButtonData(): array
    {
        return [
            'label' => __('Back'),
            'class' => 'back',
            'on_click' => sprintf("location.href = '%s';", $this->url->getUrl('*/*/')),
            'sort_order' => 10,
        ];
    }
}
