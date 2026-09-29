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
