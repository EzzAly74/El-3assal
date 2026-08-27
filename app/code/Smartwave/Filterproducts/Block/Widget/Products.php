<?php
/**
 * Copyright 2014 Adobe
 * All Rights Reserved.
 */

namespace Smartwave\Filterproducts\Block\Widget;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Block\Product\AbstractProduct;
use Magento\Catalog\Block\Product\Context;
use Magento\Catalog\Block\Product\Widget\Html\Pager;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Pricing\Price\FinalPrice;
use Magento\Catalog\ViewModel\Product\OptionsData;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Url\EncoderInterface;
use Magento\Framework\View\LayoutFactory;
use Magento\Framework\View\LayoutInterface;
use Magento\Widget\Block\BlockInterface;

/**
 * Catalog Products List widget block
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 */
class Products extends AbstractProduct implements BlockInterface, IdentityInterface
{
    /**
     * Default value for products count that will be shown
     */
    public const DEFAULT_PRODUCTS_COUNT = 10;

    /**
     * Name of request parameter for page number value
     *
     * @deprecated
     * @see $this->getData('page_var_name')
     */
    public const PAGE_VAR_NAME = 'np';

    /**
     * Default value for products per page
     */
    public const DEFAULT_PRODUCTS_PER_PAGE = 5;

    /**
     * Default value whether show pager or not
     */
    public const DEFAULT_SHOW_PAGER = false;

    /**
     * Default value whether show type list
     */

    public const DISPLAY_TYPE_PRODUCTS = 'latest_products';

    /**
     * @var Pager
     */
    protected $pager;

    /**
     * @var HttpContext
     */
    protected $httpContext;

    /**
     * @var Visibility
     */
    protected $catalogProductVisibility;

    /**
     * @var CollectionFactory
     */
    protected $productCollectionFactory;

    /**
     * @var PriceCurrencyInterface
     */
    private $priceCurrency;

    /**
     * Json Serializer Instance
     *
     * @var Json
     */
    private $json;

    /**
     * @var LayoutFactory
     */
    private $layoutFactory;

    /**
     * @var EncoderInterface|null
     */
    private $urlEncoder;

    /**
     * @var RendererList
     */
    private $rendererListBlock;

    /**
     * @var CategoryRepositoryInterface
     */
    private $categoryRepository;

    /**
     * @var OptionsData
     */
    private OptionsData $optionsData;

    /**
     * @param Context $context
     * @param CollectionFactory $productCollectionFactory
     * @param Visibility $catalogProductVisibility
     * @param HttpContext $httpContext
     * @param array $data
     * @param Json|null $json
     * @param LayoutFactory|null $layoutFactory
     * @param EncoderInterface|null $urlEncoder
     * @param CategoryRepositoryInterface|null $categoryRepository
     * @param OptionsData|null $optionsData
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Context $context,
        CollectionFactory $productCollectionFactory,
        Visibility $catalogProductVisibility,
        HttpContext $httpContext,
        array $data = [],
        ?Json $json = null,
        ?LayoutFactory $layoutFactory = null,
        ?EncoderInterface $urlEncoder = null,
        ?CategoryRepositoryInterface $categoryRepository = null,
        ?OptionsData $optionsData = null
    ) {
        $this->productCollectionFactory = $productCollectionFactory;
        $this->catalogProductVisibility = $catalogProductVisibility;
        $this->httpContext = $httpContext;
        $this->json = $json ?: ObjectManager::getInstance()->get(Json::class);
        $this->layoutFactory = $layoutFactory ?: ObjectManager::getInstance()->get(LayoutFactory::class);
        $this->urlEncoder = $urlEncoder ?: ObjectManager::getInstance()->get(EncoderInterface::class);
        $this->categoryRepository = $categoryRepository ?? ObjectManager::getInstance()
                ->get(CategoryRepositoryInterface::class);
        $this->optionsData = $optionsData ?: ObjectManager::getInstance()->get(OptionsData::class);
        parent::__construct(
            $context,
            $data
        );
    }

    /**
     * Internal constructor, that is called from real constructor
     *
     * @return void
     */
    protected function _construct()
    {
        parent::_construct();

        $this->addData([
            'cache_lifetime' => 86400,
            'cache_tags' => [
                Product::CACHE_TAG,
            ],
        ]);
    }

