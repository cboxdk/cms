<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Core\Registry\Actions\DescribeActions;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionDescription;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\HookEntry;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeRegistryCache;
use Cbox\Cms\Core\Tests\Registry\InspectedRegistry;

/*
 * DescribeActions, behind cms:actions (GUARDRAILS 7.1): every action of the compiled registry in
 * its order, with the command or query it handles, its surfaces, the permission a grant needs and,
 * for a write, the hooks of its command version in the order they run. It reads the registry cache,
 * so a missing or damaged cache is refused with its own exception.
 */

/**
 * @return list<string>
 */
function describedHooks(ActionDescription $description): array
{
    return array_map(static fn (HookEntry $hook): string => $hook->class, $description->hooks);
}

it('describes every action in the registry order with its surfaces, permission and hooks in run order', function (): void {
    $actions = new DescribeActions(InspectedRegistry::cache())->describe();

    expect(array_map(static fn (ActionDescription $description): string => $description->action->command->value.' v'.$description->action->commandVersion, $actions))
        ->toBe(['note.archive v1', 'note.create v1', 'note.create v2', 'note.find v1'])
        ->and(array_map(static fn (ActionDescription $description): string => $description->permission->value, $actions))
        ->toBe(['note.archive', 'note.create', 'note.create', 'note.find'])
        ->and($actions[1]->action->class)->toBe('Acme\Notes\CreateNoteAction')
        ->and($actions[1]->action->surfaces)->toBe([Surface::Rest, Surface::Cli])
        ->and($actions[2]->action->surfaces)->toBe([])
        ->and($actions[3]->action->kind)->toBe(ActionKind::Query)
        ->and(describedHooks($actions[0]))->toBe([])
        ->and(describedHooks($actions[1]))->toBe([
            'Acme\Notes\CheckQuota',
            'Acme\Notes\SlugTitle',
            'Acme\Audit\TrimTitle',
            'Acme\Notes\TrimTitle',
            'Acme\Notes\RequireTitle',
        ])
        ->and(describedHooks($actions[2]))->toBe(['Acme\Notes\RequireBody'])
        ->and(describedHooks($actions[3]))->toBe([]);
});

it('describes no actions when the registry holds none', function (): void {
    $cache = new FakeRegistryCache;
    $cache->write(CompiledRegistry::empty());

    expect(new DescribeActions($cache)->describe())->toBe([]);
});

it('refuses a registry cache that is missing or damaged', function (): void {
    $damaged = InspectedRegistry::cache();
    $damaged->damage();

    expect(fn (): array => new DescribeActions(new FakeRegistryCache)->describe())->toThrow(RegistryCacheMissing::class)
        ->and(fn (): array => new DescribeActions($damaged)->describe())->toThrow(MalformedRegistryCache::class);
});
