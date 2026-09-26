<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\CoreFieldType;
use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
use Override;

/**
 * A `group` field: nested fields, once or repeated (PRD 11.6). The nested fields have no
 * classification of their own.
 */
#[Internal]
final readonly class GroupOptions implements FieldOptions
{
    /**
     * @param  list<FieldBlueprint>  $fields  in the order of the file
     * @param  ?GroupRepeat  $repeat  null when the group occurs once
     */
    public function __construct(
        public array $fields,
        public ?GroupRepeat $repeat,
    ) {}

    #[Override]
    public function typeName(): string
    {
        return CoreFieldType::Group->value;
    }
}
