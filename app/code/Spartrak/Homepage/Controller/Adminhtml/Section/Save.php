<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Controller\Adminhtml\Section;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Catalog\Model\ImageUploader;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Spartrak\Homepage\Model\CategoryItemManager;
use Spartrak\Homepage\Model\SectionFactory;
use Spartrak\Homepage\Model\SectionRepository;
use Spartrak\Homepage\Model\SectionType;

/**
 * Saves a homepage section.
 *
 * HttpPostActionInterface is not decoration: it is what makes Magento enforce
 * CSRF on this route. A state-changing admin action that accepts GET is a
 * one-click-attack surface (CLAUDE.md section 17).
 */
class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Spartrak_Homepage::section';

    public function __construct(
        Context $context,
        private readonly SectionRepository $sectionRepository,
        private readonly SectionFactory $sectionFactory,
        private readonly CategoryItemManager $categoryItemManager,
        private readonly DataPersistorInterface $dataPersistor,
        private readonly ImageUploader $imageUploader
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

        $sectionId = (int) ($data['section_id'] ?? 0);

        try {
            $section = $sectionId > 0
                ? $this->sectionRepository->getById($sectionId)
                : $this->sectionFactory->create();

            $categoryItems = $data['category_items'] ?? [];
            unset($data['category_items']);

            // A new record must not inherit an empty string as its id — that
            // would make the resource model try to UPDATE a row that is not
            // there and silently save nothing.
            if ($sectionId <= 0) {
                unset($data['section_id']);
            }

            $this->validate($data);
            $data = $this->normalisePromoImages($data);

            $section->addData($data);
            $this->sectionRepository->save($section);

            if ((string) $section->getType() === SectionType::CATEGORY_TILES) {
                $this->categoryItemManager->save(
                    (int) $section->getId(),
                    is_array($categoryItems) ? $categoryItems : []
                );
            }

            $this->messageManager->addSuccessMessage(__('The section has been saved.'));
            $this->dataPersistor->clear('spartrak_homepage_section');

            if ($this->getRequest()->getParam('back')) {
                return $redirect->setPath('*/*/edit', ['section_id' => $section->getId()]);
            }

            return $redirect->setPath('*/*/');
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        } catch (\Exception $exception) {
            $this->messageManager->addExceptionMessage(
                $exception,
                __('Something went wrong while saving the section.')
            );
        }

        // Hands the rejected input back to the form so the admin does not
        // lose what they typed.
        $this->dataPersistor->set('spartrak_homepage_section', $data);

        return $sectionId > 0
            ? $redirect->setPath('*/*/edit', ['section_id' => $sectionId])
            : $redirect->setPath('*/*/new');
    }

    /**
     * Flattens the promo uploaders' array values to stored filenames, and
     * promotes anything still sitting in the staging directory.
     *
     * Identical in shape to Banner\Save::normaliseImages(), and for the same
     * reason: the imageUploader form element posts a LIST of file descriptors,
     * not a string. Three shapes arrive here —
     *   a NEW upload     ['name' => …, 'tmp_name' => …]  : move it
     *   an UNCHANGED one ['name' => …] with no tmp_name   : keep it
     *   a CLEARED field  [] or absent                     : store ''
     *
     * Runs for every section type, not just the promo one. That is deliberate:
     * switching a section away from the promo layout and back must not lose
     * the artwork, and an unconditional pass keeps the stored value intact
     * either way.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalisePromoImages(array $data): array
    {
        foreach (['promo_image_en', 'promo_image_ar'] as $field) {
            $value = $data[$field] ?? null;

            if (is_array($value)) {
                $value = $value[0] ?? [];
            }

            if (!is_array($value) || empty($value['name'])) {
                $data[$field] = '';
                continue;
            }

            if (!empty($value['tmp_name'])) {
                $data[$field] = $this->imageUploader->moveFileFromTmp((string) $value['name'], true);
                continue;
            }

            $data[$field] = (string) $value['name'];
        }

        return $data;
    }

    /**
     * Server-side validation.
     *
     * The UI component declares the same rules, but a UI validator is a
     * convenience for the admin — it is not a control. Everything that must
     * hold is re-checked here, where the request actually is.
     *
     * @param array<string, mixed> $data
     * @throws LocalizedException
     */
    private function validate(array $data): void
    {
        $code = trim((string) ($data['code'] ?? ''));

        if ($code === '') {
            throw new LocalizedException(__('An identifier is required.'));
        }

        // Deliberately kept in step with the `validate-identifier` rule the
        // form declares client-side (Magento_Ui rules.js): same character
        // class, same "must start with a letter or digit" shape. A server
        // rule that is merely SIMILAR to the UI's produces the worst kind of
        // admin bug — a field that passes validation and then fails to save.
        // Slashes and dots, which validate-identifier tolerates for URL keys,
        // are excluded here because this value becomes part of a DOM id.
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $code)) {
            throw new LocalizedException(
                __('The identifier may only contain lowercase letters, numbers, underscores and hyphens, and must start with a letter or a number.')
            );
        }

        $type = (string) ($data['type'] ?? '');

        if (!in_array($type, SectionType::all(), true)) {
            throw new LocalizedException(__('Please choose a valid section type.'));
        }

        if (in_array($type, SectionType::productTypes(), true) && (int) ($data['category_id'] ?? 0) <= 0) {
            // Caught here rather than left to render an empty rail: a product
            // section with no category is a configuration mistake the admin
            // should be told about at the moment they make it.
            throw new LocalizedException(
                __('A product section needs a source category.')
            );
        }
    }
}
