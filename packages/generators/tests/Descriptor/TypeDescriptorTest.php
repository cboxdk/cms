<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Descriptor;

use Cbox\Cms\Generators\Descriptor\Boundary\TypeDescriptorJson;
use Cbox\Cms\Generators\Descriptor\Domain\DescriptorCompiler;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\FieldDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\TypeDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValidationRule;
use Cbox\Cms\Generators\Generation\Domain\Dto\ExtensionVersion;
use Cbox\Cms\Generators\Generation\Domain\Dto\ResolvedField;
use Cbox\Cms\Generators\Generation\Domain\Dto\ResolvedSchema;
use Cbox\Cms\Generators\Generation\Domain\Dto\ResolvedType;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Generation\Domain\SchemaResolver;
use Cbox\Cms\Generators\Schema\Domain\Classification;
use Cbox\Cms\Generators\Schema\Domain\Dto\Blueprints;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\GroupOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\TextOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\TypeBlueprint;
use Cbox\Cms\Generators\Schema\Domain\FieldTypeRegistry;
use Cbox\Cms\Generators\Schema\Domain\FieldTypes\CoreFieldTypes;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
use Cbox\Cms\Generators\Schema\Domain\TextFormat;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use LogicException;
use stdClass;

/*
 * The type descriptor (PRD 11.2, 11.12, MILESTONES M1 point 1): the comprehensive example type
 * compiles to the committed golden JSON, the example covers what it claims to, the canonical JSON
 * does not depend on the order of the blueprint files or fields, and the compiler applies the
 * rules for required extension fields, encryption and nested fields.
 */

/**
 * The comprehensive example's one type.
 */
function comprehensiveDescriptor(): TypeDescriptor
{
    $types = ComprehensiveExample::compile()->types;

    expect($types)->toHaveCount(1);

    return $types[0];
}

/**
 * Every field of the descriptor, with the nested fields of its groups.
 *
 * @param  list<FieldDescriptor>  $fields
 * @return list<FieldDescriptor>
 */
function everyField(array $fields): array
{
    $all = [];

    foreach ($fields as $field) {
        $all[] = $field;
        array_push($all, ...everyField($field->fields));
    }

    return $all;
}

/**
 * @return list<string>
 */
function ruleNames(FieldDescriptor $field): array
{
    return array_map(static fn (ValidationRule $rule): string => $rule->name->value, $field->validation);
}

/**
 * A field of the descriptor by its column.
 */
function fieldByColumn(TypeDescriptor $type, string $column): FieldDescriptor
{
    foreach ($type->fields as $field) {
        if ($field->column?->name === $column) {
            return $field;
        }
    }

    throw new LogicException(sprintf('The descriptor has no column %s.', $column));
}

/**
 * Whether the keys of every object in the decoded JSON are in sorted order.
 */
function keysSorted(mixed $value): bool
{
    if ($value instanceof stdClass) {
        $keys = array_keys(get_object_vars($value));
        $sorted = $keys;
        sort($sorted, SORT_STRING);

        return $keys === $sorted && array_all(get_object_vars($value), keysSorted(...));
    }

    return ! is_array($value) || array_all($value, keysSorted(...));
}

/**
 * The type with its own fields and the nested fields of its groups in reverse order.
 */
function reversedType(TypeBlueprint $type): TypeBlueprint
{
    $reverse = static function (FieldBlueprint $field) use (&$reverse): FieldBlueprint {
        if (! $field->options instanceof GroupOptions) {
            return $field;
        }

        return new FieldBlueprint(
            $field->handle,
            $field->label,
            $field->description,
            $field->required,
            $field->classification,
            $field->filterable,
            $field->sortable,
            $field->agents,
            new GroupOptions(array_reverse(array_map($reverse, $field->options->fields)), $field->options->repeat),
            $field->owner,
            $field->location,
        );
    };

    return new TypeBlueprint($type->typeId, $type->handle, $type->label, $type->description, $type->version, $type->capabilities, array_reverse(array_map($reverse, $type->fields)), $type->owner, $type->location);
}

/**
 * A text field of the owner, with its classification and whether it is required.
 */