    /**
     * Get key pieces for caching block content
     *
     * @return array
     * @SuppressWarnings(PHPMD.RequestAwareBlockMethod)
     * @throws NoSuchEntityException
     */
    public function getCacheKeyInfo()
    {

        return [
            'FILTER_PRODUCTS_LIST_WIDGET',
            $this->getPriceCurrency()->getCurrency()->getCode(),
            $this->_storeManager->getStore()->getId(),
            $this->_design->getDesignTheme()->getId(),
            $this->httpContext->getValue(\Magento\Customer\Model\Context::CONTEXT_GROUP),
            $this->json->serialize($this->httpContext->getValue('tax_rates')),
            (int)$this->getRequest()->getParam($this->getData('page_var_name'), 1),
            $this->getProductsPerPage(),
            $this->getProductsCount(),
            $this->json->serialize($this->getRequest()->getParams()),
            $this->getTemplate(),
            $this->getTitle()
        ];
    }

    /**
     * @inheritdoc
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function getProductPriceHtml(
        Product $product,
        $priceType = null,
        $renderZone = \Magento\Framework\Pricing\Render::ZONE_ITEM_LIST,
        array $arguments = []
    ) {
        if (!isset($arguments['zone'])) {
            $arguments['zone'] = $renderZone;
        }
        $arguments['price_id'] = isset($arguments['price_id'])
            ? $arguments['price_id']
            : 'old-price-' . $product->getId() . '-' . $priceType;
        $arguments['include_container'] = isset($arguments['include_container'])
            ? $arguments['include_container']
            : true;
        $arguments['display_minimal_price'] = isset($arguments['display_minimal_price'])
            ? $arguments['display_minimal_price']
            : true;

        /** @var \Magento\Framework\Pricing\Render $priceRender */
        $priceRender = $this->getLayout()->getBlock('product.price.render.default');
        if (!$priceRender) {
            $priceRender = $this->getLayout()->createBlock(
                \Magento\Framework\Pricing\Render::class,
                'product.price.render.default',
                ['data' => ['price_render_handle' => 'catalog_product_prices']]
            );
        }

        $price = $priceRender->render(
            FinalPrice::PRICE_CODE,
            $product,
            $arguments
        );

