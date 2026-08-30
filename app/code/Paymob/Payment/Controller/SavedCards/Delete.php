<?php
namespace Paymob\Payment\Controller\SavedCards;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Paymob\Payment\Model\PaymobCardsToken;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\ResponseInterface;

class Delete extends Action
{
    protected $paymobCardsModel;
    protected $resultRedirectFactory;
    protected $customerSession;

    public function __construct(
        Context $context,
        PaymobCardsToken $paymobCardsModel,
        RedirectFactory $resultRedirectFactory,
        CustomerSession $customerSession
    ) {
        $this->paymobCardsModel = $paymobCardsModel;
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->customerSession = $customerSession;
        parent::__construct($context);
    }

    public function execute()
    {
        $cardId = $this->getRequest()->getParam('id');
        $customerId = $this->customerSession->getCustomerId();

        $card = $this->paymobCardsModel->load($cardId);
       
        if ($card->getId() && $card->getUserId() == $customerId) {
            try {
                $card->delete();
                $this->messageManager->addSuccessMessage(__('Card deleted successfully.'));
            } catch (\Exception $e) {
                $this->messageManager->addErrorMessage(__('Unable to delete the card.'));
            }
        } else {
            $this->messageManager->addErrorMessage(__('Invalid card ID.'));
        }
        $resultRedirect = $this->resultRedirectFactory->create();
        return $this->resultRedirectFactory->create()->setPath('paymob_payment/savedcards');
    }
}
