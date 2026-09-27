<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use BackedEnum;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldBlueprint;

/**
 * The values of one object of a blueprint document that the blueprint schema v1 has accepted, as a
 * FieldType reads its options from them: a field, or an object inside one such as a select option.
 *
 * Each method gives the value at a key in the form it asks for. A value of another kind is recorded
 * as a problem at its JSON pointer and read as null, or as the default where one is given, so a
 * FieldType returns null when a value it needs is null and never reports a problem itself.
 */
#[Internal]
interface FieldValues
{
    /**
     * The place of the object in its file.
     */
    public function at(): SourceLocation;

    /**
     * Whether the object has the key.
     */
    public function has(string $key): bool;

    /**
     * Records every key of the object that is not one of the known keys.
     *
     * @param  list<string>  $known
     */
    public function knownKeys(array $known): void;

    /**
     * The string at the key, which the object must have.
     */
    public function string(string $key): ?string;

    /**
     * The string at the key, or null when the object has no such key.
     */
    public function optionalString(string $key): ?string;

    /**
     * The decimal number at the key, or null when the object has no such key.
     */
    public function optionalDecimal(string $key): ?DecimalBound;

    /**
     * The date at the key, or null when the object has no such key.
     */
    public function optionalDate(string $key): ?BlueprintDate;

    /**
     * The date-time at the key, or null when the object has no such key.
     */
    public function optionalDatetime(string $key): ?BlueprintDatetime;

    /**
     * The integer at the key, which the object must have. A number with a zero fraction, such as
     * YAML's `3.0`, is an integer, as JSON Schema counts it.
     */
    public function int(string $key): ?int;

    /**
     * The integer at the key, or null when the object has no such key.
     */
    public function optionalInt(string $key): ?int;

    /**
     * The boolean at the key, or the default when the object has no such key.
     */
    public function bool(string $key, bool $default): bool;

    /**
     * The handle at the key, which the object must have.
     */
    public function handle(string $key): ?Handle;

    /**
     * The case of the enum at the key, or null when the object has no such key.
     *
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return ?T
     */
    public function optionalEnum(string $enum, string $key): ?BackedEnum;

    /**
     * The list of cases of the enum at the key, in the order of the file, or null when the object
     * has no such key.
     *
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return ?list<T>
     */
    public function enumList(string $enum, string $key): ?array;

    /**
     * The values of the object at the key, which the object must have.
     */
    public function object(string $key): ?self;

    /**
     * The values of each object in the list at the key, which the object must have.
     *
     * @return ?list<FieldValues>
     */
    public function objectList(string $key): ?array;

    /**
     * The fields in the list at the key, read as nested fields: every field type of the registry,
     * and no classification of their own.
     *
     * @return ?list<FieldBlueprint>
     */
    public function fields(string $key): ?array;
}
