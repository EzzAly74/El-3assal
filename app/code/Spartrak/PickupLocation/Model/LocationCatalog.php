<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Spartrak\Locale\Model\StoreLanguage;
use Spartrak\PickupLocation\Model\ResourceModel\Branch\CollectionFactory as BranchCollectionFactory;
use Spartrak\PickupLocation\Model\ResourceModel\Depot\CollectionFactory as DepotCollectionFactory;
use Spartrak\PickupLocation\Model\ResourceModel\Operator\CollectionFactory as OperatorCollectionFactory;

/**
 * The storefront's ONLY read path into the pickup tables.
 *
 * ===========================================================================
 * WHY ARRAYS AND NOT TYPED DTOs
 * ===========================================================================
 * Every consumer of this class is a JSON boundary - the checkout config
 * provider hands these straight to Knockout. Typed value objects would mean
 * building objects on the way into the cache and tearing them back down into
 * arrays on the way out, on a page whose first requirement is LCP. The shape
 * is documented on each method instead, and it is the same shape the browser
 * receives, so there is nothing to keep in step.
 *
 * ===========================================================================
 * WHY IT CACHES
 * ===========================================================================
 * Branches and depots change a few times a year and are read on every single
 * checkout render. Caching the resolved, locale-picked list turns three
 * queries and a join into one cache read. Invalidation is by tag, published by
 * the entities themselves (Model\Branch::getIdentities and friends), so saving
 * a depot in the admin drops the list without anyone remembering to.
 *
 * The cache key carries the store id because the locale pick differs per store
 * view - an English store view must not be served the Arabic list because an
 * Arabic one warmed the cache first.
 */
class LocationCatalog
{
    /**
     * One tag for the whole subsystem, in addition to each entity's own.
     *
     * A depot save invalidates spartrak_pickup_depot_<id>; that tag is on the
     * ENTITY, and this cache entry is a LIST, so it needs a tag of its own that
     * every location type also publishes. Operator::getIdentities() already
     * returns the blanket depot tag for the same reason.
     */
    private const CACHE_TAG = 'spartrak_pickup_location';

    private const CACHE_KEY_PREFIX = 'spartrak_pickup_';

    /** Cached for a day; a tag invalidation is what actually refreshes it. */
    private const CACHE_LIFETIME = 86400;

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $memo = [];

    public function __construct(
        private readonly BranchCollectionFactory $branchCollectionFactory,
        private readonly DepotCollectionFactory $depotCollectionFactory,
        private readonly OperatorCollectionFactory $operatorCollectionFactory,
        private readonly StoreLanguage $storeLanguage,
        private readonly StoreManagerInterface $storeManager,
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer
    ) {
    }

    /**
     * Enabled branches for the current store view, in dashboard order.
     *
     * Shape, per row:
     *   id         int
     *   name       string
     *   address    string
     *   phone      string|null
     *   region     string       governorate name, '' when unrecorded
     *   region_id  int|null
     *
     * The region travels with the row because collecting in person still
     * produces a shipping address on the order - see
     * Plugin\Checkout\ApplyPickupLocation, which builds that address out of
     * exactly these fields.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getBranches(): array
    {
        return $this->load('branch', function (): array {
            $rows = [];

            $collection = $this->branchCollectionFactory->create()
                ->addActiveInSortOrder()
                ->joinRegionName($this->resolveRegionLocale());

            /** @var Branch $branch */
            foreach ($collection as $branch) {
                $name = $this->storeLanguage->pick($branch->getNameColumns(), 'name');
                $address = $this->storeLanguage->pick($branch->getAddressColumns(), 'address');

                // A branch with no readable name in EITHER language is a
                // half-finished admin row, not something to render blank.
                if ($name === null) {
                    continue;
                }

                $rows[] = [
                    'id' => (int) $branch->getId(),
                    'name' => $name,
                    'address' => $address ?? '',
                    'phone' => $branch->getPhone(),
                    'region' => (string) $branch->getData('region_name'),
                    'region_id' => $branch->getRegionId(),
                ];
            }

