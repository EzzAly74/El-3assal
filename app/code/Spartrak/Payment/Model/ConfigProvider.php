<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Payment\Model;

use Magento\Checkout\Model\ConfigProviderInterface;

/**
 * Publishes the payment-row presentation into window.checkoutConfig.
 *
 * Only presentation crosses this boundary - descriptions, brand mark URLs and
 * a sort order. The method list itself is already in checkoutConfig under
 * `payment`, put there by Magento, and this deliberately does not duplicate,
 * filter or re-order it. If a method is missing from the checkout, the answer
 * is in Stores > Configuration > Payment Methods, never here.
 */
class ConfigProvider implements ConfigProviderInterface
{
    public function __construct(
        private readonly PresentationCatalog $catalog
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return [
            'spartrakPayment' => [
                'presentation' => $this->catalog->getForCurrentStore(),
            ],
        ];
    }
}
