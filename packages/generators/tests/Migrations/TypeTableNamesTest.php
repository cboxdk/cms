<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Migrations;

use Cbox\Cms\Generators\Migrations\Domain\TypeTableNames;
use Cbox\Cms\Generators\Tests\SchemaFixtures;

/*
 * The names of a type table and its indexes in Postgres (PRD 11.6): `<owner>__<handle>` and
 * `<table>__<column>`, injective because an owner has no underscore and a handle no double
 * underscore; an index name over 63 bytes is shortened with a hash, the same on every run.
 */

it('names a type\'s table <owner>__<handle>', function (): void {
    $schema = SchemaFixtures::schema(['blog_post' => ['title' => 'text']]);

    expect(TypeTableNames::table($schema->types[0]))->toBe('app__blog_post')
        ->and(TypeTableNames::fits(str_repeat('a', 54)))->toBeTrue()
        ->and(TypeTableNames::fits(str_repeat('a', 55)))->toBeFalse();
});

it('names an index <table>__<column> while it fits in 63 bytes', function (): void {
    expect(TypeTableNames::index('app__blog_post', 'title'))->toBe('app__blog_post__title')
        ->and(TypeTableNames::index('app__blog_post', 'ext__acme__code'))->toBe('app__blog_post__ext__acme__code')
        ->and(TypeTableNames::index('app__blog_post', 'cms_home_node'))->toBe('app__blog_post__cms_home_node')
        ->and(TypeTableNames::index('a__'.str_repeat('b', 30), str_repeat('c', 28)))->toHaveLength(63)
        ->and(TypeTableNames::index('a__'.str_repeat('b', 30), str_repeat('c', 28)))->toBe('a__'.str_repeat('b', 30).'__'.str_repeat('c', 28));
});

it('shortens a longer index name to 63 bytes with a hash of the whole name, the same on every run', function (): void {
    $table = 'app__'.str_repeat('t', 49);
    $name = TypeTableNames::index($table, 'ext__acme__'.str_repeat('c', 40));
    $other = TypeTableNames::index($table, 'ext__acme__'.str_repeat('c', 39).'d');

    expect($name)->toHaveLength(63)
        ->and($name)->toBe(substr($table.'__ext__acme__'.str_repeat('c', 40), 0, 53).'__'.substr(hash('sha256', $table.'__ext__acme__'.str_repeat('c', 40)), 0, 8))
        ->and(TypeTableNames::index($table, 'ext__acme__'.str_repeat('c', 40)))->toBe($name)
        ->and($other)->toHaveLength(63)
        ->and($other)->not->toBe($name)
        ->and(substr($other, 0, 53))->toBe(substr($name, 0, 53));
});

it('gives different pairs of a table and a column different index names', function (): void {
    // Each table is <owner>__<handle>, and each column a handle, a system column or
    // ext__<namespace>__<handle>, as the generator makes them.
    $pairs = [
        ['a__b', 'c_d'],
        ['a__b_c', 'd'],
        ['a__b', 'ext__c__d'],
        ['a__b_ext', 'c'],
        ['a__b', 'ext__c__d_e'],
        ['a__b', 'ext__cd__e'],
        ['a__b', 'cms_home_node'],
        ['a__b_cms', 'home_node'],
        ['ab__c', 'd'],
        ['a__bc', 'd'],
    ];
    $names = array_map(static fn (array $pair): string => TypeTableNames::index($pair[0], $pair[1]), $pairs);

    expect(array_unique($names))->toBe($names);
});
