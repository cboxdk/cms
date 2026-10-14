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
use Cbox\Cms\Core\Pipeline\Domain\Dto\LinkReference;
use Cbox\Cms\Core\Pipeline\Domain\Dto\RelationReference;

/**
 * Validates the fields of a revision a plan creates against its type's generated validator (PRD
 * 6.2 phase 5, 11.8, invariant 4): the type's rules in the schema version the code was generated
 * from, for the fields the revision's variant holds (PRD 10.1, TypeRules::forVariant()), at the
 * stage the write is for. It never throws for invalid fields; it returns every error, each with
 * its path below $at.
 *
 * references() gives the targets of the revision's relation fields (RelationReferences), each with
 * the types its field allows, and of its link fields, each an entry or a node, which the pipeline
 * then reads through RelationTargets (PRD 5.11).
 */
#[Internal]
interface FieldValidation
{
    public function validate(TypeDefinition $type, VariantKey $variant, FieldValues $fields, ValidationStage $stage, FieldPath $at): ValidationReport;

    /**
     * @return list<RelationReference|LinkReference>
     */
    public function references(TypeDefinition $type, VariantKey $variant, FieldValues $fields, FieldPath $at): array;
}
