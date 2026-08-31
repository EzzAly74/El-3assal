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
use Magento\Directory\Helper\Data as DirectoryHelper;
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
        private readonly DirectoryHelper $directoryHelper,
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

        $this->assertRegionChosen($values, $existing);
        $this->updateRegionData($values);
        $this->fillCityFromGovernorate($values);

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
     * Refuse to save an address whose country needs a governorate and has none.
     *
     * ===========================================================================
     * THIS EXISTS BECAUSE OF A REAL, SILENT DATA-LOSS BUG
     * ===========================================================================
     * If the region select is submitted empty, everything "works": the address
     * saves, the modal closes, the card still looks right because the street and
     * the name are unchanged. The damage only surfaces at the very end, when
     * placing the order fails with Magento's own
     *
     *     Please specify a regionId in shipping address.
     *
     * - a message that names a field the shopper cannot see, on a screen that is
     * not the one they broke. Worse, an EDIT is how it happens: opening a saved
     * address whose stored region is not one of the configured governorates
     * shows an empty select, and pressing save then wipes the region that WAS
     * there.
     *
     * So an empty region is rejected at the point of the mistake, in words that
     * name the field on screen. The check only fires where Magento itself says a
     * region is required (general/region/state_required), so a country that does
     * not use them is unaffected.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $existing
     * @throws InputException
     */
    private function assertRegionChosen(array $values, array $existing): void
    {
        $countryId = (string) ($values['country_id'] ?? $existing['country_id'] ?? '');

        if ($countryId === '' || !$this->directoryHelper->isRegionRequired($countryId)) {
            return;
        }

        if (!empty($values['region_id'])) {
            return;
        }

        $exception = new InputException();
        $exception->addError(__('Please choose a governorate.'));

        throw $exception;
    }

    /**
     * Fill `city` from the chosen governorate BEFORE the address is saved.
     *
     * ===========================================================================
     * WHY NOT THE OBSERVER THAT ALREADY DOES THIS
     * ===========================================================================
     * Spartrak\CustomerAddress\Observer\FillCityFromGovernorate fills city on
     * `customer_address_save_before`, and for every other path in the system it
     * is the right place. It is too late for this one.
     *
     * AddressRepository::save() validates and then saves, in that order:
     *
     *     $errors = $addressModel->validate();   // line 23 - city checked here
     *     if ($errors !== true) { throw ... }
     *     $addressModel->save();                 // line 31 - the event fires in here
     *
     * Magento\Customer\Model\Address\Validator\General requires a non-empty city,
     * so the exception is thrown eight lines before the observer would have
     * supplied one. The visible symptom was a form that had no city field
     * rejecting the address with `"city" is required. Enter and try again.`
     *
     * The observer stays: it still covers the quote address and every other
     * save path, and having both is not duplication - they guard different
     * moments, and this one has to be before validation by definition.
     *
     * @param array<string, mixed> $values
     */
    private function fillCityFromGovernorate(array &$values): void
    {
        if (trim((string) ($values['city'] ?? '')) !== '') {
            return;
        }

        // updateRegionData has already resolved the name onto the array by the
        // time this runs, so no second lookup is needed.
        $name = $values['region'] instanceof RegionInterface
            ? (string) $values['region']->getRegion()
            : (string) ($values['region'] ?? '');

        if ($name !== '') {
            $values['city'] = $name;
        }
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
