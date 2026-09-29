<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Contracts\Validation\TypeValidators;
use Cbox\Cms\Contracts\Validation\ValidationReport;
use Cbox\Cms\Contracts\Validation\ValidationStage;
use Cbox\Cms\Core\Pipeline\Domain\FieldValidation;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ProbeType;
use Cbox\Cms\Testkit\Validation\FakeTypeValidators;
use LogicException;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every FieldValidation does for the cases the pipeline's action tests use, run against
 * TypeRulesFieldValidation and FakeFieldValidation (GUARDRAILS 9): valid fields pass, a required
 * field that is absent or null and a field the type does not have are errors at their paths below
 * the path given, and a type without a validator is a bug of the generated code.
 */
trait FieldValidationBehaviour
{
    abstract protected function fieldValidation(TypeValidators $validators): FieldValidation;

    #[Test]
    public function valid_fields_pass(): void
    {
        $report = $this->validateProbe(new FieldValues(new FieldMap(
            new NamedValue(new FieldHandle('label'), new TextValue('A')),
            new NamedValue(new FieldHandle('rank'), new IntegerValue(2)),
        )));

        Assert::assertTrue($report->passed());
    }

    #[Test]
    public function a_required_field_that_is_absent_or_null_is_required_at_its_path(): void
    {
        $absent = $this->validateProbe(new FieldValues);
        $null = $this->validateProbe(new FieldValues(new FieldMap(new NamedValue(new FieldHandle('label'), new NullValue))));

        Assert::assertSame(['validation_required fields.label'], $this->errors($absent));
        Assert::assertSame(['validation_required fields.label'], $this->errors($null));
    }

    #[Test]
    public function a_field_the_type_does_not_have_is_unknown_at_its_path(): void
    {
        $report = $this->validateProbe(new FieldValues(new FieldMap(
            new NamedValue(new FieldHandle('label'), new TextValue('A')),
            new NamedValue(new FieldHandle('colour'), new TextValue('red')),
        )));

        Assert::assertSame(['validation_unknown_field fields.colour'], $this->errors($report));
    }

    #[Test]
    public function a_type_without_a_validator_is_refused(): void
    {
        $definition = ProbeType::definition();
        $other = new TypeDefinition($definition->id, new TypeName('test:other'), 1, $definition->capabilities, [], $definition->fields);

        try {
            $this->fieldValidation(new FakeTypeValidators)->validate($other, new FieldValues, ValidationStage::Write, new FieldPath('fields'));
            Assert::fail('A type without a validator is refused.');
        } catch (LogicException $missing) {
            Assert::assertSame('The type test:other has no generated validator. Run cms:generate.', $missing->getMessage());
        }
    }

    private function validateProbe(FieldValues $fields): ValidationReport
    {
        return $this->fieldValidation(new FakeTypeValidators(new ProbeType))
            ->validate(ProbeType::definition(), $fields, ValidationStage::Write, new FieldPath('fields'));
    }

    /**
     * @return list<string>
     */
    private function errors(ValidationReport $report): array
    {
        return array_map(static fn (CatalogError $error): string => $error->code->value.' '.($error->path?->toString() ?? '-'), $report->errors);
    }
}
