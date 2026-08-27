# ElAssal / SpareTrak — Figma map (visual source of truth)

**File:** `Elassal e-commerce` — fileKey **`6FRlQfPIncVUvNiJLn2kbT`**
`https://www.figma.com/design/6FRlQfPIncVUvNiJLn2kbT/`

> 🔑 You need to be **invited to this file** to open it. Ask karim if you get a 404.

## Pages that matter

| Page | What's on it |
|---|---|
| **Design System** | The token collections + the component library — **build components from here** |
| **Production** | The real screens: **50 desktop (1440) + 43 mobile (440)** frames |
| Design - Hi Fed | Earlier client-approved hi-fi pass (homepage + PDP). Historical — prefer Production |

## Token collections (Design System page)
`1. Primitives` (71, incl. an `alpha/*` group for translucency) → `2. Semantic` (71 role tokens)
→ `3. Typography` (30) + **38 text styles**.

**Consume semantic tokens, not primitives.** The values are mirrored in
[`design-tokens.md`](./design-tokens.md) — use that file when you just need the hex.

## Component library
**57 component sets · 1,308 variants.** 100% colour-bound to tokens, typography fully styled.
**37 sets are bilingual** via a `Direction=LTR|RTL` variant axis — the RTL variant is not a mirrored
copy, it has its own flipped auto-layout alignment. When you build a component, build both directions.

## Screen node IDs (verified)

| Screen | Node |
|---|---|
| Homepage (desktop, 1440×5543) | `595:14462` — **connected to the library**, 114 instances, 18 live `Card - Product` |
| Homepage (menu open) | `473:2531` |
| PDP | `526:21854` · alternates `519:6685` (بستم جون دير), `532:15187`, `534:9322`, `554:10145` |
| PLP | `491:4729` (تصفح المنتجات — طلمبة المياه) |
| Brand page | `499:4664` · `488:10810` (John Deere) |
| Cart | `538:6446` |
| Add-to-cart | `536:2336` |
| Footer | `624:23237` (2-variant set: `Version=Web` 1440×266 / `Version=Mobile`) |

Checkout, Login and Account exist on Production but were not indexed by node ID here — open the page.

## Key components

| Component | Node |
|---|---|
| `Card - Product` | `549:6113` |
| `Navbar` (20 variants, incl. `Type=Production`) | `549:5433` |
| `Product Image` (12 variants `img=1…12`, 260×260) | `747:39914` |
| Source product photos (12) | `746:39889` |
| SpareTrak logo | `735:32550` |
| Library container | `549:3868` |

## State of the design → code handoff

- **Homepage is fully connected** to the component library — library edits propagate to it. Verified.
- **Every other Production page is still detached frames.** They are 100% token-bound (0 raw colours),
  so the *values* you read off them are correct, but they are not library instances. Don't be surprised
  when a Cart button isn't an instance of the button component.
- `Product Image` is **not yet wired into `Card - Product`** — the card's image is still a rectangle
  with an image fill.

## Reading the file safely

If you inspect nodes programmatically:

- **Never read `fills[0]`.** Nodes routinely carry stacked paints; the visible image is the **topmost**
  fill, not the first. This mistake has produced wrong results three separate times on this file.
- Icon `#d9d9d9` rectangles are **bounding boxes**, not visible artwork.
- Some text still uses **Tajawal** (≈287 nodes) — that is legacy. The real font is **thmanyah sans**.
