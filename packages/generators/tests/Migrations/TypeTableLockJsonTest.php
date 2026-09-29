<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Migrations;

use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Migrations\Boundary\TypeTableLockJson;
use Cbox\Cms\Generators\Migrations\Domain\Dto\LockedColumn;
use Cbox\Cms\Generators\Migrations\Domain\Dto\TypeTableLock;
use Cbox\Cms\Generators\Migrations\Domain\LockText;
use Cbox\Cms\Generators\Migrations\Domain\TableChanges;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use LogicException;

/*
 * A schema lock file is read back only when it is exactly what cms:generate writes (PRD 11.6), so
 * a lock edited by hand is refused with generate_lock_invalid, never taken or rewritten.
 */

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

/**
 * The lock of `note` grown by one optional field, so it has two steps.
 */
function grownNoteLock(): TypeTableLock
{
    $first = TableChanges::next(MigrationFixtures::compile(['note.yaml' => MigrationFixtures::note()]), [])[0];
    $zone = <<<'YAML'
          - handle: zone
            label: Zone
            description: The zone.
            type: text
            classification: public
            sortable: true

        YAML;

    return TableChanges::next(MigrationFixtures::compile(['note.yaml' => MigrationFixtures::note(MigrationFixtures::TITLE.MigrationFixtures::RATING.$zone)]), [$first])[0];
}

it('writes the lock as sorted, pretty-printed JSON and reads it back', function (): void {
    $lock = grownNoteLock();
    $text = LockText::encode($lock);

    expect($text)->toStartWith("{\n    \"about\": \"The schema lock of the type table app__note: the columns its migrations app__note_<step> build, in 2 steps.")
        ->and($text)->toEndWith("    \"type_id\": \"01a0df3e-8cef-7e9f-8daf-9faa60f1fbb1\"\n}\n")
        ->and($text)->toContain("\"checks\": [\n                \"char_length(\\\"zone\\\") <= 255\"\n            ],\n            \"indexed\": true,\n            \"name\": \"zone\",\n            \"not_null\": false,\n            \"step\": 2,")
        ->and(TypeTableLockJson::decode('database/migrations/cms/app__note.lock', $text))->toEqual($lock)
        ->and(LockText::encode(TypeTableLockJson::decode('database/migrations/cms/app__note.lock', $text)))->toBe($text);
});

it('says step for a lock of one step', function (): void {
    expect(LockText::encode(TableChanges::next(MigrationFixtures::compile(['note.yaml' => MigrationFixtures::note()]), [])[0]))
        ->toContain('build, in 1 step. cms:generate writes the lock');
});

/**
 * The lock text with one edit, named by the case.
 */
function editedLock(string $case, string $text): string
{
    return match ($case) {
        'not JSON' => substr($text, 1),
        'not an object' => '[]',
        'another format' => str_replace('"lock": 1,', '"lock": 2,', $text),
        'no type' => str_replace('"type": "app:note",', '"type": 1,', $text),
        'an invalid type' => str_replace('"type": "app:note",', '"type": "app:Note",', $text),
        'an invalid type_id' => str_replace('"type_id": "01a0df3e-8cef-7e9f-8daf-9faa60f1fbb1"', '"type_id": "x"', $text),
        'unknown stages' => str_replace('"stages": "draft-release"', '"stages": "many"', $text),
        'unknown localization' => str_replace('"localization": "none"', '"localization": "all"', $text),
        'no steps' => str_replace('"steps": 2,', '"steps": 0,', $text),
        'steps as text' => str_replace('"steps": 2,', '"steps": "2",', $text),
        'another table' => str_replace('"table": "app__note",', '"table": "app__other",', $text),
        'no columns' => (string) preg_replace('/"columns": \[.*?\n    \],/s', '"columns": "none",', $text),
        'a column that is not an object' => (string) preg_replace('/"columns": \[.*?\n    \],/s', '"columns": [1],', $text),
        'checks that are not texts' => str_replace('"checks": [],', '"checks": [1],', str_replace("\"checks\": [\n                \"char_length(\\\"zone\\\") <= 255\"\n            ],", '"checks": [],', $text)),
        'a column without a name' => str_replace('"name": "zone"', '"name": null', $text),
        'not_null as text' => preg_replace('/"not_null": false/', '"not_null": "no"', $text, 1) ?? $text,
        'a step past steps' => str_replace('"step": 2,', '"step": 3,', $text),
        'a column twice' => str_replace('"name": "zone"', '"name": "title"', $text),
        'unsorted columns' => str_replace('"name": "zone"', '"name": "aaa"', str_replace('"step": 2,', '"step": 1,', $text)),
        'a step without a column' => str_replace(['"step": 2,', '"steps": 2,'], ['"step": 1,', '"steps": 3,'], str_replace('"name": "zone"', '"name": "zzz"', $text)),
        'other formatting' => str_replace('    ', '  ', $text),
        'another about' => str_replace('do not edit either.', 'edit freely.', $text),
        default => throw new LogicException('Unknown edit '.$case),
    };
}

