<?php
namespace Paymob\Payment\Controller\SavedCards;

use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\App\Action\Context;

class Index extends \Magento\Customer\Controller\AbstractAccount
{
    protected $resultPageFactory;

    public function __construct(
        Context $context,
        PageFactory $resultPageFactory
    ) {
        $this->resultPageFactory = $resultPageFactory;
        parent::__construct($context);
    }

    public function execute()
    {
        // Create a result page using the custom layout handle
        $resultPage = $this->resultPageFactory->create();
        
        // Set the layout handle to the custom XML
        $resultPage->addHandle('paymob_customer_saved_cards');
        
        // Optionally set the page title
        $resultPage->getConfig()->getTitle()->set(__('Paymob Saved Credit Cards'));
        
        return $resultPage;
    }
}
