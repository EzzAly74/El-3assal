# Static artwork for "الفئات الأكثر بحثا"

This folder holds the **static, theme-owned artwork** for the category-tiles
section. It is deliberately **not** dashboard-managed: the brief states that
the tile and reveal visuals are frontend assets, so there is no image field on
the category-pick row and the category's own Magento image is never read.

## Naming contract

Two slots per category. `ViewModel\CategoryTiles` looks for them in this
order and uses the first that exists:

| Slot | Preferred name | Fallback name |
|---|---|---|
| Tile photo (rendered 308×206) | `<url_key>-tile.webp` | `category-<id>-tile.webp` |
| Reveal visual (rendered 788×535) | `<url_key>-visual.webp` | `category-<id>-visual.webp` |

`.webp` is tried first, then `.png`, then `.jpg`. WebP is strongly preferred —
the exports below are 20–80 KB as WebP against 0.8–2 MB as PNG for the same
pixels.

`<id>` is the category ID shown in the dashboard's category picker, so the
fallback form can be used without a database lookup. `<url_key>` is preferred
where it is stable and ASCII.

A category with no matching file renders with an empty artwork slot. It does
**not** borrow another category's photograph — CLAUDE.md §3 forbids
substituting a visual asset, and a wrong photo is a worse failure than a
missing one.

## What is currently shipped

Exported from Figma node `595:15067` (file `6FRlQfPIncVUvNiJLn2kbT`) and
re-encoded to WebP at 2× rendered size:

| File | Figma node | Depicts |
|---|---|---|
| `engine-pistons-tile.webp` | `595:15092` | بساتم المحرك — engine pistons |
| `conrod-bushings-tile.webp` | `595:15100` | جلب ذراع التوصيل — con-rod bushings |
| `water-pump-visual.webp` | `595:15086` | طلمبة المايه — water pump |

## ⚠ BLOCKED / REQUIRES FIGMA ASSET

Two things are outstanding, and neither can be resolved from the design file
as it stands:

1. **These three files are not yet named after real categories.** The Figma
   frame shows one composed example, not a mapping from artwork to catalogue
   categories. Rename each file to the `url_key` (or `category-<id>`) of the
   category it actually depicts and it will appear immediately — no code
   change, no deploy beyond `setup:static-content:deploy`.

2. **Figma provides artwork for two tiles and one reveal visual only.** Every
   further category picked in the dashboard needs its own pair. Until the
   design supplies them, those tiles render without artwork rather than with a
   substitute.

The shared backdrop (`tiles-backdrop.webp`) and the decorative glow
(`tiles-glow.svg`) are not per-category and live in the theme, under
`web/images/homepage/`.
