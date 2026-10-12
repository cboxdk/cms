<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Codec\Domain\Dto;

use BackedEnum;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Codec\Domain\CodecKind;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValidationRule;

/**
 * What a property of a generated DTO holds (GUARDRAILS 2.2): its kind, the rules its codec checks
 * when it reads the value, in the type descriptor's vocabulary without `required` and `nullable`,
 * which belong to the property, and what the kind needs besides: the object of an Object, the item
 * of a List, and the class of an Id, an Enum, a Value, an IntegerValue, Fields, a Document or an
 * Embedded document, with the form of an Id's or a Value's string when it is known, which the
 * TypeScript validator checks, and the codec class of an Embedded document.
 */
#[Internal]
final readonly class CodecValue
{
    /**
     * @param  list<ValidationRule>  $rules
     * @param  ?string  $class  the fully qualified class of an Id, an Enum, a Value, an IntegerValue, Fields, a Document or an Embedded document
     * @param  ?string  $codec  the codec class of an Embedded document, without its namespace, in the namespace of the codec that holds it
     */
    private function __construct(
        public CodecKind $kind,
        public array $rules,
        public ?CodecObject $object = null,
        public ?self $item = null,
        public ?string $class = null,
        public ?StringForm $form = null,
        public ?string $codec = null,
    ) {}

    /**
     * A value of a kind that needs nothing but its rules.
     *
     * @param  list<ValidationRule>  $rules
     */
    public static function of(CodecKind $kind, array $rules = []): self
    {
        return new self($kind, $rules);
    }

    /**
     * @param  list<ValidationRule>  $rules
     */
    public static function object(CodecObject $object, array $rules = []): self
    {
        return new self(CodecKind::Object, $rules, object: $object);
    }

    /**
     * @param  list<ValidationRule>  $rules
     */
    public static function list(self $item, array $rules = []): self
    {
        return new self(CodecKind::List, $rules, item: $item);
    }

    /**
     * @param  class-string  $class
     */
    public static function id(string $class, ?StringForm $form = null): self
    {
        return new self(CodecKind::Id, [], class: $class, form: $form);
    }

    /**
     * @param  class-string<BackedEnum>  $class
     */
    public static function enum(string $class): self
    {
        return new self(CodecKind::Enum, [], class: $class);
    }

    /**
     * @param  class-string  $class
     */
    public static function value(string $class, ?StringForm $form = null): self
    {
        return new self(CodecKind::Value, [], class: $class, form: $form);
    }

    /**
     * @param  class-string  $class
     * @param  list<ValidationRule>  $rules
     */
    public static function integerValue(string $class, array $rules = []): self
    {
        return new self(CodecKind::IntegerValue, $rules, class: $class);
    }

    /**
     * @param  class-string  $class
     */
    public static function fields(string $class): self
    {
        return new self(CodecKind::Fields, [], class: $class);
    }

    /**
     * @param  class-string  $class
     */
    public static function document(string $class): self
    {
        return new self(CodecKind::Document, [], class: $class);
    }

    /**
     * @param  class-string  $class  the bound class of the embedded contract's document
     * @param  string  $codec  the embedded contract's codec class, without its namespace
     */
    public static function embedded(string $class, string $codec): self
    {
        return new self(CodecKind::Embedded, [], class: $class, codec: $codec);
    }
}
