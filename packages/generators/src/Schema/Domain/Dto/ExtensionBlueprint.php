<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
use Cbox\Cms\Generators\Schema\Domain\TypeId;

/**
 * A file of `kind: extension`: fields that the owner of its schema root adds to another owner's
 * type, in the extender's namespace (PRD 11.12). The type is named by its id, so a rename by its
 * owner does not break the extension.
 */
#[Internal]
final readonly class ExtensionBlueprint
{
    /**
     * @param  int  $version  the extender's version of the definition, from 1 (PRD 11.12)
     * @param  list<FieldBlueprint>  $fields  in the order of the file
     */
    public function __construct(
        public TypeId $extends,
        public int $version,
        public array $fields,
        public Owner $owner,
        public SourceLocation $location,
    ) {}
}
