<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\Scope;
use Cbox\Cms\Contracts\Schema\TypeName;

/**
 * What a panel page is about, which a contribution's Scope can narrow to (PRD 13.4): the command
 * whose form it shows, the content type it shows, and the field types on it, each when the page
 * has one. A page about none of them gets no contribution whose scope names commands, types or
 * field types.
 */
#[Experimental]
final readonly class ViewSubject
{
    /** @var list<string> */
    public array $fieldTypes;

    /**
     * @param  list<string>  $fieldTypes  core field types such as "text", or an addon's `<namespace>:<handle>`
     */
    public function __construct(
        public ?CommandRef $command = null,
        public ?TypeName $type = null,
        array $fieldTypes = [],
    ) {
        $this->fieldTypes = array_values(array_unique($fieldTypes));
    }

    /**
     * Whether the scope lets a contribution apply to this subject: each list the scope narrows
     * by names what the page is about. The scope's pages and its required permission are decided
     * elsewhere.
     */
    public function fits(Scope $scope): bool
    {
        $command = $this->command?->toString();

        return ($scope->commands === [] || ($command !== null && array_any($scope->commands, static fn (CommandRef $named): bool => $named->toString() === $command)))
            && ($scope->types === [] || ($this->type instanceof TypeName && array_any($scope->types, fn (TypeName $named): bool => $named->value === $this->type->value)))
            && ($scope->fieldTypes === [] || array_intersect($scope->fieldTypes, $this->fieldTypes) !== []);
    }
}
