<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Codecs;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Codecs\InvalidRecordDocument;
use Cbox\Cms\Contracts\Codecs\RecordCodecs;
use Cbox\Cms\Contracts\Fields\BooleanValue;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Ids\Uuid7;
use Cbox\Cms\Contracts\Results\ReadContent;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use stdClass;

/**
 * The shared contract suite for RecordCodecs (GUARDRAILS 2.3 and 9). The FakeRecordCodecs and the
 * class cms:generate writes run the same cases, so a test that uses the fake sees what a projection
 * sees at run time.
 *
 * Use the trait in a PHPUnit test class in the package's tests/Contract directory, return the codecs
 * from codecs(), the catalog of the same types from catalog(), and from contents() at least one
 * entry of those types with valid field values, as a read returns it:
 *
 *     final class GeneratedRecordCodecsContractTest extends TestCase
 *     {
 *         use RecordCodecsContract;
 *
 *         protected function codecs(): RecordCodecs
 *         {
 *             return new GeneratedRecordCodecs;
 *         }
 *
 *         protected function catalog(): TypeCatalog
 *         {
 *             return new GeneratedTypeCatalog;
 *         }
 *
 *         protected function contents(): array
 *         {
 *             return [new ReadContent($entry, $node, $type, $fields)];
 *         }
 *     }
 *
 * The cases hold the codecs to the catalog, one for every type and none for another, and hold what
 * encode() writes: a JSON object with the entry's id, the same bytes every time, sorted keys, and no
 * field classified above the caller's access (invariant 10), for every access.
 */
#[Experimental]
trait RecordCodecsContract
{
    /**
     * The codecs under test, with at least one type.
     */
    abstract protected function codecs(): RecordCodecs;

    /**
     * The catalog of the same types.
     */
    abstract protected function catalog(): TypeCatalog;

    /**
     * Entries of the catalog's types with field values their records accept, at least one.
     *
     * @return list<ReadContent>
     */
    abstract protected function contents(): array;

    #[Test]
    public function it_has_a_codec_and_an_entry_to_write(): void
    {
        Assert::assertNotSame([], $this->codecs()->types(), 'The suite needs codecs of at least one type.');
        Assert::assertNotSame([], $this->contents(), 'The suite needs at least one entry to write.');
    }

    #[Test]
    public function types_lists_the_types_sorted_with_each_once(): void
    {
        $ids = array_map(static fn (TypeId $type): string => $type->toString(), $this->codecs()->types());
        $sorted = $ids;
        sort($sorted, SORT_STRING);

        Assert::assertSame($sorted, $ids);
        Assert::assertSame(array_values(array_unique($ids)), $ids);
    }

    #[Test]
    public function every_type_of_the_catalog_has_a_codec_and_no_other_type_has_one(): void
    {
        $types = array_map(static fn (TypeDefinition $type): string => $type->id->toString(), $this->catalog()->all());
        sort($types, SORT_STRING);

        Assert::assertSame($types, array_map(static fn (TypeId $type): string => $type->toString(), $this->codecs()->types()));
    }

    #[Test]
    public function it_writes_an_entry_as_an_object_with_its_id_and_sorted_keys_the_same_bytes_every_time(): void
    {
        foreach ($this->contents() as $content) {
            $json = $this->codecs()->encode($content, ClassificationAccess::Public);
            $record = self::object($json);
            $keys = array_keys(get_object_vars($record));
            $sorted = $keys;
            sort($sorted, SORT_STRING);

            Assert::assertSame($content->entry->toString(), $record->cms_id ?? null);
            Assert::assertSame($sorted, $keys);
            Assert::assertSame($json, $this->codecs()->encode($content, ClassificationAccess::Public));
        }
    }

    #[Test]
    public function it_writes_no_field_above_the_access_and_every_field_the_access_allows(): void
    {
        foreach ($this->contents() as $content) {
            $type = $this->catalog()->find($content->type);
            Assert::assertInstanceOf(TypeDefinition::class, $type, 'Each entry of contents() is of a type of the catalog.');

            foreach (ClassificationAccess::cases() as $access) {
                $record = self::object($this->codecs()->encode($content, $access));

                foreach ($type->fields as $field) {
                    $held = self::held($field, $content) instanceof FieldValue;
                    $written = self::written($field, $record);

                    if (! $access->allows($field->classification)) {
                        Assert::assertFalse($written, sprintf('The field %s is classified %s and written for the access %s.', $field->address(), $field->classification->value, $access->value));
                    } elseif ($held) {
                        Assert::assertTrue($written, sprintf('The field %s is held and allowed for the access %s, and not written.', $field->address(), $access->value));
                    }
                }
            }
        }
    }

    #[Test]
    public function it_refuses_an_entry_of_a_type_the_installation_does_not_have(): void
    {
        $content = $this->contents()[0];
        $unknown = new TypeId(Uuid7::lowestAt(0));

        Assert::assertNotContains($unknown->toString(), array_map(static fn (TypeId $type): string => $type->toString(), $this->codecs()->types()));

        try {
            $this->codecs()->encode(new ReadContent($content->entry, $content->node, $unknown, $content->fields), ClassificationAccess::Public);
            Assert::fail('An entry of a type without a codec was written.');
        } catch (InvalidRecordDocument $refused) {
            Assert::assertStringContainsString($unknown->toString(), $refused->getMessage());
        }
    }

    #[Test]
    public function it_refuses_a_value_that_does_not_fit_its_field(): void
    {
        $content = $this->contents()[0];
        $type = $this->catalog()->find($content->type);
        Assert::assertInstanceOf(TypeDefinition::class, $type);
        $field = array_find($type->fields, static fn (FieldDefinition $field): bool => ! $field->namespace instanceof FieldNamespace && $field->classification === ClassificationAccess::Public);
        Assert::assertInstanceOf(FieldDefinition::class, $field, 'The first entry\'s type needs a public field of its owner.');
        $wrong = $field->valueType() === 'boolean' ? new TextValue('yes') : new BooleanValue(true);
        $fields = new FieldValues(new FieldMap(...[
            ...array_values(array_filter($content->fields->own->fields, static fn (NamedValue $named): bool => ! $named->handle->equals($field->handle))),
            new NamedValue($field->handle, $wrong),
        ]), ...$content->fields->extensions);

        $this->expectException(InvalidRecordDocument::class);

        $this->codecs()->encode($content->withFields($fields), ClassificationAccess::Sensitive);
    }

    /**
     * The value the content holds for the field, or null when it holds none or NullValue.
     */
    private static function held(FieldDefinition $field, ReadContent $content): ?FieldValue
    {
        $value = $field->namespace instanceof FieldNamespace
            ? $content->fields->extension($field->namespace)?->get($field->handle)
            : $content->fields->own->get($field->handle);

        return $value instanceof NullValue ? null : $value;
    }

    /**
     * Whether the record has a key for the field, the owner's at the top and an extender's in `ext`.
     */
    private static function written(FieldDefinition $field, stdClass $record): bool
    {
        if (! $field->namespace instanceof FieldNamespace) {
            return property_exists($record, $field->handle->value);
        }

        $extensions = $record->ext ?? null;
        $fields = $extensions instanceof stdClass ? ($extensions->{$field->namespace->value} ?? null) : null;

        return $fields instanceof stdClass && property_exists($fields, $field->handle->value);
    }

    private static function object(string $json): stdClass
    {
        $record = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        Assert::assertInstanceOf(stdClass::class, $record, 'A record is written as a JSON object.');

        return $record;
    }
}
