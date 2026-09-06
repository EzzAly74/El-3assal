<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\ProductVideo\Block\Adminhtml\Product\Edit;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\Fieldset;
use Magento\Framework\Data\FormFactory;
use Magento\Framework\Json\EncoderInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Registry;
use Magento\ProductVideo\Block\Adminhtml\Product\Edit\NewVideo as MagentoNewVideo;
use Magento\ProductVideo\Helper\Media;
use Spartrak\ProductVideo\Model\Config;

/**
 * Magento's "Add Video" dialog, plus the settings this storefront needs.
 *
 * ===========================================================================
 * ONE ADMIN SURFACE, NOT TWO
 * ===========================================================================
 * A video already has a home in the product form: the Images And Videos
 * fieldset, where it is added, given a poster, dragged into order, assigned
 * roles and hidden from the storefront. Everything below belongs to a video, so
 * it belongs in the same dialog. A separate "Product Videos" fieldset would
 * have meant adding a video in one place and configuring it in another, and
 * would have had to re-implement the poster uploader and the ordering control
 * to be usable on its own.
 *
 * ===========================================================================
 * A SUBCLASS, NOT A FORK
 * ===========================================================================
 * `_prepareForm()` calls the parent and then appends to the fieldset it built.
 * Not one line of Magento's form is copied, so a field they add, reorder or
 * relabel in a future release simply appears.
 *
 * ===========================================================================
 * WHY EVERY FIELD BELOW IS A `select` AND CARRIES `edited-data`
 * ===========================================================================
 * The dialog moves data between the form and the gallery item generically, and
 * both directions have a requirement:
 *
 *   OUT  new-video-dialog.js:600 runs `form.serializeArray()`. An unchecked
 *        checkbox is absent from that, which is indistinguishable from "the
 *        field does not exist" — so a tri-state (inherit / on / off) cannot be
 *        a checkbox. Selects always serialise, including their empty option.
 *
 *   IN   new-video-dialog.js:1205 does `$(field).val(imageData[field.name])`
 *        for every `.edited-data` field. `.val()` selects an option but does
 *        NOT tick a checkbox, so a checkbox would populate as empty every time
 *        an admin reopened a saved video.
 *
 * Field `id` and `name` are deliberately identical, because the edit-save path
 * (line 885-896) matches the two against each other to write values back into
 * the gallery's hidden inputs.
 */
