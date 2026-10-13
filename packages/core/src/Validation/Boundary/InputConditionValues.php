<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Validation\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Validation\ConditionValues;
use Cbox\Cms\Contracts\Validation\FieldReference;
use Override;

/**
 * The values a condition reads in decoded JSON (gap G3): the members of the object that holds the
 * conditional field and of each object it is in, as the input validator and the codecs read them.
 * A reference goes up from the last scope, the object that holds the field.
 */
#[Internal]
final readonly class InputConditionValues implements ConditionValues
{
    /**
     * @param  list<array<array-key, mixed>>  $scopes  the members of each object, the outermost first
     */
    public function __construct(private array $scopes) {}

    #[Override]
    public function isNull(FieldReference $field): bool
    {
        return $this->value($field) === null;
    }

    #[Override]
    public function is(FieldReference $field, string|int|bool $value): bool
    {
        return $this->value($field) === $value;
    }

    private function value(FieldReference $field): mixed
    {
        return ($this->scopes[count($this->scopes) - 1 - $field->up] ?? [])[$field->handle->value] ?? null;
    }
}
