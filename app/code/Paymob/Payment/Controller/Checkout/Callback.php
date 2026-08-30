<?php
namespace Paymob\Payment\Controller\Checkout;

use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Paymob\Library\Paymob;
use Paymob\Payment\Model\PaymobCardsToken;
use Magento\Framework\App\Filesystem\DirectoryList;
class Callback extends \Magento\Framework\App\Action\Action implements CsrfAwareActionInterface
{
	protected $order;
	protected $invoiceService;
	protected $resultFactory;
	protected $paymob;
	protected $creditmemoFactory;
	protected $creditmemoService;
	protected $orderFactory;
	protected $checkoutSession;
	protected $transaction;
	protected $stockManagement;
	protected $resourceConnection;
	protected $deploymentConfig;
	protected $quoteFactory;
	protected $paymobOrderCollection;
	protected $hmac;
	protected $email;
	protected $storeManager;
	protected $paymobCardsCollection;
	protected $customer;
	protected $paymobCardsTokenModel;
	protected $directoryList;

	public function __construct(
		\Magento\Framework\App\Action\Context $context,
		\Magento\Sales\Model\Order $order,
		\Magento\Sales\Model\Service\InvoiceService $invoiceService,
		\Magento\Store\Model\StoreManagerInterface $storeManager,
		\Paymob\Payment\Model\Paymob $paymob,
		\Magento\Sales\Model\Order\CreditmemoFactory $creditmemoFactory,
		\Magento\Sales\Model\Service\CreditmemoService $creditmemoService,
		\Magento\Sales\Model\OrderFactory $orderFactory,
		\Magento\Checkout\Model\Session $checkoutSession,
		\Magento\Framework\DB\Transaction $transaction,
		\Magento\CatalogInventory\Api\StockManagementInterface $stockManagement,
		\Magento\Framework\App\ResourceConnection $resourceConnection,
		\Magento\Framework\App\DeploymentConfig $deploymentConfig,
		\Magento\Quote\Model\QuoteFactory $quoteFactory,
		\Paymob\Payment\Model\ResourceModel\PaymobOrderInfo\CollectionFactory $paymobOrderCollection,
		\Magento\Sales\Model\Order\Email\Sender\OrderSender $email,
		\Paymob\Payment\Model\ResourceModel\PaymobCardsToken\CollectionFactory $paymobCardsCollection,
		\Magento\Customer\Model\Customer $customer,
		PaymobCardsToken $paymobCardsTokenModel,
		DirectoryList $directoryList
	) {
		parent::__construct($context);
		$this->order = $order;
		$this->invoiceService = $invoiceService;
		$this->resultFactory = $context->getResultFactory();
		$this->storeManager = $storeManager;
		$this->paymob = $paymob;
		$this->creditmemoFactory = $creditmemoFactory;
		$this->creditmemoService = $creditmemoService;
		$this->hmac = $paymob->getConfigData("hmac");
		$this->orderFactory = $orderFactory;
		$this->checkoutSession = $checkoutSession;
		$this->transaction = $transaction;
		$this->stockManagement = $stockManagement;
		$this->resourceConnection = $resourceConnection;
		$this->deploymentConfig = $deploymentConfig;
		$this->quoteFactory = $quoteFactory;
		$this->paymobOrderCollection = $paymobOrderCollection;
		$this->email = $email;
		$this->paymobCardsCollection = $paymobCardsCollection;
		$this->customer = $customer;
		$this->paymobCardsTokenModel = $paymobCardsTokenModel;
		$this->directoryList = $directoryList;
	}

	public function execute()
	{
		if (Paymob::filterVar('REQUEST_METHOD', 'SERVER') === 'POST') {
			$this->callWebhookAction();
		} else if (Paymob::filterVar('REQUEST_METHOD', 'SERVER') === 'GET' && !empty($this->getRequest()->getParams())) {
			return $this->callRetrunAction();
		} else {
			$this->redirectWithError("This Server is not ready to handle your request right now.");
		}
	}

	/**
	 * Call back action, In case of GET Request
	 *
	 */
	protected function callRetrunAction()
	{
		$response = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_REDIRECT);
		if (!Paymob::verifyHmac($this->hmac, $this->getRequest()->getParams())) {
			$this->redirectWithError('Error, There is something went wong with the qoute!');
		}
		$orderId = Paymob::getIntentionId(Paymob::filterVar('merchant_order_id'));

