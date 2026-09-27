<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\Classification;
use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;

/**
 * A field of a type, an extension or a group, with the choices that every field has and the
 * options of its field type. A field's identity is its owner and its handle (PRD 11.2, 11.12).
 *
 * A top-level field has a classification (PRD 12.2); a field inside a group has none and inherits
 * the group's. The values the blueprint file leaves out have their defaults from the blueprint
 * schema v1: not required, not filterable, not sortable. Whether agents see a field the file does
 * not decide for follows its classification: a top-level field only when it is public
 * (Classification::seenByAgentsByDefault()), and a field inside a group when agents see the group.
 * Agents never see a sensitive field, nor a field inside a sensitive group.
 */
#[Internal]
final readonly class FieldBlueprint
{
    public const bool DEFAULT_REQUIRED = false;

    public const bool DEFAULT_FILTERABLE = false;

    public const bool DEFAULT_SORTABLE = false;

    /**
     * @param  ?string  $description  what the field holds; always present when agents see the field (PRD 14.5)
     * @param  bool  $agents  whether MCP tools and agents see the field
     */
    public function __construct(
        public Handle $handle,
        public string $label,
        public ?string $description,
        public bool $required,
        public ?Classification $classification,
        public bool $filterable,
        public bool $sortable,
        public bool $agents,
        public FieldOptions $options,
        public Owner $owner,
        public SourceLocation $location,
    ) {}
}
