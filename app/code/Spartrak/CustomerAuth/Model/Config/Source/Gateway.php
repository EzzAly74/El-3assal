<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Spartrak\CustomerAuth\Model\Sms\GatewayResolver;

/**
 * Lists whatever gateways are actually registered in di.xml.
 *
 * Built from the resolver's own pool rather than a hardcoded list, so adding a
 * provider is genuinely one di.xml entry: the admin dropdown, the resolver and
 * the validation all read the same source and cannot drift apart.
 */
class Gateway implements OptionSourceInterface
{
    public function __construct(
        private readonly GatewayResolver $gatewayResolver
    ) {
    }

    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        $options = [];

        foreach ($this->gatewayResolver->getAvailableGateways() as $code => $gateway) {
            $options[] = ['value' => $code, 'label' => $gateway->getTitle()];
        }

        return $options;
    }
}
