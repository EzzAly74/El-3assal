<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Wishlist\Model;

/**
 * What one press of the heart did.
 *
 * A value object rather than an array so the controller cannot misspell a key
 * and so the two facts it carries stay together: WHICH product, and whether it
 * is now on the list or off it. Both are needed by the caller - the product id
 * to answer the browser with, `added` to decide which message to raise - and
 * neither is derivable from the other.
 */
final class ToggleResult
{
    public function __construct(
        public readonly int $productId,
        public readonly bool $added,
        public readonly string $productName
    ) {
    }
}
