# ElAssal / SpareTrak — Design Tokens (canonical)

These are the **live, canonical** values, mirrored from the Figma variable collections
(`1. Primitives` 71 · `2. Semantic` 71 · `3. Typography` 30 · 38 text styles).

> ⚠️ **Retired — never implement these:** `#FFC107`, `#1F3A93`, `#0A0E14`, and the **Tajawal** font.
> They appear in a handful of stale nodes and old screenshots. If you see them, the source is out of date.

## Colour

`#063196` **navy is the PRIMARY brand colour.** Yellow `#eebd1d` is the **accent / commerce CTA**
(add-to-cart, ratings) — not the primary.

| Role | Hex | Notes |
|---|---|---|
| Primary (navy) | `#063196` | Search button, primary actions |
| Primary hover | `#044776` | |
| Accent (yellow) | `#eebd1d` | Add-to-cart, ratings |
| Accent hover | `#E6A800` | |
| Soft-yellow callout | `#FFF6D9` | |
| Star (empty / half) | `#f8e5a5` | |
| Ink / primary text | `#0c0a20` | |
| Secondary text | `#555463` | 6.97:1 on page tint — AA |
| Muted text | `#6d6c79` | 4.86:1 on page tint — AA, only just |
| Disabled text | `#9e9da6` | 2.53:1 — **disabled only, never live text** |
| Border (default) | `#e4e3e5` | The most-used border by far |
| Border (strong) | `#ceced2` | |
| Field background | `#f3f3f4` | |
| Surface | `#ffffff` | subtle surface `#f9f9f9` |
| Page tint | `#f7f8fa` | |
| Success green | `#049228` | **not** `#16A34A` |
| Sale / danger red | `#d54033` | **not** `#DC2626` |
| Icon ink | `#1a144f` | |

### Brand chips (third-party marks — use as given, do not re-tint)
Caterpillar `#FFCD11` · John Deere `#367C2B` · Ford `#005CA9`

### NOT tokens — do not implement
`#d9d9d9` (icon bounding-box rects) · `#4a32e3` + `#f1effe` (third-party UI-kit residue)
· `#fce5e3` (illustration only) · `#ff5f00` / `#eb001b` / `#1434cb` (Visa / Mastercard logo artwork)

## Typography

| Family | Use |
|---|---|
| **thmanyah sans** (Light / Regular / Medium / Bold / Black) | **Arabic UI — the dominant font** |
| **Inter** | Latin text |
| **Inter Tight** | Numerals / prices |
| **JetBrains Mono** | SKUs / part numbers |

**Type scale:** 10 / 12 / 14 / 16 / 18 / 20 / 24 / 28 / 32 / 40
Real usage clusters on 16, 14, 12, 18, 10, 20. Any other size (13, 15, 17, 10.5 …) is drift — fold it into the scale.

**Numerals:** always rendered with the numeral token in **Latin digits**. Arabic-Indic digits (٠١٢٣) were
explicitly rejected by the client even in the Arabic locale.

## Radii

`2` / `4` / `6` / `10` / `16` / `20` + `pill`

## Third-party artwork — never re-colour or tokenize
Visa / Mastercard / Google / Meeza marks, country flags, stock illustration artwork, and `#d9d9d9`
image placeholders. **The SpareTrak/ElAssal logo is the exception** — it is a first-class brand asset.
