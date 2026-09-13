<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Block\Section;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Block\Product\ListProduct;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Framework\View\Element\Template\Context;
use Magento\ProductVideo\Model\Product\Attribute\Media\ExternalVideoEntryConverter;
use Spartrak\Homepage\Model\Image\Resizer;
use Spartrak\Homepage\Model\LocaleContext;
use Spartrak\Homepage\Model\Product\CategoryProductProvider;
use Spartrak\Homepage\Model\SectionType;
use Spartrak\Homepage\ViewModel\CategoryUrl;

/**
 * ONE block behind all three category-driven product sections.
 *
 * "الأكثر مبيعا", "عروض مميزه" and "شاهد المنتج، وأحكم بنفسك" differ in
 * exactly two things: the category the dashboard points them at, and the
 * template that lays them out. Neither is a reason for three block classes,
 * three providers or three sets of admin fields — so there is one of each,
 * and Block\Sections picks the template from the section's type.
 *
 * That is the brief's "Do not duplicate product-section architecture"
 * requirement, discharged structurally rather than by convention.
 */
class ProductCarousel extends AbstractSection
{
    /**
     * getVideo() memo, keyed by product id.
     *
     * The showcase template asks the same question three times per product —
     * once in the `$hasAnyVideo` scan that decides whether the video widget is
     * requested at all, once for the `data-video-url` attribute and once for
     * the handle — and each call walks the product's whole media gallery. The
     * gallery is already in memory so this is not a query, but a twelve-card
     * rail was scanning it thirty-six times to answer twelve questions.
     *
     * `false` is the "asked and there is none" marker, so a product with no
     * video is not re-walked either. Null would be indistinguishable from
     * "not yet asked".
     *
     * @var array<int, array{url: string, title: string, poster: string}|false>
     */
    private array $videoMemo = [];

    /**
     * Candidate widths for the showcase's media frame, in CSS pixels: the
     * 533px desktop box and the ~204px mobile one, each doubled for DPR 2.
     * See getMediaImage().
     *
     * @var int[]
     */
    private const MEDIA_WIDTHS = [228, 456, 533, 1066];

    /** The desktop box, and therefore what `src` points at. */
    private const MEDIA_DEFAULT_WIDTH = 533;

    public function __construct(
        Context $context,
        LocaleContext $localeContext,
        private readonly CategoryProductProvider $productProvider,
        // protected, not private: ProductPromoCarousel asks the SAME resolver
        // for the source category's image, and a second injected copy would be
        // two constructor arguments of one type for one shared instance - and
        // two separate memos of the same category load.
        protected readonly CategoryUrl $categoryUrl,
        private readonly ImageHelper $imageHelper,
        private readonly Resizer $resizer,
        array $data = []
    ) {
        parent::__construct($context, $localeContext, $data);
    }

    /**
     * @return ProductInterface[]
     */
    public function getProducts(): array
    {
        if ($this->hasData('resolved_products')) {
            return $this->getData('resolved_products');
        }

        $section = $this->getSection();
        $categoryId = $section?->getCategoryId();

        $products = $categoryId === null
            ? []
            : $this->productProvider->getProducts(
                $categoryId,
                $section->getProductLimit(),
                $this->needsMediaGallery()
            );

        $this->setData('resolved_products', $products);

        return $products;
    }

    public function hasContent(): bool
    {
        return $this->getProducts() !== [];
    }

    /**
     * Carousel controls appear only when there is something to scroll to.
     *
     * The rail itself is a plain scroll container, so a short rail is still
     * perfectly usable with a touchpad or a swipe — this only governs the
     * arrows and the progress bar, which would otherwise be dead controls.
     */
    public function isCarousel(): bool
    {
        return count($this->getProducts()) > 1;
    }

    /**
     * Figma puts every product rail's prev/next buttons in the section
     * header (595:15118 / 595:14588 / 595:14823) — but only when there is
     * something to page through.
     */
    public function showsCarouselNav(): bool
    {
        return $this->isCarousel();
    }

    /**
     * True only for the section type that paints media over each card.
     *
     * Gates the extra media-gallery load: the two plain carousels must not
     * pay for a query they never read from.
     */
    public function needsMediaGallery(): bool
    {
        return (string) $this->getSection()?->getType() === SectionType::PRODUCT_VIDEO_CAROUSEL;
    }

