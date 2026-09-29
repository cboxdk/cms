<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Validation;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Ids\Uuid7;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Validation\TypeValidator;
use Cbox\Cms\Contracts\Validation\TypeValidators;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shared contract suite for TypeValidators (GUARDRAILS 2.3 and 9). The FakeTypeValidators and
 * the class cms:generate writes run the same cases, so a test that uses the fake sees what the
 * kernel sees at run time.
 *
 * Use the trait in a PHPUnit test class in the package's tests/Contract directory, return the
 * validators from validators() and the catalog of the same types from catalog(), with at least one
 * type:
 *
 *     final class GeneratedTypeValidatorsContractTest extends TestCase
 *     {
 *         use TypeValidatorsContract;
 *
 *         protected function validators(): TypeValidators
 *         {
 *             return new GeneratedTypeValidators;
 *         }
 *
 *         protected function catalog(): TypeCatalog
 *         {
 *             return new GeneratedTypeCatalog;
 *         }
 *     }
 *
 * The cases hold the validators to their lookups and to the catalog: one validator for every type
 * of the catalog and none for another.
 */
#[Experimental]
trait TypeValidatorsContract
{
    /**
     * The validators under test, with at least one.
     */
    abstract protected function validators(): TypeValidators;

    /**
     * The catalog of the same types.
     */
    abstract protected function catalog(): TypeCatalog;

    #[Test]
    public function it_has_a_validator(): void
    {
        Assert::assertNotSame([], $this->validators()->all(), 'The suite needs validators of at least one type.');
    }

    #[Test]
    public function all_lists_the_validators_sorted_by_type_id_with_each_type_once(): void
    {
        $ids = array_map(static fn (TypeValidator $validator): string => $validator->type()->toString(), $this->validators()->all());
        $sorted = $ids;
        sort($sorted, SORT_STRING);

        Assert::assertSame($sorted, $ids);
        Assert::assertSame(array_values(array_unique($ids)), $ids);
    }

    #[Test]
    public function every_type_of_the_catalog_has_a_validator_and_no_other_type_has_one(): void
    {
        $types = array_map(static fn (TypeDefinition $type): string => $type->id->toString(), $this->catalog()->all());
        $validated = array_map(static fn (TypeValidator $validator): string => $validator->type()->toString(), $this->validators()->all());
        sort($types, SORT_STRING);

        Assert::assertSame($types, $validated);
    }

    #[Test]
    public function find_gives_each_validator_by_its_type(): void
    {
        foreach ($this->catalog()->all() as $type) {
            $validator = $this->validators()->find(TypeId::fromString($type->id->toString()));

            Assert::assertInstanceOf(TypeValidator::class, $validator);
            Assert::assertTrue($validator->type()->equals($type->id));
        }
    }

    #[Test]
    public function find_gives_null_for_a_type_the_installation_does_not_have(): void
    {
        $known = array_map(static fn (TypeValidator $validator): string => $validator->type()->toString(), $this->validators()->all());
        $unknown = new TypeId(Uuid7::lowestAt(0));

        Assert::assertNotContains($unknown->toString(), $known);
        Assert::assertNull($this->validators()->find($unknown));
    }

    #[Test]
    public function the_validators_do_not_change(): void
    {
        $validators = $this->validators();

        Assert::assertEquals($validators->all(), $validators->all());
        Assert::assertEquals($this->validators()->all(), $validators->all());

        foreach ($validators->all() as $validator) {
            Assert::assertEquals($validator->rules(), $validator->rules());
        }
    }
}
