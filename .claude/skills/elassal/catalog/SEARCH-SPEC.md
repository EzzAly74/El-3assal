# ElAssal e-commerce — Search Spec

> Implements client feedback (2026-06-17): **"I need to search by typing the brand
> name with the product together."** The user must be able to type the part and the
> brand in one box, in any order, in Arabic or English.

## Goal
A single search box where queries like these all return the right products:

| Query | Resolves to |
|---|---|
| `بستم فيات` | piston · brand Fiat |
| `فيات بستم` | same (order-independent) |
| `فيات بستم 100` | piston · Fiat · size 100 |
| `water pump perkins` | طلمبة مياة · Perkins |
| `perkins فلتر زيت` | oil filter · Perkins (mixed AR/EN) |

## How it works

### 1. Per-product search blob (precomputed)
Each product in `products.json` / `search-index.json` carries a `search` field — a
normalized, deduped token bag built from:
`name + item_type + matched category + model + brand + all brand synonyms`.

Because the brand (and its English/variant synonyms) is folded into every product's
blob, "brand + part in one query" works without any special query parsing.

### 2. Query matching
1. Normalize the query (same rules as below).
2. Tokenize on whitespace.
3. **AND-match**: every query token must appear (prefix match) in the product's
   `search` blob. Order does not matter.
4. Rank results (suggested): exact item-type match > brand match > token coverage >
   in-stock > popularity.

This is engine-agnostic — wire `search` into client-side Fuse.js, a SQL
`LIKE`/FTS index, Typesense/Meilisearch, or Elastic. The blob + normalization is
the contract.

## Arabic normalization rules (applied to BOTH index and query)
| Rule | Transform |
|---|---|
| Arabic-Indic & Persian digits | `٠..٩ / ۰..۹` → `0..9` |
| Tashkeel (harakat) + tatweel `ـ` | stripped |
| Alef variants | `أ إ آ` → `ا` |
| Taa marbuta | `ة` → `ه` |
| Alef maqsura | `ى` → `ي` |
| Hamza seats | `ؤ` → `و`, `ئ` → `ي` |
| Slashes `/ \` | treated as space |
| Latin | lowercased |
| Whitespace | collapsed |

## Brand synonym map
Stored in `search-index.json → brand_synonyms`. Each Arabic brand maps to its
English name and common spellings, e.g.:

- `جوندير` → `john deere`, `جون دير`, `دير`
- `بركنز` → `perkins`, `بيركنز`
- `فيات` → `fiat`, `fiatagri`, `فيات اجري`
- `نيوهولند` → `new holland`, `newholland`
- `دويتس` → `deutz`

Extend this map as new brand spellings appear in support/search logs.

## Notes / future
- **Size & model tokens** (e.g. `100`, `1013`, `STD`) are already in the blob, so
  numeric refinements work as extra AND tokens.
- Consider a typo-tolerant engine (Meilisearch/Typesense) for fuzzy Arabic input.
- Synonyms for **part types** (e.g. `طرمبة` vs `طلمبة`, `كاربراتير`) can be added the
  same way if search logs show misses.