    /**
     * The product's first gallery video, when it has one.
     *
     * ===================================================================
     * REAL MAGENTO DATA, AND NOT ONE EXTRA QUERY
     * ===================================================================
     * A product video is an `external-video` media-gallery entry, so both
     * fields below are already on the row: `media_type` is Catalog's own
     * column, and `video_url` / `video_title` are joined into
     * Gallery::createBatchBaseSelect() by Magento_ProductVideo's
     * Model\Plugin\ExternalVideoResourceBackend. That select is the SAME one
     * CategoryProductProvider::loadMediaGallery() already runs once for the
     * whole rail, so reading a video here costs nothing and there is no N+1.
     *
     * ===================================================================
     * A URL, NOT A PROVIDER ID — AND WHY THAT IS THE RIGHT SPLIT
     * ===================================================================
     * This returns the raw `video_url` and leaves it to
     * js/spartrak-video-source to say whose video it is. That module is the
     * ONE place on the storefront where a URL becomes a provider id, and it is
     * shared with the PDP gallery mixin — so the two surfaces cannot disagree
     * about what a playable video is (CLAUDE.md section 9: no duplicated
     * business logic). Parsing it a second time in PHP would be that
     * duplication.
     *
     * The security boundary is unchanged and lives in that module: the URL is
     * decomposed, the id is matched against a strict character class, and the
     * embed address is composed from a hardcoded prefix. A merchant-supplied
     * string never reaches an iframe `src`, and a URL that is not a recognised
     * provider gets no play button at all.
     *
     * Returns null when the product has no video, and the template then shows
     * the product image in the same frame with no controls over it — the
     * honest fallback: no URL is invented and no placeholder clip substituted.
     *
     * `poster` is the gallery entry's own FILE, not a URL — the caller resizes
     * it through a view preset (see getMediaImage below). The raw URL on this
     * load path is the untouched original upload, which on a 533px frame could
     * be a multi-megabyte download per card.
     *
     * @return array{url: string, title: string, poster: string}|null
     */
    public function getVideo(ProductInterface $product): ?array
    {
        if (!$product instanceof Product) {
            return null;
        }

        $productId = (int) $product->getId();

        if (isset($this->videoMemo[$productId])) {
            return $this->videoMemo[$productId] ?: null;
        }

        $this->videoMemo[$productId] = false;

        $gallery = $product->getMediaGalleryImages();

        if ($gallery === null) {
            return null;
        }

        foreach ($gallery as $entry) {
            if ($entry->getMediaType() !== ExternalVideoEntryConverter::MEDIA_TYPE_CODE) {
                continue;
            }

            $url = trim((string) $entry->getVideoUrl());

            if ($url === '') {
                // A video row with no URL is a half-saved entry, not a video.
                continue;
            }

            return $this->videoMemo[$productId] = [
                'url' => $url,
                // Figma's "@العسال لقطع غيار الجرارات والمحركات" handle slot.
                // The video's own admin title is the only real value Magento
                // has for it; an empty one renders nothing rather than a
                // fabricated channel name.
                'title' => trim((string) $entry->getVideoTitle()),
                // The video's preview image, as the admin uploaded it against
                // this entry. `file` and not `url`: see the note above.
                'poster' => (string) $entry->getFile(),
            ];
        }

        return null;
    }

