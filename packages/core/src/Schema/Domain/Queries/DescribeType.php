<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Schema\Domain\Queries;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Query as QueryName;
use Cbox\Cms\Contracts\Pipeline\ActorQuery;
use Cbox\Cms\Contracts\Schema\TypeName;

/**
 * The editor descriptor of one type of the installation (PRD 11.12, 14.1), version 1 of
 * type.describe: the type named `<owner>:<handle>`, as the TypeEditors contract has it, or nothing
 * when the installation has no such type. Every actor may run it without a permission
 * (ActorQuery), because it tells an actor only the shape of the type, and the pipeline leaves out
 * every field the actor may not read; the anonymous principal may not.
 */
#[QueryName('type.describe', version: 1)]
#[Experimental]
final readonly class DescribeType implements ActorQuery
{
    public function __construct(
        public TypeName $name,
    ) {}
}
