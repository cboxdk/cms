<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Protocol\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Codec\Domain\CodecKind;

/**
 * The PHP class a value of a kernel JSON Schema is bound to (GUARDRAILS 2.2): an id, parsed by its
 * static fromString() and written by toString(); a value object of one string, made with `new` and
 * written from its `value`; or a backed enum, by its value.
 */
#[Internal]
final readonly class ValueBinding
{
    /**
     * @param  string  $class  the class, which the reader of the schema refuses when it does not exist
     */
    private function __construct(
        public CodecKind $kind,
        public string $class,
    ) {}

    public static function id(string $class): self
    {
        return new self(CodecKind::Id, $class);
    }

    public static function value(string $class): self
    {
        return new self(CodecKind::Value, $class);
    }

    public static function enum(string $class): self
    {
        return new self(CodecKind::Enum, $class);
    }
}
