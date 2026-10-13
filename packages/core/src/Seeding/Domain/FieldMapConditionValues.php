<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Fields\BooleanValue;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Validation\ConditionValues;
use Cbox\Cms\Contracts\Validation\FieldReference;
use Override;

/**
 * The values a condition reads in field values the seeder generates (gap G3): the fields of the
 * object that holds the conditional field and of each object it is in, the outermost first.
 */
#[Internal]
final readonly class FieldMapConditionValues implements ConditionValues
{
    /**
     * @param  list<FieldMap>  $scopes
     */
    public function __construct(private array $scopes) {}

    #[Override]
    public function isNull(FieldReference $field): bool
    {
        $value = $this->value($field);

        return ! $value instanceof FieldValue || $value instanceof NullValue;
    }

    #[Override]
    public function is(FieldReference $field, string|int|bool $value): bool
    {
        $held = $this->value($field);

        return match (true) {
            $held instanceof TextValue => $held->value === $value,
            $held instanceof IntegerValue => $held->value === $value,
            $held instanceof BooleanValue => $held->value === $value,
            default => false,
        };
    }

    private function value(FieldReference $field): ?FieldValue
    {
        return ($this->scopes[count($this->scopes) - 1 - $field->up] ?? null)?->get($field->handle);
    }
}