it('refuses a lock that is not what cms:generate writes', function (string $case, string $reason): void {
    $text = editedLock($case, LockText::encode(grownNoteLock()));
    $failed = MigrationFixtures::failure(static fn (): TypeTableLock => TypeTableLockJson::decode('database/migrations/cms/app__note.lock', $text));

    expect($failed->codes())->toBe([GenerateErrorCode::LockInvalid])
        ->and($failed->getMessage())->toContain('The schema lock database/migrations/cms/app__note.lock cannot be used: '.$reason)
        ->and($failed->getMessage())->toContain('Restore the committed file with git, then run cms:generate again.');
})->with([
    'not JSON' => ['not JSON', 'it is not valid JSON'],
    'not an object' => ['not an object', 'it is not a schema lock of format 1.'],
    'another format' => ['another format', 'it is not a schema lock of format 1.'],
    'no type' => ['no type', 'it has no string type.'],
    'an invalid type' => ['an invalid type', 'A type name is <owner>:<handle>'],
    'an invalid type_id' => ['an invalid type_id', 'Expected a UUID in the form xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx with hex digits, got "x".'],
    'unknown stages' => ['unknown stages', 'its stages are not a value of the blueprint schema v1.'],
    'unknown localization' => ['unknown localization', 'its localization is not a value of the blueprint schema v1.'],
    'no steps' => ['no steps', 'it has no step.'],
    'steps as text' => ['steps as text', 'it has no integer steps.'],
    'another table' => ['another table', 'its table app__other is not app__note, the table of its type app:note'],
    'no columns' => ['no columns', 'its columns are not a list.'],
    'a column that is not an object' => ['a column that is not an object', 'column 0 is not an object.'],
    'checks that are not texts' => ['checks that are not texts', 'column 2 has no list of checks.'],
    'a column without a name' => ['a column without a name', 'column 2 has no string name.'],
    'not_null as text' => ['not_null as text', 'column 0 has no boolean not_null.'],
    'a step past steps' => ['a step past steps', 'column 2 has step 3, and the lock has steps 1 to 2.'],
    'a column twice' => ['a column twice', 'the column title is in it twice.'],
    'unsorted columns' => ['unsorted columns', 'its columns are not sorted by step and then by name.'],
    'a step without a column' => ['a step without a column', 'its step 2 adds no column.'],
    'other formatting' => ['other formatting', 'it is not in the form cms:generate writes. It was edited by hand.'],
    'another about' => ['another about', 'it is not in the form cms:generate writes. It was edited by hand.'],
]);

it('refuses a lock whose file is not named after its table', function (): void {
    $failed = MigrationFixtures::failure(static fn (): TypeTableLock => TypeTableLockJson::decode('database/migrations/cms/note.lock', LockText::encode(grownNoteLock())));

    expect($failed->codes())->toBe([GenerateErrorCode::LockInvalid])
        ->and($failed->getMessage())->toContain('or its file is not named after it.');
});

it('holds a column to another by everything but its step', function (): void {
    $column = new LockedColumn('a', 'text', false, ['x'], true, 1);

    expect($column->sameShape(new LockedColumn('a', 'text', false, ['x'], true, 2)))->toBeTrue()
        ->and($column->sameShape(new LockedColumn('b', 'text', false, ['x'], true, 1)))->toBeFalse()
        ->and($column->sameShape(new LockedColumn('a', 'jsonb', false, ['x'], true, 1)))->toBeFalse()
        ->and($column->sameShape(new LockedColumn('a', 'text', true, ['x'], true, 1)))->toBeFalse()
        ->and($column->sameShape(new LockedColumn('a', 'text', false, ['y'], true, 1)))->toBeFalse()
        ->and($column->sameShape(new LockedColumn('a', 'text', false, ['x'], false, 1)))->toBeFalse();
});
