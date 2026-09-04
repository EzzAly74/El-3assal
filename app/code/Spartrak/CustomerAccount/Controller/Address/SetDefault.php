<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAccount\Controller\Address;

use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Controller\AccountInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * "تعيين كافتراضي" on an address card — Figma 721:35537.
 *
 * ===========================================================================
 * WHY A CONTROLLER AND NOT A LINK TO THE EDIT FORM
 * ===========================================================================
 * Magento's own address book has no such action: making an address the default
 * means opening its edit form, ticking two checkboxes and saving. Figma draws
 * one button on the card, and that is the right interaction — it is a
 * single-field change to a record the shopper is already looking at.
 *
 * Sending them to the edit form instead would be the frontend workaround
 * CLAUDE.md section 5 rules out: a five-step flow standing in for the one-step
 * one the design specifies, because the one-step one needed a controller.
 *
 * ===========================================================================
 * BILLING AND SHIPPING ARE SET TOGETHER, ON PURPOSE
 * ===========================================================================
 * Magento keeps two defaults. This storefront collects ONE kind of address:
 * the checkout asks for a shipping address (Figma 557:5173) and never for a
 * separate billing one, and the account card carries a single "العنوان
 * الافتراضي" badge rather than two.
 *
 * If this set only the shipping default, a shopper's billing default would
 * quietly stay pointed at some older row — an invoice address diverging from
 * the delivery address, with no screen anywhere in this storefront that could
 * show them why or let them fix it. Setting both keeps the data model honest
 * about what the UI actually promises.
 *
 * ===========================================================================
 * OWNERSHIP IS CHECKED, NOT ASSUMED
 * ===========================================================================
 * The address id arrives from the browser. Without the owner check, any signed
 * -in customer could POST another customer's address id and repoint that
 * person's default. AddressRepository does not enforce this for us — it will
 * happily save any address it can load.
 *
 * CSRF is handled by the platform: this is a POST action that does not
 * implement CsrfAwareActionInterface, so Magento's form-key validation runs
 * before execute() and rejects a request without a valid key.
 */
class SetDefault implements HttpPostActionInterface, AccountInterface
{
    public function __construct(
        private readonly \Magento\Framework\App\RequestInterface $request,
        private readonly RedirectFactory $redirectFactory,
        private readonly CustomerSession $customerSession,
        private readonly AddressRepositoryInterface $addressRepository,
        private readonly ManagerInterface $messageManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): Redirect
    {
        $redirect = $this->redirectFactory->create()->setPath('customer/address/index');
        $addressId = (int) $this->request->getParam('id');

        if ($addressId === 0) {
            return $redirect;
        }

        try {
            $address = $this->addressRepository->getById($addressId);

            if ((int) $address->getCustomerId() !== (int) $this->customerSession->getCustomerId()) {
                // Deliberately the same message a missing address gets. Saying
                // "that address is not yours" confirms that the id exists.
                throw new NoSuchEntityException(__('The address you requested is no longer available.'));
            }

            $address->setIsDefaultShipping(true);
            $address->setIsDefaultBilling(true);
            $this->addressRepository->save($address);

            $this->messageManager->addSuccessMessage(__('This address is now your default address.'));
        } catch (NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            // Logged rather than swallowed — CLAUDE.md section 9. The shopper
            // gets a generic message because the exception text may name
            // internals.
            $this->logger->critical($e);
            $this->messageManager->addErrorMessage(__('We could not set this address as your default.'));
        }

        return $redirect;
    }
}
