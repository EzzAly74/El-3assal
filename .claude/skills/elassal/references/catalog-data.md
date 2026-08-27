# ElAssal / SpareTrak — Catalog data model

The client supplied two spreadsheets. `catalog/build_catalog.py` maps one onto the other and emits
the JSON the storefront should be seeded from. **The spreadsheets are read-only — never edit them.**

## Source spreadsheets (`data/`)

### `SKUs.xlsx` — 3 sheets
- **المنتجات (Products): 8,908 SKUs**
  Columns: `رقم الصنف` (SKU#) · `اسم الصنف` (name) · `نوع الصنف` (item type) · `الماركة` (brand) · `الموديل` (model)
  Only **~2,858 (32%)** have a model; the rest are brand-only.
- **الماركات والموديلات** — brand ↔ model reference (includes بستم size & cylinder-count attributes).
- **انواع الاصناف** — item type → parent category map.

### `ITEM TREE.xlsx` — category hierarchy, 3 levels
**7 main categories (L1) → 53 subgroups (L2) → 360 part types (L3)**

| L1 | # L2 |
|---|---|
| **المحرك** (Engine) | 14 |
| **الفلاتر** (Filters) | 6 |
| **الكهرباء والحساسات** (Electrical & Sensors) | 8 |
| **الدبرياج والفتيس ونقل الحركة** (Clutch / Gearbox / Transmission) | 13 |
| **الهيدروليك وPTO والدركسيون** (Hydraulics / PTO / Steering) | 3 |
| **جسم الجرار والكابينة والإكسسوارات** (Body / Cab / Accessories) | 3 |
| **بلي وأويل سيلات وسيور ومثبتات** (Bearings / Seals / Belts / Fasteners) | 6 |

### The known data gap
The products sheet's `نوع الصنف` (**73** distinct item types) and the tree's **360** leaf categories
**do not line up.** Products are therefore *mapped* onto leaves, not joined. Mapping outcome:

| method | count | meaning |
|---|---|---|
| `exact-leaf` | 4,320 | item type matched a tree leaf outright |
| `name-inferred` | 3,211 | item type was blank → inferred from the product name |
| `alias` | 370 | matched via an alias table (17 types) |
| `node` | 75 | matched a non-leaf node |
| `uncategorized` | **932 (10.5%)** | **needs client review** — `matched_node: false`, `l1` is a guess |

**89.5% mapped.** The 932 uncategorized products are an open item for the client, not a bug to code around.

## Emitted artifacts (`catalog/`)

### `products.json` — 8,908 objects
```json
{
  "sku": 1,
  "name": "بستم دويتس 100م k.s",
  "brand": "دويتس",
  "model": "",
  "item_type": "بستم",
  "l1": "المحرك",
  "l2": "بستم وشنبر وشميز",
  "l3": "بستم",
  "matched_node": "بستم",
  "match_method": "exact-leaf",
  "search": "100م deutz k.s بستم دوتز دويتس"
}
```
`search` is the pre-normalized, synonym-expanded token bag — index on this, not on `name`.

### `categories.json` — 418 nodes (flat, parent-linked)
```json
{
  "id": 1, "name_ar": "المحرك", "slug": "المحرك", "level": 1,
  "parent_id": null, "is_leaf": false,
  "product_count": 0, "product_count_total": 5355
}
```
`product_count` = direct hits · `product_count_total` = including descendants. Use the total for
mega-menu prioritization and for hiding empty branches.

### `navbar.json`
Ready-made navigation: `primary` (the dual-path mega menu), `support` (WhatsApp / hotline / branches),
`funnel` (the commerce path).

### `search-index.json`
Keys: `normalization` (the rules), `match_rule` (order-independent token matching),
`brand_synonyms` (Arabic ↔ Latin brand aliases), `products` (the index itself).

### `mapping-report.md`
Per-type audit of how every SKU was mapped and what didn't map. Read this before questioning a category.

## Regenerating

```bash
pip3 install openpyxl
python3 catalog/build_catalog.py     # reads data/*.xlsx, rewrites catalog/*.json + mapping-report.md
```

Idempotent, and it never writes to the spreadsheets.

## Top brands in the SKU data (30 distinct)
فيات (1,608) · بركنز (1,558) · جوندير (879) · فورد (442) · دويتس (391) · روسي (382) · نصر (354) ·
كمنز (211) · روماني (182) · زيتور (141) · ماجروس (108)
Plus combo brands — فيات/روماني, نصر/بركنز, نيوهولند/فيات, دويتس/ماجروس … **treat these as single
brand values, do not split them**, and expose them on both parent brands in filters.

## Top item types (73 distinct)
سبيكة محرك (833) · بستم (405) · شنبر (353) · طقم جوان (348) · جوان وش سلندر (255) · طلمبة مياة (244) ·
كارجة جاز (195) · طلمبة هيدروليك (173) · طلمبة زيت محرك (170) · شميز (158) · صباب محرك (149)
