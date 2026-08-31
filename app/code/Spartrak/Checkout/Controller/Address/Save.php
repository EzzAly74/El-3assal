<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Checkout\Controller\Address;

use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\RegionInterface;
use Magento\Customer\Api\Data\RegionInterfaceFactory;
use Magento\Customer\Model\Address\Mapper as AddressMapper;
use Magento\Customer\Model\Metadata\FormFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\Api\DataObjectHelper;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;
use Spartrak\Checkout\Model\CheckoutAddressList;

/**
 * Creates OR updates one customer address from the checkout's address form.
 *
 * ===========================================================================
 * ONE ENDPOINT FOR BOTH, ON PURPOSE
 * ===========================================================================
 * Create and edit differ by exactly one thing: whether an `address_id` came in.
 * Everything else - extracting the attributes, resolving the region, applying
 * the default-shipping flag, saving, and rebuilding the list the checkout shows
 * - is identical. Two controllers would have been two copies of that, and the
 * copies would have drifted the first time one of them was fixed.
 *
 * ===========================================================================
 * WHY IT MIRRORS Magento\Customer\Controller\Address\FormPost
 * ===========================================================================
 * That is Magento's own implementation of this exact operation, and it is
 * subtle in ways that are not obvious from the outside:
 *
 *   - it runs the posted values through the `customer_address_edit` METADATA
 *     FORM rather than reading $_POST directly. That is what applies each
 *     attribute's own validation, casts its value, and picks up custom
 *     attributes - which is how `additional_phone` is handled here without a
 *     single line naming it;
 *   - it resolves `region_id` into a full RegionInterface, because an address
 *     saved with only an id has no region NAME and prints as a blank line;
 *   - it merges the extracted values ON TOP of the existing address, so a
 *     field the form does not post keeps its stored value instead of being
 *     silently blanked.
 *
 * Deviating from any of those would have produced an address that saved
 * successfully and was quietly wrong.
 *
 * ===========================================================================
 * OWNERSHIP
 * ===========================================================================
 * The customer id NEVER comes from the request. It is read from the session,
 * and an incoming `address_id` is loaded and then checked against it - so a
 * signed-in shopper cannot post someone else's address id and edit it. A
 * mismatch is reported as "not found" rather than "not yours", which is also
 * what stops the endpoint being used to test whether an id exists.
 */
