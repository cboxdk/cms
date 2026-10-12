<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Protocol\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Codec\Domain\CodecKind;

/**
 * The PHP class a value of a kernel JSON Schema is bound to (GUARDRAILS 2.2): an id, parsed by its
 * static fromString() and written by toString(); a value object of one string or one integer, made
 * with `new` and written from its `value`; a backed enum, by its value; or the fields of a revision
 * of any type, FieldValues, in the form FieldValuesSchema fixes; or a JSON object of another
 * contract, a value object of its JSON text that its own codec writes and reads; or a JSON object
 * of another kernel contract embedded as its bound class, which that contract's codec, named in
 * $codec, writes and reads inline.
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
        public ?string $codec = null,
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

    public static function fields(string $class): self
    {
        return new self(CodecKind::Fields, $class);
    }

    public static function document(string $class): self
    {
        return new self(CodecKind::Document, $class);
    }

    /**
     * @param  string  $class  the class the embedded contract's document is bound to
     * @param  string  $codec  the embedded contract's codec class, without its namespace
     */
    public static function embedded(string $class, string $codec): self
    {
        return new self(CodecKind::Embedded, $class, $codec);
    }
}