function textField(string $handle, string $owner, ?Classification $classification, bool $required = false): FieldBlueprint
{
    return new FieldBlueprint(new Handle($handle), 'Field', null, $required, $classification, false, false, false, new TextOptions(null, 50, TextFormat::Plain), new Owner($owner), new SourceLocation('schema/'.$handle.'.yaml', '/fields/0'));
}

/**
 * A resolved type of the app with the given fields, built without the resolver.
 *
 * @param  list<ResolvedField>  $fields
 * @param  list<ExtensionVersion>  $extensions
 */
function resolvedType(array $fields, array $extensions = []): ResolvedType
{
    return new ResolvedType(SchemaFixtures::type(SchemaFixtures::root(), 'thing', []), $fields, $extensions);
}

it('compiles the comprehensive example to the committed golden descriptor', function (): void {
    expect(TypeDescriptorJson::encode(comprehensiveDescriptor()))
        ->toBe((string) file_get_contents(ComprehensiveExample::GOLDEN), 'The descriptor of the comprehensive example differs from '.ComprehensiveExample::GOLDEN.'. Review the difference; when it is intended, write the new descriptor there.');
});

it('covers every core field type, select options, nested and repeated groups, the flags, every classification and an extension', function (): void {
    $type = comprehensiveDescriptor();
    $fields = everyField($type->fields);
    $types = array_values(array_unique(array_map(static fn (FieldDescriptor $field): string => $field->type, $fields)));
    $core = new FieldTypeRegistry(new CoreFieldTypes)->names();
    sort($types);
    sort($core);
    $groups = array_values(array_filter($fields, static fn (FieldDescriptor $field): bool => $field->fields !== []));

    expect($types)->toBe($core)
        ->and(array_filter($fields, static fn (FieldDescriptor $field): bool => $field->choices !== []))->not->toBe([])
        ->and(array_map(static fn (FieldDescriptor $group): bool => $group->php->doc === 'array' || str_starts_with($group->php->doc, 'list<'), $groups))->toContain(true, false)
        ->and(array_map(static fn (FieldDescriptor $field): bool => $field->required, $fields))->toContain(true, false)
        ->and(array_map(static fn (FieldDescriptor $field): bool => $field->filterable, $fields))->toContain(true, false)
        ->and(array_map(static fn (FieldDescriptor $field): bool => $field->sortable, $fields))->toContain(true, false)
        ->and(array_map(static fn (FieldDescriptor $field): bool => $field->agents, $fields))->toContain(true, false)
        ->and(array_map(static fn (FieldDescriptor $field): bool => $field->agents, array_filter($fields, static fn (FieldDescriptor $field): bool => $field->classification === Classification::Confidential)))->toContain(true, false)
        ->and(array_map(static fn (FieldDescriptor $field): bool => $field->agents, array_filter($fields, static fn (FieldDescriptor $field): bool => $field->classification === Classification::Internal)))->toContain(true, false)
        ->and(array_values(array_unique(array_map(static fn (FieldDescriptor $field): string => $field->classification->value, $fields))))->toEqualCanonicalizing(array_map(static fn (Classification $classification): string => $classification->value, Classification::cases()))
        ->and($type->owner->value)->toBe('shop')
        ->and($type->extensions)->toEqual([new ExtensionVersion(new Owner('app'), 2)])
        ->and(array_keys($type->extensionFields()))->toBe(['app'])
        ->and(array_map(static fn (FieldDescriptor $field): string => $field->handle->value, $type->extensionFields()['app']))->toBe(['name', 'tax_code'])
        ->and(array_map(static fn (FieldDescriptor $field): ?string => $field->column?->name, $type->extensionFields()['app']))->toBe(['ext__app__name', 'ext__app__tax_code']);
});

