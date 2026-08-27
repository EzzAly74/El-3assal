<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * A select, not a free-text field, so the code length can only ever be a value
 * the OTP input component can actually render and the security trade-off noted
 * in config.xml stays explicit in the admin UI.
 */
class CodeLength implements OptionSourceInterface
{
    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 4, 'label' => __('4 digits (matches the Figma component — pair with 3 max attempts)')],
            ['value' => 5, 'label' => __('5 digits')],
            ['value' => 6, 'label' => __('6 digits (recommended)')],
            ['value' => 7, 'label' => __('7 digits')],
            ['value' => 8, 'label' => __('8 digits')],
        ];
    }
}
