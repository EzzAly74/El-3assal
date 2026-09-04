# CONTEXT HANDOFF — SPARTRAK MAGENTO 2 PDP REVIEWS IMPLEMENTATION

Continue from this point. Do NOT restart the investigation or rethink decisions that have already been made unless you discover a concrete technical issue.

## Project Context

* Magento 2 storefront.
* Theme:

  * `app/design/frontend/Spartrak/spartrak`
  * RTL counterpart exists: `Spartrak/spartrak_rtl`
* Parent/fallback architecture includes Porto, but Spartrak owns the custom Figma implementation.
* Styling system: LESS.
* Technology: Magento 2 + PHTML + RequireJS/JavaScript where needed.
* Do NOT introduce React, Tailwind, or unnecessary dependencies.
* Follow existing project patterns and `CLAUDE.md`.
* Prefer existing design tokens and CSS logical properties for RTL/LTR compatibility.
* Figma is the visual source of truth.

---

# CURRENT TASK

Implement the redesigned **Product Reviews tab and review submission modal** from Figma.

The goal is to replace Magento's default PDP review presentation with the Figma design while preserving Magento's real review/rating backend functionality.

---

# WHAT HAS ALREADY BEEN INVESTIGATED

## Existing PDP Review Implementation

Existing PDP review styling and structure already exist in:

```text
app/design/frontend/Spartrak/spartrak/web/css/source/components/_pdp.less
```

Important existing areas:

* Existing PDP rating summary.
* Existing star rating meter.
* Existing tab styling.
* Existing Figma-based tab implementation.

The PDP tabs are already implemented and should NOT be rebuilt.

Existing tab implementation:

* `#tab-label-additional`
* `#tab-label-description`
* `#tab-label-reviews`

The tab row is already styled as three equal-width tabs.

The review tab order is already configured.

Existing review-related CSS begins around the PDP tabs/panels section.

---

# IMPORTANT EXISTING PDP TAB STATE

The existing tab container already matches the Figma design:

* Border.
* White surface.
* Three equal-width tabs.
* Active tab:

  * Primary brand color.
  * Primary underline.
  * Bold text.
* Inactive tab:

  * Primary text.
  * Strong border underline.
* Porto's mobile accordion chevrons were explicitly disabled.

Do NOT redo the tab system.

The missing part is the actual **reviews panel content**.

---

# FIGMA REVIEWS PANEL DECISION

The Figma review tab design shows:

1. Reviews overview title.
2. Aggregate rating information.
3. Rating histogram/bars.
4. Rating summary card.
5. CTA to leave a review.

It does NOT show the normal Magento individual review list.

Therefore:

* Remove/hide the default AJAX review list from this redesigned panel.
* Do NOT render the inline Magento review form inside the tab.
* The review form should instead open as a modal.

This is an intentional content/product decision based on the Figma design.

---

# CORE MAGENTO REVIEW ARCHITECTURE DISCOVERED

Core layout:

```text
vendor/magento/module-review/view/frontend/layout/catalog_product_view.xml
```

The reviews tab block is:

```xml
<block
    class="Magento\Review\Block\Product\Review"
    name="reviews.tab"
    as="reviews"
    template="Magento_Review::review.phtml"
    group="detailed_info"
>
```

The review form is:

```text
product.review.form
```

Core `review.phtml` contains:

```html
<div id="product-review-container" data-role="product-review"></div>
```

and initializes:

```text
Magento_Review/js/process-reviews
```

which asynchronously loads the default review list.

The redesigned implementation should override this behavior with a custom panel.

---

# FIGMA REVIEW MODAL

Figma node:

```text
1207:30485
```

Overlay:

```text
1207:28688
```

Modal dimensions:

```text
400 × 574 px
```

Desktop Figma frame:

```text
1440 × 1024
```

Modal structure:

## Outer modal

* White/surface background.
* Width: 400px.
* Padding: 24px.
* Subtle shadow.
* Column layout.
* Internal gap: 24px.

Shadow token:

```text
0 3px 8px rgba(0,0,0,0.05)
```

---

## Header

Contains:

* Close button.
* Title:

```text
تقييم المنتج
```

RTL:

* Title visually on the right.
* Close button visually on the left.

Close button:

* 32 × 32px container.
* Circular.
* Field background.
* 20px icon.

---

## Rating Summary

Dimensions:

```text
352px wide
180px high
```

Style:

* White background.
* 1px default border.
* Padding:

  * Horizontal: 48px.
  * Vertical: 16px.
* Column layout.
* Centered.
* Gap: 20px.

Contains five interactive stars.

Star size:

```text
48 × 45.8417px
```

Gap between stars:

```text
20px
```

Prompt:

```text
ما تقييمك للمنتج؟
```

Typography:

* Arabic font.
* Bold.
* 24px.
* Line-height approximately 1.3.

The Figma node contains a tiny 4px ellipse artifact near the rating details. Ignore it; do NOT render it.

