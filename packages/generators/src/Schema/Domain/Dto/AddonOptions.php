<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\AddonFieldType;
use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
use Override;

/**
 * A field of a type an addon contributes (PRD 13.1). Its choices sit under `options` in the file,
 * and their meaning belongs to the addon, which checks them against the JSON Schema it contributes.
 * They are kept as canonical JSON: object keys sorted at every depth, lists in their order, no
 * escaped slashes or Unicode. Null when the file has no `options`.
 */
#[Internal]
final readonly class AddonOptions implements FieldOptions
{
    public function __construct(
        public AddonFieldType $type,
        public ?string $optionsJson,
    ) {}

    #[Override]
    public function typeName(): string
    {
        return $this->type->value;
    }
}
