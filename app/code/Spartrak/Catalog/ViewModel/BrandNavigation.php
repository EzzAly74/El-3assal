<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Catalog\ViewModel;

use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Framework\App\Config;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Swatches\Helper\Data as SwatchHelper;
use Magento\Swatches\Helper\Media as SwatchMediaHelper;
use Magento\Swatches\Model\Swatch;
use Psr\Log\LoggerInterface;

/**
 * Brand list for the header's "أكتشف بالبراند" (Discover by brand) pane —
 * Figma node 704:26408, inside the "Shop by categories" mega panel
 * (704:26365).
 *
 * ===========================================================================
 * EVERYTHING HERE IS ADMIN-MANAGED. NOTHING IS HARDCODED.
 * ===========================================================================
 * Both halves of a brand come from Magento, per CLAUDE.md §7 (admin owns
 * content, the theme owns presentation) and §9 (never hardcode dynamic
 * business data):
 *
 *   WHICH BRANDS   the option list of the `brand` product attribute.
 *                  Admin adds/removes/renames/reorders them in
 *                  Stores > Attributes > Product > brand > Manage Options.
 *                  Option sort order is respected, so merchandising order is
 *                  an admin decision, not a code one.
 *
 *   THEIR LOGOS    the per-option VISUAL SWATCH IMAGE that Magento already
 *                  supports for select attributes. Admin uploads one image
 *                  per option on that same screen, and Magento stores it
 *                  under pub/media/attribute/swatch/. Read here via
 *                  Magento_Swatches' own helpers — no new table, no new
 *                  entity, no filename convention invented in code.
 *
 * A REJECTED EARLIER VERSION of this class carried a `LOGO_MAP` constant
 * mapping Arabic brand labels to PNGs committed in the theme. That was wrong:
 * it made brand artwork a developer deployment instead of an admin task, and
 * any brand the client added later would have silently had no logo. Deleted.
 *
 * ADMIN SETUP REQUIRED (one-off, no code): set the `brand` attribute's
 * "Catalog Input Type for Store Owner" to **Visual Swatch**, then upload each
 * brand's mark against its option. Until that is done, getBrands() returns
 * logo => null and the template renders the brand's initial in the same
 * plate — an honest empty state, never a broken image and never a silently
 * dropped brand. The eight marks Figma specifies have been exported from the
 * design file at 2x and handed over for upload.
 *
 * ===========================================================================
 * CACHING
 * ===========================================================================
 * The header renders on every page, and resolving options + swatches touches
 * the EAV option tables and the swatch table. The resolved list is therefore
 * cached per store under the config cache tag, so it is rebuilt when the
 * attribute is saved or cache is flushed. Without this the mega menu would
 * add avoidable queries to every request — CLAUDE.md §4 treats that as a
 * regression, not a detail.
 */
class BrandNavigation implements ArgumentInterface
{
    /**
     * Attribute whose option list is the brand vocabulary. VERIFIED, not
     * assumed: Spartrak_Catalog's own product/card.phtml already reads
     * $product->getAttributeText('brand'), and the live PDP renders a "Brand"
     * row — so this attribute exists and is populated.
     */
    private const ATTRIBUTE_CODE = 'brand';

    private const CACHE_KEY_PREFIX = 'spartrak_brand_navigation_';

    private ProductAttributeRepositoryInterface $attributeRepository;
    private SwatchHelper $swatchHelper;
    private SwatchMediaHelper $swatchMediaHelper;
    private StoreManagerInterface $storeManager;
    private UrlInterface $urlBuilder;
    private CacheInterface $cache;
    private SerializerInterface $serializer;
    private LoggerInterface $logger;

    /** @var array<int, array<int, array{label: string, value: string, logo: ?string, url: string}>> */
    private array $resolved = [];

    public function __construct(
        ProductAttributeRepositoryInterface $attributeRepository,
        SwatchHelper $swatchHelper,
        SwatchMediaHelper $swatchMediaHelper,
        StoreManagerInterface $storeManager,
        UrlInterface $urlBuilder,
        CacheInterface $cache,
        SerializerInterface $serializer,
        LoggerInterface $logger
    ) {
        $this->attributeRepository = $attributeRepository;
        $this->swatchHelper = $swatchHelper;
        $this->swatchMediaHelper = $swatchMediaHelper;
        $this->storeManager = $storeManager;
        $this->urlBuilder = $urlBuilder;
        $this->cache = $cache;
        $this->serializer = $serializer;
        $this->logger = $logger;
    }

    /**
     * Brands to render, in the attribute's own admin-defined option order.
     *
     * @return array<int, array{label: string, value: string, logo: ?string, url: string}>
     */
    public function getBrands(): array
    {
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
        } catch (NoSuchEntityException $e) {
            return [];
        }

        if (isset($this->resolved[$storeId])) {
            return $this->resolved[$storeId];
        }

        $cacheKey = self::CACHE_KEY_PREFIX . $storeId;
        $cached = $this->cache->load($cacheKey);

        if (is_string($cached) && $cached !== '') {
            try {
                $decoded = $this->serializer->unserialize($cached);
                if (is_array($decoded)) {
                    return $this->resolved[$storeId] = $decoded;
                }
            } catch (\InvalidArgumentException $e) {
                // Corrupt entry — rebuild rather than fail the header render.
            }
        }

        $brands = $this->build();

        $this->cache->save(
            $this->serializer->serialize($brands),
            $cacheKey,
            [Config::CACHE_TAG],
            3600
        );

