<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\ProductVideo\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Yes / No / "leave it to the store setting" — the three states a playback
 * override actually has.
 *
 * The empty option is FIRST and is therefore the default, so a video nobody has
 * an opinion about follows Stores > Configuration > Product Video — and keeps
 * following it when a merchant changes that setting later. A two-state Yes/No
 * would have silently frozen every existing video at whatever the default
 * happened to be on the day it was saved.
 *
 * That third state is why the columns behind these fields are nullable; see
 * etc/db_schema.xml.
 */
class InheritOptions implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => '', 'label' => __('Use store default')],
            ['value' => '1', 'label' => __('Yes')],
            ['value' => '0', 'label' => __('No')],
        ];
    }
}
