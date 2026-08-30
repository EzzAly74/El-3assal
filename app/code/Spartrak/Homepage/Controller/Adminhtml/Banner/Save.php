<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Controller\Adminhtml\Banner;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Spartrak\Homepage\Model\BannerFactory;
use Spartrak\Homepage\Model\BannerRepository;
use Spartrak\Homepage\Model\Image\Uploader;

/**
 * Saves one banner item, including moving any freshly uploaded artwork out of
 * the staging directory.
 */
class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Spartrak_Homepage::banner';

    /**
     * The four artwork fields. Named once, here, so adding a fifth is a
     * one-line change rather than four copies of the same block.
     */
    private const IMAGE_FIELDS = [
        'image_desktop_en',
        'image_desktop_ar',
        'image_mobile_en',
        'image_mobile_ar',
    ];

    public function __construct(
        Context $context,
        private readonly BannerRepository $bannerRepository,
        private readonly BannerFactory $bannerFactory,
        private readonly Uploader $imageUploader,
        private readonly DataPersistorInterface $dataPersistor
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $data = $this->getRequest()->getPostValue();

        if (!$data) {
            return $redirect->setPath('*/*/');
        }

        $bannerId = (int) ($data['banner_id'] ?? 0);

        try {
            $banner = $bannerId > 0
                ? $this->bannerRepository->getById($bannerId)
                : $this->bannerFactory->create();

            if ($bannerId <= 0) {
                unset($data['banner_id']);
            }

            $this->validate($data);
            $data = $this->normaliseImages($data);

            $banner->addData($data);
            $this->bannerRepository->save($banner);

            $this->messageManager->addSuccessMessage(__('The banner has been saved.'));
            $this->dataPersistor->clear('spartrak_homepage_banner');

            if ($this->getRequest()->getParam('back')) {
                return $redirect->setPath('*/*/edit', ['banner_id' => $banner->getId()]);
            }

            return $redirect->setPath('*/*/');
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        } catch (\Exception $exception) {
            $this->messageManager->addExceptionMessage(
                $exception,
                __('Something went wrong while saving the banner.')
            );
        }

        $this->dataPersistor->set('spartrak_homepage_banner', $data);

        return $bannerId > 0
            ? $redirect->setPath('*/*/edit', ['banner_id' => $bannerId])
            : $redirect->setPath('*/*/new');
    }

    /**
     * Flattens the uploader's array value down to the stored filename, and
     * promotes anything still sitting in the tmp directory.
     *
     * The imageUploader form element posts each field as an ARRAY of file
     * descriptors, not a string. Three shapes arrive here:
     *   - a NEW upload      -> ['name' => ..., 'tmp_name' => ...] : move it
     *   - an UNCHANGED one  -> ['name' => ...] with no tmp_name    : keep it
     *   - a CLEARED field   -> [] or absent                        : store ''
     *
     * What is stored is the MEDIA-RELATIVE PATH, never a URL: a URL would bake
     * the store's base URL into a data row and break the moment the domain or
     * the media host changes. Model\Image\Storage normalises the base path back
     * off the value on the way out, so it stays the one class that knows where
     * banner artwork lives.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normaliseImages(array $data): array
    {
        foreach (self::IMAGE_FIELDS as $field) {
            $value = $data[$field] ?? null;

            if (is_array($value)) {
                $value = $value[0] ?? [];
            }

            if (!is_array($value) || empty($value['name'])) {
                $data[$field] = '';
                continue;
            }

            if (!empty($value['tmp_name'])) {
                // Freshly uploaded: move it out of staging. moveFileFromTmp()
                // returns the media-relative path of the file that now exists,
                // which carries a collision suffix when one was needed — so a
                // second upload of the same filename cannot leave this row
                // pointing at the first upload's artwork.
                $data[$field] = $this->imageUploader->moveFileFromTmp((string) $value['name']);
                continue;
            }

            $data[$field] = (string) $value['name'];
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @throws LocalizedException
     */
    private function validate(array $data): void
    {
        if ((int) ($data['section_id'] ?? 0) <= 0) {
            throw new LocalizedException(__('Please choose the section this banner belongs to.'));
        }

        $url = trim((string) ($data['url'] ?? ''));

        if ($url === '') {
            return; // the destination URL is optional by design
        }

        // Relative paths are legitimate and common ("checkout/cart"), so only
        // an ABSOLUTE url is checked — and it is checked for scheme, because a
        // javascript: destination stored here would render as a live link on
        // the homepage.
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)
            && !preg_match('#^https?://#i', $url)
        ) {
            throw new LocalizedException(
                __('The destination URL must be a relative path or start with http:// or https://.')
            );
        }
    }
}
