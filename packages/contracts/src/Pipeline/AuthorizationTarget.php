<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Pipeline;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\NodeId;

/**
 * A node a command's permission is checked on (PRD 5.10), and the locale it acts in: null when it
 * acts in no one locale, such as a change of the shared variant. A grant that holds only in other
 * locales than the target's does not allow the command there.
 */
#[Experimental]
final readonly class AuthorizationTarget
{
    public function __construct(
        public NodeId $node,
        public ?Locale $locale = null,
    ) {}
}
