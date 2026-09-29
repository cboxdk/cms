<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Ids;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The stable id of a content type, the type_id of its blueprint, unchanged when the type is
 * renamed (PRD 11.2, 11.12). The kernel knows no type by name (GUARDRAILS 2.4); it carries the id
 * of whatever type a schema defines. It is a UUIDv7, made by the IdGenerator contract.
 */
#[Experimental]
final readonly class TypeId
{
    public function __construct(public Uuid7 $value) {}

    /**
     * Parses the canonical UUIDv7 string. Anything else throws InvalidUuid7.
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