        return $price;
    }

    /**
     * @inheritdoc
     */
    protected function getDetailsRendererList()
    {
        if (empty($this->rendererListBlock)) {
            /** @var $layout LayoutInterface */
            $layout = $this->layoutFactory->create(['cacheable' => false]);
            $layout->getUpdate()->addHandle('catalog_widget_product_list')->load();
            $layout->generateXml();
            $layout->generateElements();

            $this->rendererListBlock = $layout->getBlock('category.product.type.widget.details.renderers');
        }
        return $this->rendererListBlock;
    }

    /**
     * Get post parameters.
     *
     * @param Product $product
     * @return array
     */
    public function getAddToCartPostParams(Product $product)
    {
        $url = $this->getAddToCartUrl($product);
        return [
            'action' => $url,
            'data' => [
                'product' => $product->getEntityId(),
                'options' => $this->optionsData->getOptionsData($product),
                ActionInterface::PARAM_NAME_URL_ENCODED => $this->urlEncoder->encode($url),
            ]
        ];
    }

    /**
     * Return product options
     *
     * @param Product $product
     * @return array
     */
    public function getOptionsData(Product $product): array
    {
        return $this->optionsData->getOptionsData($product);
    }

    /**
     * @inheritdoc
     */
    protected function _beforeToHtml()
    {
        $this->setProductCollection($this->createCollection());
        return parent::_beforeToHtml();
    }

    /**
     * Prepare and return product collection
     *
     * @return Collection
     * @SuppressWarnings(PHPMD.RequestAwareBlockMethod)
     * @throws LocalizedException
     */
    public function createCollection()
    {
        $collection = $this->getBaseCollection();

        $collection->setVisibility($this->catalogProductVisibility->getVisibleInCatalogIds());

        return $collection;
    }

    /**
     * Prepare and return product collection without visibility filter
     *
     * @return Collection
     * @throws LocalizedException
     */
    public function getBaseCollection(): Collection
    {
        $category_id = '';
        if($this->getData('category_ids')!=''){
          if(explode('/', $this->getData('category_ids'))[0] == 'category'){
            $category_id = explode('/', $this->getData('category_ids'))[1];
          }else{
            $category_id = $this->getData('category_ids');
          }
        }

        $collection = $this->productCollectionFactory->create();
        if ($this->getData('store_id') !== null) {
            $collection->setStoreId($this->getData('store_id'));
        }
        /**
         * Change sorting attribute to entity_id because created_at can be the same for products fastly created
         * one by one and sorting by created_at is indeterministic in this case.
         */
        $collection = $this->_addProductAttributesAndPrices($collection)
            ->addStoreFilter()
            ->setPageSize($this->getPageSize())
            ->setCurPage($this->getRequest()->getParam($this->getData('page_var_name'), 1));


        if($category_id){
          $category = $this->categoryRepository->get($category_id);
        }
        if(isset($category) && !empty($category->getData()))
        {
          $collection->addCategoryFilter($category);
        }

        switch ($this->getDisplayType())
        {
            case 'new_products':
                $collection->addAttributeToFilter(
                      'news_from_date',
                      ['date' => true, 'to' => $this->getEndOfDayDate()],
                      'left')
                  ->addAttributeToFilter(
                      'news_to_date',
                      [
                          'or' => [
                              0 => ['date' => true, 'from' => $this->getStartOfDayDate()],
                              1 => ['is' => new \Zend_Db_Expr('null')],
                          ]
                      ],
                      'left')
                  ->addAttributeToSort(
                      'news_from_date',
                      'desc');
                break;
            case 'featured_products':
                $collection->addAttributeToFilter('sw_featured', 1, 'left');
                break;
            case 'bestseller_products':
                $collection->getSelect()
                    ->joinLeft(['soi' => $collection->getTable('sales_order_item')], 'soi.product_id = e.entity_id', ['SUM(soi.qty_ordered) AS ordered_qty'])
                    ->join(['order' => $collection->getTable('sales_order')], "order.entity_id = soi.order_id",['order.state'])
                    ->where("order.state <> 'canceled' and soi.parent_item_id IS NULL AND soi.product_id IS NOT NULL")
                    ->group('soi.product_id')
                    ->order('ordered_qty DESC');
                /*$collection->getSelect()
                  ->join(['bestsellers' => $collection->getTable('sales_bestsellers_aggregated_yearly')],
                      'e.entity_id = bestsellers.product_id AND bestsellers.store_id = '.$this->getData('store_id'),
                      ['qty_ordered','rating_pos'])
                  ->order('rating_pos');*/
                break;
            case 'sale_products':
                $collection->addAttributeToFilter('special_price', ['neq' => ''])
                ->addAttributeToFilter(
                      'special_from_date',
                      ['date' => true, 'to' => $this->getEndOfDayDate()],
                      'left')
                  ->addAttributeToFilter(
                      'special_to_date',
                      [
                          'or' => [
                              0 => ['date' => true, 'from' => $this->getStartOfDayDate()],
                              1 => ['is' => new \Zend_Db_Expr('null')],
                          ]
                      ],
                      'left')
                  ->addAttributeToSort(
                      'news_from_date',
                      'desc');
                break;
            case 'deal_products':
                $collection->getSelect()
                    ->joinLeft(['dai' => $collection->getTable('sw_dailydeals_dailydeal')], 'dai.sw_product_sku = e.sku')
                    ->where('dai.sw_deal_enable=1')
                    ->where(
                        'dai.sw_date_from <= "'.$this->getDayDate().'" or dai.sw_date_from IS NULL'
                    )->where(
                        'dai.sw_date_to >= "'.$this->getDayDate().'" or dai.sw_date_to IS NULL'
                    );
                break;
            default:
                $collection->addAttributeToSort('created_at','desc');
                break;
        }

        /**
         * Prevent retrieval of duplicate records. This may occur when multiselect product attribute matches
         * several allowed values from condition simultaneously
         */
        $collection->distinct(true);

        return $collection;
    }

    public function getDisplayType()
    {
        if (!$this->hasData('display_type')) {
            $this->setData('display_type', self::DISPLAY_TYPE_PRODUCTS);
        }
        return $this->getData('display_type');
    }

    /**
     * Retrieve how many products should be displayed
     *
     * @return int
     */
    public function getProductsCount()
    {
        if ($this->hasData('product_count')) {
            return $this->getData('product_count');
        }

        if (null === $this->getData('product_count')) {
            $this->setData('product_count', self::DEFAULT_PRODUCTS_COUNT);
        }
        return $this->getData('product_count');
    }

    /**
     * Retrieve how many products should be displayed
     *
     * @return int
     */
    public function getProductsPerPage()
    {
        if (!$this->hasData('products_per_page')) {
            $this->setData('products_per_page', self::DEFAULT_PRODUCTS_PER_PAGE);
        }
        return $this->getData('products_per_page');
    }

    /**
     * Return flag whether pager need to be shown or not
     *
     * @return bool
     */
    public function showPager()
    {
        if (!$this->hasData('show_pager')) {
            $this->setData('show_pager', self::DEFAULT_SHOW_PAGER);
        }
        return (bool)$this->getData('show_pager');
    }

    /**
     * Retrieve how many products should be displayed on page
     *
     * @return int
     */
    protected function getPageSize()
    {
        return $this->showPager() ? $this->getProductsPerPage() : $this->getProductsCount();
    }

    /**
     * Render pagination HTML
     *
     * @return string
     * @throws LocalizedException
     */
    public function getPagerHtml()
    {
        if ($this->showPager() && $this->getProductCollection()->getSize() > $this->getProductsPerPage()) {
            if (!$this->pager) {
                $this->pager = $this->getLayout()->createBlock(
                    Pager::class,
                    $this->getWidgetPagerBlockName()
                );

                $this->pager->setUseContainer(true)
                    ->setShowAmounts(true)
                    ->setShowPerPage(false)
                    ->setPageVarName($this->getData('page_var_name'))
                    ->setLimit($this->getProductsPerPage())
                    ->setTotalLimit($this->getProductsCount())
                    ->setCollection($this->getProductCollection());
            }
            if ($this->pager instanceof \Magento\Framework\View\Element\AbstractBlock) {
                return $this->pager->toHtml();
            }
        }
        return '';
    }

    /**
     * Return identifiers for produced content
     *
     * @return array
     */
    public function getIdentities()
    {
        $identities = [];
        if ($this->getProductCollection()) {
            foreach ($this->getProductCollection() as $product) {
                if ($product instanceof IdentityInterface) {
                    $identities[] = $product->getIdentities();
                }
            }
        }
        $identities = array_merge([], ...$identities);

        return $identities ?: [Product::CACHE_TAG];
    }

    /**
     * Get value of widgets' title parameter
     *
     * @return mixed|string
     */
    public function getTitle()
    {
        return $this->getData('title');
    }

    public function getDayDate()
    {
        return $this->_localeDate->date()->format('Y-m-d H:i:s');
    }

    public function getStartOfDayDate()
    {
        return $this->_localeDate->date()->setTime(0, 0, 0)->format('Y-m-d H:i:s');
    }

    public function getEndOfDayDate()
    {
        return $this->_localeDate->date()->setTime(23, 59, 59)->format('Y-m-d H:i:s');
    }

    /**
     * Get currency of product
     *
     * @return PriceCurrencyInterface
     * @deprecated
     * @see Constructor injection
     */
    private function getPriceCurrency()
    {
        if ($this->priceCurrency === null) {
            $this->priceCurrency = ObjectManager::getInstance()
                ->get(PriceCurrencyInterface::class);
        }
        return $this->priceCurrency;
    }

    /**
     * @inheritdoc
     */
    public function getAddToCartUrl($product, $additional = [])
    {
        $requestingPageUrl = $this->getRequest()->getParam('requesting_page_url');

        if (!empty($requestingPageUrl)) {
            $additional['useUencPlaceholder'] = true;
            $url = parent::getAddToCartUrl($product, $additional);
            return str_replace('%25uenc%25', $this->urlEncoder->encode($requestingPageUrl), $url);
        }

        return parent::getAddToCartUrl($product, $additional);
    }

    /**
     * Get widget block name
     *
     * @return string
     */
    private function getWidgetPagerBlockName()
    {
        $pageName = $this->getData('page_var_name');
        $pagerBlockName = 'widget.porto.filter.products.pager';

        if (!$pageName) {
            return $pagerBlockName;
        }

        return $pagerBlockName . '.' . $pageName;
    }


    /**
     * Set template
     *
     * @return string
     */
    public function _toHtml()
    {
        $this->setTemplate(
            $this->getData('layout_type') ? 'Smartwave_Filterproducts::widget/'.$this->getData('layout_type').'.phtml' : 'Smartwave_Filterproducts::widget/grid.phtml'
        );

        $html = parent::_toHtml();
        return $html;
    }

    /**
     * @inheritdoc
     */
    protected function _afterToHtml($html)
    {
        return trim($html);
    }
}
