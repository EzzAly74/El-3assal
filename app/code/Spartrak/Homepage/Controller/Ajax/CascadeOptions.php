<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Controller\Ajax;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Spartrak\Homepage\ViewModel\CascadeOptions as CascadeOptionsProvider;

/**
 * Supplies the next level of the cascading finder.
 *
 *     GET /spartrak-homepage/ajax/cascadeOptions?parent=<categoryId>
 *
 * ===========================================================================
 * WHY THIS ENDPOINT EXISTS RATHER THAN INLINING THE TREE
 * ===========================================================================
 * The finder's levels 3 and 4 depend on what the shopper picked above them.
 * The two alternatives were both worse:
 *
 *   - serialise the WHOLE category tree into the homepage: it would be paid
 *     for by every visitor, on the page whose LCP matters most, to serve a
 *     control most of them never touch;
 *   - leave the dependent selects static: that is the dead control the
 *     previous homepage shipped, and the brief explicitly rules out faking
 *     dynamic functionality.
 *
 * So the page ships level 1 only and this fetches the rest on demand.
 *
 * ===========================================================================
 * SAFETY
 * ===========================================================================
 * GET-only and strictly read-only, so there is no CSRF surface. It returns
 * nothing but category ids and names that are already public on the storefront
 * menu, and the provider filters to active + in-menu categories — so it cannot
 * be used to enumerate categories a shopper could not otherwise see.
 */
class CascadeOptions implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly CascadeOptionsProvider $provider
    ) {
    }

    public function execute(): Json
    {
        $parentId = (int) $this->request->getParam('parent');
        $result = $this->jsonFactory->create();

        if ($parentId <= 0) {
            return $result->setData(['options' => []]);
        }

        $options = $this->provider->getChildren($parentId);

        // Cacheable: the category tree changes rarely and the response carries
        // nothing customer-specific, so an intermediary may hold it. Kept
        // short so a merchandising change surfaces the same day.
        $result->setHeader('Cache-Control', 'public, max-age=300', true);

        return $result->setData(['options' => $options]);
    }
}
