# ElAssal / SpareTrak — IA, flows and scope

## Who is buying
Farmers, mechanics and small workshops in Egypt. **Non-technical buyers.** Many arrive knowing
their *machine* (a Fiat tractor) rather than the *part number*. The whole IA exists to serve both.

## IA non-negotiables
- **B2C storefront homepage-module architecture** — not a corporate sitemap tree.
- **Dual-path discovery:** the machine/brand path **and** the part-type path, both first-class.
  Validated flow: **System → Brand → Levels** (e.g. المحرك → فيات → L2 → L3).
- Support actions visible **everywhere**: WhatsApp, Hotline, Branches.
- Matches Egyptian retail e-commerce expectations (search-first, dense, price-forward).
- Arabic-first, RTL throughout, bilingual EN/AR.

## Homepage module architecture — required order
1. Top Promo Strip
2. Utility Header (cart, branches, hotline, WhatsApp, account, language)
3. Main Search Row (**search-first**)
4. Primary Mega Navigation
5. Hero Carousel (campaigns)
6. Trust / Service Strip (shipping, pickup, support, warranty)
7. Shop by Machine / Brand
8. Shop by Part Type
9. Featured Products / Best Sellers
10. Brand Rail
11. Footer Links

## Commerce funnel
`PLP → PDP → Cart → Checkout → Order Success`

## Mega menu rules
The client explicitly rejected a **"مرصوصة"** menu — a long stacked list of items dumped one after
another with no logical grouping. Therefore:

- Group **meaningfully** and prioritize by relevance / product count.
- Render **L1 → grouped L2 headers → curated top L3 + "view all"**.
- **Never** render all 360 leaf categories.
- Curated content is in `catalog/MEGA-MENU-CONTENT.md`; structure in `catalog/navbar.json`.
- Reference behaviour: `catalog/nav-v2.html` (tri-pane). Open with `#open` to auto-expand.

## Search rules
The client's own words: *"I need to search by typing the brand name with the product together."*

- Match **brand + part-type (+ model / size) in one query, order-independent**.
  `"بستم فيات"` and `"فيات بستم 100"` must return the same thing.
- Tokenize the query; match tokens across **name / item_type / brand / model**.
- **Normalize Arabic before comparing** — strip tashkeel and tatweel, unify أ/إ/آ→ا, ة→ه, ى→ي,
  ؤ→و, ئ→ي, convert Arabic-Indic and Persian digits to Western, lowercase, collapse whitespace.
  Reference implementation: `norm()` in `catalog/build_catalog.py`.
- Brand synonyms matter (فيات ↔ fiat / fiatagri, بركنز ↔ perkins …) — the map ships in
  `catalog/search-index.json` under `brand_synonyms`.
- Full spec: `catalog/SEARCH-SPEC.md`.

## Scope

**In scope and designed:**
- Homepage (desktop 1440 + mobile 440)
- PLP + filters, PDP
- Cart, Checkout, Order Success
- Phone + OTP login / signup
- Account: profile, order history, order details with delivery tracking, saved payment method / InstaPay

**Out of scope:** 404 and skeleton screens.

## Brands portfolio (marketing-facing)
New Holland · Perkins · Massey Ferguson · JCB · John Deere · Ford Tractor · Cummins · Fiatagri ·
Iveco · UTB · Lamborghini · Deutz
