<?php

declare(strict_types=1);

namespace Paymob\Payment\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\Filesystem;
use Magento\MediaStorage\Model\File\UploaderFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Config\ScopeConfigInterface as ScopeType;

class SaveDynamicFields implements ObserverInterface
{
    protected $request;
    protected $configWriter;
    protected $cacheTypeList;
    protected $uploaderFactory;
    protected $filesystem;
    protected $scopeConfig;

    public function __construct(
        RequestInterface $request,
        WriterInterface $configWriter,
        TypeListInterface $cacheTypeList,
        UploaderFactory $uploaderFactory,
        Filesystem $filesystem,
        ScopeType $scopeConfig
    ) {
        $this->request = $request;
        $this->configWriter = $configWriter;
        $this->cacheTypeList = $cacheTypeList;
        $this->uploaderFactory = $uploaderFactory;
        $this->filesystem = $filesystem;
        $this->scopeConfig = $scopeConfig;
    }

    public function execute(Observer $observer)
    {
        $postData = $this->request->getPostValue();

        if (!isset($postData['groups']) || !is_array($postData['groups'])) {
            return;
        }

        // 🔥 Always force GLOBAL scope
        $scope     = ScopeType::SCOPE_TYPE_DEFAULT;
        $scopeCode = 0;

        foreach ($postData['groups'] as $methodCode => $fields) {
            if (!str_starts_with($methodCode, 'paymob_')) {
                continue;
            }

            if (!isset($fields['fields'])) {
                continue;
            }

            // Save normal fields
            foreach ($fields['fields'] as $fieldCode => $fieldValue) {
                if (isset($fieldValue['value'])) {
                    $path = "payment/{$methodCode}/{$fieldCode}";
                    $this->configWriter->save($path, $fieldValue['value'], $scope, $scopeCode);
                }
            }

            // Handle logo upload
            $logoUploadField = $_FILES['groups']['name'][$methodCode]['fields']['logo']['value'] ?? null;

            if ($logoUploadField) {
                try {
                    $uploader = $this->uploaderFactory->create([
                        'fileId' => "groups[$methodCode][fields][logo][value]"
                    ]);
                    $uploader->setAllowedExtensions(['jpg', 'jpeg', 'gif', 'png', 'svg']);
                    $uploader->setAllowRenameFiles(true);
                    $uploader->setFilesDispersion(false);

                    $mediaWrite = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
                    $mediaDir   = $mediaWrite->getAbsolutePath('paymentslogo/');

                    if (!$mediaWrite->isExist('paymentslogo')) {
                        $mediaWrite->create('paymentslogo');
                    }

                    $result = $uploader->save($mediaDir);

                    if (isset($result['file'])) {
                        $relativePath = 'paymentslogo/' . ltrim($result['file'], '/');
                        $this->configWriter->save("payment/{$methodCode}/logo", $relativePath, $scope, $scopeCode);
                    }
                } catch (\Exception $e) {
                    file_put_contents(BP . '/var/log/paymob_logo_upload_error.log', $e->getMessage() . PHP_EOL, FILE_APPEND);
                }
            } else {
                // No new logo uploaded — preserve existing one
                $existingLogo = $this->getConfigValue("payment/{$methodCode}/logo", $scope, $scopeCode);
                if ($existingLogo) {
                    $this->configWriter->save("payment/{$methodCode}/logo", $existingLogo, $scope, $scopeCode);
                }
            }
        }

        $this->cacheTypeList->cleanType('config');
    }

    protected function getConfigValue(string $path, string $scope, $scopeCode): ?string
    {
        return $this->scopeConfig->getValue($path, $scope, $scopeCode);
    }
}
