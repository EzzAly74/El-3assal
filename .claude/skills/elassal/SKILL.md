---
name: elassal
description: Context loader for the ElAssal / SpareTrak B2C e-commerce build — an Arabic-first (RTL), bilingual EN/AR storefront for diesel-engine, tractor and generator spare parts. Loads the product brief, the canonical design tokens, the IA and commerce funnel, the 8,908-SKU catalog data model, and the Figma source-of-truth map. Use for any ElAssal/SpareTrak implementation work — frontend, storefront, catalog, search, or design-system integration.
---

# ElAssal / SpareTrak storefront — developer context

**ElAssal for Trading & Supply** (العسال للتجارة والتوريدات, est. 1973) is an Egyptian importer/distributor of **diesel engine, tractor and generator spare parts**.
This project is its **B2C storefront** — not a corporate site — for **non-technical buyers** (farmers, mechanics, small workshops). **Arabic-first, RTL throughout, bilingual EN/AR.**

## Two brands, two roles

| | Role |
|---|---|
| **ElAssal for Trading & Supply** (العسال للتجارة والتوريدات) | The **company / legal entity** — invoices, corporate identity, `elassalparts.com` |
| **SpareTrak.com** | The **consumer-facing storefront brand** — logo, domain, and every string a shopper reads |

**Rule: SpareTrak in anything a customer sees; ElAssal for the company.**

## On invocation — do this in order

1. Read `references/design-tokens.md` — the canonical colour/type/radius tokens. **Never hardcode a colour that is not in that file.**
2. Read `references/ia-and-flows.md` — the required homepage module order, dual-path discovery, and the commerce funnel. These are non-negotiable.
3. Read `references/catalog-data.md` — the data model and the shape of `catalog/*.json`, which is the seed data for the storefront.
4. Read `references/figma-map.md` when implementing a screen — the Figma file is the visual source of truth and it carries node IDs per screen.
5. Print a short status summary (what's designed, what's built, what's next) and **wait for instruction.**

Read `references/BUSINESS-full-history.md` only when you need the *why* behind a decision — it is the designer's full working log (long, and includes internal Jira/process notes).

## Non-negotiables — never violate these

- **B2C storefront, not a corporate sitemap.** Funnel is exactly `PLP → PDP → Cart → Checkout → Order Success`.
- **Dual-path discovery.** Every shopper hits the fork "know the part, or know your machine?" The validated path is **System → Brand → Levels** (e.g. المحرك → فيات → L2/L3).
- **Search must match brand + part-type (+ model/size) in one query, order-independent.** `"بستم فيات"` and `"فيات بستم 100"` must both work. Tokenize the query and match tokens across name / item-type / brand / model. Spec: `catalog/SEARCH-SPEC.md`.
- **The mega menu must be meaningfully grouped, never a flat dump.** The client explicitly rejected a "مرصوصة" stacked list. Show L1 → grouped L2 headers → curated top L3 + "view all". Never render all 360 leaves. Content: `catalog/MEGA-MENU-CONTENT.md`.
- **RTL is the default, not an afterthought.** Arabic is the primary locale; English is the mirror. RTL is *not* just reversing children — flex/grid alignment flips too.
- **Numerals render in the numeral token, Latin-digit style** — Arabic-Indic digits were explicitly rejected.
- **Support CTAs stay visible everywhere**: WhatsApp, Hotline, Branches.
- **Never modify the client's `data/SKUs.xlsx`.** Regenerate artifacts with `catalog/build_catalog.py` instead.

## What's in this bundle

| Path | What it is |
|---|---|
| `references/design-tokens.md` | Canonical colours, fonts, type scale, radii |
| `references/ia-and-flows.md` | IA non-negotiables, homepage modules, funnel, scope |
| `references/catalog-data.md` | Catalog data model + JSON schemas + coverage |
| `references/figma-map.md` | Figma file/page/node map — the visual source of truth |
| `references/elassal-ecommerce-ia-rules.md` | Original one-page IA rules |
| `references/SITEMAP.md` | Full sitemap |
| `references/BUSINESS-full-history.md` | Designer's complete working log (deep background) |
| `catalog/products.json` | 8,908 products, mapped onto the category tree |
| `catalog/categories.json` | 418 category nodes (7 L1 → 53 L2 → 360 L3) |
| `catalog/navbar.json` | Ready-made navigation structure |
| `catalog/search-index.json` | Search index + normalization + brand synonyms |
| `catalog/SEARCH-SPEC.md` | Search behaviour spec |
| `catalog/MEGA-MENU-CONTENT.md` | Curated mega-menu content |
| `catalog/mapping-report.md` | How every SKU was mapped, and what didn't map |
| `catalog/build_catalog.py` | Re-runnable builder (`python3 catalog/build_catalog.py`, needs `openpyxl`) |
| `catalog/index.html`, `nav.html`, `nav-v2.html` | Static prototypes — `nav-v2.html` is the recommended mega-menu behaviour |
| `data/SKUs.xlsx` | Client's raw 8,908-SKU export — **read-only** |
| `data/ITEM TREE.xlsx` | Client's category hierarchy — **read-only** |
| `assets/` | SpareTrak/ElAssal logo + brand strip |

## Running the prototypes

```bash
cd "$(dirname "$0")/catalog" && python3 -m http.server 8765
# then open http://localhost:8765/nav-v2.html#open
```

## Traps worth knowing before you start

| Trap | Rule |
|---|---|
| Treating RTL as `direction: rtl` alone | Auto-layout/flex **alignment** flips too — a row that was `flex-start` in LTR is not the same node mirrored |
| Assuming `نوع الصنف` (73 item types) == the tree's 360 leaves | They do **not** line up. Products are *mapped*; 932 are still uncategorized |
| Arabic string comparison | Normalize first (strip tashkeel/tatweel, unify أإآ→ا, ة→ه, ى→ي, Arabic-Indic → Western digits). `catalog/build_catalog.py:norm()` is the reference implementation |
| Colours picked off a screenshot | Use `references/design-tokens.md`. Retired values `#FFC107` / `#1F3A93` / `#0A0E14` / Tajawal still appear in stale artwork |
| `#d9d9d9`, `#4a32e3`, `#f1effe` | Not brand colours — UI-kit / placeholder residue. Do not implement them |
