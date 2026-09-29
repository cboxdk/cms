<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Validation;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Validation\TypeValidator;
use Cbox\Cms\Contracts\Validation\TypeValidators;
use InvalidArgumentException;
use Override;

/**
 * The testkit's TypeValidators: the validators a test gives it, in memory, next to the types it
 * gives a FakeTypeCatalog (GUARDRAILS 2.4). Like the generated class, it holds each type once,
 * lists the validators sorted by type id and never changes.
 */
#[Experimental]
final readonly class FakeTypeValidators implements TypeValidators
{
    /** @var list<TypeValidator> sorted by type id */
    private array $validators;

    /**
     * @throws InvalidArgumentException when two validators are for one type
     */
    public function __construct(TypeValidator ...$validators)
    {
        $byType = [];

        foreach ($validators as $validator) {
            $id = $validator->type()->toString();

            if (isset($byType[$id])) {
                throw new InvalidArgumentException(sprintf('Two validators are for the type %s; a type has one.', $id));
            }

            $byType[$id] = $validator;
        }

        ksort($byType, SORT_STRING);
        $this->validators = array_values($byType);
    }

    #[Override]
    public function all(): array
    {
        return $this->validators;
    }

    #[Override]
    public function find(TypeId $id): ?TypeValidator
    {
        return array_find($this->validators, static fn (TypeValidator $validator): bool => $validator->type()->equals($id));
    }
}
