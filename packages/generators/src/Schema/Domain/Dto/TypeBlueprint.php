<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
use Cbox\Cms\Generators\Schema\Domain\TypeId;

/**
 * A file of `kind: type`: a type, its capabilities and its own fields, owned by the owner of the
 * schema root it lies in (PRD 11.2, 11.12).
 */
#[Internal]
final readonly class TypeBlueprint
{
    /**
     * @param  int  $version  the owner's version of the definition, from 1 (PRD 11.4)
     * @param  list<FieldBlueprint>  $fields  in the order of the file, which is the order of the form
     */
    public function __construct(
        public TypeId $typeId,
        public Handle $handle,
        public string $label,
        public ?string $description,
        public int $version,
        public Capabilities $capabilities,
        public array $fields,
        public Owner $owner,
        public SourceLocation $location,
    ) {}
}
