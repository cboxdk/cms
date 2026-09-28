<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Generation;

use Cbox\Cms\Generators\Generation\Domain\Dto\ResolvedField;
use Cbox\Cms\Generators\Generation\Domain\Dto\ResolvedSchema;
use Cbox\Cms\Generators\Generation\Domain\Dto\ResolvedType;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Generation\Domain\SchemaResolver;
use Cbox\Cms\Generators\Schema\Domain\Dto\Blueprints;
use Cbox\Cms\Generators\Schema\Domain\Dto\ExtensionBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\TypeBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
use Cbox\Cms\Generators\Schema\Domain\TypeFieldLimit;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use PHPUnit\Framework\Assert;

/*
 * The rules across blueprint files that the blueprint schema v1 cannot check, and the extensions
 * applied to the types they extend under their column names (PRD 11.12).
 */

/**
 * @param  list<TypeBlueprint>  $types
 * @param  list<ExtensionBlueprint>  $extensions
 */
function resolveFails(array $types, array $extensions = []): GenerationFailed
{
    try {
        SchemaResolver::resolve(new Blueprints($types, $extensions));
    } catch (GenerationFailed $failed) {
        return $failed;
    }

    Assert::fail('The resolver accepted the blueprints.');
}

/**
 * @return array<string, list<string>> type name (`<owner>:<handle>`) to field names
 */
function fieldNames(ResolvedSchema $schema): array
{
    $names = [];

    foreach ($schema->types as $type) {
        $names[$type->name()] = array_map(static fn (ResolvedField $field): string => $field->name, $type->fields);
    }

    return $names;
}

it('applies the extensions of every namespace to the type they extend, sorted by name', function (): void {
    $app = SchemaFixtures::root();
    $acme = SchemaFixtures::root('acme', 'vendor/acme/shop/schema');
    $blog = SchemaFixtures::root('blog', 'vendor/acme/blog/schema');
    $product = SchemaFixtures::type($acme, 'product', ['title' => 'text', 'sku' => 'text']);
    $page = SchemaFixtures::type($app, 'page', ['title' => 'text']);

    $schema = SchemaResolver::resolve(new Blueprints([$product, $page], [
        SchemaFixtures::extension($app, 'shop/product.yaml', $product->typeId, ['tax_code' => 'text']),
        SchemaFixtures::extension($blog, 'product.yaml', $product->typeId, ['tax_code' => 'text', 'teaser' => 'long_text']),
    ]));

    expect(fieldNames($schema))->toBe([
        'acme:product' => ['ext__app__tax_code', 'ext__blog__tax_code', 'ext__blog__teaser', 'sku', 'title'],
        'app:page' => ['title'],
    ])
        ->and(array_map(static fn (ResolvedType $type): string => $type->blueprint->owner->value, $schema->types))->toBe(['acme', 'app'])
        ->and($schema->types[0]->fields[0]->namespace?->value)->toBe('app')
        ->and($schema->types[0]->fields[3]->namespace)->toBeNull()
        ->and($schema->types[0]->fields[2]->typeName())->toBe('long_text');
});

it('encodes an extension field as ext__<namespace>__<handle>, which no handle of the owner can be', function (): void {
    expect(ResolvedField::columnName(new Owner('app'), new Handle('tax_code')))->toBe('ext__app__tax_code')
        ->and(preg_match(Handle::PATTERN, 'ext__app__tax_code'))->toBe(0);
});

it('gives the same schema whatever order the blueprint files come in', function (): void {
    $app = SchemaFixtures::root();
    $acme = SchemaFixtures::root('acme', 'vendor/acme/shop/schema');
    $product = SchemaFixtures::type($acme, 'product', ['title' => 'text']);
    $page = SchemaFixtures::type($app, 'page', ['title' => 'text', 'body' => 'rich_text']);
    $taxCode = SchemaFixtures::extension($app, 'shop/tax_code.yaml', $product->typeId, ['tax_code' => 'text']);
    $colour = SchemaFixtures::extension($app, 'shop/colour.yaml', $product->typeId, ['colour' => 'select']);

    expect(SchemaResolver::resolve(new Blueprints([$product, $page], [$taxCode, $colour])))
        ->toEqual(SchemaResolver::resolve(new Blueprints([$page, $product], [$colour, $taxCode])));
});

