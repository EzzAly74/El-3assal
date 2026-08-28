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
     * The product's own gallery video, when it has one.
     *
     * Real Magento media-gallery data (media_type `external-video`), loaded
     * for the whole rail in ONE batched query by the provider — never one
     * query per card. Returns null when the product has no video, and the
     * template then shows the product image in the same frame, which is the
     * honest fallback: no video URL is invented, and no placeholder clip is
     * substituted.
     *
     * @return array{url: string, title: string}|null
     */
    public function getVideo(ProductInterface $product): ?array
    {
        if (!$product instanceof Product) {
            return null;
        }

        $gallery = $product->getMediaGalleryImages();

        if ($gallery === null) {
            return null;
        }

        foreach ($gallery as $entry) {
            if ((string) $entry->getData('media_type') !== 'external-video') {
                continue;
            }

            $url = trim((string) $entry->getData('video_url'));

            if ($url === '') {
                continue;
            }

            return [
                'url' => $url,
                'title' => trim((string) $entry->getData('video_title')),
            ];
        }

        return null;
    }

    /**
     * The large portrait media image behind a video card.
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
     * @return array{url: string, width: int, height: int}|null
     */
    public function getMediaImage(ProductInterface $product): ?array
    {
        try {
            $image = $this->imageHelper->init($product, 'product_page_image_medium');

            return [
                'url' => (string) $image->getUrl(),
                'width' => (int) $image->getWidth(),
                'height' => (int) $image->getHeight(),
            ];
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
