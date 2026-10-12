<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * How cms:sites:sync changes one locale of a registered site (PRD 11.14).
 */
#[Internal]
enum LocaleChangeKind: string
{
    /** The configuration names the locale and the site lacked it: site.add_locale. */
    case Added = 'added';

    /** The site published in the locale and the configuration no longer names it: site.remove_locale. */
    case Removed = 'removed';
}