		Paymob::addLogs($this->paymob->getConfigData("debug"), $this->getDebugFilePath(), ' In Callback action, for order# ' . $orderId, json_encode($this->getRequest()->getParams()));
		$order = $this->orderFactory->create()->loadByIncrementId($orderId);
		if (empty($order->getId())) {
			$this->redirectWithError('Sorry, you are accessing wrong information');
		}
		$storeCode = $order->getStore()->getCode();
		$baseUrl = $this->storeManager->getStore($storeCode)->getBaseUrl();
		if (
			Paymob::filterVar('success') === "true" &&
			Paymob::filterVar('is_voided') === "false" &&
			Paymob::filterVar('is_refunded') === "false"
		) {
			if ($order->isCanceled() || $order->getState() === \Magento\Sales\Model\Order::STATE_CLOSED) {
				// TODO : need to add Paymob Stock management
				$this->pendOrder($order, "Paymob: revert order to the pending payment status");
			}
			//in case the pendOrder did not work
			if ($order->isCanceled()) {
				$order->setState(\Magento\Sales\Model\Order::STATE_HOLDED)
					->setStatus(\Magento\Sales\Model\Order::STATE_HOLDED)
					->addStatusHistoryComment(__('Paymob: Payment successful but order is holded'));
				$order->save();
				$note = 'Paymob: Payment successful but order is holded';
				$msg = 'In callback action, for order #' . $orderId . ' ' . $note;
				Paymob::addLogs($this->paymob->getConfigData("debug"), $this->getDebugFilePath(), $msg);

			} else {
				$response_url = $baseUrl . 'checkout/onepage/success';
				$note = 'Paymob : Payment Approved ';
				$msg = 'In callback action, for order #' . $orderId . ' ' . $note;
				Paymob::addLogs($this->paymob->getConfigData("debug"), $this->getDebugFilePath(), $msg);
				//order is not pending or canceled
				$status = $order->getState();
				if ($status !== \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT && $status !== \Magento\Sales\Model\Order::STATE_CANCELED) {
					$this->messageManager->addSuccess(__('Payment successful'));
					$response->setUrl($response_url);
					return $response;
				}
				$info = '<br/>Payment Method ID: ' . Paymob::filterVar('integration_id') . '<br/> Transaction done by: ' . Paymob::filterVar('source_data_type') . " / " . Paymob::filterVar('source_data_sub_type');
				$info .= '<br/> Transaction ID: ' . '<b>' . Paymob::filterVar('id') . '</b>' . '<br/>' . ' Order ID: ' . '<b>' . Paymob::filterVar('order') . '</b>';
				$this->markOrderAsPaid($order, $orderId, Paymob::filterVar('id'), $info);
				$response->setUrl($response_url);
				return $response;
			}
		} else {
			$gatewayError = Paymob::filterVar('data_message');
			$error = 'Payment is not completed due to ' . $gatewayError;
			$msg = 'Order #' . $orderId . ' ' . $error;
			Paymob::addLogs($this->paymob->getConfigData("debug"), $this->getDebugFilePath(), $msg);
			$this->cancelOrder($order, $msg);
			$this->redirectWithError($msg);
		}
	}

	/**
	 * Webhook event action, In case of POST Request
	 *
	 */
	protected function callWebhookAction()
	{
		$post_data = file_get_contents('php://input');
		$json_data = json_decode($post_data, true);
		$params = $this->getRequest()->getParams();
		if (isset($json_data['type']) && isset($params['hmac']) && $json_data['type'] === 'TRANSACTION' && !empty($params['hmac'])) {
			$this->acceptWebhook($json_data);
		} elseif (isset($json_data['type']) && $json_data['type'] === 'TOKEN') {
			$this->SaveCardToken($json_data);
		} else {
			$this->flashWebhook($json_data);
		}
	}

	public function acceptWebhook($json_data)
	{
		$obj = $json_data['obj'];
		$params = $this->getRequest()->getParams();
		$orderId = substr($obj['order']['merchant_order_id'], 0, -11);
		Paymob::addLogs($this->paymob->getConfigData("debug"), $this->getDebugFilePath(), ' In Webhook action, for order# ' . $orderId, json_encode($json_data));
		if (Paymob::verifyHmac($this->hmac, $json_data, null, $params['hmac'])) {
			$order = $this->orderFactory->create()->loadByIncrementId($orderId);
			if (empty($order)) {
				return "Order is not found with #: $orderId";
			}

			$transaction = $obj['id'];
			$paymobId = $obj['order']['id'];
			$info = '<br/>Payment Method ID: ' . $obj['integration_id'] . '<br/> Transaction done by: ' . $obj['source_data']['type'] . " / " . $obj['source_data']['sub_type'];
			$info .= '<br/>' . ' Transaction ID: ' . '<b>' . $transaction . '</b>' . '<br/>' . 'Order ID: ' . '<b>' . $paymobId . '</b>';

			$msg = 'Paymob  Webhook for Order #' . $orderId;
			if (
				$obj['success'] === true &&
				$obj['is_voided'] === false &&
				$obj['is_refunded'] === false &&
				$obj['pending'] === false &&
				$obj['is_void'] === false &&
				$obj['is_refund'] === false &&
				$obj['error_occured'] === false
			) {
				$note = 'Paymob  Webhook: Payment Approved ';
				$msg = $msg . ' ' . $note;
				Paymob::addLogs($this->paymob->getConfigData("debug"), $this->getDebugFilePath(), $msg);
				$this->markOrderAsPaid($order, $paymobId, $transaction, $info);
			} else {
				$note = 'Paymob Webhook: Payment is not completed ';
				$msg = $msg . ' ' . $note . "<br/>";
				Paymob::addLogs($this->paymob->getConfigData("debug"), $this->getDebugFilePath(), $msg);
				$this->cancelOrder($order, $note . $info, true);
			}
			return "Order updated: $orderId";
		} else {
			return "Can not verify order: $orderId";
		}
	}
	public function flashWebhook($json_data)
	{
		$orderId = Paymob::getIntentionId($json_data['intention']['extras']['creation_extras']['merchant_intention_id']);
		Paymob::addLogs($this->paymob->getConfigData("debug"), $this->getDebugFilePath(), ' In Webhook action, for order# ' . $orderId, json_encode($json_data));
		$collection = $this->paymobOrderCollection->create()->addFieldToFilter('magento_order_id', $orderId);
		$item = $collection->getFirstItem();
		$itemData = $item->getData();

		if (empty($itemData['paymob_cents_amount']) || empty($itemData['paymob_intention_id']) || empty($itemData['magento_order_id'])) {
			return "Can not retrieve correct information : $orderId";
		}

		$OrderIntensionId = $itemData['paymob_intention_id'];
		if ($OrderIntensionId != $json_data['intention']['id']) {
			return "intension ID is not matched for order : $orderId";
		}

		$OrderAmount = $itemData['paymob_cents_amount'];
		if ($OrderAmount != $json_data['intention']['intention_detail']['amount']) {
			return "intension amount are not matched for order : $orderId";
		}

		$country = Paymob::getCountryCode($this->paymob->getConfigData("secret_key"));
		$cents = 100;
		if ($country == 'omn') {
			$cents = 1000;
		}

		if (!Paymob::verifyHmac($this->hmac, $json_data, array('id' => $OrderIntensionId, 'amount' => $OrderAmount, 'cents' => $cents))) {
			return "can not verify order: $orderId";
		}

		$referenceNo = $itemData['magento_order_id'];
		$order = $this->orderFactory->create()->loadByIncrementId($orderId);
		if (empty($order)) {
			return "Order is not found with #: $orderId";
		}

		$status = $order->getState();
		/*if ($status !== \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT && \Magento\Sales\Model\Order::STATE_CANCELED) {
																			  return 'Order '.$referenceNo.' is already processed';
																		  }*/
		if (empty($json_data['transaction'])) {
			return "Empty transaction array";
		}
		$msg = 'Paymob  Webhook for Order #' . $orderId;
		$trans = $json_data['transaction'];


		$transaction = $json_data['transaction']['id'];
		$paymobId = $json_data['transaction']['order']['id'];
		$info = '<br/>Payment Method ID: ' . $json_data['transaction']['integration_id'] . '<br/> Transaction done by: ' . $json_data['transaction']['source_data']['type'] . " / " . $json_data['transaction']['source_data']['sub_type'];
		$info .= '<br/>' . ' Transaction ID: ' . '<b>' . $transaction . '</b>' . '<br/>' . 'Order ID: ' . '<b>' . $paymobId . '</b>';

		if (
			$trans['success'] === true &&
			$trans['is_voided'] === false &&
			$trans['is_refunded'] === false &&
			$trans['is_capture'] === false
		) {
			$note = 'Paymob  Webhook: Payment Approved ';
			$msg = $msg . ' ' . $note;
			Paymob::addLogs($this->paymob->getConfigData("debug"), $this->getDebugFilePath(), $msg);
			$this->markOrderAsPaid($order, $referenceNo, $trans['id'], $info);
			return "Order updated: $orderId" . $note;
		} else if (
			$trans['success'] === false &&
			$trans['is_refunded'] === true &&
			$trans['is_voided'] === false &&
			$trans['is_capture'] === false
		) {
			$note = 'Paymob  Webhook: Payment Refunded';
			$msg = $msg . ' ' . $note;
			Paymob::addLogs($this->paymob->getConfigData("debug"), $this->getDebugFilePath(), $msg);
			return "Order refunded in paymob account: $orderId" . $note;
		} else if (
			$trans['success'] === false &&
			$trans['is_voided'] === false &&
			$trans['is_refunded'] === false &&
			$trans['is_capture'] === false
		) {
			$note = 'Paymob Webhook: Payment is not completed ';
			$msg = $msg . ' ' . $note . "<br/>";
			Paymob::addLogs($this->paymob->getConfigData("debug"), $this->getDebugFilePath(), $msg);
			return $this->cancelOrder($order, $note . $info, true);
		}
	}
	public function saveCardToken($json_data)
	{
		$obj = $json_data['obj'];
		Paymob::addLogs($this->paymob->getConfigData("debug"), $this->getDebugFilePath(), ' In Save Card Token Webhook , for User -- ' . $obj['email'], json_encode($json_data));
		$customer_id = (int) $this->customer->setWebsiteId($this->storeManager->getStore()->getWebsiteId())->loadByEmail($obj['email'])->getId();

		if ($customer_id) {
			$existingToken = $this->paymobCardsCollection->create()
				->addFieldToFilter('user_id', $customer_id)
				->addFieldToFilter('card_subtype', $obj['card_subtype'])
				->addFieldToFilter('masked_pan', $obj['masked_pan'])
				->getFirstItem();

			if ($existingToken->getId()) {
				// Update existing token
				$existingToken->setData('token', $obj['token']);
				$existingToken->save();
			} else {
				// Add a new record by creating a fresh instance of the model
				$newToken = $this->paymobCardsTokenModel->setData(
					[
						'user_id' => $customer_id,
						'token' => $obj['token'],
						'masked_pan' => $obj['masked_pan'],
						'card_subtype' => $obj['card_subtype'],
					]
				);
				$newToken->save(); // Save the new token
			}
			return "Token Saved: user id: $customer_id, user email: " . $obj['email'];
		} else {
			return 'No User Found with this email: ' . $obj['email'];
		}
	}
	/**
	 * Mark order as paid
	 *
	 * @param Order  $order
	 * @param string $orderId
	 */
	public function markOrderAsPaid($order, $orderId, $transId, $info = null)
	{
		$note = 'Payment Approved ';
		$msg = 'Order #' . $orderId . ' ' . $note . $info;
		$this->messageManager->addSuccess($msg);
		//set order status
		$order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING)
			->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING)
			->addStatusHistoryComment($msg)
			->setIsCustomerNotified(true);

		$order->save();
		//set payment
		$payment = $order->getPayment();
		$payment->setTransactionId($transId);
		$payment->addTransaction(\Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE, null, true);
		$order->save();
		$this->addMagentoInvoice($order, $orderId, $transId);
		//send email
		$this->email->send($order);
	}

	/**
	 * Create magento invoice
	 *
	 * @param Order  $order
	 * @param string $orderId
	 */
	public function addMagentoInvoice($order, $orderId, $transId)
	{
		if ($order->canInvoice()) {
			$invoice = $this->invoiceService->prepareInvoice($order);
			if ($invoice->getTotalQty()) {
				$invoice->setTransactionId($transId);
				$invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
				$invoice->register();
				$transaction = $this->transaction->addObject($invoice)->addObject($invoice->getOrder());
				$transaction->save();
			} else {
				$order->addStatusHistoryComment("Paymob: Can not create the Magento invoice without products.");
			}
		} else {
			$order->addStatusHistoryComment("Paymob: Can not create the Magento invoice.");
		}
	}

	/**
	 * Cancel the order and restore the magento qoute for failed Payment
	 *
	 * @param Order  $order
	 * @param string $error     
	 * @param boolean $webhook
	 */
	public function cancelOrder($order, $error, $webhook = false)
	{
		$quote = $this->quoteFactory->create()->loadByIdWithoutStore($order->getQuoteId());
		if ($quote->getId()) {
			$quote->setIsActive(1)->setReservedOrderId(null)->save();
			$this->checkoutSession->replaceQuote($quote);
			$this->checkoutSession->restoreQuote();
			if (!$webhook) {
				$error = 'Paymob CallBack :' . $error;
			}
			if ($order->getState() === $order::STATE_PENDING_PAYMENT) {

				$order->registerCancellation($error);
			} else {
				$order->addStatusHistoryComment($error);
			}
			$order->save();
			if ($webhook) {
				return $error;
			} else {
				$this->redirectWithError($error);
			}
		} else {
			if ($webhook) {
				return 'Error, There is something went wong with the qoute!';
			} else {
				$this->redirectWithError('Error, There is something went wong with the qoute!');
			}

		}
	}

	/** 
	 * redirect to cart page with error message
	 * 
	 * @param string  $error
	 */
	public function redirectWithError($error)
	{
		$this->messageManager->addErrorMessage($error);
		$this->_redirect('checkout/cart', ['_secure' => false]);
	}

	/** 
	 * Ref: https://magento.stackexchange.com/questions/297133/magento-2-2-10-change-cancelled-order-to-pending
	 * Change order status from cancel to pending payment
	 * 
	 * @param Order  $order
	 * @param string $note
	 */
	public function pendOrder($order, $note)
	{
		$productStockQty = [];
		foreach ($order->getAllVisibleItems() as $item) {
			$productStockQty[$item->getProductId()] = $item->getQtyCanceled();
			foreach ($item->getChildrenItems() as $child) {
				$productStockQty[$child->getProductId()] = $item->getQtyCanceled();
				$child->setQtyCanceled(0);
				$child->setTaxCanceled(0);
				$child->setDiscountTaxCompensationCanceled(0);
			}
			$item->setQtyCanceled(0);
			$item->setTaxCanceled(0);
			$item->setDiscountTaxCompensationCanceled(0);
		}

		$order->setSubtotalCanceled(0);
		$order->setBaseSubtotalCanceled(0);
		$order->setTaxCanceled(0);
		$order->setBaseTaxCanceled(0);
		$order->setShippingCanceled(0);
		$order->setBaseShippingCanceled(0);
		$order->setDiscountCanceled(0);
		$order->setBaseDiscountCanceled(0);
		$order->setTotalCanceled(0);
		$order->setBaseTotalCanceled(0);

		/* Revert the inventory */
		try {
			$this->stockManagement->registerProductsSale(
				$productStockQty,
				$order->getStore()->getWebsiteId()
			);

			/**
			 * @var \Magento\Framework\DB\Adapter\AdapterInterface $connection
			 */
			$connection = $this->resourceConnection->getConnection();

			//get table name
			$prefix = $this->deploymentConfig->get('db/table_prefix');
			$tableName = $prefix . 'inventory_reservation';

			foreach ($order->getAllItems() as $item) {
				$sku = $item->getSku();
				$orderId = $order->getId();

				$metadata = "{\"event_type\":\"order_canceled\",\"object_type\":\"order\",\"object_id\":\"$orderId\"%";

				$selectQuery = "SELECT * FROM $tableName WHERE sku='$sku' AND metadata LIKE '$metadata'";
				$result = $connection->fetchAll($selectQuery);

				if ($result && count($result) > 0) {
					$deleteQuery = "DELETE FROM $tableName WHERE sku='$sku' AND metadata LIKE '$metadata'";
					$connection->query($deleteQuery);
				}
			}
			$order->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT)->setStatus(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);

			if (!empty($note)) {
				$order->addStatusHistoryComment($note);
			}
			$order->setInventoryProcessed(true);

			$order->save();
		} catch (\Exception $e) {
			$order->addStatusHistoryComment($e->getMessage());
			$order->save();
		}
	}
	public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
	{
		return null;
	}

	public function validateForCsrf(RequestInterface $request): ?bool
	{
		return true;
	}

	private function getDebugFilePath(): string
	{
		$logDir = $this->directoryList->getPath(DirectoryList::LOG);
		return $logDir . '/paymob.log';
	}

}