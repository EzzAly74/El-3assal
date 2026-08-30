<?php
namespace Paymob\Payment\Block\Customer;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Customer\Model\Session as CustomerSession;

class SavedCards extends Template
{
    protected $paymobCardsCollection;
    protected $customerSession;

    public function __construct(
        Context $context,
        \Paymob\Payment\Model\ResourceModel\PaymobCardsToken\CollectionFactory $paymobCardsCollection,
        CustomerSession $customerSession,
        array $data = []
    ) {
        $this->paymobCardsCollection = $paymobCardsCollection;
        $this->customerSession = $customerSession;
        parent::__construct($context, $data);
    }

    public function getUserCards()
    {
        // Ensure the session is started
        $this->customerSession->start();
        
        // Check if the customer is logged in
        if (!$this->customerSession->isLoggedIn()) {
            return null; // Or you can return an empty collection
        }

        // Retrieve the customer ID
        $customerId = $this->customerSession->getCustomerId();
        $collection = $this->paymobCardsCollection->create();
        $collection->addFieldToFilter('user_id', $customerId);

        return $collection;
    }

    public function getDeleteUrl($cardId)
    {
        return $this->getUrl('paymob_payment/savedcards/delete', ['id' => $cardId]);
    }
}