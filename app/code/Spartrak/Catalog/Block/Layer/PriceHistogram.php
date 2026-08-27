<?php
/**
 * Copyright © Spartrak. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Catalog\Block\Layer;

use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Price-distribution histogram for the layered-navigation price filter.
 *
 * Figma node 569:14168 ("Price Range Slider") draws a 19-column histogram
 * sitting directly on top of the range slider: bars inside the selected
 * range are filled with action/primary, bars outside with bg/field. Nothing
 * in Magento or Mageplaza_LayeredNavigation exposes a price distribution —
 * Mageplaza's slider mode only knows the min and the max — so this block
 * supplies the one thing that was genuinely missing. It is a real
 * aggregation over real data; no bar height is ever invented.
 *
 * WHY A BLOCK AND NOT A VIEWMODEL: the consumer is a template override of
 * a third-party module (Mageplaza_LayeredNavigation::layer/filter.phtml)
 * that is rendered through Magento's FilterRenderer, which takes no
 * view_model argument. Wiring one would mean editing that renderer's
 * layout definition; creating this block from the template is the same
 * pattern the Spartrak card template already uses for the price render and
 * the wishlist button, and it keeps the whole feature to two new files.
 *
 * PERFORMANCE (CLAUDE.md section 4): two aggregate queries, both against
 * the price index, both behind Magento's cache. The cache key includes the
 * full layer state (every applied filter), the store, the customer group
 * and the category, so a given filter combination is computed once and
 * then served from cache; the entry is tagged with the category so a
 * category save invalidates it. If anything at all goes wrong the block
 * returns an empty distribution and the template renders no histogram —
 * a missing decoration, never a broken page.
 */
class PriceHistogram extends Template
{
    /**
     * Figma 569:14169 contains exactly nineteen 12px columns.
     */
    public const BUCKET_COUNT = 19;

    /**
     * Tallest bar in the design, in px (node 569:14179).
     */
    public const MAX_BAR_HEIGHT = 80;

    private const MIN_BAR_HEIGHT = 2;

    private const CACHE_PREFIX = 'spartrak_price_histogram_';

    private const CACHE_LIFETIME = 3600;

    /**
     * Above this many products the ID list stops being a sensible thing to
     * push into an IN() clause. The histogram is a decoration; it is not
     * worth a slow query, so it simply does not render.
     */
    private const MAX_PRODUCTS = 20000;

    private LayerResolver $layerResolver;

    private ResourceConnection $resource;

    private CacheInterface $cache;

    private StoreManagerInterface $storeManager;

    private CustomerSession $customerSession;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $distribution = null;

    public function __construct(
        Context $context,
        LayerResolver $layerResolver,
        ResourceConnection $resource,
        CacheInterface $cache,
        StoreManagerInterface $storeManager,
        CustomerSession $customerSession,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->layerResolver = $layerResolver;
        $this->resource = $resource;
        $this->cache = $cache;
        $this->storeManager = $storeManager;
        $this->customerSession = $customerSession;
    }

    /**
     * Bar descriptors for the template.
     *
     * @return array<int, array{height:int, count:int, from:float, to:float, inRange:bool}>
     */
    public function getBars(): array
    {
        $distribution = $this->getDistribution();

        return $distribution['bars'] ?? [];
    }

    public function getMinPrice(): float
    {
        return (float) ($this->getDistribution()['min'] ?? 0.0);
    }

    public function getMaxPrice(): float
    {
        return (float) ($this->getDistribution()['max'] ?? 0.0);
    }

    /**
     * The lower bound currently applied by the price filter, or the
     * catalogue minimum when no filter is applied.
     */
    public function getSelectedFrom(): float
    {
        [$from] = $this->getAppliedRange();

        return $from ?? $this->getMinPrice();
    }

    public function getSelectedTo(): float
    {
        [, $to] = $this->getAppliedRange();

        return $to ?? $this->getMaxPrice();
    }

    /**
     * False whenever there is nothing real to draw — no products, no price
     * data, or a catalogue where every product costs the same (which is
     * the case on this storefront until real pricing is imported: every
     * product is currently 0.00, which is also why the slider reads
     * "0.00 - 0.00" and the cards say "Price on request").
     */
    public function canShow(): bool
    {
        $distribution = $this->getDistribution();

        return !empty($distribution['bars']) && $distribution['max'] > $distribution['min'];
    }

