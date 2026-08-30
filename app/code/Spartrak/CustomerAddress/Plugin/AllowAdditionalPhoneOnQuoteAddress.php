<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAddress\Plugin;

use Magento\Customer\Api\AddressMetadataInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\Quote\Address\CustomAttributeListInterface;

/**
 * Lets the quote address accept `additional_phone` as a custom attribute.
 *
 * ===========================================================================
 * WITHOUT THIS, THE VALUE IS DISCARDED IN SILENCE
 * ===========================================================================
 * Magento\Quote\Model\Quote\Address::getCustomAttributesCodes() returns
 * array_keys($this->attributeList->getAttributes()), and core's
 * CustomAttributeList::getAttributes() is a hardcoded `return []`. Every
 * custom attribute posted to a quote address is therefore rejected by
 * setCustomAttribute()'s in_array() guard unless it appears here.
 *
 * A PLUGIN, NOT A PREFERENCE. Core's class returns a literal empty array with
 * no constructor argument to extend, so there is nothing to configure - but
 * replacing it outright would take ownership of a class any other module may
 * also need to extend, and the last preference declared would win. An
 * `after` plugin composes: several modules can each add their own attribute
 * and all of them survive.
 */
class AllowAdditionalPhoneOnQuoteAddress
{
    private const ATTRIBUTE_CODE = 'additional_phone';

    public function __construct(
        private readonly AddressMetadataInterface $addressMetadata
    ) {
    }

    /**
     * @param CustomAttributeListInterface $subject
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetAttributes(CustomAttributeListInterface $subject, array $result): array
    {
        try {
            // The real metadata rather than a placeholder: only the KEYS are
            // read by Quote\Address today, but returning the genuine attribute
            // keeps this honest for any consumer that wants more than a code.
            $result[self::ATTRIBUTE_CODE] = $this->addressMetadata
                ->getAttributeMetadata(self::ATTRIBUTE_CODE);
        } catch (NoSuchEntityException) {
            // The data patch has not run yet - during setup:upgrade itself, or
            // on a database restored from before this module existed. Returning
            // the list unchanged is correct: the attribute genuinely does not
            // exist, and throwing here would break every checkout until the
            // upgrade completed.
            return $result;
        }

        return $result;
    }
}