it('writes canonical JSON: sorted keys, every key, one trailing newline and the same bytes each time', function (): void {
    $type = comprehensiveDescriptor();
    $json = TypeDescriptorJson::encode($type);
    $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);

    if (! $decoded instanceof stdClass) {
        throw new LogicException('The canonical JSON of a descriptor is not an object.');
    }

    expect(keysSorted($decoded))->toBeTrue()
        ->and($json)->toEndWith("}\n")
        ->and(str_ends_with($json, "\n\n"))->toBeFalse()
        ->and($json)->toBe(TypeDescriptorJson::encode(comprehensiveDescriptor()))
        ->and(array_keys(get_object_vars($decoded)))->toBe(['capabilities', 'description', 'descriptor', 'extensions', 'fields', 'handle', 'label', 'name', 'owner', 'type_id', 'version'])
        ->and($decoded->descriptor ?? null)->toBe(TypeDescriptorJson::FORMAT)
        ->and($decoded->name ?? null)->toBe('shop:product')
        ->and($json)->not->toContain('Fixtures/Comprehensive')
        ->and($json)->toContain('"description": null');
});

it('gives the same descriptor whatever the order of the fields and of the nested fields in the file', function (): void {
    $source = ComprehensiveExample::source()->read(ComprehensiveExample::roots());
    $reversed = new Blueprints(array_map(reversedType(...), $source->types), array_reverse($source->extensions));

    expect(TypeDescriptorJson::encode(SchemaFixtures::compiled($reversed)->types[0]))
        ->toBe(TypeDescriptorJson::encode(SchemaFixtures::compiled($source)->types[0]));
});

it('sorts the fields by column, whatever order the resolved type has them in', function (): void {
    $type = DescriptorCompiler::compile(new ResolvedSchema([resolvedType([
        ResolvedField::own(textField('zeta', 'app', Classification::Public)),
        ResolvedField::extension(textField('alpha', 'acme', Classification::Public)),
        ResolvedField::own(textField('beta', 'app', Classification::Public)),
    ])]))->types[0];

    expect(array_map(static fn (FieldDescriptor $field): ?string => $field->column?->name, $type->fields))->toBe(['beta', 'ext__acme__alpha', 'zeta']);
});

it('never makes an extension field NOT NULL or required in its validator, and keeps that its blueprint requires it', function (): void {
    $type = DescriptorCompiler::compile(new ResolvedSchema([resolvedType([
        ResolvedField::own(textField('own', 'app', Classification::Public, required: true)),
        ResolvedField::extension(textField('added', 'acme', Classification::Public, required: true)),
    ])]))->types[0];
    $own = fieldByColumn($type, 'own');
    $added = fieldByColumn($type, 'ext__acme__added');

    expect([$own->required, $own->column?->notNull, $own->php->nullable, $own->typeScript->nullable, ruleNames($own)[0]])->toBe([true, true, false, false, 'required'])
        ->and([$added->required, $added->column?->notNull, $added->php->nullable, $added->typeScript->nullable, ruleNames($added)[0]])->toBe([true, false, true, true, 'nullable'])
        ->and([$own->namespace, $added->namespace?->value, $added->owner->value])->toBe([null, 'acme', 'acme']);
});

it('stores a confidential field as bytea without checks, NOT NULL when it is required', function (): void {
    $type = DescriptorCompiler::compile(new ResolvedSchema([resolvedType([
        ResolvedField::own(textField('secret', 'app', Classification::Confidential, required: true)),
        ResolvedField::own(textField('open', 'app', Classification::Internal)),
    ])]))->types[0];
    $secret = fieldByColumn($type, 'secret');
    $open = fieldByColumn($type, 'open');

    expect([$secret->encrypted, $secret->column?->type, $secret->column?->notNull, $secret->column?->checks, $secret->php->doc])->toBe([true, 'bytea', true, [], 'string'])
        ->and([$open->encrypted, $open->column?->type, $open->column?->checks])->toBe([false, 'text', ['char_length("open") <= 50']]);
});

it('gives the fields inside a group no column, the group\'s classification and encryption, and requires them within the group', function (): void {
    $supplier = fieldByColumn(comprehensiveDescriptor(), 'supplier');
    [$company, $notes] = $supplier->fields;

    expect([$company->column, $company->classification, $company->encrypted, $company->required, $company->php->nullable, ruleNames($company)[0]])
        ->toBe([null, Classification::Confidential, true, true, false, 'required'])
        ->and([$notes->column, $notes->required, $notes->php->nullable, $notes->agents, ruleNames($notes)[0]])->toBe([null, false, true, false, 'nullable']);
});