    /**
     * The price filter's applied range, parsed from Magento's own
     * `price=from-to` request parameter.
     *
     * @return array{0: float|null, 1: float|null}
     */
    private function getAppliedRange(): array
    {
        $param = (string) $this->getRequest()->getParam('price', '');
        if ($param === '' || !str_contains($param, '-')) {
            return [null, null];
        }

        [$from, $to] = array_pad(explode('-', $param, 2), 2, '');

        return [
            is_numeric($from) ? (float) $from : null,
            is_numeric($to) ? (float) $to : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getDistribution(): array
    {
        if ($this->distribution !== null) {
            return $this->distribution;
        }

        $this->distribution = ['min' => 0.0, 'max' => 0.0, 'bars' => []];

        try {
            $cacheKey = $this->buildCacheKey();
            $cached = $this->cache->load($cacheKey);
            if ($cached !== false && $cached !== null) {
                $decoded = json_decode((string) $cached, true);
                if (is_array($decoded)) {
                    return $this->distribution = $decoded;
                }
            }

            $computed = $this->compute();
            $this->cache->save(
                json_encode($computed),
                $cacheKey,
                [\Magento\Catalog\Model\Category::CACHE_TAG],
                self::CACHE_LIFETIME
            );

            $this->distribution = $computed;
        } catch (\Throwable $e) {
            // A decoration must never take the page down. Log and render
            // nothing rather than half a histogram.
            $this->_logger->warning(
                'Spartrak price histogram skipped: ' . $e->getMessage()
            );
        }

        return $this->distribution;
    }

    /**
     * @return array<string, mixed>
     */
    private function compute(): array
    {
        $empty = ['min' => 0.0, 'max' => 0.0, 'bars' => []];

        $collection = $this->layerResolver->get()->getProductCollection();

        // Only ever read a collection the page has ALREADY loaded. The
        // layered-navigation sidebar shares one collection object with the
        // product list; calling getAllIds() on it before the list has loaded
        // it would run a query against a half-built select and could disturb
        // the toolbar's own paging. In the 2columns-left layout the main
        // column renders first, so by the time this runs the collection is
        // loaded — and if some other layout order ever makes that untrue,
        // the histogram simply does not draw.
        if (!$collection->isLoaded()) {
            return $empty;
        }

        $productIds = $collection->getAllIds();
        if (!$productIds || count($productIds) > self::MAX_PRODUCTS) {
            return $empty;
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('catalog_product_index_price');
        $websiteId = (int) $this->storeManager->getStore()->getWebsiteId();
        $groupId = (int) $this->customerSession->getCustomerGroupId();

        $boundsSelect = $connection->select()
            ->from($table, [
                'min_value' => new \Zend_Db_Expr('MIN(min_price)'),
                'max_value' => new \Zend_Db_Expr('MAX(min_price)'),
            ])
            ->where('entity_id IN (?)', $productIds)
            ->where('customer_group_id = ?', $groupId)
            ->where('website_id = ?', $websiteId);

        $bounds = $connection->fetchRow($boundsSelect);
        if (!$bounds || $bounds['min_value'] === null) {
            return $empty;
        }

        $min = (float) $bounds['min_value'];
        $max = (float) $bounds['max_value'];
        if ($max <= $min) {
            // Every product costs the same — a histogram of one column is
            // not a histogram. Report the bounds, draw nothing.
            return ['min' => $min, 'max' => $max, 'bars' => []];
        }

        $step = ($max - $min) / self::BUCKET_COUNT;

        $bucketExpr = new \Zend_Db_Expr(
            sprintf(
                'LEAST(FLOOR((min_price - %s) / %s), %d)',
                $connection->quote($min),
                $connection->quote($step),
                self::BUCKET_COUNT - 1
            )
        );

        $bucketSelect = $connection->select()
            ->from($table, ['bucket' => $bucketExpr, 'total' => new \Zend_Db_Expr('COUNT(*)')])
            ->where('entity_id IN (?)', $productIds)
            ->where('customer_group_id = ?', $groupId)
            ->where('website_id = ?', $websiteId)
            ->group('bucket');

        $counts = [];
        foreach ($connection->fetchAll($bucketSelect) as $row) {
            $counts[(int) $row['bucket']] = (int) $row['total'];
        }

        if (!$counts) {
            return $empty;
        }

        $peak = max($counts);
        [$appliedFrom, $appliedTo] = $this->getAppliedRange();
        $rangeFrom = $appliedFrom ?? $min;
        $rangeTo = $appliedTo ?? $max;

        $bars = [];
        for ($i = 0; $i < self::BUCKET_COUNT; $i++) {
            $count = $counts[$i] ?? 0;
            $from = $min + ($step * $i);
            $to = $from + $step;

            $bars[] = [
                'count' => $count,
                'from' => round($from, 2),
                'to' => round($to, 2),
                // Proportional to the tallest column, floored so an empty
                // bucket still reads as a bucket rather than vanishing.
                'height' => $peak > 0
                    ? max(self::MIN_BAR_HEIGHT, (int) round(($count / $peak) * self::MAX_BAR_HEIGHT))
                    : self::MIN_BAR_HEIGHT,
                'inRange' => ($to > $rangeFrom && $from < $rangeTo),
            ];
        }

        return ['min' => $min, 'max' => $max, 'bars' => $bars];
    }

    /**
     * Keyed on everything the numbers depend on: the applied layer state
     * (Magento's own state key, which encodes every active filter), the
     * category, the store and the customer group.
     */
    private function buildCacheKey(): string
    {
        $layer = $this->layerResolver->get();

        $stateParts = [];
        foreach ($layer->getState()->getFilters() as $stateFilter) {
            $stateParts[] = $stateFilter->getFilter()->getRequestVar() . '=' . $stateFilter->getValueString();
        }
        sort($stateParts);

        $category = $layer->getCurrentCategory();

        return self::CACHE_PREFIX . sha1(implode('|', [
            $category ? (int) $category->getId() : 0,
            (int) $this->storeManager->getStore()->getId(),
            (int) $this->customerSession->getCustomerGroupId(),
            (string) $this->getRequest()->getParam('q', ''),
            implode(',', $stateParts),
        ]));
    }
}
