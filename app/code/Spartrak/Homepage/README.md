# Spartrak_Homepage

Owns the storefront homepage end to end: its sections, their order, their
headings, their banner artwork, and the categories and products they read from.

## The one architectural rule

**No CMS blocks. No CMS content. Anywhere on this page.**

`cms_index_index` is Magento's homepage handle, but nothing on the page comes
from `Magento_Cms`: not a block, not a page, not a widget. This module's layout
file mounts exactly one block — `Block\Sections` — and every section under it
is assembled from this module's own tables. There is no code path from the
homepage to CMS content.

The CMS-block homepage that preceded this (the theme's own
`Magento_Cms/layout/cms_index_index.xml` and `templates/homepage/sections.phtml`)
was deleted in the same change, along with its `ObjectManager`-in-a-template
catalogue lookups.

## Data model

Three tables, all dashboard-owned.

| Table | Holds |
|---|---|
| `spartrak_homepage_section` | the section registry — what renders, in what order, under what heading |
| `spartrak_homepage_banner` | banner items belonging to a banner section |
| `spartrak_homepage_category_item` | category picks belonging to a tile section |

A section's `type` is the only thing that chooses a renderer. Everything else —
heading, order, enabled state, source category, the children hanging off it — is
shared column data. **That is why a second banner section or a fourth product
carousel is a dashboard row, not a deploy.**

Per-locale text and artwork are explicit `_en` / `_ar` columns. The trade-off
(a third language needs a schema change) is recorded in `etc/db_schema.xml`,
along with the migration path — the frontend only ever asks
`Model\LocaleContext` which locale it is in, so that stays a one-class change.

## Sections, and where each one's data comes from

| Figma | Heading | Type | Content source |
|---|---|---|---|
| `595:14562` | *(none — the artwork carries it)* | `banner` | banner rows for this section |
| `595:15067` | الفئات الأكثر بحثا | `category_tiles` | picked categories + static theme artwork |
| `595:15115` | الأكثر مبيعا | `product_carousel` | live products from the chosen category |
| `595:14586` | عروض مميزه | `product_carousel` | live products from the chosen category |
| `595:14821` | شاهد المنتج، وأحكم بنفسك | `product_video_carousel` | live products + their Magento gallery video |

A section that resolves to nothing renders **nothing** — no heading, no empty
rail. So a freshly installed store shows a clean page, and each section appears
the moment an admin gives it content.

## Admin

**Content → Homepage → Homepage Sections** (`Spartrak_Homepage::section`)
**Content → Homepage → Homepage Banners** (`Spartrak_Homepage::banner`)

Two ACL resources rather than one: banner artwork is day-to-day marketing work,
section structure is closer to site configuration, and a merchandiser can be
given the first without the second.

Banners get their own grid rather than living as rows inside the section form —
four image uploaders per row does not scale to a year of campaigns.

### The single-image versus carousel rule

Enforced in `Block\Section\Banner::isCarousel()` and in the template, not by
convention:

- **one** enabled banner → a plain static image. No arrows, no dots, **and the
  carousel JS is never requested** — the `data-mage-init` attribute that pulls
  it in is only emitted in the multi-item branch.
- **two or more** → a carousel, in dashboard order.

Banners whose artwork is missing are dropped *before* the count is taken, so one
good banner plus one empty row still renders as a static banner.

## Performance notes

- **Fixed query budget.** `Model\SectionList` loads every section and all of
  their children in at most **three** queries regardless of how many sections
  exist — one for sections, one `IN()` for all banners, one `IN()` for all
  category picks — and skips 2 and 3 when no section of that type is enabled.
  No N+1, and the count does not grow when an admin adds a row.
- **Category products.** `Model\Product\CategoryProductProvider` selects only
  the attributes the card paints, filters on the indexed
  `catalog_category_product` table, applies `setPageSize` before the collection
  is walked, and never calls `getSize()` (nothing shows a total). It respects
  store, status, visibility and the store's own out-of-stock setting.
- **Media gallery** is loaded for the video section only, through Magento's
  batched `addMediaGalleryData()` — one query for the whole rail.
- **One image per banner per visitor.** Language is resolved server-side;
  viewport is resolved by `<picture>`/`<source media>`, which the browser
  evaluates *before* the preload scanner fetches anything. A phone never
  downloads the desktop file.
- **LCP.** Only the first section on the page may mark an image
  `fetchpriority="high"`; everything else is lazy. `Block\Section\ProductCarousel::getCardIndex()`
  forces a below-the-fold index for every rail that is not first, so the shared
  card template's own eager-loading rule cannot fire in the wrong place.
- **CLS.** Banner `width`/`height` come from the real file header
  (`Model\Image\Storage::getDimensions`, one header read per cache generation),
  with a CSS `aspect-ratio` fallback for formats that have none.
- **Rails are native scroll containers.** `overflow-x: auto` +
  `scroll-snap-type`. They are swipeable, keyboard-scrollable and correct in RTL
  *before any JS loads*; the widget only teaches the arrows to call `scrollBy()`.
- **The video is a facade.** No third-party iframe is requested until a shopper
  presses play.

## Frontend files (theme)

- `web/css/source/components/_homepage-sections.less`
- `web/js/spartrak-home-carousel.js` — arrows, dots, progress
- `web/js/spartrak-home-tiles.js` — the category reveal
- `web/js/spartrak-home-video.js` — the video facade
- `web/images/homepage/` — the tile section's shared backdrop and glow

Product cards render through the **shared** `Spartrak_Catalog::product/card.phtml`
— the same component as the PLP and search grid. The showcase uses its
`horizontal` variant (Figma `746:37718`), which is a variant of that component
in the design file, not a second card.

## ⚠ Outstanding

**BLOCKED / REQUIRES FIGMA ASSET** — the per-category artwork for
الفئات الأكثر بحثا. Figma supplies two tile photos and one reveal visual for a
single composed example; every further category needs its own pair, and the
three shipped files still need renaming to the real categories they depict. The
mechanism is complete and needs no code change — see
`view/frontend/web/images/categories/README.md` for the naming contract.
