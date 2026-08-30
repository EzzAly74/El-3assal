<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Model;

use Spartrak\Locale\Model\StoreLanguage;

/**
 * @deprecated Use Spartrak\Locale\Model\StoreLanguage directly.
 *
 * The implementation MOVED to Spartrak_Locale when a second module
 * (Spartrak_PickupLocation) needed the same question answered. A fulfilment
 * module depending on the HOMEPAGE module to learn what language the store
 * speaks would be a dependency pointing the wrong way, and copying the class
 * would be exactly the duplication CLAUDE.md section 9 rules out.
 *
 * WHY THIS SUBCLASS EXISTS RATHER THAN EIGHT EDITED CALLERS
 * ===========================================================================
 * Eight working homepage files inject this type by name. Rewriting all eight
 * would be an untestable refactor of code that is already correct and already
 * shipped, for no behavioural gain. An empty subclass costs nothing at
 * runtime, changes no constructor signature (the parent's is inherited, so
 * every existing DI resolution is untouched) and leaves the convergence as a
 * mechanical find-and-replace whenever the homepage is next opened for a real
 * reason.
 *
 * Nothing new should reference this name.
 *
 * @see StoreLanguage
 */
class LocaleContext extends StoreLanguage
{
}