    /**
     * The large portrait media image behind a video card.
     *
     * ===================================================================
     * THIS FRAME IS THE VIDEO'S, SO IT SHOWS THE VIDEO'S POSTER
     * ===================================================================
     * The showcase item is two things stacked (Figma 595:14831 + 595:14852):
     * the tall media frame, which is the VIDEO — it carries the play, mute and
     * caption controls and the channel handle — and the compact product card
     * underneath, which is the PRODUCT.
     *
     * So the two images are not the same image, and each surface asks for the
     * one it is actually showing:
     *
     *   this frame   the video's own gallery-entry file — its preview image,
     *                exactly what an admin uploaded against the video
     *   the card     Spartrak_Catalog::product/card.phtml, `small_image` —
     *                the product photograph
     *
     * Both shipped resolving to the product's `image`/`small_image` roles,
     * which meant the frame showed a bearing where the video belonged (and,
     * where the poster had wrongly taken the image roles, the card showed a
     * tractor banner where the product belonged). One value drove two frames
     * that depict different subjects.
     *
     * A product with NO video falls through to its own image, unchanged: the
     * frame then renders with no controls over it, so it is a product photo
     * and should look like one.
     *
     * ===================================================================
     * setImageFile IS THE SUPPORTED WAY TO RESIZE A GALLERY ENTRY
     * ===================================================================
     * The poster's raw URL on this load path is the untouched original upload.
     * `setImageFile()` runs it through the requested preset instead, so it
     * gets Magento's own resize, derivative and cache pipeline — the same call
     * Magento\Catalog\Block\Product\View\Gallery::getGalleryImages() makes for
     * every thumbnail on a product page. Nothing here re-implements image
     * handling.
     *
     * Order is load-bearing: `init()` calls `_reset()`, which nulls
     * `_imageFile`. The helper is a shared singleton, so setting the file
     * BEFORE init would be discarded — and, worse, a file left set across a
     * loop would leak one card's poster onto the next card's product image.
     *
     * Reuses a view preset that already exists in the theme's view.xml rather
     * than declaring a homepage-only image role: a new role would mean a
     * whole extra set of cached resizes of the same source files (CLAUDE.md
     * section 11 — never generate a second copy of an image you already have).
     *
     * `product_page_image_medium` (650x650 in Porto's view.xml), NOT
     * `product_page_image_large` — the "large" preset declares no width or
     * height at all, so it serves the ORIGINAL upload. On a 533px-wide frame
     * that could mean shipping a multi-megabyte source file per card. This is
     * the largest BOUNDED preset the theme already ships.
     *
     * ===================================================================
     * WHY A POSTER TURNS THE FRAME PADDING OFF
     * ===================================================================
     * Porto sets `product_image_white_borders` to 1 (etc/view.xml:262), so
     * every preset it declares has `keep_frame` on — Magento PADS the result
     * out to the full 650x650 with white. That is right for a product
     * photograph and wrong for a video poster: a provider thumbnail is 16:9,
     * so it would arrive with ~150px of white BAKED INTO THE FILE above and
     * below the picture, and no amount of CSS can take baked pixels out again.
     * The frame letterboxes video posters on purpose (components/
     * _homepage-sections.less, `[data-video-url]`), and it can only do that
     * over its own dark background if the file itself has no border.
     *
     * `frame` is passed as an ATTRIBUTE OVERRIDE, not by declaring a second
     * preset. init()'s third argument merges into the view-config attributes
     * and is part of the cache key, so this is one bounded extra derivative of
     * the poster only — the product-image path is left on the preset exactly
     * as it was, and gains no new resizes.
     *
     * ===================================================================
     * NO width / height RETURNED, AND THAT IS NOT AN OMISSION
     * ===================================================================
     * The <img> is `position: absolute; inset: 0; width: 100%; height: 100%`,
     * so intrinsic attributes on it are inert — the box is sized entirely by
     * `.spartrak-home-showcase__media`'s `aspect-ratio: 533/692`, which is
     * what reserves the space and prevents the layout shift (CLAUDE.md
     * section 11). They were being emitted as the PRESET's 650x650, which for
     * an unpadded 16:9 poster is not the file's size at all. Advertising a
     * dimension this element does not have, cannot use, and does not need is
     * worse than not advertising one.
     *
     * ===================================================================
     * AND THEN THROUGH THE RESIZER, BECAUSE THE PRESET SHIPS A PNG
     * ===================================================================
     * Everything above gets the right PIXELS. It does not get an acceptable
     * FILE. Magento's image cache preserves the source format, and these
     * uploads are PNG, so `product_page_image_medium` handed the showcase a
     * 650px PNG — measured on the live homepage at 346,124 bytes for ONE
     * poster, the single largest resource on a 1,467KB page, and Lighthouse's
     * image-delivery insight put 326.9KB of that down to format alone.
     *
     * So the preset's output is now the SOURCE for one more pass, through the
     * same Model\Image\Resizer the category tiles and the hero banner already
     * use: WebP at quality 82, derived at the widths this frame actually
     * draws. Resizing a derivative rather than the original is deliberate —
     * the preset has already bounded it to 650px and, for a poster, stripped
     * Porto's baked white frame, so this pass starts from exactly the pixels
     * the design wants and costs one cheap re-encode instead of a
     * multi-megabyte decode.
     *
     * WIDTHS. The media frame is 533px on desktop (Figma 595:14833) and 204px
     * on a 375px phone, so the set spans both and doubles each for DPR 2. The
     * `sizes` attribute that picks between them lives on the <img> in
     * section/product-video-carousel.phtml, next to the markup it describes.
     *
     * WHY IT STILL RETURNS A URL ON FAILURE. The resizer answers null when it
     * cannot write its cache — a read-only media directory, an unsupported
     * source. Falling back to the preset's own URL keeps the poster VISIBLE and
     * merely heavy, which is the correct failure for a decorative frame; the
     * alternative is a card with a hole in it (CLAUDE.md section 9: do not
     * hide errors, but do not let a size optimisation become a hard
     * dependency either).
     *
     * @return array{url: string, srcset: string}|null
     */
    public function getMediaImage(ProductInterface $product): ?array
    {
        $video = $this->getVideo($product);
        $poster = $video === null ? '' : $video['poster'];

        try {
            $image = $this->imageHelper->init(
                $product,
                'product_page_image_medium',
                $poster === '' ? [] : ['frame' => false]
            );

            if ($poster !== '') {
                $image->setImageFile($poster);
            }

            $url = (string) $image->getUrl();
            $resized = $this->resizer->responsive(
                $url,
                self::MEDIA_WIDTHS,
                self::MEDIA_DEFAULT_WIDTH
            );

            if ($resized === null) {
                return ['url' => $url, 'srcset' => ''];
            }

            return ['url' => $resized['url'], 'srcset' => $resized['srcset']];
        } catch (\Exception $exception) {
            $this->_logger->warning(
                'Spartrak_Homepage: no media image for product ' . $product->getId()
                . ': ' . $exception->getMessage()
            );

            return null;
        }
    }