it('resolves the same type handle from two owners as two types, named by owner and handle', function (): void {
    $app = SchemaFixtures::root();
    $acme = SchemaFixtures::root('acme', 'vendor/acme/shop/schema');
    $blog = SchemaFixtures::root('blog', 'vendor/acme/blog/schema');
    $appProduct = SchemaFixtures::type($app, 'product', ['title' => 'text', 'tax_code' => 'text']);
    $acmeProduct = SchemaFixtures::type($acme, 'product', ['title' => 'text', 'sku' => 'text']);

    $schema = SchemaResolver::resolve(new Blueprints([$appProduct, $acmeProduct], [
        SchemaFixtures::extension($blog, 'product.yaml', $acmeProduct->typeId, ['teaser' => 'text']),
        SchemaFixtures::extension($acme, 'app_product.yaml', $appProduct->typeId, ['colour' => 'text']),
    ]));

    expect(fieldNames($schema))->toBe([
        'acme:product' => ['ext__blog__teaser', 'sku', 'title'],
        'app:product' => ['ext__acme__colour', 'tax_code', 'title'],
    ])
        ->and(array_map(static fn (ResolvedType $type): string => $type->handle(), $schema->types))->toBe(['product', 'product'])
        ->and($schema->types[0]->blueprint)->toBe($acmeProduct)
        ->and($schema->types[1]->blueprint)->toBe($appProduct);
});

it('names a type <owner>:<handle>, and no two owner and handle pairs share a name', function (): void {
    $owners = ['a', 'ab', 'a1', 'app', 'acme', 'b'];
    $handles = ['a', 'b', 'ab', 'a_b', 'b_a', 'app_b', 'acme_product', 'product'];
    $names = [];

    foreach ($owners as $owner) {
        foreach ($handles as $handle) {
            $name = ResolvedType::nameOf(new Owner($owner), new Handle($handle));

            expect(explode(ResolvedType::SEPARATOR, $name))->toBe([$owner, $handle])
                ->and($names)->not->toHaveKey($name);

            $names[$name] = true;
        }
    }

    expect(ResolvedType::nameOf(new Owner('acme'), new Handle('product')))->toBe('acme:product');
});

it('refuses the same type handle twice from one owner with generate_duplicate_type_handle', function (): void {
    $app = SchemaFixtures::root();
    $first = SchemaFixtures::type($app, 'page', ['title' => 'text']);
    $second = new TypeBlueprint(
        SchemaFixtures::typeId('app', 'other_page'),
        $first->handle,
        $first->label,
        null,
        1,
        $first->capabilities,
        $first->fields,
        $first->owner,
        new SourceLocation('schema/pages/page.yaml', ''),
    );

    $failed = resolveFails([$first, $second]);

    expect($failed->codes())->toBe([GenerateErrorCode::DuplicateTypeHandle])
        ->and($failed->problems[0]->message)->toBe('schema/page.yaml and schema/pages/page.yaml both define a type "page" of app. A type handle is unique for its owner.');
});

it('refuses two types with the same type_id with generate_duplicate_type_id', function (): void {
    $app = SchemaFixtures::root();
    $page = SchemaFixtures::type($app, 'page', ['title' => 'text']);
    $post = SchemaFixtures::type($app, 'post', ['title' => 'text']);
    $copy = new TypeBlueprint($page->typeId, $post->handle, $post->label, null, 1, $post->capabilities, $post->fields, $post->owner, $post->location);

    $failed = resolveFails([$page, $copy]);

    expect($failed->codes())->toBe([GenerateErrorCode::DuplicateTypeId])
        ->and($failed->problems[0]->message)->toBe(sprintf('schema/page.yaml and schema/post.yaml both define the type_id %s. A type_id names one type: give one of them a new UUIDv7.', $page->typeId->toString()));
});

