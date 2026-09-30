<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Schema;

use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\FieldTypes\FieldBase;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Schema\ColumnDefinition;
use Cbox\Cms\Contracts\Schema\ExtensionVersion;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\InvalidTypeDefinition;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeCapabilities;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;

/*
 * What the TypeCatalog gives the kernel (PRD 11.12, GUARDRAILS 2.4): a type definition holds its
 * invariants, so a catalog, generated or built by hand in a test, cannot hand the kernel a type
 * whose columns, namespaces or classification contradict each other.
 */

function column(string $name, bool $notNull = false): ColumnDefinition
{
    return new ColumnDefinition($name, 'text', $notNull, []);
}

/**
 * @param  list<FieldDefinition>  $fields
 */
function field(?string $namespace, string $handle, ClassificationAccess $classification = ClassificationAccess::Public, bool $agents = true, ?ColumnDefinition $column = null, array $fields = [], bool $encrypted = false): FieldDefinition
{
    $prefix = $namespace === null ? '' : 'ext__'.$namespace.'__';

    return new FieldDefinition(
        $namespace === null ? null : new FieldNamespace($namespace),
        new FieldHandle($handle),
        'text',
        $classification,
        $agents,
        $encrypted,
        false,
        false,
        false,
        $column ?? column($prefix.$handle),
        $fields,
    );
}

/**
 * @param  list<FieldDefinition>  $fields
 * @param  list<ExtensionVersion>  $extensions
 */
function type(array $fields, array $extensions = [], int $version = 1): TypeDefinition
{
    return new TypeDefinition(
        TypeId::fromString('0198d2a4-5c3e-7a41-9b2f-3c8e1f6a7d20'),
        new TypeName('shop:product'),
        $version,
        new TypeCapabilities(History::Full, Stages::DraftRelease, Localization::None, true),
        $extensions,
        $fields,
    );
}

it('reads the owner and the handle from a type name', function (): void {
    $name = new TypeName('shop:blog_post2');

    expect($name->owner)->toBe('shop')
        ->and($name->handle)->toBe('blog_post2')
        ->and($name->equals(new TypeName('shop:blog_post2')))->toBeTrue()
        ->and($name->equals(new TypeName('app:blog_post2')))->toBeFalse();
});

it('names the type\'s table <owner>__<handle>, so two names never give one table', function (): void {
    $names = ['shop:blog_post2', 'shop:blog', 'shopblog:post2', 'shop2:blog_post', 'app:shop_blog_post2'];
    $tables = array_map(static fn (string $name): string => new TypeName($name)->table(), $names);

    expect($tables)->toBe(['shop__blog_post2', 'shop__blog', 'shopblog__post2', 'shop2__blog_post', 'app__shop_blog_post2'])
        ->and(array_unique($tables))->toBe($tables)
        ->and(TypeName::TABLE_SEPARATOR)->toBe('__');
});

it('refuses a type name that is not <owner>:<handle>', function (string $value): void {
    expect(fn (): TypeName => new TypeName($value))->toThrow(InvalidTypeDefinition::class, 'A type name is <owner>:<handle>');
})->with([
    'no owner' => ['blog_post'],
    'the owner ext' => ['ext:post'],
    'the handle ext' => ['app:ext'],
    'a reserved prefix' => ['app:cms_post'],
    'a double underscore' => ['app:blog__post'],
    'upper case' => ['App:post'],
    'an owner of 21 characters' => ['a'.str_repeat('b', 20).':post'],
    'a handle of 64 characters' => ['app:a'.str_repeat('b', 63)],
]);

it('takes an owner of 20 and a handle of 63 characters', function (): void {
    expect(new TypeName('a'.str_repeat('b', 19).':a'.str_repeat('b', 62))->handle)->toHaveLength(63);
});

it('refuses a column without a name Postgres takes unquoted or without a type', function (string $name, string $type, string $message): void {
    expect(fn (): ColumnDefinition => new ColumnDefinition($name, $type, false, []))->toThrow(InvalidTypeDefinition::class, $message);
})->with([
    'upper case' => ['Title', 'text', 'A column name is'],
    'too long' => ['a'.str_repeat('b', 63), 'text', 'A column name is'],
    'no type' => ['title', ' ', 'The column "title" has no type.'],
]);

it('addresses an extension field under ext and its namespace', function (): void {
    expect(field(null, 'title')->address())->toBe('title')
        ->and(field('app', 'title')->address())->toBe('ext.app.title');
});

it('refuses a field type that is neither a core field type nor a namespaced one', function (): void {
    expect(fn (): FieldDefinition => new FieldDefinition(null, new FieldHandle('title'), 'Text', ClassificationAccess::Public, true, false, false, false, false, column('title')))
        ->toThrow(InvalidTypeDefinition::class, 'A field type is');
    expect(new FieldDefinition(null, new FieldHandle('title'), 'acme:colour', ClassificationAccess::Public, true, false, false, false, false, column('title'), base: FieldBase::Text)->fieldType)->toBe('acme:colour');
});

it('refuses agents on a field above confidential', function (ClassificationAccess $classification): void {
    expect(fn (): FieldDefinition => field(null, 'secret', $classification))->toThrow(InvalidTypeDefinition::class, 'is visible to agents');
    expect(field(null, 'secret', $classification, agents: false)->agents)->toBeFalse();
})->with([ClassificationAccess::Personal, ClassificationAccess::Sensitive]);

