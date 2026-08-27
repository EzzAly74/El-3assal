# Spartrak_Search

Supplies the storefront **search-suggestions panel** — the dropdown that opens
under the header search box while a shopper is typing.

## Why the module exists

Figma (desktop node `864:8879`, mobile node `647:53929`) specifies a panel with
three parts at once:

| Part | Content |
|---|---|
| Head | total result count + a "view all" link to the results page |
| Rail | a horizontal row of matching **product cards** (image, name, price) |
| Terms | a list of suggested **search terms** |

Magento's own `search/ajax/suggest` endpoint returns only the third of those —
`AutocompleteInterface` items, which carry a `title` and a `num_results` and no
product data at all. The panel cannot be built on it without fabricating the
products, so this module adds one endpoint that answers all three parts from
real catalogue data.

## What it does not do

It does not reimplement search. Terms still come from core's
`AutocompleteInterface`, and products come from
`Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection` — the same
`@api` collection that backs the search results page, so the count shown in the
panel is the count the shopper lands on when they follow "view all".

### Two traps in wiring that collection

Both were hit on the first `setup:di:compile` of this module, and both are
worth knowing before anyone touches `SuggestionProvider`'s constructor:

1. **`Fulltext\CollectionFactory` is not a class.** It is a `virtualType`
   (`module-catalog-search/etc/di.xml`, line 98) over
   `Catalog\Model\ResourceModel\Product\CollectionFactory`. A virtual type has
   no file for reflection to read, so using one as a constructor type hint
   aborts compilation with *"Class … does not exist"*. The constructor
   therefore hints the real base factory and `etc/di.xml` supplies the virtual
   type — the same pattern core uses for
   `Layer\Category\ItemCollectionProvider`.

2. **The plain factory searches the wrong index request.** `Collection`'s
   constructor defaults `$searchRequestName` to `catalog_view_container` — the
   *category browse* request — and `_renderFiltersBefore()` branches on that
   name. A keyword search needs `quick_search_container`.

3. **And the generic quick-search factory under-counts.**
   `Fulltext\SearchCollectionFactory` fixes the request name but keeps the
   default `TotalRecordsResolver`, which returns `null` by design ("For Mysql
   search engine we can't resolve total record count before full load").
   `AbstractDb::getSize()` then falls through to a `COUNT` over a select that
   `SearchResultApplier` has already narrowed to the current page's ids — so it
   reports the **page size** as the total. Observed live: a term with 909
   results rendered *"6 products / View all (6)"*, 6 being the configured rail
   size.

   The engine modules replace that resolver with one returning
   `$searchResult->getTotalCount()`, and `getSize()` keeps it — line 437 is
   `$this->_totalRecords = $this->_totalRecords ?? fetchOne($sql)`, and the
   `getSelectCountSql()` call above it is what runs `_renderFiltersBefore()`
   and populates `_totalRecords` in the first place.

   So the injected factory is `elasticsearchFulltextSearchCollectionFactory`.
   Despite the name that is not "the Elasticsearch one" but *the quick-search
   one*: `Magento_Elasticsearch8` and `Magento_OpenSearch` both point their own
   engine key at this same shared virtual type, and MySQL search was removed in
   2.4 — so on 2.4.8 it is the collection quick search uses under every
   supported engine, and the one behind `/catalogsearch/result`.

   **If the panel's count ever equals the configured rail size again, this
   wiring is the first thing to check.**

## Endpoint

    GET /search-suggest/ajax/suggest?q=<term>

Returns a rendered HTML fragment, or an **empty body** when there is nothing to
suggest (below the store's minimum query length, no matches, or the feature
switched off). HTML rather than JSON so the markup, its escaping and its theme
override all stay server-side and the browser has no templating to do.

Read-only and GET-only, so there is no CSRF surface.

## Caching

The rendered fragment is cached under a key covering store, currency, customer
group, both limits and the lower-cased query, tagged with
`Magento\Catalog\Model\Product::CACHE_TAG`. Customer group is in the key because
prices are group-specific — serving one group's prices to another would be a
correctness bug, not a performance win. A product save clears the panel
regardless of the configured lifetime.

## Admin

**Stores → Configuration → Catalog → Search Suggestions**
(ACL resource `Spartrak_Search::config`)

| Field | Default | Purpose |
|---|---|---|
| Enable Suggestions Panel | Yes | Off ⇒ empty response; the search box falls back to plain form submission |
| Products In Rail | 6 | Figma's own visible card count; 0 hides the rail |
| Search Terms Listed | 5 | 0 hides the term list |
| Response Cache Lifetime | 300 | Seconds; 0 disables the response cache |

## Theme side

Appearance and behaviour live in the theme, not here:

- `Spartrak/spartrak/web/css/source/components/_search-suggest.less`
- `Spartrak/spartrak/web/js/spartrak-search-suggest.js`
- `Spartrak/spartrak/Magento_Search/templates/form.mini.phtml` (wires the widget)

`view/frontend/templates/suggest/panel.phtml` ships here so the module is
self-contained, and is overridable from either theme in the usual way.
