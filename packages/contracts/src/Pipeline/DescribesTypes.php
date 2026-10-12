<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Pipeline;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Editors\TypeEditor;

/**
 * A Result that carries type editor descriptors (PRD 6.2, 11.12, 12.2), such as the results of
 * type.list and type.describe.
 *
 * The query pipeline reads editors() after the action returned, gives each descriptor back as the
 * principal may see it (TypeEditor::visibleTo(): without the fields above the principal's
 * classification access and, for an agent's credential, without the fields the blueprint does
 * not open to agents, PRD 2.31) and hands them back through withEditors(). The result the caller
 * gets is the one withEditors() returns, so a field the principal may not read never leaves the
 * pipeline, not even as its label.
 *
 * withEditors() gets the same descriptors, in the same order, with only their fields changed; it
 * returns a result of the same class that holds them in place of its own.
 */
#[Experimental]
interface DescribesTypes extends Result
{
    /**
     * The descriptors the result holds, in its order.
     *
     * @return list<TypeEditor>
     */
    public function editors(): array;

    /**
     * The same result with these descriptors in place of its own.
     *
     * @param  list<TypeEditor>  $editors
     */
    public function withEditors(array $editors): static;
}
