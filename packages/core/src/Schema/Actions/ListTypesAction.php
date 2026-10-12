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
use Cbox\Cms\Core\Schema\Domain\Dto\TypeList;
use Cbox\Cms\Core\Schema\Domain\Queries\ListTypes;
use Override;

/**
 * type.list (PRD 11.12, 14.1): the editor descriptor of every type of the installation, from the
 * TypeEditors the generated code binds, sorted by type name. It reads no table: the descriptors
 * are fixed for the life of the process, and the query pipeline leaves out of each the fields the
 * reader may not see (DescribesTypes). It costs one unit.
 *
 * @implements QueryAction<ListTypes, TypeList>
 */
#[Action(handles: ListTypes::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Mcp])]
#[Internal]
final readonly class ListTypesAction implements QueryAction
{
    /** What the read costs: the descriptors from memory. */
    public const int COST = 1;

    public function __construct(private TypeEditors $editors) {}

    #[Override]
    public function cost(Query $query): QueryCost
    {
        return new QueryCost(self::COST);
    }

    #[Override]
    public function handle(Query $query): TypeList
    {
        return new TypeList($this->editors->all());
    }
}
