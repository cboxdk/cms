<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Migrations;

use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Migrations\Domain\Dto\LockedColumn;
use Cbox\Cms\Generators\Migrations\Domain\Dto\TypeTableLock;
use Cbox\Cms\Generators\Migrations\Domain\TableChanges;
use Cbox\Cms\Generators\Schema\Domain\Localization;
use Cbox\Cms\Generators\Schema\Domain\Stages;
use Cbox\Cms\Generators\Tests\SchemaFixtures;

/*
 * The schema lock of every type table follows from the compiled schema and the committed locks
 * (PRD 11.6, 11.12). Until schema evolution (B3) a table only grows: a new type gets a lock of one
 * step and a new optional field one more step, and every other change is refused with its own
 * generate_* code.
 */

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

/**
 * The lock of `note` as its first blueprint gives it.
 */
function noteLock(): TypeTableLock
{
    return TableChanges::next(MigrationFixtures::compile(['note.yaml' => MigrationFixtures::note()]), [])[0];
}

it('gives a new type a lock of one step with a column per top-level field, sorted by name', function (): void {
    $lock = noteLock();

    expect($lock->table)->toBe('app__note')
        ->and($lock->type->value)->toBe('app:note')
        ->and($lock->typeId->toString())->toBe(MigrationFixtures::TYPE_ID)
        ->and($lock->stages)->toBe(Stages::DraftRelease)
        ->and($lock->localization)->toBe(Localization::None)
        ->and($lock->steps)->toBe(1)
        ->and($lock->file())->toBe('app__note.lock')
        ->and($lock->columns)->toEqual([
            new LockedColumn('rating', 'bigint', false, ['"rating" >= 1', '"rating" <= 5'], false, 1),
            new LockedColumn('title', 'text', true, ['char_length("title") <= 255'], true, 1),
        ])
        ->and($lock->columnsOf(1))->toEqual($lock->columns)
        ->and($lock->columnsOf(2))->toBe([])
        ->and($lock->column('title'))->toEqual($lock->columns[1])
        ->and($lock->column('body'))->toBeNull();
});

it('keeps the lock of a type that did not change', function (): void {
    $lock = noteLock();

    expect(TableChanges::next(MigrationFixtures::compile(['note.yaml' => MigrationFixtures::note()]), [$lock]))->toEqual([$lock]);
});

it('adds a step with the columns of the new optional fields, and keeps the earlier steps', function (): void {
    $lock = noteLock();
    $body = <<<'YAML'
          - handle: body
            label: Body
            description: The body.
            type: long_text
            classification: public
          - handle: zone
            label: Zone
            description: The zone.
            type: text
            classification: public
            sortable: true

        YAML;

    $grown = TableChanges::next(MigrationFixtures::compile(['note.yaml' => MigrationFixtures::note(MigrationFixtures::TITLE.MigrationFixtures::RATING.$body)]), [$lock])[0];

    expect($grown->steps)->toBe(2)
        ->and($grown->columnsOf(1))->toEqual($lock->columns)
        ->and($grown->columnsOf(2))->toEqual([
            new LockedColumn('body', 'text', false, ['char_length("body") <= 10000'], false, 2),
            new LockedColumn('zone', 'text', false, ['char_length("zone") <= 255'], true, 2),
        ]);
});

it('adds a required extension field as a nullable column, because the owner\'s code never sets it', function (): void {
    $lock = noteLock();
    $extension = <<<'YAML'
        blueprint: 1
        kind: extension
        extends: 01a0df3e-8cef-7e9f-8daf-9faa60f1fbb1
        version: 1
        fields:
          - handle: code
            label: Code
            description: The code.
            type: text
            required: true
            classification: internal

        YAML;

    $grown = TableChanges::next(MigrationFixtures::compile(['note.yaml' => MigrationFixtures::note()], ['note.yaml' => $extension]), [$lock])[0];

    expect($grown->steps)->toBe(2)
        ->and($grown->columnsOf(2))->toEqual([new LockedColumn('ext__acme__code', 'text', false, ['char_length("ext__acme__code") <= 255'], false, 2)]);
});

