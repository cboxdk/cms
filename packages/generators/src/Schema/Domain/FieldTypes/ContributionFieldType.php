<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\FieldTypes\FieldTypeContribution;
use Cbox\Cms\Contracts\FieldTypes\FieldTypeOptions;
use Cbox\Cms\Contracts\FieldTypes\InvalidFieldShape;
use Cbox\Cms\Contracts\FieldTypes\InvalidFieldTypeOptions;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\Dto\ShapedOptions;
use Cbox\Cms\Generators\Schema\Domain\FieldType;
use Cbox\Cms\Generators\Schema\Domain\FieldValues;
use Override;

/**
 * A field type an addon contributes, as the reader resolves it (PRD 13.1, 11.12): its choices sit
 * under `options`, which the values check against the contribution's options schema, and the
 * contribution's shape of them gives the options of the core field type every generator writes.
 * A shape the contribution cannot make, or makes with a value of the wrong form, is
 * generate_schema_invalid at the field's `options`.
 */
#[Internal]
final readonly class ContributionFieldType implements FieldType
{
    public const string OPTIONS = 'options';

    public function __construct(private FieldTypeContribution $contribution) {}

    #[Override]
    public function name(): string
    {
        return $this->contribution->name()->value;
    }

    #[Override]
    public function optionKeys(): array
    {
        return [self::OPTIONS];
    }

    #[Override]
    public function options(FieldValues $field): ?ShapedOptions
    {
        $options = $field->fieldTypeOptions(self::OPTIONS, $this->name(), $this->contribution->optionsSchema());

        if (! $options instanceof FieldTypeOptions) {
            return null;
        }

        try {
            return new ShapedOptions($this->name(), ShapeOptions::of($this->contribution->shape($options)));
        } catch (InvalidFieldTypeOptions|InvalidFieldShape $invalid) {
            $field->invalid(self::OPTIONS, $invalid->getMessage());
        } catch (GenerationFailed $failed) {
            foreach ($failed->problems as $problem) {
                $field->invalid(self::OPTIONS, $problem->message);
            }
        }

        return null;
    }
}