it('refuses an extension of a type_id that no schema root defines with generate_unknown_extends_target', function (): void {
    $app = SchemaFixtures::root();
    $missing = SchemaFixtures::typeId('acme', 'product');

    $failed = resolveFails(
        [SchemaFixtures::type($app, 'page', ['title' => 'text'])],
        [SchemaFixtures::extension($app, 'shop/product.yaml', $missing, ['tax_code' => 'text'])],
    );

    expect($failed->codes())->toBe([GenerateErrorCode::UnknownExtendsTarget])
        ->and($failed->problems[0]->message)->toBe(sprintf('schema/shop/product.yaml, /extends extends the type_id %s, which no schema root defines. Check the type_id, or add the schema root of the type\'s owner.', $missing->toString()));
});

it('refuses an extension of a type of its own owner with generate_extension_of_own_type', function (): void {
    $app = SchemaFixtures::root();
    $page = SchemaFixtures::type($app, 'page', ['title' => 'text']);

    $failed = resolveFails([$page], [SchemaFixtures::extension($app, 'page_extra.yaml', $page->typeId, ['teaser' => 'text'])]);

    expect($failed->codes())->toBe([GenerateErrorCode::ExtensionOfOwnType])
        ->and($failed->problems[0]->message)->toBe('schema/page_extra.yaml, /extends extends the type "page" in schema/page.yaml, which app owns. An owner adds fields to its own type in the type file, not with an extension: add the fields to schema/page.yaml.');
});

it('refuses extension files of one owner for one type with different versions with generate_extension_version_mismatch', function (): void {
    $app = SchemaFixtures::root();
    $acme = SchemaFixtures::root('acme', 'vendor/acme/shop/schema');
    $product = SchemaFixtures::type($acme, 'product', ['title' => 'text']);

    $failed = resolveFails([$product], [
        SchemaFixtures::extension($app, 'shop/a.yaml', $product->typeId, ['tax_code' => 'text'], 1),
        SchemaFixtures::extension($app, 'shop/b.yaml', $product->typeId, ['note' => 'text'], 3),
    ]);

    expect($failed->codes())->toBe([GenerateErrorCode::ExtensionVersionMismatch])
        ->and($failed->problems[0]->message)->toBe('schema/shop/b.yaml, /version is the version 3 of the fields app adds to the type "product", and schema/shop/a.yaml, /version is the version 1. The fields one owner adds to one type have one version, its part of the type\'s composite version: give every extension file of app for the type the same version.');
});

it('resolves extension files of one owner for one type with the same version, beside another owner\'s extension at another version', function (): void {
    $app = SchemaFixtures::root();
    $acme = SchemaFixtures::root('acme', 'vendor/acme/shop/schema');
    $blog = SchemaFixtures::root('blog', 'vendor/acme/blog/schema');
    $product = SchemaFixtures::type($acme, 'product', ['title' => 'text']);

    $schema = SchemaResolver::resolve(new Blueprints([$product], [
        SchemaFixtures::extension($app, 'shop/a.yaml', $product->typeId, ['tax_code' => 'text'], 3),
        SchemaFixtures::extension($app, 'shop/b.yaml', $product->typeId, ['note' => 'text'], 3),
        SchemaFixtures::extension($blog, 'product.yaml', $product->typeId, ['teaser' => 'text'], 1),
    ]));

    expect(fieldNames($schema))->toBe(['acme:product' => ['ext__app__note', 'ext__app__tax_code', 'ext__blog__teaser', 'title']]);
});

