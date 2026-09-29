---
title: Type catalog
weight: 38
description: "The TypeCatalog contract: the types of an installation as the kernel knows them at run time, from the code cms:generate writes; the generated catalog and records, the testkit's FakeTypeCatalog and the shared suite TypeCatalogContract."
---

# Type catalog

<!-- extension-point: Cbox\Cms\Contracts\Schema\TypeCatalog -->
<!-- extension-point: Cbox\Cms\Testkit\Schema\TypeCatalogContract -->

The kernel knows no content type by name (GUARDRAILS 2.4). It learns the types of an installation at run time, from the code `cms:generate` writes from the blueprints (PRD 11.12), through the contract `Cbox\Cms\Contracts\Schema\TypeCatalog`. A module or addon reads a type the same way, so its code works for any type that declares what it needs.

## The contract

The catalog has three methods:

| Method | Returns |
|---|---|
| `all(): list<TypeDefinition>` | every type, sorted by name, each id and each name once |
| `find(TypeId $id): ?TypeDefinition` | the type with the id, or `null` |
| `named(TypeName $name): ?TypeDefinition` | the type with the name, such as `app:blog_post`, or `null` |

A catalog is fixed for the life of the process: it changes only with a deploy of new generated code. It never reads the blueprints or the database.

A `TypeDefinition` holds what the kernel needs to write and read the type:

- `id`, the `TypeId` from the blueprint, which never changes, and `name`, a `TypeName` of the owner and the handle. A handle is unique only for its owner, so `shop:product` and `app:product` are two types.
- `version`, the owner's version of the definition, and `extensions`, an `ExtensionVersion` per extender's namespace. Together they are the type's composite version (PRD 11.12, point 5). `extensionVersion()` gives one namespace's.
- `capabilities`, a `TypeCapabilities` of `History`, `Stages`, `Localization` and whether the type is routable. The kernel decides from them what it does with the type's entries.
- `fields`, the top-level fields, the owner's and every extender's, sorted by column. `field(?FieldNamespace, FieldHandle)` finds one.

A `FieldDefinition` says where a field lives and who may see it. `namespace` is `null` for the owner's field and the extender's namespace for an extension field, which code addresses as `ext.<namespace>.<handle>` (`address()`). `fieldType` is the field type as the blueprint names it. `classification` is a `ClassificationAccess`, and `agents` whether MCP tools and agents see the field: a field above confidential never is. `encrypted`, `required`, `filterable` and `sortable` are what the blueprint compiles to. A top-level field has a `ColumnDefinition` in the type table: its name, the handle or `ext__<namespace>__<handle>`, its Postgres type, whether it is NOT NULL and its CHECK expressions. A group's nested fields, in `fields`, have no column and take the group's namespace, classification and encryption.

Each of these types refuses a value that breaks its rules with `InvalidTypeDefinition`, so no catalog can hand the kernel a type whose columns, namespaces or classification contradict each other.

## The generated catalog and records

`cms:generate` writes, next to `TypeHandle.php` in the PHP directory (`app/Cms/Generated` in an application):

- `GeneratedTypeCatalog`, the catalog, with a `TypeDefinition` per type compiled from its descriptor.
- `GeneratedTypesServiceProvider`, which binds `TypeCatalog` to the catalog and each type's record factory. The application registers it once, in `bootstrap/providers.php`; the workbench registers it in `WorkbenchServiceProvider`.
- A directory per type in `Records`, named as the type's `TypeHandle` case, such as `Records/ShopProduct` for `shop:product`:
  - `ShopProductRecord`, the owner's interface, with a get-only property per field of the owner in camelCase and `toFieldValues()`. The owner's code knows the type only through it.
  - `ShopProductRecordFactory`, the interface of the record factory, which the owner's code asks the container for.
  - Per extender's namespace, such as `app`: `ShopProductAppExtension` with the property `ext`, `ShopProductAppExt` with the property `app`, and `ShopProductAppFields` with the namespace's fields, so PHP reads an extension field as `$product->ext->app->taxCode`.
  - The composite record `ShopProduct`, a final readonly class that implements the owner's interface and every extender's, and its factory `ShopProductFactory`.
  - An enum per select field, such as `ColourChoice`, and a class per group, such as `SupplierGroup`, or per item of a repeated group, such as `DimensionsItem`.

A record converts to and from the kernel's generic field values, `Cbox\Cms\Contracts\Fields\FieldValues`, with `fromFieldValues()` and `toFieldValues()`, through `FieldReader` and `FieldWriter`. A value of the wrong kind, a required field without a value and an option the field does not have are refused with `InvalidFieldValue`, which names the field's path, such as `dimensions.0.size`. Rich text is the kernel's `ListValue` of Portable Text blocks. The generated code uses only the public API of `cboxdk/cms` and passes PHPStan level 10, Rector and Pint unchanged; the golden files of the comprehensive example are in `packages/generators/tests/Descriptor/Fixtures/Comprehensive/Generated`.

## The fake: FakeTypeCatalog

`Cbox\Cms\Testkit\Schema\FakeTypeCatalog` holds the types a test gives it. A test of code that must work for any type builds the types it needs instead of relying on the application's schema, and hands the fake to the code under test. Like the generated catalog, the fake refuses two types with one id or one name. This example is in the `Unit` suite:

