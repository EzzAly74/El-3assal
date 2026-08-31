<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Payment\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Payment\Helper\Data as PaymentHelper;

/**
 * Every payment method Magento knows about, for the admin's method picker.
 *
 * ===========================================================================
 * ALL METHODS, NOT ONLY THE ACTIVE ONES
 * ===========================================================================
 * getPaymentMethodList(false) returns inactive methods too, and that is
 * intentional. A merchant configuring a launch sets up how InstaPay will look
 * BEFORE switching it on; a picker that hid it would force them to enable a
 * live payment method just to write its description. Presentation for a method
 * that is off is simply never read.
 *
 * The list comes from Magento's own helper rather than from a scan of
 * config, so any method any module registers - Paymob's gateway today, a
 * second acquirer tomorrow - appears here the moment it is installed, with no
 * change to this file.
 */
class ActiveMethods implements OptionSourceInterface
{
    public function __construct(
        private readonly PaymentHelper $paymentHelper
    ) {
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $options = [];

        /**
         * Signature: getPaymentMethodList($sorted, $asLabelValue, $withGroups).
         * Unsorted and un-grouped, because this is a flat picker and the labels
         * are already alphabetised below by the merchant-visible string rather
         * than by internal code.
         */
        foreach ($this->paymentHelper->getPaymentMethodList(false) as $code => $title) {
            $code = (string) $code;

            // Magento includes the empty-code placeholder used by the admin's
            // own "please select" rows; it is not a payment method.
            if ($code === '') {
                continue;
            }

            $label = is_string($title) && $title !== '' ? $title : $code;

            $options[] = [
                'value' => $code,
                // The code is shown alongside the title because two acquirers
                // routinely ship methods with the same human title, and the
                // code is what this row actually keys on.
                'label' => sprintf('%s (%s)', $label, $code),
            ];
        }

        usort(
            $options,
            static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label'])
        );

        return $options;
    }
}
