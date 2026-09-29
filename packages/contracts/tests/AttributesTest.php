<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Attributes\Query;
use Cbox\Cms\Contracts\Attributes\Stable;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Tests\Fixtures\FindVariant;
use Cbox\Cms\Contracts\Tests\Fixtures\ReleaseVariant;
use Cbox\Cms\Contracts\Tests\Fixtures\SlugHook;
use PHPUnit\Framework\Assert;

/**
 * @template T of object
 *
 * @param  class-string  $class
 * @param  class-string<T>  $attribute
 * @return T
 */
function attributeOf(string $class, string $attribute): object
{
    $attributes = new ReflectionClass($class)->getAttributes($attribute);

    expect($attributes)->toHaveCount(1);

    return $attributes[0]->newInstance();
}

/**
 * @param  Closure(): object  $build
 */
function expectInvalid(Closure $build, string $message): void
{
    try {
        $build();
    } catch (InvalidArgumentException $exception) {
        expect($exception->getMessage())->toContain($message);

        return;
    }

    Assert::fail('Expected an InvalidArgumentException.');
}

it('declares every attribute but Internal for classes only', function (): void {
    foreach ([Stable::class, Experimental::class, Command::class, Query::class, Action::class, Hook::class] as $attribute) {
        $declarations = new ReflectionClass($attribute)->getAttributes(Attribute::class);

        expect($declarations)->toHaveCount(1)
            ->and($declarations[0]->newInstance()->flags)->toBe(Attribute::TARGET_CLASS);
    }
});

it('declares Internal for classes, methods and class constants, and not as repeatable', function (): void {
    $declarations = new ReflectionClass(Internal::class)->getAttributes(Attribute::class);

    expect($declarations)->toHaveCount(1)
        ->and($declarations[0]->newInstance()->flags)
        ->toBe(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_CLASS_CONSTANT);
});

it('reads a command name and version from the command DTO', function (): void {
    $command = attributeOf(ReleaseVariant::class, Command::class);

    expect($command->name)->toBe('entry.release')
        ->and($command->version)->toBe(2);
});

it('accepts dot-separated snake_case command names', function (string $name): void {
    expect((new Command($name, 1))->name)->toBe($name);
})->with(['entry.release', 'curation.assign', 'order_line.add_item', 'shop.ticket.scan']);

it('gives the command name as a CommandName', function (): void {
    expect(attributeOf(ReleaseVariant::class, Command::class)->name())->toEqual(new CommandName('entry.release'));
});

it('rejects a command name that is not dot-separated snake_case', function (string $name): void {
    expectInvalid(static fn (): Command => new Command($name, 1), 'dot-separated snake_case');
})->with(['', 'entry', 'Entry.release', 'entry.Release', 'entry..release', '.entry', 'entry.', 'entry-release.x', '1entry.release', "entry.release\n", "entry.release\r\n", ' entry.release']);

it('rejects a command version below 1', function (int $version): void {
    expectInvalid(static fn (): Command => new Command('entry.release', $version), 'Versions start at 1');
})->with([0, -1]);

it('reads a query name and version from the query DTO, as a CommandName', function (): void {
    $query = attributeOf(FindVariant::class, Query::class);

    expect($query->name)->toBe('entry.find_variant')
        ->and($query->version)->toBe(3)
        ->and($query->name())->toEqual(new CommandName('entry.find_variant'));
});

it('rejects a query name that is not dot-separated snake_case', function (string $name): void {
    expectInvalid(static fn (): Query => new Query($name, 1), 'Query name "'.$name.'" must be dot-separated snake_case segments, for example "entry.find".');
})->with(['', 'entry', 'Entry.find', 'entry..find', "entry.find\n"]);

it('rejects a query version below 1', function (int $version): void {
    expectInvalid(static fn (): Query => new Query('entry.find', $version), 'Query "entry.find" has version '.$version.'. Versions start at 1.');
})->with([0, -1]);

it('reads command, phase, priority and budget from a hook', function (): void {
    $hook = attributeOf(SlugHook::class, Hook::class);

    expect($hook->command)->toBe(ReleaseVariant::class)
        ->and($hook->phase)->toBe(Phase::Transform)
        ->and($hook->priority)->toBe(10)
        ->and($hook->budgetMs)->toBe(20);
});

it('accepts hook budgets from 1 to 20 ms and negative priorities', function (int $budget): void {
    expect((new Hook(ReleaseVariant::class, Phase::Validate, -5, $budget))->budgetMs)->toBe($budget);
})->with([1, 20]);

it('rejects a hook budget outside 1 to 20 ms', function (int $budget): void {
    expectInvalid(static fn (): Hook => new Hook(ReleaseVariant::class, Phase::Authorize, 0, $budget), 'between 1 and 20 ms');
})->with([0, 21, -1]);

it('rejects a hook for something that is not a command class', function (): void {
    expectInvalid(static fn (): Hook => new Hook('Cbox\Cms\Missing', Phase::Transform, 0, 10), 'is not a class');
    expectInvalid(static fn (): Hook => new Hook(SlugHook::class, Phase::Transform, 0, 10), 'not declared with #[Command]');
});

it('has one phase per pipeline phase that runs hooks', function (): void {
    expect(array_map(static fn (Phase $phase): string => $phase->value, Phase::cases()))->toBe(['authorize', 'transform', 'validate']);
});