---

# COMMENT FIELD

Label:

```text
تعليقك علي المنتج
```

Textarea/input area:

```text
352px wide
91px high
```

Style:

* White background.
* Default border.
* Padding:

  * Inline start: 12px.
  * Inline end: 16px.
  * Block: 8px.
* Input shadow:

```text
0 1px 1.5px rgba(44,54,53,0.03)
```

Figma sample text:

```text
المنتج وصل كما هو في الوصف بدون أي نواقص
```

This is design/sample content, not necessarily the production default.

Use an empty textarea or appropriate placeholder behavior unless the existing design system dictates otherwise.

---

# BUTTONS

Two full-width stacked buttons.

Each:

```text
352 × 48px
```

Gap:

```text
12px
```

Primary:

```text
اترك تعليقك
```

Background:

```text
var(--action-primary)
```

Known primary value from design:

```text
#063196
```

Text: white.

Secondary:

```text
متابعة التسوق
```

Background:

```text
var(--bg-field)
```

Known design value:

```text
#f3f3f4
```

Text:

```text
var(--action-secondary-text)
```

Known primary text:

```text
#0C0A20
```

---

# FIGMA ASSETS

The modal's 48px star is NOT the same as the existing theme `star.svg`.

Existing theme star:

```text
app/design/frontend/Spartrak/spartrak/web/images/icons/star.svg
```

This is already used as a repeating mask for the PDP rating meter.

Do NOT replace it.

The modal star is a different asset:

* 48 × 45.8417.
* Built from two half-star paths.
* Gold color:

```text
#EEBD1D
```

Two variants were compared:

* Full star.
* Partially transparent variant.

The difference was opacity/fill behavior.

Create/use a dedicated asset for the modal, for example:

```text
web/images/icons/star-48.svg
```

The Figma close icon is slightly different from the existing theme close icon.

Existing:

```text
web/images/icons/close.svg
```

Figma difference is minor stroke inconsistency.

Reusing the existing close asset was considered acceptable unless visual verification shows a noticeable mismatch.

---

# MAGENTO REVIEW BACKEND FINDINGS

Core review schema:

```text
review_detail
```

Important required fields:

```text
title     NOT NULL
detail    NOT NULL
nickname  NOT NULL
```

Magento `Review::validate()` explicitly requires:

* title
* nickname
* detail

Therefore the Figma form cannot simply submit only stars + comment without backend support.

---

# BUSINESS / IMPLEMENTATION DECISION FOR MISSING FIELDS

The Figma design does not contain:

* Review title.
* Nickname.

Decision:

## Nickname

For logged-in users:

Use the customer's first name as the nickname.

For guests:

Do not show the actual review form.

The review CTA should trigger the existing login/authentication flow.

This fits the storefront's phone-auth/account model.

## Title

Magento requires a review title.

Since Figma does not provide a title field:

Generate it server-side from the beginning of the review comment.

Rules proposed:

* Take the first words of the comment.
* Cap around 60 characters.
* Prefer ending at a word boundary.
* Do not fabricate unrelated content.

This is intentional and should be documented.

---

# BACKEND IMPLEMENTATION DECISION

Use a plugin before the core review POST controller executes.

The plugin should populate missing request values BEFORE Magento validation runs.

Responsibilities:

* Populate `nickname` from the logged-in customer's first name.
* Populate `title` from the review detail/comment.

Preferred approach:

```text
beforeExecute plugin
```

on the review POST controller.

Modify request POST values before core validation reads them.

Do NOT plugin `Review::validate()` unless there is a concrete reason.

---

# RATING HISTOGRAM / AGGREGATION

The reviews overview needs:

* Average rating.
* Approved review count.
* Rating buckets/histogram for 1–5 stars.

Important schema findings:

`rating_option_vote` contains:

```text
value
percent
rating_id
review_id
option_id
entity_pk_value
```

The histogram can be built by joining:

* `rating_option_vote`
* `rating_option`
* `review`
* `review_store`

Filter by:

* Current product.
* Current store.
* Approved reviews only.

Use proper bound parameters.

Do NOT interpolate raw SQL values.

---

# IMPORTANT HISTOGRAM DECISION

A Magento review may potentially have multiple rating dimensions.

Therefore raw votes may not equal distinct reviews.

The current pragmatic decision was:

* Count rating votes rather than trying to artificially deduplicate across multiple rating dimensions.
* Document this behavior.
* Keep the histogram internally consistent with the rating system.

However, before finalizing, verify how this specific Magento store configures rating dimensions.

Do not introduce unnecessary complexity if only one rating dimension is active.

---

# RESOURCE MODEL PLAN

Create a dedicated resource model responsible for review aggregate data.

Prefer:

1. One grouped query for rating buckets.
2. One scalar query for approved review count.

The view model should consume this data.

The average rating may be pulled from the product rating summary when available.

Ensure store context is correct.

---

