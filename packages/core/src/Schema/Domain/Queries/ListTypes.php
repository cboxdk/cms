<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Schema\Domain\Queries;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Query as QueryName;
use Cbox\Cms\Contracts\Pipeline\ActorQuery;

/**
 * The editor descriptors of every type of the installation (PRD 11.12, 14.1), version 1 of
 * type.list: what the panel's forms render each type from, as the TypeEditors contract has them.
 * It carries nothing. Every actor may run it without a permission (ActorQuery), because it tells
 * an actor only the shape of the types, and the pipeline leaves out every field the actor may not
 * read; the anonymous principal may not.
 */
#[QueryName('type.list', version: 1)]
#[Experimental]
final readonly class ListTypes implements ActorQuery {}
