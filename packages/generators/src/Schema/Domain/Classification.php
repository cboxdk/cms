<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The data classification of a top-level field (PRD 12.2). Fields inside a group inherit the
 * classification of the group.
 */
#[Internal]
enum Classification: string
{
    case Public = 'public';

    case Internal = 'internal';

    case Confidential = 'confidential';

    case Personal = 'personal';

    case Sensitive = 'sensitive';
}
