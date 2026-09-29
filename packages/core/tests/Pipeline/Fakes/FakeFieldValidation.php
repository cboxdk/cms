<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Fakes;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Validation\FieldRules;
use Cbox\Cms\Contracts\Validation\Presence;
use Cbox\Cms\Contracts\Validation\TypeValidator;
use Cbox\Cms\Contracts\Validation\TypeValidators;
use Cbox\Cms\Contracts\Validation\ValidationReport;
use Cbox\Cms\Contracts\Validation\ValidationStage;
use Cbox\Cms\Core\Pipeline\Domain\FieldValidation;
use LogicException;
use Override;

/**
 * The owner's top-level fields of a revision checked against the type's validator from
 * TypeValidators: a required field that is absent or null is validation_required, and a field the
 * validator does not declare is validation_unknown_field, each at its path below $at. It checks no
 * other rule. FieldValidationBehaviour holds it to TypeRulesFieldValidation for these cases, and
 * it records the types it was asked about.
 */
final class FakeFieldValidation implements FieldValidation
{
    /** @var list<string> the names of the types asked about, in order */
    public array $asked = [];

    public function __construct(private readonly TypeValidators $validators) {}

    #[Override]
    public function validate(TypeDefinition $type, FieldValues $fields, ValidationStage $stage, FieldPath $at): ValidationReport
    {
        $this->asked[] = $type->name->value;
        $validator = $this->validators->find($type->id);

        if (! $validator instanceof TypeValidator) {
            throw new LogicException(sprintf('The type %s has no generated validator. Run cms:generate.', $type->name->value));
        }

        $errors = [];
        $declared = [];

        foreach ($validator->rules()->fields as $rules) {
            $declared[$rules->handle->value] = true;
            $value = $fields->own->get($rules->handle);

            if ($this->required($rules, $stage) && (! $value instanceof FieldValue || $value instanceof NullValue)) {
                $errors[] = new CatalogError(ErrorCode::ValidationRequired, $at->then($rules->handle->value), 'is required.');
            }
        }

        foreach ($fields->own->handles() as $handle) {
            if (! isset($declared[$handle->value])) {
                $errors[] = new CatalogError(ErrorCode::ValidationUnknownField, $at->then($handle->value), 'is not a field of the type.');
            }
        }

        return new ValidationReport($errors);
    }

    private function required(FieldRules $rules, ValidationStage $stage): bool
    {
        return $rules->presence === Presence::Required
            || ($rules->presence === Presence::RequiredOnRelease && $stage === ValidationStage::Release);
    }
}
