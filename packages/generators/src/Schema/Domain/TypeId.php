<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\InvalidUuid7;
use Cbox\Cms\Contracts\Ids\Uuid7;

/**
 * The stable id of a type (PRD 11.2): a UUIDv7 written once into the type's blueprint file. It
 * stays the same when the type's handle changes, so an extension or a relation refers to a type by
 * its id and survives a rename by the owner (PRD 11.12).
 */
#[Internal]
final readonly class TypeId
{
    public function __construct(public Uuid7 $value) {}

    /**
     * @throws InvalidUuid7 when the string is not a UUIDv7
     */
    public static function fromString(string $value): self
    {
        return new self(new Uuid7($value));
    }

    public function toString(): string
    {
        return $this->value->value;
    }

    public function equals(self $other): bool
    {
        return $this->value->equals($other->value);
    }
}
