<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\ProductVideo\Block\Product\View;

use Magento\Catalog\Block\Product\Context;
use Magento\Catalog\Block\Product\View\Gallery;
use Magento\Catalog\Model\Product\Gallery\ImagesConfigFactoryInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Json\EncoderInterface;
use Magento\Framework\Stdlib\ArrayUtils;
use Spartrak\ProductVideo\Model\Config;
use Spartrak\ProductVideo\Model\Video\DescriptorBuilder;

/**
 * The PDP's video payload: metadata only, and only when there is any.
 *
 * ===========================================================================
 * WHAT THIS BLOCK PUTS ON THE PAGE
 * ===========================================================================
 * A JSON array of video descriptors and one `data-mage-init`. No <video>
 * element, no <iframe>, no poster <img> of its own and no second gallery — the
 * poster is the frame Magento's Fotorama gallery is already rendering, because
 * a video IS a media gallery entry (see etc/db_schema.xml).
 *
 * So the cost of a product with ten videos, before a shopper touches one, is
 * ten entries in one JSON array. No video file is requested, no provider API
 * is loaded, no player is constructed and no iframe exists.
 *
 * A product with NO videos renders NOTHING — not an empty container and not an
 * empty init. That is what keeps this feature off the 8,900 product pages that
 * will never use it.
 *
 * ===========================================================================
 * WHY IT EXTENDS THE CORE GALLERY BLOCK
 * ===========================================================================
 * Magento\Catalog\Block\Product\View\Gallery::getGalleryImages() decorates
 * every entry with the URLs for each configured image role — which is where
 * the poster URL comes from, already resized, already cached, and already the
 * exact string Fotorama will use. Extending it means this block matches its
 * descriptors to gallery frames on a value both sides computed the same way,
 * rather than on an array index.
 *
 * That is not a stylistic choice. `js/spartrak-gallery-rtl-mixin` REVERSES the
 * gallery array on the Arabic store, and Magento_ProductVideo attaches its
 * videos by position — which is why its videos would land on the wrong frames
 * here. Keying on identity instead of index is the fix, and it is structural
 * rather than a patch.
 *
 * Magento\ProductVideo\Block\Product\View\Gallery extends the same class for
 * the same reason; this block replaces it in the layout.
 *
 * ===========================================================================
 * CACHEABLE
 * ===========================================================================
 * Everything below is product- and store-scoped and nothing is customer
 * specific, so the block stays cacheable and the PDP stays in the full page
 * cache. The side tables are written only during a product save, so Magento's
 * own `cat_p_<id>` invalidation already covers a change — there is no extra
 * cache plugin in this module and there does not need to be.
 */
class Video extends Gallery
{
    /**
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $videos = null;

    public function __construct(
        Context $context,
        ArrayUtils $arrayUtils,
        EncoderInterface $jsonEncoder,
        private readonly Config $config,
        private readonly DescriptorBuilder $descriptorBuilder,
        array $data = [],
        ?ImagesConfigFactoryInterface $imagesConfigFactory = null,
        array $galleryImagesConfig = []
    ) {
        parent::__construct($context, $arrayUtils, $jsonEncoder, $data, $imagesConfigFactory, $galleryImagesConfig);
    }

    public function hasVideos(): bool
    {
        return $this->getVideos() !== [];
    }

    /**
     * The widget configuration, ready for a `data-mage-init` attribute.
     *
     * Built in PHP and emitted as one attribute — the house pattern, and the
     * same one Spartrak_Homepage's video rail uses: a widget is requested only
     * when the data warrants it, so RequireJS never fetches this module on a
     * page that has no video to play.
     */
    public function getWidgetConfigJson(): string
    {
        return $this->jsonEncoder->encode([
            'spartrakPdpVideo' => [
                'videos' => $this->getVideos(),
            ],
        ]);
    }

    /**
     * One descriptor per playable video, in gallery order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getVideos(): array
    {
        if ($this->videos !== null) {
            return $this->videos;
        }

        $this->videos = [];

        if (!$this->config->isEnabled()) {
            return $this->videos;
        }

        $storeId = (int) $this->_storeManager->getStore()->getId();
        $featuredSeen = false;

        foreach ($this->getGalleryImages() as $image) {
            /** @var DataObject $image */
            $descriptor = $this->descriptorBuilder->build($image, $storeId);

            if ($descriptor === null) {
                continue;
            }

            // Only the FIRST video marked featured wins. Two "open on this
            // video" flags is a merchant mistake, not a state the player
            // should try to represent.
            if ($descriptor['featured'] && $featuredSeen) {
                $descriptor['featured'] = false;
            }

            $featuredSeen = $featuredSeen || $descriptor['featured'];

            $this->videos[] = $descriptor;
        }

        return $this->videos;
    }
}
