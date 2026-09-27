<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * Something that registers field types in the FieldTypeRegistry (GUARDRAILS 2.4): the core with
 * CoreFieldTypes, and every other contributor through the same interface.
 */
#[Internal]
interface FieldTypeContributor
{
    /**
     * @return list<FieldType>
     */
    public function fieldTypes(): array;
}
