<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\NodePath;
use InvalidArgumentException;

/**
 * A grant that breaks its rules: an empty locale set, or one that names a locale twice.
 */
#[Internal]
final class InvalidGrant extends InvalidArgumentException
{
    public static function noLocales(NodePath $node): self
    {
        return new self(sprintf('The locale set of a grant on %s is null for every locale or names at least one, got none.', $node->value));
    }

    public static function repeatedLocale(NodePath $node, Locale $locale): self
    {
        return new self(sprintf('The locale set of a grant on %s names %s twice.', $node->value, $locale->value));
    }
}
