# NON-NEGOTIABLE PROJECT PRIORITY

Performance is the #1 engineering priority.

The ultimate goal is a fast, smooth, stable, pixel-perfect e-commerce website.

When forced to choose between:

- convenience and performance → choose performance
- a shortcut and clean architecture → choose clean architecture
- Porto styling and the Spartrak Design System → choose Spartrak
- hardcoded content and proper Magento data architecture → choose Magento architecture
- unnecessary JavaScript and native browser/CSS behavior → choose native behavior

Claude's role on this project is not "frontend developer." Claude owns the full system — frontend, backend, admin, and database — end to end.

**Summary:**
Figma = shape. Design System = design language. Magento = data and business logic. Claude = responsible for wiring all four layers correctly. Performance is the first measure of success.

---

# 1. PLATFORM VERSION

**Magento 2.4.8.**

All module code, dependency choices, deprecated-API checks, and Composer constraints must target 2.4.8 specifically. Do not use APIs deprecated in or before 2.4.8. Do not assume behavior from earlier 2.4.x lines without verifying it still holds — check the 2.4.8 release notes/changelog when in doubt rather than relying on memory of older versions.

---

# 2. ARCHITECTURE & OWNERSHIP MODEL

The stack layers, in order:

```
Spartrak RTL
  ↓
Spartrak
  ↓
Porto (Smartwave)
  ↓
Magento/blank
```

Porto stays installed. It is a **fallback**, not the design authority.

**Never:**

- Remove Porto.
- Reparent Spartrak underneath a different base theme.
- Modify `vendor/`.
- Modify Magento core.

## Ownership rule

- **If Spartrak overrides a component:** the Spartrak implementation + Spartrak Design System is the source of truth for it. Full stop.
- **If Spartrak does not override a component:** Porto/Magento's native implementation is allowed to stand as-is.

**Never:**

- Customize Porto merely because it happens to exist in the codebase.
- Let Porto styling leak into or override the Spartrak Design System.
- Duplicate Porto functionality that already works.

Use Magento native functionality wherever possible. Use Porto functionality where it's already required and compatible. Replace Porto's _presentation_ only where Figma specifies a different UI — don't touch Porto components Figma doesn't cover.

## Before implementing any component

1. Inspect Figma.
2. Inspect the existing Spartrak Design System (tokens, existing components).
3. Inspect the existing Magento/Porto implementation.
4. Reuse existing components/tokens where possible — don't rebuild what already matches.
5. Implement the smallest correct solution.
6. Validate desktop.
7. Validate mobile.
8. Validate RTL.
9. Compare pixel-for-pixel against Figma.
10. Measure the performance impact.

Never proceed on assumption when Figma or the codebase can verify the answer directly.

---

# 3. FIGMA IS THE UI SOURCE OF TRUTH

Figma — together with `design_system.json` — is the absolute visual source of truth. Magento is the content/data source of truth. These two never blur into each other.

**For every image, icon, logo, illustration, banner, background, or visual asset:**

- Use the exact Figma-provided asset. Retrieve/export the real asset — never approximate it.
- Never invent, replace, approximate, or generate a substitute visual asset when a Figma asset exists.
- Never use a placeholder image "for now."
- Never swap in a different icon because it looks similar — visual identity is exact, not close-enough.
- Never fall back to generic stock assets.
- Never invent dimensions, colors, typography, or spacing values when they're determinable from Figma or `design_system.json` — pull the real token/value, don't eyeball it.
- Never invent visual effects (shadows, gradients, blur, corner radius, etc.) not specified in Figma.

**If an asset genuinely cannot be retrieved from Figma:**

Mark the implementation as `BLOCKED / REQUIRES_ASSET` and stop there for that piece — do not paper over the gap with an invented substitute, and do not proceed without explicit approval.

Never proceed with a substitute asset without explicit approval. If Figma access/tooling is temporarily unavailable, stop asset-dependent implementation rather than guessing — "Figma is unavailable, so I'll use a placeholder for now" is not an acceptable resolution.

**Precedence:**

- Figma + `design_system.json` → visual SSOT (what it looks like).
- Magento → content/data SSOT (what it says, what data drives it).

A component pulling from both must respect this split: structure/styling/assets trace back to Figma; dynamic values trace back to Magento (see Section 7).

## FIGMA VERIFICATION RULE

Do not implement from memory, screenshots alone, or assumptions when the actual Figma source is available.

For every major component:

1. Open the referenced Figma source.
2. Inspect the actual dimensions, spacing, typography, assets, variants, and responsive states.
3. Identify the exact Figma node/frame/component being implemented.
4. Implement from that source.
5. Re-check the rendered result against the same Figma reference.

If Figma and an existing implementation disagree, Figma wins for visual presentation unless doing so would violate an explicit Magento/business requirement.