it('refuses a top-level field without a classification', function (): void {
    expect(static fn (): mixed => DescriptorCompiler::compile(new ResolvedSchema([resolvedType([
        ResolvedField::own(textField('first', 'app', null)),
        ResolvedField::own(textField('second', 'app', null)),
    ])])))->toThrow(GenerationFailed::class, '[generate_schema_invalid] schema/first.yaml, /fields/0: a top-level field needs a classification (PRD 12.2).'."\n".'[generate_schema_invalid] schema/second.yaml, /fields/0: a top-level field needs a classification (PRD 12.2).');
});

it('keeps the type\'s identity, version, capabilities and the extenders\' versions', function (): void {
    $blueprint = SchemaFixtures::type(SchemaFixtures::root(), 'thing', []);
    $extensions = [new ExtensionVersion(new Owner('acme'), 4), new ExtensionVersion(new Owner('blog'), 1)];
    $type = DescriptorCompiler::compile(new ResolvedSchema([new ResolvedType($blueprint, [], $extensions)]))->types[0];

    expect([$type->typeId, $type->owner, $type->handle, $type->label, $type->description, $type->version, $type->capabilities, $type->extensions, $type->location, $type->name()])
        ->toBe([$blueprint->typeId, $blueprint->owner, $blueprint->handle, $blueprint->label, $blueprint->description, $blueprint->version, $blueprint->capabilities, $extensions, $blueprint->location, 'app:thing']);
});

it('resolves the version of each extender of a type, sorted by namespace', function (): void {
    $shop = SchemaFixtures::root('shop', 'vendor/shop/schema');
    $blog = SchemaFixtures::root('blog', 'vendor/blog/schema');
    $app = SchemaFixtures::root();
    $product = SchemaFixtures::type($shop, 'product', ['title' => 'text']);
    $page = SchemaFixtures::type($shop, 'page', ['title' => 'text']);
    $schema = SchemaResolver::resolve(new Blueprints([$product, $page], [
        SchemaFixtures::extension($blog, 'product.yaml', $product->typeId, ['teaser' => 'text'], 3),
        SchemaFixtures::extension($app, 'product.yaml', $product->typeId, ['tax_code' => 'text'], 2),
        SchemaFixtures::extension($app, 'more/product.yaml', $product->typeId, ['sku' => 'text'], 2),
        SchemaFixtures::extension($blog, 'page.yaml', $page->typeId, ['intro' => 'text'], 5),
    ]));
    $versions = array_map(
        static fn (ResolvedType $type): array => array_map(static fn (ExtensionVersion $version): string => $version->namespace->value.' '.$version->version, $type->extensions),
        $schema->types,
    );

    expect(array_map(static fn (ResolvedType $type): string => $type->name(), $schema->types))->toBe(['shop:page', 'shop:product'])
        ->and($versions)->toBe([['blog 5'], ['app 2', 'blog 3']]);
});

it('gives the owner\'s fields by handle and the extension fields by namespace, whatever their columns sort as', function (): void {
    $type = DescriptorCompiler::compile(new ResolvedSchema([resolvedType([
        ResolvedField::own(textField('title', 'app', Classification::Public)),
        ResolvedField::extension(textField('b', 'a', Classification::Public)),
        ResolvedField::extension(textField('a', 'a1', Classification::Public)),
        ResolvedField::extension(textField('a', 'a', Classification::Public)),
        ResolvedField::own(textField('extra', 'app', Classification::Public)),
    ])]))->types[0];
    $extension = array_map(
        static fn (array $fields): array => array_map(static fn (FieldDescriptor $field): string => $field->handle->value, $fields),
        $type->extensionFields(),
    );

    expect(array_map(static fn (FieldDescriptor $field): string => $field->handle->value, $type->ownFields()))->toBe(['extra', 'title'])
        ->and($extension)->toBe(['a' => ['a', 'b'], 'a1' => ['a']]);
});