            return $rows;
        });
    }

    /**
     * Enabled depots for the current store view, in dashboard order.
     *
     * Shape, per row:
     *   id            int
     *   name          string
     *   address       string
     *   region        string        governorate name, '' when unrecorded
     *   region_id     int|null
     *   operator_id   int|null
     *   operator      string|null   resolved chip label
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDepots(): array
    {
        return $this->load('depot', function (): array {
            // Resolved once and indexed, rather than a lookup per depot row.
            $operatorNames = [];
            foreach ($this->getOperators() as $operator) {
                $operatorNames[$operator['id']] = $operator['name'];
            }

            $rows = [];

            $collection = $this->depotCollectionFactory->create()
                ->addActiveInSortOrder()
                ->joinRegionName($this->resolveRegionLocale());

            /** @var Depot $depot */
            foreach ($collection as $depot) {
                $name = $this->storeLanguage->pick($depot->getNameColumns(), 'name');
                $address = $this->storeLanguage->pick($depot->getAddressColumns(), 'address');

                if ($name === null) {
                    continue;
                }

                $operatorId = $depot->getOperatorId();

                $rows[] = [
                    'id' => (int) $depot->getId(),
                    'name' => $name,
                    'address' => $address ?? '',
                    'region' => (string) $depot->getData('region_name'),
                    'region_id' => $depot->getRegionId(),
                    'operator_id' => $operatorId,
                    // An operator that has since been DISABLED is not in the
                    // index, so the chip simply disappears rather than
                    // rendering a name the filter row cannot offer.
                    'operator' => $operatorId !== null ? ($operatorNames[$operatorId] ?? null) : null,
                ];
            }

            return $rows;
        });
    }

    /**
     * Enabled operators for the current store view, in chip order.
     *
     * Shape, per row:
     *   id    int
     *   code  string
     *   name  string
     *
     * @return array<int, array<string, mixed>>
     */
    public function getOperators(): array
    {
        return $this->load('operator', function (): array {
            $rows = [];

            $collection = $this->operatorCollectionFactory->create()->addActiveInSortOrder();

            /** @var Operator $operator */
            foreach ($collection as $operator) {
                $name = $this->storeLanguage->pick($operator->getNameColumns(), 'name');

                if ($name === null) {
                    continue;
                }

                $rows[] = [
                    'id' => (int) $operator->getId(),
                    'code' => (string) $operator->getData('code'),
                    'name' => $name,
                ];
            }

            return $rows;
        });
    }

    /**
     * True when there is at least one branch to collect from.
     *
     * The carrier asks this before offering itself: a pickup method a shopper
     * cannot complete is worse than no method at all. Cheap, because the list
     * it counts is already cached.
     */
    public function hasBranches(): bool
    {
        return $this->getBranches() !== [];
    }

    public function hasDepots(): bool
    {
        return $this->getDepots() !== [];
    }

    /**
     * One branch by id, or null. Used when an order is placed, to snapshot the
     * name and address onto the address row.
     *
     * @return array<string, mixed>|null
     */
    public function getBranchById(int $branchId): ?array
    {
        foreach ($this->getBranches() as $branch) {
            if ($branch['id'] === $branchId) {
                return $branch;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDepotById(int $depotId): ?array
    {
        foreach ($this->getDepots() as $depot) {
            if ($depot['id'] === $depotId) {
                return $depot;
            }
        }

        return null;
    }

    /**
     * Memo -> cache -> database, in that order.
     *
     * @param callable(): array<int, array<string, mixed>> $builder
     * @return array<int, array<string, mixed>>
     */
    private function load(string $bucket, callable $builder): array
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        $key = self::CACHE_KEY_PREFIX . $bucket . '_' . $storeId;

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $cached = $this->cache->load($key);

        if (is_string($cached) && $cached !== '') {
            $decoded = $this->serializer->unserialize($cached);

            if (is_array($decoded)) {
                return $this->memo[$key] = $decoded;
            }
        }

        $rows = $builder();

        $this->cache->save(
            $this->serializer->serialize($rows),
            $key,
            [self::CACHE_TAG, Branch::CACHE_TAG, Depot::CACHE_TAG, Operator::CACHE_TAG],
            self::CACHE_LIFETIME
        );

        return $this->memo[$key] = $rows;
    }

    /**
     * Which locale row to read a governorate name from.
     *
     * directory_country_region_name is keyed by locale, and
     * Setup\Patch\Data\AddEgyptGovernorates writes both an ar_EG row and an
     * en_US row. Asking for the store's own locale therefore returns the
     * governorate in the language the rest of the page is in.
     */
    private function resolveRegionLocale(): string
    {
        return $this->storeLanguage->isArabic() ? 'ar_EG' : 'en_US';
    }
}
