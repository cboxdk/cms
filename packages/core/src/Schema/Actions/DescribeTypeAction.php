<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Schema\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Editors\TypeEditors;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Core\Schema\Domain\Dto\TypeDescription;
use Cbox\Cms\Core\Schema\Domain\Queries\DescribeType;
use Override;

/**
 * type.describe (PRD 11.12, 14.1): the editor descriptor of the type named, from the TypeEditors
 * the generated code binds, or null when the installation has no type of that name. It reads no
 * table: the descriptors are fixed for the life of the process, and the query pipeline leaves out
 * of the descriptor the fields the reader may not see (DescribesTypes). It costs one unit.
 *
 * @implements QueryAction<DescribeType, TypeDescription>
 */
#[Action(handles: DescribeType::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Mcp])]
#[Internal]
final readonly class DescribeTypeAction implements QueryAction
{
    /** What the read costs: one descriptor from memory. */
    public const int COST = 1;

    public function __construct(private TypeEditors $editors) {}

    #[Override]
    public function cost(Query $query): QueryCost
    {
        return new QueryCost(self::COST);
    }

    /**
     * @param  DescribeType  $query
     */
    #[Override]
    public function handle(Query $query): TypeDescription
    {
        return new TypeDescription($this->editors->named($query->name));
    }
}
