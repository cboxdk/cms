<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Fields\FieldValues;

/**
 * A stored revision as RevisionContents gives it (PRD 4.1, 5.4): the schema version it was written
 * under, and its fields, or null when that version is not the type's, so the type's rules and field
 * definitions do not describe them.
 */
#[Internal]
final readonly class RevisionContent
{
    public function __construct(
        public int $schemaVersion,
        public ?FieldValues $fields,
    ) {}
}
