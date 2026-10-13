<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\ConditionRules;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\Owner;

/**
 * The field a condition names, as the blueprint writes it (gap G3): a handle of a sibling, `../`
 * once per group up before a handle of a sibling of a group the conditional field is in, or
 * `ext.<namespace>.<handle>`, a top-level field of an extender's namespace. ConditionRules holds
 * it to a field the condition can reach.
 *
 * @see ConditionRules
 */
#[Internal]
final readonly class ConditionReference
{
    /**
     * @param  int  $up  the groups to go up; 0 for a namespaced reference
     * @param  ?Owner  $namespace  the namespace of `ext.<namespace>.<handle>`, or null
     */
    public function __construct(
        public int $up,
        public ?Owner $namespace,
        public Handle $handle,
    ) {}

    /**
     * The reference as the blueprint writes it.
     */
    public function written(): string
    {
        return $this->namespace instanceof Owner
            ? 'ext.'.$this->namespace->value.'.'.$this->handle->value
            : str_repeat('../', $this->up).$this->handle->value;
    }
}
