<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Validation\TypeValidator;
use Cbox\Cms\Contracts\Validation\TypeValidators;
use Cbox\Cms\Contracts\Validation\ValidationReport;
use Cbox\Cms\Contracts\Validation\ValidationStage;
use Cbox\Cms\Core\Pipeline\Domain\FieldValidation;
use Cbox\Cms\Core\Validation\Boundary\InputValidator;
use LogicException;
use Override;

/**
 * Validates a revision's fields with the type's generated validator (PRD 6.2 phase 5, 11.8): it
 * finds the validator through TypeValidators, puts the fields in the input form with
 * FieldValuesInput and checks them with the kernel's InputValidator, so a plan meets the same rules
 * as input from outside.
 */
#[Internal]
final readonly class TypeRulesFieldValidation implements FieldValidation
{
    public function __construct(
        private TypeValidators $validators,
        private InputValidator $input,
    ) {}

    /**
     * @throws LogicException when the type has no generated validator: the generated code is out of step
     */
    #[Override]
    public function validate(TypeDefinition $type, FieldValues $fields, ValidationStage $stage, FieldPath $at): ValidationReport
    {
        $validator = $this->validators->find($type->id);

        if (! $validator instanceof TypeValidator) {
            throw new LogicException(sprintf('The type %s has no generated validator. Run cms:generate.', $type->name->value));
        }

        return $this->input->validateWith($validator, FieldValuesInput::of($fields), $stage, $at);
    }
}
