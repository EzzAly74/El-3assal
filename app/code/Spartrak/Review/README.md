# Spartrak_Review

The data behind the PDP reviews panel, and the four places Magento's own review
flow does not do what this storefront's design asks for.

The **panel** (`جميع التقييمات`) and the **rating dialog** (`تقييم المنتج`) are
templates and live in the theme, with every other Figma-derived view:

```
app/design/frontend/Spartrak/spartrak/Magento_Review/
├── layout/catalog_product_view.xml   the view model, the cookie-notice
│                                     trigger, and the dialog's move to page
│                                     level
├── templates/review.phtml            the panel + the CTA
└── templates/form.phtml              the dialog

app/design/frontend/Spartrak/spartrak/
├── web/css/source/components/_reviews.less
└── web/js/spartrak-review-dialog.js
```

Figma (file `6FRlQfPIncVUvNiJLn2kbT`, read live 2026-09-04):
`535:10083` / `1204:27098` for the panel, `1207:30485` / `1204:27392` for the
dialog.

---

## What this module contains, and why each piece exists

### `Model\ResourceModel\RatingHistogram`

The distribution — *843 people said 5, 351 said 4* — which **no core table
holds**. Magento aggregates reviews two ways and neither is a histogram:
`review_entity_summary` is one average per product, and
`rating_option_vote_aggregated` is one row per rating dimension. So this is one
grouped read of `rating_option_vote`, joined through `review` and
`review_store` so only **approved** reviews **on this store view** are counted,
with every parameter bound.

It counts **votes, not reviews**. On a store with more than one rating dimension
one review casts one vote per dimension and therefore appears in more than one
bucket — deliberately, because the bars, the `تقييمات` count and the average are
all derived from this one result set and so always add up to each other.
Counting distinct reviews per bucket makes the bars sum to more than the review
count the moment a review splits its votes.

### `ViewModel\ProductReviews`

One read, and every number in the panel derived from it, so they cannot
disagree. The average is computed from the votes rather than read from
`review_entity_summary.rating_summary`, which is a percentage maintained on a
different schedule and drifts.

Bar widths are each value's **share of all votes**. Figma's own widths are
sample art and contradict themselves between breakpoints (the mobile frame
draws the 5-star and 4-star bars at an identical 240px for two different
counts), so the rule is taken from what the control means.

### `Plugin\Review\SupplyFieldsFigmaDoesNotCollect`

Figma's dialog collects three things: a star value, a comment, and a press.
Magento requires two more — `Review::validate()` rejects an empty `title` or
`nickname`, and both columns are `NOT NULL`. Rather than add fields the design
does not have, or fork core's validation, the two are **supplied from facts the
store already holds**, on the request, before the controller reads it:

| field | source |
|---|---|
| `nickname` | the signed-in customer's **first name** — this storefront authenticates by phone, so every account carries a real name |
| `title` | the **opening of the comment**, cut on a word boundary at 60 characters |

A **guest gets nothing** from this plugin, on purpose: a review with no
identity behind it is the review this store does not want, and inventing
"Guest" would put a fake name on a public page. The panel's CTA asks a guest to
sign in instead, through the storefront's existing auth modal.

The generated `title` is the one value with no natural source, and the
alternatives were worse: a constant prints the same word on every row of the
admin's review grid — the column a moderator scans — and an empty one fails
validation. Every character of it is the shopper's own. The Spartrak panel
prints no review titles, because Figma draws none, so `title` is used only in
the admin.

### `Plugin\Review\InvalidateProductPageCache`

The panel is server-rendered (Figma's panel is an aggregate, not a list, so
core's post-paint AJAX fetch is gone — one fewer request on the PDP). The cost
is that the numbers are baked into the full page cache, **and nothing in
Magento invalidates a PDP when a review is approved**: `Review` implements no
`IdentityInterface`, so `FlushCacheByTags` never sees it, and module-review's
`events.xml` carries a single observer, for product deletion.

So a moderator approved a review and the panel changed nothing until something
unrelated flushed the page. This plugs the resource model's `aggregate()` — the
one method every path goes through, including the delete path, which calls the
resource directly and would be missed by plugging the model — and dispatches
`clean_cache_by_tags` for that product's tag alone. The event, not a direct
cache call, because it is also what purges Varnish; the tag, not
`invalidate('full_page')`, because one shopper's review must not empty the
cache for 8,908 SKUs.

---

## Decisions that differ from Magento's defaults

| | |
|---|---|
| **The review LIST is not rendered** | Figma's panel draws no individual review anywhere — it is an average, two counts and a five-row distribution. Core's `#product-review-container` and `Magento_Review/js/process-reviews` are therefore gone, along with their request and the layout shift where the list used to arrive. |
| **The form is a dialog, not an inline form** | Figma 1207:30485. A native `<dialog>`, so the top layer, the `::backdrop` scrim, Escape, focus containment and page inertness are all the browser's. |
| **`nickname` and `title` are supplied server-side** | See above. |
| **Guests are sent to sign in** | `catalog/review/allow_guest` is respected — this is core's own `getAllowWriteReviewFlag()` test, asked through `Framework\App\Http\Context` so it stays correct under full-page cache. |
| **The dialog block is moved to `content`** | An inactive tab panel is `display: none`, and a `<dialog>` inside a `display:none` subtree cannot be shown. Moved in **layout**, not in JavaScript, so it cannot race Magento_ReCaptchaReview's own mount inside the same form. |
| **The `require-cookie` trigger is repointed** | Core watches `.review .action.submit`, class names this design does not use. Left alone, the cookies-disabled notice would silently stop working. |

## Documented gaps

| | |
|---|---|
| **No Figma frame for "no ratings yet"** | The file draws the panel only with data in it. The empty state is built from the panel's own measurements and tokens and introduces no new colour, spacing or shape. Marked as such in `review.phtml` and `_reviews.less`. |
| **Mobile draws the panel CTA twice** | `1204:27138` and `1204:27152` are identical in every respect — box, fill, label, node structure. Rendered once; the desktop frame is the one that agrees with itself. |
| **The two frames disagree on the CTA label** | `أترك تقييمك` on desktop, `أترك تعليقك` on mobile. The desktop wording is used at both widths, because the dialog's own primary action is already `اترك تعليقك`. |
| **A stray 4px dot in the dialog** | `1207:30518`, alone under the heading, named "Rating breakdown" — the name of the two-count row in the *panel*. Residue of a duplicated component; not rendered. |
| **More than one rating dimension** | Figma draws one row of five stars. Magento allows several. Each active dimension gets its own control, labelled with its own name; with exactly one — this store's configuration and what the design was drawn for — the result is the Figma frame unchanged. Nothing is silently dropped. |
