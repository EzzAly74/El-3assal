<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAccount\ViewModel;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Helper\Session\CurrentCustomer;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Spartrak\CustomerAuth\Model\Phone\Normalizer;
use Spartrak\CustomerAuth\ViewModel\CustomerEmail;

/**
 * The three values on the "حسابي" card — Figma 562:11030.
 *
 * ===========================================================================
 * WHY A VIEW MODEL AND NOT THE DASHBOARD BLOCK
 * ===========================================================================
 * Core's Dashboard\Info block answers "name" and "email" but knows nothing
 * about a phone number, and the phone is the identifier this storefront
 * actually signs people in with (Spartrak_CustomerAuth). The card also has to
 * apply the placeholder-email rule, which is a Spartrak concern that has no
 * business inside a core block.
 *
 * The same three values are read twice — once by the card's read-only state and
 * once by its edit form — so they are answered in one place and both states
 * render from it.
 *
 * ===========================================================================
 * THE EMAIL CAN BE ABSENT, AND ABSENT IS NOT EMPTY
 * ===========================================================================
 * A phone-registered customer has a SYNTHESISED address in customer_entity —
 * see Spartrak_CustomerAuth's PlaceholderEmail. It must never reach the DOM,
 * not merely never be displayed: view-source and password managers both read
 * value attributes. getEmail() returns null for those rows and the template
 * renders the field empty, which is also exactly what the edit form needs so
 * the shopper can add a real one.
 */
class Profile implements ArgumentInterface
{
    /**
     * Registered by Spartrak_CustomerAuth's AddPhoneAttributes patch.
     */
    private const ATTRIBUTE_PHONE = 'phone_number';

    private ?CustomerInterface $customer = null;
    private bool $resolved = false;

    public function __construct(
        private readonly CurrentCustomer $currentCustomer,
        private readonly CustomerEmail $customerEmail,
        private readonly Normalizer $phoneNormalizer
    ) {
    }

    /**
     * The signed-in customer, or null if there somehow isn't one.
     *
     * Every account page is behind Magento's account controller guard, so null
     * is not a state a shopper can reach — but a template that fatals on a
     * logged-out edge case is worse than one that renders empty fields.
     */
    public function getCustomer(): ?CustomerInterface
    {
        if (!$this->resolved) {
            $this->resolved = true;

            try {
                $this->customer = $this->currentCustomer->getCustomer();
            } catch (NoSuchEntityException) {
                $this->customer = null;
            }
        }

        return $this->customer;
    }

    /**
     * "اسم المستخدم" — one field in the design, two columns in Magento.
     *
     * Figma draws a single "username" input holding "Mohamed Ahmed". Magento
     * stores firstname and lastname separately and both are required, so the
     * READ state joins them and the EDIT form keeps them as the two inputs the
     * platform validates — see the template. Joining here rather than in the
     * template keeps the two states reading the same method.
     */
    public function getName(): string
    {
        $customer = $this->getCustomer();

        if ($customer === null) {
            return '';
        }

        return trim(implode(' ', array_filter([
            $customer->getFirstname(),
            $customer->getMiddlename(),
            $customer->getLastname(),
        ])));
    }

    public function getFirstname(): string
    {
        return (string) ($this->getCustomer()?->getFirstname() ?? '');
    }

    public function getLastname(): string
    {
        return (string) ($this->getCustomer()?->getLastname() ?? '');
    }

    /**
     * The customer's real email, or null when the stored value is a synthesised
     * placeholder. See the class docblock.
     */
    public function getEmail(): ?string
    {
        $email = $this->getCustomer()?->getEmail();

        if (!is_string($email) || trim($email) === '') {
            return null;
        }

        return $this->customerEmail->isPlaceholder($email) ? null : $email;
    }

    public function hasEmail(): bool
    {
        return $this->getEmail() !== null;
    }

    /**
     * The national number as a shopper writes it — "01207245632".
     */
    public function getPhoneNational(): string
    {
        return $this->phoneParts()['national'];
    }

    /**
     * The dialling code for the fixed box beside it — "+20".
     */
    public function getPhoneDialCode(): string
    {
        return $this->phoneParts()['dial'];
    }

    public function hasPhone(): bool
    {
        return $this->getPhoneNational() !== '';
    }

    /**
     * The number exactly as stored, in E.164.
     *
     * The edit card's phone dialog compares what the shopper typed against
     * this to decide whether anything changed, and that comparison has to be
     * made on the CANONICAL form. Rebuilding it in the browser by concatenating
     * the dial code and the national part would put the trunk-prefix rule —
     * "01…" locally, "+201…" stored — in a second place, in a language with no
     * access to the configured country. One value, rendered once.
     */
    public function getPhoneE164(): string
    {
        $customer = $this->getCustomer();
        $attribute = $customer?->getCustomAttribute(self::ATTRIBUTE_PHONE);

        return $attribute !== null ? (string) $attribute->getValue() : '';
    }

    /**
     * @return array{dial: string, national: string}
     */
    private function phoneParts(): array
    {
        $customer = $this->getCustomer();
        $attribute = $customer?->getCustomAttribute(self::ATTRIBUTE_PHONE);
        $stored = $attribute !== null ? (string) $attribute->getValue() : '';

        return $this->phoneNormalizer->toLocalParts($stored);
    }
}