<!-- example: examples/Unit/Schema/TypeCatalogTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Schema\ColumnDefinition;
use Cbox\Cms\Contracts\Schema\ExtensionVersion;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeCapabilities;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;

// Code that must work for any type reads the type from the TypeCatalog, never by its name in the
// code. A test builds the types it needs and hands the fake catalog to the code under test.

/**
 * The columns of a type table that the kernel may show to an agent: public or internal fields that
 * the blueprint does not hide, and confidential ones the blueprint shows.
 *
 * @return list<string>
 */
function agentColumns(TypeCatalog $catalog, TypeId $id): array
{
    $columns = [];

    foreach ($catalog->find($id)->fields ?? [] as $field) {
        if ($field->agents && $field->column instanceof ColumnDefinition) {
            $columns[] = $field->column->name;
        }
    }

    return $columns;
}

function productCatalog(): FakeTypeCatalog
{
    $text = static fn (?string $namespace, string $handle, ClassificationAccess $classification, bool $agents): FieldDefinition => new FieldDefinition(
        namespace: $namespace === null ? null : new FieldNamespace($namespace),
        handle: new FieldHandle($handle),
        fieldType: 'text',
        classification: $classification,
        agents: $agents,
        encrypted: $classification === ClassificationAccess::Confidential,
        required: false,
        filterable: false,
        sortable: false,
        column: new ColumnDefinition(
            $namespace === null ? $handle : 'ext__'.$namespace.'__'.$handle,
            $classification === ClassificationAccess::Confidential ? 'bytea' : 'text',
            false,
            [],
        ),
    );

    return new FakeTypeCatalog(new TypeDefinition(
        TypeId::fromString('0198d2a4-5c3e-7a41-9b2f-3c8e1f6a7d20'),
        new TypeName('shop:product'),
        3,
        new TypeCapabilities(History::Full, Stages::DraftRelease, Localization::None, true),
        [new ExtensionVersion(new FieldNamespace('app'), 2)],
        [
            $text(null, 'name', ClassificationAccess::Public, true),
            $text(null, 'cost', ClassificationAccess::Confidential, false),
            $text('app', 'tax_code', ClassificationAccess::Internal, true),
        ],
    ));
}

it('finds a type by its id or its name, with its composite version', function (): void {
    $catalog = productCatalog();
    $product = $catalog->named(new TypeName('shop:product'));

    expect($product?->id->toString())->toBe('0198d2a4-5c3e-7a41-9b2f-3c8e1f6a7d20')
        ->and($catalog->find(TypeId::fromString('0198d2a4-5c3e-7a41-9b2f-3c8e1f6a7d20')))->toEqual($product)
        ->and($product?->version)->toBe(3)
        ->and($product?->extensionVersion(new FieldNamespace('app')))->toBe(2)
        ->and($catalog->named(new TypeName('app:product')))->toBeNull();
});

it('gives each field its column and address, owner\'s and extender\'s alike', function (): void {
    $product = productCatalog()->named(new TypeName('shop:product'));

    expect(array_map(static fn (FieldDefinition $field): string => $field->address(), $product->fields ?? []))
        ->toBe(['cost', 'ext.app.tax_code', 'name'])
        ->and($product?->field(new FieldNamespace('app'), new FieldHandle('tax_code'))?->column?->name)->toBe('ext__app__tax_code');
});

it('decides from the catalog which columns an agent may see', function (): void {
    expect(agentColumns(productCatalog(), TypeId::fromString('0198d2a4-5c3e-7a41-9b2f-3c8e1f6a7d20')))
        ->toBe(['ext__app__tax_code', 'name']);
});
```

## Running the shared suite

The generated catalog and the fake run the same shared suite, the trait `Cbox\Cms\Testkit\Schema\TypeCatalogContract`. Use it in a PHPUnit test class in your `tests/Contract` directory and return the catalog from `catalog()`, with at least one type. The cases cover the lookups, that the catalog does not change, and the rules the generated code follows: an owner's column is its handle and an extender's `ext__<namespace>__<handle>`, only an owner's required field is NOT NULL, an encrypted field is `bytea` without checks, agents see no field above confidential, and a group passes its classification to its fields. An application runs it against its generated catalog, as this example does with the workbench's, in the `Contract` suite:

<!-- example: examples/Contract/Schema/GeneratedTypeCatalogContractTest.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Contract\Schema;

use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Testkit\Schema\TypeCatalogContract;
use Override;
use PHPUnit\Framework\TestCase;
use Workbench\App\Cms\Generated\GeneratedTypeCatalog;

/**
 * The shared TypeCatalog suite against the catalog cms:generate writes. In an application the
 * class is App\Cms\Generated\GeneratedTypeCatalog; here it is the workbench's, generated from its
 * fixture schema. The generated catalog has a constructor without arguments, so the suite needs no
 * application.
 */
final class GeneratedTypeCatalogContractTest extends TestCase
{
    use TypeCatalogContract;

    #[Override]
    protected function catalog(): TypeCatalog
    {
        return new GeneratedTypeCatalog;
    }
}
```

`cboxdk/cms` runs the suite against the fake in `packages/testkit/tests/Contract/FakeTypeCatalogContractTest.php`, against the workbench's catalog from the container in `packages/generators/tests/Contract/WorkbenchTypeCatalogContractTest.php`, and against the comprehensive example's golden catalog in `packages/generators/tests/Contract/ComprehensiveTypeCatalogContractTest.php`.
