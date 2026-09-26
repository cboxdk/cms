<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Ids;

use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\InvalidCommandName;
use InvalidArgumentException;

/*
 * The name of a command type: the name from #[Command], without the version, in the form of
 * Command::NAME_PATTERN.
 */

it('keeps a command name exactly as given', function (string $value): void {
    expect((new CommandName($value))->value)->toBe($value);
})->with([
    'two segments' => 'entry.release',
    'snake_case segments' => 'curation_slot.assign_entry',
    'three segments' => 'asset.rendition.create',
    'digits after the first letter' => 'entry2.release_v2',
]);

it('rejects what the command name pattern rejects', function (string $value): void {
    expect(preg_match(Command::NAME_PATTERN, $value))->toBe(0)
        ->and(static fn (): CommandName => new CommandName($value))
        ->toThrow(InvalidCommandName::class, 'A command name is dot-separated snake_case segments');
})->with([
    'empty' => '',
    'one segment' => 'entry',
    'upper case' => 'Entry.release',
    'with a version' => 'entry.release@1',
    'trailing newline' => "entry.release\n",
    'leading digit' => '1entry.release',
    'empty segment' => 'entry..release',
    'trailing dot' => 'entry.release.',
    'hyphen' => 'entry.re-lease',
    'space' => 'entry. release',
]);

it('accepts exactly the names #[Command] accepts', function (string $value): void {
    $attribute = static fn (): Command => new Command($value, 1);
    $name = static fn (): CommandName => new CommandName($value);

    try {
        $attribute();
        $attributeAccepts = true;
    } catch (InvalidArgumentException) {
        $attributeAccepts = false;
    }

    try {
        $name();
        $nameAccepts = true;
    } catch (InvalidCommandName) {
        $nameAccepts = false;
    }

    expect($nameAccepts)->toBe($attributeAccepts);
})->with(['entry.release', 'entry', 'Entry.release', 'entry.release@1', "entry.release\n", 'a.b_c.d9']);

it('shows rejected input cut and escaped in the message', function (): void {
    expect(static fn (): CommandName => new CommandName("entry.release\n"))
        ->toThrow(InvalidCommandName::class, 'got "entry.release\\n".');
});

it('compares command names exactly', function (): void {
    expect(new CommandName('entry.release')->equals(new CommandName('entry.release')))->toBeTrue()
        ->and(new CommandName('entry.release')->equals(new CommandName('entry.publish')))->toBeFalse();
});