## FIGMA ASSET OWNERSHIP

Figma assets must be treated as source assets, not visual references.

When implementing an asset:

- Retrieve the actual asset from Figma whenever possible.
- Preserve its intended visual appearance.
- Store it in the appropriate project location according to the asset's role.
- Do not recreate it with CSS/SVG/code if the actual Figma asset exists.
- Do not download an equivalent asset from the internet.
- Do not use AI-generated or developer-created replacements.
- Do not use placeholders.

If the exact asset cannot be retrieved, mark the affected implementation:

`BLOCKED / REQUIRES FIGMA ASSET`

and do not silently substitute another asset.

## PERFORMANCE + PIXEL ACCURACY

Performance and pixel accuracy are both non-negotiable.

When they appear to conflict:

1. First find a technically superior implementation that preserves both.
2. Do not silently change the Figma design for performance.
3. Do not sacrifice meaningful performance for developer convenience.
4. If a genuine technical conflict remains, document the tradeoff explicitly and choose the solution that preserves the intended user experience with the best measurable performance.

---

# 4. PERFORMANCE IS THE #1 PRIORITY

Performance is the highest project priority. It is not a phase, not a cleanup pass, and not negotiable against convenience or deadline pressure.

Product goal: **FAST + SMOOTH + RESPONSIVE + PIXEL-PERFECT.**

Performance always outranks developer convenience. If a choice makes implementation easier but the site slower, take the harder path.

Every implementation must actively optimize for:

- LCP (Largest Contentful Paint) — **top priority metric**
- INP (Interaction to Next Paint)
- CLS (Cumulative Layout Shift)
- TTFB (Time to First Byte)
- First Contentful Paint
- Total Blocking Time
- JavaScript execution cost
- CSS payload size
- Number of network requests
- Image weight
- Font loading strategy
- DOM complexity / node count
- Server response time
- Database and query performance
- Magento rendering performance (layout XML, block instantiation, template resolution)

## LCP protection checklist

For every page, identify the likely LCP element before writing code, and protect it from:

- Lazy loading when it sits above the fold
- Unnecessary JavaScript in its critical path
- Render-blocking CSS/JS
- Oversized or unoptimized images
- Unnecessary font dependencies
- Third-party scripts/tags loading before it
- Delayed or client-side-only rendering
- Layout shift caused by missing dimensions

**Rule of thumb:** if you can't say in one sentence what the LCP element is and why it's fast, the implementation isn't done.

Do not add a dependency unless its value clearly justifies its performance cost — state the tradeoff explicitly if one is added.

Do not add libraries for simple UI. Avoid `!important` and specificity hacks. Prefer clean component ownership over layers of overrides.

**Performance regressions are blockers.**

Before introducing a dependency, large asset, global script, global stylesheet, or expensive backend operation, assess its expected performance cost up front.

After significant changes, verify — don't assume:

- Network requests
- Transferred bytes
- Render-blocking resources
- LCP
- JS execution
- Layout shifts

Use Lighthouse, WebPageTest, or browser DevTools Performance/Network panels. Do not declare an optimization successful based on code inspection alone.

The final site must **feel** fast and smooth in real usage, not merely score well synthetically. Synthetic scores are a proxy, not the goal.

---

# 5. FULL-STACK OWNERSHIP

Claude owns both frontend and backend. Never treat backend work as out of scope.

If a requirement touches any of the following, implement the correct full-stack solution — not a partial one:

- Magento module
- PHP
- Database table / EAV attribute / extension attribute
- API / GraphQL / REST endpoint
- Admin configuration or Admin UI
- ACL
- Cron
- Indexer
- Cache configuration
- Repository / service layer
- Data model / collection
- Observer / plugin
- Layout XML / theme implementation

**Never:**

- Build a frontend workaround when the correct architecture requires backend functionality.
- Hardcode data that belongs in Magento.
- Fake dynamic functionality with static JSON or hardcoded arrays — even temporarily, even for a demo.

Use proper Magento architecture and conventions throughout. The deliverable is a complete, production-quality system — not a visual approximation of one.

---

# 6. DYNAMIC BANNERS — PROJECT-WIDE RULE

All banners are dynamic and must be manageable from Magento Admin. This applies project-wide, with no page-specific exceptions.

Every banner must support, where applicable:

- Desktop/Web asset
- Mobile asset
- Link
- Status (enabled/disabled)
- Sort order
- Store view
- Scheduling (from/to date)
- Relevant metadata (alt text, campaign tags, etc.)

**Desktop and mobile assets are always separate.** Never assume one image can simply be resized to serve both. The architecture must let the frontend serve the correct asset per viewport.

Performance rules for banners:

- Never load both desktop and mobile assets on one render.
- Never download a mobile asset on desktop, or vice versa when a dedicated mobile asset exists.
- Use `<picture>` / `srcset` or equivalent responsive image techniques.
- Above-the-fold hero/LCP banners get load priority (`fetchpriority="high"`, no lazy-load, preload if justified).
- Below-the-fold banners are lazy-loaded.
- Reserve image dimensions (width/height or aspect-ratio CSS) to prevent CLS.
- Optimize delivery (compression, format, CDN) without altering the visual result.

Admin manages **content**. Developers manage **presentation**. Admin must never need to touch frontend code to change banner content.

---

# 7. DYNAMIC CONTENT ARCHITECTURE

Strictly separate **content** from **presentation**.

**Magento Admin controls:**

- Images (desktop + mobile)
- Text/content where applicable
- Links
- Status
- Ordering
- Scheduling
- Store view
- Campaign/content metadata

**Spartrak (theme/frontend) controls:**

- Markup
- Layout
- Typography
- Spacing
- Colors
- Responsive behavior
- Component structure
- Interactions and animations
- Design system

Never let admin-managed content degrade into an arbitrary HTML/CSS dumping ground unless a requirement explicitly calls for it. Default to structured content models (attributes, blocks, WYSIWYG fields with defined schema) over free-form HTML fields.

---

# 8. BACKEND/DASHBOARD RULE

When Figma or business requirements introduce a dynamic feature requiring dashboard management, **do not fake it in the theme.** Build the full chain:

```
Admin UI
  ↓
Magento data model
  ↓
Repository/service layer
  ↓
Frontend data
  ↓
Spartrak component
```

If a custom module is required, build it properly using:

- Magento conventions
- Dependency injection
- Service contracts where appropriate
- Repositories where appropriate
- ACL
- Admin configuration
- Input validation
- Caching
- Indexing where appropriate
- Sound database design
- Store-view awareness
- Secure input handling (escaping, sanitization, CSRF protection)

**Never:**

- Put business logic inside `.phtml` templates.
- Query the database directly from templates.
- Use procedural shortcuts where Magento already provides the proper mechanism.

---

# 9. BEST-PRACTICES RULE

Always use production-quality engineering practices. No "hacky" implementations, ever — including ones intended as temporary.

**Do not:**

- Hardcode dynamic business data
- Duplicate business logic across components
- Query the database from templates
- Modify `vendor/` or Magento core
- Use `!important` as a structural fix
- Create CSS specificity wars
- Add unnecessary dependencies
- Create duplicate components or modules
- Copy large Porto/theme files unnecessarily
- Let temporary hacks become permanent
- Hide or silently swallow errors
- Suppress exceptions without understanding their cause
- Disable functionality blindly to "make it work"
- Introduce arbitrary magic values
- Use fake/static data where real Magento data is required

**Prefer:**

- Clean ownership boundaries
- Reusable components
- Proper abstractions
- Magento-native patterns
- Minimal code
- Measurable performance
- Maintainability
- Explicit dependencies
- Predictable, traceable data flow

When a shortcut conflicts with architecture or performance: **do not take the shortcut.** Flag the tradeoff instead and propose the correct path even if it takes longer.

---

# 10. LOADER — PROJECT-WIDE SYSTEM

The loading experience is a single, global Spartrak Design System component — not a page-specific one.

Replace Magento's default loading indicator wherever appropriate, consistently across the entire project.

The loader must:

- Use Spartrak Design System colors/tokens (primary/main theme color where appropriate)
- Support LTR and RTL layouts correctly
- Work on desktop and mobile
- Work with Magento AJAX operations, add-to-cart, minicart, checkout, and any Magento-driven loading state
- Preserve Magento's underlying functional loading behavior (don't just hide the default spinner — replace it cleanly)

Implementation constraints:

- Prefer a lightweight, pure-CSS implementation.
- Do not add an external library for this.
- Implement it **once**, at the appropriate global Magento/theme integration point, and reuse it everywhere.
- Do not create page-specific loaders. Do not create separate implementations for header, PDP, cart, checkout, AJAX, or any other individual surface — a new surface hooks into the existing global system.
- Never hardcode its color if a semantic design token already exists; use the token so theme color changes propagate automatically.
- Minimize JavaScript; the loader must not hurt LCP or introduce render-blocking resources.

---

# 11. IMAGE PERFORMANCE

Images are a major performance lever. Every image implementation must account for:

- Intrinsic dimensions and aspect ratio
- Responsive sizing (`srcset`/`sizes`)
- Separate desktop/mobile assets where applicable
- Loading priority
- Lazy loading (below fold only)
- `decoding` attribute
- Format (prefer modern formats like WebP/AVIF where supported)
- Compression
- CDN/cache behavior
- LCP impact
- CLS prevention

**Rules:**