# PROPOSED MODULE

Create:

```text
Spartrak_Review
```

Expected responsibilities:

* Review aggregate resource model.
* PDP review view model.
* Controller plugin for nickname/title.
* Dependency injection configuration.
* Any supporting configuration.
* Translation files.
* README documenting the architectural decisions.

Possible structure:

```text
app/code/Spartrak/Review/
├── registration.php
├── etc/module.xml
├── etc/frontend/di.xml
├── Model/ResourceModel/
├── ViewModel/
├── Plugin/
├── i18n/
└── README.md
```

Use the project's existing module conventions if they differ.

---

# THEME LAYER PLAN

Add theme-level implementation for:

1. Layout XML override.
2. Custom reviews overview panel template.
3. Custom review modal/form template.
4. LESS styling.
5. JavaScript only where necessary for modal/star interaction.

Likely theme files should live under:

```text
app/design/frontend/Spartrak/spartrak/Magento_Review/
```

Potential files:

```text
layout/catalog_product_view.xml
templates/review.phtml
templates/form.phtml
```

Exact naming can follow Magento conventions.

---

# REVIEW FORM IMPLEMENTATION

Do NOT use React.

Do NOT use Tailwind.

Do NOT introduce a new dependency.

Use Magento-compatible PHTML.

The simplified form should preserve real Magento backend requirements:

* Correct action URL.
* Form key.
* Real rating option IDs.
* Real rating field names.
* Comment/detail field.

The UI should visually contain only:

1. Five stars.
2. Comment textarea.
3. Submit button.
4. Continue shopping button.

Title and nickname are injected server-side.

---

# FORM SUBMISSION APPROACH

Preferred approach currently:

Use a plain HTML POST form rather than Magento's full Knockout review form UI.

Reasons:

* Figma has only a few controls.
* Existing core controller can handle POST submission.
* Native required validation may be sufficient.
* Simpler and more aligned with existing custom dialogs in this project.

However:

Before implementing, verify that the core controller receives the exact expected POST structure.

Do not accidentally break:

* CSRF/form key.
* Rating option submission.
* Review detail submission.
* Redirect/messages.
* Customer association.

---

# STAR INPUT IMPLEMENTATION

Use actual Magento rating option IDs.

The five Figma stars should correspond to real rating choices.

A clean implementation can use:

* Radio inputs.
* Labels styled as the Figma stars.
* JS/CSS for selected/hover state if needed.

Do not fake rating values that the backend cannot understand.

The actual rating input name must match Magento expectations.

---

# GUEST BEHAVIOR

If user is not authenticated:

* CTA should not open a form that cannot provide the required customer identity.
* Trigger the existing login/auth flow.

Do not create a duplicate authentication system.

Reuse existing storefront login modal behavior.

---

# WHAT MUST NOT BE REDONE

Do NOT:

* Rebuild the PDP tabs.
* Rebuild the existing PDP rating meter.
* Introduce Tailwind.
* Introduce React.
* Replace the existing global design system.
* Change the existing `star.svg` used by the PDP rating meter.
* Reintroduce Magento's default inline review form.
* Reintroduce the AJAX individual review list in this Figma panel.
* Ignore Magento-required title/nickname validation.

---

# CURRENT EXACT STOPPING POINT

The investigation is complete.

The implementation plan is decided.

The next step is:

## START WRITING THE IMPLEMENTATION

Proceed in this order:

1. Inspect existing project module conventions briefly.
2. Create `Spartrak_Review`.
3. Implement aggregate rating resource model.
4. Implement view model.
5. Implement request/controller plugin for generated title + nickname.
6. Configure DI.
7. Override the PDP reviews tab template.
8. Build the Figma reviews overview panel.
9. Build the modal review form.
10. Wire real Magento rating option IDs into the five stars.
11. Add modal behavior and guest login behavior.
12. Add LESS using existing Spartrak design tokens.
13. Deploy/compile.
14. Verify on actual Arabic RTL storefront.
15. Verify English/LTR does not break.
16. Test:

    * No reviews.
    * Existing approved reviews.
    * Logged-in user submits review.
    * Guest CTA.
    * Star selection.
    * Comment required validation.
    * Generated title.
    * Generated nickname.
    * Review approval flow.
17. Document anything intentionally different from Magento's default behavior.

---

# IMPLEMENTATION QUALITY REQUIREMENTS

Approach this as production Magento code.

Priorities:

* Preserve Magento backend integrity.
* No raw SQL interpolation.
* Correct store filtering.
* Correct approved-review filtering.
* RTL/LTR-safe CSS using logical properties.
* Reuse existing Spartrak tokens/components where appropriate.
* Minimal JS.
* No unnecessary framework/dependency additions.
* Pixel-accurate Figma implementation.
* Keep business logic in the module and visual implementation in the theme where appropriate.
* Do not claim completion until actual storefront verification has been performed.

Continue directly from here and start implementing.
