<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\PanelTypes\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\PanelTypes\Domain\ShapeKind;

/**
 * The structure of one place in a contract's JSON Schema, as cms:panel:types writes its TypeScript
 * type (PRD 13.4): its kind; for an object its members and the schema of every other member, or
 * none when it takes no other (`additionalProperties: false`); for an array its items; for a
 * literal its TypeScript text, such as 'draft' or 3; for a reference the definition's key in
 * `$defs`; for a union or an intersection its branches; and its description. A keyword that only
 * narrows the values, such as `pattern`, `minimum` or `if`, has no TypeScript form and is left to
 * the codec that reads the document.
 */
#[Internal]
final readonly class JsonShape
{
    /**
     * @param  list<ShapeProperty>  $properties
     * @param  list<JsonShape>  $members
     */
    public function __construct(
        public ShapeKind $kind,
        public array $properties = [],
        public ?JsonShape $additional = null,
        public bool $closed = false,
        public ?JsonShape $items = null,
        public ?string $literal = null,
        public ?string $reference = null,
        public array $members = [],
        public ?string $description = null,
    ) {}
}