it('sorts a group\'s fields by handle and finds them', function (): void {
    $group = field(null, 'owner', fields: [
        new FieldDefinition(null, new FieldHandle('name'), 'text', ClassificationAccess::Public, true, false, false, false, false, null),
        new FieldDefinition(null, new FieldHandle('age'), 'integer', ClassificationAccess::Public, true, false, false, false, false, null),
    ]);

    expect(array_map(static fn (FieldDefinition $field): string => $field->handle->value, $group->fields))->toBe(['age', 'name'])
        ->and($group->field(new FieldHandle('name'))?->fieldType)->toBe('text')
        ->and($group->field(new FieldHandle('missing')))->toBeNull();
});

it('refuses a group whose fields do not follow it', function (FieldDefinition $nested, string $message): void {
    expect(fn (): FieldDefinition => field(null, 'owner', fields: [$nested]))->toThrow(InvalidTypeDefinition::class, $message);
})->with([
    'a column' => [fn (): FieldDefinition => field(null, 'name'), 'has a column, but a group is one column'],
    'another classification' => [fn (): FieldDefinition => new FieldDefinition(null, new FieldHandle('name'), 'text', ClassificationAccess::Internal, true, false, false, false, false, null), 'differs from its group'],
    'another namespace' => [fn (): FieldDefinition => new FieldDefinition(new FieldNamespace('app'), new FieldHandle('name'), 'text', ClassificationAccess::Public, true, false, false, false, false, null), 'differs from its group'],
    'another encryption' => [fn (): FieldDefinition => new FieldDefinition(null, new FieldHandle('name'), 'text', ClassificationAccess::Public, true, true, false, false, false, null), 'differs from its group'],
]);

it('refuses a group with a field twice', function (): void {
    $nested = new FieldDefinition(null, new FieldHandle('name'), 'text', ClassificationAccess::Public, true, false, false, false, false, null);

    expect(fn (): FieldDefinition => field(null, 'owner', fields: [$nested, $nested]))->toThrow(InvalidTypeDefinition::class, 'The field "owner.name" appears twice.');
});

it('sorts the fields by column and the extensions by namespace, and finds each', function (): void {
    $type = type(
        [field('erp', 'code'), field(null, 'title'), field('app', 'code'), field(null, 'code')],
        [new ExtensionVersion(new FieldNamespace('erp'), 3), new ExtensionVersion(new FieldNamespace('app'), 1)],
    );

    expect(array_map(static fn (FieldDefinition $field): ?string => $field->column?->name, $type->fields))->toBe(['code', 'ext__app__code', 'ext__erp__code', 'title'])
        ->and(array_map(static fn (ExtensionVersion $extension): string => $extension->namespace->value, $type->extensions))->toBe(['app', 'erp'])
        ->and($type->field(null, new FieldHandle('code'))?->address())->toBe('code')
        ->and($type->field(new FieldNamespace('app'), new FieldHandle('code'))?->address())->toBe('ext.app.code')
        ->and($type->field(new FieldNamespace('shop'), new FieldHandle('code')))->toBeNull()
        ->and($type->extensionVersion(new FieldNamespace('erp')))->toBe(3)
        ->and($type->extensionVersion(new FieldNamespace('shop')))->toBeNull();
});

it('refuses a type that contradicts itself', function (callable $build, string $message): void {
    expect($build)->toThrow(InvalidTypeDefinition::class, $message);
})->with([
    'version 0' => [fn (): TypeDefinition => type([field(null, 'title')], version: 0), 'The version of the type shop:product starts at 1, got 0.'],
    'an extension version 0' => [fn (): ExtensionVersion => new ExtensionVersion(new FieldNamespace('app'), 0), 'The version of the extension app starts at 1, got 0.'],
    'a namespace twice' => [fn (): TypeDefinition => type([field(null, 'title')], [new ExtensionVersion(new FieldNamespace('app'), 1), new ExtensionVersion(new FieldNamespace('app'), 2)]), 'The extension namespace "app" appears twice in one type.'],
    'a top-level field without a column' => [fn (): TypeDefinition => type([new FieldDefinition(null, new FieldHandle('title'), 'text', ClassificationAccess::Public, true, false, false, false, false, null)]), 'The top-level field "title" has no column'],
    'an extension field of an extender that is not listed' => [fn (): TypeDefinition => type([field('app', 'code')]), 'The field "ext.app.code" is in a namespace that extends no version'],
    'a field twice' => [fn (): TypeDefinition => type([field(null, 'title'), field(null, 'title', column: column('other'))]), 'The field "title" appears twice.'],
    'a column twice' => [fn (): TypeDefinition => type([field(null, 'title'), field(null, 'name', column: column('title'))]), 'The column "title" appears twice in one type.'],
]);

it('reads a value in the form of the field type, or of its base for an addon\'s field type, and holds the base to the field type', function (): void {
    $stars = new FieldDefinition(null, new FieldHandle('stars'), 'reviews:stars', ClassificationAccess::Public, true, false, false, true, true, column('stars'), base: FieldBase::Integer);

    expect($stars->valueType())->toBe('integer')
        ->and($stars->base)->toBe(FieldBase::Integer)
        ->and(new FieldDefinition(null, new FieldHandle('title'), 'text', ClassificationAccess::Public, true, false, false, false, false, column('title'))->valueType())->toBe('text')
        ->and(fn (): FieldDefinition => new FieldDefinition(null, new FieldHandle('stars'), 'reviews:stars', ClassificationAccess::Public, true, false, false, false, false, column('stars')))
        ->toThrow(InvalidTypeDefinition::class, 'The field type "reviews:stars" has no base.')
        ->and(fn (): FieldDefinition => new FieldDefinition(null, new FieldHandle('title'), 'text', ClassificationAccess::Public, true, false, false, false, false, column('title'), base: FieldBase::Text))
        ->toThrow(InvalidTypeDefinition::class, 'The field type "text" has a base.');
});
