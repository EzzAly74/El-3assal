<?php

namespace Paymob\Payment\Plugin;

use Magento\Payment\Model\MethodList;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;

class MethodListPlugin
{
    protected $objectManager;
    protected $logger;
    protected $scopeConfig;
    protected $resourceConnection;

    public function __construct(
        \Magento\Framework\ObjectManagerInterface $objectManager,
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        ResourceConnection $resourceConnection
    ) {
        $this->objectManager = $objectManager;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->resourceConnection = $resourceConnection;
    }

    public function afterGetAvailableMethods(MethodList $subject, array $methods, $quote)
    {
        // Remove the default 'paymob_payment' method if it exists
        foreach ($methods as $key => $method) {
            if ($method->getCode() === 'paymob_payment') {
                unset($methods[$key]);
            }
        }

        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('core_config_data');

        // Fetch all paymob config entries for active/sort_order
        $select = $connection->select()
            ->from($tableName, ['path', 'value'])
            ->where("path LIKE 'payment/paymob_%/active' OR path LIKE 'payment/paymob_%/sort_order'");

        $rows = $connection->fetchAll($select);

        // Parse and build method data
        $methodData = [];
        foreach ($rows as $row) {
            if (preg_match('#payment/(paymob_[^/]+)/(active|sort_order)#', $row['path'], $matches)) {
                $code = $matches[1];
                $field = $matches[2];

                if (!isset($methodData[$code])) {
                    $methodData[$code] = ['active' => 0, 'sort_order' => 0];
                }

                $methodData[$code][$field] = $row['value'];
            }
        }

        // Filter by active and sort by sort_order
        $filteredMethods = array_filter($methodData, fn($data) => $data['active'] == 1);
        uasort($filteredMethods, fn($a, $b) => (int)$a['sort_order'] <=> (int)$b['sort_order']);

        foreach (array_keys($filteredMethods) as $code) {
            if ($code === 'paymob_payment') {
                continue;
            }
            try {
                $method = $this->objectManager->create(
                    \Paymob\Payment\Model\Method\PaymobGeneric::class,
                    ['data' => ['code' => $code]]
                );

                if ($method->isAvailable($quote)) {
                    $methods[] = $method;
                    $this->logger->info("Paymob method {$code} added to available methods. ");
                } else {

                    $this->logger->info("Paymob method {$code} is not available.");
                }
            } catch (\Exception $e) {
                $this->logger->error("Error creating method {$code}: " . $e->getMessage());
            }
        }

        return $methods;
    }
}