class NewVideo extends MagentoNewVideo
{
    public function __construct(
        Context $context,
        Registry $registry,
        FormFactory $formFactory,
        Media $mediaHelper,
        EncoderInterface $jsonEncoder,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $registry, $formFactory, $mediaHelper, $jsonEncoder, $data);
    }

    /**
     * @return void
     */
    protected function _prepareForm()
    {
        parent::_prepareForm();

        if (!$this->config->isEnabled()) {
            return;
        }

        $form = $this->getForm();
        $fieldset = $form?->getElement('new_video_form_fieldset');

        if (!$fieldset instanceof Fieldset) {
            // Magento renamed or restructured its fieldset. Better to render
            // their dialog unchanged than to fatal on a product form.
            return;
        }

        $this->addUploadFields($fieldset);
        $this->addPlaybackFields($fieldset);
        $this->addChapterFields($fieldset);
    }

    /**
     * The file picker, and the hidden field that carries the result.
     *
     * The picker is NOT posted with the form — a file input is not part of
     * `serializeArray()`, and the dialog is submitted as gallery data rather
     * than as a file upload. js/spartrak-video-upload.js sends the chosen file
     * to Controller\Adminhtml\Video\Upload on its own and writes the staged
     * media path into the hidden field below, which IS posted.
     *
     * It also fills in `video_url`, because Magento marks that field required
     * and a merchant who uploaded a file should not then have to paste a URL
     * to satisfy a validator.
     */
    private function addUploadFields(Fieldset $fieldset): void
    {
        $fieldset->addField(
            'spartrak_video_path',
            'hidden',
            [
                'class' => 'edited-data',
                'name' => 'spartrak_video_path',
            ]
        );

        $fieldset->addField(
            'spartrak_video_upload',
            'file',
            [
                'label' => __('Upload a video file'),
                'title' => __('Upload a video file'),
                'name' => 'spartrak_video_upload',
                'note' => __(
                    'MP4 or WebM. Leave this empty and paste a link above to use YouTube, Vimeo '
                    . 'or a video already hosted on a CDN. Large files are better served from a '
                    . 'CDN than from this server.'
                ),
            ]
        );

        $fieldset->addField(
            'spartrak_video_upload_button',
            'button',
            [
                'label' => '',
                'title' => __('Upload'),
                'name' => 'spartrak_video_upload_button',
                'value' => __('Upload'),
                'class' => 'action-default',
                'data-role' => 'spartrak-video-upload',
            ]
        );
    }

    /**
     * The five tri-state overrides and the featured flag.
     */
    private function addPlaybackFields(Fieldset $fieldset): void
    {
        $overrides = [
            'spartrak_video_autoplay' => __('Autoplay'),
            'spartrak_video_loop' => __('Loop'),
            'spartrak_video_muted' => __('Start muted'),
            'spartrak_video_controls' => __('Show player controls'),
            'spartrak_video_download' => __('Allow download'),
        ];

        foreach ($overrides as $name => $label) {
            $fieldset->addField(
                $name,
                'select',
                [
                    'class' => 'edited-data',
                    'label' => $label,
                    'title' => $label,
                    'name' => $name,
                    'values' => $this->inheritOptions(),
                ]
            );
        }

        $fieldset->addField(
            'spartrak_video_featured',
            'select',
            [
                'class' => 'edited-data',
                'label' => __('Open on this video'),
                'title' => __('Open on this video'),
                'name' => 'spartrak_video_featured',
                'values' => [
                    ['value' => '0', 'label' => __('No')],
                    ['value' => '1', 'label' => __('Yes')],
                ],
                'note' => __(
                    'Shows this video first instead of the main product image. If more than one '
                    . 'video is marked, the earliest in the gallery order wins.'
                ),
            ]
        );
    }

    /**
     * Chapters, one textarea per language.
     *
     * Two fields rather than one because chapter titles are content and this
     * storefront is bilingual — the same rule every other Spartrak text pair
     * follows. They line up by position, which is the one thing a merchant has
     * to know and is therefore said in the note rather than left implicit.
     */
    private function addChapterFields(Fieldset $fieldset): void
    {
        $note = __(
            'One chapter per line, as "1:24 Fitting the pump" - a timecode, a space, then the '
            . 'title. Chapters apply to uploaded and direct-URL videos only; YouTube and Vimeo '
            . 'carry their own. Keep the two languages in the same order - line 3 here is line 3 '
            . 'there.'
        );

        $fieldset->addField(
            'spartrak_video_chapters_ar',
            'textarea',
            [
                'class' => 'edited-data',
                'label' => __('Chapters (Arabic)'),
                'title' => __('Chapters (Arabic)'),
                'name' => 'spartrak_video_chapters_ar',
                'note' => $note,
            ]
        );

        $fieldset->addField(
            'spartrak_video_chapters_en',
            'textarea',
            [
                'class' => 'edited-data',
                'label' => __('Chapters (English)'),
                'title' => __('Chapters (English)'),
                'name' => 'spartrak_video_chapters_en',
            ]
        );
    }

    /**
     * The empty option is FIRST and is the default, so a video that nobody has
     * opinions about follows the store setting — and keeps following it when a
     * merchant later changes that setting.
     *
     * @return array<int, array{value: string, label: Phrase}>
     */
    private function inheritOptions(): array
    {
        return [
            ['value' => '', 'label' => __('Use store default')],
            ['value' => '1', 'label' => __('Yes')],
            ['value' => '0', 'label' => __('No')],
        ];
    }

    /**
     * Magento's note says "YouTube and Vimeo supported", which stopped being
     * the whole truth the moment this module was installed.
     *
     * @return Phrase
     */
    protected function getNoteVideoUrl()
    {
        if (!$this->config->isEnabled()) {
            return parent::getNoteVideoUrl();
        }

        return __(
            'A YouTube or Vimeo link, or a direct link to an MP4 or WebM file. '
            . 'To use a file of your own, upload it below instead.'
        );
    }

    /**
     * Adds this module's upload endpoint to the options the dialog already
     * publishes on `data-modal-info`, so js/spartrak-video-upload.js has a URL
     * without a second config blob or a hardcoded path.
     *
     * @return string
     */
    public function getWidgetOptions()
    {
        $options = json_decode(parent::getWidgetOptions(), true) ?: [];
        $options['spartrakUploadUrl'] = $this->getUrl('spartrak_product_video/video/upload');

        return $this->jsonEncoder->encode($options);
    }
}
