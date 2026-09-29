<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Pipeline;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Results\ReadContent;

/**
 * A Result that carries content: the entries it read, each with its fields (PRD 6.2, 9.4, 12.2).
 *
 * The query pipeline reads contents() after the action returned, removes from each entry every
 * field classified above the principal's classification access, and every field the entry's type
 * does not declare, and hands the entries back through withContents(). The result the caller gets
 * is the one withContents() returns, so a field the principal may not read never leaves the
 * pipeline. The pipeline also attaches the content keys of the entries, `e-{entry}` and
 * `n-{node}`, to the answer, and writes the read audit where a field's classification requires it.
 *
 * withContents() gets the same entries, in the same order, with only their fields changed; it
 * returns a result of the same class that holds them in place of its own.
 */
#[Experimental]
interface ReadsContent extends Result
{
    /**
     * The entries the result holds, in its order.
     *
     * @return list<ReadContent>
     */
    public function contents(): array;

    /**
     * The same result with these entries in place of its own.
     *
     * @param  list<ReadContent>  $contents
     */
    public function withContents(array $contents): static;
}
