<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Hooks;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What a TransformHook answers: the field changes in the order the kernel applies them. A later
 * change of the same field wins.
 */
#[Experimental]
final readonly class FieldChanges
{
    /** @var list<FieldChange> */
    public array $changes;

    public function __construct(FieldChange ...$changes)
    {
        $this->changes = array_values($changes);
    }

    /**
     * The answer of a hook that changes nothing.
     */
    public static function none(): self
    {
        return new self;
    }

    public function isEmpty(): bool
    {
        return $this->changes === [];
    }
}
