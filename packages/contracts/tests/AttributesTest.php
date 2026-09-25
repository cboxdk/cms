<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Attributes\Stable;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Tests\Fixtures\ReleaseVariant;
use Cbox\Cms\Contracts\Tests\Fixtures\ReleaseVariantAction;
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
    foreach ([Stable::class, Experimental::class, Command::class, Action::class, Hook::class] as $attribute) {
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

it('rejects a command name that is not dot-separated snake_case', function (string $name): void {
    expectInvalid(static fn (): Command => new Command($name, 1), 'dot-separated snake_case');
})->with(['', 'entry', 'Entry.release', 'entry.Release', 'entry..release', '.entry', 'entry.', 'entry-release.x', '1entry.release']);

it('rejects a command version below 1', function (int $version): void {
    expectInvalid(static fn (): Command => new Command('entry.release', $version), 'Versions start at 1');
})->with([0, -1]);

it('reads the surfaces from an action', function (): void {
    $action = attributeOf(ReleaseVariantAction::class, Action::class);

    expect($action->surfaces)->toBe([Surface::Rest, Surface::Mcp])
        ->and($action->exposes(Surface::Rest))->toBeTrue()
        ->and($action->exposes(Surface::Cli))->toBeFalse();
});

it('allows an action without generated surfaces', function (): void {
    expect((new Action(surfaces: []))->surfaces)->toBe([]);
});

it('rejects a surface declared twice', function (): void {
    expectInvalid(static fn (): Action => new Action(surfaces: [Surface::Rest, Surface::Cli, Surface::Rest]), 'Surface "Rest"');
});

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
    expectInvalid(static fn (): Hook => new Hook(ReleaseVariantAction::class, Phase::Transform, 0, 10), 'not declared with #[Command]');
});

it('has one surface per transport profile and one phase per hook interface', function (): void {
    expect(array_map(static fn (Surface $surface): string => $surface->value, Surface::cases()))->toBe(['rest', 'inertia', 'mcp', 'cli'])
        ->and(array_map(static fn (Phase $phase): string => $phase->value, Phase::cases()))->toBe(['authorize', 'transform', 'validate']);
});