        return $this->resolved[$storeId] = $brands;
    }

    /**
     * True when there is anything to render, so the template can skip the
     * whole pane — heading included — rather than emitting an empty column.
     */
    public function hasBrands(): bool
    {
        return $this->getBrands() !== [];
    }

    /**
     * @return array<int, array{label: string, value: string, logo: ?string, url: string}>
     */
    private function build(): array
    {
        try {
            $attribute = $this->attributeRepository->get(self::ATTRIBUTE_CODE);
        } catch (NoSuchEntityException $e) {
            // The attribute genuinely does not exist on this install. Log and
            // render nothing — never invent a brand list to fill the gap.
            $this->logger->info(
                'Spartrak: brand navigation skipped, attribute "' . self::ATTRIBUTE_CODE . '" not found.'
            );

            return [];
        }

        $source = $attribute->getSource();
        if ($source === null) {
            return [];
        }

        // A LIST of pairs, deliberately not a map keyed by option id.
        //
        // PHP silently casts numeric-string array keys to int, so
        // `$options['123'] = $label` becomes `$options[123]`, and iterating it
        // back out yields an INT where a string option id is expected. Under
        // declare(strict_types=1) that is a hard TypeError, which is exactly
        // how this method crashed the storefront on first deploy:
        //   BrandNavigation::buildFilterUrl(): Argument #3 ($optionId) must be
        //   of type string, int given
        // Keeping the id in a value slot instead of a key slot means nothing
        // can re-type it behind our back.
        $options = [];
        foreach ($source->getAllOptions(false) as $option) {
            $label = isset($option['label']) ? trim((string) $option['label']) : '';
            $value = isset($option['value']) ? trim((string) $option['value']) : '';

            if ($label !== '' && $value !== '') {
                $options[] = ['value' => $value, 'label' => $label];
            }
        }

        if ($options === []) {
            return [];
        }

        $logos = $this->resolveSwatchImages(array_column($options, 'value'));
        $attributeCode = (string) $attribute->getAttributeCode();

        $brands = [];
        foreach ($options as $option) {
            $value = $option['value'];
            $label = $option['label'];

            $brands[] = [
                'label' => $label,
                'value' => $value,
                // $logos may legitimately be int-keyed for numeric option ids
                // (same coercion as above). A string lookup still resolves,
                // because PHP coerces numeric-string subscripts to int too —
                // so this stays correct either way.
                'logo' => $logos[$value] ?? null,
                'url' => $this->buildFilterUrl($attributeCode, $label, $value),
            ];
        }

        return $brands;
    }

    /**
     * Map option id => absolute swatch image URL, for options whose
     * admin-uploaded swatch is an IMAGE.
     *
     * Colour swatches (type 1) and text swatches (type 0) are skipped: this
     * pane renders brand marks, and a flat colour chip is not one. Those
     * options fall through to the initial-letter plate.
     *
     * @param  array<int, string> $optionIds
     * @return array<array-key, string>
     */
    private function resolveSwatchImages(array $optionIds): array
    {
        $urls = [];

        if ($optionIds === []) {
            return $urls;
        }

        try {
            $swatches = $this->swatchHelper->getSwatchesByOptionsId($optionIds);
        } catch (\Exception $e) {
            // Swatch tables unavailable / attribute not swatch-enabled yet.
            // Not an error worth breaking the header over — every brand simply
            // renders its initial until admin configures swatches.
            return $urls;
        }

        foreach ($swatches as $optionId => $swatch) {
            if (!is_array($swatch)) {
                continue;
            }

            $type = isset($swatch['type']) ? (int) $swatch['type'] : Swatch::SWATCH_TYPE_EMPTY;
            $file = isset($swatch['value']) ? (string) $swatch['value'] : '';

            if ($type !== Swatch::SWATCH_TYPE_VISUAL_IMAGE || $file === '') {
                continue;
            }

            try {
                $urls[(string) $optionId] = $this->swatchMediaHelper->getSwatchAttributeImage(
                    Swatch::SWATCH_IMAGE_NAME,
                    $file
                );
            } catch (\Exception $e) {
                // A single unreadable swatch file must not take out the pane.
                continue;
            }
        }

        return $urls;
    }

    /**
     * A URL that genuinely filters the catalog to this brand.
     *
     * Two parameters, deliberately:
     *   q      — the brand label. This catalog's search is brand-aware by
     *            design (catalog/SEARCH-SPEC.md: a query must match brand +
     *            part-type in one query, order-independent, with Arabic<->Latin
     *            brand synonyms), so the label alone already resolves to that
     *            brand's products.
     *   <code> — the attribute code carrying the OPTION ID, which is what
     *            Magento's layered navigation reads. This turns the result into
     *            a real attribute filter rather than a text-relevance guess,
     *            and it is the same parameter the PLP's own brand filter
     *            produces, so the filter chip renders and is removable.
     *
     * Uses catalogsearch/result because no dedicated brand landing route
     * exists yet — that is the Module 2 dependency documented in the theme's
     * topmenu.phtml. When it lands, only this method changes.
     *
     * $optionId is intentionally untyped-then-cast rather than declared
     * `string`: option ids arrive from EAV as either int or string depending
     * on the source model, and under declare(strict_types=1) a `string`
     * parameter turns that harmless variance into a fatal TypeError (which is
     * how this crashed on first deploy). Casting inside the method is the
     * tolerant boundary; callers should not have to know.
     *
     * @param int|string $optionId
     */
    private function buildFilterUrl(string $attributeCode, string $label, $optionId): string
    {
        return $this->urlBuilder->getUrl(
            'catalogsearch/result',
            [
                '_query' => [
                    'q' => $label,
                    $attributeCode => (string) $optionId,
                ],
            ]
        );
    }
}