it('refuses a field name twice in one type with generate_duplicate_field_handle', function (): void {
    $app = SchemaFixtures::root();
    $acme = SchemaFixtures::root('acme', 'vendor/acme/shop/schema');
    $product = SchemaFixtures::type($acme, 'product', ['title' => 'text']);
    $page = SchemaFixtures::type($app, 'page', ['title' => 'text']);
    $twice = new TypeBlueprint($page->typeId, $page->handle, $page->label, null, 1, $page->capabilities, [...$page->fields, ...$page->fields], $page->owner, $page->location);

    $failed = resolveFails([$product, $twice], [
        SchemaFixtures::extension($app, 'shop/a.yaml', $product->typeId, ['tax_code' => 'text']),
        SchemaFixtures::extension($app, 'shop/b.yaml', $product->typeId, ['tax_code' => 'integer']),
    ]);

    expect($failed->codes())->toBe([GenerateErrorCode::DuplicateFieldHandle, GenerateErrorCode::DuplicateFieldHandle])
        ->and($failed->problems[0]->message)->toBe('schema/page.yaml, /fields/0 and schema/page.yaml, /fields/0 both give the type "page" the field title. A field name is unique within its type.')
        ->and($failed->problems[1]->message)->toBe('schema/shop/a.yaml, /fields/0 and schema/shop/b.yaml, /fields/0 both give the type "product" the field ext__app__tax_code. A field name is unique within its type.');
});

it('refuses the column name of an extension field over 63 bytes with generate_column_name_too_long', function (): void {
    $acme = SchemaFixtures::root('acme', 'vendor/acme/shop/schema');
    $extender = SchemaFixtures::root('longnamespace', 'vendor/long/schema');
    $product = SchemaFixtures::type($acme, 'product', ['title' => 'text']);
    $fits = str_repeat('a', 63 - strlen('ext__longnamespace__'));
    $tooLong = $fits.'b';

    $failed = resolveFails([$product], [SchemaFixtures::extension($extender, 'product.yaml', $product->typeId, [$fits => 'text', $tooLong => 'text'])]);

    expect($failed->codes())->toBe([GenerateErrorCode::ColumnNameTooLong])
        ->and($failed->problems[0]->message)->toBe(sprintf('vendor/long/schema/product.yaml, /fields/1: the column name ext__longnamespace__%s has 64 bytes, and Postgres allows 63. Choose a shorter handle.', $tooLong));
});

/**
 * The given number of text fields, `field_1` and up, as SchemaFixtures::type() and extension() take them.
 *
 * @return array<string, string>
 */
function manyFields(int $count, string $prefix = 'field'): array
{
    $fields = [];

    for ($i = 1; $i <= $count; $i++) {
        $fields[$prefix.'_'.$i] = 'text';
    }

    return $fields;
}

it('refuses a type with 200 fields of its own and one an extension adds with generate_too_many_fields', function (): void {
    $app = SchemaFixtures::root();
    $acme = SchemaFixtures::root('acme', 'vendor/acme/shop/schema');
    $product = SchemaFixtures::type($acme, 'product', manyFields(TypeFieldLimit::MAX_FIELDS));

    $failed = resolveFails([$product], [SchemaFixtures::extension($app, 'shop/product.yaml', $product->typeId, ['tax_code' => 'text'])]);

    expect(TypeFieldLimit::MAX_FIELDS)->toBe(200)
        ->and($failed->codes())->toBe([GenerateErrorCode::TooManyFields])
        ->and($failed->problems[0]->message)->toBe('vendor/acme/shop/schema/product.yaml, /fields: the type product of acme has 201 fields, 200 of its own and 1 that extensions add in schema/shop/product.yaml. A type has at most 200 fields, its own and those its extensions add together, because each is a column of its table: remove fields, or model a part of the type as a type of its own.');
});

