# ElAssal e-commerce — BUSINESS.md

> **Single source of truth for the ElAssal B2C e-commerce project.**
> Mention/open this file at the start of every session to restore full context.
> Location: `/Users/krim/Downloads/Elassal e-commerce/BUSINESS.md`
> Last updated: 2026-08-18

---

## 1. Who is Al Assal?

- **Full name:** ElAssal for Trading & Supply — Mohsen ElAssal & Co. (العسال للتجارة والتوريدات)
- **Established:** 1973 (50+ years in business)
- **Core business:** Importing & distributing **diesel engine, tractor, and generator spare parts**
- **Sister company / logistics hub:** Diesel House FZC — Sharjah Publishing City Free Zone, UAE
- **Sub-brand:** Diesel House (Agricultural Machinery Spare Parts)
- **✅ TWO BRANDS, TWO ROLES — resolved 2026-08-18 (karim):**
  - **ElAssal for Trading & Supply** (العسال للتجارة والتوريدات) = the **company / legal entity**, est. 1973. Corporate identity, invoices, Jira stream naming, `elassalparts.com`, `omar@elassalparts.com`.
  - **SpareTrak.com** = the **consumer-facing storefront brand** — logo, domain, and all UI copy in the product. This is what the shopper sees.
  Both are correct; they are not in conflict. **Use SpareTrak in anything a customer reads; use ElAssal for the company.** The earlier "branding discrepancy" flags across this file are superseded by this line.
- **Email:** omar@elassalparts.com
- **WhatsApp / Hotline:** +20 122 314 9614

### Locations
- **Shop:** 25 Orabi St, Azbakya, Downtown, Cairo
- **Admin:** 27 Orabi St, Azbakya, Downtown, Cairo
- **Main warehouse:** 111 Adel Waly St., Abu Rawash, Km 26 Cairo–Alex Desert Road
- **UAE:** Business Centre, Sharjah Publishing City Free Zone

### Sectors served
- Agricultural machinery (tractors, harvesters)
- Industrial generators and diesel engines
- Heavy equipment and construction machines

### Markets
- Egypt (nationwide distribution)
- UAE (logistics & export)
- Africa & Middle East (via Diesel House FZC)

---

## 2. The Project

A **strictly B2C e-commerce storefront** (NOT a corporate website) selling spare parts.

- **Target users:** Farmers, mechanics, small workshops — **non-technical buyers**.
- **Languages:** Arabic-first, **RTL throughout**, bilingual EN/AR.
- **UX priority:** Smooth, simple shopping for non-technical users.

### IA non-negotiables
- B2C storefront homepage-module architecture — **not** a corporate sitemap tree.
- **Dual-path discovery:** machine/brand path **+** part-type path.
- Keep support actions visible everywhere: **WhatsApp, Hotline, Branches**.
- Aligned with Egyptian retail e-commerce expectations.

### Homepage module architecture (required order)
1. Top Promo Strip
2. Utility Header (cart, branches, hotline, WhatsApp, account, language)
3. Main Search Row (search-first)
4. Primary Mega Navigation
5. Hero Carousel (campaigns)
6. Trust/Service Strip (shipping, pickup, support, warranty)
7. Shop by Machine/Brand
8. Shop by Part Type
9. Featured Products / Best Sellers
10. Brand Rail
11. Footer Links

### Commerce funnel
`PLP → PDP → Cart → Checkout → Order Success`

> ⚠️ **SCOPE CHANGED 2026-08-12:** the old note here said *"NOT building account/orders or 404/skeleton screens in current scope."* **Account and orders ARE now designed and built** (Account section in Figma: profile, order history, order details with delivery tracking, saved payment method / InstaPay) — ticketed as `NEW2B-5229`. Phone+OTP **login/signup** is also built, ticketed as `NEW2B-5228`. Only **404 / skeleton screens** remain out of scope.

---

## 3. Design System (source of truth)

Final designs live in **Figma file "Elassal e-commerce"** (fileKey `6FRlQfPIncVUvNiJLn2kbT`), page **"Design - Hi Fed"**. Covers **Homepage + PDP** only, each desktop (1440) + mobile (500).

> ✅ **TOKEN DRIFT RESOLVED 2026-08-17.** The old documented tokens (`#FFC107`, `#1F3A93`, Tajawal) are **dead** — they appeared in ~20 nodes while the real values appeared in thousands. karim decided the **live build values are canonical**, and they are now **Figma variables** (see §11). Everything below reflects the live build.

### Color tokens — LIVE (canonical)

**`#063196` navy is the PRIMARY brand colour** (karim, 2026-08-17). Yellow `#eebd1d` is the **accent / commerce CTA** (add-to-cart), not the primary.

| Role | Hex | Notes |
|---|---|---|
| **Primary (navy)** | `#063196` | 1,029 uses — search button, primary actions |
| Primary hover | `#044776` | 204 uses, on the `Primary Buttons` instances |
| **Accent (yellow)** | `#eebd1d` | 1,560 uses — add-to-cart, ratings |
| Accent hover | `#E6A800` | |
| Soft-yellow callout | `#FFF6D9` | |
| Star (empty/half) | `#f8e5a5` | 320 vector uses |
| Ink / primary text | `#0c0a20` | 3,148 uses |
| Secondary text | `#555463` | 1,684 uses — 6.97:1 on page tint (AA) |
| Muted text | `#6d6c79` | 4.86:1 on page tint (AA, only just) |
| Disabled text | `#9e9da6` | 2.53:1 — **disabled only, never live text** |
| Border (default) | `#e4e3e5` | 1,697 strokes — the single most-used border |
| Border (strong) | `#ceced2` | |
| Field bg | `#f3f3f4` | |
| Surface | `#ffffff` · subtle `#f9f9f9` | |
| Page tint | `#f7f8fa` | |
| Success green | `#049228` | (NOT `#16A34A`) |
| Sale / danger red | `#d54033` | (NOT `#DC2626`) |
| Icon ink | `#1a144f` | 1,443 icon vectors |
| Brand chip — Cat | `#FFCD11` | |
| Brand chip — John Deere | `#367C2B` | |
| Brand chip — Ford | `#005CA9` | |

**NOT tokens — noise found during the 2026-08-17 audit, do not reuse:**
`#d9d9d9` (1,552× — icon "Bounding box" rects) · `#4a32e3` + `#f1effe` (UI-kit `Label › #Oldmoneys` residue) · `#fce5e3` (illustration-only) · `#ff5f00`/`#eb001b`/`#1434cb` (Visa/Mastercard logo artwork).
⚠️ The stray purple `#4a32e3` **leaked into real UI** — "View All" links and "Estimated Total Value". **Cleanup item:** re-point those to `text/link`.

### Typography — LIVE (canonical)
- **`thmanyah sans`** (Light / Regular / Medium / Bold / Black) — Arabic UI, the dominant font (6,351 uses).
  ✅ **It IS loadable by the plugin API** (confirmed 2026-08-17) — the older note that it couldn't be loaded, and the resulting Tajawal substitutions, are **obsolete**. Existing Tajawal text (287 nodes) should be migrated.
- **Inter** — Latin text · **Inter Tight** — numerics/prices · **JetBrains Mono** — SKUs / part numbers.
- **Type scale (normalized):** 10 / 12 / 14 / 16 / 18 / 20 / 24 / 28 / 32 / 40.
  Real usage clusters on 16 (2,451), 14 (1,809), 12 (833), 18 (748), 10 (724), 20 (614). Strays to fold in: 13, 15, 17, 10.5, 13.78.

### Radii
2 / 4 / 6 / 10 / 16 / 20 + pill

> In Claude Design, set design-system dropdown to **None** (not "Buddyget Design System") so ElAssal styling isn't overridden.

---

## 4. Catalog Data (discovered)

### Brands portfolio (marketing)
New Holland, Perkins, Massey Ferguson, JCB, John Deere, Ford Tractor, Cummins, Fiatagri, Iveco, UTB, Lamborghini, Deutz.

