<?php

namespace Paymob\Payment\Controller\Adminhtml\Integration;

use Magento\Backend\App\Action;
use Magento\Framework\Controller\Result\JsonFactory;
use Paymob\Payment\Model\IntegrationManager;
use Magento\Framework\Exception\LocalizedException;

class Fetch extends Action
{
    protected $resultJsonFactory;
    protected $integrationManager;

    public function __construct(
        Action\Context $context,
        JsonFactory $resultJsonFactory,
        IntegrationManager $integrationManager
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->integrationManager = $integrationManager;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        $apiKey = $this->getRequest()->getParam('api_key');
        $secretKey = $this->getRequest()->getParam('secret_key');
        $publicKey = $this->getRequest()->getParam('public_key');

        try {
            if (!$apiKey || !$secretKey || !$publicKey) {
                throw new LocalizedException(__('API Key, Secret Key, and Public Key are required.'));
            }

            $message = $this->integrationManager->fetchAndCreateMethods($apiKey, $secretKey, $publicKey);

            return $result->setData([
                'success' => true,
                'message' => $message
            ]);

        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Paymob_Payment::config');
    }
}