class Save implements HttpPostActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly CustomerSession $customerSession,
        private readonly FormKeyValidator $formKeyValidator,
        private readonly JsonFactory $resultJsonFactory,
        private readonly AddressRepositoryInterface $addressRepository,
        private readonly AddressInterfaceFactory $addressFactory,
        private readonly RegionInterfaceFactory $regionDataFactory,
        private readonly RegionFactory $regionFactory,
        private readonly AddressMapper $addressMapper,
        private readonly FormFactory $formFactory,
        private readonly DataObjectHelper $dataObjectHelper,
        private readonly CheckoutAddressList $checkoutAddressList,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): ResultInterface
    {
        $result = $this->resultJsonFactory->create();

        if (!$this->formKeyValidator->validate($this->request)) {
            return $result->setHttpResponseCode(403)->setData([
                'success' => false,
                'message' => (string) __('Your session has expired. Please refresh the page and try again.'),
            ]);
        }

        /**
         * Guests have no address book, so there is nothing here for them to
         * write to. This is not a fallback - the checkout's own save path
         * handles a guest by keeping the address on the quote, and calling this
         * endpoint at all would be a bug in the caller.
         */
        if (!$this->customerSession->isLoggedIn()) {
            return $result->setHttpResponseCode(403)->setData([
                'success' => false,
                'message' => (string) __('Please sign in to save an address.'),
            ]);
        }

        try {
            $address = $this->buildAddress();
            $saved = $this->addressRepository->save($address);
        } catch (InputException $e) {
            // Field-level validation. Every message is returned, because
            // "the address is invalid" tells a shopper nothing about which box
            // to fix.
            $errors = array_map(
                static fn ($error): string => (string) $error->getMessage(),
                $e->getErrors()
            );

            return $result->setHttpResponseCode(400)->setData([
                'success' => false,
                'message' => (string) $e->getMessage(),
                'errors'  => $errors ?: [(string) $e->getMessage()],
            ]);
        } catch (NoSuchEntityException $e) {
            return $result->setHttpResponseCode(404)->setData([
                'success' => false,
                'message' => (string) __('That address could not be found.'),
            ]);
        } catch (LocalizedException $e) {
            return $result->setHttpResponseCode(400)->setData([
                'success' => false,
                'message' => (string) $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            // Logged rather than swallowed: a shopper who cannot save an
            // address cannot complete the order, and the reason must be
            // findable afterwards.
            $this->logger->error('Spartrak: could not save a checkout address.', ['exception' => $e]);

            return $result->setHttpResponseCode(500)->setData([
                'success' => false,
                'message' => (string) __('We could not save your address. Please try again.'),
            ]);
        }

        return $result->setData([
            'success'    => true,
            'address_id' => (int) $saved->getId(),
            // The whole list, freshly read, in the exact shape the checkout's
            // own address-list model expects. See CheckoutAddressList for why
            // it is rebuilt server-side rather than patched in the browser.
            'addresses'  => $this->checkoutAddressList->getForCurrentCustomer(),
            'message'    => (string) __('Your address has been saved.'),
        ]);
    }

    /**
     * @throws NoSuchEntityException when the id is not this customer's
     * @throws LocalizedException
     */
    private function buildAddress(): AddressInterface
    {
        $customerId = (int) $this->customerSession->getCustomerId();
        $existing = $this->getExistingAddressData($customerId);

        /**
         * The metadata form is what makes this correct rather than merely
         * working. `customer_address_edit` is the form `additional_phone`
         * declares itself in (Spartrak_CustomerAddress's data patch sets
         * used_in_forms), so extracting through it picks the field up with its
         * own validation, and would pick up any future address attribute the
         * same way - with no change to this file.
         */
        $addressForm = $this->formFactory->create(
            'customer_address',
            'customer_address_edit',
            $existing
        );

        $extracted = $addressForm->extractData($this->request);
        $values = $addressForm->compactData($extracted);

        $this->updateRegionData($values);

        $addressObject = $this->addressFactory->create();

        /**
         * Merged ON TOP of the existing address, not used in its place. The
         * checkout's form does not post every attribute an address can carry -
         * company and postcode are hidden by
         * Spartrak\Checkout\Plugin\Checkout\AddressFormLayout - and populating
         * from the extracted values alone would blank each of them on every
         * edit.
         */
        $this->dataObjectHelper->populateWithArray(
            $addressObject,
            array_merge($existing, $values),
            AddressInterface::class
        );

        $addressObject->setCustomerId($customerId);

        if (isset($existing['id'])) {
            $addressObject->setId((int) $existing['id']);
        }

        $this->applyDefaultFlags($addressObject, $existing);

        return $addressObject;
    }

    /**
     * The address being edited, as a flat array - or [] when creating.
     *
     * @return array<string, mixed>
     * @throws NoSuchEntityException
     */
    private function getExistingAddressData(int $customerId): array
    {
        $addressId = (int) $this->request->getParam('address_id');

        if ($addressId <= 0) {
            return [];
        }

        $existing = $this->addressRepository->getById($addressId);

        /**
         * THE OWNERSHIP CHECK. Cast both sides: the session returns an int and
         * the address an int-ish string depending on the driver, and a
         * !== between the two is always true - which would pass every id.
         */
        if ((int) $existing->getCustomerId() !== $customerId) {
            throw new NoSuchEntityException(__('That address could not be found.'));
        }

        return $this->addressMapper->toFlatArray($existing);
    }

    /**
     * Resolve region_id into a full region, exactly as core's own controller
     * does.
     *
     * An address saved with only a region id has no region NAME, and prints
     * with a blank line where the governorate should be - on the address card,
     * in the admin, and on the order.
     *
     * @param array<string, mixed> $values
     */
    private function updateRegionData(array &$values): void
    {
        if (!empty($values['region_id'])) {
            $region = $this->regionFactory->create()->load($values['region_id']);
            $values['region_code'] = $region->getCode();
            $values['region'] = $region->getDefaultName();
        }

        $regionData = [
            RegionInterface::REGION_ID   => !empty($values['region_id']) ? $values['region_id'] : null,
            RegionInterface::REGION      => !empty($values['region']) ? $values['region'] : null,
            RegionInterface::REGION_CODE => !empty($values['region_code']) ? $values['region_code'] : null,
        ];

        $region = $this->regionDataFactory->create();
        $this->dataObjectHelper->populateWithArray($region, $regionData, RegionInterface::class);
        $values['region'] = $region;
    }

    /**
     * ===========================================================================
     * THE DEFAULT-SHIPPING FLAG, AND WHY IT MUST BE A REAL BOOLEAN
     * ===========================================================================
     * Magento\Customer\Model\ResourceModel\Address\Relation decides what to do
     * with the customer's default_shipping column like this:
     *
     *     if ($object->getIsDefaultShipping())            -> make this the default
     *     elseif (... && $object->getIsDefaultShipping() === false && this IS
     *             the current default)                    -> clear the default
     *
     * That second branch is a STRICT comparison. Passing 0, '0' or '' turns the
     * toggle off in the UI and leaves the customer's default pointing at this
     * address forever, with nothing to indicate it failed. So the value is cast
     * to a real bool before it goes anywhere near the model.
     *
     * ===========================================================================
     * BILLING IS DELIBERATELY LEFT ALONE
     * ===========================================================================
     * The existing value is carried through unchanged. It is passed rather than
     * omitted because the same Relation clears a default when it sees a strict
     * `false` - so re-asserting the address's own current state is what
     * guarantees an edit cannot silently drop the customer's default billing
     * address, which the checkout has no business touching.
     *
     * @param array<string, mixed> $existing
     */
    private function applyDefaultFlags(AddressInterface $address, array $existing): void
    {
        $address->setIsDefaultShipping(
            $this->request->getParam('default_shipping') !== null
                ? (bool) $this->request->getParam('default_shipping')
                : (bool) ($existing['default_shipping'] ?? false)
        );

        $address->setIsDefaultBilling((bool) ($existing['default_billing'] ?? false));
    }
}
