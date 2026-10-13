<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Validation\ValidationReport;
use Cbox\Cms\Contracts\Validation\ValidationStage;

/**
 * Validates the fields of a revision a plan creates against its type's generated validator (PRD
 * 6.2 phase 5, 11.8, invariant 4): the type's rules in the schema version the code was generated
 * from, for the fields the revision's variant holds (PRD 10.1, TypeRules::forVariant()), at the
 * stage the write is for. It never throws for invalid fields; it returns every error, each with
 * its path below $at.
 */
#[Internal]
interface FieldValidation
{
    public function validate(TypeDefinition $type, VariantKey $variant, FieldValues $fields, ValidationStage $stage, FieldPath $at): ValidationReport;
}
