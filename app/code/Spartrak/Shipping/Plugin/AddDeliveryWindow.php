<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Shipping\Plugin;

use Magento\Quote\Api\Data\ShippingMethodExtensionFactory;
use Magento\Quote\Api\Data\ShippingMethodInterface;
use Magento\Quote\Model\Cart\ShippingMethodConverter;
use Spartrak\Shipping\Model\Carrier\Spartrak;
use Spartrak\Shipping\Model\DeliveryWindow;

/**
 * Puts the configured delivery window onto each SpareTrak rate as it is
 * converted into the DTO the checkout consumes.
 *
 * ===========================================================================
 * WHY HERE
 * ===========================================================================
 * `ShippingMethodConverter::modelToDataObject()` is the single choke point
 * where a rate model becomes a `ShippingMethodInterface` — every path that
 * shows rates to a shopper goes through it, whether the quote is addressed by
 * id, by a guest address estimate, or by the admin order-create screen. Filling
 * the attribute here means the value is present wherever the rate is, and the
 * Knockout template never has to ask a second question to render a card.
 *
 * The alternative — reading config in the template — would put business data
 * lookup inside presentation, which §8 rules out, and would have to be repeated
 * for the desktop and mobile renderers.
 *
 * ===========================================================================
 * IT TOUCHES NOTHING BUT ITS OWN CARRIER
 * ===========================================================================
 * The first thing this does is check the carrier code. Rates from flatrate,
 * freeshipping, a courier integration or anything a merchant installs later
 * pass through completely unaltered — no extension object is created for them,
 * so nothing about their payload changes.
 */
class AddDeliveryWindow
{
    public function __construct(
        private readonly DeliveryWindow $deliveryWindow,
        private readonly ShippingMethodExtensionFactory $extensionFactory
    ) {
    }

    /**
     * @param ShippingMethodConverter $subject
     * @param ShippingMethodInterface $result
     * @return ShippingMethodInterface
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterModelToDataObject(
        ShippingMethodConverter $subject,
        ShippingMethodInterface $result
    ): ShippingMethodInterface {
        if ($result->getCarrierCode() !== Spartrak::CODE) {
            return $result;
        }

        $window = $this->deliveryWindow->get((string) $result->getMethodCode());

        if ($window === null) {
            // The merchant has not stated a window for this tier. The card
            // simply renders without the line — see DeliveryWindow::get().
            return $result;
        }

        // Never discard extension attributes another module already set: this
        // plugin is one of possibly several contributing to the same DTO.
        $extension = $result->getExtensionAttributes() ?: $this->extensionFactory->create();

        $extension->setSpartrakDeliveryDaysMin($window['min']);
        $extension->setSpartrakDeliveryDaysMax($window['max']);

        $result->setExtensionAttributes($extension);

        return $result;
    }
}