it('resolves a type with 200 fields, its own and those its extensions add together', function (): void {
    $app = SchemaFixtures::root();
    $blog = SchemaFixtures::root('blog', 'vendor/acme/blog/schema');
    $acme = SchemaFixtures::root('acme', 'vendor/acme/shop/schema');
    $product = SchemaFixtures::type($acme, 'product', manyFields(100));

    $schema = SchemaResolver::resolve(new Blueprints([$product], [
        SchemaFixtures::extension($app, 'shop/a.yaml', $product->typeId, manyFields(50, 'a')),
        SchemaFixtures::extension($app, 'shop/b.yaml', $product->typeId, manyFields(25, 'b')),
        SchemaFixtures::extension($blog, 'product.yaml', $product->typeId, manyFields(25)),
    ]));

    expect($schema->types[0]->fields)->toHaveCount(200);
});

it('counts the fields of every extension file of every owner towards the limit of a type', function (): void {
    $app = SchemaFixtures::root();
    $blog = SchemaFixtures::root('blog', 'vendor/acme/blog/schema');
    $acme = SchemaFixtures::root('acme', 'vendor/acme/shop/schema');
    $product = SchemaFixtures::type($acme, 'product', manyFields(100));
    $page = SchemaFixtures::type($app, 'page', ['title' => 'text']);

    $failed = resolveFails([$page, $product], [
        SchemaFixtures::extension($app, 'shop/a.yaml', $product->typeId, manyFields(50, 'a')),
        SchemaFixtures::extension($blog, 'product.yaml', $product->typeId, manyFields(50)),
        SchemaFixtures::extension($app, 'shop/b.yaml', $product->typeId, manyFields(1, 'b')),
    ]);

    expect($failed->codes())->toBe([GenerateErrorCode::TooManyFields])
        ->and($failed->problems[0]->message)->toStartWith('vendor/acme/shop/schema/product.yaml, /fields: the type product of acme has 201 fields, 100 of its own and 101 that extensions add in schema/shop/a.yaml, vendor/acme/blog/schema/product.yaml, schema/shop/b.yaml. ');
});

it('refuses a type with more than 200 fields of its own with generate_too_many_fields', function (): void {
    $product = SchemaFixtures::type(SchemaFixtures::root(), 'product', manyFields(TypeFieldLimit::MAX_FIELDS + 1));

    $failed = resolveFails([$product]);

    expect($failed->codes())->toBe([GenerateErrorCode::TooManyFields])
        ->and($failed->problems[0]->message)->toStartWith('schema/product.yaml, /fields: the type product of app has 201 fields, all of its own. ');
});

it('counts a field name used twice in a type once towards its limit', function (): void {
    $app = SchemaFixtures::root();
    $acme = SchemaFixtures::root('acme', 'vendor/acme/shop/schema');
    $product = SchemaFixtures::type($acme, 'product', manyFields(199));

    $failed = resolveFails([$product], [
        SchemaFixtures::extension($app, 'shop/a.yaml', $product->typeId, ['tax_code' => 'text']),
        SchemaFixtures::extension($app, 'shop/b.yaml', $product->typeId, ['tax_code' => 'text']),
    ]);

    expect($failed->codes())->toBe([GenerateErrorCode::DuplicateFieldHandle]);
});

it('reports every problem of the blueprints at once', function (): void {
    $app = SchemaFixtures::root();
    $page = SchemaFixtures::type($app, 'page', ['title' => 'text']);
    $post = SchemaFixtures::type($app, 'post', ['title' => 'text']);

    $failed = resolveFails(
        [$page, new TypeBlueprint($page->typeId, $post->handle, $post->label, null, 1, $post->capabilities, $post->fields, $post->owner, $post->location)],
        [SchemaFixtures::extension($app, 'x.yaml', SchemaFixtures::typeId('acme', 'missing'), ['tax_code' => 'text'])],
    );

    expect($failed->codes())->toBe([GenerateErrorCode::DuplicateTypeId, GenerateErrorCode::UnknownExtendsTarget]);
});
