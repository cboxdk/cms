<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;
use Throwable;

/**
 * A panel point's declaration, or a value it is made of, breaks the rules of the panel's extension
 * model: a name, page, id, translation key or release that does not have its form, or a
 * #[PanelPoint] whose kind, region, multiplicity, ownership and tightening props do not fit
 * together. The message says which rule and how to keep it.
 */
#[Experimental]
final class InvalidPanelPoint extends InvalidArgumentException
{
    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self($reason, 0, $previous);
    }
}
