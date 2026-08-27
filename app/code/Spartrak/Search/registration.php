<?php
/**
 * Spartrak_Search — the storefront search-suggestions panel.
 *
 * Figma (desktop node 864:8879, mobile node 647:53929) specifies a suggestions
 * panel that shows THREE things at once: a result count with a "view all"
 * link, a horizontal rail of matching PRODUCTS, and a list of query
 * suggestions. Magento's own `search/ajax/suggest` endpoint returns only the
 * third of those — Autocomplete items ({title, num_results}) — so the panel
 * cannot be built on it without inventing the product data, which CLAUDE.md
 * §5 forbids outright.
 *
 * This module supplies the missing half: one endpoint that runs a real
 * catalogsearch query and returns the count, the top products and the
 * autocomplete terms together, rendered as HTML by a themeable template.
 *
 * It lives in app/code rather than in the theme because it is PHP — a
 * controller, a service and a block. The theme owns the panel's appearance
 * (web/css/source/components/_search-suggest.less) and its behaviour
 * (web/js/spartrak-search-suggest.js); this module owns only the data.
 */

\Magento\Framework\Component\ComponentRegistrar::register(
    \Magento\Framework\Component\ComponentRegistrar::MODULE,
    'Spartrak_Search',
    __DIR__
);