### `SKUs.xlsx` — 3 sheets
- **المنتجات (Products): 8,908 SKUs**
  Columns: `رقم الصنف` (SKU#) · `اسم الصنف` (name) · `نوع الصنف` (item type) · `الماركة` (brand) · `الموديل` (model)
  Only **~2,858 (32%)** have a model filled; rest are brand-only.
- **الماركات والموديلات** — brand ↔ model reference (incl. بستم size & cylinder-count attributes).
- **انواع الاصناف** — item type → parent category map.

### Top brands in SKU data (30 distinct)
فيات (1,608) · بركنز (1,558) · جوندير (879) · فورد (442) · دويتس (391) · روسي (382) · نصر (354) · كمنز (211) · روماني (182) · زيتور (141) · ماجروس (108)
Plus combo brands: فيات/روماني, نصر/بركنز, نيوهولند/فيات, دويتس/ماجروس…

### Top item types (73 distinct `نوع الصنف`)
سبيكة محرك (833) · بستم (405) · شنبر (353) · طقم جوان (348) · جوان وش سلندر (255) · طلمبة مياة (244) · كارجة جاز (195) · طلمبة هيدروليك (173) · طلمبة زيت محرك (170) · شميز (158) · صباب محرك (149)…

### `ITEM TREE.xlsx` — category hierarchy (3 levels)
**7 Main Categories (L1) → 53 subgroups (L2) → 360 part types (L3)**

| Main category (L1) | # L2 | L2 groups |
|---|---|---|
| **المحرك** (Engine) | 14 | بستم وشنبر وشميز · كرنك وبيل وسبايك · وش سلندر وصبابات · كامة وتقسيمة · جوانات واويل سيلات · دورة زيت · تربو · حوامل وقواعد · دورة الوقود والحقن · رشاشات وفونيات · مواسير وخراطيم جاز · تانك الجاز · دورة التبريد والمياة · شكمان وهواء |
| **الفلاتر** (Filters) | 6 | فلتر زيت · فلتر جاز · فلتر هواء · فلتر هيدروليك · قواعد وكبايات · حشو فلتر |
| **الكهرباء والحساسات** (Electrical & Sensors) | 8 | مارش · دينامو · حساسات · مفاتيح وكونتاك · سولونيد · عدادات وتابلوه · أسلاك وفيش · إضاءة وكلاكس |
| **الدبرياج والفتيس ونقل الحركة** (Clutch/Gearbox/Transmission) | 13 | أجزاء دبرياج · فتيس · تروس فتيس · اكسات وكردان · كرونة ودفرنس · PTO · دريكسيون · شيالة ورفع · فرامل · ماستر وسيرفو · فرامل يد · عجل وصرة · مسامير عجل |
| **الهيدروليك وPTO والدركسيون** | 3 | طلمبات هيدروليك وباور · بلوف ومنظمات · مواسير وخراطيم |
| **جسم الجرار والكابينة والإكسسوارات** | 3 | كبوت وشبكة ورفرف · كابينة وأبواب · علامات واستيكرات |
| **بلي وأويل سيلات وسيور ومثبتات** | 6 | بلي · أويل سيلات · كاوتش وجلد وأورينج · مسامير وصواميل · ورد وطبات وقفيز · بنز وسنارات |

### ⚠️ Known data gap
The product sheet's `نوع الصنف` (73 item types) and the ITEM TREE's 360 L3 leaf categories **do not fully line up**. Products must be **mapped onto the tree's leaf categories** during catalog build. This is an open task.

---

## 5. Source Files (in this folder)

| File | What it is |
|---|---|
| `BUSINESS.md` | **This file** — single source of truth |
| `elassal-ecommerce-ia-rules.md` | IA rules (B2C non-negotiables) |
| `SKUs.xlsx` | 8,908 products + brands/models + item-type map |
| `ITEM TREE.xlsx` | 7→53→360 category hierarchy |
| `Import Template Items Template.xlsx` | Import template for items |
| `El Assal Company.pdf` | Company profile |
| `EN&AR LOGO.pdf`, `AR LOGO.pdf`, `elassal logo.png` | Logos |
| `image.png` (Ford), `image2.png` (Perkins), `image3.png` (Diesel House/Fiat) | Product photos — water pumps |
| `brands.webp` | Brand logos strip |
| Various `*.pdf` / WhatsApp `*.jpeg` | Catalog scans & product photos |

### Other build artifacts (outside this folder)
At `/Users/krim/Documents/elassal/`:
- `hifi-homepage.html`, `hifi-pdp.html` — standalone hi-fi prototypes
- `claude-design-master-prompt.md` — master prompt for full storefront generation
- `2026-04-20-elassal-ia-design.md` — IA design notes
- `assets/` — logo + assets

---

## 6. Client Feedback (from meetings)

### 2026-06-17 meeting
1. **Search — combined brand + product:** "I need to search by typing the brand name with the product together." → Search must match **brand + part-type (+ model/size) in one query, order-independent** (e.g. "بستم فيات", "فيات بستم 100" both work). Tokenize query and match tokens across name / item-type / brand / model.
2. **Navbar must NOT be "مرصوصة":** Client dislikes the menu being a long stacked list of items dumped one after another **without meaning / logical grouping**. → Mega menu must be **meaningfully grouped and prioritized** (by relevance/product count), not all 360 leaves crammed. Show L1 → grouped L2 headers → curated top L3 + "view all", not an overwhelming wall.

---

## 7. Catalog Mapping — Decisions (2026-06-17)
- Empty `نوع الصنف` (4,143 products): **infer category from product name** (longest-prefix match vs known type names); unmatched → Uncategorized for client review.
- **Do NOT modify the client's `SKUs.xlsx`** — produce clean new artifacts (`catalog/*.json`, reports).
- Navbar: **dual-path mega menu** (Shop by Part / Shop by Brand / Shop by Machine) — grouped & meaningful per feedback above.
- Mapping overlap measured: 4,320 exact-leaf · 75 node · 370 alias (17 types) · 4,143 empty(name-infer).

---

## 8. Session Log / Open Tasks

---

## ⭐ RESUME HERE — state as of 2026-08-18 (end of day)

**Read this block first. Everything below it is the dated history.**

### Where the work stands

| Layer | State |
|---|---|
| **Tokens** | `1. Primitives` **71** · `2. Semantic` **71** · `3. Typography` **30** · **38 text styles** |
| **Component library** (page "Design System") | **57 component sets · 1,308 variants**, of which **37 sets are bilingual** (`Direction=LTR\|RTL`). 100% colour-bound, typography fully styled |
| **Desktop Production (1440)** | 50 live frames · **100% colour · 0 raw** |
| **Mobile Production (440)** | 43 frames · **100% colour · 0 raw** |
| **Homepage `595:14462`** | 🏁 **CONNECTED** — 114 instances, **18 live `Card - Product`**, 100% colour, typography 228/230, height 1440×5543 unchanged |
| **Rest of Production** | ⬜ **NOT connected** — still detached frames. Only the homepage is mapped |

**Propagation is proven.** Editing `Card - Product` in the Design System updates the homepage with no edit to the page (verified on the plus icon and the badge row).

### ▶️ NEXT SESSION — do these, in this order

1. **Wire `Product Image` (`747:39914`) into `Card - Product`.** The set exists (12 variants `img=1…12`, 260×260) but the card's Image Container is still a rectangle with an image fill. Converting it to an **instance-swap slot** makes a card's photo a variant pick instead of an image-hash edit. Structural change to a component with 18 live instances — do it as its own step and verify the homepage after.
2. **Map the next Production page to the library**, using the proven method (below). Suggested order: **PDP `526:21854`** → Cart `538:6446` → Checkout → Account. The homepage took one pass; each page needs its own content-transfer map.
3. **Human read-through of the Arabic copy** in the library. It is consistent and fits, but strings like `خيار` / `اسم القطعة` are generic where real labels would read better.
4. **Optional cleanup:** delete the 5 stale `691:*` homepage duplicates (they still carry retired `#FFC107`/`#0A0E14`) — but only once nothing needs them as an opacity reference; and the canvas debris (3 orphan TEXT nodes, 2 empty `Fork Row` frames, the old `fashionable-women-s-handbag…` grid now superseded by `Product Image`).

### 🔑 The retrofit method (use this for every remaining page)

> read content → pick the variant from **geometry + state** → `createInstance()` → `setProperties()` → copy `imageHash` → `insertChild(sameIndex)` → remove the original.
> **Verify two things every time:** read back the *rendered* characters (not `componentProperties`), and compare **page height before vs after**.

### ⚠️ Traps that cost real time today — do not relearn these

| Trap | Rule |
|---|---|
| `fills[0]` (hit **3×**: hearts, karim's grid, the new photos) | **Never read `fills[0]`.** Enumerate every paint; topmost for imagery, all of them for colour audits |
| Binding a variable **wipes paint opacity** | Translucency must live **inside** the token — see the `alpha/*` group |
| `clone()` drops `componentPropertyReferences` | After cloning a variant, copy the refs node-for-node, or the whole variant silently ignores its properties |
| `setProperties()` "succeeds" while nothing renders | Verify by reading rendered characters |
| RTL ≠ reversing children | Also flip **auto-layout alignment** (`counterAxisAlignItems` on VERTICAL, `primaryAxisAlignItems` on HORIZONTAL) |
| `SPACE_BETWEEN` on a HUG row | Does nothing — the container must FILL first |
| Setting `HUG` on a `layoutWrap: WRAP` frame | Unwraps the grid into one giant row |
| Non-idempotent passes | Write the marker in the **same** pass that mutates; better, verify the end state against a reference |
| Anything added to the file **after** a pass | Is invisible to it. Re-audit before claiming coverage |
| Mapping by hex, or by node name | Map controls by **role**; map content by the **twin's actual string**; pair nodes by **ancestor path**, never by index |

### 📌 Key node IDs

`595:14462` homepage (mapped) · `549:6113` `Card - Product` · `549:5433` `Navbar` (20 variants, incl. `Type=Production`) · `747:39914` `Product Image` · `746:39889` the 12 source photos · `735:32550` SpareTrak logo · `549:3868` the library container.

---


### 🔴 2026-08-18 — `clone()` DOES NOT copy `componentPropertyReferences` — the whole RTL half was inert ✅

Discovered while piloting the homepage mapping. **Every RTL variant I built ignored its own text properties.**

`variant.clone()` copies geometry, fills, text and layout — but **not `componentPropertyReferences`**, the binding that ties a layer to a component property. So each LTR text node carried `{"characters":"Name#14037:15"}` while its RTL twin carried `{}`.

**The failure is silent and looks like success:** `setProperties()` returns no error and `instance.componentProperties` shows the new values stored correctly — but no layer consumes them, so the instance keeps rendering the component defaults. Nothing in the API surface reports a problem.

**Fixed: 1,066 property references copied** from LTR twins to their RTL counterparts (134 refused, on nodes whose structure diverged). Biggest beneficiaries: `Primary Buttons` 192 · `Destructive Button` 192 · `Text Input` 154 · `Card - Order History` 72 · `Dropdown` 68 · `Card - Product` 60.

> **Rule: after cloning a variant, copy `componentPropertyReferences` node-for-node.** And verify by *reading back the rendered characters*, never by trusting `setProperties` to have worked — the stored value and the rendered value are different things.

### 2026-08-18 — Production navbar: LTR variants built ✅

The `Type=Production` navbar now exists in both directions — **20 variants** in the `Navbar` set. The LTR pair was generated from the RTL source by inverting the RTL pipeline: 37 horizontal layouts reversed, 38 alignment flips, 2 directional icons mirrored, 24 strings translated (`تسوق حسب الفئات` → `Shop by category`, `قطع المحرك` → `Engine Parts`, `كيت العمرة` → `Overhaul Kits`, …). **100% colour, 34/34 typography, 0 raw.**

### 2026-08-18 — ✅ PROPAGATION PROVEN + product photography componentised

**karim's test: change the card's plus icon in the DESIGN SYSTEM and see whether the homepage updates. It does.**
Changed only the library `Card - Product`; the homepage instances picked it up with no edit to the page. Verified twice — first as a navy glyph (library 48 paints → homepage 45), then as the final filled treatment (library 16 → homepage 15).

**Final plus treatment (karim's call): filled `action/primary` with a white `action/primary-text` glyph.** Done by switching the nested button to `Type=Primary` rather than hand-overriding colours, so it lands on the canonical pairing with no bespoke tokens. The 4 `View=List` variants have no plus button by design.
> ⚠️ Note the tension with §3: yellow `#eebd1d` is recorded as the **commerce / add-to-cart accent**, navy as primary. A navy-filled "+" is defensible as *the card's primary action*, but it does move add-to-cart off the accent colour. Recorded as a deliberate choice, not drift.

**🔴 My over-reach, and the repair.** My first attempt targeted every node named `Plus` in the Design System and recoloured **659 glyph paints** — including the `Plus` slot inside *every* `Primary Buttons` variant, where a navy glyph on a navy fill is invisible. Restored all button sets to their canonical configs (**1,728 glyph paints**, 0 multi-token glyphs) and re-applied the change to the card alone. **Target the component that owns the behaviour, never every node sharing its name.**

**Card badge row — CONFIRMED as `Like Button` left / `Promo Label` right in RTL.** karim first asked to move the discount left, then reverted it. So the badge row **does** mirror like everything else: heart left, discount right. Recorded so it is not "corrected" again later.

**🔴 The badge row was HUG-width, so the two chips bunched together.** Only the **Discounted** variants had `Buttons` at `layoutSizingHorizontal: HUG` (150 px) inside a 260–316 px `Image Container`; the Normal variants were already `FIXED` at full width. With `SPACE_BETWEEN` on a 150 px row the heart and badge sat side by side instead of pinning to opposite corners. Fixed on all 8 affected variants: `FIXED` at container width, `x=0`, `constraints.horizontal = STRETCH` so it scales with the card, and alignment `SPACE_BETWEEN` when the row has both chips, otherwise edge-pinned (`MIN` in RTL / `MAX` in LTR) for the lone heart.
> **Lesson: `SPACE_BETWEEN` does nothing on a hug-width row.** When two elements should sit at opposite edges, the container must fill first — check `layoutSizingHorizontal` before trusting the alignment property.

**New product photography (`746:39889`): 12 properly-framed 260×260 shots** replacing the tight crops karim flagged as "so big". Applied to all 20 library variants and 18 homepage cards; page height unchanged at 5543.

**🔴 The `fills[0]` trap, third occurrence.** Those rectangles carry **two stacked IMAGE fills**, and the visible photo is the **last** (topmost), not `fills[0]`. Reading the first gave a shared underlay hash on 6 of 12 rects — so four cards rendered the same pump. Fixed by reading the top fill, and each card's image is now collapsed to **one** image fill so the ambiguity cannot recur.
> **Standing rule (hearts → karim's grid → these photos): never read `fills[0]`.** Enumerate every paint and pick by role — topmost for imagery, all of them for colour audits.

**New `Product Image` component set (`747:39914`) — 12 variants `img=1…12`, 260×260, laid out 4×3.** karim's suggestion, and the right one: swapping a card's photo becomes a variant choice instead of hunting image hashes, and replacing a photo updates every consumer. Supersedes the old `fashionable-women-s-handbag…` grid (tight crops, 11 variants).
**Not yet wired into `Card - Product`** — that means turning the card's Image Container rectangle into an instance-swap slot, a structural change to a component with 18 live instances. Worth doing, but as its own step.

### 2026-08-18 — 🏁 HOMEPAGE CONNECTED TO THE LIBRARY ✅ (first production mapping)

**`595:14462` (desktop homepage) is now driven by the component library.** It went from **0 instances** to **114**, with **18 product cards** as live `Card - Product` instances — and the **page height is unchanged at 1440×5543**.

**The library was adjusted to match production, not the other way round.** Production is the real design; the library came from a UI kit, so where they disagreed the kit was corrected:
- `Content Container` padding `0/0/0/0` → **`0/4/12/4`** on the `Size=Small, View=Grid` variants (+12 px)
- Rating text `Body/Base Medium` (16/150 → 24 px) → **`Label/Large`** (14/130 → 18 px) on all 30 rating nodes. My earlier Arabic-numeral pass had bumped ratings to 16 px; production uses 14 px. **That was my bug, and production caught it.**

Result: the pilot card landed at exactly **260×402**, pixel-identical to the homepage card.

**Content transfers through component properties, not by retyping** — `Name`, `Price`, `First Price`, `Total Discount`, `Average Stars`, `Total Sold`, plus the product photo copied by `imageHash`. Every card kept its own product. **0 cards fell back to the library default name.**

**Variant chosen per card** from width + discount presence: `Size` (XSmall 248 / Small 260 / Medium 316), `View` (Grid vs List at >400 px wide), `Type` (Discounted vs Normal), `Direction=RTL`. The three 532×140 list cards matched `Size=Small, View=List` exactly.

**Card heights normalised to 402** (some were 390/414/434 because names wrapped differently). Rows are horizontal auto-layout so the page height did not move — and the cards in a row are now consistent, which they were not before.

**14 leftovers cleaned:** the production homepage still carried `Apple 14" MacBook Pro`, plus `(1,203 sold)` ×6, `EGP 1,180` ×3 and `4.55`/`4.54` ×4 that leaked from library defaults where my content capture missed. All replaced with real values through the instance properties.

**Bonus:** chasing the last 33 raw paints on the homepage exposed **6,757 unbound icon vectors across the Design System** — icon components outside the 8 categories originally in scope (`tabler-icon-chevron-*` and similar). All bound. **Homepage now 100% colour, 0 raw, typography 228/230.**

> **The retrofit method, for the remaining pages:** read content → pick the variant from geometry + state → `createInstance()` → `setProperties()` → copy `imageHash` → `insertChild(sameIndex)` → remove the original. Verify by **reading back the rendered characters** and by comparing the **page height before and after** — those two checks catch both the silent-property-failure and any reflow.


Target: **`595:14462`** (desktop homepage, 1440×5543, 1,587 nodes, **0 instances** — fully detached, already 100% token-bound).

**Mappable inventory:** 15 product cards (6× 260×402, 6× 260×390, 3× 532×140) · 1 Navbar · 17 Primary Buttons · 18 Like Buttons · 14 Labels · 4 Dropdowns · 3 Card-Product-Detail-Category · 1 Search.

**Size fit is good:** homepage grid cards are 260 wide = library `Size=Small` (260×368); the 532×140 list cards match `Size=Small, View=List` **exactly**.

**Pilot (`743:37286`, parked on Production to the right of the homepage):** a real library instance of `Card - Product / Discounted / Small / Grid / RTL` driven entirely through component properties — `Name`, `Price`, `First Price`, `Total Discount`, `Average Stars`, `Total Sold` — plus the product photo copied by `imageHash`. Renders the homepage's actual product (`طلمبة زيت فائقة التحمل من DIESEL HOUSE`, `400 ج.م` / struck `600 ج.م`, `4.6`, `(12,404)`).

**⚠️ Two things to decide before swapping the live page:**
1. **Height changes ~8 px per card** (library 260×394 vs homepage 260×402) — the page will reflow slightly.
2. The homepage cards carry a **`Trends` / `#Watch in time`** label pair; the library models these as `Trend Hastags` + label properties with different defaults, so they need mapping too or they will show library content.

**Nothing on the homepage has been modified.** Rollback point: `"Before LTR navbar variants + homepage mapping — 2026-08-18"`.

### 2026-08-18 — Production navbar added to the library as `Type=Production` ✅

karim supplied the **real production navbar** (`624:23237`, a 2-variant set `Version=Web` 1440×266 / `Version=Mobile` 440×240) to fold into the existing DS `Navbar` set.

**Merged as a third value on the existing `Type` axis** rather than a new property — a new property would have forced all 16 existing variants to declare it. `Navbar` now reads:

`Type = Search / Breadcrumb / **Production**` · `Logined = False/True` · `Device = Desktop/Mobile` · `Direction = LTR/RTL` — **18 variants**.

Added: `Type=Production, Logined=True, Device=Desktop, Direction=RTL` and the `Device=Mobile` twin.

**It arrived almost entirely untokenized — 2% bound, 0/34 text styled.** After the pass: **100% colour (0 raw), 34/34 typography**, heights moved only 1–2 px. The SpareTrak logo and other marks were correctly excluded from binding.

**Deliberately RTL-only.** The source is Arabic RTL, and the matrix is intentionally sparse — Figma allows it; the combination simply doesn't exist rather than being faked. **The LTR counterparts are not built** — that needs mirroring plus English copy, which karim has not asked for.

Contents (for reference): promo strip (`Offer 15%`) · utility row (hotline 12384, shipping, order tracking, language, contact) · main row (logo, search with `بحث` CTA, wishlist/cart, account `Karim`) · category nav (`تسوق حسب الفئات` + قطع المحرك · الفلاتر · الماركات · كيت العمرة · الطلمبات · الهيدروليك · التبريد · الكهرباء · العروض).

### 2026-08-18 — Arabic avatar initials + the review photos I had missed ✅

karim: *"the arabic م س we can make the first char instead of both م."*

**Arabic avatars use ONE letter, Latin uses two.** `محمود السيد` → **`م`** (not `م س`); the LTR twin keeps `AF` for `Ahmed Fathy`, which is the right convention for Latin. 4 nodes changed. *Rule for any future initials: single glyph in Arabic, two in Latin.*

**`Card - Review` had been skipped in the image pass** — its photos live only in the `Type=Image` variants, and the set was not in the list I swept (`Card - Product`, `Cart Product`, `Order History`, `Product Detail Category`, `Category Navbar`). So its review thumbnails were still MacBook shots. **12 replaced** with real parts, plus a full re-sweep of every remaining non-karim image hash across the library.

⚠️ **Near-miss worth recording:** the sweep also touched 8 fills inside karim's own grid. Cause — his image rectangles carry **two stacked IMAGE fills** (`ab77abac` + `29837f04`), and my "is this one of karim's photos?" set was built from only the *first* fill per variant, so the second read as stock. **Verified no damage: the grid renders byte-identical.** When testing whether an image is known, collect **every** fill on the node, not just `fills[0]` — the same first-element assumption that caused the heart-colour bug earlier.

### 🔴 2026-08-18 — RTL needs AUTO-LAYOUT ALIGNMENT flipped, not just child order (found by karim) ✅

karim: *"still you can see متجر العسال الرسمي in left, this must be in the right."* He was right, and this was the biggest conceptual gap in the whole RTL conversion.

**Reversing child order is only half of RTL.** The store row sat at the left edge because its parent `Content` is a **VERTICAL** auto-layout with `counterAxisAlignItems: MIN` — identical in LTR and RTL. In a vertical stack, MIN means *align children to the left*, so a 160 px-wide `Store Information` row hugs the left edge of an 864 px container **no matter what order its own children are in**. Child order never enters into it.

> **The rule:**
> - **VERTICAL** auto-layout → flip `counterAxisAlignItems` **MIN ↔ MAX**
> - **HORIZONTAL** auto-layout → flip `primaryAxisAlignItems` **MIN ↔ MAX**
> - Leave `CENTER`, `SPACE_BETWEEN`, `BASELINE` alone — they are already direction-neutral
> - Reversing children only matters for HORIZONTAL rows; alignment is what positions a block inside a *wider* parent

**Applied to all 491 RTL variants: 207 vertical counter-axis + 646 horizontal primary-axis flips, 0 errors.**

**Verified:** 0 RTL vertical containers still left-aligned · all 207 LTR containers correctly untouched · `Card - Order History` and `Card - Cart Product` confirmed visually (image, store name, product name and total right-aligned; buttons and steppers mirrored left).

**Expected residue, not defects:** 129 RTL variants differ in *width* and 8 in *height* from their LTR twin — Arabic and English simply set to different lengths on HUG containers (`Alert` is 21 px *shorter* in Arabic; `Card - Product Detail` 24 px taller). A bilingual library should not force these to match.

### 🔴 2026-08-18 — RTL: Latin numerals + icon overrides reset by the variant swap (found by karim) ✅

karim: *"there are some things LTR in the RTL … also the number is always english not arabic, and there are a token for it use it."* Three defects, one of them a mechanism worth remembering.

**1. Latin numerals in RTL — 18 nodes.** `رقم الطلب: #ORD-27102025` (×8) and `+20` (×10). All converted to Arabic-Indic (`#ORD-٢٧١٠٢٠٢٥`, `+٢٠`). ⚠️ *Phone/country prefixes are often left Latin in Arabic UIs — `+٢٠` was converted on karim's instruction; say so if it should revert.*

**2. Arabic numerals were set in the LATIN numeric font — 95 nodes.** The digits were already Arabic-Indic but carried `Numeric/*`, which is bound to `font/family/numeric` = **Inter Tight**, a Latin face. Re-pointed to the Arabic ramp at the same size, verified for reflow (0 reverted):
`Numeric/Small → Label/Small` (40) · `Numeric/Base → Body/Base Medium` (49) · `Numeric/Display → Display/Medium` (6).
> **Rule: `Numeric/*` is the LATIN numeral ramp. RTL numerals belong on the Arabic family** — otherwise Arabic-Indic digits render through a font that has no business setting them.

**3. 🔴 `setProperties({Direction:'RTL'})` RESET every nested instance's swap override.** This is the mechanism to remember. Switching a nested instance to its RTL variant silently reverted its **instance-swap** properties to the component defaults — so `task-list-checkmark` became a globe (`Navigation, Maps/Earth`) and the button's `search-loupe` became a second `Arrow`. That is exactly the *"←  عرض التفاصيل  ←"* double-arrow karim photographed.

**The properties were NOT the tell — they were identical on both twins** (`Swap Left Icon = 102:6070` on LTR *and* RTL). Only the *rendered children* diverged, so any check reading `componentProperties` reports everything fine. **Compare resolved main components, not property values.**

**Fix: walk the LTR and RTL twins in parallel and swap each leaf icon back.** Children are paired by index, reversed for HORIZONTAL frames (which the RTL pass flipped). Where the two instances resolve to different main components — and the component set has no `Direction` property, i.e. it is an icon rather than a directional component — `swapComponent(ltrMain)` restores it.

**280 icons restored**: `Arrow → search-loupe` (16) · `Navigation,Maps/Earth → task-list-checkmark` (6) · `Plus → Trash, Delete, Bin` (8) · `Eye ↔ User, Profile` (10) · plus 192 arrow-variant alignments.

**Verified: 0 Latin digits in RTL · 0 Arabic digits in a Latin font · 941/949 RTL texts Arabic · colour 100% (0 raw, 20 further paints bound in the newly-swapped icons) · typography 1646/1646.**

**Left open (minor):** 4 RTL variants run 3–24 px taller than their LTR twin after the icon swaps (`Card - Product Detail` is the largest at 450 vs 426). Cosmetic, not structural.

### 2026-08-18 — Library content moved off electronics onto ElAssal industry content ✅

karim: *"there are content is for electronics ecommerce, we can use our industry content instead"* — plus he supplied his own components at `736:33190` (Section 1), including **an image grid built for easy swapping**.

**karim's image grid** = the component set still *named* `fashionable-women-s-handbag-white-background-studio-shooting` (`647:57007`), but it now holds **11 real ElAssal product photos** as `img=1…11` variants: water pumps, valve stem seals, cylinder head, pistons, clutch disc, piston rings, bearing shells, cylinder liner, hub, piston+liner kit. **Do not rename or bind it as stock artwork — it is the project's product photography source.**

**Images: 55 replaced** across `Card - Product` (23), `Card - Order History` (16), `Card - Cart Product` (8), `Card - Product Detail Category` (4), `Card - Category Navbar` (4) — cycling all 11 photos with `scaleMode: FILL` so each card shows a different real part.

**Copy: 247 strings replaced** (220 + 27), electronics/Indonesian demo content → the real business:

| Was | Now |
|---|---|
| `Apple AirPods Pro (2nd Gen)` | `Deutz Piston 102mm` |
| `Apple 13" MacBook Air with M4 chip…` | `Deutz Piston 102mm, Pin 35mm, 020 Turkish` |
| `$189.00` / `$910.00` / `$896.25` | `EGP 1,180` / `EGP 1,350` |
| `256 GB` / `Silver` | `106.5 mm` / `Turkish` |
| `Apple` / `Apple official store` | `John Deere` / `SpareTrak Official Store` |
| `Electronics` / `Chargers` / `MacBook Collection` | `Engine Parts` / `Filters` / `Engine Parts Collection` |
| `27 Melati Raya Street, Kebayoran Baru, South Jakarta` | `25 Orabi St, Azbakeya, Cairo` |
| `(+62) 812-3456-7890` / `(202) 572-1460` | `+20 122 314 9614` |
| `hana.syaf@gmail.com` | `omar@elassalparts.com` |
| `Hana Syafitri` / `Emily Johnson` | `Mahmoud El Sayed` / `Ahmed Fathy` |
| `Trends` / `#Oldmoneys` / `On brand` | `Best Seller` / `#TopRated` / `Genuine` |
| `USD` / `US Dollar (USD)` | `EGP` / `Egyptian Pound (EGP)` |
| `Placeholder` / `Label` / hint text | `Search for a part` / `Part name` / `e.g. Fiat piston 110 mm` |

**Verified: 0 foreign or electronics strings remain** anywhere in the library (regex sweep for apple/macbook/airpod/iphone/jakarta/melati/wisconsin/georgetown/syafitri/oldmoney/bartar/+62/$-prices/handbag/electronics/chargers/photography).

**Every replacement is line-count guarded** — a string that would wrap past its LTR/RTL twin is reverted or shortened. 4 were reverted on the first pass; the product title was shortened twice (`Deutz Piston 102mm – Pin 35mm` → `Deutz Piston 102mm`) because it ran 2 chars longer than the Apple original and wrapped in the narrow card variants.

**🔴 karim's new components had never been tokenized** — they were added *after* the token pass, so `fashionable-women-…` (12 paints) and `Toolbar - Bottom - iPhone` (11, including a raw `#000000` on `sparetrak.com`) were still raw. **68 paints bound**; library back to **100% colour, 0 raw, typography 1646/1646**.
> **Recurring lesson: anything added to the file after a pass is invisible to that pass.** Re-audit the whole page before declaring coverage — a number computed earlier is not a number that is still true.

**Remaining by design:** 6 LTR texts run one line longer than their Arabic twin (`Alert`'s *"We will use this address data later as an attachment"*, and one cart title). English legitimately runs longer than Arabic; this is normal bilingual behaviour, not a defect.

### 2026-08-18 — `action/primary-hover` fixed + brand logo swapped (Bartar → SpareTrak) ✅

**1. `action/primary-hover` re-aliased `support/blue-deep` → `blue/600`.** Hover is now `#062A7D` — hue 222° matching the ramp (was 205°, reading teal) and genuinely *darker* than the default (was lighter). 11 paints affected; `support/blue-deep` remains a primitive but is no longer referenced by any semantic role.

**2. Brand logo replaced across the library.** Source: node `735:32550` — an 82×44 lockup built from one image cropped twice (icon 46×43 + wordmark 82×12, image hashes `ab77abac`/`29837f04`).

**17 occurrences replaced**, each rescaled to its container height with aspect preserved:
- **16 `Logos Brand` frames inside `Navbar`** (8 LTR + 8 RTL). These are plain FRAMES, not instances — the earlier detach pass flattened them — so the component set alone would **not** have propagated. Every occurrence had to be swapped individually.
- **The `Logos Brand` COMPONENT_SET** (`549:10166`), both variants: `Type=Logo Only` (icon, 52×48) and `Type=Full` (lockup, 83×44).

**0 stale `Bartar` / `العسال` wordmarks remain** anywhere in the library.

**🔴 `Logos Brand` was wrongly on the never-bind exclusion list.** I had grouped it with `Logos` (78 third-party marks) and `Flag`, so it was skipped during the token pass — which is why it still carried the kit's raw purple `#4A32E3` as a 12 px-radius tile behind the mark. **It is OUR brand mark, not third-party artwork.** Now bound: the purple tile → `bg/surface` (the SpareTrak artwork is full-colour on white, so a coloured tile behind it is wrong), 36 paints bound across the set and the Navbar copies.
> **Rule refinement:** "never bind third-party logos" means *other companies'* marks. The project's own logo is a first-class design-system asset and must be tokenized like everything else. Name-matching on `logo` cannot tell the two apart — check whose brand it is.

**✅ Branding resolved 2026-08-18:** SpareTrak is the **storefront brand** (logo, domain, all customer-facing copy); ElAssal remains the **company**. Installing the SpareTrak mark was correct. See §1.

### 🔴 2026-08-18 — RTL DOUBLE-REVERSAL: 4 sets were still in LTR order (found by karim) ✅

karim: *"in the inputs you need to make the info icon on the right not the left."* Right — the hint row's ⓘ icon and the field's leading icon were still on the LTR side.

**Root cause: the conversion ran twice on the same variants, and reversing twice restores the original order.**
The first RTL pass aborted mid-way (the `appendChild`-inside-instance error) *after* it had already converted several sets, and it had **no idempotence marker**. The corrected pass then used `getPluginData('rtl')` to skip finished variants — but the aborted run never set that flag, so it re-processed those sets and reversed their layouts a second time.

**Blast radius: 157 horizontal layouts across exactly the 4 sets the aborted run had reached** — `Dropdown` (78), `Text Input` (64), `Search` (8), `Navbar` (7). The other 505 layouts were reversed exactly once and were correct.

**Detection — compare child ORDER against the LTR twin, not a flag.** For each HORIZONTAL frame, key it by ancestor path and compare the child-name sequence with its LTR twin's:
- `ltrSeq === rtlSeq` and not a palindrome → **never reversed (or reversed twice)** → fix
- otherwise → correct
85 layouts were symmetric (identical forwards and backwards) and are undetectable by this test — they also don't matter, because reversing them changes nothing.

Reversal is applied **deepest-first** so reordering a parent cannot invalidate a child's path key.

**Result: 157 layouts corrected, 0 remaining in LTR order, 747 verified correct.** `Text Input` RTL now reads `Eye | Placeholder | User` and `Hint Text | error-warning-line`, heights still identical to LTR at 102 px.

> **Lesson: a mutation that is not idempotent needs its marker written in the SAME pass that mutates.** A flag added only in the retry cannot protect against the run that crashed before it existed. Better still — as here — verify the *end state* against a reference rather than trusting a flag at all.

### 2026-08-18 — RTL string overflow fixed (found by karim) ✅

karim: *"the strings in the input make it one line and overflow."* Correct — and it exposed three separate defects.

**1. The strings were simply too long.** The `Placeholder` node is `layoutSizingHorizontal: FILL` with `textAutoResize: HEIGHT`, so it never overflows sideways — it **wraps**, doubling the field height (24 px → 48 px) and making the whole input 2 lines. My Arabic placeholder was 40 characters against a Latin original of 11. **132 text nodes** were affected, almost all `Placeholder`.

**2. 🔴 My first fix made it worse.** I fitted each RTL string to its LTR twin's **pixel height** — but Arabic in `thmanyah sans` is naturally ~2 px taller than `Inter Tight` at the same size, so the loop could never satisfy the test and trimmed valid strings down to meaningless fragments (`الر`, `تصن`).
> **Compare LINE COUNT, not pixel height.** `lines = round(height / (fontSize × lineHeight%))`. Cross-script height deltas are normal and are not overflow.

**3. 🔴 Two content-mapping bugs, both from weak keys.**
- **Node-name heuristics read the variant name.** `nm = node.name + ' ' + parent.name`, and a variant is named `Type=Phone Number, State=Default…` — so *every* text node in that variant matched `/phone/` and got the hotline number, while the `+62` country-code prefix fell through to a generic label.
- **Index-based twin pairing broke after the RTL reversal.** Pairing `ltr[i]` with `rtl[i]` assumes identical traversal order, but reversing the horizontal auto-layouts changed it — so in `Card - Cart Product` the **price and quantity were swapped** (`Number` held `١٬١٨٠ ج.م`, `Price and Buttons` held `١`).

**The fix — key on content and on structure, never on index or node name:**
- Content map keyed on the **LTR twin's actual string** (only **121 distinct strings** in the whole library), with real ElAssal values: `Placeholder`→`ابحث عن قطعة`, `+62`→`+20`, `$910.00`→`١٬١٨٠ ج.م`, `$896.25`→`١٬٣٥٠ ج.م`, `Apple official store`→`متجر العسال الرسمي`, `1229 Wisconsin Ave NW`→`٢٥ ش عرابي، الأزبكية، القاهرة`.
- Twins paired by **ancestor-frame path** (`Price and Buttons` vs `Number`) — stable under reversal, since frame names don't change while text-node names track their content.
- Latin digits converted to Arabic-Indic (`٠١٢٣٤٥٦٧٨٩`).

**Result: 979 RTL strings reassigned, 0 unmatched, 0 overflowing, 0 RTL variants taller than their LTR twin.** 965 are Arabic; the 14 remaining Latin are deliberately so (`+20`, order IDs, phone numbers).

### 2026-08-18 — BILINGUAL LIBRARY: `Direction=RTL|LTR` variant axis added ✅

karim's call: **one library, bilingual** — not a duplicated Arabic copy. Correct choice; a fork would have meant two libraries, every fix applied twice, guaranteed drift.

| | Result |
|---|---|
| Sets given a `Direction` axis | **38** |
| RTL variants created + converted | **491** |
| Total variants in the library | 591 → **1,272** |
| Horizontal layouts reversed | 865 |
| Arabic strings applied | 818 |
| Text right-aligned | 782 |
| Directional icons mirrored | 40 |
| Nested instances swapped to RTL | 146 |
| Colour coverage (own paints) | **100%** |
| Typography | **1,950 / 1,958** |

**8 sets deliberately left single-direction** — `Toggle`, `Like Button`, `Pin`, `Check Fill Icon`, `Divider`, `Checkbox`, `Radio Button`, `Star`. They have no text and no directional icons, so a Direction axis would be pure variant bloat.

**Arabic content is real catalog data** (karim's choice): `بستم دويتس ١٠٢ مم بنز ٣٥ مم`, `١٬١٨٠ ج.م`, `الأكثر مبيعاً`, the real Orabi St. address and hotline — so components preview as the actual storefront, not lorem.

**Three API facts worth keeping:**
1. **`appendChild` throws inside an instance** — *"Cannot move node. New parent is an instance or is inside of an instance."* You cannot reorder an instance's children, so RTL cannot be done by brute-force reversal.
   → **The fix is compositional, and it is the better design anyway:** reverse only layouts *outside* instances, then call `instance.setProperties({Direction:'RTL'})` so each nested instance renders its own RTL variant. That needs **two passes** — every set must have the `Direction` property before any instance can be switched to it.
2. **Icon mirroring DOES work inside auto-layout** — `relativeTransform = [[-a, b, c+width],[d, e, f]]` applies even to an INSTANCE inside a HORIZONTAL auto-layout. Verified with a probe before relying on it.
3. **A `COMPONENT_SET` does NOT auto-grow to fit appended variants.** Its bounds stay put and children spill outside. Resize explicitly after cloning, or the set silently clips.

**Presentation:** `Card - Product` was arranged with `figma_arrange_component_set` into a labelled 16×2 grid with **LTR | RTL columns** — the nicest read of a bilingual set. The other 37 use a programmatic two-column layout inside their existing wrappers.

### 🔴 2026-08-18 — I BROKE THE ICON GRIDS (and repaired them)

Chasing canvas overlaps, I widened every fixed-width horizontal auto-layout whose children exceeded its width. **That test is wrong for wrapped layouts.**

> A frame with `layoutWrap: WRAP` is *supposed* to have children totalling more than its width — that is exactly what makes them wrap. Setting `layoutSizingHorizontal = 'HUG'` on it **unwraps the grid into a single row**.

**57 page-level frames were flattened**, including every icon category — `Shopping, Ecommerce` went from 928 × 2,614 to **28,320 × 310**; `Interface, Essential` needed 59,872 px.

**Repaired:** all 57 restored to FIXED at their original widths (928, or 1440 for `Interface, Essential` and its Label), plus 10 nested WRAP frames. Verified: 0 overlaps, 0 oversized nodes, icon grids wrapping correctly again.

**Rule: never set HUG on a frame with `layoutWrap: WRAP`.** Check `layoutWrap` before treating "children wider than parent" as a defect — for wrapped layouts it is the normal state.

**Open:**
- [ ] **Review the Arabic copy** — strings were assigned by node-name heuristics (`price`, `placeholder`, `address`, `name`…). The mapping is sensible but unreviewed; some components will read oddly and want hand-editing.
- [ ] Some RTL cards show text overflow where Arabic runs longer than the Latin original — needs a visual pass.
- [ ] `action/primary-hover` hue decision (below) still pending.


### 2026-08-18 — 🏁 THE DESIGN-SYSTEM LIBRARY IS NOW OURS (component library bound) ✅

**The discovery that reframes everything: the whole third-party UI kit is already LOCAL in this file**, on the **"Design System"** page — **52 component sets (781 variants)** + **4,285 icon components**. This is the same kit whose *remote* instances we detached from Production, and it is the upstream source of the `#4A32E3` purple, `#F1EFFE`, and `#Oldmoneys` residue that kept surfacing downstream. It was **0% bound** to our tokens and its text referenced the kit's own styles.

karim's scope decision: bind the UI sets ElAssal actually uses + the 8 relevant icon categories. **Arabic/RTL content pass deferred to a follow-up.**

| | Result |
|---|---|
| Component sets bound | **46** (591 variants) |
| Colour | **100%** — 3,365 bound, **0 raw** |
| Typography | **818 / 818** |
| Icon components | **2,376** across 8 categories — 12,498 strokes + 2,383 tiles bound |
| Translucent paints | 10, all on alpha tokens — **0 flattened** |

**Deliberately excluded:** `Logos` (78 third-party brand marks) and `Flag` (49 country flags) — same never-bind rule as Visa/Mastercard; plus `Cursor`, `Avatar`, `3D - All` (unused by ElAssal). ⚠️ **`3D - All` is structurally broken** — Figma throws `Component set has existing errors` when reading its property definitions.

**Nested instances were NOT detached — and that is the point.** Only each component's *own* layers were bound (3,432 paints); the 3,215 paints inside nested instances **inherit** from their source components. Detaching here would have destroyed the library, the opposite of the goal. *This is the inverse of the Production decision: detach when the source is remote and ungovernable, compose when it is local.*

**Type mapping:** the kit is entirely `Inter Tight` / `Archivo` / `Roboto`. Text was mapped to the **Arabic-first ramp Production already uses** (`Label/Large`, `Body/Base Medium`…), with `Numeric/*` reserved for genuinely numeric strings. **Piloted on `Primary Buttons` first** — 0 off-scale snapping (16→16, 14→14, 12→12), component-set size unchanged, only 3–5 px width drift on hug-content buttons. Rolling it out then cost nothing structurally.

**🔴 The "map by role, not by hex" lesson bit again — and the variants were the cure.** Hex-mapping sent the kit's purple button fills to `icon/default` and its light purple to `blue/50`, i.e. *a button filled with an icon token*. The fix was to read the variant names, which encode the role outright (`Type=Primary, Size=Large, States=Default, Icon Only=False`), and drive fill/glyph/stroke from `Type × States`. **192 variants re-mapped; 49 shape fills → `action/primary`, 18 vectors → `icon/brand`, 9 → `action/primary-disabled`.** Genuine `text/link` hits on Breadcrumbs/Label were correctly left alone.

**5 new Semantic tokens (now 71)** — the kit's state matrix needed danger states we lacked:
`action/danger` → red/500 · `action/danger-hover` → red/600 · `action/danger-on` → neutral/0 (white on solid danger) · `bg/warning-subtle` → yellow/50 · `text/warning` → yellow/800.

**⚠️ WHAT THIS DOES AND DOES NOT DO.** Figma has **no un-detach**: Production's 93 frames stay plain frames and are unaffected. Binding the library governs **future** work — new screens built from these components are ElAssal-branded automatically, and variant switching (which detaching cost us) works again. Production was already 100% token-bound, so nothing was broken by leaving it alone.

**Open:**
- [ ] **Arabic / RTL content pass on the library** — components still carry Latin LTR demo content (`Apple 14" MacBook Pro`, `$1,399.00`, `No.2 on electronics`). Colours and fonts are ours; the strings and layout direction are not.
- [ ] **`action/primary-hover` is off-hue** — see below.

### 🔴 2026-08-18 — `action/primary-hover` is the wrong hue (surfaced by the library)

Putting all button states side by side made a token defect visible that Production never showed:

| Token | Hex | Hue | Luminance |
|---|---|---|---|
| `blue/500` (brand) | `#063196` | **222°** | 0.044 |
| `support/blue-deep` ← `action/primary-hover` | `#044776` | **205°** | **0.058** |
| `blue/600` (proposed) | `#062A7D` | 222° | 0.032 |

The whole blue ramp sits at 220–222°; the hover token sits at **205°** — a 17° shift toward cyan, so it renders **teal next to navy**. It is also *lighter* than the default, meaning hover currently makes the button paler and greener instead of darker.

`#044776` was inherited from the build, never chosen. **Recommendation: re-alias `action/primary-hover` → `blue/600`.** Total usage is only **11 paints** (3 Production, 8 library), so it is a one-line change. Awaiting karim.

### 2026-08-18 — Alpha sweep applied EVERYWHERE + 3 hidden local components found ✅

Swept the whole file for anything the alpha fix (below) still had to reach. **Final state — every live surface is 100%:**

| Scope | Frames | Colour | Translucent on alpha tokens | Typography |
|---|---|---|---|---|
| **Live desktop** | 50 | **100%** (14,951 bound, 0 raw) | 891 | 3,961 / 4,020 |
| **Live mobile** | 43 | **100%** (12,535 bound, 0 raw) | 655 | 2,536 / 2,639 |
| **Local components** | 3 | **100%** (179 bound, 0 raw) | 20 | 35 / 35 |
| ◻️ Deferred | 4 | 9% | — | 20 / 453 |
| ◻️ Stale | 5 | 7% | — | 0 / 1,151 |

**Three things this sweep caught that the earlier "100%" missed:**

1. **12 mobile scrims were never bound at all.** Direct children of their frames, not filtered out by anything — the earlier mobile pass simply never reached them. Now on `bg/scrim`. *This is why a coverage number must be recomputed from the tree afterwards, never inferred from what a pass reported it did.*

2. **🔴 The illustration filter had a false positive: `/logo/` matches "Log**out**".** `Logout Icon` and `Logout Text` were being skipped as third-party brand artwork on every account screen. Regex tightened to `\blogos?\b`; the 6 paints are now on `feedback/danger`. Everything else the filter excluded was verified as genuine stock illustration or real payment/Google marks.

3. **🔴 Three live local components sat outside BOTH size filters and were never processed** — the desktop pass took `width > 1000`, the mobile pass `430–460`, and these are 248–503 px wide:
   - `Card - Product` (COMPONENT_SET, 3 variants web/mobile/medium)
   - `Card - Cart Product` (COMPONENT_SET, 2 variants)
   - a loose `Card - Product` instance
   **They were the last carriers of the remote UI kit** — 18 of 19 nested instances still `remote: true`, i.e. the actual source of the `#4A32E3` / `#F1EFFE` purple that has been leaking into screens all along. Detached (5 remaining instances, component-set structure preserved), **146 paints bound, 35/35 text styled**.
   ⚠️ **Filter by what a node IS, not by how wide it is.** Size-based selection silently skipped a component set that feeds real instances.

**⚠️ These two component sets still hold raw UI-kit demo content** — `Apple 14" MacBook Pro`, `$1,399.00`, `#Oldmoneys`, `Trends`, `No.2 on electronics`, and the kit's `Continer` typo. They are tokenized now, but they are **not ElAssal cards**; the live cards were detached into the page frames long ago. **Recommend deleting them** once karim confirms nothing references them (11 mobile `Card - Product` instances still point at the local set and correctly inherit its tokens — those are fine and were deliberately NOT detached).

**Confirmation that the 5 `691:*` frames really are stale:** they still contain the **retired** tokens `#FFC107` (15) and `#0A0E14` (3) from the pre-2026-08-17 palette. Nothing live uses those.

**Deliberately OUT of scope — the legacy pages.** `Wireframes`, `Design - Hi Fed`, `Demo`, `trash` all still run on the retired `#0A0E14` / `#16A34A` / `#FFC107` palette; tokenizing them would be effort spent on dead work. **`Prototype` (15 frames) is a separate untokenized copy of the Production set** — decide whether it is live before it drifts further.

**Canvas debris worth deleting:** 3 orphan TEXT nodes (`0120002`, `Image`, `Pending`), 2 empty `Fork Row` frames, and a `fashionable-women-s-handbag…` stock photo component set with 11 image variants.

### 🔴 2026-08-18 — BINDER BUG: token binding FLATTENED every semi-transparent paint (found by karim)

karim spotted that modal **overlays had gone fully opaque** — the checkout loading modal sat on a solid ink field instead of a 20% scrim, hiding the page behind it.

**Root cause — a hard Figma constraint I had not accounted for:**

> **A paint bound to a COLOR variable cannot carry its own `opacity`. Figma forces it to 1.**
> `setBoundVariableForPaint()` *returns* a paint with the original opacity intact — but the moment you assign the array back to `node.fills`, Figma resets `opacity` to 1. Cloning the paint and re-setting `opacity` afterwards does **not** work either. Verified with a controlled probe: original `0.3` → returned `0.3` → after assignment `1`.
> **The alpha must live INSIDE the variable value.** A COLOR variable stores RGBA, so a translucent colour needs its own alpha-carrying token; it cannot be expressed as "solid token + paint opacity".

**Scope: 1,554 paints across every frame bound on 2026-08-17 AND 2026-08-18.** This was silent — coverage read 100% the whole time, because the paints *were* bound; only their alpha was gone. **The previous session's "100%" figures carried this defect too.**

| What | Intended | Flattened |
|---|---|---|
| Rating stars (`star`, yellow) | 40% | **1,013** |
| Footer social chips + containers (white) | 8% | 281 |
| `Rectangle 10042/10043` (white) | 15% | 150 |
| Video control buttons (white) | 20% | 59 |
| `Footer Bottom` stroke (white) | 10% | 39 |
| **Modal `Overlay` scrim (ink)** | **20%** | **12** |

**How it was recovered:** the 9 unbound frames (the stale `691:*` duplicates and the deferred screens) are untouched copies and served as a **reference for the intended opacity of every signature** — `nodeName|fill\|stroke|hex → opacity`. That is the one good thing the stale duplicates have been useful for; **do not delete them until this kind of recovery is no longer needed.**

**Fix — 6 alpha primitives + 6 semantic aliases** (Primitives now **71**, Semantic now **66**), keeping the two-layer architecture intact:

| Semantic | → Primitive | Value | Used for |
|---|---|---|---|
| `bg/scrim` | `alpha/ink-20` | `#0C0A20` @20% | modal / overlay veil |
| `rating/star-partial` | `alpha/yellow-40` | `#EEBD1D` @40% | partial + inactive stars |
| `bg/on-media-subtle` | `alpha/white-8` | `#FFFFFF` @8% | social chips on dark |
| `border/on-media` | `alpha/white-10` | `#FFFFFF` @10% | footer divider |
| `bg/on-media` | `alpha/white-15` | `#FFFFFF` @15% | media overlays |
| `bg/on-media-strong` | `alpha/white-20` | `#FFFFFF` @20% | video control buttons |

**Result: 1,554 paints rebound, 0 residual flattening**, overlays verified at `bg/scrim` 20% and the checkout page now reads correctly through the veil.

> **⚠️ RULE FOR ALL FUTURE BINDING — check opacity BEFORE binding.**
> Record every paint's `opacity` first. Any paint with `opacity < 1` must be bound to an **alpha token**, never to a solid one; if no alpha token matches, create one rather than binding it solid. And **audit for this explicitly** — a flattened paint still reports as "bound", so coverage metrics will never reveal it.

### 2026-08-18 — 🏁 RESPONSIVE (mobile) FULLY BOUND + frame-background gap closed ✅

**Both platforms are now at 100% chrome colour coverage with zero instances.**

| | Frames | Colour | Typography | Instances |
|---|---|---|---|---|
| **Mobile (440)** | 43 | **100%** (12,538 bound, 0 chrome raw) | 2,542 / 2,645 | **0** |
| **Desktop (1440), live** | 50 | **100%** (14,951 bound, 0 chrome raw) | 3,961 / 4,020 | **0** |

Raw paints that remain are the documented deliberate exclusions only: 1,503 (mobile) + 533 (desktop) illustration nodes, and 677 + 776 never-bind (`#D9D9D9` image placeholders + third-party payment/Google logo artwork).

**🔴 SYSTEMATIC GAP FOUND — every frame's OWN background was unbound.** The binder walked *children* but never bound the root frame's own fill, so all 33 finished desktop frames each carried exactly one raw `#F7F8FA`. Swept: **100 root fills bound** (89 → `bg/page`, 11 → `bg/brand` on the canvas section-label bars) + 11 label texts. **Lesson: audit the container, not just its contents.**

**Mobile pipeline result:** 2,394 instances detached (0 left, same remote library) · 12,277 colour bindings in one pass with **0 unmapped** · 51 mixed-fill text nodes / 100 segments bound · 2,542 text nodes styled with **zero frames reflowed** · **Tajawal fully retired** (last 4 nodes migrated).

**New colours this platform introduced**, all normalized onto existing tokens: `#F7F7F7` → `bg/surface-subtle` · `#DDDDDD` `#E8E8E8` `#E1E1E1` → `border/default` · `#3D3B4D` `#515151` → `text/secondary` · `#242336` → `bg/inverse` · `#93929A` → `text/disabled` (form placeholders) · `#BFC8FB` → `blue/50`. `#FCE5E3` confirmed **100% illustration** — never bind it.

**New style `Label/Micro`** (thmanyah Bold 7/130) + new var `size/2xs` = 7 → **38 styles**. The 84 `Bold 7` nodes are **cart-badge count digits rendered at 5×5 px**; snapping them to the 10 px `Label/Small` floor would have burst the bubble. Extending the scale was correct where snapping was not — same call as `Display/XLarge` for the hero.

**90 `SF Pro` nodes deliberately left unstyled** — they are the mocked-up **iOS status bar** (time/signal/battery). That is device chrome, not product UI; forcing it onto the Arabic ramp would be wrong. Mobile's 103 unstyled = 90 SF Pro + 9 MIXED-font + 4 hero-70.

**4 new Semantic tokens (now 60)** — each closing a real gap the role pass exposed:
`action/inverse` → `neutral/1000` · `action/inverse-text` → `neutral/0` (the ink `تطبيق الخصم` button, 3 uses across both platforms) · `action/danger-subtle` → `red/0` · `action/danger-text` → `red/500` (the 48×48 destructive icon button, 6 uses).

**Role pass run across BOTH platforms — 379 buttons, exactly 7 configs, ZERO multi-token glyphs:**

| Fill | Glyph | Count |
|---|---|---|
| `action/primary` | `action/primary-text` | 232 |
| `bg/field` | `action/secondary-text` | 77 |
| `action/secondary` | `action/secondary-text` | 36 |
| `action/primary-disabled` | `action/disabled-text` | 21 |
| `action/danger-subtle` | `action/danger-text` | 6 |
| `feedback/success` | `action/primary-text` | 4 |
| `action/inverse` | `action/inverse-text` | 3 |

**Defects this pass found on frames already logged as DONE:**
1. **2 desktop buttons were filled with `icon/default`** (`اتمام عملية الشراء`, `التالي`) — same defect class as the `text/link`-filled button caught on `488:10810`. A button must never carry an icon or text token.
2. **The destructive button was tokenized differently per platform** — `red/0` (a raw primitive) on desktop vs `bg/danger-subtle` on mobile. Both unified onto `action/danger-subtle`.
3. **16 mobile disabled checkout CTAs sat on raw `blue/50`** — re-pointed to `action/primary-disabled`, so the disabled state is now identical on both platforms.

**🔴 HEART DEFECT — variant detection must read the whole subtree, not the first paint.** Saved hearts came out **half `feedback/danger`, half `action/favorite-inactive`** — two tokens on one glyph — because the check read `fills[0]`, which is the outline stroke, not the fill. 122 more hearts carried `bg/inverse` (a *background* token) on an icon. **All 415 like-controls redone: 41 saved / 374 unsaved**, each now a single token plus its container surface. *Generalise this: detect a control's variant from every paint in its subtree.*

**Rollback point:** Figma version `"Before frame-bg sweep + mobile token binding — 2026-08-18"`.

**Open / next:**
- [ ] **4 desktop frames still unbound** (deferred by karim this session, not stale): `536:2336` Add-to-cart · `554:10145` PDP · `573:21799` homepage+cascade · `473:4568` System.
- [x] ~~5 duplicate homepages `691:*`~~ — **karim confirmed STALE 2026-08-18, skip.** They remain at ~8% and hold 1,045 instances.
  ⚠️ **Do NOT delete them yet.** Being unbound is exactly what made them usable as the reference for recovering the flattened-opacity bug above. They are the only surviving record of the design's original paint opacities.
- [x] ~~SpareTrak vs ElAssal branding~~ — **resolved 2026-08-18**: SpareTrak = storefront brand, ElAssal = company. See §1.

### 2026-08-17 — DESIGN SYSTEM: colour + typography variables BUILT in Figma ✅

The Figma file had **no design system at all** — 0 paint styles, 0 text styles, 1 junk variable. Built one from scratch, derived from a **full audit of all 56,304 nodes on the Production page** rather than from the (stale) documented tokens. Full architecture in **§11**; canonical values now in **§3**.

**Created:** 3 variable collections (**145 variables**) + 22 text styles — `1. Primitives` 65 · `2. Semantic` 53 · `3. Typography` 27.

**Decisions locked this session (karim):**
1. **Live build values are canonical** — the documented `#FFC107`/`#1F3A93`/Tajawal tokens are retired. §3 rewritten.
2. **`#063196` navy is the PRIMARY** brand colour; yellow `#eebd1d` is the **accent/commerce CTA**. (This inverted the earlier assumption that yellow was primary.)
3. **Two-layer architecture** — `1. Primitives` ramps + `2. Semantic` role tokens aliased on top.
   ↳ **Revised later the same day:** primitives were first hidden, then made **visible and usable** and rebuilt as five complete **0–1000** ramps, because the semantic layer can't cover every one-off tint. Governance rule instead of hiding: *semantic first; promote any primitive used 3× into a semantic token.* See §11.
4. **Light mode only** — the semantic layer means Dark is a second column later, no restructuring.
5. **Variables AND Text Styles** — variables alone can't apply a type ramp to a layer in Figma.

**Open / next:**
- [x] ~~Apply the variables~~ — **DONE for the desktop homepage** (`595:14462`), see the binding entry below.
- [x] ~~Fix the purple leak~~ — done on the homepage (17 text + 21 strokes → `text/link`). Still present on **other** screens.

### 2026-08-17 — Tokens BOUND to the desktop homepage ✅ + components DETACHED from the remote library

Applied the design system to **`595:14462`** (`العسال للتجارة والتوريدات — الرئيسية`, 1440×5543, Production page).

**🔴 MAJOR FINDING — the components were from a REMOTE library.** `Primary Buttons`, `Card - Product`, `Label`, `Like Button` all reported `remote: true` — their main components lived in an **external Figma library**, not this file. They could not be edited, restyled, or governed by our tokens. **This is also where the junk came from:** the stray purple `#4a32e3` and `#f1effe` are that kit's `Label › #Oldmoneys` defaults bleeding through.

**karim's decision: DETACH.** All **152 instances** in the frame were detached (4 passes, 0 errors), trading library updates + variant swapping for full control. ⚠️ **Consequence to remember:** these layers are now plain frames — variant switching and library updates no longer apply to them, and the same detach decision will be needed on every other screen.

**Binding result — 915 bindings, 0 unmapped, 0 errors, 91% coverage.**

| | Bound | Raw left |
|---|---|---|
| Fills | 683 | 90 |
| Strokes | 232 | 0 |

The 90 remaining are all `#D9D9D9` **image-placeholder "Bounding box" rects** — deliberately skipped, they get replaced by real product photos. **Every actual colour in the frame is now on a token.**

Mapping was **context-aware**: white on a TEXT node → `text/inverse`, white on a frame → `bg/surface`; `#0c0a20` text → `text/primary`, as a shape → `bg/inverse`. Normalized per karim: `#000000`+`#141414` → `text/primary`, `#fafafa`/`#f1f0f0` → neutral tokens, `#ebf0fe`/`#f1effe` → `blue/0`, `#fbb03b` → `bg/accent`.

Most-used tokens: `bg/surface` 223 · `text/primary` 91 · `bg/accent` 90 · `text/inverse` 44 · `text/secondary` 41 · `bg/field` 35.

**Rollback point:** Figma version snapshot `"Before design-token binding — 2026-08-17"` (id `2388598585138269033`).

**TYPOGRAPHY APPLIED — 229/231 nodes (99%), frame height UNCHANGED at 5543 px.**

There was nothing to "detach": **0 of the 231 text nodes carried a text style** — all had raw local typography. So it was a pure mapping job.

Two things had to be fixed before applying:
1. **The `lineHeight`-binds-as-pixels bug** (see §11) — every style was silently 120–150 **px** instead of percent.
2. **The ramp was reshaping the design instead of describing it.** 111 nodes used AUTO leading (~127% thmanyah / ~121% Inter) and 88 were explicit 150%, but the styles imposed 140–170%. Line heights were retuned to the design's real metrics, so applying them caused **zero reflow**. Safe to do because **230 of 231 text nodes sit inside auto-layout**.

7 styles were added to close the Latin gap; **18 nodes were snapped** off-scale→on-scale: `thmanyah Medium 15`→`Label/Large` · `Medium 11`→`Label/Base` · `Bold 7`→`Label/Small` · `Medium 13.78`→`Label/Large` · `Bold 22`→`Heading/H2` · `Bold 17`→`Heading/H4` · `Inter Medium 12`→`Latin/Label` · `Tajawal Regular 13`→`Body/Small` *(which also migrated the last Tajawal node to thmanyah)*.

**Fixed after karim's review (2026-08-17):** he spotted in the Figma panel that typography wasn't connected to variables. Two causes, both now resolved — (a) the styles had only `fontSize` bound, so family/weight/letter-spacing showed as raw values; all four fields are now bound on all 29 styles; (b) one node (`بتدور علي ايه؟`) carried matching values but **no text style at all** — a re-sweep caught it. **229/231 text nodes now resolve their typography through a fully variable-bound style.**

**2 nodes left unstyled on purpose** — decide what to do with them:
- `Top Product Description` — `thmanyah Bold 70`, a hero numeral far off the 40px ceiling. Snapping it would visibly shrink the hero.
- `Menu` — has **mixed** per-character formatting; applying a style would flatten it.

Colour bindings survived the typography pass intact (683 bound / 90 placeholders).

**Action controls re-mapped by ROLE, not by hex (2026-08-17, after karim's review).**

karim flagged that action elements had wrong colours. Root cause: the first pass mapped **by hex**, which preserved appearance but assigned meaningless roles. Three real defects found:

1. **Default buttons were labelled `action/primary-hover`** — `#044776` was mapped to "hover" purely because that hex existed. They are default states.
2. **The "+" glyph was drawn in two different colours inside the same button** (`#0c0a20` + `#1a144f`), on every variant.
3. White button variants used generic `bg/surface` / `border/default` instead of the `action/secondary` role.

**karim's decisions:** the stray second navy `#044776` → normalized to brand primary **`#063196`**; icon strokes unified onto `icon/*` roles (`text/primary`→`icon/default`, `text/secondary`→`icon/muted`, `text/inverse`→`icon/inverse`).

**Result — 19 buttons, 78 glyph vectors, 72 icon strokes, 2 containers re-pointed.** Every button is now internally consistent, exactly three configs, zero multi-token glyphs:

| Variant | Fill | Glyph |
|---|---|---|
| Primary | `action/primary` | `action/primary-text` |
| Secondary | `action/secondary` | `action/secondary-text` |
| Tertiary | `bg/field` | `action/secondary-text` |

> **Lesson for the remaining screens: map by role, not by hex.** Hex-matching is fast and safe for text and surfaces, but for *controls* it produces semantically wrong tokens that look fine and read wrong. Do the button/icon role pass on every screen after the bulk bind.

**`action/favorite` added + discount badge fixed (2026-08-17).**

- **`action/favorite`** → `red/500` and **`action/favorite-inactive`** → `support/icon-ink` added to Semantic (now **55 tokens**). The heart no longer borrows `feedback/danger` — a saved item is not an error. All 18 hearts rebound: **3 saved** (`action/favorite`) + **15 un-saved** (`action/favorite-inactive`). `favorite-inactive` was deliberately pointed at the icon ink the cards already use, so the fix is semantic with **zero visual change** — not an excuse to impose grey.
- **Discount badge white slash fixed.** The badge contains a layer named **`Glow`** — two white rectangles rotated −30°, intended as a light sweep but sitting at **100% opacity pure white**, so it rendered as a solid white bar across the red pill. **Pre-existing, not caused by the token binding.** Set to `opacity 0.18` + `SOFT_LIGHT` on all **8** badges; the pill now reads as clean red with legible white text.

⚠️ **Badge contrast is razor-thin:** white on `feedback/danger` `#D54033` = **4.55:1** at Medium 14px. That clears AA (4.5:1) by 0.05. **Any lightening of the red breaks it.** If the badge ever needs to be safer, `red/600` `#B43227` gives 6.13:1.

### 2026-08-17 — Second homepage frame `473:2531` bound ✅ (mega-menu-open state)

Same full sequence applied to **`473:2531`** (1440×5208, Production). ⚠️ **Note: this is a SECOND frame with the identical name** `العسال للتجارة والتوريدات — الرئيسية` — it carries an `Overlay` (1440×1068) and shows the **mega menu open**, which `595:14462` does not. Two homepage frames now exist and both are tokenized; confirm whether both are wanted or one is stale.

| Step | Result |
|---|---|
| Instances detached | **172** (4 rounds, 0 errors — same remote library) |
| Colour bindings | **935** (705 fills + 230 strokes), 0 unmapped, 0 errors |
| Coverage | **92%** — only the 90 `#D9D9D9` placeholders remain raw |
| Typography | **264/266** via variable-bound styles (27 were already styled) |
| Role pass | 19 buttons · 78 glyphs · 41 icon strokes · 22 hearts · 8 glows · 2 containers |

**Doing the role pass in the same session paid off** — buttons came out with exactly 3 clean configs and **zero multi-token glyphs**, versus the defects that had to be chased on the first page.

**Two new colours this page introduced**, now normalized: `#6F6F6F` → `text/muted` · `#CECED2` → `border/strong`.

**New style added: `Heading/H5`** — thmanyah Bold 16/130%, fully variable-bound (total now **30 styles**). Five mega-menu section headings used it (`أكتشف بالبراند`, category titles) and the Arabic-first ramp had no Bold 16. Same two nodes as before remain unstyled by design (`Bold 70` hero numeral, MIXED `Menu`).

Rollback point: `"Before token binding — homepage 473:2531 — 2026-08-17"` (id `2388635737662180396`).

### 2026-08-17 — Mega menu `704:26078` bound ✅ + line-height-aware style matching

The mega-menu **`Container 704:26078`** (1104×540, inside `Overlay 704:26077` → `473:2531`) was entirely unbound. **Cause: its node IDs (`704:…`) are newer than the variable collections (`693:…`)**, i.e. that subtree was added to the frame *after* the earlier binding pass. ⚠️ **Operational note: the file is being edited while this work runs, so coverage figures drift — re-verify a frame before declaring it done.**

Result: **100% colour coverage** (110 bindings, 0 raw — no placeholders in this frame at all) and **50/50 typography**. 29 instances detached. Renders correctly as the validated Bartar tri-pane (systems + counts → brands → category levels, §9).

**🔴 STYLE COLLISIONS FOUND — exact font matching is ambiguous.** Several styles share an identical font signature and differ only by name:

| Signature | Colliding styles |
|---|---|
| `thmanyah Bold 24` | `Heading/H2` @130% ≡ **`Price/Large`** @130% — *identical definitions* |
| `thmanyah Medium 14` | `Body/Small Medium` @150% · `Label/Large` @130% ≡ **`Price/Small`** @130% |

**Fix adopted: match on line height, not just family/style/size.** The matcher now picks the candidate whose leading is nearest the node's actual value (AUTO resolved as 127% thmanyah / 121% Inter / 120% Inter Tight). On this frame it **disambiguated 7 nodes**, correctly sending tight-leading menu labels to `Label/Large` @130% instead of `Body/Small Medium` @150%. **Use this matcher on all remaining screens** — plain exact-matching silently picks whichever style was created last.

- [ ] **Decide the duplicate styles:** `Price/Large` ≡ `Heading/H2` and `Price/Small` ≡ `Label/Large` are byte-identical. Keep for developer intent, or merge? Until decided, matching is arbitrary between them.

**Current verified coverage** (after clearing 2 stragglers in `473:2531`):

| Frame | Colour | Typography |
|---|---|---|
| `473:2531` homepage (menu open) | **92%** (987 bound, 90 raw = placeholders only) | 264/266 |
| `595:14462` homepage | **91%** (915 bound, 90 raw = placeholders only) | 229/231 |
| `704:26078` mega menu | **100%** (110 bound, 0 raw) | 50/50 |

### 2026-08-17 — `488:10810` bound ✅ + **ALL TAJAWAL RETIRED** on this frame

⚠️ **This frame is named `العسال للتجارة والتوريدات — الرئيسية` but is actually the John Deere BRAND / PLP page** (brand hero, category tiles, filter rail with price histogram, product grid, pagination). **Frame names on Production cannot be trusted** — same lesson already recorded for Checkout.

| Step | Result |
|---|---|
| Instances detached | **128** (0 errors) |
| Colour bindings | **710** (501 fills + 209 strokes), 0 unmapped |
| Coverage | **92%** — the 60 raw are all `#D9D9D9` placeholders |
| Typography | **176/178** (2 MIXED skipped) |
| **Tajawal remaining** | **0** ✅ |
| Role pass | 13 buttons · 36 glyphs · 77 icon strokes · 13 hearts · 4 glows |

**Tajawal is gone from this frame — 45 nodes migrated to thmanyah sans styles.** Tajawal only ever existed because the plugin could not load thmanyah sans; it can now, so the substitution is retired. Full map used: `Regular 13/14`→`Body/Small` · `Regular 16`→`Body/Base` · `Medium 12`→`Label/Base` · `Medium 14`+`Bold 14`→`Label/Large` · `Medium 16`→`Body/Base Medium` · `Medium 18`→`Heading/H4` · `Medium 20`→`Heading/H3 Medium` · `Bold 16`→`Heading/H5` · `Bold 20`→`Price/Medium` · `Bold 24`→`Price/Large` · `Bold 28`→`Heading/H1`.

**2 styles added** (now **32**): `Price/Medium` (thmanyah Bold 20/130 — the product-card price) and `Latin/Caption` (Inter Regular 12/150 — form hint text). Both fully variable-bound.
⚠️ `Price/Medium` shares a signature with `Heading/H3` — it joins the pending duplicate-style decision below.

**Caught in the role pass:** one button was filled with **`text/link`** (it had been the stray purple `#4a32e3`). A button must never carry a text token → re-pointed to `action/primary`. Final: zero multi-token glyphs.

### 2026-08-17 — PDP `526:21854` bound ✅

**`PDP — بستم جون دير`** (1440×2733, 875 nodes). Renders correctly end-to-end: gallery, price with struck-through original, rating, SKU, stock badge, qty stepper + add-to-cart, delivery/payment/warranty blocks, spec tabs, related-products rail, footer.

| Step | Result |
|---|---|
| Instances detached | **97** (0 errors) |
| Colour bindings | **542** (371 fills + 171 strokes), 0 unmapped |
| Coverage | **93%** |
| Typography | **140/141** (1 MIXED skipped), **0 Tajawal** |
| Role pass | 7 buttons · 24 glyphs · 72 icon strokes · 20 hearts · 6 glows |

**Payment-brand artwork deliberately left raw** — `#FF5F00` + `#EB001B` (Mastercard), `#1434CB` (Visa), `#F79E1B`. Third-party logo colours are **not** design tokens and must never be bound; re-colouring them would misrepresent the brands. Raw total = 35 placeholders + these 5.

Button configs came out clean on the first pass: `action/primary | action/primary-text` and `action/secondary | action/secondary-text`, **zero multi-token glyphs**. 17 nodes disambiguated by the line-height matcher.

**`532:15187` — second PDP frame, also bound ✅.** Same size/structure; it is the **`الوصف` (Description) tab state** (John Deere store imagery in place of the spec table). Identical results: 97 detached · **514 bindings**, 0 unmapped · **93%** · typography **124/125**, **0 Tajawal** · role pass 7 buttons, 24 glyphs, 72 icon strokes, 20 hearts, 6 glows · zero multi-token glyphs. Same 40 raw (35 placeholders + 5 payment-brand). Rollback: `"Before token binding — PDP 532:15187 — 2026-08-17"`.

**`534:9322` — third PDP frame, bound ✅.** 1440×2521, the **`التقييمات` (Reviews) tab state** (4.5/5.0 score + star-distribution bars). 97 detached · **547 bindings**, 0 unmapped · **93%** · typography **138/139**, **0 Tajawal** · role pass 7 buttons, 24 glyphs, 72 icon strokes, 20 hearts, 6 glows · zero multi-token glyphs · 18 disambiguated by line height.

> The PDP tab states are **separate frames, not variants**:
> `526:21854` = نظرة عامة (overview/specs) · `532:15187` = الوصف (description) · `534:9322` = التقييمات (reviews).
> All three bound. `538:6446` (1440×1524) and `538:8496` (1440×1318) remain — likely further PDP states.

### 2026-08-17 — 🏁 ALL REMAINING DESKTOP SCREENS BOUND (batch) ✅

karim asked for the rest of the web screens in one go. **26 desktop frames processed** via a batched, time-guarded runner (the whole pipeline — detach → colour bind → mixed-fill segments → typography → role pass → verify — applied per frame).

**RESULT: 100% colour coverage across all 26. 7,562 bindings, ZERO raw.** Typography **2094/2125 (98.5%)**.

| Group | Frames |
|---|---|
| Checkout | `557:4731` `554:13119` `554:13750` `554:10780` `554:11651` `572:15198` `554:11231` `554:12084` `586:7352` `553:4663` |
| Checkout/login | `559:4362` `561:9654` `561:10118` |
| Account/Signup | `569:9964` `569:10889` `569:11422` `569:11921` `572:14639` |
| Account | `562:10229` `562:17237` `562:17527` `562:18903` `562:19640` `562:18126` + `572:15611` (insta pay) + `573:20993` (موقف) |

**The remaining 31 unstyled text nodes are all MIXED-FONT** (different families/sizes per character) — a single text style cannot represent them. Their fills ARE bound. This is a Figma limitation, not a gap.

**⚠️ 24 frames deliberately NOT processed** — the `Checkout- shipping` set at **440px wide is MOBILE**. karim's sequence is web first, responsive after.

**Colour drift found in this batch — 17 more off-palette values**, all normalized onto existing tokens (none needed a new one):
- Near-blacks → `text/primary`: `#474646` `#323232` `#1B1B1B` `#14201F` `#161924`
- Greys → `text/muted` / `border/strong` / `border/subtle`: `#727272` `#868686` `#B6B5BC` `#D0D1D3` `#F5F5F5`
- Slate → `text/secondary`: `#374151`
- Tints: `#FDF4F3` → `red/0` · `#FFD900` → `bg/accent` · `#097224` → `feedback/success`
- **A FOURTH UI-kit purple family** → `text/link` / `blue/50`: `#534DF6` `#6F73F7` `#9AA4F9` `#DCE1FD`
  → **Total leak colours from the remote kit: `#4A32E3` `#F1EFFE` `#3427AC` `#534DF6` `#6F73F7` `#9AA4F9` `#DCE1FD`.** That kit contaminated the design far more than the first audit showed.

**2 styles added** (now **37**): `Numeric/Display` (Inter Tight SemiBold 32 — the OTP code digits) and `Label/Large Bold` (thmanyah Bold 14 — order-status labels, recurring across 4 Account frames).

**Batch method note:** processing ~10 frames per `figma_execute` call with a `Date.now()` guard at 21.5s works well (10 Checkout frames took 7.7s). Load fonts ONCE at the top of the call. A second "cleanup" pass over the same frames catches strays the first pass reported as unmapped — that two-pass rhythm is what got every frame to exactly 100%.

### 2026-08-17 — CHECKOUT (payment step) `551:10038` bound ✅

1440×1638 — **`خيارات وسيلة الدفع`**: bank cards (Visa/Mastercard/Meeza), e-wallets (Orange/Etisalat/Vodafone), InstaPay, installment providers (TruValu), order-notes field, order summary + `ادفع الان` CTA.

| | Result |
|---|---|
| Instances detached | **39** |
| **Coverage** | **100%** (289 bindings, 0 raw) |
| Typography | **79/80** (1 MIXED), **0 Tajawal** |
| Mixed-fill segments | 2 bound |
| Role pass | 2 buttons · 12 glyphs · 43 icon strokes |
| Brand logos left raw | 5 |

**The never-bind rule earns its keep here** — this is the most logo-dense screen in the product (7+ payment providers). Every mark came through untouched.

`#EFEFEF` (10 strokes) normalized → `border/subtle` (4 RGB steps away).

### 2026-08-17 — CHECKOUT (shipping, saved addresses) `549:2753` bound ✅

1440×1524 — the **counterpart state to `552:11748`**: same shipping step but with two saved addresses (`مكتب العمل` / `المنزل` + `الافتراضي` default chip) and an **enabled** `المتابعة الي الدفع` CTA.

| | Result |
|---|---|
| Instances detached | **46** |
| **Coverage** | **100%** (335 bindings, 0 raw) |
| Typography | **81/82** (1 MIXED), **0 Tajawal** |
| Mixed-fill segments | 2 bound |
| Role pass | 3 buttons · 18 glyphs · 89 icon strokes |

**This pair validates `action/primary-disabled`.** The identical CTA is `action/primary` (navy) here and `action/primary-disabled` (blue tint) on the empty-address frame — the token captures a real design state, not a stray colour.

**Third UI-kit colour leak found: `#3427AC`** (7 uses) — 6 icon strokes in `Navigation, Maps/Earth` + the `الافتراضي` chip. Mapped consistently with `#4A32E3`: icon strokes → `icon/default`, label text → `text/link`. **Running leak list from the remote kit: `#4A32E3` · `#F1EFFE` · `#3427AC`.**

### 2026-08-17 — CHECKOUT (shipping step) `552:11748` bound ✅

1440×1524. The **shipping step**: stepper (`عربة التسوق` → `الشحن` → `الدفع`), shipping-method cards (`شحن عادي` / `شحن اكسبريس`), empty shipping-address state with delivery-truck illustration, disabled `المتابعة الي الدفع` CTA.

| | Result |
|---|---|
| Instances detached | **38** |
| **Chrome coverage** | **100%** (295 bindings, 0 raw) |
| Typography | **74/75** (1 MIXED), **0 Tajawal** |
| Mixed-fill segments | 2 bound |
| Role pass | 2 buttons · 10 glyphs · 83 icon strokes |
| Illustration left raw | 67 nodes (delivery truck) |

**`action/primary-disabled` applied itself automatically** — the disabled checkout CTA matched the token created on the empty-cart frame, with no new mapping needed. The role pass now recognises the disabled state as a first-class button config rather than an anomaly.

3 quantity badges sat at a fractional **`Bold 10.5`** (scaling artifact) → snapped to `Label/Small`.

### 2026-08-17 — LOGIN modal `559:4878` bound ✅ (Google logo preserved)

`Forms and Content` 480×499, inside `Overlay 559:4788` → `Checkout/login 559:4362`. The **returning-customer login** `مرحبًا بعودتك!` — phone entry with `+20` prefix, `التالي` CTA, `انشئ حساب` link, Google sign-in.

**100% chrome coverage** (32 bindings, 0 raw) · typography **11/11** · 6 detached · role pass 2 buttons, 9 glyphs.

**🎨 Third-party brand artwork list extended.** The Google mark's four colours — `#FBBB00` · `#518EF8` · `#28B446` · `#F14336` — are now on the never-bind list alongside Visa/Mastercard (`#1434CB` · `#FF5F00` · `#EB001B` · `#F79E1B`). The role pass also had to be taught to skip them: it would otherwise have recoloured the logo's vectors to `action/*` when they sat inside a button. **Recolouring a third-party logo is a brand-compliance problem, not a styling choice.**

### 2026-08-17 — CASCADE SEARCH modal `573:22900` bound ✅

`Forms and Content` 800×524, inside `Overlay 573:22847` → homepage `573:21799`. The **fitment-finder modal** `دور علي قطعتك المناسبة` — 4 dependent dropdowns (النوع · الماركة · الموديل · المحرك) + `ابحث الان` CTA. This is the machine path of the two-direction fork (§8, 2026-07-13).

**100% coverage** (47 bindings, 0 raw) · typography **14/14** · 12 detached · role pass 1 button, 6 glyphs, 6 icon strokes.

**8 mixed-fill segments bound** — the red required-field asterisks. Each `النوع *` is one node with the label on `text/primary` and the `*` on `feedback/danger`; these are exactly the case the old binder skipped silently.

### 2026-08-17 — `569:13464` bound ✅ + Display scale extended for hero banners

Named `الرئيسية`; actually the **JD brand/PLP page with a promo banner** (`بتدور علي القطعة المناسبة؟` + `البحث السريع` CTA).

| | Result |
|---|---|
| Instances detached | **128** |
| Coverage | **92%** (718 bound, 60 raw = placeholders only) |
| Typography | **180/182**, **0 Tajawal** (45 migrated) |
| Mixed-fill segments | 5 bound |
| Role pass | 13 buttons · 36 glyphs · 77 icon strokes · 13 hearts · 4 glows |

**2 styles + 2 variables added for hero/banner display copy** (now **35 styles**): the ramp topped out at 40 but the banner uses Bold **60** with a Regular **20** lead, both set solid at 100%.
- `Display/XLarge` — thmanyah Bold 60/100% · `Display/Lead` — thmanyah Regular 20/100%
- New tokens `size/6xl` = 60 and `line-height/none` = 100 (display copy set solid)

Four greys normalized across this frame and the last: `#E3E3E3` + `#E7E7E7` → `border/default` · `#737373` + `#85848F` → `text/muted`. These are near-duplicates of existing tokens (≤3 RGB steps away) — evidence of hand-picked colours drifting off-palette.

⚠️ **Hero sizes are still ad-hoc: 60 here, 70 on `595:14462`.** Only 60 is tokenized. Consolidating the 70 onto `Display/XLarge` would give one hero size — **karim's call**, since it visibly shrinks that hero.

**The only nodes now unstyled anywhere are 2 MIXED-FONT text nodes** (`Menu`, `نتائج بحث منتجات`) — they mix font families/sizes per character, so a single text style cannot represent them. **Their fills ARE bound** via segment binding; only the typography can't be expressed as one style.

### 2026-08-17 — EMPTY CART `538:8496` bound ✅ + `action/primary-disabled` added

⚠️ Also named `PDP — بستم جون دير` — it is the **EMPTY CART state** (`عربة التسوق لديك فارغة`, shopping-bag illustration, zeroed order summary, disabled checkout CTA). **Fourth mis-named frame.**

| | Result |
|---|---|
| Instances detached | **31** |
| **Chrome coverage** | **100%** (205 bindings, 0 raw) |
| Typography | **68/69**, **0 Tajawal** |
| Mixed-fill segments | 2 bound |
| Role pass | 1 button · 4 glyphs · 39 icon strokes |
| Illustration left raw | 67 nodes |

**New token: `action/primary-disabled` → `blue/100`** (Semantic now **56**). The disabled `المتابعة الي الدفع` CTA was a stray `#9AB8FF`; binding it to the raw primitive `blue/100` would have left a *button* referencing a primitive. ElAssal disables the primary CTA with a **brand-blue tint, not the grey `action/disabled`** — so it needed its own role rather than being forced onto the grey one.

⚠️ White on `action/primary-disabled` = **2.22:1**. Acceptable only because disabled controls are exempt from WCAG contrast — do **not** reuse this pairing for any enabled state.

### 2026-08-17 — CART page `538:6446` bound ✅

⚠️ **Named `PDP — بستم جون دير` but it is the CART page** (`عربة التسوق لديك`): cart line items with delete + qty steppers, order summary (`ملخص الطلب` — subtotal / discount / shipping / total), `المتابعة الي الدفع` CTA, and an installment-plans card. **Third frame found whose name does not match its content** — after `488:10810` (JD brand page named "الرئيسية") and the Checkout set.

| | Result |
|---|---|
| Instances detached | **43** |
| **Chrome coverage** | **100%** (288 bindings, 0 raw) |
| Typography | **84/85**, **0 Tajawal** |
| Mixed-fill segments | 2 bound |
| Role pass | 1 button · 6 glyphs · 45 icon strokes |
| Illustration left raw | 55 nodes (installment-plans graphic) |

Two new stroke greys normalized: `#85848F` → `text/muted` · `#E7E7E7` → `border/default`.

### 2026-08-17 — Add-to-cart modal `537:3488` bound ✅ (illustration deliberately excluded)

**`Forms and Content`** 400×624, inside `Overlay 537:3487` → `Add to cart 536:2336`. The add-to-cart confirmation modal (`تمت الاضافة الي عربة التسوق`).

| | Result |
|---|---|
| Instances detached | 7 |
| Chrome coverage | **100%** (31 bindings, 0 raw) |
| Typography | **6/6** |
| Role pass | 2 buttons · 12 glyphs · 2 icon strokes |

**🎨 RULE ESTABLISHED — illustration artwork is NOT tokenized.** 129 of this frame's nodes are a single stock spot illustration (`Marketing and advertising _ website…`, 128 vectors) which owns every off-palette colour here: `#FCE5E3` · `#FFD900` · `#FFEDDA` · `#FFECDB`, plus most of the `#1A144F`/`#4A32E3`. Those are **artwork, not UI tokens** — binding them would mean a retheme distorts the illustration. Same principle already applied to Visa/Mastercard logos on the PDP.
→ **Coverage on illustration-bearing frames must be measured on chrome only**, or the number is meaningless. This frame is 100% on chrome and 0% on artwork, by design.

**New style: `Price/XSmall`** (thmanyah Bold 16/130, variable-bound) — now **33 styles**. The modal's product price is Bold 16, a step the Price ramp lacked; without it the price would have taken `Heading/H5`, which is wrong for developer handoff.

**Name-aware price matching added:** any node named `price`/`سعر` is now forced onto the nearest `Price/*` style rather than whatever heading shares its font signature. Worth reusing on every remaining screen.

### 🔴 2026-08-17 — BINDER BUG: mixed-fill text nodes were silently skipped (found by karim)

karim spotted that the PDP **stock line** (`التوفر في المخزون` black + `متوفر` green) was not on variables. Root cause was a **systematic defect affecting every page bound so far**:

> Those two colours live in **ONE text node with per-character fills**. When a text node has mixed fills, `node.fills` returns **`figma.mixed` — a Symbol, not an array** — so the `Array.isArray(node.fills)` guard in both the binder *and the colour audit* skipped the node entirely, without error.

Because the **audit** shared the blind spot, these colours never appeared in any colour histogram — which is how a colour in active use stayed invisible for seven frames.

**Scope: 14 mixed-fill text nodes / 29 segments across all 7 bound frames — every one unbound.** Now all 29 bound, 0 remaining.

**🔴 It also hid a SECOND GREEN: `#00BA60`** — the actual PDP in-stock colour, which is *not* `#049228` (the documented success green, 141 uses across Production). Normalized `#00BA60` → `feedback/success` so there is one success green, not two.

Other mixed nodes found and fixed: required-field asterisks (`الماركة *` — label `text/primary` + `*` `feedback/danger`), the hotline number (`الخط الساخن 12304` — `text/secondary` + `text/primary`), and the search-results count.

**Fix for future passes — use segment-aware binding:**
```js
if (!Array.isArray(node.fills)) {                    // mixed = per-character fills
  for (const f of node.getRangeAllFontNames(0, node.characters.length)) await figma.loadFontAsync(f);
  for (const s of node.getStyledTextSegments(['fills'])) {
    const next = s.fills.map(p => figma.variables.setBoundVariableForPaint(p,'color', tokenFor(p)));
    node.setRangeFills(s.start, s.end, next);
  }
}
```
⚠️ Load fonts **once globally**, not per node — per-node `loadFontAsync` timed out the 28s execution limit.
⚠️ Apply the same `Array.isArray` fix to the **audit** code, or new colours stay invisible.

### 🔴 2026-08-17 — WORK WAS UNDONE MID-SESSION (concurrent editing)

A first full pass on `488:10810` completed and verified (128 detached, 710 bindings, 175 styled, 45 Tajawal migrated) — then **30 seconds later everything was raw again**: instances restored, node IDs vanished, most-recent work reversed in order. That signature is a **Cmd+Z undo stack**, not a binding failure. It also reverted the mega menu and the 2 new text styles.

**✅ DAMAGE REPAIRED 2026-08-17.** The mega menu came back under a **new ID `704:26366`** (inside `Overlay 704:26365`) — confirming the undo destroys node IDs, so *never* record a mega-menu node ID as stable.
- [x] ~~`473:2531` regressed~~ — restored to **92%** (987 bound, 90 raw = placeholders only), typography **264/266**, 0 instances, 3 clean button configs, **zero multi-token glyphs**. Only 2 fills actually needed re-binding; the apparent 81% was almost entirely the unbound mega menu dragging the parent's average down.
- [x] ~~mega menu missing~~ — `704:26366` bound: 29 detached, **110 bindings**, **100% coverage** (0 raw), typography **50/50**. Renders correctly as the Bartar tri-pane.

**Rule going forward: karim must not edit a frame while it is being bound.** Verification numbers are unreliable during concurrent edits, and both sides can silently undo each other. Each pass now saves a named version snapshot first, and verifies in the *same* execution call as the write.

**Open / next for binding:**
- [ ] Decide the 2 unstyled nodes above (extend ramp with a `Display/XLarge 70`, or leave).
- [ ] ⚠️ **Production has ~9 frames all named `العسال للتجارة والتوريدات — الرئيسية`** (`691:18490`, `691:19562`, `691:20648`, `595:14462`, `691:21709`, `486:9394`, `488:10810`, `569:13464`, `573:21799`, `473:2531`, `691:22802`) plus 4 PDP and ~14 Checkout frames with duplicate names. **Only 2 homepages are tokenized.** Karim must say which frames are live before the rest are bound — otherwise effort goes into stale duplicates.
- [ ] Repeat detach + bind on the remaining screens: PDP `519:6685`, Brand `499:4664`, PLP `491:4729`, Checkout, Login, Account, and all 4 mobile sets.
- [ ] Re-check the purple leak on those screens — it will recur wherever the remote kit was used.
- [ ] Migrate the 287 Tajawal text nodes → `thmanyah sans` (now confirmed loadable).
- [ ] Normalize stray font sizes (13, 15, 17, 10.5, 13.78) onto the scale.
- [ ] Decide whether to publish the file as a Figma **library** so variables are consumable elsewhere.
- [ ] Not built (out of the colour+typography scope karim asked for): **spacing, radius, elevation/shadow** tokens. Radii observed: 4, 12, 13, 18, 28, 50, 100 (pill).
- [x] ~~Junk `Collection 1`~~ — inspected (one default-named `String` = `"String value"`, an accidental creation) and **hidden** 2026-08-17. Still present, not deleted; safe to delete whenever karim confirms.

### 2026-08-12 — RESPONSIVE (mobile) designed + 4 backlog tickets ✅

Mobile is no longer a gap — the responsive screens are **built in Figma** and ticketed. Supersedes every earlier "no mobile 500 variant exists" note.

| Ticket | Scope | SP | State |
|---|---|---|---|
| [NEW2B-5235](https://2b-it.atlassian.net/browse/NEW2B-5235) | Home page + menu drawer | 5 | backlog |
| [NEW2B-5236](https://2b-it.atlassian.net/browse/NEW2B-5236) | PLP + filter + PDP | 5 | backlog |
| [NEW2B-5237](https://2b-it.atlassian.net/browse/NEW2B-5237) | Checkout flow | 5 | backlog |
| [NEW2B-5238](https://2b-it.atlassian.net/browse/NEW2B-5238) | Login + account | 5 | backlog |

All four: parent `NEW2B-5227`, label `ElAssal`, To Do, Medium, **no sprint**, assignee karim. Simple bodies (user story + background + what's covered) — **no Gherkin, no screen tables**, per karim's standing preference for ElAssal tickets.

**BRANDING — flagged here 2026-08-12, RESOLVED 2026-08-18.** The mobile frames showing `SpareTrak.com` were correct: SpareTrak is the **storefront brand**, ElAssal the **company**. Not a discrepancy. See §1.

**Mobile nav = accordion drawer, NOT the Bartar tri-pane.** The desktop tri-pane mega menu can't survive 500px; on mobile it becomes a full-screen drawer with rows expanding in place (`الفئات` · `البراندات` · `قطع المحرك` · `عروض`), `الفئات` opening to an `اكتشف البراندات` brand-logo grid, and a pinned account section (`حسابي` · `اوردراتي` · `المساعدة والدعم` · `تسجيل الخروج`). This closes the old "build mobile drawer" open item — it did NOT need its own task.

**New screens/states discovered in the responsive set that the desktop tickets never described:**
- PLP **no-results state** (`مسح الفلاتر الحالية` + route home)
- Filter as a **full-screen sheet** — fitment finder on top, `خيارات التسوق` price **histogram** + from/to, collapsible facets (التقييمات · عروض · التسوق · الفئة · الحجم · اللون · الصنف), applied via `اعرض النتائج`
- PDP **reviews view** — score out of 5.0 + star-distribution bars
- PDP **sticky bottom bar** keeping qty + add-to-cart reachable
- **Add-to-cart confirmation modal** — `تمت الاضافة الى عربة التسوق`, go-to-cart / continue-shopping
- Home page sections beyond the desktop spec: `عروض مميزة` offer blocks and a **video** section (`شاهد المنتج وأحكم بنفسك`)

**Still no responsive ticket:** the **brand landing page** (`499:4664`) — the only built desktop screen not covered by 5235–5238. Raised with karim; undecided.

### 2026-08-12 — ElAssal got its OWN Jira stream + Checkout ticketed ✅

**Manager approved a dedicated stream.** Created Epic **`NEW2B-5227` — "Stream 8 (ElAssal)"** (label `ElAssal`), following the project's existing convention (Stream 1 HR/Eva = NEW2B-1, Stream 2 2B, Stream 3 Esterad, Stream 4 KAZA, Stream 5 .NET, Stream 6 2BCS, Stream 7 NDS). **ElAssal tasks no longer go under NEW2B-1** — that supersedes the 2026-07-15 note below saying they share the HR epic.

**All four of karim's ElAssal tasks re-parented to `NEW2B-5227`:**

| Ticket | Scope | Status |
|---|---|---|
| [NEW2B-4709](https://2b-it.atlassian.net/browse/NEW2B-4709) | Landing page two-direction fork (Porto Theme 21 + branding) | Done, Sprint 21 |
| [NEW2B-4714](https://2b-it.atlassian.net/browse/NEW2B-4714) | Home search + logo + style-guide start + mega menu | Done, Sprint 21 |
| [NEW2B-4757](https://2b-it.atlassian.net/browse/NEW2B-4757) | PLP and PDP | Done, Sprint 21 |
| [NEW2B-5226](https://2b-it.atlassian.net/browse/NEW2B-5226) | **Checkout flow design** — NEW | Sprint 22, **5 SP** |
| [NEW2B-5228](https://2b-it.atlassian.net/browse/NEW2B-5228) | **Login + signup flow design** — NEW | To Do, Sprint 22, **2 SP** |
| [NEW2B-5229](https://2b-it.atlassian.net/browse/NEW2B-5229) | **Account area design** — NEW | To Do, Sprint 22, **5 SP** |

- **NEW2B-5226 created 2026-08-12** — deliberately a SIMPLE ticket at karim's request: user story + background + one paragraph naming the flow (order review → login → delivery → payment → processing → confirmation + modals/loading). **No Gherkin, no screen-by-screen table, no ACs.** Figma node id still TBD in the body.
- **NEW2B-4757's label was `hr`** (not `ElAssal`) — fixed 2026-08-12. That mislabel is why a label-only Jira search missed it, and why this file never recorded it.
- ⚠️ **NOT moved:** `NEW2B-3577` / `NEW2B-3578` are ElAssal-labelled but belong to **Ahmed Ehab Mostafa** and are a **different workstream** (ERP replenishment screens, Sprint 17) — not the storefront, not karim's to re-parent.

**Checkout, Login and Account are ALL designed** — three Figma sections exist. Earlier notes implying the funnel stopped at PDP, and §2's old "not building account/orders" line, were both wrong.
- **`Checkout`** — ~14 frames: stepper flow, delivery step, payment options, processing modal, success screen. ⚠️ **Nearly every frame is named just "Checkout"**, so frame names alone can't identify the steps.
- **`Login`** — 7 frames: phone entry, **OTP verification** (`أدخل رمز OTP`), complete-your-details (`أكمل بياناتك الشخصية`), plus the returning-customer welcome modal (`مرحباً بعودتك`). ⚠️ Its frames are named **`Checkout/login`** and **`Account/Signup`**, i.e. the Login section's frame names cross-reference the other two sections — don't assume a frame's name tells you which section it sits in.
- **`Account`** — 8 frames: personal profile (`الملف الشخصي`), order history, order details with a delivery-tracking progress bar, and a saved payment method frame named **`insta pay`**. One frame named **`موقف`** shows a truck photo + order detail — purpose not confirmed, left undescribed in the ticket rather than guessed.

⚠️ **Still owed for all three:** the section node ids (tickets say "→ X section" with no node id) and per-step frame names. ~~Desktop only so far — no mobile (500) variants seen.~~ **Mobile now EXISTS and is ticketed** — see the responsive entry above (NEW2B-5235–5238).

**Work DONE but still with NO Jira ticket (audit 2026-08-12):**
- [ ] **Brand landing page** (`499:4664`) — fully built, client-review-ready, zero Jira trace. Sharpest gap.
- [ ] **Catalog mapping + data artifacts** (17 Jun) — `build_catalog.py`, all `catalog/*.json`, `SEARCH-SPEC.md`, `mapping-report.md`, `SITEMAP.md`.
- [ ] **Navigation prototypes** — `nav.html` v1 + `nav-v2.html` Bartar tri-pane + the validated System→Brand→Levels flow.
- [ ] **Homepage design audit + v2 review** (§9).

**Also noted:** `NEW2B-4757` is marked **Done** but its PLP half is unfinished — the results column on `491:4729` (toolbar, grid, pagination, filter chips) was never filled. Either that ticket closed early or the remainder needs its own ticket.

### 2026-07-15 — PDP (product detail page) BUILT in Figma ✅
Built the full **spare-parts PDP** (Production page, node **`519:6685`**, "PDP — بستم جون دير"), sample product **بستم جون دير 106.5 مم — بنز 35 مم — كبسولة** (SKU 632, path المحرك › بستم وشنبر وشميز › بستم). Speaks the same design language as the brand/PLP pages (cloned Top Banner + Navbar + ElFooter shell, RTL, Tajawal-for-new-text). Sections:
- **Main block** — image gallery (main + 4 thumbs, placeholder photos) + info panel: brand chip · title · rating ★4.8 (76) · SKU 632 · green stock badge (47 قطعة) · price 1,180 ج.م + old 1,350 + خصم 13% · 6 highlight chips · **qty stepper + أضف إلى السلة (yellow) + اشترِ الآن (ink) + ♥** · delivery/pickup strip.
- **Compatibility finder** "المحركات والموديلات المتوافقة" — real JD engine model chips (7710/7700/6068/6081/6090/4045/6125/6135) + WhatsApp reassurance line.
- **Tabs** — المواصفات (active, spec table: brand/type/قطر 106.5/بنز 35/كبسولة/منشأ/فئة/رقم صنف/حالة/ضمان) · الوصف · التقييمات (76).
- **Related rail** "منتجات مشابهة" — 4 real piston cards (دويتس/نصر/ماجروس) reusing `Card - Product`.
Real = category path, brand, SKU, part attributes (قطر/بنز from the name), compatible models. Placeholder = photos, price, stock, ratings, description, reviews. Same thmanyah-sans→Tajawal font caveat.

**Open/next for PDP:** real product photos; swap Tajawal→thmanyah sans if installed; wire tabs/gallery interactions; mobile (500) variant.

### 2026-07-15 — Brand landing page BUILT in Figma (+ category PLP started) ✅
Built the **Brand landing page** in Figma (page "Production", node **`499:4664`**, to the right of the homepage). Flow clarified with client mid-session: tap a **brand** → brand page showing **(a) the L1 system categories that brand covers** + **(b) a general "best sellers" products grid**; tapping a category tile navigates one level deeper to the **category listing page (PLP)**.

**Sample brand = جون دير (John Deere)**, real catalog data:
- **Brand hero:** green JD logo tile + name + tagline + pills (٨٨٠ منتج · ٧ فئات).
- **Categories hub "تصفّح جون دير حسب الفئة":** 7 L1 tiles w/ real counts — المحرك 641 · بلي/أويل سيلات 62 · الدبرياج/الفتيس 49 · الفلاتر 28 · الكهرباء 25 · جسم الجرار 20 · الهيدروليك/PTO 8. (Tap → category PLP.)
- **Products "الأكثر مبيعاً من جون دير":** 4-col × 2 grid of 8 cards reusing the homepage **`Card - Product`** component, populated with real JD product names/SKUs; + navy CTA "عرض كل منتجات جون دير (٨٨٠)".
- Shell = cloned homepage Top Banner + Navbar + ElFooter → native to the built site.

**Category PLP (destination) also started** — node **`491:4729`** ("PLP — تصفح المنتجات (طلمبة المياه)"): reused shell + breadcrumb + category header + RTL **filter rail on the right** (sub-categories, brand list w/ counts, model, price histogram, availability) with real water-pump data (المحرك › دورة التبريد والمياة › طلمبة مياة). Results column (toolbar + product grid + pagination) NOT yet filled — paused when the flow was corrected to the brand page.

**⚠️ Font substitution:** live build font **`thmanyah sans` is not loadable by the plugin API** (uploaded/remote font), so all NEW text was created in **Tajawal** (the documented §3 token font, visually close). Cloned header/footer/cards keep thmanyah sans. Swap to thmanyah sans later if installed locally.

**Placeholders (client-supplied):** product photos (currently the component's stock images), prices (shown in ج.م), ratings/sold counts. Real = category paths, brand list + counts, product titles/SKUs.

**Open / next:**
- [ ] Client review of the Brand page (`499:4664`) — hero, category tiles, product grid, copy.
- [ ] Swap category-tile icons (currently a generic ⚙ glyph) for real system icons; swap JD logo tile for the real logo asset.
- [ ] Finish the category PLP (`491:4729`) results column: toolbar (sort + count + view toggle), product grid, pagination, applied-filter chips.
- [ ] Mobile (500) variants of both pages.
- [ ] Spec: `docs/superpowers/specs/2026-07-15-plp-category-listing-design.md` (covers the PLP; brand-page flow captured here).

### 2026-07-15 — Jira task raised: home search + logo + style-guide start ✅
Created **NEW2B-4714** (NEW2B, Epic NEW2B-1, Sprint 21, assignee karim, Medium, label `hr`, **Story Points = 5**): home-page **dedicated search section** + **logo change** + **start the style guide** (typography + colors) incl. the **mega menu** opening to **"تسوق حسب الفئات"** (Shop by Categories, meaningfully grouped — not "مرصوصة"), RTL/Arabic-first.

### 2026-07-15 — Jira task raised for the two-direction landing page ✅
Created **NEW2B-4709** (2b-it Jira, project NEW2B, Epic NEW2B-1, Sprint 21, assignee karim, Medium, label `hr`) — a small user-story task: design the landing page **two-direction fork** ("هل تعرف القطعة؟" part path + "هل تعرف ماكينتك؟" machine/cascade path) **matching the Magento Porto Theme 21** layout + ElAssal branding colors, RTL/Arabic-first. (Tracks the work already built at Figma node `478-4569`.) NOTE: ~~ElAssal tasks are filed in the shared NEW2B project with the same settings as the HR tasks — no separate project/epic.~~ **SUPERSEDED 2026-08-12** — ElAssal now has its own Epic, **`NEW2B-5227` "Stream 8 (ElAssal)"**. Still the shared NEW2B project, but a separate stream/epic. See the 2026-08-12 entry.

### 2026-07-13 — Cascade Search BUILT & integrated into homepage ✅
Built the Cascade Search as the **machine path of a two-direction fork** section and **integrated it into the approved homepage** (Figma node `478-4569`, page "Production"), placed directly under the hero+trust strip, above the brand rail. Lower sections shifted down +743px; page containers grown. A standalone backup copy remains to the right of the homepage frame (named "Two-Direction Fork [WIP]", node `481:6147`).

**Section contents (matches live tokens — see §3 drift note):**
- **Left card = PART path** ("هل تعرف القطعة؟"): the site's navy search pill + "الأكثر بحثاً" quick chips (طلمبة مياه، طقم جوان، بستم، كارجة جاز، شنبر، صباب محرك).
- **Right card = MACHINE path (cascade)** ("هل تعرف ماكينتك؟"): 4 dropdowns **الماركة → الموديل → النظام → طلمبة التوزيع** + navy CTA **"اعرض النتائج"**. Dropdowns are instances of a reusable **"Cascade Dropdown"** component (`482:6154`).

**Design decisions locked this session (brainstorm):**
1. **Cascade behavior = optional/skippable** — each dropdown auto-shows "الكل / All" when it has no data and never blocks (needed because Model is only ~32% filled and 0 for some brands like Ford).
2. **طلمبة التوزيع / Distributor dropdown = pump part-type picker** — friendly label mapped to the real catalog leaves under المحرك → دورة الوقود والحقن: طلمبة جاز (52) · كارجة جاز (229) · هد طلمبة جاز (122) · رشاشات (160). NOTE: the literal word "توزيع" appears in **0** SKUs; this bridge is required.
3. **Placement = the section IS the two-direction fork** (part path + machine path together) — closes the previously-open fork gap.
4. **CTA = plain "اعرض النتائج"** button (no live count) → navigates to a pre-filtered PLP.

**Data feasibility (from `catalog/products.json`):** Brand missing on 27% (2,410); Model present on 32% (2,858), 0 for Ford; Systems well-populated (7 L1). This is why the cascade is optional-not-strict.

**Open / next:**
- [ ] Client review of the built fork section (copy, card balance, chip choices).
- [ ] Wire the dropdowns to real data: extend `build_catalog.py` to emit `catalog/cascade.json` (brand → models → systems → distributor-leaves + counts + label→leaves map).
- [ ] Design the pre-filtered PLP destination + result count.
- [ ] Mobile (500) version: stack the two paths vertically.

### 2026-07-09 — Client APPROVED design direction ✅ + new Cascade Search section
- Client approved the direction at Figma node **184-2145**: https://www.figma.com/design/6FRlQfPIncVUvNiJLn2kbT/Elassal-e-commerce?node-id=184-2145
- This is now the **locked baseline**.
- **Missing section to add: "Cascade Search"** — a guided fitment finder (dependent dropdowns) on the homepage: **الماركة (Brand) → الموديل (Model) → النظام (System) → طلمبة التوزيع / Distributor (extra dropdown)**. Each dropdown populates only valid options from the prior choice → filtered PLP.
- **"Distributor" = the distributor (injection) pump** (مضخة/طلمبة التوزيع), a flagship Diesel House product line (confirmed from `El Assal Company.pdf` product page + Diesel House box-artwork PDFs `15-5.pdf`, `20-10-10.pdf` … which are packaging designs). Client wants it as an **extra dropdown in the cascade** (decided 2026-07-09).
- Must follow the approved ElAssal style (tokens §3). Task in progress: study Figma style → design the cascade section in Figma (duplicate an approved section frame).

### 2026-06-17 — Catalog mapping + sitemap (DONE)
Built via `catalog/build_catalog.py` (re-runnable; client `SKUs.xlsx` untouched). Mapping: **7,976/8,908 mapped (89.5%)** — exact-leaf 4,320 · name-inferred 3,211 · alias 370 · node 75 · **uncategorized 932 (10.5%, listed for client review)**.
- [x] `catalog/categories.json` — 7→53→360 tree, slugs, parent ids, product counts (rollup includes non-leaf nodes).
- [x] `catalog/products.json` — 8,908 products mapped (l1/l2/l3 + match_method + search blob).
- [x] `catalog/mapping-report.md` — coverage %, brands(30), L1 counts, full 932 Uncategorized list.
- [x] `catalog/search-index.json` + `catalog/SEARCH-SPEC.md` — combined brand+product search (order-independent, AR normalization, brand synonyms) per feedback #1.
- [x] `catalog/navbar.json` + `SITEMAP.md` — grouped/prioritized dual-path nav per feedback #2.

L1 counts: المحرك 5,355 · الدبرياج/الفتيس 1,102 · بلي/أويل سيلات 574 · الكهرباء 382 · الفلاتر 243 · الهيدروليك 212 · جسم الجرار 86.

### Open / next
- [ ] Client to review the 932 Uncategorized (mostly ambiguous ترس/gears, kits) → confirm/assign.

---

## 9. Design Direction & Client Feedback (Homepage)

### Homepage design audit (2026-06-17)
Client reviewed the Figma homepage (file `6FRlQfPIncVUvNiJLn2kbT`). Verdict: usability loved, but it **reads as a generic template/UI-kit**. Root causes diagnosed:
1. **All-white canvas** — the #1 "template" tell. Wants a layered/industrial background, NOT flat white.
2. **Grid overuse** — page is one repeated `Title · عرض الكل · grid` module 4–5× ("مرصوصة"). Dislikes grids.
3. **Redundancy** — "منتجات مميزة" and "الأكثر مبيعاً" were the SAME 6 products.
4. **Section order** — two discovery paths torn apart; trust strip mid-page.
5. **Stock components** — generic product cards (no parts identity).
**New requirement:** **two directions** from the homepage = the dual-path fork *"do you know the part, or do you know your machine?"* (System/Part path + Brand/Machine path).
Fixes prescribed: dark+warm canvas, replace grids with editorial/rails, de-dup, re-sequence, add two-direction fork under hero, "Since 1973" credibility band, parts-identity product card (SKU mono + brand chip + stock).

### Homepage v2 (client's redesign, reviewed 2026-06-17)
Big improvement: atmospheric dark workshop hero (fixed white-bg complaint), product **rails** instead of grids (fixed grid complaint). Still missing: the **two-direction fork** and the **Shop-by-Part path** (only Shop-by-Brand present), parts-identity card, "Since 1973" band.

### Navigation = current focus (most important = homepage first section)
Reference adopted: **Bartar tri-pane mega menu** screenshot → System list (left) + Brands-in-that-system (middle) + Sub-type levels w/ thumbnails (right), shown together.
**Key validated flow:** user shops **System → Brand → Levels** (e.g. **المحرك → فيات → L2/L3**). This is the correct parts model (combined path, not either/or). Confirmed good by client.
Data added to support it: `build_catalog.py` now emits **per-system brand counts** on each `navbar.json` shop_by_part group (`groups[].brands[]`). E.g. المحرك → بركنز 1325, جوندير 640, فيات 627.

## 10. Live Prototypes (localhost)
Served from `catalog/` via `python3 -m http.server 8765` (stop: `lsof -ti:8765 | xargs kill`). GSAP-animated, RTL, ElAssal tokens, wired to real `navbar.json`/`products.json`.
- `catalog/index.html` — catalog + combined-search demo (stats, brand chips, mega).
- `catalog/nav.html` — **Navigation v1** (tabbed mega: part-tab OR brand-tab). Heavy navy/industrial bars.
- `catalog/nav-v2.html` — **Navigation v2 (Bartar tri-pane)** ⭐ — System→Brand+Levels combined; light/clean pill-nav look; brand-click shows «المحرك ← فيات ← اختر المستوى» breadcrumb. Add `#open` to URL to auto-open mega.
- All include GSAP open/close + stagger + hover-intent + sticky-shrink (v1) + search-focus.

### Decided / recommended
- **Adopt v2's interaction model** (tri-pane combined path) over v1's either/or tabs.
- **Open aesthetic choice:** keep v2's light Bartar skin vs reskin to ElAssal industrial palette (navy/yellow). Recommended: v2 logic + industrial palette.
- Right-pane thumbnails are placeholder icons → swap real part photos later.

### Open / next (design)
- [ ] Client: pick aesthetic (light Bartar vs industrial reskin) for the nav.
- [ ] Make brand-click in v2 actually filter the levels live (currently breadcrumb only).
- [ ] Build mobile drawer version of the nav.
- [ ] Then: hero + two-direction fork section below the nav.

---

## 11. Design System — Figma Variables (built 2026-08-17)

Figma file `6FRlQfPIncVUvNiJLn2kbT`, page **"Design System"**. Three collections, **170 variables** (71 Primitives · 71 Semantic · 28 Typography), **38 text styles** — counts current as of 2026-08-18. The component library on this page carries a `Direction=RTL|LTR` variant axis (38 sets, 1,272 variants).

> **Alpha tokens are mandatory for translucency.** `1. Primitives` carries an `alpha/*` group (`ink-20`, `yellow-40`, `white-8/10/15/20`) aliased by the semantic `bg/scrim`, `rating/star-partial`, `bg/on-media*`, `border/on-media`. This is not a stylistic choice: Figma **cannot** keep a paint's `opacity` once a colour variable is bound to it, so a translucent colour is only expressible as a token whose value carries the alpha. See §8 (2026-08-18 binder bug). Values derived from a 56,304-node audit of the Production page — see §3 for the canonical hex list.

### `1. Primitives` — 65 vars, VISIBLE and usable (revised 2026-08-17)

**Shades live here, not in Semantic.** A shade (`blue/300`) is a *value*; a semantic token (`action/primary`) is a *decision*. Roles don't come in shades, so the ramps belong in this layer by definition.

> ⚠️ **Reversal, same day.** These were first built hidden (`scopes = []`, invisible in every picker). karim pushed back: designers genuinely need a tint for a category tile, a hover wash, a chip background, and the 53 semantic roles will never cover every one-off. A shade you can't reach becomes a **pasted hex code**, which is strictly worse. So primitives are now visible and usable. The 5-step incomplete ramps were rebuilt as full ones at the same time.

**Five complete 12-step ramps — `0 · 50 · 100 · 200 · 300 · 400 · 500 · 600 · 700 · 800 · 900 · 1000`** (0 = lightest tint, 500 = the real brand value, 1000 = darkest shade):

| Ramp | 500 (base) | Notes |
|---|---|---|
| `blue/*` | `#063196` | **Brand primary** |
| `yellow/*` | `#EEBD1D` | Accent / commerce CTA. `50` = `#FFF6D9` callout, `200` = `#F8E5A5` star, `600` = `#E6A800` hover — all pinned real values |
| `neutral/*` | `#9E9DA6` | **Every one of the 12 steps is an observed real value** from the audit — nothing invented. `0` = white → `1000` = ink `#0C0A20` |
| `green/*` | `#049228` | Success / in-stock |
| `red/*` | `#D54033` | Sale price / danger |

Plus off-ramp exceptions: `support/blue-deep` `#044776` · `support/icon-ink` `#1A144F` · `brand/cat` · `brand/john-deere` · `brand/ford`.

**How the ramps were generated:** anchored on the real values and interpolated in HSL between anchors, then machine-checked for **monotonic lightness** — the first attempt failed this (yellow ran `#FFF6D9 → #EEE7CF → #F8E5A5`, light→dark→light, because pinned real values collided with the generated curve; red's tints drifted gray and read as neutral). The shipped ramps pass with **zero violations**.

**⚠️ The governance rule — semantic first.** Reach for a primitive only when no semantic role fits. **If you use the same primitive three times, promote it to a semantic token.** Otherwise consistency erodes and dark mode later breaks for anything wired to a raw shade.

**Contrast, for picking text colours** (against white / against ink `#0C0A20`):
- `neutral/700` 7.41 / 2.62 — safe body text on light
- `neutral/600` 5.16 / 3.77 — safe both ways
- `neutral/500` 2.68 / 7.25 — **fails on white**, disabled only
- `blue/500` 11.13 / 1.75 · `yellow/500` 1.76 / 11.05 — yellow needs ink on top, never white
- Rule of thumb: steps `0–300` are backgrounds, `600–1000` are text, `400–500` depend on the pairing.

### `2. Semantic` — 60 vars, mode "Light"
Role-named, every one an **alias** into Primitives. This is the layer designers and devs use.

| Group | Tokens |
|---|---|
| `bg/` | page · surface · surface-subtle · field · inverse · brand · brand-subtle · accent · accent-subtle · success-subtle · danger-subtle · **scrim** · **on-media** · **on-media-subtle** · **on-media-strong** |
| `text/` | primary · secondary · muted · disabled · inverse · brand · link · price · price-sale · price-was · success · on-brand · on-accent |
| `border/` | subtle · default · strong · focus · danger · **on-media** |
| `action/` | primary · primary-hover · primary-text · **primary-disabled** · accent · accent-hover · accent-text · secondary · secondary-border · secondary-text · disabled · disabled-text · **inverse** · **inverse-text** · **danger-subtle** · **danger-text** · **favorite** · **favorite-inactive** |
| `feedback/` | success · danger · warning · info |
| `icon/` | default · muted · inverse · brand |
| `rating/` | star-full · star-empty · **star-partial** |
| `brand/` | cat · john-deere · ford |

**The 7 canonical button configs** (verified across all 379 buttons on both platforms, 2026-08-18) — a button's fill and its glyph token always come as a pair, and a button must **never** carry a `bg/*`, `text/*` or `icon/*` token:

`action/primary`→`action/primary-text` · `bg/field`→`action/secondary-text` · `action/secondary`→`action/secondary-text` · `action/primary-disabled`→`action/disabled-text` · `action/danger-subtle`→`action/danger-text` · `feedback/success`→`action/primary-text` · `action/inverse`→`action/inverse-text`

⚠️ **`action/accent-text` is ink `#0c0a20`, never white** — white on `#eebd1d` fails contrast.

### `3. Typography` — 28 vars
- `font/family/` arabic (`thmanyah sans`) · latin (`Inter`) · numeric (`Inter Tight`) · mono (`JetBrains Mono`)
- `font/weight/` light · regular · medium · bold · black
- `size/` **2xs 7** · xs 10 · sm 12 · md 14 · base 16 · lg 18 · xl 20 · 2xl 24 · 3xl 28 · 4xl 32 · 5xl 40 · 6xl 60
  ↳ `2xs` exists only for `Label/Micro`, the 5×5 px cart-badge count digit. Do not use it for reading text.
- `line-height/` tight 120 · snug 130 · normal 140 · relaxed 150 · **loose 170** (Arabic body — needs generous leading)
- `letter-spacing/` tight −0.5 · normal 0 · wide 0.5

### Text Styles — 38

`Display/` Large·Medium·XLarge·Lead — `Heading/` H1·H2·H3·**H3 Medium**·H4·H5 — `Body/` Large·Base·Base Medium·Small·Small Medium·**XSmall** — `Label/` Large·Base·Small·Large Bold·**Micro** — `Price/` Large·Base·Small·Medium·XSmall — `SKU/` Base·Small (JetBrains Mono) — `Latin/` Body·**Body Medium**·**Body Large**·**Body Small**·Label·**Label Small**·Caption — `Numeric/` Base·**Small**·Display.
*(bold = added 2026-08-17 to close the Latin gap; the ramp was authored Arabic-first and the build uses Inter at 10/14/16 heavily.)*

**Every style is bound to the Typography variables on all four bindable fields** (fixed 2026-08-17 — an earlier pass had bound `fontSize` only, so the Figma Typography panel showed raw values with no variable chips):

| Field | Bound to | Coverage |
|---|---|---|
| `fontFamily` | `font/family/arabic` · `latin` · `numeric` · `mono` | 29/29 |
| `fontStyle` | `font/weight/regular` · `medium` · `bold` | 29/29 |
| `fontSize` | `size/*` | 29/29 |
| `letterSpacing` | `letter-spacing/tight` · `normal` · `wide` | 29/29 |
| `lineHeight` | **intentionally unbound** — see the px trap below | 0/29 |

Change `font/family/arabic` once and all 20 Arabic styles re-point; change `size/base` and every style using it updates.

> ⚠️ **`lineHeight` is deliberately NOT bound to a variable — do not "fix" this.**
> Figma reads a FLOAT variable bound to `lineHeight` as **PIXELS**, never percent. Binding `line-height/relaxed` (150) made every style **150 px** — catastrophic on a 10 px label. A percentage-based leading scale simply cannot be bound in Figma. Line heights are therefore explicit `PERCENT` values on each style, and the `line-height/*` variables remain as a **dev-facing token scale only**.
> Second trap: **assigning `lineHeight` while it is still bound is silently ignored.** Always `setBoundVariable('lineHeight', null)` *first*, then assign.

**Line heights are tuned to the real design, not to theory:** 150% body · 130% compact UI (labels, prices, SKU, Latin small) · 120–130% headings and display. The build's own AUTO leading resolves to ~127% (thmanyah), ~121% (Inter), 120% (Inter Tight), and 88 nodes were already explicit 150% — so these values apply as near-no-ops. *(An earlier draft used 170% for Arabic body; that was invented, not observed, and would have loosened every page by 15–40%.)*

> **Why a two-layer system:** re-theming means re-pointing one alias, not hunting hex codes across 56k nodes; and devs read intent (`action/primary`) instead of values (`#063196`).

---

> **How to use:** At the start of each session, open this file. Update Section 6 with progress and append new discoveries to the relevant section.