it('refuses a change to a type table with its own code', function (string $blueprint, GenerateErrorCode $code, string $message): void {
    $failed = MigrationFixtures::failure(static fn (): array => TableChanges::next(MigrationFixtures::compile(['note.yaml' => $blueprint]), [noteLock()]));

    expect($failed->codes())->toBe([$code])
        ->and($failed->getMessage())->toContain($message);
})->with([
    'a removed field' => [MigrationFixtures::note(MigrationFixtures::TITLE), GenerateErrorCode::FieldRemoved, 'has no field for the column rating, which its schema lock app__note.lock has since step 1'],
    'a changed type' => [MigrationFixtures::note(MigrationFixtures::TITLE.str_replace(['type: integer', 'min: 1', 'max: 5'], ['type: decimal', 'precision: 4', 'scale: 1'], MigrationFixtures::RATING)), GenerateErrorCode::FieldChanged, 'the column rating of app__note is numeric(4, 1) with no checks and no index, and its schema lock app__note.lock has bigint with the checks "rating" >= 1, "rating" <= 5 and no index'],
    'a changed check' => [MigrationFixtures::note(MigrationFixtures::TITLE.str_replace('max: 5', 'max: 10', MigrationFixtures::RATING)), GenerateErrorCode::FieldChanged, 'the column rating of app__note'],
    'a field made required' => [MigrationFixtures::note(str_replace('required: true', 'required: false', MigrationFixtures::TITLE).MigrationFixtures::RATING), GenerateErrorCode::FieldChanged, 'the column title of app__note is text with'],
    'an index dropped' => [MigrationFixtures::note(str_replace('filterable: true', 'filterable: false', MigrationFixtures::TITLE).MigrationFixtures::RATING), GenerateErrorCode::FieldChanged, 'and no index, and its schema lock app__note.lock has text not null with the checks char_length("title") <= 255 and an index'],
    'a new required field' => [MigrationFixtures::note(MigrationFixtures::TITLE.MigrationFixtures::RATING.str_replace(['handle: title', 'label: Title'], ['handle: subtitle', 'label: Subtitle'], MigrationFixtures::TITLE)), GenerateErrorCode::RequiredFieldAdded, 'the new field subtitle of app:note is required'],
    'other stages' => [MigrationFixtures::note(stages: 'none'), GenerateErrorCode::TableChanged, 'has type_id 01a0df3e-8cef-7e9f-8daf-9faa60f1fbb1, stages none and localization none, and its schema lock app__note.lock has type_id 01a0df3e-8cef-7e9f-8daf-9faa60f1fbb1, stages draft-release'],
    'another type_id' => [MigrationFixtures::note(typeId: '01a0df3e-8cef-7e9f-8daf-9faa60f1fbb2'), GenerateErrorCode::TableChanged, 'has type_id 01a0df3e-8cef-7e9f-8daf-9faa60f1fbb2'],
]);

it('refuses a type whose lock remains after its blueprint is gone', function (): void {
    $failed = MigrationFixtures::failure(static fn (): array => TableChanges::next(MigrationFixtures::compile(['other.yaml' => MigrationFixtures::note(typeId: '01a0df3e-8cef-7e9f-8daf-9faa60f1fbb3', handle: 'other')]), [noteLock()]));

    expect($failed->codes())->toBe([GenerateErrorCode::TypeRemoved])
        ->and($failed->getMessage())->toContain('The type "app:note" has the table app__note in the schema lock app__note.lock, but no blueprint defines it.');
});

it('collects every problem of every type before it fails', function (): void {
    $other = TableChanges::next(MigrationFixtures::compile(['other.yaml' => MigrationFixtures::note(typeId: '01a0df3e-8cef-7e9f-8daf-9faa60f1fbb3', handle: 'other')]), [])[0];

    expect(MigrationFixtures::codes(static fn (): array => TableChanges::next(
        MigrationFixtures::compile(['other.yaml' => MigrationFixtures::note(MigrationFixtures::TITLE, typeId: '01a0df3e-8cef-7e9f-8daf-9faa60f1fbb3', handle: 'other')]),
        [noteLock(), $other],
    )))->toBe([GenerateErrorCode::FieldRemoved, GenerateErrorCode::TypeRemoved]);
});

it('refuses a type whose table name passes 54 bytes, and takes one of 54', function (): void {
    $fits = 'a'.str_repeat('b', 48);
    $long = $fits.'c';

    $failed = MigrationFixtures::failure(static fn (): array => TableChanges::next(MigrationFixtures::compile(['long.yaml' => MigrationFixtures::note(handle: $long)]), []));

    expect($failed->codes())->toBe([GenerateErrorCode::TableNameTooLong])
        ->and($failed->getMessage())->toContain('schema/long.yaml, /handle: the table of the type "app:'.$long.'" is app__'.$long.', 55 bytes, and a type table has at most 54')
        ->and(TableChanges::next(MigrationFixtures::compile(['fits.yaml' => MigrationFixtures::note(handle: $fits)]), [])[0]->table)->toBe('app__'.$fits)
        ->and(strlen('app__'.$fits))->toBe(54);
});

it('gives the locks sorted by table', function (): void {
    $tables = array_map(
        static fn (TypeTableLock $lock): string => $lock->table,
        TableChanges::next(MigrationFixtures::compile([
            'zeta.yaml' => MigrationFixtures::note(typeId: '01a0df3e-8cef-7e9f-8daf-9faa60f1fbb4', handle: 'zeta'),
            'alpha.yaml' => MigrationFixtures::note(typeId: '01a0df3e-8cef-7e9f-8daf-9faa60f1fbb5', handle: 'alpha'),
        ]), []),
    );

    expect($tables)->toBe(['app__alpha', 'app__zeta']);
});
