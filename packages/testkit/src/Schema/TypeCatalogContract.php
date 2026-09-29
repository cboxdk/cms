<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Schema;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Ids\Uuid7;
use Cbox\Cms\Contracts\Schema\ColumnDefinition;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shared contract suite for TypeCatalog (GUARDRAILS 2.3 and 9). The FakeTypeCatalog and the
 * catalog cms:generate writes run the same cases, so a test that uses the fake sees what the
 * kernel sees at run time.
 *
 * Use the trait in a PHPUnit test class in the package's tests/Contract directory and return the
 * catalog from catalog(), with at least one type:
 *
 *     final class GeneratedTypeCatalogContractTest extends TestCase
 *     {
 *         use TypeCatalogContract;
 *
 *         protected function catalog(): TypeCatalog
 *         {
 *             return new GeneratedTypeCatalog;
 *         }
 *     }
 *
 * The cases hold the catalog to its lookups and to the rules the generated code follows: the
 * column of an owner's field is its handle and an extender's `ext__<namespace>__<handle>`
 * (PRD 11.12), only an owner's required field is NOT NULL, an encrypted field is `bytea` without
 * checks (PRD 12.2), and agents never see a field above confidential (PRD 2.31).
 */
#[Experimental]
trait TypeCatalogContract
{
    /**
     * The catalog under test, with at least one type.
     */
    abstract protected function catalog(): TypeCatalog;

    #[Test]
    public function it_has_a_type(): void
    {
        Assert::assertNotSame([], $this->catalog()->all(), 'The suite needs a catalog with at least one type.');
    }

    #[Test]
    public function all_lists_the_types_sorted_by_name_with_each_id_and_name_once(): void
    {
        $names = array_map(static fn (TypeDefinition $type): string => $type->name->value, $this->catalog()->all());
        $ids = array_map(static fn (TypeDefinition $type): string => $type->id->toString(), $this->catalog()->all());
        $sorted = $names;
        sort($sorted, SORT_STRING);

        Assert::assertSame($sorted, $names);
        Assert::assertSame(array_values(array_unique($names)), $names);
        Assert::assertSame(array_values(array_unique($ids)), $ids);
    }

    #[Test]
    public function the_catalog_does_not_change(): void
    {
        $catalog = $this->catalog();

        Assert::assertEquals($catalog->all(), $catalog->all());
        Assert::assertEquals($this->catalog()->all(), $catalog->all());
    }

    #[Test]
    public function find_gives_each_type_by_its_id(): void
    {
        $catalog = $this->catalog();

        foreach ($catalog->all() as $type) {
            Assert::assertEquals($type, $catalog->find(TypeId::fromString($type->id->toString())), $type->name->value);
        }
    }

    #[Test]
    public function named_gives_each_type_by_its_name(): void
    {
        $catalog = $this->catalog();

        foreach ($catalog->all() as $type) {
            Assert::assertEquals($type, $catalog->named(new TypeName($type->name->value)), $type->name->value);
        }
    }

    #[Test]
    public function find_gives_null_for_an_id_no_type_has(): void
    {
        $catalog = $this->catalog();
        $taken = array_map(static fn (TypeDefinition $type): string => $type->id->toString(), $catalog->all());
        $milliseconds = 981_173_106_000;

        do {
            $id = new TypeId(Uuid7::lowestAt($milliseconds));
            $milliseconds += 86_400_000;
        } while (in_array($id->toString(), $taken, true));

        Assert::assertNull($catalog->find($id));
    }

    #[Test]
    public function named_gives_null_for_a_name_no_type_has(): void
    {
        $catalog = $this->catalog();
        $taken = array_map(static fn (TypeDefinition $type): string => $type->name->value, $catalog->all());
        $number = 0;

        do {
            $name = new TypeName('absent:type_'.$number++);
        } while (in_array($name->value, $taken, true));

        Assert::assertNull($catalog->named($name));
    }

    #[Test]
    public function the_fields_are_sorted_by_column_and_each_top_level_field_has_its_own_column(): void
    {
        foreach ($this->catalog()->all() as $type) {
            $columns = [];

            foreach ($type->fields as $field) {
                Assert::assertInstanceOf(ColumnDefinition::class, $field->column, $field->address());
                $columns[] = $field->column->name;
            }

            $sorted = $columns;
            sort($sorted, SORT_STRING);

            Assert::assertSame($sorted, $columns, $type->name->value);
            Assert::assertSame(array_values(array_unique($columns)), $columns, $type->name->value);
        }
    }

    #[Test]
    public function a_column_is_named_after_the_handle_and_the_namespace_of_an_extension_field(): void
    {
        foreach ($this->catalog()->all() as $type) {
            foreach ($type->fields as $field) {
                $expected = $field->namespace instanceof FieldNamespace
                    ? 'ext__'.$field->namespace->value.'__'.$field->handle->value
                    : $field->handle->value;

                Assert::assertSame($expected, $field->column?->name, $type->name->value.' '.$field->address());

                if ($field->namespace instanceof FieldNamespace) {
                    Assert::assertNotNull($type->extensionVersion($field->namespace), $field->address());
                }
            }
        }
    }

    #[Test]
    public function only_a_required_field_of_the_owner_is_not_null(): void
    {
        foreach ($this->catalog()->all() as $type) {
            foreach ($type->fields as $field) {
                Assert::assertSame(
                    $field->required && ! $field->namespace instanceof FieldNamespace,
                    $field->column?->notNull,
                    $type->name->value.' '.$field->address(),
                );
            }
        }
    }

    #[Test]
    public function an_encrypted_field_is_stored_as_ciphertext_without_checks(): void
    {
        foreach ($this->catalog()->all() as $type) {
            foreach ($type->fields as $field) {
                Assert::assertTrue(
                    ! $field->encrypted || ($field->column?->type === 'bytea' && $field->column->checks === []),
                    $type->name->value.' '.$field->address(),
                );
            }
        }
    }

    #[Test]
    public function agents_see_no_field_above_confidential_and_a_group_passes_its_class_to_its_fields(): void
    {
        foreach ($this->catalog()->all() as $type) {
            foreach ($type->fields as $field) {
                $this->assertFieldClassification($type, $field);
            }
        }
    }

    private function assertFieldClassification(TypeDefinition $type, FieldDefinition $field): void
    {
        $message = $type->name->value.' '.$field->address();

        if ($field->agents) {
            Assert::assertTrue(ClassificationAccess::Confidential->allows($field->classification), $message);
        }

        foreach ($field->fields as $nested) {
            Assert::assertNull($nested->column, $message.'.'.$nested->handle->value);
            Assert::assertSame($field->classification, $nested->classification, $message.'.'.$nested->handle->value);
            Assert::assertSame($field->encrypted, $nested->encrypted, $message.'.'.$nested->handle->value);

            $this->assertFieldClassification($type, $nested);
        }
    }
}
