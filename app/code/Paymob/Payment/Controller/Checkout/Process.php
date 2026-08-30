<?php
namespace Paymob\Payment\Controller\Checkout;

use Paymob\Library\Paymob;
use Paymob\Payment\Helper\LogHelper;
use Magento\Catalog\Helper\Data as TaxHelper;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\Filesystem\DirectoryList;

class Process extends \Magento\Framework\App\Action\Action
{
    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $checkoutSession;
    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;
    /**
     * @var \Paymob\Payment\Model\PaymobOrderInfo
     */
    protected $paymobOrderInfo;
    protected $base_url;
    protected $taxHelper;
    protected $paymobCardsCollection;
    protected $directoryList;
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Paymob\Payment\Model\PaymobOrderInfo $paymobOrderInfo,
        TaxHelper $taxHelper,
        StoreManagerInterface $storeManager,
        \Paymob\Payment\Model\ResourceModel\PaymobCardsToken\CollectionFactory $paymobCardsCollection,
        DirectoryList $directoryList
    ) {
        parent::__construct($context);
        $this->checkoutSession = $checkoutSession;
        $this->paymobOrderInfo = $paymobOrderInfo;
        $this->scopeConfig = $scopeConfig;
        $this->taxHelper = $taxHelper;
        $this->base_url = $storeManager->getStore()->getBaseUrl();
        $this->paymobCardsCollection = $paymobCardsCollection;
        $this->directoryList = $directoryList;
    }
    public function execute()
    {
        try {
            $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/mostafaTestNewPaymob.log');
            $logger = new \Zend_Log();
            $logger->addWriter($writer);
            $orderID = $this->checkoutSession->getLastOrderId();
            $order = $this->_objectManager->create('Magento\Sales\Model\Order')->load($orderID);

            if ($order) {
                if (sizeof($this->getUserTokens($order->getCustomerId())) > 3) {
                    $url = $this->base_url . 'paymob_payment/savedcards/';
                    $url = '<a href="' . $url . '">Paymob Saved Cards</a>';
                    $url = 'Please remove your cards from ' . $url . ' ' . 'to complete your purchase';
                    $msg = 'Ops,Max number of card tokens is 3.' . '<br>' . $url;
                    throw new \Exception('Error, ' . $msg);
                } else {
                    $orderRef = $order->getIncrementId();
                    // change order state & status to pending payment.
                    $order->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
                    $order->setStatus(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
                    $order->setIsNotified(false);
                    $order->save();
                    //------- 
                    $addressObj = $order->getBillingAddress();
                    if (!is_object($addressObj)) {
                        $addressObj = $order->getShippingAddress();
                        if (!is_object($addressObj)) {
                            throw new \Exception('Error, Billing or Shipping addresses should be set to create paymob invoice');
                        }
                    }
                    $address = $addressObj->getData();
                    $billing = [
                        "email" => $order->getCustomerEmail(),
                        "first_name" => $addressObj->getFirstname(),
                        "last_name" => $addressObj->getLastname(),
                        "street" => ($address['street']) ?? 'N.A',
                        "phone_number" => ($address['telephone']) ?? 'N.A',
                        "city" => ($address['city']) ?? 'N.A',
                        "country" => ($address['country_id']) ?? 'N.A',
                        "state" => ($address['region']) ?? 'N.A',
                        "postal_code" => ($address['postcode']) ?? 'N.A',
                    ];



                    $methodCode = $order->getPayment()->getMethod();
                    $scopeCode = $order->getStoreId();
                    $logger->info('Payment method code: ' . $methodCode);
                    $sk = $this->scopeConfig->getValue("payment/{$methodCode}/secret_key", \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $scopeCode);
                    $pk = $this->scopeConfig->getValue("payment/{$methodCode}/public_key", \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $scopeCode);
                    $has_items = $this->scopeConfig->getValue("payment/{$methodCode}/has_items", \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $scopeCode);
                    $integration_id = $this->getIntegrationIdByCategory($order, $methodCode, $scopeCode);
                    $logger->info('Using integration ID: ' . $integration_id);
                    //new custom field from 2b development
                    $is_original_price = $this->scopeConfig->getValue("payment/{$methodCode}/is_original_price", \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $scopeCode);
                    $discount_percentage = $this->scopeConfig->getValue("payment/{$methodCode}/discount_amount_percentage", \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $scopeCode);
                    //endnew custom field from 2b development
                    $integrations = explode(',', $integration_id);
                    $integration_ids = [];
                    $debug = $this->scopeConfig->getValue("payment/{$methodCode}/debug", \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $scopeCode);

                    foreach ($integrations as $id) {
                        $id = (int) trim($id);
                        if ($id > 0) {
                            array_push($integration_ids, $id);
                        }
                    }
                    $country = Paymob::getCountryCode($sk);
                    $cents = 100;
                    $round = 2;
                    if ($country == 'omn') {
                        $round = 3;
                        $cents = 1000;
                    }
                    $store = $order->getStore();
                    $logger->info('is_original_price value = ' . $is_original_price);
                    $amount = $this->calculateAmount($order, $round, $cents, $is_original_price);
                    $logger->info('Amount after calculateAmount: ' . $amount . ' (Type: ' . gettype($amount) . ')');
                    $shippingAmount = round($order->getBaseShippingAmount() ?? 0, $round) * $cents;
                    $logger->info('Shipping amount: ' . $shippingAmount);
                    $amount = $this->applyDiscountPercentage($amount, $discount_percentage, $round, $cents, $shippingAmount);
                    $logger->info('Final calculated amount (in smallest currency unit): ' . $amount . ' (Type: ' . gettype($amount) . ')');
                    $data = [
                        "amount" => $amount,
                        "currency" => $store->getBaseCurrencyCode(),
                        "payment_methods" => $integration_ids,
                        "billing_data" => $billing,
                        "expires_at" => $this->getExpiryTime($order, $country),
                        "extras" => ["merchant_intention_id" => $orderRef . '_' . time()],
                        "special_reference" => $orderRef . '_' . time()
                    ];
                    if ($has_items) {
                        $data['items'] = $this->getInvoiceItems($order);
                    }
                    if ($order->getCustomerId()) {

                        $data['card_tokens'] = $this->getUserTokens($order->getCustomerId());
                    }
                    $paymobReq = new Paymob($debug, $this->getDebugFilePath());
                    $status = $paymobReq->createIntention($sk, $data, $orderID,$cs='','POST');
                    if (!$status['success']) {
                        throw new \Exception('Error, ' . $status['message']);
                    }

                    $apiUrl = $paymobReq->getApiUrl($country);
                    $cs = $status['cs'];
                    $redirect_url = $apiUrl . "unifiedcheckout?publicKey=$pk&clientSecret=$cs";
                    $logger->info('Paymob redirect url: ' . $redirect_url);
                    //save paymob intention information
                    $this->paymobOrderInfo->addData(
                        [
                            'magento_order_id' => $orderRef,
                            'paymob_intention_id' => $status['intentionId'],
                            'paymob_cents_amount' => $status['centsAmount'],
                        ]
                    );
                    $this->paymobOrderInfo->save();
                    return $this->_redirect($redirect_url);
                }
            } else {
                throw new \Exception("Error, Order is not found!</p>");
            }
        } catch (\Exception $e) {

            $this->messageManager->addError($e->getMessage());
            $_quoteFactory = $this->_objectManager->create('\Magento\Quote\Model\QuoteFactory');
            $quote = $_quoteFactory->create()->loadByIdWithoutStore($order->getQuoteId());
            if ($quote->getId()) {
                $quote->setIsActive(1)->setReservedOrderId(null)->save();
                $this->checkoutSession->replaceQuote($quote);
                $resultRedirect = $this->resultRedirectFactory->create();
                if ($order && $order->getId()) {
                    if ($order->getState() === \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT) {
                        $order->registerCancellation($e->getMessage());
                    } else {
                        $order->addStatusHistoryComment($e->getMessage());
                    }
                    $order->save();
                }
                 $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/mostafaTestNewPaymob.log');
                    $logger = new \Zend_Log();
                    $logger->addWriter($writer);
                $logger->info('error' . $e->getMessage());
                $resultRedirect->setPath('checkout/cart');
                return $resultRedirect;
            } else {
                throw new \Exception('Error, There is something went wong with magento qoute!');
            }

        }

    }
    public function getUserTokens($customer_id)
    {
        $tokens = array();
        $data = array();
        $results = $this->paymobCardsCollection->create()
            ->addFieldToFilter('user_id', $customer_id)
            ->getItems();
        foreach ($results as $item) {
            // You can access each item and its data here
            $data[] = $item->getData();
        }
        if (!empty($data)) {
            foreach ($data as $value) {
                $tokens[] = $value['token'];
            }
        }
        return $tokens;
    }
    public function getExpiryTime($order, $country)
    {
        $expiryDate = '';
        //get Pending Payment Order Lifetime in minutes
        $orderPendingLifeTime = $this->scopeConfig->getValue('sales/orders/delete_pending_after', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $order->getStoreId());
        if ($orderPendingLifeTime) {
            $date = new \DateTime('now', new \DateTimeZone(Paymob::getTimeZone($country)));
            $date->modify("+$orderPendingLifeTime minute");
            $expiryDate = $date->format('Y-m-d\TH:i:s\Z');
        }
        return $expiryDate;
    }
    
    /**
     * Get integration ID based on product categories in the order
     * 
     * @param object $order The order object
     * @param array $categoryIntegrationMap Mapping of category IDs to integration IDs
     * @param string $defaultIntegrationId Default integration ID to use as fallback
     * @param object $logger Logger instance
     * @return string Selected integration ID
     */
    protected function getIntegrationIdByCategoryMapping($order, $categoryIntegrationMap, $defaultIntegrationId, $logger)
    {
        // Get all order items
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $items = $order->getAllItems();
        
        // Track which integration IDs are found in the order
        $foundIntegrationIds = [];
        $hasUnmappedProduct = false; // Track if any product has no matching category
        
        foreach ($items as $item) {
            if ($item->getProductType() != 'simple') {
                continue;
            }
            
            $productId = $item->getProductId();
            $product = $objectManager->create(\Magento\Catalog\Model\Product::class)->load($productId);
            
            // Get product category IDs
            $productCategoryIds = $product->getCategoryIds();
            $logger->info('Product ID ' . $productId . ' has categories: ' . implode(', ', $productCategoryIds));
            
            // Check if this product has any matching category
            $productHasMatch = false;
            
            // Check each product category against the mapping
            foreach ($productCategoryIds as $catId) {
                if (isset($categoryIntegrationMap[$catId])) {
                    $foundIntegrationIds[] = $categoryIntegrationMap[$catId];
                    $logger->info('Found category ' . $catId . ' mapped to integration ID: ' . $categoryIntegrationMap[$catId]);
                    $productHasMatch = true;
                }
            }
            
            // If this product has no matching categories, mark it
            if (!$productHasMatch) {
                $hasUnmappedProduct = true;
                $logger->info('Product ID ' . $productId . ' has no matching categories in the map');
            }
        }
        
        // Remove duplicate integration IDs
        $foundIntegrationIds = array_unique($foundIntegrationIds);
        $logger->info('Found integration IDs: ' . implode(', ', $foundIntegrationIds));
        $logger->info('Has unmapped product: ' . ($hasUnmappedProduct ? 'Yes' : 'No'));
        
        // If ANY product doesn't have a matching category, use default
        if ($hasUnmappedProduct) {
            $logger->info('At least one product has no matching category, using default integration ID');
            return $defaultIntegrationId;
        }
        
        // If exactly ONE integration ID is found for all products, use it
        if (count($foundIntegrationIds) === 1) {
            $selectedIntegrationId = $foundIntegrationIds[0];
            $logger->info('All products match single integration ID: ' . $selectedIntegrationId);
            return $selectedIntegrationId;
        } else if (count($foundIntegrationIds) > 1) {
            // Multiple different integration IDs found - mixed categories
            $logger->info('Mixed categories detected (multiple integration IDs), using default integration ID');
            return $defaultIntegrationId;
        } else {
            // No matching categories found at all
            $logger->info('No matching categories found, using default integration ID');
            return $defaultIntegrationId;
        }
    }
    
    public function getIntegrationIdByCategory($order, $methodCode, $scopeCode)
    {
        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/mostafaTestNewPaymob.log');
        $logger = new \Zend_Log();
        $logger->addWriter($writer);
        
        // Get default integration ID
        $integration_id = $this->scopeConfig->getValue("payment/{$methodCode}/integration_id", \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $scopeCode);
        
        $logger->info('Default integration ID: ' . $integration_id);
        $logger->info('Method Code: ' . $methodCode);
        
        // Switch case for different payment methods
        switch ($methodCode) {
            // case 'paymob_5273032':
            //     // Define category to integration ID mapping
            //     // Format: [category_id => integration_id]
            //     $categoryIntegrationMap = [
            //         2410 => 5367826,
            //         // Add more mappings as needed
            //     ];
                
            //     return $this->getIntegrationIdByCategoryMapping($order, $categoryIntegrationMap, $integration_id, $logger);
                
            //Add more cases for other payment methods if needed
            // case 'paymob_5146698':
            //     // Define different category mapping for another payment method
            //     $categoryIntegrationMap = [
            //         2410 => 5146699,
            //         // Add more mappings as needed
            //     ];
                
            //     return $this->getIntegrationIdByCategoryMapping($order, $categoryIntegrationMap, $integration_id, $logger);
            // case 'paymob_4609443':
            //     // Define different category mapping for another payment method
            //     $categoryIntegrationMap = [
            //         2410 => 5400868,
            //         // Add more mappings as needed
            //     ];
                    
            //     return $this->getIntegrationIdByCategoryMapping($order, $categoryIntegrationMap, $integration_id, $logger);
                            
            default:
                // Return default integration ID for all other methods
                $logger->info('Using default integration ID for method: ' . $methodCode);
                break;
        }
        
        return $integration_id;
    }
    
    public function applyDiscountPercentage($amount, $discount_percentage, $round, $cents, $shippingAmount = 0)
    {
        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/mostafaTestNewPaymob.log');
        $logger = new \Zend_Log();
        $logger->addWriter($writer);
        
        // If no discount percentage or invalid value, return original amount
        if (!$discount_percentage || $discount_percentage <= 0 || $discount_percentage >= 100) {
            $logger->info('No discount applied - returning original amount: ' . $amount);
            return (int) $amount;
        }
        
        $logger->info('Input amount (in cents): ' . $amount);
        $logger->info('Shipping amount (in cents): ' . $shippingAmount);
        $logger->info('Discount percentage: ' . $discount_percentage);
        
        // Convert amount back from cents to normal value
        $normalAmount = $amount / $cents;
        $normalShipping = $shippingAmount / $cents;
        
        $logger->info('Normal amount: ' . $normalAmount);
        $logger->info('Normal shipping: ' . $normalShipping);
        
        // Exclude shipping from the amount to be discounted
        $amountToDiscount = $normalAmount - $normalShipping;
        $logger->info('Amount to discount (excluding shipping): ' . $amountToDiscount);
        
        // Calculate discount amount (only on non-shipping amount)
        $discountAmount = ($amountToDiscount * $discount_percentage) / 100;
        $logger->info('Discount amount calculated: ' . $discountAmount);
        
        // Subtract discount from the amount (shipping remains intact)
        $finalAmount = $normalAmount - $discountAmount;
        $logger->info('Final amount before rounding: ' . $finalAmount);
        
        // Round the final amount first, then convert to cents as integer
        $roundedAmount = round($finalAmount, $round);
        $logger->info('Rounded amount: ' . $roundedAmount);
        
        $finalAmountInCents = (int) round($roundedAmount * $cents);
        $logger->info('Final amount in cents (as integer): ' . $finalAmountInCents);
        
        return $finalAmountInCents;
    }
    
    public function calculateAmount($order, $round, $cents, $is_original_price = false)
    {
        if ($is_original_price != 1) {
            // Use the default order total
            return (int) round(round($order->getBaseTotalDue(), $round) * $cents);
        }
        
        // Calculate amount based on original prices
        $amount = 0;
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $items_arr = $order->getAllItems();
        $priceIncTax = $this->scopeConfig->getValue('tax/calculation/price_includes_tax', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $order->getStoreId());
        
        // Product items with original price
        foreach ($items_arr as $item) {
            if ($item->getProductType() != 'simple') {
                continue;
            }
            
            $productId = $item->getProductId();
            $product = $objectManager->create(\Magento\Catalog\Model\Product::class)->load($productId);
            $qty = (int) $item->getQtyOrdered();
            
            // Use original price
            $priceExTax = $this->taxHelper->getTaxPrice($product, $product->getPrice(), false);
            $itemPrice = round($priceExTax ?? 0, $round);
            
            $amount += $itemPrice * $qty;
        }
        
        // Apply discount
        $discount1 = $order->getBaseDiscountAmount() ?? 0;
        if (!$priceIncTax) {
            $discount1 += $order->getBaseDiscountTaxCompensationAmount() ?? 0;
        }
        $discount = round($discount1, $round);
        $amount += $discount;
        
        // Apply shipping
        $shipping1 = $order->getBaseShippingAmount() ?? 0;
        $shipping = round($shipping1, $round);
        $amount += $shipping;
        
        // Apply additional fees (if any)
        $fees1 = $order->getBaseMageworxFeeAmount() ?? 0;
        $fees = round($fees1, $round);
        $amount += $fees;
        
        $productFees1 = $order->getBaseMageworxProductFeeAmount() ?? 0;
        $productFees = round($productFees1, $round);
        $amount += $productFees;
        
        // Calculate tax
        $taxAmount = 0;
        foreach ($items_arr as $item) {
            if ($item->getProductType() != 'simple') {
                continue;
            }
            
            $productId = $item->getProductId();
            $product = $objectManager->create(\Magento\Catalog\Model\Product::class)->load($productId);
            $qty = (int) $item->getQtyOrdered();
            
            // Calculate tax on original price
            $priceIncludingTax = $this->taxHelper->getTaxPrice($product, $product->getPrice(), true);
            $priceExcludingTax = $this->taxHelper->getTaxPrice($product, $product->getPrice(), false);
            $itemTax = round(($priceIncludingTax - $priceExcludingTax) * $qty, $round);
            $taxAmount += $itemTax;
        }
        
        $amount += $taxAmount;
        
        return (int) round(round($amount, $round) * $cents);
    }
    
    public function getInvoiceItems($order)
    {
        $items = [];
        $cents = 100;
        $country = Paymob::getCountryCode($this->scopeConfig->getValue('payment/paymob_payment/secret_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $order->getStoreId()));
        $round = 2;
        if ($country == 'omn') {
            $round = 3;
            $cents = 1000;
        }

        $priceIncTax = $this->scopeConfig->getValue('tax/calculation/price_includes_tax', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $order->getStoreId());
        $amount = 0;
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $items_arr = $order->getAllItems();
        // Product items
        foreach ($items_arr as $item) {
            if ($item->getProductType() != 'simple') {
                continue;
            }

            $productId = $item->getProductId();
            $product = $objectManager->create(\Magento\Catalog\Model\Product::class)->load($productId);

            $name = $product->getName();
            $qty = (int) $item->getQtyOrdered();

            $priceExTax = $this->taxHelper->getTaxPrice($product, $product->getFinalPrice(), false);
            $itemPrice = round($priceExTax ?? 0, $round);

            $items[] = [
                'name' => $name,
                'quantity' => $qty,
                'amount' => $itemPrice * $cents,
            ];
            $amount += $itemPrice * $qty;
        }

        // Apply discount
        $discount1 = $order->getBaseDiscountAmount() ?? 0;
        if (!$priceIncTax) {
            $discount1 += $order->getBaseDiscountTaxCompensationAmount() ?? 0;
        }
        $discount = round($discount1, $round);
        if ($discount) {
            $items[] = [
                'name' => 'Discount Amount',
                'quantity' => 1,
                'amount' => $discount * $cents,
            ];
            $amount += $discount;
        }

        // Apply shipping
        $shipping1 = $order->getBaseShippingAmount() ?? 0;
        $shipping = round($shipping1, $round);
        if ($shipping) {
            $items[] = [
                'name' => 'Shipping Amount',
                'quantity' => 1,
                'amount' => $shipping * $cents,
            ];
            $amount += $shipping;
        }

        // Apply additional fees (if any)
        $fees1 = $order->getBaseMageworxFeeAmount() ?? 0;
        $fees = round($fees1, $round);
        if ($fees) {
            $items[] = [
                'name' => 'Additional Fees',
                'quantity' => 1,
                'amount' => $fees * $cents,
            ];
            $amount += $fees;
        }

        $productFees1 = $order->getBaseMageworxProductFeeAmount() ?? 0;
        $productFees = round($productFees1, $round);
        if ($productFees) {
            $items[] = [
                'name' => 'Additional Product Fees',
                'quantity' => 1,
                'amount' => $productFees * $cents,
            ];
            $amount += $productFees;
        }

        // Apply tax
        $tax1 = round($order->getBaseTotalDue() ?? 0, $round);
        $tax = round($tax1 - $amount, $round);
        if ($tax) {
            $items[] = [
                'name' => 'Tax Amount',
                'quantity' => 1,
                'amount' => $tax * $cents,
            ];
        }

        return $items;
    }

    private function getDebugFilePath(): string
	{
		$logDir = $this->directoryList->getPath(DirectoryList::LOG);
		return $logDir . '/paymob.log';
	}
}