- Never load an unnecessarily large image for the rendered size.
- Never lazy-load the primary LCP image just because "it's an image."
- Never preload every image — preload is a scarce resource, reserved for the LCP asset.
- Use `fetchpriority="high"` only where justified by actual LCP status.
- For banners: desktop asset ≠ mobile asset. Always respect the separate Figma-provided assets — never derive one from the other via CSS resize.

---

# 12. LCP-FIRST IMPLEMENTATION

For every page, follow this sequence:

1. Identify the likely LCP element.
2. Identify every resource required to render it.
3. Minimize dependencies that must resolve before it can paint.
4. Ensure the LCP asset has correct priority (preload/fetchpriority as warranted).
5. Avoid unnecessary global CSS/JS ahead of it in the critical path.
6. Avoid unnecessary font dependencies ahead of it.
7. Avoid layout shifts around it.
8. Measure the actual result — don't assume.

The first screen must become visually useful as fast as possible. Optimize the **critical rendering path**, not just total page weight or load time.

---

# 13. PERFORMANCE DECISION RULE

When choosing between two otherwise-valid implementations, prefer, in order:

1. Better LCP
2. Less JavaScript
3. Less CSS
4. Fewer network requests
5. Smaller assets
6. Less DOM complexity
7. Better caching
8. Better server-side performance
9. Less overall complexity
10. Better maintainability

Performance is an architecture-time decision, not a post-hoc optimization pass.

---

# 14. FULL-STACK IMPLEMENTATION MINDSET

Think in terms of the complete system, always:

```
Figma
  ↓
Design System
  ↓
Spartrak Components
  ↓
Magento Theme
  ↓
Magento Backend / Modules / Admin
  ↓
Database / Cache / Index / API
  ↓
Browser
```

If a requirement crosses multiple layers, **implement all required layers.** Do not stop at the layer currently visible on screen. The correct solution is the one that produces the cleanest, most complete system across every layer it touches.

---

# 15. ACCESSIBILITY (WCAG BASELINE)

Accessibility is a correctness requirement, not a polish item.

- All interactive elements must be keyboard-navigable with visible focus states.
- All images must have meaningful `alt` text (or `alt=""` for purely decorative images) — admin-managed banners must expose an alt-text field.
- Color contrast must meet WCAG AA at minimum.
- Semantic HTML first; ARIA only to fill genuine gaps, never as a substitute for correct markup.
- Forms must have properly associated labels and error messaging announced to assistive tech.
- Motion/animation must respect `prefers-reduced-motion`.

---

# 16. RTL / MULTI-LANGUAGE SUPPORT

The storefront serves both LTR and RTL locales (e.g. Arabic). This is not an edge case — treat every component as bidirectional from the start.

- Use logical CSS properties (`margin-inline-start`, `inset-inline-end`, etc.) instead of physical ones (`margin-left`, `right`) wherever feasible, rather than maintaining parallel RTL overrides.
- Verify icons, carousels, and directional UI (arrows, progress indicators) flip correctly in RTL.
- Verify all text-heavy components (banners, loader, admin-driven content) render correctly with Arabic text length/wrapping, not just placeholder Latin text.
- Store-view-aware content (Section 7) must resolve correctly per locale, not just per store code.

---

# 17. SECURITY BASELINE

- Sanitize and validate all input, both admin-side and customer-facing.
- Escape all output in templates (`$block->escapeHtml()`, `escapeUrl()`, etc.) — never raw-echo dynamic data.
- CSRF protection on all state-changing admin and frontend forms.
- No secrets, API keys, or credentials committed to the repo or hardcoded in code — use `env.php` / Magento's secure config store.
- Respect Magento ACL for all new admin resources.

---

# 18. GIT / CHANGE HYGIENE

- Commits scoped to a single logical change; no unrelated file churn bundled in.
- No commented-out dead code left in place "just in case."
- No debug output (`var_dump`, `console.log`, `die()`) left in committed code.
- Every new module/component gets a brief note on its purpose (README or module `composer.json` description) so intent isn't lost.

---

# 19. RE-READ PROTOCOL

Before starting any new task, treat this file as the permanent project contract.

Re-read the relevant sections before implementation. Re-check the **full** contract when the task crosses architecture, performance, backend, Figma, or ownership boundaries — not for every minor subtask.

Do not proceed based on assumptions or prior instructions if they conflict with this document.

From this point forward, this file is the authoritative source for: Figma as the UI SSOT, the Spartrak/Porto/Magento ownership hierarchy, performance-first architecture, LCP/Core Web Vitals, full-stack (frontend + backend + admin + DB) ownership, admin-managed responsive banners, the project-wide loader, and zero-hacks engineering practice.

If work already in progress complies with these rules, do not rebuild it from scratch on account of re-reading this file — audit it quickly against the rules above and continue.
