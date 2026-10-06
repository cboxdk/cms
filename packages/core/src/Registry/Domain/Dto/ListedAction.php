<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Registry\Domain\ListedActionKind;

/**
 * An action the actor may run, as action.list gives it (PRD 13.2, 13.4): the name and contract
 * version of the command or query it handles, whether it is a command or a query, and the title
 * and description of the JSON Schema of that contract version, in English, which the panel shows
 * unless its catalogue has a text for the action. The name is the permission a role's permissions
 * hold to allow the action.
 */
#[Experimental]
final readonly class ListedAction
{
    public function __construct(
        public CommandName $name,
        public int $version,
        public ListedActionKind $kind,
        public string $title,
        public string $description,
    ) {}
}