    /**
     * A shared Magento\Catalog\Block\Product\ListProduct instance.
     *
     * The shared Card - Product template needs one for getAddToCartUrl(),
     * which builds the uenc-signed add-to-cart route. Created ONCE per
     * carousel and handed to every card, not once per card — it is a
     * stateless helper here, and instantiating it per product would be a
     * block construction per row for no benefit.
     */
    public function getListBlock(): ?ListProduct
    {
        if ($this->hasData('list_block')) {
            return $this->getData('list_block');
        }

        try {
            $listBlock = $this->getLayout()->createBlock(ListProduct::class);
        } catch (\Exception $exception) {
            // The card degrades gracefully without it: no add-to-cart button,
            // everything else still renders.
            $this->_logger->warning(
                'Spartrak_Homepage: could not create ListProduct block: ' . $exception->getMessage()
            );
            $listBlock = null;
        }

        $this->setData('list_block', $listBlock);

        return $listBlock;
    }

    /**
     * Renders one product through the SHARED Card - Product template.
     *
     * Spartrak_Catalog::product/card.phtml is the single card component used
     * by the PLP, the search grid and these rails alike — this section does
     * not get a card of its own (04-COMPONENT-INVENTORY.md's single
     * "Card - Product" rule).
     *
     * $index is passed straight through so the card applies the project's own
     * loading policy; combined with getCardIndex() below, no image in a
     * below-the-fold rail is ever eager.
     */
    public function renderCard(ProductInterface $product, int $index, string $variant = 'grid'): string
    {
        try {
            return $this->getLayout()
                ->createBlock(\Magento\Framework\View\Element\Template::class)
                ->setTemplate('Spartrak_Catalog::product/card.phtml')
                ->setData('product', $product)
                ->setData('list_block', $this->getListBlock())
                ->setData('index', $index)
                ->setData('variant', $variant)
                ->toHtml();
        } catch (\Exception $exception) {
            $this->_logger->error(
                'Spartrak_Homepage: card render failed for product '
                . $product->getId() . ': ' . $exception->getMessage(),
                ['exception' => $exception]
            );

            return '';
        }
    }

    /**
     * The index to hand the card template.
     *
     * The card treats index < 4 as above the fold and loads those images
     * eagerly. That is right for a PLP's first grid row — and wrong for every
     * homepage rail except one, because a rail four sections down is
     * emphatically not above the fold. Returning a deliberately
     * below-the-fold index for those sections keeps the whole rail lazy
     * without the card template needing to know what a homepage is.
     */
    public function getCardIndex(int $index): int
    {
        return $this->isAboveFold() ? $index : 99;
    }

    protected function getCategoryUrl(): string
    {
        $categoryId = $this->getSection()?->getCategoryId();

        return $categoryId === null ? '' : $this->categoryUrl->get($categoryId);
    }
}
